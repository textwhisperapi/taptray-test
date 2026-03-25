<?php
require_once __DIR__ . '/includes/mgm_ui.php';

$ctx = mgm_bootstrap('traffic', 'Traffic Overview');

function mgm_column_exists(mysqli $mysqli, string $table, string $column): bool {
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

function mgm_table_exists(mysqli $mysqli, string $table): bool {
    $tableEsc = $mysqli->real_escape_string($table);
    $sql = "SHOW TABLES LIKE '{$tableEsc}'";
    $result = $mysqli->query($sql);
    if (!$result instanceof mysqli_result) {
        return false;
    }
    $exists = $result->num_rows > 0;
    $result->close();
    return $exists;
}

function mgm_scalar(mysqli $mysqli, string $sql) {
    $result = $mysqli->query($sql);
    if (!$result instanceof mysqli_result) {
        return null;
    }
    $row = $result->fetch_row();
    $result->close();
    return $row[0] ?? null;
}

function mgm_tail_lines(string $path, int $lineCount = 200): array {
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }

    $handle = fopen($path, 'rb');
    if (!$handle) {
        return [];
    }

    $buffer = '';
    $chunkSize = 4096;
    fseek($handle, 0, SEEK_END);
    $position = ftell($handle);

    while ($position > 0 && substr_count($buffer, "\n") <= $lineCount) {
        $read = min($chunkSize, $position);
        $position -= $read;
        fseek($handle, $position);
        $buffer = fread($handle, $read) . $buffer;
    }

    fclose($handle);

    $lines = preg_split("/\r\n|\n|\r/", trim($buffer));
    $lines = array_values(array_filter($lines, static fn($line) => trim((string)$line) !== ''));
    return array_slice($lines, -$lineCount);
}

function mgm_parse_log_timestamp(string $line): ?DateTimeImmutable {
    if (!preg_match('/^\[(\d{2})-([A-Za-z]{3})-(\d{4}) (\d{2}):(\d{2}):(\d{2}) UTC\]/', $line, $m)) {
        return null;
    }

    $dt = DateTimeImmutable::createFromFormat(
        'd-M-Y H:i:s T',
        "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]} UTC",
        new DateTimeZone('UTC')
    );

    return $dt ?: null;
}

function mgm_parse_device_family(string $agent): string {
    $agent = strtolower($agent);
    if ($agent === '') {
        return 'Unknown';
    }
    if (strpos($agent, 'iphone') !== false || strpos($agent, 'ipad') !== false || strpos($agent, 'ios') !== false) {
        return 'iPhone / iPad';
    }
    if (strpos($agent, 'android') !== false) {
        return 'Android';
    }
    if (strpos($agent, 'windows') !== false) {
        return 'Windows';
    }
    if (strpos($agent, 'macintosh') !== false || strpos($agent, 'mac os x') !== false) {
        return 'macOS';
    }
    if (strpos($agent, 'linux') !== false) {
        return 'Linux';
    }
    return 'Other';
}

$hasMembersCreatedAt = mgm_column_exists($mysqli, 'members', 'created_at');
$hasMembersVerified = mgm_column_exists($mysqli, 'members', 'email_verified');
$hasMemberTokensExpires = mgm_column_exists($mysqli, 'member_tokens', 'expires');
$hasMemberTokensIp = mgm_column_exists($mysqli, 'member_tokens', 'ip_address');
$hasMemberTokensAgent = mgm_column_exists($mysqli, 'member_tokens', 'user_agent');
$hasMemberTokensSessionOnly = mgm_column_exists($mysqli, 'member_tokens', 'session_only');
$hasChangeLogGeneral = mgm_table_exists($mysqli, 'change_log_general');

$memberStats = [
    'total' => (int)(mgm_scalar($mysqli, "SELECT COUNT(*) FROM members") ?? 0),
    'verified' => $hasMembersVerified ? (int)(mgm_scalar($mysqli, "SELECT COUNT(*) FROM members WHERE email_verified = 1") ?? 0) : null,
    'new1d' => $hasMembersCreatedAt ? (int)(mgm_scalar($mysqli, "SELECT COUNT(*) FROM members WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL 1 DAY)") ?? 0) : null,
    'new7d' => $hasMembersCreatedAt ? (int)(mgm_scalar($mysqli, "SELECT COUNT(*) FROM members WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL 7 DAY)") ?? 0) : null,
    'new30d' => $hasMembersCreatedAt ? (int)(mgm_scalar($mysqli, "SELECT COUNT(*) FROM members WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL 30 DAY)") ?? 0) : null,
    'new1d_first_seen' => null,
    'new7d_first_seen' => null,
    'new30d_first_seen' => null,
];

if (!$hasMembersCreatedAt && mgm_column_exists($mysqli, 'member_tokens', 'created_at')) {
    $memberStats['new1d_first_seen'] = (int)(mgm_scalar($mysqli, "
        SELECT COUNT(*) FROM (
            SELECT user_id, MIN(created_at) AS first_seen
            FROM member_tokens
            GROUP BY user_id
            HAVING first_seen >= (UTC_TIMESTAMP() - INTERVAL 1 DAY)
        ) t
    ") ?? 0);
    $memberStats['new7d_first_seen'] = (int)(mgm_scalar($mysqli, "
        SELECT COUNT(*) FROM (
            SELECT user_id, MIN(created_at) AS first_seen
            FROM member_tokens
            GROUP BY user_id
            HAVING first_seen >= (UTC_TIMESTAMP() - INTERVAL 7 DAY)
        ) t
    ") ?? 0);
    $memberStats['new30d_first_seen'] = (int)(mgm_scalar($mysqli, "
        SELECT COUNT(*) FROM (
            SELECT user_id, MIN(created_at) AS first_seen
            FROM member_tokens
            GROUP BY user_id
            HAVING first_seen >= (UTC_TIMESTAMP() - INTERVAL 30 DAY)
        ) t
    ") ?? 0);
}

$sessionStats = [
    'total' => (int)(mgm_scalar($mysqli, "SELECT COUNT(*) FROM member_tokens") ?? 0),
    'remembered' => $hasMemberTokensSessionOnly ? (int)(mgm_scalar($mysqli, "SELECT COUNT(*) FROM member_tokens WHERE session_only = 0") ?? 0) : null,
    'temporary' => $hasMemberTokensSessionOnly ? (int)(mgm_scalar($mysqli, "SELECT COUNT(*) FROM member_tokens WHERE session_only = 1") ?? 0) : null,
    'expiring7d' => $hasMemberTokensExpires ? (int)(mgm_scalar($mysqli, "SELECT COUNT(*) FROM member_tokens WHERE expires < (UTC_TIMESTAMP() + INTERVAL 7 DAY)") ?? 0) : null,
    'distinctIps' => $hasMemberTokensIp ? (int)(mgm_scalar($mysqli, "SELECT COUNT(DISTINCT ip_address) FROM member_tokens WHERE ip_address IS NOT NULL AND ip_address <> ''") ?? 0) : null,
];

$planMix = [];
$result = $mysqli->query("
    SELECT COALESCE(NULLIF(plan, ''), 'free') AS plan_name, COUNT(*) AS total
    FROM members
    GROUP BY COALESCE(NULLIF(plan, ''), 'free')
    ORDER BY total DESC, plan_name ASC
");
if ($result instanceof mysqli_result) {
    while ($row = $result->fetch_assoc()) {
        $planMix[] = [
            'plan' => (string)$row['plan_name'],
            'total' => (int)$row['total'],
        ];
    }
    $result->close();
}

$verificationBreakdown = [];
if ($hasMembersVerified) {
    $result = $mysqli->query("
        SELECT email_verified, COUNT(*) AS total
        FROM members
        GROUP BY email_verified
        ORDER BY email_verified DESC
    ");
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $verificationBreakdown[] = [
                'label' => ((int)$row['email_verified'] === 1) ? 'Verified' : 'Not verified',
                'total' => (int)$row['total'],
            ];
        }
        $result->close();
    }
}

$deviceBreakdown = [];
if ($hasMemberTokensAgent) {
    $result = $mysqli->query("SELECT user_agent FROM member_tokens ORDER BY expires DESC LIMIT 500");
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $family = mgm_parse_device_family((string)($row['user_agent'] ?? ''));
            $deviceBreakdown[$family] = ($deviceBreakdown[$family] ?? 0) + 1;
        }
        $result->close();
    }
    arsort($deviceBreakdown);
}

$recentSessions = [];
if ($hasMemberTokensExpires && $hasMemberTokensAgent && $hasMemberTokensIp) {
    $result = $mysqli->query("
        SELECT
            m.username,
            m.email,
            m.plan,
            t.user_agent,
            t.ip_address,
            t.expires,
            t.created_at,
            t.session_only
        FROM member_tokens t
        JOIN members m ON m.id = t.user_id
        ORDER BY t.expires DESC
        LIMIT 18
    ");
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $recentSessions[] = [
                'username' => (string)($row['username'] ?? ''),
                'email' => (string)($row['email'] ?? ''),
                'plan' => (string)($row['plan'] ?? 'free'),
                'device' => mgm_parse_device_family((string)($row['user_agent'] ?? '')),
                'ip' => (string)($row['ip_address'] ?? ''),
                'expires' => (string)($row['expires'] ?? ''),
                'created_at' => (string)($row['created_at'] ?? ''),
                'session_only' => (int)($row['session_only'] ?? 0),
            ];
        }
        $result->close();
    }
}

$topIps = [];
if ($hasMemberTokensIp) {
    $result = $mysqli->query("
        SELECT ip_address, COUNT(*) AS session_count, COUNT(DISTINCT user_id) AS user_count
        FROM member_tokens
        WHERE ip_address IS NOT NULL AND ip_address <> ''
        GROUP BY ip_address
        ORDER BY session_count DESC, user_count DESC, ip_address ASC
        LIMIT 12
    ");
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $topIps[] = [
                'ip' => (string)$row['ip_address'],
                'sessions' => (int)$row['session_count'],
                'users' => (int)$row['user_count'],
            ];
        }
        $result->close();
    }
}

$recentSubscriptions = [];
if (mgm_column_exists($mysqli, 'members', 'subscribed_at')) {
    $result = $mysqli->query("
        SELECT username, email, plan, subscription_status, subscribed_at, storage_addon, user_addon
        FROM members
        WHERE subscribed_at IS NOT NULL
        ORDER BY subscribed_at DESC
        LIMIT 12
    ");
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $recentSubscriptions[] = [
                'username' => (string)($row['username'] ?? ''),
                'email' => (string)($row['email'] ?? ''),
                'plan' => (string)($row['plan'] ?? ''),
                'status' => (string)($row['subscription_status'] ?? ''),
                'subscribed_at' => (string)($row['subscribed_at'] ?? ''),
                'storage_addon' => (int)($row['storage_addon'] ?? 0),
                'user_addon' => (int)($row['user_addon'] ?? 0),
            ];
        }
        $result->close();
    }
}

$activityStats = [
    'events24h' => null,
    'events7d' => null,
    'events30d' => null,
    'actors24h' => null,
    'actors7d' => null,
    'actors30d' => null,
    'latest_at' => null,
];
$topActions = [];
$topActors = [];
$recentActivity = [];
$recentActorsSummary = [];
$recentActionSummary = [];
$activityByActor = [];
$activityByAction = [];
$windowEventRows = [
    '24h' => [],
    '7d' => [],
    '30d' => [],
];
$windowActorRows = [
    '24h' => [],
    '7d' => [],
    '30d' => [],
];

if ($hasChangeLogGeneral) {
    $activityStats['events24h'] = (int)(mgm_scalar($mysqli, "SELECT COUNT(*) FROM change_log_general WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL 1 DAY)") ?? 0);
    $activityStats['events7d'] = (int)(mgm_scalar($mysqli, "SELECT COUNT(*) FROM change_log_general WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL 7 DAY)") ?? 0);
    $activityStats['events30d'] = (int)(mgm_scalar($mysqli, "SELECT COUNT(*) FROM change_log_general WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL 30 DAY)") ?? 0);
    $activityStats['actors24h'] = (int)(mgm_scalar($mysqli, "SELECT COUNT(DISTINCT actor_username) FROM change_log_general WHERE actor_username IS NOT NULL AND actor_username <> '' AND created_at >= (UTC_TIMESTAMP() - INTERVAL 1 DAY)") ?? 0);
    $activityStats['actors7d'] = (int)(mgm_scalar($mysqli, "SELECT COUNT(DISTINCT actor_username) FROM change_log_general WHERE actor_username IS NOT NULL AND actor_username <> '' AND created_at >= (UTC_TIMESTAMP() - INTERVAL 7 DAY)") ?? 0);
    $activityStats['actors30d'] = (int)(mgm_scalar($mysqli, "SELECT COUNT(DISTINCT actor_username) FROM change_log_general WHERE actor_username IS NOT NULL AND actor_username <> '' AND created_at >= (UTC_TIMESTAMP() - INTERVAL 30 DAY)") ?? 0);
    $activityStats['latest_at'] = mgm_scalar($mysqli, "SELECT MAX(created_at) FROM change_log_general");

    $result = $mysqli->query("
        SELECT action, COUNT(*) AS total
        FROM change_log_general
        WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL 7 DAY)
        GROUP BY action
        ORDER BY total DESC, action ASC
        LIMIT 10
    ");
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $topActions[] = [
                'action' => (string)$row['action'],
                'total' => (int)$row['total'],
            ];
        }
        $result->close();
    }

    $result = $mysqli->query("
        SELECT actor_username, COUNT(*) AS total
        FROM change_log_general
        WHERE actor_username IS NOT NULL AND actor_username <> ''
          AND created_at >= (UTC_TIMESTAMP() - INTERVAL 7 DAY)
        GROUP BY actor_username
        ORDER BY total DESC, actor_username ASC
        LIMIT 10
    ");
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $topActors[] = [
                'actor' => (string)$row['actor_username'],
                'total' => (int)$row['total'],
            ];
        }
        $result->close();
    }

    $result = $mysqli->query("
        SELECT created_at, action, target_type, target_id, owner_username, actor_username
        FROM change_log_general
        ORDER BY created_at DESC
        LIMIT 18
    ");
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $recentActivity[] = [
                'created_at' => (string)($row['created_at'] ?? ''),
                'action' => (string)($row['action'] ?? ''),
                'target_type' => (string)($row['target_type'] ?? ''),
                'target_id' => (string)($row['target_id'] ?? ''),
                'owner_username' => (string)($row['owner_username'] ?? ''),
                'actor_username' => (string)($row['actor_username'] ?? ''),
            ];
        }
        $result->close();
    }

    $windowQueries = [
        '24h' => "created_at >= (UTC_TIMESTAMP() - INTERVAL 1 DAY)",
        '7d' => "created_at >= (UTC_TIMESTAMP() - INTERVAL 7 DAY)",
        '30d' => "created_at >= (UTC_TIMESTAMP() - INTERVAL 30 DAY)",
    ];

    foreach ($windowQueries as $windowKey => $whereClause) {
        $result = $mysqli->query("
            SELECT created_at, action, target_type, target_id, owner_username, actor_username
            FROM change_log_general
            WHERE {$whereClause}
            ORDER BY created_at DESC
            LIMIT 30
        ");
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $windowEventRows[$windowKey][] = [
                    'created_at' => (string)($row['created_at'] ?? ''),
                    'action' => (string)($row['action'] ?? ''),
                    'target_type' => (string)($row['target_type'] ?? ''),
                    'target_id' => (string)($row['target_id'] ?? ''),
                    'owner_username' => (string)($row['owner_username'] ?? ''),
                    'actor_username' => (string)($row['actor_username'] ?? ''),
                ];
            }
            $result->close();
        }

        $result = $mysqli->query("
            SELECT actor_username, COUNT(*) AS total, MAX(created_at) AS last_at
            FROM change_log_general
            WHERE actor_username IS NOT NULL
              AND actor_username <> ''
              AND {$whereClause}
            GROUP BY actor_username
            ORDER BY total DESC, last_at DESC, actor_username ASC
            LIMIT 20
        ");
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $windowActorRows[$windowKey][] = [
                    'actor' => (string)$row['actor_username'],
                    'total' => (int)$row['total'],
                    'last_at' => (string)$row['last_at'],
                ];
            }
            $result->close();
        }
    }

    foreach ($recentActivity as $row) {
        $actorKey = (string)($row['actor_username'] ?? '');
        if ($actorKey !== '') {
            if (!isset($activityByActor[$actorKey])) {
                $activityByActor[$actorKey] = [];
            }
            if (count($activityByActor[$actorKey]) < 8) {
                $activityByActor[$actorKey][] = $row;
            }
        }

        $actionKey = (string)($row['action'] ?? '');
        if ($actionKey !== '') {
            if (!isset($activityByAction[$actionKey])) {
                $activityByAction[$actionKey] = [];
            }
            if (count($activityByAction[$actionKey]) < 8) {
                $activityByAction[$actionKey][] = $row;
            }
        }
    }

    $result = $mysqli->query("
        SELECT
            actor_username,
            COUNT(*) AS total,
            MAX(created_at) AS last_at,
            GROUP_CONCAT(DISTINCT action ORDER BY action SEPARATOR ', ') AS action_list
        FROM change_log_general
        WHERE actor_username IS NOT NULL
          AND actor_username <> ''
          AND created_at >= (UTC_TIMESTAMP() - INTERVAL 7 DAY)
        GROUP BY actor_username
        ORDER BY last_at DESC, total DESC, actor_username ASC
        LIMIT 8
    ");
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $recentActorsSummary[] = [
                'actor' => (string)$row['actor_username'],
                'total' => (int)$row['total'],
                'last_at' => (string)$row['last_at'],
                'actions' => (string)($row['action_list'] ?? ''),
            ];
        }
        $result->close();
    }

    $result = $mysqli->query("
        SELECT
            action,
            COUNT(*) AS total,
            MAX(created_at) AS last_at,
            COUNT(DISTINCT actor_username) AS actor_count
        FROM change_log_general
        WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL 7 DAY)
        GROUP BY action
        ORDER BY total DESC, last_at DESC, action ASC
        LIMIT 8
    ");
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $recentActionSummary[] = [
                'action' => (string)$row['action'],
                'total' => (int)$row['total'],
                'last_at' => (string)$row['last_at'],
                'actor_count' => (int)$row['actor_count'],
            ];
        }
        $result->close();
    }
}

$logPath = __DIR__ . '/error.log';
$logLines = mgm_tail_lines($logPath, 220);
$todayUtc = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
$logStats = [
    'todayRequests' => 0,
    'todayAvgRequestMs' => null,
    'todayLoginFails' => 0,
    'todayAuthSuccess' => 0,
    'todayPasswordLogins' => 0,
    'todayRememberRestores' => 0,
    'todayWarnings' => 0,
    'recentEvents' => [],
];
$requestDurations = [];
$recentRequestEvents = [];
$recentAuthFailureEvents = [];
$recentAuthSuccessEvents = [];
$recentWarningEvents = [];

foreach ($logLines as $line) {
    $timestamp = mgm_parse_log_timestamp($line);
    if (!$timestamp) {
        continue;
    }

    if ($timestamp->format('Y-m-d') === $todayUtc) {
        if (preg_match('/⏱\s+(\d+)ms\s+—\s+DONE/u', $line, $msMatch)) {
            $logStats['todayRequests']++;
            $requestDurations[] = (int)$msMatch[1];
        }
        if (strpos($line, '[login_check] ❌ Login check failed') !== false) {
            $logStats['todayLoginFails']++;
        }
        if (strpos($line, '[process_login] Success') !== false) {
            $logStats['todayPasswordLogins']++;
            $logStats['todayAuthSuccess']++;
        }
        if (
            strpos($line, '[login_check] ✅ Restored from remember_token') !== false ||
            strpos($line, '[login_check] ✅ Auth restored mode=remember_token') !== false
        ) {
            $logStats['todayRememberRestores']++;
            $logStats['todayAuthSuccess']++;
        }
        if (strpos($line, 'PHP Warning:') !== false) {
            $logStats['todayWarnings']++;
        }
    }

    if (
        strpos($line, '[process_login]') !== false ||
        strpos($line, '[login_check]') !== false ||
        strpos($line, 'PHP Warning:') !== false ||
        strpos($line, '✅ Push subscription saved') !== false ||
        preg_match('/⏱\s+\d+ms\s+—\s+DONE/u', $line)
    ) {
        $logStats['recentEvents'][] = [
            'time' => $timestamp->format('Y-m-d H:i:s') . ' UTC',
            'message' => preg_replace('/^\[[^\]]+\]\s*/', '', $line),
        ];
    }

    if (preg_match('/⏱\s+\d+ms\s+—\s+DONE/u', $line)) {
        $recentRequestEvents[] = [
            'time' => $timestamp->format('Y-m-d H:i:s') . ' UTC',
            'message' => preg_replace('/^\[[^\]]+\]\s*/', '', $line),
        ];
    }

    if (strpos($line, '[login_check] ❌ Login check failed') !== false) {
        $recentAuthFailureEvents[] = [
            'time' => $timestamp->format('Y-m-d H:i:s') . ' UTC',
            'message' => preg_replace('/^\[[^\]]+\]\s*/', '', $line),
        ];
    }

    if (
        strpos($line, '[process_login] Success') !== false ||
        strpos($line, '[login_check] ✅ Restored from remember_token') !== false ||
        strpos($line, '[login_check] ✅ Auth restored mode=remember_token') !== false
    ) {
        $recentAuthSuccessEvents[] = [
            'time' => $timestamp->format('Y-m-d H:i:s') . ' UTC',
            'message' => preg_replace('/^\[[^\]]+\]\s*/', '', $line),
        ];
    }

    if (strpos($line, 'PHP Warning:') !== false) {
        $recentWarningEvents[] = [
            'time' => $timestamp->format('Y-m-d H:i:s') . ' UTC',
            'message' => preg_replace('/^\[[^\]]+\]\s*/', '', $line),
        ];
    }
}

if ($requestDurations) {
    $logStats['todayAvgRequestMs'] = (int)round(array_sum($requestDurations) / count($requestDurations));
}

$logStats['recentEvents'] = array_slice(array_reverse($logStats['recentEvents']), 0, 12);
$recentRequestEvents = array_slice(array_reverse($recentRequestEvents), 0, 8);
$recentAuthFailureEvents = array_slice(array_reverse($recentAuthFailureEvents), 0, 8);
$recentAuthSuccessEvents = array_slice(array_reverse($recentAuthSuccessEvents), 0, 8);
$recentWarningEvents = array_slice(array_reverse($recentWarningEvents), 0, 8);

mgm_render_shell_start(
    $ctx,
    'Traffic Overview',
    'A first operational view of traffic from the data this app already records: account state, session footprint, who is active, what they are doing approximately, and whether they are hitting errors.'
);
?>
      <style>
        .mgm-disclosure {
          background: var(--mgm-panel);
          border: 1px solid var(--mgm-border);
          border-radius: 18px;
          box-shadow: var(--mgm-shadow);
          overflow: hidden;
        }
        .mgm-disclosure + .mgm-disclosure {
          margin-top: 14px;
        }
        .mgm-disclosure summary {
          list-style: none;
          cursor: pointer;
          padding: 18px 22px;
        }
        .mgm-disclosure summary::-webkit-details-marker {
          display: none;
        }
        .mgm-disclosure[open] summary {
          border-bottom: 1px solid #e7edf5;
          background: #fbfcfe;
        }
        .mgm-disclosure-body {
          padding: 18px 22px 22px;
        }
        .mgm-signal-link {
          display: block;
          text-decoration: none;
          color: inherit;
        }
        .mgm-signal-link + .mgm-signal-link {
          margin-top: 12px;
        }
        .mgm-signal-card {
          display: block;
          width: 100%;
          padding: 0;
          border: 0;
          background: transparent;
          text-align: left;
          cursor: pointer;
        }
        .mgm-signal-shell {
          padding: 14px 16px;
          border-radius: 16px;
          background: var(--mgm-panel-soft);
          border: 1px solid #dde6f1;
        }
        .mgm-signal-meta {
          display: flex;
          gap: 12px;
          align-items: center;
          flex-wrap: wrap;
        }
        .mgm-signal-text {
          margin: 0;
          font-size: 14px;
          line-height: 1.45;
        }
        .mgm-signal-detail {
          margin-top: 12px;
          padding-top: 12px;
          border-top: 1px solid #dce5f0;
        }
        .mgm-summary-row {
          display: flex;
          justify-content: space-between;
          gap: 14px;
          align-items: flex-start;
        }
        .mgm-summary-main {
          min-width: 0;
        }
        .mgm-summary-main strong {
          display: block;
          font-size: 16px;
          line-height: 1.2;
        }
        .mgm-summary-sub {
          margin-top: 4px;
          color: var(--mgm-muted);
          font-size: 13px;
          line-height: 1.4;
        }
        .mgm-summary-metric {
          white-space: nowrap;
          font: 700 13px/1.2 "Trebuchet MS", sans-serif;
          color: var(--mgm-accent);
        }
        .mgm-inline-disclosure {
          border-radius: 14px;
          overflow: hidden;
          border: 1px solid #dde6f1;
          background: var(--mgm-panel-soft);
        }
        .mgm-inline-disclosure + .mgm-inline-disclosure {
          margin-top: 10px;
        }
        .mgm-inline-disclosure summary {
          list-style: none;
          cursor: pointer;
          padding: 12px 14px;
        }
        .mgm-inline-disclosure summary::-webkit-details-marker {
          display: none;
        }
        .mgm-inline-disclosure[open] summary {
          border-bottom: 1px solid #dce5f0;
          background: #f8fbff;
        }
        .mgm-inline-body {
          padding: 12px 14px 14px;
        }
        .mgm-pop-card {
          display: block;
          width: 100%;
          text-align: left;
          border: 1px solid #dde6f1;
          background: var(--mgm-panel-soft);
          border-radius: 14px;
          padding: 12px 14px;
          cursor: pointer;
          color: inherit;
        }
        .mgm-pop-card + .mgm-pop-card {
          margin-top: 10px;
        }
        .mgm-pop-card:hover {
          background: #f8fbff;
          border-color: #cfdbe9;
        }
        .mgm-modal[hidden] {
          display: none;
        }
        .mgm-modal {
          position: fixed;
          inset: 0;
          z-index: 1000;
        }
        .mgm-modal-backdrop {
          position: absolute;
          inset: 0;
          background: rgba(15, 23, 42, 0.45);
        }
        .mgm-modal-card {
          position: relative;
          z-index: 1;
          width: min(960px, calc(100vw - 32px));
          max-height: calc(100vh - 48px);
          overflow: auto;
          margin: 24px auto;
          background: var(--mgm-panel);
          border: 1px solid var(--mgm-border);
          border-radius: 22px;
          box-shadow: 0 30px 70px rgba(15, 23, 42, 0.25);
          padding: 22px;
        }
        .mgm-modal-head {
          display: flex;
          justify-content: space-between;
          gap: 16px;
          align-items: flex-start;
          margin-bottom: 14px;
        }
        .mgm-modal-head h3 {
          margin: 0;
        }
        .mgm-modal-close {
          border: 1px solid #d7e0eb;
          background: #fff;
          border-radius: 999px;
          padding: 8px 12px;
          cursor: pointer;
          font: 700 12px/1 "Trebuchet MS", sans-serif;
          letter-spacing: 0.06em;
          text-transform: uppercase;
        }
        .mgm-kv-button {
          appearance: none;
          background: transparent;
          border: 0;
          padding: 0;
          color: inherit;
          font: inherit;
          cursor: pointer;
          text-align: left;
        }
        .mgm-kv-button:hover {
          color: var(--mgm-accent);
        }
        .mgm-kv-click {
          display: contents;
        }
        .mgm-kv-click dt,
        .mgm-kv-click dd {
          cursor: pointer;
        }
        .mgm-kv-click:hover dt,
        .mgm-kv-click:hover dd {
          color: var(--mgm-accent);
        }
      </style>
      <section class="mgm-grid cols-4">
        <article class="mgm-panel">
          <p class="mgm-stat-label">Members</p>
          <p class="mgm-stat-value"><?= number_format($memberStats['total']) ?></p>
          <p class="mgm-stat-note">
            <?php if ($memberStats['verified'] !== null): ?>
              <?= number_format($memberStats['verified']) ?> verified accounts
            <?php else: ?>
              Verification data not available in schema
            <?php endif; ?>
          </p>
        </article>
        <article class="mgm-panel">
          <p class="mgm-stat-label">Saved Sessions</p>
          <p class="mgm-stat-value"><?= number_format($sessionStats['total']) ?></p>
          <p class="mgm-stat-note">
            <?php if ($sessionStats['remembered'] !== null): ?>
              <?= number_format($sessionStats['remembered']) ?> remembered and <?= number_format((int)$sessionStats['temporary']) ?> temporary
            <?php else: ?>
              Session split not available
            <?php endif; ?>
          </p>
        </article>
        <article class="mgm-panel">
          <p class="mgm-stat-label">Requests Today</p>
          <p class="mgm-stat-value"><?= number_format($logStats['todayRequests']) ?></p>
          <p class="mgm-stat-note">
            <?php if ($logStats['todayAvgRequestMs'] !== null): ?>
              Average completion <?= number_format($logStats['todayAvgRequestMs']) ?> ms
            <?php else: ?>
              No request-duration markers found today
            <?php endif; ?>
          </p>
        </article>
        <article class="mgm-panel">
          <p class="mgm-stat-label">Auth Signals Today</p>
          <p class="mgm-stat-value"><?= number_format($logStats['todayLoginFails']) ?></p>
          <p class="mgm-stat-note">
            <?= number_format($logStats['todayAuthSuccess']) ?> auth successes, <?= number_format($logStats['todayWarnings']) ?> PHP warnings
          </p>
        </article>
      </section>

      <section class="mgm-grid cols-3" style="margin-top:18px;">
        <article class="mgm-panel">
          <h2>Recorded Content Activity</h2>
          <p class="mgm-panel-intro">This is not overall system usage. It comes only from `change_log_general`, which currently records a partial set of content-edit actions, mainly `create` and `upload` from specific endpoints.</p>
          <ul class="mgm-list" style="margin-bottom:14px;">
            <li><strong>Actor</strong> means the username recorded as `actor_username` on a logged content action.</li>
            <li><strong>Event</strong> means one row in `change_log_general`, for example a single `create` or `upload` action.</li>
          </ul>
          <dl class="mgm-kv">
            <button type="button" class="mgm-kv-button mgm-kv-click" data-mgm-open-modal="content-events-24h">
              <dt>Recorded events, 24h</dt>
              <dd><?= $activityStats['events24h'] !== null ? number_format($activityStats['events24h']) : 'Unavailable' ?></dd>
            </button>
            <button type="button" class="mgm-kv-button mgm-kv-click" data-mgm-open-modal="content-actors-24h">
              <dt>Recorded actors, 24h</dt>
              <dd><?= $activityStats['actors24h'] !== null ? number_format($activityStats['actors24h']) : 'Unavailable' ?></dd>
            </button>
            <button type="button" class="mgm-kv-button mgm-kv-click" data-mgm-open-modal="content-events-7d">
              <dt>Recorded events, 7d</dt>
              <dd><?= $activityStats['events7d'] !== null ? number_format($activityStats['events7d']) : 'Unavailable' ?></dd>
            </button>
            <button type="button" class="mgm-kv-button mgm-kv-click" data-mgm-open-modal="content-events-30d">
              <dt>Recorded events, 30d</dt>
              <dd><?= $activityStats['events30d'] !== null ? number_format($activityStats['events30d']) : 'Unavailable' ?></dd>
            </button>
            <button type="button" class="mgm-kv-button mgm-kv-click" data-mgm-open-modal="content-actors-7d">
              <dt>Recorded actors, 7d</dt>
              <dd><?= $activityStats['actors7d'] !== null ? number_format($activityStats['actors7d']) : 'Unavailable' ?></dd>
            </button>
            <button type="button" class="mgm-kv-button mgm-kv-click" data-mgm-open-modal="content-actors-30d">
              <dt>Recorded actors, 30d</dt>
              <dd><?= $activityStats['actors30d'] !== null ? number_format($activityStats['actors30d']) : 'Unavailable' ?></dd>
            </button>
            <dt>Saved sessions total</dt>
            <dd><?= number_format($sessionStats['total']) ?></dd>
          </dl>
          <?php if (($activityStats['events24h'] ?? 0) === 0 && !empty($activityStats['latest_at'])): ?>
            <p class="mgm-panel-intro" style="margin-top:14px;">No recorded content actions in the last 24 hours. Last recorded content event: <?= mgm_h((string)$activityStats['latest_at']) ?> UTC.</p>
          <?php endif; ?>
        </article>

        <article class="mgm-panel">
          <h2>Recent Actors</h2>
          <p class="mgm-panel-intro">Summarized by actor first. Here, an actor is the username attached to logged content actions.</p>
          <?php if ($recentActorsSummary): ?>
            <?php foreach ($recentActorsSummary as $row): ?>
              <button
                type="button"
                class="mgm-pop-card"
                data-mgm-open-modal="actor-<?= mgm_h($row['actor']) ?>"
              >
                <div class="mgm-summary-row">
                  <div class="mgm-summary-main">
                    <strong><?= mgm_h($row['actor']) ?></strong>
                    <div class="mgm-summary-sub">
                      <?= mgm_h($row['last_at']) ?>
                      <?php if ($row['actions'] !== ''): ?>
                        <br><?= mgm_h($row['actions']) ?>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="mgm-summary-metric"><?= number_format($row['total']) ?> events</div>
                </div>
              </button>
            <?php endforeach; ?>
          <?php else: ?>
            <p class="mgm-panel-intro">No recent recorded actors yet.</p>
          <?php endif; ?>
        </article>

        <article class="mgm-panel">
          <h2>Member Growth</h2>
          <p class="mgm-panel-intro">
            This is based on the `members` table.
            <?php if ($hasMembersCreatedAt): ?>
              Signup recency is using `members.created_at`.
            <?php else: ?>
              This database does not have `members.created_at`, so recent counts below use first seen login/session time from `member_tokens.created_at` as the fallback.
            <?php endif; ?>
          </p>
          <dl class="mgm-kv">
            <dt>Total accounts</dt>
            <dd><?= number_format($memberStats['total']) ?></dd>
            <dt>Verified accounts</dt>
            <dd><?= $memberStats['verified'] !== null ? number_format($memberStats['verified']) : 'Unavailable' ?></dd>
            <dt><?= $hasMembersCreatedAt ? 'New today' : 'First seen today' ?></dt>
            <dd><?= $hasMembersCreatedAt ? number_format((int)$memberStats['new1d']) : number_format((int)$memberStats['new1d_first_seen']) ?></dd>
            <dt><?= $hasMembersCreatedAt ? 'New in last 7 days' : 'First seen in last 7 days' ?></dt>
            <dd><?= $hasMembersCreatedAt ? number_format((int)$memberStats['new7d']) : number_format((int)$memberStats['new7d_first_seen']) ?></dd>
            <dt><?= $hasMembersCreatedAt ? 'New in last 30 days' : 'First seen in last 30 days' ?></dt>
            <dd><?= $hasMembersCreatedAt ? number_format((int)$memberStats['new30d']) : number_format((int)$memberStats['new30d_first_seen']) ?></dd>
          </dl>
        </article>

        <article class="mgm-panel">
          <h2>Session Footprint</h2>
          <p class="mgm-panel-intro">Current session storage footprint from `member_tokens`.</p>
          <dl class="mgm-kv">
            <dt>Total saved tokens</dt>
            <dd><?= number_format($sessionStats['total']) ?></dd>
            <dt>Remembered browsers</dt>
            <dd><?= $sessionStats['remembered'] !== null ? number_format($sessionStats['remembered']) : 'Unavailable' ?></dd>
            <dt>Temporary sessions</dt>
            <dd><?= $sessionStats['temporary'] !== null ? number_format((int)$sessionStats['temporary']) : 'Unavailable' ?></dd>
            <dt>Expiring within 7 days</dt>
            <dd><?= $sessionStats['expiring7d'] !== null ? number_format($sessionStats['expiring7d']) : 'Unavailable' ?></dd>
            <dt>Distinct IPs</dt>
            <dd><?= $sessionStats['distinctIps'] !== null ? number_format($sessionStats['distinctIps']) : 'Unavailable' ?></dd>
          </dl>
        </article>

        <article class="mgm-panel">
          <h2>Application Signals</h2>
          <p class="mgm-panel-intro">Derived from the local [error.log](/var/www/textwhisper-test/error.log) file.</p>
          <details class="mgm-signal-link">
            <summary class="mgm-signal-card">
              <div class="mgm-signal-shell">
                <div class="mgm-signal-meta">
                  <span class="mgm-pill">Today</span>
                  <p class="mgm-signal-text"><?= number_format($logStats['todayRequests']) ?> request completion markers logged.</p>
                </div>
              </div>
            </summary>
            <div class="mgm-signal-shell mgm-signal-detail">
              <p class="mgm-panel-intro">These markers come from the existing `⏱ … DONE` log entries in [error.log](/var/www/textwhisper-test/error.log).</p>
              <dl class="mgm-kv">
                <dt>Completed requests today</dt>
                <dd><?= number_format($logStats['todayRequests']) ?></dd>
                <dt>Average request time</dt>
                <dd><?= $logStats['todayAvgRequestMs'] !== null ? number_format($logStats['todayAvgRequestMs']) . ' ms' : 'Unavailable' ?></dd>
              </dl>
              <?php if ($recentRequestEvents): ?>
                <div class="mgm-table-wrap" style="margin-top:14px;">
                  <table>
                    <tr><th>Time</th><th>Recent Request Completion</th></tr>
                    <?php foreach ($recentRequestEvents as $event): ?>
                      <tr>
                        <td><?= mgm_h($event['time']) ?></td>
                        <td><?= mgm_h($event['message']) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </table>
                </div>
              <?php endif; ?>
            </div>
          </details>

          <details class="mgm-signal-link">
            <summary class="mgm-signal-card">
              <div class="mgm-signal-shell">
                <div class="mgm-signal-meta">
                  <span class="mgm-pill warn">Auth</span>
                  <p class="mgm-signal-text"><?= number_format($logStats['todayLoginFails']) ?> unauthenticated session checks logged today.</p>
                </div>
              </div>
            </summary>
            <div class="mgm-signal-shell mgm-signal-detail">
              <p class="mgm-panel-intro">This reflects `[login_check] ❌ Login check failed` entries. These are usually session/auth misses such as expired cookies, anonymous requests, stale browser state, or frontend calls after logout. They are not the same thing as wrong-password attempts.</p>
              <dl class="mgm-kv">
                <dt>Session/auth misses today</dt>
                <dd><?= number_format($logStats['todayLoginFails']) ?></dd>
                <dt>Auth successes today</dt>
                <dd><?= number_format($logStats['todayAuthSuccess']) ?></dd>
              </dl>
              <?php if ($recentAuthFailureEvents): ?>
                <div class="mgm-table-wrap" style="margin-top:14px;">
                  <table>
                    <tr><th>Time</th><th>Recent Session/Auth Miss</th></tr>
                    <?php foreach ($recentAuthFailureEvents as $event): ?>
                      <tr>
                        <td><?= mgm_h($event['time']) ?></td>
                        <td><?= mgm_h($event['message']) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </table>
                </div>
              <?php endif; ?>
            </div>
          </details>

          <details class="mgm-signal-link">
            <summary class="mgm-signal-card">
              <div class="mgm-signal-shell">
                <div class="mgm-signal-meta">
                  <span class="mgm-pill">Auth</span>
                  <p class="mgm-signal-text"><?= number_format($logStats['todayAuthSuccess']) ?> auth successes logged today.</p>
                </div>
              </div>
            </summary>
            <div class="mgm-signal-shell mgm-signal-detail">
              <p class="mgm-panel-intro">This combines password-form logins and remember-token restores. It still does not include every possible auth path, only the ones that emit these current log markers.</p>
              <dl class="mgm-kv">
                <dt>Password logins today</dt>
                <dd><?= number_format($logStats['todayPasswordLogins']) ?></dd>
                <dt>Remember-token restores today</dt>
                <dd><?= number_format($logStats['todayRememberRestores']) ?></dd>
                <dt>Saved sessions total</dt>
                <dd><?= number_format($sessionStats['total']) ?></dd>
              </dl>
              <?php if ($recentAuthSuccessEvents): ?>
                <div class="mgm-table-wrap" style="margin-top:14px;">
                  <table>
                    <tr><th>Time</th><th>Recent Auth Success</th></tr>
                    <?php foreach ($recentAuthSuccessEvents as $event): ?>
                      <tr>
                        <td><?= mgm_h($event['time']) ?></td>
                        <td><?= mgm_h($event['message']) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </table>
                </div>
              <?php endif; ?>
            </div>
          </details>

          <details class="mgm-signal-link">
            <summary class="mgm-signal-card">
              <div class="mgm-signal-shell">
                <div class="mgm-signal-meta">
                  <span class="mgm-pill danger">Warnings</span>
                  <p class="mgm-signal-text"><?= number_format($logStats['todayWarnings']) ?> PHP warnings logged today.</p>
                </div>
              </div>
            </summary>
            <div class="mgm-signal-shell mgm-signal-detail">
              <p class="mgm-panel-intro">Warnings are pulled from the same log stream and usually point to code paths worth checking before they become user-visible failures.</p>
              <dl class="mgm-kv">
                <dt>Warnings today</dt>
                <dd><?= number_format($logStats['todayWarnings']) ?></dd>
                <dt>Recent log events shown below</dt>
                <dd><?= number_format(count($logStats['recentEvents'])) ?></dd>
              </dl>
              <?php if ($recentWarningEvents): ?>
                <div class="mgm-table-wrap" style="margin-top:14px;">
                  <table>
                    <tr><th>Time</th><th>Recent Warning</th></tr>
                    <?php foreach ($recentWarningEvents as $event): ?>
                      <tr>
                        <td><?= mgm_h($event['time']) ?></td>
                        <td><?= mgm_h($event['message']) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </table>
                </div>
              <?php endif; ?>
            </div>
          </details>
        </article>
      </section>

      <section class="mgm-grid cols-2" style="margin-top:18px;">
        <article class="mgm-panel">
          <h2>Device Mix</h2>
          <p class="mgm-panel-intro">Approximate family split from `member_tokens.user_agent`.</p>
          <?php if ($deviceBreakdown): ?>
            <div class="mgm-table-wrap">
              <table>
                <tr><th>Device Family</th><th>Saved Sessions</th></tr>
                <?php foreach (array_slice($deviceBreakdown, 0, 8, true) as $family => $count): ?>
                  <tr>
                    <td><?= mgm_h($family) ?></td>
                    <td><?= number_format($count) ?></td>
                  </tr>
                <?php endforeach; ?>
              </table>
            </div>
          <?php else: ?>
            <p class="mgm-panel-intro">No `user_agent` data available to build a device split.</p>
          <?php endif; ?>
        </article>

        <article class="mgm-panel">
          <h2>Origin Footprint</h2>
          <p class="mgm-panel-intro">Current origin data is at IP level. Country/world mapping is not available yet because this host does not have a GeoIP database installed.</p>
          <ul class="mgm-list">
            <li><?= number_format($sessionStats['distinctIps'] ?? 0) ?> distinct IPs are currently visible in saved session storage.</li>
            <li>The Top IP Footprint table below is the current drillable source for “from where”.</li>
            <li>If you want a true world map next, the clean next step is installing a local GeoLite database and resolving IPs to country codes server-side.</li>
          </ul>
        </article>
      </section>

      <section class="mgm-grid cols-2" style="margin-top:18px;">
        <article class="mgm-panel">
          <h2>Top Actions</h2>
          <p class="mgm-panel-intro">Summarized by action type first. Each count is the number of logged event rows for that action.</p>
          <?php if ($recentActionSummary): ?>
            <?php foreach ($recentActionSummary as $row): ?>
              <button
                type="button"
                class="mgm-pop-card"
                data-mgm-open-modal="action-<?= mgm_h($row['action']) ?>"
              >
                <div class="mgm-summary-row">
                  <div class="mgm-summary-main">
                    <strong><?= mgm_h($row['action']) ?></strong>
                    <div class="mgm-summary-sub">
                      <?= number_format($row['actor_count']) ?> actors
                      <br>latest: <?= mgm_h($row['last_at']) ?>
                    </div>
                  </div>
                  <div class="mgm-summary-metric"><?= number_format($row['total']) ?> events</div>
                </div>
              </button>
            <?php endforeach; ?>
          <?php else: ?>
            <p class="mgm-panel-intro">No general activity rows are available yet.</p>
          <?php endif; ?>
        </article>

        <article class="mgm-panel">
          <h2>Top Actors</h2>
          <p class="mgm-panel-intro">Who has been doing the most recorded activity in the last 7 days.</p>
          <?php if ($topActors): ?>
            <div class="mgm-table-wrap">
              <table>
                <tr><th>User</th><th>Events, 7d</th></tr>
                <?php foreach ($topActors as $row): ?>
                  <tr>
                    <td><?= mgm_h($row['actor']) ?></td>
                    <td><?= number_format($row['total']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </table>
            </div>
          <?php else: ?>
            <p class="mgm-panel-intro">No actor activity rows are available yet.</p>
          <?php endif; ?>
        </article>
      </section>

      <section class="mgm-grid cols-2" style="margin-top:18px;">
        <article class="mgm-panel">
          <h2>Plan Mix</h2>
          <p class="mgm-panel-intro">Current account distribution by stored plan value.</p>
          <?php if ($planMix): ?>
            <div class="mgm-table-wrap">
              <table>
                <tr><th>Plan</th><th>Accounts</th></tr>
                <?php foreach ($planMix as $row): ?>
                  <tr>
                    <td><?= mgm_h($row['plan']) ?></td>
                    <td><?= number_format($row['total']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </table>
            </div>
          <?php else: ?>
            <p class="mgm-panel-intro">No plan distribution data available.</p>
          <?php endif; ?>
        </article>

        <article class="mgm-panel">
          <h2>Verification Split</h2>
          <p class="mgm-panel-intro">How many stored accounts are verified versus unverified.</p>
          <?php if ($verificationBreakdown): ?>
            <div class="mgm-table-wrap">
              <table>
                <tr><th>Status</th><th>Accounts</th></tr>
                <?php foreach ($verificationBreakdown as $row): ?>
                  <tr>
                    <td><?= mgm_h($row['label']) ?></td>
                    <td><?= number_format($row['total']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </table>
            </div>
          <?php else: ?>
            <p class="mgm-panel-intro">Verification state is not available in this schema.</p>
          <?php endif; ?>
        </article>
      </section>

      <section class="mgm-grid cols-2" style="margin-top:18px;">
        <details class="mgm-disclosure">
          <summary>
            <h2 style="margin:0;">Recent Saved Sessions</h2>
            <p class="mgm-panel-intro" style="margin:8px 0 0;">Latest stored browser sessions from `member_tokens`, joined to accounts.</p>
          </summary>
          <div class="mgm-disclosure-body">
            <?php if ($recentSessions): ?>
              <div class="mgm-table-wrap">
                <table>
                  <tr>
                    <th>User</th>
                    <th>Plan</th>
                    <th>Device</th>
                    <th>IP</th>
                    <th>Created</th>
                    <th>Expires</th>
                    <th>Type</th>
                  </tr>
                  <?php foreach ($recentSessions as $row): ?>
                    <tr>
                      <td><?= mgm_h($row['username']) ?><br><span style="color:#5d6b80;"><?= mgm_h($row['email']) ?></span></td>
                      <td><?= mgm_h($row['plan'] !== '' ? $row['plan'] : 'free') ?></td>
                      <td><?= mgm_h($row['device']) ?></td>
                      <td><?= mgm_h($row['ip']) ?></td>
                      <td><?= mgm_h($row['created_at']) ?></td>
                      <td><?= mgm_h($row['expires']) ?></td>
                      <td><?= $row['session_only'] ? 'Temporary' : 'Remembered' ?></td>
                    </tr>
                  <?php endforeach; ?>
                </table>
              </div>
            <?php else: ?>
              <p class="mgm-panel-intro">No recent session rows available.</p>
            <?php endif; ?>
          </div>
        </details>

        <details class="mgm-disclosure">
          <summary>
            <h2 style="margin:0;">Top IP Footprint</h2>
            <p class="mgm-panel-intro" style="margin:8px 0 0;">IPs with the highest number of saved sessions.</p>
          </summary>
          <div class="mgm-disclosure-body">
            <?php if ($topIps): ?>
              <div class="mgm-table-wrap">
                <table>
                  <tr><th>IP Address</th><th>Saved Sessions</th><th>Distinct Users</th></tr>
                  <?php foreach ($topIps as $row): ?>
                    <tr>
                      <td><?= mgm_h($row['ip']) ?></td>
                      <td><?= number_format($row['sessions']) ?></td>
                      <td><?= number_format($row['users']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </table>
              </div>
            <?php else: ?>
              <p class="mgm-panel-intro">No IP data available in saved sessions.</p>
            <?php endif; ?>
          </div>
        </details>
      </section>

      <section class="mgm-panel" style="margin-top:18px;">
        <h2>Recent Subscription Activity</h2>
        <p class="mgm-panel-intro">Most recently updated subscription records from `members.subscribed_at`.</p>
        <?php if ($recentSubscriptions): ?>
          <div class="mgm-table-wrap">
            <table>
              <tr>
                <th>User</th>
                <th>Plan</th>
                <th>Status</th>
                <th>Storage Add-on</th>
                <th>User Add-on</th>
                <th>Subscribed At</th>
              </tr>
              <?php foreach ($recentSubscriptions as $row): ?>
                <tr>
                  <td><?= mgm_h($row['username']) ?><br><span style="color:#5d6b80;"><?= mgm_h($row['email']) ?></span></td>
                  <td><?= mgm_h($row['plan'] !== '' ? $row['plan'] : 'free') ?></td>
                  <td><?= mgm_h($row['status'] !== '' ? $row['status'] : 'unknown') ?></td>
                  <td><?= number_format($row['storage_addon']) ?> GB</td>
                  <td><?= number_format($row['user_addon']) ?></td>
                  <td><?= mgm_h($row['subscribed_at']) ?></td>
                </tr>
              <?php endforeach; ?>
            </table>
          </div>
        <?php else: ?>
          <p class="mgm-panel-intro">No recent subscription timestamps are available.</p>
        <?php endif; ?>
      </section>

      <details class="mgm-disclosure" style="margin-top:18px;">
        <summary>
          <h2 style="margin:0;">Detailed Activity Feed</h2>
          <p class="mgm-panel-intro" style="margin:8px 0 0;">Drill into the latest raw activity rows from `change_log_general` when the summaries above are not enough.</p>
        </summary>
        <div class="mgm-disclosure-body">
          <?php if ($recentActivity): ?>
            <div class="mgm-table-wrap">
              <table>
                <tr>
                  <th>Time</th>
                  <th>Actor</th>
                  <th>Action</th>
                  <th>Target</th>
                  <th>Owner</th>
                </tr>
                <?php foreach ($recentActivity as $row): ?>
                  <tr>
                    <td><?= mgm_h($row['created_at']) ?></td>
                    <td><?= mgm_h($row['actor_username'] !== '' ? $row['actor_username'] : 'unknown') ?></td>
                    <td><?= mgm_h($row['action']) ?></td>
                    <td><?= mgm_h($row['target_type']) ?><?= $row['target_id'] !== '' ? ' #' . mgm_h($row['target_id']) : '' ?></td>
                    <td><?= mgm_h($row['owner_username'] !== '' ? $row['owner_username'] : 'unknown') ?></td>
                  </tr>
                <?php endforeach; ?>
              </table>
            </div>
          <?php else: ?>
            <p class="mgm-panel-intro">No general activity rows are available yet.</p>
          <?php endif; ?>
        </div>
      </details>

      <?php foreach ($recentActorsSummary as $row): ?>
        <?php $actorEvents = $activityByActor[$row['actor']] ?? []; ?>
        <div class="mgm-modal" hidden data-mgm-modal="actor-<?= mgm_h($row['actor']) ?>">
          <div class="mgm-modal-backdrop" data-mgm-close-modal></div>
          <div class="mgm-modal-card" role="dialog" aria-modal="true" aria-labelledby="mgm-modal-actor-<?= mgm_h($row['actor']) ?>">
            <div class="mgm-modal-head">
              <div>
                <h3 id="mgm-modal-actor-<?= mgm_h($row['actor']) ?>"><?= mgm_h($row['actor']) ?></h3>
                <p class="mgm-panel-intro">Recent logged content events for this actor.</p>
              </div>
              <button type="button" class="mgm-modal-close" data-mgm-close-modal>Close</button>
            </div>
            <?php if ($actorEvents): ?>
              <div class="mgm-table-wrap">
                <table>
                  <tr><th>Time</th><th>Action</th><th>Target</th><th>Owner</th></tr>
                  <?php foreach ($actorEvents as $event): ?>
                    <tr>
                      <td><?= mgm_h($event['created_at']) ?></td>
                      <td><?= mgm_h($event['action']) ?></td>
                      <td><?= mgm_h($event['target_type']) ?><?= $event['target_id'] !== '' ? ' #' . mgm_h($event['target_id']) : '' ?></td>
                      <td><?= mgm_h($event['owner_username'] !== '' ? $event['owner_username'] : 'unknown') ?></td>
                    </tr>
                  <?php endforeach; ?>
                </table>
              </div>
            <?php else: ?>
              <p class="mgm-panel-intro">No recent detailed rows available for this actor.</p>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>

      <?php foreach ($recentActionSummary as $row): ?>
        <?php $actionEvents = $activityByAction[$row['action']] ?? []; ?>
        <div class="mgm-modal" hidden data-mgm-modal="action-<?= mgm_h($row['action']) ?>">
          <div class="mgm-modal-backdrop" data-mgm-close-modal></div>
          <div class="mgm-modal-card" role="dialog" aria-modal="true" aria-labelledby="mgm-modal-action-<?= mgm_h($row['action']) ?>">
            <div class="mgm-modal-head">
              <div>
                <h3 id="mgm-modal-action-<?= mgm_h($row['action']) ?>"><?= mgm_h($row['action']) ?></h3>
                <p class="mgm-panel-intro">Recent logged content events for this action type.</p>
              </div>
              <button type="button" class="mgm-modal-close" data-mgm-close-modal>Close</button>
            </div>
            <?php if ($actionEvents): ?>
              <div class="mgm-table-wrap">
                <table>
                  <tr><th>Time</th><th>Actor</th><th>Target</th><th>Owner</th></tr>
                  <?php foreach ($actionEvents as $event): ?>
                    <tr>
                      <td><?= mgm_h($event['created_at']) ?></td>
                      <td><?= mgm_h($event['actor_username'] !== '' ? $event['actor_username'] : 'unknown') ?></td>
                      <td><?= mgm_h($event['target_type']) ?><?= $event['target_id'] !== '' ? ' #' . mgm_h($event['target_id']) : '' ?></td>
                      <td><?= mgm_h($event['owner_username'] !== '' ? $event['owner_username'] : 'unknown') ?></td>
                    </tr>
                  <?php endforeach; ?>
                </table>
              </div>
            <?php else: ?>
              <p class="mgm-panel-intro">No recent detailed rows available for this action.</p>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>

      <?php
        $windowTitles = [
          '24h' => 'Last 24 Hours',
          '7d' => 'Last 7 Days',
          '30d' => 'Last 30 Days',
        ];
      ?>
      <?php foreach ($windowTitles as $windowKey => $windowLabel): ?>
        <div class="mgm-modal" hidden data-mgm-modal="content-events-<?= mgm_h($windowKey) ?>">
          <div class="mgm-modal-backdrop" data-mgm-close-modal></div>
          <div class="mgm-modal-card" role="dialog" aria-modal="true">
            <div class="mgm-modal-head">
              <div>
                <h3>Recorded Content Events: <?= mgm_h($windowLabel) ?></h3>
                <p class="mgm-panel-intro">Raw logged content-action rows for this time window.</p>
              </div>
              <button type="button" class="mgm-modal-close" data-mgm-close-modal>Close</button>
            </div>
            <?php if (!empty($windowEventRows[$windowKey])): ?>
              <div class="mgm-table-wrap">
                <table>
                  <tr><th>Time</th><th>Actor</th><th>Action</th><th>Target</th><th>Owner</th></tr>
                  <?php foreach ($windowEventRows[$windowKey] as $event): ?>
                    <tr>
                      <td><?= mgm_h($event['created_at']) ?></td>
                      <td><?= mgm_h($event['actor_username'] !== '' ? $event['actor_username'] : 'unknown') ?></td>
                      <td><?= mgm_h($event['action']) ?></td>
                      <td><?= mgm_h($event['target_type']) ?><?= $event['target_id'] !== '' ? ' #' . mgm_h($event['target_id']) : '' ?></td>
                      <td><?= mgm_h($event['owner_username'] !== '' ? $event['owner_username'] : 'unknown') ?></td>
                    </tr>
                  <?php endforeach; ?>
                </table>
              </div>
            <?php else: ?>
              <p class="mgm-panel-intro">No recorded content-action rows in this window.</p>
            <?php endif; ?>
          </div>
        </div>

        <div class="mgm-modal" hidden data-mgm-modal="content-actors-<?= mgm_h($windowKey) ?>">
          <div class="mgm-modal-backdrop" data-mgm-close-modal></div>
          <div class="mgm-modal-card" role="dialog" aria-modal="true">
            <div class="mgm-modal-head">
              <div>
                <h3>Recorded Content Actors: <?= mgm_h($windowLabel) ?></h3>
                <p class="mgm-panel-intro">Distinct actors with logged content actions in this time window.</p>
              </div>
              <button type="button" class="mgm-modal-close" data-mgm-close-modal>Close</button>
            </div>
            <?php if (!empty($windowActorRows[$windowKey])): ?>
              <div class="mgm-table-wrap">
                <table>
                  <tr><th>Actor</th><th>Events</th><th>Latest Activity</th></tr>
                  <?php foreach ($windowActorRows[$windowKey] as $actorRow): ?>
                    <tr>
                      <td><?= mgm_h($actorRow['actor']) ?></td>
                      <td><?= number_format($actorRow['total']) ?></td>
                      <td><?= mgm_h($actorRow['last_at']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </table>
              </div>
            <?php else: ?>
              <p class="mgm-panel-intro">No recorded actors in this window.</p>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>

      <script>
        (() => {
          const openButtons = document.querySelectorAll("[data-mgm-open-modal]");
          const closeButtons = document.querySelectorAll("[data-mgm-close-modal]");
          const modalSelector = (key) => `.mgm-modal[data-mgm-modal="${CSS.escape(key)}"]`;
          const closeModal = (modal) => {
            if (!modal) return;
            modal.hidden = true;
            document.body.style.overflow = "";
          };
          const openModal = (key) => {
            const modal = document.querySelector(modalSelector(key));
            if (!modal) return;
            modal.hidden = false;
            document.body.style.overflow = "hidden";
          };
          openButtons.forEach((button) => {
            button.addEventListener("click", () => openModal(button.dataset.mgmOpenModal || ""));
          });
          closeButtons.forEach((button) => {
            button.addEventListener("click", () => closeModal(button.closest(".mgm-modal")));
          });
          document.addEventListener("keydown", (event) => {
            if (event.key !== "Escape") return;
            document.querySelectorAll(".mgm-modal:not([hidden])").forEach(closeModal);
          });
        })();
      </script>

      <section class="mgm-panel" style="margin-top:18px;">
        <h2>Recent Log Events</h2>
        <p class="mgm-panel-intro">Most recent operational events pulled from the local log file.</p>
        <?php if ($logStats['recentEvents']): ?>
          <div class="mgm-table-wrap">
            <table>
              <tr><th>Time</th><th>Event</th></tr>
              <?php foreach ($logStats['recentEvents'] as $event): ?>
                <tr>
                  <td><?= mgm_h($event['time']) ?></td>
                  <td><?= mgm_h($event['message']) ?></td>
                </tr>
              <?php endforeach; ?>
            </table>
          </div>
        <?php else: ?>
          <p class="mgm-panel-intro">No recent log events matched the current filters.</p>
        <?php endif; ?>
      </section>
<?php
mgm_render_shell_end();
