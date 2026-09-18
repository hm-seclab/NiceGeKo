// SPDX-License-Identifier: MIT

package main

import (
	"crypto/tls"
	"crypto/x509"
	"fmt"
	"net/http"
	"os"
	"time"
)

// newHTTPClient creates an HTTP client configured with the device certificate
// for mTLS and optional custom CA verification.
func newHTTPClient(caCert string, clientCert *tls.Certificate) *http.Client {
	tlsConfig := &tls.Config{
		MinVersion: tls.VersionTLS13,
	}

	if clientCert != nil {
		tlsConfig.Certificates = []tls.Certificate{*clientCert}
	}

	if caCert != "" {
		// A misconfigured --ca must fail loudly, not silently fall back to the
		// system trust store (which would change the trust anchor unnoticed).
		caCertPEM, err := os.ReadFile(caCert)
		if err != nil {
			fmt.Fprintf(os.Stderr, "ERROR: cannot read --ca file %q: %v\n", caCert, err)
			os.Exit(1)
		}
		pool := x509.NewCertPool()
		if !pool.AppendCertsFromPEM(caCertPEM) {
			fmt.Fprintf(os.Stderr, "ERROR: no valid certificates found in --ca file %q\n", caCert)
			os.Exit(1)
		}
		tlsConfig.RootCAs = pool
	}

	return &http.Client{
		Timeout: 30 * time.Second,
		Transport: &http.Transport{
			TLSClientConfig: tlsConfig,
		},
	}
}
