<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal;

use Vpn\Portal\Cfg\Config;
use Vpn\Portal\Exception\ConnectionHookException;

final class ConnectionHooks implements ConnectionHookInterface
{
    /** @var array<ConnectionHookInterface> */
    private array $connectionHookList = [];

    public function __construct(private LoggerInterface $logger) {}

    public function add(ConnectionHookInterface $connectionHook): void
    {
        $this->connectionHookList[] = $connectionHook;
    }

    #[\Override]
    public function connect(NodeInfo $nodeInfo, string $userId, string $profileId, string $vpnProto, string $connectionId, string $ipFour, string $ipSix, ?string $originatingIp, ?IpInfo $ipInfo): void
    {
        foreach ($this->connectionHookList as $connectionHook) {
            try {
                $connectionHook->connect($nodeInfo, $userId, $profileId, $vpnProto, $connectionId, $ipFour, $ipSix, $originatingIp, $ipInfo);
            } catch (ConnectionHookException $e) {
                $this->logger->warning(\sprintf('[%s,%s] %s', __METHOD__, $connectionHook::class, $e->getMessage()));

                throw $e;
            }
        }
    }

    #[\Override]
    public function disconnect(NodeInfo $nodeInfo, string $userId, string $profileId, string $vpnProto, string $connectionId, string $ipFour, string $ipSix, int $bytesIn, int $bytesOut): void
    {
        foreach ($this->connectionHookList as $connectionHook) {
            try {
                $connectionHook->disconnect(
                    $nodeInfo,
                    $userId,
                    $profileId,
                    $vpnProto,
                    $connectionId,
                    $ipFour,
                    $ipSix,
                    $bytesIn,
                    $bytesOut
                );
            } catch (ConnectionHookException $e) {
                // we can't do anything, so log it, but let it go...
                $this->logger->warning(\sprintf('[%s,%s] %s', __METHOD__, $connectionHook::class, $e->getMessage()));
            }
        }
    }

    public static function init(Config $config, Storage $storage, LoggerInterface $logger): self
    {
        $connectionHooks = new self($logger);
        $connectionHooks->add(new ConnectionLogHook($storage));
        if ($config->logConfig()->syslogConnectionEvents()) {
            $connectionHooks->add(new LogConnectionHook($storage, $logger, $config->logConfig()));
        }
        if (null !== $connectScriptPath = $config->connectScriptPath()) {
            $connectionHooks->add(new ScriptConnectionHook($connectScriptPath));
        }

        return $connectionHooks;
    }
}
