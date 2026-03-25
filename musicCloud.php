<?php
// musicCloud.php — standalone test page for R2-based music panel
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Music Cloud Panel (R2 Test)</title>
  <link rel="stylesheet" href="musicPanel.css">
  <style>
    body { font-family: sans-serif; background:#222; color:#eee; padding:20px; }
    #midiList { margin-top:20px; }
    .musicPanel-item { background:#333; margin:6px 0; padding:10px; border-radius:6px; }
    .musicPanel-title { font-weight:bold; cursor:pointer; }
    .musicPanel-header { display:flex; justify-content:space-between; align-items:center; }
    .musicPanel-deleteBtn { background:none; border:none; color:#f66; cursor:pointer; }

    /* 🟦 Progress bar styling */
    #progressWrapper {
      width: 100%;
      max-width: 400px;
      height: 20px;
      background: #444;
      border-radius: 10px;
      margin: 10px 0;
      overflow: hidden;
      display: none;
    }
    #progressBar {
      height: 100%;
      width: 0%;
      background: #4da6ff;
      text-align: center;
      line-height: 20px;
      color: #000;
      font-size: 12px;
      font-weight: bold;
      transition: width 0.2s;
    }
  </style>
</head>
<body>
  <h2>🎵 Music Cloud Panel Test (R2)</h2>

  <label>
    Username:
    <input type="text" id="usernameInput" placeholder="e.g. grimmi">
  </label>
  <label>
    Surrogate ID:
    <input type="text" id="surrogateInput" placeholder="e.g. 1379">
  </label>
  <br><br>

  <input type="file" id="musicFileInput">
  <button id="uploadBtn">Upload</button>

  <!-- 🟦 Progress -->
  <div id="progressWrapper">
    <div id="progressBar">0%</div>
  </div>

  <div id="status"></div>

  <h3>Uploaded Files</h3>
  <div id="midiList"></div>

  <script>
    const WORKER_URL = "https://r2-worker.textwhisper.workers.dev";
    const PUBLIC_URL = "https://pub-1afc23a510c147a5a857168f23ff6db8.r2.dev";

    async function listFiles() {
      const username = document.getElementById("usernameInput").value.trim();
      const surrogate = document.getElementById("surrogateInput").value.trim();
      if (!username || !surrogate) return;

      const prefix = `${username}/surrogate-${surrogate}/files/`;
      const resp = await fetch(`${WORKER_URL}/list?prefix=${encodeURIComponent(prefix)}`);
      const data = await resp.json();

      const list = document.getElementById("midiList");
      list.innerHTML = "";

      if (Array.isArray(data) && data.length) {
        data.forEach(obj => {
          const name = obj.key.split("/").pop();
          const url = `${PUBLIC_URL}/${obj.key}`;

          const wrapper = document.createElement("div");
          wrapper.className = "musicPanel-item";

          const header = document.createElement("div");
          header.className = "musicPanel-header";

          const title = document.createElement("span");
          title.className = "musicPanel-title";
          title.textContent = name;
          title.onclick = () => {
            const player = document.createElement("audio");
            player.controls = true;
            player.src = url;
            wrapper.appendChild(player);
          };

          const delBtn = document.createElement("button");
          delBtn.className = "musicPanel-deleteBtn";
          delBtn.textContent = "🗑️";
          delBtn.onclick = async (e) => {
            e.stopPropagation();
            if (!confirm(`Delete ${name}?`)) return;
            await fetch(`${WORKER_URL}/?key=${encodeURIComponent(obj.key)}`, { method: "DELETE" });
            listFiles();
          };

          header.appendChild(title);
          header.appendChild(delBtn);
          wrapper.appendChild(header);
          list.appendChild(wrapper);
        });
      } else {
        list.innerHTML = "<p class='text-muted'>No files found.</p>";
      }
    }

    async function uploadFile() {
      const file = document.getElementById("musicFileInput").files[0];
      const username = document.getElementById("usernameInput").value.trim();
      const surrogate = document.getElementById("surrogateInput").value.trim();
      if (!file || !username || !surrogate) {
        alert("Pick a file, username, and surrogate ID");
        return;
      }

      const key = `${username}/surrogate-${surrogate}/files/${file.name}`;
      const uploadUrl = `${WORKER_URL}/?key=${encodeURIComponent(key)}`;

      const progressWrapper = document.getElementById("progressWrapper");
      const progressBar = document.getElementById("progressBar");
      progressWrapper.style.display = "block";
      progressBar.style.width = "0%";
      progressBar.textContent = "0%";

      const xhr = new XMLHttpRequest();
      xhr.open("POST", uploadUrl, true);
      xhr.setRequestHeader("Content-Type", file.type || "application/octet-stream");

      // 🟦 Progress tracking
      xhr.upload.onprogress = function(e) {
        if (e.lengthComputable) {
          const percent = (e.loaded / e.total) * 100;
          progressBar.style.width = percent.toFixed(1) + "%";
          progressBar.textContent = percent.toFixed(0) + "%";
        }
      };

      xhr.onload = function() {
        if (xhr.status === 200) {
          document.getElementById("status").textContent = "✅ Uploaded!";
          progressBar.style.width = "100%";
          progressBar.textContent = "100%";
          setTimeout(() => { progressWrapper.style.display = "none"; }, 1000);
          listFiles();
        } else {
          document.getElementById("status").textContent = "❌ Upload failed: " + xhr.responseText;
        }
      };

      xhr.onerror = () => {
        document.getElementById("status").textContent = "❌ Connection error";
      };

      xhr.send(file);
    }

    document.getElementById("uploadBtn").addEventListener("click", uploadFile);
    document.getElementById("usernameInput").addEventListener("change", listFiles);
    document.getElementById("surrogateInput").addEventListener("change", listFiles);
  </script>
</body>
</html>
