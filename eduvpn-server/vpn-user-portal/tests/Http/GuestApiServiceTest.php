<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Tests;

use PHPUnit\Framework\TestCase;
use Vpn\Portal\Http\Exception\HttpException;
use Vpn\Portal\Http\GuestApiService;

/**
 * @internal
 *
 * @coversNothing
 */
final class GuestApiServiceTest extends TestCase
{
    public function testValidateGuestUserIdInvalidEncoding(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('[Guest]: User ID has invalid encoding');
        GuestApiService::validateGuestUserId('+');
    }

    public function testValidateGuestUserIdInvalidLength(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('[Guest]: User ID has invalid length');
        GuestApiService::validateGuestUserId('foo');
    }
}
