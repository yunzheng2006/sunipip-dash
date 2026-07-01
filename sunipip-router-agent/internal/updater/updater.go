package updater

import (
	"context"
	"fmt"
	"log/slog"
	"os"
	"os/exec"
	"strconv"
	"strings"

	"sunipip-router-agent/internal/api"
)

const defaultBinaryPath = "/usr/local/bin/sunipip-router-agent"

// Updater handles self-updating the agent binary.
type Updater struct {
	client     *api.Client
	logger     *slog.Logger
	binaryPath string
}

// NewUpdater creates a new Updater. It auto-detects the current binary path
// via os.Executable(), falling back to the default path.
func NewUpdater(client *api.Client, logger *slog.Logger) *Updater {
	binPath := defaultBinaryPath
	if exe, err := os.Executable(); err == nil && exe != "" {
		binPath = exe
	}

	return &Updater{
		client:     client,
		logger:     logger.With("component", "updater"),
		binaryPath: binPath,
	}
}

// CheckAndUpdate compares the running version with latestVersion.
// If latestVersion is newer, it downloads the new binary from downloadURL,
// writes it to a temp file, and atomically replaces the running binary.
// Returns true if an update was performed (caller should arrange a restart).
func (u *Updater) CheckAndUpdate(ctx context.Context, latestVersion, downloadURL string) (bool, error) {
	currentVersion := api.AgentVersion

	if !isNewer(latestVersion, currentVersion) {
		u.logger.Debug("Agent is up to date",
			"current", currentVersion,
			"latest", latestVersion,
		)
		return false, nil
	}

	u.logger.Info("Newer agent version available, starting update",
		"current", currentVersion,
		"latest", latestVersion,
		"download_url", downloadURL,
	)

	// Step 1: Download to temp file
	tempPath := u.binaryPath + ".new"
	if err := u.client.DownloadAgentBinary(ctx, downloadURL, tempPath); err != nil {
		// Clean up temp file on failure
		os.Remove(tempPath)
		return false, fmt.Errorf("download new binary: %w", err)
	}

	// Step 2: Set executable permissions
	if err := os.Chmod(tempPath, 0755); err != nil {
		os.Remove(tempPath)
		return false, fmt.Errorf("chmod new binary: %w", err)
	}

	// Step 3: Verify it's a valid executable by checking it can print a version
	verifyCmd := exec.CommandContext(ctx, tempPath, "--version")
	if verifyOut, err := verifyCmd.CombinedOutput(); err != nil {
		u.logger.Warn("New binary --version check failed (non-fatal, proceeding)",
			"error", err,
			"output", string(verifyOut),
		)
		// Don't fail on this -- the binary may not support --version yet
	}

	// Step 4: Atomic replace — rename temp over the running binary
	if err := os.Rename(tempPath, u.binaryPath); err != nil {
		os.Remove(tempPath)
		return false, fmt.Errorf("rename new binary into place: %w", err)
	}

	u.logger.Info("Agent binary updated successfully",
		"from", currentVersion,
		"to", latestVersion,
		"path", u.binaryPath,
	)

	// Step 5: Report event to platform
	_ = u.client.ReportEvent(ctx, "agent_updated", "info",
		fmt.Sprintf("Agent updated from %s to %s", currentVersion, latestVersion),
		map[string]interface{}{
			"from_version": currentVersion,
			"to_version":   latestVersion,
		},
	)

	return true, nil
}

// RestartSelf restarts the agent via systemctl. This function does not return
// on success — the process will be replaced. On failure, the caller should
// fall back to os.Exit(0) and let systemd restart the service.
func (u *Updater) RestartSelf() {
	u.logger.Info("Restarting agent via systemctl")
	cmd := exec.Command("systemctl", "restart", "sunipip-router-agent")
	if err := cmd.Run(); err != nil {
		u.logger.Error("systemctl restart failed, falling back to exit",
			"error", err,
		)
	}
	// Whether systemctl succeeded or not, exit so systemd restarts us.
	os.Exit(0)
}

// isNewer returns true if version a is strictly newer than version b.
// Versions are expected in semver format: "major.minor.patch".
// Each component is compared numerically.
func isNewer(a, b string) bool {
	partsA := parseSemver(a)
	partsB := parseSemver(b)

	for i := 0; i < 3; i++ {
		if partsA[i] > partsB[i] {
			return true
		}
		if partsA[i] < partsB[i] {
			return false
		}
	}
	return false // equal
}

// parseSemver splits a version string like "1.2.3" into [1, 2, 3].
// Missing or non-numeric parts default to 0.
func parseSemver(v string) [3]int {
	var parts [3]int
	// Strip leading 'v' if present
	v = strings.TrimPrefix(v, "v")
	segments := strings.SplitN(v, ".", 3)
	for i := 0; i < len(segments) && i < 3; i++ {
		n, err := strconv.Atoi(segments[i])
		if err == nil {
			parts[i] = n
		}
	}
	return parts
}
