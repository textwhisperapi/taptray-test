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
$targetId = (int)($_POST["member_id"] ?? 0);
if ($actorId <= 0 || $threadId <= 0 || $targetId <= 0) {
    ct_json_response(["status" => "error", "message" => "Invalid input"], 400);
}

if (!ct_user_in_thread($mysqli, $threadId, $actorId)) {
    ct_json_response(["status" => "error", "message" => "Permission denied"], 403);
}
if (!ct_user_in_thread($mysqli, $threadId, $targetId)) {
    ct_json_response(["status" => "error", "message" => "Member not in thread"], 404);
}

$manageInfo = ct_thread_manage_info($mysqli, $threadId, $actorId);
if (empty($manageInfo["exists"])) {
    ct_json_response(["status" => "error", "message" => "Thread not found"], 404);
}
$creatorId = (int)($manageInfo["creator_id"] ?? 0);
$actorCanManage = !empty($manageInfo["can_manage"]);
$actorIsCreator = !empty($manageInfo["is_creator"]);

$targetRole = ct_thread_member_role($mysqli, $threadId, $targetId);
$targetIsCreator = $targetId === $creatorId;

if ($targetId !== $actorId) {
    if (!$actorCanManage) {
        ct_json_response(["status" => "error", "message" => "Only chat managers can remove others"], 403);
    }
    if (!$actorIsCreator && ($targetIsCreator || in_array($targetRole, ["owner", "admin"], true))) {
        ct_json_response(["status" => "error", "message" => "Admins cannot remove owner/admin"], 403);
    }
}

$stmt = $mysqli->prepare("SELECT COUNT(*) AS c FROM chat_thread_members WHERE thread_id = ? AND left_at IS NULL");
$stmt->bind_param("i", $threadId);
$stmt->execute();
$countRow = $stmt->get_result()->fetch_assoc();
$stmt->close();
$activeCount = (int)($countRow["c"] ?? 0);
if ($activeCount <= 1) {
    ct_json_response(["status" => "error", "message" => "Cannot remove last member"], 400);
}

$mysqli->begin_transaction();
try {
    $stmt = $mysqli->prepare("UPDATE chat_thread_members SET left_at = NOW() WHERE thread_id = ? AND member_id = ? AND left_at IS NULL");
    $stmt->bind_param("ii", $threadId, $targetId);
    $stmt->execute();
    $stmt->close();

    if ($targetId === $creatorId) {
        $stmt = $mysqli->prepare("\n            SELECT member_id\n            FROM chat_thread_members\n            WHERE thread_id = ? AND left_at IS NULL\n            ORDER BY joined_at ASC\n            LIMIT 1\n        ");
        $stmt->bind_param("i", $threadId);
        $stmt->execute();
        $next = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $nextId = (int)($next["member_id"] ?? 0);
        if ($nextId > 0) {
            $stmt = $mysqli->prepare("UPDATE chat_threads SET created_by_member_id = ? WHERE id = ?");
            $stmt->bind_param("ii", $nextId, $threadId);
            $stmt->execute();
            $stmt->close();

            $stmt = $mysqli->prepare("UPDATE chat_thread_members SET role = 'owner' WHERE thread_id = ? AND member_id = ? AND left_at IS NULL");
            $stmt->bind_param("ii", $threadId, $nextId);
            $stmt->execute();
            $stmt->close();
        }
    }

    $title = ct_group_title_from_members($mysqli, $threadId);
    $stmt = $mysqli->prepare("UPDATE chat_threads SET title = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param("si", $title, $threadId);
    $stmt->execute();
    $stmt->close();

    $mysqli->commit();
} catch (Throwable $e) {
    $mysqli->rollback();
    ct_json_response(["status" => "error", "message" => "Unable to remove member"], 500);
}

ct_json_response(["status" => "OK"]);
