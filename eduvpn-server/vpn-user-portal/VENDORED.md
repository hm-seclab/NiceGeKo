# Vendored copy

This directory is a **vendored fork** of upstream eduVPN `vpn-user-portal`, absorbed
directly into this project's repository as plain files (it is no longer a separate git
clone / submodule).

- **Upstream:** https://codeberg.org/eduVPN/vpn-user-portal.git
- **Branch:** `v3`
- **Base commit:** `a92fd2e5` ("ClientConfig::wgQuick must not contain Override attribute")
- **Vendored on:** 2026-07-01

The project's posture additions are layered on top of that base (see
`eduvpn-integration/README.md` for the authoritative list). To compare against or pull in
later upstream changes, diff against upstream commit `a92fd2e5`.
