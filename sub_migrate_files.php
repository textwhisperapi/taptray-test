<?php
//sub_migrate_files.php
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/db_connect.php";
sec_session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: /login.php");
    exit;
}

$username = $_SESSION['username'] ?? 'unknown';
$version  = time();

$langFile = __DIR__ . "/lang/en.php"; // load language, adapt to $locale if needed
$lang = file_exists($langFile) ? include $langFile : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title><?= $lang['migrate_files_cloudflare'] ?? 'Migrate files to Cloudflare' ?></title>
  <link rel="stylesheet" href="/sub_settings.css?v=<?= $version ?>">
  <style>
    .migration-wrapper { max-width: 900px; margin: 0 auto; padding: 20px; }
    .migration-table { width:100%; border-collapse:collapse; margin-top:20px; }
    .migration-table th, .migration-table td { border:1px solid #ccc; padding:6px; font-size:14px; }
    .status-pending { color:#999; }
    .status-processing { color:#0066cc; }
    .status-done { color:green; }
    .status-failed { color:red; }
    #progressBar { height:20px; background:#4caf50; width:0%; transition:width .2s; }
    #progressWrapper { background:#eee; margin:10px 0; width:100%; }
  </style>
</head>
<body>
<div class="migration-wrapper">
    
  <a href="javascript:history.back()" class="back-link">← Back</a>    
  
  <h2>☁️ <?= $lang['migrate_files_cloudflare'] ?? 'Migrate files to Cloudflare' ?></h2>

  <label>
    <?= $lang['select_type'] ?? 'Select type' ?>:
    <select id="migrateTypeSelect">
      <option value="music"><?= $lang['files_music'] ?? 'Music files' ?></option>
      <option value="pdf"><?= $lang['files_pdf'] ?? 'PDF + annotations' ?></option>
      <option value="all"><?= $lang['files_all'] ?? 'All files' ?></option>
    </select>
  </label>

  <button id="scanBtn"><?= $lang['scan'] ?? 'Scan files' ?></button>
  <button id="startMigrationBtn" disabled><?= $lang['action_migrate'] ?? 'Migrate' ?></button>

  <div id="progressWrapper" style="display:none;">
    <div id="progressBar"></div>
    <p id="progressText"></p>
  </div>

  <table id="fileTable" class="migration-table">
    <thead>
      <tr>
        <th><?= $lang['surrogate'] ?? 'Surrogate' ?></th>
        <th><?= $lang['filename'] ?? 'Filename' ?></th>
        <th><?= $lang['status'] ?? 'Status' ?></th>
      </tr>
    </thead>
    <tbody></tbody>
  </table>
</div>

<script>
const scanBtn = document.getElementById("scanBtn");
const startBtn = document.getElementById("startMigrationBtn");
const typeSelect = document.getElementById("migrateTypeSelect");
const tbody = document.querySelector("#fileTable tbody");
const progressWrapper = document.getElementById("progressWrapper");
const progressBar = document.getElementById("progressBar");
const progressText = document.getElementById("progressText");

let filesToMigrate = [];

scanBtn.addEventListener("click", async () => {
  tbody.innerHTML = "<tr><td colspan='3'><?= $lang['processing'] ?? 'Processing…' ?></td></tr>";
  startBtn.disabled = true;

  const type = typeSelect.value;
  try {
    const res = await fetch("/File_listAllFiles.php?type=" + encodeURIComponent(type));
    const data = await res.json();

    if (data.status !== "success") throw new Error(data.error || "Scan failed");

    filesToMigrate = data.files;
    renderFileTable(filesToMigrate);
    startBtn.disabled = filesToMigrate.length === 0;
  } catch (err) {
    tbody.innerHTML = "<tr><td colspan='3'>❌ " + err.message + "</td></tr>";
  }
});

startBtn.addEventListener("click", async () => {
  if (!confirm("<?= $lang['msg_confirm_action'] ?? '⚠️ This will process your files. Continue?' ?>")) return;

  progressWrapper.style.display = "block";
  progressBar.style.width = "0%";
  progressText.textContent = "";

  let processed = 0;
  const total = filesToMigrate.length;

  for (let i = 0; i < total; i++) {
    const file = filesToMigrate[i];

    // ✅ Skip Cloudflare-only
    if (!file.exists_local && file.exists_on_cloudflare) {
      updateRowStatus(file, "cloudflare_only");
      processed++;
      const percent = ((processed / total) * 100).toFixed(1);
      progressBar.style.width = percent + "%";
      progressText.textContent = `${processed}/${total} ( ${percent}% )`;
      continue;
    }

    // ✅ Skip Already migrated
    if (file.exists_local && file.exists_on_cloudflare) {
      updateRowStatus(file, "already");
      processed++;
      const percent = ((processed / total) * 100).toFixed(1);
      progressBar.style.width = percent + "%";
      progressText.textContent = `${processed}/${total} ( ${percent}% )`;
      continue;
    }

    // 🚀 Pending → migrate
    updateRowStatus(file, "processing");

    try {
      const res = await fetch("/cloudflare_migrate_files.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body:
          "type=" + encodeURIComponent(file.type) +
          "&surrogate=" + encodeURIComponent(file.surrogate ?? "") +
          "&filename=" + encodeURIComponent(file.name)
      });
      const result = await res.json();
      if (result.status === "success") {
        updateRowStatus(file, "done");
      } else {
        updateRowStatus(file, "failed", result.error || "");
      }
    } catch (e) {
      updateRowStatus(file, "failed", e.message);
    }

    processed++;
    const percent = ((processed / total) * 100).toFixed(1);
    progressBar.style.width = percent + "%";
    progressText.textContent = `${processed}/${total} ( ${percent}% )`;
  }
});

// --- Helpers ---
function renderFileTable(files) {
  tbody.innerHTML = "";
  files.forEach((file, idx) => {
    const tr = document.createElement("tr");

    // Parse surrogate (or from key)
    let surrogateText = file.surrogate;
    if (!surrogateText && file.key) {
      const match = file.key.match(/surrogate-(\d+)/);
      if (match) surrogateText = match[1];
    }
    surrogateText = surrogateText || "–";

    tr.dataset.index = idx; // ✅ reliable row identifier
    tr.dataset.name = file.name;

    // Decide initial status
    let initialStatus;
    if (file.type === "cloudflare_only") {
      initialStatus = "cloudflare_only";
    } else if (file.exists_local && file.exists_on_cloudflare) {
      initialStatus = "already";
    } else if (file.exists_local && !file.exists_on_cloudflare) {
      initialStatus = "pending";
    } else if (!file.exists_local && file.exists_on_cloudflare) {
      initialStatus = "cloudflare_only";
    } else {
      initialStatus = "failed";
    }

    tr.innerHTML = `
      <td>${surrogateText}</td>
      <td>${file.name}</td>
      <td class="status status-${initialStatus}"></td>
    `;

    tbody.appendChild(tr);
    updateRowStatusByIndex(idx, initialStatus);
  });
}






function updateRowStatus(file, status, message = "") {
  const row = tbody.querySelector(
    `tr[data-surrogate="${file.surrogate ?? ""}"][data-name="${file.name}"]`
  );
  if (!row) return;

  const td = row.querySelector(".status");
  td.className = "status status-" + status;

  if (status === "processing") {
    td.textContent = "<?= $lang['processing'] ?? 'Processing…' ?>";
  } else if (status === "done") {
    td.textContent = "<?= $lang['action_done'] ?? '✅ Done' ?>";
  } else if (status === "failed") {
    td.textContent =
      "<?= $lang['failed'] ?? '❌ Failed' ?>" +
      (message ? " " + message : "");
  } else if (status === "already") {
    td.textContent =
      "<?= $lang['already_migrated'] ?? '✅ Already migrated' ?>";
  } else if (status === "cloudflare_only") {
    td.textContent =
      "<?= $lang['cloudflare_only'] ?? '☁️ Cloudflare only' ?>";
  } else if (status === "pending") {
    td.textContent = "<?= $lang['pending'] ?? 'Pending' ?>";
  } else {
    td.textContent = "❌ <?= $lang['unexpected'] ?? 'Unexpected' ?>";
  }
}


function updateRowStatusByIndex(index, status, message = "") {
  const row = tbody.querySelector(`tr[data-index="${index}"]`);
  if (!row) return;

  const td = row.querySelector(".status");
  td.className = "status status-" + status;

  if (status === "processing") {
    td.textContent = "<?= $lang['processing'] ?? 'Processing…' ?>";
  } else if (status === "done") {
    td.textContent = "<?= $lang['action_done'] ?? '✅ Done' ?>";
  } else if (status === "failed") {
    td.textContent =
      "<?= $lang['failed'] ?? '❌ Failed' ?>" +
      (message ? " " + message : "");
  } else if (status === "already") {
    td.textContent =
      "<?= $lang['already_migrated'] ?? '✅ Already migrated' ?>";
  } else if (status === "cloudflare_only") {
    td.textContent =
      "<?= $lang['cloudflare_only'] ?? '☁️ Cloudflare only' ?>";
  } else if (status === "pending") {
    td.textContent = "<?= $lang['pending'] ?? 'Pending' ?>";
  } else {
    td.textContent = "❌ <?= $lang['unexpected'] ?? 'Unexpected' ?>";
  }
}



</script>
</body>
</html>
