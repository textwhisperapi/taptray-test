<?php
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/mgmt_storage_usage.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$json = $argv[1] ?? '';
if ($json === '') {
    fwrite(STDERR, "Usage: php tools/mgmt_storage_usage_upsert.php '[{\"member_id\":1,\"username\":\"demo\",\"file_count\":12,\"bytes_used\":12345}]'\n");
    exit(1);
}

$rows = json_decode($json, true);
if (!is_array($rows)) {
    fwrite(STDERR, "Invalid JSON payload.\n");
    exit(1);
}

if (!mgmt_storage_usage_ensure_schema($mysqli)) {
    fwrite(STDERR, "Could not ensure mgmt_storage_usage schema.\n");
    exit(1);
}

$written = 0;
foreach ($rows as $row) {
    if (!is_array($row)) {
        continue;
    }

    $ok = mgmt_storage_usage_upsert(
        $mysqli,
        (int)($row['member_id'] ?? 0),
        trim((string)($row['username'] ?? '')),
        (int)($row['file_count'] ?? 0),
        (int)($row['bytes_used'] ?? 0),
        trim((string)($row['source'] ?? 'cloudflare')),
        isset($row['scanned_at']) ? (string)$row['scanned_at'] : null
    );

    if ($ok) {
        $written++;
    }
}

fwrite(STDOUT, "Upserted {$written} row(s).\n");
