<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Http;

interface SessionInterface
{
    public function get(string $sessionKey): ?string;

    public function set(string $sessionKey, string $sessionValue): void;

    public function remove(string $sessionKey): void;

    public function destroy(): void;
}
