<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

require_once \dirname(__DIR__) . '/vendor/autoload.php';
$baseDir = \dirname(__DIR__);

use fkooman\OAuth\Server\PdoStorage as OAuthStorage;
use fkooman\OAuth\Server\Signer;
use fkooman\SeCookie\Cookie;
use fkooman\SeCookie\CookieOptions;
use Vpn\Portal\Cfg\Config;
use Vpn\Portal\ConnectionHooks;
use Vpn\Portal\ConnectionManager;
use Vpn\Portal\Crypto\HmacKey;
use Vpn\Portal\Expiry;
use Vpn\Portal\FileIO;
use Vpn\Portal\Http\AccessHook;
use Vpn\Portal\Http\AdminHook;
use Vpn\Portal\Http\AdminPortalModule;
use Vpn\Portal\Http\Auth\ClientCertAuthModule;
use Vpn\Portal\Http\Auth\DbCredentialValidator;
use Vpn\Portal\Http\Auth\LdapCredentialValidator;
use Vpn\Portal\Http\Auth\MellonAuthModule;
use Vpn\Portal\Http\Auth\OidcAuthModule;
use Vpn\Portal\Http\Auth\PhpSamlSpAuthModule;
use Vpn\Portal\Http\Auth\RadiusCredentialValidator;
use Vpn\Portal\Http\Auth\ShibAuthModule;
use Vpn\Portal\Http\Auth\UserPassAuthModule;
use Vpn\Portal\Http\CSRFHook;
use Vpn\Portal\Http\DisabledUserHook;
use Vpn\Portal\Http\HmacUserIdHook;
use Vpn\Portal\Http\HtmlResponse;
use Vpn\Portal\Http\LogoutModule;
use Vpn\Portal\Http\OAuthModule;
use Vpn\Portal\Http\PasswdModule;
use Vpn\Portal\Http\PortalService;
use Vpn\Portal\Http\Request;
use Vpn\Portal\Http\Response;
use Vpn\Portal\Http\SeCookie;
use Vpn\Portal\Http\SeSession;
use Vpn\Portal\Http\UpdateUserInfoHook;
use Vpn\Portal\Http\VpnPortalModule;
use Vpn\Portal\HttpClient\ClientConfig;
use Vpn\Portal\HttpClient\CurlClient;
use Vpn\Portal\OAuth\VpnClientDb;
use Vpn\Portal\OAuth\VpnOAuthServer;
use Vpn\Portal\OpenVpn\CA\VpnCa;
use Vpn\Portal\OpenVpn\TlsCrypt;
use Vpn\Portal\PermissionSourceManager;
use Vpn\Portal\ServerInfo;
use Vpn\Portal\StaticPermissionsSource;
use Vpn\Portal\Storage;
use Vpn\Portal\SysLogger;
use Vpn\Portal\Tpl;
use Vpn\Portal\Validator;
use Vpn\Portal\VpnDaemon;

// only allow owner permissions
umask(0077);

$logger = new SysLogger('vpn-user-portal');
$tpl = null;

try {
    $config = Config::fromFile($baseDir . '/config/config.php');
    $request = Request::createFromGlobals();

    // determine preferred UI language
    if (null === $uiLanguage = $request->getCookie('L', fn(string $s) => Validator::languageCode($s))) {
        $uiLanguage = $config->defaultLanguage();
    }
    $oauthClientDb = new VpnClientDb($baseDir . '/config/oauth_client_db.json', $config->apiConfig()->appSets());
    $tpl = new Tpl(
        $baseDir,
        $config->styleName(),
        $uiLanguage,
        [
            'requestRoot' => $request->getRoot(),
            'portalHost' => gethostname(),
            'portalVersion' => trim(FileIO::read($baseDir . '/VERSION')),
            'enabledLanguages' => $config->enabledLanguages(),
            'authModule' => $config->authModule(),
            'isAdmin' => false,
            'enableLogoutButton' => true,
        ],
        $oauthClientDb
    );

    $dateTime = new DateTimeImmutable();
    FileIO::mkdir($baseDir . '/data');
    $ca = new VpnCa($baseDir . '/config/keys/ca', $config->vpnCaPath());
    $sessionExpiry = new Expiry(
        $config->sessionExpiry(),
        $config->supportedSessionExpiry(),
        $dateTime,
        $ca->caCert()->validTo()
    );

    $storage = new Storage($config->dbConfig($baseDir));

    // XXX do we need to set the path?
    $cookieOptions = CookieOptions::init()->withPath($request->getRoot());
    $cookieBackend = new SeCookie(new Cookie($cookieOptions->withMaxAge(60 * 60 * 24 * 90)->withSameSiteLax()));
    $sessionBackend = new SeSession($cookieOptions->withSameSiteStrict(), $config);

    $ldapCredentialValidator = null;
    switch ($config->authModule()) {
        case 'DbAuthModule':
            $dbCredentialStorage = new DbCredentialValidator($storage);
            $authModule = new UserPassAuthModule($dbCredentialStorage, $sessionBackend, $tpl, $logger, $config->logConfig());

            break;

        case 'ClientCertAuthModule':
            $authModule = new ClientCertAuthModule();

            break;

        case 'LdapAuthModule':
            $ldapCredentialValidator = new LdapCredentialValidator($config->ldapAuthConfig());
            $authModule = new UserPassAuthModule(
                $ldapCredentialValidator,
                $sessionBackend,
                $tpl,
                $logger,
                $config->logConfig()
            );

            break;

        case 'RadiusAuthModule':
            $authModule = new UserPassAuthModule(
                new RadiusCredentialValidator(
                    $logger,
                    $config->radiusAuthConfig(),
                    $sessionBackend
                ),
                $sessionBackend,
                $tpl,
                $logger,
                $config->logConfig()
            );

            break;

        case 'ShibAuthModule':
            $authModule = new ShibAuthModule($config->shibAuthConfig());

            break;

        case 'MellonAuthModule':
            $authModule = new MellonAuthModule($config->mellonAuthConfig());

            break;

        case 'PhpSamlSpAuthModule':
            $authModule = new PhpSamlSpAuthModule($config->phpSamlSpAuthConfig());

            break;

        case 'OidcAuthModule':
            $authModule = new OidcAuthModule($config->oidcAuthConfig());

            break;

        default:
            throw new RuntimeException('unsupported authentication mechanism');
    }

    $service = new PortalService($authModule, $tpl);
    if ($config->apiConfig()->enableGuestAccess()) {
        $service->addHook(new HmacUserIdHook(HmacKey::load(FileIO::read($baseDir . '/config/keys/hmac.key'))));
    }
    $service->addHook(new CSRFHook());

    if ('DbAuthModule' === $config->authModule()) {
        $dbCredentialStorage = new DbCredentialValidator($storage);
        // when using local database, users are allowed to change their own
        // password
        $service->addModule(
            new PasswdModule($dbCredentialStorage, $tpl, $storage)
        );
    }

    $staticPermissionsSource = new StaticPermissionsSource($config->staticPermissionsConfig($baseDir), $request);

    // "Authentication Permissions"
    $authPermissionSourceManager = new PermissionSourceManager();
    // "Static Permissions" are always enabled during user authentication
    $authPermissionSourceManager->add($staticPermissionsSource);
    if ($config->ldapAuthConfig()->isAuthenticationPermissionSource()) {
        $authPermissionSourceManager->add($ldapCredentialValidator ?? new LdapCredentialValidator($config->ldapAuthConfig()));
    }

    $service->addHook(
        new UpdateUserInfoHook(
            session: $sessionBackend,
            storage: $storage,
            authModule: $authModule,
            permissionSourceManager: $authPermissionSourceManager,
            dateTime: $dateTime
        )
    );
    $service->addHook(new DisabledUserHook());

    if (null !== $accessPermissionList = $config->accessPermissionList()) {
        // hasAccess
        $service->addHook(new AccessHook($accessPermissionList));
    }

    // isAdmin
    $adminHook = new AdminHook(
        $config->adminPermissionList(),
        $config->adminUserIdList(),
        $tpl
    );

    $service->addHook($adminHook);
    $oauthStorage = new OAuthStorage($storage->dbPdo(), 'oauth_');
    $oauthKey = FileIO::read($baseDir . '/config/keys/oauth.key');
    $oauthSigner = new Signer($oauthKey);
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

    // "Live Permissions"
    $livePermissionSourceManager = new PermissionSourceManager();
    if ($config->staticPermissionsConfig($baseDir)->isLivePermissionSource()) {
        $livePermissionSourceManager->add($staticPermissionsSource);
    }
    if ($config->ldapAuthConfig()->isLivePermissionSource()) {
        $livePermissionSourceManager->add($ldapCredentialValidator ?? new LdapCredentialValidator($config->ldapAuthConfig()));
    }

    // portal module
    $vpnPortalModule = new VpnPortalModule(
        $config,
        $tpl,
        $cookieBackend,
        $connectionManager,
        $storage,
        $oauthStorage,
        $serverInfo,
        $sessionExpiry,
        $livePermissionSourceManager
    );
    $service->addModule($vpnPortalModule);

    $adminPortalModule = new AdminPortalModule(
        $config,
        $tpl,
        $connectionManager,
        $storage,
        $oauthStorage,
        $serverInfo,
        $oauthClientDb
    );
    $service->addModule($adminPortalModule);

    if (null === $issuerIdentity = $config->apiConfig()->issuerIdentity()) {
        $issuerIdentity = $request->getOrigin();
    }

    // OAuth module
    $oauthServer = new VpnOAuthServer(
        $oauthStorage,
        $oauthClientDb,
        $oauthSigner,
        $config->apiConfig()->tokenExpiry(),
        $issuerIdentity
    );

    $oauthModule = new OAuthModule(
        $oauthServer,
        $tpl,
        $sessionExpiry
    );
    $service->addModule($oauthModule);
    $service->addModule(new LogoutModule($authModule, $sessionBackend));

    $htmlResponse = $service->run($request);
    $sessionBackend->stop();
    $htmlResponse->send();
} catch (Throwable $e) {
    $logger->error($e->getMessage());
    $response = new Response($e->getMessage(), ['Content-Type' => 'text/plain'], 500);
    if (null !== $tpl) {
        // reset template buffer first if it was already started and ran into
        // an exception at that point
        $tpl->reset();
        $response = new HtmlResponse(
            $tpl->render(
                'errorPage',
                [
                    'enableLogoutButton' => false,
                    'code' => 500,
                    'message' => $e->getMessage(),
                ]
            ),
            [],
            500
        );
    }
    $response->send();
}
