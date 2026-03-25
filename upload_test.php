<?php
// --- PHP upload handler ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    header("Content-Type: application/json");

    $uploadDir = __DIR__ . "/uploads";
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    $file = $_FILES['file'];
    $target = $uploadDir . "/" . basename($file['name']);

    if (move_uploaded_file($file['tmp_name'], $target)) {
        echo json_encode([
            "status" => "success",
            "path"   => $target,
            "size"   => filesize($target)
        ]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "msg" => "Move failed"]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Upload Test</title>
<style>
  #progressWrapper { width: 400px; border: 1px solid #ccc; margin-top: 20px; }
  #progressBar { height: 20px; width: 0; background: #4a90e2; text-align:center; color:white; }
  #stats { margin-top: 10px; font-family: monospace; }
</style>
</head>
<body>
  <h2>Upload Test</h2>
  <input type="file" id="fileInput">
  <button onclick="upload()">Upload</button>

  <div id="progressWrapper">
    <div id="progressBar">0%</div>
  </div>
  <div id="stats">Progress: 0% | Elapsed: 0.0s | Speed: 0 MB/s | ETA: --s</div>

  <pre id="output"></pre>

<script>
function upload() {
  const file = document.getElementById("fileInput").files[0];
  if (!file) return alert("Pick a file");

  const xhr = new XMLHttpRequest();
  const formData = new FormData();
  formData.append("file", file);

  xhr.open("POST", location.href, true);

  const startTime = Date.now();

  xhr.upload.onprogress = function(e) {
    if (e.lengthComputable) {
      const percent = (e.loaded / e.total) * 100;
      const bar = document.getElementById("progressBar");
      bar.style.width = percent.toFixed(1) + "%";
      bar.textContent = percent.toFixed(1) + "%";

      const elapsed = (Date.now() - startTime) / 1000;
      const speed = (e.loaded / (1024*1024)) / elapsed; // MB/s
      const remaining = e.total - e.loaded;
      const eta = remaining > 0 ? (remaining / (1024*1024)) / speed : 0;

      document.getElementById("stats").textContent =
        `Progress: ${percent.toFixed(1)}% | Elapsed: ${elapsed.toFixed(1)}s | Speed: ${speed.toFixed(2)} MB/s | ETA: ${eta.toFixed(1)}s`;
    }
  };

  xhr.onload = function() {
    const elapsed = (Date.now() - startTime) / 1000;
    document.getElementById("stats").textContent += ` | ✅ Done in ${elapsed.toFixed(1)}s`;
    document.getElementById("output").textContent = xhr.responseText;
  };

  xhr.onerror = function() {
    document.getElementById("output").textContent = "❌ Upload failed";
  };

  xhr.send(formData);
}
</script>
</body>
</html>
