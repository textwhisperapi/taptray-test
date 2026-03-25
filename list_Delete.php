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
$currentUserId = $_SESSION['user_id'] ?? null;
$currentUsername = $_SESSION['username'] ?? '';
$isSiteAdmin = !empty($_SESSION['is_admin']);

if (!$token || !$currentUserId) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing data']);
    exit;
}

// Get list info
$stmt = $mysqli->prepare("
    SELECT cl.id, cl.owner_id, m.username
    FROM content_lists cl
    JOIN members m ON m.id = cl.owner_id
    WHERE cl.token = ?
    LIMIT 1
");
$stmt->bind_param("s", $token);
$stmt->execute();
$stmt->bind_result($listId, $ownerId, $ownerUsername);
$stmt->fetch();
$stmt->close();

$canEditOwner = ($ownerUsername && $currentUsername)
    ? can_user_edit_list($mysqli, $ownerUsername, $currentUsername)
    : false;
$canEditList = ($token && $currentUsername)
    ? can_user_edit_list($mysqli, $token, $currentUsername)
    : false;

if (
    !$listId ||
    ($ownerId !== $currentUserId && !$isSiteAdmin && !$canEditOwner && !$canEditList)
) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Permission denied']);
    exit;
}

// 🚫 Block delete if chat history exists for this list
$chatCheck = $mysqli->prepare("SELECT 1 FROM chat_messages WHERE listToken = ? LIMIT 1");
$chatCheck->bind_param("s", $token);
$chatCheck->execute();
$chatCheck->store_result();
if ($chatCheck->num_rows > 0) {
    $chatCheck->close();
    http_response_code(409);
    echo json_encode(['status' => 'error', 'message' => 'List has chat history']);
    exit;
}
$chatCheck->close();

// Delete items first
$delItems = $mysqli->prepare("DELETE FROM content_list_items WHERE content_list_id = ?");
$delItems->bind_param("i", $listId);
$delItems->execute();
$delItems->close();

// Delete the list
$delList = $mysqli->prepare("DELETE FROM content_lists WHERE id = ?");
$delList->bind_param("i", $listId);
$delList->execute();
$delList->close();

echo json_encode(['status' => 'success']);
