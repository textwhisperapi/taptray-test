<?php
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/db_connect.php";

sec_session_start();
header('Content-Type: application/json');

$user_id = $_SESSION['user_id'] ?? null;
$token = $_POST['token'] ?? '';
$email = $_POST['email'] ?? '';
$newRole = $_POST['role'] ?? '';

if (!$user_id || !$token || !$email || !$newRole) {
  http_response_code(400);
  echo json_encode(["status" => "error", "message" => "Missing parameters"]);
  exit;
}

// ✅ Check if user is owner
$stmt = $mysqli->prepare("SELECT owner_id FROM content_lists WHERE token = ?");
$stmt->bind_param("s", $token);
$stmt->execute();
$stmt->bind_result($owner_id);
$stmt->fetch();
$stmt->close();

$isOwner = ($owner_id == $user_id);

// ✅ If not owner, check if user is admin
$isEditor = false;
if (!$isOwner) {
  $stmt = $mysqli->prepare("SELECT role FROM invitations WHERE listToken = ? AND email = (SELECT email FROM members WHERE id = ?)");
  $stmt->bind_param("si", $token, $user_id);
  $stmt->execute();
  $stmt->bind_result($userRole);
  $stmt->fetch();
  $stmt->close();

  $isEditor = ($userRole === 'admin');
}


if (!$isOwner && !$isEditor) {
  http_response_code(403);
  echo json_encode(["status" => "error", "message" => "Permission denied"]);
  exit;
}

if ($newRole === 'owner') {
  http_response_code(403);
  echo json_encode(["status" => "error", "message" => "Cannot assign owner role."]);
  exit;
}

// ✅ If role is "remove", delete the invitation
if ($newRole === 'remove') {
  $stmt = $mysqli->prepare("DELETE FROM invitations WHERE listToken = ? AND email = ?");
  $stmt->bind_param("ss", $token, $email);
  $stmt->execute();
  $stmt->close();
  echo json_encode(["status" => "success", "action" => "removed"]);
  exit;
}

// ✅ Otherwise, update role and backfill member_id when possible.
$stmt = $mysqli->prepare("
  UPDATE invitations
  SET role = ?,
      member_id = COALESCE(
        member_id,
        (SELECT id FROM members WHERE email = ? LIMIT 1)
      )
  WHERE listToken = ? AND email = ?
");
$stmt->bind_param("ssss", $newRole, $email, $token, $email);
$success = $stmt->execute();
$stmt->close();

echo json_encode(["status" => $success ? "success" : "error"]);
$mysqli->close();
