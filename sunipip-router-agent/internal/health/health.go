package health

import (
	"bufio"
	"context"
	"log/slog"
	"net"
	"os"
	"os/exec"
	"strconv"
	"strings"

	"sunipip-router-agent/internal/api"
)

// Collector gathers system health information.
type Collector struct {
	logger *slog.Logger
}

// NewCollector creates a new health info collector.
func NewCollector(logger *slog.Logger) *Collector {
	return &Collector{logger: logger}
}

// Collect gathers current system health metrics.
func (c *Collector) Collect(ctx context.Context) api.SystemInfo {
	info := api.SystemInfo{}

	info.CPUTemp = c.getCPUTemp()
	info.MemUsedMB, info.MemTotalMB = c.getMemoryInfo()
	info.DiskUsedMB = c.getDiskUsed(ctx)
	info.UptimeSecs = c.getUptime()

	return info
}

// GetWANIP returns the IP address of the default route interface.
func (c *Collector) GetWANIP(ctx context.Context) string {
	// Get default route interface
	iface := c.getDefaultRouteInterface(ctx)
	if iface == "" {
		c.logger.Debug("Could not determine default route interface")
		return ""
	}

	// Get IP of that interface
	ip := c.getInterfaceIP(ctx, iface)
	return ip
}

// getCPUTemp reads CPU temperature from thermal zone.
func (c *Collector) getCPUTemp() float64 {
	data, err := os.ReadFile("/sys/class/thermal/thermal_zone0/temp")
	if err != nil {
		c.logger.Debug("Could not read CPU temp", "error", err)
		return 0
	}

	raw := strings.TrimSpace(string(data))
	tempMilliC, err := strconv.ParseFloat(raw, 64)
	if err != nil {
		return 0
	}

	// Convert from millidegrees to degrees
	return tempMilliC / 1000.0
}

// getMemoryInfo reads memory information from /proc/meminfo.
func (c *Collector) getMemoryInfo() (usedMB, totalMB int64) {
	file, err := os.Open("/proc/meminfo")
	if err != nil {
		c.logger.Debug("Could not read meminfo", "error", err)
		return 0, 0
	}
	defer file.Close()

	var memTotalKB, memAvailableKB int64
	scanner := bufio.NewScanner(file)
	for scanner.Scan() {
		line := scanner.Text()
		fields := strings.Fields(line)
		if len(fields) < 2 {
			continue
		}

		switch fields[0] {
		case "MemTotal:":
			memTotalKB, _ = strconv.ParseInt(fields[1], 10, 64)
		case "MemAvailable:":
			memAvailableKB, _ = strconv.ParseInt(fields[1], 10, 64)
		}
	}

	totalMB = memTotalKB / 1024
	usedMB = (memTotalKB - memAvailableKB) / 1024
	return usedMB, totalMB
}

// getDiskUsed returns the disk used in MB for the root filesystem.
func (c *Collector) getDiskUsed(ctx context.Context) int64 {
	cmd := exec.CommandContext(ctx, "df", "-B1", "/")
	output, err := cmd.Output()
	if err != nil {
		c.logger.Debug("Could not get disk usage", "error", err)
		return 0
	}

	lines := strings.Split(string(output), "\n")
	if len(lines) < 2 {
		return 0
	}

	// Parse the second line
	fields := strings.Fields(lines[1])
	if len(fields) < 4 {
		return 0
	}

	usedBytes, err := strconv.ParseInt(fields[2], 10, 64)
	if err != nil {
		return 0
	}

	return usedBytes / (1024 * 1024) // Convert bytes to MB
}

// getUptime reads system uptime from /proc/uptime.
func (c *Collector) getUptime() float64 {
	data, err := os.ReadFile("/proc/uptime")
	if err != nil {
		c.logger.Debug("Could not read uptime", "error", err)
		return 0
	}

	fields := strings.Fields(string(data))
	if len(fields) < 1 {
		return 0
	}

	uptime, err := strconv.ParseFloat(fields[0], 64)
	if err != nil {
		return 0
	}

	return uptime
}

// getDefaultRouteInterface finds the interface used for the default route.
func (c *Collector) getDefaultRouteInterface(ctx context.Context) string {
	cmd := exec.CommandContext(ctx, "ip", "route", "show", "default")
	output, err := cmd.Output()
	if err != nil {
		return ""
	}

	// Format: "default via 192.168.1.1 dev eth0 ..."
	fields := strings.Fields(string(output))
	for i, f := range fields {
		if f == "dev" && i+1 < len(fields) {
			return fields[i+1]
		}
	}

	return ""
}

// getInterfaceIP returns the first IPv4 address on an interface.
func (c *Collector) getInterfaceIP(ctx context.Context, iface string) string {
	ifi, err := net.InterfaceByName(iface)
	if err != nil {
		return ""
	}

	addrs, err := ifi.Addrs()
	if err != nil {
		return ""
	}

	for _, addr := range addrs {
		if ipNet, ok := addr.(*net.IPNet); ok {
			if ipv4 := ipNet.IP.To4(); ipv4 != nil {
				return ipv4.String()
			}
		}
	}

	return ""
}

// ConnectedDevice represents a device seen on the network.
type ConnectedDevice struct {
	IP        string `json:"ip"`
	MAC       string `json:"mac"`
	Interface string `json:"interface"`
	Hostname  string `json:"hostname,omitempty"`
}

// GetConnectedDevices returns devices seen in ARP and DHCP lease tables.
func (c *Collector) GetConnectedDevices(ctx context.Context) []ConnectedDevice {
	var devices []ConnectedDevice
	seen := make(map[string]bool) // key by IP

	// Read ARP table
	arpDevices := c.readARPTable()
	for _, d := range arpDevices {
		if !seen[d.IP] {
			seen[d.IP] = true
			devices = append(devices, d)
		}
	}

	// Read DHCP leases to get hostnames
	leases := c.readDHCPLeases()
	for i := range devices {
		if lease, ok := leases[devices[i].MAC]; ok {
			if devices[i].Hostname == "" {
				devices[i].Hostname = lease.Hostname
			}
		}
	}

	// Add any lease entries not in ARP
	for _, lease := range leases {
		if !seen[lease.IP] {
			seen[lease.IP] = true
			devices = append(devices, ConnectedDevice{
				IP:       lease.IP,
				MAC:      lease.MAC,
				Hostname: lease.Hostname,
			})
		}
	}

	return devices
}

// readARPTable parses /proc/net/arp.
func (c *Collector) readARPTable() []ConnectedDevice {
	file, err := os.Open("/proc/net/arp")
	if err != nil {
		c.logger.Debug("Could not read ARP table", "error", err)
		return nil
	}
	defer file.Close()

	var devices []ConnectedDevice
	scanner := bufio.NewScanner(file)
	first := true
	for scanner.Scan() {
		if first {
			first = false
			continue // skip header
		}

		fields := strings.Fields(scanner.Text())
		if len(fields) < 6 {
			continue
		}

		// Skip incomplete entries
		if fields[2] == "0x0" {
			continue
		}

		devices = append(devices, ConnectedDevice{
			IP:        fields[0],
			MAC:       fields[3],
			Interface: fields[5],
		})
	}

	return devices
}

type dhcpLease struct {
	IP       string
	MAC      string
	Hostname string
}

// readDHCPLeases reads dnsmasq lease file.
func (c *Collector) readDHCPLeases() map[string]dhcpLease {
	leases := make(map[string]dhcpLease)

	file, err := os.Open("/var/lib/misc/dnsmasq.leases")
	if err != nil {
		// Try alternative path
		file, err = os.Open("/var/lib/dnsmasq/dnsmasq.leases")
		if err != nil {
			return leases
		}
	}
	defer file.Close()

	scanner := bufio.NewScanner(file)
	for scanner.Scan() {
		// Format: timestamp MAC IP hostname client-id
		fields := strings.Fields(scanner.Text())
		if len(fields) < 4 {
			continue
		}

		lease := dhcpLease{
			MAC:      fields[1],
			IP:       fields[2],
			Hostname: fields[3],
		}
		if lease.Hostname == "*" {
			lease.Hostname = ""
		}

		leases[lease.MAC] = lease
	}

	return leases
}

// CountDevicesPerInterface returns the number of connected devices per network interface.
func (c *Collector) CountDevicesPerInterface(ctx context.Context) map[string]int {
	devices := c.GetConnectedDevices(ctx)
	counts := make(map[string]int)
	for _, d := range devices {
		if d.Interface != "" {
			counts[d.Interface]++
		}
	}
	return counts
}

// InterfaceInfo describes a network interface.
type InterfaceInfo struct {
	Name  string   `json:"name"`
	State string   `json:"state"`
	IPs   []string `json:"ips"`
	MAC   string   `json:"mac"`
}

// GetInterfaces returns info about all non-loopback interfaces.
func (c *Collector) GetInterfaces() []InterfaceInfo {
	ifaces, err := net.Interfaces()
	if err != nil {
		c.logger.Debug("Could not list interfaces", "error", err)
		return nil
	}

	var result []InterfaceInfo
	for _, iface := range ifaces {
		if iface.Flags&net.FlagLoopback != 0 {
			continue
		}

		info := InterfaceInfo{
			Name: iface.Name,
			MAC:  iface.HardwareAddr.String(),
		}

		if iface.Flags&net.FlagUp != 0 {
			info.State = "up"
		} else {
			info.State = "down"
		}

		addrs, err := iface.Addrs()
		if err == nil {
			for _, addr := range addrs {
				info.IPs = append(info.IPs, addr.String())
			}
		}

		result = append(result, info)
	}

	return result
}

// Status contains overall system status for the local API.
type Status struct {
	CPUTemp        float64 `json:"cpu_temp"`
	MemUsedMB      int64   `json:"mem_used_mb"`
	MemTotalMB     int64   `json:"mem_total_mb"`
	DiskUsedMB     int64   `json:"disk_used_mb"`
	UptimeSeconds  float64 `json:"uptime_seconds"`
	WANIP          string  `json:"wan_ip"`
	AgentVersion   string  `json:"agent_version"`
	ConfigVersion  int     `json:"config_version"`
}

// GetStatus returns a full system status snapshot.
func (c *Collector) GetStatus(ctx context.Context, agentVersion string, configVersion int) Status {
	info := c.Collect(ctx)
	return Status{
		CPUTemp:       info.CPUTemp,
		MemUsedMB:     info.MemUsedMB,
		MemTotalMB:    info.MemTotalMB,
		DiskUsedMB:    info.DiskUsedMB,
		UptimeSeconds: info.UptimeSecs,
		WANIP:         c.GetWANIP(ctx),
		AgentVersion:  agentVersion,
		ConfigVersion: configVersion,
	}
}

// DeviceCountPerVLAN returns count per bridge name by matching ARP interface to br-vlan*.
func (c *Collector) DeviceCountPerVLAN(ctx context.Context) map[string]int {
	devices := c.GetConnectedDevices(ctx)
	counts := make(map[string]int)
	for _, d := range devices {
		if strings.HasPrefix(d.Interface, "br-vlan") {
			counts[d.Interface]++
		}
	}
	return counts
}

// InterfaceInfoWithDeviceCount extends VLANInfo with device counts.
type InterfaceInfoWithDeviceCount struct {
	Name         string   `json:"name"`
	State        string   `json:"state"`
	IPs          []string `json:"ips"`
	MAC          string   `json:"mac"`
	DeviceCount  int      `json:"device_count,omitempty"`
	VLANID       int      `json:"vlan_id,omitempty"`
}

// GetNetworkOverview returns all interfaces with VLAN device counts.
func (c *Collector) GetNetworkOverview(ctx context.Context) []InterfaceInfoWithDeviceCount {
	ifaces := c.GetInterfaces()
	deviceCounts := c.CountDevicesPerInterface(ctx)

	var result []InterfaceInfoWithDeviceCount
	for _, iface := range ifaces {
		info := InterfaceInfoWithDeviceCount{
			Name:        iface.Name,
			State:       iface.State,
			IPs:         iface.IPs,
			MAC:         iface.MAC,
			DeviceCount: deviceCounts[iface.Name],
		}

		// Extract VLAN ID from bridge name
		if strings.HasPrefix(iface.Name, "br-vlan") {
			vlanIDStr := strings.TrimPrefix(iface.Name, "br-vlan")
			vlanID, _ := strconv.Atoi(vlanIDStr)
			info.VLANID = vlanID
		}

		result = append(result, info)
	}

	return result
}
