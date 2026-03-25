<?php
$path = $_POST['path'] ?? '';
$filename = basename($path);

// Validate file format
if (!preg_match('/^temp_pdf_surrogate-[a-zA-Z0-9_-]+\.pdf$/', $filename)) {
  http_response_code(400);
  echo json_encode(['status' => 'error', 'error' => 'Invalid filename']);
  exit;
}

$fullPath = __DIR__ . "/uploads/$filename";

if (file_exists($fullPath)) {
  unlink($fullPath);
  echo json_encode(['status' => 'deleted', 'file' => $filename]);
} else {
  echo json_encode(['status' => 'not_found', 'file' => $filename]);
}
