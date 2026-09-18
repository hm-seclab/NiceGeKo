<?php declare(strict_types=1); ?>
<?php /** @var \Vpn\Portal\Tpl $this */ ?>
<?php /** @var \fkooman\OAuth\Server\AuthorizeRequest $authorizeRequest */ ?>
<?php $this->layout('base', ['pageTitle' => $this->t('Approve Application')]); ?>
<?php $this->start('content'); ?>
    <div class="appAuth">

        <p>
<?= $this->t('Only click "Approve" if you are trying to connect with the %display_name% application!');?>
        </p>
        <p>
<?= $this->t('Close this window if that was not your intention!');?>
        </p>

    <div class="appApproval">
        <span class="<?= $this->e($authorizeRequest->clientInfo->clientId()); ?>"><?= $this->e($authorizeRequest->clientInfo->displayName()); ?></span>
        <form class="frm" method="<?= 'query' === $authorizeRequest->responseMode ? 'get' : 'post';?>" action="<?= $this->e($authorizeRequest->redirectUri);?>">
            <input type="hidden" name="code" value="<?= $this->e($authorizeRequest->authorizationCode);?>">
            <input type="hidden" name="iss" value="<?= $this->e($authorizeRequest->issuerIdentity);?>">
            <input type="hidden" name="state" value="<?= $this->e($authorizeRequest->requestState);?>">
            <fieldset>
                <button type="submit"><?= $this->t('Approve'); ?></button>
            </fieldset>
        </form>
    </div>

    <details>
        <summary>
<?= $this->t('Why is this necessary?'); ?>
        </summary>
        <p>
<?= $this->t('To prevent malicious applications from silently establishing a VPN connection on your behalf, you have to explicitly approve this application first.'); ?>
        </p>
    </details>
    </div>
<?php $this->stop('content'); ?>
