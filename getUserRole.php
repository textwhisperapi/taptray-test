<?php
header('Content-Type: application/json');

require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/db_connect.php";

sec_session_start();

$userId   = $_SESSION['user_id'] ?? null;
$username = $_SESSION['username'] ?? null;
$token    = $_GET['token'] ?? null;

if (!$userId || !$token) {
  echo json_encode(["role" => "none", "role_rank" => 0]);
  exit;
}

// --------------------------------------------------
// helpers
// --------------------------------------------------
function directRole($mysqli, $token, $userId, $username) {

  // Owner of personal All Content
  if ($token === $username) {
    return ["role" => "owner", "role_rank" => 90];
  }

  // Owner of list
  $stmt = $mysqli->prepare("SELECT owner_id FROM content_lists WHERE token = ?");
  $stmt->bind_param("s", $token);
  $stmt->execute();
  $stmt->bind_result($ownerId);
  $stmt->fetch();
  $stmt->close();

  if ((int)$ownerId === (int)$userId) {
    return ["role" => "owner", "role_rank" => 90];
  }

  // Invitation on this list
  $stmt = $mysqli->prepare("
    SELECT i.role, i.role_rank
    FROM invitations i
    JOIN members m ON m.email = i.email
    WHERE i.listToken = ? AND m.id = ?
    LIMIT 1
  ");
  $stmt->bind_param("si", $token, $userId);
  $stmt->execute();
  $stmt->bind_result($role, $rank);
  $stmt->fetch();
  $stmt->close();

  if ($role) {
    return ["role" => $role, "role_rank" => (int)$rank];
  }

  return null;
}

// --------------------------------------------------
// fetch list + parent
// --------------------------------------------------
$stmt = $mysqli->prepare("
  SELECT id, parent_id, owner_id
  FROM content_lists
  WHERE token = ?
  LIMIT 1
");
$stmt->bind_param("s", $token);
$stmt->execute();
$list = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$list) {
  echo json_encode(["role" => "none", "role_rank" => 0]);
  exit;
}

// --------------------------------------------------
// 🔑 GLOBAL ROOT: access via owner's All Content
// --------------------------------------------------
$stmt = $mysqli->prepare("
  SELECT i.role, i.role_rank
  FROM invitations i
  JOIN content_lists cl ON cl.token = i.listToken
  JOIN members owner ON owner.id = cl.owner_id
  WHERE
    cl.token = owner.username
    AND owner.id = ?
    AND i.email = (SELECT email FROM members WHERE id = ? LIMIT 1)
  LIMIT 1
");
$stmt->bind_param("ii", $list['owner_id'], $userId);
$stmt->execute();
$stmt->bind_result($role, $rank);
$stmt->fetch();
$stmt->close();

if ($role) {
  echo json_encode([
    "role" => $role,
    "role_rank" => (int)$rank
  ]);
  exit;
}

// --------------------------------------------------
// 1) direct access
// --------------------------------------------------
$role = directRole($mysqli, $token, $userId, $username);
if ($role) {
  echo json_encode($role);
  exit;
}

// --------------------------------------------------
// 2) inherit from parents
// --------------------------------------------------
$currentId = (int)$list['id'];
$safety = 0;

while ($currentId && $safety++ < 30) {

  $stmt = $mysqli->prepare("
    SELECT parent_id, token
    FROM content_lists
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->bind_param("i", $currentId);
  $stmt->execute();
  $stmt->bind_result($parentId, $parentToken);
  $ok = $stmt->fetch();
  $stmt->close();

  if (!$ok || !$parentId) break;

  $role = directRole($mysqli, $parentToken, $userId, $username);
  if ($role) {
    echo json_encode($role);
    exit;
  }

  $currentId = (int)$parentId;
}

// --------------------------------------------------
// no access
// --------------------------------------------------
echo json_encode(["role" => "none", "role_rank" => 0]);
$mysqli->close();
