<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Http;

use Closure;

interface ApiServiceInterface
{
    /**
     * @param Closure(Request,ApiUserInfo):Response $closure
     */
    public function get(string $pathInfo, Closure $closure): void;

    /**
     * @param Closure(Request,ApiUserInfo):Response $closure
     */
    public function post(string $pathInfo, Closure $closure): void;
}
