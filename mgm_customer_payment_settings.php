<?php
require_once __DIR__ . '/includes/mgm_ui.php';

function mgm_customer_payment_settings_ensure_schema(mysqli $mysqli): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $mysqli->query("
        CREATE TABLE IF NOT EXISTS mmt_payment_settings (
            member_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            provider VARCHAR(32) NOT NULL DEFAULT 'rapyd',
            mode VARCHAR(16) NOT NULL DEFAULT 'test',
            provider_wallet_id VARCHAR(128) DEFAULT NULL,
            provider_account_id VARCHAR(128) DEFAULT NULL,
            access_key_enc TEXT DEFAULT NULL,
            secret_key_enc TEXT DEFAULT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

$ctx = mgm_bootstrap('customer_payments', 'Customer Payment Settings');
mgm_customer_payment_settings_ensure_schema($mysqli);

$saveState = null;
$selectedMemberId = max(0, (int)($_GET['member_id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mgm_action'] ?? '') === 'save_member_payment_settings') {
    $memberId = max(0, (int)($_POST['member_id'] ?? 0));
    $provider = preg_replace('/[^a-z0-9_]/', '', strtolower(trim((string)($_POST['provider'] ?? 'rapyd')))) ?: 'rapyd';
    $mode = preg_replace('/[^a-z0-9_]/', '', strtolower(trim((string)($_POST['mode'] ?? 'test')))) ?: 'test';
    $providerWalletId = trim((string)($_POST['provider_wallet_id'] ?? ''));
    $providerAccountId = trim((string)($_POST['provider_account_id'] ?? ''));

    if ($memberId < 1) {
        $saveState = ['type' => 'error', 'message' => 'Select a customer first.'];
    } else {
        $stmt = $mysqli->prepare("
            INSERT INTO mmt_payment_settings (
                member_id,
                provider,
                mode,
                provider_wallet_id,
                provider_account_id
            ) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                provider = VALUES(provider),
                mode = VALUES(mode),
                provider_wallet_id = VALUES(provider_wallet_id),
                provider_account_id = VALUES(provider_account_id)
        ");

        if ($stmt instanceof mysqli_stmt) {
            $stmt->bind_param('issss', $memberId, $provider, $mode, $providerWalletId, $providerAccountId);
            if ($stmt->execute()) {
                $saveState = ['type' => 'ok', 'message' => 'Customer payment settings saved.'];
                $selectedMemberId = $memberId;
            } else {
                $saveState = ['type' => 'error', 'message' => 'Could not save customer payment settings.'];
            }
            $stmt->close();
        } else {
            $saveState = ['type' => 'error', 'message' => 'Could not prepare customer payment settings update.'];
        }
    }
}

$memberOptions = [];
$membersResult = $mysqli->query("
    SELECT id, username, COALESCE(NULLIF(display_name, ''), username) AS display_name
    FROM members
    ORDER BY display_name ASC, username ASC
    LIMIT 500
");
if ($membersResult instanceof mysqli_result) {
    while ($row = $membersResult->fetch_assoc()) {
        $memberOptions[] = [
            'id' => (int)($row['id'] ?? 0),
            'username' => (string)($row['username'] ?? ''),
            'display_name' => (string)($row['display_name'] ?? ''),
        ];
    }
    $membersResult->close();
}

$memberPaymentById = [];
$memberPaymentResult = $mysqli->query("
    SELECT
        ps.member_id,
        ps.provider,
        ps.mode,
        ps.provider_wallet_id,
        ps.provider_account_id,
        ps.updated_at,
        m.username,
        COALESCE(NULLIF(m.display_name, ''), m.username) AS display_name
    FROM mmt_payment_settings ps
    LEFT JOIN members m ON m.id = ps.member_id
    ORDER BY ps.updated_at DESC, ps.member_id DESC
");
if ($memberPaymentResult instanceof mysqli_result) {
    while ($row = $memberPaymentResult->fetch_assoc()) {
        $memberPaymentById[(int)($row['member_id'] ?? 0)] = $row;
    }
    $memberPaymentResult->close();
}

if ($selectedMemberId < 1 && $memberOptions) {
    $selectedMemberId = (int)$memberOptions[0]['id'];
}

$selectedMember = null;
foreach ($memberOptions as $memberOption) {
    if ((int)$memberOption['id'] === $selectedMemberId) {
        $selectedMember = $memberOption;
        break;
    }
}
$selectedMemberPayment = $selectedMemberId > 0 ? ($memberPaymentById[$selectedMemberId] ?? null) : null;

mgm_render_shell_start(
    $ctx,
    'Customer Payment Settings',
    'Select a customer, then edit that customer\'s wallet payment settings. This page is DB-backed and separate from the global TapTray payment configuration.'
);
?>
      <style>
        .mgm-customer-layout {
          display: grid;
          grid-template-columns: 320px minmax(0, 1fr);
          gap: 18px;
        }
        .mgm-customer-list {
          display: grid;
          gap: 8px;
          max-height: 640px;
          overflow: auto;
        }
        .mgm-customer-link {
          display: block;
          text-decoration: none;
          padding: 10px 12px;
          border-radius: 12px;
          border: 1px solid var(--mgm-border);
          background: #fff;
        }
        .mgm-customer-link.is-active {
          background: var(--mgm-accent-soft);
          border-color: rgba(15, 118, 110, 0.25);
        }
        .mgm-customer-link strong {
          display: block;
          margin-bottom: 4px;
        }
        .mgm-customer-link span {
          color: var(--mgm-muted);
          font: 500 13px/1.35 "Trebuchet MS", sans-serif;
        }
      </style>
      <section class="mgm-customer-layout">
        <article class="mgm-panel">
          <h2>Customers</h2>
          <p class="mgm-panel-intro">Select a customer to edit wallet payment settings.</p>
          <div class="mgm-customer-list">
            <?php foreach ($memberOptions as $member): ?>
              <a class="mgm-customer-link <?= (int)$member['id'] === $selectedMemberId ? 'is-active' : '' ?>" href="/mgm_customer_payment_settings.php?member_id=<?= (int)$member['id'] ?>">
                <strong><?= mgm_h($member['display_name']) ?></strong>
                <span>#<?= (int)$member['id'] ?> · <?= mgm_h($member['username']) ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </article>

        <article class="mgm-panel">
          <h2>Customer Wallet Settings</h2>
          <p class="mgm-panel-intro">Store provider wallet/account details for the selected customer in <code>mmt_payment_settings</code>.</p>
          <?php if ($saveState): ?>
            <p class="mgm-panel-intro" style="color: <?= $saveState['type'] === 'ok' ? 'var(--mgm-accent)' : 'var(--mgm-danger)' ?>; margin-bottom:12px;"><?= mgm_h($saveState['message']) ?></p>
          <?php endif; ?>
          <?php if ($selectedMember): ?>
            <p class="mgm-panel-intro" style="margin-bottom:12px;"><strong><?= mgm_h($selectedMember['display_name']) ?></strong> · #<?= (int)$selectedMember['id'] ?> · <?= mgm_h($selectedMember['username']) ?></p>
          <?php endif; ?>
          <form method="post" class="mgm-settings-form">
            <input type="hidden" name="mgm_action" value="save_member_payment_settings">
            <input type="hidden" name="member_id" value="<?= (int)$selectedMemberId ?>">
            <div class="mgm-settings-grid">
              <label>
                <span>Provider</span>
                <?php $selectedProvider = strtolower((string)($selectedMemberPayment['provider'] ?? 'rapyd')); ?>
                <select name="provider">
                  <option value="rapyd" <?= $selectedProvider === 'rapyd' ? 'selected' : '' ?>>Rapyd</option>
                  <option value="worldline" <?= $selectedProvider === 'worldline' ? 'selected' : '' ?>>Worldline</option>
                  <option value="stripe" <?= $selectedProvider === 'stripe' ? 'selected' : '' ?>>Stripe</option>
                </select>
              </label>
              <label>
                <span>Mode</span>
                <?php $selectedMode = strtolower((string)($selectedMemberPayment['mode'] ?? 'test')); ?>
                <select name="mode">
                  <option value="test" <?= $selectedMode === 'test' ? 'selected' : '' ?>>Test</option>
                  <option value="live" <?= $selectedMode === 'live' ? 'selected' : '' ?>>Live</option>
                </select>
              </label>
              <label>
                <span>Provider wallet ID</span>
                <input type="text" name="provider_wallet_id" value="<?= mgm_h((string)($selectedMemberPayment['provider_wallet_id'] ?? '')) ?>" placeholder="ewallet_...">
              </label>
              <label>
                <span>Provider account ID</span>
                <input type="text" name="provider_account_id" value="<?= mgm_h((string)($selectedMemberPayment['provider_account_id'] ?? '')) ?>" placeholder="optional">
              </label>
            </div>
            <div style="margin-top:14px;">
              <button type="submit" class="mgm-save-btn">Save customer payment settings</button>
            </div>
          </form>
          <div class="mgm-table-wrap" style="margin-top:18px;">
            <table>
              <thead>
                <tr>
                  <th>Provider</th>
                  <th>Mode</th>
                  <th>Wallet</th>
                  <th>Account</th>
                  <th>Updated</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$selectedMemberPayment): ?>
                  <tr>
                    <td colspan="5">No payment settings saved yet for this customer.</td>
                  </tr>
                <?php else: ?>
                  <tr>
                    <td><?= mgm_h(strtoupper((string)($selectedMemberPayment['provider'] ?? ''))) ?></td>
                    <td><?= mgm_h(strtoupper((string)($selectedMemberPayment['mode'] ?? 'test'))) ?></td>
                    <td><?= mgm_h((string)($selectedMemberPayment['provider_wallet_id'] ?? '')) ?></td>
                    <td><?= mgm_h((string)($selectedMemberPayment['provider_account_id'] ?? '')) ?></td>
                    <td><?= mgm_h(mgm_format_datetime((string)($selectedMemberPayment['updated_at'] ?? ''))) ?></td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </article>
      </section>
<?php
mgm_render_shell_end();
