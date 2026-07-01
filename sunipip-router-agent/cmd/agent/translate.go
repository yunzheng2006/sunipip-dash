package main

import (
	"strings"

	"sunipip-router-agent/internal/api"
	"sunipip-router-agent/internal/config"
)

// translateInterfaces replaces abstract interface names (eth0-eth3) in the
// device config with the real interface names from the interface map.
func translateInterfaces(cfg *api.DeviceConfig, ifmap config.InterfaceMap) {
	mapping := map[string]string{
		"eth0": ifmap.WAN,
		"eth1": ifmap.MGMT,
		"eth2": ifmap.AP,
		"eth3": ifmap.LAN,
	}

	// Network section
	cfg.Network.WAN.Interface = translateName(cfg.Network.WAN.Interface, mapping)
	cfg.Network.Management.Interface = translateName(cfg.Network.Management.Interface, mapping)
	cfg.Network.Wired.Interface = translateName(cfg.Network.Wired.Interface, mapping)
	cfg.Network.Trunk.Interface = translateName(cfg.Network.Trunk.Interface, mapping)

	// VLAN interfaces: e.g. "eth2.10" -> "enp3s0.10"
	for i := range cfg.Network.VLANs {
		cfg.Network.VLANs[i].Interface = translateVLAN(cfg.Network.VLANs[i].Interface, mapping)
	}
}

// translateName performs a simple lookup; returns the original value if no mapping exists.
func translateName(name string, mapping map[string]string) string {
	if real, ok := mapping[name]; ok {
		return real
	}
	return name
}

// translateVLAN handles VLAN interface names like "eth2.10" by splitting on
// the first dot, translating the base interface, and reassembling.
func translateVLAN(name string, mapping map[string]string) string {
	// Try direct match first (covers plain names without VLAN suffix)
	if real, ok := mapping[name]; ok {
		return real
	}

	parts := strings.SplitN(name, ".", 2)
	if len(parts) != 2 {
		return name
	}

	base := parts[0]
	vlanSuffix := parts[1]

	if real, ok := mapping[base]; ok {
		return real + "." + vlanSuffix
	}
	return name
}
