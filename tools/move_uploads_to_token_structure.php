<?php
// move_uploads_to_token_structure.php

ini_set('display_errors', 1);
error_reporting(E_ALL);

// 🔐 Secure access
if (!isset($_GET['secret']) || $_GET['secret'] !== 'mymovekey123') {
    http_response_code(403);
    exit("🚫 Access denied.");
}

// ✅ Source folders
$oldPath = "/home1/wecanrec/public_html/test.textwhisper.com/uploads/";
$annotationPath = $oldPath . "annotations/";

// ✅ Destination base
$newBase = "/home1/wecanrec/textwhisper_uploads/";

// ✅ Include DB connection
require_once __DIR__ . "/../includes/db_connect.php";

// 🛠 Ensure directory exists
function ensureDir($path) {
    if (!is_dir($path)) {
        if (!mkdir($path, 0755, true)) {
            echo "❌ Failed to create directory: $path<br>\n";
            return false;
        }
    }
    return true;
}

// 🔍 Get token from DB by surrogate
function getTokenForSurrogate($mysqli, $surrogate) {
    $stmt = $mysqli->prepare("SELECT Owner FROM text WHERE Surrogate = ?");
    if (!$stmt) {
        echo "❌ DB prepare failed: " . $mysqli->error . "<br>\n";
        return null;
    }
    $stmt->bind_param("i", $surrogate);
    $stmt->execute();
    $stmt->bind_result($ownerToken);
    $stmt->fetch();
    $stmt->close();
    return $ownerToken;
}

// 📂 Scan both PDF and annotation folders
$pdfFiles = scandir($oldPath);
$annotationFiles = is_dir($annotationPath) ? scandir($annotationPath) : [];

$allFiles = [];

foreach ($pdfFiles as $file) {
    if (!in_array($file, ['.', '..'])) {
        $allFiles[$file] = $oldPath . $file;
    }
}
foreach ($annotationFiles as $file) {
    if (!in_array($file, ['.', '..'])) {
        $allFiles[$file] = $annotationPath . $file;
    }
}

// 🚀 Process files
foreach ($allFiles as $file => $fullSrc) {
    if (preg_match('/^(temp_pdf_surrogate|annotation)-(\d+)(-p\d+)?\.(pdf|png)$/', $file, $m)) {
        $type = $m[1];
        $surrogate = $m[2];
        $ext = $m[4];

        $token = getTokenForSurrogate($mysqli, $surrogate);
        if (!$token) {
            echo "⚠️ Token not found for surrogate $surrogate ($file)<br>\n";
            continue;
        }

        $subfolder = ($type === 'annotation') ? "annotations" : "pdf";
        $newDir = $newBase . "$token/$subfolder/";

        if (!ensureDir($newDir)) {
            echo "❌ Skipped $file due to directory creation failure<br>\n";
            continue;
        }

        $dest = $newDir . $file;

        if (copy($fullSrc, $dest)) {
            echo "✅ Copied $file → $dest<br>\n";
        } else {
            echo "❌ Failed to copy $file → $dest<br>\n";
        }

    } else {
        echo "⏭ Skipped unknown or non-matching file: $file<br>\n";
    }
}
