// SPDX-License-Identifier: MIT

package main

import (
	"fmt"
	"os"
	"os/exec"
	"strings"
)

// setupWireGuard writes the WireGuard configuration to a file and brings
// the interface up using wg-quick.
func setupWireGuard(config, iface, privateKey string) error {
	// When the client supplies its own public_key on /v3/connect (as we do),
	// the eduVPN v3 API returns a config with NO PrivateKey line — the server
	// never learns our private key. We must add it ourselves so wg-quick has a
	// usable [Interface] key.
	config = ensurePrivateKey(config, privateKey)

	// Write config to wg-quick compatible path
	configPath := fmt.Sprintf("/etc/wireguard/%s.conf", iface)

	if err := os.WriteFile(configPath, []byte(config), 0600); err != nil {
		return fmt.Errorf("writing WireGuard config to %s: %w", configPath, err)
	}
	fmt.Printf("  WireGuard config written to %s\n", configPath)

	// Bring down any existing interface with same name (ignore errors)
	downCmd := exec.Command("wg-quick", "down", iface)
	_ = downCmd.Run()

	// Bring up the interface
	upCmd := exec.Command("wg-quick", "up", iface)
	upCmd.Stdout = os.Stdout
	upCmd.Stderr = os.Stderr

	if err := upCmd.Run(); err != nil {
		return fmt.Errorf("wg-quick up %s: %w", iface, err)
	}

	return nil
}

// ensurePrivateKey makes sure the WireGuard config carries our private key.
// If a PrivateKey line is present it is replaced; otherwise a PrivateKey line
// is inserted immediately after the [Interface] section header. The eduVPN v3
// /connect response omits PrivateKey entirely when the client supplies its own
// public_key, so the insert path is the normal case — replacing only matters
// for configs that already contain a placeholder.
func ensurePrivateKey(config, privateKey string) string {
	lines := strings.Split(config, "\n")
	for i, line := range lines {
		if strings.HasPrefix(strings.TrimSpace(line), "PrivateKey") {
			lines[i] = "PrivateKey = " + privateKey
			return strings.Join(lines, "\n")
		}
	}
	// No PrivateKey line: insert one just after the [Interface] header.
	for i, line := range lines {
		if strings.TrimSpace(line) == "[Interface]" {
			out := make([]string, 0, len(lines)+1)
			out = append(out, lines[:i+1]...)
			out = append(out, "PrivateKey = "+privateKey)
			out = append(out, lines[i+1:]...)
			return strings.Join(out, "\n")
		}
	}
	// No [Interface] header at all (unexpected): prepend a minimal one.
	return "[Interface]\nPrivateKey = " + privateKey + "\n" + config
}
