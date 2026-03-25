<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/sub_paypal_config.php';

sec_session_start();

$userId    = $_SESSION['user_id'] ?? null;
$urlSubId  = $_GET['subscription_id'] ?? null;
$allowSandboxUrl = (defined('PAYPAL_ENV') && PAYPAL_ENV === 'sandbox' && $urlSubId);

if (!$userId && !$allowSandboxUrl) { http_response_code(403); exit('❌ Not logged in.'); }

// find sub id (DB first, URL fallback in sandbox)
$dbSubId = null;
if ($userId) {
  $stmt = $mysqli->prepare("SELECT paypal_sub_id FROM members WHERE id=? LIMIT 1");
  $stmt->bind_param("i", $userId);
  $stmt->execute(); $stmt->bind_result($dbSubId); $stmt->fetch(); $stmt->close();
}
$subId = $dbSubId ?: $urlSubId;

echo "<link rel='stylesheet' href='/sub_settings.css?v=".time()."'>";

if (!$subId) {
  echo "<div class='success-container'><h2>ℹ️ Nothing to cancel</h2><p>No PayPal subscription found.</p><p><a href='/'>← Return</a></p></div>";
  exit;
}

try {
  $sub    = paypal_api('GET', "/v1/billing/subscriptions/{$subId}");
  $status = strtoupper($sub['status'] ?? 'UNKNOWN');

  if (!in_array($status, ['CANCELLED','EXPIRED'], true)) {
    paypal_api('POST', "/v1/billing/subscriptions/{$subId}/cancel", ['reason'=>'User requested']);
    $status = 'CANCELLED';
  }

  if ($userId) {
    $stmt = $mysqli->prepare("
      UPDATE members
         SET plan='free',
             storage_addon=0,
             user_addon=0,
             subscription_status='canceled',
             paypal_sub_id=NULL
       WHERE id=?");
    $stmt->bind_param("i", $userId);
    $stmt->execute(); $stmt->close();
    $_SESSION['plan'] = 'free';
  }

  echo "<div class='success-container'><h2>✅ Subscription cancelled</h2><p>Status: <strong>canceled</strong>.</p><p><a href='/'>← Return</a></p></div>";

} catch (Exception $e) {
  error_log("PayPal cancel error: ".$e->getMessage());
  echo "<div class='success-container'><h2>❌ Couldn’t cancel</h2><p>".htmlspecialchars($e->getMessage())."</p><p><a href='/'>← Return</a></p></div>";
}
