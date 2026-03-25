<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['username'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$annotator = $_SESSION['username'];
$surrogate = $_POST['surrogate'] ?? null;
$owner     = basename($_POST['owner'] ?? '');
$visibility = $_POST['visibility'] ?? 'private';
$pageRaw   = $_POST['page'] ?? null;

if (!$surrogate || !is_numeric($surrogate)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid surrogate ID']);
    exit;
}
if (!$owner) {
    echo json_encode(['status' => 'error', 'message' => 'Missing owner']);
    exit;
}
if (!isset($_FILES['annotation']) || $_FILES['annotation']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['status' => 'error', 'message' => 'No file uploaded']);
    exit;
}

// Validate PNG
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$type = finfo_file($finfo, $_FILES['annotation']['tmp_name']);
finfo_close($finfo);
if ($type !== 'image/png') {
    echo json_encode(['status' => 'error', 'message' => 'Only PNG supported']);
    exit;
}

$page = (is_numeric($pageRaw) && intval($pageRaw) > 0) ? intval($pageRaw) : null;
$pageSuffix = $page !== null ? "-p{$page}" : "";

// Determine target path
$baseDir = "/home1/wecanrec/textwhisper_uploads/{$owner}/annotations";
$filename = "annotation-{$surrogate}{$pageSuffix}.png";

// Use subfolder if annotator ≠ owner
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
$isOwner = ($annotator === $owner);

// Use user subfolder only if NOT owner or admin
if (!$isOwner && !$isAdmin) {
    $annotatorSafe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $annotator);
    $baseDir .= "/users/{$annotatorSafe}";
}

if (!is_dir($baseDir)) {
    mkdir($baseDir, 0775, true);
}

$targetPath = "{$baseDir}/{$filename}";

if (!move_uploaded_file($_FILES['annotation']['tmp_name'], $targetPath)) {
    error_log("❌ Failed to save annotation for $surrogate (page $page) by $annotator");
    echo json_encode(['status' => 'error', 'message' => 'Failed to save file']);
    exit;
}

error_log("✅ Annotation saved: $targetPath");
echo json_encode(['status' => 'success']);
