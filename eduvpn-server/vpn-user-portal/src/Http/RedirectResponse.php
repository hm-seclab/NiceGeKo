<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Http;

final class RedirectResponse extends Response
{
    public function __construct(string $redirectUri, int $statusCode = 302)
    {
        parent::__construct(null, ['Location' => $redirectUri], $statusCode);
    }
}
