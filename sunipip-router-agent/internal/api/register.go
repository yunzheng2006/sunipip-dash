package api

import (
	"context"
	"crypto/rand"
	"encoding/base64"
	"fmt"
	"log/slog"
	"net/http"
	"os"
	"os/exec"
	"strings"

	"sunipip-router-agent/internal/config"

	"golang.org/x/crypto/curve25519"
)

// RegisterRequest is sent to the platform to register a new device.
type RegisterRequest struct {
	InstallToken string `json:"install_token"`
	SerialNumber string `json:"serial_number"`
	WGPublicKey1 string `json:"wg_public_key_1"`
	WGPublicKey2 string `json:"wg_public_key_2"`
	Hostname     string `json:"hostname"`
	AgentVersion string `json:"agent_version"`
}

// RegisterResponse contains the registration result.
type RegisterResponse struct {
	Success bool `json:"success"`
	Data    struct {
		DeviceID int              `json:"device_id"`
		AgentKey string           `json:"agent_key"`
		WGConfigs []WGConfigEntry `json:"wg_configs"`
	} `json:"data"`
	Message string `json:"message"`
}

// WGConfigEntry is a WireGuard config from the registration response.
type WGConfigEntry struct {
	Interface          string `json:"interface"`
	AssignedIP         string `json:"assigned_ip"`
	ServerPublicKey    string `json:"server_public_key"`
	ServerEndpoint     string `json:"server_endpoint"`
	MTU                int    `json:"mtu"`
	PersistentKeepalive int   `json:"persistent_keepalive"`
	DNS                string `json:"dns"`
}

// WGKeyPair holds a WireGuard private/public key pair.
type WGKeyPair struct {
	PrivateKey string
	PublicKey  string
}

// Register performs first-time device registration with the platform.
func Register(ctx context.Context, platformURL, installToken string, logger *slog.Logger) (*config.AgentConfig, error) {
	logger.Info("Starting device registration", "platform_url", platformURL)

	// Get serial number
	serialNumber, err := getSerialNumber()
	if err != nil {
		return nil, fmt.Errorf("get serial number: %w", err)
	}
	logger.Info("Device serial number", "serial", serialNumber)

	// Get hostname
	hostname, err := os.Hostname()
	if err != nil {
		hostname = "unknown"
	}

	// Generate WireGuard key pairs
	wgKey1, err := generateWGKeyPair()
	if err != nil {
		return nil, fmt.Errorf("generate WG key pair 1: %w", err)
	}
	wgKey2, err := generateWGKeyPair()
	if err != nil {
		return nil, fmt.Errorf("generate WG key pair 2: %w", err)
	}

	logger.Info("Generated WireGuard key pairs")

	// Build registration request
	reqBody := RegisterRequest{
		InstallToken: installToken,
		SerialNumber: serialNumber,
		WGPublicKey1: wgKey1.PublicKey,
		WGPublicKey2: wgKey2.PublicKey,
		Hostname:     hostname,
		AgentVersion: AgentVersion,
	}

	// Create a temporary client for registration
	tmpClient := &Client{
		httpClient: http.DefaultClient,
		store:      config.NewStore(""),
		logger:     logger,
	}

	url := platformURL + "/api/v1/router-agent/register"
	var resp RegisterResponse
	if err := tmpClient.doJSON(ctx, http.MethodPost, url, "", reqBody, &resp); err != nil {
		return nil, fmt.Errorf("registration request: %w", err)
	}

	if !resp.Success {
		return nil, fmt.Errorf("registration failed: %s", resp.Message)
	}

	logger.Info("Registration successful",
		"device_id", resp.Data.DeviceID,
		"wg_configs_count", len(resp.Data.WGConfigs),
	)

	// Write WireGuard configs
	wgKeys := map[string]string{
		"wg0": wgKey1.PrivateKey,
		"wg1": wgKey2.PrivateKey,
	}
	for _, wgCfg := range resp.Data.WGConfigs {
		privateKey, ok := wgKeys[wgCfg.Interface]
		if !ok {
			logger.Warn("Unknown WG interface from registration", "interface", wgCfg.Interface)
			continue
		}
		if err := writeInitialWGConfig(wgCfg, privateKey); err != nil {
			return nil, fmt.Errorf("write WG config %s: %w", wgCfg.Interface, err)
		}
		logger.Info("Wrote WireGuard config", "interface", wgCfg.Interface, "ip", wgCfg.AssignedIP)
	}

	// Build and return agent config
	agentCfg := &config.AgentConfig{
		DeviceID:                  resp.Data.DeviceID,
		AgentKey:                  resp.Data.AgentKey,
		PlatformURL:               platformURL,
		HeartbeatIntervalSeconds:  60,
		ConfigPollIntervalSeconds: 30,
		LocalAPIListen:            "172.10.0.1:8080",
		SerialNumber:              serialNumber,
	}

	return agentCfg, nil
}

// getSerialNumber reads the device serial number from DMI data.
func getSerialNumber() (string, error) {
	// Try DMI serial number first
	data, err := os.ReadFile("/sys/class/dmi/id/product_serial")
	if err == nil {
		serial := strings.TrimSpace(string(data))
		if serial != "" && serial != "To Be Filled By O.E.M." && serial != "Default string" {
			return serial, nil
		}
	}

	// Try board serial
	data, err = os.ReadFile("/sys/class/dmi/id/board_serial")
	if err == nil {
		serial := strings.TrimSpace(string(data))
		if serial != "" && serial != "To Be Filled By O.E.M." && serial != "Default string" {
			return serial, nil
		}
	}

	// Fall back to machine-id
	data, err = os.ReadFile("/etc/machine-id")
	if err == nil {
		machineID := strings.TrimSpace(string(data))
		if len(machineID) > 12 {
			return machineID[:12], nil
		}
		return machineID, nil
	}

	return "", fmt.Errorf("cannot determine device serial number")
}

// generateWGKeyPair generates a WireGuard key pair using pure Go (curve25519).
func generateWGKeyPair() (*WGKeyPair, error) {
	// Try wg command first (more standard)
	privKey, err := exec.Command("wg", "genkey").Output()
	if err == nil {
		privKeyStr := strings.TrimSpace(string(privKey))
		cmd := exec.Command("wg", "pubkey")
		cmd.Stdin = strings.NewReader(privKeyStr)
		pubKey, err := cmd.Output()
		if err == nil {
			return &WGKeyPair{
				PrivateKey: privKeyStr,
				PublicKey:  strings.TrimSpace(string(pubKey)),
			}, nil
		}
	}

	// Fallback: generate using Go's crypto
	var privateKey [32]byte
	if _, err := rand.Read(privateKey[:]); err != nil {
		return nil, fmt.Errorf("generate random bytes: %w", err)
	}

	// Clamp the private key per WireGuard spec
	privateKey[0] &= 248
	privateKey[31] &= 127
	privateKey[31] |= 64

	publicKey, err := curve25519.X25519(privateKey[:], curve25519.Basepoint)
	if err != nil {
		return nil, fmt.Errorf("compute public key: %w", err)
	}

	return &WGKeyPair{
		PrivateKey: base64.StdEncoding.EncodeToString(privateKey[:]),
		PublicKey:  base64.StdEncoding.EncodeToString(publicKey),
	}, nil
}

// writeInitialWGConfig writes the initial WireGuard config from registration.
func writeInitialWGConfig(cfg WGConfigEntry, privateKey string) error {
	if err := os.MkdirAll("/etc/wireguard", 0700); err != nil {
		return fmt.Errorf("create wireguard dir: %w", err)
	}

	content := fmt.Sprintf(`[Interface]
PrivateKey = %s
Address = %s
MTU = %d

[Peer]
PublicKey = %s
Endpoint = %s
AllowedIPs = 10.10.0.0/16
PersistentKeepalive = %d
`, privateKey, cfg.AssignedIP, cfg.MTU, cfg.ServerPublicKey, cfg.ServerEndpoint, cfg.PersistentKeepalive)

	path := fmt.Sprintf("/etc/wireguard/%s.conf", cfg.Interface)
	if err := os.WriteFile(path, []byte(content), 0600); err != nil {
		return fmt.Errorf("write %s: %w", path, err)
	}

	return nil
}
