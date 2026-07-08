package manager

import (
	"context"
	"fmt"
	"log/slog"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"sync"

	"sunipip-router-agent/internal/api"
	"sunipip-router-agent/internal/services"
)

// Manager orchestrates configuration application across all services.
type Manager struct {
	freeradius *services.FreeRadiusService
	clash      *services.ClashService
	wireguard  *services.WireGuardService
	network    *services.NetworkService
	firewall   *services.FirewallService
	dhcp       *services.DHCPService
	ap         *services.APService
	logger     *slog.Logger

	mu                   sync.RWMutex
	lastAppliedConfig    *api.DeviceConfig
	appliedConfigVersion int
}

// NewManager creates a new configuration manager.
func NewManager(logger *slog.Logger) *Manager {
	return &Manager{
		freeradius: services.NewFreeRadiusService(logger.With("service", "freeradius")),
		clash:      services.NewClashService(logger.With("service", "clash")),
		wireguard:  services.NewWireGuardService(logger.With("service", "wireguard")),
		network:    services.NewNetworkService(logger.With("service", "network")),
		firewall:   services.NewFirewallService(logger.With("service", "firewall")),
		dhcp:       services.NewDHCPService(logger.With("service", "dhcp")),
		ap:         services.NewAPService(logger.With("service", "ap")),
		logger:     logger,
	}
}

// Apply applies a full device configuration in the correct order.
// Order: network -> AP (must be before DHCP changes) -> clash -> firewall -> dhcp -> wireguard -> freeradius
// AP config MUST be pushed before DHCP changes to prevent AP losing connectivity.
// Clash must start BEFORE firewall so tproxy rules have a running backend.
func (m *Manager) Apply(ctx context.Context, cfg *api.DeviceConfig) error {
	m.logger.Info("Applying configuration",
		"version", cfg.ConfigVersion,
		"generated_at", cfg.GeneratedAt,
		"hostname", cfg.Device.Hostname,
	)

	// 1. Network interfaces (VLANs, bridges) must come first
	if err := m.network.Apply(ctx, cfg.Network); err != nil {
		return fmt.Errorf("apply network: %w", err)
	}

	// 2. AP config — push static IP BEFORE DHCP changes to avoid AP losing connectivity
	if cfg.AP != nil {
		if err := m.ap.Apply(ctx, *cfg.AP); err != nil {
			m.logger.Warn("AP config push failed (non-fatal, will retry)", "error", err)
		}
	}

	// 3. Clash proxy — must be running before firewall enables tproxy rules
	if err := m.clash.Apply(ctx, cfg.Clash, cfg.ConfigVersion); err != nil {
		return fmt.Errorf("apply clash: %w", err)
	}

	// 4. Firewall rules (depends on interfaces existing, tproxy needs Clash)
	if err := m.firewall.Apply(ctx, cfg.Network, cfg.ConfigVersion); err != nil {
		return fmt.Errorf("apply firewall: %w", err)
	}

	// 5. DHCP config (depends on interfaces and bridges)
	if err := m.dhcp.Apply(ctx, cfg.Network, cfg.ConfigVersion); err != nil {
		return fmt.Errorf("apply dhcp: %w", err)
	}

	// 6. WireGuard tunnels
	if err := m.wireguard.Apply(ctx, cfg.WireGuard); err != nil {
		return fmt.Errorf("apply wireguard: %w", err)
	}

	// 7. FreeRadius (needs VLANs to exist)
	if err := m.freeradius.Apply(ctx, cfg.FreeRadius, cfg.ConfigVersion); err != nil {
		return fmt.Errorf("apply freeradius: %w", err)
	}

	// 8. Local page HTML
	if cfg.LocalPage != "" {
		if err := writeLocalPage(cfg.LocalPage); err != nil {
			m.logger.Warn("Failed to write local page", "error", err)
		}
	}

	// Store last applied config
	m.mu.Lock()
	m.lastAppliedConfig = cfg
	m.appliedConfigVersion = cfg.ConfigVersion
	m.mu.Unlock()

	m.logger.Info("Configuration applied successfully", "version", cfg.ConfigVersion)
	return nil
}

// AppliedConfigVersion returns the currently applied config version.
func (m *Manager) AppliedConfigVersion() int {
	m.mu.RLock()
	defer m.mu.RUnlock()
	return m.appliedConfigVersion
}

// LastAppliedConfig returns the last successfully applied config.
func (m *Manager) LastAppliedConfig() *api.DeviceConfig {
	m.mu.RLock()
	defer m.mu.RUnlock()
	return m.lastAppliedConfig
}

// ServiceStatus represents the status of a single service.
type ServiceStatus struct {
	Name    string `json:"name"`
	Running bool   `json:"running"`
}

// GetServiceStatuses returns the running status of all managed services.
func (m *Manager) GetServiceStatuses(ctx context.Context) []ServiceStatus {
	return []ServiceStatus{
		{Name: "freeradius", Running: m.freeradius.IsRunning(ctx)},
		{Name: "clash", Running: m.clash.IsRunning(ctx)},
		{Name: "wireguard", Running: m.wireguard.IsRunning(ctx)},
		{Name: "dnsmasq", Running: m.dhcp.IsRunning(ctx)},
		{Name: "nftables", Running: m.firewall.IsRunning(ctx)},
	}
}

// RestartService restarts a managed service by name.
func (m *Manager) RestartService(ctx context.Context, name string) error {
	m.mu.RLock()
	cfg := m.lastAppliedConfig
	m.mu.RUnlock()

	if cfg == nil {
		return fmt.Errorf("no configuration has been applied yet")
	}

	switch name {
	case "freeradius":
		return m.freeradius.Apply(ctx, cfg.FreeRadius, cfg.ConfigVersion)
	case "clash":
		return m.clash.Apply(ctx, cfg.Clash, cfg.ConfigVersion)
	case "dnsmasq":
		return m.dhcp.Apply(ctx, cfg.Network, cfg.ConfigVersion)
	default:
		return fmt.Errorf("unknown or non-restartable service: %s", name)
	}
}

// MaintenanceRestart restarts FreeRadius and dnsmasq to clear stale state.
func (m *Manager) MaintenanceRestart(ctx context.Context) error {
	var errs []string
	if err := m.freeradius.Restart(ctx); err != nil {
		errs = append(errs, "freeradius: "+err.Error())
	}
	if err := m.dhcp.Restart(ctx); err != nil {
		errs = append(errs, "dnsmasq: "+err.Error())
	}
	if len(errs) > 0 {
		return fmt.Errorf("maintenance restart errors: %s", strings.Join(errs, "; "))
	}
	return nil
}

// EnsureLogging enables detailed logging on all services and configures journal retention.
func (m *Manager) EnsureLogging(ctx context.Context) {
	m.freeradius.EnableAuthLog(ctx)
	m.ensureJournalRetention()
}

// ensureJournalRetention configures systemd-journald to retain at least 7 days / 500MB.
func (m *Manager) ensureJournalRetention() {
	const dropinDir = "/etc/systemd/journald.conf.d"
	const dropinPath = "/etc/systemd/journald.conf.d/sunipip-retention.conf"
	const content = `# Managed by SuniPIP Router Agent
[Journal]
Storage=persistent
SystemMaxUse=500M
MaxRetentionSec=7day
`
	existing, _ := os.ReadFile(dropinPath)
	if string(existing) == content {
		return
	}
	if err := os.MkdirAll(dropinDir, 0755); err != nil {
		m.logger.Warn("Failed to create journald drop-in dir", "error", err)
		return
	}
	if err := os.WriteFile(dropinPath, []byte(content), 0644); err != nil {
		m.logger.Warn("Failed to write journald retention config", "error", err)
		return
	}
	m.logger.Info("Configured journal retention: 500M / 7 days")

	cmd := exec.CommandContext(context.Background(), "systemctl", "restart", "systemd-journald")
	if err := cmd.Run(); err != nil {
		m.logger.Warn("Failed to restart journald", "error", err)
	}
}

// CleanStaleHosts removes DHCP host entries for disconnected devices.
func (m *Manager) CleanStaleHosts(ctx context.Context) {
	m.freeradius.CleanStaleHosts(ctx)
}

func (m *Manager) PruneGraceFile() {
	m.freeradius.PruneGraceFile()
}

// NetworkService returns the network service for direct queries.
func (m *Manager) NetworkService() *services.NetworkService {
	return m.network
}

const localPagePath = "/var/www/router-frontend/index.html"

func writeLocalPage(html string) error {
	dir := filepath.Dir(localPagePath)
	if err := os.MkdirAll(dir, 0755); err != nil {
		return fmt.Errorf("mkdir %s: %w", dir, err)
	}
	return os.WriteFile(localPagePath, []byte(html), 0644)
}
