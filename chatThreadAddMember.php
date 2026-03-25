<?php
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/chatThreadCommon.php";

sec_session_start();
header("Content-Type: application/json");

if (!login_check($mysqli)) {
    ct_json_response(["status" => "error", "message" => "Not logged in"], 403);
}

$actorId = (int)($_SESSION["user_id"] ?? 0);
$threadId = (int)($_POST["thread_id"] ?? 0);
$usernameRaw = trim((string)($_POST["username"] ?? ""));
if ($actorId <= 0 || $threadId <= 0 || $usernameRaw === "") {
    ct_json_response(["status" => "error", "message" => "Missing input"], 400);
}

if (!preg_match('/^[a-zA-Z0-9._-]{2,64}$/', $usernameRaw)) {
    ct_json_response(["status" => "error", "message" => "Invalid username"], 400);
}

if (!ct_user_in_thread($mysqli, $threadId, $actorId)) {
    ct_json_response(["status" => "error", "message" => "Permission denied"], 403);
}

$target = ct_find_member_by_username($mysqli, $usernameRaw);
if (!$target) {
    ct_json_response(["status" => "error", "message" => "Member not found"], 404);
}
$targetId = (int)($target["id"] ?? 0);
if ($targetId <= 0 || $targetId === $actorId) {
    ct_json_response(["status" => "error", "message" => "Invalid member"], 400);
}

if (ct_user_in_thread($mysqli, $threadId, $targetId)) {
    $meta = ct_thread_meta_for_user($mysqli, $threadId, $actorId);
    ct_json_response([
        "status" => "OK",
        "thread_id" => $threadId,
        "token" => ct_dm_token($threadId),
        "meta" => $meta,
        "message" => "Member already in thread"
    ]);
}

$stmt = $mysqli->prepare("SELECT thread_type, title FROM chat_threads WHERE id = ? AND is_active = 1 LIMIT 1");
$stmt->bind_param("i", $threadId);
$stmt->execute();
$thread = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$thread) {
    ct_json_response(["status" => "error", "message" => "Thread not found"], 404);
}

$mysqli->begin_transaction();
try {
    if (($thread["thread_type"] ?? "") === "dm") {
        $title = trim((string)($thread["title"] ?? ""));
        if ($title === "") {
            $title = "Group chat";
        }
        $stmt = $mysqli->prepare("
            UPDATE chat_threads
            SET thread_type = 'group', title = ?, member_a_id = NULL, member_b_id = NULL, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param("si", $title, $threadId);
        $stmt->execute();
        $stmt->close();
    } else {
        ct_touch_thread_updated_at($mysqli, $threadId);
    }

    $role = "member";
    $joinedAt = date("Y-m-d H:i:s");
    $stmt = $mysqli->prepare("
        INSERT INTO chat_thread_members (thread_id, member_id, role, joined_at, left_at)
        VALUES (?, ?, ?, ?, NULL)
        ON DUPLICATE KEY UPDATE left_at = NULL
    ");
    $stmt->bind_param("iiss", $threadId, $targetId, $role, $joinedAt);
    $stmt->execute();
    $stmt->close();

    // Keep group title aligned to member first names.
    $groupTitle = ct_group_title_from_members($mysqli, $threadId);
    $stmt = $mysqli->prepare("UPDATE chat_threads SET title = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param("si", $groupTitle, $threadId);
    $stmt->execute();
    $stmt->close();

    $mysqli->commit();
} catch (Throwable $e) {
    $mysqli->rollback();
    ct_json_response(["status" => "error", "message" => "Unable to add member"], 500);
}

$meta = ct_thread_meta_for_user($mysqli, $threadId, $actorId);
ct_json_response([
    "status" => "OK",
    "thread_id" => $threadId,
    "token" => ct_dm_token($threadId),
    "meta" => $meta
]);
