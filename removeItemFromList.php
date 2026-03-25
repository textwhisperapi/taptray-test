<?php
// removeItemFromList.php
header('Content-Type: application/json');
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/db_connect.php";
sec_session_start();

// ✅ Ensure user is logged in
$username = $_SESSION['username'] ?? null;
if (!$username) {
    http_response_code(403);
    echo json_encode(["error" => "Not logged in"]);
    exit;
}

// ✅ Parse input
$token     = $_POST['token']     ?? '';
$surrogate = $_POST['surrogate'] ?? '';
$user_id   = (int)($_SESSION['user_id'] ?? 0);

if ($token === '' || $surrogate === '') {
    http_response_code(400);
    echo json_encode(["error" => "Missing token or surrogate"]);
    exit;
}

// ✅ Resolve list + owner
$list_id = 0;
$owner_id = 0;
$owner_username = '';
$stmt = $mysqli->prepare("
    SELECT cl.id, cl.owner_id, m.username
    FROM content_lists cl
    LEFT JOIN members m ON m.id = cl.owner_id
    WHERE cl.token = ?
");
$stmt->bind_param("s", $token);
$stmt->execute();
$stmt->bind_result($list_id, $owner_id, $owner_username);
$stmt->fetch();
$stmt->close();

if (!$list_id) {
    http_response_code(404);
    echo json_encode(["error" => "List not found"]);
    exit;
}

// ✅ Permission: owner OR invited admin/editor on this list OR admin/editor on owner's root profile
$hasPermission = ((int)$owner_id === $user_id);

if (!$hasPermission && $username) {
    $stmt = $mysqli->prepare("
        SELECT 1
        FROM invitations i
        JOIN members m ON m.email = i.email
        WHERE i.listToken = ? AND m.username = ? AND i.role IN ('admin', 'editor')
        LIMIT 1
    ");
    $stmt->bind_param("ss", $token, $username);
    $stmt->execute();
    $stmt->store_result();
    $hasPermission = $stmt->num_rows > 0;
    $stmt->close();
}

if (!$hasPermission && $username && $owner_username) {
    $hasPermission = get_user_list_role_rank($mysqli, $owner_username, $username) >= 80;
}

if (!$hasPermission) {
    http_response_code(403);
    echo json_encode(["error" => "Permission denied"]);
    exit;
}

// ✅ Remove the item
$stmt = $mysqli->prepare("DELETE FROM content_list_items WHERE content_list_id = ? AND surrogate = ?");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(["error" => "Query prepare failed: " . $mysqli->error]);
    exit;
}
$stmt->bind_param("ii", $list_id, $surrogate);
$success = $stmt->execute();
$error   = $stmt->error;
$stmt->close();
$mysqli->close();

if ($success) {
    echo json_encode(["status" => "OK"]);
} else {
    http_response_code(500);
    echo json_encode(["status" => "Failed", "error" => $error]);
}
