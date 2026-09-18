#!/bin/sh

#
# Deploy a VPN server on Fedora / EL
#

if ! [ "root" = "$(id -u -n)" ]; then
    echo "ERROR: ${0} must be run as root!"; exit 1
fi

###############################################################################
# VARIABLES
###############################################################################

MACHINE_HOSTNAME=$(hostname -f)

# DNS name of the Web Server
printf "DNS name of the Web Server [%s]: " "${MACHINE_HOSTNAME}"; read -r WEB_FQDN
WEB_FQDN=${WEB_FQDN:-${MACHINE_HOSTNAME}}
# convert hostname to lowercase
WEB_FQDN=$(echo "${WEB_FQDN}" | tr '[:upper:]' '[:lower:]')

# Try to detect external "Default Gateway" Interface, but allow admin override
EXTERNAL_IF=$(ip -4 ro show default | tail -1 | awk '{print $5}')
printf "External Network Interface [%s]: " "${EXTERNAL_IF}"; read -r EXT_IF
EXTERNAL_IF=${EXT_IF:-${EXTERNAL_IF}}

printf "Enable *Weekly* Automatic Update & Reboot? [y/n] (default=y)? "; read -r AUTO_UPDATE
AUTO_UPDATE=${AUTO_UPDATE:-y}

# whether or not to use the "development" repository (for experimental builds 
# or platforms not yet officially supported)
USE_DEV_REPO=${USE_DEV_REPO:-n}

###############################################################################
# SYSTEM
###############################################################################

# SELinux enabled?

if ! /usr/sbin/selinuxenabled
then
    echo "Please **ENABLE** SELinux before running this script!"
    exit 1
fi

###############################################################################
# SOFTWARE
###############################################################################

# disable and stop existing firewalling (if running)
systemctl disable --now firewalld >/dev/null 2>/dev/null || true
systemctl disable --now iptables >/dev/null 2>/dev/null || true
systemctl disable --now ip6tables >/dev/null 2>/dev/null || true
systemctl disable --now nftables >/dev/null 2>/dev/null || true

# stop daemons we use (if they are already running)
systemctl disable --now httpd >/dev/null 2>/dev/null || true
systemctl disable --now php-fpm >/dev/null 2>/dev/null || true
systemctl disable --now vpn-daemon >/dev/null 2>/dev/null || true

if ! [ -f /etc/os-release ]; then
    echo 'No "/etc/os-release"'
    exit 1
fi
. /etc/os-release

if [ "Fedora Linux" = "${NAME}" ]; then
    REPO_PREFIX=fedora
elif [ "AlmaLinux" = "${NAME}" ] ; then
    REPO_PREFIX=alma+epel
    dnf config-manager --set-enabled crb
    dnf -y install "https://dl.fedoraproject.org/pub/epel/epel-release-latest-$(rpm --eval %rhel).noarch.rpm"
elif [ "Rocky Linux" = "${NAME}" ]; then
    REPO_PREFIX=rocky+epel
    dnf config-manager --set-enabled crb
    dnf -y install "https://dl.fedoraproject.org/pub/epel/epel-release-latest-$(rpm --eval %rhel).noarch.rpm"
elif [ "Red Hat Enterprise Linux" = "${NAME}" ]; then
    REPO_PREFIX=rhel+epel
    subscription-manager repos --enable "codeready-builder-for-rhel-$(rpm --eval %rhel)-$(arch)-rpms"
    dnf -y install "https://dl.fedoraproject.org/pub/epel/epel-release-latest-$(rpm --eval %rhel).noarch.rpm"
else
    echo "OS not supported!"
    exit 1
fi

if [ "${USE_DEV_REPO}" = "y" ]; then
    cp resources/repo+v3-dev@eduvpn.org.asc /etc/pki/rpm-gpg/RPM-GPG-KEY-eduVPN_v3-dev
    cat << EOF > /etc/yum.repos.d/eduVPN_v3-dev.repo
[eduVPN_v3-dev]
name=eduVPN 3.x Development Packages (${NAME} \$releasever)
baseurl=https://repo.tuxed.net/eduVPN/v3-dev/rpm/${REPO_PREFIX}-\$releasever-\$basearch
gpgkey=file:///etc/pki/rpm-gpg/RPM-GPG-KEY-eduVPN_v3-dev
gpgcheck=1
enabled=1
EOF
else
    cp resources/repo+v3@eduvpn.org.asc /etc/pki/rpm-gpg/RPM-GPG-KEY-eduVPN_v3
    cat << EOF > /etc/yum.repos.d/eduVPN_v3.repo
[eduVPN_v3]
name=eduVPN 3.x Packages (${NAME} \$releasever)
baseurl=https://repo.eduvpn.org/v3/rpm/${REPO_PREFIX}-\$releasever-\$basearch
gpgkey=file:///etc/pki/rpm-gpg/RPM-GPG-KEY-eduVPN_v3
gpgcheck=1
enabled=1
EOF
fi

/usr/bin/dnf -y install mod_ssl php-opcache httpd cronie \
    nftables php-fpm php-cli policycoreutils-python-utils chrony \
    tmux

/usr/bin/dnf -y install vpn-server-node vpn-user-portal vpn-maint-scripts \
    proxyguard-server openvpn

###############################################################################
# SELINUX
###############################################################################

setsebool -P httpd_can_network_connect=1
setsebool -P openvpn_run_unconfined=1

###############################################################################
# AUTO UPDATE
###############################################################################

if [ "${AUTO_UPDATE}" = "y" ]; then
    cat << EOF > /etc/cron.weekly/vpn-maint-update-system
#!/bin/sh
/usr/sbin/vpn-maint-update-system && /usr/sbin/reboot
EOF
    chmod +x /etc/cron.weekly/vpn-maint-update-system
fi

###############################################################################
# APACHE
###############################################################################

# Use a hardened ssl.conf instead of the default, gives A+ on
# https://www.ssllabs.com/ssltest/
cp resources/ssl.rpm.conf /etc/httpd/conf.d/ssl.conf
cp resources/localhost.rpm.conf /etc/httpd/conf.d/localhost.conf
cp resources/vpn.example.rpm.conf "/etc/httpd/conf.d/${WEB_FQDN}.conf"

# update hostname
sed -i "s/vpn.example/${WEB_FQDN}/" "/etc/httpd/conf.d/${WEB_FQDN}.conf"

# replace the default landing page with empty page
touch /var/www/html/index.html

###############################################################################
# PHP
###############################################################################

# update php-fpm settings to suite this system (for use with "Local Accounts")
# see https://docs.eduvpn.org/server/v3/php-tuning.html for more information

sh ./php_fpm_limits.sh --local | tee "/etc/php-fpm.d/www_vpn.conf" >/dev/null

###############################################################################
# VPN-USER-PORTAL
###############################################################################

# update config.php with updated defaults
cp resources/vpn-user-portal/config.php /etc/vpn-user-portal/config.php

# update hostname of VPN server
sed -i "s/vpn.example/${WEB_FQDN}/" "/etc/vpn-user-portal/config.php"

# update the default IP ranges for the profile
sed -i "s|10.43.43.0/24|$(/usr/libexec/vpn-user-portal/generate-prefix -4 -p 20)|" "/etc/vpn-user-portal/config.php"
sed -i "s|fd43::/64|$(/usr/libexec/vpn-user-portal/generate-prefix -6)|" "/etc/vpn-user-portal/config.php"

###############################################################################
# NETWORK
###############################################################################

cat << EOF > /etc/sysctl.d/70-vpn.conf
# **ONLY** needed for IPv6 configuration through auto configuration. Do **NOT**
# use this in production, you SHOULD be using STATIC addresses!
net.ipv6.conf.${EXTERNAL_IF}.accept_ra = 2

# enable IPv4 and IPv6 forwarding
net.ipv4.ip_forward = 1
net.ipv6.conf.all.forwarding = 1
EOF

sysctl --system

###############################################################################
# UPDATE SECRETS
###############################################################################

cp /etc/vpn-user-portal/keys/node.0.key /etc/vpn-server-node/keys/node.key

###############################################################################
# CERTIFICATE
###############################################################################

# generate self signed certificate and key
openssl req \
    -nodes \
    -subj "/CN=${WEB_FQDN}" \
    -x509 \
    -sha256 \
    -newkey rsa:2048 \
    -keyout "/etc/pki/tls/private/${WEB_FQDN}.key" \
    -out "/etc/pki/tls/certs/${WEB_FQDN}.crt" \
    -days 90

###############################################################################
# DAEMONS
###############################################################################

systemctl enable --now php-fpm
systemctl enable --now httpd
systemctl enable --now vpn-daemon
systemctl enable --now crond
systemctl enable --now proxyguard-server

###############################################################################
# VPN SERVER CONFIG
###############################################################################

# increase the allowed number of processes for the OpenVPN service
mkdir -p /etc/systemd/system/openvpn-server@.service.d
cat << EOF > /etc/systemd/system/openvpn-server@.service.d/override.conf
[Service]
LimitNPROC=127
EOF

# apply all configuration changes and start/enable the OpenVPN and WireGuard
# daemons
vpn-maint-apply-changes

###############################################################################
# LOG
###############################################################################

# limit log retention to 1 month
mkdir -p /etc/systemd/journald.conf.d
cat << 'EOF' > /etc/systemd/journald.conf.d/retention.conf
[Journal]
MaxRetentionSec=1month
EOF
systemctl restart systemd-journald

###############################################################################
# FIREWALL
###############################################################################

cp resources/firewall/nftables.conf /etc/sysconfig/nftables.conf
sed -i "s|define EXTERNAL_IF = eth0|define EXTERNAL_IF = ${EXTERNAL_IF}|" /etc/sysconfig/nftables.conf
systemctl enable --now nftables

###############################################################################
# USERS
###############################################################################

USER_NAME="vpn"
USER_PASS=$(openssl rand -base64 12)

sudo -u apache vpn-user-portal-account --add "${USER_NAME}" --password "${USER_PASS}"

echo "########################################################################"
echo "# Portal"
echo "# ======"
echo "#     https://${WEB_FQDN}/"
echo "#         User Name: ${USER_NAME}"
echo "#         User Pass: ${USER_PASS}"
echo "#"
echo "# Admin"
echo "# ====="
echo "# Add 'vpn' to 'adminUserIdList' in /etc/vpn-user-portal/config.php in"
echo "# order to make yourself an admin in the portal."
echo "#"
echo "# Documentation"
echo "# ============="
echo "# See https://docs.eduvpn.org/server/v3/sitemap.html for all available"
echo "# documentation!"
echo "########################################################################"
