<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * This file is part of eduVPN vpn-user-portal and has been MODIFIED.
 * Modified 2026-07 by the eduVPNextension project: added postureCheckConfig(),
 * which returns a PostureCheckConfig when a 'PostureCheck' block is present and
 * null otherwise (posture checking is opt-in).
 *
 * See eduvpn-server/vpn-user-portal/VENDORED.md for the unmodified upstream base.
 */

namespace Vpn\Portal\Cfg;

use DateInterval;
use Vpn\Portal\Cfg\Exception\ConfigException;
use Vpn\Portal\Extractor;
use Vpn\Portal\FileIO;
use Vpn\Portal\Json;

final class Config
{
    private const SESSION_EXPIRY_DEFAULT = 'P90D';

    public function __construct(
        /** @var array<mixed> $d */
        private array $d,
        private ?string $configDir = null
    ) {}

    public function sessionExpiry(): DateInterval
    {
        return new DateInterval(Extractor::requireString($this->d, 'sessionExpiry', self::SESSION_EXPIRY_DEFAULT));
    }

    /**
     * List of supported "sessionExpiry" values that can be provided as values
     * of "permissionAttributeList" through user authentication/authorization
     * which override the default "sessionExpiry" on a per user basis.
     *
     * NOTE: the default "sessionExpiry" is always included in the list of
     * accepted values
     *
     * @return array<string>
     */
    public function supportedSessionExpiry(): array
    {
        return array_merge(
            [
                Extractor::requireString($this->d, 'sessionExpiry', self::SESSION_EXPIRY_DEFAULT),
            ],
            Extractor::requireStringArray($this->d, 'supportedSessionExpiry', [])
        );
    }

    public function browserSessionExpiry(): DateInterval
    {
        return new DateInterval(Extractor::requireString($this->d, 'browserSessionExpiry', 'PT30M'));
    }

    public function caExpiry(): DateInterval
    {
        return new DateInterval(Extractor::requireString($this->d, 'caExpiry', 'P25Y'));
    }

    public function maxActiveConfigurations(): int
    {
        return Extractor::requireInt($this->d, 'maxActiveConfigurations', 3);
    }

    public function apiConfig(): ApiConfig
    {
        return new ApiConfig(Extractor::requireArray($this->d, 'Api', []));
    }

    public function memcacheSessionConfig(): MemcacheSessionConfig
    {
        return new MemcacheSessionConfig(Extractor::requireArray($this->d, 'MemcacheSessionModule', []));
    }

    public function vpnCaPath(): string
    {
        return Extractor::requireString($this->d, 'vpnCaPath', '/usr/bin/vpn-ca');
    }

    public function authModule(): string
    {
        return Extractor::requireString($this->d, 'authModule', 'DbAuthModule');
    }

    public function sessionModule(): string
    {
        return Extractor::requireString($this->d, 'sessionModule', 'FileSessionModule');
    }

    public function defaultLanguage(): string
    {
        return Extractor::requireString($this->d, 'defaultLanguage', 'en-US');
    }

    /**
     * @return ?array<string>
     */
    public function accessPermissionList(): ?array
    {
        return Extractor::optionalStringArray($this->d, 'accessPermissionList');
    }

    /**
     * @return array<string>
     */
    public function adminUserIdList(): array
    {
        return Extractor::requireStringArray($this->d, 'adminUserIdList', []);
    }

    /**
     * @return array<string>
     */
    public function adminPermissionList(): array
    {
        return Extractor::requireStringArray($this->d, 'adminPermissionList', []);
    }

    /**
     * @return array<string>
     */
    public function enabledLanguages(): array
    {
        return Extractor::requireStringArray($this->d, 'enabledLanguages', ['en-US']);
    }

    public function styleName(): ?string
    {
        return Extractor::optionalString($this->d, 'styleName');
    }

    public function showPermissions(): bool
    {
        return Extractor::requireBool($this->d, 'showPermissions', false);
    }

    public function connectScriptPath(): ?string
    {
        return Extractor::optionalString($this->d, 'connectScriptPath');
    }

    public function openVpnConfig(): OpenVpnConfig
    {
        return new OpenVpnConfig(Extractor::requireArray($this->d, 'OpenVpn', []));
    }

    public function wireGuardConfig(): WireGuardConfig
    {
        return new WireGuardConfig(Extractor::requireArray($this->d, 'WireGuard', []));
    }

    public function postureCheckConfig(): ?PostureCheckConfig
    {
        $data = Extractor::requireArray($this->d, 'PostureCheck', []);
        if (0 === \count($data)) {
            return null;
        }

        return new PostureCheckConfig($data);
    }

    public function hasProfile(string $profileId): bool
    {
        foreach ($this->profileConfigList() as $profileConfig) {
            if ($profileId === $profileConfig->profileId()) {
                return true;
            }
        }

        return false;
    }

    public function profileConfig(string $profileId): ProfileConfig
    {
        foreach ($this->profileConfigList() as $profileConfig) {
            if ($profileId === $profileConfig->profileId()) {
                return $profileConfig;
            }
        }

        throw new ConfigException('profile "' . $profileId . '" does not exist');
    }

    /**
     * Return the list of all UNIQUE "nodeUrls" used by the profile(s).
     *
     * @return array<int,string>
     */
    public function nodeNumberUrlList(): array
    {
        $nodeNumberUrlList = [];
        foreach ($this->profileConfigList() as $profileConfig) {
            foreach ($profileConfig->onNode() as $nodeNumber) {
                $nodeUrl = $profileConfig->nodeUrl($nodeNumber);
                if (!\in_array($nodeUrl, $nodeNumberUrlList, true)) {
                    $nodeNumberUrlList[$nodeNumber] = $nodeUrl;
                }
            }
        }

        return $nodeNumberUrlList;
    }

    /**
     * @return array<ProfileConfig>
     */
    public function profileConfigList(): array
    {
        $profileConfigList = [];
        foreach (Extractor::requireArrayArray($this->d, 'ProfileList', []) as $profileData) {
            $profileConfigList[] = new ProfileConfig($profileData);
        }

        if (null !== $this->configDir) {
            // if we have a "configDir", go over the JSON files as well...
            if (false === $profilesDirGlob = glob(\sprintf('%s/profiles/*.json', $this->configDir))) {
                throw new ConfigException(\sprintf('unable to read JSON files in folder %s/profiles', $this->configDir));
            }
            foreach ($profilesDirGlob as $profileConfigFile) {
                $profileConfigList[] = new ProfileConfig(Json::decode(FileIO::read($profileConfigFile)));
            }
        }

        // sort the profiles by "priority"
        usort(
            $profileConfigList,
            function (ProfileConfig $a, ProfileConfig $b): int {
                if ($a->profilePriority() < $b->profilePriority()) {
                    return 1;
                }
                if ($a->profilePriority() > $b->profilePriority()) {
                    return -1;
                }

                return 0;
            }
        );

        return $profileConfigList;
    }

    public function dbConfig(string $baseDir): DbConfig
    {
        return new DbConfig(
            array_merge(
                [
                    'baseDir' => $baseDir,
                ],
                Extractor::requireArray($this->d, 'Db', [])
            )
        );
    }

    public function logConfig(): LogConfig
    {
        return new LogConfig(Extractor::requireArray($this->d, 'Log', []));
    }

    public function mellonAuthConfig(): MellonAuthConfig
    {
        return new MellonAuthConfig(Extractor::requireArray($this->d, 'MellonAuthModule', []));
    }

    public function phpSamlSpAuthConfig(): PhpSamlSpAuthConfig
    {
        return new PhpSamlSpAuthConfig(Extractor::requireArray($this->d, 'PhpSamlSpAuthModule', []));
    }

    public function shibAuthConfig(): ShibAuthConfig
    {
        return new ShibAuthConfig(Extractor::requireArray($this->d, 'ShibAuthModule', []));
    }

    public function oidcAuthConfig(): OidcAuthConfig
    {
        return new OidcAuthConfig(Extractor::requireArray($this->d, 'OidcAuthModule', []));
    }

    public function radiusAuthConfig(): RadiusAuthConfig
    {
        return new RadiusAuthConfig(Extractor::requireArray($this->d, 'RadiusAuthModule', []));
    }

    public function ldapAuthConfig(): LdapAuthConfig
    {
        return new LdapAuthConfig(Extractor::requireArray($this->d, 'LdapAuthModule', []));
    }

    public function staticPermissionsConfig(string $baseDir): StaticPermissionsConfig
    {
        return new StaticPermissionsConfig(
            array_merge(
                [
                    'baseDir' => $baseDir,
                ],
                Extractor::requireArray($this->d, 'StaticPermissions', [])
            )
        );
    }

    /**
     * @psalm-suppress UnresolvableInclude
     */
    public static function fromFile(string $configFile): self
    {
        if (false === FileIO::exists($configFile)) {
            throw new ConfigException(\sprintf('unable to read "%s"', $configFile));
        }
        $configData = require $configFile;
        if (!\is_array($configData)) {
            throw new ConfigException('configuration file does not contain a PHP array');
        }

        return new self($configData, \dirname($configFile));
    }

    /**
     * Get a list of configuration keys NOT supported in order to warn the
     * admins on the "Info" page about it.
     *
     * @return array<string>
     */
    public function unsupportedConfigKeys(): array
    {
        $supportedKeys = [
            'accessPermissionList',
            'adminPermissionList',
            'adminUserIdList',
            'Api',
            'authModule',
            'browserSessionExpiry',
            'caExpiry',
            'connectScriptPath',
            'Db',
            'defaultLanguage',
            'enabledLanguages',
            'LdapAuthModule',
            'Log',
            'maxActiveConfigurations',
            'MellonAuthModule',
            'MemcacheSessionModule',
            'OidcAuthModule',
            'PhpSamlSpAuthModule',
            'ProfileList',
            'RadiusAuthModule',
            'sessionExpiry',
            'sessionModule',
            'ShibAuthModule',
            'showPermissions',
            'styleName',
            'supportedSessionExpiry',
            'vpnCaPath',
            'OpenVpn',
            'PostureCheck',
            'WireGuard',
            'StaticPermissions',
        ];

        $unsupportedKeys = [];
        foreach (array_keys($this->d) as $configKey) {
            if (!\is_string($configKey)) {
                // ignore non-string keys
                continue;
            }
            if (!\in_array($configKey, $supportedKeys, true)) {
                $unsupportedKeys[] = $configKey;
            }
        }

        return $unsupportedKeys;
    }
}
