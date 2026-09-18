<?php declare(strict_types=1); ?>
<?php /** @var \Vpn\Portal\Tpl $this */ ?>
<?php /** @var \Vpn\Portal\ServerInfo $serverInfo */ ?>
<?php /** @var bool $wSupport */ ?>
<?php /** @var bool $oSupport */ ?>
<?php /** @var array<\Vpn\Portal\Cfg\ProfileConfig> $profileConfigList */ ?>
<?php /** @var array<array{node_number:int,node_url:string,node_info:?array{connection_count:int,rel_load_average:array<int>,load_average:array<float>,cpu_count:int,node_uptime:int,maintenance_mode:bool,v:string}}> $nodeInfoList */ ?>
<?php /** @var string $portalVersion */ ?>
<?php /** @var array{global_problems:array<string>,profile_problems:array<string,array<string>>} $problemList */ ?>
<?php /** @var string $dbDriverName */ ?>
<?php $this->layout('base', ['activeItem' => 'info', 'pageTitle' => $this->t('Info')]); ?>
<?php $this->start('content'); ?>
    <h2><?= $this->t('Server'); ?></h2>
    <table class="tbl">
        <tbody>
            <tr>
                <th><?= $this->t('Version'); ?></th>
                <td>v<?= $this->e($portalVersion); ?></td>
            </tr>

            <tr>
                <th><?= $this->t('Node(s)'); ?></th>
                <td>
<?php foreach ($nodeInfoList as $nodeInfo): ?>
<?php if (null === $nodeInfo['node_info']): ?>
<?php /** Offline */ ?>
                    <span class="error" title="<?= $this->e($nodeInfo['node_url']); ?>">
                        <small>#<?= $this->e((string) $nodeInfo['node_number']); ?></small>
                        <br>
                        <?= $this->t('Offline'); ?>
                        <br>
                        <small><?= $this->t('#Connections:');?> <?= $this->t('N/A');?></small>
                        <br>
                        <small><?= $this->t('CPU Usage:'); ?> <?= $this->t('N/A'); ?></small>
                    </span>
<?php else: ?>
<?php /** Online */ ?>
<?php if ($nodeInfo['node_info']['maintenance_mode']): ?>
<?php /** Maintenance Mode */ ?>
                    <span class="plain" title="<?= $this->e($nodeInfo['node_url']); ?> [<?= implode(', ', $nodeInfo['node_info']['rel_load_average']); ?>] #CPU=<?= $this->e((string) $nodeInfo['node_info']['cpu_count']); ?> V=<?= $this->e($nodeInfo['node_info']['v']);?>">
                        <small>#<?= $this->e((string) $nodeInfo['node_number']); ?></small>
                        <br>
                        <?= $this->t('Maintenance'); ?>
<?php if (str_starts_with($nodeInfo['node_url'], 'https://')): ?>🔒<?php endif; ?>
                        <br>
                        <small><?= $this->t('#Connections:');?> <?= $this->e(\sprintf('%d', $nodeInfo['node_info']['connection_count'])); ?></small>
                        <br>
                        <small><?= $this->t('CPU Usage:'); ?> <?= $this->e(\sprintf('%d%%', $nodeInfo['node_info']['rel_load_average'][0])); ?></small>
                    </span>
<?php elseif ($nodeInfo['node_info']['rel_load_average'][0] >= 75): ?>
<?php /** High CPU Usage */ ?>
                    <span class="warning" title="<?= $this->e($nodeInfo['node_url']); ?> [<?= implode(', ', $nodeInfo['node_info']['rel_load_average']); ?>] #CPU=<?= $this->e((string) $nodeInfo['node_info']['cpu_count']); ?> V=<?= $this->e($nodeInfo['node_info']['v']);?>">
                        <small>#<?= $this->e((string) $nodeInfo['node_number']); ?></small>
                        <br>
                        <?= $this->t('Online'); ?>
<?php if (str_starts_with($nodeInfo['node_url'], 'https://')): ?>🔒<?php endif; ?>
                        <br>
                        <small><?= $this->t('#Connections:');?> <?= $this->e(\sprintf('%d', $nodeInfo['node_info']['connection_count'])); ?></small>
                        <br>
                        <small><?= $this->t('CPU Usage:'); ?> <?= $this->e(\sprintf('%d%%', $nodeInfo['node_info']['rel_load_average'][0])); ?></small>
                    </span>
<?php else: ?>
<?php /** Normal CPU Usage */ ?>
                    <span class="success" title="<?= $this->e($nodeInfo['node_url']); ?> [<?= implode(', ', $nodeInfo['node_info']['rel_load_average']); ?>] #CPU=<?= $this->e((string) $nodeInfo['node_info']['cpu_count']); ?> V=<?= $this->e($nodeInfo['node_info']['v']);?>">
                        <small>#<?= $this->e((string) $nodeInfo['node_number']); ?></small><br><?= $this->t('Online'); ?>
<?php if (str_starts_with($nodeInfo['node_url'], 'https://')): ?>🔒<?php endif; ?>
                        <br>
                        <small><?= $this->t('#Connections:');?> <?= $this->e(\sprintf('%d', $nodeInfo['node_info']['connection_count'])); ?></small>
                        <br>
                        <small><?= $this->t('CPU Usage:'); ?> <?= $this->e(\sprintf('%d%%', $nodeInfo['node_info']['rel_load_average'][0])); ?></small>
                    </span>
<?php endif; ?>
<?php endif; ?>
<?php endforeach; ?>
                </td>
            </tr>

            <tr>
                <th><?= $this->t('Profile(s)'); ?></th>
                <td>
                    <ul>
<?php foreach ($profileConfigList as $profileConfig): ?>
                        <li><a href="#<?= $this->e($profileConfig->profileId()); ?>"><?= $this->e($profileConfig->displayName()); ?></a></li>
<?php endforeach; ?>
                    </ul>
                </td>
            </tr>

<?php if (0 !== \count($problemList['global_problems'])): ?>
            <tr>
                <th><?= $this->t('Issues'); ?></th>
                <td>
<?php foreach ($problemList['global_problems'] as $p):?>
                        <span class="warning"><?= $this->e($p); ?></span>
<?php endforeach; ?>
                </td>
            </tr>
<?php endif; ?>

        </tbody>
    </table>

    <details><summary><?= $this->t('More'); ?></summary>
    <table class="tbl">
        <tbody>
<?php if ($oSupport): ?>
            <tr>
                <th><?= $this->t('OpenVPN'); ?></th>
                <td>
                    <dl>
                        <dt><?= $this->t('CA'); ?></dt>
                        <dd>
                            <dl>
                                <dt><?= $this->t('Created On'); ?></dt><dd><span title="<?= $this->d($serverInfo->ca()->caCert()->validFrom()); ?>"><?= $this->d($serverInfo->ca()->caCert()->validFrom(), 'Y-m-d'); ?></span></dd>
                                <dt><?= $this->t('Expires On'); ?></dt><dd><span title="<?= $this->d($serverInfo->ca()->caCert()->validTo()); ?>"><?= $this->d($serverInfo->ca()->caCert()->validTo(), 'Y-m-d'); ?></span></dd>
                                <dt><?= $this->t('Fingerprint'); ?></dt><dd><code><?= $this->e(implode(' ', str_split($serverInfo->ca()->caCert()->fingerprint(), 4))); ?></code></dd>
                            </dl>
                        </dd>
<?php if (null !== $setMtu = $serverInfo->openVpnConfig()->setMtu()): ?>
                        <dt><?= $this->t('MTU'); ?></dt><dd><code><?= $this->e((string) $setMtu); ?></code></dd>
<?php endif; ?>
<?php if ($serverInfo->openVpnConfig()->singleProcess()): ?>
                        <dt><?= $this->t('Single Process'); ?></dt><dd><?= $this->t('Yes');?></dd>
<?php endif; ?>
                    </dl>
                </td>
            </tr>
<?php endif; ?>
<?php if ($wSupport): ?>
            <tr>
                <th><?= $this->t('WireGuard'); ?></th>
                <td>
                    <dl>
                        <dt><?= $this->t('Transport Protocol(s)');?></dt>
                        <dd>
<?php if ($serverInfo->wgConfig()->enableProxy()): ?>
<?php if ($serverInfo->wgConfig()->onlyProxy()): ?>
                            <span class="plain"><?= $this->t('TCP');?></span>
<?php else: ?>
                            <span class="plain"><?= $this->t('UDP');?></span>
                            <span class="plain"><?= $this->t('TCP');?></span>
<?php endif; ?>
<?php else: ?>
                            <span class="plain"><?= $this->t('UDP');?></span>
<?php endif; ?>
                        </dd>
<?php if (!$serverInfo->wgConfig()->onlyProxy()): ?>
                        <dt><?= $this->t('Port'); ?></dt><dd><span class="plain">udp/<?= $this->e((string) $serverInfo->wgConfig()->listenPort()); ?></span></dd>
<?php endif; ?>
<?php if ($serverInfo->wgConfig()->enableProxy() && null !== $proxyUrl = $serverInfo->wgConfig()->proxyUrl()): ?>
                        <dt><?= $this->t('Proxy URL'); ?></dt><dd><code><?= $this->e($proxyUrl); ?></code></dd>
<?php endif; ?>
<?php if (null !== $setMtu = $serverInfo->wgConfig()->setMtu()): ?>
                        <dt><?= $this->t('MTU'); ?></dt><dd><code><?= $this->e((string) $setMtu); ?></code></dd>
<?php endif; ?>
                    </dl>
                </td>
            </tr>
<?php endif; ?>
            <tr>
                <th><?= $this->t('OAuth'); ?></th>
                <td>
                    <dl>
                        <dt><?= $this->t('Public Key'); ?></dt><dd><code><?= $this->e($serverInfo->oauthPublicKey()); ?></code></dd>
                    </dl>
                </td>
            </tr>
<?php if ('sqlite' !== $dbDriverName): ?>
            <tr>
                <th><?= $this->t('Database'); ?></th>
                <td>
                    <dl>
                        <dt><?= $this->t('Driver');?></dt><dd><code><?= $this->e($dbDriverName);?></code></dd>
                    </dl>
                </td>
            </tr>
<?php endif; ?>
        </tbody>
    </table>
    </details>

    <h2><?= $this->t('Profile(s)'); ?></h2>
<?php foreach ($profileConfigList as $profileConfig): ?>
    <h3 id="<?= $this->e($profileConfig->profileId()); ?>"><?= $this->e($profileConfig->displayName()); ?></h3>
    <table class="tbl">
        <tbody>
            <tr>
                <th></th>
                <td>
<?php if ($profileConfig->defaultGateway()): ?>
                    <span class="plain"><?= $this->t('Default Gateway'); ?></span>
<?php endif; ?>
                </td>
            </tr>

<?php if ($profileConfig->oSupport() && $profileConfig->wSupport()) : ?>
            <tr><th><?= $this->t('Preferred Protocol'); ?></th><td>
<?php if ('openvpn' === $profileConfig->preferredProto()): ?>
                <span class="plain openvpn"><?= $this->t('OpenVPN'); ?></span>
<?php endif; ?>
<?php if ('wireguard' === $profileConfig->preferredProto()): ?>
        <span class="plain wireguard"><?= $this->t('WireGuard'); ?></span>
<?php endif; ?>
            </td></tr>
<?php endif; ?>

            <tr><th><?= $this->t('Profile ID'); ?></th><td>
            <span class="plain"><code><?= $this->e($profileConfig->profileId()); ?></code></span>
            </td></tr>

<?php if (0 !== $profilePriority = $profileConfig->profilePriority()): ?>
            <tr><th><?= $this->t('Priority'); ?></th><td>
            <span class="plain"><?= $this->e((string) $profilePriority); ?></span>
            </td></tr>
<?php endif; ?>

            <tr><th><?= $this->t('Hostname'); ?></th><td>
<?php foreach ($profileConfig->onNode() as $nodeNumber): ?>
            <span class="plain"><code><?= $this->e($profileConfig->hostName($nodeNumber)); ?></code></span>
<?php endforeach; ?>
            </td></tr>
<?php if (1 !== \count($profileConfig->onNode()) || 'http://localhost:41194' !== $profileConfig->nodeUrl($profileConfig->onNode()[0])): ?>
            <tr><th><?= $this->t('Node URL'); ?></th><td>
<?php foreach ($profileConfig->onNode() as $nodeNumber): ?>
            <span class="plain"><code><?= $this->e($profileConfig->nodeUrl($nodeNumber)); ?></code></span>
<?php endforeach; ?>
            </td></tr>
<?php endif; ?>

<?php if (0 !== \count($profileConfig->dnsSearchDomainList())): ?>
            <tr><th><?= $this->t('DNS Search Domain(s)'); ?></th>
            <td>
<?php foreach ($profileConfig->dnsSearchDomainList() as $dnsSearchDomain): ?>
                <span class="plain"><code><?= $this->e($dnsSearchDomain); ?></code></span>
<?php endforeach; ?>
            </td>
            </tr>
<?php endif; ?>

<?php if (0 !== \count($profileConfig->routeList())): ?>
            <tr><th><?= $this->t('Route(s)'); ?></th>
            <td>
<?php foreach ($profileConfig->routeList() as $route): ?>
                    <span class="plain"><code><?= $this->e($route); ?></code></span>
<?php endforeach; ?>
            </td>
            </tr>
<?php endif; ?>

<?php if (0 !== \count($profileConfig->excludeRouteList())): ?>
            <tr><th><?= $this->t('Excluded Route(s)'); ?></th>
            <td>
<?php foreach ($profileConfig->excludeRouteList() as $excludeRoute): ?>
                    <span class="plain"><code><?= $this->e($excludeRoute); ?></code></span>
<?php endforeach; ?>
            </td>
            </tr>
<?php endif; ?>

<?php if (0 !== \count($profileConfig->dnsServerList())): ?>
            <tr><th><?= $this->t('DNS Server(s)'); ?></th>
            <td>
<?php foreach ($profileConfig->dnsServerList() as $dnsServer): ?>
                    <span class="plain"><code><?= $this->e($dnsServer); ?></code></span>
<?php endforeach; ?>
            </td>
            </tr>
<?php endif; ?>

<?php if (null !== $aclPermissionList = $profileConfig->aclPermissionList()): ?>
            <tr><th><?= $this->t('ACL Permission List'); ?></th>
            <td>
<?php if (0 === \count($aclPermissionList)): ?>
<span class="warning"><?= $this->t('No Permission(s)'); ?></span>
<?php else: ?>
<?php foreach ($aclPermissionList as $aclPermission): ?>
                    <span class="plain"><code><?= $this->e($aclPermission); ?></code></span>
<?php endforeach; ?>
            </td>
<?php endif; ?>
            </tr>
<?php endif; ?>

<?php if ($profileConfig->oSupport()):?>
        <tr><th colspan="2" class="openvpn"><?= $this->t('OpenVPN'); ?></th></tr>
<?php if ($profileConfig->oEnableLog() || $profileConfig->oBlockLan()):?>
            <tr>
                <th></th>
                <td>
<?php if ($profileConfig->oEnableLog()): ?>
                    <span class="plain"><?= $this->t('OpenVPN Server Log'); ?></span>
<?php endif; ?>

<?php if ($profileConfig->oBlockLan()): ?>
                    <span class="plain"><?= $this->t('Block LAN'); ?></span>
<?php endif; ?>
                </td>
            </tr>
<?php endif; ?>
            <tr><th><?= $this->t('IPv4 Prefix'); ?></th><td>
<?php foreach ($profileConfig->onNode() as $nodeNumber): ?>
            <span class="plain"><code><?= $this->e((string) $profileConfig->oRangeFour($nodeNumber)); ?></code></span>
<?php endforeach; ?>
            </td></tr>
            <tr><th><?= $this->t('IPv6 Prefix'); ?></th><td>
<?php foreach ($profileConfig->onNode() as $nodeNumber): ?>
            <span class="plain"><code><?= $this->e((string) $profileConfig->oRangeSix($nodeNumber)); ?></code></span>
<?php endforeach; ?>
            </td></tr>
<?php if (0 !== \count($profileConfig->oUdpPortList()) || 0 !== \count($profileConfig->oTcpPortList())): ?>
            <tr><th><?= $this->t('Ports'); ?></th>
            <td>
<?php foreach ($profileConfig->oUdpPortList() as $udpPort): ?>
                    <span class="plain"><code><?= $this->e(\sprintf('udp/%s', $udpPort)); ?></code></span>
<?php endforeach; ?>
<?php foreach ($profileConfig->oTcpPortList() as $tcpPort): ?>
                    <span class="plain"><code><?= $this->e(\sprintf('tcp/%s', $tcpPort)); ?></code></span>
<?php endforeach; ?>
            </td>
            </tr>
<?php endif; ?>

<?php if (0 !== \count($profileConfig->oExposedUdpPortList()) || 0 !== \count($profileConfig->oExposedTcpPortList())): ?>
            <tr><th><?= $this->t('Offered Ports'); ?></th>
            <td>
<?php foreach ($profileConfig->oExposedUdpPortList() as $oExposedUdpPort): ?>
                    <span class="plain"><code><?= $this->e(\sprintf('udp/%s', $oExposedUdpPort)); ?></code></span>
<?php endforeach; ?>
<?php foreach ($profileConfig->oExposedTcpPortList() as $oExposedTcpPort): ?>
                    <span class="plain"><code><?= $this->e(\sprintf('tcp/%s', $oExposedTcpPort)); ?></code></span>
<?php endforeach; ?>
            </td>
            </tr>
<?php endif; ?>
<?php endif; ?>

<?php if ($profileConfig->wSupport()):?>
        <tr><th colspan="2" class="wireguard"><?= $this->t('WireGuard'); ?></th>
<?php if ($profileConfig->wKeepAlive()):?>
            <tr>
                <th></th>
                <td>
                    <span class="plain"><?= $this->t('Keepalive'); ?></span>
                </td>
            </tr>
<?php endif; ?>
            <tr><th><?= $this->t('IPv4 Prefix'); ?></th><td>
<?php foreach ($profileConfig->onNode() as $nodeNumber): ?>
            <span class="plain"><code><?= $this->e((string) $profileConfig->wRangeFour($nodeNumber)); ?></code></span>
<?php endforeach; ?>
            </td></tr>
            <tr><th><?= $this->t('IPv6 Prefix'); ?></th><td>
<?php foreach ($profileConfig->onNode() as $nodeNumber): ?>
            <span class="plain"><code><?= $this->e((string) $profileConfig->wRangeSix($nodeNumber)); ?></code></span>
<?php endforeach; ?>
            </td></tr>
<?php endif; ?>

<?php if (\array_key_exists($profileConfig->profileId(), $problemList['profile_problems']) && 0 !== \count($problemList['profile_problems'][$profileConfig->profileId()])): ?>
            <tr>
                <th colspan="2" class="issues"><?= $this->t('Issues'); ?></th>
            </tr>
            <tr>
                <th></th>
                <td>
<?php foreach ($problemList['profile_problems'][$profileConfig->profileId()] as $p):?>
                        <span class="warning"><?= $this->e($p); ?></span>
<?php endforeach; ?>
                </td>
            </tr>
<?php endif; ?>
        </tbody>
    </table>
<?php endforeach; ?>
<?php $this->stop('content'); ?>
