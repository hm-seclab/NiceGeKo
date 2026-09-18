<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Tests;

use Vpn\Portal\HttpClient\ClientInterface;
use Vpn\Portal\HttpClient\Request;
use Vpn\Portal\HttpClient\Response;

final class TestHttpClient implements ClientInterface
{
    /** @var array<\Vpn\Portal\HttpClient\Request> */
    private array $logData = [];

    /**
     * @return array<\Vpn\Portal\HttpClient\Request>
     */
    public function logData(): array
    {
        return $this->logData;
    }

    #[\Override]
    public function send(Request $request): Response
    {
        $this->logData[] = $request;
        if ('http://localhost:41194/i/node?include_client_peer_count=no' === $request->requestUrlWithQuery()) {
            return new Response(
                200,
                '{"rel_load_average":[24,25,31],"load_average":[0.48,0.5,0.63],"cpu_count":2,"node_uptime":12345,"v":"3.0.0"}'
            );
        }

        if ('http://localhost:41194/w/peer_list?show_all=yes' === $request->requestUrlWithQuery()) {
            return new Response(
                200,
                '{"peer_list": []}'
            );
        }

        if ('http://localhost:41194/o/connection_list' === $request->requestUrlWithQuery()) {
            return new Response(
                200,
                '{"connection_list": []}'
            );
        }

        return new Response(
            404,
            '{"error": "not_found"}'
        );
    }
}
