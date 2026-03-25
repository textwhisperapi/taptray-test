<?php
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/db_connect.php";
sec_session_start();
//session_start();
$user_id = $_SESSION['user_id'] ?? null;
$token = $_POST['token'] ?? '';

$con = $mysqli;

if (!$user_id || !$token) {
    echo json_encode(["status" => "error", "message" => "Missing user or token"]);
    exit;
}

$stmt = $con->prepare("INSERT IGNORE INTO favorite_lists (user_id, list_token) VALUES (?, ?)");
$stmt->bind_param("is", $user_id, $token);
$stmt->execute();
$stmt->close();
mysqli_close($con);

echo json_encode(["status" => "OK"]);
?>