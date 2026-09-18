<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

require_once \dirname(__DIR__) . '/vendor/autoload.php';
$baseDir = \dirname(__DIR__);

use Vpn\Portal\Cfg\Config;
use Vpn\Portal\Cfg\DbConfig;
use Vpn\Portal\Migration;
use Vpn\Portal\Storage;

try {
    $doInit = false;
    $schemaVersion = null;
    $doMigrate = false;
    $dbDsn = null;
    $dbUser = null;
    $dbPass = null;
    $setJournalMode = null;
    for ($i = 1; $i < $argc; ++$i) {
        if ('--init' === $argv[$i]) {
            $doInit = true;

            continue;
        }

        if ('--version' === $argv[$i]) {
            if ($i + 1 < $argc) {
                $schemaVersion = $argv[$i + 1];
            }

            continue;
        }

        if ('--migrate' === $argv[$i]) {
            $doMigrate = true;

            continue;
        }

        if ('--dsn' === $argv[$i]) {
            if ($i + 1 < $argc) {
                $dbDsn = $argv[$i + 1];
            }

            continue;
        }

        if ('--user' === $argv[$i]) {
            if ($i + 1 < $argc) {
                $dbUser = $argv[$i + 1];
            }

            continue;
        }

        if ('--pass' === $argv[$i]) {
            if ($i + 1 < $argc) {
                $dbPass = $argv[$i + 1];
            }

            continue;
        }

        if ('--set-journal-mode' === $argv[$i]) {
            if ($i + 1 < $argc) {
                $setJournalMode = $argv[$i + 1];
                if (!\in_array($setJournalMode, ['delete', 'wal'], true)) {
                    throw new Exception(\sprintf('journal mode "%s" not supported', $setJournalMode));
                }
            }

            continue;
        }

        if ('--help' === $argv[$i]) {
            echo 'SYNTAX: ' . $argv[0] . ' [--init] [--version VERSION] [--migrate] [--set-journal-mode JOURNAL_MODE] [--dsn DSN] [--user USER] [--pass PASS]' . \PHP_EOL;

            exit(0);
        }
    }

    $config = Config::fromFile($baseDir . '/config/config.php');
    $dbConfig = $config->dbConfig($baseDir);

    // if dbDsn, dbUser or dbPass are provided, use those
    if (null !== $dbDsn || null !== $dbUser || null !== $dbPass) {
        $dbConfig = new DbConfig(
            array_merge(
                [
                    'baseDir' => $baseDir,
                ],
                null !== $dbDsn ? ['dbDsn' => $dbDsn] : [],
                null !== $dbUser ? ['dbUser' => $dbUser] : [],
                null !== $dbPass ? ['dbPass' => $dbPass] : []
            )
        );
    }

    $storage = new Storage(
        dbConfig: $dbConfig,
        // disable auto database initialization and/or migration with SQLite...
        sqliteAutoInitMigrate: false
    );

    $driverName = $storage->driverName();
    if (!$doInit && !$doMigrate) {
        echo 'Database Driver        : ' . $driverName . \PHP_EOL;
        if ('sqlite' === $driverName) {
            $currentJournalMode = $storage->getJournalMode();
            if (null !== $setJournalMode) {
                if ($currentJournalMode !== $setJournalMode) {
                    $storage->setJournalMode($setJournalMode);
                    if ($setJournalMode !== $currentJournalMode = $storage->getJournalMode()) {
                        throw new Exception(\sprintf('we were unable to set "%s" journal mode', $setJournalMode));
                    }
                }
            }
            echo 'Journal Mode           : ' . $currentJournalMode . \PHP_EOL;
        }

        // show database status information
        $currentVersion = Migration::getCurrentVersion($storage->dbPdo());
        $latestVersion = Storage::CURRENT_SCHEMA_VERSION;
        echo 'Current Schema Version : ' . ($currentVersion ?? 'N/A') . \PHP_EOL;
        echo 'Latest Schema Version  : ' . $latestVersion . \PHP_EOL;

        if ($currentVersion === $latestVersion) {
            echo 'Status                 : **OK**' . \PHP_EOL;

            exit(0);
        }
        if (null === $currentVersion) {
            echo 'Status                 : **Initialization Required** (use --init)' . \PHP_EOL;

            exit(1);
        }

        echo 'Status                 : **Migration Required** (use --migrate)' . \PHP_EOL;

        exit(1);
    }

    Migration::run(
        db: $storage->dbPdo(),
        schemaDir: $dbConfig->schemaDir(),
        schemaVersion: $schemaVersion ?? Storage::CURRENT_SCHEMA_VERSION,
        autoInit: $doInit,
        autoMigrate: $doMigrate
    );
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . \PHP_EOL;

    exit(1);
}
