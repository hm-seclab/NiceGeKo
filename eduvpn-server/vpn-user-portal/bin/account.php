<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

require_once \dirname(__DIR__) . '/vendor/autoload.php';
$baseDir = \dirname(__DIR__);

use fkooman\OAuth\Server\PdoStorage as OAuthStorage;
use Vpn\Portal\Cfg\Config;
use Vpn\Portal\ConnectionHooks;
use Vpn\Portal\ConnectionManager;
use Vpn\Portal\FileIO;
use Vpn\Portal\Http\PasswdModule;
use Vpn\Portal\HttpClient\ClientConfig;
use Vpn\Portal\HttpClient\CurlClient;
use Vpn\Portal\Storage;
use Vpn\Portal\SysLogger;
use Vpn\Portal\VpnDaemon;

const ACTION_USER_ADD = 'add';
const ACTION_USER_DISABLE = 'disable';
const ACTION_USER_ENABLE = 'enable';
const ACTION_USER_DELETE = 'delete';
const ACTION_USER_LIST = 'list';
const ACTION_USER_DELETE_STALE = 'delete_stale';

function showHelp(): void
{
    echo '  --add USER-ID [--password PASSWORD]' . \PHP_EOL;
    echo '        Add new *LOCAL* user account' . \PHP_EOL;
    echo '  --enable USER-ID' . \PHP_EOL;
    echo '        (Re)enable user account(*)' . \PHP_EOL;
    echo '  --disable USER-ID' . \PHP_EOL;
    echo '        Disable user account(*)' . \PHP_EOL;
    echo '  --delete USER-ID [--force]' . \PHP_EOL;
    echo '        Delete user account (data)' . \PHP_EOL;
    echo '  --list' . \PHP_EOL;
    echo '        List user accounts' . \PHP_EOL;
    echo '  --delete-stale [--force]' . \PHP_EOL;
    echo '        Delete accounts that are inactive' . \PHP_EOL;
    echo '  --delete-stale-interval INTERVAL' . \PHP_EOL;
    echo '        Set the interval from which to delete accounts(**)' . \PHP_EOL;

    echo \PHP_EOL;
    echo '(*) Only for accounts that have logged in at least once!' . \PHP_EOL;
    echo '(**) By default the INTERVAL is the value of `sessionExpiry`' . \PHP_EOL;
}

function requireUserId(?string $userId): string
{
    if (null === $userId || '' === $userId) {
        showHelp();

        throw new RuntimeException('USER-ID must be specified');
    }

    return $userId;
}

$logger = new SysLogger('vpn-user-portal');
$dateTime = new DateTimeImmutable();

try {
    /** @var ?string */
    $accountAction = null;

    $forceAction = false;
    $userId = null;
    $userPass = null;

    $config = Config::fromFile($baseDir . '/config/config.php');
    // subtract the 2*sessionExpiry from the current date/time to reach the
    // cut-off for when accounts will be deleted
    $lastSeenBefore = $dateTime->sub($config->sessionExpiry())->sub($config->sessionExpiry());

    // parse CLI flags
    for ($i = 1; $i < $argc; ++$i) {
        if ('--add' === $argv[$i]) {
            $accountAction = ACTION_USER_ADD;
            if ($i + 1 < $argc) {
                $userId = $argv[$i + 1];
            }

            continue;
        }
        if ('--enable' === $argv[$i]) {
            $accountAction = ACTION_USER_ENABLE;
            if ($i + 1 < $argc) {
                $userId = $argv[++$i];
            }

            continue;
        }
        if ('--disable' === $argv[$i]) {
            $accountAction = ACTION_USER_DISABLE;
            if ($i + 1 < $argc) {
                $userId = $argv[++$i];
            }

            continue;
        }
        if ('--delete' === $argv[$i]) {
            $accountAction = ACTION_USER_DELETE;
            if ($i + 1 < $argc) {
                $userId = $argv[++$i];
            }

            continue;
        }
        if ('--force' === $argv[$i]) {
            $forceAction = true;
        }
        if ('--password' === $argv[$i]) {
            if ($i + 1 < $argc) {
                $userPass = $argv[++$i];
            }

            continue;
        }
        if ('--list' === $argv[$i]) {
            $accountAction = ACTION_USER_LIST;

            continue;
        }

        if ('--delete-stale' === $argv[$i]) {
            $accountAction = ACTION_USER_DELETE_STALE;

            continue;
        }

        if ('--delete-stale-interval' === $argv[$i]) {
            if ($i + 1 < $argc) {
                // if specified, we use the exact value specified, not modify
                // it to introduce a margin
                $lastSeenBefore = $dateTime->sub(new DateInterval($argv[++$i]));
            }

            continue;
        }

        if ('--help' === $argv[$i] || '-h' === $argv[$i]) {
            showHelp();

            exit(0);
        }
    }

    $config = Config::fromFile($baseDir . '/config/config.php');
    $storage = new Storage($config->dbConfig($baseDir));
    $connectionHooks = ConnectionHooks::init($config, $storage, $logger);

    switch ($accountAction) {
        case ACTION_USER_LIST:
            if ('DbAuthModule' === $config->authModule()) {
                // list local user accounts
                foreach ($storage->localUserList() as $localUserId) {
                    echo $localUserId . \PHP_EOL;
                }

                break;
            }
            // list users that ever authenticated
            foreach ($storage->userList() as $userInfo) {
                echo $userInfo->userId() . \PHP_EOL;
            }

            break;
        case ACTION_USER_ADD:
            $userId = requireUserId($userId);
            if ('DbAuthModule' !== $config->authModule()) {
                throw new RuntimeException('users can only be added when using DbAuthModule');
            }
            if (null === $userPass) {
                echo \sprintf('Setting password for user "%s"', $userId) . \PHP_EOL;
                // ask for password
                exec('stty -echo');
                echo 'Password: ';
                $userPass = FileIO::readLine();
                echo \PHP_EOL . 'Password (repeat): ';
                $userPassRepeat = FileIO::readLine();
                exec('stty echo');
                echo \PHP_EOL;
                if ($userPass !== $userPassRepeat) {
                    throw new RuntimeException('specified passwords do not match');
                }
            }

            if ('' === $userPass) {
                throw new RuntimeException('Password cannot be empty');
            }
            $storage->localUserAdd($userId, PasswdModule::generatePasswordHash($userPass), new DateTimeImmutable());

            break;
        case ACTION_USER_ENABLE:
            $userId = requireUserId($userId);
            // we only need to enable the user, no other steps required
            $storage->userEnable($userId);

            break;
        case ACTION_USER_DELETE:
            $userId = requireUserId($userId);
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
            $connectionManager = new ConnectionManager($config, $vpnDaemon, $storage, $connectionHooks, $logger);

            if (!$forceAction) {
                echo 'Are you sure you want to DELETE user "' . $userId . '"? [y/N]: ';
                if ('y' !== FileIO::readLine()) {
                    break;
                }
            }

            // delete and disconnect all (active) VPN configurations
            // for this user
            $connectionManager->disconnectByUserId($userId);

            // delete all user data (except log)
            $storage->userDelete($userId);

            if ('DbAuthModule' === $config->authModule()) {
                // remove the user from the local database
                $storage->localUserDelete($userId);
            }

            break;
        case ACTION_USER_DISABLE:
            $userId = requireUserId($userId);
            $vpnDaemon = new VpnDaemon(
                new CurlClient(
                    new ClientConfig(
                        caFile: $baseDir . '/config/keys/vpn-daemon/ca.crt',
                        certFile: $baseDir . '/config/keys/vpn-daemon/vpn-daemon-client.crt',
                        keyFile: $baseDir . '/config/keys/vpn-daemon/vpn-daemon-client.key'
                    )
                ),
                $logger
            );
            $connectionManager = new ConnectionManager($config, $vpnDaemon, $storage, $connectionHooks, $logger);
            $oauthStorage = new OAuthStorage($storage->dbPdo(), 'oauth_');
            $storage->userDisable($userId);

            // delete and disconnect all (active) VPN configurations
            // for this user
            $connectionManager->disconnectByUserId($userId);

            // revoke all OAuth authorizations
            foreach ($oauthStorage->getAuthorizations($userId) as $clientAuthorization) {
                $oauthStorage->deleteAuthorization($clientAuthorization->authKey());
            }

            break;

        case ACTION_USER_DELETE_STALE:
            $userList = $storage->userList();
            $totalUserCount = \count($userList);
            $staleUserList = $storage->userListLastSeenBefore($lastSeenBefore);
            $staleUserCount = \count($staleUserList);
            if (0 === $staleUserCount) {
                echo \sprintf('There are no accounts that were last used before %s', $lastSeenBefore->format(DateTimeImmutable::ATOM)) . \PHP_EOL;

                break;
            }

            if (!$forceAction) {
                echo \sprintf('You are about to delete %d out of %d accounts that were last active before %s!', $staleUserCount, $totalUserCount, $lastSeenBefore->format(DateTimeImmutable::ATOM)) . \PHP_EOL;
                echo 'Are you sure? [y/N]: ';
                if ('y' !== FileIO::readLine()) {
                    break;
                }
            }

            foreach ($staleUserList as $userInfo) {
                if ('DbAuthModule' === $config->authModule()) {
                    // for local users, delete the entry from the "users" table
                    // as well as the local account
                    $storage->userDelete($userInfo->userId());
                    $storage->localUserDelete($userInfo->userId());

                    continue;
                }

                if ($userInfo->isDisabled()) {
                    // we prevent disabled accounts from being removed, even if
                    // they are stale to prevent users from "coming back" in
                    // case an external authentication source is used where the
                    // account remains active...
                    echo \sprintf('User account "%s" not deleted to retain "is_disabled" flag', $userInfo->userId()) . \PHP_EOL;

                    continue;
                }
                $storage->userDelete($userInfo->userId());
            }

            break;
        default:
            showHelp();

            throw new RuntimeException('operation must be specified');
    }
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . \PHP_EOL;

    exit(1);
}
