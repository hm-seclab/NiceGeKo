<?php declare(strict_types=1); ?>
<?php /** @var \Vpn\Portal\Tpl $this */ ?>
<?php /** @var string $requestRoot */ ?>
<?php /** @var array<array{profileId:string,displayName:string,wSupport:bool,wList:array<array{userId:string,displayName:string,ipList:array<string>}>,wActive:int,wAllocated:int,wMax:int,oSupport:bool,oList:array<array{userId:string,displayName:string,ipList:array<string>}>,oActive:int,oAllocated:int,oMax:int}> $profileConnectionInfoList */ ?>

<?php $this->layout('base', ['activeItem' => 'connections', 'pageTitle' => $this->t('Connections')]); ?>
<?php $this->start('content'); ?>

<table class="tbl">
    <thead>
        <tr>
            <th><?= $this->t('Profile');?></th>
            <th><?= $this->t('#Connections');?></th>
        </tr>
    </thead>
    <tbody>
<?php foreach ($profileConnectionInfoList as $profileConnectionInfo): ?>
        <tr>
            <td>
                <a href="#<?= $this->e($profileConnectionInfo['profileId']);?>"><?= $this->e($profileConnectionInfo['displayName']);?></a>
            </td>
            <td>
                <?= $this->e((string) ($profileConnectionInfo['wActive'] + $profileConnectionInfo['oActive']));?>
            </td>
        </tr>
<?php endforeach; ?>
    </tbody>
</table>

<?php foreach ($profileConnectionInfoList as $profileConnectionInfo): ?>
<h2 id="<?= $this->e($profileConnectionInfo['profileId']); ?>"><?= $this->e($profileConnectionInfo['displayName']); ?></h2>
<table class="tbl">
    <thead>
        <tr>
            <th><?= $this->t('Protocol');?></th>
            <th><?= $this->t('#Connections');?></th>
            <th title="<?= $this->t('Number of allocated IP addresses for WireGuard clients that are online, or may return in the near future. For OpenVPN this number is always equal to the number of connections.');?>"><?= $this->t('#Allocated IPs');?></th>
            <th><?= $this->t('Max #Connections');?></th>
        </tr>
    </thead>
    <tbody>
<?php if ($profileConnectionInfo['wSupport']): ?>
        <tr>
            <td class="wireguard"><?= $this->t('WireGuard');?></td>
            <td><?= $this->e((string) $profileConnectionInfo['wActive']);?></td>
            <td><?= $this->e((string) $profileConnectionInfo['wAllocated']);?></td>
            <td><?= $this->e((string) $profileConnectionInfo['wMax']);?></td>
        </tr>
<?php endif;?>
<?php if ($profileConnectionInfo['oSupport']): ?>
        <tr>
            <td class="openvpn"><?= $this->t('OpenVPN');?></td>
            <td><?= $this->e((string) $profileConnectionInfo['oActive']);?></td>
            <td><?= $this->e((string) $profileConnectionInfo['oAllocated']);?></td>
            <td><?= $this->e((string) $profileConnectionInfo['oMax']);?></td>
        </tr>
<?php endif; ?>
    </tbody>
</table>
<?php if (0 !== \count($profileConnectionInfo['wList']) + \count($profileConnectionInfo['oList'])): ?>
<table class="tbl">
    <thead>
        <tr>
            <th><?= $this->t('User ID'); ?></th>
            <th><?= $this->t('Name'); ?></th>
            <th><?= $this->t('IP Address'); ?></th>
            <th></th>
        </tr>
    </thead>
    <tbody>
<?php foreach ($profileConnectionInfo['wList'] as $connectionInfo): ?>
        <tr>
            <td>
                <a href="<?= $this->e($requestRoot); ?>user?user_id=<?= $this->eRaw($connectionInfo['userId']); ?>" title="<?= $this->e($connectionInfo['userId']); ?>"><?= $this->etr($connectionInfo['userId'], 25); ?></a>
            </td>
            <td>
                <span title="<?= $this->e($connectionInfo['displayName']); ?>"><?= $this->etr($connectionInfo['displayName'], 25); ?></span>
            </td>
            <td>
                <ul>
<?php foreach ($connectionInfo['ipList'] as $ipAddress): ?>
                    <li><code><?= $this->e($ipAddress); ?></code></li>
<?php endforeach; ?>
                </ul>
            </td>
            <td class="wireguard"><?= $this->t('WireGuard'); ?></td>
        </tr>
<?php endforeach; ?>
<?php foreach ($profileConnectionInfo['oList'] as $connectionInfo): ?>
        <tr>
            <td>
                <a href="<?= $this->e($requestRoot); ?>user?user_id=<?= $this->eRaw($connectionInfo['userId']); ?>" title="<?= $this->e($connectionInfo['userId']); ?>"><?= $this->etr($connectionInfo['userId'], 25); ?></a>
            </td>
            <td>
                <span title="<?= $this->e($connectionInfo['displayName']); ?>"><?= $this->etr($connectionInfo['displayName'], 25); ?></span>
            </td>
            <td>
                <ul>
<?php foreach ($connectionInfo['ipList'] as $ipAddress): ?>
                    <li><code><?= $this->e($ipAddress); ?></code></li>
<?php endforeach; ?>
                </ul>
            </td>
            <td class="openvpn"><?= $this->t('OpenVPN'); ?></td>
        </tr>
<?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
<?php endforeach; ?>
<?php $this->stop('content'); ?>
