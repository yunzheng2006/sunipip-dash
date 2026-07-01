package services

import (
	"bufio"
	"context"
	"fmt"
	"log/slog"
	"os"
	"os/exec"
	"strings"

	"sunipip-router-agent/internal/api"
)

// NetworkService manages VLAN interfaces and bridges.
type NetworkService struct {
	logger *slog.Logger
}

// NewNetworkService creates a new network service manager.
func NewNetworkService(logger *slog.Logger) *NetworkService {
	return &NetworkService{logger: logger}
}

// Apply creates/updates VLAN interfaces and bridges, and removes stale ones.
func (s *NetworkService) Apply(ctx context.Context, cfg api.NetworkConfig) error {
	s.logger.Info("Applying network configuration",
		"vlans", len(cfg.VLANs),
		"wan", cfg.WAN.Interface,
		"trunk", cfg.Trunk.Interface,
	)

	// Get currently existing VLAN interfaces on the trunk
	existingVLANs, err := s.getExistingVLANInterfaces(ctx, cfg.Trunk.Interface)
	if err != nil {
		s.logger.Warn("Failed to list existing VLAN interfaces", "error", err)
	}

	// Track desired VLAN interfaces
	desiredVLANs := make(map[string]bool)
	for _, vlan := range cfg.VLANs {
		desiredVLANs[vlan.Interface] = true
	}

	// Remove stale VLANs
	for _, existing := range existingVLANs {
		if !desiredVLANs[existing] {
			s.logger.Info("Removing stale VLAN interface", "interface", existing)
			if err := s.removeVLAN(ctx, existing); err != nil {
				s.logger.Error("Failed to remove stale VLAN", "interface", existing, "error", err)
			}
		}
	}

	// Create/update desired VLANs
	for _, vlan := range cfg.VLANs {
		if err := s.ensureVLAN(ctx, cfg.Trunk.Interface, vlan); err != nil {
			return fmt.Errorf("ensure VLAN %d: %w", vlan.VLANID, err)
		}
	}

	// Ensure trunk interface IP (native VLAN for AP management)
	if cfg.Trunk.IP != "" {
		if err := s.ensureInterfaceIP(ctx, cfg.Trunk.Interface, cfg.Trunk.IP); err != nil {
			s.logger.Warn("Failed to set trunk IP", "interface", cfg.Trunk.Interface, "error", err)
		}
	}

	// v2 flat mode: add WiFi subnet as secondary IP on trunk (no VLAN needed)
	if cfg.Trunk.WifiSubnet != nil && cfg.Trunk.WifiSubnet.IP != "" {
		if err := s.ensureSecondaryIP(ctx, cfg.Trunk.Interface, cfg.Trunk.WifiSubnet.IP); err != nil {
			s.logger.Warn("Failed to set WiFi subnet IP on trunk", "error", err)
		}
	}

	// Ensure management interface IP
	if cfg.Management.IP != "" {
		if err := s.ensureInterfaceIP(ctx, cfg.Management.Interface, cfg.Management.IP); err != nil {
			s.logger.Warn("Failed to set management IP", "interface", cfg.Management.Interface, "error", err)
		}
	}

	// Ensure wired interface IP
	if cfg.Wired.IP != "" {
		if err := s.ensureInterfaceIP(ctx, cfg.Wired.Interface, cfg.Wired.IP); err != nil {
			s.logger.Warn("Failed to set wired IP", "interface", cfg.Wired.Interface, "error", err)
		}
	}

	// Set up TProxy routing — required for Clash transparent proxy
	if err := s.ensureTProxyRouting(ctx); err != nil {
		s.logger.Warn("Failed to set up TProxy routing", "error", err)
	}

	// Router's own DNS — use public DNS for reliability (not ISP DNS)
	s.ensureResolvConf()

	s.logger.Info("Network configuration applied successfully")
	return nil
}

// ensureResolvConf writes /etc/resolv.conf with fixed public DNS servers.
// VLAN client DNS goes through Clash (1.1.1.1 via fake-ip on port 1053).
// The router's own DNS uses 223.5.5.5 (Alibaba) for domestic resolution.
func (s *NetworkService) ensureResolvConf() {
	const resolvPath = "/etc/resolv.conf"
	const desired = "nameserver 223.5.5.5\nnameserver 119.29.29.29\n"

	existing, _ := os.ReadFile(resolvPath)
	if string(existing) == desired {
		return
	}

	if err := os.WriteFile(resolvPath, []byte(desired), 0644); err != nil {
		s.logger.Warn("Failed to write resolv.conf", "error", err)
		return
	}

	// Prevent DHCP client from overwriting resolv.conf
	const dhclientConf = "/etc/dhcp/dhclient.conf"
	const supersede = "supersede domain-name-servers 223.5.5.5, 119.29.29.29;\n"
	if content, err := os.ReadFile(dhclientConf); err != nil || !strings.Contains(string(content), "supersede domain-name-servers") {
		f, err := os.OpenFile(dhclientConf, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0644)
		if err == nil {
			f.WriteString(supersede)
			f.Close()
		}
	}

	s.logger.Info("Set router DNS to 223.5.5.5 / 119.29.29.29")
}

// ensureTProxyRouting sets up the ip rule and ip route entries needed for TProxy.
// Packets marked with fwmark 0x1 (by nftables tproxy rule) are routed to loopback.
func (s *NetworkService) ensureTProxyRouting(ctx context.Context) error {
	// Check if the rule already exists
	cmd := exec.CommandContext(ctx, "ip", "rule", "list")
	output, err := cmd.Output()
	if err != nil {
		return fmt.Errorf("list ip rules: %w", err)
	}

	if !strings.Contains(string(output), "fwmark 0x1") {
		if err := runCmd(ctx, "ip", "rule", "add", "fwmark", "1", "table", "100"); err != nil {
			return fmt.Errorf("add tproxy ip rule: %w", err)
		}
		s.logger.Info("Added TProxy ip rule (fwmark 1 -> table 100)")
	}

	// Check if the route exists in table 100
	cmd = exec.CommandContext(ctx, "ip", "route", "show", "table", "100")
	output, err = cmd.Output()
	if err != nil || !strings.Contains(string(output), "local default") {
		if err := runCmd(ctx, "ip", "route", "replace", "local", "default", "dev", "lo", "table", "100"); err != nil {
			return fmt.Errorf("add tproxy route: %w", err)
		}
		s.logger.Info("Added TProxy route (local default dev lo table 100)")
	}

	return nil
}

// ensureVLAN creates a VLAN interface and bridge if they don't exist.
func (s *NetworkService) ensureVLAN(ctx context.Context, trunkIface string, vlan api.VLANConfig) error {
	s.logger.Debug("Ensuring VLAN", "vlan_id", vlan.VLANID, "interface", vlan.Interface, "bridge", vlan.Bridge)

	// 1. Create VLAN interface if not exists
	if !s.interfaceExists(ctx, vlan.Interface) {
		if err := runCmd(ctx, "ip", "link", "add", "link", trunkIface,
			"name", vlan.Interface, "type", "vlan", "id", fmt.Sprintf("%d", vlan.VLANID)); err != nil {
			return fmt.Errorf("create VLAN interface %s: %w", vlan.Interface, err)
		}
		s.logger.Debug("Created VLAN interface", "interface", vlan.Interface)
	}

	// 2. Create bridge if not exists
	if !s.interfaceExists(ctx, vlan.Bridge) {
		if err := runCmd(ctx, "ip", "link", "add", "name", vlan.Bridge, "type", "bridge"); err != nil {
			return fmt.Errorf("create bridge %s: %w", vlan.Bridge, err)
		}
		s.logger.Debug("Created bridge", "bridge", vlan.Bridge)
	}

	// 3. Add VLAN interface to bridge
	if err := runCmd(ctx, "ip", "link", "set", vlan.Interface, "master", vlan.Bridge); err != nil {
		// May already be set, log but don't fail
		s.logger.Debug("Set master (may already be set)", "interface", vlan.Interface, "bridge", vlan.Bridge, "error", err)
	}

	// 4. Set IP address on bridge
	if err := s.ensureInterfaceIP(ctx, vlan.Bridge, vlan.IP); err != nil {
		return fmt.Errorf("set IP on bridge %s: %w", vlan.Bridge, err)
	}

	// 5. Bring interfaces up
	if err := runCmd(ctx, "ip", "link", "set", vlan.Interface, "up"); err != nil {
		return fmt.Errorf("bring up %s: %w", vlan.Interface, err)
	}
	if err := runCmd(ctx, "ip", "link", "set", vlan.Bridge, "up"); err != nil {
		return fmt.Errorf("bring up %s: %w", vlan.Bridge, err)
	}

	return nil
}

// ensureSecondaryIP adds an IP to an interface without flushing existing IPs.
func (s *NetworkService) ensureSecondaryIP(ctx context.Context, iface, ip string) error {
	currentIPs, _ := s.getInterfaceIPs(ctx, iface)
	for _, cur := range currentIPs {
		if cur == ip {
			return nil
		}
	}
	if err := runCmd(ctx, "ip", "addr", "add", ip, "dev", iface); err != nil {
		if strings.Contains(err.Error(), "RTNETLINK answers: File exists") {
			return nil
		}
		return fmt.Errorf("add secondary IP %s to %s: %w", ip, iface, err)
	}
	s.logger.Info("Added WiFi subnet secondary IP", "interface", iface, "ip", ip)
	return nil
}

// ensureInterfaceIP ensures an interface has the specified IP address.
func (s *NetworkService) ensureInterfaceIP(ctx context.Context, iface, ip string) error {
	// Check if IP is already assigned
	currentIPs, err := s.getInterfaceIPs(ctx, iface)
	if err != nil {
		s.logger.Debug("Could not get current IPs", "interface", iface, "error", err)
	}

	for _, currentIP := range currentIPs {
		if currentIP == ip {
			return nil // Already has the correct IP
		}
	}

	// Flush existing IPs and add the new one
	runCmd(ctx, "ip", "addr", "flush", "dev", iface) // ignore error
	if err := runCmd(ctx, "ip", "addr", "add", ip, "dev", iface); err != nil {
		return fmt.Errorf("add IP %s to %s: %w", ip, iface, err)
	}

	return nil
}

// removeVLAN removes a VLAN interface and its associated bridge.
func (s *NetworkService) removeVLAN(ctx context.Context, vlanIface string) error {
	// Determine bridge name from VLAN interface (e.g., eth2.10 -> br-vlan10)
	parts := strings.SplitN(vlanIface, ".", 2)
	if len(parts) != 2 {
		return fmt.Errorf("unexpected VLAN interface name format: %s", vlanIface)
	}
	bridgeName := "br-vlan" + parts[1]

	// Bring down and delete bridge
	runCmd(ctx, "ip", "link", "set", bridgeName, "down")
	runCmd(ctx, "ip", "link", "delete", bridgeName)

	// Bring down and delete VLAN interface
	runCmd(ctx, "ip", "link", "set", vlanIface, "down")
	runCmd(ctx, "ip", "link", "delete", vlanIface)

	return nil
}

// interfaceExists checks if a network interface exists.
func (s *NetworkService) interfaceExists(ctx context.Context, iface string) bool {
	cmd := exec.CommandContext(ctx, "ip", "link", "show", iface)
	return cmd.Run() == nil
}

// getExistingVLANInterfaces lists VLAN interfaces on the given trunk.
func (s *NetworkService) getExistingVLANInterfaces(ctx context.Context, trunkIface string) ([]string, error) {
	cmd := exec.CommandContext(ctx, "ip", "-o", "link", "show", "type", "vlan")
	output, err := cmd.Output()
	if err != nil {
		return nil, err
	}

	prefix := trunkIface + "."
	var vlans []string
	scanner := bufio.NewScanner(strings.NewReader(string(output)))
	for scanner.Scan() {
		line := scanner.Text()
		// Format: "3: eth2.10@eth2: ..."
		fields := strings.Fields(line)
		if len(fields) >= 2 {
			name := strings.TrimRight(fields[1], ":")
			// Remove @parent suffix
			if idx := strings.Index(name, "@"); idx >= 0 {
				name = name[:idx]
			}
			if strings.HasPrefix(name, prefix) {
				vlans = append(vlans, name)
			}
		}
	}

	return vlans, nil
}

// getInterfaceIPs returns the IP addresses assigned to an interface.
func (s *NetworkService) getInterfaceIPs(ctx context.Context, iface string) ([]string, error) {
	cmd := exec.CommandContext(ctx, "ip", "-o", "addr", "show", "dev", iface)
	output, err := cmd.Output()
	if err != nil {
		return nil, err
	}

	var ips []string
	scanner := bufio.NewScanner(strings.NewReader(string(output)))
	for scanner.Scan() {
		fields := strings.Fields(scanner.Text())
		for i, f := range fields {
			if f == "inet" || f == "inet6" {
				if i+1 < len(fields) {
					ips = append(ips, fields[i+1])
				}
			}
		}
	}

	return ips, nil
}

// WANStatus represents the current WAN interface configuration.
type WANStatus struct {
	Mode    string `json:"mode"`    // dhcp, static, pppoe
	IP      string `json:"ip"`
	Gateway string `json:"gateway"`
	DNS1    string `json:"dns1"`
	DNS2    string `json:"dns2"`
	// Static-only
	Netmask string `json:"netmask,omitempty"`
	// PPPoE-only
	PPPoEUser string `json:"pppoe_user,omitempty"`
}

// WANSetRequest is the request body for setting WAN configuration.
type WANSetRequest struct {
	Mode      string `json:"mode"` // dhcp, static, pppoe
	IP        string `json:"ip,omitempty"`
	Netmask   string `json:"netmask,omitempty"`
	Gateway   string `json:"gateway,omitempty"`
	DNS1      string `json:"dns1,omitempty"`
	DNS2      string `json:"dns2,omitempty"`
	PPPoEUser string `json:"pppoe_user,omitempty"`
	PPPoEPass string `json:"pppoe_pass,omitempty"`
}

// GetWANStatus reads the current WAN configuration and runtime network state.
func (s *NetworkService) GetWANStatus(ctx context.Context, wanIface string) (*WANStatus, error) {
	status := &WANStatus{Mode: "dhcp"}

	// Check PPPoE first
	if _, err := os.Stat("/etc/ppp/peers/wan"); err == nil {
		status.Mode = "pppoe"
		if peerContent, err := os.ReadFile("/etc/ppp/peers/wan"); err == nil {
			for _, line := range strings.Split(string(peerContent), "\n") {
				line = strings.TrimSpace(line)
				if strings.HasPrefix(line, "user ") {
					status.PPPoEUser = strings.Trim(strings.TrimPrefix(line, "user "), "\"")
				}
			}
		}
	}

	// Parse WAN interface config (check dedicated file first, then main interfaces file)
	for _, path := range []string{"/etc/network/interfaces.d/wan", "/etc/network/interfaces"} {
		content, err := os.ReadFile(path)
		if err != nil {
			continue
		}
		for _, line := range strings.Split(string(content), "\n") {
			line = strings.TrimSpace(line)
			if strings.HasPrefix(line, "iface "+wanIface+" inet dhcp") {
				status.Mode = "dhcp"
			} else if strings.HasPrefix(line, "iface "+wanIface+" inet static") {
				status.Mode = "static"
			} else if strings.HasPrefix(line, "iface "+wanIface+" inet ppp") || strings.Contains(line, "pppoe") {
				status.Mode = "pppoe"
			} else if strings.HasPrefix(line, "address ") {
				status.IP = strings.TrimPrefix(line, "address ")
			} else if strings.HasPrefix(line, "netmask ") {
				status.Netmask = strings.TrimPrefix(line, "netmask ")
			} else if strings.HasPrefix(line, "gateway ") {
				status.Gateway = strings.TrimPrefix(line, "gateway ")
			} else if strings.HasPrefix(line, "dns-nameservers ") {
				parts := strings.Fields(strings.TrimPrefix(line, "dns-nameservers "))
				if len(parts) >= 1 {
					status.DNS1 = parts[0]
				}
				if len(parts) >= 2 {
					status.DNS2 = parts[1]
				}
			}
		}
	}

	// Always fill runtime IP for DHCP or when static config doesn't have it
	if status.Mode == "dhcp" || status.IP == "" {
		ips, err := s.getInterfaceIPs(ctx, wanIface)
		if err == nil && len(ips) > 0 {
			status.IP = strings.Split(ips[0], "/")[0]
		}
	}

	// Always fill runtime gateway if not set
	if status.Gateway == "" {
		cmd := exec.CommandContext(ctx, "ip", "route", "show", "default")
		if out, err := cmd.Output(); err == nil {
			fields := strings.Fields(string(out))
			for i, f := range fields {
				if f == "via" && i+1 < len(fields) {
					status.Gateway = fields[i+1]
					break
				}
			}
		}
	}

	// Always fill DNS from resolv.conf if not set
	if status.DNS1 == "" {
		if resolvContent, err := os.ReadFile("/etc/resolv.conf"); err == nil {
			var dnsIdx int
			for _, line := range strings.Split(string(resolvContent), "\n") {
				line = strings.TrimSpace(line)
				if strings.HasPrefix(line, "nameserver ") {
					ns := strings.TrimPrefix(line, "nameserver ")
					if dnsIdx == 0 {
						status.DNS1 = ns
					} else if dnsIdx == 1 {
						status.DNS2 = ns
					}
					dnsIdx++
				}
			}
		}
	}

	return status, nil
}

// SetWANConfig writes WAN configuration files and applies them.
func (s *NetworkService) SetWANConfig(ctx context.Context, wanIface string, req WANSetRequest) error {
	s.logger.Info("Setting WAN configuration", "mode", req.Mode, "interface", wanIface)

	switch req.Mode {
	case "dhcp":
		return s.setWANDHCP(ctx, wanIface)
	case "static":
		if req.IP == "" || req.Gateway == "" {
			return fmt.Errorf("static mode requires ip and gateway")
		}
		return s.setWANStatic(ctx, wanIface, req)
	case "pppoe":
		if req.PPPoEUser == "" || req.PPPoEPass == "" {
			return fmt.Errorf("pppoe mode requires pppoe_user and pppoe_pass")
		}
		return s.setWANPPPoE(ctx, wanIface, req)
	default:
		return fmt.Errorf("unsupported WAN mode: %s", req.Mode)
	}
}

func (s *NetworkService) setWANDHCP(ctx context.Context, wanIface string) error {
	content := fmt.Sprintf("auto %s\niface %s inet dhcp\n", wanIface, wanIface)
	if err := os.WriteFile("/etc/network/interfaces.d/wan", []byte(content), 0644); err != nil {
		return fmt.Errorf("write wan config: %w", err)
	}
	// Remove PPPoE config if present
	os.Remove("/etc/ppp/peers/wan")

	return s.restartNetworking(ctx, wanIface)
}

func (s *NetworkService) setWANStatic(ctx context.Context, wanIface string, req WANSetRequest) error {
	netmask := req.Netmask
	if netmask == "" {
		netmask = "255.255.255.0"
	}
	dns := "223.5.5.5 119.29.29.29"
	if req.DNS1 != "" {
		dns = req.DNS1
		if req.DNS2 != "" {
			dns += " " + req.DNS2
		}
	}

	content := fmt.Sprintf(`auto %s
iface %s inet static
    address %s
    netmask %s
    gateway %s
    dns-nameservers %s
`, wanIface, wanIface, req.IP, netmask, req.Gateway, dns)

	if err := os.WriteFile("/etc/network/interfaces.d/wan", []byte(content), 0644); err != nil {
		return fmt.Errorf("write wan config: %w", err)
	}
	os.Remove("/etc/ppp/peers/wan")

	return s.restartNetworking(ctx, wanIface)
}

func (s *NetworkService) setWANPPPoE(ctx context.Context, wanIface string, req WANSetRequest) error {
	// Write pppoe peer config
	peerContent := fmt.Sprintf(`plugin pppoe.so %s
user "%s"
password "%s"
persist
maxfail 0
holdoff 5
defaultroute
replacedefaultroute
usepeerdns
noauth
hide-password
lcp-echo-interval 20
lcp-echo-failure 3
`, wanIface, req.PPPoEUser, req.PPPoEPass)

	if err := os.MkdirAll("/etc/ppp/peers", 0755); err != nil {
		return fmt.Errorf("create ppp peers dir: %w", err)
	}
	if err := os.WriteFile("/etc/ppp/peers/wan", []byte(peerContent), 0600); err != nil {
		return fmt.Errorf("write pppoe config: %w", err)
	}

	// Write interface config to bring up PPPoE
	ifaceContent := fmt.Sprintf(`auto %s
iface %s inet manual
    pre-up /bin/ip link set %s up
    post-up /usr/bin/pon wan

auto wan
iface wan inet ppp
    provider wan
`, wanIface, wanIface, wanIface)

	if err := os.WriteFile("/etc/network/interfaces.d/wan", []byte(ifaceContent), 0644); err != nil {
		return fmt.Errorf("write wan interface config: %w", err)
	}

	return s.restartNetworking(ctx, wanIface)
}

func (s *NetworkService) restartNetworking(ctx context.Context, wanIface string) error {
	// Bring down and back up the WAN interface
	runCmd(ctx, "ip", "link", "set", wanIface, "down")
	runCmd(ctx, "ifdown", wanIface)

	// Kill any existing pppd
	runCmd(ctx, "poff", "wan")

	if err := runCmd(ctx, "ifup", wanIface); err != nil {
		s.logger.Error("Failed to bring up WAN interface", "error", err)
		return fmt.Errorf("bring up WAN: %w", err)
	}
	s.logger.Info("WAN interface restarted", "interface", wanIface)
	return nil
}

// GetVLANInfo returns information about current VLAN interfaces for the local API.
type VLANInfo struct {
	VLANID    int    `json:"vlan_id"`
	Interface string `json:"interface"`
	Bridge    string `json:"bridge"`
	IP        string `json:"ip"`
	State     string `json:"state"`
}

// GetVLANInterfaces returns info about all VLAN interfaces.
func (s *NetworkService) GetVLANInterfaces(ctx context.Context) ([]VLANInfo, error) {
	cmd := exec.CommandContext(ctx, "ip", "-o", "link", "show", "type", "vlan")
	output, err := cmd.Output()
	if err != nil {
		return nil, err
	}

	var vlans []VLANInfo
	scanner := bufio.NewScanner(strings.NewReader(string(output)))
	for scanner.Scan() {
		line := scanner.Text()
		fields := strings.Fields(line)
		if len(fields) < 2 {
			continue
		}
		name := strings.TrimRight(fields[1], ":")
		if idx := strings.Index(name, "@"); idx >= 0 {
			name = name[:idx]
		}

		parts := strings.SplitN(name, ".", 2)
		if len(parts) != 2 {
			continue
		}

		state := "unknown"
		for _, f := range fields {
			if f == "UP" || f == "DOWN" || f == "UNKNOWN" {
				state = strings.ToLower(f)
				break
			}
		}

		vlanID := 0
		fmt.Sscanf(parts[1], "%d", &vlanID)

		vlan := VLANInfo{
			VLANID:    vlanID,
			Interface: name,
			Bridge:    "br-vlan" + parts[1],
			State:     state,
		}

		// Get IP
		ips, err := s.getInterfaceIPs(ctx, vlan.Bridge)
		if err == nil && len(ips) > 0 {
			vlan.IP = ips[0]
		}

		vlans = append(vlans, vlan)
	}

	return vlans, nil
}

// runCmd runs a command and returns an error if it fails.
func runCmd(ctx context.Context, name string, args ...string) error {
	cmd := exec.CommandContext(ctx, name, args...)
	if output, err := cmd.CombinedOutput(); err != nil {
		return fmt.Errorf("%s %s: %w (output: %s)", name, strings.Join(args, " "), err, strings.TrimSpace(string(output)))
	}
	return nil
}
