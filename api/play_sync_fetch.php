<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/functions.php';

sec_session_start();

function respond(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(405, ['status' => 'error', 'error' => 'Method not allowed']);
}

if (!login_check($mysqli) || empty($_SESSION['user_id']) || empty($_SESSION['username'])) {
    respond(401, ['status' => 'error', 'error' => 'Unauthorized']);
}

$token = trim((string)($_GET['token'] ?? ''));
$since = (int)($_GET['since'] ?? 0);

if ($token === '') {
    respond(400, ['status' => 'error', 'error' => 'Missing token']);
}

if (!preg_match('/^[A-Za-z0-9._-]{1,120}$/', $token)) {
    respond(400, ['status' => 'error', 'error' => 'Invalid token']);
}

$userId = (int)$_SESSION['user_id'];
$username = (string)$_SESSION['username'];

function getDirectInviteRank(mysqli $mysqli, string $token, int $userId): int {
    $stmt = $mysqli->prepare(
        "SELECT i.role_rank
         FROM invitations i
         JOIN members m ON m.email = i.email
         WHERE i.listToken = ? AND m.id = ?
         LIMIT 1"
    );
    if (!$stmt) return 0;
    $stmt->bind_param('si', $token, $userId);
    $stmt->execute();
    $stmt->bind_result($rank);
    $ok = $stmt->fetch();
    $stmt->close();
    return $ok ? (int)$rank : 0;
}

function resolveRoleRank(mysqli $mysqli, string $token, int $userId, string $username): int {
    if ($token === $username) {
        return 90;
    }

    $stmt = $mysqli->prepare(
        "SELECT id, parent_id, owner_id
         FROM content_lists
         WHERE token = ?
         LIMIT 1"
    );
    if (!$stmt) return 0;
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $list = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$list) {
        $stmt = $mysqli->prepare("SELECT id FROM members WHERE username = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $token);
            $stmt->execute();
            $stmt->bind_result($ownerByUsernameId);
            $hasOwnerByUsername = $stmt->fetch();
            $stmt->close();

            if ($hasOwnerByUsername) {
                $ownerByUsernameId = (int)$ownerByUsernameId;
                if ($ownerByUsernameId === $userId) {
                    return 90;
                }

                $stmt = $mysqli->prepare(
                    "SELECT MAX(i.role_rank)
                     FROM invitations i
                     JOIN members m ON m.email = i.email
                     JOIN content_lists cl ON cl.token = i.listToken
                     WHERE m.id = ? AND cl.owner_id = ?"
                );
                if ($stmt) {
                    $stmt->bind_param('ii', $userId, $ownerByUsernameId);
                    $stmt->execute();
                    $stmt->bind_result($profileRank);
                    $stmt->fetch();
                    $stmt->close();
                    $profileRank = (int)($profileRank ?? 0);
                    if ($profileRank > 0) {
                        return $profileRank;
                    }
                }
            }
        }
        return 0;
    }

    if ((int)$list['owner_id'] === $userId) {
        return 90;
    }

    $rank = getDirectInviteRank($mysqli, $token, $userId);
    if ($rank > 0) {
        return $rank;
    }

    $currentId = (int)$list['id'];
    $safety = 0;

    while ($currentId && $safety++ < 30) {
        $stmt = $mysqli->prepare(
            "SELECT id, parent_id, owner_id
             FROM content_lists
             WHERE id = ?
             LIMIT 1"
        );
        if (!$stmt) break;
        $stmt->bind_param('i', $currentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) break;
        if ((int)$row['owner_id'] === $userId) {
            return 90;
        }

        $parentId = (int)($row['parent_id'] ?? 0);
        if ($parentId <= 0) break;

        $stmt = $mysqli->prepare(
            "SELECT token, owner_id
             FROM content_lists
             WHERE id = ?
             LIMIT 1"
        );
        if (!$stmt) break;
        $stmt->bind_param('i', $parentId);
        $stmt->execute();
        $parent = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$parent) break;
        if ((int)$parent['owner_id'] === $userId) {
            return 90;
        }

        $parentToken = (string)($parent['token'] ?? '');
        if ($parentToken !== '') {
            $rank = getDirectInviteRank($mysqli, $parentToken, $userId);
            if ($rank > 0) {
                return $rank;
            }
        }

        $currentId = $parentId;
    }

    return 0;
}

$roleRank = resolveRoleRank($mysqli, $token, $userId, $username);
if ($roleRank <= 0) {
    respond(403, ['status' => 'error', 'error' => 'Access denied']);
}

$storeReady = $mysqli->query(
    "CREATE TABLE IF NOT EXISTS tw_play_state (
        token VARCHAR(120) NOT NULL PRIMARY KEY,
        owner_user VARCHAR(120) NULL,
        owner_session VARCHAR(191) NULL,
        lease_expires_at BIGINT NOT NULL DEFAULT 0,
        owner_updated_at BIGINT NOT NULL DEFAULT 0,
        sync_json LONGTEXT NULL,
        sync_updated_at BIGINT NOT NULL DEFAULT 0,
        updated_at BIGINT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
if (!$storeReady) {
    $tableProbe = $mysqli->query("SELECT 1 FROM tw_play_state LIMIT 1");
    if (!$tableProbe) {
        respond(500, ['status' => 'error', 'error' => 'Failed to prepare play state storage']);
    }
}

$stmt = $mysqli->prepare(
    "SELECT sync_json, sync_updated_at
     FROM tw_play_state
     WHERE token = ?
     LIMIT 1"
);
if (!$stmt) {
    respond(500, ['status' => 'error', 'error' => 'Storage query failed']);
}
$stmt->bind_param('s', $token);
$stmt->execute();
$stmt->bind_result($syncJson, $syncUpdatedAtDb);
$hasRow = $stmt->fetch();
$stmt->close();

if (!$hasRow || !is_string($syncJson) || $syncJson === '') {
    respond(200, ['status' => 'ok', 'changed' => false]);
}

$data = json_decode($syncJson, true);
if (!is_array($data)) {
    respond(200, ['status' => 'ok', 'changed' => false]);
}

$updatedAt = (int)($data['updated_at'] ?? (int)$syncUpdatedAtDb);
if ($since > 0 && $updatedAt > 0 && $updatedAt <= $since) {
    respond(200, ['status' => 'ok', 'changed' => false]);
}

respond(200, [
    'status' => 'ok',
    'changed' => true,
    'token' => (string)($data['token'] ?? $token),
    'item_token' => (string)($data['item_token'] ?? ($data['token'] ?? $token)),
    'surrogate' => (string)($data['surrogate'] ?? ''),
    'event_type' => (string)($data['event_type'] ?? ((string)($data['surrogate'] ?? '') !== '' ? 'selection' : 'list')),
    'list_open' => array_key_exists('list_open', $data) ? (bool)$data['list_open'] : null,
    'page_num' => (int)($data['page_num'] ?? 0),
    'page_mode' => (string)($data['page_mode'] ?? ''),
    'publisher_client_id' => (string)($data['publisher_client_id'] ?? ''),
    'published_by' => (string)($data['published_by'] ?? ''),
    'updated_at' => $updatedAt,
]);
