<?php
require_once __DIR__ . '/includes/db_connect.php';
header('Content-Type: application/json; charset=utf-8');
header('HTTP/1.1 200 OK');       // ✅ Always send 200 OK

// --- Debug log (optional) ---
$debugFile = __DIR__ . "/deleteTextComments.log";
$raw = file_get_contents("php://input");
file_put_contents($debugFile, date("Y-m-d H:i:s") . "\n" . $raw . "\n---\n", FILE_APPEND);
// --- End debug log ---

$data = json_decode($raw, true);
if (!is_array($data)) {
    echo json_encode(["status" => "error", "message" => "Invalid JSON"]);
    exit;
}

$surrogate = intval($data['surrogate'] ?? 0);
$owner     = trim($data['owner'] ?? '');
$annotator = trim($data['annotator'] ?? '');

if (!$surrogate || !$owner || !$annotator) {
    echo json_encode(["status" => "error", "message" => "Missing parameters"]);
    exit;
}

$stmt = $mysqli->prepare("DELETE FROM text_comments WHERE surrogate=? AND owner=? AND annotator=?");
if (!$stmt) {
    echo json_encode(["status" => "error", "message" => $mysqli->error]);
    exit;
}

$stmt->bind_param("iss", $surrogate, $owner, $annotator);
$stmt->execute();
$deleted = $stmt->affected_rows;
$stmt->close();

// ✅ Always return valid JSON, even if nothing deleted
echo json_encode([
    "status"     => "cleared",
    "deleted"    => $deleted,
    "surrogate"  => $surrogate,
    "annotator"  => $annotator
], JSON_UNESCAPED_UNICODE);
exit;
