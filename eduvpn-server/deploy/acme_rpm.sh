#!/bin/sh

#
# Use ACME to obtain certificates for the Web Server.
#
# **NOTE** we assume you successfully performed deploy_fedora.sh or 
# deploy_el.sh on your server!
#

if ! [ "root" = "$(id -u -n)" ]; then
    echo "ERROR: ${0} must be run as root!"; exit 1
fi

if ! [ -f "/etc/redhat-release" ]; then
    echo "ERROR: this is not a Fedora or EL system"; exit 1
fi

###############################################################################
# SYSTEM
###############################################################################

dnf install -y certbot

###############################################################################
# VARIABLES
###############################################################################

echo "Select Your Preferred ACME CA"
echo "L) Let's Encrypt (https://letsencrypt.org/)"
echo "A) Actalis (https://www.actalis.com/)"
echo "Z) ZeroSSL (https://zerossl.com/)"
echo "O) Other CA"
echo "E) Other CA (with EAB)"
printf "Choice [default=L]: "; read -r ACME_CA

# Actalis
if [ "a" = "${ACME_CA}" ] || [ "A" = "${ACME_CA}" ]; then
    ACME_SERVER="https://acme-api.actalis.com/acme/directory"
    echo "Actalis CA requires EAB credentials!"
    printf "EAB KID: "; read -r ACME_EAB_KID
    printf "EAB HMAC Key: "; read -r ACME_EAB_HMAC_KEY
    # register first at this CA
    certbot register --server "${ACME_SERVER}" --eab-kid "${ACME_EAB_KID}" --eab-hmac-key "${ACME_EAB_HMAC_KEY}"
fi

# ZeroSSL
if [ "z" = "${ACME_CA}" ] || [ "Z" = "${ACME_CA}" ]; then
    ACME_SERVER="https://acme.zerossl.com/v2/DV90"
    echo "ZeroSSL CA requires EAB credentials!"
    printf "EAB KID: "; read -r ACME_EAB_KID
    printf "EAB HMAC Key: "; read -r ACME_EAB_HMAC_KEY
    # register first at this CA
    certbot register --server "${ACME_SERVER}" --eab-kid "${ACME_EAB_KID}" --eab-hmac-key "${ACME_EAB_HMAC_KEY}"
fi

# Other CA
if [ "o" = "${ACME_CA}" ] || [ "O" = "${ACME_CA}" ]; then
    echo "Other CA requires Server URL!"
    printf "Server URL: "; read -r ACME_SERVER
fi

# Other CA (with EAB)
if [ "e" = "${ACME_CA}" ] || [ "E" = "${ACME_CA}" ]; then
    echo "Other CA requires Server URL and EAB credentials!"
    printf "Server URL: "; read -r ACME_SERVER
    printf "EAB KID: "; read -r ACME_EAB_KID
    printf "EAB HMAC Key: "; read -r ACME_EAB_HMAC_KEY
    # register first at this CA
    certbot register --server "${ACME_SERVER}" --eab-kid "${ACME_EAB_KID}" --eab-hmac-key "${ACME_EAB_HMAC_KEY}"
fi

MACHINE_HOSTNAME=$(hostname -f)

# DNS name of the Web Server
printf "DNS name of the Web Server [%s]: " "${MACHINE_HOSTNAME}"; read -r WEB_FQDN
WEB_FQDN=${WEB_FQDN:-${MACHINE_HOSTNAME}}
# convert hostname to lowercase
WEB_FQDN=$(echo "${WEB_FQDN}" | tr '[:upper:]' '[:lower:]')

###############################################################################
# CERTBOT
###############################################################################

if [ -z "${ACME_SERVER}" ]; then
    certbot certonly \
        -d "${WEB_FQDN}" \
        --webroot \
        --webroot-path /var/www/html || exit 1
else
    # ACME server specified!
    certbot certonly \
        -d "${WEB_FQDN}" \
        --webroot \
        --webroot-path /var/www/html \
        --server "${ACME_SERVER}" || exit 1
fi

###############################################################################
# APACHE
###############################################################################

sed -i "s|SSLCertificateFile /etc/pki/tls/certs/${WEB_FQDN}|#SSLCertificateFile /etc/pki/tls/certs/${WEB_FQDN}|" "/etc/httpd/conf.d/${WEB_FQDN}.conf"
sed -i "s|SSLCertificateKeyFile /etc/pki/tls/private/${WEB_FQDN}.key|#SSLCertificateKeyFile /etc/pki/tls/private/${WEB_FQDN}.key|" "/etc/httpd/conf.d/${WEB_FQDN}.conf"

sed -i "s|#SSLCertificateFile /etc/letsencrypt/live/${WEB_FQDN}/fullchain.pem|SSLCertificateFile /etc/letsencrypt/live/${WEB_FQDN}/fullchain.pem|" "/etc/httpd/conf.d/${WEB_FQDN}.conf"
sed -i "s|#SSLCertificateKeyFile /etc/letsencrypt/live/${WEB_FQDN}/privkey.pem|SSLCertificateKeyFile /etc/letsencrypt/live/${WEB_FQDN}/privkey.pem|" "/etc/httpd/conf.d/${WEB_FQDN}.conf"

systemctl reload httpd

###############################################################################
# HOOK
###############################################################################

# deploy "post" renewal hook
cat << EOF > /etc/letsencrypt/renewal-hooks/post/reload_apache.sh
#!/bin/sh
systemctl reload httpd
EOF

chmod +x /etc/letsencrypt/renewal-hooks/post/reload_apache.sh

systemctl enable --now certbot-renew.timer
