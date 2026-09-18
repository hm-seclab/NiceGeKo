<?php declare(strict_types=1); ?>
<?php /** @var \Vpn\Portal\Tpl $this */ ?>
<?php /** @var \Vpn\Portal\WireGuard\ClientConfig $wireGuardClientConfig */ ?>
<?php $this->layout('base', ['activeItem' => 'home', 'pageTitle' => $this->t('Home')]); ?>
<?php $this->start('content'); ?>
    <h2><?= $this->t('WireGuard Configuration'); ?></h2>
<?php if ('application/x-wireguard+tcp-profile' === $wireGuardClientConfig->contentType()): ?>
    <p class="warning">
<?= $this->t('Only WireGuard clients that support WireGuard over TCP using ProxyGuard can be used with the configuration file below.');?>
    </p>
<?php endif; ?>
<?php if (null !== $qrCode = $wireGuardClientConfig->getQr()): ?>
    <figure>
        <figcaption><?= $this->t('Use the WireGuard app on your mobile device to scan this QR code.'); ?></figcaption>
        <?= $qrCode; ?>
    </figure>
<?php endif; ?>
    <p>
<?= $this->t('Import or copy/paste this configuration to your WireGuard application.'); ?>
    </p>
    <blockquote>
        <pre><?= $this->e($wireGuardClientConfig->get(includeComments: true)); ?></pre>
    </blockquote>
<?php $this->stop('content'); ?>
