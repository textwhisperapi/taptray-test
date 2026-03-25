<?php
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/chatThreadCommon.php";

sec_session_start();
header("Content-Type: application/json");

if (!login_check($mysqli)) {
    ct_json_response(["status" => "error", "message" => "Not logged in"], 403);
}

$actorId = (int)($_SESSION["user_id"] ?? 0);
$threadId = (int)($_GET["thread_id"] ?? 0);
if ($actorId <= 0 || $threadId <= 0) {
    ct_json_response(["status" => "error", "message" => "Invalid input"], 400);
}

if (!ct_user_in_thread($mysqli, $threadId, $actorId)) {
    ct_json_response(["status" => "error", "message" => "Permission denied"], 403);
}

$manageInfo = ct_thread_manage_info($mysqli, $threadId, $actorId);
if (empty($manageInfo["exists"])) {
    ct_json_response(["status" => "error", "message" => "Thread not found"], 404);
}
$creatorId = (int)($manageInfo["creator_id"] ?? 0);
$actorCanManage = !empty($manageInfo["can_manage"]);
$actorIsCreator = !empty($manageInfo["is_creator"]);

$stmt = $mysqli->prepare("\n    SELECT m.id AS member_id, m.username, m.display_name, COALESCE(m.avatar_url, '/default-avatar.png') AS avatar_url, tm.role\n    FROM chat_thread_members tm\n    JOIN members m ON m.id = tm.member_id\n    WHERE tm.thread_id = ? AND tm.left_at IS NULL\n    ORDER BY tm.joined_at ASC\n");
$stmt->bind_param("i", $threadId);
$stmt->execute();
$result = $stmt->get_result();

$members = [];
while ($row = $result->fetch_assoc()) {
    $mid = (int)($row["member_id"] ?? 0);
    $targetRole = strtolower((string)($row["role"] ?? "member"));
    $targetIsCreator = $mid === $creatorId;
    $canRemove = false;
    if ($mid === $actorId) {
        $canRemove = true;
    } elseif ($actorCanManage) {
        if ($actorIsCreator) {
            $canRemove = true;
        } else {
            $canRemove = !$targetIsCreator && !in_array($targetRole, ["owner", "admin"], true);
        }
    }
    $members[] = [
        "member_id" => $mid,
        "username" => (string)($row["username"] ?? ""),
        "display_name" => (string)($row["display_name"] ?? ""),
        "avatar_url" => (string)($row["avatar_url"] ?? "/default-avatar.png"),
        "is_self" => $mid === $actorId,
        "can_remove" => $canRemove
    ];
}
$stmt->close();

ct_json_response([
    "status" => "OK",
    "creator_id" => $creatorId,
    "members" => $members
]);
