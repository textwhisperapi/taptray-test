<?php
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/db_connect.php";

sec_session_start();
header("Content-Type: application/json");

if (!login_check($mysqli)) {
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "Not logged in"]);
    exit;
}

$id = (int)($_POST["id"] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid message id"]);
    exit;
}

$username = (string)($_SESSION["username"] ?? "");
if ($username === "") {
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "Not logged in"]);
    exit;
}

$mysqli->set_charset("utf8mb4");
$mysqli->query("SET collation_connection = 'utf8mb4_unicode_ci'");

// Only own messages, only recent ones.
$stmt = $mysqli->prepare("
  SELECT id
  FROM chat_messages
  WHERE id = ?
    AND username = ?
    AND created_at >= (NOW() - INTERVAL 60 MINUTE)
  LIMIT 1
");
$stmt->bind_param("is", $id, $username);
$stmt->execute();
$stmt->store_result();
$canDelete = $stmt->num_rows > 0;
$stmt->close();

if (!$canDelete) {
  http_response_code(403);
  echo json_encode(["status" => "error", "message" => "Message too old or not yours"]);
  exit;
}

$mysqli->begin_transaction();
try {
  $stmt = $mysqli->prepare("DELETE FROM chat_reactions WHERE message_id = ?");
  $stmt->bind_param("i", $id);
  $stmt->execute();
  $stmt->close();

  $stmt = $mysqli->prepare("DELETE FROM chat_messages WHERE id = ? AND username = ?");
  $stmt->bind_param("is", $id, $username);
  $stmt->execute();
  $affected = $stmt->affected_rows;
  $stmt->close();

  if ($affected < 1) {
    throw new RuntimeException("Delete failed");
  }

  $mysqli->commit();
  echo json_encode(["status" => "OK"]);
} catch (Throwable $e) {
  $mysqli->rollback();
  http_response_code(500);
  echo json_encode(["status" => "error", "message" => "Unable to delete message"]);
}

