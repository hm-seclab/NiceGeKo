<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

/*
 * Unit tests for Vpn\Portal\Cfg\PostureCheckConfig — the typed accessor the
 * whole gate depends on (required fields + defaults + int cast).
 */

use Vpn\Portal\Cfg\Config;
use Vpn\Portal\Cfg\PostureCheckConfig;

// required strings present
$cfg = new PostureCheckConfig([
    'wazuhApiUrl' => 'https://wazuh.test:55000',
    'wazuhUser' => 'wazuh-wui',
    'wazuhPass' => 'secret',
]);
T::eq('https://wazuh.test:55000', $cfg->wazuhApiUrl(), 'config: wazuhApiUrl returned');
T::eq('wazuh-wui', $cfg->wazuhUser(), 'config: wazuhUser returned');
T::eq('secret', $cfg->wazuhPass(), 'config: wazuhPass returned');

// defaults
T::eq('', $cfg->wazuhCaCert(), 'config: wazuhCaCert defaults to empty string');
T::eq(0, $cfg->scaMinScore(), 'config: scaMinScore defaults to 0');

// scaMinScore int cast + explicit value
$cfg2 = new PostureCheckConfig([
    'wazuhApiUrl' => 'https://x', 'wazuhUser' => 'u', 'wazuhPass' => 'p',
    'scaMinScore' => 70,
]);
T::eq(70, $cfg2->scaMinScore(), 'config: scaMinScore explicit value');

// missing required field throws a RangeException (not just any Throwable, so a
// refactor that raises a different error is caught by the test).
T::throws(
    static fn () => (new PostureCheckConfig(['wazuhUser' => 'u', 'wazuhPass' => 'p']))->wazuhApiUrl(),
    'config: missing wazuhApiUrl throws',
    \RangeException::class
);

// --- the gate's on/off switch ------------------------------------------------
// SECURITY-RELEVANT DEFAULT: posture checking is OPT-IN. When the 'PostureCheck'
// block is absent or empty, Config::postureCheckConfig() returns null, web/api.php
// leaves $postureChecker null, and VpnApiThreeModule lets /v3/connect through
// UNGATED. That is intended behaviour (it keeps the patched portal usable as
// stock eduVPN), but it is silent, so pin it here: if a refactor ever changes
// which of these shapes disables the gate, this test says so out loud.
// Documented in README "Limitations".
T::eq(null, (new Config([]))->postureCheckConfig(), 'gate off: absent PostureCheck block => null (UNGATED)');
T::eq(null, (new Config(['PostureCheck' => []]))->postureCheckConfig(), 'gate off: empty PostureCheck block => null (UNGATED)');

// Conversely, a populated block must produce a config object => gate enforced.
$enabled = (new Config(['PostureCheck' => [
    'wazuhApiUrl' => 'https://wazuh.test:55000', 'wazuhUser' => 'u', 'wazuhPass' => 'p',
]]))->postureCheckConfig();
T::ok($enabled instanceof PostureCheckConfig, 'gate on: populated PostureCheck block => PostureCheckConfig');
T::eq('https://wazuh.test:55000', $enabled->wazuhApiUrl(), 'gate on: values reach the checker config');
