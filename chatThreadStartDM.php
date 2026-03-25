<?php
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/chatThreadCommon.php";

sec_session_start();
header("Content-Type: application/json");

if (!login_check($mysqli)) {
    ct_json_response(["status" => "error", "message" => "Not logged in"], 403);
}

$actorId = (int)($_SESSION["user_id"] ?? 0);
$targetId = (int)($_POST["member_id"] ?? 0);
if ($actorId <= 0 || $targetId <= 0 || $actorId === $targetId) {
    ct_json_response(["status" => "error", "message" => "Invalid member_id"], 400);
}

$target = ct_find_member_by_id($mysqli, $targetId);
if (!$target) {
    ct_json_response(["status" => "error", "message" => "Member not found"], 404);
}

try {
    $threadId = ct_find_or_create_dm_thread($mysqli, $actorId, $targetId);
    $meta = ct_thread_meta_for_user($mysqli, $threadId, $actorId);
    ct_json_response([
        "status" => "OK",
        "thread_id" => $threadId,
        "token" => ct_dm_token($threadId),
        "meta" => $meta
    ]);
} catch (Throwable $e) {
    ct_json_response(["status" => "error", "message" => "Unable to create DM thread"], 500);
}
