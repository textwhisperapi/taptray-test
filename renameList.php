<?php
//#rename_list.php 
require_once __DIR__ . "/includes/db_connect.php";
require_once __DIR__ . "/includes/functions.php";

sec_session_start();
header('Content-Type: application/json');

if (!login_check($mysqli)) {
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "Not logged in"]);
    exit;
}

$user_id = $_SESSION['user_id'] ?? null;
$username = $_SESSION['username'] ?? '';
$isSiteAdmin = !empty($_SESSION['is_admin']);

$data = json_decode(file_get_contents("php://input"), true);
$token = $data['token'] ?? '';
$newName = trim($data['name'] ?? '');

if (!$token || $newName === '' || !$username || !$user_id) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Missing token or name"]);
    exit;
}

// 🔒 Ensure the user can edit this list
$check = $mysqli->prepare("
    SELECT cl.owner_id, m.username
    FROM content_lists cl
    LEFT JOIN members m ON m.id = cl.owner_id
    WHERE cl.token = ?
    LIMIT 1
");
$check->bind_param("s", $token);
$check->execute();
$check->bind_result($owner_id, $owner_username);
$check->fetch();
$check->close();

if (!$owner_id) {
    http_response_code(404);
    echo json_encode(["status" => "error", "message" => "List not found"]);
    exit;
}

$isOwner = ((int)$owner_id === (int)$user_id);
$canEditViaListRole = get_user_list_role_rank($mysqli, $token, $username) >= 80;
$canEditViaOwnerRole = ($owner_username && get_user_list_role_rank($mysqli, $owner_username, $username) >= 80);
if (!$isOwner && !$isSiteAdmin && !$canEditViaListRole && !$canEditViaOwnerRole) {
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "Permission denied"]);
    exit;
}

// ✅ Proceed with rename
$stmt = $mysqli->prepare("UPDATE content_lists SET name = ? WHERE token = ?");
$stmt->bind_param("ss", $newName, $token);
if ($stmt->execute()) {
    echo json_encode(["status" => "success"]);
} else {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Failed to rename list"]);
}
$stmt->close();
?>
