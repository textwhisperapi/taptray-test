<?php
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/functions.php';
sec_session_start();

$mysqli->set_charset("utf8mb4");
$mysqli->query("SET collation_connection = 'utf8mb4_unicode_ci'");


if (!login_check($mysqli) || !isset($_SESSION['username'])) {
  http_response_code(403);
  echo json_encode(["error" => "Not logged in"]);
  exit;
}

$username = $_SESSION['username'];
$message_id = $_POST['message_id'] ?? '';
$emoji = $_POST['emoji'] ?? '';

if (!$message_id || !$emoji) {
  echo json_encode(["error" => "Missing data"]);
  exit;
}

if ($emoji === '__remove__') {
  $stmt = $mysqli->prepare("
    DELETE FROM chat_reactions
    WHERE message_id = ? AND username = ?
  ");
  $stmt->bind_param("is", $message_id, $username);
  $stmt->execute();
  echo json_encode(["status" => "success", "action" => "cleared"]);
  exit;
}


$stmt = $mysqli->prepare("
  INSERT INTO chat_reactions (message_id, username, emoji)
  VALUES (?, ?, ?)
  ON DUPLICATE KEY UPDATE emoji = VALUES(emoji)
");
$stmt->bind_param("iss", $message_id, $username, $emoji);
$success = $stmt->execute();
$stmt->close();

echo json_encode(["status" => $success ? "success" : "fail"]);
