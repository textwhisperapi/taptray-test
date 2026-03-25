<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db_connect.php';

sec_session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit("Unauthorized");
}

$userId = $_SESSION['user_id'];
$fileserver = $_POST['fileserver'] ?? 'cloudflare';

if ($fileserver !== 'cloudflare') {
    http_response_code(400);
    exit("Invalid option");
}

$stmt = $mysqli->prepare("UPDATE members SET fileserver=? WHERE id=?");
$stmt->bind_param("si", $fileserver, $userId);
$stmt->execute();
$stmt->close();

echo "success";
