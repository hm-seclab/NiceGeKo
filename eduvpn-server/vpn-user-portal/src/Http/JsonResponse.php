<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Http;

use Vpn\Portal\Json;

final class JsonResponse extends Response
{
    /**
     * @param array<mixed> $jsonData
     * @param array<string,string> $responseHeaders
     */
    public function __construct(array $jsonData, array $responseHeaders = [], int $statusCode = 200)
    {
        $responseHeaders['Content-Type'] = 'application/json';
        parent::__construct(Json::encode($jsonData), $responseHeaders, $statusCode);
    }
}
