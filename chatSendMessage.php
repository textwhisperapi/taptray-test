<?php
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/db_connect.php";
require_once __DIR__ . "/vendor/autoload.php"; 
//require_once VENDOR_PATH . '/autoload.php';
require_once __DIR__ . "/chatConfig.php";  


use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

sec_session_start();
header('Content-Type: application/json');

if (!login_check($mysqli)) {
    http_response_code(403);
    echo json_encode(["error" => "Not logged in"]);
    exit;
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$token = $_POST['token'] ?? '';
$message = trim($_POST['message'] ?? '');
$alertRequested = isset($_POST['alert']) && (string)$_POST['alert'] === '1';

if (!$token || !$message) {
    http_response_code(400);
    echo json_encode(["error" => "Missing token or message"]);
    exit;
}

// ✅ Chat permission: owner OR direct invite OR owner-root ("above") invite.
function can_chat_list_token(mysqli $mysqli, int $userId, string $username, string $token): bool {
    if ($token === '' || $username === '') return false;

    if ($token === $username) return true;

    $stmt = $mysqli->prepare("SELECT 1 FROM content_lists WHERE token = ? AND owner_id = ? LIMIT 1");
    $stmt->bind_param("si", $token, $userId);
    $stmt->execute();
    $ok = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    if ($ok) return true;

    $stmt = $mysqli->prepare("SELECT email FROM members WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->bind_result($email);
    $stmt->fetch();
    $stmt->close();
    $email = (string)($email ?? "");
    if ($email === "") return false;

    $stmt = $mysqli->prepare("SELECT 1 FROM invitations WHERE listToken = ? AND email = ? LIMIT 1");
    $stmt->bind_param("ss", $token, $email);
    $stmt->execute();
    $direct = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    if ($direct) return true;

    $stmt = $mysqli->prepare("
        SELECT owner.username
        FROM content_lists cl
        JOIN members owner ON owner.id = cl.owner_id
        WHERE cl.token = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $stmt->bind_result($ownerUsername);
    $stmt->fetch();
    $stmt->close();
    $ownerUsername = (string)($ownerUsername ?? "");
    if ($ownerUsername === "") return false;

    $stmt = $mysqli->prepare("SELECT 1 FROM invitations WHERE listToken = ? AND email = ? LIMIT 1");
    $stmt->bind_param("ss", $ownerUsername, $email);
    $stmt->execute();
    $above = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $above;
}

if (!can_chat_list_token($mysqli, (int)$user_id, (string)$username, (string)$token)) {
    http_response_code(403);
    echo json_encode(["error" => "Permission denied"]);
    exit;
}

// âœ… Save message
$mysqli->set_charset("utf8mb4");
$mysqli->query("SET collation_connection = 'utf8mb4_unicode_ci'");

$stmt = $mysqli->prepare("INSERT INTO chat_messages (listToken, username, message) VALUES (?, ?, ?)");
$stmt->bind_param("sss", $token, $username, $message);
$success = $stmt->execute();
$messageId = (int)$stmt->insert_id;
$stmt->close();

echo json_encode(["status" => $success ? "success" : "failed"]);

// Send push notifications to others (if message save succeeded)
if (!$success) exit;

    $pdo = new PDO(
      "mysql:host=" . HOST . ";dbname=" . DATABASE,
      USER,
      PASSWORD
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        




//$host = $_SERVER['HTTP_HOST'] ?? 'textwhisper.com';
//$env = preg_replace('/^www\./', '', explode(':', $host)[0]);
$host = (string)($_SERVER['HTTP_HOST'] ?? 'textwhisper.com');
$host = explode(':', $host)[0];
$defaultEnv = preg_replace('/^www\./', '', $host);
$env = preg_replace('/[^a-z0-9\.\-]/i', '', (string)($_POST['env'] ?? $defaultEnv));
if ($env === '') $env = 'textwhisper.com';


$stmt = $pdo->prepare("
  SELECT ps.username AS target_username, ps.endpoint, ps.p256dh, ps.auth,
         ns.enabled, ns.sound_mode, ns.show_message
  FROM push_subscriptions ps
  LEFT JOIN notification_settings ns
    ON ns.username = ps.username AND ns.list_token = :token
  WHERE ps.username != :username
    AND ps.env = :env
");
$stmt->execute([
  ':token' => $token,
  ':username' => $username,
  ':env' => $env
]);

$subs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$webPush = new WebPush([
  'VAPID' => [
    'subject' => getenv('VAPID_SUBJECT'),
    'publicKey' => getenv('VAPID_PUBLIC_KEY'),
    'privateKey' => getenv('VAPID_PRIVATE_KEY'),
  ]
]);



foreach ($subs as $sub) {
  if (isset($sub['enabled']) && !$sub['enabled']) continue;

  $showMsg = !isset($sub['show_message']) || $sub['show_message'];
  
  // 🔔 Push only when client explicitly marked this as an alert message.
  // Fallback to plaintext scan for legacy clients (unencrypted mode).
  $shouldPush = $alertRequested || (
    strpos($message, "ENC:") !== 0 &&
    (strpos($message, "!") !== false || strpos($message, "🔔") !== false)
  );
  if (!$shouldPush) continue;

  $isEncrypted = strpos($message, "ENC:") === 0;
  $body = ($showMsg && !$isEncrypted)
    ? "$username: " . mb_substr($message, 0, 100)
    : "$username sent an alert";

  $targetUsername = (string)($sub['target_username'] ?? '');
  $targetPath = $targetUsername !== '' ? '/' . rawurlencode($targetUsername) : '/';
  $deepUrl = "https://{$env}{$targetPath}"
    . "?open_chat_token=" . rawurlencode($token)
    . "&open_chat_msg=" . (int)$messageId;

  $payload = json_encode([
    //'title' => "New message",
    'title' => getListNameFromToken($pdo, $token) ?? "New message",

    'body' => $body,
    'url' => $deepUrl,
    'sound' => $sub['sound_mode'] ?? "ding"
  ]);

  $subscription = Subscription::create([
    'endpoint' => $sub['endpoint'],
    'keys' => [
      'p256dh' => $sub['p256dh'],
      'auth' => $sub['auth']
    ]
  ]);

  //$webPush->sendNotification($subscription, $payload);
    $webPush->queueNotification($subscription, $payload);
    foreach ($webPush->flush() as $report) {
      $endpoint = $report->getEndpoint();
      if ($report->isSuccess()) {
        error_log("✅ Push sent to $endpoint");
      } else {
        error_log("❌ Push failed: " . $report->getReason());
      }
    }

}

function getListNameFromToken($pdo, $token) {
    $stmt = $pdo->prepare("SELECT name FROM content_lists WHERE token = ?");
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row['name'] ?? null;
}


?>
