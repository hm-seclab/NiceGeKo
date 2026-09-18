<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\HttpClient\Tests;

use PHPUnit\Framework\TestCase;
use Vpn\Portal\HttpClient\Request;

/**
 * @covers \Vpn\Portal\HttpClient\Request
 */
final class RequestTest extends TestCase
{
    public function testEncodeParameters(): void
    {
        $r = new Request(
            requestMethod: 'POST',
            requestUrl: 'https://www.example.org/foo',
            postParameters: [
                'foo' => 'bar',
                'ip_net' => [
                    '10.42.42.5/32',
                    'fd42::5/128',
                ],
            ]
        );

        static::assertSame('foo=bar&ip_net=10.42.42.5%2F32&ip_net=fd42%3A%3A5%2F128', $r->encodedPostParameters());
    }
}
