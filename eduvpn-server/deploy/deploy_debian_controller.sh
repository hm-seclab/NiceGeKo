#!/bin/sh

#
# Deploy a VPN server on Debian/Ubuntu
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

# whether or not to use the "development" repository (for experimental builds 
# or platforms not yet officially supported)
USE_DEV_REPO=${USE_DEV_REPO:-n}

###############################################################################
# SOFTWARE
###############################################################################

apt update
apt install -y apt-transport-https curl apache2 php-fpm nftables sudo \
    lsb-release tmux

DEBIAN_ARCH=$(dpkg --print-architecture)
DEBIAN_CODE_NAME=$(/usr/bin/lsb_release -cs)
PHP_VERSION=$(/usr/sbin/phpquery -V)

if [ "${USE_DEV_REPO}" = "y" ]; then
    cp resources/repo+v3-dev@eduvpn.org.gpg /usr/share/keyrings/repo+v3-dev@eduvpn.org.gpg
    echo "deb [arch=${DEBIAN_ARCH} signed-by=/usr/share/keyrings/repo+v3-dev@eduvpn.org.gpg] https://repo.tuxed.net/eduVPN/v3-dev/deb ${DEBIAN_CODE_NAME} main" > /etc/apt/sources.list.d/eduVPN_v3-dev.list
else
    cp resources/repo+v3@eduvpn.org.gpg /usr/share/keyrings/repo+v3@eduvpn.org.gpg
    echo "deb [arch=${DEBIAN_ARCH} signed-by=/usr/share/keyrings/repo+v3@eduvpn.org.gpg] https://repo.eduvpn.org/v3/deb ${DEBIAN_CODE_NAME} main" > /etc/apt/sources.list.d/eduVPN_v3.list
fi

apt update

# install software (VPN packages)
apt install -y vpn-user-portal vpn-maint-scripts

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
    -keyout "/etc/ssl/private/${WEB_FQDN}.key" \
    -out "/etc/ssl/certs/${WEB_FQDN}.crt" \
    -days 90

###############################################################################
# APACHE
###############################################################################

a2enmod ssl headers rewrite proxy_fcgi setenvif proxy_http
a2dismod status
a2enconf "php${PHP_VERSION}-fpm"

# VirtualHost
cp resources/ssl.debian.conf /etc/apache2/mods-available/ssl.conf
cp resources/vpn.example.debian.conf "/etc/apache2/sites-available/${WEB_FQDN}.conf"
cp resources/localhost.debian.conf /etc/apache2/sites-available/localhost.conf

# update hostname
sed -i "s/vpn.example/${WEB_FQDN}/" "/etc/apache2/sites-available/${WEB_FQDN}.conf"

a2enconf vpn-user-portal
a2ensite "${WEB_FQDN}" localhost
a2dissite 000-default

# replace the default landing page with empty page
echo -n '' | tee /var/www/html/index.html

systemctl restart apache2

###############################################################################
# PHP
###############################################################################

# update php-fpm settings to suite this system (for use with "Local Accounts")
# see https://docs.eduvpn.org/server/v3/php-tuning.html for more information

sh ./php_fpm_limits.sh --local | tee "/etc/php/${PHP_VERSION}/fpm/pool.d/www_vpn.conf" >/dev/null

###############################################################################
# VPN-USER-PORTAL
###############################################################################

# update hostname of VPN server
sed -i "s/vpn.example/${WEB_FQDN}/" "/etc/vpn-user-portal/config.php"

# update the default IP ranges for the profile
sed -i "s|10.43.43.0/24|$(/usr/libexec/vpn-user-portal/generate-prefix -4 -p 20)|" "/etc/vpn-user-portal/config.php"
sed -i "s|fd43::/64|$(/usr/libexec/vpn-user-portal/generate-prefix -6)|" "/etc/vpn-user-portal/config.php"

###############################################################################
# UPDATE SECRETS
###############################################################################

#cp /etc/vpn-user-portal/keys/node.0.key /etc/vpn-server-node/keys/node.key

###############################################################################
# DAEMONS
###############################################################################

systemctl enable --now "php${PHP_VERSION}-fpm"
systemctl enable --now apache2

###############################################################################
# FIREWALL
###############################################################################

cp resources/firewall/controller/nftables.conf /etc/nftables.conf
systemctl enable --now nftables

###############################################################################
# USERS
###############################################################################

USER_NAME="vpn"
USER_PASS=$(openssl rand -base64 12)

sudo -u www-data vpn-user-portal-account --add "${USER_NAME}" --password "${USER_PASS}"

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
