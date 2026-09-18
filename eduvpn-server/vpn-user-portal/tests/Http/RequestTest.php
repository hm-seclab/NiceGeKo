<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Tests;

use PHPUnit\Framework\TestCase;
use RangeException;
use Vpn\Portal\Http\Exception\HttpException;
use Vpn\Portal\Http\Request;

/**
 * @covers \Vpn\Portal\Http\Request
 *
 * @uses \Vpn\Portal\Http\Exception\HttpException
 */
final class RequestTest extends TestCase
{
    public function testValidate(): void
    {
        $r = new Request(
            [],
            [],
            [
                'xyz' => 'foo',
            ],
            []
        );

        static::expectException(HttpException::class);
        static::expectExceptionMessage('invalid value for "xyz"');
        $r->requirePostParameter('xyz', function ($s): void {
            throw new RangeException();
        });
    }
}
