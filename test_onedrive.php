<?php
require_once __DIR__ . "/includes/functions.php";
sec_session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<title>Test OneDrive Connection</title>
<style>
  body {
    font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
    padding: 20px;
  }
  button {
    padding: 8px 14px;
    margin-right: 10px;
  }
  pre {
    background: #f6f6f6;
    padding: 12px;
    border-radius: 6px;
    max-height: 300px;
    overflow: auto;
  }
  .ok { color: green; }
  .bad { color: red; }
</style>
</head>
<body>

<h2>Test OneDrive Connection</h2>

<div>
  <button onclick="connect()">Connect to OneDrive</button>
  <button onclick="check()">Check Session</button>
  <button onclick="listRoot()">List Root</button>
</div>

<p>
  Session token present:
  <?php if (!empty($_SESSION['ONEDRIVE_ACCESS_TOKEN'])): ?>
    <strong class="ok">true</strong>
  <?php else: ?>
    <strong class="bad">false</strong>
  <?php endif; ?>
</p>

<h3>Output</h3>
<pre id="out">(empty)</pre>

<script>
function out(v) {
  document.getElementById("out").textContent =
    typeof v === "string" ? v : JSON.stringify(v, null, 2);
}

function connect() {
  window.open(
    "/api/auth/microsoft/onedrive-login.php",
    "onedriveOAuth",
    "width=600,height=700"
  );
}

async function check() {
  out("Access token present: " + <?=
    json_encode(!empty($_SESSION['ONEDRIVE_ACCESS_TOKEN']))
  ?>);
}

async function listRoot() {
  try {
    const res = await fetch("/File_listOneDrive.php?folder=root");
    if (!res.ok) {
      out("HTTP " + res.status);
      return;
    }
    out(await res.json());
  } catch (e) {
    out(String(e));
  }
}
</script>

</body>
</html>
