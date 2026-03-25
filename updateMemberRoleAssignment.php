<?php
header("Content-Type: application/json");

require_once __DIR__ . "/includes/db_connect.php";
require_once __DIR__ . "/includes/functions.php";

sec_session_start();

if (!login_check($mysqli) || empty($_SESSION['user_id'])) {
  http_response_code(401);
  exit;
}

$memberId = (int)$_SESSION['user_id'];

$input = json_decode(file_get_contents("php://input"), true);
$ownerUsername = trim($input['owner'] ?? '');
$role          = trim($input['role'] ?? '');

if ($ownerUsername === '' || $role === '') {
  http_response_code(400);
  exit;
}

/* resolve owner_id from username */
$stmt = $mysqli->prepare(
  "SELECT id FROM members WHERE username = ? LIMIT 1"
);
$stmt->bind_param("s", $ownerUsername);
$stmt->execute();
$stmt->bind_result($ownerId);
$stmt->fetch();
$stmt->close();

if (!$ownerId) {
  http_response_code(404);
  exit;
}

/* load owner's role structure */
$stmt = $mysqli->prepare(
  "SELECT member_roles_json FROM members WHERE id = ? LIMIT 1"
);
$stmt->bind_param("i", $ownerId);
$stmt->execute();
$stmt->bind_result($rolesJson);
$stmt->fetch();
$stmt->close();

$data  = json_decode($rolesJson ?: "", true);
$roles = $data['roles'] ?? ["All"];

if (!in_array($role, $roles, true)) {
  http_response_code(400);
  exit;
}

/* upsert assignment using IDs */
$stmt = $mysqli->prepare(
  "INSERT INTO member_role_assignments (owner_id, member_id, role)
   VALUES (?, ?, ?)
   ON DUPLICATE KEY UPDATE role = VALUES(role)"
);
$stmt->bind_param("iis", $ownerId, $memberId, $role);
$stmt->execute();
$stmt->close();

echo json_encode([
  "ok"   => true,
  "role" => $role
]);
