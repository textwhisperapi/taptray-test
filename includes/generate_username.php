<?php
require_once 'db_connect.php';

header('Content-Type: application/json');

if (!isset($_GET['email']) || !filter_var($_GET['email'], FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid email']);
    exit;
}

$base = explode('@', $_GET['email'])[0];
$username = $base;
$suffix = 1;

// Check for uniqueness
$stmt = $mysqli->prepare("SELECT id FROM members WHERE username = ? LIMIT 1");
if (!$stmt) {
    echo json_encode(['status' => 'error', 'message' => 'DB error']);
    exit;
}

while (true) {
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows === 0) break;
    $username = $base . mt_rand(100, 999); // Add random number
}
$stmt->close();

echo json_encode(['status' => 'ok', 'username' => $username]);
