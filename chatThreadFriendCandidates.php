<?php
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/db_connect.php";
require_once __DIR__ . "/chatThreadCommon.php";

sec_session_start();
header("Content-Type: application/json");

if (!login_check($mysqli)) {
    ct_json_response(["status" => "error", "message" => "Not logged in"], 403);
}

$actorId = (int)($_SESSION["user_id"] ?? 0);
$threadId = (int)($_GET["thread_id"] ?? 0);
if ($actorId <= 0) {
    ct_json_response(["status" => "error", "message" => "Session invalid"], 403);
}

if ($threadId > 0 && !ct_user_in_thread($mysqli, $threadId, $actorId)) {
    ct_json_response(["status" => "error", "message" => "Permission denied"], 403);
}

$sql = "
SELECT
  m.id AS member_id,
  m.username,
  m.display_name,
  COALESCE(m.avatar_url, '/default-avatar.png') AS avatar_url,
  CASE WHEN tm.member_id IS NULL THEN 0 ELSE 1 END AS in_thread
FROM (
  SELECT i.member_id AS candidate_id
  FROM invitations i
  JOIN content_lists cl ON cl.token = i.listToken
  WHERE cl.owner_id = ?
    AND i.member_id IS NOT NULL
    AND i.role NOT IN ('paused','request')

  UNION

  SELECT cl.owner_id AS candidate_id
  FROM invitations i
  JOIN content_lists cl ON cl.token = i.listToken
  WHERE i.member_id = ?
    AND i.role NOT IN ('paused','request')

  UNION

  SELECT i2.member_id AS candidate_id
  FROM invitations i
  JOIN content_lists cl ON cl.token = i.listToken
  JOIN invitations i2 ON i2.listToken = cl.token
  WHERE i.member_id = ?
    AND i2.member_id IS NOT NULL
    AND i2.role NOT IN ('paused','request')

  UNION

  SELECT tm2.member_id AS candidate_id
  FROM chat_thread_members tm2
  WHERE tm2.thread_id = ?
    AND tm2.left_at IS NULL
) c
JOIN members m ON m.id = c.candidate_id
LEFT JOIN chat_thread_members tm
  ON tm.thread_id = ?
 AND tm.member_id = m.id
 AND tm.left_at IS NULL
WHERE m.id <> ?
ORDER BY in_thread DESC, COALESCE(NULLIF(m.display_name, ''), m.username) ASC
LIMIT 250
";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param("iiiiii", $actorId, $actorId, $actorId, $threadId, $threadId, $actorId);
$stmt->execute();
$result = $stmt->get_result();

$friends = [];
while ($row = $result->fetch_assoc()) {
    $friends[] = [
        "member_id" => (int)($row["member_id"] ?? 0),
        "username" => (string)($row["username"] ?? ""),
        "display_name" => (string)($row["display_name"] ?? ""),
        "avatar_url" => (string)($row["avatar_url"] ?? "/default-avatar.png"),
        "in_thread" => (int)($row["in_thread"] ?? 0) === 1
    ];
}
$stmt->close();

ct_json_response([
    "status" => "OK",
    "friends" => $friends
]);
