<?php
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/db_connect.php";
require_once __DIR__ . "/includes/sub_plans.php";
require_once __DIR__ . "/includes/sub_functions.php";
require_once __DIR__ . "/includes/sub_worldline_config.php";
//require_once __DIR__ . "/includes/sub_confirm_change.php";

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);


use Worldline\Connect\Sdk\V1\Domain\CreateHostedCheckoutRequest;
use Worldline\Connect\Sdk\V1\Domain\Order;
use Worldline\Connect\Sdk\V1\Domain\AmountOfMoney;
use Worldline\Connect\Sdk\V1\Domain\Customer;
use Worldline\Connect\Sdk\V1\Domain\ContactDetails;
use Worldline\Connect\Sdk\V1\Domain\HostedCheckoutSpecificInput;
use Worldline\Connect\Sdk\V1\Domain\CardPaymentMethodSpecificInput;
use Worldline\Connect\Sdk\V1\Domain\Address;
use Worldline\Connect\Sdk\V1\Domain\CreatePaymentRequest;
use Worldline\Connect\Sdk\V1\Domain\RefundRequest;
use Worldline\Connect\Sdk\V1\Domain\RefundReferences;
use Worldline\Connect\Sdk\V1\Domain\PersonalInformation;
use Worldline\Connect\Sdk\V1\Domain\Name;



sec_session_start();

$userId    = $_SESSION['user_id'] ?? null;
$newPlan   = $_POST['plan'] ?? null;
$plan      = $_POST['plan'] ?? null; 
$storageAddonKey = (int) ($_POST['storage_addon'] ?? 0);
$userAddonKey    = (int) ($_POST['user_addon'] ?? 0);

if (!$userId || !$plan || !isset($PLANS[$plan])) {
    die("❌ Invalid input");
}


function dumpWorldlineResponse($title, $response) {
    echo "<div style='background:#f9f9f9;border:1px solid #ccc;
                padding:12px;margin:15px 0;border-radius:6px;font-family:monospace;'>";
    echo "<h3 style='margin-top:0;'>{$title}</h3>";
    if (is_object($response) || is_array($response)) {
        echo "<pre>" . htmlspecialchars(json_encode($response, JSON_PRETTY_PRINT)) . "</pre>";
    } else {
        echo "<pre>" . htmlspecialchars(print_r($response, true)) . "</pre>";
    }
    echo "</div>";
}



function insertSubSessionGlobal() {
    global $mysqli, $userId, $plan, $sessionId, $contractId, $newPaymentId,
           $storageAddonKey, $userAddonKey,
           $oldPlan, $oldStorage, $oldUsers,
           $oldTotal, $newTotal, $subscribed_at;

    $stmt = $mysqli->prepare("
        INSERT INTO sub_sessions 
          (user_id, plan_id, provider, session_id, contract_id, payment_id, storage_addon, user_addon,
           old_plan, old_storage_addon, old_user_addon, old_total, new_total, subscribed_at, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?, ?, NOW())
    ");
    $provider = "worldline";
    $stmt->bind_param(
        "issssiisiddss",
        $userId,
        $plan,
        $provider,
        $sessionId,
        $contractId,
        $newPaymentId,
        $storageAddonKey,
        $userAddonKey,
        $oldPlan,
        $oldStorage,
        $oldUsers,
        $oldTotal,
        $newTotal,
        $subscribed_at
    );
    $stmt->execute();
    $stmt->close();

}


// --- fetch current subscription
$stmt = $mysqli->prepare("SELECT email, plan, worldline_payment_id, worldline_token, worldline_contract_id, storage_addon, user_addon, subscribed_at, first_name, last_name 
                          FROM members WHERE id=? LIMIT 1");
$stmt->bind_param("i", $userId);
$stmt->execute();
$stmt->bind_result($email, $oldPlan, $oldPaymentId, $worldlineToken, $worldlineContractId, $oldStorage, $oldUsers, $subscribed_at, $firstName, $lastName);
$stmt->fetch();
$stmt->close();


$contractId = $worldlineContractId;

    // generate new contract_id if none exists
    if (empty($contractId)) {
        $contractId = uniqid("contract_{$userId}_");
        $stmt = $mysqli->prepare("UPDATE members SET worldline_contract_id=? WHERE id=?");
        $stmt->bind_param("si", $contractId, $userId);
        $stmt->execute();
        $stmt->close();
    }


$client   = wl_client();
$currency = "EUR";

// --- calculate old/new totals
// $oldBase   = (float)($PLANS[$oldPlan]['price'] ?? 0);
// $oldAddons = calculateAddonPrice($oldPlan, $oldStorage, $oldUsers);
// $oldTotal  = $oldBase + $oldAddons;

// $newBase   = (float)($PLANS[$newPlan]['price'] ?? 0);
// $newAddons = calculateAddonPrice($newPlan, $storageAddonKey, $userAddonKey);
// $newTotal  = $newBase + $newAddons;

// $diff = $newTotal - $oldTotal;


// --- calculate old/new totals with pro-rata if mid-period ---
// --- calculate old/new totals ---
$oldBase   = (float)($PLANS[$oldPlan]['price'] ?? 0);
$oldAddons = calculateAddonPrice($oldPlan, $oldStorage, $oldUsers);
$oldTotal  = $oldBase + $oldAddons;

$newBase   = (float)($PLANS[$plan]['price'] ?? 0);
$newAddons = calculateAddonPrice($plan, $storageAddonKey, $userAddonKey);
$newTotal  = $newBase + $newAddons;

// --- period handling (default yearly unless $PLANS includes 'period')
$period    = $PLANS[$plan]['period'] ?? 'year';
$cycleDays = ($period === 'month') ? 30 : 365;

// --- old contract start + aligned end
$oldStartTs = !empty($subscribed_at) ? strtotime($subscribed_at) : time();
$oldEndTs   = strtotime("+{$cycleDays} days", $oldStartTs);

// --- how many days are left in the old cycle
$usedDays   = floor((time() - $oldStartTs) / 86400);
$unusedDays = max(0, $cycleDays - $usedDays);

// --- prorated credits/charges (only for remaining days until oldEndTs)
$oldProRated = round(($unusedDays / $cycleDays) * $oldTotal, 2);
$newProRated = round(($unusedDays / $cycleDays) * $newTotal, 2);

// --- final difference
$diff = $newProRated - $oldProRated;

// For later display/debug
$newStart = date("Y-m-d");
$newEnd   = date("Y-m-d", $oldEndTs);  // 🔑 align new contract with old contract’s end




$sessionId   = null;
$newPaymentId = null;
$redirectUrl = null;

// ==================================================
// === EXISTING CUSTOMER (auto charge/refund)
// ==================================================
if (!empty($worldlineToken)) {

    if ($diff > 0) {
        // Upgrade: auto-charge difference using saved token
        $paymentRequest = new CreatePaymentRequest();
    
        $amountObj = new AmountOfMoney();
        $amountObj->amount = intval($diff * 100); 
        $amountObj->currencyCode = $currency;
    
        $orderObj = new Order();
        $orderObj->amountOfMoney = $amountObj;
        $orderObj->references = new \Worldline\Connect\Sdk\V1\Domain\OrderReferences();
        //$orderObj->references->merchantReference = "UPGRADE-{$userId}-" . uniqid();
        $orderObj->references->merchantReference = $contractId;

    
        $customerObj = new Customer();
        $customerObj->merchantCustomerId = (string)$userId;
        $customerObj->contactDetails = new ContactDetails();
        $customerObj->contactDetails->emailAddress = $email;
        $orderObj->customer = $customerObj;
    
        $paymentRequest->order = $orderObj;
    
        $cardInput = new CardPaymentMethodSpecificInput();
        $cardInput->token = $worldlineToken; 
        $cardInput->isRecurring = true;
        $cardInput->recurringPaymentSequenceIndicator = "recurring";
        $cardInput->requiresApproval = false;
        $paymentRequest->cardPaymentMethodSpecificInput = $cardInput;
    
        try {
            $paymentResponse = $client->v1()
                ->merchant(WL_MERCHANT_ID)
                ->payments()
                ->create($paymentRequest);
    
            $status       = $paymentResponse->status ?? 'UNKNOWN';
            $sessionId    = $paymentResponse->id ?? uniqid("wlpay_");   // WL request id (can still track as session)
            $newPaymentId = $paymentResponse->payment->id ?? null;      // ✅ real payment id
            $refundId = null;

            
    
            // log payment as positive amount
            // log payment as positive amount
            
            $tokenForPayment = null;
            
            $stmt = $mysqli->prepare("SELECT worldline_token FROM members WHERE id=? LIMIT 1");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $stmt->bind_result($tokenForPayment);
            $stmt->fetch();
            $stmt->close();
            

            $gateway = "worldline";
            $stmt = $mysqli->prepare("
                INSERT INTO sub_payments 
                  (user_id, gateway, token, contract_id, reference, refund_id, amount, currency, status, created_at, session_id) 
                VALUES (?,?,?,?,?,?,?,?,?, NOW(), ?)
            ");
            $stmt->bind_param("isssssdsss", 
                $userId,
                $gateway,
                $tokenForPayment,
                $contractId,
                $newPaymentId,
                $refundId,
                $diff,
                $currency,
                $status,
                $sessionId
            );
            
            $stmt->execute();
            $stmt->close();

    
            //dumpWorldlineResponse("Worldline Charge Response", $paymentResponse);
    
        } catch (\Worldline\Connect\Sdk\V1\ResponseException $e) {
            dumpWorldlineResponse("Worldline Error", $e->getResponse());
            error_log("❌ Worldline payment failed: " . $e->getMessage());
            die("❌ Worldline could not process this upgrade. Please try again.");
        }
    
        $redirectUrl = "/sub_success_worldline.php?plan={$newPlan}&user={$userId}&addon={$storageAddonKey}&users={$userAddonKey}&session={$sessionId}";
    }


    //Refund

    else if ($diff < 0) {
        // Downgrade: auto-refund difference across one or more payments
        $refundAmountTotal = abs($diff); // total to refund in EUR
        $remainingToRefund = intval($refundAmountTotal * 100); // in cents
    
        // 1. Fetch all successful/captured payments for this user (latest first)
        $stmt = $mysqli->prepare("
            SELECT reference,
                   SUM(CASE WHEN refund_id IS NULL THEN amount ELSE 0 END) as paid_amount,
                   SUM(CASE WHEN refund_id IS NOT NULL THEN -amount ELSE 0 END) as already_refunded
              FROM sub_payments
             WHERE user_id=? AND contract_id=?
             GROUP BY reference
             ORDER BY MAX(created_at) DESC
        ");
        $stmt->bind_param("is", $userId, $contractId);

        $stmt->execute();
        $result = $stmt->get_result();
        $payments = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

$sessionId = uniqid("wlrefund_");  // unique marker for sub_sessions

        foreach ($payments as $p) {
            if ($remainingToRefund <= 0) break;
        
            $paymentId         = $p['reference'];
            $capturedCents     = intval($p['paid_amount'] * 100);   // total captured in cents
            $alreadyRefunded   = intval($p['already_refunded']);    // already refunded in cents
            $refundableBalance = $capturedCents - $alreadyRefunded; // max refundable
        
            // skip if nothing left
            if ($refundableBalance <= 0) continue;
        
            // safe clamp
            $refundNow = min($remainingToRefund, $refundableBalance);
        
            // skip if nothing valid
            if ($refundNow <= 0) continue;
        
            // debug trace
            error_log("Refund check: payment={$paymentId}, captured={$capturedCents}, alreadyRefunded={$alreadyRefunded}, balance={$refundableBalance}, refundNow={$refundNow}");
        
            // Build refund request
            $refundRequest = new RefundRequest();
            $amountObj = new AmountOfMoney();
            $amountObj->amount       = $refundNow;
            $amountObj->currencyCode = $currency;
            $refundRequest->amountOfMoney = $amountObj;
            $refundRequest->refundDate    = date('Ymd');
        
            $refundRefs = new RefundReferences();
            $refundRefs->merchantReference = "DOWNGRADE-{$userId}-" . uniqid(); // structured
            $refundRequest->refundReferences = $refundRefs;
    
            $customer = new Customer();
            $customer->merchantCustomerId = (string)$userId;
            $customer->contactDetails = new ContactDetails();
            $customer->contactDetails->emailAddress = $email ?? '';
            $refundRequest->customer = $customer;

        
            try {
                $refundResponse = $client->v1()
                    ->merchant(WL_MERCHANT_ID)
                    ->payments()
                    ->refund($paymentId, $refundRequest);
        
                $status   = $refundResponse->status ?? 'REFUND_REQUESTED';
                $refundId = $refundResponse->id ?? uniqid("wlrefund_");
        
                $gateway = "worldline";
                $refundAmountNegative = -1 * ($refundNow / 100.0); // back to EUR


                $tokenForPayment = null;
                
                $stmt = $mysqli->prepare("SELECT worldline_token FROM members WHERE id=? LIMIT 1");
                $stmt->bind_param("i", $userId);
                $stmt->execute();
                $stmt->bind_result($tokenForPayment);
                $stmt->fetch();
                $stmt->close();
        
                $stmt = $mysqli->prepare("
                    INSERT INTO sub_payments 
                      (user_id, gateway, token, contract_id, reference, refund_id, amount, currency, status, created_at, session_id) 
                    VALUES (?,?,?,?,?,?,?,?,?, NOW(), ?)
                ");
                $stmt->bind_param("isssssdsss",
                    $userId,
                    $gateway,
                    $tokenForPayment,
                    $contractId,
                    $paymentId,
                    $refundId,
                    $refundAmountNegative,
                    $currency,
                    $status,
                    $sessionId
                );
                $stmt->execute();
                if ($stmt->affected_rows <= 0) {
                    error_log("⚠️ Refund log insert failed: " . $stmt->error);
                }
                $stmt->close();

        
                $remainingToRefund -= $refundNow;
        
            } catch (\Worldline\Connect\Sdk\V1\ResponseException $e) {
                dumpWorldlineResponse("Worldline Error", $e->getResponse());
                error_log("❌ Worldline refund failed for payment {$paymentId}: " . $e->getMessage());
                die("❌ Refund could not be processed right now.");
            }
        }

    
        $redirectUrl = "/sub_success_worldline.php?plan={$newPlan}&user={$userId}&addon={$storageAddonKey}&users={$userAddonKey}&session={$sessionId}";
    }


    
    else {
        // No change
        $sessionId = uniqid("wlnodiff_");
        $redirectUrl = "/sub_success_worldline.php?plan={$newPlan}&user={$userId}&addon={$storageAddonKey}&users={$userAddonKey}&session={$sessionId}";
        //$redirectUrl = "/sub_success_worldline.php?plan={$newPlan}&user={$userId}&addon={$storageAddonKey}&users={$userAddonKey}&session={$sessionId}&paymentId={$sessionId}";

    }

} else {
    // ==================================================
    // === NEW CUSTOMER (hosted checkout)
    // ==================================================
    $request = new CreateHostedCheckoutRequest();

    $amount = new AmountOfMoney();
    $amount->amount       = intval($newTotal * 100);
    $amount->currencyCode = $currency;

    $order = new Order();
    $order->amountOfMoney = $amount;
    $order->references = new \Worldline\Connect\Sdk\V1\Domain\OrderReferences();
    $order->references->merchantReference = $contractId;

    $customer = new Customer();
    $customer->merchantCustomerId = (string)$userId;
    $contact = new ContactDetails();
    $contact->emailAddress = $email;
    $customer->contactDetails = $contact;

    $billingAddress = new Address();
    $billingAddress->countryCode = "US";
    $customer->billingAddress = $billingAddress;

    $order->customer = $customer;
    $request->order  = $order;

    // 👇 Always create your own sessionId (for consistency)
    $sessionId = uniqid("wlpay_");

    $hostedInput = new HostedCheckoutSpecificInput();
    $hostedInput->returnUrl = WL_RETURN_URL 
        . "?plan={$plan}&user={$userId}&addon={$storageAddonKey}&users={$userAddonKey}&session={$sessionId}";
    $hostedInput->locale    = "en_GB";
    $hostedInput->showResultPage = false;
    $request->hostedCheckoutSpecificInput = $hostedInput;

    $cardInput = new CardPaymentMethodSpecificInput();
    $cardInput->isRecurring = true;
    $cardInput->recurringPaymentSequenceIndicator = "first";
    $cardInput->tokenize = true;
    $cardInput->requiresApproval = false;
    $request->cardPaymentMethodSpecificInput = $cardInput;

    $response    = $client->v1()->merchant(WL_MERCHANT_ID)->hostedcheckouts()->create($request);
    $redirectUrl = wl_redirect_url($response);

    if (!$redirectUrl) {
        die("❌ Worldline did not return redirect URL");
    }

    // Store new session in DB for tracking
    // $stmt = $mysqli->prepare("INSERT INTO sub_sessions 
    //     (session_id, user_id, plan_id, storage_addon, user_addon, new_total, contract_id, created_at) 
    //     VALUES (?,?,?,?,?,?,?,NOW())");
    // $stmt->bind_param(
    //     "siiiids",
    //     $sessionId,
    //     $userId,
    //     $plan,
    //     $storageAddonKey,
    //     $userAddonKey,
    //     $newTotal,
    //     $contractId
    // );
    // $stmt->execute();
    // $stmt->close();

    $newPaymentId = null;
}


// ✅ Always snapshot into sub_sessions

error_log("🔎 sub_subscribe_worldline: user={$userId}, plan={$plan}, sessionId={$sessionId}, diff={$diff}");

    $stmt = $mysqli->prepare("
        INSERT INTO sub_sessions 
          (user_id, plan_id, provider, session_id, contract_id, payment_id, storage_addon, user_addon,
           old_plan, old_storage_addon, old_user_addon, old_total, new_total, subscribed_at, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?, NOW())
    ");
    $provider = "worldline";
    $stmt->bind_param(
        "isssssiisiidds",
        $userId,
        $plan,
        $provider,
        $sessionId,
        $contractId,
        $newPaymentId,
        $storageAddonKey,
        $userAddonKey,
        $oldPlan,
        $oldStorage,
        $oldUsers,
        $oldTotal,
        $newTotal,
        $subscribed_at
    );

    $stmt->execute();
    $stmt->close();



// ✅ redirect
header("Location: " . $redirectUrl);
exit;
