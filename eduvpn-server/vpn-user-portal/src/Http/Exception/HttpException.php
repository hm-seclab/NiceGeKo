<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Http\Exception;

use Exception;

final class HttpException extends Exception
{
    /**
     * @param array<string,string> $responseHeaders
     */
    public function __construct(string $message, private int $statusCode, private array $responseHeaders = [])
    {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string,string>
     */
    public function responseHeaders(): array
    {
        return $this->responseHeaders;
    }
}
