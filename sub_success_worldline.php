<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/sub_worldline_config.php';
require_once __DIR__ . '/includes/sub_plans.php';
require_once __DIR__ . '/includes/sub_functions.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL & ~E_DEPRECATED);

sec_session_start();

$username   = $_SESSION['username'] ?? null;
$userId     = $_SESSION['user_id'] ?? null;
$plan       = $_GET['plan'] ?? null;
$checkoutId = $_GET['hostedCheckoutId'] ?? null;
$session    = $_GET['session'] ?? null;

if (!$username) { exit("❌ Not logged in."); }
if (empty($checkoutId) && (empty($plan) || empty($userId))) {
    exit("❌ Missing parameters.");
}

// defaults
$headline = "⏳ Processing";
$detail   = "Awaiting Worldline response...";
$contractIdFromSession = null;
$diff = 0.0;
$newPlan = $plan;
$oldPlan = null;
$oldStorage = $newStorage = 0;
$oldUsers = $newUsers = 0;
$newTotal = 0.0;
$handled = 0;

error_log("🔎 sub_success_worldline: session={$session}, plan={$plan}, user={$userId}");

// --- Load sub_session data if exists
if (!empty($session)) {
    $stmt = $mysqli->prepare("
        SELECT old_plan, plan_id, old_storage_addon, storage_addon,
               old_user_addon, user_addon,
               charge_now, new_total, contract_id, handled
          FROM sub_sessions
         WHERE session_id=? AND user_id=?
      ORDER BY created_at DESC LIMIT 1
    ");
    $stmt->bind_param("si", $session, $userId);
    $stmt->execute();
    $stmt->bind_result(
        $oldPlan, $newPlan,
        $oldStorage, $newStorage,
        $oldUsers, $newUsers,
        $diff, $newTotal,
        $contractIdFromSession,
        $handled
    );
    $stmt->fetch();
    $stmt->close();
}

try {
    $client = wl_client();

    // --- Checkout lookup
    $payment = null;
    $tokens  = null;
    $status  = 'UNKNOWN';
    $paymentId = null;

    if (!empty($checkoutId)) {
        $checkoutStatus = $client->v1()->merchant(WL_MERCHANT_ID)->hostedcheckouts()->get($checkoutId);
        $payment  = $checkoutStatus->createdPaymentOutput->payment ?? null;
        $tokens   = $checkoutStatus->createdPaymentOutput->tokens ?? null;

        // fallback: token from webhook-style structure
        if (empty($tokens) && !empty($checkoutStatus->token->id)) {
            $tokens = $checkoutStatus->token->id;
            error_log("ℹ️ Fallback token from token.updated: {$tokens}");
        }

        $newToken = is_array($tokens) ? reset($tokens) : $tokens;
        error_log("ℹ️ Worldline: plan={$plan}, tokens={$tokens}, newToken={$newToken}");

        if (!$payment) {
            echo "<pre>"; print_r($checkoutStatus); echo "</pre>";
            exit("❌ No payment found in Worldline response.");
        }

        $status    = strtoupper($payment->status ?? 'UNKNOWN');
        $paymentId = $payment->id ?? null;
    } else {
        $paymentId = $_GET['paymentId'] ?? null;
    }

    // --- Fetch current member
    $stmt = $mysqli->prepare("
        SELECT id, plan, storage_addon, user_addon,
               worldline_contract_id, worldline_token,
               subscribed_at, worldline_payment_id
          FROM members WHERE username=? LIMIT 1
    ");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->bind_result(
        $userId, $oldPlanDb, $oldStorageDb, $oldUsersDb,
        $oldContractId, $oldToken, $oldSubscribed_at, $oldPaymentId
    );
    $stmt->fetch();
    $stmt->close();

    if (empty($oldPlan)) $oldPlan = $oldPlanDb;

    // --- Prepare contract details
    $newContractId = $contractIdFromSession ?: ($oldContractId ?: uniqid("contract_{$userId}_"));
    $newToken      = $newToken ?? $oldToken;
    $newPaymentId  = $paymentId ?: $oldPaymentId;
    if (empty($newPaymentId)) $newPaymentId = "wl_{$userId}_{$session}";

    $amount   = $payment->paymentOutput->amountOfMoney->amount ?? 0;
    $currency = $payment->paymentOutput->amountOfMoney->currencyCode ?? 'EUR';

    $subscribedAt = date("Y-m-d H:i:s");
    $subStatus    = in_array($status, ['CAPTURED','AUTHORIZED','CAPTURE_REQUESTED']) ? 'active' : 'pending';

    // --- Card details
    $brand = $last4 = $expiry = null;
    if (!empty($payment->paymentOutput->cardPaymentMethodSpecificOutput->card)) {
        $cardInfo = $payment->paymentOutput->cardPaymentMethodSpecificOutput->card;
        $brandId  = $payment->paymentOutput->cardPaymentMethodSpecificOutput->paymentProductId ?? null;
        $brandMap = [1=>"Visa",3=>"Mastercard",117=>"Amex",114=>"Discover",122=>"Diners Club",125=>"JCB"];
        $brand    = $brandMap[$brandId] ?? "Card";
        $last4    = $cardInfo->cardNumber ? substr($cardInfo->cardNumber, -4) : null;
        $expiry   = $cardInfo->expiryDate ?? null;
    }

    // --- Already handled?
    if ($handled) {
        error_log("ℹ️ Skipping duplicate success handling for {$session}");
    }
    // --- Subscription flow
    elseif ($plan) {
        // update member
        $stmt = $mysqli->prepare("
            UPDATE members
               SET plan=?, storage_addon=?, user_addon=?, subscribed_at=?,
                   subscription_status=?, worldline_contract_id=?, worldline_token=?, worldline_payment_id=?
             WHERE id=?
        ");
        $stmt->bind_param(
            "siisssssi",
            $newPlan,
            $newStorage,
            $newUsers,
            $subscribedAt,
            $subStatus,
            $newContractId,
            $newToken,
            $newPaymentId,
            $userId
        );
        $stmt->execute();
        $stmt->close();


        // Update card details if new contract
        // Update card details if new contract
        if ($brand || $last4 || $expiry) {
            $stmt = $mysqli->prepare("UPDATE members SET card_brand=?, card_last4=?, card_expiry=? WHERE id=?");
            $stmt->bind_param("sssi", $brand, $last4, $expiry, $userId);
            $stmt->execute();
            $stmt->close();
        
        
            // --- Save new token if present and different
            //if (!empty($newToken) 
            //&& $newToken !== $oldToken
           // ) {
            $gateway   = "worldline";
            $isDefault = 1;
    
            // Clear previous defaults for this user
            $stmt = $mysqli->prepare("UPDATE sub_tokens SET is_default=0 WHERE user_id=?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $stmt->close();
    
            // Insert or update token row
            $stmt = $mysqli->prepare("
                INSERT INTO sub_tokens (user_id, gateway, token, brand, last4, expiry, is_default, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                    brand=VALUES(brand),
                    last4=VALUES(last4),
                    expiry=VALUES(expiry),
                    is_default=VALUES(is_default),
                    updated_at=NOW()
            ");
            $stmt->bind_param("isssssi", $userId, $gateway, $newToken, $brand, $last4, $expiry, $isDefault);
            $stmt->execute();
            $stmt->close();
    
            error_log("💾 success line 203 new Worldline token for user {$userId}: token {$newToken} old token {$oldToken}");
        }






        // update sub_session with paymentId
        if (!empty($paymentId)) {
            $stmt = $mysqli->prepare("
                UPDATE sub_sessions SET payment_id=?
                 WHERE session_id=? AND user_id=? 
                 ORDER BY created_at DESC LIMIT 1
            ");
            $stmt->bind_param("ssi", $paymentId, $session, $userId);
            $stmt->execute();
            $stmt->close();
        }

        // log payment
        // log payment
        if (!empty($paymentId)) {
            $gateway   = "worldline";
            $refundId  = null;
            $amountEUR = ($amount / 100.0);
        
            
            // $stmt = $mysqli->prepare("SELECT worldline_token FROM members WHERE id=? LIMIT 1");
            // $stmt->bind_param("i", $userId);
            // $stmt->execute();
            // $stmt->bind_result($tokenForPayment);
            // $stmt->fetch();   // ✅ fetch result into $tokenForPayment
            // $stmt->close();
        
            $tokenForPayment = null;
            
            $stmt = $mysqli->prepare("SELECT worldline_token FROM members WHERE id=? LIMIT 1");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $stmt->bind_result($tokenForPayment);
            $stmt->fetch();
            $stmt->close();
            
            error_log("👉 token fetched: ".$tokenForPayment);
     
        
        
            $stmt = $mysqli->prepare("
                INSERT INTO sub_payments 
                  (user_id, gateway, token, contract_id, reference, refund_id, amount, currency, status, created_at, session_id)
                VALUES (?,?,?,?,?,?,?,?,?, NOW(), ?)
            ");
            $stmt->bind_param("isssssdsss",
                $userId,
                $gateway,
                $tokenForPayment,
                $newContractId,
                $paymentId,
                $refundId,
                $amountEUR,
                $currency,
                $status,
                $session
            );
            $stmt->execute();
            $stmt->close();
        }










        // log plan change
        $changeType = $diff > 0 ? 'upgrade' : ($diff < 0 ? 'downgrade' : 'create');
        $stmt = $mysqli->prepare("
            INSERT INTO sub_plan_changes 
              (user_id, old_plan, new_plan, old_storage, new_storage, old_users, new_users,
               price_diff, change_type, gateway, session_id, changed_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?, NOW())
        ");
        $stmt->bind_param("issssiidsis",
            $userId, $oldPlan, $newPlan,
            $oldStorage, $newStorage,
            $oldUsers, $newUsers,
            $diff, $changeType, $gateway, $session
        );
        $stmt->execute();
        $stmt->close();

        $_SESSION['plan'] = $newPlan;
        $headline = $subStatus === 'active' ? "✅ Subscription active" : "⏳ Subscription processing";
        $detail   = "Status: <strong>{$status}</strong>.";

        // mark session handled
        $stmt = $mysqli->prepare("UPDATE sub_sessions SET handled=1 WHERE session_id=? AND user_id=?");
        $stmt->bind_param("si", $session, $userId);
        $stmt->execute();
        $stmt->close();
    }
    // --- Card update flow (no plan change)
    else {
        $stmt = $mysqli->prepare("UPDATE members SET worldline_token=? WHERE id=?");
        $stmt->bind_param("si", $newToken, $userId);
        $stmt->execute();
        $stmt->close();


        // Update card details 
        if ($brand || $last4 || $expiry) {
            $stmt = $mysqli->prepare("UPDATE members SET card_brand=?, card_last4=?, card_expiry=? WHERE id=?");
            $stmt->bind_param("sssi", $brand, $last4, $expiry, $userId);
            $stmt->execute();
            $stmt->close();
        
        
            // --- Save new token if present and different
           // if (!empty($newToken)) 
            //&& $newToken !== $oldToken) 
            //{
            $gateway   = "worldline";
            $isDefault = 1;
    
            // Clear previous defaults for this user
            $stmt = $mysqli->prepare("UPDATE sub_tokens SET is_default=0 WHERE user_id=?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $stmt->close();
    
            // Insert or update token row
            $stmt = $mysqli->prepare("
                INSERT INTO sub_tokens (user_id, gateway, token, brand, last4, expiry, is_default, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                    brand=VALUES(brand),
                    last4=VALUES(last4), 
                    expiry=VALUES(expiry),
                    is_default=VALUES(is_default),
                    updated_at=NOW()
            ");
            $stmt->bind_param("isssssi", $userId, $gateway, $newToken, $brand, $last4, $expiry, $isDefault);
            $stmt->execute();
            $stmt->close();
                    
            error_log("💾 Stored new Worldline token for user {$userId}: {$newToken}");
        }
        

        $headline = "✅ Card updated";
        $detail   = "Your payment details have been updated successfully.";

        // mark session handled
        $stmt = $mysqli->prepare("UPDATE sub_sessions SET handled=1 WHERE session_id=? AND user_id=?");
        $stmt->bind_param("si", $session, $userId);
        $stmt->execute();
        $stmt->close();
    }

} catch (Exception $e) {
    $headline = "❌ Error";
    $detail   = htmlspecialchars($e->getMessage());
}
?>



<?php
// ----------------------------------------------------
// Contract summary calculations
// ----------------------------------------------------

$contractSummary = null;

if (!empty($plan)) {
    $oldBase   = (float)($PLANS[$oldPlan]['price'] ?? 0);
    $oldAddons = calculateAddonPrice($oldPlan, $oldStorage, $oldUsers);
    $oldTotal  = $oldBase + $oldAddons;

    $newBase   = (float)($PLANS[$plan]['price'] ?? 0);
    $newAddons = calculateAddonPrice($plan, $newStorage, $newUsers);
    $newTotal  = $newBase + $newAddons;

    $period    = $PLANS[$plan]['period'] ?? 'year';
    $cycleDays = ($period === 'month') ? 30 : 365;

    $oldStartTs = !empty($oldSubscribed_at) ? strtotime($oldSubscribed_at) : time();
    $oldEndTs   = strtotime("+{$cycleDays} days", $oldStartTs);

    $usedDays   = floor((time() - $oldStartTs) / 86400);
    $unusedDays = max(0, $cycleDays - $usedDays);

    $oldProRated = round(($unusedDays / $cycleDays) * $oldTotal, 2);
    $newProRated = round(($unusedDays / $cycleDays) * $newTotal, 2);
    $diff        = $newProRated - $oldProRated;

    $contractSummary = [
        'oldPlan'      => $oldPlan,
        'oldBase'      => $oldBase,
        'oldAddons'    => $oldAddons,
        'oldTotal'     => $oldTotal,
        'newPlan'      => $plan,
        'newBase'      => $newBase,
        'newAddons'    => $newAddons,
        'newTotal'     => $newTotal,
        'period'       => $period,
        'cycleDays'    => $cycleDays,
        'oldStart'     => date("Y-m-d", $oldStartTs),
        'oldEnd'       => date("Y-m-d", $oldEndTs),
        'newStart'     => date("Y-m-d"),
        'newEnd'       => date("Y-m-d", $oldEndTs),
        'unusedDays'   => $unusedDays,
        'oldProRated'  => $oldProRated,
        'newProRated'  => $newProRated,
        'diff'         => $diff,
        'paymentId'    => $paymentId ?? null
    ];
}
?>

<?php
$eventRows = [];
if (!empty($paymentEvents)) {
    foreach ($paymentEvents as $ev) {
        $eventRows[] = [
            'type'    => $ev['refund_id'] ? "Refund" : "Payment",
            'amount'  => number_format($ev['amount'], 2) . " " . $ev['currency'],
            'status'  => $ev['status'],
            'details' => $ev['refund_id']
                ? "Refund ID: {$ev['refund_id']} (from Payment {$ev['reference']})"
                : "Payment ID: {$ev['reference']}",
            'date'    => date("Y-m-d H:i", strtotime($ev['created_at']))
        ];
    }
}
?>


<!DOCTYPE html>
<html lang="en">
<meta name="viewport" content="width=device-width, initial-scale=1">

<head>
  <meta charset="UTF-8" />
  <title>Subscription Status</title>
  <!--<link rel="stylesheet" href="/sub_settings.css?v=<?= time() ?>">-->
    <style>
    .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    table.contract-summary { border-collapse: collapse; margin-top: 1em; width: 100%; min-width: 480px; }
    table.contract-summary td, table.contract-summary th { padding: 6px 10px; vertical-align: top; border: 1px solid #ddd; }
    table.contract-summary th { background: #f9f9f9; text-align: left; }
    table.contract-summary tr:nth-child(even) { background: #fdfdfd; }
    
    h3 { margin-top: 1.5em; }
    .note { font-size: 0.9em; color: #555; margin-top: -0.5em; margin-bottom: 1em; }
    
    .success-container h2 { color: #16a34a; margin-bottom: 0.5rem; margin-top: 0; }
    .success-container p { margin: 0.5rem 0 1.5rem; color: #333; }
    .success-container a { display: inline-block; margin-top: 1rem; color: #007bff; text-decoration: none; font-weight: 500; }
    .success-container a:hover { text-decoration: underline; }
    
    .back-row { text-align: left; margin-bottom: 0; }
    .success-container { max-width: 600px; margin: 40px auto; padding: 0.5rem 2rem 2rem; background: #fff; border: 1px solid #ddd; border-radius: 8px; box-shadow: 0 2px 6px rgba(0,0,0,0.1); text-align: center; font-family: system-ui, sans-serif; }
    
    .back-link { display: block; margin-bottom: -30px; text-align: left; color: #374151; font-size: 15px; text-decoration: none; font-weight: 500; }
    .back-link:hover { color: #111827; text-decoration: underline; }
    
    /* Mobile override */
    /*@media (max-width: 480px) {*/
    /*  .success-container { margin: 15px; padding: 1rem; max-width: 100%; }*/
    /*  table.contract-summary { font-size: 14px; }*/
    /*}*/
    


    
    </style>

</head>
<body>
    <div class="success-container">
        
      <div class="back-row">
        <a href="/sub_pricing.php" class="back-link">← Back</a>
      </div>

      <h2><?= $headline ?></h2>
      <p><?= $detail ?></p>
      <p class="note">All amounts shown in EUR</p>
      <p class="note">Plan change effective: <strong><?= date("Y-m-d H:i") ?></strong></p>
    
      <?php if ($contractSummary): ?>
        <h3>Contract Summary</h3>
        <div class="table-responsive">
          <table class="contract-summary">
            <tr><td colspan="2"><strong>Previous Contract</strong></td></tr>
            <tr><td>Plan:</td><td><?= htmlspecialchars($contractSummary['oldPlan']) ?></td></tr>
            <tr><td>Base price:</td><td><?= number_format($contractSummary['oldBase'], 2) ?> EUR</td></tr>
            <tr><td>Add-ons:</td><td><?= number_format($contractSummary['oldAddons'], 2) ?> EUR</td></tr>
            <tr><td>Total:</td><td><strong><?= number_format($contractSummary['oldTotal'], 2) ?> EUR</strong> / <?= $contractSummary['period'] ?></td></tr>
            <tr><td>Period:</td><td><?= $contractSummary['oldStart'] ?> → <?= $contractSummary['oldEnd'] ?></td></tr>
            <tr><td colspan="2"><hr></td></tr>
            <tr><td colspan="2"><strong>New Contract</strong></td></tr>
            <tr><td>Plan:</td><td><?= htmlspecialchars($contractSummary['newPlan']) ?></td></tr>
            <tr><td>Base price:</td><td><?= number_format($contractSummary['newBase'], 2) ?> EUR</td></tr>
            <tr><td>Add-ons:</td><td><?= number_format($contractSummary['newAddons'], 2) ?> EUR</td></tr>
            <tr><td>Total:</td><td><strong><?= number_format($contractSummary['newTotal'], 2) ?> EUR</strong> / <?= $contractSummary['period'] ?></td></tr>
            <tr><td>Next renewal:</td><td><?= $contractSummary['oldEnd'] ?></td></tr>
            <tr><td colspan="2"><hr></td></tr>
            <tr><td colspan="2"><strong>Adjustment (Pro-rata)</strong></td></tr>
            <tr><td>Unused days:</td><td><strong><?= $contractSummary['unusedDays'] ?> of <?= $contractSummary['cycleDays'] ?> days</strong></td></tr>
            <tr><td>Old contract credit:</td><td><?= number_format($contractSummary['oldProRated'], 2) ?> EUR</td></tr>
            <tr><td>New contract charge:</td><td><?= number_format($contractSummary['newProRated'], 2) ?> EUR</td></tr>
            <tr><td>Difference:</td><td><strong><?= number_format($contractSummary['diff'], 2) ?> EUR</strong></td></tr>
            <?php if ($contractSummary['paymentId']): ?>
              <tr><td>Payment reference:</td><td><?= htmlspecialchars($contractSummary['paymentId']) ?></td></tr>
            <?php endif; ?>
          </table>
        </div>
      <?php endif; ?>
    
      <?php if ($eventRows): ?>
        <h3>Payment Events</h3>
        <div class="table-responsive">
          <table class="contract-summary">
            <tr><th>Type</th><th>Amount</th><th>Status</th><th>Details</th><th>Date</th></tr>
            <?php foreach ($eventRows as $row): ?>
              <tr>
                <td><?= $row['type'] ?></td>
                <td><?= $row['amount'] ?></td>
                <td><?= htmlspecialchars($row['status']) ?></td>
                <td><?= htmlspecialchars($row['details']) ?></td>
                <td><?= $row['date'] ?></td>
              </tr>
            <?php endforeach; ?>
          </table>
        </div>
      <?php endif; ?>
    </div>

</body>
</html>



