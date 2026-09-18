# Vendored copy — eduVPN deploy scripts

These are the **official eduVPN server deploy scripts**, vendored into this repo so
the server container builds reproducibly from a pinned version (no build-time clone).

- **Upstream:** https://codeberg.org/eduVPN/deploy.git
- **Branch:** `v3`
- **Pinned commit:** `f4c87b4426c8f7b6e776f0906de2140791c211e7` ("add Actalis", 2026-06-09)
- **Vendored on:** 2026-07-14

The server container (`eduvpn-server/Dockerfile`) runs `deploy_debian.sh` non-interactively
at first boot. Only the Debian path and its `resources/` are exercised; the RPM/Fedora/EL
scripts are kept as-is from upstream for completeness. To update, re-clone the branch and
diff against the pinned commit above.

## License

**AGPL-3.0-or-later**, on eduVPN's own statement.

The `eduVPN/deploy` repository itself ships no `LICENSE` file and no SPDX header — neither
in the repo nor in these vendored scripts (re-checked 2026-08-04). eduVPN does, however,
state project-wide in their documentation:

> "The VPN server software is licensed under the AGPLv3+."
> — <https://docs.eduvpn.org/server/v2/index.html> (retrieved 2026-08-04)

Every other eduVPN server component (`vpn-user-portal`, `vpn-server-node`) carries an
explicit `AGPL-3.0-or-later` SPDX header, so these deploy scripts are treated as
AGPL-3.0-or-later on that basis.

Recorded honestly, because the evidence is indirect: that sentence appears on the **v2**
documentation index, is not repeated in the v3 docs, and does not name this repository
specifically. If certainty is needed, ask upstream to add a `LICENSE` file to
<https://codeberg.org/eduVPN/deploy>.

These files are included verbatim. This project claims no copyright over them and grants no
rights to them — the licence above is upstream's, not ours, and the repository's own MIT
licence does not extend to this directory.
