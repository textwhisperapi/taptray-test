<?php
require_once __DIR__ . '/api/config_google.php';
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Drive Import – Test UI</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<style>
body { font-family: system-ui; padding:16px; }
button { padding:6px 10px; margin-right:6px; }

.tree ul {
  list-style:none;
  padding-left:18px;
  margin:0;
}

.folder-label {
  font-weight:600;
  cursor:pointer;
}

.file-label {
  cursor:pointer;
  color:#444;
}

.collapsed > ul {
  display:none;
}
</style>

<script>
window.GOOGLE_CLIENT_ID = <?= json_encode(GOOGLE_CLIENT_ID) ?>;
</script>
<script src="https://accounts.google.com/gsi/client"></script>
<script src="https://apis.google.com/js/api.js"></script>
</head>

<body>

<h3>Google Drive Import (test)</h3>

<button onclick="pickRoot()">Select root folder</button>
<button onclick="commitImport()">Use selection</button>

<div id="tree" class="tree"></div>

<script>
let accessToken;
let selectedFiles = new Set();

/* ================= AUTH ================= */

function getToken() {
  return new Promise(resolve => {
    google.accounts.oauth2.initTokenClient({
      client_id: GOOGLE_CLIENT_ID,
      scope: "https://www.googleapis.com/auth/drive.readonly",
      callback: r => resolve(r.access_token)
    }).requestAccessToken();
  });
}

/* ================= PICK ROOT ================= */

async function pickRoot() {
  await new Promise(r => gapi.load("picker", r));
  accessToken = await getToken();

  const picker = new google.picker.PickerBuilder()
    .addView(
      new google.picker.DocsView()
        .setIncludeFolders(true)
        .setSelectFolderEnabled(true)
    )
    .setOAuthToken(accessToken)
    .setOrigin(location.origin)
    .setCallback(onPicked)
    .build();

  picker.setVisible(true);
}

async function onPicked(data) {
  if (data.action !== google.picker.Action.PICKED) return;

  const folder = data.docs[0];

  const res = await fetch(`/test_drive_list.php?folder=${folder.id}`, {
    headers: { Authorization: `Bearer ${accessToken}` }
  });

  const tree = await res.json();
  renderTree(tree);
}

/* ================= TREE ================= */

function renderTree(root) {
  selectedFiles.clear();
  const el = document.getElementById("tree");
  el.innerHTML = "";

  const ul = document.createElement("ul");
  ul.appendChild(renderNode(root));
  el.appendChild(ul);
}

function renderNode(node) {
  const li = document.createElement("li");

  const checkbox = document.createElement("input");
  checkbox.type = "checkbox";

  if (node.mimeType.includes("folder")) {
    const label = document.createElement("span");
    label.className = "folder-label";
    label.textContent = " 📂 " + (node.name || "(root)");

    // expand / collapse
    label.onclick = (e) => {
      e.stopPropagation();
      li.classList.toggle("collapsed");
    };

    // recursive select
    checkbox.onchange = () => {
      setFolderChecked(node, checkbox.checked);
    };

    li.appendChild(checkbox);
    li.appendChild(label);

    if (node.children?.length) {
      const ul = document.createElement("ul");
      node.children.forEach(child => ul.appendChild(renderNode(child)));
      li.appendChild(ul);
    }

  } else {
    const label = document.createElement("span");
    label.className = "file-label";
    label.textContent = " 📄 " + node.name;

    checkbox.onchange = () => {
      if (checkbox.checked) selectedFiles.add(node);
      else selectedFiles.delete(node);
    };

    li.appendChild(checkbox);
    li.appendChild(label);
  }

  node._checkbox = checkbox;
  return li;
}

/* ================= SELECTION ================= */

function setFolderChecked(node, checked) {
  if (node.mimeType.includes("folder")) {
    node._checkbox.checked = checked;
    node.children?.forEach(c => setFolderChecked(c, checked));
  } else {
    node._checkbox.checked = checked;
    if (checked) selectedFiles.add(node);
    else selectedFiles.delete(node);
  }
}

/* ================= HANDOFF ================= */

function commitImport() {
  if (!selectedFiles.size) {
    console.warn("No files selected.");
    return;
  }

  const files = [...selectedFiles];

  console.group("Drive import – selection");
  console.log("Total selected files:", files.length);

  files.forEach(f => {
    console.log({
      id: f.id,
      name: f.name,
      mimeType: f.mimeType
    });
  });

  console.groupEnd();
}
</script>

</body>
</html>
