<?php
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/chatThreadCommon.php";

sec_session_start();
header("Content-Type: application/json");

$mysqli->set_charset("utf8mb4");
$mysqli->query("SET collation_connection = 'utf8mb4_unicode_ci'");

if (!login_check($mysqli)) {
    ct_json_response(["error" => "Not logged in"], 403);
}

$memberId = (int)($_SESSION["user_id"] ?? 0);
$username = (string)($_SESSION["username"] ?? "");
$threadId = (int)($_GET["thread_id"] ?? 0);
if ($memberId <= 0 || $username === "" || $threadId <= 0) {
    ct_json_response(["error" => "Invalid input"], 400);
}

if (!ct_user_in_thread($mysqli, $threadId, $memberId)) {
    ct_json_response(["error" => "Permission denied"], 403);
}

$meta = ct_thread_meta_for_user($mysqli, $threadId, $memberId);
if (!$meta) {
    ct_json_response(["error" => "Thread not found"], 404);
}

$stmt = $mysqli->prepare("
    SELECT
      m.id,
      m.username,
      d.display_name,
      d.avatar_url,
      m.message,
      m.created_at,
      CASE
        WHEN m.username = ? AND m.created_at >= (NOW() - INTERVAL 60 MINUTE) THEN 1
        ELSE 0
      END AS can_delete
    FROM chat_messages m
    LEFT JOIN members d ON d.username = m.username
    WHERE m.thread_id = ?
    ORDER BY m.id DESC
    LIMIT 50
");
$stmt->bind_param("si", $username, $threadId);
$stmt->execute();
$result = $stmt->get_result();

$messages = [];
$messageIds = [];
while ($row = $result->fetch_assoc()) {
    $id = (int)$row["id"];
    $messageIds[] = $id;
    $messages[$id] = [
        "id" => $id,
        "username" => $row["username"],
        "display_name" => $row["display_name"],
        "avatar_url" => $row["avatar_url"] ?? "/default-avatar.png",
        "message" => $row["message"],
        "created_at" => $row["created_at"],
        "can_delete" => (int)($row["can_delete"] ?? 0) === 1,
        "reactions_detailed" => []
    ];
}
$stmt->close();

if (!empty($messageIds)) {
    $placeholders = implode(",", array_fill(0, count($messageIds), "?"));
    $types = str_repeat("i", count($messageIds));
    $stmt = $mysqli->prepare("
        SELECT r.message_id, r.emoji, m.display_name
        FROM chat_reactions r
        JOIN members m ON m.username = r.username
        WHERE r.message_id IN ($placeholders)
    ");
    $stmt->bind_param($types, ...$messageIds);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $mid = (int)$row["message_id"];
        if (!isset($messages[$mid])) continue;
        $messages[$mid]["reactions_detailed"][$row["emoji"]][] = $row["display_name"] ?? "?";
    }
    $stmt->close();
}

foreach ($messages as &$msg) {
    $msg["reactions"] = [];
    foreach ($msg["reactions_detailed"] as $emoji => $users) {
        $msg["reactions"][] = [
            "emoji" => $emoji,
            "count" => count($users)
        ];
    }
}
unset($msg);

ct_json_response([
    "meta" => $meta,
    "messages" => array_values(array_reverse($messages))
]);
