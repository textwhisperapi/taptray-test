<?php
// test_tw_tree.php
// Standalone test page – READ ONLY
// Cloudflare R2 ONLY
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>TextWhisper – Owners Lists Tree + Cloudflare Audio</title>

<style>
  body { font-family: system-ui, sans-serif; background:#111; color:#eee; padding:16px; }
  h1 { font-size:20px; margin-bottom:6px; }
  h2 { font-size:16px; margin-top:22px; border-bottom:1px solid #333; padding-bottom:4px; }

  .info { font-size:12px; color:#aaa; margin-bottom:10px; }

  .bar { display:flex; gap:8px; align-items:center; margin-bottom:12px; }
  .bar input {
    background:#1a1a1a; color:#eee; border:1px solid #444;
    padding:4px 6px; font-size:12px; width:200px;
  }
  .bar button {
    background:#2a2a2a; color:#eee; border:1px solid #444;
    padding:4px 10px; font-size:12px; cursor:pointer;
  }

  ul.tree { list-style:none; padding-left:16px; margin:4px 0; }
  .tree li { margin:2px 0; }

  .node { cursor:pointer; user-select:none; }
  .folder::before { content:"▸ "; color:#aaa; }
  .folder.open::before { content:"▾ "; }

  .item { color:#ccc; padding-left:14px; }
  .item::before { content:"• "; color:#666; }

  .item.expandable { cursor:pointer; }
  .item.expandable::before { content:"▸ "; color:#aaa; }
  .item.expandable.open::before { content:"▾ "; }

  .meta { color:#777; font-size:11px; margin-left:6px; }
  .audio-count { font-size:11px; color:#9ad; margin-left:6px; }

  .music { margin-left:22px; margin-top:2px; font-size:12px; color:#9ad; }
  .music-title { margin-bottom:2px; opacity:.9; }
  .music-item { margin-left:10px; color:#8bc; }
  .music-item::before { content:"🎵 "; }

  .hidden { display:none; }
  .error { color:#f66; margin-top:12px; }
</style>
</head>

<body>

<h1>TextWhisper – Owners Lists Tree + Cloudflare Audio</h1>

<div class="info">
  READ-ONLY • Cloudflare R2 only • ONE fetch • token = owner
</div>

<div class="bar">
  <label>Owner / Token:</label>
  <input id="ownerInput" value="grimmi" />
  <button id="loadBtn">Load</button>
</div>

<div id="treeRoot">Idle</div>

<script>
/* ==========================================================
   CONFIG
   ========================================================== */
const R2_WORKER_BASE = "https://r2-worker.textwhisper.workers.dev";
const CLOUD_AUDIO_BY_SURROGATE = Object.create(null);

/* ==========================================================
   Cloudflare owner-wide audio (ONE FETCH)
   ========================================================== */
async function loadOwnerCloudAudio(owner) {
  Object.keys(CLOUD_AUDIO_BY_SURROGATE).forEach(k => delete CLOUD_AUDIO_BY_SURROGATE[k]);

  const url = `${R2_WORKER_BASE}/list?prefix=${encodeURIComponent(owner + "/")}`;
  const res = await fetch(url);
  if (!res.ok) throw new Error("Cloudflare list failed");

  const list = await res.json();
  if (!Array.isArray(list)) return;

  list.forEach(obj => {
    if (!obj.key) return;
    if (!/\.(mp3|wav|ogg|m4a|flac|aac|aif|aiff|webm|mid|midi)$/i.test(obj.key)) return;

    const m = obj.key.match(/surrogate-(\d+)/);
    if (!m) return;

    const s = String(m[1]);
    (CLOUD_AUDIO_BY_SURROGATE[s] ||= []).push(obj.key);
  });

  console.log("CF audio loaded:", CLOUD_AUDIO_BY_SURROGATE);
}

/* ==========================================================
   Main loader
   ========================================================== */
const treeRoot = document.getElementById("treeRoot");
const ownerInput = document.getElementById("ownerInput");
const loadBtn = document.getElementById("loadBtn");

loadBtn.onclick = () => loadTree(ownerInput.value.trim());

// auto-load default
loadTree(ownerInput.value.trim());

async function loadTree(owner) {
  if (!owner) {
    treeRoot.innerHTML = "<div class='error'>Owner required.</div>";
    return;
  }

  treeRoot.textContent = "Loading…";

  try {
    // token == owner
    const res = await fetch(
      `/getOwnersListsJSON.php?token=${encodeURIComponent(owner)}`,
      { credentials: "include" }
    );
    if (!res.ok) throw new Error("HTTP " + res.status);

    const data = await res.json();

    await loadOwnerCloudAudio(owner);

    treeRoot.innerHTML = "";

    if (data.owned?.length)
      treeRoot.appendChild(renderSection("Owned Lists", data.owned));

    if (data.accessible?.length)
      treeRoot.appendChild(renderSection("Accessible Lists", data.accessible));

    if (!data.owned?.length && !data.accessible?.length)
      treeRoot.appendChild(document.createTextNode("No lists found."));

  } catch (err) {
    treeRoot.innerHTML = `<div class="error">${err.message}</div>`;
  }
}

/* ==========================================================
   Tree rendering
   ========================================================== */
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
  label.textContent = list.title || "(list)";
  li.appendChild(label);

  const children = document.createElement("ul");
  children.className = "tree hidden";

  list.children?.forEach(c => children.appendChild(renderListNode(c)));

  list.items?.forEach(item => {
    const it = document.createElement("li");
    const span = document.createElement("div");
    span.className = "item";
    span.textContent = item.title || "(item)";

    if (item.surrogate != null) {
      const meta = document.createElement("span");
      meta.className = "meta";
      meta.textContent = `(${item.surrogate})`;
      span.appendChild(meta);
    }

    it.appendChild(span);
    children.appendChild(it);

    if (item.surrogate == null) return;

    const s = String(item.surrogate);
    const cloud = CLOUD_AUDIO_BY_SURROGATE[s] || [];
    if (!cloud.length) return;

    span.classList.add("expandable");

    const badge = document.createElement("span");
    badge.className = "audio-count";
    badge.textContent = `🎵 ${cloud.length}`;
    span.appendChild(badge);

    const music = document.createElement("div");
    music.className = "music hidden";

    const t = document.createElement("div");
    t.className = "music-title";
    t.textContent = "Cloudflare audio:";
    music.appendChild(t);

    cloud.forEach(k => {
      const r = document.createElement("div");
      r.className = "music-item";
      r.textContent = `[CF] ${k.split("/").pop()}`;
      music.appendChild(r);
    });

    it.appendChild(music);

    span.onclick = e => {
      e.stopPropagation();
      music.classList.toggle("hidden");
      span.classList.toggle("open");
    };
  });

  li.appendChild(children);

  label.onclick = () => {
    children.classList.toggle("hidden");
    label.classList.toggle("open");
  };

  return li;
}
</script>

</body>
</html>
