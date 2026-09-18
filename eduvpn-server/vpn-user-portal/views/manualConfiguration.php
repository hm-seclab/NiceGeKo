<?php declare(strict_types=1); ?>
<?php /** @var \Vpn\Portal\Tpl $this */ ?>
<?php /** @var int $maxActiveConfigurations */ ?>
<?php /** @var int $numberOfActivePortalConfigurations */ ?>
<?php /** @var \DateTimeImmutable $expiryDate */ ?>
<?php /** @var array<\Vpn\Portal\Cfg\ProfileConfig> $profileConfigList */ ?>
<?php /** @var array<\Vpn\Portal\Cfg\ProfileConfig> $availableProfileConfigList */ ?>
<?php /** @var array<string> $availableProfileIdList */ ?>
<?php /** @var array<array{profile_id:string,display_name:string,expires_at:\DateTimeImmutable,connection_id:string,vpn_proto:string}> $manualUserConfigList */ ?>
<?php /** @var int $rowCount */ ?>
<?php /** @var array<array{profileId:string,displayName:string,protoTransportList:array<string>}> $profileProtoList */ ?>
<?php /** @var bool $allowExpiryExtension */ ?>
<h3><?= $this->t('New Configuration'); ?></h3>
<?php if (0 === $maxActiveConfigurations): ?>
<p class="warning">
    <?= $this->t('Downloading VPN configurations is not allowed. Please use a VPN application.'); ?>
</p>
<?php elseif ($numberOfActivePortalConfigurations >= $maxActiveConfigurations): ?>
<p class="warning">
    <?= $this->t('You have reached the maximum number of allowed active VPN configurations. Delete some first.'); ?>
</p>
<?php else: ?>
<?php if (0 === \count($availableProfileConfigList)): ?>
<p class="warning">
    <?= $this->t('No VPN profiles are available for your account.'); ?>
</p>
<?php else: ?>
<p>
<?= $this->t('Obtain a new VPN configuration file for use in your VPN client.'); ?>

<?= $this->t('Select a profile and choose a name, e.g. "Phone".'); ?>
</p>

<p class="warning">
<?= $this->t('Your new configuration will expire on %expiryDate%. Come back here to obtain a new configuration after expiry!'); ?>
</p>

<form method="post" class="frm">
    <input type="hidden" name="action" value="add_config">
    <fieldset>
        <label for="profileId"><?= $this->t('Profile'); ?></label>
        <select name="profileId" id="profileId" size="<?= $this->e((string) min(10, $rowCount));?>" required>
<?php foreach ($profileProtoList as $v): ?>
                <optgroup label="<?= $this->e($v['displayName']);?>">
<?php foreach ($v['protoTransportList'] as $protoTransport): ?>
<?php if ('openvpn+udp' === $protoTransport): ?>
                    <option value="<?= $this->e($v['profileId']);?>_openvpn+udp"><?= $this->t('OpenVPN (Prefer UDP)');?></option>
<?php endif; ?>
<?php if ('openvpn+tcp' === $protoTransport): ?>
                    <option value="<?= $this->e($v['profileId']);?>_openvpn+tcp"><?= $this->t('OpenVPN (Prefer TCP)');?></option>
<?php endif; ?>
<?php if ('wireguard+udp' === $protoTransport): ?>
                    <option value="<?= $this->e($v['profileId']);?>_wireguard+udp"><?= $this->t('WireGuard (UDP)');?></option>
<?php endif; ?>
<?php if ('wireguard+tcp' === $protoTransport): ?>
                    <option value="<?= $this->e($v['profileId']);?>_wireguard+tcp"><?= $this->t('WireGuard (TCP)');?></option>
<?php endif; ?>
<?php endforeach; ?>
                </optgroup>
<?php endforeach; ?>
        </select>
        <label for="displayName"><?= $this->t('Name'); ?></label>
        <input type="text" name="displayName" id="displayName" size="32" maxlength="64" placeholder="<?= $this->t('Name'); ?>" required>
    </fieldset>
    <fieldset>
        <button type="submit"><?= $this->t('Download'); ?></button>
    </fieldset>
</form>
<?php endif; ?>
<?php endif; ?>

<?php if (0 !== \count($manualUserConfigList)): ?>
<h3 id="active-configurations"><?= $this->t('Active Configurations'); ?></h3>
<table class="tbl">
    <thead>
        <tr><th><?= $this->t('Profile'); ?></th><th><?= $this->t('Name'); ?></th><th><?= $this->t('Expires On'); ?></th><th></th></tr>
    </thead>
    <tbody>
<?php foreach ($manualUserConfigList as $manualUserConfig): ?>
        <tr>
            <td>
<?php if (!\in_array($manualUserConfig['profile_id'], $availableProfileIdList, true)): ?>
                <span title="<?= $this->t('Profile no longer available with your current permissions.');?>">⚠️</span>
<?php endif; ?>
                <span title="<?= $this->e($manualUserConfig['profile_id']); ?>"><?= $this->profileIdToDisplayName($profileConfigList, $manualUserConfig['profile_id']); ?></span>
            </td>
            <td><span title="<?= $this->e($manualUserConfig['display_name']); ?>"><?= $this->etr($manualUserConfig['display_name'], 25); ?></span></td>
            <td><span title="<?= $this->d($manualUserConfig['expires_at']); ?>"><?= $this->d($manualUserConfig['expires_at'], 'Y-m-d'); ?></span></td>
            <td class="text-right">
<?php if ($allowExpiryExtension && 'wireguard' === $manualUserConfig['vpn_proto'] && \in_array($manualUserConfig['profile_id'], $availableProfileIdList, true)): ?>
                <form class="frm" method="post">
                    <input type="hidden" name="action" value="extend_config">
                    <input type="hidden" name="connectionId" value="<?= $this->e($manualUserConfig['connection_id']); ?>">
                    <button class="plain" type="submit" title="<?= $this->t('Extend the validity of the configuration until %expiryDate%');?>"><?= $this->t('Extend'); ?></button>
                </form>
<?php endif; ?>
                <form class="frm" method="post">
                    <input type="hidden" name="action" value="delete_config">
                    <input type="hidden" name="connectionId" value="<?= $this->e($manualUserConfig['connection_id']); ?>">
                    <button class="warning" type="submit"><?= $this->t('Delete'); ?></button>
                </form>
            </td>
        </tr>
<?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
