<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal;

use DateTimeImmutable;
use DateTimeZone;
use fkooman\OAuth\Server\ClientDbInterface;
use RangeException;
use Vpn\Portal\Exception\TplException;

final class Tpl implements TplInterface
{
    /** @var array<string> */
    private array $templateFolderList;

    /** @var array<string> */
    private array $translationFolderList;

    private string $assetDir;

    private ?string $activeSectionName = null;

    /** @var array<string,string> */
    private array $sectionList = [];

    /** @var array<string,array<string,mixed>> */
    private array $layoutList = [];

    /**
     * @param array<string,mixed> $templateVariables
     */
    public function __construct(string $baseDir, ?string $styleName, private string $uiLanguage, private array $templateVariables, private ClientDbInterface $clientDb)
    {
        $this->templateFolderList = self::templateFolderList($baseDir, $styleName);
        $this->translationFolderList = self::translationFolderList($baseDir, $styleName);
        $this->assetDir = $baseDir . '/web';
    }

    /**
     * @param mixed $v
     */
    #[\Override]
    public function addDefault(string $k, $v): void
    {
        $this->templateVariables[$k] = $v;
    }

    /**
     * @param array<string,mixed> $templateVariables
     */
    #[\Override]
    public function render(string $templateName, array $templateVariables = []): string
    {
        $this->templateVariables = array_merge($this->templateVariables, $templateVariables);
        extract($this->templateVariables);
        ob_start();
        /** @psalm-suppress UnresolvableInclude */
        include $this->templatePath($templateName);
        if (false === $templateStr = ob_get_clean()) {
            throw new TplException('unable to get output buffer');
        }
        if (0 === \count($this->layoutList)) {
            // we have no layout defined, so simple template...
            return $templateStr;
        }

        foreach ($this->layoutList as $layoutTemplateName => $layoutTemplateVariables) {
            unset($this->layoutList[$layoutTemplateName]);
            $templateStr .= $this->render($layoutTemplateName, $layoutTemplateVariables);
        }

        return $templateStr;
    }

    public function clientIdToDisplayName(string $clientId): string
    {
        if (null === $clientInfo = $this->clientDb->get($clientId)) {
            return $this->e($clientId);
        }

        return $this->e($clientInfo->displayName());
    }

    /**
     * @param array<\Vpn\Portal\Cfg\ProfileConfig> $profileConfigList
     */
    public function profileIdToDisplayName(array $profileConfigList, string $profileId): string
    {
        foreach ($profileConfigList as $profileConfig) {
            if ($profileId === $profileConfig->profileId()) {
                return $this->e($profileConfig->displayName());
            }
        }

        return $this->e($profileId);
    }

    public function uiLanguage(): string
    {
        return $this->uiLanguage;
    }

    public function languageCodeToHuman(string $uiLanguage): string
    {
        $supportedLanguages = self::supportedLanguages();
        if (!\array_key_exists($uiLanguage, $supportedLanguages)) {
            throw new TplException(\sprintf('unsupported UI language "%s"', $uiLanguage));
        }

        return $supportedLanguages[$uiLanguage];
    }

    /**
     * @param array<string,mixed> $templateVariables
     */
    public function insert(string $templateName, array $templateVariables = []): string
    {
        $this->templateVariables = array_merge($this->templateVariables, $templateVariables);
        extract($this->templateVariables);
        ob_start();
        /** @psalm-suppress UnresolvableInclude */
        include $this->templatePath($templateName);

        if (false === $outputBuffer = ob_get_clean()) {
            throw new TplException('unable to get output buffer');
        }

        return $outputBuffer;
    }

    /**
     * Get a URL with cache busting query parameter.
     */
    public function getAssetUrl(string $requestRoot, string $assetPath): string
    {
        if (false === $mTime = @filemtime($this->assetDir . '/' . $assetPath)) {
            // can't find file or determine last modified time, do not include
            // cache busting query parameter
            return $this->e($requestRoot . $assetPath);
        }

        return $this->e($requestRoot . $assetPath . '?mTime=' . $mTime);
    }

    public function start(string $sectionName): void
    {
        if (null !== $this->activeSectionName) {
            throw new TplException(\sprintf('section "%s" already started', $this->activeSectionName));
        }

        $this->activeSectionName = $sectionName;
        ob_start();
    }

    public function stop(string $sectionName): void
    {
        if (null === $this->activeSectionName) {
            throw new TplException('no section started');
        }

        if ($sectionName !== $this->activeSectionName) {
            throw new TplException(\sprintf('attempted to end section "%s" but current section is "%s"', $sectionName, $this->activeSectionName));
        }

        if (false === $outputBuffer = ob_get_clean()) {
            throw new TplException('unable to get output buffer');
        }
        $this->sectionList[$this->activeSectionName] = $outputBuffer;
        $this->activeSectionName = null;
    }

    /**
     * @param array<string,mixed> $templateVariables
     */
    public function layout(string $layoutName, array $templateVariables = []): void
    {
        $this->layoutList[$layoutName] = $templateVariables;
    }

    public function section(string $sectionName): string
    {
        if (!\array_key_exists($sectionName, $this->sectionList)) {
            throw new TplException(\sprintf('section "%s" does not exist', $sectionName));
        }

        return $this->sectionList[$sectionName];
    }

    public static function escape(string $v): string
    {
        return htmlentities($v, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    public function e(string $v): string
    {
        return self::escape($v);
    }

    public function eRaw(string $v): string
    {
        return self::escape(rawurlencode($v));
    }

    /**
     * Trim a string to a specified length and escape it.
     *
     * @throws \RangeException
     */
    public function etr(string $inputString, int $maxLen): string
    {
        if ($maxLen < 3) {
            throw new RangeException('"maxLen" must be >= 3');
        }

        $strLen = \strlen($inputString);
        if ($strLen <= $maxLen) {
            return $this->e($inputString);
        }

        $partOne = substr($inputString, 0, (int) ceil(($maxLen - 1) / 2));
        $partTwo = substr($inputString, (int) -floor(($maxLen - 1) / 2));

        return $this->e($partOne . '…' . $partTwo);
    }

    /**
     * Format a date.
     */
    public function d(DateTimeImmutable $dateTime, string $dateFormat = 'Y-m-d H:i:s T'): string
    {
        $dateTime = $dateTime->setTimezone(new DateTimeZone(date_default_timezone_get()));

        return $this->e($dateTime->format($dateFormat));
    }

    /**
     * Format a date, UTC.
     */
    public function du(DateTimeImmutable $dateTime, string $dateFormat = 'Y-m-d H:i:s T'): string
    {
        $dateTime = $dateTime->setTimezone(new DateTimeZone('UTC'));

        return $this->e($dateTime->format($dateFormat));
    }

    public function t(string $v): string
    {
        // use original, unless it is found in any of the translation files...
        $translatedText = $v;
        if ('en-US' !== $this->uiLanguage) {
            foreach ($this->translationFolderList as $translationFolder) {
                $translationFile = $translationFolder . '/' . $this->uiLanguage . '.php';
                if (!file_exists($translationFile)) {
                    continue;
                }

                /** @var array<string,string> $translationData */
                $translationData = include $translationFile;
                if (\array_key_exists($v, $translationData)) {
                    // translation found, run with it, we don't care if we find
                    // it in other file(s) as well!
                    $translatedText = $translationData[$v];

                    break;
                }
            }
        }

        // find all string values, wrap the key, and escape the variable
        $escapedVars = [];
        foreach ($this->templateVariables as $k => $tvv) {
            if (\is_string($tvv)) {
                $escapedVars['%' . $k . '%'] = $this->e($tvv);
            }
        }

        return str_replace(array_keys($escapedVars), array_values($escapedVars), $translatedText);
    }

    public function exists(string $templateName): bool
    {
        foreach ($this->templateFolderList as $templateFolder) {
            $templatePath = \sprintf('%s/%s.php', $templateFolder, $templateName);
            if (file_exists($templatePath)) {
                return true;
            }
        }

        return false;
    }

    public function templatePath(string $templateName): string
    {
        foreach (array_reverse($this->templateFolderList) as $templateFolder) {
            $templatePath = \sprintf('%s/%s.php', $templateFolder, $templateName);
            if (file_exists($templatePath)) {
                return $templatePath;
            }
        }

        throw new TplException(\sprintf('template "%s" does not exist', $templateName));
    }

    /**
     * @return array<string,string>
     */
    public static function supportedLanguages(): array
    {
        return [
            'af-ZA' => 'Afrikaans',
            'ar-MA' => 'العربية',
            'ca-ES' => 'Català',
            'cs-CZ' => 'Čeština',
            'da-DK' => 'Dansk',
            'de-DE' => 'Deutsch',
            'en-US' => 'English',
            'it-IT' => 'Italiano',
            'es-ES' => 'Español de España',
            'es-LA' => 'Español Latinoamericano',
            'et-EE' => 'Eesti',
            'fr-FR' => 'Français',
            'nb-NO' => 'norsk bokmål',
            'nl-NL' => 'Nederlands',
            'pl-PL' => 'polski',
            'pt-PT' => 'Português',
            'ro-RO' => 'română',
            'sk-SK' => 'Slovenčina',
            'sv-SE' => 'svenska',
            'tr-TR' => 'Türkçe',
            'uk-UA' => 'Українська',
            'lt-LT' => 'Lietuvių',
        ];
    }

    public function textDir(): string
    {
        if (\in_array($this->uiLanguage, ['ar-MA'], true)) {
            return 'rtl';
        }

        return 'ltr';
    }

    #[\Override]
    public function reset(): void
    {
        ob_clean();
        $this->activeSectionName = null;
        $this->sectionList = [];
        $this->layoutList = [];
    }

    /**
     * @return array<string>
     */
    private static function templateFolderList(string $baseDir, ?string $styleName): array
    {
        $templateDirs = [
            $baseDir . '/views',
            $baseDir . '/config/views',
        ];
        if (null !== $styleName) {
            $templateDirs[] = $baseDir . '/views/' . $styleName;
            $templateDirs[] = $baseDir . '/config/views/' . $styleName;
        }

        return $templateDirs;
    }

    /**
     * @return array<string>
     */
    private static function translationFolderList(string $baseDir, ?string $styleName): array
    {
        $translationDirs = [
            $baseDir . '/locale',
            $baseDir . '/config/locale',
        ];
        if (null !== $styleName) {
            $translationDirs[] = $baseDir . '/locale/' . $styleName;
            $translationDirs[] = $baseDir . '/config/locale/' . $styleName;
        }

        return $translationDirs;
    }
}
