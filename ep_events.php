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

function ep_json($payload) {
    echo json_encode($payload);
    exit;
}

function ep_events_has_column(mysqli $mysqli, string $columnName): bool {
    $safeName = preg_replace('/[^a-zA-Z0-9_]/', '', $columnName);
    if ($safeName === '') return false;
    $res = $mysqli->query("SHOW COLUMNS FROM ep_events LIKE '{$safeName}'");
    if (!$res) {
        return false;
    }
    $has = $res->num_rows > 0;
    $res->close();
    return $has;
}

function ep_events_ensure_image_columns(mysqli $mysqli): array {
    $hasImageUrl = ep_events_has_column($mysqli, 'image_url');
    $hasImageId = ep_events_has_column($mysqli, 'image_id');

    if (!$hasImageUrl) {
        @$mysqli->query("ALTER TABLE ep_events ADD COLUMN image_url VARCHAR(1024) NULL AFTER notes");
        $hasImageUrl = ep_events_has_column($mysqli, 'image_url');
    }
    if (!$hasImageId) {
        @$mysqli->query("ALTER TABLE ep_events ADD COLUMN image_id INT NULL AFTER image_url");
        $hasImageId = ep_events_has_column($mysqli, 'image_id');
        if ($hasImageId) {
            @$mysqli->query("ALTER TABLE ep_events ADD INDEX idx_ep_events_image_id (image_id)");
        }
    }
    return [
        'image_url' => $hasImageUrl,
        'image_id' => $hasImageId
    ];
}

function ep_events_ensure_recurring_columns(mysqli $mysqli): array {
    $hasSeriesId = ep_events_has_column($mysqli, 'recurring_series_id');
    if (!$hasSeriesId) {
        @$mysqli->query("ALTER TABLE ep_events ADD COLUMN recurring_series_id VARCHAR(64) NULL AFTER created_by_member_id");
        $hasSeriesId = ep_events_has_column($mysqli, 'recurring_series_id');
        if ($hasSeriesId) {
            @$mysqli->query("ALTER TABLE ep_events ADD INDEX idx_ep_events_series_owner (owner_id, recurring_series_id)");
        }
    }
    return [
        'recurring_series_id' => $hasSeriesId
    ];
}

function ep_events_ensure_image_library_schema(mysqli $mysqli): bool {
    $sql = "
        CREATE TABLE IF NOT EXISTS event_images (
            id INT AUTO_INCREMENT PRIMARY KEY,
            owner_id INT NOT NULL,
            image_url VARCHAR(1024) NOT NULL,
            image_url_hash CHAR(64) NOT NULL,
            source_key VARCHAR(512) DEFAULT NULL,
            content_hash CHAR(64) DEFAULT NULL,
            created_by_member_id INT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME DEFAULT NULL,
            UNIQUE KEY uniq_owner_hash (owner_id, image_url_hash),
            KEY idx_owner_last_used (owner_id, last_used_at),
            KEY idx_owner_created (owner_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    $ok = @$mysqli->query($sql);
    return (bool)$ok;
}

function ep_clean_image_url($value): string {
    $url = trim((string)$value);
    if ($url === '') return '';
    if (strlen($url) > 1024) {
        $url = substr($url, 0, 1024);
    }
    return $url;
}

function ep_extract_source_key_from_image_url(string $url): string {
    $prefix = "https://pub-1afc23a510c147a5a857168f23ff6db8.r2.dev/";
    if (str_starts_with($url, $prefix)) {
        $raw = substr($url, strlen($prefix));
        $raw = preg_replace('/\?.*$/', '', $raw);
        return trim(rawurldecode($raw));
    }
    return '';
}

function ep_image_library_touch(mysqli $mysqli, int $ownerId, string $imageUrl): int {
    $cleanUrl = ep_clean_image_url($imageUrl);
    if ($cleanUrl === '') return 0;
    if (!ep_events_ensure_image_library_schema($mysqli)) return 0;

    $hash = hash('sha256', $cleanUrl);
    $sourceKey = ep_extract_source_key_from_image_url($cleanUrl);
    $stmt = $mysqli->prepare("
        SELECT id
        FROM event_images
        WHERE owner_id = ? AND image_url_hash = ?
        LIMIT 1
    ");
    if (!$stmt) return 0;
    $stmt->bind_param("is", $ownerId, $hash);
    $stmt->execute();
    $stmt->bind_result($existingId);
    $found = $stmt->fetch();
    $stmt->close();

    if ($found && !empty($existingId)) {
        $imageId = (int)$existingId;
        $stmt = $mysqli->prepare("
            UPDATE event_images
            SET image_url = ?, source_key = ?, last_used_at = NOW()
            WHERE id = ? AND owner_id = ?
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param("ssii", $cleanUrl, $sourceKey, $imageId, $ownerId);
            $stmt->execute();
            $stmt->close();
        }
        return $imageId;
    }

    $stmt = $mysqli->prepare("
        INSERT INTO event_images (owner_id, image_url, image_url_hash, source_key, created_by_member_id, last_used_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    if (!$stmt) return 0;
    $createdBy = (int)($_SESSION['user_id'] ?? 0);
    $stmt->bind_param("isssi", $ownerId, $cleanUrl, $hash, $sourceKey, $createdBy);
    $ok = $stmt->execute();
    $newId = $ok ? (int)$stmt->insert_id : 0;
    $stmt->close();
    return $newId;
}

function ep_generate_series_id(): string {
    $datePart = gmdate('YmdHis');
    try {
        $randomPart = bin2hex(random_bytes(4));
    } catch (Throwable $ex) {
        $randomPart = substr(md5(uniqid((string)mt_rand(), true)), 0, 8);
    }
    return "SER-{$datePart}-{$randomPart}";
}

function ep_set_event_groups(mysqli $mysqli, int $eventId, int $allMembers, array $groupIds): void {
    $stmt = $mysqli->prepare("UPDATE ep_events SET all_members = ? WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("ii", $allMembers, $eventId);
        $stmt->execute();
        $stmt->close();
    }

    $stmt = $mysqli->prepare("DELETE FROM ep_event_groups WHERE event_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $eventId);
        $stmt->execute();
        $stmt->close();
    }

    if ($allMembers) return;
    $stmt = $mysqli->prepare("INSERT INTO ep_event_groups (event_id, group_id) VALUES (?, ?)");
    if (!$stmt) return;
    foreach ($groupIds as $gid) {
        $gid = (int)$gid;
        if ($gid <= 0) continue;
        $stmt->bind_param("ii", $eventId, $gid);
        $stmt->execute();
    }
    $stmt->close();
}

function ep_set_series_groups(mysqli $mysqli, int $ownerId, string $seriesId, int $allMembers, array $groupIds): int {
    $seriesId = trim($seriesId);
    if ($seriesId === '') return 0;
    $stmt = $mysqli->prepare("
        SELECT id
        FROM ep_events
        WHERE owner_id = ? AND recurring_series_id = ?
    ");
    if (!$stmt) return 0;
    $stmt->bind_param("is", $ownerId, $seriesId);
    $stmt->execute();
    $res = $stmt->get_result();
    $eventIds = [];
    while ($row = $res->fetch_assoc()) {
        $eventIds[] = (int)$row['id'];
    }
    $stmt->close();

    foreach ($eventIds as $eventId) {
        ep_set_event_groups($mysqli, $eventId, $allMembers, $groupIds);
    }
    return count($eventIds);
}

$rawOwner = $_GET['owner'] ?? null;
$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data)) {
    $data = $_POST;
}
$rawOwner = $rawOwner ?? ($data['owner'] ?? null);

function ep_resolve_owner_id($mysqli, $tokenOrUser, $fallback) {
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
$canManage = ($ownerId === (int)$memberId) || ($roleRank >= 80);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $groupId = (int)($_GET['group_id'] ?? 0);
    $start = $_GET['start'] ?? null;
    $end = $_GET['end'] ?? null;
    $myEvents = (int)($_GET['my_events'] ?? 0);
    $category = trim($_GET['category'] ?? '');

    if ((int)$ownerId !== (int)$memberId) {
        $stmt = $mysqli->prepare("
            SELECT 1
            FROM content_lists cl
            JOIN invitations i ON i.listToken = cl.token
            JOIN members m ON m.email = i.email
            WHERE cl.owner_id = ? AND m.id = ?
            LIMIT 1
        ");
        $stmt->bind_param("ii", $ownerId, $memberId);
        $stmt->execute();
        $stmt->store_result();
        $isInvited = $stmt->num_rows > 0;
        $stmt->close();
        if (!$isInvited) {
            ep_json(["status" => "OK", "events" => []]);
        }
    }

    $imageCols = ep_events_ensure_image_columns($mysqli);
    $recurringCols = ep_events_ensure_recurring_columns($mysqli);
    $hasImageLibrary = ep_events_ensure_image_library_schema($mysqli);
    $hasImageUrlColumn = !empty($imageCols['image_url']);
    $hasImageIdColumn = !empty($imageCols['image_id']);
    $hasSeriesIdColumn = !empty($recurringCols['recurring_series_id']);
    $imageSelect = ($hasImageIdColumn && $hasImageLibrary)
        ? "COALESCE(lib.image_url, e.image_url, '') AS image_url"
        : ($hasImageUrlColumn ? "e.image_url" : "'' AS image_url");
    $seriesSelect = $hasSeriesIdColumn
        ? "COALESCE(e.recurring_series_id, '') AS recurring_series_id"
        : "'' AS recurring_series_id";
    $imageJoin = ($hasImageIdColumn && $hasImageLibrary)
        ? "LEFT JOIN event_images lib ON lib.id = e.image_id AND lib.owner_id = e.owner_id"
        : "";

    $events = [];
    if ($groupId > 0) {
        $sql = "
            SELECT e.id, e.title, e.location, e.starts_at, e.ends_at, e.notes, {$imageSelect}, {$seriesSelect}, e.all_members, e.category,
                   c.status AS my_checkin, owner_m.display_name AS owner_display_name, owner_m.username AS owner_username,
                   CASE
                     WHEN e.owner_id = ? OR gm.member_id IS NOT NULL
                       OR (e.all_members = 1 AND invm.id IS NOT NULL)
                     THEN 1 ELSE 0
                   END AS is_member
            FROM ep_events e
            {$imageJoin}
            LEFT JOIN ep_event_groups eg ON eg.event_id = e.id
            LEFT JOIN members owner_m ON owner_m.id = e.owner_id
            LEFT JOIN ep_group_members gm ON gm.group_id = eg.group_id AND gm.member_id = ?
            LEFT JOIN content_lists cl ON cl.owner_id = owner_m.id AND cl.token = owner_m.username
            LEFT JOIN invitations i ON i.listToken = cl.token
            LEFT JOIN members invm ON invm.email = i.email AND invm.id = ?
            LEFT JOIN ep_checkins c ON c.event_id = e.id AND c.member_id = ?
            WHERE e.owner_id = ?
              AND (eg.group_id = ? OR e.all_members = 1)
        ";
        $types = "iiiiii";
        $params = [$memberId, $memberId, $memberId, $memberId, $ownerId, $groupId];
        if ($category !== '') {
            $sql .= " AND e.category = ?";
            $types .= "s";
            $params[] = $category;
        }
        if ($start) {
            $sql .= " AND e.starts_at >= ?";
            $types .= "s";
            $params[] = $start;
        }
        if ($end) {
            $sql .= " AND e.starts_at <= ?";
            $types .= "s";
            $params[] = $end;
        }
        $sql .= " ORDER BY e.starts_at ASC";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param($types, ...$params);
    } else {
        $includeCheckins = ((int)$ownerId === (int)$memberId);
        if ($includeCheckins) {
            $sql = "
                SELECT DISTINCT e.id, e.title, e.location, e.starts_at, e.ends_at, e.notes, {$imageSelect}, {$seriesSelect}, e.all_members, e.category,
                       c.status AS my_checkin, owner_m.display_name AS owner_display_name, owner_m.username AS owner_username,
                       CASE
                         WHEN e.owner_id = ? OR gm.member_id IS NOT NULL
                           OR (e.all_members = 1 AND invm.id IS NOT NULL)
                         THEN 1 ELSE 0
                       END AS is_member
                FROM ep_events e
                {$imageJoin}
                LEFT JOIN ep_event_groups eg ON eg.event_id = e.id
                LEFT JOIN ep_group_members gm ON gm.group_id = eg.group_id AND gm.member_id = ?
                LEFT JOIN ep_checkins c ON c.event_id = e.id AND c.member_id = ?
                LEFT JOIN members owner_m ON owner_m.id = e.owner_id
                LEFT JOIN content_lists cl ON cl.owner_id = owner_m.id AND cl.token = owner_m.username
                LEFT JOIN invitations i ON i.listToken = cl.token
                LEFT JOIN members invm ON invm.email = i.email AND invm.id = ?
                WHERE (
                  e.owner_id = ?
                  OR c.status = 'in'
                  OR gm.member_id IS NOT NULL
                  OR (e.all_members = 1 AND invm.id IS NOT NULL)
                )
            ";
            $types = "iiiii";
            $params = [$memberId, $memberId, $memberId, $memberId, $ownerId];
        } else {
            $sql = "
                SELECT DISTINCT e.id, e.title, e.location, e.starts_at, e.ends_at, e.notes, {$imageSelect}, {$seriesSelect}, e.all_members, e.category,
                       c.status AS my_checkin, owner_m.display_name AS owner_display_name, owner_m.username AS owner_username,
                       CASE
                         WHEN e.owner_id = ? OR gm.member_id IS NOT NULL
                           OR (e.all_members = 1 AND invm.id IS NOT NULL)
                         THEN 1 ELSE 0
                       END AS is_member
                FROM ep_events e
                {$imageJoin}
                LEFT JOIN ep_event_groups eg ON eg.event_id = e.id
                LEFT JOIN ep_groups g ON g.id = eg.group_id AND g.owner_id = ?
                LEFT JOIN ep_group_members gm ON gm.group_id = g.id AND gm.member_id = ?
                LEFT JOIN ep_checkins c ON c.event_id = e.id AND c.member_id = ?
                LEFT JOIN members owner_m ON owner_m.id = e.owner_id
                LEFT JOIN content_lists cl ON cl.owner_id = owner_m.id AND cl.token = owner_m.username
                LEFT JOIN invitations i ON i.listToken = cl.token
                LEFT JOIN members invm ON invm.email = i.email AND invm.id = ?
                WHERE e.owner_id = ?
            ";
            $types = "iiiiii";
            $params = [$memberId, $ownerId, $memberId, $memberId, $memberId, $ownerId];
        }
        if ($start) {
            $sql .= " AND e.starts_at >= ?";
            $types .= "s";
            $params[] = $start;
        }
        if ($end) {
            $sql .= " AND e.starts_at <= ?";
            $types .= "s";
            $params[] = $end;
        }
        if ($myEvents && (int)$memberId !== (int)$ownerId) {
            $sql .= " AND (e.all_members = 1 OR gm.member_id IS NOT NULL)";
        }
        if ($category !== '') {
            $sql .= " AND e.category = ?";
            $types .= "s";
            $params[] = $category;
        }
        $sql .= " ORDER BY e.starts_at ASC";

        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $events[] = $row;
    }
    $stmt->close();

    if (count($events)) {
        $eventIds = array_map(static function ($row) {
            return (int)$row['id'];
        }, $events);
        $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
        $types = str_repeat('i', count($eventIds));
        $stmt = $mysqli->prepare("
            SELECT eg.event_id, g.id, g.name, g.color
            FROM ep_event_groups eg
            JOIN ep_groups g ON g.id = eg.group_id
            WHERE eg.event_id IN ($placeholders)
            ORDER BY g.name ASC
        ");
        $stmt->bind_param($types, ...$eventIds);
        $stmt->execute();
        $res = $stmt->get_result();
        $groupsByEvent = [];
        while ($row = $res->fetch_assoc()) {
            $eventId = (int)$row['event_id'];
            $groupsByEvent[$eventId] = $groupsByEvent[$eventId] ?? [];
            $groupsByEvent[$eventId][] = [
                "id" => (int)$row['id'],
                "name" => $row['name'],
                "color" => $row['color']
            ];
        }
        $stmt->close();

        foreach ($events as &$event) {
            $eventId = (int)$event['id'];
            $event['groups'] = $groupsByEvent[$eventId] ?? [];
        }
        unset($event);
    }

    ep_json(["status" => "OK", "events" => $events]);
}

$action = $data['action'] ?? '';

if (!$canManage) {
    ep_json(["status" => "error", "message" => "Permission denied"]);
}

if ($action === 'create') {
    $title = trim($data['title'] ?? '');
    $category = trim($data['category'] ?? '');
    if ($category === '') $category = '';
    $location = trim($data['location'] ?? '');
    $startsAt = $data['starts_at'] ?? '';
    $endsAt = $data['ends_at'] ?? null;
    $notes = trim($data['notes'] ?? '');
    $imageUrl = ep_clean_image_url($data['image_url'] ?? '');

    if ($title === '' || $startsAt === '') {
        ep_json(["status" => "error", "message" => "title and starts_at required"]);
    }

    $allMembers = !empty($data['all_members']) ? 1 : 0;
    $imageCols = ep_events_ensure_image_columns($mysqli);
    $hasImageUrlColumn = !empty($imageCols['image_url']);
    $hasImageIdColumn = !empty($imageCols['image_id']);
    $imageId = 0;
    if ($imageUrl !== '') {
        $imageId = ep_image_library_touch($mysqli, (int)$ownerId, $imageUrl);
    }
    if ($imageUrl !== '' && $imageId <= 0 && $hasImageIdColumn) {
        ep_json(["status" => "error", "message" => "Unable to register image"]);
    }
    if ($hasImageUrlColumn && $hasImageIdColumn) {
        $stmt = $mysqli->prepare("
            INSERT INTO ep_events (title, category, location, starts_at, ends_at, notes, image_url, image_id, owner_id, created_by_member_id, all_members)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("sssssssiiii", $title, $category, $location, $startsAt, $endsAt, $notes, $imageUrl, $imageId, $ownerId, $memberId, $allMembers);
    } elseif ($hasImageUrlColumn) {
        $stmt = $mysqli->prepare("
            INSERT INTO ep_events (title, category, location, starts_at, ends_at, notes, image_url, owner_id, created_by_member_id, all_members)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("sssssssiii", $title, $category, $location, $startsAt, $endsAt, $notes, $imageUrl, $ownerId, $memberId, $allMembers);
    } else {
        $stmt = $mysqli->prepare("
            INSERT INTO ep_events (title, category, location, starts_at, ends_at, notes, owner_id, created_by_member_id, all_members)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("ssssssiii", $title, $category, $location, $startsAt, $endsAt, $notes, $ownerId, $memberId, $allMembers);
    }
    $stmt->execute();
    $newId = $stmt->insert_id;
    $stmt->close();

    $groupIds = $data['group_ids'] ?? [];
    if (!$allMembers && is_array($groupIds) && count($groupIds)) {
        $stmt = $mysqli->prepare("INSERT INTO ep_event_groups (event_id, group_id) VALUES (?, ?)");
        foreach ($groupIds as $gid) {
            $gid = (int)$gid;
            if ($gid <= 0) continue;
            $stmt->bind_param("ii", $newId, $gid);
            $stmt->execute();
        }
        $stmt->close();
    }

    ep_json(["status" => "OK", "event_id" => $newId]);
}

if ($action === 'create_recurring') {
    $title = trim($data['title'] ?? '');
    $category = trim($data['category'] ?? '');
    if ($category === '') $category = '';
    $location = trim($data['location'] ?? '');
    $startsAt = $data['starts_at'] ?? '';
    $endsAt = $data['ends_at'] ?? null;
    $notes = trim($data['notes'] ?? '');
    $imageUrl = ep_clean_image_url($data['image_url'] ?? '');
    $frequency = $data['recurring_frequency'] ?? 'weekly';
    $until = $data['recurring_until'] ?? '';
    $groupIds = $data['group_ids'] ?? [];
    $rotateGroups = !empty($data['rotate_groups']) ? 1 : 0;
    $allMembers = !empty($data['all_members']) ? 1 : 0;

    if ($title === '' || $startsAt === '' || $until === '') {
        ep_json(["status" => "error", "message" => "title, starts_at, and recurring_until required"]);
    }
    if (!$allMembers && (!is_array($groupIds) || count($groupIds) === 0)) {
        ep_json(["status" => "error", "message" => "Select at least one group or All members"]);
    }

    $startDate = new DateTime($startsAt);
    $untilDate = new DateTime($until . " 23:59:59");
    $duration = null;
    if ($endsAt) {
        $endDate = new DateTime($endsAt);
        $duration = $startDate->diff($endDate);
    }

    $recurringCols = ep_events_ensure_recurring_columns($mysqli);
    $hasSeriesIdColumn = !empty($recurringCols['recurring_series_id']);
    $seriesId = ep_generate_series_id();
    $imageCols = ep_events_ensure_image_columns($mysqli);
    $hasImageUrlColumn = !empty($imageCols['image_url']);
    $hasImageIdColumn = !empty($imageCols['image_id']);
    $imageId = 0;
    if ($imageUrl !== '') {
        $imageId = ep_image_library_touch($mysqli, (int)$ownerId, $imageUrl);
    }
    if ($imageUrl !== '' && $imageId <= 0 && $hasImageIdColumn) {
        ep_json(["status" => "error", "message" => "Unable to register image"]);
    }

    $createdIds = [];
    $groupCount = count($groupIds);
    $occurrenceIndex = 0;

    while ($startDate <= $untilDate) {
        $eventStart = $startDate->format("Y-m-d H:i:s");
        $eventEnd = null;
        if ($duration) {
            $endCopy = clone $startDate;
            $endCopy->add($duration);
            $eventEnd = $endCopy->format("Y-m-d H:i:s");
        }

        if ($hasSeriesIdColumn && $hasImageUrlColumn && $hasImageIdColumn) {
            $stmt = $mysqli->prepare("
                INSERT INTO ep_events (title, category, location, starts_at, ends_at, notes, image_url, image_id, owner_id, created_by_member_id, recurring_series_id, all_members)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("sssssssiiisi", $title, $category, $location, $eventStart, $eventEnd, $notes, $imageUrl, $imageId, $ownerId, $memberId, $seriesId, $allMembers);
        } elseif ($hasSeriesIdColumn && $hasImageUrlColumn) {
            $stmt = $mysqli->prepare("
                INSERT INTO ep_events (title, category, location, starts_at, ends_at, notes, image_url, owner_id, created_by_member_id, recurring_series_id, all_members)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("sssssssiisi", $title, $category, $location, $eventStart, $eventEnd, $notes, $imageUrl, $ownerId, $memberId, $seriesId, $allMembers);
        } elseif ($hasSeriesIdColumn) {
            $stmt = $mysqli->prepare("
                INSERT INTO ep_events (title, category, location, starts_at, ends_at, notes, owner_id, created_by_member_id, recurring_series_id, all_members)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("ssssssiisi", $title, $category, $location, $eventStart, $eventEnd, $notes, $ownerId, $memberId, $seriesId, $allMembers);
        } elseif ($hasImageUrlColumn && $hasImageIdColumn) {
            $stmt = $mysqli->prepare("
                INSERT INTO ep_events (title, category, location, starts_at, ends_at, notes, image_url, image_id, owner_id, created_by_member_id, all_members)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("sssssssiiii", $title, $category, $location, $eventStart, $eventEnd, $notes, $imageUrl, $imageId, $ownerId, $memberId, $allMembers);
        } elseif ($hasImageUrlColumn) {
            $stmt = $mysqli->prepare("
                INSERT INTO ep_events (title, category, location, starts_at, ends_at, notes, image_url, owner_id, created_by_member_id, all_members)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("sssssssiii", $title, $category, $location, $eventStart, $eventEnd, $notes, $imageUrl, $ownerId, $memberId, $allMembers);
        } else {
            $stmt = $mysqli->prepare("
                INSERT INTO ep_events (title, category, location, starts_at, ends_at, notes, owner_id, created_by_member_id, all_members)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("ssssssiii", $title, $category, $location, $eventStart, $eventEnd, $notes, $ownerId, $memberId, $allMembers);
        }
        $stmt->execute();
        $eventId = $stmt->insert_id;
        $stmt->close();

        if (!$allMembers) {
            if ($rotateGroups) {
                $groupId = (int)$groupIds[$occurrenceIndex % $groupCount];
                if ($groupId > 0) {
                    $stmt = $mysqli->prepare("INSERT INTO ep_event_groups (event_id, group_id) VALUES (?, ?)");
                    $stmt->bind_param("ii", $eventId, $groupId);
                    $stmt->execute();
                    $stmt->close();
                }
            } else {
                $stmt = $mysqli->prepare("INSERT INTO ep_event_groups (event_id, group_id) VALUES (?, ?)");
                foreach ($groupIds as $gid) {
                    $gid = (int)$gid;
                    if ($gid <= 0) continue;
                    $stmt->bind_param("ii", $eventId, $gid);
                    $stmt->execute();
                }
                $stmt->close();
            }
        }

        $createdIds[] = $eventId;
        $occurrenceIndex++;

        if ($frequency === 'monthly') {
            $startDate->modify('+1 month');
        } else {
            $startDate->modify('+1 week');
        }
    }

    ep_json(["status" => "OK", "created" => count($createdIds), "series_id" => $hasSeriesIdColumn ? $seriesId : ""]);
}
if ($action === 'update') {
    $eventId = (int)($data['event_id'] ?? 0);
    if ($eventId <= 0) {
        ep_json(["status" => "error", "message" => "event_id required"]);
    }

    $recurringCols = ep_events_ensure_recurring_columns($mysqli);
    $hasSeriesIdColumn = !empty($recurringCols['recurring_series_id']);
    $updateScope = strtolower(trim((string)($data['update_scope'] ?? 'event')));
    $applySeries = $hasSeriesIdColumn && ($updateScope === 'series');
    $seriesId = '';
    $anchorStartsAt = '';
    if ($applySeries) {
        $stmt = $mysqli->prepare("
            SELECT recurring_series_id, starts_at, ends_at
            FROM ep_events
            WHERE id = ? AND owner_id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            ep_json(["status" => "error", "message" => "Unable to resolve series"]);
        }
        $stmt->bind_param("ii", $eventId, $ownerId);
        $stmt->execute();
        $stmt->bind_result($seriesIdValue, $anchorStartsAtValue, $anchorEndsAtValue);
        $found = $stmt->fetch();
        $stmt->close();
        $seriesId = trim((string)($seriesIdValue ?? ''));
        $anchorStartsAt = trim((string)($anchorStartsAtValue ?? ''));
        if (!$found || $seriesId === '') {
            ep_json(["status" => "error", "message" => "Selected event is not part of a recurring series"]);
        }
    }

    $fields = [];
    $params = [];
    $types = "";
    $imageCols = ep_events_ensure_image_columns($mysqli);
    $hasImageUrlColumn = !empty($imageCols['image_url']);
    $hasImageIdColumn = !empty($imageCols['image_id']);
    $hasGroupPatch = array_key_exists('group_ids', $data) || array_key_exists('all_members', $data);
    if (array_key_exists('group_ids', $data) && !is_array($data['group_ids'])) {
        ep_json(["status" => "error", "message" => "group_ids must be array"]);
    }
    $seriesStartShiftSeconds = null;
    $seriesDurationSeconds = null;
    $seriesSetEndsNull = false;
    $seriesHasEndUpdate = false;
    $seriesStartWasUpdated = false;
    $seriesNewStartsAt = null;
    $seriesEventNotesPatch = false;
    $seriesEventNotesValue = "";
    $allowed = ["title", "category", "location", "starts_at", "ends_at", "notes", "all_members"];
    foreach ($allowed as $field) {
        if (array_key_exists($field, $data)) {
            // Preserve per-event notes when applying updates to a recurring series.
            if ($applySeries && $field === "notes") {
                $seriesEventNotesPatch = true;
                $seriesEventNotesValue = (string)$data[$field];
                continue;
            }
            if ($applySeries && $field === "starts_at") {
                $seriesStartWasUpdated = true;
                $seriesNewStartsAt = trim((string)$data[$field]);
                if ($seriesNewStartsAt === '' || $anchorStartsAt === '') {
                    ep_json(["status" => "error", "message" => "starts_at required for series update"]);
                }
                try {
                    $anchorStartTs = (new DateTime($anchorStartsAt))->getTimestamp();
                    $nextStartTs = (new DateTime($seriesNewStartsAt))->getTimestamp();
                    $seriesStartShiftSeconds = $nextStartTs - $anchorStartTs;
                } catch (Throwable $ex) {
                    ep_json(["status" => "error", "message" => "Invalid starts_at value"]);
                }
                continue;
            }
            if ($applySeries && $field === "ends_at") {
                $seriesHasEndUpdate = true;
                $nextEndsRaw = trim((string)$data[$field]);
                if ($nextEndsRaw === '') {
                    $seriesSetEndsNull = true;
                } else {
                    try {
                        $baseStart = $seriesStartWasUpdated ? $seriesNewStartsAt : $anchorStartsAt;
                        if ($baseStart === '') {
                            ep_json(["status" => "error", "message" => "Invalid series start reference"]);
                        }
                        $baseStartTs = (new DateTime($baseStart))->getTimestamp();
                        $nextEndsTs = (new DateTime($nextEndsRaw))->getTimestamp();
                        $seriesDurationSeconds = $nextEndsTs - $baseStartTs;
                        if ($seriesDurationSeconds < 0) {
                            ep_json(["status" => "error", "message" => "ends_at must be after starts_at"]);
                        }
                    } catch (Throwable $ex) {
                        ep_json(["status" => "error", "message" => "Invalid ends_at value"]);
                    }
                }
                continue;
            }
            $fields[] = "$field = ?";
            if ($field === "all_members") {
                $params[] = !empty($data[$field]) ? 1 : 0;
                $types .= "i";
            } elseif ($field === "category") {
                $value = trim($data[$field]);
                $params[] = $value === "" ? "" : $value;
                $types .= "s";
            } else {
                $params[] = $data[$field];
                $types .= "s";
            }
        }
    }
    if (array_key_exists("image_url", $data)) {
        $nextImageUrl = ep_clean_image_url($data["image_url"]);
        if ($hasImageUrlColumn) {
            $fields[] = "image_url = ?";
            $params[] = $nextImageUrl;
            $types .= "s";
        }
        if ($hasImageIdColumn) {
            if ($nextImageUrl === "") {
                $fields[] = "image_id = NULL";
            } else {
                $nextImageId = ep_image_library_touch($mysqli, (int)$ownerId, $nextImageUrl);
                if ($nextImageId <= 0) {
                    ep_json(["status" => "error", "message" => "Unable to register image"]);
                }
                $fields[] = "image_id = ?";
                $params[] = $nextImageId;
                $types .= "i";
            }
        }
    }
    $hasSeriesDatePatch = $applySeries && ($seriesStartWasUpdated || $seriesHasEndUpdate);
    if (!$fields && !$hasGroupPatch && !$hasSeriesDatePatch && !$seriesEventNotesPatch) {
        ep_json(["status" => "error", "message" => "No fields to update"]);
    }

    if ($fields) {
        if ($applySeries) {
            $sql = "UPDATE ep_events SET " . implode(", ", $fields) . " WHERE owner_id = ? AND recurring_series_id = ?";
            $types .= "is";
            $params[] = $ownerId;
            $params[] = $seriesId;
        } else {
            $sql = "UPDATE ep_events SET " . implode(", ", $fields) . " WHERE id = ? AND owner_id = ?";
            $types .= "ii";
            $params[] = $eventId;
            $params[] = $ownerId;
        }
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            ep_json(["status" => "error", "message" => "Unable to update event"]);
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();
    }

    if ($applySeries && $seriesStartShiftSeconds !== null && (int)$seriesStartShiftSeconds !== 0) {
        $shift = (int)$seriesStartShiftSeconds;
        $stmt = $mysqli->prepare("
            UPDATE ep_events
            SET starts_at = DATE_ADD(starts_at, INTERVAL ? SECOND),
                ends_at = CASE WHEN ends_at IS NULL THEN NULL ELSE DATE_ADD(ends_at, INTERVAL ? SECOND) END
            WHERE owner_id = ? AND recurring_series_id = ?
        ");
        if ($stmt) {
            $stmt->bind_param("iiis", $shift, $shift, $ownerId, $seriesId);
            $stmt->execute();
            $stmt->close();
        }
    }

    if ($applySeries && $seriesHasEndUpdate) {
        if ($seriesSetEndsNull) {
            $stmt = $mysqli->prepare("
                UPDATE ep_events
                SET ends_at = NULL
                WHERE owner_id = ? AND recurring_series_id = ?
            ");
            if ($stmt) {
                $stmt->bind_param("is", $ownerId, $seriesId);
                $stmt->execute();
                $stmt->close();
            }
        } elseif ($seriesDurationSeconds !== null) {
            $duration = (int)$seriesDurationSeconds;
            $stmt = $mysqli->prepare("
                UPDATE ep_events
                SET ends_at = DATE_ADD(starts_at, INTERVAL ? SECOND)
                WHERE owner_id = ? AND recurring_series_id = ?
            ");
            if ($stmt) {
                $stmt->bind_param("iis", $duration, $ownerId, $seriesId);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    if ($hasGroupPatch) {
        $allMembers = !empty($data['all_members']) ? 1 : 0;
        $groupIds = is_array($data['group_ids'] ?? null) ? $data['group_ids'] : [];
        if ($applySeries) {
            ep_set_series_groups($mysqli, (int)$ownerId, $seriesId, $allMembers, $groupIds);
        } else {
            ep_set_event_groups($mysqli, $eventId, $allMembers, $groupIds);
        }
    }

    if ($applySeries && $seriesEventNotesPatch) {
        $stmt = $mysqli->prepare("
            UPDATE ep_events
            SET notes = ?
            WHERE id = ? AND owner_id = ?
        ");
        if ($stmt) {
            $stmt->bind_param("sii", $seriesEventNotesValue, $eventId, $ownerId);
            $stmt->execute();
            $stmt->close();
        }
    }

    ep_json([
        "status" => "OK",
        "scope" => $applySeries ? "series" : "event",
        "series_id" => $seriesId
    ]);
}

if ($action === 'image_suggestions') {
    if (!$canManage) {
        ep_json(["status" => "error", "message" => "Forbidden"]);
    }
    ep_events_ensure_image_columns($mysqli);
    ep_events_ensure_image_library_schema($mysqli);
    $limit = (int)($data['limit'] ?? 24);
    if ($limit < 1) $limit = 1;
    if ($limit > 100) $limit = 100;

    $items = [];
    $stmt = $mysqli->prepare("
        SELECT image_url
        FROM event_images
        WHERE owner_id = ?
        ORDER BY COALESCE(last_used_at, created_at) DESC
        LIMIT ?
    ");
    if ($stmt) {
        $stmt->bind_param("ii", $ownerId, $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $url = ep_clean_image_url($row['image_url'] ?? '');
            if ($url !== '') {
                $items[] = $url;
            }
        }
        $stmt->close();
    }

    if (empty($items) && ep_events_has_column($mysqli, 'image_url')) {
        $stmt = $mysqli->prepare("
            SELECT DISTINCT image_url
            FROM ep_events
            WHERE owner_id = ?
              AND image_url IS NOT NULL
              AND image_url <> ''
            ORDER BY id DESC
            LIMIT ?
        ");
        if ($stmt) {
            $stmt->bind_param("ii", $ownerId, $limit);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $url = ep_clean_image_url($row['image_url'] ?? '');
                if ($url !== '') {
                    $items[] = $url;
                }
            }
            $stmt->close();
        }
    }

    ep_json(["status" => "OK", "images" => array_values(array_unique($items))]);
}

if ($action === 'image_suggestion_remove') {
    if (!$canManage) {
        ep_json(["status" => "error", "message" => "Forbidden"]);
    }
    ep_events_ensure_image_library_schema($mysqli);
    $imageUrl = ep_clean_image_url($data['image_url'] ?? '');
    if ($imageUrl === '') {
        ep_json(["status" => "error", "message" => "image_url required"]);
    }
    $hash = hash('sha256', $imageUrl);
    $stmt = $mysqli->prepare("
        DELETE FROM event_images
        WHERE owner_id = ? AND image_url_hash = ?
        LIMIT 1
    ");
    if (!$stmt) {
        ep_json(["status" => "error", "message" => "Unable to remove image"]);
    }
    $stmt->bind_param("is", $ownerId, $hash);
    $stmt->execute();
    $removed = $stmt->affected_rows > 0;
    $stmt->close();

    ep_json(["status" => "OK", "removed" => $removed ? 1 : 0]);
}

if ($action === 'delete') {
    $eventId = (int)($data['event_id'] ?? 0);
    if ($eventId <= 0) {
        ep_json(["status" => "error", "message" => "event_id required"]);
    }

    $stmt = $mysqli->prepare("DELETE FROM ep_event_groups WHERE event_id = ?");
    $stmt->bind_param("i", $eventId);
    $stmt->execute();
    $stmt->close();

    $stmt = $mysqli->prepare("DELETE FROM ep_checkins WHERE event_id = ?");
    $stmt->bind_param("i", $eventId);
    $stmt->execute();
    $stmt->close();

    $stmt = $mysqli->prepare("
        DELETE FROM ep_events
        WHERE id = ? AND owner_id = ?
    ");
    $stmt->bind_param("ii", $eventId, $ownerId);
    $stmt->execute();
    $stmt->close();

    ep_json(["status" => "OK"]);
}

ep_json(["status" => "error", "message" => "Unsupported action"]);
