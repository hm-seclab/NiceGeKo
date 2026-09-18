<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

require_once \dirname(__DIR__) . '/vendor/autoload.php';

use Vpn\Portal\Cfg\Config;
use Vpn\Portal\Http\Auth\RadiusCredentialValidator;
use Vpn\Portal\SysLogger;

try {
    if ($argc < 3) {
        echo 'SYNTAX: ' . $argv[0] . ' username password' . \PHP_EOL;
        exit(1);
    }
    $c = Config::fromFile(\dirname(__DIR__) . '/config/config.php');
    $r = new RadiusCredentialValidator(new SysLogger(basename(__FILE__)), $c->radiusAuthConfig());
    $userInfo = $r->validate($argv[1], $argv[2]);
    echo $userInfo->userId() . \PHP_EOL;
    var_dump($userInfo->permissionList());
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . \PHP_EOL;
    exit(1);
}
