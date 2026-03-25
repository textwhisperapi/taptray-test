<?php
declare(strict_types=1);

require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/db_connect.php";

sec_session_start();
header("Content-Type: application/json");

function tw_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function tw_remote_pdf_size(string $url): int
{
    if (!function_exists('curl_init')) {
        return 0;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_CONNECTTIMEOUT => 6
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $len = (int)curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
    curl_close($ch);

    if ($code >= 200 && $code < 300 && $len > 0) {
        return $len;
    }
    return 0;
}

function tw_remote_exists(string $url): bool
{
    if (!function_exists('curl_init')) return false;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code >= 200 && $code < 300);
}

function tw_download_to_file(string $url, string $targetPath): bool
{
    if (!function_exists('curl_init')) return false;
    $fp = fopen($targetPath, 'wb');
    if (!$fp) return false;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ok = ($code >= 200 && $code < 300);
    curl_close($ch);
    fclose($fp);
    return $ok && is_file($targetPath) && filesize($targetPath) > 0;
}

function tw_upload_pdf_file(string $url, string $sourcePath): bool
{
    if (!function_exists('curl_init')) return false;
    $blob = @file_get_contents($sourcePath);
    if ($blob === false) return false;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/pdf'],
        CURLOPT_POSTFIELDS => $blob,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code >= 200 && $code < 300);
}

function tw_is_site_admin(mysqli $mysqli, string $username): bool
{
    if ($username === '') return false;

    $hasAdminColumn = false;
    $res = $mysqli->query("SHOW COLUMNS FROM members LIKE 'is_admin'");
    if ($res && $res->num_rows > 0) $hasAdminColumn = true;
    if ($res) $res->close();

    if (!$hasAdminColumn) return false;

    $stmt = $mysqli->prepare("SELECT COALESCE(is_admin, 0) FROM members WHERE username = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->bind_result($isAdmin);
    $ok = $stmt->fetch();
    $stmt->close();

    return $ok && (int)$isAdmin === 1;
}

if (!login_check($mysqli)) {
    tw_json(['status' => 'error', 'error' => 'Not logged in'], 403);
}

$action = strtolower(trim((string)($_REQUEST['action'] ?? 'status')));
$surrogate = preg_replace('/[^0-9]/', '', (string)($_REQUEST['surrogate'] ?? ''));
$fixOffset = (string)($_REQUEST['fix_offset'] ?? '') === '1';
$offsetStrengthRaw = strtolower(trim((string)($_REQUEST['offset_strength'] ?? 'low')));
$offsetStrength = in_array($offsetStrengthRaw, ['low', 'medium', 'high'], true) ? $offsetStrengthRaw : 'low';
if ($surrogate === '') {
    tw_json(['status' => 'error', 'error' => 'Missing surrogate'], 400);
}

$surrogateSafe = $mysqli->real_escape_string($surrogate);
$row = null;
$res = $mysqli->query("SELECT owner FROM `text` WHERE Surrogate = '{$surrogateSafe}' LIMIT 1");
if ($res) {
    $row = $res->fetch_assoc();
}

if (!$row || empty($row['owner'])) {
    tw_json(['status' => 'error', 'error' => 'Item not found'], 404);
}

$owner = (string)$row['owner'];
$currentUser = (string)$_SESSION['username'];

$isSiteAdmin = !empty($_SESSION['is_admin']) || tw_is_site_admin($mysqli, $currentUser);
$roleRankOwner = (int)get_user_list_role_rank($mysqli, $owner, $currentUser);
$canEditOwner = can_user_edit_list($mysqli, $owner, $currentUser);

// Match existing app style: site admin OR owner-list admin/editor.
if (!$isSiteAdmin && !$canEditOwner && $roleRankOwner < 90) {
    tw_json(['status' => 'error', 'error' => 'Admin only'], 403);
}

$safeSurrogate = preg_replace('/[^a-zA-Z0-9_-]/', '', $surrogate);
$pdfPath = "/home1/wecanrec/textwhisper_uploads/{$owner}/pdf/temp_pdf_surrogate-{$safeSurrogate}.pdf";
$r2Key = "{$owner}/pdf/temp_pdf_surrogate-{$safeSurrogate}.pdf";
$r2ReadUrl = 'https://r2-worker.textwhisper.workers.dev/' .
    rawurlencode($owner) . '/pdf/' . rawurlencode("temp_pdf_surrogate-{$safeSurrogate}.pdf");
$r2UploadUrl = 'https://r2-worker.textwhisper.workers.dev/?key=' . rawurlencode($r2Key);
$hasLocalPdf = is_file($pdfPath);

$jobDir = rtrim(sys_get_temp_dir(), '/') . '/textwhisper_pdf_opt';
if (!is_dir($jobDir) && !@mkdir($jobDir, 0775, true) && !is_dir($jobDir)) {
    tw_json(['status' => 'error', 'error' => 'Could not initialize optimizer queue'], 500);
}

$jobKey = hash('sha256', $owner . ':' . $safeSurrogate);
$statusFile = "{$jobDir}/{$jobKey}.status.json";
$lockFile = "{$jobDir}/{$jobKey}.lock";
$jobFile = "{$jobDir}/{$jobKey}.job.json";
$backupPath = "{$jobDir}/{$jobKey}.backup.pdf";
$backupR2Key = "{$owner}/pdf/temp_pdf_surrogate-{$safeSurrogate}.backup.pdf";
$backupReadUrl = 'https://r2-worker.textwhisper.workers.dev/' .
    rawurlencode($owner) . '/pdf/' . rawurlencode("temp_pdf_surrogate-{$safeSurrogate}.backup.pdf");
$backupUploadUrl = 'https://r2-worker.textwhisper.workers.dev/?key=' . rawurlencode($backupR2Key);
$currentSize = $hasLocalPdf ? (int)filesize($pdfPath) : tw_remote_pdf_size($r2ReadUrl);

$statusData = null;
if (is_file($statusFile)) {
    $statusData = json_decode((string)file_get_contents($statusFile), true);
    if (!is_array($statusData)) {
        $statusData = null;
    }
}

$statusRevertAvailable = false;
$statusRevertExpiresAt = 0;
if ($statusData) {
    $statusRevertExpiresAt = (int)($statusData['revert_expires_at'] ?? 0);
    $withinTtl = $statusRevertExpiresAt > time();
    if ($withinTtl && !empty($statusData['revert_available'])) {
        $statusRevertAvailable = $hasLocalPdf
            ? is_file($backupPath)
            : tw_remote_exists($backupReadUrl);
    }
}

if ($action === 'revert') {
    if (is_file($lockFile)) {
        tw_json(['status' => 'error', 'error' => 'Optimization is still running'], 409);
    }
    if (!$statusRevertAvailable) {
        tw_json(['status' => 'error', 'error' => 'No revert backup available'], 404);
    }

    if ($hasLocalPdf) {
        if (!@copy($backupPath, $pdfPath)) {
            tw_json(['status' => 'error', 'error' => 'Failed to restore local backup'], 500);
        }
        @unlink($backupPath);
    } else {
        $tmp = "{$jobDir}/{$jobKey}.revert.tmp.pdf";
        $ok = tw_download_to_file($backupReadUrl, $tmp) && tw_upload_pdf_file($r2UploadUrl, $tmp);
        @unlink($tmp);
        if (!$ok) {
            tw_json(['status' => 'error', 'error' => 'Failed to restore remote backup'], 500);
        }
    }

    if ($statusData) {
        $statusData['status'] = 'reverted';
        $statusData['revert_available'] = false;
        $statusData['revert_expires_at'] = 0;
        $statusData['finished_at'] = time();
        @file_put_contents($statusFile, json_encode($statusData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    $currentSize = $hasLocalPdf ? (int)filesize($pdfPath) : tw_remote_pdf_size($r2ReadUrl);
    tw_json([
        'status' => 'reverted',
        'owner' => $owner,
        'surrogate' => $surrogate,
        'current_size_bytes' => $currentSize
    ]);
}

if ($action === 'status') {
    if ($statusData) {
        $statusData['owner'] = $owner;
        $statusData['surrogate'] = $surrogate;
        $statusData['current_size_bytes'] = $currentSize;
        $statusData['revert_available'] = $statusRevertAvailable;
        $statusData['revert_expires_at'] = $statusRevertExpiresAt;
        tw_json($statusData);
    }
    tw_json([
        'status' => 'idle',
        'owner' => $owner,
        'surrogate' => $surrogate,
        'current_size_bytes' => $currentSize,
        'storage' => $hasLocalPdf ? 'local' : 'r2',
        'revert_available' => false
    ]);
}

if ($action !== 'start') {
    tw_json(['status' => 'error', 'error' => 'Unsupported action'], 400);
}

if (!is_executable('/usr/bin/gs') && trim((string)shell_exec('command -v gs')) === '') {
    tw_json(['status' => 'error', 'error' => 'Ghostscript is not available'], 503);
}

if (is_file($lockFile)) {
    $startedAt = (int)@filemtime($lockFile);
    if ($startedAt > 0 && (time() - $startedAt) < (15 * 60)) {
        tw_json([
            'status' => 'running',
            'owner' => $owner,
            'surrogate' => $surrogate,
            'current_size_bytes' => $currentSize
        ]);
    }
    @unlink($lockFile);
}

if ($statusData && ($statusData['status'] ?? '') === 'running') {
    $startedAt = (int)($statusData['started_at'] ?? 0);
    if ($startedAt > 0 && (time() - $startedAt) < (15 * 60)) {
        tw_json([
            'status' => 'running',
            'owner' => $owner,
            'surrogate' => $surrogate,
            'current_size_bytes' => $currentSize
        ]);
    }
}

$payload = [
    'pdf_path' => $hasLocalPdf ? $pdfPath : '',
    'source_url' => $hasLocalPdf ? '' : $r2ReadUrl,
    'upload_url' => $hasLocalPdf ? '' : $r2UploadUrl,
    'storage' => $hasLocalPdf ? 'local' : 'r2',
    'backup_path' => $hasLocalPdf ? $backupPath : '',
    'backup_upload_url' => $hasLocalPdf ? '' : $backupUploadUrl,
    'status_file' => $statusFile,
    'lock_file' => $lockFile,
    'surrogate' => $surrogate,
    'owner' => $owner,
    'old_size_bytes' => $currentSize,
    'fix_offset' => $fixOffset ? 1 : 0,
    'offset_strength' => $offsetStrength,
    'revert_expires_at' => time() + (12 * 60 * 60),
    'queued_at' => time()
];
if (@file_put_contents($jobFile, json_encode($payload, JSON_UNESCAPED_SLASHES)) === false) {
    tw_json(['status' => 'error', 'error' => 'Could not queue optimization job'], 500);
}

$worker = __DIR__ . '/tools/pdf_optimize_worker.php';
$phpCli = '/usr/bin/php';
if (!is_executable($phpCli)) {
    $phpCli = PHP_BINARY;
}
if (!is_executable($phpCli) || !is_file($worker)) {
    @unlink($jobFile);
    tw_json(['status' => 'error', 'error' => 'Optimizer runtime not available'], 500);
}

$cmd = 'nohup ' . escapeshellarg($phpCli) . ' ' . escapeshellarg($worker) .
    ' --job=' . escapeshellarg($jobFile) .
    ' > /dev/null 2>&1 &';
shell_exec($cmd);
@touch($lockFile);

tw_json([
    'status' => 'queued',
    'owner' => $owner,
    'surrogate' => $surrogate,
    'current_size_bytes' => $currentSize,
    'storage' => $hasLocalPdf ? 'local' : 'r2'
]);
