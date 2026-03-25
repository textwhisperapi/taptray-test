<?php
// test_tw_tree.php
// Standalone test page – READ ONLY
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>TextWhisper – Owners Lists Tree (Test)</title>

<style>
  body {
    font-family: system-ui, sans-serif;
    background: #111;
    color: #eee;
    padding: 16px;
  }

  h1 {
    font-size: 20px;
    margin-bottom: 6px;
  }

  h2 {
    font-size: 16px;
    margin-top: 22px;
    border-bottom: 1px solid #333;
    padding-bottom: 4px;
  }

  .info {
    font-size: 12px;
    color: #aaa;
    margin-bottom: 10px;
  }

  .token-bar {
    display: flex;
    gap: 8px;
    align-items: center;
    margin-bottom: 14px;
  }

  .token-bar input {
    background: #1a1a1a;
    color: #eee;
    border: 1px solid #444;
    padding: 4px 6px;
    font-size: 12px;
    width: 220px;
  }

  .token-bar button {
    background: #2a2a2a;
    color: #eee;
    border: 1px solid #444;
    padding: 4px 10px;
    font-size: 12px;
    cursor: pointer;
  }

  .token-bar button:hover {
    background: #3d6091;
    border-color: #3d6091;
  }

  ul.tree {
    list-style: none;
    padding-left: 16px;
    margin: 4px 0;
  }

  .tree li {
    margin: 2px 0;
  }

  .node {
    cursor: pointer;
    user-select: none;
  }

  .node:hover {
    color: #4da6ff;
  }

  .folder::before {
    content: "▸ ";
    color: #aaa;
  }

  .folder.open::before {
    content: "▾ ";
  }

  .item {
    color: #ccc;
    padding-left: 14px;
  }

  .item::before {
    content: "• ";
    color: #666;
  }

  .meta {
    color: #777;
    font-size: 11px;
    margin-left: 6px;
  }

  .hidden {
    display: none;
  }

  .error {
    color: #f66;
    margin-top: 12px;
  }
</style>
</head>

<body>

<h1>TextWhisper – Owners Lists JSON Tree</h1>
<div class="info">
  Session-based • No cache writes • Mirrors JSFunctions.js token logic
</div>

<div class="token-bar">
  <label for="tokenInput">List token:</label>
  <input id="tokenInput" type="text" />
  <button id="reloadBtn">Reload</button>
</div>

<div id="treeRoot">Loading…</div>

<script>
/* ==========================================================
   Effective token resolution (mirrors JSFunctions.js)
   ========================================================== */
function getEffectiveToken() {
  // 1) URL path: /{token}/...
  const parts = location.pathname.split("/").filter(Boolean);
  if (parts.length > 0) {
    return parts[0];
  }

  // 2) Last used list
  const last = localStorage.getItem("lastUsedListToken");
  if (last) return last;

  // 3) Fallback
  return "welcome";
}

const treeRoot = document.getElementById("treeRoot");
const tokenInput = document.getElementById("tokenInput");
const reloadBtn = document.getElementById("reloadBtn");

// Initialize token field
tokenInput.value = getEffectiveToken();

reloadBtn.onclick = () => {
  loadTree(tokenInput.value.trim());
};

// Initial load
loadTree(tokenInput.value.trim());

async function loadTree(token) {
  treeRoot.textContent = "Loading…";

  try {
    const res = await fetch(
      `/getOwnersListsJSON.php?token=${encodeURIComponent(token)}`,
      { credentials: "include" }
    );

    if (!res.ok) {
      throw new Error("HTTP " + res.status);
    }

    const data = await res.json();
    treeRoot.innerHTML = "";

    if (data.owned?.length) {
      treeRoot.appendChild(renderSection("Owned Lists", data.owned));
    }

    if (data.accessible?.length) {
      treeRoot.appendChild(renderSection("Accessible Lists", data.accessible));
    }

    if (!data.owned?.length && !data.accessible?.length) {
      treeRoot.textContent = "No lists found for this token.";
    }

  } catch (err) {
    treeRoot.innerHTML =
      `<div class="error">Failed to load JSON (${token}): ${err.message}</div>`;
  }
}

// --------------------------------------------------------

function renderSection(title, lists) {
  const wrap = document.createElement("div");

  const h = document.createElement("h2");
  h.textContent = title;
  wrap.appendChild(h);

  const ul = document.createElement("ul");
  ul.className = "tree";
  lists.forEach(l => ul.appendChild(renderListNode(l)));
  wrap.appendChild(ul);

  return wrap;
}

function renderListNode(list) {
  const li = document.createElement("li");

  const label = document.createElement("div");
  label.className = "node folder";
  label.textContent = list.title || list.name || "(untitled list)";
  li.appendChild(label);

  const childrenWrap = document.createElement("ul");
  childrenWrap.className = "tree hidden";

  // Sub-lists
  if (Array.isArray(list.children)) {
    list.children.forEach(child => {
      childrenWrap.appendChild(renderListNode(child));
    });
  }

  // Items
  if (Array.isArray(list.items)) {
    list.items.forEach(item => {
      const it = document.createElement("li");
      const span = document.createElement("div");
      span.className = "item";
      span.textContent = item.title || item.name || item.surrogate;

      if (item.surrogate) {
        const meta = document.createElement("span");
        meta.className = "meta";
        meta.textContent = `(${item.surrogate})`;
        span.appendChild(meta);
      }

      it.appendChild(span);
      childrenWrap.appendChild(it);
    });
  }

  li.appendChild(childrenWrap);

  // Expand / collapse
  label.onclick = () => {
    const open = !childrenWrap.classList.contains("hidden");
    childrenWrap.classList.toggle("hidden", open);
    label.classList.toggle("open", !open);
  };

  return li;
}
</script>

</body>
</html>
