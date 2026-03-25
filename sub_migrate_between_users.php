<?php
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/db_connect.php";
sec_session_start();

if (!isset($_SESSION['user_id'])) {
  header("Location: /login.php");
  exit;
}

$username = $_SESSION['username'] ?? 'unknown';
$version  = time();
$R2_BASE  = "https://r2-worker.textwhisper.workers.dev";

header_remove("X-Powered-By");

$langFile = __DIR__ . "/lang/en.php";
$lang = file_exists($langFile) ? include $langFile : [];
$t = fn($k, $d) => ($lang[$k] ?? $d);

// ---------- AJAX ----------
if (isset($_GET['ajax'])) {
  header("Content-Type: application/json");
  $action = $_POST['action'] ?? '';

  // 1️⃣ Scan for files that are missing in destination
  if ($action === 'scan') {
    $from = trim($_POST['fromUser'] ?? '');
    $to   = trim($_POST['toUser'] ?? '');
    if ($from === '' || $to === '') {
      echo json_encode(['status' => 'error', 'error' => 'Missing source or destination user']);
      exit;
    }

    $prefix = "$from/";
    $listUrl = "$R2_BASE/list?prefix=" . rawurlencode($prefix);
    $data = @file_get_contents($listUrl);
    if (!$data) {
      echo json_encode(['status' => 'error', 'error' => 'Cannot list source files']);
      exit;
    }

    $objects = json_decode($data, true);
    if (!is_array($objects)) {
      echo json_encode(['status' => 'error', 'error' => 'Invalid list JSON']);
      exit;
    }

    // Filter: only files missing in destination
    $missing = [];
    foreach ($objects as $obj) {
      $oldKey = $obj['key'];
      $newKey = str_replace("$from/", "$to/", $oldKey);

      $checkUrl = "$R2_BASE/" . str_replace('%2F','/', rawurlencode($newKey));
      $ch = curl_init($checkUrl);
      curl_setopt_array($ch, [
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0
      ]);
      curl_exec($ch);
      $exists = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
      curl_close($ch);

      if (!$exists) {
        $missing[] = ['oldKey' => $oldKey, 'newKey' => $newKey];
      }
    }

    echo json_encode(['status' => 'success', 'objects' => $missing]);
    exit;
  }

  // 2️⃣ Copy one object (already confirmed missing)
  if ($action === 'copyOne') {
    $oldKey = $_POST['oldKey'] ?? '';
    $newKey = $_POST['newKey'] ?? '';
    if (!$oldKey || !$newKey) {
      echo json_encode(['status' => 'error', 'error' => 'Missing keys']);
      exit;
    }

    // Download
    $getUrl = $R2_BASE . '/' . str_replace('%2F','/', rawurlencode($oldKey));
    $ch = curl_init($getUrl);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_SSL_VERIFYPEER => false,
      CURLOPT_SSL_VERIFYHOST => 0,
      CURLOPT_HEADER => true
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($resp, 0, $headerSize);
    $body = substr($resp, $headerSize);
    curl_close($ch);

    if ($code !== 200 && $code !== 206) {
      echo json_encode(['status' => 'error', 'error' => "GET failed ($code)"]);
      exit;
    }

    $contentType = "application/octet-stream";
    foreach (explode("\r\n", $headers) as $line) {
      if (stripos($line, "Content-Type:") === 0) {
        $contentType = trim(substr($line, 13));
        break;
      }
    }

    // Upload
    $putUrl = "$R2_BASE/?key=" . rawurlencode($newKey);
    $ch = curl_init($putUrl);
    curl_setopt_array($ch, [
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => $body,
      CURLOPT_HTTPHEADER => ["Content-Type: $contentType"],
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_SSL_VERIFYPEER => false,
      CURLOPT_SSL_VERIFYHOST => 0
    ]);
    $out = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status >= 200 && $status < 300) {
      echo json_encode(['status' => 'success']);
    } else {
      echo json_encode(['status' => 'error', 'error' => "PUT failed ($status)"]);
    }
    exit;
  }

  echo json_encode(['status' => 'error', 'error' => 'Unknown action']);
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>☁️ Migrate Missing Files Only</title>
  <link rel="stylesheet" href="/sub_settings.css?v=<?= $version ?>">
  <style>
    body { font-family: system-ui, sans-serif; }
    .migration-wrapper { max-width: 900px; margin: 0 auto; padding: 20px; }
    table { width:100%; border-collapse:collapse; margin-top:20px; }
    th, td { border:1px solid #ccc; padding:6px; font-size:14px; }
    .status { text-align:center; }
    .status-pending { color:#999; }
    .status-copying { color:#06f; }
    .status-done { color:green; }
    .status-error { color:red; }
    #progressWrapper { display:none; background:#eee; margin:10px 0; }
    #progressBar { height:20px; background:#4caf50; width:0%; transition:width .2s; }
  </style>
</head>
<body>
<div class="migration-wrapper">
  <a href="javascript:history.back()">← Back</a>
  <h2>☁️ Migrate Missing Files Between Cloudflare Users</h2>

  <div style="margin-bottom:10px">
    <label>From user: <input id="fromUser" type="text" placeholder="helgabergmann10"></label>
    <label style="margin-left:20px;">To user: <input id="toUser" type="text" placeholder="korlindakirkju"></label>
    <button id="scanBtn">🔍 Scan for missing</button>
    <button id="startBtn" disabled>🚀 Start</button>
  </div>

  <div id="progressWrapper"><div id="progressBar"></div></div>
  <p id="progressText"></p>

  <table>
    <thead><tr><th>#</th><th>Source</th><th>Destination</th><th>Status</th></tr></thead>
    <tbody id="fileTable"><tr><td colspan="4">Waiting…</td></tr></tbody>
  </table>
</div>

<script>
const scanBtn = document.getElementById("scanBtn");
const startBtn = document.getElementById("startBtn");
const fromUser = document.getElementById("fromUser");
const toUser = document.getElementById("toUser");
const tbody = document.getElementById("fileTable");
const progressBar = document.getElementById("progressBar");
const progressText = document.getElementById("progressText");
const wrapper = document.getElementById("progressWrapper");

let queue = [];

scanBtn.addEventListener("click", async () => {
  const from = fromUser.value.trim();
  const to = toUser.value.trim();
  if (!from || !to) return alert("Enter both source and destination usernames.");
  tbody.innerHTML = "<tr><td colspan='4'>Scanning…</td></tr>";

  const fd = new FormData();
  fd.append("action", "scan");
  fd.append("fromUser", from);
  fd.append("toUser", to);
  const res = await fetch("?ajax=1", { method: "POST", body: fd });
  const j = await res.json().catch(()=>null);
  if (!j || j.status !== "success") {
    tbody.innerHTML = "<tr><td colspan='4'>❌ " + (j?.error || "Scan failed") + "</td></tr>";
    return;
  }

  queue = j.objects;
  if (!queue.length) {
    tbody.innerHTML = "<tr><td colspan='4'>✅ Everything is already migrated.</td></tr>";
    return;
  }

  renderTable(queue);
  startBtn.disabled = false;
});

startBtn.addEventListener("click", async () => {
  if (!queue.length) return;
  if (!confirm("Copy " + queue.length + " missing files?")) return;
  wrapper.style.display = "block";
  progressBar.style.width = "0%";
  progressText.textContent = "";
  startBtn.disabled = true;

  let done = 0;
  for (let i = 0; i < queue.length; i++) {
    const f = queue[i];
    updateStatus(i, "copying");
    const fd = new FormData();
    fd.append("action", "copyOne");
    fd.append("oldKey", f.oldKey);
    fd.append("newKey", f.newKey);
    try {
      const res = await fetch("?ajax=1", { method: "POST", body: fd });
      const j = await res.json();
      if (j.status === "success") updateStatus(i, "done");
      else updateStatus(i, "error", j.error);
    } catch (e) {
      updateStatus(i, "error", e.message);
    }
    done++;
    const percent = ((done / queue.length) * 100).toFixed(1);
    progressBar.style.width = percent + "%";
    progressText.textContent = `${done}/${queue.length} (${percent}%)`;
  }
  startBtn.disabled = false;
});

function renderTable(list) {
  tbody.innerHTML = "";
  list.forEach((f, i) => {
    const tr = document.createElement("tr");
    tr.innerHTML = `
      <td>${i+1}</td>
      <td><code>${f.oldKey}</code></td>
      <td><code>${f.newKey}</code></td>
      <td class="status status-pending">Pending</td>
    `;
    tbody.appendChild(tr);
  });
}

function updateStatus(i, s, msg = "") {
  const tr = tbody.children[i];
  if (!tr) return;
  const td = tr.querySelector(".status");
  td.className = "status status-" + s;
  td.textContent = s === "done" ? "✅ Done" :
                   s === "copying" ? "Copying…" :
                   s === "error" ? "❌ " + msg :
                   s;
}
</script>
</body>
</html>
