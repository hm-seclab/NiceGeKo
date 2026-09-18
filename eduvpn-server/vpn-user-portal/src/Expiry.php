<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal;

use DateInterval;
use DateTimeImmutable;
use Vpn\Portal\Http\UserInfo;

/**
 * Determine the "Session Expiry", i.e. when the OAuth authorization and
 * VPN configuration files expire and for the client to restart the
 * authorization process, or the user to return to the portal to obtain a new
 * configuration file.
 */
final class Expiry
{
    /**
     * @param array<string> $supportedSessionExpiry
     */
    public function __construct(private DateInterval $defaultSessionExpiry, private array $supportedSessionExpiry, private DateTimeImmutable $dateTime, private DateTimeImmutable $caExpiresAt) {}

    public function expiresAt(?UserInfo $userInfo = null): DateTimeImmutable
    {
        if (null !== $userInfo) {
            $userSessionExpiry = $userInfo->sessionExpiry();
            if (1 === \count($userSessionExpiry)) {
                if ($this->isAllowed($userSessionExpiry[0])) {
                    return $this->clampToCa(new DateInterval($userSessionExpiry[0]));
                }
            }
        }

        return $this->clampToCa($this->defaultSessionExpiry);
    }

    /**
     * Make sure the exact "string" representation of a `DateInterval` is
     * allowed.
     */
    public function isAllowed(string $dateInterval): bool
    {
        return \in_array($dateInterval, $this->supportedSessionExpiry, true);
    }

    public function expiresIn(?UserInfo $userInfo = null): DateInterval
    {
        return $this->dateTime->diff($this->expiresAt($userInfo));
    }

    /**
     * Make sure that whatever sessionExpiry we have, it never outlives the CA.
     */
    public function clampToCa(DateInterval $sessionExpiry): DateTimeImmutable
    {
        $expiresAt = $this->dateTime->add($sessionExpiry);
        if ($expiresAt > $this->caExpiresAt) {
            return $this->caExpiresAt;
        }

        return $expiresAt;
    }
}
