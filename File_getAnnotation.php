<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

$surrogate = $_GET['surrogate'] ?? null;
$page      = $_GET['page'] ?? null;
$owner     = basename($_GET['owner'] ?? '');
$annotator = basename($_GET['annotator'] ?? '');

if (!$surrogate || !is_numeric($surrogate) || !$owner) {
    http_response_code(400);
    echo "Missing or invalid surrogate or owner.";
    exit;
}

// Session info
$username  = $_SESSION['username'] ?? '';
$userRole  = $_SESSION['role'] ?? '';
$isOwner   = $annotator === $owner;
$isAdmin   = $userRole === 'admin';

// Default annotator to owner if not provided
if (!$annotator) {
    $annotator = $owner;
}

$pageSuffix = $page ? "-p" . intval($page) : "";
$filename = "annotation-{$surrogate}{$pageSuffix}.png";

$baseDir = "/home1/wecanrec/textwhisper_uploads/{$owner}/annotations";
$fullPath = "";

// Determine correct path
if (!$isOwner && !$isAdmin) {
    $safeUser = preg_replace('/[^a-zA-Z0-9_-]/', '_', $annotator);
    $fullPath = "{$baseDir}/users/{$safeUser}/{$filename}";
} else {
    $fullPath = "{$baseDir}/{$filename}";
}

// Debug logging (optional)
error_log("👤 SESSION: " . json_encode($_SESSION));
error_log("🔎 Looking for annotation: $fullPath");

// Serve file if it exists
if (file_exists($fullPath)) {
    header("Content-Type: image/png");
    header("Content-Length: " . filesize($fullPath));
    readfile($fullPath);
    exit;
}

// Legacy fallback for owner (no annotator in filename)
if ($annotator === $owner) {
    $legacyPath = "{$baseDir}/annotation-{$surrogate}{$pageSuffix}.png";
    if (file_exists($legacyPath)) {
        header("Content-Type: image/png");
        header("Content-Length: " . filesize($legacyPath));
        readfile($legacyPath);
        exit;
    }
}

// Not found
http_response_code(204);
exit;
