package manager

import (
	"context"
	"os"
	"time"
)

const reconcileInterval = 5 * time.Minute

// StartReconcileLoop runs a background goroutine that periodically checks
// for config drift and re-applies if needed.
func (m *Manager) StartReconcileLoop(ctx context.Context, client interface {
	ReportEvent(ctx context.Context, eventType, severity, message string, metadata map[string]interface{}) error
}) {
	go func() {
		ticker := time.NewTicker(reconcileInterval)
		defer ticker.Stop()

		m.logger.Info("Config reconciliation loop started", "interval", reconcileInterval)

		for {
			select {
			case <-ctx.Done():
				m.logger.Info("Config reconciliation loop stopped")
				return
			case <-ticker.C:
				m.reconcile(ctx, client)
			}
		}
	}()
}

// reconcile checks the current system state against the last applied config
// and re-applies if drift is detected.
func (m *Manager) reconcile(ctx context.Context, client interface {
	ReportEvent(ctx context.Context, eventType, severity, message string, metadata map[string]interface{}) error
}) {
	m.mu.RLock()
	cfg := m.lastAppliedConfig
	m.mu.RUnlock()

	if cfg == nil {
		return
	}

	m.logger.Debug("Running config reconciliation check", "config_version", cfg.ConfigVersion)

	drifts := m.detectDrift(ctx)
	if len(drifts) == 0 {
		m.logger.Debug("No config drift detected")
		return
	}

	m.logger.Warn("Config drift detected, re-applying", "drifts", drifts)

	// Report drift event to platform
	if client != nil {
		_ = client.ReportEvent(ctx, "config_drift", "warning",
			"Configuration drift detected, re-applying",
			map[string]interface{}{
				"config_version": cfg.ConfigVersion,
				"drifted":        drifts,
			},
		)
	}

	// Re-apply the full config
	if err := m.Apply(ctx, cfg); err != nil {
		m.logger.Error("Failed to re-apply config after drift detection", "error", err)
		if client != nil {
			_ = client.ReportEvent(ctx, "config_drift_fix_failed", "error",
				"Failed to fix config drift: "+err.Error(), nil)
		}
	} else {
		m.logger.Info("Config drift fixed successfully")
	}
}

// detectDrift checks each service for configuration drift.
func (m *Manager) detectDrift(ctx context.Context) []string {
	var drifts []string

	// Check if services are running (only flag drift for installed services)
	if m.freeradius.IsInstalled(ctx) && !m.freeradius.IsRunning(ctx) {
		drifts = append(drifts, "freeradius not running")
	}
	if m.clash.IsInstalled(ctx) && !m.clash.IsRunning(ctx) {
		drifts = append(drifts, "clash not running")
	}
	if m.dhcp.IsInstalled(ctx) && !m.dhcp.IsRunning(ctx) {
		drifts = append(drifts, "dnsmasq not running")
	}

	// Check FreeRadius config content
	m.mu.RLock()
	cfg := m.lastAppliedConfig
	m.mu.RUnlock()

	if cfg != nil {
		// Check FreeRadius authorize file content
		currentFR, err := m.freeradius.GetCurrentConfig()
		if err != nil {
			drifts = append(drifts, "cannot read freeradius config: "+err.Error())
		} else if currentFR == "" && len(cfg.FreeRadius.Users) > 0 {
			drifts = append(drifts, "freeradius authorize file missing")
		}

		// Check Clash config
		currentClash, err := m.clash.GetCurrentConfig()
		if err != nil {
			drifts = append(drifts, "cannot read clash config: "+err.Error())
		} else if currentClash == "" && len(cfg.Clash.Proxies) > 0 {
			drifts = append(drifts, "clash config missing")
		}

		// Check WireGuard configs
		for _, peer := range cfg.WireGuard.Peers {
			currentWG, err := m.wireguard.GetCurrentConfig(peer.Interface)
			if err != nil {
				drifts = append(drifts, "cannot read wireguard config for "+peer.Interface+": "+err.Error())
			} else if currentWG == "" {
				drifts = append(drifts, "wireguard config missing for "+peer.Interface)
			}
		}

		// Check firewall config
		currentFW, err := m.firewall.GetCurrentConfig()
		if err != nil {
			drifts = append(drifts, "cannot read nftables config: "+err.Error())
		} else if currentFW == "" {
			drifts = append(drifts, "nftables config missing")
		}

		// Check v2 WiFi files (hook script, IP pool, dhcp-hosts)
		if len(cfg.FreeRadius.Users) > 0 {
			for _, path := range []string{
				"/etc/sunipip/radius-dhcp-hook.sh",
				"/etc/sunipip/user-ip-pool.conf",
				"/etc/sunipip/dhcp-hosts.conf",
			} {
				if _, err := os.Stat(path); os.IsNotExist(err) {
					drifts = append(drifts, path+" missing")
				}
			}
		}
	}

	return drifts
}
