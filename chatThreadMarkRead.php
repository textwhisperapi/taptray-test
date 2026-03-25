<?php
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/chatThreadCommon.php";

sec_session_start();
header("Content-Type: application/json");

if (!login_check($mysqli)) {
    ct_json_response(["error" => "Not logged in"], 403);
}

$memberId = (int)($_SESSION["user_id"] ?? 0);
$username = (string)($_SESSION["username"] ?? "");
$threadId = (int)($_POST["thread_id"] ?? 0);
if ($memberId <= 0 || $username === "" || $threadId <= 0) {
    ct_json_response(["error" => "Missing thread_id"], 400);
}

if (!ct_user_in_thread($mysqli, $threadId, $memberId)) {
    ct_json_response(["error" => "Permission denied"], 403);
}

ct_mark_read($mysqli, $username, $threadId);
ct_json_response(["status" => "OK"]);
