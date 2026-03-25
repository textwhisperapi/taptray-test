<?php
ini_set('display_errors',1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/sub_worldline_config.php';

use Worldline\Connect\Sdk\V1\Domain\CreateHostedCheckoutRequest;
use Worldline\Connect\Sdk\V1\Domain\HostedCheckoutSpecificInput;
use Worldline\Connect\Sdk\V1\Domain\Order;
use Worldline\Connect\Sdk\V1\Domain\Customer;
use Worldline\Connect\Sdk\V1\Domain\ContactDetails;
use Worldline\Connect\Sdk\V1\Domain\Address;
use Worldline\Connect\Sdk\V1\Domain\AmountOfMoney;

sec_session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: /login.php");
    exit;
}

$userId = $_SESSION['user_id'];

// ----------------------------------------------------
// ✅ Fetch current subscription info
// ----------------------------------------------------
$stmt = $mysqli->prepare("SELECT email, worldline_token, card_brand, card_last4, card_expiry FROM members WHERE id=? LIMIT 1");
$stmt->bind_param("i",$userId);
$stmt->execute();
$stmt->bind_result($email, $worldlineToken, $brand, $last4, $expiry);
$stmt->fetch();
$stmt->close();

// Display card info from DB
$currentCardInfo = [
  'brand'    => $brand ?: "⚠️ No card details available",
  'lastFour' => $last4 ?: "",
  'expiry'   => $expiry ?: ""
];


// ----------------------------------------------------
// ✅ Fetch all tokens for this user from sub_tokens
// ----------------------------------------------------
$allTokens = [];
$stmt = $mysqli->prepare("
    SELECT token, brand, last4, expiry, is_default 
    FROM sub_tokens 
    WHERE user_id=? 
    ORDER BY is_default DESC, updated_at DESC
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $allTokens[] = $row;
}
$stmt->close();





// ----------------------------------------------------
// ✅ Init WL client
// ----------------------------------------------------
$client = wl_client();
if (!$client) {
    die("❌ wl_client() failed");
}

// helper: raw REST call for endpoints not in SDK
function wl_rest_request($method, $path) {
    $apiKeyId  = WL_API_KEY_ID;
    $secret    = WL_API_SECRET;
    $endpoint  = WL_ENDPOINT;
    $merchant  = WL_MERCHANT_ID;

    $url = rtrim($endpoint, '/') . "/v1/{$merchant}{$path}";
    $date = gmdate("D, d M Y H:i:s T");
    $contentType = "application/json";

    $stringToSign = "{$method}\n\n{$contentType}\n{$date}\n{$path}";
    $hmac = base64_encode(hash_hmac("sha256", $stringToSign, base64_decode($secret), true));
    $authHeader = "GCS v1HMAC:{$apiKeyId}:{$hmac}";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Date: {$date}",
        "Authorization: {$authHeader}",
        "Content-Type: {$contentType}"
    ]);
    $response = curl_exec($ch);
    if ($response === false) {
        throw new Exception("cURL error: " . curl_error($ch));
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status < 200 || $status >= 300) {
        throw new Exception("Worldline API error (HTTP {$status}): {$response}");
    }
    return json_decode($response, true);
}

// ----------------------------------------------------
// ✅ If update requested → Hosted Checkout with new token
// ----------------------------------------------------
// ✅ If update requested → Hosted Checkout with new token
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['updateCard'])) {
    $checkoutRequest = new CreateHostedCheckoutRequest();

    // Hosted checkout setup
    $hcsInput = new HostedCheckoutSpecificInput();
    $hcsInput->variant   = "hostedTokenization";
    $hcsInput->returnUrl = WL_RETURN_URL;
    $checkoutRequest->hostedCheckoutSpecificInput = $hcsInput;

    // ✅ Important: request tokenization
    $cardInput = new \Worldline\Connect\Sdk\V1\Domain\CardPaymentMethodSpecificInput();
    $cardInput->tokenize = true;
    $checkoutRequest->cardPaymentMethodSpecificInput = $cardInput;

    // WL still requires dummy amount
    $order = new Order();
    $amount = new AmountOfMoney();
    $amount->amount       = 1; // = 0.01 EUR dummy
    $amount->currencyCode = "EUR";
    $order->amountOfMoney = $amount;

    // Customer info
    $customer = new Customer();
    $customer->merchantCustomerId = (string)$userId;
    $contact = new ContactDetails();
    $contact->emailAddress = $email;
    $customer->contactDetails = $contact;

    $address = new Address();
    $address->countryCode = "US";
    $customer->billingAddress = $address;

    $order->customer = $customer;
    $checkoutRequest->order = $order;

    // 🚀 Create hosted checkout session
    $response = $client->v1()
        ->merchant(WL_MERCHANT_ID)
        ->hostedCheckouts()
        ->create($checkoutRequest);

    $redirectUrl = wl_redirect_url($response);
    header("Location: " . $redirectUrl);
    exit;
}



// ----------------------------------------------------
// ✅ Fetch token details (WL API) for display
// ----------------------------------------------------
// $allTokens = [];
// if (!empty($worldlineToken)) {
//     try {
//         $data = wl_rest_request("GET", "/tokens/{$worldlineToken}");
//         $allTokens = [$data]; // wrap in array so table still works
//     } catch (Exception $e) {
//         error_log("⚠️ Could not fetch token details: " . $e->getMessage());
//         $allTokens = [];
//     }
// }
?>
<!DOCTYPE html>
<html lang="en">
<meta name="viewport" content="width=device-width, initial-scale=1">
<head>
  <meta charset="UTF-8">
  <title>Update Card</title>
  <style>
    body { font-family: Arial, sans-serif; background: #f9fafb; margin: 0; padding: 0; }
    .container { max-width: 900px; margin: 80px auto 40px auto; background: #fff;
      padding: 25px 30px; border-radius: 12px; box-shadow: 0 3px 10px rgba(0,0,0,0.08);}
    h2 { margin-top: 0; color: #222; }
    .card-box { background: #f3f4f6; border: 1px solid #e5e7eb; border-radius: 8px;
      padding: 15px 20px; margin-bottom: 20px;}
    .card-box p { margin: 6px 0; font-size: 15px; color: #333; }
    .btn { background: #2563eb; color: #fff; padding: 10px 18px; font-size: 15px;
      font-weight: bold; border: none; border-radius: 6px; cursor: pointer;
      transition: background 0.2s ease; text-decoration: none; }
    .btn:hover { background: #1d4ed8; }
    h3 { margin-top: 35px; margin-bottom: 15px; color: #111827; }
    .note { font-size: 13px; color: #6b7280; margin-top: 6px; }
    .back-link { display: inline-block; margin-bottom: 20px; color: #374151;
      font-size: 14px; text-decoration: none; font-weight: 500; }
    .back-link:hover { color: #111827; text-decoration: underline; }

    /* Responsive card list */
    .card-list {
      display: flex;
      flex-wrap: wrap;
      gap: 15px;
    }
    .card-item {
      flex: 1 1 calc(50% - 15px); /* two per row on tablet/desktop */
      min-width: 240px;
      background: #f9fafb;
      border: 1px solid #e5e7eb;
      border-radius: 8px;
      padding: 15px;
      box-sizing: border-box;
    }
    .card-item p { margin: 6px 0; font-size: 14px; }
    .card-item .default { color: green; font-weight: bold; }

    /* On very small screens, stack full width */
    @media (max-width: 600px) {
      .card-item { flex: 1 1 100%; }
    }
  </style>
</head>
<body>
  <div class="container">
    <a href="javascript:history.back()" class="back-link">← Back</a>
    <h2>Update your payment method</h2>

    <div class="card-box">
      <p><strong>Card Brand:</strong> <?= htmlspecialchars($currentCardInfo['brand']) ?></p>
      <p><strong>Last 4 Digits:</strong> <?= htmlspecialchars($currentCardInfo['lastFour']) ?></p>
      <p><strong>Expiry:</strong> <?= htmlspecialchars($currentCardInfo['expiry']) ?></p>
    </div>

    <form method="post">
      <button type="submit" class="btn" name="updateCard">🔄 Update Card</button>
    </form>

    <h3>All Saved Cards</h3>
    <?php if (empty($allTokens)): ?>
      <p class="note">ℹ️ No saved cards found for this account.</p>
    <?php else: ?>
      <div class="card-list">
        <?php foreach ($allTokens as $t): ?>
        <div class="card-item">
          <p><strong><?= htmlspecialchars($t['brand']) ?></strong> ••••<?= htmlspecialchars($t['last4']) ?></p>
          <p>Expiry: <?= htmlspecialchars($t['expiry']) ?></p>
          <?php if ($t['is_default']): ?>
            <p class="default">✅ Default</p>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>

