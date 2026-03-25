<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/db_connect.php";

sec_session_start();

if (!login_check($mysqli)) {
    http_response_code(403);
    echo "Not logged in";
    exit;
}

$user_id = $_SESSION['user_id'];
$creator_id = $_SESSION['user_id'];
$is_admin = !empty($_SESSION['is_admin']);
$owner_username = trim($_POST['owner'] ?? '');
$session_username = $_SESSION['username'] ?? '';

// ✅ Allow site admins or list admins/editors to create on behalf of another owner
if ($owner_username !== '' && $owner_username !== $session_username) {
    $has_list_rights = get_user_list_role_rank($mysqli, $owner_username, $session_username) >= 80;
    if (!$is_admin && !$has_list_rights) {
        http_response_code(403);
        echo "No rights to create list for this owner.";
        exit;
    }

    $stmt = $mysqli->prepare("SELECT id FROM members WHERE username = ? LIMIT 1");
    $stmt->bind_param("s", $owner_username);
    $stmt->execute();
    $stmt->bind_result($owner_id);
    if ($stmt->fetch()) {
        $user_id = (int)$owner_id;
    } else {
        http_response_code(404);
        echo "Owner not found.";
        $stmt->close();
        exit;
    }
    $stmt->close();
}
$list_name = trim($_POST['name'] ?? '');

if ($list_name === '') {
    http_response_code(400);
    echo "List name is required.";
    exit;
}

$name_length = function_exists('mb_strlen') ? mb_strlen($list_name, 'UTF-8') : strlen($list_name);
if ($name_length > 100) {
    http_response_code(400);
    echo "Name too long.";
    exit;
}


if (!preg_match('/^[\p{L}\p{N}\p{M}\p{P}\p{S} ]{2,100}$/u', $list_name)) {
    http_response_code(400);
    echo "Invalid characters in name.";
    exit;
}


function generateToken($length = 12) {
    return bin2hex(random_bytes($length / 2));
}
$token = generateToken();

$conn = $mysqli;

if ($conn->connect_error) {
    http_response_code(500);
    echo "DB connection failed.";
    exit;
}

$stmt = $conn->prepare("SELECT id FROM content_lists WHERE name = ? AND owner_id = ?");
$stmt->bind_param("si", $list_name, $user_id);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows > 0) {
    http_response_code(409);
    echo "List already exists.";
    $stmt->close();
    $conn->close();
    exit;
}
$stmt->close();

$stmt = $conn->prepare("INSERT INTO content_lists (name, token, owner_id, created_by_id, is_public) VALUES (?, ?, ?, ?, 0)");
$stmt->bind_param("ssii", $list_name, $token, $user_id, $creator_id);
$success = $stmt->execute();
$newListId = $stmt->insert_id;
$stmt->close();

if ($success) {
    $owner_username_final = $owner_username !== '' ? $owner_username : $session_username;
    $meta = json_encode(['name' => $list_name], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    log_change_general(
        $mysqli,
        'create',
        'list',
        $newListId ?: null,
        $token,
        $user_id,
        $owner_username_final,
        $_SESSION['user_id'] ?? null,
        $session_username,
        $meta
    );
    echo "OK";
} else {
    http_response_code(500);
    echo "Error creating list.";
}

$conn->close();

?>
