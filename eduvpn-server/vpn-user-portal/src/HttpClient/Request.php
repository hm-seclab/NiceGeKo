<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\HttpClient;

final class Request
{
    public function __construct(
        public string $requestMethod,
        public string $requestUrl,
        /** @var array<string> */
        public array $requestHeaders = [],
        /** @var array<string,string|array<string>> */
        public array $queryParameters = [],
        /** @var array<string,string|array<string>> */
        public array $postParameters = []
    ) {}

    /**
     * Add the query parameters to the request URL if necessary.
     */
    public function requestUrlWithQuery(): string
    {
        if (null === $encodedQueryParameters = $this->encodedQueryParameters()) {
            return $this->requestUrl;
        }

        if (str_contains($this->requestUrl, '?')) {
            return \sprintf('%s&%s', $this->requestUrl, $encodedQueryParameters);
        }

        return \sprintf('%s?%s', $this->requestUrl, $encodedQueryParameters);
    }

    public function encodedPostParameters(): ?string
    {
        if (0 === \count($this->postParameters)) {
            return null;
        }

        return self::buildQuery($this->postParameters);
    }

    private function encodedQueryParameters(): ?string
    {
        if (0 === \count($this->queryParameters)) {
            return null;
        }

        return self::buildQuery($this->queryParameters);
    }

    /**
     * Properly encode HTTP (POST) query parameters while also supporting
     * duplicate key names. PHP's built in `http_build_query` uses weird key[]
     * syntax.
     *
     * @param array<string,string|array<string>> $queryParameters
     */
    private static function buildQuery(array $queryParameters): string
    {
        $qParts = [];
        foreach ($queryParameters as $k => $v) {
            if (\is_string($v)) {
                $qParts[] = urlencode($k) . '=' . urlencode($v);
            }
            if (\is_array($v)) {
                foreach ($v as $w) {
                    $qParts[] = urlencode($k) . '=' . urlencode($w);
                }
            }
        }

        return implode('&', $qParts);
    }
}
