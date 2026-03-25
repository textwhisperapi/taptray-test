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
</head>

<body>

<h3>Google Drive Import (test)</h3>

<button onclick="pickRoot()">Select Drive account</button>
<button onclick="commitImport()">Use selection</button>

<div id="tree" class="tree"></div>

<script>
let accessToken = null;
let selectedFiles = new Set();

/* ================= AUTH ================= */

let tokenClient = null;

function getToken() {
  return new Promise((resolve, reject) => {
    if (!tokenClient) {
      tokenClient = google.accounts.oauth2.initTokenClient({
        client_id: GOOGLE_CLIENT_ID,
        scope: "https://www.googleapis.com/auth/drive.readonly",
        callback: (resp) => {
          if (resp?.access_token) resolve(resp.access_token);
          else reject("No access token");
        }
      });
    }
    tokenClient.requestAccessToken({ prompt: "select_account" });
  });
}

/* ================= INITIAL LOAD ================= */

// async function pickRoot() {
//   accessToken = await getToken();
//   console.log("ACCESS TOKEN OK");

//   const res = await fetch(`/test_drive_list2.php?folder=root`, {
//     headers: { Authorization: `Bearer ${accessToken}` }
//   });

//   if (!res.ok) {
//     console.error("Backend error:", res.status);
//     return;
//   }

//   const data = await res.json();
//   renderTree(data);
// }

async function pickRoot() {
  accessToken = await getToken();
  console.log("ACCESS TOKEN OK");

  document.getElementById("tree").innerHTML = "";

  await loadRoot("root", "My Drive");
  await loadRoot("shared", "Shared with me");
}


async function loadRoot(rootId, title) {
  const res = await fetch(`/test_drive_list2.php?folder=${rootId}`, {
    headers: { Authorization: `Bearer ${accessToken}` }
  });

  if (!res.ok) {
    console.error(`Backend error (${rootId}):`, res.status);
    return;
  }

  const data = await res.json();

  const container = document.getElementById("tree");

  const rootLi = document.createElement("li");
  rootLi.classList.add("collapsed");

  const label = document.createElement("span");
  label.className = "folder-label";
  label.textContent = " 📁 " + title;

  label.onclick = () => {
    rootLi.classList.toggle("collapsed");
  };

  rootLi.appendChild(document.createTextNode(""));
  rootLi.appendChild(label);

  const ul = document.createElement("ul");
  (data.children || []).forEach(child => ul.appendChild(renderNode(child)));

  rootLi.appendChild(ul);
  container.appendChild(rootLi);
}


/* ================= TREE RENDER ================= */

function renderTree(root) {
  selectedFiles.clear();

  const el = document.getElementById("tree");
  el.innerHTML = "";

  const ul = document.createElement("ul");

  // 🔑 IMPORTANT: render ONLY root children
  (root.children || []).forEach(child => {
    ul.appendChild(renderNode(child));
  });

  el.appendChild(ul);
}

function renderNode(node) {
  const li = document.createElement("li");

  const checkbox = document.createElement("input");
  checkbox.type = "checkbox";

  if (node.mimeType === "application/vnd.google-apps.folder") {
    li.classList.add("collapsed");

    const label = document.createElement("span");
    label.className = "folder-label";
    label.textContent = " 📂 " + (node.name || "(folder)");

    // expand / collapse (children will be loaded later)
    label.onclick = async (e) => {
    e.stopPropagation();

    if (!node._loaded) {
        label.textContent = " 📂 " + (node.name || "(loading…)");

        const res = await fetch(`/test_drive_list2.php?folder=${node.id}`, {
        headers: { Authorization: `Bearer ${accessToken}` }
        });

        if (!res.ok) {
        label.textContent = " 📂 " + (node.name || "(error)");
        return;
        }

        const data = await res.json();
        node.children = data.children || [];
        node._loaded = true;

        const ul = document.createElement("ul");
        node.children.forEach(child => ul.appendChild(renderNode(child)));
        li.appendChild(ul);

        label.textContent = " 📂 " + (node.name || "(folder)");
    }

    // ✅ ALWAYS toggle
    li.classList.toggle("collapsed");
    };



    // recursive select (only affects already-loaded children)
    checkbox.onchange = () => {
      setFolderChecked(node, checkbox.checked);
    };

    li.appendChild(checkbox);
    li.appendChild(label);

    // no children rendered yet (lazy load later)

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
  node._checkbox.checked = checked;

  if (!node.children) return;

  node.children.forEach(c => {
    if (c.mimeType === "application/vnd.google-apps.folder") {
      setFolderChecked(c, checked);
    } else {
      c._checkbox.checked = checked;
      checked ? selectedFiles.add(c) : selectedFiles.delete(c);
    }
  });
}

/* ================= HANDOFF ================= */

function commitImport() {
  if (!selectedFiles.size) {
    console.warn("No files selected.");
    return;
  }

  console.group("Drive import – selection");
  console.log("Total selected files:", selectedFiles.size);

  [...selectedFiles].forEach(f => {
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
