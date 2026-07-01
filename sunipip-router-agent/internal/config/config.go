package config

import (
	"encoding/json"
	"fmt"
	"os"
	"sync"
)

const (
	DefaultConfigPath      = "/etc/sunipip/agent.json"
	DefaultInterfaceMapPath = "/etc/sunipip/interfaces.json"
)

// InterfaceMap maps abstract interface roles to real device interface names.
type InterfaceMap struct {
	WAN  string `json:"wan"`
	MGMT string `json:"mgmt"`
	AP   string `json:"ap"`
	LAN  string `json:"lan"`
}

// DefaultInterfaceMap returns the default mapping (backward-compatible eth names).
func DefaultInterfaceMap() InterfaceMap {
	return InterfaceMap{
		WAN:  "eth0",
		MGMT: "eth1",
		AP:   "eth2",
		LAN:  "eth3",
	}
}

// AgentConfig represents the agent's local configuration file.
type AgentConfig struct {
	DeviceID                 int    `json:"device_id"`
	AgentKey                 string `json:"agent_key"`
	PlatformURL              string `json:"platform_url"`
	HeartbeatIntervalSeconds int    `json:"heartbeat_interval_seconds"`
	ConfigPollIntervalSeconds int   `json:"config_poll_interval_seconds"`
	LocalAPIListen           string `json:"local_api_listen"`
	SerialNumber             string `json:"serial_number"`
}

// Store provides thread-safe access to the agent configuration.
type Store struct {
	mu    sync.RWMutex
	cfg   *AgentConfig
	path  string
	ifMap InterfaceMap
}

// NewStore creates a new config store that reads from the given path.
func NewStore(path string) *Store {
	return &Store{path: path}
}

// Load reads the configuration from disk.
func (s *Store) Load() error {
	data, err := os.ReadFile(s.path)
	if err != nil {
		return fmt.Errorf("read config file %s: %w", s.path, err)
	}

	var cfg AgentConfig
	if err := json.Unmarshal(data, &cfg); err != nil {
		return fmt.Errorf("parse config file %s: %w", s.path, err)
	}

	if cfg.PlatformURL == "" {
		return fmt.Errorf("platform_url is required in config")
	}
	if cfg.AgentKey == "" {
		return fmt.Errorf("agent_key is required in config")
	}
	if cfg.DeviceID == 0 {
		return fmt.Errorf("device_id is required in config")
	}

	// Apply defaults
	if cfg.HeartbeatIntervalSeconds == 0 {
		cfg.HeartbeatIntervalSeconds = 60
	}
	if cfg.ConfigPollIntervalSeconds == 0 {
		cfg.ConfigPollIntervalSeconds = 30
	}
	if cfg.LocalAPIListen == "" {
		cfg.LocalAPIListen = "0.0.0.0:8080"
	}

	s.mu.Lock()
	s.cfg = &cfg
	s.mu.Unlock()

	return nil
}

// Get returns a copy of the current configuration.
func (s *Store) Get() AgentConfig {
	s.mu.RLock()
	defer s.mu.RUnlock()
	if s.cfg == nil {
		return AgentConfig{}
	}
	return *s.cfg
}

// Save writes the configuration to disk.
func (s *Store) Save(cfg *AgentConfig) error {
	s.mu.Lock()
	s.cfg = cfg
	s.mu.Unlock()

	data, err := json.MarshalIndent(cfg, "", "  ")
	if err != nil {
		return fmt.Errorf("marshal config: %w", err)
	}

	if err := os.MkdirAll("/etc/sunipip", 0755); err != nil {
		return fmt.Errorf("create config directory: %w", err)
	}

	if err := os.WriteFile(s.path, data, 0600); err != nil {
		return fmt.Errorf("write config file %s: %w", s.path, err)
	}

	return nil
}

// Exists checks whether the config file exists on disk.
func (s *Store) Exists() bool {
	_, err := os.Stat(s.path)
	return err == nil
}

// Path returns the config file path.
func (s *Store) Path() string {
	return s.path
}

// LoadInterfaceMap reads the interface map from disk. If the file does not
// exist, the default eth0-eth3 mapping is used for backward compatibility.
func (s *Store) LoadInterfaceMap() error {
	data, err := os.ReadFile(DefaultInterfaceMapPath)
	if err != nil {
		if os.IsNotExist(err) {
			s.mu.Lock()
			s.ifMap = DefaultInterfaceMap()
			s.mu.Unlock()
			return nil
		}
		return fmt.Errorf("read interface map %s: %w", DefaultInterfaceMapPath, err)
	}

	var ifMap InterfaceMap
	if err := json.Unmarshal(data, &ifMap); err != nil {
		return fmt.Errorf("parse interface map %s: %w", DefaultInterfaceMapPath, err)
	}

	// Fill any empty fields with defaults
	def := DefaultInterfaceMap()
	if ifMap.WAN == "" {
		ifMap.WAN = def.WAN
	}
	if ifMap.MGMT == "" {
		ifMap.MGMT = def.MGMT
	}
	if ifMap.AP == "" {
		ifMap.AP = def.AP
	}
	if ifMap.LAN == "" {
		ifMap.LAN = def.LAN
	}

	s.mu.Lock()
	s.ifMap = ifMap
	s.mu.Unlock()

	return nil
}

// GetInterfaceMap returns the loaded interface map.
func (s *Store) GetInterfaceMap() InterfaceMap {
	s.mu.RLock()
	defer s.mu.RUnlock()
	return s.ifMap
}
