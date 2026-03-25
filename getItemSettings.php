<?php
require_once __DIR__ . "/includes/db_connect.php";
require_once __DIR__ . "/includes/functions.php";

sec_session_start();
header("Content-Type: application/json; charset=utf-8");

function ensure_item_settings_columns(mysqli $mysqli): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $required = [
        "short_description" => "ALTER TABLE item_settings ADD COLUMN short_description TEXT NULL AFTER public_description",
        "detailed_description" => "ALTER TABLE item_settings ADD COLUMN detailed_description MEDIUMTEXT NULL AFTER short_description",
    ];

    $existing = [];
    if ($result = $mysqli->query("SHOW COLUMNS FROM item_settings")) {
        while ($row = $result->fetch_assoc()) {
            $existing[$row["Field"]] = true;
        }
        $result->close();
    }

    foreach ($required as $column => $sql) {
        if (!isset($existing[$column])) {
            @$mysqli->query($sql);
        }
    }
}

if (!login_check($mysqli) || empty($_SESSION["username"])) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Not logged in"]);
    exit;
}

$surrogate = intval($_GET["surrogate"] ?? 0);
if ($surrogate <= 0) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid surrogate"]);
    exit;
}

ensure_item_settings_columns($mysqli);

$stmt = $mysqli->prepare("
    SELECT surrogate, owner_username,
           COALESCE(NULLIF(TRIM(short_description), ''), NULLIF(TRIM(public_description), ''), '') AS short_description,
           COALESCE(NULLIF(TRIM(detailed_description), ''), '') AS detailed_description,
           COALESCE(NULLIF(TRIM(public_description), ''), '') AS public_description,
           price_label, image_url, allergens, is_available, updated_at
    FROM item_settings
    WHERE surrogate = ?
    LIMIT 1
");
$stmt->bind_param("i", $surrogate);
$stmt->execute();
$result = $stmt->get_result();
$row = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$row) {
    echo json_encode([
        "status" => "OK",
        "data" => [
            "surrogate" => $surrogate,
            "owner_username" => "",
            "short_description" => "",
            "detailed_description" => "",
            "public_description" => "",
            "price_label" => "",
            "image_url" => "",
            "allergens" => "",
            "is_available" => 1,
            "updated_at" => null
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode(["status" => "OK", "data" => $row], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
