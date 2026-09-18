#!/bin/sh

#
# we use this script to determine some php-fpm variables and "optimize" it for
# the server it is deployed on. This will not be perfect, and does not 
# accommodate changes in server configuration afterwards, then the script needs
# to run again...
#
# during deployment the output of this script is written to:
#
# /etc/php/$(/usr/sbin/phpquery -V)/fpm/pool.d/www_vpn.conf (Debian/Ubuntu)
# /etc/php-fpm.d/www_vpn.conf (Fedora/EL)
#

TOTAL_MEM=$(expr $(cat /proc/meminfo | grep MemTotal | awk {'print $2'}) / 1024)
PROC_MEM=16
if [ "--local" = "${1}" ]; then
    # for "Local Account" deployments we need more memory per process for the 
    # password hashing
    PROC_MEM=$(php -r 'echo floor(SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE / 1024 / 1024 + 16);')
fi
MAX_CHILDREN=$(((TOTAL_MEM - 512) / PROC_MEM))
NPROC=$(nproc)
MIN_SPARE_SERVERS=$((NPROC * 2))
MAX_SPARE_SERVERS=$((NPROC * 4))
START_SERVERS=$(((MAX_SPARE_SERVERS + MIN_SPARE_SERVERS) / 2))

echo '; PHP tuning for VPN server'
echo "; #CPUs: ${NPROC}"
echo "; #Total Memory: ${TOTAL_MEM}"
echo "; #Process Memory: ${PROC_MEM}"
echo '; See: https://docs.eduvpn.org/server/v3/php-tuning.html'
echo '[www]'
echo 'pm = dynamic'
echo "pm.max_children = ${MAX_CHILDREN}"
echo "pm.start_servers = ${START_SERVERS}"
echo "pm.min_spare_servers = ${MIN_SPARE_SERVERS}"
echo "pm.max_spare_servers = ${MAX_SPARE_SERVERS}"
