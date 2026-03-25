<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/sub_paypal_config.php';
require_once __DIR__ . '/includes/sub_plans.php'; // for price comparison

sec_session_start();

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) { echo "❌ Not logged in."; exit; }

$subId = $_GET['subscription_id'] ?? null;
if (!$subId) { echo "❌ Missing subscription_id."; exit; }

// Logging helper
function log_plan_change($mysqli, $userId, $oldPlan, $newPlan, $oldStorage, $newStorage, $type) {
    $stmt = $mysqli->prepare("
        INSERT INTO sub_plan_changes (user_id, old_plan, new_plan, old_storage, new_storage, change_type, changed_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->bind_param("isssss", $userId, $oldPlan, $newPlan, $oldStorage, $newStorage, $type);
    $stmt->execute();
    $stmt->close();
}

// ==== FREE PLAN HANDLING (no PayPal API call) ====
if ($subId === 'free') {
    $stmt = $mysqli->prepare("SELECT plan, storage_addon FROM members WHERE id=? LIMIT 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->bind_result($oldPlan, $oldStorage);
    $stmt->fetch();
    $stmt->close();

    // Cancel any existing PayPal subscription
    $stmt = $mysqli->prepare("SELECT paypal_sub_id FROM members WHERE id=? LIMIT 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->bind_result($paypalSubId);
    $stmt->fetch();
    $stmt->close();
    if (!empty($paypalSubId)) {
        try {
            paypal_api('POST', "/v1/billing/subscriptions/{$paypalSubId}/cancel", ['reason' => 'User switched to Free']);
        } catch (Exception $e) {
            error_log("PayPal cancel failed: ".$e->getMessage());
        }
    }

    // Update DB
    $stmt = $mysqli->prepare("
        UPDATE members
           SET plan='free',
               paypal_sub_id=NULL,
               storage_addon=0,
               user_addon=0,
               subscription_status='canceled',
               subscribed_at=NOW()
         WHERE id=?
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->close();

    $_SESSION['plan'] = 'free';

    // Log
    log_plan_change(
        $mysqli,
        $userId,
        $oldPlan ?: null,
        'free',
        $oldStorage ?: 'none',
        'none',
        'downgrade'
    );

    $headline = "✅ Switched to Free Plan";
    $detail   = "Your subscription has been cancelled and you are now on the Free plan.";
    $plan = 'free';
    $storageGB = 0;

} else {
    // ==== PAID PLAN HANDLING ====
    try {
        $sub = paypal_api('GET', "/v1/billing/subscriptions/{$subId}");
        $status  = strtoupper($sub['status'] ?? 'UNKNOWN');

        $meta = !empty($sub['custom_id'])
            ? (json_decode(base64_decode($sub['custom_id']), true) ?: [])
            : [];

        $plan        = $meta['plan'] ?? 'free';
        $storageKey  = $meta['storage_addon'] ?? 'none';
        $storageGB   = $storageKey !== 'none' ? preg_replace('/[^0-9]/', '', $storageKey) : 0;

        // Fetch old plan
        $stmt = $mysqli->prepare("SELECT plan, storage_addon FROM members WHERE id=? LIMIT 1");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $stmt->bind_result($oldPlan, $oldStorage);
        $stmt->fetch();
        $stmt->close();

        if ($status === 'ACTIVE') {
            $stmt = $mysqli->prepare("
                UPDATE members
                   SET plan=?, paypal_sub_id=?, subscribed_at=NOW(),
                       storage_addon=?, subscription_status='active'
                 WHERE id=?
            ");
            $stmt->bind_param("ssii", $plan, $subId, $storageGB, $userId);
            $stmt->execute();
            $stmt->close();

            $_SESSION['plan'] = $plan;

            // Determine change type
            if (empty($oldPlan)) {
                $changeType = 'create';
            } elseif ($oldPlan === $plan) {
                $changeType = 'same';
            } elseif ($plan === 'free') {
                $changeType = 'downgrade';
            } else {
                $oldPrice = $PLANS[$oldPlan]['price'] ?? 0;
                $newPrice = $PLANS[$plan]['price'] ?? 0;
                $changeType = ($newPrice > $oldPrice) ? 'upgrade' : 'downgrade';
            }

            // Log change
            log_plan_change(
                $mysqli,
                $userId,
                $oldPlan ?: null,
                $plan,
                $oldStorage ?: 'none',
                $storageKey,
                $changeType
            );

            $headline = "✅ Subscription active";
            $detail   = "You're all set! Status: <strong>{$status}</strong>.";
        } else {
            $headline = "⏳ Subscription processing";
            $detail   = "PayPal is finalizing your subscription. Status: <strong>{$status}</strong>.";
        }

    } catch (Exception $e) {
        $headline = "❌ Subscription Error";
        $detail   = htmlspecialchars($e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Subscription Status</title>
  <link rel="stylesheet" href="/sub_settings.css?v=<?= time() ?>">
</head>
<body>
  <div class="success-container">
    <h2><?= $headline ?></h2>
    <p><?= $detail ?></p>
    <?php if (!empty($plan)): ?>
      <p>Plan: <strong><?= htmlspecialchars($plan) ?></strong>
        <?php if (!empty($storageGB)): ?>
          • Storage: <strong><?= (int)$storageGB ?> GB</strong>
        <?php endif; ?>
      </p>
    <?php endif; ?>
    <p><a href="/">← Return to dashboard</a></p>
  </div>
</body>
</html>
