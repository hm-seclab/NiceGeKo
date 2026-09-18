// SPDX-License-Identifier: MIT

package main

import (
	"encoding/base64"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestPostureRejectError_Error(t *testing.T) {
	e := &PostureRejectError{Reason: "device certificate required"}
	if !strings.Contains(e.Error(), "device certificate required") {
		t.Errorf("Error() = %q", e.Error())
	}
}

func TestGetProfiles(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/info" {
			t.Errorf("path = %s, want /info", r.URL.Path)
		}
		if r.Header.Get("Authorization") != "Bearer tok" {
			t.Errorf("Authorization = %q", r.Header.Get("Authorization"))
		}
		_, _ = w.Write([]byte(`{"info":{"profile_list":[{"profile_id":"default","display_name":"Default"}]}}`))
	}))
	defer srv.Close()

	profiles, err := getProfiles(srv.URL, "tok", "", nil)
	if err != nil {
		t.Fatal(err)
	}
	if len(profiles) != 1 || profiles[0].ID != "default" {
		t.Errorf("profiles = %+v", profiles)
	}
}

func TestConnect_Success(t *testing.T) {
	// Assert the REQUEST as well as the response: without this, dropping the
	// bearer header or sending no public_key still passed.
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/connect" {
			t.Errorf("path = %s, want /connect", r.URL.Path)
		}
		if r.Method != http.MethodPost {
			t.Errorf("method = %s, want POST", r.Method)
		}
		if got := r.Header.Get("Authorization"); got != "Bearer tok" {
			t.Errorf("Authorization = %q, want %q", got, "Bearer tok")
		}
		if err := r.ParseForm(); err != nil {
			t.Fatalf("ParseForm: %v", err)
		}
		if got := r.Form.Get("profile_id"); got != "default" {
			t.Errorf("form[profile_id] = %q, want default", got)
		}
		// The server needs the client's WireGuard PUBLIC key to build the peer.
		// It must be present and must be a valid 32-byte X25519 key.
		pub := r.Form.Get("public_key")
		if pub == "" {
			t.Error("form[public_key] is missing — the server cannot build a peer without it")
		} else if raw, err := base64.StdEncoding.DecodeString(pub); err != nil {
			t.Errorf("form[public_key] = %q is not standard base64: %v", pub, err)
		} else if len(raw) != 32 {
			t.Errorf("form[public_key] decodes to %d bytes, want 32", len(raw))
		}
		_, _ = w.Write([]byte("[Interface]\nPrivateKey = REPLACE_ME\n"))
	}))
	defer srv.Close()

	cfg, priv, err := connect(srv.URL, "tok", "default", "", nil)
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(cfg, "[Interface]") {
		t.Errorf("config = %q", cfg)
	}
	if priv == "" {
		t.Error("connect returned an empty private key")
	}
}

// A 403 with a JSON {"error": …} body must surface as *PostureRejectError.
func TestConnect_PostureReject_JSON(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusForbidden)
		_, _ = w.Write([]byte(`{"error":"no Wazuh agent registered for device \"device-x\""}`))
	}))
	defer srv.Close()

	_, _, err := connect(srv.URL, "tok", "default", "", nil)
	pe, ok := err.(*PostureRejectError)
	if !ok {
		t.Fatalf("error type = %T (%v), want *PostureRejectError", err, err)
	}
	if !strings.Contains(pe.Reason, "no Wazuh agent registered") {
		t.Errorf("reason = %q", pe.Reason)
	}
}

// A 403 with a non-JSON body still yields a PostureRejectError with the raw body.
func TestConnect_PostureReject_NonJSON(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusForbidden)
		_, _ = w.Write([]byte("plain forbidden"))
	}))
	defer srv.Close()

	_, _, err := connect(srv.URL, "tok", "default", "", nil)
	pe, ok := err.(*PostureRejectError)
	if !ok {
		t.Fatalf("error type = %T, want *PostureRejectError", err)
	}
	if !strings.Contains(pe.Reason, "plain forbidden") {
		t.Errorf("reason = %q", pe.Reason)
	}
}

// A 5xx is a transport/server error, NOT a posture rejection.
func TestConnect_ServerError(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusInternalServerError)
		_, _ = w.Write([]byte("boom"))
	}))
	defer srv.Close()

	_, _, err := connect(srv.URL, "tok", "default", "", nil)
	if err == nil {
		t.Fatal("expected error on HTTP 500")
	}
	if _, ok := err.(*PostureRejectError); ok {
		t.Error("HTTP 500 must not be classified as a posture rejection")
	}
}
