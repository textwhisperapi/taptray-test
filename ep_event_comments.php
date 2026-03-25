<?php
header('Content-Type: application/json');
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db_connect.php';

sec_session_start();
$mysqli->set_charset("utf8mb4");

$memberId = (int)($_SESSION['user_id'] ?? 0);
if ($memberId <= 0) {
    echo json_encode(["status" => "error", "message" => "Not logged in"]);
    exit;
}

function ep_comments_json($payload) {
    echo json_encode($payload);
    exit;
}

function ep_comments_can_edit_fresh(int $actorId, int $commentMemberId, string $createdAt): bool {
    if ($actorId <= 0 || $commentMemberId <= 0 || $actorId !== $commentMemberId) return false;
    $createdTs = strtotime($createdAt);
    if (!$createdTs) return false;
    $freshWindowSeconds = 15 * 60;
    return (time() - $createdTs) <= $freshWindowSeconds;
}

function ep_comments_resolve_owner_id(mysqli $mysqli, ?string $tokenOrUser, int $fallback): int {
    $raw = trim((string)$tokenOrUser);
    if ($raw === '') return $fallback;

    $ownerId = null;
    $stmt = $mysqli->prepare("SELECT owner_id FROM content_lists WHERE token = ? LIMIT 1");
    $stmt->bind_param("s", $raw);
    $stmt->execute();
    $stmt->bind_result($ownerId);
    $stmt->fetch();
    $stmt->close();
    if (!empty($ownerId)) return (int)$ownerId;

    $stmt = $mysqli->prepare("SELECT id FROM members WHERE username = ? LIMIT 1");
    $stmt->bind_param("s", $raw);
    $stmt->execute();
    $stmt->bind_result($ownerId);
    $stmt->fetch();
    $stmt->close();
    return !empty($ownerId) ? (int)$ownerId : $fallback;
}

function ep_comments_ensure_table(mysqli $mysqli): void {
    $sql = "
        CREATE TABLE IF NOT EXISTS ep_event_comments (
            id INT NOT NULL AUTO_INCREMENT,
            event_id INT NOT NULL,
            member_id INT NOT NULL,
            comment_text TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_ep_event_comments_event_created (event_id, created_at),
            KEY idx_ep_event_comments_member (member_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    $mysqli->query($sql);
}

function ep_comments_get_event(mysqli $mysqli, int $eventId): ?array {
    $stmt = $mysqli->prepare("
        SELECT id, owner_id, all_members
        FROM ep_events
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $eventId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

function ep_comments_has_access(mysqli $mysqli, int $eventId, int $eventOwnerId, int $allMembers, int $memberId, int $contextOwnerId): bool {
    if ($eventOwnerId === $memberId) return true;

    $stmt = $mysqli->prepare("
        SELECT 1
        FROM ep_event_groups eg
        JOIN ep_group_members gm ON gm.group_id = eg.group_id
        WHERE eg.event_id = ? AND gm.member_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $eventId, $memberId);
    $stmt->execute();
    $stmt->store_result();
    $viaGroup = $stmt->num_rows > 0;
    $stmt->close();
    if ($viaGroup) return true;

    if ($allMembers === 1) {
        $ownerUsername = null;
        $stmt = $mysqli->prepare("SELECT username FROM members WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $contextOwnerId);
        $stmt->execute();
        $stmt->bind_result($ownerUsername);
        $stmt->fetch();
        $stmt->close();

        if (!empty($ownerUsername)) {
            $stmt = $mysqli->prepare("
                SELECT 1
                FROM content_lists cl
                JOIN invitations i ON i.listToken = cl.token
                JOIN members m ON m.email = i.email
                WHERE cl.owner_id = ? AND cl.token = ? AND m.id = ?
                LIMIT 1
            ");
            $stmt->bind_param("isi", $contextOwnerId, $ownerUsername, $memberId);
            $stmt->execute();
            $stmt->store_result();
            $isInvited = $stmt->num_rows > 0;
            $stmt->close();
            if ($isInvited) return true;
        }
    }

    $stmt = $mysqli->prepare("
        SELECT 1
        FROM ep_checkins
        WHERE event_id = ? AND member_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $eventId, $memberId);
    $stmt->execute();
    $stmt->store_result();
    $viaCheckin = $stmt->num_rows > 0;
    $stmt->close();
    return $viaCheckin;
}

ep_comments_ensure_table($mysqli);

$rawOwner = $_GET['owner'] ?? null;
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$payload = [];
if ($method !== 'GET') {
    $payload = json_decode(file_get_contents("php://input"), true);
    if (!is_array($payload)) $payload = $_POST;
    if ($rawOwner === null) {
        $rawOwner = $payload['owner'] ?? null;
    }
}

$contextOwnerId = ep_comments_resolve_owner_id($mysqli, is_string($rawOwner) ? $rawOwner : null, $memberId);

$eventId = (int)($_GET['event_id'] ?? ($payload['event_id'] ?? 0));
if ($eventId <= 0) {
    ep_comments_json(["status" => "error", "message" => "event_id required"]);
}

$eventRow = ep_comments_get_event($mysqli, $eventId);
if (!$eventRow) {
    ep_comments_json(["status" => "error", "message" => "Event not found"]);
}

$eventOwnerId = (int)($eventRow['owner_id'] ?? 0);
$allMembers = (int)($eventRow['all_members'] ?? 0);

if (!ep_comments_has_access($mysqli, $eventId, $eventOwnerId, $allMembers, $memberId, $contextOwnerId)) {
    ep_comments_json(["status" => "error", "message" => "Permission denied"]);
}

if ($method === 'GET') {
    $stmt = $mysqli->prepare("
        SELECT c.id, c.event_id, c.member_id, c.comment_text, c.created_at,
               m.username, m.display_name, m.avatar_url
        FROM ep_event_comments c
        JOIN members m ON m.id = c.member_id
        WHERE c.event_id = ?
        ORDER BY c.created_at ASC, c.id ASC
        LIMIT 200
    ");
    $stmt->bind_param("i", $eventId);
    $stmt->execute();
    $res = $stmt->get_result();
    $comments = [];
    while ($row = $res->fetch_assoc()) {
        $comments[] = [
            "id" => (int)$row['id'],
            "event_id" => (int)$row['event_id'],
            "member_id" => (int)$row['member_id'],
            "comment" => (string)$row['comment_text'],
            "created_at" => (string)$row['created_at'],
            "username" => (string)($row['username'] ?? ''),
            "display_name" => (string)($row['display_name'] ?? ''),
            "avatar_url" => (string)($row['avatar_url'] ?? ''),
            "can_edit_fresh" => ep_comments_can_edit_fresh($memberId, (int)$row['member_id'], (string)$row['created_at'])
        ];
    }
    $stmt->close();
    ep_comments_json(["status" => "OK", "comments" => $comments]);
}

$action = strtolower(trim((string)($payload['action'] ?? 'create')));

if ($action === 'update') {
    $commentId = (int)($payload['comment_id'] ?? 0);
    $comment = trim((string)($payload['comment'] ?? ''));
    if ($commentId <= 0) {
        ep_comments_json(["status" => "error", "message" => "comment_id required"]);
    }
    if ($comment === '') {
        ep_comments_json(["status" => "error", "message" => "Comment is required"]);
    }
    if (mb_strlen($comment, 'UTF-8') > 1000) {
        ep_comments_json(["status" => "error", "message" => "Comment is too long"]);
    }

    $stmt = $mysqli->prepare("
        SELECT id, member_id, created_at
        FROM ep_event_comments
        WHERE id = ? AND event_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $commentId, $eventId);
    $stmt->execute();
    $res = $stmt->get_result();
    $existing = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$existing) {
        ep_comments_json(["status" => "error", "message" => "Comment not found"]);
    }
    if (!ep_comments_can_edit_fresh($memberId, (int)$existing['member_id'], (string)$existing['created_at'])) {
        ep_comments_json(["status" => "error", "message" => "Fresh comment edit window expired"]);
    }

    $stmt = $mysqli->prepare("
        UPDATE ep_event_comments
        SET comment_text = ?
        WHERE id = ? AND event_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("sii", $comment, $commentId, $eventId);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) {
        ep_comments_json(["status" => "error", "message" => "Unable to update comment"]);
    }

    $stmt = $mysqli->prepare("
        SELECT c.id, c.event_id, c.member_id, c.comment_text, c.created_at,
               m.username, m.display_name, m.avatar_url
        FROM ep_event_comments c
        JOIN members m ON m.id = c.member_id
        WHERE c.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $commentId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$row) {
        ep_comments_json(["status" => "error", "message" => "Unable to load updated comment"]);
    }
    ep_comments_json([
        "status" => "OK",
        "comment" => [
            "id" => (int)$row['id'],
            "event_id" => (int)$row['event_id'],
            "member_id" => (int)$row['member_id'],
            "comment" => (string)$row['comment_text'],
            "created_at" => (string)$row['created_at'],
            "username" => (string)($row['username'] ?? ''),
            "display_name" => (string)($row['display_name'] ?? ''),
            "avatar_url" => (string)($row['avatar_url'] ?? ''),
            "can_edit_fresh" => ep_comments_can_edit_fresh($memberId, (int)$row['member_id'], (string)$row['created_at'])
        ]
    ]);
}

$comment = trim((string)($payload['comment'] ?? ''));
if ($comment === '') {
    ep_comments_json(["status" => "error", "message" => "Comment is required"]);
}
if (mb_strlen($comment, 'UTF-8') > 1000) {
    ep_comments_json(["status" => "error", "message" => "Comment is too long"]);
}

$stmt = $mysqli->prepare("
    INSERT INTO ep_event_comments (event_id, member_id, comment_text)
    VALUES (?, ?, ?)
");
$stmt->bind_param("iis", $eventId, $memberId, $comment);
$ok = $stmt->execute();
$insertId = $ok ? (int)$stmt->insert_id : 0;
$stmt->close();

if (!$ok || $insertId <= 0) {
    ep_comments_json(["status" => "error", "message" => "Unable to save comment"]);
}

$stmt = $mysqli->prepare("
    SELECT c.id, c.event_id, c.member_id, c.comment_text, c.created_at,
           m.username, m.display_name, m.avatar_url
    FROM ep_event_comments c
    JOIN members m ON m.id = c.member_id
    WHERE c.id = ?
    LIMIT 1
");
$stmt->bind_param("i", $insertId);
$stmt->execute();
$res = $stmt->get_result();
$row = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$row) {
    ep_comments_json(["status" => "error", "message" => "Unable to load saved comment"]);
}

ep_comments_json([
    "status" => "OK",
    "comment" => [
        "id" => (int)$row['id'],
        "event_id" => (int)$row['event_id'],
        "member_id" => (int)$row['member_id'],
        "comment" => (string)$row['comment_text'],
        "created_at" => (string)$row['created_at'],
        "username" => (string)($row['username'] ?? ''),
        "display_name" => (string)($row['display_name'] ?? ''),
        "avatar_url" => (string)($row['avatar_url'] ?? ''),
        "can_edit_fresh" => ep_comments_can_edit_fresh($memberId, (int)$row['member_id'], (string)$row['created_at'])
    ]
]);
