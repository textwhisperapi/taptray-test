<?php
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/db_connect.php";
sec_session_start();

header("Content-Type: application/json");

// 🔐 Require POST and login
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'error' => 'Method not allowed']);
    exit;
}
if (!isset($_SESSION['username'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'error' => 'Not logged in']);
    exit;
}

// 🔢 Get input
$input = json_decode(file_get_contents("php://input"), true);
$surrogate = $input['surrogate'] ?? '';
$name = $input['name'] ?? '';

if (!is_numeric($surrogate) || !$name) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => 'Missing surrogate or filename']);
    exit;
}

// 🔍 Get owner of the surrogate
$safeSurrogate = $mysqli->real_escape_string($surrogate);
$result = $mysqli->query("SELECT owner FROM text WHERE Surrogate = '$safeSurrogate' LIMIT 1");
$row = $result ? $result->fetch_assoc() : null;
$owner = $row['owner'] ?? null;

if (!$owner) {
    echo json_encode(['status' => 'error', 'error' => 'Item owner not found']);
    exit;
}

// 🔒 Only owner or admin can delete
$current = $_SESSION['username'];
$isAdmin = !empty($_SESSION['is_admin']);
if ($current !== $owner && !$isAdmin) {
    echo json_encode(['status' => 'error', 'error' => 'Permission denied']);
    exit;
}

// 🧹 Attempt delete
$basePath = "/home1/wecanrec/textwhisper_uploads/$owner/surrogate-$surrogate/files";
$filePath = realpath("$basePath/$name");

if (!$filePath || !str_starts_with($filePath, realpath($basePath))) {
    echo json_encode(['status' => 'error', 'error' => 'Invalid file path']);
    exit;
}

if (!file_exists($filePath)) {
    echo json_encode(['status' => 'error', 'error' => 'File not found']);
    exit;
}

if (!unlink($filePath)) {
    echo json_encode(['status' => 'error', 'error' => 'Failed to delete']);
    exit;
}

echo json_encode(['status' => 'success']);
