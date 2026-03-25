<?php
require_once __DIR__ . "/includes/db_connect.php";
require_once __DIR__ . "/includes/functions.php";
sec_session_start();

header("Content-Type: application/json");
$mysqli->set_charset("utf8mb4");
$mysqli->query("SET collation_connection = 'utf8mb4_unicode_ci'");

// ✅ Must be logged in
if (!login_check($mysqli)) {
    http_response_code(403);
    echo json_encode(["error" => "Not logged in"]);
    exit;
}

$message_id = intval($_GET['id'] ?? 0);
if (!$message_id) {
    http_response_code(400);
    echo json_encode(["error" => "Missing message id"]);
    exit;
}

// ✅ Fetch reactions for this message
$stmt = $mysqli->prepare("
    SELECT r.emoji, m.display_name
    FROM chat_reactions r
    JOIN members m ON m.username = r.username
    WHERE r.message_id = ?
");
$stmt->bind_param("i", $message_id);
$stmt->execute();
$res = $stmt->get_result();

$reactions_detailed = [];
while ($row = $res->fetch_assoc()) {
    $emoji = $row['emoji'];
    $who   = $row['display_name'] ?? '?';
    if (!isset($reactions_detailed[$emoji])) {
        $reactions_detailed[$emoji] = [];
    }
    $reactions_detailed[$emoji][] = $who;
}
$stmt->close();

// ✅ Build summary counts
$reactions = [];
foreach ($reactions_detailed as $emoji => $users) {
    $reactions[] = [
        "emoji" => $emoji,
        "count" => count($users)
    ];
}

echo json_encode([
    "status" => "success",
    "reactions" => $reactions,
    "detailed" => $reactions_detailed
]);
