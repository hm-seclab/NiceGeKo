#!/bin/sh

# we hard code the paths for RPM and Debian packages.
sed -i "s|^require_once.*$|require_once '/usr/share/php/Vpn/Portal/autoload.php';|g" bin/*.php web/*.php libexec/*.php
sed -i "s|^\$baseDir =.*$|\$baseDir = '/usr/share/vpn-user-portal';|g" bin/*.php web/*.php libexec/*.php
