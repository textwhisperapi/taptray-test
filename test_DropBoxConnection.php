<?php
session_start();
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Test Dropbox Connection</title>
  <style>
    body { font-family: system-ui, sans-serif; padding: 20px; }
    button { padding: 6px 12px; }
    pre { background:#f5f5f5; padding:10px; margin-top:10px; }
  </style>
</head>
<body>

<h2>Test Dropbox Connection</h2>

<button id="btnConnect">Connect to Dropbox</button>

<p id="status">Status: idle</p>

<pre id="debug"></pre>

<script>
const statusEl = document.getElementById("status");
const debugEl  = document.getElementById("debug");

/* Listen for OAuth completion from popup */
window.addEventListener("message", (e) => {
  if (e.origin !== location.origin) return;
  if (e.data?.type === "dropbox-auth-ok") {
    statusEl.textContent = "Status: OAuth completed";
    checkSession();
  }
});

/* Start OAuth */
document.getElementById("btnConnect").onclick = () => {
  statusEl.textContent = "Status: opening Dropbox OAuth…";
  window.open(
    "/api/auth/dropbox/dropbox-login.php",
    "dropboxOAuth",
    "width=600,height=700"
  );
};

/* Check session state */
async function checkSession() {
  const res = await fetch("/api/config_dropbox.php");
  const cfg = await res.json();

  // debugEl.textContent =
  //   "Config loaded:\n" + JSON.stringify(cfg, null, 2) +
  //   "\n\nSession token present: " +
  //   (<?php echo isset($_SESSION["DROPBOX_ACCESS_TOKEN"]) ? 'true' : 'false'; ?>);

  const s = await fetch("/api/auth/dropbox/session_status.php");
  const sess = await s.json();

  debugEl.textContent =
    "Config loaded:\n" + JSON.stringify(cfg, null, 2) +
    "\n\nSession token present: " + sess.hasToken;

}
</script>

</body>
</html>
