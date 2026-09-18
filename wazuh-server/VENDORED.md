# Vendored: Wazuh Docker configuration

Everything in this directory is **derived from the upstream Wazuh Docker project**
and is **not** covered by this repository's MIT licence.

| | |
|---|---|
| Upstream | <https://github.com/wazuh/wazuh-docker> |
| Version | `v4.14.3` (matches `WAZUH_VERSION` in the root `.env`) |
| Source path | `single-node/` |
| Copyright | Wazuh App Copyright (C) 2017, Wazuh Inc. |
| Licence | **GPL-2.0-only** — upstream's `LICENSE` reads "under the terms of the GNU General Public License (version 2)" with no "or any later version" clause |

## Files

| File | Upstream original | Relationship |
|---|---|---|
| `generate-certs.yml` | `single-node/generate-indexer-certs.yml` | near-verbatim; renamed, banner comment added |
| `config/certs.yml` | `single-node/config/certs.yml` | one comment line changed |
| `config/wazuh_dashboard/wazuh.yml` | same path | byte-identical |
| `config/wazuh_dashboard/opensearch_dashboards.yml` | same path | hostnames adjusted |
| `config/wazuh_indexer/internal_users.yml` | same path | demo password hashes replaced |
| `config/wazuh_indexer/wazuh.indexer.yml` | same path | node/hostnames adjusted |
| `config/wazuh_cluster/wazuh_manager.conf` | same path | `<use_password>no</use_password>` for password-less agent enrolment; see the root README's limitations |

The Wazuh service definitions in the **root** `docker-compose.yml` (`wazuh.manager`,
`wazuh.indexer`, `wazuh.dashboard`) are likewise adapted from
`single-node/docker-compose.yml`, with the agent ports deliberately changed from
published to `expose`-only.

## Licence note

This project's own code is MIT. MIT is a permissive licence and is compatible with
GPL-2.0-only, so the earlier AGPL-3.0 ↔ GPL-2.0-only incompatibility no longer
applies. These files nonetheless remain **separately licensed under GPL-2.0-only**:
the MIT grant does not extend to them, and they stay configuration for
separately-distributed upstream container images — the repository ships no Wazuh
source code or binaries, only configuration that is fetched and run by the official
`wazuh/*` images at runtime.

Upstream's copyright notices have been restored on each derived file, as GPLv2 §1
requires, and a full copy of the GPL-2.0 text ships at
[`LICENSES/GPL-2.0-only.txt`](../LICENSES/GPL-2.0-only.txt). If you redistribute this
repository and are unsure whether your use constitutes mere aggregation, seek your own
advice — nothing here is a legal opinion.

## Restoring pristine upstream files

```bash
git clone --depth 1 -b v4.14.3 https://github.com/wazuh/wazuh-docker.git
diff -u wazuh-docker/single-node/config/certs.yml wazuh-server/config/certs.yml
```
