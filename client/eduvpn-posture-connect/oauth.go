// SPDX-License-Identifier: MIT

package main

import (
	"context"
	"crypto/rand"
	"crypto/sha256"
	"crypto/tls"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"os/exec"
	"runtime"
	"time"
)

const (
	// Use the Linux client ID — registered in eduVPN's OAuth client database.
	oauthClientID = "org.eduvpn.app.linux"
	oauthScope    = "config"
)

// oauthAuthorize performs the OAuth2 Authorization Code flow with PKCE.
// It opens the browser for user login, listens for the callback on localhost,
// and exchanges the authorization code for an access token.
func oauthAuthorize(endpoints *Endpoints, caCert string, clientCert *tls.Certificate) (string, error) {
	// Generate PKCE code verifier and challenge
	codeVerifier, err := generateCodeVerifier()
	if err != nil {
		return "", fmt.Errorf("generating PKCE verifier: %w", err)
	}
	codeChallenge := computeCodeChallenge(codeVerifier)

	// Generate random state parameter
	state, err := generateRandomString(32)
	if err != nil {
		return "", fmt.Errorf("generating state: %w", err)
	}

	// Start local HTTP server for OAuth callback
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		return "", fmt.Errorf("starting callback listener: %w", err)
	}
	defer listener.Close()

	port := listener.Addr().(*net.TCPAddr).Port
	redirectURI := fmt.Sprintf("http://127.0.0.1:%d/callback", port)

	// Build authorization URL
	authURL, err := url.Parse(endpoints.AuthorizationEndpoint)
	if err != nil {
		return "", fmt.Errorf("parsing authorization endpoint: %w", err)
	}
	q := authURL.Query()
	q.Set("client_id", oauthClientID)
	q.Set("redirect_uri", redirectURI)
	q.Set("response_type", "code")
	q.Set("scope", oauthScope)
	q.Set("state", state)
	q.Set("code_challenge_method", "S256")
	q.Set("code_challenge", codeChallenge)
	authURL.RawQuery = q.Encode()

	// Channel to receive the authorization code
	codeCh := make(chan string, 1)
	errCh := make(chan error, 1)

	// Non-blocking sends: the channels are buffered (size 1) so the first
	// callback always delivers, and a duplicate/extra callback hit can't wedge
	// its handler goroutine on a full channel.
	sendErr := func(e error) {
		select {
		case errCh <- e:
		default:
		}
	}
	sendCode := func(c string) {
		select {
		case codeCh <- c:
		default:
		}
	}

	mux := http.NewServeMux()
	mux.HandleFunc("/callback", func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Query().Get("state") != state {
			sendErr(fmt.Errorf("OAuth state mismatch"))
			http.Error(w, "state mismatch", http.StatusBadRequest)
			return
		}

		if errMsg := r.URL.Query().Get("error"); errMsg != "" {
			desc := r.URL.Query().Get("error_description")
			sendErr(fmt.Errorf("OAuth error: %s — %s", errMsg, desc))
			fmt.Fprintf(w, "<html><body><h2>Authorization Failed</h2><p>%s: %s</p><p>You can close this window.</p></body></html>", errMsg, desc)
			return
		}

		code := r.URL.Query().Get("code")
		if code == "" {
			sendErr(fmt.Errorf("no authorization code in callback"))
			http.Error(w, "missing code", http.StatusBadRequest)
			return
		}

		sendCode(code)
		fmt.Fprintf(w, "<html><body><h2>Authorization Successful</h2><p>You can close this window and return to the terminal.</p></body></html>")
	})

	server := &http.Server{
		Handler:      mux,
		ReadTimeout:  10 * time.Second,
		WriteTimeout: 10 * time.Second,
	}
	go func() {
		_ = server.Serve(listener)
	}()
	defer func() {
		ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
		defer cancel()
		_ = server.Shutdown(ctx)
	}()

	// Open browser
	fmt.Printf("  Opening browser for login...\n")
	fmt.Printf("  If the browser does not open, visit:\n  %s\n\n", authURL.String())
	openBrowser(authURL.String())

	// Wait for callback (with timeout)
	var authCode string
	select {
	case authCode = <-codeCh:
		// got the code
	case err := <-errCh:
		return "", err
	case <-time.After(5 * time.Minute):
		return "", fmt.Errorf("OAuth authorization timed out (5 minutes)")
	}

	// Exchange authorization code for access token
	return exchangeCode(endpoints.TokenEndpoint, authCode, redirectURI, codeVerifier, caCert, clientCert)
}

// exchangeCode exchanges an authorization code for an access token.
func exchangeCode(tokenEndpoint, code, redirectURI, codeVerifier, caCert string, clientCert *tls.Certificate) (string, error) {
	data := url.Values{
		"grant_type":    {"authorization_code"},
		"code":          {code},
		"redirect_uri":  {redirectURI},
		"client_id":     {oauthClientID},
		"code_verifier": {codeVerifier},
	}

	client := newHTTPClient(caCert, clientCert)
	resp, err := client.PostForm(tokenEndpoint, data)
	if err != nil {
		return "", fmt.Errorf("token request: %w", err)
	}
	defer resp.Body.Close()

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return "", fmt.Errorf("reading token response: %w", err)
	}

	if resp.StatusCode != http.StatusOK {
		return "", fmt.Errorf("token endpoint returned HTTP %d: %s", resp.StatusCode, string(body))
	}

	var tokenResp struct {
		AccessToken string `json:"access_token"`
		TokenType   string `json:"token_type"`
		ExpiresIn   int    `json:"expires_in"`
	}

	if err := json.Unmarshal(body, &tokenResp); err != nil {
		return "", fmt.Errorf("parsing token response: %w", err)
	}

	if tokenResp.AccessToken == "" {
		return "", fmt.Errorf("no access_token in response")
	}

	return tokenResp.AccessToken, nil
}

// generateCodeVerifier creates a random PKCE code verifier (43–128 chars).
func generateCodeVerifier() (string, error) {
	b := make([]byte, 32)
	if _, err := rand.Read(b); err != nil {
		return "", err
	}
	return base64.RawURLEncoding.EncodeToString(b), nil
}

// computeCodeChallenge computes the S256 PKCE code challenge from a verifier.
func computeCodeChallenge(verifier string) string {
	h := sha256.Sum256([]byte(verifier))
	return base64.RawURLEncoding.EncodeToString(h[:])
}

// generateRandomString creates a cryptographically random URL-safe string of n
// characters. Note: it reads n random bytes then keeps the first n base64url
// chars, so effective entropy is ~6n bits (not 8n) — ample for the OAuth `state`
// value it is used for. Do not reuse where exactly n bytes of entropy are required.
func generateRandomString(n int) (string, error) {
	b := make([]byte, n)
	if _, err := rand.Read(b); err != nil {
		return "", err
	}
	return base64.RawURLEncoding.EncodeToString(b)[:n], nil
}

// openBrowser opens the given URL in the default browser.
func openBrowser(url string) {
	var cmd *exec.Cmd
	switch runtime.GOOS {
	case "linux":
		cmd = exec.Command("xdg-open", url)
	case "darwin":
		cmd = exec.Command("open", url)
	default:
		cmd = exec.Command("xdg-open", url)
	}
	_ = cmd.Start()

	// Don't wait for the browser process — it may block
	go func() {
		if cmd.Process != nil {
			_ = cmd.Wait()
		}
	}()

	// Give the browser a moment to handle the URL before our HTTP server
	// starts serving the callback, avoiding timing issues.
	time.Sleep(500 * time.Millisecond)
}
