//JSEvents.js



// ✅ Show update notice using the built-in flash message system
if ("serviceWorker" in navigator) {
  navigator.serviceWorker.addEventListener("controllerchange", () => {
    const showTwUpdateNotice = (text) => {
      const msg = document.createElement("div");
      msg.innerHTML = `
        <img src="/img/wrt.png" alt="TextWhisper" style="width:18px;height:18px;vertical-align:middle;margin-right:8px;border-radius:4px;">
        <span>${text}</span>
      `;
      Object.assign(msg.style, {
        position: "fixed",
        top: "50%",
        left: "50%",
        transform: "translate(-50%, -50%)",
        background: "#222",
        color: "#fff",
        padding: "12px 18px",
        borderRadius: "8px",
        fontSize: "15px",
        zIndex: "99999",
        textAlign: "center",
        fontFamily: "sans-serif",
        boxShadow: "0 2px 10px rgba(0,0,0,0.3)",
        display: "flex",
        alignItems: "center",
        gap: "2px",
      });
      document.body.appendChild(msg);
      return msg;
    };

    if (window.forceUpdate === true) {
      console.log("🔄 Critical Service Worker update activated — refreshing...");
      const msg = showTwUpdateNotice("TextWhisper critical update applied. Refreshing...");
      setTimeout(() => window.location.reload(), 1700);
      setTimeout(() => msg.remove(), 1700);
      return;
    }

    console.log("✅ New Service Worker activated — no refresh needed.");
    const msg = showTwUpdateNotice("TextWhisper updated to the latest version.");
    setTimeout(() => msg.remove(), 1500);
  });
}




//DOM
document.addEventListener("DOMContentLoaded", function () {
    console.log("🚀 Scripts loaded!");

    const isLoggedIn = document.body.classList.contains("logged-in");

    const slider = document.getElementById("b");
    const searchInput = document.getElementById("searchSidebar");
    const sidebarTabs = document.querySelectorAll(".tab-link");
    const SidebartabContents = document.querySelectorAll(".sidebar-tab-content");

    //const editModeToggle = document.getElementById("editModeToggle");
    const editModeToggle = document.querySelectorAll(".edit-mode-toggle");

    const newButton = document.getElementById("newButton");
    const saveButton = document.getElementById("saveButton");
    const deleteButton = document.getElementById("deleteButton");
    const saveAnnotation = document.getElementById("saveAnnotation");
    const clearAnnotation = document.getElementById("clearAnnotation");
    const textarea = document.getElementById("myTextarea");
    // const path = window.location.pathname.split("/").filter(Boolean);
    const path = window.location.pathname.split("/");
    const token = path[1];
    const penColor = document.getElementById("penColor");
    const undoButton = document.getElementById("undoAnnotation");
    const refreshButton = document.getElementById("refreshAnnotation");
    const storedVersion = localStorage.getItem("lastSeenVersion");


// ✅ Unified item selection handler
document.addEventListener("click", (e) => {
    console.log("addEventListener( 44");
  // look for the nearest .select-item element
  const zone = e.target.closest(".select-item");
  if (!zone) return; // not a click in the clickable zone

  // find the corresponding list-sub-item wrapper
  const row = zone.closest(".list-sub-item");
  if (!row) return;

  const surrogate = row.dataset.value;
  const token = row.dataset.token;
  if (!surrogate || !token) return;

  // skip if already active item
  if (row.classList.contains("active") && window.currentSurrogate == surrogate) return;

  const container = document.getElementById(`list-${token}`) || null;
  selectItem(surrogate, token, container);
});


document.addEventListener("keydown", (e) => {
  
  //not when in edit mode  
  if (document.body.classList.contains("edit-mode")) return;

  // ignore while typing
  const tag = document.activeElement.tagName;
  if (["INPUT", "TEXTAREA", "SELECT"].includes(tag) || document.activeElement.isContentEditable) return;

  if (e.key !== "ArrowUp" && e.key !== "ArrowDown") return;
  e.preventDefault();

  const current = document.querySelector(".list-sub-item.active");
  if (!current) return;
  
  const dir = e.key === "ArrowDown" ? "next" : "prev";
  let target = dir === "next" ? current.nextElementSibling : current.previousElementSibling;

  // skip non-item siblings
  while (target && !target.classList.contains("list-sub-item")) {
    target = dir === "next" ? target.nextElementSibling : target.previousElementSibling;
  }

  // if none left, jump to another list
  if (!target) {
    const currentList = current.closest(".list-contents");
    const nextList = dir === "next"
      ? currentList?.nextElementSibling
      : currentList?.previousElementSibling;

    if (nextList) {
      const items = nextList.querySelectorAll(".list-sub-item");
      if (items.length) {
        target = dir === "next" ? items[0] : items[items.length - 1];
      }
    }
  }



  // select new target
  if (target) {
    // clear old active highlights
    document.querySelectorAll(".list-sub-item.active").forEach(el => el.classList.remove("active"));

    const surrogate = target.dataset.value;
    const token = target.dataset.token;
    if (!surrogate || !token) return;

    const container = document.getElementById(`list-${token}`) || null;
    selectItem(surrogate, token, container);
    target.scrollIntoView({ block: "nearest", behavior: "smooth" });
  }
});



  const createBtn = document.getElementById("createButton");
  if (createBtn) {
    createBtn.addEventListener("click", () => {
      // Reuse the existing new item creation
      document.getElementById("newButton")?.click();
    });
  }



  // 🔄 Synchronize scrolling between myTextarea and myTextarea2
  const t1 = document.getElementById("myTextarea");
  const t2 = document.getElementById("myTextarea2");
  if (t1 && t2) {
    function syncScroll(source, target) {
      target.scrollTop = source.scrollTop;
      target.scrollLeft = source.scrollLeft;
    }
    t1.addEventListener("scroll", () => syncScroll(t1, t2));
    t2.addEventListener("scroll", () => syncScroll(t2, t1));
  }


//for file upload progress spinner
  const musicInput = document.getElementById("musicFileInput");
  if (musicInput) {
    musicInput.addEventListener("change", function (e) {
      const file = e.target.files[0];
      if (!file) return;

      // get current surrogate (selected item)
      const surrogate = window.currentSurrogate ||
        document.querySelector(".list-sub-item.active")?.dataset.value;

      if (!surrogate) {
        alert("⚠️ Please select an item first.");
        return;
      }

      // decide upload type
      let type = "audio";
      if (file.name.toLowerCase().endsWith(".midi") || file.type === "audio/midi") {
        type = "midi";
      }

      // call your unified upload handler
      handleFileUpload(file, surrogate, type);

      // reset so the same file can be selected again later
      e.target.value = "";
    });
  }




    if (!slider || !searchInput || !editModeToggle || !newButton || !saveButton || !deleteButton || !textarea) {
        console.error("❌ Required UI elements missing from the DOM!");
        return;
    }
    
    
// 🔎 Find/Replace logic
(function setupFindReplace() {
  const toolbar = document.getElementById("findReplaceToolbar");
  const textarea = document.getElementById("myTextarea");
  const textarea2 = document.getElementById("myTextarea2");

  const findInput = document.getElementById("findInput");
  const replaceInput = document.getElementById("replaceInput");
  const findNextBtn = document.getElementById("findNextBtn");
  const replaceBtn = document.getElementById("replaceBtn");
  const replaceAllBtn = document.getElementById("replaceAllBtn");

  let lastIndex = 0;

  function syncValue() {
    if (textarea2) textarea2.value = textarea.value;
  }

  function findNext() {
    const query = findInput.value;
    if (!query) return;
    const text = textarea.value;
    lastIndex = text.indexOf(query, textarea.selectionEnd);
    if (lastIndex === -1) lastIndex = text.indexOf(query, 0);
    if (lastIndex !== -1) {
      textarea.focus();
      textarea.setSelectionRange(lastIndex, lastIndex + query.length);
    }
  }

  function replaceOne() {
    if (textarea.selectionStart !== textarea.selectionEnd) {
      textarea.setRangeText(
        replaceInput.value,
        textarea.selectionStart,
        textarea.selectionEnd,
        "end"
      );
      syncValue();
    }
    findNext();
  }

  function replaceAll() {
    const query = findInput.value;
    if (!query) return;
    textarea.value = textarea.value.split(query).join(replaceInput.value);
    syncValue();
  }

  // Wire buttons
  if (findNextBtn) findNextBtn.addEventListener("click", findNext);
  if (replaceBtn) replaceBtn.addEventListener("click", replaceOne);
  if (replaceAllBtn) replaceAllBtn.addEventListener("click", replaceAll);

  // ⌨️ Keyboard shortcuts
  document.addEventListener("keydown", e => {
       
      
    // Ctrl+H (Win/Linux) or ⌘+H (Mac)
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === "h") {
      e.preventDefault();
      const isHidden = toolbar.style.display === "none" || !toolbar.style.display;
      toolbar.style.display = isHidden ? "flex" : "none";

      if (!isHidden) {
        // closing → restore focus
        textarea.focus();
        return;
      }

      // opening → auto-fill Find box with selection if available
      const selection = textarea.value.substring(
        textarea.selectionStart,
        textarea.selectionEnd
      );
      if (selection) {
        findInput.value = selection;
      }
      findInput.focus();
    }

    // Esc → close toolbar
    if (e.key === "Escape" && toolbar.style.display !== "none") {
      toolbar.style.display = "none";
      textarea.focus();
    }
  });
})();





    // ✅ Helper: ensure edit mode is on, then trigger new item
window.enableEditAndCreateNew = function (token) {
  const editToggle = document.querySelector(".edit-mode-toggle");
  if (editToggle && !editToggle.checked) {
    editToggle.click(); // triggers app-defined logic
    window.editModeWasFromMenu = true;
  }

  // ✅ Store the active list token so newButton knows where to insert
  window.currentListToken = token;

  document.getElementById("newButton")?.click();
};




    
if (window.editModeWasFromMenu) {
  const editToggle = document.querySelector(".edit-mode-toggle");
  if (editToggle && editToggle.checked) {
    editToggle.click(); // turns edit mode OFF using native logic
  }
  window.editModeWasFromMenu = false;
}







      // 📱 Mobile fullscreen helper: adjusts layout + scrolls to hide chrome
      function enableMobileFullscreenBehavior() {
        function updateViewportHeight() {
          const vh = window.innerHeight * 0.01;
          document.documentElement.style.setProperty('--vh', `${vh}px`);
        }
    
        updateViewportHeight();
        window.addEventListener('resize', updateViewportHeight);
        window.addEventListener('orientationchange', updateViewportHeight);
    
        window.addEventListener('load', () => {
          setTimeout(() => window.scrollTo(0, 1), 150);
        });
    
        document.body.addEventListener('click', () => {
          setTimeout(() => window.scrollTo(0, 1), 100);
        });
      }
    
      enableMobileFullscreenBehavior(); // ✅ Call it here


    if (window.appVersion !== storedVersion) {
      localStorage.setItem("lastSeenVersion", window.appVersion);
    
      const banner = document.createElement("div");
      banner.textContent = `🔄 A new version is available. Tap here to update.`;
      banner.style = `
        position: fixed;
        bottom: 0; left: 0; right: 0;
        background: #222; color: #fff;
        text-align: center; padding: 10px;
        font-size: 14px;
        z-index: 9999;
        cursor: pointer;
        font-family: sans-serif;
      `;
      banner.onclick = () => {
        navigator.serviceWorker.getRegistrations().then(regs => {
          regs.forEach(reg => reg.unregister());
          location.reload();
        });
      };
      document.body.appendChild(banner);
    }

    if (window.forceUpdate && !localStorage.getItem("forceUpdateDone")) {
      localStorage.setItem("forceUpdateDone", "1");
      navigator.serviceWorker.getRegistrations().then(regs => {
        regs.forEach(reg => reg.unregister());
        location.reload(true);
      });
    }


    if (penColor) {
    // Load saved color from localStorage
    // const savedColor = localStorage.getItem("penColor");
    // if (savedColor) penColor.value = savedColor;
    
    // Save new color on change
    penColor.addEventListener("input", () => {
      localStorage.setItem("penColor", penColor.value);
    });
    }


    if (saveButton) {
      saveButton.addEventListener("click", () => {
          //showFlashMessage("🔐 testing save.");
        if (!isUserLoggedIn()) {
          showFlashMessage("🔐 You need to log in for this action.");
          return;
        }
        insertData();
        //showFlashMessage("💾 Text saved.");
      });
    }

    if (deleteButton) {
      deleteButton.addEventListener("click", () => {
        if (!isUserLoggedIn()) {
          showFlashMessage("🔐 You need to log in for this action.");
          return;
        }
        const confirmed = confirm("🗑️ This will delete this text. Continue?");
        if (!confirmed) return;
    
        deleteData();
        showFlashMessage("🗑️ Text deleted.");
      });
    }


    if (saveAnnotation) {
      saveAnnotation.addEventListener("click", () => {
        if (!isUserLoggedIn()) {
          showFlashMessage("🔐 You need to log in for this action.");
          return;
        }
        savePerPageAnnotations("✅ Annotations saved.");
      });
    }


    if (clearAnnotation) {
      clearAnnotation.addEventListener("click", async () => {
        if (!isUserLoggedIn()) {
          showFlashMessage("🔐 You need to log in for this action.");
          return;
        }
    
        const confirmed = await showConfirmDialog(
          "🗑️ This will delete your annotations from this PDF. Continue?"
        );
        if (!confirmed) return;
    
        // ✅ Delegate to drawing module (handles user vs owner automatically)
        if (typeof window.deleteAnnotations === "function") {
          await window.deleteAnnotations();
          return;
        }
    
        // 🧩 Fallback (in case drawing module didn’t load)
        document.querySelectorAll(".annotation-canvas").forEach(canvas => {
          const ctx = canvas.getContext("2d");
          ctx.clearRect(0, 0, canvas.width, canvas.height);
        });
    
        if (window.overlayUndoStacks instanceof Map) {
          window.overlayUndoStacks.forEach(stack => (stack.length = 0));
        }
    
        requestAnimationFrame(() => {
          document.querySelectorAll(".annotation-canvas").forEach(c => {
            c.style.display = "none";
            void c.offsetHeight;
            c.style.display = "block";
          });
        });
    
        showFlashMessage("🗑️ Your annotations cleared. Saving...");
        await savePerPageAnnotations("✅ Your annotations deleted.");
      });
    }




    if (undoButton) {
      undoButton.addEventListener("click", () => {
        if (!window.lastActiveOverlay) {
          showFlashMessage("⚠️ No active drawing layer.");
          return;
        }
    
    //    const stack = overlayUndoStacks.get(window.lastActiveOverlay);
        const stack = window.overlayUndoStacks.get(window.lastActiveOverlay);
        const ctx = window.lastActiveOverlay.getContext("2d");
    
        if (stack && stack.length > 0) {
          const prev = stack.pop();
          ctx.putImageData(prev, 0, 0);
        } else {
          showFlashMessage("↩️ Nothing to undo.");
        }
      });
    }


    if (refreshButton) {
      refreshButton.addEventListener("click", () => {
        const surrogate = window.currentSurrogate;
        const text = document.getElementById("myTextarea2")?.value || "";
        const match = text.match(/https:\/\/drive\.google\.com\/file\/d\/([a-zA-Z0-9_-]+)/);
        const driveUrl = match ? match[0] : null;
    
        showFlashMessage("🔄 Reloading PDF & annotations...");
        window.loadPDF(surrogate, driveUrl);
      });
    }


    function isUserLoggedIn() {
      return document.body.classList.contains("logged-in");
    }



async function savePerPageAnnotationsXXXX(successMsg = "✅ Annotations saved.") {
  const overlays = document.querySelectorAll(".annotation-canvas");
  const surrogate = window.currentSurrogate;
  const annotator = window.SESSION_USERNAME || "guest";
  const savePromises = [];

  // --- Helper: always returns a Blob, even if canvas empty ---
  function safeCanvasBlob(canvas, mime = "image/png") {
    return new Promise(resolve => {
      try {
        canvas.toBlob(blob => {
          if (blob) return resolve(blob);
          // fallback transparent 1x1 PNG
          const base64 =
            "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQI12P4DwQACfsD/VE/5gAAAABJRU5ErkJggg==";
          const bytes = Uint8Array.from(atob(base64), c => c.charCodeAt(0));
          resolve(new Blob([bytes], { type: mime }));
        }, mime);
      } catch (e) {
        console.warn("safeCanvasBlob error:", e);
        const bytes = Uint8Array.from(
          atob("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQI12P4DwQACfsD/VE/5gAAAABJRU5ErkJggg=="),
          c => c.charCodeAt(0)
        );
        resolve(new Blob([bytes], { type: mime }));
      }
    });
  }

  for (const overlay of overlays) {
    const page = overlay.dataset.page;
    if (!page) continue;

    const p = new Promise(async resolve => {
      try {
        const blob = await safeCanvasBlob(overlay);

        const el = document.querySelector(`.list-sub-item[data-value="${surrogate}"]`);
        const owner = el?.dataset.owner;
        const fileServer = el?.dataset.fileserver || "justhost";

        if (!owner) {
          console.warn("⚠️ Cannot save annotation — missing owner info.");
          resolve();
          return;
        }

        const saveUrl = window.getAnnotationPath({
          owner,
          annotator,
          surrogate,
          page,
          fileServer,
          action: "save",
          type: annotator === owner ? "base" : "user"
        });

        console.log(
          `✏️ Saving annotation p${page} | surrogate:${surrogate} | owner:${owner} | annotator:${annotator} | server:${fileServer} | blob:${blob.size}`
        );

        // ☁️ Cloudflare R2 upload
        if (fileServer === "cloudflare" || fileServer === "r2" || fileServer === "worker") {
          const res = await fetch(saveUrl, {
            method: "POST",
            headers: { "Content-Type": "image/png" },
            body: blob
          });

          const data = await res.json().catch(() => ({}));
          if (data.status === "success") {
            console.log(`✅ Saved p${page} → Cloudflare R2`);
          } else {
            console.warn(`⚠️ Cloudflare save failed p${page}:`, data.message || data);
          }
        }

        // 🐘 PHP / JustHost upload
        else {
          const formData = new FormData();
          formData.append("annotation", blob, `annotation-${surrogate}-p${page}.png`);
          formData.append("surrogate", surrogate);
          formData.append("page", page);
          formData.append("visibility", "private");
          formData.append("owner", owner);
          formData.append("annotator", annotator);

          const res = await fetch(saveUrl, {
            method: "POST",
            body: formData,
            credentials: "include"
          });

          const data = await res.json().catch(() => ({}));
          if (data.status === "success") {
            console.log(`✅ Saved p${page} → PHP (${owner})`);
          } else {
            console.warn(`❌ Save failed p${page}:`, data.message || data);
          }
        }
      } catch (err) {
        console.error(`❌ Error saving page ${page}:`, err);
      } finally {
        resolve();
      }
    });

    savePromises.push(p);
  }

  // ✅ Wait for all uploads, then clear + refresh cache
  await Promise.all(savePromises);

  console.log(`✅ All annotation pages saved for surrogate ${surrogate}`);

  try {
    if (typeof clearAnnotationCacheFor === "function") {
      await clearAnnotationCacheFor(surrogate);
    }
    if (typeof cacheAnnotations === "function") {
      await cacheAnnotations(surrogate);
    }
  } catch (err) {
    console.warn("⚠️ Cache update failed after save:", err);
  }

  showFlashMessage(successMsg);
}


async function savePerPageAnnotations(successMsg = "✅ Annotations saved.") {
  const overlays = document.querySelectorAll(".annotation-canvas");
  const surrogate = window.currentSurrogate;
  const annotator = window.SESSION_USERNAME || "guest";
  const savePromises = [];

  // --- Helper: always returns a Blob, even if canvas empty ---
  function safeCanvasBlob(canvas, mime = "image/png") {
    return new Promise(resolve => {
      try {
        canvas.toBlob(blob => {
          if (blob) return resolve(blob);
          // fallback transparent 1x1 PNG
          const base64 =
            "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQI12P4DwQACfsD/VE/5gAAAABJRU5ErkJggg==";
          const bytes = Uint8Array.from(atob(base64), c => c.charCodeAt(0));
          resolve(new Blob([bytes], { type: mime }));
        }, mime);
      } catch {
        const bytes = Uint8Array.from(
          atob("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQI12P4DwQACfsD/VE/5gAAAABJRU5ErkJggg=="),
          c => c.charCodeAt(0)
        );
        resolve(new Blob([bytes], { type: mime }));
      }
    });
  }

  for (const overlay of overlays) {
    const page = overlay.dataset.page;
    if (!page) continue;

    const p = new Promise(async resolve => {
      try {
        const blob = await safeCanvasBlob(overlay);

        const el = document.querySelector(`.list-sub-item[data-value="${surrogate}"]`);
        const owner = el?.dataset.owner;
        const fileServer = el?.dataset.fileserver || "justhost";

        if (!owner) {
          console.warn("⚠️ Cannot save annotation — missing owner info.");
          resolve();
          return;
        }

        const saveUrl = window.getAnnotationPath({
          owner,
          annotator,
          surrogate,
          page,
          fileServer,
          action: "save",
          type: annotator === owner ? "base" : "user"
        });

        console.log(
          `✏️ Saving annotation p${page} | surrogate:${surrogate} | owner:${owner} | annotator:${annotator} | server:${fileServer} | blob:${blob.size}`
        );

        // ☁️ Cloudflare / Worker upload
        if (fileServer === "cloudflare" || fileServer === "r2" || fileServer === "worker") {
          const res = await fetch(saveUrl, {
            method: "POST",
            headers: { "Content-Type": "image/png" },
            body: blob
          });

          const data = await res.json().catch(() => ({}));
          if (data.status === "success") {
            console.log(`✅ Saved p${page} → Cloudflare R2`);

            // ✅ Immediately cache the same blob locally
            try {
              const cache = await caches.open("textwhisper-annotations");
              const cacheKey =
                `https://r2-worker.textwhisper.workers.dev/${owner}/annotations/` +
                (annotator === owner
                  ? `annotation-${surrogate}-p${page}.png`
                  : `users/${annotator}/annotation-${surrogate}-p${page}.png`);
              await cache.put(
                cacheKey,
                new Response(blob, { headers: { "Content-Type": "image/png" } })
              );
              console.log("💾 Locally cached new annotation:", cacheKey);
            } catch (cacheErr) {
              console.warn("⚠️ Local cache write failed:", cacheErr);
            }
          } else {
            console.warn(`⚠️ Cloudflare save failed p${page}:`, data.message || data);
          }
        }

        // 🐘 PHP / JustHost upload
        else {
          const formData = new FormData();
          formData.append("annotation", blob, `annotation-${surrogate}-p${page}.png`);
          formData.append("surrogate", surrogate);
          formData.append("page", page);
          formData.append("visibility", "private");
          formData.append("owner", owner);
          formData.append("annotator", annotator);

          const res = await fetch(saveUrl, {
            method: "POST",
            body: formData,
            credentials: "include"
          });

          const data = await res.json().catch(() => ({}));
          if (data.status === "success") {
            console.log(`✅ Saved p${page} → PHP (${owner})`);
          } else {
            console.warn(`❌ Save failed p${page}:`, data.message || data);
          }
        }
      } catch (err) {
        console.error(`❌ Error saving page ${page}:`, err);
      } finally {
        resolve();
      }
    });

    savePromises.push(p);
  }

    // ✅ Wait for all uploads
    await Promise.all(savePromises);
    console.log(`✅ All annotation pages saved for surrogate ${surrogate}`);
    
    showFlashMessage(successMsg);

}


// 🔗 Ensure drawing module can always access save function
window.savePerPageAnnotations = savePerPageAnnotations;    
    
    window.showFlashMessage = function (text, duration = 2000) {
    // function showFlashMessage(text, duration = 2500) {
      const flash = document.createElement("div");
      flash.textContent = text;
    
      // ✨ Centered on screen
      flash.style.position = "fixed";
      flash.style.top = "50%";
      flash.style.left = "50%";
      flash.style.transform = "translate(-50%, -50%)";
    
      // 💅 Style
      flash.style.background = "#222";
      flash.style.color = "#fff";
      flash.style.padding = "12px 20px";
      flash.style.borderRadius = "10px";
      flash.style.boxShadow = "0 4px 12px rgba(0,0,0,0.3)";
      flash.style.zIndex = "9999";
      flash.style.fontSize = "16px";
      flash.style.opacity = "0";
      flash.style.transition = "opacity 0.3s ease-in-out";
    
      document.body.appendChild(flash);
    
      requestAnimationFrame(() => {
        flash.style.opacity = "1";
      });
    
      setTimeout(() => {
        flash.style.opacity = "0";
        setTimeout(() => flash.remove(), 500);
      }, duration);
    }

  

    // Will auto-cache itself if the user previously clicked “📥 Make Available Offline.”
    if (navigator.onLine && token && token.length === 32 && isListFlaggedOffline(token)) {
        console.log("📦 Auto-caching flagged list:", token);
        makeListOffline(token);
    }


    function isListFlaggedOffline(token) {
        const flagged = JSON.parse(localStorage.getItem("offline-enabled-lists") || "[]");
        return flagged.includes(token);
    }




    function updateButtonState() {
      const isEditing = Array.from(document.querySelectorAll(".edit-mode-toggle"))
        .some(toggle => toggle.checked);
    
      const isEnabled = document.body.classList.contains("logged-in") && isEditing;
    
      [newButton, saveButton, deleteButton].forEach(btn => {
        btn.disabled = !isEnabled;
        btn.classList.toggle("active", isEnabled);
      });
    }


    // 🖱 Toggle edit mode
    editModeToggle.forEach(toggle => {
      toggle.addEventListener("change", (e) => {
        const isEditing = e.target.checked;
    
        // Sync all toggles
        editModeToggle.forEach(t => {
          if (t !== e.target) t.checked = isEditing;
        });
    
        document.body.classList.toggle("edit-mode", isEditing);
        textarea.readOnly = !isEditing;
    
        if (isLoggedIn) updateButtonState();
    
        const textToolbar = document.getElementById("textToolbar");
        const drawingPalette = document.getElementById("drawingPalette");
        const activeTabContent = document.querySelector(".main-tab-content.active");
        const isText = activeTabContent?.id === "textTabContent";
        const isPdf = activeTabContent?.id === "pdfTabContent";
        
        if (textToolbar) {
          textToolbar.style.display = (isEditing && isText) ? "flex" : "none";
        }
        
        if (drawingPalette) {
          drawingPalette.style.display = (isEditing && isPdf) ? "flex" : "none";
        }
    
        console.log(`✏️ Edit mode: ${isEditing ? "ON" : "OFF"}`);
      });
    });


    



    // ➕ New button behavior
    
    newButton.addEventListener("click", function () {
      console.log("➕ New button pressed");
    
    let token = window.currentListToken;
    
    // Fallback: find active group if no item selected
    if (!token) {
      const activeGroup = document.querySelector(".group-item.active-list");
      token = activeGroup?.dataset.group || loggedInUserId;
    }

    
      // 🔄 Fallback: try to find any visible list if own list not rendered
      let listContainer = document.getElementById(`list-${token}`);
      if (!listContainer) {
        const fallback = document.querySelector(`[id^="list-"]`);
        if (fallback) {
          listContainer = fallback;
          console.warn("⚠️ Fallback used for list container:", fallback.id);
        }
      }
      if (!listContainer) {
        console.warn("⚠️ List container not found.");
        return;
      }
    
      // 🛑 Prevent duplicate temporary item
      if (listContainer.querySelector(`[data-value="0"]`)) {
        console.log("⚠️ Temporary new item already exists.");
        return;
      }
    
      // ✅ Delegate to a proper function in JSFunctions.js
      window.startNewItem(token);
    
      // ✅ Update browser URL to placeholder
      window.history.pushState({}, "", `/${token}/0`);
    
      updateButtonState();
    });





    // Set initial state
    updateButtonState();
    
    // 🎚 Slider logic
    slider.addEventListener("input", function () {
      const trimValue = this.value;
      console.log(`📏 Adjusting text trim level: ${trimValue}`);
      textTrimmer(trimValue);
    });
    
    // 🔁 Tab switching
    //The tab swiching logik is in JSEventsHeaderTabs.js
    sidebarTabs.forEach(tab => {
      tab.addEventListener("click", function () {
        const targetTab = this.dataset.target;
        console.log(`🔄 Switching to: ${targetTab}`);
    
        SidebartabContents.forEach(content => {
          content.classList.toggle("active", content.id === targetTab);
          content.classList.toggle("hidden", content.id !== targetTab);
        });
    
        sidebarTabs.forEach(btn => btn.classList.remove("active"));
        this.classList.add("active");
    
        if (targetTab === "usersTab") {
          loadUserList();
        } else if (targetTab === "listsTab") {
          loadUserContentLists();
        }
    
        // 🎨 Control toolbar visibility (text vs PDF)
        const textToolbar = document.getElementById("textToolbar");
        const drawingPalette = document.getElementById("drawingPalette");
        const isEditing = document.body.classList.contains("edit-mode");
    

      });
    });


    // 📦 Load initial lists
    // 📦 Load initial lists
    const pathParts = window.location.pathname.split("/");
    const tokenFromUrl = pathParts[1] || null;
    const surrogateFromUrl = pathParts[2] || null;
    
    // ✅ Just use what’s in the URL – do not rewrite it
    
    //Tiltekt #1
    // document.querySelector(".tab-link[data-target='listsTab']")?.click();
    loadUserContentLists(tokenFromUrl, surrogateFromUrl);




    window.loadUserContentLists = loadUserContentLists;

    // 🔍 Enable sidebar search
    bindSidebarSearch();
    console.log("🔍 Search functionality enabled!");
    


  initKeyboardNavigation();
  initSwipeNavigation();
  initEdgeTapNavigation();    
    

      
    //For footer icons
    if (typeof initFooterMenu === "function") {
        initFooterMenu();
    }


    console.log("🚀 Scripts loaded!");

        
});




/* -------------------------------------
   1️⃣  CORE UNIVERSAL NAVIGATE FUNCTION
   ------------------------------------- */
window.navigate = async function(direction) {
    console.log("event navigate 867", direction); 
  // direction = "next" or "prev"
  const isPdfActive = document.getElementById("pdfTabContent")?.classList.contains("active");
  const isTextActive = document.getElementById("textTabContent")?.classList.contains("active");

  // 🧭 If PDF paged view is active, turn page first
  if (window.pagedViewEnabled && isPdfActive && window.pdfState?.pdf) {
    const turned = await window.turnPdfPage(direction);
    if (turned) return;
  }

  // 🧭 Otherwise, navigate between items
  if (direction === "next") selectNextItem();
  else selectPreviousItem();
};


/* -------------------------------
   2️⃣  KEYBOARD NAVIGATION (GLOBAL)
   ------------------------------- */
// function initKeyboardNavigation() {
//   if (window._keyboardBound) return;
//   window._keyboardBound = true;

//   document.addEventListener("keydown", async (e) => {
//     if (e.key === "ArrowRight") await window.navigate("next");
//     else if (e.key === "ArrowLeft") await window.navigate("prev");
//   });
// }


/* -------------------------------
   2️⃣  KEYBOARD NAVIGATION (GLOBAL)
   ------------------------------- */
function initKeyboardNavigation() {
  if (window._keyboardBound) return;
  window._keyboardBound = true;

  document.addEventListener("keydown", async (e) => {
    // 🧠 Don’t navigate while editing text
    if (document.body.classList.contains("edit-mode")) return;

    if (e.key === "ArrowRight") await window.navigate("next");
    else if (e.key === "ArrowLeft") await window.navigate("prev");
  });
}



/* ---------------------------------
   3️⃣  SWIPE NAVIGATION (MOBILE)
   --------------------------------- */
function initSwipeNavigation() {
  let startX = 0, startY = 0, startTime = 0;

  document.addEventListener("touchstart", (e) => {
    if (e.touches.length !== 1) return;
    const t = e.touches[0];
    startX = t.clientX;
    startY = t.clientY;
    startTime = Date.now();
  }, { passive: true });

  document.addEventListener("touchmove", (e) => {
    // 👉 Let vertical scrolls pass through
    const t = e.touches[0];
    const dx = Math.abs(t.clientX - startX);
    const dy = Math.abs(t.clientY - startY);
    if (dy > dx) return; // vertical drag — allow scrolling
    // else: horizontal likely, but still wait for touchend
  }, { passive: true });

  document.addEventListener("touchend", async (e) => {
    if (e.changedTouches.length !== 1) return;
    if (document.body.classList.contains("edit-mode")) return;

    const t = e.changedTouches[0];
    const dx = t.clientX - startX;
    const dy = t.clientY - startY;
    const dt = Date.now() - startTime;

    // ✅ Abort if vertical motion dominates
    if (Math.abs(dy) > Math.abs(dx)) return;

    const longEnough = Math.abs(dx) > 100;
    const fastEnough = Math.abs(dx / dt) > 0.3;
    if (!(longEnough && fastEnough)) return;

    await window.navigate(dx < 0 ? "next" : "prev");
  }, { passive: true });
}



/* --------------------------------------
   4️⃣  EDGE DOUBLE-TAP / DOUBLE-CLICK NAV
   -------------------------------------- */
function initEdgeTapNavigation() {
  const pdfContainer = document.getElementById("pdfTabContent");
  if (!pdfContainer) return;

  let lastTap = 0;
  let tapLock = false;

  function isPointerMouse() {
    return matchMedia("(pointer: fine)").matches;
  }

  async function onEdgeAction(direction) {
    await window.navigate(direction === "right" ? "next" : "prev");
  }

  // 👆 Double-tap on touch
  pdfContainer.addEventListener("touchend", async (e) => {
    const now = Date.now();
    const tapGap = now - lastTap;
    lastTap = now;

    const isDrawingMode =
      document.body.classList.contains("edit-mode") &&
      document.querySelector(".nav-link.active")?.dataset.target === "pdfTab";

    if (tapGap > 0 && tapGap < 400 && !tapLock && !isDrawingMode) {
      tapLock = true;
      setTimeout(() => (tapLock = false), 500);

      const x = e.changedTouches[0].clientX;
      const width = window.innerWidth;
      if (x > width * 0.90) await onEdgeAction("right");
      else if (x < width * 0.10) await onEdgeAction("left");
    }
  });

  // 🖱️ Double-click for mouse
  if (isPointerMouse()) {
    pdfContainer.addEventListener("dblclick", async (e) => {
      const isDrawingMode =
        document.body.classList.contains("edit-mode") &&
        document.querySelector(".nav-link.active")?.dataset.target === "pdfTab";
      if (isDrawingMode) {
        showFlashMessage?.("✏️ Drawing mode active — double-tap navigation disabled");
        return;
      }

      const x = e.clientX;
      const width = window.innerWidth;
      if (x > width * 0.75) await onEdgeAction("right");
      else if (x < width * 0.25) await onEdgeAction("left");
    });
  }
}





// JSFunctions.js
window.startNewItem = function (token) {
  const listContainer = document.getElementById(`list-${token}`);
  if (!listContainer) {
    console.warn("⚠️ No list container for token", token);
    return;
  }

  const username    = window.SESSION_USERNAME || "unknown";
  const displayName = window.SESSION_DISPLAY_NAME || username;
  const fileserver = window.fileServer || document.body.dataset.fileserver || "justhost";

  // 🟢 Create placeholder item
  const newItem = document.createElement("div");
  newItem.classList.add("list-sub-item");
  newItem.dataset.value = "0";           // placeholder surrogate
  newItem.dataset.token = token;
  newItem.dataset.owner = username;
  newItem.dataset.itemRoleRank = "90";
  newItem.dataset.canEdit = "1";
  newItem.dataset.fileserver = fileserver;

  newItem.innerHTML = `
    <div class="select-item">
      <div class="item-title">• Untitled</div>
      <div class="item-owner">${displayName} <span class="username">[${username}]</span></div>
    </div>

    <div class="item-menu-wrapper">
      <button class="menu-button" onclick="toggleItemMenu(this); event.stopPropagation();">⋮</button>
      <div class="item-menu-dropdown">
        <div class="list-choice remove-choice" onclick="this.closest('.list-sub-item')?.remove(); event.stopPropagation();">🗑️ Remove</div>
      </div>
    </div>
  `;

  listContainer.prepend(newItem);

  // 🟢 Clear textareas
  document.querySelectorAll(".textareas-container textarea").forEach(el => {
    el.value = "";
    el.readOnly = false;
  });

  // 🟢 Select placeholder
  selectItem(0, token, listContainer);

  // 🟢 Enable edit mode
  const editToggle = document.querySelector(".edit-mode-toggle");
  if (editToggle) {
    editToggle.checked = true;
    document.body.classList.add("edit-mode");
  }

  console.log("✅ New placeholder item created for:", username);
};



/**
 * ✨ showConfirmMessage(text, options)
 * Opens a centered confirmation modal matching app theme.
 * Returns a Promise<boolean>: resolves true (Yes) or false (No).
 */
window.showConfirmDialog = function (
  text,
  { yesText = "Yes", noText = "No", title = "" } = {}
) {
  return new Promise((resolve) => {
    // Prevent multiple modals
    if (document.getElementById("confirmModal")) return resolve(false);

    const overlay = document.createElement("div");
    overlay.id = "confirmModal";
    overlay.style.cssText = `
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,0.4);
      display: flex;
      justify-content: center;
      align-items: center;
      z-index: 100000;
    `;

    const box = document.createElement("div");
    box.style.cssText = `
      background: rgba(40,40,40,0.96);
      color: #fff;
      padding: 22px 28px;
      border-radius: 14px;
      text-align: center;
      font-size: 16px;
      box-shadow: 0 8px 30px rgba(0,0,0,0.5);
      max-width: 320px;
      line-height: 1.5;
      animation: fadeIn 0.25s ease;
    `;

    if (title) {
      const header = document.createElement("h3");
      header.textContent = title;
      header.style.margin = "0 0 10px 0";
      header.style.fontSize = "18px";
      header.style.fontWeight = "600";
      box.appendChild(header);
    }

    const msg = document.createElement("p");
    msg.textContent = text;
    msg.style.margin = "0 0 18px 0";
    box.appendChild(msg);

    const btnWrap = document.createElement("div");
    btnWrap.style.display = "flex";
    btnWrap.style.justifyContent = "center";
    btnWrap.style.gap = "10px";

    const yesBtn = document.createElement("button");
    yesBtn.textContent = yesText;
    yesBtn.style.cssText = `
      background: #007bff;
      color: white;
      border: none;
      padding: 8px 18px;
      border-radius: 8px;
      cursor: pointer;
      font-size: 14px;
    `;
    yesBtn.addEventListener("click", () => {
      overlay.remove();
      resolve(true);
    });

    const noBtn = document.createElement("button");
    noBtn.textContent = noText;
    noBtn.style.cssText = `
      background: #444;
      color: white;
      border: none;
      padding: 8px 18px;
      border-radius: 8px;
      cursor: pointer;
      font-size: 14px;
    `;
    noBtn.addEventListener("click", () => {
      overlay.remove();
      resolve(false);
    });

    btnWrap.appendChild(yesBtn);
    btnWrap.appendChild(noBtn);
    box.appendChild(btnWrap);
    overlay.appendChild(box);
    document.body.appendChild(overlay);
  });
};




