<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal;

use PDO;
use PDOException;
use RangeException;
use RuntimeException;
use Throwable;
use Vpn\Portal\Exception\MigrationException;

final class Migration
{
    public static function run(PDO $db, string $schemaDir, string $schemaVersion, bool $autoInit, bool $autoMigrate): void
    {
        $currentVersion = self::getCurrentVersion($db);
        if ($schemaVersion === $currentVersion) {
            // all good, initialized and up to date
            return;
        }

        if (null === $currentVersion) {
            // not yet initialized
            if (!$autoInit) {
                throw new MigrationException('database not initialized');
            }

            self::runQueries($db, self::getQueriesFromFile(self::getNameForDriver($db, \sprintf('%s/%s.schema', $schemaDir, $schemaVersion))));
            self::createVersionTable($db, $schemaVersion);

            return;
        }

        // migration required
        if (!$autoMigrate) {
            throw new MigrationException(\sprintf('database migration required: "%s" --> "%s"', $currentVersion, $schemaVersion));
        }

        $migrationFileList = glob(self::getNameForDriver($db, \sprintf('%s/*_*.migration', $schemaDir)));
        if (false === $migrationFileList) {
            throw new RuntimeException(\sprintf('unable to read schema directory "%s"', $schemaDir));
        }

        // prepare the list, depending on whether we "migrate" or "rollback"
        $migrationFileList = self::filterMigrationList($migrationFileList, $currentVersion, $schemaVersion);

        self::lock($db);

        try {
            foreach ($migrationFileList as $migrationFile) {
                $migrationVersion = self::getNameForDriver($db, basename($migrationFile, '.migration'));
                [$fromVersion, $toVersion] = self::validateMigrationVersion($migrationVersion);
                if ($fromVersion === $currentVersion && $fromVersion !== $schemaVersion) {
                    // get the queries before we start the transaction as we
                    // ONLY want to deal with "PDOExceptions" once the
                    // transaction started...
                    $queryList = self::getQueriesFromFile(self::getNameForDriver($db, \sprintf('%s/%s.migration', $schemaDir, $migrationVersion)));

                    try {
                        self::dbBeginTransaction($db);
                        $db->exec(\sprintf("DELETE FROM version WHERE current_version = '%s'", $fromVersion));
                        self::runQueries($db, $queryList);
                        $db->exec(\sprintf("INSERT INTO version (current_version) VALUES('%s')", $toVersion));
                        self::dbCommit($db);
                        $currentVersion = $toVersion;
                    } catch (PDOException $e) {
                        self::dbRollback($db);

                        throw $e;
                    }
                }
            }
        } catch (Throwable $e) {
            // something went wrong that was not related to SQL queries
            self::unlock($db);

            throw $e;
        }

        self::unlock($db);

        $currentVersion = self::getCurrentVersion($db);
        if ($currentVersion !== $schemaVersion) {
            throw new MigrationException(\sprintf('unable to migrate to database schema version "%s", not all required migrations are available', $schemaVersion));
        }
    }

    /**
     * Gets the current version of the database schema.
     */
    public static function getCurrentVersion(PDO $db): ?string
    {
        try {
            $stmt = $db->prepare('SELECT current_version FROM version');
            $stmt->execute();
            $resultRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!\is_array($resultRow)) {
                return null;
            }
            $currentVersion = self::requireString($resultRow, 'current_version');
            self::validateSchemaVersion($currentVersion);

            return $currentVersion;
        } catch (PDOException) {
            // the "version" table probably does not exist (yet)
            return null;
        }
    }

    /**
     * See if there is a file available specifically for this DB driver. If
     * so, use it, if not fallback to the "default".
     */
    private static function getNameForDriver(PDO $db, string $fileName): string
    {
        $driverName = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!\is_string($driverName)) {
            throw new MigrationException('unable to determine database driver');
        }
        if (file_exists($fileName . '.' . $driverName)) {
            return $fileName . '.' . $driverName;
        }

        return $fileName;
    }

    private static function lock(PDO $db): void
    {
        // this creates a "lock" as only one process will succeed in this...
        $db->exec('CREATE TABLE _migration_in_progress (dummy INTEGER)');

        if ('sqlite' === $db->getAttribute(PDO::ATTR_DRIVER_NAME)) {
            $db->exec('PRAGMA foreign_keys = OFF');
        }
    }

    private static function unlock(PDO $db): void
    {
        if ('sqlite' === $db->getAttribute(PDO::ATTR_DRIVER_NAME)) {
            $db->exec('PRAGMA foreign_keys = ON');
        }

        // release "lock"
        $db->exec('DROP TABLE _migration_in_progress');
    }

    private static function createVersionTable(PDO $db, string $schemaVersion): void
    {
        $db->exec('CREATE TABLE IF NOT EXISTS version (current_version TEXT NOT NULL)');
        // we know that schemaVersion is a 10 digit string as per
        // validateSchemaVersion
        $db->exec(\sprintf("INSERT INTO version (current_version) VALUES('%s')", $schemaVersion));
    }

    /**
     * @param array<string> $queryList
     */
    private static function runQueries(PDO $db, array $queryList): void
    {
        foreach ($queryList as $dbQuery) {
            if (0 === \strlen(trim($dbQuery))) {
                // ignore empty line(s)
                continue;
            }
            $db->exec($dbQuery);
        }
    }

    /**
     * @return array<string>
     */
    private static function getQueriesFromFile(string $filePath): array
    {
        if (false === $fileContent = file_get_contents($filePath)) {
            throw new RuntimeException(\sprintf('unable to read "%s"', $filePath));
        }

        return explode(';', $fileContent);
    }

    private static function validateSchemaVersion(string $schemaVersion): void
    {
        if (1 !== preg_match('/^[0-9]{10}$/', $schemaVersion)) {
            throw new RangeException('schemaVersion must be 10 a digit string');
        }
    }

    /**
     * @return array{0:string,1:string}
     */
    private static function validateMigrationVersion(string $migrationVersion): array
    {
        if (1 !== preg_match('/^[0-9]{10}_[0-9]{10}$/', $migrationVersion)) {
            throw new RangeException('migrationVersion must be two times a 10 digit string separated by an underscore');
        }

        return [
            substr($migrationVersion, 0, 10),
            substr($migrationVersion, 11),
        ];
    }

    private static function dbBeginTransaction(PDO $db): void
    {
        // MySQL does not allow for CREATE/DROP TABLE inside a transaction, it
        // will commit by itself.
        // @see https://mariadb.com/kb/en/sql-statements-that-cause-an-implicit-commit/
        if ('mysql' !== $db->getAttribute(PDO::ATTR_DRIVER_NAME)) {
            $db->beginTransaction();
        }
    }

    private static function dbCommit(PDO $db): void
    {
        // MySQL does not allow for CREATE/DROP TABLE inside a transaction, it
        // will commit by itself.
        // @see https://mariadb.com/kb/en/sql-statements-that-cause-an-implicit-commit/
        if ('mysql' !== $db->getAttribute(PDO::ATTR_DRIVER_NAME)) {
            $db->commit();
        }
    }

    private static function dbRollback(PDO $db): void
    {
        // MySQL does not allow for CREATE/DROP TABLE inside a transaction, it
        // will commit by itself.
        // @see https://mariadb.com/kb/en/sql-statements-that-cause-an-implicit-commit/
        if ('mysql' !== $db->getAttribute(PDO::ATTR_DRIVER_NAME)) {
            $db->rollBack();
        }
    }

    /**
     * @param array<string> $migrationFileList
     *
     * @return array<string>
     */
    private static function filterMigrationList(array $migrationFileList, string $fromVersion, string $toVersion): array
    {
        if ($fromVersion === $toVersion) {
            return [];
        }

        if ($fromVersion < $toVersion) {
            // keep only entries from migration list where FROM < TO
            // then sort
            foreach ($migrationFileList as $k => $v) {
                [$f, $t] = self::fromTo(basename($v, '.migration'));
                if ($f > $t) {
                    unset($migrationFileList[$k]);
                }
            }
            sort($migrationFileList);

            return $migrationFileList;
        }

        // keep only entries from migration list where FROM > TO
        // then sort
        foreach ($migrationFileList as $k => $v) {
            [$f, $t] = self::fromTo(basename($v, '.migration'));
            if ($f < $t) {
                unset($migrationFileList[$k]);
            }
        }
        rsort($migrationFileList);

        return $migrationFileList;
    }

    /**
     * @return array{0:string,1:string}
     */
    private static function fromTo(string $fromTo): array
    {
        $e = explode('_', $fromTo, 2);
        if (2 !== \count($e)) {
            throw new MigrationException(\sprintf('invalid migration file name "%s"', $fromTo));
        }

        return $e;
    }

    /**
     * @param array<mixed> $inData
     */
    private static function requireString(array $inData, string $k): string
    {
        if (!\array_key_exists($k, $inData)) {
            throw new MigrationException(\sprintf('key "%s" does not exist', $k));
        }
        if (\is_string($inData[$k])) {
            return $inData[$k];
        }

        throw new MigrationException(\sprintf('value of key "%s" is not of type `string`', $k));
    }
}
