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

$payload = json_decode(file_get_contents("php://input"), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid payload"]);
    exit;
}

$surrogate = intval($payload["surrogate"] ?? 0);
if ($surrogate <= 0) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid surrogate"]);
    exit;
}

$shortDescription = trim((string)($payload["short_description"] ?? $payload["public_description"] ?? ""));
$detailedDescription = trim((string)($payload["detailed_description"] ?? ""));
$publicDescription = $shortDescription;
$priceLabel = trim((string)($payload["price_label"] ?? ""));
$imageUrl = trim((string)($payload["image_url"] ?? ""));
$allergens = trim((string)($payload["allergens"] ?? ""));
$isAvailable = !empty($payload["is_available"]) ? 1 : 0;
$updatedBy = trim((string)$_SESSION["username"]);

if ($imageUrl !== "" && !filter_var($imageUrl, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid image URL"]);
    exit;
}

$ownerUsername = "";
$stmtOwner = $mysqli->prepare("SELECT Owner FROM text WHERE Surrogate = ? LIMIT 1");
$stmtOwner->bind_param("i", $surrogate);
$stmtOwner->execute();
$stmtOwner->bind_result($ownerUsername);
$stmtOwner->fetch();
$stmtOwner->close();

if ($ownerUsername === "") {
    http_response_code(404);
    echo json_encode(["status" => "error", "message" => "Item not found"]);
    exit;
}

ensure_item_settings_columns($mysqli);

$stmt = $mysqli->prepare("
    INSERT INTO item_settings
      (surrogate, owner_username, public_description, short_description, detailed_description, price_label, image_url, allergens, is_available, updated_by)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      owner_username = VALUES(owner_username),
      public_description = VALUES(public_description),
      short_description = VALUES(short_description),
      detailed_description = VALUES(detailed_description),
      price_label = VALUES(price_label),
      image_url = VALUES(image_url),
      allergens = VALUES(allergens),
      is_available = VALUES(is_available),
      updated_by = VALUES(updated_by)
");
$stmt->bind_param(
    "isssssssis",
    $surrogate,
    $ownerUsername,
    $publicDescription,
    $shortDescription,
    $detailedDescription,
    $priceLabel,
    $imageUrl,
    $allergens,
    $isAvailable,
    $updatedBy
);

$ok = $stmt->execute();
$stmt->close();

if (!$ok) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Failed to save settings"]);
    exit;
}

echo json_encode([
    "status" => "OK",
    "data" => [
        "surrogate" => $surrogate,
        "owner_username" => $ownerUsername,
        "short_description" => $shortDescription,
        "detailed_description" => $detailedDescription,
        "public_description" => $publicDescription,
        "price_label" => $priceLabel,
        "image_url" => $imageUrl,
        "allergens" => $allergens,
        "is_available" => $isAvailable,
        "updated_by" => $updatedBy
    ]
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
