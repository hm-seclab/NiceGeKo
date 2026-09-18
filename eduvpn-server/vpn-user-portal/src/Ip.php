<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal;

use GMP;
use Stringable;
use Vpn\Portal\Exception\IpException;

/**
 * This class would be a lot simpler if only IPv4 existed with 32 bit
 * addresses :-).
 */
final class Ip implements Stringable
{
    public const IP_4 = 4;
    public const IP_6 = 6;

    private function __construct(private GMP $ipAddress, private int $ipPrefix, private int $ipFamily) {}

    #[\Override]
    public function __toString(): string
    {
        return $this->address() . '/' . $this->ipPrefix;
    }

    public static function fromIp(string $ipAddress, ?int $ipPrefix = null): self
    {
        if (!str_contains($ipAddress, ':')) {
            // IPv4
            return self::fromIpFour($ipAddress, $ipPrefix);
        }

        // IPv6
        return self::fromIpSix($ipAddress, $ipPrefix);
    }

    public static function fromIpPrefix(string $ipAddressPrefix): self
    {
        $ipAddressPrefixArray = explode('/', $ipAddressPrefix, 2);
        if (2 !== \count($ipAddressPrefixArray)) {
            // no prefix specified
            return self::fromIp($ipAddressPrefix);
        }
        [$ipAddress, $ipPrefix] = $ipAddressPrefixArray;

        return self::fromIp($ipAddress, (int) $ipPrefix);
    }

    public function address(bool $sixBrackets = false): string
    {
        if ($sixBrackets && self::IP_6 === $this->ipFamily) {
            return \sprintf('[%s]', self::toAddress($this->ipAddress, $this->addressBits()));
        }

        return self::toAddress($this->ipAddress, $this->addressBits());
    }

    public function network(): self
    {
        return self::fromIp(
            self::toAddress(
                gmp_and(
                    $this->ipAddress,
                    gmp_and(
                        gmp_sub(self::gmpPowTwo($this->addressBits()), 1),
                        gmp_sub(self::gmpPowTwo($this->addressBits()), self::gmpPowTwo($this->addressBits() - $this->ipPrefix))
                    )
                ),
                $this->addressBits()
            ),
            $this->ipPrefix
        );
    }

    public function netmask(): string
    {
        return self::toAddress(
            gmp_xor(
                gmp_sub(self::gmpPowTwo($this->addressBits()), 1),
                gmp_sub(self::gmpPowTwo($this->addressBits() - $this->ipPrefix), 1),
            ),
            $this->addressBits()
        );
    }

    /**
     * Get the number of available IP addresses in the network represented by
     * the IP range specified in this object.
     */
    public function numberOfHostsFour(): int
    {
        if (self::IP_4 !== $this->ipFamily) {
            throw new IpException('IPv4 only');
        }

        return 2 ** (32 - $this->ipPrefix) - 2;
    }

    /**
     * Get the first usable IP in the network represented by the IP range
     * specified in this object.
     */
    public function firstHost(): string
    {
        if (self::IP_4 === $this->ipFamily && 31 <= $this->ipPrefix) {
            throw new IpException('network not big enough');
        }
        if (self::IP_6 === $this->ipFamily && 127 <= $this->ipPrefix) {
            throw new IpException('network not big enough');
        }

        return self::toAddress(
            gmp_add(
                self::fromAddress($this->network()->address()),
                1
            ),
            $this->addressBits()
        );
    }

    public function lastHost(): string
    {
        return self::toAddress(
            gmp_add(
                self::fromAddress($this->network()->address()),
                gmp_sub(
                    self::gmpPowTwo($this->addressBits() - $this->prefix()),
                    1
                )
            ),
            $this->addressBits()
        );
    }

    public function firstHostPrefix(): string
    {
        return $this->firstHost() . '/' . $this->prefix();
    }

    /**
     * @return array{0:Ip,1:Ip}
     */
    public function splitInHalf(): array
    {
        if (self::IP_4 === $this->ipFamily && $this->ipPrefix > 31) {
            throw new IpException(\sprintf('can not split prefix "/%d"', $this->ipPrefix));
        }

        if (self::IP_6 === $this->ipFamily && $this->ipPrefix > 127) {
            throw new IpException(\sprintf('can not split prefix "/%d"', $this->ipPrefix));
        }

        $prefixBits = $this->ipPrefix + 1;
        $netIp = $this->network();
        $noOfHosts = self::gmpPowTwo($this->addressBits() - $prefixBits);

        return [
            new self(
                self::fromAddress($netIp->address()),
                $prefixBits,
                $this->ipFamily
            ),
            new self(
                gmp_add($noOfHosts, self::fromAddress($netIp->address())),
                $prefixBits,
                $this->ipFamily
            ),
        ];
    }

    /**
     * @return array<Ip>
     */
    public function split(int $networkCount): array
    {
        // XXX what if we split in three?!
        // XXX introduce "maxPrefix" parameter?
        if (2 ** ($this->addressBits() - $this->ipPrefix - 2) < $networkCount) {
            throw new IpException('network too small to split in this many networks');
        }

        $requiredBits = (int) log($networkCount, 2);
        $prefixBits = self::IP_4 === $this->ipFamily ? $this->ipPrefix + $requiredBits : 112;
        if (self::IP_6 === $this->ipFamily) {
            $minPrefix = 112 - $requiredBits;
            if ($minPrefix < $this->ipPrefix) {
                throw new IpException('network too small, must be >= /' . $minPrefix);
            }
        }
        $netIp = $this->network();
        $splitRanges = [];
        for ($i = 0; $i < $networkCount; ++$i) {
            $noOfHosts = self::gmpPowTwo($this->addressBits() - $prefixBits);
            $netAddress = gmp_add(gmp_mul($i, $noOfHosts), self::fromAddress($netIp->address()));
            $splitRanges[] = new self($netAddress, $prefixBits, $this->ipFamily);
        }

        return $splitRanges;
    }

    /**
     * Get a free IPv4 address from this prefix for VPN clients, i.e. starting
     * at the 2nd address available, the first is allocated to the VPN server.
     *
     * The provided list of IP addresses ("allocated IPs") is considered and a
     * "gap search" is performed to find the first free IP address not in the
     * allocated list.
     *
     * @param array<string> $allocatedIpFourList
     *
     * @return array{0:string,1:int}
     */
    public function freeIpFourAddress(array $allocatedIpFourList): array
    {
        $ipCount = $this->numberOfHostsFour() - 1;
        for ($i = 0; $i < $ipCount; $i++) {
            $ipAddress = self::toAddress(
                gmp_add(
                    self::fromAddress($this->network()->address()),
                    2 + $i
                ),
                $this->addressBits()
            );
            if (!\in_array($ipAddress, $allocatedIpFourList, true)) {
                return [$ipAddress, 2 + $i];
            }
        }

        throw new IpException('no IPv4 address available');
    }

    /**
     * Get the _n_th IPv6 address in the IP prefix.
     *
     * This is used together with the by Ip::freeIpFourAddress returned index
     * parameter to get the matching IPv6 address for the "Free IPv4" address.
     * It does NOT prevent you asking for an index that is not available as a
     * client IP, the Ip::freeIpFourAddress function is supposed to take care
     * of this as IPv4 is more restrictive, i.e. no network/broadcast addresses
     * can be used.
     */
    public function ipSixAddressByIndex(int $i): string
    {
        if (self::IP_6 !== $this->ipFamily) {
            throw new IpException('IPv6 only');
        }

        if (127 <= $this->ipPrefix) {
            throw new IpException('network not big enough to create a list of client IPs');
        }

        $prefixMaxNoOfHosts = self::gmpPowTwo(128 - $this->ipPrefix);
        if (gmp_cmp($i, $prefixMaxNoOfHosts) > 0) {
            throw new IpException(\sprintf('prefix "/%d" does not contain "%d" hosts', $this->ipPrefix, $i));
        }

        return self::toAddress(
            gmp_add(
                self::fromAddress($this->network()->address()),
                $i
            ),
            $this->addressBits()
        );
    }

    /**
     * @return array<string>
     */
    public function clientIpListFour(): array
    {
        if (self::IP_4 !== $this->ipFamily) {
            throw new IpException('IPv4 only');
        }

        if (31 <= $this->ipPrefix) {
            throw new IpException('network not big enough to create a list of client IPs');
        }

        $maxNoOfHosts = 2 ** (32 - $this->ipPrefix) - 3;
        $hostIpList = [];
        for ($i = 0; $i < $maxNoOfHosts; ++$i) {
            $hostIpList[] = self::toAddress(
                gmp_add(
                    self::fromAddress($this->network()->address()),
                    2 + $i
                ),
                $this->addressBits()
            );
        }

        return $hostIpList;
    }

    /**
     * @return array<string>
     */
    public function clientIpListSix(int $maxNoOfHosts): array
    {
        if (self::IP_6 !== $this->ipFamily) {
            throw new IpException('IPv6 only');
        }

        if (127 <= $this->ipPrefix) {
            throw new IpException('network not big enough to create a list of client IPs');
        }

        // make sure we do not specify a "maxNumberOfHosts" that is bigger
        // than the prefix we have
        $prefixMaxNoOfHosts = gmp_sub(self::gmpPowTwo(128 - $this->ipPrefix), 3);
        if (gmp_cmp($maxNoOfHosts, $prefixMaxNoOfHosts) > 0) {
            throw new IpException(\sprintf('prefix "/%d" does not contain "%d" hosts', $this->ipPrefix, $maxNoOfHosts));
        }

        $hostIpList = [];
        for ($i = 0; $i < $maxNoOfHosts; ++$i) {
            $hostIpList[] = self::toAddress(
                gmp_add(
                    self::fromAddress($this->network()->address()),
                    2 + $i
                ),
                $this->addressBits()
            );
        }

        return $hostIpList;
    }

    public function equals(self $i): bool
    {
        return $this->address() === $i->address() && $this->prefix() === $i->prefix();
    }

    public function contains(self $i): bool
    {
        if ($this->family() !== $i->family()) {
            return false;
        }

        // the first address of the range
        $lowerAddress = self::fromAddress($this->network()->address());
        // the last address of the range
        $upperAddress = self::fromAddress($this->network()->lastHost());

        $lowerCompare = gmp_cmp(self::fromAddress($i->network()->address()), $lowerAddress);
        $upperCompare = gmp_cmp(self::fromAddress($i->network()->lastHost()), $upperAddress);

        return $lowerCompare >= 0 && $upperCompare <= 0;
    }

    public function prefix(): int
    {
        return $this->ipPrefix;
    }

    public function family(): int
    {
        return $this->ipFamily;
    }

    private function addressBits(): int
    {
        return self::IP_4 === $this->ipFamily ? 32 : 128;
    }

    private static function fromIpFour(string $ipAddress, ?int $ipPrefix = null): self
    {
        if (false === filter_var($ipAddress, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
            throw new IpException('invalid IPv4 address');
        }
        if (null === $ipPrefix) {
            $ipPrefix = 32;
        }
        if ($ipPrefix < 0 || $ipPrefix > 32) {
            throw new IpException('invalid IPv4 prefix');
        }

        return new self(self::fromAddress($ipAddress), $ipPrefix, self::IP_4);
    }

    private static function fromIpSix(string $ipAddress, ?int $ipPrefix = null): self
    {
        if (false === filter_var($ipAddress, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
            throw new IpException('invalid IPv6 address');
        }
        if (null === $ipPrefix) {
            $ipPrefix = 128;
        }
        if ($ipPrefix < 0 || $ipPrefix > 128) {
            throw new IpException('invalid IPv6 prefix');
        }

        return new self(self::fromAddress($ipAddress), $ipPrefix, self::IP_6);
    }

    private static function fromAddress(string $ipAddress): GMP
    {
        if (false === $addrBytes = inet_pton($ipAddress)) {
            throw new IpException('inet_pton');
        }

        return gmp_init(bin2hex($addrBytes), 16);
    }

    private static function toAddress(GMP $ipAddress, int $addressBits): string
    {
        $addrBytes = hex2bin(
            str_pad(
                gmp_strval($ipAddress, 16),
                (int) ($addressBits / 4),
                '0',
                \STR_PAD_LEFT
            )
        );
        if (false === $addrBytes) {
            throw new IpException('hex2bin');
        }
        if (false === $ipAddress = inet_ntop($addrBytes)) {
            throw new IpException('inet_ntop');
        }

        return $ipAddress;
    }

    /**
     * Workaround PHP bug in gmp_pow() where the exponent all of a sudden
     * needs to be <64.
     *
     * This issue was introducted in PHP 8.2.26 and 8.3.14 (and perhaps in
     * PHP 8.4.0?)
     *
     * @see https://github.com/php/php-src/issues/16870
     * @see https://github.com/phpipam/phpipam/commit/2414f8606bf77c6ed41364e780d1fb31150017d5
     */
    private static function gmpPowTwo(int $e): GMP
    {
        $r = gmp_init(0);
        gmp_setbit($r, $e);

        return $r;
    }
}
