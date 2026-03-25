<?php
header('Content-Type: application/json');
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/functions.php';


sec_session_start();

if (!login_check($mysqli)) {
  http_response_code(403);
  echo json_encode(['status' => 'error', 'message' => 'Not logged in']);
  exit;
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$display_name = $_SESSION['display_name'] ?? $username;
$token = $_POST['token'] ?? '';
$invite_email = $_POST['email'] ?? '';
$role = $_POST['role'] ?? '';

// Avoid session lock during bulk invite bursts.
session_write_close();

if (!$token || !$invite_email || !$role) {
  http_response_code(400);
  echo json_encode(['status' => 'error', 'message' => 'Missing parameters']);
  exit;
}

// Get list and owner
$is_owner = false;
$owner_id = null;

// Try content_lists first
$stmt = $mysqli->prepare("SELECT id, owner_id FROM content_lists WHERE token = ?");
$stmt->bind_param("s", $token);
$stmt->execute();
$stmt->bind_result($list_id, $owner_id);
$stmt->fetch();
$stmt->close();

if ($list_id) {
  $is_owner = ($user_id == $owner_id);
} elseif ($token === $username) {
  // Fallback for "All content"
  $is_owner = true;
  $owner_id = $user_id;
} else {
  http_response_code(404);
  echo json_encode(['status' => 'error', 'message' => 'List not found']);
  exit;
}


// Check permissions using JOIN (based on email match)
$is_owner = ($user_id == $owner_id);
$is_editor = false;
$is_admin = false;

$stmt = $mysqli->prepare("
  SELECT invitations.role
  FROM invitations
  JOIN members ON invitations.email = members.email
  WHERE invitations.listToken = ? AND members.username = ?
");
$stmt->bind_param("ss", $token, $username);
$stmt->execute();
$stmt->bind_result($existing_role);
if ($stmt->fetch()) {
  if ($existing_role === 'editor') {
    $is_editor = true;
  } elseif ($existing_role === 'admin') {
    $is_admin = true;
  }
}

$stmt->close();

error_log("chatInviteToList user_id: $user_id, username: $username, owner_id: $owner_id");
error_log("chatInviteToList checking invite role via JOIN for $username on list $token â†’ $existing_role");

if (!$is_owner && !$is_editor && !$is_admin) {
  http_response_code(403);
  echo json_encode(['status' => 'error', 'message' => 'Permission denied']);
  exit;
}

// Insert or update invitation
// $stmt = $mysqli->prepare("
//   INSERT INTO invitations (listToken, email, role)
//   VALUES (?, ?, ?)
//   ON DUPLICATE KEY UPDATE role = VALUES(role)
// ");
// $stmt->bind_param("sss", $token, $invite_email, $role);
// $success = $stmt->execute();
// $stmt->close();


// Insert or update invitation and member id if exists.
// Backward-compatible: fall back when invitations.created_at column does not exist.
$success = false;
$stmt = $mysqli->prepare("
  INSERT INTO invitations (listToken, email, role, member_id, created_at)
  VALUES (
    ?,
    ?,
    ?,
    (SELECT id FROM members WHERE email = ? LIMIT 1),
    NOW()
  )
  ON DUPLICATE KEY UPDATE
    role = VALUES(role),
    member_id = VALUES(member_id),
    created_at = NOW()
");
if ($stmt) {
  $stmt->bind_param("ssss", $token, $invite_email, $role, $invite_email);
  $success = $stmt->execute();
  if (!$success) {
    error_log("chatInviteToList primary upsert failed: " . $stmt->error);
  }
  $stmt->close();
}

if (!$success) {
  $fallback = $mysqli->prepare("
    INSERT INTO invitations (listToken, email, role, member_id)
    VALUES (
      ?,
      ?,
      ?,
      (SELECT id FROM members WHERE email = ? LIMIT 1)
    )
    ON DUPLICATE KEY UPDATE
      role = VALUES(role),
      member_id = VALUES(member_id)
  ");
  if ($fallback) {
    $fallback->bind_param("ssss", $token, $invite_email, $role, $invite_email);
    $success = $fallback->execute();
    if (!$success) {
      error_log("chatInviteToList fallback upsert failed: " . $fallback->error);
    } else {
      error_log("chatInviteToList used fallback upsert (no created_at column).");
    }
    $fallback->close();
  }
}

if (!$success) {
  $legacy = $mysqli->prepare("
    INSERT INTO invitations (listToken, email, role)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE
      role = VALUES(role)
  ");
  if ($legacy) {
    $legacy->bind_param("sss", $token, $invite_email, $role);
    $success = $legacy->execute();
    if (!$success) {
      error_log("chatInviteToList legacy upsert failed: " . $legacy->error);
    } else {
      error_log("chatInviteToList used legacy upsert (no member_id/created_at columns).");
    }
    $legacy->close();
  }
}


$response = ['status' => $success ? 'success' : 'error'];

if (!$success) {
    echo json_encode($response);
    exit;
}

// Return quickly when possible, then send mail after response.
if (function_exists('fastcgi_finish_request')) {
    echo json_encode($response);
    fastcgi_finish_request();
    if (!sendInviteEmail($invite_email, $display_name, $token, $role)) {
        error_log("⚠️ Failed to send invite email to $invite_email");
    }
    exit;
}

if (!sendInviteEmail($invite_email, $display_name, $token, $role)) {
    error_log("⚠️ Failed to send invite email to $invite_email");
}

echo json_encode($response);
