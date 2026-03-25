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

function ep_polls_json($payload) {
    echo json_encode($payload);
    exit;
}

function ep_polls_resolve_owner_id($mysqli, $tokenOrUser, $fallback) {
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

function ep_polls_is_invited_to_owner($mysqli, $ownerId, $memberId) {
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
    $ok = $stmt->num_rows > 0;
    $stmt->close();
    return $ok;
}

function ep_polls_can_access_list_token($mysqli, $listToken, $username, $minRank = 60) {
    if (!$listToken || !$username) return false;
    return (int)get_user_list_role_rank($mysqli, $listToken, $username) >= (int)$minRank;
}

function ep_polls_supports_chat_scope($mysqli) {
    $hasSource = false;
    $hasListToken = false;
    $res = $mysqli->query("SHOW COLUMNS FROM ep_polls");
    if (!$res) return false;
    while ($row = $res->fetch_assoc()) {
        $field = strtolower($row['Field'] ?? '');
        if ($field === 'source') $hasSource = true;
        if ($field === 'list_token') $hasListToken = true;
    }
    $res->close();
    return $hasSource && $hasListToken;
}

function ep_polls_fetch_scope_for_poll($mysqli, $pollId) {
    if (ep_polls_supports_chat_scope($mysqli)) {
        $stmt = $mysqli->prepare("
            SELECT p.source, p.list_token, e.owner_id AS event_owner_id
            FROM ep_polls p
            LEFT JOIN ep_events e ON e.id = p.event_id
            WHERE p.id = ?
            LIMIT 1
        ");
    } else {
        $stmt = $mysqli->prepare("
            SELECT 'event' AS source, '' AS list_token, e.owner_id AS event_owner_id
            FROM ep_polls p
            LEFT JOIN ep_events e ON e.id = p.event_id
            WHERE p.id = ?
            LIMIT 1
        ");
    }
    if (!$stmt) return null;
    $stmt->bind_param("i", $pollId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;
    return [
        "source" => (string)($row['source'] ?? 'event'),
        "list_token" => (string)($row['list_token'] ?? ''),
        "owner_id" => (int)($row['event_owner_id'] ?: 0)
    ];
}

$rawOwner = $_GET['owner'] ?? null;
$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data)) {
    $data = $_POST;
}
$rawOwner = $rawOwner ?? ($data['owner'] ?? null);
$ownerRequested = trim((string)$rawOwner) !== '';
$listTokenScope = trim((string)($_GET['list_token'] ?? ($data['list_token'] ?? '')));
$sourceScope = trim((string)($_GET['source'] ?? ($data['source'] ?? 'event')));
if (!in_array($sourceScope, ['event', 'chat', 'all'], true)) {
    $sourceScope = 'event';
}

$ownerId = ep_polls_resolve_owner_id($mysqli, $rawOwner, (int)$memberId);
$username = $_SESSION['username'] ?? '';
$listTokenForRole = $rawOwner ?: $username;
$chatScopeEnabled = ep_polls_supports_chat_scope($mysqli);
if ($listTokenScope !== '') {
    $listTokenForRole = $listTokenScope;
    $resolvedOwnerFromToken = ep_polls_resolve_owner_id($mysqli, $listTokenScope, 0);
    if ($resolvedOwnerFromToken > 0) {
        $ownerId = $resolvedOwnerFromToken;
    }
}
$roleRank = $username ? get_user_list_role_rank($mysqli, $listTokenForRole, $username) : 0;
$canManage = ($ownerId === (int)$memberId) || ($roleRank >= 80);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $eventIdFilter = (int)($_GET['event_id'] ?? 0);
    $pollIdFilter = (int)($_GET['poll_id'] ?? 0);
    $queryMode = $sourceScope;
    if ($listTokenScope !== '' && $queryMode === 'event') {
        $queryMode = 'chat';
    }

    if ($pollIdFilter > 0) {
        $pollScope = ep_polls_fetch_scope_for_poll($mysqli, $pollIdFilter);
        if (!$pollScope) {
            ep_polls_json(["status" => "OK", "polls" => []]);
        }
        if (($pollScope['source'] ?? 'event') === 'chat') {
            if (!ep_polls_can_access_list_token($mysqli, $pollScope['list_token'] ?? '', $username, 60)) {
                ep_polls_json(["status" => "OK", "polls" => []]);
            }
        } else {
            $pollOwnerId = (int)($pollScope['owner_id'] ?? 0);
            if ((int)$pollOwnerId !== (int)$memberId && !ep_polls_is_invited_to_owner($mysqli, (int)$pollOwnerId, (int)$memberId) && !$canManage) {
                ep_polls_json(["status" => "OK", "polls" => []]);
            }
        }
        if ($chatScopeEnabled) {
            $sql = "
                SELECT p.id, p.event_id, p.list_token, p.source, p.question, p.allow_multiple, p.created_by_member_id, p.created_at,
                       COALESCE(e.title, '') AS event_title,
                       COALESCE(
                           owner_m.display_name,
                           owner_m.username,
                           (
                               SELECT COALESCE(m2.display_name, m2.username, '')
                               FROM content_lists cl2
                               JOIN members m2 ON m2.id = cl2.owner_id
                               WHERE BINARY cl2.token = BINARY p.list_token
                               LIMIT 1
                           ),
                           ''
                       ) AS owner_display_name
                FROM ep_polls p
                LEFT JOIN ep_events e ON e.id = p.event_id
                LEFT JOIN members owner_m ON owner_m.id = e.owner_id
                WHERE p.id = ?
                LIMIT 1
            ";
        } else {
            $sql = "
                SELECT p.id, p.event_id, '' AS list_token, 'event' AS source, p.question, p.allow_multiple, p.created_by_member_id, p.created_at,
                       COALESCE(e.title, '') AS event_title,
                       COALESCE(owner_m.display_name, owner_m.username, '') AS owner_display_name
                FROM ep_polls p
                LEFT JOIN ep_events e ON e.id = p.event_id
                LEFT JOIN members owner_m ON owner_m.id = e.owner_id
                WHERE p.id = ?
                LIMIT 1
            ";
        }
        $types = "i";
        $params = [$pollIdFilter];
    } else if ($queryMode === 'chat') {
        if (!$chatScopeEnabled) {
            ep_polls_json(["status" => "error", "message" => "Poll chat scope not enabled in DB yet"]);
        }
        if ($listTokenScope === '') {
            ep_polls_json(["status" => "error", "message" => "list_token required for chat poll query"]);
        }
        if (!ep_polls_can_access_list_token($mysqli, $listTokenScope, $username, 60)) {
            ep_polls_json(["status" => "OK", "polls" => []]);
        }
        $sql = "
            SELECT p.id, p.event_id, p.list_token, p.source, p.question, p.allow_multiple, p.created_by_member_id, p.created_at,
                   '' AS event_title,
                   COALESCE(owner_m.display_name, owner_m.username, '') AS owner_display_name
            FROM ep_polls p
            JOIN content_lists cl ON BINARY cl.token = BINARY p.list_token
            JOIN members owner_m ON owner_m.id = cl.owner_id
            WHERE p.source = 'chat' AND p.list_token = ?
            ORDER BY p.id DESC
        ";
        $types = "s";
        $params = [$listTokenScope];
    } else {
        $eventSelectScopeCols = $chatScopeEnabled
            ? "p.list_token, p.source,"
            : "'' AS list_token, 'event' AS source,";
        $useStrictOwnerScope = $ownerRequested && ((int)$ownerId !== (int)$memberId);
        if ($useStrictOwnerScope) {
            if ((int)$ownerId !== (int)$memberId && !ep_polls_is_invited_to_owner($mysqli, (int)$ownerId, (int)$memberId) && !$canManage) {
                ep_polls_json(["status" => "OK", "polls" => []]);
            }
            $sql = "
                SELECT p.id, p.event_id, $eventSelectScopeCols p.question, p.allow_multiple, p.created_by_member_id, p.created_at, e.title AS event_title,
                       COALESCE(owner_m.display_name, owner_m.username, '') AS owner_display_name
                FROM ep_polls p
                JOIN ep_events e ON e.id = p.event_id
                LEFT JOIN members owner_m ON owner_m.id = e.owner_id
                WHERE e.owner_id = ?
            ";
            $types = "i";
            $params = [(int)$ownerId];
        } else {
            // Match event-calendar visibility: own events + invite/group/all-members access.
            $sql = "
                SELECT DISTINCT p.id, p.event_id, $eventSelectScopeCols p.question, p.allow_multiple, p.created_by_member_id, p.created_at, e.title AS event_title,
                       COALESCE(owner_m.display_name, owner_m.username, '') AS owner_display_name
                FROM ep_polls p
                JOIN ep_events e ON e.id = p.event_id
                LEFT JOIN members owner_m ON owner_m.id = e.owner_id
                LEFT JOIN ep_event_groups eg ON eg.event_id = e.id
                LEFT JOIN ep_group_members gm ON gm.group_id = eg.group_id AND gm.member_id = ?
                LEFT JOIN ep_checkins c ON c.event_id = e.id AND c.member_id = ?
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
            $types = "iiii";
            $params = [(int)$memberId, (int)$memberId, (int)$memberId, (int)$memberId];
        }
        if ($eventIdFilter > 0) {
            $sql .= " AND p.event_id = ?";
            $types .= "i";
            $params[] = $eventIdFilter;
        }
        $sql .= " ORDER BY p.id DESC";
    }

    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $polls = [];
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        $row['event_id'] = !is_null($row['event_id']) ? (int)$row['event_id'] : null;
        $row['list_token'] = isset($row['list_token']) ? (string)$row['list_token'] : "";
        $row['source'] = isset($row['source']) && $row['source'] !== '' ? (string)$row['source'] : 'event';
        $row['owner_display_name'] = isset($row['owner_display_name']) ? trim((string)$row['owner_display_name']) : "";
        $row['allow_multiple'] = (int)$row['allow_multiple'];
        $row['created_by_member_id'] = (int)$row['created_by_member_id'];
        $row['options'] = [];
        $row['my_option_ids'] = [];
        $polls[] = $row;
    }
    $stmt->close();

    if (!count($polls)) {
        ep_polls_json(["status" => "OK", "polls" => []]);
    }

    $pollIds = array_map(static function ($poll) { return (int)$poll['id']; }, $polls);
    $pollMap = [];
    foreach ($polls as $idx => $poll) {
        $pollMap[(int)$poll['id']] = $idx;
    }

    $placeholders = implode(',', array_fill(0, count($pollIds), '?'));
    $types = str_repeat('i', count($pollIds));

    $stmt = $mysqli->prepare("
        SELECT id, poll_id, option_text, sort_order
        FROM ep_poll_options
        WHERE poll_id IN ($placeholders)
        ORDER BY poll_id ASC, sort_order ASC, id ASC
    ");
    $stmt->bind_param($types, ...$pollIds);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $pollId = (int)$row['poll_id'];
        if (!array_key_exists($pollId, $pollMap)) continue;
        $polls[$pollMap[$pollId]]['options'][] = [
            "id" => (int)$row['id'],
            "poll_id" => $pollId,
            "option_text" => $row['option_text'],
            "sort_order" => (int)$row['sort_order'],
            "vote_count" => 0,
            "voters" => []
        ];
    }
    $stmt->close();

    $stmt = $mysqli->prepare("
        SELECT v.poll_id, v.option_id, v.member_id, m.username, m.display_name, m.avatar_url
        FROM ep_poll_votes
        v
        JOIN members m ON m.id = v.member_id
        WHERE v.poll_id IN ($placeholders)
    ");
    $stmt->bind_param($types, ...$pollIds);
    $stmt->execute();
    $res = $stmt->get_result();
    $voteByOption = [];
    while ($row = $res->fetch_assoc()) {
        $pollId = (int)$row['poll_id'];
        $optionId = (int)$row['option_id'];
        $memberIdVote = (int)$row['member_id'];
        $key = $pollId . ":" . $optionId;
        if (!array_key_exists($key, $voteByOption)) {
            $voteByOption[$key] = [];
        }
        $voteByOption[$key][] = [
            "member_id" => $memberIdVote,
            "username" => $row['username'],
            "display_name" => $row['display_name'],
            "avatar_url" => $row['avatar_url']
        ];
    }
    $stmt->close();

    $stmt = $mysqli->prepare("
        SELECT poll_id, option_id
        FROM ep_poll_votes
        WHERE poll_id IN ($placeholders) AND member_id = ?
    ");
    $typesWithMember = $types . "i";
    $paramsWithMember = $pollIds;
    $paramsWithMember[] = (int)$memberId;
    $stmt->bind_param($typesWithMember, ...$paramsWithMember);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $pollId = (int)$row['poll_id'];
        if (!array_key_exists($pollId, $pollMap)) continue;
        $polls[$pollMap[$pollId]]['my_option_ids'][] = (int)$row['option_id'];
    }
    $stmt->close();

    foreach ($polls as &$poll) {
        foreach ($poll['options'] as &$option) {
            $key = (int)$poll['id'] . ":" . (int)$option['id'];
            $option['voters'] = $voteByOption[$key] ?? [];
            $option['vote_count'] = count($option['voters']);
        }
        unset($option);
    }
    unset($poll);

    ep_polls_json(["status" => "OK", "polls" => $polls]);
}

$action = $data['action'] ?? '';

if ($action === 'create') {
    $source = trim((string)($data['source'] ?? 'event'));
    if (!in_array($source, ['event', 'chat'], true)) {
        $source = 'event';
    }
    $eventId = (int)($data['event_id'] ?? 0);
    $listToken = trim((string)($data['list_token'] ?? ''));
    $question = trim($data['question'] ?? '');
    $allowMultiple = !empty($data['allow_multiple']) ? 1 : 0;
    $options = $data['options'] ?? [];

    if ($question === '' || !is_array($options)) {
        ep_polls_json(["status" => "error", "message" => "question and options are required"]);
    }

    $cleanOptions = [];
    foreach ($options as $idx => $opt) {
        $value = trim((string)$opt);
        if ($value === '') continue;
        if (strlen($value) > 255) {
            $value = substr($value, 0, 255);
        }
        $cleanOptions[] = $value;
    }
    if (count($cleanOptions) < 2) {
        ep_polls_json(["status" => "error", "message" => "At least two options are required"]);
    }

    if ($source === 'chat') {
        if (!$chatScopeEnabled) {
            ep_polls_json(["status" => "error", "message" => "Poll chat scope not enabled in DB yet"]);
        }
        if ($listToken === '') {
            ep_polls_json(["status" => "error", "message" => "list_token required for chat poll"]);
        }
        if (!ep_polls_can_access_list_token($mysqli, $listToken, $username, 80)) {
            ep_polls_json(["status" => "error", "message" => "Permission denied"]);
        }
        $stmt = $mysqli->prepare("
            INSERT INTO ep_polls (event_id, list_token, source, question, allow_multiple, created_by_member_id, created_at)
            VALUES (NULL, ?, 'chat', ?, ?, ?, NOW())
        ");
        $stmt->bind_param("ssii", $listToken, $question, $allowMultiple, $memberId);
    } else {
        if (!$canManage) {
            ep_polls_json(["status" => "error", "message" => "Permission denied"]);
        }
        if ($eventId <= 0) {
            ep_polls_json(["status" => "error", "message" => "event_id required for event poll"]);
        }
        $stmt = $mysqli->prepare("SELECT owner_id FROM ep_events WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $eventId);
        $stmt->execute();
        $stmt->bind_result($eventOwnerId);
        $stmt->fetch();
        $stmt->close();
        if ((int)$eventOwnerId !== (int)$ownerId) {
            ep_polls_json(["status" => "error", "message" => "Invalid event for this owner"]);
        }
        if ($chatScopeEnabled) {
            $stmt = $mysqli->prepare("
                INSERT INTO ep_polls (event_id, list_token, source, question, allow_multiple, created_by_member_id, created_at)
                VALUES (?, NULL, 'event', ?, ?, ?, NOW())
            ");
            $stmt->bind_param("isii", $eventId, $question, $allowMultiple, $memberId);
        } else {
            $stmt = $mysqli->prepare("
                INSERT INTO ep_polls (event_id, question, allow_multiple, created_by_member_id, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->bind_param("isii", $eventId, $question, $allowMultiple, $memberId);
        }
    }
    $stmt->execute();
    $pollId = (int)$stmt->insert_id;
    $stmt->close();

    $stmt = $mysqli->prepare("
        INSERT INTO ep_poll_options (poll_id, option_text, sort_order)
        VALUES (?, ?, ?)
    ");
    foreach ($cleanOptions as $idx => $optionText) {
        $sortOrder = $idx + 1;
        $stmt->bind_param("isi", $pollId, $optionText, $sortOrder);
        $stmt->execute();
    }
    $stmt->close();

    ep_polls_json(["status" => "OK", "poll_id" => $pollId]);
}

if ($action === 'delete') {
    $pollId = (int)($data['poll_id'] ?? 0);
    if ($pollId <= 0) {
        ep_polls_json(["status" => "error", "message" => "poll_id required"]);
    }

    $pollScope = ep_polls_fetch_scope_for_poll($mysqli, $pollId);
    if (!$pollScope) {
        ep_polls_json(["status" => "error", "message" => "Poll not found"]);
    }
    $canDelete = false;
    if (($pollScope['source'] ?? 'event') === 'chat') {
        $canDelete = ep_polls_can_access_list_token($mysqli, $pollScope['list_token'] ?? '', $username, 80);
    } else {
        $canDelete = ((int)$pollScope['owner_id'] === (int)$ownerId) && $canManage;
    }
    if (!$canDelete) {
        ep_polls_json(["status" => "error", "message" => "Permission denied"]);
    }

    $stmt = $mysqli->prepare("DELETE FROM ep_poll_votes WHERE poll_id = ?");
    $stmt->bind_param("i", $pollId);
    $stmt->execute();
    $stmt->close();

    $stmt = $mysqli->prepare("DELETE FROM ep_poll_options WHERE poll_id = ?");
    $stmt->bind_param("i", $pollId);
    $stmt->execute();
    $stmt->close();

    $stmt = $mysqli->prepare("DELETE FROM ep_polls WHERE id = ?");
    $stmt->bind_param("i", $pollId);
    $stmt->execute();
    $stmt->close();

    ep_polls_json(["status" => "OK"]);
}

if ($action === 'vote') {
    $pollId = (int)($data['poll_id'] ?? 0);
    $optionIds = $data['option_ids'] ?? [];
    if ($pollId <= 0 || !is_array($optionIds)) {
        ep_polls_json(["status" => "error", "message" => "poll_id and option_ids are required"]);
    }

    $pollScope = ep_polls_fetch_scope_for_poll($mysqli, $pollId);
    if (!$pollScope) {
        ep_polls_json(["status" => "error", "message" => "Poll not found"]);
    }
    if (($pollScope['source'] ?? 'event') === 'chat') {
        if (!ep_polls_can_access_list_token($mysqli, $pollScope['list_token'] ?? '', $username, 60)) {
            ep_polls_json(["status" => "error", "message" => "Permission denied"]);
        }
    } else {
        $pollOwnerId = (int)($pollScope['owner_id'] ?? 0);
        if (!$pollOwnerId || ((int)$pollOwnerId !== (int)$memberId && !ep_polls_is_invited_to_owner($mysqli, (int)$pollOwnerId, (int)$memberId))) {
            ep_polls_json(["status" => "error", "message" => "Permission denied"]);
        }
    }

    $stmt = $mysqli->prepare("SELECT allow_multiple FROM ep_polls WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $pollId);
    $stmt->execute();
    $stmt->bind_result($allowMultiple);
    $stmt->fetch();
    $stmt->close();
    if (!$allowMultiple && count($optionIds) > 1) {
        ep_polls_json(["status" => "error", "message" => "This poll allows only one choice"]);
    }

    $rawIds = [];
    foreach ($optionIds as $id) {
        $num = (int)$id;
        if ($num > 0) $rawIds[$num] = true;
    }
    $targetOptionIds = array_keys($rawIds);

    $validOptionIds = [];
    if (count($targetOptionIds)) {
        $placeholders = implode(',', array_fill(0, count($targetOptionIds), '?'));
        $types = "i" . str_repeat('i', count($targetOptionIds));
        $params = array_merge([(int)$pollId], $targetOptionIds);
        $stmt = $mysqli->prepare("
            SELECT id
            FROM ep_poll_options
            WHERE poll_id = ? AND id IN ($placeholders)
        ");
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $validOptionIds[] = (int)$row['id'];
        }
        $stmt->close();
    }

    if (!$allowMultiple && count($validOptionIds) > 1) {
        ep_polls_json(["status" => "error", "message" => "This poll allows only one choice"]);
    }

    $stmt = $mysqli->prepare("DELETE FROM ep_poll_votes WHERE poll_id = ? AND member_id = ?");
    $stmt->bind_param("ii", $pollId, $memberId);
    $stmt->execute();
    $stmt->close();

    if (count($validOptionIds)) {
        $stmt = $mysqli->prepare("
            INSERT INTO ep_poll_votes (poll_id, option_id, member_id, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        foreach ($validOptionIds as $optionId) {
            $stmt->bind_param("iii", $pollId, $optionId, $memberId);
            $stmt->execute();
        }
        $stmt->close();
    }

    ep_polls_json(["status" => "OK"]);
}

ep_polls_json(["status" => "error", "message" => "Unsupported action"]);
