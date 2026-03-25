<?php
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/db_connect.php";
require_once __DIR__ . "/includes/sub_plans.php";
require_once __DIR__ . "/includes/sub_functions.php";
require_once __DIR__ . "/includes/sub_worldline_config.php";

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

use Worldline\Connect\Sdk\V1\Domain\CreateHostedCheckoutRequest;
use Worldline\Connect\Sdk\V1\Domain\Order;
use Worldline\Connect\Sdk\V1\Domain\AmountOfMoney;
use Worldline\Connect\Sdk\V1\Domain\Customer;
use Worldline\Connect\Sdk\V1\Domain\ContactDetails;
use Worldline\Connect\Sdk\V1\Domain\HostedCheckoutSpecificInput;
use Worldline\Connect\Sdk\V1\Domain\CardPaymentMethodSpecificInput;
use Worldline\Connect\Sdk\V1\Domain\Address;

sec_session_start();

$userId    = $_SESSION['user_id'] ?? null;
$plan      = $_POST['plan'] ?? null;
$storageAddonKey = (int) ($_POST['storage_addon'] ?? 0);
$userAddonKey    = (int) ($_POST['user_addon'] ?? 0);

if (!$userId || !$plan || !isset($PLANS[$plan])) {
    die("❌ Invalid input");
}

// --- fetch current member info (also country)
$stmt = $mysqli->prepare("SELECT email, plan, storage_addon, user_addon FROM members WHERE id=? LIMIT 1");
$stmt->bind_param("i", $userId);
$stmt->execute();
$stmt->bind_result($email, $oldPlan, $oldStorage, $oldUsers);
$stmt->fetch();
$stmt->close();

// calculate totals
$oldBase   = (float)($PLANS[$oldPlan]['price'] ?? 0);
$oldAddons = calculateAddonPrice($oldPlan, $oldStorage, $oldUsers);
$oldTotal  = $oldBase + $oldAddons;

$newBase   = (float)($PLANS[$plan]['price'] ?? 0);
$newAddons = calculateAddonPrice($plan, $storageAddonKey, $userAddonKey);
$newTotal  = $newBase + $newAddons;

$currency  = "EUR";

// === Build hosted checkout request ===
$client  = wl_client();
$request = new CreateHostedCheckoutRequest();

// amount
$amount = new AmountOfMoney();
$amount->amount       = intval($newTotal * 100);
$amount->currencyCode = $currency;

$order = new Order();
$order->amountOfMoney = $amount;

// customer
$customer = new Customer();
$customer->merchantCustomerId = (string)$userId;

$contact = new ContactDetails();
$contact->emailAddress = $email;
$customer->contactDetails = $contact;

// ✅ required billing address
$billingAddress = new Address();
//$billingAddress->countryCode = !empty($country) ? $country : "US"; // dynamic fallback
$billingAddress->countryCode = "US"; // fallback until you add real country field
$customer->billingAddress = $billingAddress;

$order->customer = $customer;
$request->order  = $order;

// hosted checkout
$hostedInput = new HostedCheckoutSpecificInput();
$hostedInput->returnUrl = WL_RETURN_URL . "?plan=$plan&user=$userId&addon=$storageAddonKey&users=$userAddonKey";
$hostedInput->locale    = "en_GB";
$hostedInput->showResultPage = false;
$request->hostedCheckoutSpecificInput = $hostedInput;

// recurring
$cardInput = new CardPaymentMethodSpecificInput();
$cardInput->isRecurring = true;
$cardInput->recurringPaymentSequenceIndicator = "first";
$cardInput->tokenize = true; // ✅ request tokenization
$cardInput->requiresApproval = false;
$request->cardPaymentMethodSpecificInput = $cardInput;

// send
$response    = $client->v1()->merchant(WL_MERCHANT_ID)->hostedcheckouts()->create($request);
$redirectUrl = wl_redirect_url($response);

if (!$redirectUrl) {
    die("❌ Worldline did not return redirect URL");
}

// snapshot to sub_sessions
$stmt = $mysqli->prepare("
    INSERT INTO sub_sessions 
      (user_id, plan_id, provider, session_id, storage_addon, user_addon,
       old_plan, old_storage_addon, old_user_addon, old_total, new_total, created_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())
");
$provider = "worldline";

$stmt->bind_param(
    "isssiiisidd",
    $userId,
    $plan,
    $provider,
    $response->hostedCheckoutId,
    $storageAddonKey,
    $userAddonKey,
    $oldPlan,
    $oldStorage,
    $oldUsers,
    $oldTotal,
    $newTotal
);

$stmt->execute();
$stmt->close();

// redirect to payment page
header("Location: " . $redirectUrl);
exit;
