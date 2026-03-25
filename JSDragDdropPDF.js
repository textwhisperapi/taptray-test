logStep("JSDragDropPDF.js executed");
// console.log("📄 JSDragDropPDF.js loaded");

window.currentUserIsOwner = true; 
//window.currentActiveTab = 'pdfTab';




// -------------------------------


const container = document.getElementById("pdfTabContent");

// Create overlay
const overlay = document.createElement("div");
overlay.id = "pdfDropOverlay";
overlay.style = `
  position: fixed;
  top: 0; left: 0; width: 100vw; height: 100vh;
  display: none;
  background: rgba(255,255,255,0.6);
  border: 2px dashed #007bff;
  z-index: 10000;
  text-align: center;
  padding-top: 20%;
  font-size: 24px;
  color: #007bff;
`;
overlay.innerText =
  window.translations?.drop_pdf_here ||
  "📄 Drop PDF, MusicXML, audio, or MIDI here!";
document.body.appendChild(overlay);

// Show overlay only if dragging a real file
document.addEventListener("dragover", (e) => {
  if (importModalOpen()) return;
  if (!e.dataTransfer?.types.includes("Files")) return;

  const pdfTab = document.getElementById("pdfTabContent");
  const musicTab = document.getElementById("musicTabContent");
  if (!pdfTab || window.currentActiveTab !== "pdfTab") return;

  // detect if we're over the bottom music panel
  const musicBounds = musicTab?.getBoundingClientRect();
  const overMusic =
    musicBounds &&
    musicTab.classList.contains("visible") &&
    e.clientX >= musicBounds.left &&
    e.clientX <= musicBounds.right &&
    e.clientY >= musicBounds.top &&
    e.clientY <= musicBounds.bottom;

  // detect if inside pdf tab
  const tabBounds = pdfTab.getBoundingClientRect();
  const overPdfTab =
    e.clientX >= tabBounds.left &&
    e.clientX <= tabBounds.right &&
    e.clientY >= tabBounds.top &&
    e.clientY <= tabBounds.bottom;

  // ✅ always trigger overlay when inside the pdf tab, regardless of canvases
  if (window.currentUserIsOwner && overPdfTab && !overMusic) {
    e.preventDefault();
    overlay.style.display = "block";
  } else {
    overlay.style.display = "none";
  }
});






// Hide overlay properly
document.addEventListener("dragleave", (e) => {
  if (importModalOpen()) return;     
  e.preventDefault();
  if (e.clientX <= 0 || e.clientY <= 0 || e.clientX >= window.innerWidth || e.clientY >= window.innerHeight) {
    overlay.style.display = "none";
  }
});

overlay.addEventListener("dragover", (e) => e.preventDefault());

overlay.addEventListener("drop", async (e) => {
  if (importModalOpen()) return;
  e.preventDefault();
  overlay.style.display = "none";

  // ✅ Only continue if PDF tab is visible
  const pdfTab = document.getElementById("pdfTabContent");
  if (!pdfTab?.classList.contains("active")) return;

  const file = e.dataTransfer?.files?.[0];
  if (!file) return;

  const ext = file.name.split(".").pop().toLowerCase();
  const type = getUploadType(ext);
  if (!type || !["pdf", "musicxml", "audio", "midi"].includes(type)) {
    return alert("❌ Only PDF, MusicXML, audio, or MIDI files here");
  }

  const surrogate = window.currentSurrogate;
  const el = document.querySelector(`.list-sub-item[data-value="${surrogate}"]`);
  const owner = el?.dataset.owner;
  if (!surrogate || !owner) return alert("⚠️ Select an item first.");

  await handleFileUpload(file, surrogate, type);

  // Accept the drop explicitly (prevents browser default fallback)
  if (e.dataTransfer) {
    e.dataTransfer.dropEffect = "copy";
  }
});

// Ensure drop is accepted on the PDF tab itself (not just overlay)
const pdfTabEl = document.getElementById("pdfTabContent");
if (pdfTabEl) {
  pdfTabEl.addEventListener("dragover", (e) => {
    if (importModalOpen()) return;
    e.preventDefault();
    if (e.dataTransfer) e.dataTransfer.dropEffect = "copy";
  });
}





//----------------




function getUploadType(ext) {
  const audioExts = ["mp3", "wav", "ogg", "m4a", "flac", "aac", "aif", "aiff", "webm"];
  if (ext === "pdf") return "pdf";
  if (["xml", "musicxml", "mxl"].includes(ext)) return "musicxml";
  if (["mid", "midi"].includes(ext)) return "midi";
  if (audioExts.includes(ext)) return "audio";
  return null;
}






async function handleFileUpload(file, surrogate, type) {
  const el = document.querySelector(`.list-sub-item[data-value="${surrogate}"]`);
  const batchState = window._batchUploadState;
  const batchMode = !!(batchState && batchState.active);
  let batchTicked = false;
  const batchTick = (status = "ok") => {
    if (!batchMode || batchTicked) return;
    batchTicked = true;
    batchState.done = Number(batchState.done || 0) + 1;
    if (typeof batchState.onTick === "function") {
      batchState.onTick(batchState.done, Number(batchState.total || 0), status, file?.name || "");
    }
  };

  const owner =
    el?.dataset.owner ||
    window.currentItemOwner ||
    window.currentOwner?.username ||
    window.SESSION_USERNAME ||
    document.body.dataset.username ||
    null;

  if (!surrogate || !owner) {
    if (!batchMode) alert(window.translations?.select_item_first || "⚠️ Select an item first.");
    batchTick("error");
    return;
  }

  const sizeLimits = { pdf: 30, musicxml: 10, midi: 2, audio: 100 };
  const limit = (sizeLimits[type] || 3) * 1024 * 1024;
  if (file.size > limit) {
    if (!batchMode) {
      alert(
        `${window.translations?.file_too_large || "🚫 File too large"} (max ${sizeLimits[type]}MB)`
      );
    }
    batchTick("error");
    return;
  }

  // Cloudflare-only upload pipeline (pdf + musicxml + audio + midi)
  let key;
  if (type === "pdf") {
    key = `${owner}/pdf/temp_pdf_surrogate-${surrogate}.pdf`;
  } else {
    key = `${owner}/surrogate-${surrogate}/files/${file.name}`;
  }

  const uploadUrl = `https://r2-worker.textwhisper.workers.dev/?key=${encodeURIComponent(key)}`;

  if (!batchMode) showUploadSpinner(`⏳ Uploading ${type.toUpperCase()}…`, 0, true);

  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open("POST", uploadUrl, true);
    xhr.setRequestHeader("Content-Type", file.type || "application/octet-stream");

    xhr.upload.onprogress = function (e) {
      if (e.lengthComputable) {
        const percent = Math.round((e.loaded / e.total) * 100);
        updateUploadProgress?.(percent);
      }
    };

    xhr.onload = async function () {
      if (!batchMode) hideUploadSpinner();
      if (xhr.status === 200) {
        if (!batchMode) showFlashMessage(`✅ Uploaded to Cloudflare: ${file.name}`);

        if (type === "pdf") {
          await removeOldCachedPDF(surrogate);
          const cloudflareUrl = `https://pub-1afc23a510c147a5a857168f23ff6db8.r2.dev/${key}`;
          await cachePDFOffline(surrogate);
          window.loadPDF(surrogate, `${cloudflareUrl}?v=${Date.now()}`);
          try {
            await fetch("/logUploadGeneral.php", {
              method: "POST",
              headers: { "Content-Type": "application/x-www-form-urlencoded" },
              body: new URLSearchParams({
                surrogate: String(surrogate),
                type: "pdf",
                url: cloudflareUrl,
                source: "cloudflare"
              })
            });
          } catch (err) {
            console.warn("⚠️ PDF general log failed:", err?.message || err);
          }
        } else if (type === "musicxml") {
          window.twMusicXml?.refreshPdfTabXmlState?.(surrogate);
          if (window.currentActiveTab === "pdfTab") {
            showFlashMessage?.(`✅ Playable score ready: ${file.name}`);
          }
        } else if (type === "midi" && window.addMidiToFooterMenu) {
          window.addMidiToFooterMenu(file.name, `https://pub-1afc23a510c147a5a857168f23ff6db8.r2.dev/${key}`);
        }

        if (document.getElementById("musicTabContent")?.classList.contains("visible")) {
          window.renderMusicPanel();
        }

        batchTick("ok");
        resolve();
      } else {
        if (!batchMode) alert("❌ Upload failed: " + xhr.responseText);
        batchTick("error");
        reject(new Error(xhr.responseText));
      }
    };

    xhr.onerror = () => {
      if (!batchMode) hideUploadSpinner();
      if (!batchMode) alert("❌ Connection error");
      batchTick("error");
      reject(new Error("Network error"));
    };

    xhr.send(file);
  });
}







async function maybeExtractTextFromPDF(url) {
  const currentText = document.getElementById("myTextarea")?.value.trim();
  if (currentText && currentText.length > 20) return; // Skip if already filled

  //const wantsExtract = confirm("📝 This item is empty. Extract text from the PDF?");
  const wantsExtract = confirm(window.translations?.extract_text_prompt || "📝 This item is empty. Extract text from the PDF?");
  if (!wantsExtract) return;

  try {
    const loadingTask = pdfjsLib.getDocument(url);
    const pdf = await loadingTask.promise;
    const page = await pdf.getPage(1);
    const content = await page.getTextContent();
    const rawLines = content.items.map(i => i.str).filter(Boolean);

    // ⚠️ Detect meaningful lines using Icelandic + common punctuation
    const icelandicRegex = /^[a-zA-ZÁÉÍÓÚÝÞÆÖáðéíóúýþæö0-9 ,.!?:"'“”‘’\-–—]+$/;
    const meaningful = rawLines.filter(line => icelandicRegex.test(line.trim()));

    if (meaningful.length < 2) {
      //showFlashMessage("🎼 This PDF appears to be musical notation — no readable text extracted.");
      showFlashMessage(window.translations?.no_text_found_pdf || "🎼 This PDF appears to be musical notation — no readable text extracted.");
      console.warn("⛔ Not enough readable text for extraction:", rawLines);
      return;
    }

    const title = meaningful[0] || "Untitled";
    const snippet = meaningful.slice(1, 5).join("\n");
    const fullText = title + "\n" + snippet;

    document.getElementById("myTextarea").value = fullText;
    document.getElementById("myTextarea2").value = fullText;

    //showFlashMessage("📄 Text extracted from PDF!");
    showFlashMessage(window.translations?.text_extracted || "📄 Text extracted from PDF!");
  } catch (err) {
    console.error("❌ Failed to extract PDF text:", err);
    showFlashMessage("⚠️ Could not extract text from this PDF.");
  }
}


//----------------




document.getElementById("mobilePdfInput")?.addEventListener("change", async function () {
  const file = this.files?.[0];
  if (!file) return;

  const ext = file.name.split(".").pop().toLowerCase();
  const type = getUploadType(ext);

  if (!type) {
    alert(
      window.translations?.invalid_file_type ||
      "❌ Invalid file type. Only PDF, MusicXML, MIDI, or common audio files (MP3, M4A, WAV, OGG, FLAC, AAC, AIF) are allowed."
    );


    return;
  }

  const surrogate = window.currentSurrogate;
  if (!surrogate) {
    alert(window.translations?.select_item_first || "⚠️ Please select an item first.");
    return;
  }

  await handleFileUpload(file, surrogate, type);
  this.value = "";
});





// ---------------------------------





// Helper functions
async function checkIfPDFExists(surrogate) {
  const el = document.querySelector(`.list-sub-item[data-value="${surrogate}"]`);
  const owner =
    el?.dataset.owner ||
    window.currentItemOwner ||
    window.currentOwner?.username ||
    window.SESSION_USERNAME ||
    "";
  if (!owner) return false;

  const urls = [
    `https://r2-worker.textwhisper.workers.dev/${owner}/pdf/temp_pdf_surrogate-${surrogate}.pdf`,
    `https://pub-1afc23a510c147a5a857168f23ff6db8.r2.dev/${owner}/pdf/temp_pdf_surrogate-${surrogate}.pdf`
  ];

  for (const url of urls) {
    try {
      const res = await fetch(url, { method: "HEAD", cache: "no-store" });
      if (res.ok) return true;
    } catch {}

    try {
      const res = await fetch(url, {
        method: "GET",
        cache: "no-store",
        headers: { Range: "bytes=0-0" }
      });
      if (res.ok || res.status === 206) return true;
    } catch {}
  }

  return false;
}





/**
 * 📄 Upload a remote PDF (e.g. Dropbox or Drive link) to the correct backend.
 * Works for both Cloudflare R2 and legacy PHP storage.
 */
async function uploadDirectPdfUrl() {
  const input = document.getElementById("directPdfUrl");
  const externalUrl = input?.value.trim();
  const surrogate = window.currentSurrogate;

//   if (!externalUrl || !externalUrl.endsWith(".pdf")) {
//     alert("❌ Please enter a valid PDF URL.");
//     return;
//   }
  
  if (!externalUrl || !/\.pdf(\?|$)/i.test(externalUrl)) {
      alert("❌ The selected file does not appear to be a valid PDF link.");
      console.warn("Rejected URL:", externalUrl);
      return;
  }
  

  const el = document.querySelector(`.list-sub-item[data-value="${surrogate}"]`);
  const owner =
    el?.dataset.owner ||
    window.currentItemOwner ||
    window.SESSION_USERNAME ||
    document.body.dataset.username ||
    "unknown";

  if (!surrogate || !owner) {
    alert("⚠️ Please select an item first.");
    return;
  }

  try {
    // 🧩 Normalize Dropbox or Drive URLs
    let normalizedUrl = externalUrl
      .replace("www.dropbox.com", "dl.dropboxusercontent.com")
      .replace("dropbox.com/s/", "dl.dropboxusercontent.com/s/")
      .replace("?dl=0", "")
      .replace("?raw=1", "");

    console.log(`☁️ Uploading PDF via Cloudflare Worker → ${normalizedUrl}`);

    // Build canonical R2 key for this item
    const key = `${owner}/pdf/temp_pdf_surrogate-${surrogate}.pdf`;

    try {
      // First try direct browser fetch + worker upload (fastest path).
      const blob = await fetch(normalizedUrl).then(r => {
        if (!r.ok) throw new Error("Could not fetch remote PDF.");
        return r.blob();
      });

      const uploadUrl = `https://r2-worker.textwhisper.workers.dev/?key=${encodeURIComponent(
        key
      )}`;

      const res = await fetch(uploadUrl, {
        method: "POST",
        headers: { "Content-Type": "application/pdf" },
        body: blob,
      });

      if (!res.ok) throw new Error("Cloudflare upload failed.");

      const canonicalUrl = `https://pub-1afc23a510c147a5a857168f23ff6db8.r2.dev/${key}`;
      await cachePDFOffline(surrogate);
      await removeOldCachedPDF?.(surrogate);
      window.loadPDF(surrogate, `${canonicalUrl}?v=${Date.now()}`);
      showFlashMessage("✅ PDF uploaded to Cloudflare");
      return;
    } catch (directErr) {
      // Fallback for non-CORS origins: let server fetch URL with cURL.
      console.warn("⚠️ Direct browser upload failed, trying server fallback:", directErr);
    }

    const body = new URLSearchParams({
      url: normalizedUrl,
      surrogate: String(surrogate),
    });

    const fallbackRes = await fetch("/File_uploadDirectPdfUrl.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      credentials: "same-origin",
      body: body.toString(),
    });

    const rawText = await fallbackRes.text();
    const fallbackJson = (() => {
      try {
        return rawText ? JSON.parse(rawText) : {};
      } catch {
        return {};
      }
    })();

    if (!fallbackRes.ok || fallbackJson?.status !== "success" || !fallbackJson?.url) {
      const parts = [];
      if (!fallbackRes.ok) parts.push(`HTTP ${fallbackRes.status}`);
      if (fallbackJson?.error) parts.push(String(fallbackJson.error));
      if (!fallbackJson?.error && rawText && !fallbackJson?.status) {
        parts.push(rawText.slice(0, 180));
      }
      const suffix = parts.length ? ` (${parts.join(" | ")})` : "";
      throw new Error(`Server-side URL import failed${suffix}`);
    }

    const fallbackUrl = String(fallbackJson.url);
    const withBuster = `${fallbackUrl}${fallbackUrl.includes("?") ? "&" : "?"}v=${Date.now()}`;
    await removeOldCachedPDF?.(surrogate);
    window.loadPDF(surrogate, withBuster);
    showFlashMessage("✅ PDF uploaded via server fallback");
  } catch (err) {
    console.error("❌ Upload failed:", err);
    const detail = err?.message ? `\n${err.message}` : "";
    alert((window.translations?.pdf_upload_failed_url || "❌ Could not upload the PDF from this URL.") + detail);
  }
}



function importModalOpen() {
  return !!document.getElementById("importFilesModal");
}




// === Global Upload Spinner ===
// spinner.js
// === Upload Spinner with Timer ===
let uploadStartTime = null;
let uploadTimerInterval = null;

window.showUploadSpinner = function (
  msg = "⏳ Uploading…",
  initial = 0,
  cancellable = false,
  xhr = null
) {
  let overlay = document.getElementById("uploadOverlay");
  if (!overlay) {
    overlay = document.createElement("div");
    overlay.id = "uploadOverlay";
    overlay.style = `
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,0.6);
      display: flex;
      justify-content: center;
      align-items: center;
      z-index: 99999;
    `;

    overlay.innerHTML = `
      <div style="background:#222; padding:20px; border-radius:12px; text-align:center; color:#fff; min-width:220px;">
        <svg width="100" height="100">
          <circle cx="50" cy="50" r="46" stroke="#444" stroke-width="8" fill="none"></circle>
          <circle id="progressCircle" cx="50" cy="50" r="46"
                  stroke="#337ab7" stroke-width="8" fill="none"
                  stroke-linecap="round"
                  stroke-dasharray="${2 * Math.PI * 46}"
                  stroke-dashoffset="${2 * Math.PI * 46}"
                  transform="rotate(-90 50 50)"></circle>
        </svg>
        <div id="progressText" style="margin-top:10px; font-size:16px;">${initial}%</div>
        <div id="uploadSpinnerMsg" style="margin-top:6px; font-size:14px;">${msg}</div>
        <div id="uploadTimer" style="margin-top:6px; font-size:13px; color:#ccc;">0s</div>
        <button id="uploadCancelBtn"
                style="margin-top:10px; padding:6px 12px; border:none; border-radius:6px;
                       background:#337ab7; color:#fff; cursor:pointer; display:none;">
          ✖ Cancel
        </button>
      </div>
    `;
    document.body.appendChild(overlay);
  }

  // Reset + start timer
  uploadStartTime = Date.now();
  const timerEl = document.getElementById("uploadTimer");
  if (uploadTimerInterval) clearInterval(uploadTimerInterval);
  uploadTimerInterval = setInterval(() => {
    if (!timerEl) return;
    const elapsed = Math.floor((Date.now() - uploadStartTime) / 1000);
    const mins = Math.floor(elapsed / 60);
    const secs = elapsed % 60;
    timerEl.textContent = mins > 0 ? `${mins}m ${secs}s` : `${secs}s`;
  }, 1000);

  // Update message
  document.getElementById("uploadSpinnerMsg").textContent = msg;

  // Bind cancel button
  const cancelBtn = document.getElementById("uploadCancelBtn");
  if (cancelBtn) {
    cancelBtn.style.display = cancellable ? "inline-block" : "none";
    cancelBtn.onclick = () => {
      if (xhr) {
        console.log("🚫 Upload canceled by user");
        xhr.abort();
      }
      window.hideUploadSpinner();
    };
  }

  // Initialize progress
  window.updateUploadProgress(initial);
};

window.updateUploadProgress = function (percent) {
  const circle = document.getElementById("progressCircle");
  const text = document.getElementById("progressText");
  if (!circle || !text) return;

  const r = 46;
  const c = 2 * Math.PI * r;
  const offset = c - (percent / 100) * c;

  circle.style.strokeDashoffset = offset;
  text.textContent = percent + "%";
};

window.hideUploadSpinner = function () {
  if (uploadTimerInterval) clearInterval(uploadTimerInterval);
  uploadTimerInterval = null;
  uploadStartTime = null;
  document.getElementById("uploadOverlay")?.remove();
};



async function initDropbox() {
  // prevent duplicate loads
  if (window._dropboxReady) return true;

  const res = await fetch("/api/config_dropbox.php");
  const cfg = await res.json();

  if (!cfg.appKey) {
    alert("❌ Dropbox configuration not found.");
    return false;
  }

  return new Promise((resolve, reject) => {
    const script = document.createElement("script");
    script.id = "dropboxjs";
    script.src = "https://www.dropbox.com/static/api/2/dropins.js";
    script.type = "text/javascript";
    script.dataset.appKey = cfg.appKey;
    script.onload = () => {
      console.log(`✅ Dropbox SDK loaded for ${cfg.env} with key ${cfg.appKey}`);
      window._dropboxReady = true;
      resolve(true);
    };
    script.onerror = () => reject("❌ Dropbox SDK failed to load.");
    document.head.appendChild(script);
  });
}



window.importFromDropboxMMMMM = async function () {
  const ok = await initDropbox();
  if (!ok) return;

  Dropbox.choose({
    linkType: "direct",
    multiselect: false,
    extensions: [".pdf"],
    success: async (files) => {
      const f = files[0];
      let fileUrl = f.link
        .replace("www.dropbox.com", "dl.dropboxusercontent.com")
        .replace("dropbox.com/s/", "dl.dropboxusercontent.com/s/")
        .replace("?dl=0", "")
        .replace("?raw=1", "");

      document.getElementById("directPdfUrl").value = fileUrl;
      await uploadDirectPdfUrl(); // ✅ Cloudflare-aware
    },
    cancel: () => showFlashMessage("❌ Dropbox selection canceled."),
  });
};


window.importFromDropbox = async function () {
  const ok = await initDropbox();
  if (!ok) return;

  Dropbox.choose({
    linkType: "direct",
    multiselect: false,
    extensions: [".pdf"],
    success: async (files) => {
      const f = files[0];

      // --- Normalize Dropbox URL for direct binary download ---
      let fileUrl = f.link
        .replace("www.dropbox.com", "dl.dropboxusercontent.com")
        .replace("dropbox.com/s/", "dl.dropboxusercontent.com/s/")
        .replace("?dl=0", "")
        .replace("?raw=1", "");

      // 🔥 CRITICAL FIX:
      // Dropbox sometimes returns an HTML preview unless you force ?dl=1.
      if (!fileUrl.includes("?")) fileUrl += "?dl=1";
      else fileUrl += "&dl=1";

      console.log("📥 Normalized Dropbox PDF URL:", fileUrl);

      document.getElementById("directPdfUrl").value = fileUrl;

      // 🔄 Upload using Cloudflare-aware pipeline
      await uploadDirectPdfUrl();
    },
    cancel: () => showFlashMessage("❌ Dropbox selection canceled."),
  });
};


window.importMusicFromGoogleDrive = async function () {
  try {
    // 1) Preconditions (same as PDF)
    if (!window.gapi || !window.google?.accounts?.oauth2) {
      alert("Google API not loaded yet. Try again in a moment.");
      return;
    }

    const surrogate = window.currentSurrogate;
    if (!surrogate) {
      alert("⚠️ Select an item first (an existing item must be active).");
      return;
    }

    if (typeof window.handleFileUpload !== "function") {
      throw new Error("handleFileUpload(file, surrogate, type) not found.");
    }

    // 2) Load Picker
    await new Promise(resolve => gapi.load("picker", resolve));

    // 3) OAuth token (same helper)
    const token = await getGoogleDriveToken();
    if (!token) {
      showFlashMessage?.("❌ Google authorization failed.");
      return;
    }

    // 4) Build Picker (audio + MIDI, multi-select)
    const picker = new google.picker.PickerBuilder()
      .addView(
        new google.picker.View(google.picker.ViewId.DOCS)
          .setMimeTypes(
            "audio/mpeg,audio/wav,audio/ogg,audio/x-m4a,audio/flac," +
            "audio/aac,audio/aiff,audio/midi"
          )
      )
      .enableFeature(google.picker.Feature.MULTISELECT_ENABLED)
      .setOAuthToken(token)
      .setOrigin(window.location.origin)
      .setCallback(async (data) => {
        try {
          if (data.action !== google.picker.Action.PICKED) return;

          for (const doc of data.docs || []) {
            if (!doc?.id) continue;

            // re-check active item
            const targetSurrogate = window.currentSurrogate;
            if (!targetSurrogate) {
              alert("⚠️ No active item selected.");
              return;
            }

            // 5) Fetch file from Drive (OAuth protected)
            const res = await fetch(
              `https://www.googleapis.com/drive/v3/files/${doc.id}?alt=media`,
              { headers: { Authorization: `Bearer ${token}` } }
            );

            if (!res.ok) {
              throw new Error(`Failed to download (${res.status})`);
            }

            const blob = await res.blob();

            // 6) Detect type by extension
            const name = doc.name || "imported";
            const ext  = name.split(".").pop().toLowerCase();
            const type =
              ["mid", "midi"].includes(ext) ? "midi" :
              ["mp3","wav","ogg","m4a","flac","aac","aif","aiff"].includes(ext)
                ? "audio"
                : null;

            if (!type) {
              console.warn("Skipping unsupported file:", name);
              continue;
            }

            const file = new File([blob], name, {
              type: blob.type || "application/octet-stream"
            });

            await window.handleFileUpload(file, targetSurrogate, type);
          }

          renderMusicPanel();
          showFlashMessage?.("✅ Music imported from Google Drive");

        } catch (e) {
          console.error("Google Drive music upload failed:", e);
          showFlashMessage?.("❌ Google Drive upload failed.");
        }
      })
      .build();

    picker.setVisible(true);

  } catch (err) {
    console.error("Google Drive import failed:", err);
    showFlashMessage?.("❌ Google Drive import failed.");
  }
};


let googleDriveTokenClient = null;

async function getGoogleDriveToken() {
  return new Promise((resolve, reject) => {
    if (!google?.accounts?.oauth2) {
      reject("Google OAuth not loaded");
      return;
    }
    if (!window.GOOGLE_CLIENT_ID) {
      reject("Missing GOOGLE_CLIENT_ID");
      return;
    }

    if (!googleDriveTokenClient) {
      googleDriveTokenClient = google.accounts.oauth2.initTokenClient({
        client_id: window.GOOGLE_CLIENT_ID,
        scope: "https://www.googleapis.com/auth/drive.readonly",
        callback: () => {}
      });
    }

    googleDriveTokenClient.callback = (resp) => {
      if (resp?.access_token) resolve(resp.access_token);
      else reject("No access token returned");
    };

    googleDriveTokenClient.requestAccessToken();
  });
}




