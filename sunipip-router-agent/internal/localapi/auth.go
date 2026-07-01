package localapi

import (
	"context"
	"log/slog"
	"net/http"
	"strings"
	"sync"
	"time"
)

const tokenCacheTTL = 5 * time.Minute

// tokenCacheEntry stores a cached token verification result.
type tokenCacheEntry struct {
	customerID int
	expiresAt  time.Time
}

// AuthMiddleware verifies Bearer tokens by calling the platform /auth/me endpoint.
type AuthMiddleware struct {
	verifier         TokenVerifier
	boundCustomerID  int
	logger           *slog.Logger

	mu    sync.RWMutex
	cache map[string]tokenCacheEntry
}

// TokenVerifier is the interface for verifying customer tokens.
type TokenVerifier interface {
	VerifyCustomerToken(ctx context.Context, token string) (customerID int, err error)
}

// NewAuthMiddleware creates a new auth middleware.
func NewAuthMiddleware(verifier TokenVerifier, boundCustomerID int, logger *slog.Logger) *AuthMiddleware {
	return &AuthMiddleware{
		verifier:        verifier,
		boundCustomerID: boundCustomerID,
		logger:          logger,
		cache:           make(map[string]tokenCacheEntry),
	}
}

// Wrap returns an HTTP handler that verifies the Authorization header before
// passing the request to the wrapped handler.
func (a *AuthMiddleware) Wrap(next http.HandlerFunc) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		token := extractBearerToken(r)
		if token == "" {
			writeJSONError(w, http.StatusUnauthorized, "missing or invalid Authorization header")
			return
		}

		customerID, err := a.verifyToken(r.Context(), token)
		if err != nil {
			a.logger.Warn("Token verification failed", "error", err, "remote_addr", r.RemoteAddr)
			writeJSONError(w, http.StatusUnauthorized, "authentication failed")
			return
		}

		// Verify the customer owns this device
		a.mu.RLock()
		boundID := a.boundCustomerID
		a.mu.RUnlock()
		if boundID > 0 && customerID != boundID {
			a.logger.Warn("Customer ID mismatch",
				"token_customer_id", customerID,
				"bound_customer_id", boundID,
				"remote_addr", r.RemoteAddr,
			)
			writeJSONError(w, http.StatusForbidden, "access denied")
			return
		}

		next(w, r)
	}
}

// SetBoundCustomerID updates the bound customer ID (set after config is loaded).
func (a *AuthMiddleware) SetBoundCustomerID(id int) {
	a.mu.Lock()
	a.boundCustomerID = id
	a.mu.Unlock()
}

// verifyToken checks the cache or calls the platform API.
func (a *AuthMiddleware) verifyToken(ctx context.Context, token string) (int, error) {
	// Check cache
	a.mu.RLock()
	entry, ok := a.cache[token]
	a.mu.RUnlock()

	if ok && time.Now().Before(entry.expiresAt) {
		return entry.customerID, nil
	}

	// Call platform API
	customerID, err := a.verifier.VerifyCustomerToken(ctx, token)
	if err != nil {
		return 0, err
	}

	// Cache the result
	a.mu.Lock()
	a.cache[token] = tokenCacheEntry{
		customerID: customerID,
		expiresAt:  time.Now().Add(tokenCacheTTL),
	}

	// Prune expired entries periodically (simple approach)
	if len(a.cache) > 100 {
		now := time.Now()
		for k, v := range a.cache {
			if now.After(v.expiresAt) {
				delete(a.cache, k)
			}
		}
	}
	a.mu.Unlock()

	return customerID, nil
}

// extractBearerToken extracts the token from the Authorization header.
func extractBearerToken(r *http.Request) string {
	auth := r.Header.Get("Authorization")
	if auth == "" {
		return ""
	}

	parts := strings.SplitN(auth, " ", 2)
	if len(parts) != 2 || !strings.EqualFold(parts[0], "Bearer") {
		return ""
	}

	return strings.TrimSpace(parts[1])
}
