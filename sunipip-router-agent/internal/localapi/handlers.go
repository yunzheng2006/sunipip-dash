package localapi

import (
	"encoding/json"
	"log/slog"
	"net/http"
	"os/exec"
	"strings"

	"sunipip-router-agent/internal/api"
	"sunipip-router-agent/internal/health"
	"sunipip-router-agent/internal/manager"
	"sunipip-router-agent/internal/services"
)

// Handlers provides HTTP handler functions for the local API.
type Handlers struct {
	mgr       *manager.Manager
	collector *health.Collector
	logger    *slog.Logger
}

// NewHandlers creates a new handler set.
func NewHandlers(mgr *manager.Manager, collector *health.Collector, logger *slog.Logger) *Handlers {
	return &Handlers{
		mgr:       mgr,
		collector: collector,
		logger:    logger,
	}
}

// HandleStatus returns system status information.
// GET /api/status
func (h *Handlers) HandleStatus(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		writeJSONError(w, http.StatusMethodNotAllowed, "method not allowed")
		return
	}

	status := h.collector.GetStatus(r.Context(), api.AgentVersion, h.mgr.AppliedConfigVersion())
	writeJSON(w, http.StatusOK, map[string]interface{}{
		"success": true,
		"data":    status,
	})
}

// HandleNetwork returns network interface information.
// GET /api/network
func (h *Handlers) HandleNetwork(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		writeJSONError(w, http.StatusMethodNotAllowed, "method not allowed")
		return
	}

	overview := h.collector.GetNetworkOverview(r.Context())
	vlans, err := h.mgr.NetworkService().GetVLANInterfaces(r.Context())
	if err != nil {
		h.logger.Warn("Failed to get VLAN interfaces", "error", err)
	}

	writeJSON(w, http.StatusOK, map[string]interface{}{
		"success": true,
		"data": map[string]interface{}{
			"interfaces": overview,
			"vlans":      vlans,
		},
	})
}

// HandleServices returns the status of managed services.
// GET /api/services
func (h *Handlers) HandleServices(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		writeJSONError(w, http.StatusMethodNotAllowed, "method not allowed")
		return
	}

	statuses := h.mgr.GetServiceStatuses(r.Context())
	writeJSON(w, http.StatusOK, map[string]interface{}{
		"success": true,
		"data":    statuses,
	})
}

// HandleConnectedDevices returns devices connected per VLAN.
// GET /api/connected-devices
func (h *Handlers) HandleConnectedDevices(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		writeJSONError(w, http.StatusMethodNotAllowed, "method not allowed")
		return
	}

	devices := h.collector.GetConnectedDevices(r.Context())
	writeJSON(w, http.StatusOK, map[string]interface{}{
		"success": true,
		"data":    devices,
	})
}

// RestartServiceRequest is the request body for POST /api/restart-service.
type RestartServiceRequest struct {
	Service string `json:"service"`
}

// HandleRestartService restarts a named service.
// POST /api/restart-service
func (h *Handlers) HandleRestartService(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		writeJSONError(w, http.StatusMethodNotAllowed, "method not allowed")
		return
	}

	var req RestartServiceRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		writeJSONError(w, http.StatusBadRequest, "invalid request body")
		return
	}

	allowedServices := map[string]bool{
		"freeradius": true,
		"clash":      true,
		"dnsmasq":    true,
	}

	if !allowedServices[req.Service] {
		writeJSONError(w, http.StatusBadRequest, "service not allowed: "+req.Service)
		return
	}

	h.logger.Info("Restart service requested", "service", req.Service, "remote_addr", r.RemoteAddr)

	if err := h.mgr.RestartService(r.Context(), req.Service); err != nil {
		h.logger.Error("Failed to restart service", "service", req.Service, "error", err)
		writeJSONError(w, http.StatusInternalServerError, "failed to restart service: "+err.Error())
		return
	}

	writeJSON(w, http.StatusOK, map[string]interface{}{
		"success": true,
		"message": "service restarted: " + req.Service,
	})
}

// HandleWANConfig handles GET (read) and POST (set) for WAN configuration.
// GET /api/wan-config — returns current WAN mode/IP/gateway/DNS
// POST /api/wan-config — sets WAN mode (dhcp/static/pppoe)
func (h *Handlers) HandleWANConfig(w http.ResponseWriter, r *http.Request) {
	wanIface := "eth0"
	if cfg := h.mgr.LastAppliedConfig(); cfg != nil && cfg.Network.WAN.Interface != "" {
		wanIface = cfg.Network.WAN.Interface
	}

	switch r.Method {
	case http.MethodGet:
		status, err := h.mgr.NetworkService().GetWANStatus(r.Context(), wanIface)
		if err != nil {
			h.logger.Error("Failed to get WAN status", "error", err)
			writeJSONError(w, http.StatusInternalServerError, "failed to get WAN status")
			return
		}
		writeJSON(w, http.StatusOK, map[string]interface{}{
			"success": true,
			"data":    status,
		})

	case http.MethodPost:
		var req services.WANSetRequest
		if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
			writeJSONError(w, http.StatusBadRequest, "invalid request body")
			return
		}

		allowedModes := map[string]bool{"dhcp": true, "static": true, "pppoe": true}
		if !allowedModes[req.Mode] {
			writeJSONError(w, http.StatusBadRequest, "mode must be dhcp, static, or pppoe")
			return
		}

		h.logger.Info("WAN config change requested", "mode", req.Mode, "remote_addr", r.RemoteAddr)

		if err := h.mgr.NetworkService().SetWANConfig(r.Context(), wanIface, req); err != nil {
			h.logger.Error("Failed to set WAN config", "error", err)
			writeJSONError(w, http.StatusInternalServerError, "failed to set WAN config: "+err.Error())
			return
		}

		writeJSON(w, http.StatusOK, map[string]interface{}{
			"success": true,
			"message": "WAN configuration updated to " + req.Mode,
		})

	default:
		writeJSONError(w, http.StatusMethodNotAllowed, "method not allowed")
	}
}

// HandleDiagnostics returns comprehensive network and system diagnostic info.
// GET /api/diagnostics — no auth required, for local troubleshooting only.
func (h *Handlers) HandleDiagnostics(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		writeJSONError(w, http.StatusMethodNotAllowed, "method not allowed")
		return
	}

	ctx := r.Context()
	diag := make(map[string]interface{})

	// IP addresses
	if out, err := exec.CommandContext(ctx, "ip", "-o", "addr", "show").Output(); err == nil {
		diag["ip_addresses"] = string(out)
	}

	// Routing table
	if out, err := exec.CommandContext(ctx, "ip", "route", "show").Output(); err == nil {
		diag["routes"] = string(out)
	}

	// Default gateway
	if out, err := exec.CommandContext(ctx, "ip", "route", "show", "default").Output(); err == nil {
		diag["default_route"] = strings.TrimSpace(string(out))
	}

	// nftables summary
	if out, err := exec.CommandContext(ctx, "nft", "list", "ruleset").CombinedOutput(); err == nil {
		diag["nftables"] = string(out)
	} else {
		diag["nftables"] = "nft not available: " + err.Error()
	}

	// Service statuses
	diag["services"] = h.mgr.GetServiceStatuses(ctx)

	// Applied config version
	diag["applied_config_version"] = h.mgr.AppliedConfigVersion()

	// Agent version
	diag["agent_version"] = api.AgentVersion

	// System info
	sysInfo := h.collector.Collect(ctx)
	diag["system_info"] = sysInfo

	// DNS test
	if out, err := exec.CommandContext(ctx, "nslookup", "admin.sunipip.uk").CombinedOutput(); err == nil {
		diag["dns_test"] = string(out)
	} else {
		diag["dns_test"] = "failed: " + string(out)
	}

	// Ping gateway test
	if out, err := exec.CommandContext(ctx, "ip", "route", "show", "default").Output(); err == nil {
		fields := strings.Fields(string(out))
		for i, f := range fields {
			if f == "via" && i+1 < len(fields) {
				gw := fields[i+1]
				diag["gateway_ip"] = gw
				if pingOut, pingErr := exec.CommandContext(ctx, "ping", "-c", "2", "-W", "2", gw).CombinedOutput(); pingErr == nil {
					diag["gateway_ping"] = "ok"
				} else {
					diag["gateway_ping"] = "failed: " + string(pingOut)
				}
				break
			}
		}
	}

	// Ping external test
	if pingOut, err := exec.CommandContext(ctx, "ping", "-c", "2", "-W", "2", "223.5.5.5").CombinedOutput(); err == nil {
		diag["internet_ping"] = "ok"
	} else {
		diag["internet_ping"] = "failed: " + string(pingOut)
	}

	// Interface map
	if data, err := exec.CommandContext(ctx, "cat", "/etc/sunipip/interfaces.json").Output(); err == nil {
		diag["interface_map"] = string(data)
	} else {
		diag["interface_map"] = "not found (using defaults)"
	}

	writeJSON(w, http.StatusOK, map[string]interface{}{
		"success": true,
		"data":    diag,
	})
}

// HandleNetworkCheck pings 223.5.5.5 to verify internet connectivity.
// GET /api/network-check
func (h *Handlers) HandleNetworkCheck(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		writeJSONError(w, http.StatusMethodNotAllowed, "method not allowed")
		return
	}

	cmd := exec.CommandContext(r.Context(), "ping", "-c", "3", "-W", "3", "223.5.5.5")
	output, err := cmd.CombinedOutput()

	connected := err == nil
	var latency string
	if connected {
		for _, line := range strings.Split(string(output), "\n") {
			if strings.Contains(line, "avg") {
				parts := strings.Split(line, "=")
				if len(parts) >= 2 {
					vals := strings.Split(strings.TrimSpace(parts[1]), "/")
					if len(vals) >= 2 {
						latency = vals[1] + "ms"
					}
				}
			}
		}
	}

	writeJSON(w, http.StatusOK, map[string]interface{}{
		"success": true,
		"data": map[string]interface{}{
			"connected": connected,
			"latency":   latency,
		},
	})
}

// writeJSON writes a JSON response.
func writeJSON(w http.ResponseWriter, status int, data interface{}) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	json.NewEncoder(w).Encode(data)
}

// writeJSONError writes a JSON error response.
func writeJSONError(w http.ResponseWriter, status int, message string) {
	writeJSON(w, status, map[string]interface{}{
		"success": false,
		"message": message,
	})
}
