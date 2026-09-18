# SPDX-License-Identifier: MIT
# =============================================================================
# eduVPN server — systemd container running the REAL eduVPN deploy + posture overlay
# =============================================================================
# Unlike the old stub, this image boots systemd as PID 1 and, on first boot,
# runs the official eduVPN `deploy_debian.sh` non-interactively to install a
# genuine eduVPN server (Apache + php-fpm + vpn-user-portal + vpn-server-node).
# It then layers the mTLS device-posture integration on top:
#   * step-ca-issued server TLS cert (SAN vpn.local),
#   * Apache mTLS snippet (SSLVerifyClient optional, vhost-level),
#   * the 5 patched vpn-user-portal files (native posture gate),
#   * a PostureCheck config pointing at the Wazuh Manager API.
#
# Requires (set in docker-compose.yml): privileged, cgroup: host, tmpfs /run.
# See eduvpn-server/first-boot.sh for the provisioning logic.
# =============================================================================
FROM debian:trixie

ENV container=docker DEBIAN_FRONTEND=noninteractive

# --- systemd base + prerequisites -------------------------------------------
RUN apt-get update && apt-get install -y --no-install-recommends \
        systemd systemd-sysv dbus \
        ca-certificates curl openssl sudo cron procps iproute2 \
    && rm -rf /var/lib/apt/lists/*

# Mask units that are meaningless / harmful inside a container.
# NOTE: nftables is masked on purpose — eduVPN's deploy installs a lockdown
# ruleset that begins with `flush ruleset` + `policy drop`, which wipes Docker's
# in-netns DNS/NAT rules and cuts the container off from ca-server/wazuh. The
# eduVPN host firewall is out of scope for the posture-gate PoC (tunnel routing
# is best-effort), so we keep Docker's default networking instead.
RUN systemctl mask \
        systemd-udevd.service systemd-udevd-kernel.socket \
        systemd-udevd-control.socket systemd-modules-load.service \
        sys-kernel-config.mount sys-kernel-debug.mount sys-kernel-tracing.mount \
        nftables.service

# --- step CLI ----------------------------------------------------------------
# Installed from the GitHub release .deb (the Smallstep APT repo has a known
# "Suites: debes" typo — see eduvpn-server/README.md §5.1).
ARG STEP_VERSION=0.29.0
RUN curl -fsSL "https://github.com/smallstep/cli/releases/download/v${STEP_VERSION}/step-cli_${STEP_VERSION}-1_amd64.deb" \
        -o /tmp/step-cli.deb \
    && dpkg -i /tmp/step-cli.deb \
    && rm -f /tmp/step-cli.deb

# --- provisioning assets -----------------------------------------------------
# Vendored eduVPN deploy scripts (pinned; see deploy/VENDORED.md).
COPY deploy/ /opt/eduvpn-deploy/
# Posture overlay: the 5 patched portal files + the Apache mTLS snippet.
COPY vpn-user-portal/ /opt/posture-overlay/vpn-user-portal/
COPY apache-mtls-snippet.conf /opt/posture-overlay/apache-mtls-snippet.conf
# First-boot provisioning driven by a systemd oneshot unit.
COPY first-boot.sh /usr/local/sbin/eduvpn-first-boot.sh
COPY eduvpn-first-boot.service /etc/systemd/system/eduvpn-first-boot.service
RUN chmod +x /usr/local/sbin/eduvpn-first-boot.sh \
    && systemctl enable eduvpn-first-boot.service

STOPSIGNAL SIGRTMIN+3
CMD ["/sbin/init"]
