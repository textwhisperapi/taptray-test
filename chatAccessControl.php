<?php
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/functions.php';

/**
 * Checks user access level for a list.
 *
 * @param mysqli $mysqli
 * @param string $token
 * @param string $username
 * @param int $user_id
 * @return array ['status' => 'owner'|'invited'|'denied', 'role' => string (if invited)]
 */

function chat_lookup_member_identity(mysqli $mysqli, string $username): ?array {
    if ($username === '') return null;
    $stmt = $mysqli->prepare("SELECT id, email FROM members WHERE username = ? LIMIT 1");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->bind_result($userId, $email);
    $ok = $stmt->fetch();
    $stmt->close();
    if (!$ok || (int)$userId <= 0) return null;
    return ['id' => (int)$userId, 'email' => (string)($email ?? '')];
}

function chat_lookup_list_row(mysqli $mysqli, string $token): ?array {
    if ($token === '') return null;
    $stmt = $mysqli->prepare("
        SELECT cl.id, cl.parent_id, cl.owner_id, m.username AS owner_username
        FROM content_lists cl
        JOIN members m ON m.id = cl.owner_id
        WHERE cl.token = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$row) return null;
    return [
        'id' => (int)($row['id'] ?? 0),
        'parent_id' => (int)($row['parent_id'] ?? 0),
        'owner_id' => (int)($row['owner_id'] ?? 0),
        'owner_username' => (string)($row['owner_username'] ?? '')
    ];
}

function chat_invite_rank_for_token(mysqli $mysqli, string $token, int $userId, string $email): int {
    if ($token === '' || $userId <= 0) return 0;
    $stmt = $mysqli->prepare("
        SELECT COALESCE(i.role_rank, 0) AS role_rank
        FROM invitations i
        WHERE i.listToken = ?
          AND (i.member_id = ? OR i.email = ?)
        LIMIT 1
    ");
    $stmt->bind_param("sis", $token, $userId, $email);
    $stmt->execute();
    $stmt->bind_result($roleRank);
    $ok = $stmt->fetch();
    $stmt->close();
    return $ok ? (int)$roleRank : 0;
}

function chat_has_direct_access(mysqli $mysqli, string $token, int $userId, string $username, string $email, int $minRank): bool {
    if ($token === '' || $userId <= 0) return false;
    if ($token === $username) return true; // owner "All Content" token

    $list = chat_lookup_list_row($mysqli, $token);
    if (!$list) return false;
    if ((int)$list['owner_id'] === $userId) return true;

    if (chat_invite_rank_for_token($mysqli, $token, $userId, $email) >= $minRank) {
        return true;
    }

    $ownerToken = (string)$list['owner_username'];
    if ($ownerToken !== '' && chat_invite_rank_for_token($mysqli, $ownerToken, $userId, $email) >= $minRank) {
        return true;
    }

    return false;
}

function chat_can_access_list_token(mysqli $mysqli, string $token, string $username, int $minRank = 60): bool {
    if ($token === '' || $username === '') return false;
    $member = chat_lookup_member_identity($mysqli, $username);
    if (!$member) return false;
    $userId = (int)$member['id'];
    $email = (string)$member['email'];

    if (chat_has_direct_access($mysqli, $token, $userId, $username, $email, $minRank)) {
        return true;
    }

    $start = chat_lookup_list_row($mysqli, $token);
    if (!$start) return false;

    $currentId = (int)$start['id'];
    $guard = 0;
    while ($currentId > 0 && $guard++ < 30) {
        $stmt = $mysqli->prepare("SELECT parent_id, token FROM content_lists WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $currentId);
        $stmt->execute();
        $stmt->bind_result($parentId, $parentToken);
        $ok = $stmt->fetch();
        $stmt->close();
        if (!$ok || (int)$parentId <= 0) break;
        $parentToken = (string)($parentToken ?? '');
        if ($parentToken !== '' && chat_has_direct_access($mysqli, $parentToken, $userId, $username, $email, $minRank)) {
            return true;
        }
        $currentId = (int)$parentId;
    }

    return false;
}

function getUserAccessStatus($mysqli, $token, $username): array {
    $token = (string)($token ?? '');
    $username = (string)($username ?? '');
    if ($token === '' || $username === '') {
        error_log("🔐 Access denied: missing token/username");
        return ['status' => 'denied'];
    }

    $member = chat_lookup_member_identity($mysqli, $username);
    if (!$member) {
        error_log("🔐 Access denied: user lookup failed");
        return ['status' => 'denied'];
    }

    if (!chat_can_access_list_token($mysqli, $token, $username, 60)) {
        error_log("🔐 Access denied: no chat access");
        return ['status' => 'denied'];
    }

    $list = chat_lookup_list_row($mysqli, $token);
    if ($token === $username || ((int)($list['owner_id'] ?? 0) === (int)$member['id'])) {
        return ['status' => 'owner'];
    }

    return ['status' => 'invited'];
}
