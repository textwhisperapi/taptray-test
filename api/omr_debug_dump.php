<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'error' => $msg], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Method not allowed.', 405);
}

$raw = file_get_contents('php://input');
if (!is_string($raw) || $raw === '') {
    fail('Missing JSON payload.');
}
if (strlen($raw) > 8 * 1024 * 1024) {
    fail('Payload too large.', 413);
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    fail('Invalid JSON payload.');
}

$pubDir = sys_get_temp_dir() . '/omr/public';
if (!is_dir($pubDir) && !@mkdir($pubDir, 0775, true)) {
    fail('Failed to prepare debug storage.', 500);
}

$fileName = 'omr-debug-latest.json';
$outPath = $pubDir . '/' . $fileName;

$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
if (!is_string($json) || $json === '') {
    fail('Failed to encode debug JSON.', 500);
}
if (@file_put_contents($outPath, $json) === false) {
    fail('Failed to write debug file.', 500);
}

echo json_encode([
    'status' => 'ok',
    'debugUrl' => '/api/omr_fetch.php?file=' . urlencode($fileName),
    'debugName' => $fileName,
], JSON_UNESCAPED_SLASHES);
