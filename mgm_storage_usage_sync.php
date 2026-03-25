<?php
require_once __DIR__ . '/includes/mgm_ui.php';
require_once __DIR__ . '/includes/mgmt_storage_usage.php';

$ctx = mgm_bootstrap('users', 'Storage Sync');

header('Content-Type: application/json; charset=UTF-8');

$memberId = (int)($_POST['member_id'] ?? 0);
$result = mgmt_storage_usage_sync_member($mysqli, $memberId);
if (empty($result['ok'])) {
    http_response_code(($result['error'] ?? '') === 'Member not found' ? 404 : 502);
    echo json_encode($result);
    exit;
}

$result['scanned_at'] = mgm_format_datetime((string)($result['scanned_at_raw'] ?? ''));
unset($result['scanned_at_raw']);

echo json_encode($result);
