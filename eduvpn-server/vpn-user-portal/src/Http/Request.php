<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Http;

use Closure;
use RangeException;
use Vpn\Portal\Extractor;
use Vpn\Portal\Http\Exception\HttpException;
use Vpn\Portal\Validator;

final class Request
{
    public function __construct(
        /** @var array<mixed> */
        private array $serverData,
        /** @var array<mixed> */
        private array $getData,
        /** @var array<mixed> */
        private array $postData,
        /** @var array<mixed> */
        private array $cookieData
    ) {}

    public static function createFromGlobals(): self
    {
        return new self(
            $_SERVER,
            $_GET,
            $_POST,
            $_COOKIE
        );
    }

    public function getScheme(): string
    {
        $requestScheme = 'http';
        if ('on' === $this->optionalHeader('HTTPS')) {
            $requestScheme = 'https';
        }
        if ('https' === $this->optionalHeader('REQUEST_SCHEME')) {
            $requestScheme = 'https';
        }

        return $requestScheme;
    }

    /**
     * URI = scheme:[//authority]path[?query][#fragment]
     * authority = [userinfo@]host[:port].
     *
     * @see https://en.wikipedia.org/wiki/Uniform_Resource_Identifier#Generic_syntax
     */
    public function getAuthority(): string
    {
        // we do NOT care about "userinfo"
        $requestScheme = $this->getScheme();
        $serverName = $this->requireHeader('SERVER_NAME');
        $serverPort = (int) $this->requireHeader('SERVER_PORT');

        if ('https' === $requestScheme && 443 === $serverPort) {
            return $serverName;
        }
        if ('http' === $requestScheme && 80 === $serverPort) {
            return $serverName;
        }

        return $serverName . ':' . $serverPort;
    }

    public function getUri(): string
    {
        return $this->getScheme() . '://' . $this->getAuthority() . $this->requireHeader('REQUEST_URI');
    }

    public function getRoot(): string
    {
        if (null === $appRoot = $this->optionalHeader('VPN_APP_ROOT')) {
            return '/';
        }

        return $appRoot . '/';
    }

    public function getRootUri(): string
    {
        return $this->getScheme() . '://' . $this->getAuthority() . $this->getRoot();
    }

    public function getRequestMethod(): string
    {
        return $this->requireHeader('REQUEST_METHOD');
    }

    public function getServerName(): string
    {
        return $this->requireHeader('SERVER_NAME');
    }

    public function getOrigin(): string
    {
        return \sprintf('%s://%s', $this->getScheme(), $this->getAuthority());
    }

    public function getPathInfo(): string
    {
        // if we have PATH_INFO available, use it
        if (null !== $pathInfo = $this->optionalHeader('PATH_INFO')) {
            return $pathInfo;
        }

        // if not, we have to reconstruct it
        $requestUri = $this->requireHeader('REQUEST_URI');

        // trim the query string (if any)
        if (false !== $queryStart = strpos($requestUri, '?')) {
            $requestUri = substr($requestUri, 0, $queryStart);
        }

        // remove the VPN_APP_ROOT (if any)
        if (null !== $appRoot = $this->optionalHeader('VPN_APP_ROOT')) {
            $requestUri = substr($requestUri, \strlen($appRoot));
        }

        return $requestUri;
    }

    /**
     * @param Closure(string):void $c
     */
    public function requireQueryParameter(string $queryKey, Closure $c): string
    {
        try {
            $v = Extractor::requireString($this->getData, $queryKey);
            $c($v);

            return $v;
        } catch (RangeException) {
            throw new HttpException(\sprintf('invalid value for "%s"', $queryKey), 400);
        }
    }

    /**
     * @param Closure(string):void $c
     */
    public function optionalQueryParameter(string $queryKey, Closure $c): ?string
    {
        if (!\array_key_exists($queryKey, $this->getData)) {
            return null;
        }

        return $this->requireQueryParameter($queryKey, $c);
    }

    /**
     * @param Closure(array<string>):void $c
     *
     * @return array<string>
     */
    public function optionalArrayPostParameter(string $postKey, Closure $c): array
    {
        try {
            $postValue = Extractor::requireStringOrStringArray($this->postData, $postKey, []);
            $c($postValue);

            return $postValue;
        } catch (RangeException) {
            throw new HttpException(\sprintf('invalid value for "%s"', $postKey), 400);
        }
    }

    /**
     * @param Closure(string):void $c
     */
    public function requirePostParameter(string $postKey, Closure $c): string
    {
        try {
            $v = Extractor::requireString($this->postData, $postKey);
            $c($v);

            return $v;
        } catch (RangeException) {
            throw new HttpException(\sprintf('invalid value for "%s"', $postKey), 400);
        }
    }

    /**
     * @param Closure(string):void $c
     */
    public function optionalPostParameter(string $postKey, Closure $c): ?string
    {
        if (!\array_key_exists($postKey, $this->postData)) {
            return null;
        }

        return $this->requirePostParameter($postKey, $c);
    }

    /**
     * @param Closure(string):void $c
     */
    public function getCookie(string $cookieKey, Closure $c): ?string
    {
        if (null === $v = Extractor::optionalString($this->cookieData, $cookieKey)) {
            return null;
        }

        try {
            $c($v);

            return $v;
        } catch (RangeException) {
            // when a cookie value is malformed, we consider it not set at all
            return null;
        }
    }

    /**
     * @param ?Closure(string):void $c
     *
     * @return non-empty-string
     */
    public function requireHeader(string $headerKey, ?Closure $c = null): string
    {
        try {
            $v = Extractor::requireString($this->serverData, $headerKey);
            if ('' === $v) {
                throw new HttpException(\sprintf('value of request header "%s" MUST not be empty', $headerKey), 400);
            }
            if (null !== $c) {
                $c($v);
            }

            return $v;
        } catch (RangeException) {
            throw new HttpException(\sprintf('invalid value for "%s"', $headerKey), 400);
        }
    }

    /**
     * @param ?Closure(string):void $c
     *
     * @return ?non-empty-string
     */
    public function optionalHeader(string $headerKey, ?Closure $c = null): ?string
    {
        if (!\array_key_exists($headerKey, $this->serverData)) {
            return null;
        }

        return $this->requireHeader($headerKey, $c);
    }

    /**
     * If the HTTP_REFERER header is set, verify it and return it.
     */
    public function optionalReferrer(): ?string
    {
        if (null === $referrerHeaderValue = $this->optionalHeader('HTTP_REFERER')) {
            return null;
        }

        try {
            Validator::matchesOrigin($this->getOrigin(), $referrerHeaderValue);

            return $referrerHeaderValue;
        } catch (RangeException) {
            throw new HttpException('unexpected HTTP_REFERER', 400);
        }
    }

    /**
     * Verify the HTTP_REFERER and return it.
     */
    public function requireReferrer(): string
    {
        if (null === $referrerHeaderValue = $this->optionalReferrer()) {
            throw new HttpException('missing HTTP_REFERER', 400);
        }

        return $referrerHeaderValue;
    }
}
