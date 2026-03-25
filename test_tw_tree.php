<?php
require_once __DIR__ . "/includes/functions.php";
sec_session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Test TW Tree (Phase 1)</title>

<style>
  body {
    font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
    padding: 20px;
  }
  ul {
    list-style: none;
    padding-left: 18px;
    margin: 0;
  }
  li.collapsed > ul {
    display: none;
  }
  .folder-label,
  .file-label {
    cursor: pointer;
    white-space: nowrap;
  }
</style>
</head>
<body>

<h2>TextWhisper Tree — Phase 1</h2>
<p class="text-muted">Lists → items only. No audio yet.</p>

<div id="driveTree"></div>

<script>
/* ============================================================
   REQUIREMENTS
   ============================================================ */
/*
  - window.CACHED_OWNER_LISTS must already exist
  - window.currentOwnerToken must be set
  - driveRenderNode() must be loaded (reuse main JS)
*/

/* ============================================================
   SAFETY CHECKS
   ============================================================ */

if (!window.CACHED_OWNER_LISTS || !window.currentOwnerToken) {
  document.getElementById("driveTree").innerHTML =
    "<p style='color:red'>Missing CACHED_OWNER_LISTS or currentOwnerToken</p>";
  throw new Error("TW data missing");
}

/* ============================================================
   TW → TREE (PHASE 1 ONLY)
   ============================================================ */

function toTWFolder(list) {
  const listName = list.name || list.title || "Untitled list";

  // Items → files (PDF only for now)
  const itemNodes = (list.items || []).map(it => ({
    name: it.title,
    mimeType: "application/pdf",
    surrogate: it.surrogate,
    _twSurrogate: it.surrogate
  }));

  // Child lists → folders
  const childFolders = (list.children || []).map(toTWFolder);

  return {
    name: listName,
    mimeType: "application/vnd.google-apps.folder",
    children: [...childFolders, ...itemNodes]
  };
}

/* ============================================================
   RENDER
   ============================================================ */

const ownerToken = window.currentOwnerToken;
const data = window.CACHED_OWNER_LISTS[ownerToken];

const roots = [
  ...(Array.isArray(data?.owned) ? data.owned : []),
  ...(Array.isArray(data?.accessible) ? data.accessible : [])
];

const ul = document.createElement("ul");
roots.forEach(list =>
  ul.appendChild(driveRenderNode(toTWFolder(list)))
);

document.getElementById("driveTree").appendChild(ul);
</script>

</body>
</html>
