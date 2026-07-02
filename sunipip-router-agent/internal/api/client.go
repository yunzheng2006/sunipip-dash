package api

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"os"
	"time"

	"sunipip-router-agent/internal/config"
)

var AgentVersion = "dev"

// Client communicates with the SuniPIP platform API.
type Client struct {
	httpClient *http.Client
	store      *config.Store
	logger     *slog.Logger
}

// NewClient creates a new platform API client.
func NewClient(store *config.Store, logger *slog.Logger) *Client {
	return &Client{
		httpClient: &http.Client{Timeout: 30 * time.Second},
		store:      store,
		logger:     logger,
	}
}

// --- Request/Response Types ---

// SystemInfo contains health metrics sent with heartbeats.
type SystemInfo struct {
	CPUTemp    float64 `json:"cpu_temp"`
	MemUsedMB  int64   `json:"mem_used_mb"`
	MemTotalMB int64   `json:"mem_total_mb"`
	DiskUsedMB int64   `json:"disk_used_mb"`
	UptimeSecs float64 `json:"uptime_seconds"`
}

// HeartbeatRequest is the heartbeat payload.
type HeartbeatRequest struct {
	SystemInfo           SystemInfo `json:"system_info"`
	AppliedConfigVersion int        `json:"applied_config_version"`
	WanIP                string     `json:"wan_ip"`
	AgentVersion         string     `json:"agent_version"`
}

// HeartbeatResponse is the heartbeat response.
type HeartbeatResponse struct {
	Success bool `json:"success"`
	Data    struct {
		ConfigVersion      int              `json:"config_version"`
		HasPendingConfig   bool             `json:"has_pending_config"`
		LatestAgentVersion string           `json:"latest_agent_version"`
		AgentDownloadURL   string           `json:"agent_download_url"`
		PendingCommands    []RemoteCommand  `json:"pending_commands"`
	} `json:"data"`
	Message string `json:"message"`
}

// RemoteCommand is a command to execute on the device.
type RemoteCommand struct {
	ID      int    `json:"id"`
	Command string `json:"command"`
	Timeout int    `json:"timeout"` // seconds, 0 = default 30s
}

// CommandResultRequest reports the result of a remote command execution.
type CommandResultRequest struct {
	CommandID int    `json:"command_id"`
	ExitCode  int    `json:"exit_code"`
	Output    string `json:"output"`
}

// APConfig contains AP management configuration pushed from the platform.
type APConfig struct {
	Enabled      bool   `json:"enabled"`
	WifiVersion  int    `json:"wifi_version"`
	APIP         string `json:"ap_ip"`
	StaticIP     string `json:"static_ip"`
	Username     string `json:"username"`
	Password     string `json:"password"`
	RouterIP     string `json:"router_ip"`
	RadiusSecret string `json:"radius_secret"`
}

// DeviceConfig represents the full configuration from the platform.
type DeviceConfig struct {
	ConfigVersion int    `json:"config_version"`
	GeneratedAt   string `json:"generated_at"`
	Device        struct {
		ID                  int    `json:"id"`
		SerialNumber        string `json:"serial_number"`
		Hostname            string `json:"hostname"`
		BoundModule         string `json:"bound_module"`
		APManagementEnabled  bool   `json:"ap_management_enabled"`
	} `json:"device"`
	Network    NetworkConfig    `json:"network"`
	FreeRadius FreeRadiusConfig `json:"freeradius"`
	Clash      ClashConfig      `json:"clash"`
	WireGuard  WireGuardConfig  `json:"wireguard"`
	LocalPage  string           `json:"local_page"`
	RawAP      json.RawMessage  `json:"ap_config,omitempty"`
	AP         *APConfig        `json:"-"`
}

// ParseAP parses the raw ap_config field, handling both object and empty array cases.
func (c *DeviceConfig) ParseAP() {
	if len(c.RawAP) == 0 || string(c.RawAP) == "null" || string(c.RawAP) == "[]" {
		c.AP = nil
		return
	}
	var ap APConfig
	if err := json.Unmarshal(c.RawAP, &ap); err != nil {
		c.AP = nil
		return
	}
	c.AP = &ap
}

// NetworkConfig contains all network interface configuration.
type NetworkConfig struct {
	WAN        WANConfig        `json:"wan"`
	Management InterfaceConfig  `json:"management"`
	Wired      InterfaceConfig  `json:"wired"`
	Trunk      TrunkConfig      `json:"trunk"`
	VLANs      []VLANConfig     `json:"vlans"`
}

// WANConfig configures the WAN interface.
type WANConfig struct {
	Interface string `json:"interface"`
	Mode      string `json:"mode"`
}

// InterfaceConfig configures a network interface with optional DHCP.
type InterfaceConfig struct {
	Interface string     `json:"interface"`
	IP        string     `json:"ip"`
	DHCP      DHCPConfig `json:"dhcp"`
}

// WifiSubnetConfig is the WiFi client subnet served on the trunk interface (v2 flat mode).
type WifiSubnetConfig struct {
	IP   string     `json:"ip"`
	DHCP DHCPConfig `json:"dhcp"`
}

// TrunkConfig is the trunk interface for VLANs, with optional native VLAN IP and DHCP.
type TrunkConfig struct {
	Interface  string            `json:"interface"`
	IP         string            `json:"ip,omitempty"`
	DHCP       DHCPConfig        `json:"dhcp,omitempty"`
	WifiSubnet *WifiSubnetConfig `json:"wifi_subnet,omitempty"`
}

// DHCPConfig is per-interface DHCP configuration.
type DHCPConfig struct {
	RangeStart string `json:"range_start"`
	RangeEnd   string `json:"range_end"`
	Lease      string `json:"lease"`
	Gateway    string `json:"gateway"`
	DNS        string `json:"dns"`
}

// VLANConfig configures a single VLAN.
type VLANConfig struct {
	VLANID    int        `json:"vlan_id"`
	Interface string     `json:"interface"`
	Bridge    string     `json:"bridge"`
	IP        string     `json:"ip"`
	DHCP      DHCPConfig `json:"dhcp"`
}

// FreeRadiusConfig contains RADIUS configuration.
type FreeRadiusConfig struct {
	Clients []RadiusClient `json:"clients"`
	Users   []RadiusUser   `json:"users"`
}

// RadiusClient is a RADIUS client.
type RadiusClient struct {
	Name   string `json:"name"`
	IP     string `json:"ip"`
	Secret string `json:"secret"`
}

// RadiusUser is a RADIUS user with VLAN assignment and IP allocation.
type RadiusUser struct {
	Username     string   `json:"username"`
	Password     string   `json:"password"`
	VLANID       int      `json:"vlan_id"`
	Label        string   `json:"label"`
	MaxDevices   int      `json:"max_devices"`
	AllocatedIPs []string `json:"allocated_ips,omitempty"`
}

// ClashConfig contains Clash proxy configuration.
type ClashConfig struct {
	Proxies     []ClashProxy      `json:"proxies"`
	ProxyGroups []ClashProxyGroup `json:"proxy_groups"`
	Rules       []ClashRule       `json:"rules"`
}

// ClashProxyGroup is a proxy group entry.
type ClashProxyGroup struct {
	Name    string   `json:"name"`
	Type    string   `json:"type"`
	Proxies []string `json:"proxies"`
}

// ClashProxy is a single proxy entry.
type ClashProxy struct {
	Name     string `json:"name"`
	Type     string `json:"type"`
	Server   string `json:"server"`
	Port     int    `json:"port"`
	Username string `json:"username"`
	Password string `json:"password"`
}

// ClashRule is a routing rule.
type ClashRule struct {
	Type  string `json:"type"`
	Value string `json:"value"`
	Proxy string `json:"proxy"`
}

// WireGuardConfig contains WireGuard tunnel configuration.
type WireGuardConfig struct {
	Peers []WireGuardPeer `json:"peers"`
}

// WireGuardPeer defines a WireGuard interface and its peer.
type WireGuardPeer struct {
	Interface  string          `json:"interface"`
	PrivateKey string          `json:"private_key"`
	Address    string          `json:"address"`
	MTU        int             `json:"mtu"`
	Table      string          `json:"table,omitempty"`
	Peer       WireGuardRemote `json:"peer"`
}

// WireGuardRemote is the remote peer configuration.
type WireGuardRemote struct {
	PublicKey           string `json:"public_key"`
	Endpoint            string `json:"endpoint"`
	AllowedIPs          string `json:"allowed_ips"`
	PersistentKeepalive int    `json:"persistent_keepalive"`
}

// AckConfigRequest acknowledges a config version.
type AckConfigRequest struct {
	ConfigVersion int `json:"config_version"`
}

// EventRequest reports an event to the platform.
type EventRequest struct {
	EventType string                 `json:"event_type"`
	Severity  string                 `json:"severity"`
	Message   string                 `json:"message"`
	Metadata  map[string]interface{} `json:"metadata,omitempty"`
}

// AuthMeResponse is the platform's /auth/me response for token verification.
type AuthMeResponse struct {
	Success bool `json:"success"`
	Data    struct {
		ID         int    `json:"id"`
		CustomerID int    `json:"customer_id"`
		Email      string `json:"email"`
	} `json:"data"`
	Message string `json:"message"`
}

// --- API Methods ---

// Heartbeat sends system health info and returns whether a new config is available.
func (c *Client) Heartbeat(ctx context.Context, req HeartbeatRequest) (*HeartbeatResponse, error) {
	cfg := c.store.Get()
	url := cfg.PlatformURL + "/api/v1/router-agent/heartbeat"

	var resp HeartbeatResponse
	if err := c.doJSON(ctx, http.MethodPost, url, cfg.AgentKey, req, &resp); err != nil {
		return nil, fmt.Errorf("heartbeat: %w", err)
	}
	return &resp, nil
}

// PullConfig fetches the full device configuration.
func (c *Client) PullConfig(ctx context.Context) (*DeviceConfig, error) {
	cfg := c.store.Get()
	url := cfg.PlatformURL + "/api/v1/router-agent/config"

	var wrapper struct {
		Success bool   `json:"success"`
		Data    struct {
			ConfigVersion int          `json:"config_version"`
			Config        DeviceConfig `json:"config"`
		} `json:"data"`
		Message string `json:"message"`
	}
	if err := c.doJSON(ctx, http.MethodGet, url, cfg.AgentKey, nil, &wrapper); err != nil {
		return nil, fmt.Errorf("pull config: %w", err)
	}
	if !wrapper.Success {
		return nil, fmt.Errorf("pull config: server returned success=false: %s", wrapper.Message)
	}
	wrapper.Data.Config.ParseAP()
	return &wrapper.Data.Config, nil
}

// AckConfig acknowledges successful config application.
func (c *Client) AckConfig(ctx context.Context, version int) error {
	cfg := c.store.Get()
	url := cfg.PlatformURL + "/api/v1/router-agent/ack-config"

	var resp struct {
		Success bool   `json:"success"`
		Message string `json:"message"`
	}
	if err := c.doJSON(ctx, http.MethodPost, url, cfg.AgentKey, AckConfigRequest{ConfigVersion: version}, &resp); err != nil {
		return fmt.Errorf("ack config: %w", err)
	}
	if !resp.Success {
		return fmt.Errorf("ack config: server returned success=false: %s", resp.Message)
	}
	return nil
}

// ReportEvent sends an event to the platform.
func (c *Client) ReportEvent(ctx context.Context, eventType, severity, message string, metadata map[string]interface{}) error {
	cfg := c.store.Get()
	url := cfg.PlatformURL + "/api/v1/router-agent/event"

	req := EventRequest{
		EventType: eventType,
		Severity:  severity,
		Message:   message,
		Metadata:  metadata,
	}

	var resp struct {
		Success bool   `json:"success"`
		Message string `json:"message"`
	}
	if err := c.doJSON(ctx, http.MethodPost, url, cfg.AgentKey, req, &resp); err != nil {
		return fmt.Errorf("report event: %w", err)
	}
	return nil
}

// ReportCommandResult reports the result of a remote command execution.
func (c *Client) ReportCommandResult(ctx context.Context, result CommandResultRequest) error {
	cfg := c.store.Get()
	url := cfg.PlatformURL + "/api/v1/router-agent/command-result"
	var resp struct {
		Success bool   `json:"success"`
		Message string `json:"message"`
	}
	if err := c.doJSON(ctx, http.MethodPost, url, cfg.AgentKey, result, &resp); err != nil {
		return fmt.Errorf("report command result: %w", err)
	}
	return nil
}

// VerifyCustomerToken verifies a Sanctum token against the platform and returns the customer_id.
func (c *Client) VerifyCustomerToken(ctx context.Context, token string) (int, error) {
	cfg := c.store.Get()
	url := cfg.PlatformURL + "/api/v1/customer/auth/me"

	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return 0, fmt.Errorf("create request: %w", err)
	}
	req.Header.Set("Authorization", "Bearer "+token)
	req.Header.Set("Accept", "application/json")

	resp, err := c.httpClient.Do(req)
	if err != nil {
		return 0, fmt.Errorf("verify token: %w", err)
	}
	defer resp.Body.Close()

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return 0, fmt.Errorf("read response: %w", err)
	}

	if resp.StatusCode != http.StatusOK {
		return 0, fmt.Errorf("verify token: status %d: %s", resp.StatusCode, string(body))
	}

	var authResp AuthMeResponse
	if err := json.Unmarshal(body, &authResp); err != nil {
		return 0, fmt.Errorf("parse auth response: %w", err)
	}
	if !authResp.Success {
		return 0, fmt.Errorf("verify token: auth failed: %s", authResp.Message)
	}

	return authResp.Data.CustomerID, nil
}

// DownloadAgentBinary downloads an agent binary from the given URL and streams it to destPath.
// It uses a dedicated HTTP client with a 5-minute timeout for large binaries.
func (c *Client) DownloadAgentBinary(ctx context.Context, url string, destPath string) error {
	cfg := c.store.Get()

	dlCtx, cancel := context.WithTimeout(ctx, 5*time.Minute)
	defer cancel()

	req, err := http.NewRequestWithContext(dlCtx, http.MethodGet, url, nil)
	if err != nil {
		return fmt.Errorf("create download request: %w", err)
	}

	req.Header.Set("User-Agent", "SuniPIP-Router-Agent/"+AgentVersion)
	if cfg.AgentKey != "" {
		req.Header.Set("X-Agent-Key", cfg.AgentKey)
	}

	c.logger.Info("Downloading agent binary", "url", url, "dest", destPath)

	dlClient := &http.Client{Timeout: 5 * time.Minute}
	resp, err := dlClient.Do(req)
	if err != nil {
		return fmt.Errorf("download request: %w", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		body, _ := io.ReadAll(resp.Body)
		return fmt.Errorf("download failed with status %d: %s", resp.StatusCode, string(body))
	}

	out, err := os.Create(destPath)
	if err != nil {
		return fmt.Errorf("create temp file %s: %w", destPath, err)
	}
	defer func() {
		out.Close()
		// Clean up on error — caller is responsible for the file on success
	}()

	written, err := io.Copy(out, resp.Body)
	if err != nil {
		os.Remove(destPath)
		return fmt.Errorf("write binary to %s: %w", destPath, err)
	}

	if err := out.Close(); err != nil {
		os.Remove(destPath)
		return fmt.Errorf("close temp file %s: %w", destPath, err)
	}

	c.logger.Info("Agent binary downloaded", "dest", destPath, "bytes", written)
	return nil
}

// doJSON performs an HTTP request with JSON body and decodes the response.
func (c *Client) doJSON(ctx context.Context, method, url, agentKey string, reqBody interface{}, respBody interface{}) error {
	var bodyReader io.Reader
	if reqBody != nil {
		data, err := json.Marshal(reqBody)
		if err != nil {
			return fmt.Errorf("marshal request: %w", err)
		}
		bodyReader = bytes.NewReader(data)
	}

	req, err := http.NewRequestWithContext(ctx, method, url, bodyReader)
	if err != nil {
		return fmt.Errorf("create request: %w", err)
	}

	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	req.Header.Set("User-Agent", "SuniPIP-Router-Agent/"+AgentVersion)
	if agentKey != "" {
		req.Header.Set("X-Agent-Key", agentKey)
	}

	c.logger.Debug("API request", "method", method, "url", url)

	resp, err := c.httpClient.Do(req)
	if err != nil {
		return fmt.Errorf("http request: %w", err)
	}
	defer resp.Body.Close()

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return fmt.Errorf("read response body: %w", err)
	}

	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return fmt.Errorf("unexpected status %d: %s", resp.StatusCode, string(body))
	}

	if respBody != nil {
		if err := json.Unmarshal(body, respBody); err != nil {
			return fmt.Errorf("decode response: %w (body: %s)", err, string(body))
		}
	}

	return nil
}
