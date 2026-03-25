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

// Endpoints
$R2_READ  = "https://pub-1afc23a510c147a5a857168f23ff6db8.r2.dev";   // public for GET
$R2_WRITE = "https://r2-worker.textwhisper.workers.dev";              // Worker for PUT/POST

header_remove("X-Powered-By");

// ---------- AJAX ----------
if (isset($_GET['ajax'])) {
  header("Content-Type: application/json");
  $action = $_POST['action'] ?? '';

  // 1️⃣ Scan music files missing in destination
  if ($action === 'scan') {
    $from = trim($_POST['fromUser'] ?? '');
    $to   = trim($_POST['toUser'] ?? '');
    if ($from === '' || $to === '') {
      echo json_encode(['status'=>'error','error'=>'Missing source or destination user']);
      exit;
    }

    // --- List all objects under fromUser/ ---
    $listUrl = "$R2_WRITE/list?prefix=" . rawurlencode("$from/");
    $data = @file_get_contents($listUrl);
    if (!$data) {
      echo json_encode(['status'=>'error','error'=>'Cannot list source files']);
      exit;
    }

    $objects = json_decode($data, true);
    if (!is_array($objects)) {
      echo json_encode(['status'=>'error','error'=>'Invalid list JSON']);
      exit;
    }

    // --- Filter: only /surrogate-####/files/ (music/audio) ---
    $music = array_values(array_filter($objects, function($o){
      return preg_match('~/surrogate-\d+/files/~', $o['key']);
    }));

    // --- Find missing ones in destination ---
    $missing = [];
    foreach ($music as $obj) {
      $oldKey = $obj['key'];
      $newKey = str_replace("$from/", "$to/", $oldKey);

      $checkUrl = "$R2_READ/" . str_replace('%2F','/', rawurlencode($newKey));
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

      if (!$exists) $missing[] = ['oldKey'=>$oldKey,'newKey'=>$newKey];
    }

    echo json_encode(['status'=>'success','objects'=>$missing]);
    exit;
  }

  // 2️⃣ Copy one music file (GET from pub, PUT to Worker)
  if ($action === 'copyOne') {
    $oldKey = $_POST['oldKey'] ?? '';
    $newKey = $_POST['newKey'] ?? '';
    if (!$oldKey || !$newKey) {
      echo json_encode(['status'=>'error','error'=>'Missing keys']);
      exit;
    }

    // Normalize spacing and encoding quirks
    $oldKey = preg_replace('/\s+/', ' ', $oldKey); // collapse multiple spaces
    $getUrl = $R2_READ . '/' . str_replace('%2F','/', rawurlencode($oldKey));

    // --- GET from public R2 ---
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

    // 🧩 Try relaxed fallback if first GET fails
    if ($code === 404) {
      $altKey = preg_replace('/\s+/', ' ', $oldKey);
      $altKey = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $altKey); // strip accents
      $altKey = preg_replace('/[^A-Za-z0-9_\/\.\- ]/', '', $altKey); // remove exotic chars

      $altUrl = $R2_READ . '/' . str_replace('%2F','/', rawurlencode($altKey));
      $ch2 = curl_init($altUrl);
      curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HEADER => true
      ]);
      $resp2 = curl_exec($ch2);
      $code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
      $headerSize2 = curl_getinfo($ch2, CURLINFO_HEADER_SIZE);
      $headers2 = substr($resp2, 0, $headerSize2);
      $body2 = substr($resp2, $headerSize2);
      curl_close($ch2);

      if ($code2 === 200 || $code2 === 206) {
        // ✅ Fallback succeeded
        $resp = $resp2;
        $headers = $headers2;
        $body = $body2;
        $code = $code2;
      }
    }

    if ($code !== 200 && $code !== 206) {
      echo json_encode(['status' => 'error', 'error' => "GET failed ($code)"]);
      exit;
    }

    // --- Determine Content-Type ---
    $contentType = "application/octet-stream";
    foreach (explode("\r\n", $headers) as $line) {
      if (stripos($line, "Content-Type:") === 0) {
        $contentType = trim(substr($line, 13));
        break;
      }
    }

    // --- PUT to Worker (upload to new user prefix) ---
    $putUrl = "$R2_WRITE/?key=" . rawurlencode($newKey);
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
      echo json_encode(['status'=>'success']);
    } else {
      echo json_encode(['status'=>'error','error'=>"PUT failed ($status)"]);
    }
    exit;
  }

} // ✅ closes AJAX handler
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>🎵 Migrate Missing Music Files</title>
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
  <h2>🎵 Migrate Missing Music Files Between Cloudflare Users</h2>

  <div style="margin-bottom:10px">
    <label>From user: <input id="fromUser" type="text" placeholder="helgabergmann10"></label>
    <label style="margin-left:20px;">To user: <input id="toUser" type="text" placeholder="korlindakirkju"></label>
    <button id="scanBtn">🔍 Scan missing music</button>
    <button id="startBtn" disabled>🚀 Start migration</button>
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
    tbody.innerHTML = "<tr><td colspan='4'>✅ All music files are already migrated.</td></tr>";
    return;
  }

  renderTable(queue);
  startBtn.disabled = false;
});

startBtn.addEventListener("click", async () => {
  if (!queue.length) return;
  if (!confirm("Copy " + queue.length + " missing music files?")) return;
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

  if (s === "done") {
    td.innerHTML = "✅ Done";
  } else if (s === "copying") {
    td.innerHTML = "Copying…";
  } else if (s === "error") {
    const file = queue[i];
    const encodedKey = encodeURIComponent(file.oldKey);
    const downloadUrl = `https://pub-1afc23a510c147a5a857168f23ff6db8.r2.dev/${encodedKey}`;
    td.innerHTML = `❌ ${msg || "GET failed"} 
      <a href="${downloadUrl}" target="_blank" 
         style="margin-left:6px; text-decoration:none; color:#06f;">💾 Download</a>`;
  } else {
    td.textContent = s;
  }
}

</script>
</body>
</html>
