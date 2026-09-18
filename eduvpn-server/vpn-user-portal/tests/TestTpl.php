<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Tests;

use Vpn\Portal\Json;
use Vpn\Portal\TplInterface;

final class TestTpl implements TplInterface
{
    /** @var array<string, mixed> */
    private array $tplVariables = [];

    /**
     * @param mixed $v
     */
    #[\Override]
    public function addDefault(string $k, $v): void
    {
        $this->tplVariables[$k] = $v;
    }

    #[\Override]
    public function reset(): void
    {
        // NOP
    }

    /**
     * @param array<string,mixed> $templateVariables
     */
    #[\Override]
    public function render(string $templateName, array $templateVariables = []): string
    {
        return Json::encode(
            [
                $templateName => array_merge($this->tplVariables, $templateVariables),
            ]
        );
    }
}
