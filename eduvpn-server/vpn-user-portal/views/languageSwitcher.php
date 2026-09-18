<?php declare(strict_types=1); ?>
<?php /** @var \Vpn\Portal\Tpl $this */ ?>
<?php /** @var array<string> $enabledLanguages */ ?>
<?php /** @var string $requestRoot */ ?>
<?php if (1 < \count($enabledLanguages)): ?>
<form id="langSwitch" method="post" action="<?= $this->e($requestRoot); ?>setLanguage">
    <label for="language"><span title="<?= $this->t('Select Language');?>">🌐</span> <select id="language" name="uiLanguage">
<?php foreach ($enabledLanguages as $uiLanguage): ?>
           <option value="<?= $this->e($uiLanguage); ?>"<?php if ($this->uiLanguage() === $uiLanguage): ?> selected="selected"<?php endif; ?>><?= $this->e($this->languageCodeToHuman($uiLanguage)); ?></option>
<?php endforeach; ?>
    </select></label>
    <button type="submit"><?= $this->t('Switch');?></button>
</form>
<script src="<?= $this->getAssetUrl($requestRoot, 'js/language-switcher.js'); ?>" defer></script>
<?php endif; ?>
