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

if (!login_check($mysqli) || empty($_SESSION['user_id']) || empty($_SESSION['username'])) {
    respond(401, ['status' => 'error', 'error' => 'Unauthorized']);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = [];
if ($method !== 'GET') {
    $input = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($input)) $input = [];
}

$token = trim((string)(($method === 'GET') ? ($_GET['token'] ?? '') : ($input['token'] ?? '')));
$action = trim((string)(($method === 'GET') ? ($_GET['action'] ?? 'status') : ($input['action'] ?? 'status')));
if ($action === '') $action = 'status';

if ($token === '') {
    respond(400, ['status' => 'error', 'error' => 'Missing token']);
}
if (!preg_match('/^[A-Za-z0-9._-]{1,120}$/', $token)) {
    respond(400, ['status' => 'error', 'error' => 'Invalid token']);
}

$userId = (int)$_SESSION['user_id'];
$username = (string)$_SESSION['username'];
$sessionId = (string)session_id();

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
                if ($ownerByUsernameId === $userId) return 90;

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
                    if ($profileRank > 0) return $profileRank;
                }
            }
        }
        return 0;
    }
    if ((int)$list['owner_id'] === $userId) return 90;

    $rank = getDirectInviteRank($mysqli, $token, $userId);
    if ($rank > 0) return $rank;

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
        if ((int)$row['owner_id'] === $userId) return 90;

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
        if ((int)$parent['owner_id'] === $userId) return 90;

        $parentToken = (string)($parent['token'] ?? '');
        if ($parentToken !== '') {
            $rank = getDirectInviteRank($mysqli, $parentToken, $userId);
            if ($rank > 0) return $rank;
        }

        $currentId = $parentId;
    }

    return 0;
}

function readOwnerInfo(mysqli $mysqli, string $ownerUser): array {
    if ($ownerUser === '') return [];
    $stmt = $mysqli->prepare("SELECT username, display_name, avatar_url FROM members WHERE username = ? LIMIT 1");
    if (!$stmt) return ['username' => $ownerUser, 'display_name' => $ownerUser, 'avatar_url' => ''];
    $stmt->bind_param('s', $ownerUser);
    $stmt->execute();
    $stmt->bind_result($u, $display, $avatar);
    $ok = $stmt->fetch();
    $stmt->close();
    if (!$ok) return ['username' => $ownerUser, 'display_name' => $ownerUser, 'avatar_url' => ''];
    return [
        'username' => (string)$u,
        'display_name' => (string)($display ?: $u),
        'avatar_url' => (string)($avatar ?: '')
    ];
}

function resolveOwnerIdFromToken(mysqli $mysqli, string $token): int {
    $stmt = $mysqli->prepare("SELECT id FROM members WHERE username = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $stmt->bind_result($memberId);
        $ok = $stmt->fetch();
        $stmt->close();
        if ($ok && $memberId) {
            return (int)$memberId;
        }
    }

    $stmt = $mysqli->prepare("SELECT owner_id FROM content_lists WHERE token = ? LIMIT 1");
    if (!$stmt) return 0;
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $stmt->bind_result($ownerId);
    $ok = $stmt->fetch();
    $stmt->close();
    return $ok ? (int)$ownerId : 0;
}

function listAdminCandidates(mysqli $mysqli, string $token): array {
    $out = [];
    $ownerId = resolveOwnerIdFromToken($mysqli, $token);
    $isProfileToken = false;

    $stmt = $mysqli->prepare("SELECT id FROM members WHERE username = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $stmt->bind_result($profileId);
        $isProfileToken = (bool)$stmt->fetch();
        $stmt->close();
    }

    if ($ownerId > 0) {
        $stmt = $mysqli->prepare("SELECT username, display_name, avatar_url FROM members WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $ownerId);
            $stmt->execute();
            $stmt->bind_result($u, $display, $avatar);
            if ($stmt->fetch()) {
                $out[$u] = [
                    'username' => (string)$u,
                    'display_name' => (string)($display ?: $u),
                    'avatar_url' => (string)($avatar ?: '')
                ];
            }
            $stmt->close();
        }
    }

    if ($isProfileToken && $ownerId > 0) {
        $stmt = $mysqli->prepare(
            "SELECT DISTINCT m.username, m.display_name, m.avatar_url
             FROM invitations i
             JOIN members m ON m.email = i.email
             WHERE i.listToken = ? AND i.role_rank >= 80"
        );
        if ($stmt) {
            $stmt->bind_param('s', $token);
            $stmt->execute();
            $stmt->bind_result($u, $display, $avatar);
            while ($stmt->fetch()) {
                $out[$u] = [
                    'username' => (string)$u,
                    'display_name' => (string)($display ?: $u),
                    'avatar_url' => (string)($avatar ?: '')
                ];
            }
            $stmt->close();
        }
    } else {
        $stmt = $mysqli->prepare(
            "SELECT DISTINCT m.username, m.display_name, m.avatar_url
             FROM invitations i
             JOIN members m ON m.email = i.email
             WHERE i.listToken = ? AND i.role_rank >= 80"
        );
        if ($stmt) {
            $stmt->bind_param('s', $token);
            $stmt->execute();
            $stmt->bind_result($u, $display, $avatar);
            while ($stmt->fetch()) {
                $out[$u] = [
                    'username' => (string)$u,
                    'display_name' => (string)($display ?: $u),
                    'avatar_url' => (string)($avatar ?: '')
                ];
            }
            $stmt->close();
        }
    }

    return array_values($out);
}

$roleRank = resolveRoleRank($mysqli, $token, $userId, $username);
if ($roleRank <= 0) {
    respond(403, ['status' => 'error', 'error' => 'Access denied']);
}
$canControl = $roleRank >= 80;

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

$now = (int)floor(microtime(true) * 1000);
$leaseMs = 15000;

$stmt = $mysqli->prepare(
    "SELECT owner_user, owner_session, lease_expires_at
     FROM tw_play_state
     WHERE token = ?
     LIMIT 1"
);
if (!$stmt) {
    respond(500, ['status' => 'error', 'error' => 'Storage query failed']);
}
$stmt->bind_param('s', $token);
$stmt->execute();
$stmt->bind_result($ownerUserDb, $ownerSessionDb, $leaseExpiresDb);
$hasRow = $stmt->fetch();
$stmt->close();

$ownerUser = $hasRow ? (string)($ownerUserDb ?? '') : '';
$ownerSession = $hasRow ? (string)($ownerSessionDb ?? '') : '';
$leaseExpires = $hasRow ? (int)($leaseExpiresDb ?? 0) : 0;
$ownerActive = ($ownerUser !== '' && $leaseExpires > $now);

if ($action === 'status') {
    respond(200, [
        'status' => 'ok',
        'token' => $token,
        'can_control' => $canControl,
        'is_owner' => ($ownerActive && $ownerUser === $username),
        'owner' => $ownerActive ? readOwnerInfo($mysqli, $ownerUser) : null,
        'lease_expires_at' => $ownerActive ? $leaseExpires : 0
    ]);
}

if ($action === 'list_admins') {
    respond(200, [
        'status' => 'ok',
        'token' => $token,
        'can_control' => $canControl,
        'is_owner' => ($ownerActive && $ownerUser === $username),
        'owner' => $ownerActive ? readOwnerInfo($mysqli, $ownerUser) : null,
        'admins' => listAdminCandidates($mysqli, $token)
    ]);
}

if ($method !== 'POST') {
    respond(405, ['status' => 'error', 'error' => 'Method not allowed']);
}

if (!$canControl && in_array($action, ['claim', 'takeover', 'heartbeat', 'release', 'assign'], true)) {
    respond(403, ['status' => 'error', 'error' => 'Only admins can control play mode']);
}

if ($action === 'claim' || $action === 'takeover') {
    $force = ($action === 'takeover') || !empty($input['takeover']);

    $mysqli->begin_transaction();
    try {
        $seed = $mysqli->prepare(
            "INSERT INTO tw_play_state (token, updated_at)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE updated_at = updated_at"
        );
        if (!$seed) throw new Exception('seed failed');
        $seed->bind_param('si', $token, $now);
        $seed->execute();
        $seed->close();

        $lock = $mysqli->prepare(
            "SELECT owner_user, owner_session, lease_expires_at
             FROM tw_play_state
             WHERE token = ?
             LIMIT 1
             FOR UPDATE"
        );
        if (!$lock) throw new Exception('lock failed');
        $lock->bind_param('s', $token);
        $lock->execute();
        $lock->bind_result($lOwnerUser, $lOwnerSession, $lLeaseExpires);
        $lock->fetch();
        $lock->close();

        $lOwnerUser = (string)($lOwnerUser ?? '');
        $lLeaseExpires = (int)($lLeaseExpires ?? 0);
        $lOwnerActive = ($lOwnerUser !== '' && $lLeaseExpires > $now);
        if ($lOwnerActive && $lOwnerUser !== $username && !$force) {
            $mysqli->rollback();
            respond(409, [
                'status' => 'error',
                'error' => 'Play mode already owned',
                'owner' => readOwnerInfo($mysqli, $lOwnerUser)
            ]);
        }

        $up = $mysqli->prepare(
            "UPDATE tw_play_state
             SET owner_user = ?, owner_session = ?, lease_expires_at = ?, owner_updated_at = ?, updated_at = ?
             WHERE token = ?"
        );
        if (!$up) throw new Exception('update failed');
        $leaseTo = $now + $leaseMs;
        $up->bind_param('ssiiis', $username, $sessionId, $leaseTo, $now, $now, $token);
        $up->execute();
        $up->close();
        $mysqli->commit();
    } catch (Throwable $e) {
        $mysqli->rollback();
        respond(500, ['status' => 'error', 'error' => 'Failed to claim play mode']);
    }

    respond(200, [
        'status' => 'ok',
        'claimed' => true,
        'token' => $token,
        'can_control' => true,
        'is_owner' => true,
        'owner' => readOwnerInfo($mysqli, $username),
        'lease_expires_at' => $now + $leaseMs
    ]);
}

if ($action === 'heartbeat') {
    $up = $mysqli->prepare(
        "UPDATE tw_play_state
         SET owner_session = ?, lease_expires_at = ?, owner_updated_at = ?, updated_at = ?
         WHERE token = ? AND owner_user = ? AND (owner_session = '' OR owner_session = ? OR owner_session IS NULL) AND lease_expires_at > ?"
    );
    if (!$up) {
        respond(500, ['status' => 'error', 'error' => 'Failed to extend play mode']);
    }
    $leaseTo = $now + $leaseMs;
    $up->bind_param('siiisssi', $sessionId, $leaseTo, $now, $now, $token, $username, $sessionId, $now);
    $up->execute();
    $affected = (int)$up->affected_rows;
    $up->close();
    if ($affected <= 0) {
        respond(409, ['status' => 'error', 'error' => 'Not current play owner']);
    }

    respond(200, ['status' => 'ok', 'is_owner' => true, 'lease_expires_at' => $now + $leaseMs]);
}

if ($action === 'release') {
    $del = $mysqli->prepare(
        "UPDATE tw_play_state
         SET owner_user = NULL, owner_session = NULL, lease_expires_at = 0, owner_updated_at = ?, updated_at = ?
         WHERE token = ? AND owner_user = ? AND (owner_session = '' OR owner_session = ? OR owner_session IS NULL)"
    );
    if ($del) {
        $del->bind_param('iisss', $now, $now, $token, $username, $sessionId);
        $del->execute();
        $del->close();
    }
    respond(200, ['status' => 'ok', 'released' => true]);
}

if ($action === 'assign') {
    $targetUser = trim((string)($input['target_user'] ?? ''));
    if ($targetUser === '' || !preg_match('/^[A-Za-z0-9._-]{1,120}$/', $targetUser)) {
        respond(400, ['status' => 'error', 'error' => 'Invalid target user']);
    }

    $admins = listAdminCandidates($mysqli, $token);
    $allowed = false;
    foreach ($admins as $a) {
        if (($a['username'] ?? '') === $targetUser) {
            $allowed = true;
            break;
        }
    }
    if (!$allowed) {
        respond(403, ['status' => 'error', 'error' => 'Target is not an admin']);
    }

    $assign = $mysqli->prepare(
        "INSERT INTO tw_play_state (token, owner_user, owner_session, lease_expires_at, owner_updated_at, updated_at)
         VALUES (?, ?, '', ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           owner_user = VALUES(owner_user),
           owner_session = '',
           lease_expires_at = VALUES(lease_expires_at),
           owner_updated_at = VALUES(owner_updated_at),
           updated_at = VALUES(updated_at)"
    );
    if (!$assign) {
        respond(500, ['status' => 'error', 'error' => 'Failed to assign play mode owner']);
    }
    $leaseTo = $now + $leaseMs;
    $assign->bind_param('ssiii', $token, $targetUser, $leaseTo, $now, $now);
    $assign->execute();
    $assign->close();

    respond(200, [
        'status' => 'ok',
        'assigned' => true,
        'token' => $token,
        'can_control' => true,
        'is_owner' => ($targetUser === $username),
        'owner' => readOwnerInfo($mysqli, $targetUser),
        'lease_expires_at' => $now + $leaseMs
    ]);
}

respond(400, ['status' => 'error', 'error' => 'Unknown action']);
