<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vpn\Portal\Cfg\Config;
use Vpn\Portal\Http\Auth\NullAuthModule;
use Vpn\Portal\Http\NullSession;
use Vpn\Portal\Http\Request;
use Vpn\Portal\Http\UpdateUserInfoHook;
use Vpn\Portal\Http\UserInfo;
use Vpn\Portal\PermissionSourceManager;
use Vpn\Portal\StaticPermissionsSource;
use Vpn\Portal\Storage;

/**
 * @internal
 *
 * @coversNothing
 */
final class UpdateUserInfoHookTest extends TestCase
{
    private UpdateUserInfoHook $updateUserInfoHook;
    private Storage $storage;

    #[\Override]
    public function setUp(): void
    {
        $baseDir = \dirname(__DIR__, 2);
        $config = new Config(
            [
                'Db' => [
                    'dbDsn' => 'sqlite::memory:',
                ],
                'StaticPermissions' => [
                    'permissionsFile' => __DIR__ . '/data/static_permissions.json',
                ],
            ]
        );

        $permissionSourceManager = new PermissionSourceManager();
        $permissionSourceManager->add(
            new StaticPermissionsSource(
                $config->staticPermissionsConfig($baseDir),
                new Request(['REMOTE_ADDR' => '192.168.9.9'], [], [], [])
            )
        );

        $this->storage = new Storage($config->dbConfig($baseDir));
        $this->updateUserInfoHook = new UpdateUserInfoHook(
            new NullSession(),
            $this->storage,
            new NullAuthModule(),
            $permissionSourceManager,
            new DateTimeImmutable()
        );
    }

    /**
     * We used to have a bug where the "isDisabled" state got lost when
     * updating the UserInfo object in UpdateUserInfoHook
     */
    public function testIsDisabled(): void
    {
        // add the *disabled* user to the DB
        $this->storage->userAdd(
            new UserInfo(
                'foo',
                [],
                null,
                true    // isDisabled
            ),
            new DateTimeImmutable('2023-01-01T08:00:00+00:00')
        );

        // the authentication source does NOT set "isDisabled" on the UserInfo
        // object because the authentication sources do not (generally) have
        // access to the DB
        $userInfo = new UserInfo('foo', []);
        $this->updateUserInfoHook->afterAuth(
            new Request([], [], [], []),
            $userInfo
        );

        // the UpdateUserInfoHook MUST in all cases check the DB to make sure
        // the user is not disabled... Oof, this is ugly!
        static::assertTrue($userInfo->isDisabled());
    }

    public function testStaticPermissionSource(): void
    {
        $userInfo = new UserInfo('foo', []);
        $this->updateUserInfoHook->afterAuth(
            new Request([], [], [], []),
            $userInfo
        );

        static::assertSame(['eduPersonAffiliation!employee', 'memberOf!example-permission'], $userInfo->permissionList());
    }

    public function testStaticPermissionSourceNoPermission(): void
    {
        $userInfo = new UserInfo('baz', []);
        $this->updateUserInfoHook->afterAuth(
            new Request([], [], [], []),
            $userInfo
        );

        static::assertSame([], $userInfo->permissionList());
    }

    public function testStaticPermissionSourceExistingPermission(): void
    {
        $userInfo = new UserInfo('foo', ['p1', 'p2']);
        $this->updateUserInfoHook->afterAuth(
            new Request([], [], [], []),
            $userInfo
        );

        static::assertSame(['p1', 'p2', 'eduPersonAffiliation!employee', 'memberOf!example-permission'], $userInfo->permissionList());
    }
}
