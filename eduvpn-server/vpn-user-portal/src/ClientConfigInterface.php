<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal;

interface ClientConfigInterface
{
    public function contentType(): string;

    public function get(bool $includeComments): string;
}
