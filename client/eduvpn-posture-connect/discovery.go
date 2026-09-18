// SPDX-License-Identifier: MIT

package main

import (
	"crypto/tls"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
)

// Endpoints holds the discovered eduVPN API endpoint URLs.
type Endpoints struct {
	APIEndpoint           string
	AuthorizationEndpoint string
	TokenEndpoint         string
}

// discover fetches the .well-known/vpn-user-portal endpoint to find
// the API, authorization, and token endpoint URLs.
func discover(server, caCert string, clientCert *tls.Certificate) (*Endpoints, error) {
	url := fmt.Sprintf("https://%s/.well-known/vpn-user-portal", server)

	client := newHTTPClient(caCert, clientCert)
	resp, err := client.Get(url)
	if err != nil {
		return nil, fmt.Errorf("GET %s: %w", url, err)
	}
	defer resp.Body.Close()

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return nil, fmt.Errorf("reading discovery response: %w", err)
	}

	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("discovery returned HTTP %d: %s", resp.StatusCode, string(body))
	}

	var result struct {
		API map[string]struct {
			APIEndpoint           string `json:"api_endpoint"`
			AuthorizationEndpoint string `json:"authorization_endpoint"`
			TokenEndpoint         string `json:"token_endpoint"`
		} `json:"api"`
	}

	if err := json.Unmarshal(body, &result); err != nil {
		return nil, fmt.Errorf("parsing discovery JSON: %w", err)
	}

	apiInfo, ok := result.API["http://eduvpn.org/api#3"]
	if !ok {
		return nil, fmt.Errorf("server does not advertise API v3")
	}

	return &Endpoints{
		APIEndpoint:           apiInfo.APIEndpoint,
		AuthorizationEndpoint: apiInfo.AuthorizationEndpoint,
		TokenEndpoint:         apiInfo.TokenEndpoint,
	}, nil
}
