<?php
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/db_connect.php";
require_once __DIR__ . "/includes/sub_stripe_config.php";
require_once __DIR__ . "/includes/sub_paypal_config.php";
require_once __DIR__ . "/includes/sub_worldline_config.php";
require_once '/home1/wecanrec/textwhisper_vendor/stripe/stripe-php/init.php';

// Worldline endpoint verification
if (isset($_SERVER['HTTP_X_GCS_WEBHOOKS_ENDPOINT_VERIFICATION'])) {
    header('Content-Type: text/plain');
    echo $_SERVER['HTTP_X_GCS_WEBHOOKS_ENDPOINT_VERIFICATION'];
    exit;
}


ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL & ~E_DEPRECATED);



//Purpose of sub_webhook.php
//This file will:
// Listen for Stripe events like:
// checkout.session.completed
// customer.subscription.updated
// customer.subscription.deleted
// Be secure using Stripe's signature verification
// Update your members table when:
// A subscription is canceled
// A plan is changed
// A payment fails or succeeds
//
// Additionally, it will:
// Listen for Worldline webhooks like:
// payment.completed
// payment.canceled
// Be secure using Worldline's signature verification
// Update your members table when:
// A subscription (payment token) is created
// A payment is successful or failed


// ----------------------------------------------------
// 🔎 Detect Worldline vs Stripe
// ----------------------------------------------------
if (isset($_SERVER['HTTP_X_GCS_SIGNATURE'])) {
    // ✅ Handle Worldline webhook
    //require_once __DIR__ . "/../sub_worldline_config.php";

    $payload   = @file_get_contents("php://input");
    $signature = $_SERVER['HTTP_X_GCS_SIGNATURE'] ?? '';
    $keyId     = $_SERVER['HTTP_X_GCS_KEYID'] ?? '';

    // 🔐 Verify Worldline webhook signature
    if ($keyId !== WL_WEBHOOK_KEYID) {
        http_response_code(400);
        exit("Invalid webhook keyId");
    }

    $calcSignature = base64_encode(hash_hmac('sha256', $payload, WL_WEBHOOK_SECRET, true));
    if (!hash_equals($calcSignature, $signature)) {
        http_response_code(400);
        exit("Invalid webhook signature");
    }

    $event = json_decode($payload, true);
    if (!$event) {
        http_response_code(400);
        exit("Invalid JSON");
    }
    
// Log the json
error_log("🌍 WL Webhook Raw: " . json_encode($event, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));    

    $eventId   = $event['id'] ?? uniqid("worldline_", true);
    $eventType = $event['type'] ?? 'unknown';

    // 🔁 Skip duplicate events
    $stmt = $mysqli->prepare("SELECT COUNT(*) FROM webhook_events WHERE event_id = ?");
    $stmt->bind_param("s", $eventId);
    $stmt->execute();
    $stmt->bind_result($exists);
    $stmt->fetch();
    $stmt->close();

    if ($exists) {
        http_response_code(200);
        exit("Duplicate Worldline webhook ignored");
    }

    $payloadJson = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    // ✅ Log to DB
    $stmt = $mysqli->prepare("INSERT INTO webhook_events (event_id, event_type, payload) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $eventId, $eventType, $payloadJson);
    $stmt->execute();
    $stmt->close();

    // 🧠 Handle Worldline events
    if ($eventType === "payment.completed") {
        // Just log it
        error_log("ℹ️ Worldline payment.completed (status=$status, token=$token, ref=$merchantRef)");
    
        // ✅ Mark as handled
        $stmt = $mysqli->prepare("UPDATE webhook_events SET handled = 1 WHERE event_id = ?");
        $stmt->bind_param("s", $eventId);
        $stmt->execute();
        $stmt->close();

    } elseif ($eventType === "token.updatedxxx") {
        $tokenId = $event['token']['id'];
        $alias   = $event['token']['card']['alias'] ?? null;
        $expiry  = $event['token']['card']['data']['cardWithoutCvv']['expiryDate'] ?? null;
        $brandId = $event['token']['paymentProductId'] ?? null;
    
        $brandMap = [1=>"Visa",3=>"Mastercard",117=>"Amex",114=>"Discover",122=>"Diners Club",125=>"JCB"];
        $brand = $brandMap[$brandId] ?? "Card";
        $last4 = $alias ? substr($alias, -4) : null;
    
        $merchantCustomerId = $event['token']['card']['customer']['merchantCustomerId'] ?? null;
        if ($merchantCustomerId) {
            $userId = (int)$merchantCustomerId;
    
            $stmt = $mysqli->prepare("
                UPDATE members 
                   SET worldline_token=?, card_brand=?, card_last4=?, card_expiry=? 
                 WHERE id=?");
            $stmt->bind_param("ssssi", $tokenId, $brand, $last4, $expiry, $userId);
            $stmt->execute();
            $stmt->close();
        }

    } elseif ($eventType === "payment.canceled") {
        // ❌ User canceled during checkout — no DB update, just log

        // ✅ Mark as handled
        $stmt = $mysqli->prepare("UPDATE webhook_events SET handled = 1 WHERE event_id = ?");
        $stmt->bind_param("s", $eventId);
        $stmt->execute();
        $stmt->close();

    } elseif ($eventType === "payment.failed") {
        // ❌ Payment attempt failed (e.g. declined card) — no DB update, just log

        // ✅ Mark as handled
        $stmt = $mysqli->prepare("UPDATE webhook_events SET handled = 1 WHERE event_id = ?");
        $stmt->bind_param("s", $eventId);
        $stmt->execute();
        $stmt->close();
    
    } elseif ($eventType === "payment.pending_approval") {
        $payment   = $event['payment'] ?? [];
        $paymentId = $payment['id'] ?? null;
    
        if ($paymentId) {
            try {
                $client = wl_client();
                $client->v1()
                    ->merchant(WL_MERCHANT_ID)
                    ->payments()
                    ->cancel($paymentId);
    
                error_log("✅ Canceled dummy Worldline payment {$paymentId} after card update");
            } catch (Exception $e) {
                error_log("❌ Cancel failed for {$paymentId}: " . $e->getMessage());
            }
        }
    
        // ✅ Mark as handled
        $stmt = $mysqli->prepare("UPDATE webhook_events SET handled = 1 WHERE event_id = ?");
        $stmt->bind_param("s", $eventId);
        $stmt->execute();
        $stmt->close();
    }


    http_response_code(200);
    exit("Worldline webhook received: $eventType");
}




// ----------------------------------------------------
// ✅ Handle Stripe webhook (original logic below)
// ----------------------------------------------------
\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

// 🔐 Webhook secret from Stripe dashboard
$endpoint_secret = STRIPE_WEBHOOK_SECRET;

$payload = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$event = null;

try {
    $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $endpoint_secret);
} catch (\UnexpectedValueException $e) {
    http_response_code(400);
    exit("Invalid payload");
} catch (\Stripe\Exception\SignatureVerificationException $e) {
    http_response_code(400);
    exit("Invalid signature");
}

$eventId = $event->id;
$eventType = $event->type;
$payloadJson = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// 🔁 Skip duplicate events
$stmt = $mysqli->prepare("SELECT COUNT(*) FROM webhook_events WHERE event_id = ?");
$stmt->bind_param("s", $eventId);
$stmt->execute();
$stmt->bind_result($exists);
$stmt->fetch();
$stmt->close();

if ($exists) {
    http_response_code(200);
    exit("Duplicate webhook ignored");
}

// ✅ Log to DB
$stmt = $mysqli->prepare("INSERT INTO webhook_events (event_id, event_type, payload) VALUES (?, ?, ?)");
$stmt->bind_param("sss", $eventId, $eventType, $payloadJson);
$stmt->execute();
$stmt->close();

// 🧠 Handle event logic
$data = $event->data->object;

switch ($eventType) {

    case 'customer.subscription.deleted':
        $subscriptionId = $data->id;

        $stmt = $mysqli->prepare("UPDATE members SET plan = 'free', stripe_sub_id = NULL WHERE stripe_sub_id = ?");
        $stmt->bind_param("s", $subscriptionId);
        $stmt->execute();
        $stmt->close();

        // ✅ Mark as handled
        $stmt = $mysqli->prepare("UPDATE webhook_events SET handled = 1 WHERE event_id = ?");
        $stmt->bind_param("s", $eventId);
        $stmt->execute();
        $stmt->close();
        break;

    case 'customer.subscription.updated':
        // Add logic if needed later
        break;

    case 'checkout.session.completed':
        // Already handled via return page
        break;

    case 'invoice.payment_failed':
        // Optional logic
        break;

    default:
        // Unhandled event type
        break;
}

http_response_code(200);
echo "Webhook received: $eventType";
