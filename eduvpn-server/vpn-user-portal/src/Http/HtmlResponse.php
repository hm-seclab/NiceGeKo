<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Http;

final class HtmlResponse extends Response
{
    /**
     * @param array<string,string> $responseHeaders
     */
    public function __construct(string $responseBody, array $responseHeaders = [], int $statusCode = 200)
    {
        $responseHeaders['Content-Type'] = 'text/html;charset=utf-8';
        parent::__construct($responseBody, $responseHeaders, $statusCode);
    }
}
