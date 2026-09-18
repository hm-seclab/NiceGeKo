// SPDX-License-Identifier: MIT

package main

import (
	"crypto/ecdh"
	"crypto/rand"
	"crypto/tls"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
)

// PostureRejectError is returned when the server rejects the connection
// due to a failed posture check (HTTP 403).
type PostureRejectError struct {
	Reason string
}

func (e *PostureRejectError) Error() string {
	return fmt.Sprintf("posture check failed: %s", e.Reason)
}

// ProfileInfo represents a VPN profile from the /v3/info endpoint.
type ProfileInfo struct {
	ID          string `json:"profile_id"`
	DisplayName string `json:"display_name"`
}

// getProfiles fetches available VPN profiles from the /v3/info endpoint.
func getProfiles(apiEndpoint, accessToken, caCert string, clientCert *tls.Certificate) ([]ProfileInfo, error) {
	client := newHTTPClient(caCert, clientCert)

	req, err := http.NewRequest("GET", apiEndpoint+"/info", nil)
	if err != nil {
		return nil, err
	}
	req.Header.Set("Authorization", "Bearer "+accessToken)

	resp, err := client.Do(req)
	if err != nil {
		return nil, fmt.Errorf("GET /v3/info: %w", err)
	}
	defer resp.Body.Close()

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return nil, err
	}

	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("/v3/info returned HTTP %d: %s", resp.StatusCode, string(body))
	}

	var result struct {
		Info struct {
			ProfileList []ProfileInfo `json:"profile_list"`
		} `json:"info"`
	}

	if err := json.Unmarshal(body, &result); err != nil {
		return nil, fmt.Errorf("parsing /v3/info: %w", err)
	}

	return result.Info.ProfileList, nil
}

// connect calls POST /v3/connect with the bearer token and mTLS client cert.
// On success it returns the WireGuard configuration and the base64 private key
// belonging to the public key we registered (the caller needs it to bring the
// tunnel up, since the server never sees it). Returns a PostureRejectError on 403.
func connect(apiEndpoint, accessToken, profileID, caCert string, clientCert *tls.Certificate) (string, string, error) {
	// Generate a WireGuard key pair; keep the private key for the caller.
	publicKey, privateKey, err := generateWireGuardKeyPair()
	if err != nil {
		return "", "", fmt.Errorf("generating WireGuard key pair: %w", err)
	}

	client := newHTTPClient(caCert, clientCert)

	data := url.Values{
		"profile_id": {profileID},
		"public_key": {publicKey},
	}

	req, err := http.NewRequest("POST", apiEndpoint+"/connect", strings.NewReader(data.Encode()))
	if err != nil {
		return "", "", err
	}
	req.Header.Set("Authorization", "Bearer "+accessToken)
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	// Request WireGuard config specifically
	req.Header.Set("Accept", "application/x-wireguard-profile")

	resp, err := client.Do(req)
	if err != nil {
		return "", "", fmt.Errorf("POST /v3/connect: %w", err)
	}
	defer resp.Body.Close()

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return "", "", err
	}

	switch resp.StatusCode {
	case http.StatusOK:
		return string(body), privateKey, nil

	case http.StatusForbidden:
		// Parse the rejection reason from JSON
		var errResp struct {
			Error string `json:"error"`
		}
		if err := json.Unmarshal(body, &errResp); err != nil {
			return "", "", &PostureRejectError{Reason: string(body)}
		}
		return "", "", &PostureRejectError{Reason: errResp.Error}

	default:
		return "", "", fmt.Errorf("/v3/connect returned HTTP %d: %s", resp.StatusCode, string(body))
	}
}

// generateWireGuardKeyPair generates a Curve25519 key pair using Go's
// stdlib crypto/ecdh (available since Go 1.20). Returns the base64-encoded
// public and private keys.
func generateWireGuardKeyPair() (publicKey, privateKey string, err error) {
	curve := ecdh.X25519()
	privKey, err := curve.GenerateKey(rand.Reader)
	if err != nil {
		return "", "", err
	}

	publicKey = base64.StdEncoding.EncodeToString(privKey.PublicKey().Bytes())
	privateKey = base64.StdEncoding.EncodeToString(privKey.Bytes())
	return publicKey, privateKey, nil
}
