<?php
// testDropbox.php — Dropbox integration test for TextWhisper

include_once __DIR__ . '/includes/db_connect.php';
include_once __DIR__ . '/includes/functions.php';
sec_session_start();

$username = $_SESSION['username'] ?? 'guest';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Dropbox → TextWhisper PDF Test</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/myStyles.css?v=<?=time()?>">

<!-- Dropbox SDK -->
<script type="text/javascript"
        src="https://www.dropbox.com/static/api/2/dropins.js"
        id="dropboxjs"
        data-app-key="0cc3ltg6cq7pkt9"></script>

<style>
body {
  font-family: system-ui, sans-serif;
  padding: 20px;
  background: #f9fafb;
  color: #333;
}
h2 { margin-bottom: 10px; }
button {
  background: #007bff;
  color: #fff;
  border: none;
  padding: 12px 18px;
  font-size: 16px;
  border-radius: 8px;
  cursor: pointer;
}
button:hover { background: #0056b3; }
#result {
  margin-top: 20px;
  background: #fff;
  border: 1px solid #ccc;
  padding: 15px;
  border-radius: 8px;
  min-height: 60px;
}
.banner {
  background: #e0ffe5;
  border: 1px solid #97e09e;
  padding: 8px 12px;
  border-radius: 8px;
  margin-bottom: 15px;
  color: #2c662d;
  font-size: 15px;
}
footer {
  margin-top: 40px;
  font-size: 14px;
  color: #666;
}
</style>
</head>

<body>
<h2>📁 Dropbox PDF Import Test</h2>
<div class="banner">
  ✅ Using test surrogate <b>#2057</b> (owner: <b>grimmi</b>)
</div>
<p>
  Logged in as: <strong><?=htmlspecialchars($username)?></strong><br>
  This page tests importing a PDF directly from Dropbox into TextWhisper.
</p>

<button id="chooseFromDropboxBtn">📁 Choose PDF from Dropbox</button>
<div id="result">Waiting for selection...</div>

<!-- Hidden field reused by uploadDirectPdfUrl() -->
<input type="hidden" id="directPdfUrl">

<!-- Load existing TextWhisper upload logic -->
<script src="/JSDragDdropPDF.js?v=<?=time()?>"></script>

<script>
/* ---------------------------------------------------
   Minimal TextWhisper context for standalone testing
--------------------------------------------------- */

// Pretend we are viewing surrogate #2057 (owned by grimmi)
window.currentSurrogate = 2057;
window.currentItemOwner = "grimmi";
window.SESSION_USERNAME = "grimmi";
window.fileServer = "cloudflare"; // or "justhost" if testing legacy

// Simulate the expected list item DOM node
document.body.insertAdjacentHTML("beforeend", `
  <div class="list-sub-item" data-value="2057" data-owner="grimmi" data-fileserver="cloudflare"></div>
`);


/* ---------------------------------------------------
   Dropbox integration
--------------------------------------------------- */

document.getElementById("chooseFromDropboxBtn").addEventListener("click", function () {
  if (!window.Dropbox) {
    alert("Dropbox SDK not loaded — check your App Key and domain setup.");
    return;
  }

  const options = {
    success: async function (files) {
      const f = files[0];
      console.log("✅ Dropbox Chooser returned:", f);
    
      // Convert Dropbox URL → direct-download URL
      let fileUrl = f.link
        .replace("www.dropbox.com", "dl.dropboxusercontent.com")
        .replace("dropbox.com/s/", "dl.dropboxusercontent.com/s/")
        .replace("?dl=0", "")
        .replace("?raw=1", "");
    
      // 💬 Show the detected and converted URL
      document.getElementById("result").innerHTML = `
        <b>Selected:</b> ${f.name}<br>
        <b>Original URL:</b><br><code>${f.link}</code><br>
        <b>Converted direct URL:</b><br><code>${fileUrl}</code><br>
        <a href="${fileUrl}" target="_blank">🔗 Test this link</a><br><br>
        <em>Uploading to TextWhisper surrogate #2057...</em>
      `;
    
      try {
        document.getElementById("directPdfUrl").value = fileUrl;
        await uploadDirectPdfUrl();
        document.getElementById("result").innerHTML +=
          "<p>✅ Upload complete — check TextWhisper.</p>";
      } catch (err) {
        console.error("❌ Upload error:", err);
        document.getElementById("result").innerHTML +=
          `<p style='color:red;'>❌ Upload failed: ${err.message}</p>`;
      }
    },


    cancel: function () {
      document.getElementById("result").textContent =
        "❌ Dropbox selection canceled.";
    },
    linkType: "direct",
    multiselect: false,
    extensions: [".pdf"],
    folderselect: false,
  };

  Dropbox.choose(options);
});
</script>


<footer>
  <p>🧪 Test page for Dropbox Chooser → TextWhisper upload flow.<br>
  Works with both Cloudflare R2 and JustHost backends.</p>
</footer>
</body>
</html>
