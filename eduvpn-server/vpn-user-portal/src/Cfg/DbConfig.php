<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Cfg;

use PDO;
use Vpn\Portal\Extractor;

final class DbConfig
{
    public function __construct(
        /** @var array<mixed> $d */
        private array $d
    ) {}

    public function baseDir(): string
    {
        return Extractor::requireString($this->d, 'baseDir');
    }

    public function schemaDir(): string
    {
        return $this->baseDir() . '/schema';
    }

    public function dbDsn(): string
    {
        return Extractor::requireString($this->d, 'dbDsn', 'sqlite://' . $this->baseDir() . '/data/db.sqlite');
    }

    public function dbUser(): ?string
    {
        return Extractor::optionalString($this->d, 'dbUser');
    }

    public function dbPass(): ?string
    {
        return Extractor::optionalString($this->d, 'dbPass');
    }

    public function tlsCa(): ?string
    {
        return Extractor::optionalString($this->d, 'tlsCa');
    }

    public function tlsCert(): ?string
    {
        return Extractor::optionalString($this->d, 'tlsCert');
    }

    public function tlsKey(): ?string
    {
        return Extractor::optionalString($this->d, 'tlsKey');
    }

    /**
     * @return array<mixed>
     */
    public function dbOptions(): array
    {
        $dbOptions = [];
        if (str_starts_with($this->dbDsn(), 'mysql:')) {
            // MySQL / MariaDB
            if (null !== $tlsCa = $this->tlsCa()) {
                $dbOptions[PDO::MYSQL_ATTR_SSL_CA] = $tlsCa;
                // it seems this flag is enabled by default, but I don't think
                // it hurts to explicity set it here...
                $dbOptions[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = 1;
            }
            if (null !== $tlsCert = $this->tlsCert()) {
                $dbOptions[PDO::MYSQL_ATTR_SSL_CERT] = $tlsCert;
            }
            if (null !== $tlsKey = $this->tlsKey()) {
                $dbOptions[PDO::MYSQL_ATTR_SSL_KEY] = $tlsKey;
            }
        }

        return $dbOptions;
    }
}
