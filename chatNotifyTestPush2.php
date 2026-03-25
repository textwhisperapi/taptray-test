<?php
// chatNotifyTestPush2.php — Manual test trigger for push notifications

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/chatConfig.php';
require_once __DIR__ . '/vendor/autoload.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

sec_session_start();
header('Content-Type: application/json');

// ✅ Require login
if (!login_check($mysqli) || !isset($_SESSION['username'])) {
  http_response_code(403);
  echo json_encode(['error' => 'Not logged in']);
  exit;
}

$username = $_SESSION['username'];

// 🔐 Optional: restrict to admin
// if ($username !== 'admin') {
//   http_response_code(403);
//   echo json_encode(['error' => 'Admin only']);
//   exit;
// }

$origin = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https://" : "http://") . $_SERVER['HTTP_HOST'];

// ✅ Fetch other users' subscriptions
$stmt = $mysqli->prepare("SELECT endpoint, p256dh, auth FROM push_subscriptions WHERE username != ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

$subs = [];
while ($row = $result->fetch_assoc()) {
  $subs[] = $row;
}
$stmt->close();

if (empty($subs)) {
  echo json_encode(['status' => 'no_subscribers']);
  exit;
}

// ✅ Setup VAPID
$webPush = new WebPush([
  'VAPID' => [
    'subject' => getenv('VAPID_SUBJECT'),
    'publicKey' => getenv('VAPID_PUBLIC_KEY'),
    'privateKey' => getenv('VAPID_PRIVATE_KEY'),
  ]
]);


$count = 0;
$expired = 0;

// ✅ Send notifications
foreach ($subs as $sub) {
  try {
    $subscription = Subscription::create([
      'endpoint' => $sub['endpoint'],
      'keys' => [
        'p256dh' => $sub['p256dh'],
        'auth' => $sub['auth']
      ]
    ]);

    $payload = json_encode([
      'title' => '🔔 Test Notification',
      'body' => 'This is a push test from TextWhisper.',
      'url' => $origin . '/',
      'sound' => 'ding'
    ]);

   // $webPush->sendNotification($subscription, $payload);
    $webPush->queueNotification($subscription, $payload);
    $count++;
  } catch (Exception $e) {
    error_log("❌ Push send error: " . $e->getMessage());
  }
}

// ✅ Process delivery results
foreach ($webPush->flush() as $report) {
  $endpoint = $report->getEndpoint();
  if ($report->isSuccess()) {
    error_log("✅ Push sent: $endpoint");
  } else {
    error_log("❌ Push failed: " . $report->getReason());

    if ($report->isSubscriptionExpired()) {
      // 🧹 Remove expired endpoint
      $del = $mysqli->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?");
      $del->bind_param("s", $endpoint);
      $del->execute();
      $del->close();
      error_log("🧹 Removed expired subscription: $endpoint");
      $expired++;
    }
  }
}

// ✅ Return summary
echo json_encode([
  'status' => 'done',
  'sent' => $count,
  'expired_removed' => $expired
]);
