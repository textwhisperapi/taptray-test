<?php
require_once __DIR__ . "/includes/db_connect.php";

$surrogate     = $_GET['q'] ?? '';
$clientVersion = $_GET['v'] ?? '';

if (!$surrogate) {
    header("X-Text-Version: ");
    echo "";
    exit;
}

$sql = "SELECT Text, Owner, CreatedUser, CreatedTime, UpdatedUser, UpdatedTime FROM text WHERE surrogate = ?";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("s", $surrogate);
$stmt->execute();

$res  = $stmt->get_result();
$row  = $res->fetch_assoc();
$stmt->close();

$text          = $row['Text'] ?? "";
$owner         = $row['Owner'] ?? "";
$createdUser   = $row['CreatedUser'] ?? "";
$createdTime   = $row['CreatedTime'] ?? "";
$updatedUser   = $row['UpdatedUser'] ?? "";
$serverVersion = $row['UpdatedTime'] ?? "";

// Always send version header
header("X-Text-Version: " . $serverVersion);
header("X-Text-Owner: " . $owner);
header("X-Text-Created-User: " . $createdUser);
header("X-Text-Created-Time: " . $createdTime);
header("X-Text-Updated-User: " . $updatedUser);
header("X-Text-Updated-Time: " . $serverVersion);

// If client already has this version → 304
if ($clientVersion !== "" && $clientVersion === $serverVersion) {
    http_response_code(304);
    exit;
}

// Output raw HTML (NOT JSON!)
header("Content-Type: text/html; charset=utf-8");
echo $text;
