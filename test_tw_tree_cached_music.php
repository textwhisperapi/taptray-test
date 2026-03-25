<?php
// test_audio_list_exact_1138.php
// Zero-assumption diagnostic – READ ONLY
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Audio List – Surrogate 1138 (Exact)</title>

<style>
  body {
    font-family: system-ui, sans-serif;
    background: #111;
    color: #eee;
    padding: 16px;
  }

  h1 {
    font-size: 20px;
    margin-bottom: 8px;
  }

  .info {
    font-size: 12px;
    color: #aaa;
    margin-bottom: 14px;
  }

  .row {
    font-size: 13px;
    margin-left: 12px;
    margin-bottom: 4px;
    word-break: break-all;
    color: #8bc;
  }

  .row::before {
    content: "🎵 ";
  }

  .muted {
    color: #777;
    margin-left: 12px;
  }

  .error {
    color: #f66;
  }

  code {
    color: #9ad;
  }
</style>
</head>

<body>

<h1>Audio List (Exact Read)</h1>

<div class="info">
  Source: <strong>localStorage</strong><br>
  Key read: <code>audioList-1138</code><br>
  No assumptions • No inference • No cache scanning
</div>

<div id="out">Reading…</div>

<script>
(function () {
  const out = document.getElementById("out");
  const keyName = "audioList-1138";

  let raw;
  try {
    raw = localStorage.getItem(keyName);
  } catch (e) {
    out.innerHTML = "<div class='error'>localStorage not accessible.</div>";
    return;
  }

  if (!raw) {
    out.innerHTML =
      `<div class="muted">Key <code>${keyName}</code> does not exist.</div>`;
    return;
  }

  let data;
  try {
    data = JSON.parse(raw);
  } catch (e) {
    out.innerHTML =
      `<div class="error">Value of <code>${keyName}</code> is not valid JSON.</div>`;
    return;
  }

  if (!Array.isArray(data) || data.length === 0) {
    out.innerHTML =
      `<div class="muted">Key exists but contains no entries.</div>`;
    return;
  }

  out.innerHTML = "";

  data.forEach(entry => {
    const div = document.createElement("div");
    div.className = "row";
    div.textContent = String(entry.key ?? entry);
    out.appendChild(div);
  });
})();
</script>

</body>
</html>
