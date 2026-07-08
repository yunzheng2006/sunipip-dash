package main

import (
	"context"
	"flag"
	"fmt"
	"log/slog"
	"os"
	"os/exec"
	"os/signal"
	"sync"
	"syscall"
	"time"

	"sunipip-router-agent/internal/api"
	"sunipip-router-agent/internal/config"
	"sunipip-router-agent/internal/health"
	"sunipip-router-agent/internal/localapi"
	"sunipip-router-agent/internal/manager"

	"sunipip-router-agent/internal/updater"
)

func main() {
	// Parse flags
	configPath := flag.String("config", config.DefaultConfigPath, "path to agent config file")
	registerURL := flag.String("register-url", "", "platform URL for first-time registration")
	installToken := flag.String("install-token", "", "install token for first-time registration")
	logLevel := flag.String("log-level", "info", "log level (debug, info, warn, error)")
	flag.Parse()

	// Set up structured logger
	var level slog.Level
	switch *logLevel {
	case "debug":
		level = slog.LevelDebug
	case "info":
		level = slog.LevelInfo
	case "warn":
		level = slog.LevelWarn
	case "error":
		level = slog.LevelError
	default:
		level = slog.LevelInfo
	}

	logger := slog.New(slog.NewJSONHandler(os.Stdout, &slog.HandlerOptions{Level: level}))
	slog.SetDefault(logger)

	logger.Info("SuniPIP Router Agent starting",
		"version", api.AgentVersion,
		"config_path", *configPath,
	)

	// Config store
	store := config.NewStore(*configPath)

	// Handle registration if config doesn't exist
	if !store.Exists() {
		if *registerURL == "" || *installToken == "" {
			logger.Error("Config file not found and no registration parameters provided",
				"config_path", *configPath,
				"hint", "use --register-url and --install-token for first-time setup",
			)
			os.Exit(1)
		}

		logger.Info("No config found, starting registration flow")
		ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
		agentCfg, err := api.Register(ctx, *registerURL, *installToken, logger)
		cancel()
		if err != nil {
			logger.Error("Registration failed", "error", err)
			os.Exit(1)
		}

		if err := store.Save(agentCfg); err != nil {
			logger.Error("Failed to save config after registration", "error", err)
			os.Exit(1)
		}

		logger.Info("Registration complete, config saved", "device_id", agentCfg.DeviceID)
	}

	// Load config
	if err := store.Load(); err != nil {
		logger.Error("Failed to load config", "error", err)
		os.Exit(1)
	}

	// Load interface name mapping
	if err := store.LoadInterfaceMap(); err != nil {
		logger.Error("Failed to load interface map", "error", err)
		os.Exit(1)
	}
	ifMap := store.GetInterfaceMap()
	logger.Info("Interface map loaded",
		"wan", ifMap.WAN,
		"mgmt", ifMap.MGMT,
		"ap", ifMap.AP,
		"lan", ifMap.LAN,
	)

	cfg := store.Get()
	logger.Info("Config loaded",
		"device_id", cfg.DeviceID,
		"platform_url", cfg.PlatformURL,
		"heartbeat_interval", cfg.HeartbeatIntervalSeconds,
		"config_poll_interval", cfg.ConfigPollIntervalSeconds,
	)

	// Create core components
	client := api.NewClient(store, logger.With("component", "api"))
	mgr := manager.NewManager(logger.With("component", "manager"))
	collector := health.NewCollector(logger.With("component", "health"))
	upd := updater.NewUpdater(client, logger)

	// Context for graceful shutdown
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	// Start local API server
	localServer := localapi.NewServer(cfg.LocalAPIListen, mgr, client, collector, logger)
	if err := localServer.Start(); err != nil {
		logger.Error("Failed to start local API server", "error", err)
		os.Exit(1)
	}

	// Channel to trigger config pull
	configTrigger := make(chan struct{}, 1)

	// Do initial config pull
	logger.Info("Performing initial config pull")
	triggerConfigPull(configTrigger)

	// Start heartbeat loop
	var wg sync.WaitGroup
	wg.Add(2)

	go func() {
		defer wg.Done()
		heartbeatLoop(ctx, client, collector, mgr, upd, configTrigger, cfg, logger)
	}()

	// Start config sync loop
	go func() {
		defer wg.Done()
		configSyncLoop(ctx, client, mgr, store, configTrigger, logger)
	}()

	// Start reconciliation loop
	mgr.StartReconcileLoop(ctx, client)

	// Start service watchdog (30s health checks with auto-restart)
	mgr.StartWatchdogLoop(ctx, "", client)

	// Ensure detailed logging on all services + journal retention
	mgr.EnsureLogging(ctx)

	// Start stale DHCP host cleanup loop (every 30s, removes disconnected devices)
	wg.Add(1)
	go func() {
		defer wg.Done()
		staleCleanupLoop(ctx, mgr, logger)
	}()

	// Start daily maintenance loop (restart FreeRadius + dnsmasq to clear stale state)
	wg.Add(1)
	go func() {
		defer wg.Done()
		maintenanceLoop(ctx, mgr, client, logger)
	}()

	// Signal handling
	sigCh := make(chan os.Signal, 1)
	signal.Notify(sigCh, syscall.SIGTERM, syscall.SIGINT, syscall.SIGUSR1)

	logger.Info("Agent is running, waiting for signals")

	for {
		sig := <-sigCh
		switch sig {
		case syscall.SIGUSR1:
			logger.Info("Received SIGUSR1, triggering immediate config pull")
			triggerConfigPull(configTrigger)

		case syscall.SIGTERM, syscall.SIGINT:
			logger.Info("Received shutdown signal", "signal", sig.String())
			cancel()

			// Shutdown local API
			shutdownCtx, shutdownCancel := context.WithTimeout(context.Background(), 10*time.Second)
			if err := localServer.Shutdown(shutdownCtx); err != nil {
				logger.Error("Error shutting down local API", "error", err)
			}
			shutdownCancel()

			// Wait for goroutines
			wg.Wait()

			logger.Info("Agent shutdown complete")
			os.Exit(0)
		}
	}
}

// triggerConfigPull sends a non-blocking signal to the config trigger channel.
func triggerConfigPull(ch chan struct{}) {
	select {
	case ch <- struct{}{}:
	default:
		// Already triggered
	}
}

// heartbeatLoop sends periodic heartbeats to the platform.
func heartbeatLoop(
	ctx context.Context,
	client *api.Client,
	collector *health.Collector,
	mgr *manager.Manager,
	upd *updater.Updater,
	configTrigger chan struct{},
	cfg config.AgentConfig,
	logger *slog.Logger,
) {
	logger = logger.With("loop", "heartbeat")
	ticker := time.NewTicker(time.Duration(cfg.HeartbeatIntervalSeconds) * time.Second)
	defer ticker.Stop()

	var pendingStaleCount int
	var lastAppliedVersion int

	for {
		select {
		case <-ctx.Done():
			logger.Info("Heartbeat loop stopped")
			return

		case <-ticker.C:
			sysInfo := collector.Collect(ctx)
			wanIP := collector.GetWANIP(ctx)

			req := api.HeartbeatRequest{
				SystemInfo:           sysInfo,
				AppliedConfigVersion: mgr.AppliedConfigVersion(),
				WanIP:               wanIP,
				AgentVersion:         api.AgentVersion,
			}

			resp, err := client.Heartbeat(ctx, req)
			if err != nil {
				logger.Error("Heartbeat failed", "error", err)
				continue
			}

			logger.Debug("Heartbeat sent",
				"config_version", resp.Data.ConfigVersion,
				"has_pending", resp.Data.HasPendingConfig,
				"applied_version", mgr.AppliedConfigVersion(),
			)

			// Check if we need to pull a new config (before update check so config sync is never blocked)
			if resp.Data.HasPendingConfig || resp.Data.ConfigVersion > mgr.AppliedConfigVersion() {
				logger.Info("New config available",
					"server_version", resp.Data.ConfigVersion,
					"applied_version", mgr.AppliedConfigVersion(),
				)
				triggerConfigPull(configTrigger)
			}

			// Config sync watchdog: detect stuck config apply
			currentApplied := mgr.AppliedConfigVersion()
			if resp.Data.HasPendingConfig && lastAppliedVersion > 0 && currentApplied == lastAppliedVersion {
				pendingStaleCount++
				if pendingStaleCount >= 5 {
					logger.Error("Config sync appears stuck, restarting agent",
						"pending_stale_count", pendingStaleCount,
						"applied_version", currentApplied,
					)
					_ = client.ReportEvent(ctx, "watchdog_restart", "warning",
						fmt.Sprintf("Config sync stuck for %d heartbeats at version %d, restarting", pendingStaleCount, currentApplied), nil)
					os.Exit(1)
				}
			} else {
				pendingStaleCount = 0
			}
			lastAppliedVersion = currentApplied

			// Check for agent self-update (after config pull to avoid blocking sync)
			if resp.Data.LatestAgentVersion != "" && resp.Data.AgentDownloadURL != "" {
				updated, updateErr := upd.CheckAndUpdate(ctx, resp.Data.LatestAgentVersion, resp.Data.AgentDownloadURL)
				if updateErr != nil {
					logger.Error("Agent self-update failed", "error", updateErr)
				} else if updated {
					logger.Info("Agent updated, restarting...")
					upd.RestartSelf()
					return
				}
			}

			// Execute pending remote commands
			for _, cmd := range resp.Data.PendingCommands {
				go executeRemoteCommand(ctx, client, cmd, logger)
			}
		}
	}
}

// configSyncLoop waits for config pull triggers and applies new configurations.
func configSyncLoop(
	ctx context.Context,
	client *api.Client,
	mgr *manager.Manager,
	store *config.Store,
	configTrigger chan struct{},
	logger *slog.Logger,
) {
	logger = logger.With("loop", "config-sync")

	for {
		select {
		case <-ctx.Done():
			logger.Info("Config sync loop stopped")
			return

		case <-configTrigger:
			logger.Info("Config pull triggered")
			if err := pullAndApplyConfig(ctx, client, mgr, store, logger); err != nil {
				logger.Error("Config sync failed", "error", err)

				_ = client.ReportEvent(ctx, "config_apply_failed", "error",
					fmt.Sprintf("Failed to apply config: %s", err.Error()), nil)
			}
		}
	}
}

// pullAndApplyConfig fetches config from the platform and applies it.
func pullAndApplyConfig(
	ctx context.Context,
	client *api.Client,
	mgr *manager.Manager,
	store *config.Store,
	logger *slog.Logger,
) error {
	// Pull config
	deviceCfg, err := client.PullConfig(ctx)
	if err != nil {
		return fmt.Errorf("pull config: %w", err)
	}

	// Translate abstract interface names (eth0-eth3) to real device names
	translateInterfaces(deviceCfg, store.GetInterfaceMap())

	logger.Info("Config pulled",
		"version", deviceCfg.ConfigVersion,
		"hostname", deviceCfg.Device.Hostname,
		"vlans", len(deviceCfg.Network.VLANs),
	)

	// Skip if already at this version
	if deviceCfg.ConfigVersion <= mgr.AppliedConfigVersion() {
		logger.Info("Config already at version, skipping", "version", deviceCfg.ConfigVersion)
		return nil
	}

	// Apply config
	if err := mgr.Apply(ctx, deviceCfg); err != nil {
		return fmt.Errorf("apply config v%d: %w", deviceCfg.ConfigVersion, err)
	}

	// Acknowledge
	if err := client.AckConfig(ctx, deviceCfg.ConfigVersion); err != nil {
		logger.Error("Failed to ack config (config was applied successfully)", "version", deviceCfg.ConfigVersion, "error", err)
	} else {
		logger.Info("Config acknowledged", "version", deviceCfg.ConfigVersion)
	}

	// Report success
	_ = client.ReportEvent(ctx, "config_applied", "info",
		fmt.Sprintf("Config v%d applied successfully", deviceCfg.ConfigVersion),
		map[string]interface{}{
			"config_version": deviceCfg.ConfigVersion,
			"vlans":          len(deviceCfg.Network.VLANs),
		},
	)

	return nil
}

// maintenanceLoop restarts FreeRadius and dnsmasq daily at 06:00 local time
// to prevent stale RADIUS/DHCP state that causes WiFi reconnection failures.
func staleCleanupLoop(ctx context.Context, mgr *manager.Manager, logger *slog.Logger) {
	logger = logger.With("loop", "stale-cleanup")

	// Wait 2 minutes after startup before first cleanup.
	// Gives devices time to renew leases after dnsmasq restart during config apply.
	logger.Info("Stale cleanup waiting 2m startup grace period")
	select {
	case <-ctx.Done():
		return
	case <-time.After(2 * time.Minute):
	}
	logger.Info("Stale cleanup active")

	cleanTicker := time.NewTicker(30 * time.Second)
	defer cleanTicker.Stop()
	pruneTicker := time.NewTicker(5 * time.Minute)
	defer pruneTicker.Stop()

	for {
		select {
		case <-ctx.Done():
			logger.Info("Stale cleanup loop stopped")
			return
		case <-cleanTicker.C:
			mgr.CleanStaleHosts(ctx)
		case <-pruneTicker.C:
			mgr.PruneGraceFile()
		}
	}
}

func maintenanceLoop(
	ctx context.Context,
	mgr *manager.Manager,
	client *api.Client,
	logger *slog.Logger,
) {
	logger = logger.With("loop", "maintenance")
	const maintenanceHour = 6

	for {
		now := time.Now()
		next := time.Date(now.Year(), now.Month(), now.Day(), maintenanceHour, 0, 0, 0, now.Location())
		if !next.After(now) {
			next = next.Add(24 * time.Hour)
		}
		delay := next.Sub(now)
		logger.Info("Next maintenance scheduled", "at", next.Format("2006-01-02 15:04:05"), "in", delay.Round(time.Minute))

		select {
		case <-ctx.Done():
			logger.Info("Maintenance loop stopped")
			return
		case <-time.After(delay):
			logger.Info("Running scheduled maintenance")
			if err := mgr.MaintenanceRestart(ctx); err != nil {
				logger.Error("Maintenance restart failed", "error", err)
				_ = client.ReportEvent(ctx, "maintenance_failed", "error", err.Error(), nil)
			} else {
				logger.Info("Maintenance restart completed")
				_ = client.ReportEvent(ctx, "maintenance_complete", "info",
					"Scheduled maintenance: restarted FreeRadius and dnsmasq", nil)
			}
		}
	}
}

// executeRemoteCommand runs a single remote command and reports the result.
func executeRemoteCommand(ctx context.Context, client *api.Client, cmd api.RemoteCommand, logger *slog.Logger) {
	logger.Info("Executing remote command", "id", cmd.ID, "command", cmd.Command)

	timeout := time.Duration(cmd.Timeout) * time.Second
	if timeout <= 0 {
		timeout = 30 * time.Second
	}

	cmdCtx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()

	execCmd := exec.CommandContext(cmdCtx, "bash", "-c", cmd.Command)
	output, err := execCmd.CombinedOutput()

	exitCode := 0
	if err != nil {
		if exitErr, ok := err.(*exec.ExitError); ok {
			exitCode = exitErr.ExitCode()
		} else {
			exitCode = -1
		}
	}

	outputStr := string(output)
	if len(outputStr) > 10000 {
		outputStr = outputStr[:10000] + "\n... (truncated)"
	}

	logger.Info("Remote command completed", "id", cmd.ID, "exit_code", exitCode, "output_len", len(outputStr))

	if reportErr := client.ReportCommandResult(ctx, api.CommandResultRequest{
		CommandID: cmd.ID,
		ExitCode:  exitCode,
		Output:    outputStr,
	}); reportErr != nil {
		logger.Error("Failed to report command result", "id", cmd.ID, "error", reportErr)
	}
}
