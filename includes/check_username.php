<?php
require_once 'db_connect.php';

header('Content-Type: application/json');

if (!isset($_GET['base']) || !preg_match('/^[a-zA-Z0-9_]+$/', $_GET['base'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid base']);
    exit;
}

$base = $_GET['base'];
$username = $base;
$suffix = 1;

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
    $username = $base . $suffix++;
}
$stmt->close();

echo json_encode(['status' => 'ok', 'username' => $username]);
