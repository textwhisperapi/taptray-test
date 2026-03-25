<?php
header("Content-Type: application/json");

require_once __DIR__ . "/includes/db_connect.php";
require_once __DIR__ . "/includes/functions.php";

sec_session_start();

if (!login_check($mysqli) || empty($_SESSION['username'])) {
  http_response_code(401);
  exit;
}

$username = $_SESSION['username'];
$input = json_decode(file_get_contents("php://input"), true);

$owner = trim($input['owner'] ?? '');
$roles = $input['roles'] ?? null;

if ($owner === '' || !is_array($roles)) {
  http_response_code(400);
  exit;
}

// 🔐 Permission check (authoritative)
if (!can_user_edit_list($mysqli, $owner, $username)
    && !can_user_edit_surrogate($mysqli, $owner, $username)) {
  http_response_code(403);
  exit;
}

// 🧹 Sanitize roles
$clean = [];
foreach ($roles as $r) {
  $r = trim($r);
  if ($r !== '' && mb_strlen($r) <= 64) {
    $clean[] = $r;
  }
}

if (!in_array("All", $clean, true)) {
  array_unshift($clean, "All");
}

$clean = array_values(array_unique($clean));

$payload = json_encode(["roles" => $clean], JSON_UNESCAPED_UNICODE);

// 💾 Store for OWNER
$stmt = $mysqli->prepare(
  "UPDATE members SET member_roles_json = ? WHERE username = ?"
);
$stmt->bind_param("ss", $payload, $owner);
$stmt->execute();
$stmt->close();

echo json_encode([
  "ok"    => true,
  "roles" => $clean
]);
