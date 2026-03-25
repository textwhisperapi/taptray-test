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

function ep_group_owner($mysqli, $groupId) {
    $stmt = $mysqli->prepare("SELECT owner_id FROM ep_groups WHERE id = ?");
    $stmt->bind_param("i", $groupId);
    $stmt->execute();
    $stmt->bind_result($ownerId);
    $stmt->fetch();
    $stmt->close();
    return $ownerId ?: null;
}

function ep_owner_username($mysqli, $ownerId) {
    $stmt = $mysqli->prepare("SELECT username FROM members WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $ownerId);
    $stmt->execute();
    $stmt->bind_result($username);
    $stmt->fetch();
    $stmt->close();
    return $username ?: "";
}

function ep_all_members_group_id($mysqli, $ownerId) {
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
    if ($groupId) return $groupId;

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
    return $legacyId ?: null;
}

function ep_is_all_members_group($mysqli, $groupId) {
    $stmt = $mysqli->prepare("SELECT is_all_members FROM ep_groups WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $groupId);
    $stmt->execute();
    $stmt->bind_result($isAllMembers);
    $stmt->fetch();
    $stmt->close();
    return (int)$isAllMembers === 1;
}

function ep_sync_all_members_group($mysqli, $groupId, $ownerId) {
    $ownerUsername = ep_owner_username($mysqli, $ownerId);
    if ($ownerUsername === "") return;

    $stmt = $mysqli->prepare("
        INSERT INTO ep_group_members (group_id, member_id, role)
        SELECT ?, m.id, NULL
        FROM content_lists cl
        JOIN invitations i ON i.listToken = cl.token
        JOIN members m ON m.email = i.email
        WHERE cl.owner_id = ? AND cl.token = ?
          AND NOT EXISTS (
            SELECT 1 FROM ep_group_members gm
            WHERE gm.group_id = ? AND gm.member_id = m.id
          )
    ");
    $stmt->bind_param("iisi", $groupId, $ownerId, $ownerUsername, $groupId);
    $stmt->execute();
    $stmt->close();

    $stmt = $mysqli->prepare("
        INSERT INTO ep_group_members (group_id, member_id)
        SELECT ?, ?
        WHERE NOT EXISTS (
          SELECT 1 FROM ep_group_members gm
          WHERE gm.group_id = ? AND gm.member_id = ?
        )
    ");
    $stmt->bind_param("iiii", $groupId, $ownerId, $groupId, $ownerId);
    $stmt->execute();
    $stmt->close();

    $stmt = $mysqli->prepare("
        DELETE gm
        FROM ep_group_members gm
        LEFT JOIN (
            SELECT m.id AS member_id
            FROM content_lists cl
            JOIN invitations i ON i.listToken = cl.token
            JOIN members m ON m.email = i.email
            WHERE cl.owner_id = ? AND cl.token = ?
            UNION SELECT ?
        ) allowed ON allowed.member_id = gm.member_id
        WHERE gm.group_id = ? AND allowed.member_id IS NULL
    ");
    $stmt->bind_param("isii", $ownerId, $ownerUsername, $ownerId, $groupId);
    $stmt->execute();
    $stmt->close();
}

function ep_group_belongs_to_owner($mysqli, $groupId, $ownerId) {
    $groupOwner = ep_group_owner($mysqli, $groupId);
    return $groupOwner && (int)$groupOwner === (int)$ownerId;
}

function ep_json($payload) {
    echo json_encode($payload);
    exit;
}

$rawOwner = $_GET['owner'] ?? null;
$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data)) {
    $data = $_POST;
}
$rawOwner = $rawOwner ?? ($data['owner'] ?? null);
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
$ownerUsername = ep_owner_username($mysqli, $ownerId);
$activeListToken = ep_normalize_owner_token($rawOwner ?: $ownerUsername);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $groupId = (int)($_GET['group_id'] ?? 0);
    if ($groupId <= 0) {
        ep_json(["status" => "error", "message" => "group_id required"]);
    }
    if (!ep_group_belongs_to_owner($mysqli, $groupId, $ownerId)) {
        ep_json(["status" => "error", "message" => "Permission denied"]);
    }
    $isAllMembersGroup = ep_is_all_members_group($mysqli, $groupId);
    if ($canManage && $isAllMembersGroup) {
        ep_sync_all_members_group($mysqli, $groupId, $ownerId);
    }

    if ($isAllMembersGroup) {
        $stmt = $mysqli->prepare("
            SELECT m.id AS member_id, m.username, m.display_name, m.avatar_url, m.email,
                   gm.role, gm.joined_at,
                   CASE
                       WHEN m.id = ? THEN 'owner'
                       ELSE COALESCE(i.role, 'viewer')
                   END AS access_role
            FROM ep_group_members gm
            JOIN members m ON m.id = gm.member_id
            LEFT JOIN invitations i
              ON i.listToken = ? AND i.email = m.email
            WHERE gm.group_id = ?
            ORDER BY
              CASE
                WHEN m.id = ? THEN 0
                WHEN COALESCE(i.role, 'viewer') = 'admin' THEN 1
                ELSE 2
              END,
              m.display_name ASC,
              m.username ASC
        ");
        $stmt->bind_param("isii", $ownerId, $ownerUsername, $groupId, $ownerId);
    } else {
        $stmt = $mysqli->prepare("
            SELECT m.id AS member_id, m.username, m.display_name, m.avatar_url, m.email,
                   gm.role, gm.joined_at
            FROM ep_group_members gm
            JOIN members m ON m.id = gm.member_id
            WHERE gm.group_id = ?
            ORDER BY m.display_name ASC, m.username ASC
        ");
        $stmt->bind_param("i", $groupId);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $members = [];
    while ($row = $res->fetch_assoc()) {
        $members[] = $row;
    }
    $stmt->close();

    $memberGroups = [];
    $memberIds = array_values(array_filter(array_map(function ($m) {
        return (int)($m['member_id'] ?? 0);
    }, $members)));
    if ($memberIds) {
        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $sql = "
            SELECT gm.member_id, g.id, g.name, g.color, g.is_all_members,
                   CASE WHEN g.is_all_members = 1 THEN 0 ELSE g.is_role_group END AS is_role_group
            FROM ep_group_members gm
            JOIN ep_groups g ON g.id = gm.group_id
            WHERE gm.member_id IN ($placeholders)
              AND g.owner_id = ?
            ORDER BY g.is_all_members DESC, g.sort_order IS NULL, g.sort_order ASC, g.name ASC
        ";
        $stmt = $mysqli->prepare($sql);
        $types = str_repeat("i", count($memberIds) + 1);
        $values = $memberIds;
        $values[] = $ownerId;
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $memberId = (int)($row['member_id'] ?? 0);
            if (!$memberId) continue;
            if (!isset($memberGroups[$memberId])) $memberGroups[$memberId] = [];
            $memberGroups[$memberId][] = [
                "id" => (int)($row['id'] ?? 0),
                "name" => $row['name'] ?? "",
                "color" => $row['color'] ?? "",
                "is_all_members" => (int)($row['is_all_members'] ?? 0),
                "is_role_group" => (int)($row['is_role_group'] ?? 0)
            ];
        }
        $stmt->close();
    }

    if ($memberGroups) {
        foreach ($members as &$member) {
            $id = (int)($member['member_id'] ?? 0);
            $member['groups'] = $memberGroups[$id] ?? [];
        }
        unset($member);
    }

    $pendingInvites = [];
    if ($canManage && $activeListToken !== '') {
        $stmt = $mysqli->prepare("
            SELECT DISTINCT i.email
            FROM invitations i
            JOIN content_lists cl ON cl.token = i.listToken
            LEFT JOIN members m ON m.email = i.email
            WHERE cl.owner_id = ? AND cl.token = ? AND m.id IS NULL
            ORDER BY i.email ASC
        ");
        $stmt->bind_param("is", $ownerId, $activeListToken);
        $stmt->execute();
        $stmt->bind_result($pendingEmail);
        while ($stmt->fetch()) {
            $pendingInvites[] = ["email" => $pendingEmail];
        }
        $stmt->close();
    }

    ep_json([
        "status" => "OK",
        "members" => $members,
        "pending_invites" => $pendingInvites,
        "is_all_members" => $isAllMembersGroup,
        "access_list_token" => $activeListToken
    ]);
}

$action = $data['action'] ?? '';
$groupId = (int)($data['group_id'] ?? 0);
if ($groupId <= 0) {
    ep_json(["status" => "error", "message" => "group_id required"]);
}
if (!ep_group_belongs_to_owner($mysqli, $groupId, $ownerId) || !$canManage) {
    ep_json(["status" => "error", "message" => "Permission denied"]);
}
if ($activeListToken === '') {
    ep_json(["status" => "error", "message" => "No active list token"]);
}

if ($action === 'invite_resend' || $action === 'invite_remove' || $action === 'invite_resend_all') {
    $targetEmail = trim((string)($data['email'] ?? ''));
    if ($action !== 'invite_resend_all' && $targetEmail === '') {
        ep_json(["status" => "error", "message" => "email required"]);
    }

    $pendingStmt = $mysqli->prepare("
        SELECT i.email, i.role, cl.token
        FROM invitations i
        JOIN content_lists cl ON cl.token = i.listToken
        LEFT JOIN members m ON m.email = i.email
        WHERE cl.owner_id = ?
          AND cl.token = ?
          AND m.id IS NULL
          AND (? = '' OR i.email = ?)
        ORDER BY i.email ASC
    ");
    $pendingStmt->bind_param("isss", $ownerId, $activeListToken, $targetEmail, $targetEmail);
    $pendingStmt->execute();
    $pendingRes = $pendingStmt->get_result();
    $pendingRows = $pendingRes ? $pendingRes->fetch_all(MYSQLI_ASSOC) : [];
    $pendingStmt->close();

    // Pending invite UI is distinct-by-email; keep resend behavior/count aligned.
    $pendingRowsByEmail = [];
    foreach ($pendingRows as $row) {
        $email = trim((string)($row['email'] ?? ''));
        $token = trim((string)($row['token'] ?? ''));
        if ($email === '' || $token === '') continue;
        $emailKey = strtolower($email);
        if (!isset($pendingRowsByEmail[$emailKey])) {
            $pendingRowsByEmail[$emailKey] = $row;
        }
    }
    $pendingRows = array_values($pendingRowsByEmail);

    if ($action === 'invite_remove') {
        if (!$pendingRows) {
            ep_json(["status" => "OK", "removed" => 0]);
        }
        $deleteStmt = $mysqli->prepare("
            DELETE i FROM invitations i
            JOIN content_lists cl ON cl.token = i.listToken
            LEFT JOIN members m ON m.email = i.email
            WHERE cl.owner_id = ?
              AND cl.token = ?
              AND m.id IS NULL
              AND i.email = ?
        ");
        $deleteStmt->bind_param("iss", $ownerId, $activeListToken, $targetEmail);
        $deleteStmt->execute();
        $removed = $deleteStmt->affected_rows;
        $deleteStmt->close();
        ep_json(["status" => "OK", "removed" => $removed]);
    }

    $inviterName = $_SESSION['display_name'] ?? $_SESSION['username'] ?? '';
    $queueCount = count($pendingRows);

    if (($action === 'invite_resend' || $action === 'invite_resend_all') && function_exists('fastcgi_finish_request')) {
        // Match bulk invite behavior: respond immediately and finish email delivery in background.
        echo json_encode(["status" => "OK", "sent" => $queueCount]);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        fastcgi_finish_request();
        foreach ($pendingRows as $row) {
            $email = $row['email'] ?? '';
            $token = $row['token'] ?? '';
            $role = $row['role'] ?? 'viewer';
            if ($email === '' || $token === '') continue;
            sendInviteEmail($email, $inviterName, $token, $role);
        }
        exit;
    }

    $sent = 0;
    foreach ($pendingRows as $row) {
        $email = $row['email'] ?? '';
        $token = $row['token'] ?? '';
        $role = $row['role'] ?? 'viewer';
        if ($email === '' || $token === '') continue;
        if (sendInviteEmail($email, $inviterName, $token, $role)) {
            $sent++;
        }
    }
    ep_json(["status" => "OK", "sent" => $sent]);
}

if ($action === 'add') {
    $targetId = (int)($data['member_id'] ?? 0);
    if ($targetId <= 0) {
        ep_json(["status" => "error", "message" => "member_id required"]);
    }
    $skipRoleUpdate = false;
    $skipRaw = strtolower(trim((string)($data['skip_role_update'] ?? '0')));
    if (in_array($skipRaw, ['1', 'true', 'yes', 'on'], true)) {
        $skipRoleUpdate = true;
    }
    $targetIsRoleGroup = 0;
    $targetGroupName = "";
    $stmt = $mysqli->prepare("
        SELECT is_role_group, name
        FROM ep_groups
        WHERE id = ? AND owner_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $groupId, $ownerId);
    $stmt->execute();
    $stmt->bind_result($targetIsRoleGroup, $targetGroupName);
    $stmt->fetch();
    $stmt->close();

    if ((int)$targetIsRoleGroup === 1) {
        // Keep role groups exclusive: a member can belong to only one role group at a time.
        $stmt = $mysqli->prepare("
            DELETE gm
            FROM ep_group_members gm
            JOIN ep_groups g ON g.id = gm.group_id
            WHERE gm.member_id = ?
              AND g.owner_id = ?
              AND g.is_role_group = 1
              AND gm.group_id <> ?
        ");
        $stmt->bind_param("iii", $targetId, $ownerId, $groupId);
        $stmt->execute();
        $stmt->close();
    }

    $role = trim($data['role'] ?? '');
    if ((int)$targetIsRoleGroup === 1) {
        // Role-group membership defines role label.
        $role = trim((string)$targetGroupName);
    }
    if ($role === '') {
        $allMembersGroupId = ep_all_members_group_id($mysqli, $ownerId);
        if ($allMembersGroupId) {
            $stmt = $mysqli->prepare("
                SELECT gm.role
                FROM ep_group_members gm
                WHERE gm.group_id = ? AND gm.member_id = ?
                AND gm.role IS NOT NULL AND gm.role <> ''
                LIMIT 1
            ");
            $stmt->bind_param("ii", $allMembersGroupId, $targetId);
            $stmt->execute();
            $stmt->bind_result($existingRole);
            $stmt->fetch();
            $stmt->close();
            if (!empty($existingRole)) {
                $role = $existingRole;
            }
        }
    }

    $stmt = $mysqli->prepare("DELETE FROM ep_group_members WHERE group_id = ? AND member_id = ?");
    $stmt->bind_param("ii", $groupId, $targetId);
    $stmt->execute();
    $stmt->close();

    $stmt = $mysqli->prepare("
        INSERT INTO ep_group_members (group_id, member_id, role)
        VALUES (?, ?, ?)
    ");
    $stmt->bind_param("iis", $groupId, $targetId, $role);
    $stmt->execute();
    $stmt->close();

    if (!($skipRoleUpdate && (int)$targetIsRoleGroup !== 1)) {
        $stmt = $mysqli->prepare("
            UPDATE ep_group_members gm
            JOIN ep_groups g ON g.id = gm.group_id
            SET gm.role = ?
            WHERE gm.member_id = ? AND g.owner_id = ?
        ");
        $stmt->bind_param("sii", $role, $targetId, $ownerId);
        $stmt->execute();
        $stmt->close();
    }

    ep_json(["status" => "OK"]);
}

if ($action === 'remove') {
    $targetId = (int)($data['member_id'] ?? 0);
    if ($targetId <= 0) {
        ep_json(["status" => "error", "message" => "member_id required"]);
    }
    $stmt = $mysqli->prepare("DELETE FROM ep_group_members WHERE group_id = ? AND member_id = ?");
    $stmt->bind_param("ii", $groupId, $targetId);
    $stmt->execute();
    $stmt->close();
    ep_json(["status" => "OK"]);
}

if ($action === 'set') {
    $memberIds = $data['member_ids'] ?? [];
    if (!is_array($memberIds)) {
        ep_json(["status" => "error", "message" => "member_ids must be array"]);
    }

    $stmt = $mysqli->prepare("DELETE FROM ep_group_members WHERE group_id = ?");
    $stmt->bind_param("i", $groupId);
    $stmt->execute();
    $stmt->close();

    $stmt = $mysqli->prepare("
        INSERT INTO ep_group_members (group_id, member_id)
        VALUES (?, ?)
    ");
    foreach ($memberIds as $id) {
        $id = (int)$id;
        if ($id <= 0) continue;
        $stmt->bind_param("ii", $groupId, $id);
        $stmt->execute();
    }
    $stmt->close();

    ep_json(["status" => "OK"]);
}

ep_json(["status" => "error", "message" => "Unsupported action"]);
