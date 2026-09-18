<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Http;

use fkooman\SeCookie\Cookie;

final class SeCookie implements CookieInterface
{
    public function __construct(private Cookie $cookie) {}

    #[\Override]
    public function set(string $cookieName, string $cookieValue): void
    {
        $this->cookie->set($cookieName, $cookieValue);
    }
}
