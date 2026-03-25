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

$token = $_POST['token'] ?? '';
$newName = trim($_POST['name'] ?? '');
$username = $_SESSION['username'] ?? '';
$isSiteAdmin = !empty($_SESSION['is_admin']);
$currentUserId = $_SESSION['user_id'] ?? null;

if (!$token || !$newName || !$username) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing data']);
    exit;
}

// Get list + owner context
$stmt = $mysqli->prepare("
    SELECT cl.id, cl.owner_id, m.username
    FROM content_lists cl
    LEFT JOIN members m ON m.id = cl.owner_id
    WHERE cl.token = ?
    LIMIT 1
");
$stmt->bind_param("s", $token);
$stmt->execute();
$stmt->bind_result($listId, $ownerId, $ownerUsername);
$stmt->fetch();
$stmt->close();

if (!$listId || !$currentUserId) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Permission denied']);
    exit;
}

// Owner OR site admin OR list/admin-editor rights.
$isOwner = ((int)$ownerId === (int)$currentUserId);
$canEditViaListRole = get_user_list_role_rank($mysqli, $token, $username) >= 80;
$canEditViaOwnerRole = ($ownerUsername && get_user_list_role_rank($mysqli, $ownerUsername, $username) >= 80);
if (!$isOwner && !$isSiteAdmin && !$canEditViaListRole && !$canEditViaOwnerRole) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Permission denied']);
    exit;
}

// Update list name
$update = $mysqli->prepare("UPDATE content_lists SET name = ?, updated_at = NOW() WHERE id = ?");
$update->bind_param("si", $newName, $listId);
$update->execute();
$update->close();

echo json_encode(['status' => 'success']);
