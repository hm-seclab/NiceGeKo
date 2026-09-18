<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

require_once \dirname(__DIR__) . '/vendor/autoload.php';
$baseDir = \dirname(__DIR__);

use Vpn\Portal\Cfg\Config;
use Vpn\Portal\ConnectionHooks;
use Vpn\Portal\Http\Auth\NodeAuthModule;
use Vpn\Portal\Http\JsonResponse;
use Vpn\Portal\Http\NodeApiModule;
use Vpn\Portal\Http\NodeApiService;
use Vpn\Portal\Http\Request;
use Vpn\Portal\OpenVpn\CA\VpnCa;
use Vpn\Portal\OpenVpn\ServerConfig as OpenVpnServerConfig;
use Vpn\Portal\OpenVpn\SingleProcessServerConfig as OpenVpnSingleProcessServerConfig;
use Vpn\Portal\OpenVpn\TlsCrypt;
use Vpn\Portal\ServerConfig;
use Vpn\Portal\Storage;
use Vpn\Portal\SysLogger;
use Vpn\Portal\WireGuard\ServerConfig as WireGuardServerConfig;

// only allow owner permissions
umask(0077);

$logger = new SysLogger('vpn-user-portal');

try {
    $config = Config::fromFile($baseDir . '/config/config.php');
    $service = new NodeApiService(
        new NodeAuthModule(
            $baseDir,
            'Node API'
        )
    );

    $storage = new Storage($config->dbConfig($baseDir));
    $ca = new VpnCa($baseDir . '/config/keys/ca', $config->vpnCaPath());
    $tlsCrypt = new TlsCrypt($baseDir . '/data/keys');

    $openVpnServerConfig = match ($config->openVpnConfig()->singleProcess()) {
        true => new OpenVpnSingleProcessServerConfig($config->openVpnConfig(), $ca, $tlsCrypt),
        false => new OpenVpnServerConfig($config->openVpnConfig(), $ca, $tlsCrypt),
    };

    $nodeApiModule = new NodeApiModule(
        $config,
        $storage,
        new ServerConfig(
            $openVpnServerConfig,
            new WireGuardServerConfig($baseDir . '/data/keys', $config->wireGuardConfig()),
        ),
        ConnectionHooks::init($config, $storage, $logger),
        $logger
    );

    $service->addModule($nodeApiModule);
    $request = Request::createFromGlobals();
    $service->run($request)->send();
} catch (Throwable $e) {
    $logger->error($e->getMessage());
    $response = new JsonResponse(['error' => $e->getMessage()], [], 500);
    $response->send();
}
