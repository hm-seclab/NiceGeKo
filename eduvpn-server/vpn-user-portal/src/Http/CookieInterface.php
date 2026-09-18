<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Http;

interface CookieInterface
{
    public function set(string $cookieName, string $cookieValue): void;
}
