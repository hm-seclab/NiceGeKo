<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Http;

use Vpn\Portal\Http\Exception\HttpException;

/**
 * Used from "node-api.php" to handle the API calls from "vpn-server-node".
 */
final class NodeApiService extends Service implements ServiceInterface
{
    #[\Override]
    public function run(Request $request): Response
    {
        try {
            return parent::run($request);
        } catch (HttpException $e) {
            return new JsonResponse(
                [
                    'error' => $e->getMessage(),
                ],
                $e->responseHeaders(),
                $e->statusCode()
            );
        }
    }
}
