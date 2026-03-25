<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/sub_plans.php';
sec_session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: /login.php");
    exit;
}

$userId = $_SESSION['user_id'];

// --- Fetch member subscription details ---
$stmt = $mysqli->prepare("
    SELECT plan, storage_addon, user_addon, subscribed_at, subscription_status,
           stripe_sub_id, paypal_sub_id, worldline_contract_id
    FROM members WHERE id = ?
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$contract = $stmt->get_result()->fetch_assoc();
$stmt->close();

// --- Fetch combined payments + plan changes via session_id ---
$stmt = $mysqli->prepare("
    SELECT 
        p.contract_id,
        p.created_at AS payment_date,
        p.reference, p.refund_id, p.amount, p.currency, p.gateway, p.status,
        c.changed_at AS change_date,
        c.old_plan, c.new_plan, c.old_storage, c.new_storage, c.old_users, c.new_users,
        c.price_diff, c.change_type
    FROM sub_payments p
    LEFT JOIN sub_plan_changes c ON c.session_id = p.session_id
    WHERE p.user_id = ?
    ORDER BY p.contract_id, p.created_at DESC
");

$stmt->bind_param("i", $userId);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$contracts = [];
foreach ($rows as $r) {
    $cid = $r['contract_id'] ?: 'unknown';
    if (!isset($contracts[$cid])) {
        $contracts[$cid] = ['total' => 0, 'rows' => []];
    }
    $contracts[$cid]['total'] += (float)$r['amount'];
    $contracts[$cid]['rows'][] = $r;
}

?>
<!DOCTYPE html>
<html lang="en">
<meta name="viewport" content="width=device-width, initial-scale=1">
<head>
  <meta charset="UTF-8">
  <title>Payments Overview</title>
  <style>
    body { font-family: system-ui, sans-serif; margin:2rem; background:#f9f9f9; }
    h1,h2 { margin-top:1.5rem; }
    .back-link {
      display:inline-block; color:#374151; font-size:14px; text-decoration:none; font-weight:500;
    }
    .back-link:hover { color:#111827; text-decoration:underline; }

    table { border-collapse:collapse; width:100%; margin-top:0.5rem; }
    th,td { border:1px solid #ddd; padding:8px; font-size:0.95rem; }
    th { background:#eee; text-align:left; }
    tr:nth-child(even) { background:#fdfdfd; }
    td.num { text-align:right; }

    .table-responsive { overflow-x:auto; -webkit-overflow-scrolling:touch; }

    /* Card style for mobile */
    @media (max-width:600px) {
      body { margin:1rem; }
      table { display:none; }
      .history-card, .contract-card {
        background:#fff; border:1px solid #ddd; border-radius:8px;
        padding:12px; margin-bottom:12px;
      }
      .history-card p, .contract-card p { margin:4px 0; font-size:14px; }
      .history-card strong, .contract-card strong { color:#111; }
    }
  </style>
</head>
<body>
  <a href="javascript:history.back()" class="back-link">← Back</a>    

  <h2>Manage Payment Method</h2>
  <?php if ($contract['worldline_contract_id']): ?>
    <p>Current subscription is billed via <strong>Worldline</strong>.</p>
    <form method="POST" action="sub_update_card_worldline.php">
      <button type="submit" style="padding:8px 16px; border:none; border-radius:6px; background:#007bff; color:#fff; cursor:pointer;">
        🔄 Update Credit Card
      </button>
    </form>
  <?php else: ?>
    <p>No active Worldline subscription found.</p>
  <?php endif; ?>

  <h1>💳 Payments & Subscription Overview</h1>

  <h2>Contract Status</h2>
  <div class="table-responsive">
    <table>
      <tr><th>Current Plan</th><td><?= htmlspecialchars($contract['plan'] ?? '—') ?></td></tr>
      <tr><th>Storage Add-on</th><td><?= (int)$contract['storage_addon'] ?> GB</td></tr>
      <tr><th>User Add-on</th><td><?= (int)$contract['user_addon'] ?> users</td></tr>
      <tr><th>Subscribed At</th><td><?= $contract['subscribed_at'] ?></td></tr>
      <tr><th>Status</th><td><?= $contract['subscription_status'] ?></td></tr>
      <tr><th>Stripe Sub ID</th><td><?= $contract['stripe_sub_id'] ?></td></tr>
      <tr><th>PayPal Sub ID</th><td><?= $contract['paypal_sub_id'] ?></td></tr>
      <tr><th>Worldline Sub ID</th><td><?= $contract['worldline_contract_id'] ?></td></tr>
    </table>
  </div>

  <!-- Mobile contract card -->
  <div class="contract-card">
    <p><strong>Current Plan:</strong> <?= htmlspecialchars($contract['plan'] ?? '—') ?></p>
    <p><strong>Storage:</strong> <?= (int)$contract['storage_addon'] ?> GB</p>
    <p><strong>Users:</strong> <?= (int)$contract['user_addon'] ?></p>
    <p><strong>Subscribed At:</strong> <?= $contract['subscribed_at'] ?></p>
    <p><strong>Status:</strong> <?= $contract['subscription_status'] ?></p>
    <p><strong>Stripe Sub ID:</strong> <?= $contract['stripe_sub_id'] ?></p>
    <p><strong>PayPal Sub ID:</strong> <?= $contract['paypal_sub_id'] ?></p>
    <p><strong>Worldline Sub ID:</strong> <?= $contract['worldline_contract_id'] ?></p>
  </div>

  <h2>Payment & Plan Change History</h2>

  <?php if ($contracts): ?>
    <?php foreach ($contracts as $cid => $cdata): ?>
      <h3>Contract: <?= htmlspecialchars($cid) ?> — Total: <?= number_format($cdata['total'], 2) ?> EUR</h3>

      <div class="table-responsive">
        <table>
          <tr>
            <th>Date</th><th>Gateway</th><th>Reference</th><th>Refund ID</th>
            <th>Plan Change</th><th class="num">Amount</th><th>Currency</th><th>Status</th>
          </tr>
          <?php foreach ($cdata['rows'] as $r): ?>
            <?php
              $desc = "—";
              if ($r['new_plan']) {
                $desc = ucfirst($r['change_type']).": ".$r['new_plan'];
                if ($r['new_storage'] != $r['old_storage']) $desc .= " | Storage: {$r['old_storage']} → {$r['new_storage']} GB";
                if ($r['new_users'] != $r['old_users']) $desc .= " | Users: {$r['old_users']} → {$r['new_users']}";
              }
            ?>
            <tr>
              <td><?= $r['payment_date'] ?></td>
              <td><?= ucfirst($r['gateway']) ?></td>
              <td><?= htmlspecialchars($r['reference'] ?: '-') ?></td>
              <td><?= htmlspecialchars($r['refund_id'] ?: '-') ?></td>
              <td><?= htmlspecialchars($desc) ?></td>
              <td class="num"><?= $r['amount'] !== null ? number_format($r['amount'], 2) : '-' ?></td>
              <td><?= $r['currency'] ?: '-' ?></td>
              <td><?= $r['status'] ?: '-' ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
      </div>

      <!-- Mobile-friendly cards -->
      <?php foreach ($cdata['rows'] as $r): ?>
        <div class="history-card">
          <p><strong>Date:</strong> <?= $r['payment_date'] ?></p>
          <p><strong>Gateway:</strong> <?= ucfirst($r['gateway']) ?></p>
          <p><strong>Reference:</strong> <?= htmlspecialchars($r['reference'] ?: '-') ?></p>
          <p><strong>Refund ID:</strong> <?= htmlspecialchars($r['refund_id'] ?: '-') ?></p>
          <p><strong>Plan Change:</strong> <?= htmlspecialchars($desc) ?></p>
          <p><strong>Amount:</strong> <?= $r['amount'] !== null ? number_format($r['amount'], 2) : '-' ?> <?= $r['currency'] ?></p>
          <p><strong>Status:</strong> <?= $r['status'] ?: '-' ?></p>
        </div>
      <?php endforeach; ?>

    <?php endforeach; ?>
  <?php else: ?>
    <p>No history found.</p>
  <?php endif; ?>
</body>
</html>

