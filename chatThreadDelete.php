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

$actorIsCreator = !empty($manageInfo["is_creator"]);

$mysqli->begin_transaction();
try {
    if ($actorIsCreator) {
        $stmt = $mysqli->prepare("
            UPDATE chat_threads
            SET is_active = 0, updated_at = NOW()
            WHERE id = ? AND is_active = 1
        ");
        $stmt->bind_param("i", $threadId);
        $stmt->execute();
        $stmt->close();

        $stmt = $mysqli->prepare("
            UPDATE chat_thread_members
            SET left_at = NOW()
            WHERE thread_id = ? AND left_at IS NULL
        ");
        $stmt->bind_param("i", $threadId);
        $stmt->execute();
        $stmt->close();

        $scope = "all";
        $message = "Chat deleted for all members.";
    } else {
        $stmt = $mysqli->prepare("
            UPDATE chat_thread_members
            SET left_at = NOW()
            WHERE thread_id = ? AND member_id = ? AND left_at IS NULL
        ");
        $stmt->bind_param("ii", $threadId, $actorId);
        $stmt->execute();
        $stmt->close();

        $stmt = $mysqli->prepare("
            SELECT COUNT(*) AS c
            FROM chat_thread_members
            WHERE thread_id = ? AND left_at IS NULL
        ");
        $stmt->bind_param("i", $threadId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $remaining = (int)($row["c"] ?? 0);

        if ($remaining <= 0) {
            $stmt = $mysqli->prepare("
                UPDATE chat_threads
                SET is_active = 0, updated_at = NOW()
                WHERE id = ? AND is_active = 1
            ");
            $stmt->bind_param("i", $threadId);
            $stmt->execute();
            $stmt->close();
        }

        $scope = "self";
        $message = "Chat deleted for you.";
    }

    $mysqli->commit();
} catch (Throwable $e) {
    $mysqli->rollback();
    ct_json_response(["status" => "error", "message" => "Unable to delete chat"], 500);
}

ct_json_response([
    "status" => "OK",
    "scope" => $scope,
    "message" => $message
]);

