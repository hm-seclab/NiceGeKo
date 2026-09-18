<?php declare(strict_types=1); ?>
<?php /** @var \Vpn\Portal\Tpl $this */ ?>
<?php /** @var string $requestRoot */ ?>
<?php /** @var string $authState */ ?>
<?php /** @var ?string $authUser */ ?>
<?php /** @var string $authRedirectTo */ ?>
<?php /** @var string $authChallengeMessage */ ?>
<?php $this->layout('base', ['pageTitle' => $this->t('Sign In')]); ?>
<?php $this->start('content'); ?>
    <div class="auth">

<?php if ($this->exists('userPassAuthOrgInfo')) : ?>
    <?= $this->insert('userPassAuthOrgInfo'); ?>
<?php endif; ?>

<?php if ('AUTH_CHALLENGE' === $authState): ?>
        <p>
            <?= $this->t('To complete the sign in, you need to provide an additional credential.'); ?>
        </p>
<?php else: ?>
        <p>
            <?= $this->t('Please sign in with your username and password.'); ?>
        </p>
<?php endif; ?>

<?php if ('AUTH_FAILED' === $authState): ?>
        <p class="error">
            <?= $this->t('The credentials you provided were not correct.'); ?>
        </p>
<?php endif; ?>

        <form class="frm" method="post" action="<?= $this->e($requestRoot); ?>_user_pass_auth/verify">
            <fieldset>
<?php if ('AUTH_CHALLENGE' === $authState && null !== $authUser): ?>
                <input type="hidden" name="userName" value="<?= $this->e($authUser);?>">
                <label for="userName"><?= $this->t('Username'); ?></label>
                <input type="text" id="userName" name="userName" autocapitalize="off" placeholder="<?= $this->t('Username'); ?>" value="<?= $this->e($authUser); ?>" disabled>
                <input type="hidden" name="userName" value="<?= $this->e($authUser);?>">
<?php if ('' === $authChallengeMessage): ?>
                <label for="userPass"><?= $this->t('Additional Credential');?></label>
                <input type="password" id="userPass" name="userPass" placeholder="<?= $this->t('Additional Credential');?>" autofocus required>
<?php else: ?>
                <label for="userPass"><?= $this->e($authChallengeMessage);?></label>
                <input type="password" id="userPass" name="userPass" placeholder="<?= $this->e($authChallengeMessage);?>" autofocus required>
<?php endif; ?>
<?php else: ?>
<?php if (null !== $authUser): ?>
                <label for="userName"><?= $this->t('Username'); ?></label>
                <input type="text" id="userName" name="userName" autocapitalize="off" placeholder="<?= $this->t('Username'); ?>" value="<?= $this->e($authUser); ?>" required>
                <label for="userPass"><?= $this->t('Password'); ?></label>
                <input type="password" id="userPass" name="userPass" placeholder="<?= $this->t('Password'); ?>" autofocus required>
<?php else: ?>
                <label for="userName"><?= $this->t('Username'); ?></label>
                <input type="text" name="userName" autocapitalize="off" placeholder="<?= $this->t('Username'); ?>" autofocus required>
                <label for="userPass"><?= $this->t('Password'); ?></label>
                <input type="password" name="userPass" placeholder="<?= $this->t('Password'); ?>" required>
<?php endif; ?>
<?php endif; ?>
            </fieldset>
            <fieldset>
                <input type="hidden" name="authRedirectTo" value="<?= $this->e($authRedirectTo); ?>">
                <button type="submit"><?= $this->t('Sign In'); ?></button>
            </fieldset>
        </form>
    </div>
<?php $this->stop('content'); ?>
