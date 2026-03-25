<?php
header("Content-Type: application/json");
$__start = microtime(true);

$url        = $_POST['url'] ?? '';
$surrogate  = $_POST['surrogate'] ?? '';
$owner      = $_POST['owner'] ?? '';
$accessToken = $_POST['accessToken'] ?? '';

// ✅ Validate required parameters
if (!$url || !$surrogate || !$owner || !preg_match('/^[a-zA-Z0-9_-]+$/', $owner)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => 'Missing or invalid URL, surrogate, or owner']);
    exit;
}

// ✅ Safe filename and target paths
$safeSurrogate = preg_replace('/[^a-zA-Z0-9_-]/', '', $surrogate);
$filename = "temp_pdf_surrogate-$safeSurrogate.pdf";
$basePath = '/home1/wecanrec/textwhisper_uploads';
//$targetDir = __DIR__ . "/textwhisper_uploads/$owner/pdf";
$targetDir = "$basePath/$owner/pdf";

if (!is_dir($targetDir)) {
    mkdir($targetDir, 0775, true);
}

$fullPath = "$targetDir/$filename";
$webPath = "/textwhisper_uploads/$owner/pdf/$filename";

// ✅ Already downloaded?
if (file_exists($fullPath)) {
    echo json_encode(['status' => 'success', 'url' => $webPath, 'cached' => true]);
    exit;
}

// ✅ Convert Drive link
if (preg_match('/drive\.google\.com\/file\/d\/([a-zA-Z0-9_-]+)/', $url, $match)) {
    $fileId = $match[1];

    if ($accessToken) {
        $url = "https://www.googleapis.com/drive/v3/files/$fileId?alt=media";
    } else {
        $url = "https://drive.google.com/uc?export=download&id=$fileId";
    }
}

// ✅ Setup cURL
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

if ($accessToken) {
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $accessToken",
        "Accept: application/json"
    ]);
}

$response = curl_exec($ch);
$httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

// ❌ Handle errors
if (!$response || $httpStatus !== 200 || !preg_match('/pdf|octet-stream/', $contentType)) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'error' => 'Fetch failed or invalid file type',
        'httpStatus' => $httpStatus,
        'contentType' => $contentType,
    ]);
    exit;
}

// ✅ Save the PDF
file_put_contents($fullPath, $response);

// Optional timing/debug log
$duration = round((microtime(true) - $__start) * 1000, 2);
file_put_contents(__DIR__ . "/upload_debug.log", "⏱ uploadPDF.php duration: {$duration}ms\n", FILE_APPEND);

// ✅ Done
echo json_encode(['status' => 'success', 'url' => $webPath, 'cached' => false]);
?>
