<?php

function mgmt_storage_usage_worker_list_objects(string $prefix): array {
    $url = 'https://r2-worker.textwhisper.workers.dev/list?prefix=' . rawurlencode($prefix);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        return ['ok' => false, 'error' => $error !== '' ? $error : "Worker list request failed ({$httpCode})"];
    }

    $data = json_decode((string)$response, true);
    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'Invalid worker JSON'];
    }

    // Worker returns a flat list of objects.
    $objects = [];
    foreach ($data as $row) {
        if (!is_array($row) || empty($row['key'])) {
            continue;
        }
        $objects[] = [
            'key' => (string)$row['key'],
            'size' => (int)($row['size'] ?? 0),
            'last_modified' => (string)($row['last_modified'] ?? ''),
        ];
    }

    return ['ok' => true, 'objects' => $objects];
}

function mgmt_storage_usage_r2_config(): ?array {
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }

    $configPath = dirname(__DIR__) . '/api/config_cloudflare.php';
    if (!is_file($configPath)) {
        return $cfg = null;
    }

    require $configPath;
    if (empty($accessKey) || empty($secretKey) || empty($bucket) || empty($endpoint)) {
        return $cfg = null;
    }

    return $cfg = [
        'access_key' => $accessKey,
        'secret_key' => $secretKey,
        'bucket' => $bucket,
        'endpoint' => rtrim((string)$endpoint, '/'),
    ];
}

function mgmt_storage_usage_r2_sign(string $key, string $msg): string {
    return hash_hmac('sha256', $msg, $key, true);
}

function mgmt_storage_usage_r2_list_objects(string $prefix = ''): array {
    $cfg = mgmt_storage_usage_r2_config();
    if (!$cfg) {
        return ['ok' => false, 'error' => 'R2 config missing'];
    }

    $method = 'GET';
    $host = parse_url($cfg['endpoint'], PHP_URL_HOST);
    $canonicalUri = '/' . $cfg['bucket'];
    $region = 'auto';
    $service = 's3';
    $all = [];
    $continuationToken = null;

    do {
        $queryParts = [
            'list-type=2',
            'prefix=' . rawurlencode($prefix),
            'max-keys=1000',
        ];
        if ($continuationToken !== null && $continuationToken !== '') {
            $queryParts[] = 'continuation-token=' . rawurlencode($continuationToken);
        }
        $canonicalQuery = implode('&', $queryParts);
        $url = $cfg['endpoint'] . '/' . $cfg['bucket'] . '?' . $canonicalQuery;

        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $credentialScope = $dateStamp . '/' . $region . '/' . $service . '/aws4_request';

        $canonicalHeaders =
            "host:$host\n" .
            "x-amz-content-sha256:UNSIGNED-PAYLOAD\n" .
            "x-amz-date:$amzDate\n";
        $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
        $canonicalRequest =
            $method . "\n" .
            $canonicalUri . "\n" .
            $canonicalQuery . "\n" .
            $canonicalHeaders . "\n" .
            $signedHeaders . "\n" .
            'UNSIGNED-PAYLOAD';

        $stringToSign =
            "AWS4-HMAC-SHA256\n" .
            $amzDate . "\n" .
            $credentialScope . "\n" .
            hash('sha256', $canonicalRequest);

        $kDate = mgmt_storage_usage_r2_sign('AWS4' . $cfg['secret_key'], $dateStamp);
        $kRegion = mgmt_storage_usage_r2_sign($kDate, $region);
        $kService = mgmt_storage_usage_r2_sign($kRegion, $service);
        $kSigning = mgmt_storage_usage_r2_sign($kService, 'aws4_request');
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $headers = [
            'x-amz-date: ' . $amzDate,
            'x-amz-content-sha256: UNSIGNED-PAYLOAD',
            'Authorization: AWS4-HMAC-SHA256 Credential=' . $cfg['access_key'] . '/' . $credentialScope . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature,
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 180);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            return ['ok' => false, 'error' => $error !== '' ? $error : "R2 list request failed ({$httpCode})"];
        }

        $xml = simplexml_load_string($response);
        if (!$xml) {
            return ['ok' => false, 'error' => 'Invalid R2 list XML'];
        }

        if (isset($xml->Contents)) {
            foreach ($xml->Contents as $item) {
                $all[] = [
                    'key' => (string)$item->Key,
                    'size' => (int)$item->Size,
                    'last_modified' => (string)$item->LastModified,
                ];
            }
        }

        $isTruncated = strtolower((string)($xml->IsTruncated ?? 'false')) === 'true';
        $continuationToken = $isTruncated ? trim((string)($xml->NextContinuationToken ?? '')) : null;
    } while ($continuationToken !== null && $continuationToken !== '');

    return ['ok' => true, 'objects' => $all];
}

function mgmt_storage_usage_ensure_schema(mysqli $mysqli): bool {
    $sql = "
        CREATE TABLE IF NOT EXISTS mgmt_storage_usage (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          member_id INT NOT NULL,
          username VARCHAR(255) NOT NULL,
          source VARCHAR(32) NOT NULL DEFAULT 'cloudflare',
          file_count INT NOT NULL DEFAULT 0,
          bytes_used BIGINT UNSIGNED NOT NULL DEFAULT 0,
          gb_used DECIMAL(12,3) NOT NULL DEFAULT 0.000,
          by_type_json LONGTEXT NULL,
          scanned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uniq_mgmt_storage_usage_member_source (member_id, source),
          KEY idx_mgmt_storage_usage_username (username),
          KEY idx_mgmt_storage_usage_scanned_at (scanned_at),
          KEY idx_mgmt_storage_usage_file_count (file_count),
          KEY idx_mgmt_storage_usage_bytes_used (bytes_used)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    if (!$mysqli->query($sql)) {
        return false;
    }

    $colRes = $mysqli->query("SHOW COLUMNS FROM mgmt_storage_usage LIKE 'by_type_json'");
    if ($colRes instanceof mysqli_result) {
        $exists = $colRes->num_rows > 0;
        $colRes->close();
        if (!$exists) {
            @$mysqli->query("ALTER TABLE mgmt_storage_usage ADD COLUMN by_type_json LONGTEXT NULL AFTER gb_used");
        }
    }

    $extraColumns = [
        'orphan_file_count' => "ALTER TABLE mgmt_storage_usage ADD COLUMN orphan_file_count INT NOT NULL DEFAULT 0 AFTER by_type_json",
        'orphan_bytes_used' => "ALTER TABLE mgmt_storage_usage ADD COLUMN orphan_bytes_used BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER orphan_file_count",
        'orphan_gb_used' => "ALTER TABLE mgmt_storage_usage ADD COLUMN orphan_gb_used DECIMAL(12,3) NOT NULL DEFAULT 0.000 AFTER orphan_bytes_used",
        'orphan_detail_json' => "ALTER TABLE mgmt_storage_usage ADD COLUMN orphan_detail_json LONGTEXT NULL AFTER orphan_gb_used",
    ];
    foreach ($extraColumns as $column => $sqlAlter) {
        $res = $mysqli->query("SHOW COLUMNS FROM mgmt_storage_usage LIKE '{$column}'");
        if ($res instanceof mysqli_result) {
            $exists = $res->num_rows > 0;
            $res->close();
            if (!$exists) {
                @$mysqli->query($sqlAlter);
            }
        }
    }

    return true;
}

function mgmt_storage_usage_upsert(
    mysqli $mysqli,
    int $memberId,
    string $username,
    int $fileCount,
    int $bytesUsed,
    string $source = 'cloudflare',
    ?string $scannedAt = null,
    ?array $byType = null,
    int $orphanFileCount = 0,
    int $orphanBytesUsed = 0,
    ?array $orphanDetail = null
): bool {
    if ($memberId <= 0 || $username === '') {
        return false;
    }

    if (!mgmt_storage_usage_ensure_schema($mysqli)) {
        return false;
    }

    $fileCount = max(0, $fileCount);
    $bytesUsed = max(0, $bytesUsed);
    $gbUsed = round($bytesUsed / 1073741824, 3);
    $orphanFileCount = max(0, $orphanFileCount);
    $orphanBytesUsed = max(0, $orphanBytesUsed);
    $orphanGbUsed = round($orphanBytesUsed / 1073741824, 3);
    $scannedAt = trim((string)$scannedAt);
    if ($scannedAt === '') {
        $scannedAt = gmdate('Y-m-d H:i:s');
    }
    $byTypeJson = $byType ? json_encode($byType, JSON_UNESCAPED_SLASHES) : null;
    $orphanDetailJson = $orphanDetail ? json_encode($orphanDetail, JSON_UNESCAPED_SLASHES) : null;

    $sql = "
        INSERT INTO mgmt_storage_usage (
            member_id,
            username,
            source,
            file_count,
            bytes_used,
            gb_used,
            by_type_json,
            orphan_file_count,
            orphan_bytes_used,
            orphan_gb_used,
            orphan_detail_json,
            scanned_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            username = VALUES(username),
            file_count = VALUES(file_count),
            bytes_used = VALUES(bytes_used),
            gb_used = VALUES(gb_used),
            by_type_json = VALUES(by_type_json),
            orphan_file_count = VALUES(orphan_file_count),
            orphan_bytes_used = VALUES(orphan_bytes_used),
            orphan_gb_used = VALUES(orphan_gb_used),
            orphan_detail_json = VALUES(orphan_detail_json),
            scanned_at = VALUES(scanned_at)
    ";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt instanceof mysqli_stmt) {
        return false;
    }

    $stmt->bind_param(
        'issiidsiidss',
        $memberId,
        $username,
        $source,
        $fileCount,
        $bytesUsed,
        $gbUsed,
        $byTypeJson,
        $orphanFileCount,
        $orphanBytesUsed,
        $orphanGbUsed,
        $orphanDetailJson,
        $scannedAt
    );
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function mgmt_storage_usage_classify_key(string $key): string {
    $key = strtolower(trim($key));
    if ($key === '') {
        return 'other';
    }

    $ext = strtolower((string)pathinfo($key, PATHINFO_EXTENSION));
    $audioExts = ['mp3', 'wav', 'ogg', 'm4a', 'flac', 'aac', 'aif', 'aiff', 'mid', 'midi'];
    $imageExts = ['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg'];
    $xmlExts = ['xml', 'musicxml', 'mxl'];
    $textExts = ['txt', 'md', 'json', 'csv', 'log'];

    if (strpos($key, '/pdf/') !== false || $ext === 'pdf') {
        return 'pdf';
    }
    if (in_array($ext, $xmlExts, true)) {
        return 'xml';
    }
    if (in_array($ext, $audioExts, true)) {
        return 'audio';
    }
    if (in_array($ext, $imageExts, true) || strpos($key, '/annotations/') !== false || strpos($key, 'avatar') !== false) {
        return 'image';
    }
    if (in_array($ext, $textExts, true) || strpos($key, '/comments/') !== false) {
        return 'other';
    }
    return 'other';
}

function mgmt_storage_usage_extract_surrogate(string $key): ?int {
    $patterns = [
        '/\/pdf\/temp_pdf_surrogate-(\d+)(?:\.backup)?\.pdf$/i',
        '/\/annotations\/(?:users\/[^\/]+\/)?annotation-(\d+)-p\d+\.png$/i',
        '/\/comments\/users\/[^\/]+\/comments-(\d+)(?:-p\d+)?\.json$/i',
        '/\/surrogate-(\d+)\/files\//i',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $key, $m)) {
            return (int)$m[1];
        }
    }
    return null;
}

function mgmt_storage_usage_load_active_surrogates(mysqli $mysqli, string $ownerUsername, array $surrogates): array {
    $ownerUsername = trim($ownerUsername);
    $surrogates = array_values(array_unique(array_filter(array_map('intval', $surrogates), static fn($v) => $v > 0)));
    if ($ownerUsername === '' || !$surrogates) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($surrogates), '?'));
    $types = 's' . str_repeat('i', count($surrogates));
    $sql = "
        SELECT surrogate
        FROM text
        WHERE owner = ?
          AND surrogate IN ({$placeholders})
          AND (deleted IS NULL OR deleted != 'D')
    ";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt instanceof mysqli_stmt) {
        return [];
    }

    $params = array_merge([$ownerUsername], $surrogates);
    $refs = [];
    foreach ($params as $i => $value) {
        $refs[$i] = &$params[$i];
    }
    array_unshift($refs, $types);
    call_user_func_array([$stmt, 'bind_param'], $refs);

    $active = [];
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $surrogate = (int)($row['surrogate'] ?? 0);
                if ($surrogate > 0) {
                    $active[$surrogate] = true;
                }
            }
            $result->close();
        }
    }
    $stmt->close();
    return $active;
}

function mgmt_storage_usage_fetch_cloudflare_summary(mysqli $mysqli, string $username): array {
    $username = trim($username);
    if ($username === '') {
        return ['ok' => false, 'error' => 'Missing username'];
    }

    $list = mgmt_storage_usage_worker_list_objects($username . '/');
    if (empty($list['ok'])) {
        return $list;
    }

    $fileCount = 0;
    $bytesUsed = 0;
    $byType = [];
    $surrogates = [];
    $surrogateByKey = [];
    foreach (($list['objects'] ?? []) as $row) {
        if (!is_array($row) || empty($row['key'])) {
            continue;
        }
        $key = (string)$row['key'];
        $surrogate = mgmt_storage_usage_extract_surrogate($key);
        if ($surrogate !== null) {
            $surrogates[] = $surrogate;
            $surrogateByKey[$key] = $surrogate;
        }
        $size = max(0, (int)($row['size'] ?? 0));
        $type = mgmt_storage_usage_classify_key($key);
        $fileCount++;
        $bytesUsed += $size;
        if (!isset($byType[$type])) {
            $byType[$type] = [
                'files' => 0,
                'bytes' => 0,
                'gb' => 0.0,
            ];
        }
        $byType[$type]['files']++;
        $byType[$type]['bytes'] += $size;
    }
    foreach ($byType as $type => $stats) {
        $byType[$type]['gb'] = round(((int)$stats['bytes']) / 1073741824, 3);
    }

    $activeSurrogates = mgmt_storage_usage_load_active_surrogates($mysqli, $username, $surrogates);
    $orphanFileCount = 0;
    $orphanBytesUsed = 0;
    $orphanDetail = [];
    foreach (($list['objects'] ?? []) as $row) {
        if (!is_array($row) || empty($row['key'])) {
            continue;
        }
        $key = (string)$row['key'];
        $surrogate = $surrogateByKey[$key] ?? null;
        if ($surrogate === null || isset($activeSurrogates[$surrogate])) {
            continue;
        }
        $size = max(0, (int)($row['size'] ?? 0));
        $orphanFileCount++;
        $orphanBytesUsed += $size;
        if (count($orphanDetail) < 24) {
            $orphanDetail[] = [
                'key' => $key,
                'surrogate' => $surrogate,
                'size' => $size,
                'gb' => round($size / 1073741824, 6),
                'type' => mgmt_storage_usage_classify_key($key),
            ];
        }
    }

    return [
        'ok' => true,
        'file_count' => $fileCount,
        'bytes_used' => $bytesUsed,
        'gb_used' => round($bytesUsed / 1073741824, 3),
        'by_type' => $byType,
        'orphan_file_count' => $orphanFileCount,
        'orphan_bytes_used' => $orphanBytesUsed,
        'orphan_gb_used' => round($orphanBytesUsed / 1073741824, 3),
        'orphan_detail' => $orphanDetail,
        'scanned_at' => gmdate('Y-m-d H:i:s'),
    ];
}

function mgmt_storage_usage_sync_member(mysqli $mysqli, int $memberId): array {
    if ($memberId <= 0) {
        return ['ok' => false, 'error' => 'Missing member_id'];
    }

    $stmt = $mysqli->prepare("
        SELECT id, username, COALESCE(NULLIF(display_name, ''), username) AS display_name
        FROM members
        WHERE id = ?
        LIMIT 1
    ");
    if (!$stmt instanceof mysqli_stmt) {
        return ['ok' => false, 'error' => 'Could not prepare member lookup'];
    }

    $stmt->bind_param('i', $memberId);
    $stmt->execute();
    $result = $stmt->get_result();
    $member = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->close();
    }
    $stmt->close();

    if (!$member) {
        return ['ok' => false, 'error' => 'Member not found'];
    }

    $username = trim((string)($member['username'] ?? ''));
    $summary = mgmt_storage_usage_fetch_cloudflare_summary($mysqli, $username);
    if (empty($summary['ok'])) {
        return [
            'ok' => false,
            'error' => (string)($summary['error'] ?? 'Cloudflare scan failed'),
            'member_id' => $memberId,
            'username' => $username,
        ];
    }

    $upserted = mgmt_storage_usage_upsert(
        $mysqli,
        $memberId,
        $username,
        (int)$summary['file_count'],
        (int)$summary['bytes_used'],
        'cloudflare',
        (string)$summary['scanned_at'],
        is_array($summary['by_type'] ?? null) ? $summary['by_type'] : [],
        (int)($summary['orphan_file_count'] ?? 0),
        (int)($summary['orphan_bytes_used'] ?? 0),
        is_array($summary['orphan_detail'] ?? null) ? $summary['orphan_detail'] : []
    );

    if (!$upserted) {
        return ['ok' => false, 'error' => 'Could not update mgmt_storage_usage'];
    }

    return [
        'ok' => true,
        'member_id' => $memberId,
        'username' => $username,
        'display_name' => (string)($member['display_name'] ?? $username),
        'file_count' => (int)$summary['file_count'],
        'bytes_used' => (int)$summary['bytes_used'],
        'gb_used' => (float)$summary['gb_used'],
        'by_type' => (array)($summary['by_type'] ?? []),
        'orphan_file_count' => (int)($summary['orphan_file_count'] ?? 0),
        'orphan_gb_used' => (float)($summary['orphan_gb_used'] ?? 0),
        'orphan_detail' => (array)($summary['orphan_detail'] ?? []),
        'scanned_at_raw' => (string)$summary['scanned_at'],
    ];
}

function mgmt_storage_usage_fetch_cloudflare_summary_all(): array {
    $list = mgmt_storage_usage_r2_list_objects('');
    if (empty($list['ok'])) {
        return $list;
    }

    $users = [];
    foreach (($list['objects'] ?? []) as $row) {
        if (!is_array($row) || empty($row['key'])) {
            continue;
        }
        $key = (string)$row['key'];
        $size = max(0, (int)($row['size'] ?? 0));
        $parts = explode('/', $key, 2);
        $username = trim((string)($parts[0] ?? ''));
        if ($username === '') {
            continue;
        }
        if (!isset($users[$username])) {
            $users[$username] = [
                'file_count' => 0,
                'bytes_used' => 0,
                'by_type' => [],
            ];
        }
        $type = mgmt_storage_usage_classify_key($key);
        $users[$username]['file_count']++;
        $users[$username]['bytes_used'] += $size;
        if (!isset($users[$username]['by_type'][$type])) {
            $users[$username]['by_type'][$type] = ['files' => 0, 'bytes' => 0, 'gb' => 0.0];
        }
        $users[$username]['by_type'][$type]['files']++;
        $users[$username]['by_type'][$type]['bytes'] += $size;
    }

    foreach ($users as $username => $stats) {
        foreach ($stats['by_type'] as $type => $typeStats) {
            $users[$username]['by_type'][$type]['gb'] = round(((int)$typeStats['bytes']) / 1073741824, 3);
        }
        $users[$username]['gb_used'] = round(((int)$stats['bytes_used']) / 1073741824, 3);
    }

    return [
        'ok' => true,
        'users' => $users,
        'scanned_at' => gmdate('Y-m-d H:i:s'),
    ];
}
