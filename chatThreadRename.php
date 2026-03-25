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
$title = trim((string)($_POST["title"] ?? ""));
if ($actorId <= 0 || $threadId <= 0) {
    ct_json_response(["status" => "error", "message" => "Invalid input"], 400);
}

if (!ct_user_in_thread($mysqli, $threadId, $actorId)) {
    ct_json_response(["status" => "error", "message" => "Permission denied"], 403);
}

if ($title === "") {
    $title = ct_group_title_from_members($mysqli, $threadId);
}

if (mb_strlen($title) > 255) {
    $title = mb_substr($title, 0, 255);
}

$stmt = $mysqli->prepare("UPDATE chat_threads SET title = ?, updated_at = NOW() WHERE id = ? AND is_active = 1");
$stmt->bind_param("si", $title, $threadId);
$stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

if ($affected < 0) {
    ct_json_response(["status" => "error", "message" => "Unable to rename thread"], 500);
}

$meta = ct_thread_meta_for_user($mysqli, $threadId, $actorId);
ct_json_response([
    "status" => "OK",
    "thread_id" => $threadId,
    "token" => ct_dm_token($threadId),
    "meta" => $meta
]);

