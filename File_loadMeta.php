<?php
// File_loadMeta.php

header('Content-Type: application/json');

$surrogate = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['surrogate'] ?? '');
$name      = basename($_GET['name'] ?? '');
$type      = $_GET['type'] ?? 'meta';

if (!$surrogate || !$name) {
  echo json_encode(['status' => 'error', 'error' => 'Missing fields']);
  exit;
}

$file = __DIR__ . "/uploads/$surrogate/_meta/$name.$type.json";

if (!file_exists($file)) {
  echo json_encode(['status' => 'not_found']);
  exit;
}

$data = json_decode(file_get_contents($file), true);
echo json_encode(['status' => 'success', 'data' => $data]);
