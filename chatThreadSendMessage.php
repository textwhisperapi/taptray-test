<?php
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/chatThreadCommon.php";
require_once __DIR__ . "/chatConfig.php";
require_once __DIR__ . "/vendor/autoload.php";

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

sec_session_start();
header("Content-Type: application/json");

if (!login_check($mysqli)) {
    ct_json_response(["error" => "Not logged in"], 403);
}

$memberId = (int)($_SESSION["user_id"] ?? 0);
$username = (string)($_SESSION["username"] ?? "");
$threadId = (int)($_POST["thread_id"] ?? 0);
$message = trim((string)($_POST["message"] ?? ""));
$alertRequested = isset($_POST["alert"]) && (string)$_POST["alert"] === "1";
if ($memberId <= 0 || $username === "" || $threadId <= 0 || $message === "") {
    ct_json_response(["error" => "Missing thread_id or message"], 400);
}

if (!ct_user_in_thread($mysqli, $threadId, $memberId)) {
    ct_json_response(["error" => "Permission denied"], 403);
}

$token = ct_dm_token($threadId);
$mysqli->set_charset("utf8mb4");
$mysqli->query("SET collation_connection = 'utf8mb4_unicode_ci'");

$stmt = $mysqli->prepare("
    INSERT INTO chat_messages (listToken, thread_id, username, message)
    VALUES (?, ?, ?, ?)
");
$stmt->bind_param("siss", $token, $threadId, $username, $message);
$success = $stmt->execute();
$messageId = (int)$stmt->insert_id;
$stmt->close();

if (!$success) {
    ct_json_response(["status" => "failed"], 500);
}

ct_touch_thread_updated_at($mysqli, $threadId);

if ($alertRequested) {
    $stmt = $mysqli->prepare("SELECT thread_type, title FROM chat_threads WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $threadId);
    $stmt->execute();
    $threadRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $threadType = (string)($threadRow["thread_type"] ?? "dm");
    $threadTitle = trim((string)($threadRow["title"] ?? ""));

    $host = (string)($_SERVER["HTTP_HOST"] ?? "textwhisper.com");
    $host = explode(":", $host)[0];
    $defaultEnv = preg_replace('/^www\./', '', $host);
    $env = preg_replace('/[^a-z0-9\.\-]/i', '', (string)($_POST["env"] ?? $defaultEnv));
    if ($env === "") $env = "textwhisper.com";

    $stmt = $mysqli->prepare("
        SELECT m.username AS target_username, ps.endpoint, ps.p256dh, ps.auth,
               ns.enabled, ns.sound_mode, ns.show_message
        FROM chat_thread_members tm
        JOIN members m ON m.id = tm.member_id
        JOIN push_subscriptions ps ON ps.username = m.username
        LEFT JOIN notification_settings ns
          ON ns.username = m.username AND ns.list_token = ?
        WHERE tm.thread_id = ?
          AND tm.left_at IS NULL
          AND tm.member_id <> ?
          AND ps.env = ?
    ");
    $dmToken = ct_dm_token($threadId);
    $stmt->bind_param("siis", $dmToken, $threadId, $memberId, $env);
    $stmt->execute();
    $subs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (!empty($subs)) {
        $webPush = new WebPush([
            "VAPID" => [
                "subject" => getenv("VAPID_SUBJECT"),
                "publicKey" => getenv("VAPID_PUBLIC_KEY"),
                "privateKey" => getenv("VAPID_PRIVATE_KEY"),
            ]
        ]);

        foreach ($subs as $sub) {
            if (isset($sub["enabled"]) && !(int)$sub["enabled"]) continue;
            try {
                $targetUsername = (string)($sub["target_username"] ?? "");
                $targetPath = $targetUsername !== "" ? "/" . rawurlencode($targetUsername) : "/";
                $targetUrl = "https://{$env}{$targetPath}"
                    . "?open_chat_token=" . rawurlencode($dmToken)
                    . "&open_chat_msg=" . (int)$messageId;
                $title = ($threadType === "group")
                    ? ($threadTitle !== "" ? $threadTitle : "Group chat")
                    : "Direct message";
                $payload = json_encode([
                    "title" => $title,
                    "body" => $username . " sent an alert",
                    "url" => $targetUrl,
                    "sound" => $sub["sound_mode"] ?? "ding"
                ]);
                $subscription = Subscription::create([
                    "endpoint" => $sub["endpoint"],
                    "keys" => [
                        "p256dh" => $sub["p256dh"],
                        "auth" => $sub["auth"]
                    ]
                ]);
                $webPush->queueNotification($subscription, $payload);
            } catch (Throwable $e) {
                error_log("DM push queue failed: " . $e->getMessage());
            }
        }

        foreach ($webPush->flush() as $report) {
            if (!$report->isSuccess() && $report->isSubscriptionExpired()) {
                $endpoint = $report->getEndpoint();
                $del = $mysqli->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?");
                if ($del) {
                    $del->bind_param("s", $endpoint);
                    $del->execute();
                    $del->close();
                }
            }
        }
    }
}

ct_json_response([
    "status" => "success",
    "thread_id" => $threadId,
    "message_id" => $messageId
]);
