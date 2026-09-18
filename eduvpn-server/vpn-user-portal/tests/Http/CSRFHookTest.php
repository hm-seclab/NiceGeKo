<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Tests;

use PHPUnit\Framework\TestCase;
use Vpn\Portal\Http\CSRFHook;
use Vpn\Portal\Http\Exception\HttpException;
use Vpn\Portal\Http\Request;

/**
 * @coversNothing
 */
final class CSRFHookTest extends TestCase
{
    public function testSecFetchSiteCrossOrigin(): void
    {
        $request = new Request(
            [
                'REQUEST_METHOD' => 'POST',
                'HTTP_SEC_FETCH_SITE' => 'cross-site',
            ],
            [],
            [],
            []
        );

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('"Sec-Fetch-Site" header value is not "same-origin" or "none" (CSRF)');
        $hook = new CSRFHook();
        $hook->beforeAuth($request);
    }
}
