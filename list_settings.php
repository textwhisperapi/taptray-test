<?php
header("Content-Type: application/json");

require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/db_connect.php";

sec_session_start();

if (!login_check($mysqli)) {
    http_response_code(403);
    echo json_encode(["ok" => false, "error" => "Not logged in"]);
    exit;
}

$username = $_SESSION['username'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$token = $method === 'POST'
    ? ($_POST['token'] ?? '')
    : ($_GET['token'] ?? '');
$key = $method === 'POST'
    ? ($_POST['key'] ?? '')
    : ($_GET['key'] ?? '');

$token = trim($token);
$key = trim($key);

if ($token === '' || $key === '') {
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "Missing token or key"]);
    exit;
}

$roleRank = get_user_list_role_rank($mysqli, $token, $username);
if ($roleRank < 80) {
    http_response_code(403);
    echo json_encode(["ok" => false, "error" => "Permission denied"]);
    exit;
}

$stmt = $mysqli->prepare("SELECT id FROM content_lists WHERE token = ? LIMIT 1");
$stmt->bind_param("s", $token);
$stmt->execute();
$stmt->bind_result($list_id);
if (!$stmt->fetch()) {
    $stmt->close();
    http_response_code(404);
    echo json_encode(["ok" => false, "error" => "List not found"]);
    exit;
}
$stmt->close();

if ($method === 'GET') {
    $stmt = $mysqli->prepare("SELECT setting_value FROM list_settings WHERE list_id = ? AND setting_key = ? LIMIT 1");
    $stmt->bind_param("is", $list_id, $key);
    $stmt->execute();
    $stmt->bind_result($value);
    $found = $stmt->fetch();
    $stmt->close();

    echo json_encode([
        "ok" => true,
        "value" => $found ? $value : null
    ]);
    exit;
}

if ($method === 'POST') {
    $value = $_POST['value'] ?? null;
    if ($value === null) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "Missing value"]);
        exit;
    }

    // Empty value deletes the setting
    if (trim($value) === '') {
        $stmt = $mysqli->prepare("DELETE FROM list_settings WHERE list_id = ? AND setting_key = ?");
        $stmt->bind_param("is", $list_id, $key);
        $stmt->execute();
        $stmt->close();
        echo json_encode(["ok" => true, "deleted" => true]);
        exit;
    }

    $stmt = $mysqli->prepare("
        INSERT INTO list_settings (list_id, setting_key, setting_value)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    $stmt->bind_param("iss", $list_id, $key, $value);
    $stmt->execute();
    $stmt->close();

    echo json_encode(["ok" => true]);
    exit;
}

http_response_code(405);
echo json_encode(["ok" => false, "error" => "Method not allowed"]);
