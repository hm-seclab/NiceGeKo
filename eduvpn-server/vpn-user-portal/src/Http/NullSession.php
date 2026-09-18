<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Http;

/**
 * The do nothing session class.
 */
final class NullSession implements SessionInterface
{
    #[\Override]
    public function get(string $sessionKey): ?string
    {
        return null;
    }

    #[\Override]
    public function set(string $sessionKey, string $sessionValue): void {}

    #[\Override]
    public function remove(string $sessionKey): void {}

    #[\Override]
    public function destroy(): void {}
}
