<?php
// File_saveMeta.php

header('Content-Type: application/json');
$input = json_decode(file_get_contents('php://input'), true);

$surrogate = preg_replace('/[^a-zA-Z0-9_\-]/', '', $input['surrogate'] ?? '');
$name      = basename($input['name'] ?? '');
$type      = $input['type'] ?? 'meta';
$data      = $input['data'] ?? null;

if (!$surrogate || !$name || !$data) {
  echo json_encode(['status' => 'error', 'error' => 'Missing fields']);
  exit;
}

$dir = __DIR__ . "/uploads/$surrogate/_meta";
@mkdir($dir, 0777, true);

$targetFile = "$dir/$name.$type.json";
file_put_contents($targetFile, json_encode($data, JSON_PRETTY_PRINT));

echo json_encode(['status' => 'success']);
