<?php
//toggleUserList.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/db_connect.php";

sec_session_start();

//tmp/session_debug.log
//file_put_contents('/tmp/session_debug.log', print_r($_SESSION, true), FILE_APPEND);


if (!login_check($mysqli)) {
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "Not logged in"]);
    exit;
}

// if (empty($_SESSION['user_id'])) {
//     http_response_code(403);
//     echo json_encode(["status" => "error", "message" => "Not logged in"]);
//     exit;
// }


$user_id = $_SESSION['user_id'] ?? null;
$token = $_POST['token'] ?? '';

if (!$user_id || !$token) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Missing user or token"]);
    exit;
}

// ✅ Check if user already added this list
$check = $mysqli->prepare("SELECT id FROM followed_lists WHERE user_id = ? AND list_token = ?");
$check->bind_param("is", $user_id, $token);
$check->execute();
$check->store_result();

if ($check->num_rows > 0) {
    // Already exists, so remove it
    $remove = $mysqli->prepare("DELETE FROM followed_lists WHERE user_id = ? AND list_token = ?");
    $remove->bind_param("is", $user_id, $token);
    $remove->execute();
    echo json_encode(["status" => "removed"]);
} else {
    // Doesn't exist, so add it
    $add = $mysqli->prepare("INSERT INTO followed_lists (user_id, list_token) VALUES (?, ?)");
    $add->bind_param("is", $user_id, $token);
    $add->execute();
    echo json_encode(["status" => "added"]);
}
?>
