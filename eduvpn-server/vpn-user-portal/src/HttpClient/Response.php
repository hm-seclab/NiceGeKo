<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\HttpClient;

use Stringable;

final class Response implements Stringable
{
    public function __construct(
        public int $responseCode,
        public string $responseBody
    ) {}

    #[\Override]
    public function __toString(): string
    {
        $outStr = '##### Response Code #####' . \PHP_EOL;
        $outStr .= \sprintf('%d', $this->responseCode) . \PHP_EOL;
        $outStr .= '##### Begin Response Body #####' . \PHP_EOL;
        $outStr .= $this->responseBody . \PHP_EOL;
        $outStr .= '##### End Response Body #####' . \PHP_EOL;

        return $outStr;
    }

    public function isOkay(): bool
    {
        return $this->responseCode >= 200 && $this->responseCode < 300;
    }
}
