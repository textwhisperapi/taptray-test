<?php
//cloudFlare_fileUpload.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . "/api/config_cloudflare.php";
require '/home1/wecanrec/textwhisper_vendor/aws_vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

$bucket = 'twaudio';

// Cloudflare gives every bucket a public r2.dev domain
$publicDomain = "pub-1afc23a510c147a5a857168f23ff6db8.r2.dev";

if (isset($_GET['name'])) {
    $originalName = $_GET['name'];
    $safeKey = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($originalName));

    try {
        $client = new S3Client([
            'region' => 'auto',
            'version' => 'latest',
            'endpoint' => "https://{$accountId}.r2.cloudflarestorage.com",
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key'    => $accessKey,
                'secret' => $secretKey,
            ],
        ]);

        // Presigned PUT URL (browser uses this for upload)
        $cmd = $client->getCommand('PutObject', [
            'Bucket' => $bucket,
            'Key'    => $safeKey,
        ]);
        $request = $client->createPresignedRequest($cmd, '+15 minutes');
        $uploadUrl = (string) $request->getUri();

        // Public URL for playback/download
        $publicUrl = "https://{$publicDomain}/" . rawurlencode($safeKey);

        header("Content-Type: application/json");
        echo json_encode([
            "status"   => "ok",
            "upload"   => $uploadUrl,   // used by JS to PUT the file
            "public"   => $publicUrl,   // permanent link for playback
            "filename" => $originalName,
            "storedAs" => $safeKey
        ]);
    } catch (AwsException $e) {
        http_response_code(500);
        echo json_encode([
            "status" => "error",
            "msg"    => $e->getAwsErrorMessage() ?: $e->getMessage()
        ]);
    }
    exit;
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Cloudflare R2 Upload Test (Direct)</title>
<style>
  #progressWrapper { width: 400px; border: 1px solid #ccc; margin-top: 20px; }
  #progressBar { height: 20px; width: 0; background: #4a90e2; text-align:center; color:white; }
  #stats { margin-top: 10px; font-family: monospace; }
  #stage { margin-top: 15px; font-weight: bold; color: #444; }
</style>
</head>
<body>
  <h2>Upload Directly to Cloudflare R2</h2>
  <input type="file" id="fileInput">
  <button onclick="upload()">Upload</button>

  <div id="progressWrapper">
    <div id="progressBar">0%</div>
  </div>
  <div id="stats">Progress: 0% | Elapsed: 0.0s | Speed: 0 MB/s | ETA: --s</div>
  <div id="stage">Idle</div>

  <pre id="output"></pre>

<script>
async function upload() {
  const file = document.getElementById("fileInput").files[0];
  if (!file) return alert("Pick a file");

  document.getElementById("stage").textContent = "Stage 1: Requesting presigned URL…";

  // Step 1: get presigned URL from PHP
  const resp = await fetch("cloudFlare_fileUpload.php?name=" + encodeURIComponent(file.name));
  const data = await resp.json();

  if (data.status !== "ok") {
    document.getElementById("stage").textContent = "❌ Failed to get presigned URL";
    document.getElementById("output").textContent = JSON.stringify(data, null, 2);
    return;
  }

  const uploadUrl = data.url;
  const publicUrl = data.public;

  // Step 2: PUT file directly to R2
  const xhr = new XMLHttpRequest();
  xhr.open("PUT", uploadUrl, true);
      
  //Ensure Content-Type is sent
  xhr.setRequestHeader("Content-Type", file.type || "application/octet-stream");  

  const startTime = Date.now();
  document.getElementById("stage").textContent = "Stage 2: Uploading to R2…";

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
    if (xhr.status === 200) {
      document.getElementById("stats").textContent += ` | ✅ Done in ${elapsed.toFixed(1)}s`;
      document.getElementById("stage").textContent = "✅ Uploaded to R2: " + file.name;
      document.getElementById("output").textContent = JSON.stringify(data, null, 2);

      const a = document.createElement("a");
      a.href = publicUrl;
      a.textContent = "Open uploaded file";
      a.target = "_blank";
      document.body.appendChild(a);
    } else {
      document.getElementById("stage").textContent = "❌ Upload failed (" + xhr.status + ")";
      document.getElementById("output").textContent = xhr.responseText;
    }
  };

  xhr.onerror = function() {
    document.getElementById("stage").textContent = "❌ Connection error";
  };

  xhr.send(file);
}
</script>
</body>
</html>
