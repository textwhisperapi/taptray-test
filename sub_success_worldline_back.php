<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/sub_worldline_config.php';
require_once __DIR__ . '/includes/sub_plans.php';
require_once __DIR__ . '/includes/sub_functions.php';

error_log("⏳ Initializing wl_client...");
$client = wl_client();
if (!$client) {
    die("❌ wl_client() failed — check WL_API_KEY_ID, WL_API_SECRET, WL_ENDPOINT, WL_INTEGRATOR");
}
error_log("✅ wl_client() ready");



sec_session_start();





ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL & ~E_DEPRECATED);


$username = $_SESSION['username'] ?? null;
if (!$username) { echo "❌ Not logged in."; exit; }

$plan       = $_GET['plan'] ?? null;
$checkoutId = $_GET['hostedCheckoutId'] ?? null;
$userId     = $_GET['user'] ?? null;


//if (!$plan || !$checkoutId) { echo "❌ Missing parameters."; exit; }

if (!$plan || !$userId) {
    echo "❌ Missing parameters.";
    exit;
}


// hostedCheckoutId only matters for first-time subscriptions
if (empty($checkoutId)) {
    // Fake a minimal object so the rest of the script works
    $checkoutId = null;
}


try {
    $client = wl_client();
    $checkoutStatus = $client->v1()
        ->merchant(WL_MERCHANT_ID)
        ->hostedcheckouts()
        ->get($checkoutId);
        
error_log("✅ Worldline checkoutStatus raw object:");
ob_start();
var_dump($checkoutStatus);
error_log(ob_get_clean());
        
        

    $payment = $checkoutStatus->createdPaymentOutput->payment ?? null;
    if (!$payment) {
        echo "<pre>"; print_r($checkoutStatus); echo "</pre>";
        exit("❌ No payment found in Worldline response.");
    }


    $status    = strtoupper($payment->status ?? 'UNKNOWN');

    $paymentId = $payment->id ?? null;

    if (!$paymentId) {
        error_log("Worldline paymentId missing for checkout {$checkoutId}");
    }



    // The real reusable token is here:
    $sub_id = $checkoutStatus->createdPaymentOutput->tokens ?? null;
    
    // Keep both for clarity
    $token = $sub_id;
    $contractId = $sub_id;


    $amount   = $payment->paymentOutput->amountOfMoney->amount ?? 0;
    $currency = $payment->paymentOutput->amountOfMoney->currencyCode ?? 'EUR';
    


// error_log("Worldline payment dump: " . print_r($payment, true));


// $contractId = $payment->paymentOutput
//     ->cardPaymentMethodSpecificOutput
//     ->recurringPaymentContract->contractId ?? null;

// error_log("Extracted contractId: " . var_export($contractId, true));


// $checkoutStatus = $client->v1()
//     ->merchant(WL_MERCHANT_ID)
//     ->hostedcheckouts()
//     ->get($checkoutId);

$payment = $checkoutStatus->createdPaymentOutput->payment ?? null;

// DEBUG DUMP
error_log("Worldline checkoutStatus: " . print_r($checkoutStatus, true));
error_log("Worldline payment dump: " . print_r($payment, true));



    // Fetch user_id + old plan
    $stmt = $mysqli->prepare("SELECT id, plan, storage_addon, user_addon, worldline_sub_id, subscribed_at, worldline_payment_id 
                                FROM members 
                               WHERE username=? LIMIT 1");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->bind_result($userId, $oldPlan, $oldStorage, $oldUsers, $oldContractId, $subscribed_at, $oldPaymentId);
    $stmt->fetch();
    $stmt->close();


    // 🆕 Fetch addons from sub_sessions
    $stmt = $mysqli->prepare("SELECT storage_addon, user_addon 
                                FROM sub_sessions 
                               WHERE session_id=? AND user_id=? 
                            ORDER BY created_at DESC LIMIT 1");
    $stmt->bind_param("si", $checkoutId, $userId);
    $stmt->execute();
    $stmt->bind_result($storageAddon, $userAddon);
    $stmt->fetch();
    $stmt->close();

    $storageGB = (int) $storageAddon;
    $userSeats = (int) $userAddon;

    //$paymentId = $payment->id ?? null;
        
    // $newContractId = $contractId ?: $paymentId;
    // $oldContractId = $oldContractId ?: $oldPaymentId;

    // === Update members row ===
    // === Update members row with real Worldline identifiers ===
    $stmt = $mysqli->prepare("
        UPDATE members
           SET plan=?, 
               storage_addon=?, 
               user_addon=?, 
               subscribed_at=NOW(),
               subscription_status=?, 
               worldline_sub_id=?,      -- real recurring contractId
               worldline_token=?,       -- reusable token
               worldline_payment_id=?   -- last payment id
         WHERE id=?
    ");
   // $subStatus = in_array($status, ['CAPTURED','AUTHORIZED']) ? 'active' : 'pending';
    $subStatus = in_array($status, ['CAPTURED','AUTHORIZED','CAPTURE_REQUESTED']) ? 'active' : 'pending';

    
    $stmt->bind_param(
        "siissssi",
        $plan,
        $storageGB,
        $userSeats,
        $subStatus,
        $contractId,   // ✅ no longer fallback to paymentId
        $token,        // ✅ actual token
        $paymentId,
        $userId
    );
    $stmt->execute();
    $stmt->close();



    $_SESSION['plan'] = $plan;

    // Work out change type
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

    // Log plan change (with contract IDs)
    $gateway = 'worldline';

    $oldPrice  = $PLANS[$oldPlan]['price'] ?? 0;
    $newPrice  = $PLANS[$plan]['price'] ?? 0;
    $priceDiff = $newPrice - $oldPrice;
    
    $stmt = $mysqli->prepare("
        INSERT INTO plan_changes 
          (user_id, old_plan, new_plan, old_storage, new_storage, old_users, new_users,
           old_price, new_price, price_diff, change_type, gateway, old_contract_id, new_contract_id, changed_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
    ");
    $stmt->bind_param(
        "issiiiiddsssss",
        $userId,
        $oldPlan,
        $plan,
        $oldStorage,
        $storageGB,
        $oldUsers,
        $userSeats,
        $oldPrice,
        $newPrice,
        $priceDiff,
        $changeType,
        $gateway,
        $oldContractId,
        $contractId   // ✅ new one
    );
    $stmt->execute();
    $stmt->close();

    
    // Log payment
    $stmt = $mysqli->prepare("
        INSERT INTO payments_log (user_id, gateway, reference, amount, currency, status, created_at)
        VALUES (?, 'worldline', ?, ?, ?, ?, NOW())
    ");
    $stmt->bind_param("isiss", $userId, $paymentId, $amount, $currency, $status);
    $stmt->execute();
    $stmt->close();




//    temporarily commented out until contract id and token id are fixed in members
    // if ($changeType === 'downgrade' && !empty($oldContractId) && !empty($oldPlan)) {
    //     // Compute pro-rata unused amount
    //     $oldPrice = $PLANS[$oldPlan]['price'] ?? 0;
    //     $cycleDays = 365; // TODO: handle monthly if needed
    
    //     $oldSubscribedAt = $subscribed_at; // preserve before UPDATE
    //     $usedDays = (time() - strtotime($oldSubscribedAt)) / 86400;
    //     $unusedDays = max(0, $cycleDays - $usedDays);
    
    //     $refundAmount = round(($unusedDays / $cycleDays) * $oldPrice, 2);
    

    //     if ($refundAmount > 0 && !empty($oldPaymentId)) {
    //         issueRefund($userId, 'worldline', $oldPaymentId, $refundAmount);
    //     }
    // }

    

    // Messaging
    if ($subStatus === 'active') {
        $headline = "✅ Subscription active";
        $detail   = "You're all set! Status: <strong>{$status}</strong>.";
    } else {
        $headline = "⏳ Subscription processing";
        $detail   = "Worldline is finalizing your payment. Status: <strong>{$status}</strong>.";
    }

} catch (Exception $e) {
    $headline = "❌ Error";
    $detail   = htmlspecialchars($e->getMessage());
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
        <?php if (!empty($userSeats)): ?>
          • Extra users: <strong><?= (int)$userSeats ?></strong>
        <?php endif; ?>
      </p>
    <?php endif; ?>
    <p><a href="/">← Return to dashboard</a></p>
  </div>
</body>
</html>
