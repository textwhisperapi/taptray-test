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

function ep_json($payload) {
    echo json_encode($payload);
    exit;
}

function ep_sanitize_color($value) {
    $value = trim((string)$value);
    if ($value === '') return null;
    if (preg_match('/^#([0-9a-fA-F]{6}|[0-9a-fA-F]{3})$/', $value)) {
        return strtolower($value);
    }
    return null;
}

function ep_parse_bool_flag($value) {
    if (is_bool($value)) return $value ? 1 : 0;
    $raw = strtolower(trim((string)$value));
    if ($raw === '') return 0;
    return in_array($raw, ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
}

function ep_role_group_flag_from_input($data) {
    return ep_parse_bool_flag($data['is_role_group'] ?? 0);
}

function ep_normalize_role_key($value) {
    $value = trim((string)$value);
    if ($value === '') return '';
    $value = mb_strtolower($value, 'UTF-8');
    $value = preg_replace('/\s+/u', ' ', $value);
    return trim((string)$value);
}

function ep_role_looks_like_choir($role) {
    $key = ep_normalize_role_key($role);
    if ($key === '') return false;
    // Match voice-part style role names (including common Icelandic spellings),
    // but do not treat generic choir labels like "Kór"/"Choir" as role groups.
    $normalized = preg_replace('/[._-]+/u', ' ', $key);
    $normalized = trim((string)$normalized);
    if ($normalized === '' || preg_match('/^(k[oó]r|choir)$/u', $normalized)) {
        return false;
    }
    return (bool)preg_match('/\b(sopr(?:an|ano|ana)?|alt(?:o)?|ten[oó]r|bass(?:i)?|bar[ií]t(?:on)?)\b/u', $normalized);
}

function ep_role_group_pastel_palette() {
    return [
        '#ef9a9a', '#ffb74d', '#ffd54f', '#aed581',
        '#81c784', '#4db6ac', '#64b5f6', '#7986cb',
        '#ba68c8', '#f48fb1', '#90a4ae', '#ff8a65'
    ];
}

function ep_next_pastel_color(&$usedColors, $seed = '') {
    $palette = ep_role_group_pastel_palette();
    $count = count($palette);
    if ($count === 0) return '#cfe8ff';
    $start = 0;
    if ($seed !== '') {
        $start = abs(crc32($seed)) % $count;
    }
    for ($i = 0; $i < $count; $i++) {
        $idx = ($start + $i) % $count;
        $color = strtolower($palette[$idx]);
        if (!isset($usedColors[$color])) {
            $usedColors[$color] = true;
            return $color;
        }
    }
    $fallback = strtolower($palette[$start % $count]);
    $usedColors[$fallback] = true;
    return $fallback;
}

function ep_get_existing_role_groups_by_key($mysqli, $ownerId) {
    $roleGroupsByKey = [];
    $stmt = $mysqli->prepare("
        SELECT id, name, color
        FROM ep_groups
        WHERE owner_id = ? AND is_all_members = 0 AND is_role_group = 1
        ORDER BY sort_order IS NULL, sort_order ASC, id ASC
    ");
    $stmt->bind_param("i", $ownerId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $gid = (int)($row['id'] ?? 0);
        $gname = trim((string)($row['name'] ?? ''));
        $gcolor = strtolower(trim((string)($row['color'] ?? '')));
        if ($gid <= 0 || $gname === '') continue;
        $key = ep_normalize_role_key($gname);
        if ($key === '') continue;
        if (!isset($roleGroupsByKey[$key])) {
            $roleGroupsByKey[$key] = ["id" => $gid, "name" => $gname, "color" => $gcolor];
        }
    }
    $stmt->close();
    return $roleGroupsByKey;
}

function ep_get_choir_role_candidates($mysqli, $ownerId, $existingRoleGroupsByKey) {
    $candidatesByKey = [];
    $stmt = $mysqli->prepare("
        SELECT gm.member_id, gm.role
        FROM ep_group_members gm
        JOIN ep_groups g ON g.id = gm.group_id
        WHERE g.owner_id = ?
          AND g.is_all_members = 1
          AND gm.role IS NOT NULL
          AND TRIM(gm.role) <> ''
        ORDER BY gm.member_id ASC
    ");
    $stmt->bind_param("i", $ownerId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $memberIdRaw = (int)($row['member_id'] ?? 0);
        $roleRaw = trim((string)($row['role'] ?? ''));
        if ($memberIdRaw <= 0 || $roleRaw === '' || !ep_role_looks_like_choir($roleRaw)) continue;
        $key = ep_normalize_role_key($roleRaw);
        if ($key === '') continue;
        if (!isset($candidatesByKey[$key])) {
            $existing = $existingRoleGroupsByKey[$key] ?? null;
            $candidatesByKey[$key] = [
                "key" => $key,
                "name" => $existing['name'] ?? $roleRaw,
                "count" => 0,
                "existing_group_id" => $existing['id'] ?? 0
            ];
        }
        $candidatesByKey[$key]["count"] += 1;
    }
    $stmt->close();

    $candidates = array_values($candidatesByKey);
    usort($candidates, function ($a, $b) {
        $countCmp = (int)$b['count'] <=> (int)$a['count'];
        if ($countCmp !== 0) return $countCmp;
        return strcasecmp((string)$a['name'], (string)$b['name']);
    });
    return $candidates;
}

function ep_default_choir_roles_by_type($type) {
    $key = strtolower(trim((string)$type));
    if ($key === 'men' || $key === 'male' || $key === 'mans') {
        return ['1.Tenór', '2.Tenór', 'Baritón', 'Bassi'];
    }
    if ($key === 'women' || $key === 'female' || $key === 'womans') {
        return ['1.Sopran', '2.Sopran', '1.Alt', '2.Alt'];
    }
    if ($key === 'mixed' || $key === 'satb') {
        return ['Sopran', 'Alt', 'Tenór', 'Bassi'];
    }
    return [];
}

function ep_default_meetings_group_name_by_locale($locale) {
    $locale = strtolower(trim((string)$locale));
    if (str_starts_with($locale, 'is')) {
        return 'Fundir';
    }
    return 'Meatings';
}

function ep_generate_list_token($length = 12) {
    return bin2hex(random_bytes((int)($length / 2)));
}

function ep_default_lists_by_locale($locale) {
    $locale = strtolower(trim((string)$locale));
    if (str_starts_with($locale, 'is')) {
        return [
            'Næsta gigg',
            'Vortónleikar',
            'Lokið',
            'Lagasafn'
        ];
    }
    return [
        'Next gig',
        'Spring concert',
        'Completed',
        'Song library'
    ];
}

function ep_owner_group_type($mysqli, $ownerId) {
    $colRes = $mysqli->query("SHOW COLUMNS FROM members LIKE 'group_type'");
    $hasGroupType = $colRes && $colRes->num_rows > 0;
    if ($colRes) $colRes->free();
    if (!$hasGroupType) return '';

    $stmt = $mysqli->prepare("SELECT COALESCE(group_type, '') FROM members WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $ownerId);
    $stmt->execute();
    $stmt->bind_result($groupType);
    $stmt->fetch();
    $stmt->close();
    $groupType = strtolower(trim((string)$groupType));
    return in_array($groupType, ['mixed', 'men', 'women'], true) ? $groupType : '';
}

function ep_owner_profile_type($mysqli, $ownerId) {
    $colRes = $mysqli->query("SHOW COLUMNS FROM members LIKE 'profile_type'");
    $hasProfileType = $colRes && $colRes->num_rows > 0;
    if ($colRes) $colRes->free();
    if (!$hasProfileType) return 'person';

    $stmt = $mysqli->prepare("SELECT COALESCE(profile_type, 'person') FROM members WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $ownerId);
    $stmt->execute();
    $stmt->bind_result($profileType);
    $stmt->fetch();
    $stmt->close();
    $profileType = strtolower(trim((string)$profileType));
    return in_array($profileType, ['person', 'group'], true) ? $profileType : 'person';
}

function ep_normalize_locale_code($locale) {
    $locale = strtolower(trim((string)$locale));
    if (!preg_match('/^[a-z]{2}$/', $locale)) {
        $locale = 'en';
    }
    $langFile = __DIR__ . "/lang/{$locale}.php";
    if (!file_exists($langFile)) {
        $locale = 'en';
    }
    return $locale;
}

function ep_owner_locale($mysqli, $ownerId) {
    $colRes = $mysqli->query("SHOW COLUMNS FROM members LIKE 'locale'");
    $hasLocale = $colRes && $colRes->num_rows > 0;
    if ($colRes) $colRes->free();
    if (!$hasLocale) return 'en';

    $stmt = $mysqli->prepare("SELECT COALESCE(locale, 'en') FROM members WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $ownerId);
    $stmt->execute();
    $stmt->bind_result($locale);
    $stmt->fetch();
    $stmt->close();
    return ep_normalize_locale_code($locale);
}

function ep_set_owner_group_type($mysqli, $ownerId, $groupType) {
    $groupType = strtolower(trim((string)$groupType));
    if (!in_array($groupType, ['mixed', 'men', 'women'], true)) return false;
    $colRes = $mysqli->query("SHOW COLUMNS FROM members LIKE 'group_type'");
    $hasGroupType = $colRes && $colRes->num_rows > 0;
    if ($colRes) $colRes->free();
    if (!$hasGroupType) return false;
    $stmt = $mysqli->prepare("UPDATE members SET group_type = ? WHERE id = ?");
    $stmt->bind_param("si", $groupType, $ownerId);
    $ok = $stmt->execute();
    $stmt->close();
    return (bool)$ok;
}

function ep_ensure_all_members_group($mysqli, $ownerId, $memberId) {
    $stmt = $mysqli->prepare("
        SELECT id FROM ep_groups
        WHERE owner_id = ? AND is_all_members = 1
        LIMIT 1
    ");
    $stmt->bind_param("i", $ownerId);
    $stmt->execute();
    $stmt->bind_result($groupId);
    $stmt->fetch();
    $stmt->close();
    if ($groupId) {
        $stmt = $mysqli->prepare("
            UPDATE ep_groups
            SET sort_order = 0, is_role_group = 0
            WHERE id = ? AND (sort_order IS NULL OR sort_order <> 0 OR is_role_group <> 0)
        ");
        $stmt->bind_param("i", $groupId);
        $stmt->execute();
        $stmt->close();
        return;
    }

    $stmt = $mysqli->prepare("
        SELECT id FROM ep_groups
        WHERE owner_id = ? AND name = 'All Members'
        LIMIT 1
    ");
    $stmt->bind_param("i", $ownerId);
    $stmt->execute();
    $stmt->bind_result($legacyId);
    $stmt->fetch();
    $stmt->close();
    if ($legacyId) {
        $stmt = $mysqli->prepare("UPDATE ep_groups SET is_all_members = 1, is_role_group = 0 WHERE id = ?");
        $stmt->bind_param("i", $legacyId);
        $stmt->execute();
        $stmt->close();
        $stmt = $mysqli->prepare("
            UPDATE ep_groups
            SET sort_order = 0, is_role_group = 0
            WHERE id = ? AND (sort_order IS NULL OR sort_order <> 0 OR is_role_group <> 0)
        ");
        $stmt->bind_param("i", $legacyId);
        $stmt->execute();
        $stmt->close();
        return;
    }

    $stmt = $mysqli->prepare("
        INSERT INTO ep_groups (name, description, owner_id, created_by_member_id, is_all_members, is_role_group, sort_order)
        VALUES ('All Members', 'Auto-managed members list', ?, ?, 1, 0, 0)
    ");
    $stmt->bind_param("ii", $ownerId, $memberId);
    $stmt->execute();
    $stmt->close();
}

function ep_next_group_sort_order($mysqli, $ownerId) {
    $stmt = $mysqli->prepare("
        SELECT COALESCE(MAX(sort_order), 0) AS max_sort
        FROM ep_groups
        WHERE owner_id = ? AND is_all_members = 0
    ");
    $stmt->bind_param("i", $ownerId);
    $stmt->execute();
    $stmt->bind_result($maxSort);
    $stmt->fetch();
    $stmt->close();
    return ((int)$maxSort) + 1;
}

if ($method === 'GET') {
    if ($canManage) {
        ep_ensure_all_members_group($mysqli, $ownerId, $memberId);
    }
    $ownerGroupType = ep_owner_group_type($mysqli, $ownerId);
    $ownerProfileType = ep_owner_profile_type($mysqli, $ownerId);
    $ownerLocale = ep_owner_locale($mysqli, $ownerId);
    $groupId = isset($_GET['group_id']) ? (int)$_GET['group_id'] : null;

    if ($groupId) {
        $stmt = $mysqli->prepare("
            SELECT id, name, description, color, owner_id, created_by_member_id, created_at, is_all_members,
                   CASE WHEN is_all_members = 1 THEN 0 ELSE is_role_group END AS is_role_group,
                   sort_order
            FROM ep_groups
            WHERE id = ? AND owner_id = ?
        ");
        $stmt->bind_param("ii", $groupId, $ownerId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();

        ep_json([
            "status" => "OK",
            "group" => $row,
            "owner_group_type" => $ownerGroupType,
            "owner_profile_type" => $ownerProfileType,
            "owner_locale" => $ownerLocale
        ]);
    }

    $stmt = $mysqli->prepare("
        SELECT id, name, description, color, owner_id, created_by_member_id, created_at, is_all_members,
               CASE WHEN is_all_members = 1 THEN 0 ELSE is_role_group END AS is_role_group,
               sort_order
        FROM ep_groups
        WHERE owner_id = ?
        ORDER BY is_all_members DESC, sort_order IS NULL, sort_order ASC, name ASC
    ");
    $stmt->bind_param("i", $ownerId);
    $stmt->execute();
    $res = $stmt->get_result();
    $groups = [];
    while ($row = $res->fetch_assoc()) {
        $groups[] = $row;
    }
    $stmt->close();

    ep_json([
        "status" => "OK",
        "groups" => $groups,
        "owner_group_type" => $ownerGroupType,
        "owner_profile_type" => $ownerProfileType,
        "owner_locale" => $ownerLocale
    ]);
}

$action = $data['action'] ?? '';

if (!$canManage) {
    ep_json(["status" => "error", "message" => "Permission denied"]);
}

if ($action === 'update_owner_profile') {
    $profileType = strtolower(trim((string)($data['profile_type'] ?? 'person')));
    $groupType = strtolower(trim((string)($data['group_type'] ?? '')));
    $localeInput = $data['locale'] ?? null;
    if (!in_array($profileType, ['person', 'group'], true)) {
        $profileType = 'person';
    }
    if (!in_array($groupType, ['', 'mixed', 'men', 'women'], true)) {
        $groupType = '';
    }
    if ($profileType !== 'group') {
        $groupType = '';
    }
    $currentLocale = ep_owner_locale($mysqli, $ownerId);
    $locale = $localeInput === null ? $currentLocale : ep_normalize_locale_code($localeInput);

    $profileColRes = $mysqli->query("SHOW COLUMNS FROM members LIKE 'profile_type'");
    $hasProfileType = $profileColRes && $profileColRes->num_rows > 0;
    if ($profileColRes) $profileColRes->free();
    $groupColRes = $mysqli->query("SHOW COLUMNS FROM members LIKE 'group_type'");
    $hasGroupType = $groupColRes && $groupColRes->num_rows > 0;
    if ($groupColRes) $groupColRes->free();
    $localeColRes = $mysqli->query("SHOW COLUMNS FROM members LIKE 'locale'");
    $hasLocale = $localeColRes && $localeColRes->num_rows > 0;
    if ($localeColRes) $localeColRes->free();

    if ($hasProfileType && $hasGroupType) {
        $groupTypeDb = $groupType === '' ? null : $groupType;
        $stmt = $mysqli->prepare("UPDATE members SET profile_type = ?, group_type = ? WHERE id = ?");
        $stmt->bind_param("ssi", $profileType, $groupTypeDb, $ownerId);
        $stmt->execute();
        $stmt->close();
    } elseif ($hasProfileType) {
        $stmt = $mysqli->prepare("UPDATE members SET profile_type = ? WHERE id = ?");
        $stmt->bind_param("si", $profileType, $ownerId);
        $stmt->execute();
        $stmt->close();
    } elseif ($hasGroupType) {
        $groupTypeDb = $groupType === '' ? null : $groupType;
        $stmt = $mysqli->prepare("UPDATE members SET group_type = ? WHERE id = ?");
        $stmt->bind_param("si", $groupTypeDb, $ownerId);
        $stmt->execute();
        $stmt->close();
    }
    if ($hasLocale) {
        $stmt = $mysqli->prepare("UPDATE members SET locale = ? WHERE id = ?");
        $stmt->bind_param("si", $locale, $ownerId);
        $stmt->execute();
        $stmt->close();
    }

    ep_json([
        "status" => "OK",
        "owner_profile_type" => $profileType,
        "owner_group_type" => $groupType,
        "owner_locale" => $locale
    ]);
}

if ($action === 'create') {
    $name = trim($data['name'] ?? '');
    $description = trim($data['description'] ?? '');
    $color = ep_sanitize_color($data['color'] ?? '');
    $isRoleGroup = ep_role_group_flag_from_input($data);
    $sortOrder = ep_next_group_sort_order($mysqli, $ownerId);
    if ($name === '') {
        ep_json(["status" => "error", "message" => "Name required"]);
    }

    $stmt = $mysqli->prepare("
        INSERT INTO ep_groups (name, description, color, owner_id, created_by_member_id, is_role_group, sort_order)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("sssiiii", $name, $description, $color, $ownerId, $memberId, $isRoleGroup, $sortOrder);
    $stmt->execute();
    $newId = $stmt->insert_id;
    $stmt->close();

    ep_json(["status" => "OK", "group_id" => $newId]);
}

if ($action === 'preview_choir_roles_to_groups') {
    ep_ensure_all_members_group($mysqli, $ownerId, $memberId);
    $existingRoleGroupsByKey = ep_get_existing_role_groups_by_key($mysqli, $ownerId);
    $candidates = ep_get_choir_role_candidates($mysqli, $ownerId, $existingRoleGroupsByKey);
    ep_json([
        "status" => "OK",
        "roles" => $candidates
    ]);
}

if ($action === 'create_default_choir_groups') {
    $choirType = strtolower(trim((string)($data['choir_type'] ?? ($data['group_type'] ?? ''))));
    if ($choirType === '') {
        $choirType = ep_owner_group_type($mysqli, $ownerId);
    }
    if ($choirType === '') {
        $choirType = 'mixed';
    }
    $roles = ep_default_choir_roles_by_type($choirType);
    if (!$roles) {
        ep_json(["status" => "error", "message" => "Unsupported choir type."]);
    }
    ep_set_owner_group_type($mysqli, $ownerId, $choirType);
    $locale = strtolower(trim((string)($data['locale'] ?? ($_SESSION['locale'] ?? 'en'))));
    $meetingsGroupName = ep_default_meetings_group_name_by_locale($locale);

    $existingNames = [];
    $usedColors = [];
    $stmt = $mysqli->prepare("
        SELECT name, color
        FROM ep_groups
        WHERE owner_id = ?
    ");
    $stmt->bind_param("i", $ownerId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $nameKey = ep_normalize_role_key($row['name'] ?? '');
        if ($nameKey !== '') $existingNames[$nameKey] = true;
        $color = strtolower(trim((string)($row['color'] ?? '')));
        if (preg_match('/^#[0-9a-f]{3,6}$/', $color)) {
            $usedColors[$color] = true;
        }
    }
    $stmt->close();

    $sortOrder = ep_next_group_sort_order($mysqli, $ownerId);
    $created = [];
    $skipped = [];
    $stmtCreate = $mysqli->prepare("
        INSERT INTO ep_groups (name, description, color, owner_id, created_by_member_id, is_role_group, sort_order)
        VALUES (?, '', ?, ?, ?, 1, ?)
    ");
    foreach ($roles as $roleName) {
        $roleName = trim((string)$roleName);
        $key = ep_normalize_role_key($roleName);
        if ($key === '') continue;
        if (isset($existingNames[$key])) {
            $skipped[] = $roleName;
            continue;
        }
        $color = ep_next_pastel_color($usedColors, $key);
        $stmtCreate->bind_param("ssiii", $roleName, $color, $ownerId, $memberId, $sortOrder);
        $stmtCreate->execute();
        if ((int)$stmtCreate->insert_id > 0) {
            $created[] = $roleName;
            $existingNames[$key] = true;
            $sortOrder += 1;
        }
    }
    $stmtCreate->close();

    $meetingsAliases = ['fundir', 'meatings', 'meetings'];
    $hasMeetingsGroup = false;
    foreach ($meetingsAliases as $alias) {
        if (isset($existingNames[$alias])) {
            $hasMeetingsGroup = true;
            break;
        }
    }
    if ($meetingsGroupName !== '') {
        if ($hasMeetingsGroup) {
            $skipped[] = $meetingsGroupName;
        } else {
            $meetingsColor = '#1f3b73';
            $stmtMeetings = $mysqli->prepare("
                INSERT INTO ep_groups (name, description, color, owner_id, created_by_member_id, is_role_group, sort_order)
                VALUES (?, '', ?, ?, ?, 0, ?)
            ");
            $stmtMeetings->bind_param("ssiii", $meetingsGroupName, $meetingsColor, $ownerId, $memberId, $sortOrder);
            $stmtMeetings->execute();
            if ((int)$stmtMeetings->insert_id > 0) {
                $created[] = $meetingsGroupName;
            } else {
                $skipped[] = $meetingsGroupName;
            }
            $stmtMeetings->close();
        }
    }

    ep_json([
        "status" => "OK",
        "created_groups" => count($created),
        "created_group_names" => $created,
        "skipped_groups" => count($skipped),
        "skipped_group_names" => $skipped,
        "group_type" => $choirType
    ]);
}

if ($action === 'create_default_lists') {
    $locale = strtolower(trim((string)($data['locale'] ?? ($_SESSION['locale'] ?? 'en'))));
    $defaultNames = ep_default_lists_by_locale($locale);
    if (!$defaultNames) {
        ep_json(["status" => "error", "message" => "No default lists configured."]);
    }
    $libraryName = (string)end($defaultNames);
    $libraryKey = mb_strtolower(trim($libraryName), 'UTF-8');

    $stmt = $mysqli->prepare("SELECT username FROM members WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $ownerId);
    $stmt->execute();
    $stmt->bind_result($ownerUsername);
    $stmt->fetch();
    $stmt->close();
    $ownerUsername = trim((string)$ownerUsername);
    if ($ownerUsername === '') {
        ep_json(["status" => "error", "message" => "Owner username not found."]);
    }

    $existingByKey = [];
    $existingByToken = [];
    $stmt = $mysqli->prepare("
        SELECT id, token, name
        FROM content_lists
        WHERE owner_id = ?
    ");
    $stmt->bind_param("i", $ownerId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $id = (int)($row['id'] ?? 0);
        $token = trim((string)($row['token'] ?? ''));
        $name = trim((string)($row['name'] ?? ''));
        if ($id <= 0 || $name === '') continue;
        $nameKey = mb_strtolower($name, 'UTF-8');
        $existingByKey[$nameKey] = ['id' => $id, 'token' => $token, 'name' => $name];
        if ($token !== '') {
            $existingByToken[$token] = ['id' => $id, 'name' => $name];
        }
    }
    $stmt->close();

    $created = [];
    $skipped = [];
    $libraryListId = 0;
    $orderedDefaultIds = [];
    foreach ($defaultNames as $listName) {
        $listName = trim((string)$listName);
        if ($listName === '') continue;
        $key = mb_strtolower($listName, 'UTF-8');
        if (isset($existingByKey[$key])) {
            $skipped[] = $listName;
            $orderedDefaultIds[] = (int)$existingByKey[$key]['id'];
            if ($libraryKey !== '' && $key === $libraryKey) {
                $libraryListId = (int)$existingByKey[$key]['id'];
            }
            continue;
        }
        $token = ep_generate_list_token();
        $stmtInsert = $mysqli->prepare("
            INSERT INTO content_lists (name, token, owner_id, created_by_id, access_level)
            VALUES (?, ?, ?, ?, 'private')
        ");
        $stmtInsert->bind_param("ssii", $listName, $token, $ownerId, $memberId);
        $stmtInsert->execute();
        $newId = (int)$stmtInsert->insert_id;
        $stmtInsert->close();
        if ($newId > 0) {
            $created[] = $listName;
            $existingByKey[$key] = ['id' => $newId, 'token' => $token, 'name' => $listName];
            $existingByToken[$token] = ['id' => $newId, 'name' => $listName];
            $orderedDefaultIds[] = $newId;
            if ($libraryKey !== '' && $key === $libraryKey) {
                $libraryListId = $newId;
            }
        } else {
            $skipped[] = $listName;
        }
    }

    if ($libraryListId <= 0 && $libraryKey !== '' && isset($existingByKey[$libraryKey])) {
        $libraryListId = (int)$existingByKey[$libraryKey]['id'];
    }

    $hasOrderIndex = false;
    $orderColRes = $mysqli->query("SHOW COLUMNS FROM content_lists LIKE 'order_index'");
    if ($orderColRes && $orderColRes->num_rows > 0) {
        $hasOrderIndex = true;
    }
    if ($orderColRes) {
        $orderColRes->free();
    }
    if ($hasOrderIndex && $orderedDefaultIds) {
        $stmtOrder = $mysqli->prepare("
            UPDATE content_lists
            SET order_index = ?, parent_id = NULL
            WHERE id = ? AND owner_id = ?
        ");
        $nextIndex = 1;
        foreach ($orderedDefaultIds as $listId) {
            $listId = (int)$listId;
            if ($listId <= 0) continue;
            $stmtOrder->bind_param("iii", $nextIndex, $listId, $ownerId);
            $stmtOrder->execute();
            $nextIndex += 1;
        }
        $stmtOrder->close();
    }

    $allContentList = $existingByToken[$ownerUsername] ?? null;
    $allContentNested = false;
    if ($libraryListId > 0 && $allContentList && (int)$allContentList['id'] > 0) {
        $allContentId = (int)$allContentList['id'];
        if ($allContentId !== $libraryListId) {
            $stmtNest = $mysqli->prepare("
                UPDATE content_lists
                SET parent_id = ?
                WHERE id = ? AND owner_id = ?
            ");
            $stmtNest->bind_param("iii", $libraryListId, $allContentId, $ownerId);
            $stmtNest->execute();
            $allContentNested = $stmtNest->affected_rows >= 0;
            $stmtNest->close();
        }
    }

    ep_json([
        "status" => "OK",
        "created_lists" => count($created),
        "created_list_names" => $created,
        "skipped_lists" => count($skipped),
        "skipped_list_names" => $skipped,
        "nested_all_content" => $allContentNested ? 1 : 0,
        "locale" => $locale
    ]);
}

if ($action === 'convert_choir_roles_to_groups') {
    ep_ensure_all_members_group($mysqli, $ownerId, $memberId);

    $roleGroupsByKey = ep_get_existing_role_groups_by_key($mysqli, $ownerId);
    $candidates = ep_get_choir_role_candidates($mysqli, $ownerId, $roleGroupsByKey);
    if (!$candidates) {
        ep_json([
            "status" => "OK",
            "created_groups" => 0,
            "updated_members" => 0,
            "message" => "No choir-like roles found."
        ]);
    }

    $selectedKeys = [];
    $requestedRoles = $data['roles'] ?? [];
    if (is_array($requestedRoles) && $requestedRoles) {
        foreach ($requestedRoles as $rawRole) {
            $key = ep_normalize_role_key($rawRole);
            if ($key !== '') $selectedKeys[$key] = true;
        }
    }
    if (!$selectedKeys) {
        foreach ($candidates as $entry) {
            $key = ep_normalize_role_key($entry['name'] ?? '');
            if ($key !== '') $selectedKeys[$key] = true;
        }
    }

    $memberRoles = [];
    $stmt = $mysqli->prepare("
        SELECT gm.member_id, gm.role
        FROM ep_group_members gm
        JOIN ep_groups g ON g.id = gm.group_id
        WHERE g.owner_id = ?
          AND g.is_all_members = 1
          AND gm.role IS NOT NULL
          AND TRIM(gm.role) <> ''
        ORDER BY gm.member_id ASC
    ");
    $stmt->bind_param("i", $ownerId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $memberIdRaw = (int)($row['member_id'] ?? 0);
        $roleRaw = trim((string)($row['role'] ?? ''));
        $key = ep_normalize_role_key($roleRaw);
        if ($memberIdRaw <= 0 || $roleRaw === '' || $key === '' || !isset($selectedKeys[$key])) continue;
        $memberRoles[] = ["member_id" => $memberIdRaw, "key" => $key, "role" => $roleRaw];
    }
    $stmt->close();

    if (!$memberRoles) {
        ep_json([
            "status" => "OK",
            "created_groups" => 0,
            "updated_members" => 0,
            "message" => "No selected choir roles found."
        ]);
    }

    $sortOrder = ep_next_group_sort_order($mysqli, $ownerId);
    $usedColors = [];
    foreach ($roleGroupsByKey as $entry) {
        $existingColor = strtolower(trim((string)($entry['color'] ?? '')));
        if (preg_match('/^#[0-9a-f]{3,6}$/', $existingColor)) {
            $usedColors[$existingColor] = true;
        }
    }
    $createdGroupNames = [];
    $stmtCreate = $mysqli->prepare("
        INSERT INTO ep_groups (name, description, color, owner_id, created_by_member_id, is_role_group, sort_order)
        VALUES (?, '', ?, ?, ?, 1, ?)
    ");
    foreach ($memberRoles as $entry) {
        $roleKey = $entry['key'];
        $roleName = $entry['role'];
        if (isset($roleGroupsByKey[$roleKey])) continue;
        $defaultColor = ep_next_pastel_color($usedColors, $roleKey);
        $stmtCreate->bind_param("ssiii", $roleName, $defaultColor, $ownerId, $memberId, $sortOrder);
        $stmtCreate->execute();
        $newId = (int)$stmtCreate->insert_id;
        if ($newId > 0) {
            $roleGroupsByKey[$roleKey] = ["id" => $newId, "name" => $roleName, "color" => $defaultColor];
            $createdGroupNames[] = $roleName;
            $sortOrder += 1;
        }
    }
    $stmtCreate->close();

    $stmtDeleteRoleGroups = $mysqli->prepare("
        DELETE gm
        FROM ep_group_members gm
        JOIN ep_groups g ON g.id = gm.group_id
        WHERE gm.member_id = ?
          AND g.owner_id = ?
          AND g.is_role_group = 1
          AND gm.group_id <> ?
    ");
    $stmtEnsureTargetMembership = $mysqli->prepare("
        INSERT INTO ep_group_members (group_id, member_id, role)
        SELECT ?, ?, ?
        FROM DUAL
        WHERE NOT EXISTS (
            SELECT 1 FROM ep_group_members
            WHERE group_id = ? AND member_id = ?
        )
    ");
    $stmtUpdateRoleEverywhere = $mysqli->prepare("
        UPDATE ep_group_members gm
        JOIN ep_groups g ON g.id = gm.group_id
        SET gm.role = ?
        WHERE gm.member_id = ? AND g.owner_id = ?
    ");

    $updatedMembers = 0;
    $processedMembers = [];
    foreach ($memberRoles as $entry) {
        $mid = (int)$entry['member_id'];
        if ($mid <= 0 || isset($processedMembers[$mid])) continue;
        $processedMembers[$mid] = true;
        $roleKey = $entry['key'];
        if (!isset($roleGroupsByKey[$roleKey])) continue;
        $targetGroupId = (int)$roleGroupsByKey[$roleKey]['id'];
        $targetRoleName = (string)$roleGroupsByKey[$roleKey]['name'];
        if ($targetGroupId <= 0 || $targetRoleName === '') continue;

        $stmtDeleteRoleGroups->bind_param("iii", $mid, $ownerId, $targetGroupId);
        $stmtDeleteRoleGroups->execute();

        $stmtEnsureTargetMembership->bind_param("iisii", $targetGroupId, $mid, $targetRoleName, $targetGroupId, $mid);
        $stmtEnsureTargetMembership->execute();

        $stmtUpdateRoleEverywhere->bind_param("sii", $targetRoleName, $mid, $ownerId);
        $stmtUpdateRoleEverywhere->execute();
        $updatedMembers += 1;
    }

    $stmtDeleteRoleGroups->close();
    $stmtEnsureTargetMembership->close();
    $stmtUpdateRoleEverywhere->close();

    ep_json([
        "status" => "OK",
        "created_groups" => count($createdGroupNames),
        "created_group_names" => $createdGroupNames,
        "updated_members" => $updatedMembers,
        "message" => "Choir roles converted to role groups."
    ]);
}

if ($action === 'update') {
    $groupId = (int)($data['group_id'] ?? 0);
    $name = trim($data['name'] ?? '');
    $description = trim($data['description'] ?? '');
    $color = ep_sanitize_color($data['color'] ?? '');
    $hasRoleGroup = array_key_exists('is_role_group', $data);
    $isRoleGroup = ep_role_group_flag_from_input($data);
    if ($groupId <= 0 || $name === '') {
        ep_json(["status" => "error", "message" => "Invalid input"]);
    }

    $stmt = $mysqli->prepare("SELECT is_all_members, is_role_group FROM ep_groups WHERE id = ? AND owner_id = ?");
    $stmt->bind_param("ii", $groupId, $ownerId);
    $stmt->execute();
    $stmt->bind_result($isAllMembers, $currentRoleGroup);
    $stmt->fetch();
    $stmt->close();
    if (!$hasRoleGroup) {
        $isRoleGroup = (int)$currentRoleGroup;
    }

    if ((int)$isAllMembers === 1 && $name !== 'All Members') {
        ep_json(["status" => "error", "message" => "All Members name is protected."]);
    }
    if ((int)$isAllMembers === 1) {
        $isRoleGroup = 0;
    }

    $stmt = $mysqli->prepare("
        UPDATE ep_groups
        SET name = ?, description = ?, color = ?, is_role_group = ?
        WHERE id = ? AND owner_id = ?
    ");
    $stmt->bind_param("sssiii", $name, $description, $color, $isRoleGroup, $groupId, $ownerId);
    $stmt->execute();
    $stmt->close();

    ep_json(["status" => "OK"]);
}

if ($action === 'delete') {
    $groupId = (int)($data['group_id'] ?? 0);
    if ($groupId <= 0) {
        ep_json(["status" => "error", "message" => "Invalid group_id"]);
    }

    $stmt = $mysqli->prepare("SELECT is_all_members FROM ep_groups WHERE id = ? AND owner_id = ?");
    $stmt->bind_param("ii", $groupId, $ownerId);
    $stmt->execute();
    $stmt->bind_result($isAllMembers);
    $stmt->fetch();
    $stmt->close();

    if ((int)$isAllMembers === 1) {
        ep_json(["status" => "error", "message" => "All Members group cannot be deleted."]);
    }

    $stmt = $mysqli->prepare("
        DELETE gm
        FROM ep_group_members gm
        JOIN ep_groups g ON g.id = gm.group_id
        WHERE gm.group_id = ? AND g.owner_id = ?
    ");
    $stmt->bind_param("ii", $groupId, $ownerId);
    $stmt->execute();
    $stmt->close();

    $stmt = $mysqli->prepare("
        DELETE FROM ep_event_groups
        WHERE group_id = ?
    ");
    $stmt->bind_param("i", $groupId);
    $stmt->execute();
    $stmt->close();

    $stmt = $mysqli->prepare("
        DELETE FROM ep_groups
        WHERE id = ? AND owner_id = ?
    ");
    $stmt->bind_param("ii", $groupId, $ownerId);
    $stmt->execute();
    $stmt->close();

    ep_json(["status" => "OK"]);
}

if ($action === 'reorder') {
    $groupIds = $data['group_ids'] ?? [];
    if (!is_array($groupIds)) {
        ep_json(["status" => "error", "message" => "group_ids must be array"]);
    }
    $groupIds = array_values(array_filter(array_map('intval', $groupIds)));
    if (!$groupIds) {
        ep_json(["status" => "OK"]);
    }

    $stmt = $mysqli->prepare("
        SELECT id, is_all_members
        FROM ep_groups
        WHERE owner_id = ?
    ");
    $stmt->bind_param("i", $ownerId);
    $stmt->execute();
    $res = $stmt->get_result();
    $allowed = [];
    while ($row = $res->fetch_assoc()) {
        $allowed[(int)$row['id']] = (int)$row['is_all_members'];
    }
    $stmt->close();

    $order = 1;
    foreach ($groupIds as $groupId) {
        if (!isset($allowed[$groupId]) || $allowed[$groupId] === 1) {
            continue;
        }
        $stmt = $mysqli->prepare("UPDATE ep_groups SET sort_order = ? WHERE id = ? AND owner_id = ?");
        $stmt->bind_param("iii", $order, $groupId, $ownerId);
        $stmt->execute();
        $stmt->close();
        $order += 1;
    }

    $stmt = $mysqli->prepare("UPDATE ep_groups SET sort_order = 0 WHERE owner_id = ? AND is_all_members = 1");
    $stmt->bind_param("i", $ownerId);
    $stmt->execute();
    $stmt->close();

    ep_json(["status" => "OK"]);
}

ep_json(["status" => "error", "message" => "Unsupported action"]);
