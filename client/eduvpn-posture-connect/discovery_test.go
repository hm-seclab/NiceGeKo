// SPDX-License-Identifier: MIT

package main

import (
	"crypto/tls"
	"encoding/pem"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// caFileFor writes an httptest TLS server's self-signed cert to a temp PEM file
// and returns its path, so newHTTPClient can trust it via the caCert parameter.
func caFileFor(t *testing.T, srv *httptest.Server) string {
	t.Helper()
	cert := srv.Certificate()
	pemBytes := pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: cert.Raw})
	f := filepath.Join(t.TempDir(), "ca.pem")
	if err := os.WriteFile(f, pemBytes, 0o600); err != nil {
		t.Fatal(err)
	}
	return f
}

func TestDiscover_Success(t *testing.T) {
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/.well-known/vpn-user-portal" {
			t.Errorf("path = %s", r.URL.Path)
		}
		_, _ = w.Write([]byte(`{"api":{"http://eduvpn.org/api#3":{` +
			`"api_endpoint":"https://vpn/vpn-user-portal/api/v3",` +
			`"authorization_endpoint":"https://vpn/vpn-user-portal/oauth/authorize",` +
			`"token_endpoint":"https://vpn/vpn-user-portal/oauth/token"}}}`))
	}))
	defer srv.Close()

	host := strings.TrimPrefix(srv.URL, "https://")
	ep, err := discover(host, caFileFor(t, srv), nil)
	if err != nil {
		t.Fatal(err)
	}
	if ep.APIEndpoint != "https://vpn/vpn-user-portal/api/v3" {
		t.Errorf("APIEndpoint = %q", ep.APIEndpoint)
	}
	if ep.TokenEndpoint == "" || ep.AuthorizationEndpoint == "" {
		t.Errorf("missing endpoints: %+v", ep)
	}
}

func TestDiscover_NoAPIv3(t *testing.T) {
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte(`{"api":{"http://eduvpn.org/api#2":{}}}`))
	}))
	defer srv.Close()

	host := strings.TrimPrefix(srv.URL, "https://")
	_, err := discover(host, caFileFor(t, srv), nil)
	if err == nil || !strings.Contains(err.Error(), "does not advertise API v3") {
		t.Errorf("err = %v, want 'does not advertise API v3'", err)
	}
}

// Security regression guard: the client pins TLS 1.3 minimum, so a TLS-1.2-only
// server must be rejected at the handshake.
func TestDiscover_RejectsTLS12(t *testing.T) {
	srv := httptest.NewUnstartedServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte(`{"api":{"http://eduvpn.org/api#3":{}}}`))
	}))
	srv.TLS = &tls.Config{MaxVersion: tls.VersionTLS12}
	srv.StartTLS()
	defer srv.Close()

	host := strings.TrimPrefix(srv.URL, "https://")
	_, err := discover(host, caFileFor(t, srv), nil)
	if err == nil {
		t.Fatal("expected TLS-1.3-min client to reject a TLS-1.2-only server")
	}
	// Require the failure to be a VERSION negotiation failure specifically.
	// Merely asserting err != nil would also be satisfied by an unreadable CA
	// file, a refused connection or a DNS failure — i.e. the test would stay
	// green even if MinVersion were removed and something unrelated broke.
	if !strings.Contains(err.Error(), "protocol version") {
		t.Errorf("err = %v, want a TLS protocol-version failure", err)
	}
}
