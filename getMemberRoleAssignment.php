<?php
header("Content-Type: application/json");

require_once __DIR__ . "/includes/db_connect.php";
require_once __DIR__ . "/includes/functions.php";

sec_session_start();

if (!login_check($mysqli) || empty($_SESSION['user_id'])) {
  echo json_encode(["role" => null]);
  exit;
}

$memberId = (int)$_SESSION['user_id'];
$owner    = trim($_GET['owner'] ?? '');

if ($owner === '') {
  echo json_encode(["role" => null]);
  exit;
}

/* resolve owner_id from username */
$stmt = $mysqli->prepare(
  "SELECT id FROM members WHERE username = ? LIMIT 1"
);
$stmt->bind_param("s", $owner);
$stmt->execute();
$stmt->bind_result($ownerId);
$stmt->fetch();
$stmt->close();

if (!$ownerId) {
  echo json_encode(["role" => null]);
  exit;
}

$stmt = $mysqli->prepare(
  "SELECT role
   FROM member_role_assignments
   WHERE owner_id = ? AND member_id = ?
   LIMIT 1"
);
$stmt->bind_param("ii", $ownerId, $memberId);
$stmt->execute();
$stmt->bind_result($role);
$stmt->fetch();
$stmt->close();

echo json_encode([
  "role" => $role ?: null
]);
