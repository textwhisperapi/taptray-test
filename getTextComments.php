<?php
require_once __DIR__ . '/includes/db_connect.php';
header('Content-Type: application/json; charset=utf-8');

$surrogate     = intval($_GET['surrogate'] ?? 0);
$annotator     = trim($_GET['annotator'] ?? '');
$clientVersion = trim($_GET['v'] ?? '');

if (!$surrogate || !$annotator) {
    http_response_code(400);
    echo json_encode(["error" => "Missing parameters"]);
    exit;
}

$stmt = $mysqli->prepare("
    SELECT owner, annotator, comments, updated_at
    FROM text_comments
    WHERE surrogate = ? AND annotator = ?
    LIMIT 1
");
$stmt->bind_param("is", $surrogate, $annotator);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$stmt->close();

// ==================================================
// ⭐ CASE 1: NO COMMENTS EXIST
// ==================================================
if (!$row) {
    header("X-Comments-Version: ");
    echo json_encode([
        "surrogate"  => $surrogate,
        "owner"      => null,
        "annotator"  => $annotator,
        "updated_at" => "",
        "count"      => 0,
        "comments"   => []
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ==================================================
// ⭐ CASE 2: COMMENTS FOUND
// ==================================================
$dbVersion = $row['updated_at'] ?: "";

// Always send version header
header("X-Comments-Version: {$dbVersion}");

// 304 → no change
if ($clientVersion !== "" && $clientVersion === $dbVersion) {
    http_response_code(304);
    exit();
}

// Decode JSON safely
$comments = json_decode($row['comments'], true);
if (!is_array($comments)) {
    $comments = [];
}

// Output full JSON
echo json_encode([
    "surrogate"  => $surrogate,
    "owner"      => $row['owner'],
    "annotator"  => $row['annotator'],
    "updated_at" => $dbVersion,
    "count"      => count($comments),
    "comments"   => $comments
], JSON_UNESCAPED_UNICODE);
?>
