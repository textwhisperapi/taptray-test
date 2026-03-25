<?php
// serveFile.php — serves PDFs or annotation PNGs securely

$owner     = $_GET['owner'] ?? '';
$surrogate = $_GET['surrogate'] ?? '';
$page      = $_GET['page'] ?? null;
$type      = $_GET['type'] ?? 'pdf'; // 'pdf' or 'annotation'

// Validate inputs
//if (!preg_match('/^[a-zA-Z0-9_-]+$/', $owner) || !is_numeric($surrogate)) {
if (!preg_match('/^[a-zA-Z0-9._-]+$/', $owner) || !is_numeric($surrogate)) {    
    http_response_code(400);
    exit("Invalid parameters");
}

// Determine path and MIME
switch ($type) {
    case 'pdf':
        $relPath = "$owner/pdf/temp_pdf_surrogate-$surrogate.pdf";
        $mime = "application/pdf";
        break;

    case 'annotation':
        $suffix = $page !== null ? "-p" . intval($page) : "";
        $relPath = "$owner/annotations/annotation-$surrogate$suffix.png";
        $mime = "image/png";
        break;

    default:
        http_response_code(400);
        exit("Invalid type.");
}

//$basePath = realpath(__DIR__ . '/../textwhisper_uploads');  // go up from public_html
$basePath = '/home1/wecanrec/textwhisper_uploads';
$fullPath = "$basePath/$relPath";


if (!file_exists($fullPath)) {
    http_response_code($type === 'annotation' ? 204 : 404);
    exit;
}

header("Content-Type: $mime");
header("Content-Length: " . filesize($fullPath));
readfile($fullPath);
