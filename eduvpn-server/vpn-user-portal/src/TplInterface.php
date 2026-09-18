<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal;

interface TplInterface
{
    /**
     * @param mixed $v
     */
    public function addDefault(string $k, $v): void;

    public function reset(): void;

    /**
     * @param array<string,mixed> $templateVariables
     */
    public function render(string $templateName, array $templateVariables = []): string;
}
