<?php
require_once __DIR__ . '/includes/mgm_ui.php';

function mgm_update_env_values(string $envPath, array $updates): bool {
    if (!is_file($envPath) || !is_readable($envPath) || !is_writable($envPath)) {
        return false;
    }

    $lines = file($envPath, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return false;
    }

    $remaining = $updates;
    foreach ($lines as &$line) {
        $trimmed = trim($line);
        if ($trimmed === '' || $trimmed[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$key] = explode('=', $line, 2);
        $key = trim($key);
        if (!array_key_exists($key, $remaining)) {
            continue;
        }
        $line = $key . '=' . $remaining[$key];
        unset($remaining[$key]);
    }
    unset($line);

    foreach ($remaining as $key => $value) {
        $lines[] = $key . '=' . $value;
    }

    $payload = implode(PHP_EOL, $lines);
    if ($payload === '' || !str_ends_with($payload, PHP_EOL)) {
        $payload .= PHP_EOL;
    }

    return file_put_contents($envPath, $payload) !== false;
}

$ctx = mgm_bootstrap('payments', 'Payment Settings');
$envPath = __DIR__ . '/.env';
$paymentSettingKeys = [
    'TT_PAYMENT_MODEL',
    'TT_MERCHANT_NAME',
    'TT_MERCHANT_DESCRIPTOR',
    'TT_MERCHANT_COUNTRY',
    'TT_MERCHANT_CURRENCY',
    'TT_WALLET_MODE',
    'TT_WALLET_ENABLED',
    'TT_GOOGLE_PAY_ENVIRONMENT',
    'TT_GOOGLE_PAY_MERCHANT_ID',
    'TT_WORLDLINE_GOOGLEPAY_GATEWAY_MERCHANT_ID',
    'TT_PLATFORM_FEE_BPS',
    'TT_WL_TEST_API_KEY_ID',
    'TT_WL_TEST_API_SECRET',
    'TT_WL_TEST_MERCHANT_ID',
    'TT_WL_TEST_ENDPOINT',
    'TT_WL_TEST_CHECKOUT_SUBDOMAIN',
    'TT_WL_LIVE_API_KEY_ID',
    'TT_WL_LIVE_API_SECRET',
    'TT_WL_LIVE_MERCHANT_ID',
    'TT_WL_LIVE_ENDPOINT',
    'TT_WL_LIVE_CHECKOUT_SUBDOMAIN',
];

$saveState = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mgm_action'] ?? '') === 'save_payment_settings') {
    $payload = [
        'TT_PAYMENT_MODEL' => preg_replace('/[^a-z_]/', '', strtolower(trim((string)($_POST['TT_PAYMENT_MODEL'] ?? 'merchant_of_record')))) ?: 'merchant_of_record',
        'TT_MERCHANT_NAME' => trim((string)($_POST['TT_MERCHANT_NAME'] ?? 'TapTray')) ?: 'TapTray',
        'TT_MERCHANT_DESCRIPTOR' => trim((string)($_POST['TT_MERCHANT_DESCRIPTOR'] ?? 'TapTray')) ?: 'TapTray',
        'TT_MERCHANT_COUNTRY' => strtoupper(substr(trim((string)($_POST['TT_MERCHANT_COUNTRY'] ?? 'NL')), 0, 2)) ?: 'NL',
        'TT_MERCHANT_CURRENCY' => strtoupper(substr(trim((string)($_POST['TT_MERCHANT_CURRENCY'] ?? 'EUR')), 0, 3)) ?: 'EUR',
        'TT_WALLET_MODE' => preg_replace('/[^a-z_]/', '', strtolower(trim((string)($_POST['TT_WALLET_MODE'] ?? 'default_wallet_first')))) ?: 'default_wallet_first',
        'TT_WALLET_ENABLED' => !empty($_POST['TT_WALLET_ENABLED']) ? '1' : '0',
        'TT_GOOGLE_PAY_ENVIRONMENT' => in_array(strtoupper(trim((string)($_POST['TT_GOOGLE_PAY_ENVIRONMENT'] ?? 'TEST'))), ['TEST', 'PRODUCTION'], true)
            ? strtoupper(trim((string)($_POST['TT_GOOGLE_PAY_ENVIRONMENT'] ?? 'TEST')))
            : 'TEST',
        'TT_GOOGLE_PAY_MERCHANT_ID' => trim((string)($_POST['TT_GOOGLE_PAY_MERCHANT_ID'] ?? '')),
        'TT_PLATFORM_FEE_BPS' => (string)max(0, (int)($_POST['TT_PLATFORM_FEE_BPS'] ?? 0)),
        'TT_WL_TEST_API_KEY_ID' => trim((string)($_POST['TT_WL_TEST_API_KEY_ID'] ?? '')),
        'TT_WL_TEST_API_SECRET' => trim((string)($_POST['TT_WL_TEST_API_SECRET'] ?? '')),
        'TT_WL_TEST_MERCHANT_ID' => trim((string)($_POST['TT_WL_TEST_MERCHANT_ID'] ?? '')),
        'TT_WL_TEST_ENDPOINT' => trim((string)($_POST['TT_WL_TEST_ENDPOINT'] ?? 'https://payment.preprod.direct.worldline-solutions.com')) ?: 'https://payment.preprod.direct.worldline-solutions.com',
        'TT_WL_TEST_CHECKOUT_SUBDOMAIN' => trim((string)($_POST['TT_WL_TEST_CHECKOUT_SUBDOMAIN'] ?? 'https://payment.pay1.preprod.checkout.worldline-solutions.com')) ?: 'https://payment.pay1.preprod.checkout.worldline-solutions.com',
        'TT_WL_LIVE_API_KEY_ID' => trim((string)($_POST['TT_WL_LIVE_API_KEY_ID'] ?? '')),
        'TT_WL_LIVE_API_SECRET' => trim((string)($_POST['TT_WL_LIVE_API_SECRET'] ?? '')),
        'TT_WL_LIVE_MERCHANT_ID' => trim((string)($_POST['TT_WL_LIVE_MERCHANT_ID'] ?? '')),
        'TT_WL_LIVE_ENDPOINT' => trim((string)($_POST['TT_WL_LIVE_ENDPOINT'] ?? 'https://payment.direct.worldline-solutions.com')) ?: 'https://payment.direct.worldline-solutions.com',
        'TT_WL_LIVE_CHECKOUT_SUBDOMAIN' => trim((string)($_POST['TT_WL_LIVE_CHECKOUT_SUBDOMAIN'] ?? '')) ,
    ];

    if (mgm_update_env_values($envPath, $payload)) {
        foreach ($payload as $key => $value) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
        $saveState = ['type' => 'ok', 'message' => 'Payment settings saved.'];
    } else {
        $saveState = ['type' => 'error', 'message' => 'Could not write payment settings to .env'];
    }
}

$paymentContext = [];
foreach ($paymentSettingKeys as $key) {
    $paymentContext[$key] = tw_get_env($key, '');
}

mgm_render_shell_start(
    $ctx,
    'Payment Settings',
    'Manage the TapTray merchant-of-record payment context used for wallet checkout, payment payloads, and later payout routing.'
);
?>
      <section class="mgm-grid cols-2">
        <article class="mgm-panel">
          <h2>TapTray Payment Context</h2>
          <p class="mgm-panel-intro">This page currently contains two nearby but different purposes. Keep them separate when editing values.</p>
          <p class="mgm-panel-intro"><strong>TapTray wallet checkout</strong> is for restaurant guests paying for food with phone wallets. <strong>Older TextWhisper plan payments</strong> are a different payment purpose and should not leak old test IDs into the TapTray wallet flow.</p>
          <?php if ($saveState): ?>
            <p class="mgm-panel-intro" style="color: <?= $saveState['type'] === 'ok' ? 'var(--mgm-accent)' : 'var(--mgm-danger)' ?>; margin-bottom:12px;"><?= mgm_h($saveState['message']) ?></p>
          <?php endif; ?>
          <form method="post" class="mgm-settings-form">
            <input type="hidden" name="mgm_action" value="save_payment_settings">
            <h3 style="margin:4px 0 10px;">TapTray Wallet Checkout</h3>
            <p class="mgm-panel-intro" style="margin-bottom:12px;">These values are used by the TapTray checkout and Google Pay wallet flow for restaurant customers.</p>
            <div class="mgm-settings-grid">
              <label>
                <span>Payment model</span>
                <input type="text" name="TT_PAYMENT_MODEL" value="<?= mgm_h($paymentContext['TT_PAYMENT_MODEL'] ?? 'merchant_of_record') ?>">
              </label>
              <label>
                <span>Merchant name</span>
                <input type="text" name="TT_MERCHANT_NAME" value="<?= mgm_h($paymentContext['TT_MERCHANT_NAME'] ?? 'TapTray') ?>">
              </label>
              <label>
                <span>Merchant descriptor</span>
                <input type="text" name="TT_MERCHANT_DESCRIPTOR" value="<?= mgm_h($paymentContext['TT_MERCHANT_DESCRIPTOR'] ?? 'TapTray') ?>">
              </label>
              <label>
                <span>Country</span>
                <input type="text" name="TT_MERCHANT_COUNTRY" value="<?= mgm_h($paymentContext['TT_MERCHANT_COUNTRY'] ?? 'NL') ?>" maxlength="2">
              </label>
              <label>
                <span>Currency</span>
                <input type="text" name="TT_MERCHANT_CURRENCY" value="<?= mgm_h($paymentContext['TT_MERCHANT_CURRENCY'] ?? 'EUR') ?>" maxlength="3">
              </label>
              <label>
                <span>Wallet mode</span>
                <input type="text" name="TT_WALLET_MODE" value="<?= mgm_h($paymentContext['TT_WALLET_MODE'] ?? 'default_wallet_first') ?>">
              </label>
              <label>
                <span>Wallet-first enabled</span>
                <select name="TT_WALLET_ENABLED">
                  <option value="1" <?= ($paymentContext['TT_WALLET_ENABLED'] ?? '1') === '1' ? 'selected' : '' ?>>Yes</option>
                  <option value="0" <?= ($paymentContext['TT_WALLET_ENABLED'] ?? '1') === '0' ? 'selected' : '' ?>>No</option>
                </select>
              </label>
              <label>
                <span>Google Pay environment</span>
                <select name="TT_GOOGLE_PAY_ENVIRONMENT">
                  <option value="TEST" <?= strtoupper((string)($paymentContext['TT_GOOGLE_PAY_ENVIRONMENT'] ?? 'TEST')) === 'TEST' ? 'selected' : '' ?>>TEST</option>
                  <option value="PRODUCTION" <?= strtoupper((string)($paymentContext['TT_GOOGLE_PAY_ENVIRONMENT'] ?? 'TEST')) === 'PRODUCTION' ? 'selected' : '' ?>>PRODUCTION</option>
                </select>
              </label>
              <label>
                <span>Google Pay merchant ID</span>
                <input type="text" name="TT_GOOGLE_PAY_MERCHANT_ID" value="<?= mgm_h($paymentContext['TT_GOOGLE_PAY_MERCHANT_ID'] ?? '') ?>" placeholder="BCR2DN...">
              </label>
              <label>
                <span>Platform fee (bps)</span>
                <input type="number" name="TT_PLATFORM_FEE_BPS" value="<?= mgm_h($paymentContext['TT_PLATFORM_FEE_BPS'] ?? '0') ?>" min="0" step="1">
              </label>
            </div>
            <p class="mgm-panel-intro" style="margin:10px 0 0;">TapTray now uses the current Worldline PSPID as the Google Pay gateway merchant ID for this wallet flow, so there is no separate editable gateway ID here.</p>
            <h3 style="margin:18px 0 10px;">Worldline Test API Access</h3>
            <p class="mgm-panel-intro" style="margin-bottom:12px;">These credentials and PSPIDs are the Worldline API access values used by TapTray diagnostics and wallet checkout tests.</p>
            <div class="mgm-settings-grid">
              <label>
                <span>Test API key ID</span>
                <input type="text" name="TT_WL_TEST_API_KEY_ID" value="<?= mgm_h($paymentContext['TT_WL_TEST_API_KEY_ID'] ?? '') ?>">
              </label>
              <label>
                <span>Test API secret</span>
                <input type="text" name="TT_WL_TEST_API_SECRET" value="<?= mgm_h($paymentContext['TT_WL_TEST_API_SECRET'] ?? '') ?>">
              </label>
              <label>
                <span>Test merchant ID / PSPID</span>
                <input type="text" name="TT_WL_TEST_MERCHANT_ID" value="<?= mgm_h($paymentContext['TT_WL_TEST_MERCHANT_ID'] ?? '') ?>">
              </label>
              <label>
                <span>Test endpoint</span>
                <input type="text" name="TT_WL_TEST_ENDPOINT" value="<?= mgm_h($paymentContext['TT_WL_TEST_ENDPOINT'] ?? 'https://payment.preprod.direct.worldline-solutions.com') ?>">
              </label>
              <label>
                <span>Test checkout subdomain</span>
                <input type="text" name="TT_WL_TEST_CHECKOUT_SUBDOMAIN" value="<?= mgm_h($paymentContext['TT_WL_TEST_CHECKOUT_SUBDOMAIN'] ?? 'https://payment.pay1.preprod.checkout.worldline-solutions.com') ?>">
              </label>
            </div>
            <h3 style="margin:18px 0 10px;">Worldline Live API Access</h3>
            <p class="mgm-panel-intro" style="margin-bottom:12px;">These are the live Worldline credentials for the same TapTray wallet flow, not for older TextWhisper plan-payment experiments.</p>
            <div class="mgm-settings-grid">
              <label>
                <span>Live API key ID</span>
                <input type="text" name="TT_WL_LIVE_API_KEY_ID" value="<?= mgm_h($paymentContext['TT_WL_LIVE_API_KEY_ID'] ?? '') ?>">
              </label>
              <label>
                <span>Live API secret</span>
                <input type="text" name="TT_WL_LIVE_API_SECRET" value="<?= mgm_h($paymentContext['TT_WL_LIVE_API_SECRET'] ?? '') ?>">
              </label>
              <label>
                <span>Live merchant ID / PSPID</span>
                <input type="text" name="TT_WL_LIVE_MERCHANT_ID" value="<?= mgm_h($paymentContext['TT_WL_LIVE_MERCHANT_ID'] ?? '') ?>">
              </label>
              <label>
                <span>Live endpoint</span>
                <input type="text" name="TT_WL_LIVE_ENDPOINT" value="<?= mgm_h($paymentContext['TT_WL_LIVE_ENDPOINT'] ?? 'https://payment.direct.worldline-solutions.com') ?>">
              </label>
              <label>
                <span>Live checkout subdomain</span>
                <input type="text" name="TT_WL_LIVE_CHECKOUT_SUBDOMAIN" value="<?= mgm_h($paymentContext['TT_WL_LIVE_CHECKOUT_SUBDOMAIN'] ?? '') ?>">
              </label>
            </div>
            <div style="margin-top:14px;">
              <button type="submit" class="mgm-save-btn">Save payment settings</button>
            </div>
          </form>
        </article>

        <article class="mgm-panel">
          <h2>Two Payment Purposes</h2>
          <p class="mgm-panel-intro">The confusing part is that the project has two payment contexts. This screen should be read with that split in mind.</p>
          <ul class="mgm-list">
            <li><strong>TapTray wallet checkout</strong> is the restaurant flow where guests pay for food with a phone wallet.</li>
            <li><strong>Older TextWhisper plan payments</strong> are a different business flow and should not share old test merchant references with TapTray.</li>
            <li><strong>Payment model</strong> sets the commercial flow. Right now it should remain `merchant_of_record` for TapTray.</li>
            <li><strong>Merchant name</strong> is what TapTray checkout and the wallet layer should surface to the guest.</li>
            <li><strong>Country and currency</strong> become the default TapTray payment payload values for checkout and diagnostics.</li>
            <li><strong>Wallet mode</strong> and <strong>Wallet-first enabled</strong> keep TapTray aligned with the phone-wallet-first guest payment flow.</li>
            <li><strong>Google Pay environment and merchant ID</strong> are TapTray wallet values, not generic shared payment settings.</li>
            <li><strong>Worldline Google Pay gateway merchant ID</strong> is derived from the current TapTray PSPID for the wallet tokenization path. It is no longer a separate editable value here.</li>
            <li><strong>Worldline Test/Live API Access</strong> controls which Worldline PSPID and API credentials TapTray uses for diagnostics and checkout.</li>
            <li><strong>Platform fee</strong> is staged here so marketplace routing can be added later without changing the TapTray source-of-truth location.</li>
          </ul>
        </article>
      </section>
<?php
mgm_render_shell_end();
