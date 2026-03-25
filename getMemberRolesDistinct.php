<?php
header("Content-Type: application/json");

require_once __DIR__ . "/includes/db_connect.php";
require_once __DIR__ . "/includes/functions.php";

sec_session_start();

if (!login_check($mysqli) || empty($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(["roles" => []]);
  exit;
}

$ownerId = (int)($_GET['owner_id'] ?? 0);
if ($ownerId <= 0) {
  http_response_code(400);
  echo json_encode(["roles" => []]);
  exit;
}

$stmt = $mysqli->prepare("
  SELECT DISTINCT gm.role
  FROM ep_group_members gm
  JOIN ep_groups g ON g.id = gm.group_id
  WHERE g.created_by_member_id = ?
    AND gm.role IS NOT NULL
    AND gm.role <> ''
  ORDER BY gm.role ASC
");
$stmt->bind_param("i", $ownerId);
$stmt->execute();
$res = $stmt->get_result();

$roles = [];
while ($row = $res->fetch_assoc()) {
  $roles[] = $row['role'];
}
$stmt->close();

echo json_encode(["roles" => $roles]);
