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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['status' => 'error', 'error' => 'Method not allowed']);
}

if (!login_check($mysqli) || empty($_SESSION['user_id']) || empty($_SESSION['username'])) {
    respond(401, ['status' => 'error', 'error' => 'Unauthorized']);
}

$input = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($input)) {
    respond(400, ['status' => 'error', 'error' => 'Invalid JSON payload']);
}

$token = trim((string)($input['token'] ?? ''));
$surrogate = trim((string)($input['surrogate'] ?? ''));
$itemToken = trim((string)($input['item_token'] ?? ''));
$listOpenRaw = $input['list_open'] ?? null;
$eventType = trim((string)($input['event_type'] ?? ''));
$pageNumRaw = $input['page_num'] ?? null;
$pageMode = trim((string)($input['page_mode'] ?? ''));
$publisherClientId = trim((string)($input['publisher_client_id'] ?? ''));
$hasListOpen = is_bool($listOpenRaw) || $listOpenRaw === 0 || $listOpenRaw === 1 || $listOpenRaw === '0' || $listOpenRaw === '1';
$listOpen = null;
if ($hasListOpen) {
    $listOpen = filter_var($listOpenRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
}
$pageNum = 0;
if ($pageNumRaw !== null && $pageNumRaw !== '') {
    $pageNum = (int)$pageNumRaw;
}

if ($token === '') {
    respond(400, ['status' => 'error', 'error' => 'Missing token']);
}

if (!preg_match('/^[A-Za-z0-9._-]{1,120}$/', $token)) {
    respond(400, ['status' => 'error', 'error' => 'Invalid token']);
}

if ($surrogate === '0') {
    $surrogate = '';
}

if ($surrogate !== '' && !preg_match('/^[A-Za-z0-9._-]{1,120}$/', $surrogate)) {
    respond(400, ['status' => 'error', 'error' => 'Invalid surrogate']);
}

if ($itemToken !== '' && !preg_match('/^[A-Za-z0-9._-]{1,120}$/', $itemToken)) {
    respond(400, ['status' => 'error', 'error' => 'Invalid item token']);
}
if ($publisherClientId !== '' && !preg_match('/^[A-Za-z0-9._-]{6,120}$/', $publisherClientId)) {
    respond(400, ['status' => 'error', 'error' => 'Invalid publisher client id']);
}

if ($surrogate === '' && $itemToken === '') {
    respond(400, ['status' => 'error', 'error' => 'Missing selection data']);
}

$allowedEventTypes = ['selection', 'list', 'page', 'annotation'];
if ($eventType === '') {
    $eventType = ($surrogate === '') ? 'list' : 'selection';
}
if (!in_array($eventType, $allowedEventTypes, true)) {
    respond(400, ['status' => 'error', 'error' => 'Invalid event type']);
}

if ($pageNum < 0 || $pageNum > 5000) {
    respond(400, ['status' => 'error', 'error' => 'Invalid page number']);
}
if ($eventType === 'page' && $pageNum <= 0) {
    respond(400, ['status' => 'error', 'error' => 'Missing page number']);
}
if ($eventType !== 'page') {
    $pageNum = 0;
}

if ($pageMode !== '' && $pageMode !== 'paged' && $pageMode !== 'continuous') {
    respond(400, ['status' => 'error', 'error' => 'Invalid page mode']);
}
if ($eventType !== 'page') {
    $pageMode = '';
}

$userId = (int)$_SESSION['user_id'];
$username = (string)$_SESSION['username'];

function enforcePublishRateLimit(string $token, string $username, string $eventType): bool {
    // Keep item/list control events responsive; throttle only high-frequency paging chatter.
    if ($eventType !== 'page') {
        return true;
    }

    $dir = sys_get_temp_dir() . '/tw_play_rate';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return true;
    }

    $path = $dir . '/' . hash('sha256', $token . '|' . $username . '|page') . '.json';
    $now = (int)floor(microtime(true) * 1000);
    $windowMs = 2000;
    $maxEvents = 80;

    $raw = is_file($path) ? @file_get_contents($path) : '';
    $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
    if (!is_array($data)) {
        $data = [];
    }

    $fresh = [];
    foreach ($data as $ts) {
        $v = (int)$ts;
        if ($v > 0 && ($now - $v) < $windowMs) {
            $fresh[] = $v;
        }
    }

    if (count($fresh) >= $maxEvents) {
        return false;
    }

    $fresh[] = $now;
    @file_put_contents($path, json_encode($fresh, JSON_UNESCAPED_SLASHES), LOCK_EX);
    return true;
}

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
            "SELECT id, parent_id, owner_id, token
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
if ($roleRank < 80) {
    respond(403, ['status' => 'error', 'error' => 'Insufficient permissions']);
}

if (!enforcePublishRateLimit($token, $username, $eventType)) {
    header('Retry-After: 1');
    respond(429, ['status' => 'error', 'error' => 'Too many page sync updates']);
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

$lockNow = (int)floor(microtime(true) * 1000);
$leaseMs = 15000;
$updatedAt = $lockNow;
$payload = [
    'token' => $token,
    'item_token' => ($itemToken !== '' ? $itemToken : $token),
    'surrogate' => $surrogate,
    'event_type' => $eventType,
    'list_open' => $listOpen,
    'page_num' => $pageNum > 0 ? $pageNum : null,
    'page_mode' => $pageMode !== '' ? $pageMode : null,
    'publisher_client_id' => $publisherClientId !== '' ? $publisherClientId : null,
    'published_by' => $username,
    'updated_at' => $updatedAt,
];

$json = json_encode($payload, JSON_UNESCAPED_SLASHES);
if ($json === false) {
    respond(500, ['status' => 'error', 'error' => 'Failed to save sync state']);
}

$mysqli->begin_transaction();
try {
    $seed = $mysqli->prepare(
        "INSERT INTO tw_play_state (token, updated_at)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE updated_at = updated_at"
    );
    if (!$seed) throw new Exception('seed failed');
    $seed->bind_param('si', $token, $lockNow);
    $seed->execute();
    $seed->close();

    $lockStmt = $mysqli->prepare(
        "SELECT owner_user, lease_expires_at, sync_updated_at
         FROM tw_play_state
         WHERE token = ?
         LIMIT 1
         FOR UPDATE"
    );
    if (!$lockStmt) throw new Exception('lock failed');
    $lockStmt->bind_param('s', $token);
    $lockStmt->execute();
    $lockStmt->bind_result($lockOwner, $lockExpires, $prevSyncTs);
    $lockStmt->fetch();
    $lockStmt->close();

    $lockOwner = (string)($lockOwner ?? '');
    $lockExpires = (int)($lockExpires ?? 0);
    $prevSyncTs = (int)($prevSyncTs ?? 0);
    if ($lockOwner !== '' && $lockExpires > $lockNow && $lockOwner !== $username) {
        $mysqli->rollback();
        respond(409, [
            'status' => 'error',
            'error' => 'Play mode owned by another admin',
            'owner_user' => $lockOwner
        ]);
    }

    if ($updatedAt <= $prevSyncTs) {
        $updatedAt = $prevSyncTs + 1;
        $payload['updated_at'] = $updatedAt;
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new Exception('json encode failed');
        }
    }

    $leaseTo = $lockNow + $leaseMs;
    $sessionId = session_id();
    $up = $mysqli->prepare(
        "UPDATE tw_play_state
         SET owner_user = ?, owner_session = ?, lease_expires_at = ?, owner_updated_at = ?,
             sync_json = ?, sync_updated_at = ?, updated_at = ?
         WHERE token = ?"
    );
    if (!$up) throw new Exception('update failed');
    $up->bind_param('ssiisiis', $username, $sessionId, $leaseTo, $lockNow, $json, $updatedAt, $updatedAt, $token);
    $up->execute();
    $up->close();

    $mysqli->commit();
} catch (Throwable $e) {
    $mysqli->rollback();
    respond(500, ['status' => 'error', 'error' => 'Failed to save sync state']);
}

respond(200, [
    'status' => 'ok',
    'updated_at' => $updatedAt,
]);
