<?php
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/db_connect.php";
sec_session_start();
header("Content-Type: application/json");

// Validate session
// if (!isset($_SESSION['user_id'])) {
//     http_response_code(403);
//     echo json_encode(['status' => 'error', 'error' => 'Not logged in']);
//     exit;
// }

// ✅ Allow public access for viewing
$user = $_SESSION['username'] ?? null;
$isLoggedIn = isset($_SESSION['user_id']);


// Inputs
$surrogate = $_GET['surrogate'] ?? '';
if (!is_numeric($surrogate)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => 'Invalid surrogate']);
    exit;
}

// Find owner of item
$surrogateSafe = $mysqli->real_escape_string($surrogate);
$query = "SELECT owner FROM text WHERE Surrogate = '$surrogateSafe' LIMIT 1";
$result = $mysqli->query($query);
$item = $result ? $result->fetch_assoc() : null;

if (!$item || empty($item['owner'])) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'error' => 'Owner not found']);
    exit;
}

$owner = $item['owner'];
$basePath = "/home1/wecanrec/textwhisper_uploads/$owner/surrogate-$surrogate/files";
$baseUrl = "/textwhisper_uploads/$owner/surrogate-$surrogate/files";

$files = [];

if (is_dir($basePath)) {
    foreach (scandir($basePath) as $file) {
        if ($file === '.' || $file === '..') continue;
        $fullPath = "$basePath/$file";
        if (is_file($fullPath)) {
            $files[] = [
                'name' => $file,
                'url' => "$baseUrl/$file",
                'size' => filesize($fullPath),
                'owner' => $owner  //include owner
            ];

        }
    }
}

echo json_encode(['status' => 'success', 'files' => $files]);
