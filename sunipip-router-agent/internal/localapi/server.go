package localapi

import (
	"context"
	"fmt"
	"log/slog"
	"net/http"
	"time"

	"sunipip-router-agent/internal/api"
	"sunipip-router-agent/internal/health"
	"sunipip-router-agent/internal/manager"
)

// Server is the local HTTP API server for the device frontend.
type Server struct {
	httpServer *http.Server
	handlers   *Handlers
	auth       *AuthMiddleware
	logger     *slog.Logger
}

// NewServer creates a new local API server.
func NewServer(
	listenAddr string,
	mgr *manager.Manager,
	client *api.Client,
	collector *health.Collector,
	logger *slog.Logger,
) *Server {
	handlerLogger := logger.With("component", "localapi")
	handlers := NewHandlers(mgr, collector, handlerLogger)
	auth := NewAuthMiddleware(client, 0, handlerLogger)

	mux := http.NewServeMux()

	// Register routes with auth middleware
	mux.HandleFunc("/api/status", auth.Wrap(handlers.HandleStatus))
	mux.HandleFunc("/api/network", auth.Wrap(handlers.HandleNetwork))
	mux.HandleFunc("/api/services", auth.Wrap(handlers.HandleServices))
	mux.HandleFunc("/api/connected-devices", auth.Wrap(handlers.HandleConnectedDevices))
	mux.HandleFunc("/api/restart-service", auth.Wrap(handlers.HandleRestartService))
	// No-auth endpoints — safe on management network (physically secured)
	mux.HandleFunc("/api/network-check", handlers.HandleNetworkCheck)
	mux.HandleFunc("/api/diagnostics", handlers.HandleDiagnostics)
	mux.HandleFunc("/api/wan-config", handlers.HandleWANConfig)

	// Health check (no auth required)
	mux.HandleFunc("/health", func(w http.ResponseWriter, r *http.Request) {
		writeJSON(w, http.StatusOK, map[string]interface{}{
			"status":  "ok",
			"version": api.AgentVersion,
		})
	})

	srv := &http.Server{
		Addr:         listenAddr,
		Handler:      mux,
		ReadTimeout:  10 * time.Second,
		WriteTimeout: 30 * time.Second,
		IdleTimeout:  60 * time.Second,
	}

	return &Server{
		httpServer: srv,
		handlers:   handlers,
		auth:       auth,
		logger:     handlerLogger,
	}
}

// SetBoundCustomerID sets the customer ID that is authorized to access this device.
func (s *Server) SetBoundCustomerID(id int) {
	s.auth.SetBoundCustomerID(id)
}

// Start starts the local API server in a goroutine.
func (s *Server) Start() error {
	s.logger.Info("Starting local API server", "addr", s.httpServer.Addr)

	go func() {
		if err := s.httpServer.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			s.logger.Error("Local API server error", "error", err)
		}
	}()

	return nil
}

// Shutdown gracefully stops the server.
func (s *Server) Shutdown(ctx context.Context) error {
	s.logger.Info("Shutting down local API server")
	ctx, cancel := context.WithTimeout(ctx, 5*time.Second)
	defer cancel()

	if err := s.httpServer.Shutdown(ctx); err != nil {
		return fmt.Errorf("shutdown local API: %w", err)
	}
	return nil
}
