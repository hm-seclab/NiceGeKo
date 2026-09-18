// SPDX-License-Identifier: MIT

package main

import (
	"strings"
	"testing"
)

func TestEnsurePrivateKey(t *testing.T) {
	cases := []struct {
		name string
		in   string
	}{
		{"replaces placeholder", "[Interface]\nPrivateKey = OLD\nAddress = 10.0.0.2/24"},
		{"handles no-space form", "[Interface]\nPrivateKey=OLD\n"},
		// The realistic eduVPN v3 /connect response: NO PrivateKey line at all
		// (client supplied its own public_key). The key must be INSERTED, not
		// dropped — this is the F-01 regression guard.
		{"inserts when key line absent", "[Interface]\nAddress = 10.0.0.2/24\n\n[Peer]\nPublicKey = SRV"},
		{"inserts even with no other interface lines", "[Interface]\n[Peer]\nEndpoint = vpn:51820"},
	}
	for _, c := range cases {
		got := ensurePrivateKey(c.in, "NEWKEY")
		if !strings.Contains(got, "PrivateKey = NEWKEY") {
			t.Errorf("%s: result has no usable private key: %q", c.name, got)
		}
		// Only meaningful for the cases whose input actually carries a stale key;
		// asserting it unconditionally passed vacuously for the two insert cases.
		if strings.Contains(c.in, "OLD") && strings.Contains(got, "OLD") {
			t.Errorf("%s: stale key still present in %q", c.name, got)
		}
		// Exactly one PrivateKey line — a duplicate would make wg-quick reject
		// the config, and is the obvious failure mode of an insert-based fix.
		if n := strings.Count(got, "PrivateKey"); n != 1 {
			t.Errorf("%s: found %d PrivateKey lines, want exactly 1: %q", c.name, n, got)
		}
		// The inserted/replaced key must live under [Interface], before [Peer].
		if iface, peer := strings.Index(got, "[Interface]"), strings.Index(got, "[Peer]"); peer != -1 {
			if key := strings.Index(got, "PrivateKey"); !(iface < key && key < peer) {
				t.Errorf("%s: PrivateKey not inside [Interface] section: %q", c.name, got)
			}
		}
	}
}

// The WireGuard key pair must be generatable (used inside connect()).
func TestGenerateWireGuardKeyPair(t *testing.T) {
	pub, priv, err := generateWireGuardKeyPair()
	if err != nil {
		t.Fatal(err)
	}
	if len(pub) == 0 {
		t.Error("empty public key")
	}
	if len(priv) == 0 {
		t.Error("empty private key")
	}
	if pub == priv {
		t.Error("public and private key are identical")
	}
}
