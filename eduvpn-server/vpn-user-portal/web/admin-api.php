<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

require_once \dirname(__DIR__) . '/vendor/autoload.php';
$baseDir = \dirname(__DIR__);

use fkooman\OAuth\Server\PdoStorage as OAuthStorage;
use fkooman\OAuth\Server\Signer;
use Vpn\Portal\Cfg\Config;
use Vpn\Portal\ConnectionHooks;
use Vpn\Portal\ConnectionManager;
use Vpn\Portal\Expiry;
use Vpn\Portal\FileIO;
use Vpn\Portal\Http\AdminApiModule;
use Vpn\Portal\Http\AdminApiService;
use Vpn\Portal\Http\Auth\AdminApiAuthModule;
use Vpn\Portal\Http\JsonResponse;
use Vpn\Portal\Http\Request;
use Vpn\Portal\HttpClient\ClientConfig;
use Vpn\Portal\HttpClient\CurlClient;
use Vpn\Portal\OpenVpn\CA\VpnCa;
use Vpn\Portal\OpenVpn\TlsCrypt;
use Vpn\Portal\ServerInfo;
use Vpn\Portal\Storage;
use Vpn\Portal\SysLogger;
use Vpn\Portal\VpnDaemon;

// only allow owner permissions
umask(0077);

$logger = new SysLogger('vpn-user-portal');

try {
    $adminApiKeyFile = \sprintf('%s/config/keys/admin-api.key', $baseDir);
    if (!FileIO::exists($adminApiKeyFile)) {
        throw new Exception('no admin API key set, admin API disabled');
    }

    $dateTime = new DateTimeImmutable();
    $request = Request::createFromGlobals();
    FileIO::mkdir($baseDir . '/data');
    $config = Config::fromFile($baseDir . '/config/config.php');
    $storage = new Storage($config->dbConfig($baseDir));
    $oauthStorage = new OAuthStorage($storage->dbPdo(), 'oauth_');
    $ca = new VpnCa($baseDir . '/config/keys/ca', $config->vpnCaPath());
    $oauthKey = FileIO::read($baseDir . '/config/keys/oauth.key');
    $service = new AdminApiService(
        new AdminApiAuthModule(
            $adminApiKeyFile,
            'Admin API'
        )
    );
    $serverInfo = new ServerInfo(
        $request->getRootUri(),
        $baseDir . '/data/keys',
        $ca,
        new TlsCrypt($baseDir . '/data/keys'),
        $config->wireGuardConfig(),
        $config->openVpnConfig(),
        Signer::publicKeyFromSecretKey($oauthKey)
    );

    $vpnDaemon = new VpnDaemon(
        new CurlClient(
            clientConfig: new ClientConfig(
                caFile: $baseDir . '/config/keys/vpn-daemon/ca.crt',
                certFile: $baseDir . '/config/keys/vpn-daemon/vpn-daemon-client.crt',
                keyFile: $baseDir . '/config/keys/vpn-daemon/vpn-daemon-client.key'
            )
        ),
        $logger
    );
    $connectionManager = new ConnectionManager($config, $vpnDaemon, $storage, ConnectionHooks::init($config, $storage, $logger), $logger);

    $sessionExpiry = new Expiry(
        $config->sessionExpiry(),
        $config->supportedSessionExpiry(),
        $dateTime,
        $ca->caCert()->validTo()
    );

    $service->addModule(
        new AdminApiModule(
            $config,
            $storage,
            $oauthStorage,
            $serverInfo,
            $connectionManager,
            $sessionExpiry
        )
    );

    $service->run($request)->send();
} catch (Throwable $e) {
    $logger->error($e->getMessage());
    $response = new JsonResponse(['error' => $e->getMessage()], [], 500);
    $response->send();
}
