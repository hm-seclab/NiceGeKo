// SPDX-License-Identifier: MIT

package main

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"regexp"
	"testing"
)

var urlSafe = regexp.MustCompile(`^[A-Za-z0-9\-_]*$`)

// computeCodeChallenge must match the canonical RFC 7636 Appendix B S256 vector.
func TestComputeCodeChallenge_RFC7636Vector(t *testing.T) {
	verifier := "dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk"
	want := "E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM"
	if got := computeCodeChallenge(verifier); got != want {
		t.Errorf("computeCodeChallenge = %q, want %q", got, want)
	}
}

func TestGenerateCodeVerifier(t *testing.T) {
	v, err := generateCodeVerifier()
	if err != nil {
		t.Fatal(err)
	}
	if len(v) != 43 { // base64url(32 bytes) = 43 chars
		t.Errorf("verifier length = %d, want 43", len(v))
	}
	if !urlSafe.MatchString(v) {
		t.Errorf("verifier is not URL-safe: %q", v)
	}
}

func TestGenerateRandomString(t *testing.T) {
	for _, n := range []int{8, 16, 32} {
		s, err := generateRandomString(n)
		if err != nil {
			t.Fatal(err)
		}
		if len(s) != n {
			t.Errorf("generateRandomString(%d) length = %d", n, len(s))
		}
		if !urlSafe.MatchString(s) {
			t.Errorf("generateRandomString(%d) not URL-safe: %q", n, s)
		}
	}
	// Characterization: reads n random bytes but keeps only the first n
	// base64url chars, so effective entropy is ~6n bits (not 8n). Adequate for
	// an OAuth state value; noted here so a future change is a conscious one.
}

func TestExchangeCode_Success(t *testing.T) {
	// This test asserts the REQUEST, not just the response. The token exchange is
	// the one request no other tier covers (e2e sources its token from
	// headless-oauth.sh), so without these checks deleting code_verifier — i.e.
	// removing PKCE entirely — left the whole suite green.
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			t.Errorf("method = %s, want POST", r.Method)
		}
		if err := r.ParseForm(); err != nil {
			t.Fatalf("ParseForm: %v", err)
		}
		for _, tc := range []struct{ key, want string }{
			{"grant_type", "authorization_code"},
			{"code", "code"},
			{"redirect_uri", "http://127.0.0.1:8000/callback"},
			{"code_verifier", "verifier"}, // PKCE proof — must be sent
			{"client_id", "org.eduvpn.app.linux"},
		} {
			if got := r.Form.Get(tc.key); got != tc.want {
				t.Errorf("form[%s] = %q, want %q", tc.key, got, tc.want)
			}
		}
		if ct := r.Header.Get("Content-Type"); ct != "application/x-www-form-urlencoded" {
			t.Errorf("Content-Type = %q", ct)
		}
		_ = json.NewEncoder(w).Encode(map[string]any{
			"access_token": "tok123", "token_type": "Bearer", "expires_in": 3600,
		})
	}))
	defer srv.Close()

	tok, err := exchangeCode(srv.URL, "code", "http://127.0.0.1:8000/callback", "verifier", "", nil)
	if err != nil {
		t.Fatal(err)
	}
	if tok != "tok123" {
		t.Errorf("token = %q, want tok123", tok)
	}
}

func TestExchangeCode_NoToken(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte(`{"error":"invalid_grant"}`))
	}))
	defer srv.Close()

	if _, err := exchangeCode(srv.URL, "code", "http://127.0.0.1:8000/callback", "verifier", "", nil); err == nil {
		t.Error("expected error when response has no access_token")
	}
}
