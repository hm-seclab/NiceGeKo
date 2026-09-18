<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Http;

use fkooman\SeCookie\CookieOptions;
use fkooman\SeCookie\FileSessionStorage;
use fkooman\SeCookie\JsonSerializer;
use fkooman\SeCookie\MemcacheSessionStorage;
use fkooman\SeCookie\Session;
use fkooman\SeCookie\SessionOptions;
use RuntimeException;
use Vpn\Portal\Cfg\Config;

final class SeSession implements SessionInterface
{
    private Session $session;

    public function __construct(CookieOptions $cookieOptions, Config $config)
    {
        $sessionStorage = match ($config->sessionModule()) {
            'FileSessionModule' => new FileSessionStorage(null, new JsonSerializer()),
            'MemcacheSessionModule' => new MemcacheSessionStorage(
                $config->memcacheSessionConfig()->serverList(),
                new JsonSerializer()
            ),
            default => throw new RuntimeException(\sprintf('session module "%s" not supported', $config->sessionModule()))
        };

        $this->session = new Session(
            SessionOptions::init()->withExpiresIn($config->browserSessionExpiry()),
            $cookieOptions,
            $sessionStorage
        );
        $this->session->start();
    }

    #[\Override]
    public function get(string $sessionKey): ?string
    {
        return $this->session->get($sessionKey);
    }

    #[\Override]
    public function set(string $sessionKey, string $sessionValue): void
    {
        $this->session->set($sessionKey, $sessionValue);
    }

    #[\Override]
    public function remove(string $sessionKey): void
    {
        $this->session->remove($sessionKey);
    }

    #[\Override]
    public function destroy(): void
    {
        $this->session->destroy();
    }

    public function stop(): void
    {
        $this->session->stop();
    }
}
