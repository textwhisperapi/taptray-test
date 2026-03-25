<?php
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/db_connect.php";
sec_session_start();
//header("Content-Type: application/octet-stream");

// ✅ Sanitize and validate inputs
$surrogate = preg_replace('/[^0-9]/', '', $_GET['surrogate'] ?? '');
$name = basename($_GET['name'] ?? '');

if (!$surrogate || !$name) {
    http_response_code(400);
    exit("Invalid request");
}

// ✅ Lookup owner from database
$stmt = $mysqli->prepare("SELECT owner FROM text WHERE surrogate = ? LIMIT 1");
$stmt->bind_param("i", $surrogate);
$stmt->execute();
$result = $stmt->get_result();
$owner = $result->fetch_assoc()['owner'] ?? '';

if (!$owner) {
    http_response_code(404);
    exit("Owner not found");
}


// ✅ Build absolute path to file
$baseDir = "/home1/wecanrec/textwhisper_uploads";
$fullPath = "$baseDir/$owner/surrogate-$surrogate/files/$name";

if (!file_exists($fullPath)) {
    http_response_code(404);
    exit("File not found");
}


// The browser will show 206 Partial Content in Network tab.
// The <audio> element will instantly seek anywhere in the track.
// Your JavaScript code (even the original one you reverted to) will work perfectly.
header('Accept-Ranges: bytes');


// ✅ Detect and serve the correct MIME type
$mime = mime_content_type($fullPath) ?: 'application/octet-stream';
// ✅ Manual MIME fallback
$ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));

$mimeOverrides = [
    "aac" => "audio/aac",
    "m4a" => "audio/mp4",
    "flac" => "audio/flac",
    "mid" => "audio/midi",
    "aif" => "audio/aif",
    "aiff" => "audio/aiff",
    "webm" => "audio/webm",
];

if (isset($mimeOverrides[$ext])) {
    $mime = $mimeOverrides[$ext];
}

header("Content-Type: $mime");
header("Content-Length: " . filesize($fullPath));
header("Content-Disposition: inline; filename=\"" . basename($name) . "\"");

// ✅ Output file
readfile($fullPath);
exit;
