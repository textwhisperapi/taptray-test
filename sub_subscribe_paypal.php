<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/sub_plans.php';
require_once __DIR__ . '/includes/sub_paypal_config.php';


ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


sec_session_start();

// Session & inputs
$userId       = $_SESSION['user_id'] ?? null;
$email        = $_SESSION['email']   ?? null;
$inputPlan    = $_POST['plan'] ?? null;              // team_lite|team_standard|...
$storageAddon = $_POST['storage_addon'] ?? 'none';   // none|s10|...

// Resolve email if missing
if (!$email && isset($_SESSION['username'])) {
    $stmt = $mysqli->prepare("SELECT email FROM members WHERE username=? LIMIT 1");
    $stmt->bind_param("s", $_SESSION['username']);
    $stmt->execute();
    $stmt->bind_result($email);
    $stmt->fetch();
    $stmt->close();
    $_SESSION['email'] = $email;
}

if (!$userId || !$email) {
    http_response_code(403);
    echo "❌ You must be logged in.";
    exit;
}

// Validate plan directly (no renaming)
if (!$inputPlan || !isset($PLANS[$inputPlan])) {
    http_response_code(400);
    echo "❌ Invalid plan selection.";
    // exit;
}
$plan = $inputPlan; // Keep original name

// URLs
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$baseUrl  = $protocol . $_SERVER['HTTP_HOST'];

// Get current subscription info
$stmt = $mysqli->prepare("SELECT paypal_sub_id, plan, storage_addon FROM members WHERE id=? LIMIT 1");
$stmt->bind_param("i", $userId);
$stmt->execute();
$stmt->bind_result($paypalSubId, $currentPlan, $currentStorage);
$stmt->fetch();
$stmt->close();

// FREE downgrade
if ($plan === 'freexxxx') {
    if (!empty($paypalSubId)) {
        try {
            paypal_api('POST', "/v1/billing/subscriptions/{$paypalSubId}/cancel", ['reason'=>'User switched to Free']);
        } catch (Exception $e) {
            error_log("PayPal cancel failed: ".$e->getMessage());
        }
    }
    // Update DB immediately for free plan
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
    echo "<h2>✅ You are now on the Free Plan</h2><p><a href='/'>← Return to dashboard</a></p>";
    exit;
}


// ===== FREE DOWNGRADE =====
if ($plan === 'free') {
    // If PayPal sub exists, cancel it but do not update DB here
    if (!empty($paypalSubId)) {
        try { 
            paypal_api('POST', "/v1/billing/subscriptions/{$paypalSubId}/cancel", ['reason'=>'User switched to Free']); 
        }
        catch (Exception $e) { error_log("PayPal cancel failed: ".$e->getMessage()); }
    }

    // Redirect to success page for finalization
    header("Location: " . $baseUrl . "/sub_success_paypal.php?subscription_id=free");
    exit;
}


// Cancel existing subscription before creating new
if (!empty($paypalSubId) && $currentPlan !== $plan) {
    try {
        paypal_api('POST', "/v1/billing/subscriptions/{$paypalSubId}/cancel", ['reason'=>'User switched plans']);
    } catch (Exception $e) {
        error_log("PayPal cancel failed: ".$e->getMessage());
    }
}

// Pricing
$basePrice    = (float)($PLANS[$plan]['price'] ?? 0);
$storageLabel = '+0 GB';
$storagePrice = 0;
if (isset($PLANS['storage_addons'][$storageAddon])) {
    [$storageLabel, $storagePrice] = $PLANS['storage_addons'][$storageAddon];
}
$total = $basePrice + (float)$storagePrice;

// Plan title & desc
$humanTitle = $PLANS[$plan]['label'];
$planName   = "TextWhisper {$humanTitle} {$storageLabel} • Annual";
$planDesc   = "{$humanTitle} with {$storageLabel} (annual). Host: ".htmlspecialchars($_SERVER['HTTP_HOST']);

// Create PayPal plan & subscription
try {
    $productId = paypal_get_or_create_product('TextWhisper Subscription');

    $meta = [
        'plan'          => $plan,             // keep original name
        'storage_addon' => $storageAddon,
        'user_id'       => $userId,
        'env'           => PAYPAL_ENV,
        'ts'            => time(),
    ];

    $planId = paypal_create_annual_plan(
        $productId, $planName, $planDesc,
        number_format($total, 2, '.', ''),
        $meta
    );

    $sub = paypal_create_subscription(
        $planId,
        $baseUrl.'/sub_success_paypal.php',
        $baseUrl.'/sub_cancel_paypal.php',
        $email,
        $meta
    );

    header("Location: ".$sub['approve']);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo "❌ PayPal error: ".htmlspecialchars($e->getMessage());
    exit;
}
