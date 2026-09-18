<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Tests;

use PHPUnit\Framework\TestCase;
use Vpn\Portal\Http\Auth\AbstractAuthModule;

/**
 * @coversNothing
 */
final class AbstractAuthModuleTest extends TestCase
{
    public function testFlattenPermissionList(): void
    {
        static::assertSame(
            [
                'n1!v1',
                'n1!v2',
                'n2!v3',
                'n2!v4',
            ],
            AbstractAuthModule::flattenPermissionList(
                [
                    'n1' => ['v1', 'v2'],
                    'n2' => ['v3', 'v4'],
                ],
                ['n1', 'n2']
            )
        );
    }

    public function testFlattenPermissionListSubset(): void
    {
        static::assertSame(
            [
                'n1!v1',
                'n1!v2',
            ],
            AbstractAuthModule::flattenPermissionList(
                [
                    'n1' => ['v1', 'v2'],
                    'n2' => ['v3', 'v4'],
                ],
                ['n1']
            )
        );
    }

    public function testFlattenPermissionListMissing(): void
    {
        static::assertSame(
            [
                'n1!v1',
                'n1!v2',
            ],
            AbstractAuthModule::flattenPermissionList(
                [
                    'n1' => ['v1', 'v2'],
                    'n2' => ['v3', 'v4'],
                ],
                ['n1', 'n3']
            )
        );
    }

    public function testFlattenPermissionListNone(): void
    {
        static::assertSame(
            [
            ],
            AbstractAuthModule::flattenPermissionList(
                [
                    'n1' => ['v1', 'v2'],
                    'n2' => ['v3', 'v4'],
                ],
                ['n4', 'n5']
            )
        );
    }
}
