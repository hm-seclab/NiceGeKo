<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal;

final class NodeInfo
{
    public function __construct(private int $nodeNumber, private string $nodeUrl, private string $hostName) {}

    public function nodeNumber(): int
    {
        return $this->nodeNumber;
    }

    public function nodeUrl(): string
    {
        return $this->nodeUrl;
    }

    public function hostName(): string
    {
        return $this->hostName;
    }
}
