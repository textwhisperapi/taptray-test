<?php
require_once __DIR__ . '/includes/mgm_ui.php';
require_once __DIR__ . '/includes/mgmt_storage_usage.php';

$ctx = mgm_bootstrap('users', 'Storage Sync All');
header('Content-Type: application/json; charset=UTF-8');
@set_time_limit(0);

$members = [];
$result = $mysqli->query("
    SELECT id, username, COALESCE(NULLIF(display_name, ''), username) AS display_name
    FROM members
    WHERE COALESCE(NULLIF(username, ''), '') <> ''
    ORDER BY id ASC
");
if ($result instanceof mysqli_result) {
    while ($row = $result->fetch_assoc()) {
        $members[] = [
            'id' => (int)($row['id'] ?? 0),
            'username' => trim((string)($row['username'] ?? '')),
            'display_name' => (string)($row['display_name'] ?? ''),
        ];
    }
    $result->close();
}

$synced = 0;
$failed = 0;
$filesTotal = 0;
$bytesTotal = 0;
$scanTimes = [];
$failures = [];

foreach ($members as $member) {
    $memberId = (int)($member['id'] ?? 0);
    $username = trim((string)($member['username'] ?? ''));
    if ($memberId <= 0 || $username === '') {
        $failed++;
        continue;
    }

    $result = mgmt_storage_usage_sync_member($mysqli, $memberId);
    if (empty($result['ok'])) {
        $failed++;
        if (count($failures) < 8) {
            $failures[] = [
                'username' => $username,
                'error' => (string)($result['error'] ?? 'Sync failed'),
            ];
        }
        continue;
    }

    $synced++;
    $filesTotal += (int)($result['file_count'] ?? 0);
    $bytesTotal += (int)($result['bytes_used'] ?? 0);
    $scannedAtRaw = trim((string)($result['scanned_at_raw'] ?? ''));
    if ($scannedAtRaw !== '') {
        $scanTimes[] = $scannedAtRaw;
    }
}

$scannedAt = $scanTimes ? max($scanTimes) : gmdate('Y-m-d H:i:s');

echo json_encode([
    'ok' => true,
    'members_total' => count($members),
    'members_synced' => $synced,
    'members_failed' => $failed,
    'files_total' => $filesTotal,
    'gb_total' => round($bytesTotal / 1073741824, 3),
    'scanned_at' => mgm_format_datetime($scannedAt),
    'failures' => $failures,
]);
