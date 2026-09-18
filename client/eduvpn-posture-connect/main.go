// SPDX-License-Identifier: MIT

package main

import (
	"crypto/tls"
	"flag"
	"fmt"
	"os"
)

const (
	colorRed    = "\033[31m"
	colorGreen  = "\033[32m"
	colorYellow = "\033[33m"
	colorReset  = "\033[0m"

	defaultCertFile = "/etc/eduvpn-client/device.crt"
	defaultKeyFile  = "/etc/eduvpn-client/device.key"
)

func main() {
	server := flag.String("server", "", "eduVPN server hostname (required)")
	profileID := flag.String("profile", "", "VPN profile ID (default: first available)")
	certFile := flag.String("cert", defaultCertFile, "path to device certificate")
	keyFile := flag.String("key", defaultKeyFile, "path to device private key")
	caCert := flag.String("ca", "", "path to CA bundle for server TLS verification (default: system)")
	iface := flag.String("interface", "eduvpn", "WireGuard interface name")
	token := flag.String("token", "", "OAuth2 access token (skips interactive browser login; e.g. from headless-oauth.sh)")
	dryRun := flag.Bool("dry-run", false, "run the posture check only (stop after /v3/connect; do not bring up WireGuard)")
	flag.Parse()

	if *server == "" {
		fmt.Fprintf(os.Stderr, "Usage: eduvpn-posture-connect --server <hostname> [options]\n")
		flag.PrintDefaults()
		os.Exit(1)
	}

	// Load the device certificate for mTLS. It is optional: an unenrolled device
	// has none, and the server must reject it ("device certificate required").
	// When the cert/key are missing at the DEFAULT paths we treat the device as
	// unenrolled and connect without one; an explicitly-given path that fails to
	// load is a hard error (the user asked for a specific cert).
	var clientCert *tls.Certificate
	switch {
	case *certFile == "" || *keyFile == "":
		fmt.Printf("%sNo device certificate%s — connecting without one (expect rejection)\n", colorYellow, colorReset)
	default:
		c, err := tls.LoadX509KeyPair(*certFile, *keyFile)
		if err != nil {
			usingDefaults := *certFile == defaultCertFile && *keyFile == defaultKeyFile
			_, certStatErr := os.Stat(*certFile)
			_, keyStatErr := os.Stat(*keyFile)
			if usingDefaults && (os.IsNotExist(certStatErr) || os.IsNotExist(keyStatErr)) {
				fmt.Printf("%sNo device certificate%s at default path — connecting without one (expect rejection)\n", colorYellow, colorReset)
				break
			}
			fmt.Fprintf(os.Stderr, "%sERROR%s: failed to load device certificate: %v\n", colorRed, colorReset, err)
			fmt.Fprintf(os.Stderr, "  cert: %s\n  key:  %s\n", *certFile, *keyFile)
			os.Exit(1)
		}
		clientCert = &c
		fmt.Printf("Device certificate loaded from %s\n", *certFile)
	}

	// Step 1: Discover API endpoints
	fmt.Printf("Discovering endpoints for %s...\n", *server)
	endpoints, err := discover(*server, *caCert, clientCert)
	if err != nil {
		fmt.Fprintf(os.Stderr, "%sERROR%s: discovery failed: %v\n", colorRed, colorReset, err)
		os.Exit(1)
	}
	fmt.Printf("  API:   %s\n", endpoints.APIEndpoint)
	fmt.Printf("  Auth:  %s\n", endpoints.AuthorizationEndpoint)
	fmt.Printf("  Token: %s\n", endpoints.TokenEndpoint)

	// Step 2: obtain an OAuth2 access token — either supplied (headless) or via
	// the interactive browser authorization-code flow.
	var accessToken string
	if *token != "" {
		accessToken = *token
		fmt.Printf("Using supplied OAuth2 access token (headless mode)\n")
	} else {
		fmt.Printf("\nStarting OAuth2 authorization...\n")
		accessToken, err = oauthAuthorize(endpoints, *caCert, clientCert)
		if err != nil {
			fmt.Fprintf(os.Stderr, "%sERROR%s: OAuth2 authorization failed: %v\n", colorRed, colorReset, err)
			os.Exit(1)
		}
		fmt.Printf("%sOAuth2 authorization successful%s\n", colorGreen, colorReset)
	}

	// Step 3: Get profile list if no profile specified
	if *profileID == "" {
		fmt.Printf("\nFetching available profiles...\n")
		profiles, err := getProfiles(endpoints.APIEndpoint, accessToken, *caCert, clientCert)
		if err != nil {
			fmt.Fprintf(os.Stderr, "%sERROR%s: failed to fetch profiles: %v\n", colorRed, colorReset, err)
			os.Exit(1)
		}
		if len(profiles) == 0 {
			fmt.Fprintf(os.Stderr, "%sERROR%s: no VPN profiles available for your account\n", colorRed, colorReset)
			os.Exit(1)
		}
		*profileID = profiles[0].ID
		fmt.Printf("  Using profile: %s (%s)\n", profiles[0].DisplayName, profiles[0].ID)
	}

	// Step 4: Connect (POST /v3/connect with mTLS)
	fmt.Printf("\nConnecting to profile %q (with device posture check)...\n", *profileID)
	wgConfig, wgPrivateKey, err := connect(endpoints.APIEndpoint, accessToken, *profileID, *caCert, clientCert)
	if err != nil {
		if postureErr, ok := err.(*PostureRejectError); ok {
			fmt.Fprintf(os.Stderr, "\n%s╔══════════════════════════════════════════════╗%s\n", colorRed, colorReset)
			fmt.Fprintf(os.Stderr, "%s║  CONNECTION REJECTED — POSTURE CHECK FAILED  ║%s\n", colorRed, colorReset)
			fmt.Fprintf(os.Stderr, "%s╚══════════════════════════════════════════════╝%s\n", colorRed, colorReset)
			fmt.Fprintf(os.Stderr, "\n  Reason: %s\n\n", postureErr.Reason)
			os.Exit(2)
		}
		fmt.Fprintf(os.Stderr, "%sERROR%s: connect failed: %v\n", colorRed, colorReset, err)
		os.Exit(1)
	}

	// Posture check passed (HTTP 200). In dry-run mode we stop here — the
	// load-bearing proof is the /v3/connect decision, not the tunnel.
	if *dryRun {
		fmt.Printf("\n%s╔══════════════════════════════════════╗%s\n", colorGreen, colorReset)
		fmt.Printf("%s║  POSTURE CHECK PASSED (dry run)      ║%s\n", colorGreen, colorReset)
		fmt.Printf("%s╚══════════════════════════════════════╝%s\n", colorGreen, colorReset)
		fmt.Printf("\n  WireGuard config issued for profile %q (tunnel not started).\n", *profileID)
		return
	}

	// Step 5: Set up WireGuard tunnel
	fmt.Printf("Setting up WireGuard interface %q...\n", *iface)
	err = setupWireGuard(wgConfig, *iface, wgPrivateKey)
	if err != nil {
		fmt.Fprintf(os.Stderr, "%sERROR%s: WireGuard setup failed: %v\n", colorRed, colorReset, err)
		os.Exit(1)
	}

	fmt.Printf("\n%s╔══════════════════════════════════════╗%s\n", colorGreen, colorReset)
	fmt.Printf("%s║  CONNECTED — POSTURE CHECK PASSED    ║%s\n", colorGreen, colorReset)
	fmt.Printf("%s╚══════════════════════════════════════╝%s\n", colorGreen, colorReset)
	fmt.Printf("\n  Server:    %s\n", *server)
	fmt.Printf("  Profile:   %s\n", *profileID)
	fmt.Printf("  Interface: %s\n", *iface)
	fmt.Printf("\n  To disconnect: wg-quick down %s\n", *iface)
}
