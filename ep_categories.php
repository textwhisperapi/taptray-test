<?php
header('Content-Type: application/json');
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db_connect.php';

sec_session_start();
$mysqli->set_charset("utf8mb4");

$memberId = $_SESSION['user_id'] ?? null;
if (!$memberId) {
    echo json_encode(["status" => "error", "message" => "Not logged in"]);
    exit;
}

$rawOwner = $_GET['owner'] ?? null;
$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data)) {
    $data = $_POST;
}
$rawOwner = $rawOwner ?? ($data['owner'] ?? null);

function ep_json($payload) {
    echo json_encode($payload);
    exit;
}

function ep_normalize_owner_token($tokenOrUser) {
    if (!$tokenOrUser) return $tokenOrUser;
    if (str_starts_with($tokenOrUser, 'invited-')) {
        return substr($tokenOrUser, strlen('invited-'));
    }
    return $tokenOrUser;
}

function ep_resolve_owner_id($mysqli, $tokenOrUser, $fallback) {
    $tokenOrUser = ep_normalize_owner_token($tokenOrUser);
    if (!$tokenOrUser) return $fallback;
    $ownerId = null;
    $stmt = $mysqli->prepare("SELECT owner_id FROM content_lists WHERE token = ? LIMIT 1");
    $stmt->bind_param("s", $tokenOrUser);
    $stmt->execute();
    $stmt->bind_result($ownerId);
    $stmt->fetch();
    $stmt->close();
    if ($ownerId) return (int)$ownerId;
    $stmt = $mysqli->prepare("SELECT id FROM members WHERE username = ? LIMIT 1");
    $stmt->bind_param("s", $tokenOrUser);
    $stmt->execute();
    $stmt->bind_result($ownerId);
    $stmt->fetch();
    $stmt->close();
    return $ownerId ? (int)$ownerId : $fallback;
}

function ep_sanitize_color($value) {
    $value = trim((string)$value);
    if ($value === '') return null;
    if (preg_match('/^#([0-9a-fA-F]{6}|[0-9a-fA-F]{3})$/', $value)) {
        return strtolower($value);
    }
    return null;
}

function ep_normalize_category_id($value) {
    $value = trim((string)$value);
    if ($value === '') return '';
    if (mb_strlen($value) > 80) {
        $value = mb_substr($value, 0, 80);
    }
    return $value;
}

function ep_category_key($value) {
    $value = trim((string)$value);
    if ($value === '') return '';
    return mb_strtolower($value, 'UTF-8');
}

function ep_default_categories_for_locale($locale) {
    $locale = strtolower(trim((string)$locale));
    if (str_starts_with($locale, 'is')) {
        return [
            ["category" => "Æfing", "description" => "", "color" => "#b9e5c6"],
            ["category" => "Tónleikar", "description" => "", "color" => "#f4c1c1"],
            ["category" => "Party", "description" => "", "color" => "#f5e8b8"],
            ["category" => "Fundur", "description" => "", "color" => "#bfd9f6"]
        ];
    }
    return [
        ["category" => "Rehearsal", "description" => "", "color" => "#b9e5c6"],
        ["category" => "Concert", "description" => "", "color" => "#f4c1c1"],
        ["category" => "Party", "description" => "", "color" => "#f5e8b8"],
        ["category" => "Meeting", "description" => "", "color" => "#bfd9f6"]
    ];
}

$ownerId = ep_resolve_owner_id($mysqli, $rawOwner, (int)$memberId);
$username = $_SESSION['username'] ?? '';
$listTokenForRole = $rawOwner ?: $username;
$roleRank = $username ? get_user_list_role_rank($mysqli, $listTokenForRole, $username) : 0;
if ($roleRank < 80 && $listTokenForRole && str_starts_with($listTokenForRole, 'invited-')) {
    $fallbackToken = substr($listTokenForRole, strlen('invited-'));
    if ($fallbackToken !== '') {
        $roleRank = max($roleRank, (int)get_user_list_role_rank($mysqli, $fallbackToken, $username));
    }
}
$canManage = ($ownerId === (int)$memberId) || ($roleRank >= 90);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $stmt = $mysqli->prepare("
        SELECT id, category, description, color, created_at, updated_at
        FROM ep_categories
        WHERE owner_id = ?
        ORDER BY category ASC
    ");
    $stmt->bind_param("i", $ownerId);
    $stmt->execute();
    $res = $stmt->get_result();
    $categories = [];
    while ($row = $res->fetch_assoc()) {
        $categories[] = $row;
    }
    $stmt->close();

    ep_json(["status" => "OK", "categories" => $categories, "can_manage" => $canManage ? 1 : 0]);
}

$action = $data['action'] ?? '';

if (!$canManage) {
    ep_json(["status" => "error", "message" => "Permission denied"]);
}

if ($action === 'create_default_categories') {
    $locale = (string)($data['locale'] ?? ($_SESSION['locale'] ?? 'en'));
    $defaults = ep_default_categories_for_locale($locale);
    if (!$defaults) {
        ep_json(["status" => "error", "message" => "No default categories configured."]);
    }

    $existingKeys = [];
    $stmt = $mysqli->prepare("SELECT category FROM ep_categories WHERE owner_id = ?");
    $stmt->bind_param("i", $ownerId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $key = ep_category_key($row['category'] ?? '');
        if ($key !== '') {
            $existingKeys[$key] = true;
        }
    }
    $stmt->close();

    $created = [];
    $skipped = [];
    $stmtInsert = $mysqli->prepare("
        INSERT INTO ep_categories (owner_id, category, description, color, created_at, updated_at)
        VALUES (?, ?, ?, ?, NOW(), NOW())
    ");
    foreach ($defaults as $entry) {
        $categoryId = ep_normalize_category_id($entry['category'] ?? '');
        $description = trim((string)($entry['description'] ?? ''));
        $color = ep_sanitize_color($entry['color'] ?? '');
        $key = ep_category_key($categoryId);
        if ($categoryId === '' || $key === '') continue;
        if (isset($existingKeys[$key])) {
            $skipped[] = $categoryId;
            continue;
        }
        $stmtInsert->bind_param("isss", $ownerId, $categoryId, $description, $color);
        $stmtInsert->execute();
        if ((int)$stmtInsert->insert_id > 0) {
            $created[] = $categoryId;
            $existingKeys[$key] = true;
        }
    }
    $stmtInsert->close();

    ep_json([
        "status" => "OK",
        "created_categories" => count($created),
        "created_category_names" => $created,
        "skipped_categories" => count($skipped),
        "skipped_category_names" => $skipped,
        "locale" => strtolower(trim($locale))
    ]);
}

if ($action === 'create') {
    $categoryId = ep_normalize_category_id($data['category'] ?? '');
    $description = trim((string)($data['description'] ?? ''));
    $color = ep_sanitize_color($data['color'] ?? '');
    if ($categoryId === '') {
        ep_json(["status" => "error", "message" => "Category id required"]);
    }

    $stmt = $mysqli->prepare("SELECT 1 FROM ep_categories WHERE owner_id = ? AND category = ? LIMIT 1");
    $stmt->bind_param("is", $ownerId, $categoryId);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    if ($exists) {
        ep_json(["status" => "error", "message" => "Category already exists"]);
    }

    $stmt = $mysqli->prepare("
        INSERT INTO ep_categories (owner_id, category, description, color, created_at, updated_at)
        VALUES (?, ?, ?, ?, NOW(), NOW())
    ");
    $stmt->bind_param("isss", $ownerId, $categoryId, $description, $color);
    $stmt->execute();
    $newId = $stmt->insert_id;
    $stmt->close();

    ep_json([
        "status" => "OK",
        "category" => [
            "id" => $newId,
            "category" => $categoryId,
            "description" => $description,
            "color" => $color
        ]
    ]);
}

if ($action === 'update') {
    $categoryId = ep_normalize_category_id($data['category'] ?? '');
    $newCategoryId = ep_normalize_category_id($data['new_category'] ?? $categoryId);
    $description = trim((string)($data['description'] ?? ''));
    $color = ep_sanitize_color($data['color'] ?? '');
    if ($categoryId === '' || $newCategoryId === '') {
        ep_json(["status" => "error", "message" => "Category id required"]);
    }

    if (strtolower($newCategoryId) !== strtolower($categoryId)) {
        $stmt = $mysqli->prepare("SELECT 1 FROM ep_categories WHERE owner_id = ? AND category = ? LIMIT 1");
        $stmt->bind_param("is", $ownerId, $newCategoryId);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();
        if ($exists) {
            ep_json(["status" => "error", "message" => "Category id already in use"]);
        }
    }

    $stmt = $mysqli->prepare("
        UPDATE ep_categories
        SET category = ?, description = ?, color = ?, updated_at = NOW()
        WHERE owner_id = ? AND category = ?
    ");
    $stmt->bind_param("sssis", $newCategoryId, $description, $color, $ownerId, $categoryId);
    $stmt->execute();
    $stmt->close();

    ep_json([
        "status" => "OK",
        "category" => [
            "category" => $newCategoryId,
            "description" => $description,
            "color" => $color
        ]
    ]);
}

if ($action === 'delete') {
    $categoryId = ep_normalize_category_id($data['category'] ?? '');
    if ($categoryId === '') {
        ep_json(["status" => "error", "message" => "Category id required"]);
    }
    $confirm = (int)($data['confirm'] ?? 0);
    if (!$confirm) {
        $stmt = $mysqli->prepare("
            SELECT COUNT(*) AS total
            FROM ep_events
            WHERE owner_id = ? AND category = ?
        ");
        $stmt->bind_param("is", $ownerId, $categoryId);
        $stmt->execute();
        $stmt->bind_result($total);
        $stmt->fetch();
        $stmt->close();
        if ((int)$total > 0) {
            ep_json([
                "status" => "warn",
                "message" => "Category is used by events",
                "count" => (int)$total
            ]);
        }
    }
    $stmt = $mysqli->prepare("DELETE FROM ep_categories WHERE owner_id = ? AND category = ?");
    $stmt->bind_param("is", $ownerId, $categoryId);
    $stmt->execute();
    $stmt->close();
    ep_json(["status" => "OK"]);
}

ep_json(["status" => "error", "message" => "Invalid action"]);
