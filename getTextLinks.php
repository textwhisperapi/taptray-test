<?php
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/db_connect.php";

$surrogate = $_GET['surrogate'] ?? '';
$clientVersion = $_GET['v'] ?? '';

if (!$surrogate) {
    echo json_encode(["links" => [], "version" => ""]);
    exit;
}

$sql = "SELECT Text, UpdatedTime FROM text WHERE surrogate = ?";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("s", $surrogate);
$stmt->execute();

$result = $stmt->get_result();
$row = $result->fetch_assoc();
$text = $row['Text'] ?? "";
$serverVersion = $row['UpdatedTime'] ?? "";

$stmt->close();

//ALWAYS send version header
header("X-Text-Version: " . $serverVersion);

//If client version matches → return 304
if ($clientVersion !== "" && $clientVersion === $serverVersion) {
    http_response_code(304);
    exit;
}

//Extract links (only if needed)
$links = [];
if (!empty($text)) {
    preg_match_all('/https?:\/\/[^\s"<>()]+/i', $text, $matches);
    $links = $matches[0] ?? [];
}

//Response with version for caching
header("Content-Type: application/json; charset=utf-8");
echo json_encode([
    "links" => $links,
    "version" => $serverVersion
]);
