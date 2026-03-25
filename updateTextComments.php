<?php
require_once __DIR__ . '/includes/db_connect.php';
header('Content-Type: application/json; charset=utf-8');

$debugFile = __DIR__ . "/updateTextComments.log";
$raw = file_get_contents("php://input");
file_put_contents($debugFile, date("Y-m-d H:i:s") . "\n" . $raw . "\n---\n", FILE_APPEND);
// === DEBUG LOG END ===

// Decode JSON
$data = json_decode($raw, true);

// 🔹 Parse incoming JSON payload
$input = file_get_contents('php://input');
$payload = json_decode($input, true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid JSON"]);
    exit;
}

$surrogate = intval($payload['surrogate'] ?? 0);
$owner     = trim($payload['owner'] ?? '');
$annotator = trim($payload['annotator'] ?? '');
$comments  = $payload['comments'] ?? [];

if (!$surrogate || !$owner || !$annotator) {
    http_response_code(400);
    echo json_encode(["error" => "Missing parameters"]);
    exit;
}

// 🔹 Encode all comments as a single JSON blob
$commentsJson = json_encode($comments, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

// 🔹 Insert or update (1 record per surrogate+annotator)
$stmt = $mysqli->prepare("
    INSERT INTO text_comments (surrogate, owner, annotator, comments)
    VALUES (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE comments = VALUES(comments), updated_at = CURRENT_TIMESTAMP
");

if (!$stmt) {
    http_response_code(500);
    echo json_encode(["error" => "DB prepare failed", "details" => $mysqli->error]);
    exit;
}

$stmt->bind_param("isss", $surrogate, $owner, $annotator, $commentsJson);
$ok = $stmt->execute();
$stmt->close();

if (!$ok) {
    http_response_code(500);
    echo json_encode(["error" => "DB write failed", "details" => $mysqli->error]);
    exit;
}

echo json_encode([
    "status" => "success",
    "saved"  => count($comments),
    "surrogate" => $surrogate,
    "annotator" => $annotator
]);
