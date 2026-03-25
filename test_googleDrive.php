<?php
require_once __DIR__ . '/api/config_google.php';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Google Drive Import Test</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<style>
  body { font-family: system-ui, sans-serif; padding: 20px; }
  button { padding: 8px 14px; font-size: 15px; cursor: pointer; }
  .list { margin-top: 20px; }
  .item { display: flex; gap: 8px; margin-bottom: 6px; }
  .folder { font-weight: 600; }
</style>

<script>
  window.GOOGLE_CLIENT_ID = <?= json_encode(GOOGLE_CLIENT_ID) ?>;
</script>

<!-- SAME LIBS AS YOUR FIRST EXAMPLE -->
<script src="https://accounts.google.com/gsi/client"></script>
<script src="https://apis.google.com/js/api.js"></script>
</head>

<body>

<h2>Google Drive – Import Test</h2>

<button onclick="openDrivePicker()">Choose from Google Drive</button>

<div class="list" id="selection"></div>

<script>
let selectedDocs = [];
let tokenClient = null;

/* ================= AUTH (unchanged) ================= */

function getToken() {
  return new Promise((resolve, reject) => {
    if (!tokenClient) {
      tokenClient = google.accounts.oauth2.initTokenClient({
        client_id: window.GOOGLE_CLIENT_ID,
        scope: "https://www.googleapis.com/auth/drive.readonly",
        callback: r => r?.access_token ? resolve(r.access_token) : reject()
      });
    }
    tokenClient.requestAccessToken();
  });
}

/* ================= PICKER ================= */

async function openDrivePicker() {
  // load picker ONLY — no client init
  await new Promise(r => gapi.load("picker", r));

  const accessToken = await getToken();

  // 🔑 IMPORTANT: DocsView, not View
  const view = new google.picker.DocsView()
    .setIncludeFolders(true)
    .setSelectFolderEnabled(true);

  const picker = new google.picker.PickerBuilder()
    .addView(view)
    .enableFeature(google.picker.Feature.MULTISELECT_ENABLED)
    .setOAuthToken(accessToken)
    .setOrigin(window.location.origin)
    .setCallback(onPicked)
    .build();

  picker.setVisible(true);
}

/* ================= CALLBACK ================= */

function onPicked(data) {
  if (data.action !== google.picker.Action.PICKED) return;

  selectedDocs = data.docs || [];
  renderSelection();
}

/* ================= RENDER ================= */

function renderSelection() {
  const box = document.getElementById("selection");
  box.innerHTML = "<h3>Selected</h3>";

  selectedDocs.forEach(d => {
    const isFolder = d.mimeType === "application/vnd.google-apps.folder";
    box.innerHTML += `
      <div class="item">
        <span class="${isFolder ? 'folder' : ''}">
          ${isFolder ? "📂" : "📄"} ${d.name}
        </span>
      </div>`;
  });

  console.log("Picker result:", selectedDocs);
}
</script>

</body>
</html>
