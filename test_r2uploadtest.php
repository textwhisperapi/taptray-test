<?php
// ✅ SHOW ERRORS (only for dev)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . "/api/config_cloudflare.php";
require '/home1/wecanrec/textwhisper_vendor/aws_vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

// ✅ Make sure bucket is defined
$bucket = 'twaudio';

// ✅ R2 client with SSL bypass fix
$client = new S3Client([
    'region' => 'us-east-1', // Required dummy region
    'version' => 'latest',
    'endpoint' => "https://{$accountId}.r2.cloudflarestorage.com",
    'use_path_style_endpoint' => true,
    'credentials' => [
        'key'    => $accessKey,
        'secret' => $secretKey,
    ],
    'http' => [
        'verify' => false, // ✅ Fix SSL handshake
        'curl' => [
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2 | CURL_SSLVERSION_TLSv1_3,
        ],
    ],
]);

// ✅ Handle upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    header("Content-Type: application/json");

    $file = $_FILES['file'];
    if ($file['error'] === UPLOAD_ERR_OK) {
        $key = basename($file['name']);

        try {
            $result = $client->putObject([
                'Bucket' => $bucket,
                'Key'    => $key,
                'Body'   => fopen($file['tmp_name'], 'rb'),
                'ACL'    => 'public-read',
            ]);

            echo json_encode([
                "status" => "success",
                "key"    => $key,
                "etag"   => $result['ETag'] ?? null,
                //"url"    => "https://pub-1afc23a510c147a5a857168f23ff6db8.r2.dev/{$bucket}/" . rawurlencode($key),
                "url" => "https://cdn.geirigrimmi.com/" . rawurlencode($key),
            ]);
        } catch (AwsException $e) {
            http_response_code(500);
            echo json_encode([
                "status" => "error",
                "msg"    => $e->getAwsErrorMessage() ?: $e->getMessage(),
                "details" => [
                    "type" => "AwsException",
                    "class" => get_class($e),
                    "code" => $e->getAwsErrorCode(),
                    "http" => $e->getStatusCode(),
                    "raw" => $e->getMessage(),
                ],
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                "status" => "error",
                "msg"    => $e->getMessage() ?: "Unknown error",
                "details" => [
                    "type" => "GenericException",
                    "class" => get_class($e),
                    "code" => $e->getCode(),
                    "raw" => $e->__toString(),
                ],
            ]);

        }
    } else {
        http_response_code(400);
        echo json_encode([
            "status" => "error",
            "msg"    => "Upload error code: {$file['error']}",
        ]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Cloudflare R2 Upload Test</title>
<style>
  #progressWrapper { width: 400px; border: 1px solid #ccc; margin-top: 20px; }
  #progressBar { height: 20px; width: 0; background: #4a90e2; text-align:center; color:white; }
  #stats { margin-top: 10px; font-family: monospace; }
  #stage { margin-top: 15px; font-weight: bold; color: #444; }
</style>
</head>
<body>
  <h2>Upload Test File to Cloudflare R2</h2>
  <input type="file" id="fileInput">
  <button onclick="upload()">Upload</button>

  <div id="progressWrapper">
    <div id="progressBar">0%</div>
  </div>
  <div id="stats">Progress: 0% | Elapsed: 0.0s | Speed: 0 MB/s | ETA: --s</div>
  <div id="stage">Idle</div>

  <pre id="output"></pre>

<script>
function upload() {
  const file = document.getElementById("fileInput").files[0];
  if (!file) return alert("Pick a file");

  const xhr = new XMLHttpRequest();
  const formData = new FormData();
  formData.append("file", file);

  xhr.open("POST", window.location.pathname, true);

  const startTime = Date.now();
  document.getElementById("stage").textContent = "Stage 1: Uploading to server…";

  xhr.upload.onprogress = function(e) {
    if (e.lengthComputable) {
      const percent = (e.loaded / e.total) * 100;
      const bar = document.getElementById("progressBar");
      bar.style.width = percent.toFixed(1) + "%";
      bar.textContent = percent.toFixed(1) + "%";

      const elapsed = (Date.now() - startTime) / 1000;
      const speed = (e.loaded / (1024*1024)) / elapsed;
      const remaining = e.total - e.loaded;
      const eta = remaining > 0 ? (remaining / (1024*1024)) / speed : 0;

      document.getElementById("stats").textContent =
        `Progress: ${percent.toFixed(1)}% | Elapsed: ${elapsed.toFixed(1)}s | Speed: ${speed.toFixed(2)} MB/s | ETA: ${eta.toFixed(1)}s`;
    }
  };

  xhr.onload = function() {
    const elapsed = (Date.now() - startTime) / 1000;
    document.getElementById("stats").textContent += ` | ✅ Done in ${elapsed.toFixed(1)}s`;
    document.getElementById("stage").textContent = "Stage 2: Uploading to Cloudflare R2…";
    document.getElementById("output").textContent = xhr.responseText;

    try {
      const res = JSON.parse(xhr.responseText);
      if (res.status === "success") {
        document.getElementById("stage").textContent = "✅ Uploaded to R2: " + res.key;
      } else {
        document.getElementById("stage").textContent = "❌ R2 error: " + (res.msg || "unknown");
      }
    } catch {
      document.getElementById("stage").textContent = "❌ Unexpected response";
    }
  };

  xhr.onerror = function() {
    document.getElementById("output").textContent = "❌ Upload failed";
    document.getElementById("stage").textContent = "❌ Connection error";
  };

  xhr.send(formData);
}
</script>
</body>
</html>
