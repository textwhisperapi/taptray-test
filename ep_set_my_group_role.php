<?php
header('Content-Type: application/json');
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db_connect.php';

sec_session_start();

if (!login_check($mysqli) || empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not logged in']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$ownerToken = trim((string)($_POST['owner_token'] ?? ''));
$role = trim((string)($_POST['role'] ?? ''));

if ($ownerToken === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing owner token']);
    exit;
}

if ($role === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing role']);
    exit;
}

// Resolve owner from list token first, then username token.
$ownerId = 0;
$stmt = $mysqli->prepare("SELECT owner_id FROM content_lists WHERE token = ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param("s", $ownerToken);
    $stmt->execute();
    $stmt->bind_result($resolvedOwnerId);
    if ($stmt->fetch()) {
        $ownerId = (int)$resolvedOwnerId;
    }
    $stmt->close();
}
if ($ownerId <= 0) {
    $stmt = $mysqli->prepare("SELECT id FROM members WHERE username = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $ownerToken);
        $stmt->execute();
        $stmt->bind_result($resolvedOwnerId);
        if ($stmt->fetch()) {
            $ownerId = (int)$resolvedOwnerId;
        }
        $stmt->close();
    }
}
if ($ownerId <= 0) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Owner not found']);
    exit;
}

// Verify role from the same source as selector:
// role-group names OR distinct existing roles under this owner.
$resolvedRole = '';
$stmt = $mysqli->prepare("
    SELECT role_name
    FROM (
      SELECT name AS role_name
      FROM ep_groups
      WHERE owner_id = ? AND is_role_group = 1
      UNION
      SELECT DISTINCT gm.role AS role_name
      FROM ep_group_members gm
      JOIN ep_groups g ON g.id = gm.group_id
      WHERE g.owner_id = ?
        AND gm.role IS NOT NULL
        AND gm.role <> ''
    ) roles
    WHERE LOWER(role_name) = LOWER(?)
    LIMIT 1
");
if ($stmt) {
    $stmt->bind_param("iis", $ownerId, $ownerId, $role);
    $stmt->execute();
    $stmt->bind_result($matchedRole);
    if ($stmt->fetch()) {
        $resolvedRole = trim((string)$matchedRole);
    }
    $stmt->close();
}
if ($resolvedRole === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid role for this group']);
    exit;
}

// Confirm user is already part of owner ecosystem (member row in any owner group OR invited by email).
$hasAccess = false;
$stmt = $mysqli->prepare("
    SELECT 1
    FROM ep_group_members gm
    JOIN ep_groups g ON g.id = gm.group_id
    WHERE g.owner_id = ? AND gm.member_id = ?
    LIMIT 1
");
if ($stmt) {
    $stmt->bind_param("ii", $ownerId, $userId);
    $stmt->execute();
    $stmt->store_result();
    $hasAccess = $stmt->num_rows > 0;
    $stmt->close();
}

if (!$hasAccess) {
    $stmt = $mysqli->prepare("
        SELECT 1
        FROM invitations i
        JOIN content_lists cl ON cl.token = i.listToken
        JOIN members m ON m.id = ?
        WHERE cl.owner_id = ?
          AND LOWER(i.email) = LOWER(m.email)
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param("ii", $userId, $ownerId);
        $stmt->execute();
        $stmt->store_result();
        $hasAccess = $stmt->num_rows > 0;
        $stmt->close();
    }
}

if (!$hasAccess) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'No access to this group']);
    exit;
}

// Resolve all-members group for owner.
$allMembersGroupId = 0;
$stmt = $mysqli->prepare("
    SELECT id
    FROM ep_groups
    WHERE owner_id = ? AND is_all_members = 1
    LIMIT 1
");
if ($stmt) {
    $stmt->bind_param("i", $ownerId);
    $stmt->execute();
    $stmt->bind_result($resolvedGroupId);
    if ($stmt->fetch()) {
        $allMembersGroupId = (int)$resolvedGroupId;
    }
    $stmt->close();
}
if ($allMembersGroupId <= 0) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'All-members group not found']);
    exit;
}

// Update own role in all-members group.
$stmt = $mysqli->prepare("
    INSERT INTO ep_group_members (group_id, member_id, role)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE role = VALUES(role)
");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Could not prepare role update']);
    exit;
}
$stmt->bind_param("iis", $allMembersGroupId, $userId, $resolvedRole);
$ok = $stmt->execute();
$stmt->close();

if (!$ok) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Could not update role']);
    exit;
}

echo json_encode(['status' => 'ok', 'role' => $resolvedRole]);
