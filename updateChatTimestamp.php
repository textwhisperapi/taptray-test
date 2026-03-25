<?php
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/db_connect.php";

sec_session_start();

$con = $mysqli;


if (!$con) {
    error_log("[chat_reads] ❌ DB connection failed: " . mysqli_connect_error());
    http_response_code(500);
    echo json_encode(["error" => "DB connection failed"]);
    exit;
}

if (!isset($_SESSION['username'])) {
    error_log("[chat_reads] ❌ No session username");
    http_response_code(401);
    echo json_encode(["error" => "Not logged in"]);
    exit;
}

$username = $_SESSION['username'];
$listToken = $_POST['listToken'] ?? null;

if (!$listToken) {
    error_log("[chat_reads] ❌ Missing listToken from POST");
    http_response_code(400);
    echo json_encode(["error" => "Missing listToken"]);
    exit;
}

error_log("[chat_reads] Processing: $username → $listToken");

$stmt = $con->prepare("
    INSERT INTO chat_reads (username, listToken, last_read_at)
    VALUES (?, ?, NOW())
    ON DUPLICATE KEY UPDATE last_read_at = NOW()
");

if (!$stmt) {
    error_log("[chat_reads] ❌ Prepare failed: " . $con->error);
    http_response_code(500);
    echo json_encode(["error" => "Prepare failed"]);
    exit;
}

$stmt->bind_param("ss", $username, $listToken);

if (!$stmt->execute()) {
    error_log("[chat_reads] ❌ Execute failed: " . $stmt->error);
    http_response_code(500);
    echo json_encode(["error" => "Execute failed"]);
    exit;
}

error_log("[chat_reads] ✅ Updated read timestamp for $username on $listToken");
echo json_encode(["status" => "OK"]);
