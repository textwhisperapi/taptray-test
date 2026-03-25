<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');

function fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'error' => $msg], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Method not allowed', 405);
}

$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') fail('Missing body');
$json = json_decode($raw, true);
if (!is_array($json)) fail('Invalid JSON');

$midiB64 = isset($json['midiBase64']) ? trim((string)$json['midiBase64']) : '';
$xmlB64 = isset($json['xmlBase64']) ? trim((string)$json['xmlBase64']) : '';
if ($midiB64 === '' && $xmlB64 === '') fail('Missing midiBase64 or xmlBase64');

$pubDir = sys_get_temp_dir() . '/omr/public';
if (!is_dir($pubDir) && !@mkdir($pubDir, 0777, true)) {
    fail('Failed to create publish directory', 500);
}

$out = ['status' => 'ok'];

if ($midiB64 !== '') {
    $midiBin = base64_decode($midiB64, true);
    if ($midiBin === false || strlen($midiBin) < 32) fail('Invalid MIDI payload');
    if (substr($midiBin, 0, 4) !== 'MThd') fail('Payload is not a MIDI file');

    $midiName = 'omr-merged-' . gmdate('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.mid';
    $midiPath = $pubDir . '/' . $midiName;
    if (@file_put_contents($midiPath, $midiBin) === false) {
        fail('Failed to write MIDI file', 500);
    }
    $out['midiUrl'] = '/api/omr_fetch.php?file=' . urlencode($midiName);
    $out['midiName'] = $midiName;
}

if ($xmlB64 !== '') {
    $xmlBin = base64_decode($xmlB64, true);
    if ($xmlBin === false || strlen($xmlBin) < 24) fail('Invalid MusicXML payload');
    $xmlText = (string)$xmlBin;
    if (stripos($xmlText, '<score-partwise') === false && stripos($xmlText, '<score-timewise') === false) {
        fail('Payload is not a MusicXML file');
    }

    $xmlName = 'omr-merged-' . gmdate('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.musicxml';
    $xmlPath = $pubDir . '/' . $xmlName;
    if (@file_put_contents($xmlPath, $xmlText) === false) {
        fail('Failed to write MusicXML file', 500);
    }
    $out['xmlUrl'] = '/api/omr_fetch.php?file=' . urlencode($xmlName);
    $out['xmlName'] = $xmlName;
}

echo json_encode($out, JSON_UNESCAPED_SLASHES);
