<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * This file is part of eduVPN vpn-user-portal and has been MODIFIED.
 * Modified 2026-07 by the eduVPNextension project: constructs a PostureChecker
 * when 'PostureCheck' is configured and injects it into VpnApiThreeModule.
 *
 * See eduvpn-server/vpn-user-portal/VENDORED.md for the unmodified upstream base.
 */

require_once \dirname(__DIR__) . '/vendor/autoload.php';
$baseDir = \dirname(__DIR__);

use fkooman\OAuth\Server\BearerValidator;
use fkooman\OAuth\Server\LocalAccessTokenVerifier;
use fkooman\OAuth\Server\PdoStorage as OAuthStorage;
use fkooman\OAuth\Server\Signer;
use Vpn\Portal\Cfg\Config;
use Vpn\Portal\ConnectionHooks;
use Vpn\Portal\ConnectionManager;
use Vpn\Portal\FileIO;
use Vpn\Portal\Http\ApiService;
use Vpn\Portal\Http\Auth\LdapCredentialValidator;
use Vpn\Portal\Http\GuestApiService;
use Vpn\Portal\Http\JsonResponse;
use Vpn\Portal\Http\Request;
use Vpn\Portal\Http\VpnApiThreeModule;
use Vpn\Portal\PostureChecker;
use Vpn\Portal\HttpClient\ClientConfig;
use Vpn\Portal\HttpClient\CurlClient;
use Vpn\Portal\OAuth\NullAccessTokenVerifier;
use Vpn\Portal\OAuth\VpnClientDb;
use Vpn\Portal\OpenVpn\CA\VpnCa;
use Vpn\Portal\OpenVpn\TlsCrypt;
use Vpn\Portal\PermissionSourceManager;
use Vpn\Portal\ServerInfo;
use Vpn\Portal\ServerList;
use Vpn\Portal\StaticPermissionsSource;
use Vpn\Portal\Storage;
use Vpn\Portal\SysLogger;
use Vpn\Portal\VpnDaemon;

// only allow owner permissions
umask(0077);

$logger = new SysLogger('vpn-user-portal');

try {
    $request = Request::createFromGlobals();
    FileIO::mkdir($baseDir . '/data');
    $config = Config::fromFile($baseDir . '/config/config.php');
    $storage = new Storage($config->dbConfig($baseDir));
    $oauthStorage = new OAuthStorage($storage->dbPdo(), 'oauth_');
    $ca = new VpnCa($baseDir . '/config/keys/ca', $config->vpnCaPath());
    $oauthKey = FileIO::read($baseDir . '/config/keys/oauth.key');

    [,,$localKeyId] = explode('.', $oauthKey, 4);

    if ($config->apiConfig()->enableGuestAccess()) {
        $serverList = new ServerList($baseDir . '/data', $config->apiConfig());
        $bearerValidator = new BearerValidator(
            new Signer($oauthKey, $serverList),
            new NullAccessTokenVerifier()
        );
        $service = new GuestApiService($bearerValidator, $serverList, $storage, $oauthStorage, $localKeyId);
    } else {
        $bearerValidator = new BearerValidator(
            new Signer($oauthKey),
            new LocalAccessTokenVerifier(
                new VpnClientDb($baseDir . '/config/oauth_client_db.json', $config->apiConfig()->appSets()),
                $oauthStorage
            )
        );
        $service = new ApiService($bearerValidator);
    }

    $serverInfo = new ServerInfo(
        $request->getRootUri(),
        $baseDir . '/data/keys',
        $ca,
        new TlsCrypt($baseDir . '/data/keys'),
        $config->wireGuardConfig(),
        $config->openVpnConfig(),
        Signer::publicKeyFromSecretKey($oauthKey)
    );

    $permissionSourceManager = new PermissionSourceManager();
    if ($config->staticPermissionsConfig($baseDir)->isLivePermissionSource()) {
        $permissionSourceManager->add(new StaticPermissionsSource($config->staticPermissionsConfig($baseDir), $request));
    }
    if ($config->ldapAuthConfig()->isLivePermissionSource()) {
        $permissionSourceManager->add(new LdapCredentialValidator($config->ldapAuthConfig()));
    }

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

    // mTLS Posture Check (optional — enabled via 'PostureCheck' in config)
    $postureChecker = null;
    if (null !== $postureCheckConfig = $config->postureCheckConfig()) {
        $postureChecker = new PostureChecker($postureCheckConfig, $logger);
    }

    $service->addModule(
        new VpnApiThreeModule(
            $config,
            $storage,
            $oauthStorage,
            $serverInfo,
            $connectionManager,
            $permissionSourceManager,
            $postureChecker
        )
    );

    $service->run($request)->send();
} catch (Throwable $e) {
    $logger->error($e->getMessage());
    $response = new JsonResponse(['error' => $e->getMessage()], [], 500);
    $response->send();
}
