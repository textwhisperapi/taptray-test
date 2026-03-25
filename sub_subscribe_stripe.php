<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/sub_plans.php';
require_once __DIR__ . '/includes/sub_stripe_config.php';
require_once '/home1/wecanrec/textwhisper_vendor/stripe/stripe-php/init.php';


sec_session_start();
\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);


// 🔐 Auth & input
$userId = $_SESSION['user_id'] ?? null;
$email  = $_SESSION['email'] ?? null;
$plan   = $_POST['plan'] ?? null;
$storageUpgrade = (int) ($_POST['storage_upgrade'] ?? 0);
$userUpgrade    = (int) ($_POST['user_upgrade'] ?? 0);

// Handle missing email from session
if (!$email && isset($_SESSION['username'])) {
    $stmt = $mysqli->prepare("SELECT email FROM members WHERE username = ? LIMIT 1");
    $stmt->bind_param("s", $_SESSION['username']);
    $stmt->execute();
    $stmt->bind_result($email);
    $stmt->fetch();
    $_SESSION['email'] = $email;
    $stmt->close();
}

// ✅ Validate session and plan
if (!$userId || !$email) {
    http_response_code(403);
    echo "❌ You must be logged in.";
    exit;
}
if (!isset($PLANS[$plan])) {
    http_response_code(400);
    echo "❌ Unknown plan selected.";
    exit;
}
if ($plan === 'free') {
    // 🔄 Check for existing subscription
    $stmt = $mysqli->prepare("SELECT stripe_sub_id FROM members WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->bind_result($subId);
    $stmt->fetch();
    $stmt->close();

    if (!empty($subId)) {
        try {
            $subscription = \Stripe\Subscription::retrieve($subId);
            $subscription->cancel();

        } catch (Exception $e) {
            error_log("❌ Stripe cancellation failed: " . $e->getMessage());
        }
    }

    // ✅ Update database
    $stmt = $mysqli->prepare("UPDATE members SET plan = 'free', stripe_sub_id = NULL, storage_addon = 0, user_addon = 0, subscribed_at = NOW() WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->close();

    $_SESSION['plan'] = 'free';

    echo "<h2>✅ You are now on the Free Plan</h2>";
    echo "<p>Your subscription has been cancelled and downgraded successfully.</p>";
    echo "<p><a href='/'>← Return to dashboard</a></p>";
    exit;
}


// ✅ Calculate total price
$basePrice = $PLANS[$plan]['price'];
$addonPrice = calculateAddonPrice($plan, $storageUpgrade, $userUpgrade, $STORAGE_UPGRADES, $USER_UPGRADES);
$totalPrice = $PLANS[$plan]['price'] + $addonPrice;



// ✅ Retrieve base Stripe price and product
$stripePriceId = $PLANS[$plan]['stripe_price_id'] ?? null;
if (!$stripePriceId) {
    http_response_code(400);
    echo "❌ Stripe price ID is missing for this plan.";
    exit;
}

try {
    $basePriceObj = \Stripe\Price::retrieve($stripePriceId);
    $stripeProductId = $basePriceObj->product;
} catch (Exception $e) {
    http_response_code(500);
    echo "❌ Failed to retrieve Stripe product: " . $e->getMessage();
    exit;
}

// ✅ Create dynamic price
try {
    //testing
    //$totalPrice = 1000;
    
    $dynamicPrice = \Stripe\Price::create([
        'unit_amount' => $totalPrice * 100, // cents
        'currency' => 'eur',
        'recurring' => ['interval' => 'year'],
        'product' => $stripeProductId,
        'metadata' => [
            'base_plan' => $plan,
            'storage_upgrade' => $storageUpgrade,
            'user_upgrade' => $userUpgrade,
        ]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo "❌ Stripe price creation failed: " . $e->getMessage();
    exit;
}

// ✅ Start Checkout
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$baseUrl = $protocol . $_SERVER['HTTP_HOST'];







try {
    $session = \Stripe\Checkout\Session::create([
        'mode' => 'subscription',
        'payment_method_types' => ['card'],
        'line_items' => [[
            'price' => $dynamicPrice->id,
            'quantity' => 1,
        ]],
        'customer_email' => $email,
        'success_url' => $baseUrl . '/sub_success_stripe.php?sid={CHECKOUT_SESSION_ID}',
        'cancel_url'  => $baseUrl . '/sub_cancel.php',
        'metadata' => [
            'user_id' => $userId,
            'plan' => $plan,
            'storage_upgrade' => $storageUpgrade,
            'user_upgrade' => $userUpgrade
        ]
    ]);
    header("Location: " . $session->url);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo "❌ Stripe error: " . $e->getMessage();
    exit;
}
