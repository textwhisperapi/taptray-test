<?php
header("Content-Type: application/json");

require_once __DIR__ . "/includes/db_connect.php";
require_once __DIR__ . "/includes/functions.php";

sec_session_start();

$owner = trim($_GET['owner'] ?? '');

if ($owner === '') {
  echo json_encode(["roles" => ["All"]]);
  exit;
}

// 🔎 Fetch role structure for owner
$stmt = $mysqli->prepare(
  "SELECT member_roles_json FROM members WHERE username = ? LIMIT 1"
);
$stmt->bind_param("s", $owner);
$stmt->execute();
$stmt->bind_result($rolesJson);
$stmt->fetch();
$stmt->close();

$data  = json_decode($rolesJson ?: "", true);
$roles = is_array($data['roles'] ?? null) ? $data['roles'] : ["All"];

echo json_encode([
  "roles" => array_values($roles)
]);
