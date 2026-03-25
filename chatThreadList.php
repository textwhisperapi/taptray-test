<?php
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/chatThreadCommon.php";

sec_session_start();
header("Content-Type: application/json");

if (!login_check($mysqli)) {
    ct_json_response(["status" => "error", "message" => "Not logged in"], 403);
}

$memberId = (int)($_SESSION["user_id"] ?? 0);
$username = (string)($_SESSION["username"] ?? "");
if ($memberId <= 0 || $username === "") {
    ct_json_response(["status" => "error", "message" => "Session invalid"], 403);
}

$stmt = $mysqli->prepare("
    SELECT
      t.id AS thread_id,
      t.thread_type,
      t.title,
      t.updated_at,
      (
        SELECT m.id
        FROM chat_thread_members tm2
        JOIN members m ON m.id = tm2.member_id
        WHERE tm2.thread_id = t.id
          AND tm2.member_id <> ?
          AND tm2.left_at IS NULL
        ORDER BY tm2.joined_at ASC
        LIMIT 1
      ) AS other_member_id,
      (
        SELECT m.username
        FROM chat_thread_members tm2
        JOIN members m ON m.id = tm2.member_id
        WHERE tm2.thread_id = t.id
          AND tm2.member_id <> ?
          AND tm2.left_at IS NULL
        ORDER BY tm2.joined_at ASC
        LIMIT 1
      ) AS other_username,
      (
        SELECT m.display_name
        FROM chat_thread_members tm2
        JOIN members m ON m.id = tm2.member_id
        WHERE tm2.thread_id = t.id
          AND tm2.member_id <> ?
          AND tm2.left_at IS NULL
        ORDER BY tm2.joined_at ASC
        LIMIT 1
      ) AS other_display_name,
      (
        SELECT m.avatar_url
        FROM chat_thread_members tm2
        JOIN members m ON m.id = tm2.member_id
        WHERE tm2.thread_id = t.id
          AND tm2.member_id <> ?
          AND tm2.left_at IS NULL
        ORDER BY tm2.joined_at ASC
        LIMIT 1
      ) AS other_avatar_url,
      (
        SELECT MAX(cm.created_at)
        FROM chat_messages cm
        WHERE cm.thread_id = t.id
      ) AS last_message_time,
      (
        SELECT COUNT(*)
        FROM chat_messages cm
        WHERE cm.thread_id = t.id
          AND cm.username <> ?
          AND cm.created_at > COALESCE(
            (SELECT cr.last_read_at
             FROM chat_reads cr
             WHERE cr.username = ? AND cr.thread_id = t.id
             ORDER BY cr.last_read_at DESC
             LIMIT 1),
            tm.joined_at,
            '1970-01-01 00:00:00'
          )
      ) AS unread_count
    FROM chat_threads t
    JOIN chat_thread_members tm ON tm.thread_id = t.id
    WHERE t.thread_type IN ('dm','group')
      AND t.is_active = 1
      AND tm.member_id = ?
      AND tm.left_at IS NULL
    ORDER BY COALESCE(last_message_time, t.updated_at) DESC
");
$stmt->bind_param("iiiissi", $memberId, $memberId, $memberId, $memberId, $username, $username, $memberId);
$stmt->execute();
$result = $stmt->get_result();

$threads = [];
while ($row = $result->fetch_assoc()) {
    $threadId = (int)($row["thread_id"] ?? 0);
    if ($threadId <= 0) continue;
    $type = (string)($row["thread_type"] ?? "dm");
    $rawTitle = trim((string)($row["title"] ?? ""));
    $chatName = ($type === "group")
        ? (($rawTitle !== "" && $rawTitle !== "Group chat") ? $rawTitle : ct_group_title_from_members($mysqli, $threadId))
        : ($row["other_display_name"] ?: $row["other_username"] ?: ("DM #" . $threadId));
    $threads[] = [
        "thread_id" => $threadId,
        "token" => ct_dm_token($threadId),
        "thread_type" => $type,
        "chat_name" => $chatName,
        "unread" => (int)($row["unread_count"] ?? 0),
        "last" => $row["last_message_time"] ?: $row["updated_at"],
        "other_member" => ($type === "dm") ? [
            "id" => (int)($row["other_member_id"] ?? 0),
            "username" => $row["other_username"] ?? "",
            "display_name" => $row["other_display_name"] ?? "",
            "avatar_url" => $row["other_avatar_url"] ?? ""
        ] : null
    ];
}
$stmt->close();

ct_json_response([
    "status" => "OK",
    "threads" => $threads
]);
