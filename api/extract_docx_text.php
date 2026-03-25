<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

session_start();
if (!isset($_SESSION['username']) || !$_SESSION['username']) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'error' => 'Method not allowed']);
    exit;
}

if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => 'Missing file upload']);
    exit;
}

$file = $_FILES['file'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => 'Upload failed']);
    exit;
}

$name = (string)($file['name'] ?? '');
if (!preg_match('/\.docx$/i', $name)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => 'Only .docx files are supported']);
    exit;
}

$tmpPath = (string)($file['tmp_name'] ?? '');
if ($tmpPath === '' || !is_file($tmpPath)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => 'Temporary file missing']);
    exit;
}

$zip = new ZipArchive();
if ($zip->open($tmpPath) !== true) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => 'Could not open DOCX']);
    exit;
}

$xml = $zip->getFromName('word/document.xml');
$zip->close();

if (!is_string($xml) || $xml === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => 'DOCX content not found']);
    exit;
}

$dom = new DOMDocument();
libxml_use_internal_errors(true);
$ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
libxml_clear_errors();

if (!$ok) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => 'Invalid DOCX XML']);
    exit;
}

$xp = new DOMXPath($dom);
$xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

$lines = [];
$paras = $xp->query('//w:body/w:p');
if ($paras instanceof DOMNodeList) {
    foreach ($paras as $p) {
        $parts = [];
        foreach ($xp->query('.//w:t', $p) as $t) {
            $parts[] = $t->textContent;
        }
        $line = trim(implode('', $parts));
        $lines[] = $line;
    }
}

$text = trim(implode("\n", $lines));

echo json_encode([
    'status' => 'ok',
    'text' => $text,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

