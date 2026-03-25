<?php
header('Content-Type: application/json');
ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/functions.php';

sec_session_start();

if (!login_check($mysqli)) {
    http_response_code(403);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

$token    = $_GET['token'] ?? '';
$username = $_SESSION['username'] ?? '';

if (!$token || !$username) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing token or session']);
    exit;
}

// --------------------------------------------------
// user info
// --------------------------------------------------
$stmt = $mysqli->prepare("SELECT email, id FROM members WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$stmt->bind_result($userEmail, $userId);
$stmt->fetch();
$stmt->close();

if (!$userEmail || !$userId) {
    http_response_code(403);
    echo json_encode(['error' => 'User not found']);
    exit;
}

// --------------------------------------------------
// helpers
// --------------------------------------------------
function hasDirectListAccess($mysqli, $token, $userId, $userEmail) {

    // Owner
    $stmt = $mysqli->prepare("SELECT 1 FROM content_lists WHERE token = ? AND owner_id = ?");
    $stmt->bind_param("si", $token, $userId);
    $stmt->execute();
    $stmt->store_result();
    $isOwner = $stmt->num_rows > 0;
    $stmt->close();

    if ($isOwner) return true;

    // Invitation
    $stmt = $mysqli->prepare("SELECT role FROM invitations WHERE listToken = ? AND email = ?");
    $stmt->bind_param("ss", $token, $userEmail);
    $stmt->execute();
    $stmt->bind_result($role);
    $stmt->fetch();
    $stmt->close();

    return (bool)$role;
}

// --------------------------------------------------
// fetch list + parent
// --------------------------------------------------
$stmt = $mysqli->prepare("
  SELECT id, parent_id
  FROM content_lists
  WHERE token = ?
  LIMIT 1
");
$stmt->bind_param("s", $token);
$stmt->execute();
$list = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$list) {
    http_response_code(404);
    echo json_encode(['error' => 'List not found']);
    exit;
}

// --------------------------------------------------
// permission (direct or inherited)
// --------------------------------------------------
$allowed = false;

// direct
if (hasDirectListAccess($mysqli, $token, $userId, $userEmail)) {
    $allowed = true;
}

// inherited
if (!$allowed) {
    $currentId = (int)$list['id'];
    $safety = 0;

    while ($currentId && $safety++ < 30) {
        $stmt = $mysqli->prepare("SELECT parent_id, token FROM content_lists WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $currentId);
        $stmt->execute();
        $stmt->bind_result($parentId, $parentToken);
        $ok = $stmt->fetch();
        $stmt->close();

        if (!$ok || !$parentId) break;

        if (hasDirectListAccess($mysqli, $parentToken, $userId, $userEmail)) {
            $allowed = true;
            break;
        }

        $currentId = (int)$parentId;
    }
}

if (!$allowed) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

// --------------------------------------------------
// requester-only view
// --------------------------------------------------
$stmt = $mysqli->prepare("SELECT role FROM invitations WHERE listToken = ? AND email = ?");
$stmt->bind_param("ss", $token, $userEmail);
$stmt->execute();
$stmt->bind_result($userRole);
$stmt->fetch();
$stmt->close();

if ($userRole === 'request') {
    echo json_encode([[
        'email' => $userEmail,
        'role' => 'request',
        'display_name' => null
    ]]);
    exit;
}

// --------------------------------------------------
// full access list (child list only)
// --------------------------------------------------
$stmt = $mysqli->prepare("
    SELECT DISTINCT i.email, i.role, m.display_name
    FROM invitations i
    LEFT JOIN members m ON i.email = m.email
    WHERE i.listToken = ?
");
$stmt->bind_param("s", $token);
$stmt->execute();
$stmt->bind_result($email, $role, $display_name);

$accessList = [];

while ($stmt->fetch()) {
    $accessList[] = [
        'email' => $email,
        'role' => $role,
        'display_name' => $display_name
    ];
}
$stmt->close();

echo json_encode($accessList);
