<?php
//require_once __DIR__ . '/vendor/autoload.php'; nott needed ...is already in chatconfig.php
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/chatConfig.php';
require_once __DIR__ . '/chatAccessControl.php';


sec_session_start();
header("Content-Type: application/json");

if (!login_check($mysqli) || !isset($_SESSION['username'])) {
  error_log("🚫 Not logged in");
  http_response_code(403);
  echo json_encode(["error" => "Not logged in"]);
  exit;
}

$username = $_SESSION['username'];
$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!$data) {
  error_log("❌ Failed to parse JSON: $raw");
  http_response_code(400);
  echo json_encode(["error" => "Invalid JSON"]);
  exit;
}

error_log("✅ Subscription input received from $username");

$token = $data['token'] ?? null;
$access = getUserAccessStatus($mysqli, $token, $username);

if ($access['status'] === 'denied') {
  http_response_code(403);
  echo json_encode(['error' => 'Access denied']);
  return;
}

// ✅ Log subscription keys
$isValid = isset($data['endpoint'], $data['keys']['p256dh'], $data['keys']['auth']);
error_log("📥 Subscription input valid? " . ($isValid ? "YES" : "NO"));
error_log("📥 Endpoint: " . ($data['endpoint'] ?? 'MISSING'));
error_log("📥 p256dh: " . ($data['keys']['p256dh'] ?? 'MISSING'));
error_log("📥 auth: " . ($data['keys']['auth'] ?? 'MISSING'));

// ✅ Save push subscription if valid
// if ($isValid) {
//   $stmt = $mysqli->prepare("REPLACE INTO push_subscriptions (username, endpoint, p256dh, auth) VALUES (?, ?, ?, ?)");
//   $stmt->bind_param("ssss", $username, $data['endpoint'], $data['keys']['p256dh'], $data['keys']['auth']);
//   $stmt->execute();
//   $stmt->close();
//   error_log("✅ Push subscription saved");
// }

// ✅ Save push subscription if valid
if ($isValid) {
  $env = preg_replace('/[^a-z0-9\.\-]/i', '', $data['env'] ?? 'textwhisper.com');
  //$env = 'geirigrimmi.com';

  $stmt = $mysqli->prepare("
    REPLACE INTO push_subscriptions (username, endpoint, p256dh, auth, env)
    VALUES (?, ?, ?, ?, ?)
  ");
  $stmt->bind_param("sssss", $username, $data['endpoint'], $data['keys']['p256dh'], $data['keys']['auth'], $env);
  $stmt->execute();
  $stmt->close();

  error_log("✅ Push subscription saved with env: $env");
}


// ✅ Save notification settings if token is present
if ($token) {
  $enabled = isset($data['enabled']) ? (int)$data['enabled'] : 1;
  $sound = in_array($data['sound_mode'] ?? '', ['ding', 'silent']) ? $data['sound_mode'] : 'ding';
  $show = isset($data['show_message']) ? (int)$data['show_message'] : 1;

  try {
    $pdo = new PDO(
      "mysql:host=" . HOST . ";dbname=" . DATABASE,
      USER,
      PASSWORD
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        

    $stmt = $pdo->prepare("
      REPLACE INTO notification_settings (username, list_token, enabled, sound_mode, show_message)
      VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$username, $token, $enabled, $sound, $show]);
    error_log("✅ Notification settings saved");

  } catch (Exception $e) {
    error_log("❌ DB error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["error" => "Database error"]);
    exit;
  }
}

echo json_encode(["status" => "success"]);
