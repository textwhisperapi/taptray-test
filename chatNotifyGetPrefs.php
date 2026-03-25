<?php
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/chatConfig.php';
require_once __DIR__ . '/chatAccessControl.php';
sec_session_start();
header("Content-Type: application/json");

if (!login_check($mysqli) || !isset($_SESSION['username'])) {
  http_response_code(403);
  echo json_encode(["error" => "Not logged in"]);
  exit;
}

$token = (string)($_GET['token'] ?? '');
if (!$token) {
  http_response_code(400);
  echo json_encode(["error" => "Missing token"]);
  exit;
}

if (!chat_can_access_list_token($mysqli, $token, (string)$_SESSION['username'], 60)) {
  http_response_code(403);
  echo json_encode(["error" => "Access denied"]);
  exit;
}

$stmt = $mysqli->prepare("
  SELECT enabled, sound_mode, show_message
  FROM notification_settings
  WHERE username = ? AND list_token = ?
  LIMIT 1
");
$username = (string)$_SESSION['username'];
$stmt->bind_param("ss", $username, $token);
$stmt->execute();
$res = $stmt->get_result();
$settings = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$settings) {
  // Provide safe defaults
  echo json_encode([
    "enabled" => 1,
    "sound_mode" => "ding",
    "show_message" => 1
  ]);
} else {
  echo json_encode($settings);
}
