<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/db_connect.php";

sec_session_start();

$surrogate = intval($_GET['surrogate'] ?? 0);
if (!$surrogate) {
    echo json_encode(["status" => "error", "message" => "No surrogate provided"]);
    exit;
}

// 🔍 Look up owner in `text`, then join to `members` for fileserver
$sql = "
    SELECT t.owner AS owner, m.fileserver
    FROM text t
    JOIN members m ON t.owner = m.username
    WHERE t.surrogate = ?
    LIMIT 1
";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $surrogate);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stmt->close();

if ($row) {
    echo json_encode([
        "status"    => "success",
        "surrogate" => $surrogate,
        "owner"     => $row['owner'],
        "fileserver"=> $row['fileserver'] ?: "php"
    ]);
} else {
    echo json_encode([
        "status"  => "error",
        "message" => "Item not found"
    ]);
}
