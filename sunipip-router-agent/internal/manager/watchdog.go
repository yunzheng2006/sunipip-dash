package manager

import (
	"context"
	"fmt"
	"net"
	"os/exec"
	"strings"
	"sync"
	"time"
)

const watchdogInterval = 30 * time.Second
const watchdogCooldown = 3 * time.Minute

// ServiceCheck represents the result of a single service health check.
type ServiceCheck struct {
	Name    string `json:"name"`
	Healthy bool   `json:"healthy"`
	Reason  string `json:"reason,omitempty"`
}

// watchdogState tracks per-service restart cooldowns.
type watchdogState struct {
	mu          sync.Mutex
	lastRestart map[string]time.Time
}

func (w *watchdogState) canRestart(service string) bool {
	w.mu.Lock()
	defer w.mu.Unlock()
	if w.lastRestart == nil {
		w.lastRestart = make(map[string]time.Time)
	}
	last, ok := w.lastRestart[service]
	if ok && time.Since(last) < watchdogCooldown {
		return false
	}
	w.lastRestart[service] = time.Now()
	return true
}

// StartWatchdogLoop runs a background goroutine that checks critical service
// health every 30 seconds and auto-restarts failed services.
func (m *Manager) StartWatchdogLoop(ctx context.Context, apIP string, client interface {
	ReportEvent(ctx context.Context, eventType, severity, message string, metadata map[string]interface{}) error
}) {
	go func() {
		ticker := time.NewTicker(watchdogInterval)
		defer ticker.Stop()

		state := &watchdogState{}
		m.logger.Info("Service watchdog started", "interval", watchdogInterval)

		for {
			select {
			case <-ctx.Done():
				m.logger.Info("Service watchdog stopped")
				return
			case <-ticker.C:
				m.runWatchdogChecks(ctx, apIP, client, state)
			}
		}
	}()
}

func (m *Manager) runWatchdogChecks(ctx context.Context, apIP string, client interface {
	ReportEvent(ctx context.Context, eventType, severity, message string, metadata map[string]interface{}) error
}, state *watchdogState) {
	var fixes []string

	if fixed := m.watchdogCheckClash(ctx, state); fixed != "" {
		fixes = append(fixes, fixed)
	}

	if fixed := m.watchdogCheckFreeRadius(ctx, state); fixed != "" {
		fixes = append(fixes, fixed)
	}

	if fixed := m.watchdogCheckDNS(ctx, state); fixed != "" {
		fixes = append(fixes, fixed)
	}

	if apIP != "" {
		if fixed := m.watchdogCheckAP(ctx, apIP); fixed != "" {
			fixes = append(fixes, fixed)
		}
	}

	if len(fixes) > 0 && client != nil {
		msg := fmt.Sprintf("Watchdog auto-fixed: %s", strings.Join(fixes, "; "))
		m.logger.Warn(msg)
		_ = client.ReportEvent(ctx, "watchdog_fix", "warning", msg, map[string]interface{}{
			"fixes": fixes,
		})
	}
}

// watchdogCheckClash verifies Clash is running and port 7890 is listening.
func (m *Manager) watchdogCheckClash(ctx context.Context, state *watchdogState) string {
	if !m.clash.IsInstalled(ctx) {
		return ""
	}

	if m.clash.IsRunning(ctx) && portListening(7890) {
		return ""
	}

	reason := "clash not healthy"
	if !state.canRestart("clash") {
		m.logger.Debug("Watchdog: clash unhealthy but in cooldown", "reason", reason)
		return ""
	}

	m.logger.Warn("Watchdog: restarting clash", "reason", reason)
	cmd := exec.CommandContext(ctx, "systemctl", "restart", "clash")
	if err := cmd.Run(); err != nil {
		m.logger.Error("Watchdog: failed to restart clash", "error", err)
		return reason + " (restart failed)"
	}

	return reason + " → restarted"
}

// watchdogCheckFreeRadius verifies FreeRadius is running.
func (m *Manager) watchdogCheckFreeRadius(ctx context.Context, state *watchdogState) string {
	if !m.freeradius.IsInstalled(ctx) {
		return ""
	}

	if m.freeradius.IsRunning(ctx) {
		return ""
	}

	reason := "freeradius not running"
	if !state.canRestart("freeradius") {
		m.logger.Debug("Watchdog: freeradius unhealthy but in cooldown", "reason", reason)
		return ""
	}

	m.logger.Warn("Watchdog: restarting freeradius", "reason", reason)
	cmd := exec.CommandContext(ctx, "systemctl", "restart", "freeradius")
	if err := cmd.Run(); err != nil {
		m.logger.Error("Watchdog: failed to restart freeradius", "error", err)
		return reason + " (restart failed)"
	}

	return reason + " → restarted"
}

// watchdogCheckDNS verifies DNS port 1053 is listening (served by Clash).
func (m *Manager) watchdogCheckDNS(ctx context.Context, state *watchdogState) string {
	if !m.clash.IsInstalled(ctx) {
		return ""
	}

	if portListening(1053) {
		return ""
	}

	if !m.clash.IsRunning(ctx) {
		return ""
	}

	reason := "dns port 1053 down"
	if !state.canRestart("clash-dns") {
		m.logger.Debug("Watchdog: DNS unhealthy but in cooldown")
		return ""
	}

	m.logger.Warn("Watchdog: DNS port 1053 down, restarting clash")
	cmd := exec.CommandContext(ctx, "systemctl", "restart", "clash")
	if err := cmd.Run(); err != nil {
		m.logger.Error("Watchdog: failed to restart clash for DNS", "error", err)
		return reason + " (restart failed)"
	}
	return reason + " → clash restarted"
}

// watchdogCheckAP pings the AP to verify connectivity.
func (m *Manager) watchdogCheckAP(ctx context.Context, apIP string) string {
	cmd := exec.CommandContext(ctx, "ping", "-c", "1", "-W", "3", apIP)
	if err := cmd.Run(); err != nil {
		m.logger.Warn("Watchdog: AP unreachable", "ip", apIP)
		return fmt.Sprintf("AP %s unreachable", apIP)
	}
	return ""
}

func processExists(name string) bool {
	cmd := exec.Command("pidof", name)
	return cmd.Run() == nil
}

func portListening(port int) bool {
	conn, err := net.DialTimeout("tcp", fmt.Sprintf("127.0.0.1:%d", port), 2*time.Second)
	if err != nil {
		return false
	}
	conn.Close()
	return true
}

func udpPortListening(port int) bool {
	cmd := exec.Command("ss", "-ulnp")
	output, err := cmd.Output()
	if err != nil {
		return false
	}
	return strings.Contains(string(output), fmt.Sprintf(":%d ", port))
}
