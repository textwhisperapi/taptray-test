<?php
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/db_connect.php";
sec_session_start();
header("Content-Type: application/json");

$surrogate = $_POST['surrogate'] ?? '';
$type      = $_POST['type'] ?? '';
$file      = $_FILES['file'] ?? null;

// must be logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'error' => 'Not logged in']);
    exit;
}

// ✅ Supported types
$allowed = [
    'pdf' => [
        'mimes'   => ['application/pdf'],
        'maxSize' => 30 * 1024 * 1024,
        'subdir'  => 'pdf',
        'ext'     => 'pdf',
        'prefix'  => 'temp_pdf_surrogate-'
    ],
    'midi' => [
        'mimes'   => ['audio/midi','audio/mid','application/x-midi'],
        'maxSize' => 2 * 1024 * 1024,
        'subdir'  => 'files',
        'ext'     => 'mid',
        'prefix'  => ''
    ],
    'audio' => [
        'mimes'   => [
            'audio/mpeg','audio/mp3','audio/x-mpeg',
            'audio/mp4','audio/x-m4a',
            'video/mp4','video/3gpp',
            'audio/wav','audio/x-wav',
            'audio/ogg','audio/flac','audio/aac',
            'audio/aif','audio/aiff'
        ],
        'maxSize' => 100 * 1024 * 1024,
        'subdir'  => 'files',
        'ext'     => '',
        'prefix'  => ''
    ]
];

if (!isset($allowed[$type])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => 'Unsupported file type']);
    exit;
}
$rules = $allowed[$type];

// validate
if (!is_numeric($surrogate) || !$file) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => 'Invalid surrogate or missing file']);
    exit;
}
if ($file['size'] > $rules['maxSize']) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => 'File too large']);
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($file['tmp_name']);
if (!in_array($mime, $rules['mimes'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => 'Invalid MIME type']);
    exit;
}

// owner lookup
$surrogateSafe = $mysqli->real_escape_string($surrogate);
$query  = "SELECT owner FROM `text` WHERE Surrogate='$surrogateSafe' LIMIT 1";
$result = $mysqli->query($query);
$item   = $result ? $result->fetch_assoc() : null;
if (!$item || empty($item['owner'])) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'error' => 'Item not found']);
    exit;
}
$owner       = $item['owner'];
$currentUser = $_SESSION['username'];
if (!can_user_edit_surrogate($mysqli, $surrogate, $currentUser)) {
    http_response_code(403);
    echo json_encode(['status'=>'error','error'=>'No rights']);
    exit;
}

// build paths
$safeSurrogate = preg_replace('/[^a-zA-Z0-9_-]/', '', $surrogate);
$basePath      = '/home1/wecanrec/textwhisper_uploads';

if ($type === 'pdf') {
    $targetDir = "$basePath/$owner/pdf";
    $safeName  = "temp_pdf_surrogate-$safeSurrogate.pdf";
    $finalPath = "$targetDir/$safeName";
} else {
    $targetDir    = "$basePath/$owner/surrogate-$safeSurrogate/files";
    $originalName = $_POST['originalName'] ?? $file['name'];
    $safeName     = preg_replace('/[^\p{L}0-9_\-\.]/u','_', $originalName);
    $finalPath    = "$targetDir/$safeName";
}
if (!is_dir($targetDir)) mkdir($targetDir,0775,true);

// --- simple single file upload ---
if (move_uploaded_file($file['tmp_name'], $finalPath)) {
    if ($type === 'pdf') {
        $url = "/textwhisper_uploads/$owner/pdf/$safeName";
    } else {
        $url = "/textwhisper_uploads/$owner/surrogate-$safeSurrogate/files/$safeName";
    }
    log_change($mysqli, 'upload', (int)$surrogate, $owner, $currentUser, $type, $url, 'local');
    echo json_encode(['status'=>'success','url'=>$url]);
} else {
    http_response_code(500);
    echo json_encode(['status'=>'error','error'=>'Failed to save file']);
}
