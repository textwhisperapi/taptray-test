<?php
// Serve temporary OMR files from /tmp/omr/public (MIDI + MusicXML + debug JSON).
$baseDir = sys_get_temp_dir() . '/omr/public';
$file = $_GET['file'] ?? '';
$file = basename($file);

if ($file === '' || !preg_match('/\\.(mid(i)?|xml|musicxml|mxl|json)$/i', $file)) {
    http_response_code(400);
    echo "Invalid file.";
    exit;
}

$path = $baseDir . '/' . $file;
if (!is_file($path)) {
    http_response_code(404);
    echo "Not found.";
    exit;
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$contentType = 'application/octet-stream';
if ($ext === 'mid' || $ext === 'midi') {
    $contentType = 'audio/midi';
} elseif ($ext === 'xml' || $ext === 'musicxml') {
    $contentType = 'application/vnd.recordare.musicxml+xml';
} elseif ($ext === 'mxl') {
    $contentType = 'application/vnd.recordare.musicxml';
} elseif ($ext === 'json') {
    $contentType = 'application/json; charset=utf-8';
}

header('Content-Type: ' . $contentType);
header('Cache-Control: no-store');
readfile($path);
