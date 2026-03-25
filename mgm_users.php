<?php
require_once __DIR__ . '/includes/mgm_ui.php';
require_once __DIR__ . '/includes/sub_plans.php';

$ctx = mgm_bootstrap('users', 'User Management');

function mgm_users_column_exists(mysqli $mysqli, string $table, string $column): bool {
    $tableEsc = $mysqli->real_escape_string($table);
    $columnEsc = $mysqli->real_escape_string($column);
    $sql = "SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$columnEsc}'";
    $result = $mysqli->query($sql);
    if (!$result instanceof mysqli_result) {
        return false;
    }
    $exists = $result->num_rows > 0;
    $result->close();
    return $exists;
}

function mgm_users_table_exists(mysqli $mysqli, string $table): bool {
    $tableEsc = $mysqli->real_escape_string($table);
    $result = $mysqli->query("SHOW TABLES LIKE '{$tableEsc}'");
    if (!$result instanceof mysqli_result) {
        return false;
    }
    $exists = $result->num_rows > 0;
    $result->close();
    return $exists;
}

function mgm_users_scalar(mysqli $mysqli, string $sql) {
    $result = $mysqli->query($sql);
    if (!$result instanceof mysqli_result) {
        return null;
    }
    $row = $result->fetch_row();
    $result->close();
    return $row[0] ?? null;
}

function mgm_users_datetime_or_null(?string $value): ?DateTimeImmutable {
    $value = trim((string)$value);
    if ($value === '' || $value === '0000-00-00 00:00:00') {
        return null;
    }
    try {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    } catch (Throwable $e) {
        return null;
    }
}

function mgm_users_plan_user_limit(string $plan, int $userAddon): int {
    global $PLANS_DEFAULT, $USER_ADDON;
    $base = (int)($PLANS_DEFAULT[$plan]['user'] ?? 0);
    if ($userAddon > 0 && isset($USER_ADDON[$userAddon])) {
        return $userAddon;
    }
    return $base;
}

function mgm_users_plan_storage_limit(string $plan, int $storageAddon): float {
    global $PLANS_DEFAULT;
    $base = (float)($PLANS_DEFAULT[$plan]['gb'] ?? 0);
    return $base + max(0, $storageAddon);
}

function mgm_users_next_renewal(?string $subscribedAt): ?DateTimeImmutable {
    $start = mgm_users_datetime_or_null($subscribedAt);
    if (!$start) {
        return null;
    }
    return $start->modify('+1 year');
}

function mgm_users_days_between_now(?DateTimeImmutable $dt): ?int {
    if (!$dt) {
        return null;
    }
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $nowMid = $now->setTime(0, 0);
    $targetMid = $dt->setTime(0, 0);
    return (int)$nowMid->diff($targetMid)->format('%r%a');
}

$hasMembersVerified = mgm_users_column_exists($mysqli, 'members', 'email_verified');
$hasMembersProfileType = mgm_users_column_exists($mysqli, 'members', 'profile_type');
$hasMembersGroupType = mgm_users_column_exists($mysqli, 'members', 'group_type');
$hasMembersDisplayName = mgm_users_column_exists($mysqli, 'members', 'display_name');
$hasMembersCreatedAt = mgm_users_column_exists($mysqli, 'members', 'created_at');
$hasMembersNdaAgreedAt = mgm_users_column_exists($mysqli, 'members', 'nda_agreed_at');
$hasEpGroups = mgm_users_column_exists($mysqli, 'ep_groups', 'id');
$hasEpGroupMembers = mgm_users_column_exists($mysqli, 'ep_group_members', 'id');
$hasMgmtStorageUsage = mgm_users_table_exists($mysqli, 'mgmt_storage_usage');
$hasMgmtStorageOrphans = $hasMgmtStorageUsage && mgm_users_column_exists($mysqli, 'mgmt_storage_usage', 'orphan_file_count');

$search = trim((string)($_GET['q'] ?? ''));
$pageSize = 50;

$summary = [
    'accounts' => (int)(mgm_users_scalar($mysqli, "SELECT COUNT(*) FROM members") ?? 0),
    'groups' => $hasMembersProfileType ? (int)(mgm_users_scalar($mysqli, "SELECT COUNT(*) FROM members WHERE COALESCE(profile_type, 'person') = 'group'") ?? 0) : 0,
    'memberships' => ($hasEpGroups && $hasEpGroupMembers) ? (int)(mgm_users_scalar($mysqli, "
        SELECT COUNT(*)
        FROM ep_group_members gm
        JOIN ep_groups g ON g.id = gm.group_id
        JOIN members owner ON owner.id = g.owner_id
        WHERE g.is_all_members = 1
          AND COALESCE(owner.profile_type, 'person') = 'group'
          AND gm.member_id <> owner.id
    ") ?? 0) : 0,
    'grouped_users' => ($hasEpGroups && $hasEpGroupMembers) ? (int)(mgm_users_scalar($mysqli, "
        SELECT COUNT(DISTINCT gm.member_id)
        FROM ep_group_members gm
        JOIN ep_groups g ON g.id = gm.group_id
        JOIN members owner ON owner.id = g.owner_id
        WHERE g.is_all_members = 1
          AND COALESCE(owner.profile_type, 'person') = 'group'
          AND gm.member_id <> owner.id
    ") ?? 0) : 0,
    'storage_files_total' => 0,
    'storage_gb_total' => 0.0,
    'storage_members_total' => 0,
    'orphan_files_total' => 0,
    'orphan_gb_total' => 0.0,
];

if ($hasMgmtStorageUsage) {
    $storageRow = $mysqli->query("
        SELECT
            COALESCE(SUM(file_count), 0) AS files_total,
            COALESCE(SUM(gb_used), 0) AS gb_total,
            COALESCE(SUM(CASE WHEN file_count > 0 THEN 1 ELSE 0 END), 0) AS members_total,
            " . ($hasMgmtStorageOrphans ? "COALESCE(SUM(orphan_file_count), 0)" : "0") . " AS orphan_files_total,
            " . ($hasMgmtStorageOrphans ? "COALESCE(SUM(orphan_gb_used), 0)" : "0") . " AS orphan_gb_total
        FROM mgmt_storage_usage
        WHERE source = 'cloudflare'
    ");
    if ($storageRow instanceof mysqli_result) {
        $storage = $storageRow->fetch_assoc() ?: [];
        $summary['storage_files_total'] = (int)($storage['files_total'] ?? 0);
        $summary['storage_gb_total'] = (float)($storage['gb_total'] ?? 0);
        $summary['storage_members_total'] = (int)($storage['members_total'] ?? 0);
        $summary['orphan_files_total'] = (int)($storage['orphan_files_total'] ?? 0);
        $summary['orphan_gb_total'] = (float)($storage['orphan_gb_total'] ?? 0);
        $storageRow->close();
    }
}

if (($_GET['ajax'] ?? '') === 'group_members') {
    header('Content-Type: application/json; charset=UTF-8');

    $groupId = (int)($_GET['group_id'] ?? 0);
    if ($groupId <= 0 || !$hasEpGroups || !$hasEpGroupMembers || !$hasMembersProfileType) {
        echo json_encode(['ok' => false, 'rows' => []]);
        exit;
    }

    $rows = [];
    $sql = "
        SELECT
            m.id,
            m.username,
            " . ($hasMembersDisplayName ? "COALESCE(NULLIF(m.display_name, ''), m.username)" : "m.username") . " AS display_name,
            COALESCE(NULLIF(m.plan, ''), 'free') AS plan_name,
            " . ($hasMembersVerified ? "m.email_verified" : "NULL") . " AS email_verified,
            COALESCE(m.profile_type, 'person') AS profile_type,
            " . ($hasMembersGroupType ? "COALESCE(NULLIF(m.group_type, ''), '')" : "''") . " AS group_type,
            COALESCE(NULLIF(gm.role, ''), 'member') AS group_role,
            " . (($hasMembersCreatedAt && $hasMembersNdaAgreedAt)
                ? "COALESCE(NULLIF(m.created_at, '0000-00-00 00:00:00'), m.nda_agreed_at)"
                : ($hasMembersCreatedAt
                    ? "m.created_at"
                    : ($hasMembersNdaAgreedAt ? "m.nda_agreed_at" : "NULL"))) . " AS joined_tw_at,
            m.subscribed_at,
            COALESCE(NULLIF(m.subscription_status, ''), '') AS subscription_status,
            COALESCE(m.storage_addon, 0) AS storage_addon,
            COALESCE(m.user_addon, 0) AS user_addon,
            " . ($hasMgmtStorageUsage ? "COALESCE(msu.file_count, 0)" : "0") . " AS file_count,
            " . ($hasMgmtStorageUsage ? "COALESCE(msu.gb_used, 0)" : "0") . " AS gb_used,
            " . ($hasMgmtStorageUsage ? "msu.scanned_at" : "NULL") . " AS usage_scanned_at,
            gm.joined_at,
            1 AS member_count
        FROM ep_groups g
        JOIN members owner ON owner.id = g.owner_id
        JOIN ep_group_members gm ON gm.group_id = g.id
        JOIN members m ON m.id = gm.member_id
        " . ($hasMgmtStorageUsage ? "LEFT JOIN mgmt_storage_usage msu ON msu.member_id = m.id AND msu.source = 'cloudflare'" : "") . "
        WHERE g.owner_id = ?
          AND g.is_all_members = 1
          AND COALESCE(owner.profile_type, 'person') = 'group'
          AND gm.member_id <> owner.id
        ORDER BY gm.joined_at DESC, m.username ASC
    ";
    $stmt = $mysqli->prepare($sql);
    if ($stmt instanceof mysqli_stmt) {
        $stmt->bind_param('i', $groupId);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($result instanceof mysqli_result) {
                while ($row = $result->fetch_assoc()) {
                    $plan = (string)($row['plan_name'] ?? 'free');
                    $joinedTw = (string)($row['joined_tw_at'] ?? '');
                    $nextRenewal = mgm_users_next_renewal((string)($row['subscribed_at'] ?? ''));
                    $rows[] = [
                        'member_id' => (int)($row['id'] ?? 0),
                        'username' => (string)($row['username'] ?? ''),
                        'display_name' => (string)($row['display_name'] ?? ''),
                        'plan' => $plan,
                        'verified' => ($row['email_verified'] === null ? '?' : (string)((int)$row['email_verified'])),
                        'type' => (string)($row['profile_type'] ?? 'person'),
                        'group_type' => (string)($row['group_type'] ?? ''),
                        'group_role' => (string)($row['group_role'] ?? 'member'),
                        'member_count' => (int)($row['member_count'] ?? 1),
                        'joined_tw' => mgm_format_datetime($joinedTw),
                        'tw_days' => mgm_users_days_between_now(mgm_users_datetime_or_null($joinedTw)),
                        'subscription_status' => (string)($row['subscription_status'] ?? ''),
                        'next_renewal' => mgm_format_datetime($nextRenewal ? $nextRenewal->format('Y-m-d H:i:s') : ''),
                        'renewal_days' => mgm_users_days_between_now($nextRenewal),
                        'storage_allowed_gb' => mgm_users_plan_storage_limit($plan, (int)($row['storage_addon'] ?? 0)),
                        'files' => (int)($row['file_count'] ?? 0),
                        'used_gb' => (float)($row['gb_used'] ?? 0),
                        'usage_scanned' => mgm_format_datetime((string)($row['usage_scanned_at'] ?? '')),
                        'users_allowed' => mgm_users_plan_user_limit($plan, (int)($row['user_addon'] ?? 0)),
                        'joined_group' => mgm_format_datetime((string)($row['joined_at'] ?? '')),
                    ];
                }
                $result->close();
            }
        }
        $stmt->close();
    }

    echo json_encode(['ok' => true, 'rows' => $rows]);
    exit;
}

if (($_GET['ajax'] ?? '') === 'group_rows') {
    header('Content-Type: application/json; charset=UTF-8');

    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $groups = [];

    if ($hasMembersProfileType) {
        $where = [];
        $types = '';
        $params = [];

        if ($search !== '') {
            $searchParts = [];
            $searchParts[] = "m.username LIKE CONCAT('%', ?, '%')";
            if ($hasMembersDisplayName) {
                $searchParts[] = "m.display_name LIKE CONCAT('%', ?, '%')";
            }
            if ($hasMembersGroupType) {
                $searchParts[] = "m.group_type LIKE CONCAT('%', ?, '%')";
            }
            $where[] = '(' . implode(' OR ', $searchParts) . ')';
            $types .= str_repeat('s', count($searchParts));
            $params[] = $search;
            if ($hasMembersDisplayName) {
                $params[] = $search;
            }
            if ($hasMembersGroupType) {
                $params[] = $search;
            }
        }

        $sql = "
            SELECT
                m.id,
                m.username,
                " . ($hasMembersDisplayName ? "COALESCE(NULLIF(m.display_name, ''), m.username)" : "m.username") . " AS display_name,
                COALESCE(m.profile_type, 'group') AS profile_type,
                " . ($hasMembersGroupType ? "COALESCE(NULLIF(m.group_type, ''), '')" : "''") . " AS group_type,
                COALESCE(NULLIF(m.plan, ''), 'free') AS plan_name,
                " . ($hasMembersVerified ? "m.email_verified" : "NULL") . " AS email_verified,
                " . (($hasMembersCreatedAt && $hasMembersNdaAgreedAt)
                    ? "COALESCE(NULLIF(m.created_at, '0000-00-00 00:00:00'), m.nda_agreed_at)"
                    : ($hasMembersCreatedAt
                        ? "m.created_at"
                        : ($hasMembersNdaAgreedAt ? "m.nda_agreed_at" : "NULL"))) . " AS joined_tw_at,
                m.subscribed_at,
                COALESCE(NULLIF(m.subscription_status, ''), '') AS subscription_status,
                COALESCE(m.storage_addon, 0) AS storage_addon,
                COALESCE(m.user_addon, 0) AS user_addon,
                " . ($hasMgmtStorageUsage ? "COALESCE(msu.file_count, 0)" : "0") . " AS file_count,
                " . ($hasMgmtStorageUsage ? "COALESCE(msu.gb_used, 0)" : "0") . " AS gb_used,
                " . ($hasMgmtStorageUsage ? "msu.scanned_at" : "NULL") . " AS usage_scanned_at,
                COUNT(DISTINCT CASE WHEN gm.member_id <> m.id THEN gm.member_id END) AS member_count
            FROM members m
            LEFT JOIN ep_groups allg ON allg.owner_id = m.id AND allg.is_all_members = 1
            LEFT JOIN ep_group_members gm ON gm.group_id = allg.id
            " . ($hasMgmtStorageUsage ? "LEFT JOIN mgmt_storage_usage msu ON msu.member_id = m.id AND msu.source = 'cloudflare'" : "") . "
            WHERE COALESCE(m.profile_type, 'person') = 'group'
        ";
        if ($where) {
            $sql .= " AND " . implode(' AND ', $where) . "\n";
        }
        $sql .= "
            GROUP BY m.id, m.username, " . ($hasMembersDisplayName ? "m.display_name, " : "") . "m.profile_type, " . ($hasMembersGroupType ? "m.group_type, " : "") . "m.plan, " . ($hasMembersVerified ? "m.email_verified, " : "") . (($hasMembersCreatedAt || $hasMembersNdaAgreedAt) ? "joined_tw_at, " : "") . "m.subscribed_at, m.subscription_status, m.storage_addon, m.user_addon, file_count, gb_used, usage_scanned_at
            ORDER BY display_name ASC
            LIMIT ? OFFSET ?
        ";
        $types .= 'ii';
        $params[] = $pageSize;
        $params[] = $offset;

        $stmt = $mysqli->prepare($sql);
        if ($stmt instanceof mysqli_stmt) {
            $bind = [$types];
            foreach ($params as $index => $value) {
                $bind[] = &$params[$index];
            }
            $stmt->bind_param(...$bind);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result instanceof mysqli_result) {
                    while ($row = $result->fetch_assoc()) {
                        $plan = (string)($row['plan_name'] ?? 'free');
                        $joinedTw = (string)($row['joined_tw_at'] ?? '');
                        $nextRenewal = mgm_users_next_renewal((string)($row['subscribed_at'] ?? ''));
                        $groups[] = [
                            'id' => (int)($row['id'] ?? 0),
                        'member_id' => (int)($row['id'] ?? 0),
                        'username' => (string)($row['username'] ?? ''),
                        'name' => (string)($row['display_name'] ?? ''),
                        'type' => (string)($row['profile_type'] ?? 'group'),
                        'group_type' => (string)($row['group_type'] ?? ''),
                        'group_role' => 'owner',
                        'plan' => $plan,
                        'verified' => ($row['email_verified'] === null ? '?' : (string)((int)$row['email_verified'])),
                        'member_count' => (int)($row['member_count'] ?? 0),
                        'joined_tw' => mgm_format_datetime($joinedTw),
                        'tw_days' => mgm_users_days_between_now(mgm_users_datetime_or_null($joinedTw)),
                        'subscription_status' => (string)($row['subscription_status'] ?? ''),
                        'next_renewal' => mgm_format_datetime($nextRenewal ? $nextRenewal->format('Y-m-d H:i:s') : ''),
                        'renewal_days' => mgm_users_days_between_now($nextRenewal),
                        'storage_allowed_gb' => mgm_users_plan_storage_limit($plan, (int)($row['storage_addon'] ?? 0)),
                        'files' => (int)($row['file_count'] ?? 0),
                        'used_gb' => (float)($row['gb_used'] ?? 0),
                        'usage_scanned' => mgm_format_datetime((string)($row['usage_scanned_at'] ?? '')),
                        'users_allowed' => mgm_users_plan_user_limit($plan, (int)($row['user_addon'] ?? 0)),
                        'joined_group' => '',
                    ];
                    }
                    $result->close();
                }
            }
            $stmt->close();
        }
    }

    echo json_encode([
        'ok' => true,
        'rows' => $groups,
        'has_more' => count($groups) === $pageSize,
    ]);
    exit;
}

$groups = [];
if ($hasMembersProfileType) {
    $where = [];
    $types = '';
    $params = [];

    if ($search !== '') {
        $searchParts = [];
        $searchParts[] = "m.username LIKE CONCAT('%', ?, '%')";
        if ($hasMembersDisplayName) {
            $searchParts[] = "m.display_name LIKE CONCAT('%', ?, '%')";
        }
        if ($hasMembersGroupType) {
            $searchParts[] = "m.group_type LIKE CONCAT('%', ?, '%')";
        }
        $where[] = '(' . implode(' OR ', $searchParts) . ')';
        $types .= str_repeat('s', count($searchParts));
        $params[] = $search;
        if ($hasMembersDisplayName) {
            $params[] = $search;
        }
        if ($hasMembersGroupType) {
            $params[] = $search;
        }
    }

    $sql = "
        SELECT
            m.id,
            m.username,
            " . ($hasMembersDisplayName ? "COALESCE(NULLIF(m.display_name, ''), m.username)" : "m.username") . " AS display_name,
            COALESCE(m.profile_type, 'group') AS profile_type,
            " . ($hasMembersGroupType ? "COALESCE(NULLIF(m.group_type, ''), '')" : "''") . " AS group_type,
            COALESCE(NULLIF(m.plan, ''), 'free') AS plan_name,
            " . ($hasMembersVerified ? "m.email_verified" : "NULL") . " AS email_verified,
            " . (($hasMembersCreatedAt && $hasMembersNdaAgreedAt)
                ? "COALESCE(NULLIF(m.created_at, '0000-00-00 00:00:00'), m.nda_agreed_at)"
                : ($hasMembersCreatedAt
                    ? "m.created_at"
                    : ($hasMembersNdaAgreedAt ? "m.nda_agreed_at" : "NULL"))) . " AS joined_tw_at,
            m.subscribed_at,
            COALESCE(NULLIF(m.subscription_status, ''), '') AS subscription_status,
            COALESCE(m.storage_addon, 0) AS storage_addon,
            COALESCE(m.user_addon, 0) AS user_addon,
            " . ($hasMgmtStorageUsage ? "COALESCE(msu.file_count, 0)" : "0") . " AS file_count,
            " . ($hasMgmtStorageUsage ? "COALESCE(msu.gb_used, 0)" : "0") . " AS gb_used,
            " . ($hasMgmtStorageUsage ? "msu.scanned_at" : "NULL") . " AS usage_scanned_at,
            COUNT(DISTINCT CASE WHEN gm.member_id <> m.id THEN gm.member_id END) AS member_count
        FROM members m
        LEFT JOIN ep_groups allg ON allg.owner_id = m.id AND allg.is_all_members = 1
        LEFT JOIN ep_group_members gm ON gm.group_id = allg.id
        " . ($hasMgmtStorageUsage ? "LEFT JOIN mgmt_storage_usage msu ON msu.member_id = m.id AND msu.source = 'cloudflare'" : "") . "
        WHERE COALESCE(m.profile_type, 'person') = 'group'
    ";
    if ($where) {
        $sql .= " AND " . implode(' AND ', $where) . "\n";
    }
    $sql .= "
        GROUP BY m.id, m.username, " . ($hasMembersDisplayName ? "m.display_name, " : "") . "m.profile_type, " . ($hasMembersGroupType ? "m.group_type, " : "") . "m.plan, " . ($hasMembersVerified ? "m.email_verified, " : "") . (($hasMembersCreatedAt || $hasMembersNdaAgreedAt) ? "joined_tw_at, " : "") . "m.subscribed_at, m.subscription_status, m.storage_addon, m.user_addon, file_count, gb_used, usage_scanned_at
        ORDER BY display_name ASC
        LIMIT ?
    ";
    $types .= 'i';
    $params[] = $pageSize;

    $stmt = $mysqli->prepare($sql);
    if ($stmt instanceof mysqli_stmt) {
        $bind = [$types];
        foreach ($params as $index => $value) {
            $bind[] = &$params[$index];
        }
        $stmt->bind_param(...$bind);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($result instanceof mysqli_result) {
                while ($row = $result->fetch_assoc()) {
                    $plan = (string)($row['plan_name'] ?? 'free');
                    $joinedTw = (string)($row['joined_tw_at'] ?? '');
                    $nextRenewal = mgm_users_next_renewal((string)($row['subscribed_at'] ?? ''));
                    $groups[] = [
                        'id' => (int)($row['id'] ?? 0),
                        'member_id' => (int)($row['id'] ?? 0),
                        'username' => (string)($row['username'] ?? ''),
                        'name' => (string)($row['display_name'] ?? ''),
                        'type' => (string)($row['profile_type'] ?? 'group'),
                        'group_type' => (string)($row['group_type'] ?? ''),
                        'group_role' => 'owner',
                        'plan' => $plan,
                        'verified' => ($row['email_verified'] === null ? '?' : (string)((int)$row['email_verified'])),
                        'member_count' => (int)($row['member_count'] ?? 0),
                        'joined_tw' => (string)($row['joined_tw_at'] ?? ''),
                        'tw_days' => mgm_users_days_between_now(mgm_users_datetime_or_null($joinedTw)),
                        'subscription_status' => (string)($row['subscription_status'] ?? ''),
                        'next_renewal' => mgm_format_datetime($nextRenewal ? $nextRenewal->format('Y-m-d H:i:s') : ''),
                        'renewal_days' => mgm_users_days_between_now($nextRenewal),
                        'storage_allowed_gb' => mgm_users_plan_storage_limit($plan, (int)($row['storage_addon'] ?? 0)),
                        'files' => (int)($row['file_count'] ?? 0),
                        'used_gb' => (float)($row['gb_used'] ?? 0),
                        'usage_scanned' => mgm_format_datetime((string)($row['usage_scanned_at'] ?? '')),
                        'users_allowed' => mgm_users_plan_user_limit($plan, (int)($row['user_addon'] ?? 0)),
                        'joined_group' => '',
                    ];
                }
                $result->close();
            }
        }
        $stmt->close();
    }
}

mgm_render_shell_start(
    $ctx,
    'Groups And Users',
    'Show only group-profile members first. Expanding a row loads the members of that group from its All Members relation.'
);
?>
      <style>
        .mgm-filter-bar {
          display: grid;
          grid-template-columns: minmax(220px, 2fr) minmax(120px, 1fr);
          gap: 12px;
          align-items: end;
        }
        .mgm-filter-field label {
          display: block;
          margin-bottom: 6px;
          font-size: 12px;
          letter-spacing: 0.14em;
          text-transform: uppercase;
          color: #5d6b80;
          font-weight: 700;
        }
        .mgm-filter-field input,
        .mgm-filter-field select {
          width: 100%;
          border: 1px solid #d7deea;
          border-radius: 12px;
          padding: 11px 12px;
          font: inherit;
          background: rgba(245, 247, 252, 0.94);
          color: #16233d;
        }
        .mgm-filter-actions {
          display: flex;
          gap: 10px;
          flex-wrap: wrap;
          margin-top: 14px;
        }
        .mgm-button {
          display: inline-flex;
          align-items: center;
          justify-content: center;
          border-radius: 999px;
          padding: 10px 16px;
          border: 1px solid #cad4e5;
          background: #eff4fb;
          color: #16233d;
          text-decoration: none;
          font-weight: 700;
        }
        .mgm-button.primary {
          background: #20304c;
          border-color: #20304c;
          color: #f6f8fb;
        }
        .mgm-wide-table table {
          min-width: 3120px;
        }
        .mgm-wide-table {
          overflow-x: auto;
          overflow-y: auto;
          max-height: calc(100vh - 260px);
          border: 1px solid #d9e1ec;
          border-radius: 16px;
          background: #ffffff;
        }
        .mgm-wide-table td,
        .mgm-wide-table th {
          white-space: nowrap;
          vertical-align: top;
        }
        .mgm-wide-table th {
          position: sticky;
          top: 0;
          background: #ffffff;
          z-index: 2;
        }
        .mgm-cell-button {
          appearance: none;
          border: 0;
          background: transparent;
          padding: 0;
          font: inherit;
          color: inherit;
          cursor: pointer;
          text-align: left;
        }
        .mgm-cell-button:hover {
          color: var(--mgm-accent);
        }
        .mgm-member-row td {
          background: #f7fafc;
        }
        .mgm-member-row td:first-child {
          padding-left: 28px;
        }
        .mgm-member-row strong {
          font-weight: 600;
        }
        .mgm-expand-empty,
        .mgm-expand-loading td {
          color: #5d6b80;
          font-size: 14px;
        }
        .mgm-expand-loading td,
        .mgm-expand-empty td {
          background: #f7fafc;
          padding-left: 28px;
        }
        .mgm-action-button {
          border-radius: 999px;
          border: 1px solid #cad4e5;
          background: #eff4fb;
          color: #16233d;
          padding: 2px 8px;
          font: 700 12px/1.2 "Trebuchet MS", sans-serif;
          cursor: pointer;
          line-height: 1;
          white-space: nowrap;
        }
        .mgm-group-row .mgm-action-button,
        .mgm-member-row .mgm-action-button {
          margin: 0;
          vertical-align: middle;
        }
        .mgm-action-button:disabled {
          opacity: 0.6;
          cursor: wait;
        }
        .mgm-modal[hidden] {
          display: none;
        }
        .mgm-modal {
          position: fixed;
          inset: 0;
          background: rgba(15, 23, 42, 0.38);
          display: grid;
          place-items: center;
          padding: 24px;
          z-index: 1000;
        }
        .mgm-modal-card {
          width: min(520px, 100%);
          background: #ffffff;
          border: 1px solid var(--mgm-border);
          border-radius: 20px;
          box-shadow: var(--mgm-shadow);
          padding: 22px;
        }
        .mgm-modal-actions {
          display: flex;
          gap: 10px;
          justify-content: flex-end;
          margin-top: 18px;
        }
        .mgm-modal-copy {
          color: var(--mgm-muted);
          line-height: 1.45;
          margin: 0 0 12px;
        }
        .mgm-sync-status {
          margin-top: 12px;
          min-height: 20px;
          color: var(--mgm-muted);
        }
        @media (max-width: 900px) {
          .mgm-filter-bar {
            grid-template-columns: 1fr;
          }
        }
      </style>

      <section class="mgm-grid cols-4">
        <article class="mgm-panel">
          <p class="mgm-stat-label">Accounts</p>
          <p class="mgm-stat-value"><?= number_format($summary['accounts']) ?></p>
          <p class="mgm-stat-note">Total member accounts.</p>
        </article>
        <article class="mgm-panel">
          <p class="mgm-stat-label">Group Profiles</p>
          <p class="mgm-stat-value"><?= number_format($summary['groups']) ?></p>
          <p class="mgm-stat-note">Members whose `profile_type` is `group`.</p>
        </article>
        <article class="mgm-panel">
          <p class="mgm-stat-label">Memberships</p>
          <p class="mgm-stat-value"><?= number_format($summary['memberships']) ?></p>
          <p class="mgm-stat-note">Joined user-to-group relationships.</p>
        </article>
        <article class="mgm-panel">
          <p class="mgm-stat-label">Grouped Users</p>
          <p class="mgm-stat-value"><?= number_format($summary['grouped_users']) ?></p>
          <p class="mgm-stat-note">Distinct users in at least one group.</p>
        </article>
        <article class="mgm-panel">
          <p class="mgm-stat-label">Stored Files</p>
          <p class="mgm-stat-value"><?= number_format($summary['storage_files_total']) ?></p>
          <p class="mgm-stat-note"><?= number_format($summary['storage_members_total']) ?> members currently have Cloudflare files.</p>
        </article>
        <article class="mgm-panel">
          <p class="mgm-stat-label">Stored GB</p>
          <p class="mgm-stat-value"><?= rtrim(rtrim(number_format((float)$summary['storage_gb_total'], 3, '.', ''), '0'), '.') ?></p>
          <p class="mgm-stat-note">Cached total Cloudflare storage volume.</p>
        </article>
        <article class="mgm-panel">
          <p class="mgm-stat-label">Orphan Files</p>
          <p class="mgm-stat-value"><?= number_format($summary['orphan_files_total']) ?></p>
          <p class="mgm-stat-note">Cloudflare files whose surrogate has no active TW item.</p>
        </article>
        <article class="mgm-panel">
          <p class="mgm-stat-label">Orphan GB</p>
          <p class="mgm-stat-value"><?= rtrim(rtrim(number_format((float)$summary['orphan_gb_total'], 3, '.', ''), '0'), '.') ?></p>
          <p class="mgm-stat-note">Cached orphan storage volume from deleted or missing TW items.</p>
        </article>
      </section>

      <section class="mgm-panel" style="margin-top:18px;">
        <h2>Groups And Users</h2>
        <p class="mgm-panel-intro">Top-level rows come from `members.profile_type = 'group'`. Expanding a group loads users from that group profile's `All Members` relation.</p>
        <form method="get">
          <div class="mgm-filter-bar">
            <div class="mgm-filter-field">
              <label for="q">Search</label>
              <input id="q" type="text" name="q" value="<?= mgm_h($search) ?>" placeholder="Group name, username, or type">
            </div>
          </div>
          <div class="mgm-filter-actions">
            <button class="mgm-button primary" type="submit">Apply Filters</button>
            <a class="mgm-button" href="/mgm_users.php">Reset</a>
            <button type="button" class="mgm-button" id="mgm-storage-sync-all">Sync All Storage</button>
          </div>
        </form>

        <?php if ($groups): ?>
          <div class="mgm-table-wrap mgm-wide-table">
            <table id="mgm-groups-table">
              <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Username</th>
                <th>Type</th>
                <th>Role</th>
                <th>Group Type</th>
                <th>Plan</th>
                <th>Subscription</th>
                <th>Next Renewal</th>
                <th>Renewal Days</th>
                <th>Allowed GB</th>
                <th>Files</th>
                <th>Used GB</th>
                <th>Usage Scan</th>
                <th>Storage Sync</th>
                <th>Allowed Users</th>
                <th>Members</th>
                <th>Verified</th>
                <th>Joined TW</th>
                <th>TW Days</th>
                <th>Joined Group</th>
              </tr>
              <?php foreach ($groups as $group): ?>
                <tr class="mgm-group-row" data-group-id="<?= (int)$group['id'] ?>">
                  <td><?= (int)$group['member_id'] ?></td>
                  <td>
                    <button type="button" class="mgm-cell-button" data-mgm-toggle-group="<?= (int)$group['id'] ?>">
                      <strong><?= mgm_h($group['name']) ?></strong>
                    </button>
                  </td>
                  <td><?= mgm_h($group['username']) ?></td>
                  <td><?= mgm_h($group['type']) ?></td>
                  <td><?= mgm_h($group['group_role']) ?></td>
                  <td><?= mgm_h($group['group_type']) ?></td>
                  <td><?= mgm_h($group['plan']) ?></td>
                  <td><?= mgm_h($group['subscription_status']) ?></td>
                  <td><?= mgm_h($group['next_renewal']) ?></td>
                  <td><?= $group['renewal_days'] === null ? '' : (int)$group['renewal_days'] ?></td>
                  <td><?= rtrim(rtrim(number_format((float)$group['storage_allowed_gb'], 1, '.', ''), '0'), '.') ?></td>
                  <td><?= (int)$group['files'] ?></td>
                  <td><?= rtrim(rtrim(number_format((float)$group['used_gb'], 3, '.', ''), '0'), '.') ?></td>
                  <td><?= mgm_h($group['usage_scanned']) ?></td>
                  <td>
                    <button
                      type="button"
                      class="mgm-action-button"
                      data-mgm-sync-storage
                      data-member-id="<?= (int)$group['member_id'] ?>"
                      data-username="<?= mgm_h($group['username']) ?>"
                      data-name="<?= mgm_h($group['name']) ?>"
                    >Sync</button>
                  </td>
                  <td><?= (int)$group['users_allowed'] ?></td>
                  <td><?= (int)$group['member_count'] ?></td>
                  <td><?= mgm_h($group['verified']) ?></td>
                  <td><?= mgm_h(mgm_format_datetime($group['joined_tw'])) ?></td>
                  <td><?= $group['tw_days'] === null ? '' : (int)$group['tw_days'] ?></td>
                  <td><?= mgm_h($group['joined_group']) ?></td>
                </tr>
                <tr class="mgm-expand-loading" hidden data-group-detail="<?= (int)$group['id'] ?>">
                  <td colspan="21">Loading users...</td>
                </tr>
              <?php endforeach; ?>
            </table>
          </div>
          <div id="mgm-groups-sentinel" class="mgm-panel-intro" style="margin-top:14px;">More groups load automatically while scrolling.</div>
        <?php else: ?>
          <p class="mgm-panel-intro">No groups matched the current filter.</p>
        <?php endif; ?>
      </section>

      <div id="mgm-storage-sync-modal" class="mgm-modal" hidden>
        <div class="mgm-modal-card">
          <h3>Sync Storage Usage</h3>
          <p class="mgm-modal-copy" id="mgm-storage-sync-copy"></p>
          <p class="mgm-modal-copy" id="mgm-storage-sync-mode-copy">This scans the Cloudflare prefix for one member and updates cached file count and used GB.</p>
          <div class="mgm-sync-status" id="mgm-storage-sync-status"></div>
          <div class="mgm-modal-actions">
            <button type="button" class="mgm-button" id="mgm-storage-sync-cancel">Close</button>
            <button type="button" class="mgm-button primary" id="mgm-storage-sync-run">Run Sync</button>
          </div>
        </div>
      </div>

      <script>
        (() => {
          const table = document.getElementById("mgm-groups-table");
          const sentinel = document.getElementById("mgm-groups-sentinel");
          const modal = document.getElementById("mgm-storage-sync-modal");
          const modalCopy = document.getElementById("mgm-storage-sync-copy");
          const modalModeCopy = document.getElementById("mgm-storage-sync-mode-copy");
          const modalStatus = document.getElementById("mgm-storage-sync-status");
          const modalCancel = document.getElementById("mgm-storage-sync-cancel");
          const modalRun = document.getElementById("mgm-storage-sync-run");
          const syncAllButton = document.getElementById("mgm-storage-sync-all");
          if (!table) return;
          let nextOffset = <?= count($groups) ?>;
          let loadingMore = false;
          let hasMore = <?= count($groups) === $pageSize ? 'true' : 'false' ?>;
          let activeSyncTarget = null;

          const escapeHtml = (value) => {
            const div = document.createElement("div");
            div.textContent = value ?? "";
            return div.innerHTML;
          };

          const parseJsonResponse = async (res) => {
            const text = await res.text();
            try {
              return JSON.parse(text);
            } catch (error) {
              const snippet = text.replace(/\s+/g, " ").trim().slice(0, 120);
              throw new Error(snippet ? `Server returned non-JSON response: ${snippet}` : "Server returned non-JSON response");
            }
          };

          const renderGroupRows = (rows) => rows.map((row) => `
            <tr class="mgm-group-row" data-group-id="${escapeHtml(row.id)}">
              <td>${escapeHtml(row.member_id)}</td>
              <td>
                <button type="button" class="mgm-cell-button" data-mgm-toggle-group="${escapeHtml(row.id)}">
                  <strong>${escapeHtml(row.name)}</strong>
                </button>
              </td>
              <td>${escapeHtml(row.username)}</td>
              <td>${escapeHtml(row.type)}</td>
              <td>${escapeHtml(row.group_role || "")}</td>
              <td>${escapeHtml(row.group_type || "")}</td>
              <td>${escapeHtml(row.plan)}</td>
              <td>${escapeHtml(row.subscription_status || "")}</td>
              <td>${escapeHtml(row.next_renewal || "")}</td>
              <td>${escapeHtml(row.renewal_days ?? "")}</td>
              <td>${escapeHtml(row.storage_allowed_gb ?? "")}</td>
              <td>${escapeHtml(row.files ?? "")}</td>
              <td>${escapeHtml(row.used_gb ?? "")}</td>
              <td>${escapeHtml(row.usage_scanned || "")}</td>
              <td>
                <button
                  type="button"
                  class="mgm-action-button"
                  data-mgm-sync-storage
                  data-member-id="${escapeHtml(row.member_id)}"
                  data-username="${escapeHtml(row.username)}"
                  data-name="${escapeHtml(row.name || row.username)}"
                >Sync</button>
              </td>
              <td>${escapeHtml(row.users_allowed ?? "")}</td>
              <td>${escapeHtml(row.member_count)}</td>
              <td>${escapeHtml(row.verified)}</td>
              <td>${escapeHtml(row.joined_tw)}</td>
              <td>${escapeHtml(row.tw_days ?? "")}</td>
              <td>${escapeHtml(row.joined_group || "")}</td>
            </tr>
            <tr class="mgm-expand-loading" hidden data-group-detail="${escapeHtml(row.id)}">
              <td colspan="21">Loading users...</td>
            </tr>
          `).join("");

          const renderRows = (groupId, rows) => {
            if (!rows.length) {
              return `
                <tr class="mgm-expand-empty" data-group-member-of="${escapeHtml(groupId)}">
                  <td colspan="21">No users are currently recorded for this group.</td>
                </tr>
              `;
            }

            return rows.map((row) => `
              <tr class="mgm-member-row" data-group-member-of="${escapeHtml(groupId)}">
                <td>${escapeHtml(row.member_id)}</td>
                <td><strong>${escapeHtml(row.display_name || row.username)}</strong></td>
                <td>${escapeHtml(row.username)}</td>
                <td>${escapeHtml(row.type || "person")}</td>
                <td>${escapeHtml(row.group_role || "")}</td>
                <td>${escapeHtml(row.group_type || "")}</td>
                <td>${escapeHtml(row.plan)}</td>
                <td>${escapeHtml(row.subscription_status || "")}</td>
                <td>${escapeHtml(row.next_renewal || "")}</td>
                <td>${escapeHtml(row.renewal_days ?? "")}</td>
                <td>${escapeHtml(row.storage_allowed_gb ?? "")}</td>
                <td>${escapeHtml(row.files ?? "")}</td>
                <td>${escapeHtml(row.used_gb ?? "")}</td>
                <td>${escapeHtml(row.usage_scanned || "")}</td>
                <td>
                  <button
                    type="button"
                    class="mgm-action-button"
                    data-mgm-sync-storage
                    data-member-id="${escapeHtml(row.member_id)}"
                    data-username="${escapeHtml(row.username)}"
                    data-name="${escapeHtml(row.display_name || row.username)}"
                  >Sync</button>
                </td>
                <td>${escapeHtml(row.users_allowed ?? "")}</td>
                <td>${escapeHtml(row.member_count)}</td>
                <td>${escapeHtml(row.verified)}</td>
                <td>${escapeHtml(row.joined_tw || "")}</td>
                <td>${escapeHtml(row.tw_days ?? "")}</td>
                <td>${escapeHtml(row.joined_group)}</td>
              </tr>
            `).join("");
          };

          const bindSyncButtons = (root = table) => {
            root.querySelectorAll("[data-mgm-sync-storage]").forEach((button) => {
              if (button.dataset.boundSync === "1") return;
              button.dataset.boundSync = "1";
              button.addEventListener("click", () => {
                activeSyncTarget = button;
                const username = button.dataset.username || "";
                const name = button.dataset.name || username;
                modalCopy.textContent = `${name} [${username}]`;
                modalModeCopy.textContent = "This scans the Cloudflare prefix for one member and updates cached file count and used GB.";
                modalStatus.textContent = "";
                modal.hidden = false;
              });
            });
          };

          const closeModal = () => {
            modal.hidden = true;
            modalRun.disabled = false;
            activeSyncTarget = null;
          };

          const updateRowUsage = (memberId, data) => {
            const syncButtons = table.querySelectorAll(`[data-mgm-sync-storage][data-member-id="${CSS.escape(String(memberId))}"]`);
            syncButtons.forEach((button) => {
              const row = button.closest("tr");
              if (!row) return;
              const cells = row.children;
              if (cells.length < 21) return;
              cells[11].textContent = String(data.file_count ?? 0);
              cells[12].textContent = String(data.gb_used ?? 0);
              cells[13].textContent = String(data.scanned_at ?? "");
            });
          };

          const bindGroupToggle = (button) => {
            button.addEventListener("click", async () => {
              const groupId = button.dataset.mgmToggleGroup || "";
              const detailRow = table.querySelector(`[data-group-detail="${CSS.escape(groupId)}"]`);
              if (!detailRow) return;
              const memberRows = () => Array.from(table.querySelectorAll(`[data-group-member-of="${CSS.escape(groupId)}"]`));
              const hasVisibleMembers = () => memberRows().some((row) => !row.hidden);

              if (!detailRow.hidden || hasVisibleMembers()) {
                detailRow.hidden = true;
                memberRows().forEach((row) => {
                  row.hidden = true;
                });
                return;
              }

              if (detailRow.dataset.loaded === "1") {
                detailRow.hidden = true;
                memberRows().forEach((row) => {
                  row.hidden = false;
                });
                return;
              }

              detailRow.hidden = false;
              detailRow.innerHTML = '<td colspan="21">Loading users...</td>';

              try {
                const res = await fetch(`/mgm_users.php?ajax=group_members&group_id=${encodeURIComponent(groupId)}`, { credentials: "same-origin" });
                const data = await res.json();
                detailRow.insertAdjacentHTML("afterend", renderRows(groupId, Array.isArray(data.rows) ? data.rows : []));
                bindSyncButtons(table);
                detailRow.dataset.loaded = "1";
                detailRow.hidden = true;
              } catch (error) {
                detailRow.innerHTML = '<td colspan="21">Could not load users for this group.</td>';
              }
            });
          };

          table.querySelectorAll("[data-mgm-toggle-group]").forEach(bindGroupToggle);
          bindSyncButtons(table);

          modalCancel?.addEventListener("click", closeModal);
          syncAllButton?.addEventListener("click", () => {
            activeSyncTarget = null;
            modalCopy.textContent = "All members with Cloudflare files";
            modalModeCopy.textContent = "This runs one broad Cloudflare listing, groups keys by username, and updates cached storage totals for members that actually have files in Cloudflare.";
            modalStatus.textContent = "";
            modal.hidden = false;
          });
          modal?.addEventListener("click", (event) => {
            if (event.target === modal) closeModal();
          });
          modalRun?.addEventListener("click", async () => {
            const isSyncAll = !activeSyncTarget;
            modalRun.disabled = true;
            modalStatus.textContent = isSyncAll ? "Scanning Cloudflare for all users..." : "Scanning Cloudflare...";
            try {
              let res;
              if (isSyncAll) {
                res = await fetch("/mgm_storage_usage_sync_all.php", {
                  method: "POST",
                  credentials: "same-origin"
                });
              } else {
                const form = new URLSearchParams({
                  member_id: activeSyncTarget.dataset.memberId || ""
                });
                res = await fetch("/mgm_storage_usage_sync.php", {
                  method: "POST",
                  credentials: "same-origin",
                  headers: { "Content-Type": "application/x-www-form-urlencoded" },
                  body: form.toString()
                });
              }
              const data = await parseJsonResponse(res);
              if (!res.ok || !data.ok) {
                throw new Error(data.error || "Sync failed");
              }
              if (isSyncAll) {
                modalStatus.textContent = `Updated ${data.members_synced} users with ${data.files_total} files and ${data.gb_total} GB from Cloudflare storage.`;
                window.setTimeout(() => window.location.reload(), 900);
              } else {
                updateRowUsage(activeSyncTarget.dataset.memberId || "", data);
                const byType = data.by_type && typeof data.by_type === "object" ? data.by_type : {};
                const typeSummary = Object.entries(byType)
                  .sort((a, b) => Number((b[1] && b[1].bytes) || 0) - Number((a[1] && a[1].bytes) || 0))
                  .slice(0, 4)
                  .map(([type, stats]) => `${type}: ${Number(stats.files || 0)} / ${Number(stats.gb || 0)} GB`)
                  .join(" • ");
                modalStatus.textContent = typeSummary
                  ? `Updated ${data.file_count} files, ${data.gb_used} GB. ${typeSummary}`
                  : `Updated ${data.file_count} files, ${data.gb_used} GB.`;
                setTimeout(closeModal, 1200);
              }
            } catch (error) {
              modalStatus.textContent = error.message || "Sync failed";
              modalRun.disabled = false;
            }
          });

          const loadMoreGroups = async () => {
            if (!sentinel || loadingMore || !hasMore) return;
            loadingMore = true;
            sentinel.textContent = 'Loading more groups...';
            try {
              const params = new URLSearchParams({
                ajax: 'group_rows',
                offset: String(nextOffset),
                q: <?= json_encode($search) ?>
              });
              const res = await fetch(`/mgm_users.php?${params.toString()}`, { credentials: "same-origin" });
              const data = await res.json();
              const rows = Array.isArray(data.rows) ? data.rows : [];
              if (rows.length) {
                table.insertAdjacentHTML('beforeend', renderGroupRows(rows));
                bindSyncButtons(table);
                rows.forEach((row) => {
                  const button = table.querySelector(`[data-mgm-toggle-group="${CSS.escape(String(row.id))}"]`);
                  if (button) bindGroupToggle(button);
                });
                nextOffset += rows.length;
              }
              hasMore = !!data.has_more;
              sentinel.textContent = hasMore ? 'More groups load automatically while scrolling.' : 'All groups loaded.';
            } catch (error) {
              sentinel.textContent = 'Could not load more groups.';
            } finally {
              loadingMore = false;
            }
          };

          if (sentinel && 'IntersectionObserver' in window) {
            const observer = new IntersectionObserver((entries) => {
              entries.forEach((entry) => {
                if (entry.isIntersecting) {
                  loadMoreGroups();
                }
              });
            }, { rootMargin: '400px 0px' });
            observer.observe(sentinel);
          }
        })();
      </script>
<?php
mgm_render_shell_end();
