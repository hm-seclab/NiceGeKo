<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Http;

use Vpn\Portal\Http\Exception\HttpException;
use Vpn\Portal\TplInterface;

/**
 * Used from "index.php".
 */
final class PortalService extends Service implements ServiceInterface
{
    public function __construct(AuthModuleInterface $authModule, private TplInterface $tpl)
    {
        parent::__construct($authModule);
    }

    #[\Override]
    public function run(Request $request): Response
    {
        try {
            return parent::run($request);
        } catch (HttpException $e) {
            return new HtmlResponse(
                $this->tpl->render(
                    'errorPage',
                    [
                        'code' => $e->statusCode(),
                        'message' => $e->getMessage(),
                    ]
                ),
                $e->responseHeaders(),
                $e->statusCode()
            );
        }
    }
}
