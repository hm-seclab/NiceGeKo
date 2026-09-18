<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\HttpClient;

interface ClientInterface
{
    public function send(Request $request): Response;
}
