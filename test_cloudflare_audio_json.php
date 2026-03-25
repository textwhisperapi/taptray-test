<?php
// test_cloudflare_owner_audio_list.php
// One Cloudflare fetch via R2 worker /list endpoint
// READ ONLY – no cache, no writes, no loops
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Cloudflare R2 – Owner Audio List (Single Fetch)</title>

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
    font-size: 15px;
    margin-top: 18px;
    border-bottom: 1px solid #333;
    padding-bottom: 2px;
  }

  .info {
    font-size: 12px;
    color: #aaa;
    margin-bottom: 12px;
  }

  .bar {
    display: flex;
    gap: 8px;
    align-items: center;
    margin-bottom: 12px;
  }

  .bar input {
    background: #1a1a1a;
    color: #eee;
    border: 1px solid #444;
    padding: 4px 6px;
    font-size: 12px;
    width: 160px;
  }

  .bar button {
    background: #2a2a2a;
    color: #eee;
    border: 1px solid #444;
    padding: 4px 10px;
    font-size: 12px;
    cursor: pointer;
  }

  .bar button:hover {
    background: #3d6091;
    border-color: #3d6091;
  }

  .file {
    font-size: 13px;
    margin-left: 12px;
    color: #8bc;
    word-break: break-all;
  }

  .file::before {
    content: "🎵 ";
  }

  .muted {
    color: #777;
    margin-left: 12px;
  }

  .error {
    color: #f66;
    margin-top: 12px;
  }

  code {
    color: #9ad;
  }
</style>
</head>

<body>

<h1>Cloudflare R2 – Owner Audio (Single Fetch)</h1>

<div class="info">
  Source: <code>/list?prefix=OWNER/</code><br>
  Exactly one request • Derived from worker code
</div>

<div class="bar">
  <label for="ownerInput">Owner:</label>
  <input id="ownerInput" type="text" value="grimmi" />
  <button id="loadBtn">Fetch</button>
</div>

<div id="output">Waiting…</div>

<script>
const ownerInput = document.getElementById("ownerInput");
const loadBtn = document.getElementById("loadBtn");
const output = document.getElementById("output");

// ⚠️ Adjust if your worker is on a different domain
const WORKER_BASE = "https://r2-worker.textwhisper.workers.dev";

loadBtn.onclick = () => load(ownerInput.value.trim());

// auto-load
load(ownerInput.value.trim());

async function load(owner) {
  output.textContent = "Loading…";

  if (!owner) {
    output.innerHTML = "<div class='error'>No owner provided.</div>";
    return;
  }

  const url = `${WORKER_BASE}/list?prefix=${encodeURIComponent(owner + "/")}`;

  try {
    const res = await fetch(url, { credentials: "include" });
    if (!res.ok) throw new Error("HTTP " + res.status);

    const list = await res.json();
    if (!Array.isArray(list) || !list.length) {
      output.innerHTML = "<div class='muted'>No objects found.</div>";
      return;
    }

    // filter audio only
    const audio = list.filter(o =>
      o.key && /\.(mp3|wav|ogg|m4a|flac|aac|aif|aiff|webm|mid|midi)$/i.test(o.key)
    );

    if (!audio.length) {
      output.innerHTML = "<div class='muted'>No audio files found.</div>";
      return;
    }

    // group by surrogate (string-derived, no assumptions)
    const groups = {};
    for (const obj of audio) {
      const m = obj.key.match(/surrogate-(\d+)/);
      const surrogate = m ? m[1] : "unknown";
      (groups[surrogate] ||= []).push(obj);
    }

    output.innerHTML = "";

    Object.keys(groups).sort().forEach(surr => {
      const h = document.createElement("h2");
      h.textContent = `Surrogate ${surr}`;
      output.appendChild(h);

      groups[surr].forEach(obj => {
        const div = document.createElement("div");
        div.className = "file";
        div.textContent = obj.key;
        output.appendChild(div);
      });
    });

  } catch (err) {
    output.innerHTML =
      `<div class="error">Fetch failed: ${err.message}</div>`;
  }
}
</script>

</body>
</html>
