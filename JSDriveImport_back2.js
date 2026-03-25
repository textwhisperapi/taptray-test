/* JSDriveImport.js - Google Drive overlay tree (ported from test_drive_ui2.php) */


// let driveAccessToken = null;
window.driveAccessToken = null;

let driveTokenClient = null;

window.driveSelectedFiles = new Set();
window.driveSelectedFolders = new Set();

window.dropboxConnected = false;

window.onedriveConnected = false;

window.addEventListener("message", (e) => {
  if (e.origin !== location.origin) return;

  if (e.data?.type === "dropbox-auth-ok") {
    window.dropboxConnected = true;
    if (window.driveProvider === "dropbox") dropboxConnect(false);
  }

  if (e.data?.type === "onedrive-auth-ok") {
    window.onedriveConnected = true;
    if (window.driveProvider === "onedrive") onedriveConnect(false);
  }
});




/* ================= AUTH ================= */

// function googleGetToken(force = false) {
//   return new Promise((resolve, reject) => {
//     if (!window.google?.accounts?.oauth2) {
//       reject("Google OAuth not loaded");
//       return;
//     }

//     if (!driveTokenClient) {
//       driveTokenClient = google.accounts.oauth2.initTokenClient({
//         client_id: window.GOOGLE_CLIENT_ID,
//         scope: "https://www.googleapis.com/auth/drive.readonly openid email",
//         callback: async (resp) => {
//           if (!resp?.access_token) {
//             reject("No access token");
//             return;
//           }

//           window.driveAccessToken = resp.access_token;
//           localStorage.setItem("driveConnected", "1");

//           try {
//             const email = await fetchGoogleUserEmail(resp.access_token);
//             if (email) localStorage.setItem("driveLoginHint", email);
//           } catch {}

//           resolve(resp.access_token);
//         }
//       });
//     }

//     driveTokenClient.requestAccessToken({
//       prompt: force ? "select_account" : "",
//       login_hint: force ? undefined : localStorage.getItem("driveLoginHint") || undefined
//     });
//   });
// }

function googleGetToken(force = false) {
  return new Promise((resolve, reject) => {
    if (!window.google?.accounts?.oauth2) {
      reject("Google OAuth not loaded");
      return;
    }

    if (!driveTokenClient) {
      driveTokenClient = google.accounts.oauth2.initTokenClient({
        client_id: window.GOOGLE_CLIENT_ID,
        scope: "https://www.googleapis.com/auth/drive.readonly openid email",
        callback: async (resp) => {
          if (!resp?.access_token) {
            reject("No access token");
            return;
          }

          window.driveAccessToken = resp.access_token;
          resolve(resp.access_token); // ✅ REQUIRED

          // ✅ Event-driven rebuild (correct place)
          if (window.driveProvider === "google") {
            setTimeout(() => {
              googleConnect(false);
            }, 0);
          }
        }
      });
    }

    driveTokenClient.requestAccessToken({
      prompt: force ? "select_account" : "",
      login_hint: force
        ? undefined
        : localStorage.getItem("driveLoginHint") || undefined
    });
  });
}




/* ================= OPEN / CLOSE (GLOBAL) ================= */

// window.openDriveImportOverlay = async function () {
window.openDriveImportOverlay = async function (provider = "google") {
  window.driveProvider = provider;

  delete window._importSimilarityIndex;
  window.driveSelectedFiles.clear();

    //Build similarity index on demand 
  buildImportSimilarityIndex();

  // ✅ Always open PDF tab correctly
  if (typeof window.switchTab === "function") {
    window.switchTab("importTab");
  }

  
  const host = document.getElementById("importTabContent");
  if (!host) return;

  host.innerHTML = ` 
    <div class="drive-import-panel">
      <div class="import-panel-header">
        <strong class="import-title">Import from:</strong>

        <select id="driveProviderSelect">
          <option value="google">Google Drive</option>
          <option value="dropbox">Dropbox</option>
          <option value="onedrive">OneDrive</option>
          <option value="icloud">iCloud</option>
          <option value="tw">TextWhisper</option>
        </select>

        <div id="driveImportTarget" class="import-target">
          ${getDriveImportTargetLabel()}
        </div>
      </div>

      <div class="import-panel-actions">
        <button class="btn" onclick="driveConnect(true)">Connect</button>
        <button class="btn" onclick="driveCommitImport()">Import</button>
        <button class="btn" onclick="closeDriveImportOverlay()">Close</button>
      </div>
      <div id="driveTree" class="import-panel-body tree"></div>
    </div>
  `;

    // wait for selectItem / PDF render to finish
    // await waitForPdfIdle();

    // ✅ Auto-connect silently (reuse remembered account if possible)
    await driveConnect(false);

    const sel = document.getElementById("driveProviderSelect");
    sel.value = window.driveProvider;
    sel.onchange = async () => {
      window.driveProvider = sel.value;
      await driveConnect(false);
    };


};


window.closeDriveImportOverlay = function () {
  const host = document.getElementById("importTabContent");
  if (!host) return;

  host.innerHTML = "";
  window.driveSelectedFiles.clear();
};

function waitForPdfIdle() {
  return new Promise(resolve => {
    requestAnimationFrame(() => {
      requestAnimationFrame(resolve);
    });
  });
}



async function fetchGoogleUserEmail(token) {
  const res = await fetch(
    "https://www.googleapis.com/oauth2/v3/userinfo",
    { headers: { Authorization: `Bearer ${token}` } }
  );
  if (!res.ok) return null;
  const data = await res.json();
  return data.email || null;
}




/* ================= CONNECT (GLOBAL) ================= */

window.driveConnect = async function (force = false) {
  switch (driveProvider) {
    case "google":
      return googleConnect(force);
    case "dropbox":
      return dropboxConnect(force);
    case "onedrive":
      return onedriveConnect(force);
    case "tw":
      return twConnect();
    case "icloud":
      return icloudConnect();      
    default:
      console.warn("Unknown drive provider:", driveProvider);
  }
};




async function googleConnect(force = false) {
  if (!window.driveAccessToken || force) {
    await googleGetToken(force);
    return; // ⛔ STOP HERE
  }

  const tree = document.getElementById("driveTree");
  if (!tree) return;

  tree.innerHTML = "";
  window.driveSelectedFiles.clear();
  window.driveSelectedFolders.clear();

  await driveLoadRoot("root", "My Drive");
  await driveLoadRoot("shared", "Shared with me");
}


// async function twConnect() {
//   const tree = document.getElementById("driveTree");
//   if (!tree) return;

//   tree.innerHTML = "";
//   window.driveSelectedFiles.clear();
//   window.driveSelectedFolders.clear();

//   const ownerToken = window.currentOwnerToken;
//   const data = window.CACHED_OWNER_LISTS?.[ownerToken];
//   if (!data) {
//     tree.innerHTML = "<li>No TextWhisper content available</li>";
//     return;
//   }

//   // Convert ONE list node (with nested children) into a "folder" node for driveRenderNode
//   function toTWFolder(list) {
//     const listName = list.name || list.title || "Untitled list";

//     // Items become "files"
//     const itemNodes = (list.items || []).map(it => ({
//       name: it.title,
//       mimeType: "application/pdf",     // treat as file in this panel
//       surrogate: it.surrogate,
//       _twSurrogate: it.surrogate
//     }));

//     // Child lists become nested "folders"
//     const childFolders = (list.children || []).map(toTWFolder);

//     return {
//       name: listName,
//       mimeType: "application/vnd.google-apps.folder",
//       // IMPORTANT: preloaded children so folders expand without backend fetch
//       children: [...childFolders, ...itemNodes]
//     };
//   }

//   // Root lists are already the correct tree (parent lists only at top level)
//   const roots = [
//     ...(Array.isArray(data.owned) ? data.owned : []),
//     ...(Array.isArray(data.accessible) ? data.accessible : [])
//   ];

//   const ul = document.createElement("ul");
//   roots.forEach(rootList => ul.appendChild(driveRenderNode(toTWFolder(rootList))));
//   tree.appendChild(ul);
// }


async function twConnect() {
  const tree = document.getElementById("driveTree");
  if (!tree) return;

  tree.innerHTML = "";
  window.driveSelectedFiles.clear();
  window.driveSelectedFolders.clear();

  const ownerToken = window.currentOwnerToken;
  const data = window.CACHED_OWNER_LISTS?.[ownerToken];
  if (!data) {
    tree.innerHTML = "<li>No TextWhisper content available</li>";
    return;
  }

  // =========================================
  // 🔊 Cloudflare audio prefetch (ONE FETCH)
  // =========================================
  window._twCloudAudioBySurrogate = Object.create(null);

  try {
    const res = await fetch(
      `https://r2-worker.textwhisper.workers.dev/list?prefix=${encodeURIComponent(ownerToken + "/")}`
    );

    if (res.ok) {
      const list = await res.json();
      if (Array.isArray(list)) {
        list.forEach(obj => {
          if (!obj.key) return;
          if (!/\.(mp3|wav|ogg|m4a|flac|aac|aif|aiff|webm|mid|midi)$/i.test(obj.key)) return;

          const m = obj.key.match(/surrogate-(\d+)/);
          if (!m) return;

          const s = String(m[1]);
          (window._twCloudAudioBySurrogate[s] ||= []).push(obj.key);
        });
      }
    }
  } catch (err) {
    console.warn("TW Cloudflare audio fetch failed:", err);
  }

  // =========================================
  // TW lists → folder tree
  // Items stay FILES, but can have children
  // =========================================
  function toTWFolder(list) {
    const listName = list.name || list.title || "Untitled list";

    const itemNodes = (list.items || []).map(it => {
      const node = {
        name: it.title,
        mimeType: "application/pdf",
        surrogate: it.surrogate,
        _twSurrogate: it.surrogate
      };

      const surr = String(it.surrogate || "");
      const audio = window._twCloudAudioBySurrogate?.[surr];

      // ✅ children directly under the FILE (no extra folder)
      if (Array.isArray(audio) && audio.length) {
        node.children = audio.map(k => ({
          name: k.split("/").pop(),
          mimeType: "audio",
          _twAudioKey: k,
          _twParentSurrogate: surr
        }));
      }

      return node;
    });

    const childFolders = (list.children || []).map(toTWFolder);

    return {
      name: listName,
      mimeType: "application/vnd.google-apps.folder",
      children: [...childFolders, ...itemNodes]
    };
  }

  const roots = [
    ...(Array.isArray(data.owned) ? data.owned : []),
    ...(Array.isArray(data.accessible) ? data.accessible : [])
  ];

  const ul = document.createElement("ul");
  roots.forEach(rootList => ul.appendChild(driveRenderNode(toTWFolder(rootList))));
  tree.appendChild(ul);
}


async function dropboxConnect(force = false) {
  if (!window.dropboxConnected || force) {
    const url = force
      ? "/api/auth/dropbox/dropbox-login.php?force=1"
      : "/api/auth/dropbox/dropbox-login.php";

    window.open(url, "dropboxOAuth", "width=600,height=700");
    return;
  }

  // load tree
  const tree = document.getElementById("driveTree");
  if (!tree) return;

  tree.innerHTML = "";
  window.driveSelectedFiles.clear();
  window.driveSelectedFolders.clear();

  await driveLoadRoot("", "Dropbox");
}



async function onedriveConnect(force = false) {
  if (!window.onedriveConnected || force) {
    const url = force
      ? "/api/auth/microsoft/onedrive-login.php?force=1"
      : "/api/auth/microsoft/onedrive-login.php";

    window.open(url, "onedriveOAuth", "width=600,height=700");
    return;
  }

  const tree = document.getElementById("driveTree");
  if (!tree) return;

  tree.innerHTML = "";
  window.driveSelectedFiles.clear();
  window.driveSelectedFolders.clear();

  await driveLoadRoot("root", "OneDrive");
}


async function icloudConnect(force = false) {
return;
}




/* ================= ROOT LOAD ================= */

function driveListEndpoint(folderId) {
  switch (driveProvider) {
    case "google":
      return {
        url: `/File_listGoogleDrive.php?folder=${encodeURIComponent(folderId)}`,
        headers: { Authorization: `Bearer ${window.driveAccessToken}` }
      };

    case "dropbox":
      return {
        url: `/File_listDropbox.php?path=${encodeURIComponent(folderId)}`,
        headers: {} // server-side auth
      };

    case "onedrive":
      return {
        url: `/File_listOneDrive.php?folder=${encodeURIComponent(folderId)}`,
        headers: {} // server-side auth
      };

    default:
      throw new Error("Unsupported drive provider: " + driveProvider);
  }
}




async function driveLoadRoot(rootId, title) {
console.log("driveLoadRoot → driveTree =", document.getElementById("driveTree"));
console.log("driveLoadRoot → visible =", document.getElementById("driveTree")?.offsetParent);


  // const res = await fetch(`/File_listGoogleDrive.php?folder=${encodeURIComponent(rootId)}`, {
  //   headers: { Authorization: `Bearer ${driveAccessToken}` }
  // });

  const { url, headers } = driveListEndpoint(rootId);

  const res = await fetch(url, { headers });

  if (!res.ok) {
    console.error(`Backend error (${rootId}):`, res.status);
    return;
  }

  const data = await res.json();
  const container = document.getElementById("driveTree");
  if (!container) return;

  const rootLi = document.createElement("li");
  rootLi.classList.add("collapsed");

  const label = document.createElement("span");
  label.className = "folder-label";
  label.textContent = " 📁 " + title;

  label.onclick = () => rootLi.classList.toggle("collapsed");

  rootLi.appendChild(label);

  const ul = document.createElement("ul");
  (data.children || []).forEach(child => ul.appendChild(driveRenderNode(child)));

  rootLi.appendChild(ul);
  container.appendChild(rootLi);
}


function toggleExpand(e) {
  e.stopPropagation();

  if (!node._loaded) {
    const ul = document.createElement("ul");
    node.children.forEach(child =>
      ul.appendChild(driveRenderNode(child))
    );
    li.appendChild(ul);
    node._loaded = true;
  }

  const collapsed = li.classList.toggle("collapsed");
  expander.textContent = collapsed ? "▸" : "▾";
}

/* ================= TREE ================= */


function driveRenderNode(node) {
  const li = document.createElement("li");

  const checkbox = document.createElement("input");
  checkbox.type = "checkbox";
  checkbox._driveNode = node;

  /* ================= FOLDER ================= */
  if (node.mimeType && node.mimeType.includes("folder")) {
    li.classList.add("collapsed");

    const label = document.createElement("span");
    label.className = "folder-label";
    label.textContent = " 📂 " + (node.name || "(folder)");

    label.onclick = async (e) => {
      e.stopPropagation();

      if (!node._loaded) {
        const ul = document.createElement("ul");

        if (Array.isArray(node.children)) {
          node.children.forEach(child =>
            ul.appendChild(driveRenderNode(child))
          );
          node._loaded = true;
          li.appendChild(ul);
        }
        else if (
          (driveProvider === "dropbox" && node.path) ||
          (driveProvider !== "dropbox" && node.id)
        ) {
          const folderId =
            driveProvider === "dropbox" ? node.path : node.id;

          const { url, headers } = driveListEndpoint(folderId);
          const res = await fetch(url, { headers });
          if (!res.ok) return;

          const data = await res.json();
          node.children = data.children || [];
          node._loaded = true;

          const ul2 = document.createElement("ul");
          node.children.forEach(child =>
            ul2.appendChild(driveRenderNode(child))
          );
          li.appendChild(ul2);
        }
      }

      li.classList.toggle("collapsed");
    };

    checkbox.onchange = () => {
      node._checked = checkbox.checked;

      checkbox.checked
        ? window.driveSelectedFolders.add(node)
        : window.driveSelectedFolders.delete(node);

      if (Array.isArray(node.children)) {
        node.children.forEach(child => {
          if (!child._checkbox) return;
          if (driveProvider === "tw" && child.mimeType === "audio") return;

          child._checkbox.checked = checkbox.checked;

          if (child.mimeType?.includes("folder")) {
            checkbox.checked
              ? window.driveSelectedFolders.add(child)
              : window.driveSelectedFolders.delete(child);
          } else {
            checkbox.checked
              ? window.driveSelectedFiles.add(child)
              : window.driveSelectedFiles.delete(child);
          }
        });
      }
    };

    li.appendChild(checkbox);
    li.appendChild(label);

    node._checkbox = checkbox;
    return li;
  }

  /* ================= TW AUDIO (pseudo-file) ================= */
  if (driveProvider === "tw" && node.mimeType === "audio") {
    li.classList.add("drive-file");

    const spacer = document.createElement("span");
    spacer.style.display = "inline-block";
    spacer.style.width = "18px";

    const label = document.createElement("span");
    label.className = "file-label";
    label.textContent = " 🎵 " + (node.name || "(audio)");

    li.appendChild(spacer);
    li.appendChild(label);
    return li;
  }

  /* ================= FILE ================= */
  li.classList.add("drive-file");
  li._driveNode = node;

  const label = document.createElement("span");
  label.className = "file-label";
  label.textContent = " 📄 " + (node.name || "(file)");

  const { level, score, surrogate } = estimateTWUsage(node.name);

  if (surrogate) {
    node._twSurrogate = surrogate;
    li.dataset.surrogate = surrogate;

    const s = document.createElement("span");
    s.className = "tw-surrogate-link";
    s.textContent = ` (${surrogate})`;
    s.style.cursor = "pointer";

    s.onclick = (e) => {
      e.stopPropagation();
      const row = document.querySelector(
        `.list-sub-item[data-value="${surrogate}"]`
      );
      if (!row) return showFlashMessage?.("Sidebar item not found");

      selectItem(
        surrogate,
        row.dataset.token,
        document.getElementById(`list-${row.dataset.token}`)
      );
    };

    label.appendChild(s);
  }

  const dot = document.createElement("span");
  dot.className = "tw-usage-dot " + level;
  dot.title =
    level === "high"
      ? `Likely already used (${score}%)`
      : level === "medium"
      ? `Possibly used (${score}%)`
      : `No match (${score}%)`;

  checkbox.onchange = () => {
    checkbox.checked
      ? window.driveSelectedFiles.add(node)
      : window.driveSelectedFiles.delete(node);
  };

  li.appendChild(checkbox);
  li.appendChild(dot);
  li.appendChild(label);

  /* ================= TW EXPANDABLE AUDIO ================= */
  if (
    driveProvider === "tw" &&
    Array.isArray(node.children) &&
    node.children.length
  ) {
    attachTWAudioExpander({ li, label, node });
  }

  node._checkbox = checkbox;
  return li;
}

//helper for TW
function attachTWAudioExpander({ li, label, node }) {
  if (
    driveProvider !== "tw" ||
    !Array.isArray(node.children) ||
    !node.children.length
  ) return;

  li.classList.add("collapsed");

  const expander = document.createElement("span");
  expander.className = "tw-expander";
  expander.textContent = "▸";
  expander.style.cursor = "pointer";
  expander.style.marginRight = "-0.6em";

  li.insertBefore(expander, label);

  const badge = document.createElement("span");
  badge.className = "audio-count";
  badge.textContent = ` 🎵 ${node.children.length}`;
  label.appendChild(badge);

  const ul = document.createElement("ul");
  ul.style.display = "none";

  node.children.forEach(child =>
    ul.appendChild(driveRenderNode(child))
  );
  li.appendChild(ul);

  const toggle = (e) => {
    e.stopPropagation();
    const open = ul.style.display === "none";
    ul.style.display = open ? "block" : "none";
    expander.textContent = open ? "▾" : "▸";
    li.classList.toggle("collapsed", !open);
  };

  expander.onclick = toggle;
  label.onclick = toggle;
}



function driveSetFolderChecked(node, checked) {
  node._checkbox.checked = checked;

  if (!node.children) return;

  node.children.forEach(c => {
    // if (c.mimeType === "application/vnd.google-apps.folder") {
    if (c.mimeType?.includes("folder")) {
      driveSetFolderChecked(c, checked);
    } else {
      c._checkbox.checked = checked;
      checked ? driveSelectedFiles.add(c) : driveSelectedFiles.delete(c);
    }
  });
}

/* ================= HANDOFF (GLOBAL) ================= */





window.driveCommitImport = async function () {
  const files   = getCheckedDriveFiles();
  const folders = window.driveSelectedFolders;

  if (!files.length) {
    alert("No files selected.");
    return;
  }

  // ✅ Provider-specific auth check
  if (driveProvider === "google" && !window.driveAccessToken) {
    alert("Google Drive not connected.");
    return;
  }

  /* ================= TARGET LIST ================= */

  let suggestedName = "Imported items";
  let hint = null;

  if (folders && folders.size === 1) {
    const folder = [...folders][0];
    if (folder?.name) {
      suggestedName = folder.name;
      hint = `Suggested from folder: “${folder.name}”`;
    }
  } else {
    const current = getCurrentList();
    if (current?.name || current?.title) {
      suggestedName = current.name || current.title;
    }
  }

  let listExists = false;
  try {
    listExists = !!(await findListByName(suggestedName));
  } catch {}

  const listName = await showDriveImportFolderModal({
    folderName: suggestedName,
    fileCount: files.length,
    listExists,
    hint
  });

  if (!listName) return;

  const existing = await findListByName(listName);
  const targetList = existing?.token
    ? existing
    : await createContentList(listName);

  if (!targetList?.token) {
    alert("Could not resolve target list.");
    return;
  }

  /* ================= IMPORT ================= */

  console.group("Drive import");

  for (const node of files) {
    try {
      // ✅ CASE A: already exists → attach
      if (node._twSurrogate) {
        addItemToList(targetList.token, node._twSurrogate);
        continue;
      }

      // ✅ CASE B: new file → provider-specific download
      let blob;

      if (driveProvider === "google") {
        const res = await fetch(
          `https://www.googleapis.com/drive/v3/files/${node.id}?alt=media`,
          { headers: { Authorization: `Bearer ${window.driveAccessToken}` } }
        );
        if (!res.ok) continue;
        blob = await res.blob();
      }

      else if (driveProvider === "dropbox") {
        const res = await fetch(
          `/File_downloadDropbox.php?path=${encodeURIComponent(node.path)}`
        );
        if (!res.ok) continue;
        blob = await res.blob();
      }

      else {
        console.warn("Unsupported provider for import:", driveProvider);
        continue;
      }

      const file = new File([blob], node.name, { type: "application/pdf" });
      await importPdfFile(file, targetList.token);

    } catch (err) {
      console.error("Import failed:", node.name, err);
    }
  }

  console.groupEnd();
  showFlashMessage?.("✅ Import completed");
};




async function importPdfFile(file, listToken = null) {
  const token = listToken || getCurrentList()?.token;
  if (!token) {
    throw new Error("No list selected.");
  }

  const title = (file.name || "")
    .replace(/\.pdf$/i, "")
    .trim();

  const surrogate = await createNewItemForPDF(token, title);
  if (!surrogate) {
    throw new Error("Item creation failed.");
  }

  await handleFileUpload(file, surrogate, "pdf");
  return surrogate;
}



function getDriveImportTargetLabel() {
  const folders = window.driveSelectedFolders;
  const list = getCurrentList();

  if (folders && folders.size) {
    return "📁 A new list will be created from selected folder(s)";
  }

  if (isRealList(list)) {
    return `📄 Import into list: ${list.name || list.title}`;
  }

  return "⚠️ No target list selected";
}


function isRealList(list) {
  return list?.token && list.token !== window.SESSION_USERNAME;
}


function showDriveImportFolderModal({ folderName, fileCount, listExists }) {
  return new Promise(resolve => {
    const overlay = document.createElement("div");
    overlay.style.cssText = `
      position:fixed;
      inset:0;
      background:rgba(0,0,0,0.45);
      display:flex;
      align-items:center;
      justify-content:center;
      z-index:100000;
    `;

    const modal = document.createElement("div");
    modal.style.cssText = `
      background:#fff;
      padding:20px 22px;
      border-radius:8px;
      max-width:460px;
      width:90%;
      box-shadow:0 10px 30px rgba(0,0,0,.3);
    `;

    modal.innerHTML = `
      <h3 style="margin:0 0 10px 0;">Import folder from Google Drive</h3>

      <p style="margin:0 0 10px 0;">
        You are about to import <strong>${fileCount} files</strong> from the folder<br>
        <strong>“${folderName}”</strong>.
      </p>

      <p style="margin:10px 0 6px 0;">
        The content will be added to a list with this name:
      </p>

      <input id="driveListNameInput"
        type="text"
        value="${folderName}"
        style="
          width:100%;
          padding:6px 8px;
          font-size:14px;
          box-sizing:border-box;
        "
      />

      <div style="font-size:12px; color:#666; margin-top:6px;">
        ℹ️ ${listExists
          ? "A list with this name already exists"
          : "A new list will be created"}
      </div>

      <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:16px;">
        <button id="driveImportCancel">Cancel</button>
        <button id="driveImportConfirm">Continue</button>
      </div>
    `;

    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    const input = modal.querySelector("#driveListNameInput");
    input.focus();
    input.select();

    modal.querySelector("#driveImportCancel").onclick = () => {
      overlay.remove();
      resolve(null);
    };

    modal.querySelector("#driveImportConfirm").onclick = () => {
      const name = input.value.trim();
      overlay.remove();
      resolve(name || null);
    };

    overlay.onclick = e => {
      if (e.target === overlay) {
        overlay.remove();
        resolve(null);
      }
    };
  });
}



async function countFilesInDriveFolder(folderId) {
  const { url, headers } = driveListEndpoint(folderId);

  const res = await fetch(url, { headers });
  if (!res.ok) return 0;

  const data = await res.json();
  let count = 0;

  for (const node of data.children || []) {
    if (node.mimeType?.includes("folder")) {
      const nextId =
        driveProvider === "dropbox" ? node.path : node.id;
      count += await countFilesInDriveFolder(nextId);
    } else {
      count++;
    }
  }

  return count;
}


async function findListByName(name) {
  const res = await fetch("/getUserLists.php");
  if (!res.ok) return null;

  const lists = await res.json();
  return lists.find(
    l => l.name?.trim().toLowerCase() === name.trim().toLowerCase()
  ) || null;
}


// function getCheckedDriveFiles() {
//   return Array.from(
//     document.querySelectorAll("#driveTree input[type=checkbox]:checked")
//   )
//     .map(cb => cb._driveNode)
//     .filter(node => node && node.mimeType !== "application/vnd.google-apps.folder");
// }

function getCheckedDriveFiles() {
  return Array.from(document.querySelectorAll("#driveTree input[type=checkbox]:checked"))
    .map(cb => cb._driveNode)
    .filter(node => node && !node.mimeType?.includes("folder"));
}


function driveReconnect() {
  localStorage.removeItem("driveConnected");
  window.driveAccessToken = null;
  driveConnect();
}




function estimateTWUsage(fileName) {
  const index = window._importSimilarityIndex;
  if (!Array.isArray(index) || !index.length) {
    return { level: "none", score: 0, surrogate: null };
  }

  const fn = normalizeName(fileName);
  let bestScore = 0; // ALWAYS 0..1
  let bestItem = null;

  for (const item of index) {
    const subj = item.subject;

    // 1️⃣ exact match
    if (subj === fn) {
      return { level: "high", score: 100, surrogate: item.surrogate };
    }

    // 2️⃣ containment (either direction)
    if (
      subj.length >= 4 &&
      fn.length >= 4 &&
      (subj.includes(fn) || fn.includes(subj))
    ) {
      const words = subj.split(" ").length;

      // single-word → yellow
      if (words === 1) {
        bestScore = Math.max(bestScore, 0.7);
        bestItem = item;
        continue;
      }

      // multi-word → green
      return { level: "high", score: 95, surrogate: item.surrogate };
    }

    // 3️⃣ fuzzy similarity
    const s = ngramSimilarity(fn, subj, 3); // 0..1
    if (s > bestScore) {
      bestScore = s;
      bestItem = item;
    }
  }

  const pct = Math.round(bestScore * 100);

  if (pct >= 85) return { level: "high", score: pct, surrogate: bestItem?.surrogate };
  if (pct >= 65) return { level: "medium", score: pct, surrogate: bestItem?.surrogate };

  return { level: "none", score: pct, surrogate: null };
}






function fingerprint(str, n = 3) {
  const s = normalizeName(str);
  const grams = new Set();

  for (let i = 0; i <= s.length - n; i++) {
    grams.add(s.slice(i, i + n));
  }
  return grams;
}

function fingerprintSimilarity(a, b) {
  const A = fingerprint(a);
  const B = fingerprint(b);

  if (!A.size || !B.size) return 0;

  let intersection = 0;
  for (const g of A) {
    if (B.has(g)) intersection++;
  }

  const union = A.size + B.size - intersection;
  return Math.round((intersection / union) * 100);
}



function ngramSimilarity(a, b, n = 3) {
  const A = ngrams(a, n);
  const B = ngrams(b, n);
  return jaccard(A, B); // 0..1
}

function ngrams(str, n = 3) {
  const s = ` ${str} `;
  const grams = new Set();
  for (let i = 0; i <= s.length - n; i++) {
    grams.add(s.slice(i, i + n));
  }
  return grams;
}

function jaccard(a, b) {
  let intersection = 0;
  for (const x of a) if (b.has(x)) intersection++;
  return intersection / (a.size + b.size - intersection || 1);
}

function buildImportSimilarityIndex() {
  if (window._importSimilarityIndex) return;

  const ownerToken = window.currentOwnerToken;
  const data = window.CACHED_OWNER_LISTS?.[ownerToken];
  if (!data) return;

  const out = [];

  function walk(lists) {
    for (const list of lists || []) {
      for (const item of list.items || []) {
        out.push({
          surrogate: item.surrogate,
          subject: normalizeName(item.title),
          title: item.title
        });
      }
      if (list.children) walk(list.children);
    }
  }

  walk(data.owned);
  walk(data.accessible);

  window._importSimilarityIndex = out;
}


function normalizeName(str) {
  return (str || "")
    .toLowerCase()
    .normalize("NFKD")
    .replace(/[\u0300-\u036f]/g, "")                 // remove accents
    .replace(/\.(pdf|docx?|txt)$/i, "")              // remove ONLY these extensions
    .replace(/[\p{Emoji_Presentation}\p{Emoji}\p{So}]/gu, "") // remove emoji anywhere
    .replace(/^[\s#]*/, "")                          // strip leading # / spaces
    .replace(/^\d+\s*[.\-–]?\s*/, "")                // strip leading numbers
    .replace(/[^\p{L}\p{N}\s]/gu, " ")               // drop punctuation
    .replace(/\s+/g, " ")
    .trim();
}


function canonical(str) {
  return normalizeName(str)
    .replace(/\s+/g, " ")
    .trim();
}


// function enableFileDrag(container, { source }) {
//   if (!container || container._sortable) return;

//   container._sortable = new Sortable(container, {
//     animation: 150,

//     draggable: "li.drive-file",
//     handle: ".file-label",

//     group: {
//       name: "items",
//       pull: "clone",
//       put: false
//     },

//     sort: false,
//     fallbackOnBody: true,
//     forceFallback: true,   // IMPORTANT
//     fallbackTolerance: 3,  // IMPORTANT

//     onClone(evt) {
//       const src = evt.item;
//       const clone = evt.clone;

//       clone.classList.add("list-sub-item");
//       clone.dataset.value  = src.dataset.surrogate || "";
//       clone.dataset.source = source;
//     }
//   });
// }






