<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Http;

class Response
{
    /**
     * @param array<string,string> $responseHeaders
     */
    public function __construct(private ?string $responseBody, private array $responseHeaders = [], private int $statusCode = 200) {}

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function responseBody(): ?string
    {
        return $this->responseBody;
    }

    public function send(): void
    {
        http_response_code($this->statusCode);
        foreach ($this->responseHeaders as $k => $v) {
            header($k . ': ' . $v);
        }
        if (null !== $this->responseBody) {
            echo $this->responseBody;
        }
    }
}
