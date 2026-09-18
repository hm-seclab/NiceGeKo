<?php declare(strict_types=1); ?>
<?php /** @var \Vpn\Portal\Tpl $this */ ?>
<?php /** @var string $requestRoot */ ?>
<?php /** @var bool $enableLogoutButton */ ?>
<form method="post" action="<?= $this->e($requestRoot); ?>_logout">
<?php if ($enableLogoutButton): ?>
    <button type="submit"><?= $this->t('Sign Out'); ?></button>
<?php else: ?>
    <button type="submit" disabled><?= $this->t('Sign Out'); ?></button>
<?php endif; ?>
</form>
