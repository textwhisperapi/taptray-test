logStep("JSEvents.js executed");

//JSEvents.js



// ✅ Show update notice using the built-in flash message system
if ("serviceWorker" in navigator) {
  navigator.serviceWorker.addEventListener("controllerchange", () => {
    const showTwUpdateNotice = (text) => {
      const msg = document.createElement("div");
      msg.innerHTML = `
        <img src="/img/wrt.png" alt="TapTray" style="width:18px;height:18px;vertical-align:middle;margin-right:8px;border-radius:4px;">
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
      const msg = showTwUpdateNotice("TapTray critical update applied. Refreshing...");
      setTimeout(() => window.location.reload(), 1700);
      setTimeout(() => msg.remove(), 1700);
      return;
    }

    const msg = showTwUpdateNotice("TapTray updated to the latest version.");
    setTimeout(() => msg.remove(), 1500);
  });
}




//DOM
document.addEventListener("DOMContentLoaded", function () {
logStep("C: JSEvents init start");    

    function ensurePdfEdgeZoneGlows() {
      let host = document.getElementById("pdfEdgeZoneGlows");
      if (!host) {
        const annotationLabel = window.translations?.annotate || "Annotate";
        const markerLabel = window.translations?.markers || "Markers";
        host = document.createElement("div");
        host.id = "pdfEdgeZoneGlows";
        host.className = "pdf-edge-zone-glows";
        host.innerHTML = `
          <div class="pdf-edge-zone-glow is-annotation" data-short="${annotationLabel}" title="${annotationLabel}" aria-label="${annotationLabel}" aria-hidden="true"></div>
          <div class="pdf-edge-zone-glow is-marker" data-short="${markerLabel}" title="${markerLabel}" aria-label="${markerLabel}" aria-hidden="true"></div>
        `;
        document.body.appendChild(host);
      }
      return host;
    }

    function isPdfGlowTabActive() {
      const pdfContent = document.getElementById("pdfTabContent");
      if (!pdfContent) return false;
      const activeMainTab = document.querySelector(".main-tab-content.active");
      const hasPdfCanvas = !!pdfContent.querySelector(".pdf-page-canvas");
      return activeMainTab === pdfContent && hasPdfCanvas;
    }

    function updatePdfEdgeZoneGlows() {
      const host = ensurePdfEdgeZoneGlows();
      const pdfContent = document.getElementById("pdfTabContent");
      const pdfActive = isPdfGlowTabActive();
      let glowRightPx = 0;
      if (pdfActive && pdfContent) {
        const rect = pdfContent.getBoundingClientRect();
        host.style.top = `${Math.round(rect.top)}px`;
        host.style.left = `${Math.round(rect.left)}px`;
        host.style.width = `${Math.round(rect.width)}px`;
        host.style.height = `${Math.round(rect.height)}px`;
        const hasVerticalOverflow = (pdfContent.scrollHeight || 0) > ((pdfContent.clientHeight || 0) + 2);
        const scrollbarWidth = Math.max(0, (pdfContent.offsetWidth || 0) - (pdfContent.clientWidth || 0));
        if (hasVerticalOverflow && scrollbarWidth > 0) {
          glowRightPx = scrollbarWidth;
        }
      } else {
        host.style.top = "";
        host.style.left = "";
        host.style.width = "";
        host.style.height = "";
      }
      host.style.setProperty("--pdf-edge-glow-right", `${glowRightPx}px`);
      host.classList.toggle("is-visible", pdfActive);
      host.dataset.ready = "1";
    }
    window.updatePdfEdgeZoneGlows = updatePdfEdgeZoneGlows;

    function openPdfRightEdgeAction(clientY) {
      const isPdfNow = isPdfGlowTabActive();
      if (!isPdfNow) return false;
      const xmlActiveInPdf =
        !!window._pdfXmlViewState?.active &&
        !!document.getElementById("pdfTabXmlViewer");
      const viewportH = Math.max(1, window.innerHeight || document.documentElement.clientHeight || 1);
      const footerH = document.getElementById("footerMenu")?.offsetHeight || 40;
      const usableBottom = Math.max(0, viewportH - footerH);
      const lowerMarkerZoneTop = Math.round(usableBottom * 0.75);
      if (Number(clientY || 0) >= lowerMarkerZoneTop) {
        window.initPdfMarkerUi?.();
        window.showPdfMarkerPanel?.();
        return true;
      }
      const toggles = Array.from(document.querySelectorAll(".edit-mode-toggle"));
      const primary = toggles.find(t => !t.disabled) || toggles[0];
      if (!primary) return false;
      if (!primary.checked) {
        primary.checked = true;
        primary.dispatchEvent(new Event("change", { bubbles: true }));
      }
      if (!xmlActiveInPdf) {
        window.initDrawingPalette?.();
        window.toggleDrawingPalette?.();
        const palette = document.getElementById("drawingPalette");
        if (palette) {
          palette.classList.add("show");
          palette.style.display = "flex";
          window.updateDrawingPaletteCompactMode?.(palette);
          window.ensureDrawingPaletteInBounds?.({ preferDefault: false });
        }
      }
      return true;
    }

    ensurePdfEdgeZoneGlows();
    updatePdfEdgeZoneGlows();
    document.addEventListener("pointerdown", (e) => {
      const glow = e.target.closest?.(".pdf-edge-zone-glow");
      if (!glow) return;
      if (openPdfRightEdgeAction(Number(e.clientY || 0))) {
        e.preventDefault();
        e.stopPropagation();
      }
    }, true);
    document.addEventListener("click", () => {
      requestAnimationFrame(updatePdfEdgeZoneGlows);
    }, true);
    window.addEventListener("resize", updatePdfEdgeZoneGlows);
    document.getElementById("pdfTabContent")?.addEventListener("scroll", updatePdfEdgeZoneGlows, { passive: true });

    const isLoggedIn = document.body.classList.contains("logged-in");
    if (!isLoggedIn) {
      window.twSetFileManagerButtonVisible?.(false);
    }

    const slider = document.getElementById("b");
    const searchInput = document.getElementById("searchSidebar");
    const sidebarTabs = document.querySelectorAll(".tab-link");
    const SidebartabContents = document.querySelectorAll(".sidebar-tab-content");

    //const editModeToggle = document.getElementById("editModeToggle");
    const editModeToggle = document.querySelectorAll(".edit-mode-toggle");
    const playModeToggle = document.querySelectorAll(".play-mode-toggle");
    const initialEditMode = Array.from(editModeToggle).some(toggle => toggle.checked);
    localStorage.setItem("twEditMode", initialEditMode ? "1" : "0");
    const savedPlayMode = localStorage.getItem("twPlayMode") === "1";
    window.twSetPlayMode = function twSetPlayMode(isPlayMode) {
      const next = !!isPlayMode && isLoggedIn;
      playModeToggle.forEach(t => { t.checked = next; });
      window.twPlayMode = next;
      localStorage.setItem("twPlayMode", next ? "1" : "0");
      window.updatePdfPlayPauseUi?.();
      if (next) {
        window.twHandlePlayModeEnabled?.();
      } else {
        window.twHandlePlayModeDisabled?.();
      }
    };
    window.twSetPlayMode(isLoggedIn ? savedPlayMode : false);

    const newButton = document.getElementById("newButton");
    const saveButton = document.getElementById("saveButton");
    const deleteButton = document.getElementById("deleteButton");
    
    const textRefreshButton = document.getElementById("textRefreshButton");
    const headerRefreshButton = document.getElementById("headerRefreshBtn");
    const toggleCommentVisibilityBtn = document.getElementById("toggleCommentVisibilityBtn");
    const exportPdfButton = document.getElementById("exportPdfButton");
    const printTextButton = document.getElementById("printTextButton");
    
    const saveAnnotation = document.getElementById("saveAnnotation");
    const clearAnnotation = document.getElementById("clearAnnotation");
    // const path = window.location.pathname.split("/").filter(Boolean);
    const path = window.location.pathname.split("/");
    const token = path[1];
    const penColor = document.getElementById("penColor");
    const undoButton = document.getElementById("undoAnnotation");
    const refreshButton = document.getElementById("refreshAnnotation");
    const storedVersion = localStorage.getItem("lastSeenVersion");




document.addEventListener("keydown", e => {

  if (e.key === "Enter" && e.altKey) {  // Alt+Enter combo
    if (typeof window.saveTextMarks === "function") {
      window.saveTextMarks()
        .catch(err => console.error("❌ Manual saveTextMarks() error:", err));
    } else {
      console.warn("⚠️ saveTextMarks() not loaded yet");
    }
  }
});



// ✅ Unified item expand handler
document.addEventListener("click", (e) => {
  const zone = e.target.closest(".select-item");
  if (!zone) return;

  const row = zone.closest(".list-sub-item");
  if (!row) return;

  const surrogate = row.dataset.value;
  const token = row.dataset.token;
  if (!surrogate || !token) return;

  if (window.innerWidth > 900) {
    const container = document.getElementById(`list-${token}`) || null;
    selectItem(surrogate, token, container);
    return;
  }

  toggleTreeItemExpand(zone, surrogate, token);
});



const toggleCommentPalette = document.getElementById("toggleCommentPalette");
if (toggleCommentPalette) {
  toggleCommentPalette.addEventListener("click", () => {
    // Create palette if needed
    if (!document.getElementById("commentPalette") && typeof window.initCommentPalette === "function") {
      window.initCommentPalette();
    }

    const palette = document.getElementById("commentPalette");
    if (!palette) return;

    // check current visibility
    const isVisible = palette.style.display === "flex" || getComputedStyle(palette).display === "flex";

    // ✅ correct direction: if visible → hide; if hidden → show
    if (isVisible) {
      palette.style.display = "none";
      toggleCommentPalette.classList.remove("active");
    } else {
      palette.style.display = "flex";
      toggleCommentPalette.classList.add("active");
    }
  });
}




function keepSidebarItemVisible(itemEl) {
  if (!itemEl) return;
  const scroller =
    itemEl.closest(".scrollable-list-area") ||
    document.querySelector("#listsTab .scrollable-list-area");
  if (!scroller) return;

  const itemRect = itemEl.getBoundingClientRect();
  const scrollerRect = scroller.getBoundingClientRect();
  const pad = 8;

  if (itemRect.bottom > scrollerRect.bottom - pad) {
    scroller.scrollTop += itemRect.bottom - (scrollerRect.bottom - pad);
  } else if (itemRect.top < scrollerRect.top + pad) {
    scroller.scrollTop -= (scrollerRect.top + pad) - itemRect.top;
  }
}

document.addEventListener("keydown", (e) => {
  
  //not when in edit mode  
  if (document.body.classList.contains("edit-mode")) return;
  
  const active = document.activeElement;

  //Prevent list navigation while renaming an item
  if (active && active.classList.contains("rename-item-input")) {
      return;
  }

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
    keepSidebarItemVisible(target);
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
  
    // 🩹 Prevent initial double-render: clear server-side content
    // if (t1) t1.innerHTML = "";
    // if (t2) t2.innerHTML = "";  
  
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




    if (!searchInput || !editModeToggle) {
        console.error("❌ Required UI elements missing from the DOM!");
        return;
    }
    
    
// 🔎 Find/Replace logic
(function setupFindReplace() {
  const toolbar = document.getElementById("findReplaceToolbar");
  const textarea = document.getElementById("myTextarea");
  const textarea2 = document.getElementById("myTextarea2");
  if (!toolbar || !textarea) return;

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
          const viewportHeight = window.visualViewport && window.visualViewport.height
            ? window.visualViewport.height
            : window.innerHeight;
          const vh = viewportHeight * 0.01;
          document.documentElement.style.setProperty('--vh', `${vh}px`);

          const footerEl = document.querySelector(".mobile-footer-menu");
          if (footerEl) {
            const footerHeight = Math.max(0, Math.round(footerEl.getBoundingClientRect().height));
            if (footerHeight > 0) {
              document.documentElement.style.setProperty("--app-footer-height", `${footerHeight}px`);
            }
          }
        }
    
        updateViewportHeight();
        window.addEventListener('resize', updateViewportHeight);
        window.addEventListener('orientationchange', updateViewportHeight);
        if (window.visualViewport) {
          window.visualViewport.addEventListener('resize', updateViewportHeight);
          window.visualViewport.addEventListener('scroll', updateViewportHeight);
        }
    
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
    }

    if (window.forceUpdate && !localStorage.getItem("forceUpdateDone")) {
      localStorage.setItem("forceUpdateDone", "1");
      navigator.serviceWorker.getRegistrations().then(regs => {
        regs.forEach(reg => reg.unregister());
        location.reload(true);
      });
    }

// Text toolbar buttons

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
    

    /* 🔄 REFRESH CURRENT TEXT */
    // if (textRefreshButton) {
    //   textRefreshButton.addEventListener("click", () => {
    //     if (window.currentSurrogate) {
    //       window.selectItem?.(window.currentSurrogate, window.currentListToken);
    //     }
    //   });
    // }
    
    const refreshCurrentItem = () => {
      const s = window.currentSurrogate;
      const t = window.currentListToken || null;
      if (!s) return;
      // Re-load the current item (text + comments + drawings)
      window.selectItem?.(s, t, null);
    };

    const reloadCurrentTwPage = () => {
      // Match a normal browser refresh of the current TW URL.
      try {
        sessionStorage.setItem("twActiveMainTab", window.currentActiveTab || "pdfTab");
      } catch (_) {}
      window.location.reload();
    };

    /* 🔄 SIMPLE TEXT REFRESH — reload the current item only */
    if (textRefreshButton) {
      textRefreshButton.addEventListener("click", refreshCurrentItem);
    }
    if (headerRefreshButton) {
      headerRefreshButton.addEventListener("click", reloadCurrentTwPage);
    }

    
    /* 👁 SHOW / HIDE COMMENT BUBBLES */
    if (toggleCommentVisibilityBtn) {
      toggleCommentVisibilityBtn.addEventListener("click", () => {
        window.toggleCommentVisibility?.();
      });
    }
    
    /* 🖨 PRINT TEXT */
    if (printTextButton) {
      printTextButton.addEventListener("click", () => {
        const rawHtml = String(window._T2_RAWHTML || "").trim();
        if (!rawHtml) return;
    
        const w = window.open("", "_blank");
        if (!w) return;
    
        w.document.write(`
          <html>
          <head>
            <title>Print</title>
            <style>
              body { font-family: system-ui; padding: 20px; line-height: 1.6; }
            </style>
          </head>
          <body>${rawHtml}</body>
          </html>
        `);
        w.document.close();
        w.focus();
        w.print();
      });
    }

    if (exportPdfButton) {
      exportPdfButton.addEventListener("click", () => {
        const rawText = String(window._T2_RAWTEXT || "").trim();
        if (!rawText) {
          showFlashMessage?.("⚠️ Nothing to export.");
          return;
        }

        const itemTitle =
          (document.getElementById("selectedItemTitle")?.textContent || "").trim() ||
          "TapTray Item";

        const safeFilename = itemTitle
          .replace(/[\\/:*?"<>|]+/g, "_")
          .replace(/\s+/g, " ")
          .trim()
          .slice(0, 120) || "textwhisper-item";

        const escapePdfText = (s) =>
          String(s || "")
            .replace(/\\/g, "\\\\")
            .replace(/\(/g, "\\(")
            .replace(/\)/g, "\\)")
            .replace(/[^\x20-\x7E]/g, "?");

        const wrapLine = (line, maxLen = 92) => {
          const src = String(line || "");
          if (!src) return [""];
          const out = [];
          let rest = src;
          while (rest.length > maxLen) {
            let cut = rest.lastIndexOf(" ", maxLen);
            if (cut < 1) cut = maxLen;
            out.push(rest.slice(0, cut));
            rest = rest.slice(cut).replace(/^\s+/, "");
          }
          out.push(rest);
          return out;
        };

        const normalized = rawText.replace(/\r\n/g, "\n").replace(/\r/g, "\n");
        const wrappedLines = normalized
          .split("\n")
          .flatMap((ln) => wrapLine(ln, 92));

        const linesPerPage = 54;
        const pages = [];
        for (let i = 0; i < wrappedLines.length; i += linesPerPage) {
          pages.push(wrappedLines.slice(i, i + linesPerPage));
        }
        if (pages.length === 0) pages.push([""]);

        const pdfObjects = [];
        const addObject = (num, body) => {
          pdfObjects.push(`${num} 0 obj\n${body}\nendobj\n`);
        };

        // 1: catalog, 2: pages, 3: font
        addObject(1, "<< /Type /Catalog /Pages 2 0 R >>");
        addObject(3, "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>");

        const pageObjNums = [];
        let nextObj = 4;
        for (const lines of pages) {
          const pageObj = nextObj++;
          const contentObj = nextObj++;
          pageObjNums.push(pageObj);

          const streamLines = [
            "BT",
            "/F1 11 Tf",
            "14 TL",
            "40 800 Td"
          ];
          for (const line of lines) {
            streamLines.push(`(${escapePdfText(line)}) Tj`);
            streamLines.push("T*");
          }
          streamLines.push("ET");
          const stream = `${streamLines.join("\n")}\n`;

          addObject(
            pageObj,
            `<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >> >> /Contents ${contentObj} 0 R >>`
          );
          addObject(
            contentObj,
            `<< /Length ${stream.length} >>\nstream\n${stream}endstream`
          );
        }

        addObject(2, `<< /Type /Pages /Count ${pageObjNums.length} /Kids [${pageObjNums.map(n => `${n} 0 R`).join(" ")}] >>`);

        // Keep object order valid for references (2 depends on pages list built above).
        const ordered = [];
        const byNum = new Map();
        for (const raw of pdfObjects) {
          const num = parseInt(raw, 10);
          byNum.set(num, raw);
        }
        for (let i = 1; i < nextObj; i += 1) {
          if (byNum.has(i)) ordered.push(byNum.get(i));
        }

        let pdf = "%PDF-1.4\n";
        const offsets = [0];
        for (const obj of ordered) {
          offsets.push(pdf.length);
          pdf += obj;
        }
        const xrefStart = pdf.length;
        pdf += `xref\n0 ${offsets.length}\n`;
        pdf += "0000000000 65535 f \n";
        for (let i = 1; i < offsets.length; i += 1) {
          pdf += `${String(offsets[i]).padStart(10, "0")} 00000 n \n`;
        }
        pdf += `trailer\n<< /Size ${offsets.length} /Root 1 0 R >>\nstartxref\n${xrefStart}\n%%EOF`;

        const blob = new Blob([pdf], { type: "application/pdf" });
        const link = document.createElement("a");
        link.href = URL.createObjectURL(blob);
        link.download = `${safeFilename}.pdf`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(link.href), 1000);
      });
    }
    


// PDF annotation buttons

    if (penColor) {
    // Load saved color from localStorage
    // const savedColor = localStorage.getItem("penColor");
    // if (savedColor) penColor.value = savedColor;
    
    // Save new color on change
    penColor.addEventListener("input", () => {
      localStorage.setItem("penColor", penColor.value);
    });
    }


    if (saveAnnotation) {
      saveAnnotation.addEventListener("click", () => {
        if (!isUserLoggedIn()) {
          showFlashMessage("🔐 You need to log in for this action.");
          return;
        }
        savePerPageAnnotations(`✅ ${window.translations?.annotations_saved || "Drawing saved"}.`);
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
    
        showFlashMessage(`🗑️ ${window.translations?.saving_cleared_annotations || "Saving cleared annotations..."}`);
        await savePerPageAnnotations(`✅ ${window.translations?.annotations_cleared || "Drawings cleared"}.`);
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
        const wrapper = window.lastActiveOverlay.closest(".pdf-page-wrapper");
        const signCanvas = wrapper?.querySelector(".annotation-sign-canvas");
        const signCtx = signCanvas?.getContext("2d");
    
        if (stack && stack.length > 0) {
          const prev = stack.pop();
          const restore = window.lastActiveOverlay._restoreUndoState;
          if (typeof restore === "function") {
            restore(prev);
            window.refreshAnnotationSaveGlow?.();
            return;
          }

          // Backward compatible: old entries may be raw ImageData.
          if (prev && prev.overlay) {
            ctx.putImageData(prev.overlay, 0, 0);
            if (signCtx) {
              if (prev.sign) signCtx.putImageData(prev.sign, 0, 0);
              else signCtx.clearRect(0, 0, signCanvas.width, signCanvas.height);
            }
          } else {
            ctx.putImageData(prev, 0, 0);
          }
          window.refreshAnnotationSaveGlow?.();
        } else {
          showFlashMessage("↩️ Nothing to undo.");
          window.refreshAnnotationSaveGlow?.();
        }
      });
    }


    if (refreshButton) {
      refreshButton.addEventListener("click", () => {
        const surrogate = window.currentSurrogate;
        const text = String(window._T2_RAWTEXT || "");
        const match = text.match(/https:\/\/drive\.google\.com\/file\/d\/([a-zA-Z0-9_-]+)/);
        const driveUrl = match ? match[0] : null;
    
        showFlashMessage("🔄 Reloading PDF & annotations...");
        localStorage.setItem("twActiveMainTab", "pdfTab");
        window._pdfForceResetOnNextLoad = true;
        window.loadPDF(surrogate, driveUrl);
      });
    }


    function isUserLoggedIn() {
      return document.body.classList.contains("logged-in");
    }






// async function savePerPageAnnotations(successMsg = "✅ Annotations saved.") {
//   const overlays = document.querySelectorAll(".annotation-canvas");
//   const surrogate = window.currentSurrogate;
//   const annotator = window.SESSION_USERNAME || "guest";
//   const savePromises = [];

//   // --- Helper: always returns a Blob, even if canvas empty ---
//   function safeCanvasBlob(canvas, mime = "image/png") {
//     return new Promise(resolve => {
//       try {
//         canvas.toBlob(blob => {
//           if (blob) return resolve(blob);
//           // fallback transparent 1x1 PNG
//           const base64 =
//             "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQI12P4DwQACfsD/VE/5gAAAABJRU5ErkJggg==";
//           const bytes = Uint8Array.from(atob(base64), c => c.charCodeAt(0));
//           resolve(new Blob([bytes], { type: mime }));
//         }, mime);
//       } catch {
//         const bytes = Uint8Array.from(
//           atob("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQI12P4DwQACfsD/VE/5gAAAABJRU5ErkJggg=="),
//           c => c.charCodeAt(0)
//         );
//         resolve(new Blob([bytes], { type: mime }));
//       }
//     });
//   }

//   for (const overlay of overlays) {
//     const page = overlay.dataset.page;
//     if (!page) continue;

//     const p = new Promise(async resolve => {
//       try {
//         const blob = await safeCanvasBlob(overlay);

//         const el = document.querySelector(`.list-sub-item[data-value="${surrogate}"]`);
//         const owner = el?.dataset.owner;
//         const fileServer = el?.dataset.fileserver || "justhost";

//         if (!owner) {
//           console.warn("⚠️ Cannot save annotation — missing owner info.");
//           resolve();
//           return;
//         }

//         const saveUrl = window.getAnnotationPath({
//           owner,
//           annotator,
//           surrogate,
//           page,
//           fileServer,
//           action: "save",
//           type: annotator === owner ? "base" : "user"
//         });

//           `✏️ Saving annotation p${page} | surrogate:${surrogate} | owner:${owner} | annotator:${annotator} | server:${fileServer} | blob:${blob.size}`
//         );

//         // ☁️ Cloudflare / Worker upload
//         if (fileServer === "cloudflare" || fileServer === "r2" || fileServer === "worker") {
//           const res = await fetch(saveUrl, {
//             method: "POST",
//             headers: { "Content-Type": "image/png" },
//             body: blob
//           });

//           const data = await res.json().catch(() => ({}));
//           if (data.status === "success") {

//             // ✅ Immediately cache the same blob locally
//             try {
//               const cache = await caches.open("textwhisper-annotations");
//               const cacheKey =
//                 `https://r2-worker.textwhisper.workers.dev/${owner}/annotations/` +
//                 (annotator === owner
//                   ? `annotation-${surrogate}-p${page}.png`
//                   : `users/${annotator}/annotation-${surrogate}-p${page}.png`);
//               await cache.put(
//                 cacheKey,
//                 new Response(blob, { headers: { "Content-Type": "image/png" } })
//               );
//             } catch (cacheErr) {
//               console.warn("⚠️ Local cache write failed:", cacheErr);
//             }
//           } else {
//             console.warn(`⚠️ Cloudflare save failed p${page}:`, data.message || data);
//           }
//         }

//         // 🐘 PHP / JustHost upload
//         else {
//           const formData = new FormData();
//           formData.append("annotation", blob, `annotation-${surrogate}-p${page}.png`);
//           formData.append("surrogate", surrogate);
//           formData.append("page", page);
//           formData.append("visibility", "private");
//           formData.append("owner", owner);
//           formData.append("annotator", annotator);

//           const res = await fetch(saveUrl, {
//             method: "POST",
//             body: formData,
//             credentials: "include"
//           });

//           const data = await res.json().catch(() => ({}));
//           if (data.status === "success") {
//           } else {
//             console.warn(`❌ Save failed p${page}:`, data.message || data);
//           }
//         }
//       } catch (err) {
//         console.error(`❌ Error saving page ${page}:`, err);
//       } finally {
//         resolve();
//       }
//     });

//     savePromises.push(p);
//   }

//     // ✅ Wait for all uploads
//     await Promise.all(savePromises);
    
//     showFlashMessage(successMsg);

// }


// // 🔗 Ensure drawing module can always access save function
// window.savePerPageAnnotations = savePerPageAnnotations;    
    
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
    
      [newButton, saveButton, deleteButton].filter(Boolean).forEach(btn => {
        btn.disabled = !isEnabled;
        btn.classList.toggle("active", isEnabled);
      });
    }





    

// 🖱 Toggle edit mode
editModeToggle.forEach(toggle => {
  toggle.addEventListener("change", (e) => {
    const isEditing = e.target.checked;

    if (isEditing) {
      window.capturePdfViewForEditSession?.();
    }

    // ✅ Sync all toggles
    editModeToggle.forEach(t => {
      if (t !== e.target) t.checked = isEditing;
    });
    
    // JS logic flag (the missing piece)
    window.EditMode = isEditing;     
    localStorage.setItem("twEditMode", isEditing ? "1" : "0");

    // ✅ Body + textarea safe checks
    document.body.classList.toggle("edit-mode", isEditing);

    // ✅ NEW: update touch behavior
    updateTouchActionForMode?.();

    if (typeof isLoggedIn !== "undefined" && isLoggedIn && typeof updateButtonState === "function") {
      updateButtonState();
    }

    // ✅ Common UI elements
    const textToolbar = document.getElementById("textToolbar");
    const xmlToolbar = document.getElementById("xmlToolbar");
    const drawingPalette = document.getElementById("drawingPalette");
    const commentPalette = document.getElementById("commentPalette");
    const commentToggleBtn = document.getElementById("toggleCommentPalette");

    const isText = document.getElementById("textTabContent")?.classList.contains("active");
    const isPdf = document.getElementById("pdfTabContent")?.classList.contains("active");

    // === 🧩 Text Toolbar ===
    if (textToolbar) {
      textToolbar.style.display = (isEditing && isText) ? "flex" : "none";
    }
    if (xmlToolbar && (!isEditing || !isPdf)) {
      xmlToolbar.style.display = "none";
    }


    // Single source of truth for palette visibility drawing on pdf or comment on text
    updatePaletteVisibility?.(window.currentActiveTab || (isPdf ? "pdfTab" : (isText ? "textTab" : "")));
    window.twMusicXml?.syncXmlEditToolbar?.(window.currentSurrogate || "");


    // deactivate toggle button if not editing
    if (commentToggleBtn && !isEditing) {
      commentToggleBtn.classList.remove("active");
    }

    // Explicit save on edit-mode OFF so drawings are not lost on mode toggle.
    if (!isEditing) {
      window.autoSaveAnnotations?.({ force: true, reason: "toggle-off" });
    }

  });
});

playModeToggle.forEach(toggle => {
  toggle.addEventListener("change", (e) => {
    window.twSetPlayMode?.(e.target.checked);
  });
});




    // ➕ New button behavior
    
    if (newButton) {
      newButton.addEventListener("click", function () {
    
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
          return;
        }
    
        // ✅ Delegate to a proper function in JSFunctions.js
        window.startNewItem(token);
    
        // ✅ Update browser URL to placeholder
        window.history.pushState({}, "", `/${token}/0`);
    
        updateButtonState();
      });
    }





    // Set initial state
    updateButtonState();
    
    // 🎚 Slider logic
    if (slider) {
      slider.addEventListener("input", function () {
        const trimValue = this.value;
        textTrimmer(trimValue);
      });
    }
    
    // 🔁 Tab switching
    //The tab swiching logik is in JSEventsHeaderTabs.js
    sidebarTabs.forEach(tab => {
      tab.addEventListener("click", function () {
        const targetTab = this.dataset.target;
    
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
    


  initKeyboardNavigation();
  // Keep core swipe navigation enabled on touch devices.
  initSwipeNavigation();
  // Edge double-tap paging must work on touch devices (iPad included).
  initEdgeTapNavigation();
  // Pull-to-refresh is intentionally disabled.
  // Prevent top pull-down from breaking fullscreen on iPad/touch devices too.
  initPdfTopOverscrollGuard();
  // Keep top-tap priority guard limited to fine pointers.
  if (matchMedia("(pointer: fine)").matches) {
    initPdfTopTouchPriorityGuard();
  }
    

      
    //For footer icons
    if (typeof initFooterMenu === "function") {
        initFooterMenu();
    }



        
});




/* -------------------------------------
   1️⃣  CORE UNIVERSAL NAVIGATE FUNCTION
   ------------------------------------- */
window.navigate = async function(direction) {
  // direction = "next" or "prev"
  const isPdfActive = document.getElementById("pdfTabContent")?.classList.contains("active");
  const navigateToAdjacentItem = (dir) => {
    if (dir === "next") {
      if (typeof window.selectNextItem === "function") return !!window.selectNextItem();
      if (typeof selectNextItem === "function") return !!selectNextItem();
      return false;
    }
    if (typeof window.selectPreviousItem === "function") return !!window.selectPreviousItem();
    if (typeof selectPreviousItem === "function") return !!selectPreviousItem();
    return false;
  };
  const getAdjacentItem = (dir) => {
    const current =
      document.querySelector(`.list-sub-item.active[data-value="${window.currentSurrogate}"]`) ||
      document.querySelector(`.list-sub-item[data-value="${window.currentSurrogate}"]`);
    if (!current) return null;
    if (dir === "next") {
      let next = current.nextElementSibling;
      while (next && !next.classList.contains("list-sub-item")) next = next.nextElementSibling;
      if (next) return next;
      const nextList = current.closest(".list-contents")?.nextElementSibling;
      return nextList?.querySelector(".list-sub-item") || null;
    }
    let prev = current.previousElementSibling;
    while (prev && !prev.classList.contains("list-sub-item")) prev = prev.previousElementSibling;
    if (prev) return prev;
    const prevList = current.closest(".list-contents")?.previousElementSibling;
    const items = prevList?.querySelectorAll(".list-sub-item");
    return items?.[items.length - 1] || null;
  };

  // 🧭 If PDF paged view is active, turn page first
  if (window.pagedViewEnabled && isPdfActive && window.pdfState?.pdf) {
    const turned = await window.turnPdfPage(direction);
    if (turned === true) return;
    if (turned === null) return; // busy: ignore duplicate gesture
    // Boundary fallback can fire twice on mobile for one gesture.
    const now = Date.now();
    if (window._pagedBoundaryNavAt && now - window._pagedBoundaryNavAt < 380) return;
    window._pagedBoundaryNavAt = now;
    const hasAdjacent = !!getAdjacentItem(direction);
    if (!hasAdjacent) {
      window._pdfNavIntent = null;
      return;
    }
    window.setPdfNavIntent?.(
      direction === "prev" ? "prev_item_last_page" : "next_item_first_page",
      window.pdfState?.surrogate || window.currentSurrogate || null
    );
  }

  // 🧭 Otherwise, navigate between items
  navigateToAdjacentItem(direction);
};



/* -------------------------------
   2️⃣  KEYBOARD NAVIGATION (GLOBAL)
   ------------------------------- */
function initKeyboardNavigation() {
  if (window._keyboardBound) return;
  window._keyboardBound = true;

  document.addEventListener("keydown", async (e) => {
    //Don’t navigate while editing text
    if (document.body.classList.contains("edit-mode")) return;

    const active = document.activeElement;

    //Prevent left/right navigation while renaming an item
    if (active && active.classList.contains("rename-item-input")) {
        return;
    }

    if (
      active &&
      (
        ["INPUT", "TEXTAREA", "SELECT"].includes(active.tagName) ||
        active.isContentEditable ||
        active.closest("#chatContainer")
      )
    ) {
      return;
    }

    if (e.key === "ArrowRight") {
      await window.navigate("next");
    } else if (e.key === "ArrowLeft") {
      await window.navigate("prev");
    }
  });
}



/* ---------------------------------
   3️⃣  SWIPE NAVIGATION (MOBILE)
   --------------------------------- */
function initSwipeNavigation() {
  let startX = 0, startY = 0, startTime = 0;
  let swipeStartEligible = false;
  let thumbStartBottomRight = false;
  let thumbStartBottomLeft = false;
  let thumbIntercept = false;
  let thumbForcedDir = null;
  let suppressSwipeUntil = 0;
  let hadMultiTouchGesture = false;
  let pianoTouchActive = false;
  let lastCornerTurnAt = 0;
  let leftEdgeSidebarTriggered = false;
  let cornerGestureOwned = false;
  let cornerLongPressTimer = 0;
  let cornerLongPressSide = "";
  let cornerHelpShown = false;
  let cornerHelpHideTimer = 0;
  const CORNER_SWIPE_MIN_PX = 52; // short, thumb-friendly corner swipe
  const CORNER_LONG_PRESS_MS = 520;
  const LEFT_EDGE_SIDEBAR_ZONE_PX = 22;
  const LEFT_EDGE_SIDEBAR_ZONE_TOUCH_PX = 46;
  const LEFT_EDGE_OPEN_MIN_DX_PX = 42;
  const LEFT_EDGE_OPEN_MIN_DX_TOUCH_PX = 26;
  const RIGHT_EDGE_EDIT_ZONE_PX = 22;
  const RIGHT_EDGE_OPEN_MIN_DX_PX = 42;
  const CM_TO_PX = 96 / 2.54;
  const CORNER_GUARD_PX = Math.round(CM_TO_PX * 2);
  const IS_COARSE_TOUCH =
    !!window.matchMedia?.("(pointer: coarse)")?.matches ||
    Number(navigator.maxTouchPoints || 0) > 0;
  const getLeftEdgeSidebarZonePx = () =>
    IS_COARSE_TOUCH ? LEFT_EDGE_SIDEBAR_ZONE_TOUCH_PX : LEFT_EDGE_SIDEBAR_ZONE_PX;
  const getLeftEdgeOpenMinDxPx = () =>
    IS_COARSE_TOUCH ? LEFT_EDGE_OPEN_MIN_DX_TOUCH_PX : LEFT_EDGE_OPEN_MIN_DX_PX;
  const isSwipeControlTarget = (target) =>
    !!target?.closest?.(
      "#footerMenu, .mobile-footer-menu, .footer-tab-btn, .footer-tab, .navbar, #sidebarContainer, .sidebar, #pdfModeMenu, #pdfMarginWrapper, #pdfEdgeNavPrev, #pdfEdgeNavNext, #pdfEdgeNavPrevRight, #pdfEdgeNavNextLeft, .menu-button, .item-menu-wrapper, .dropdown-menu, .create-menu, input, textarea, select, button, a, label, [contenteditable='true'], [role='button']"
    );
  const isSwipeBlockedByVisibleEditUi = () => {
    if (!document.body.classList.contains("edit-mode")) return false;
    const drawingPalette = document.getElementById("drawingPalette");
    const commentPalette = document.getElementById("commentPalette");
    const drawingVisible = !!drawingPalette && getComputedStyle(drawingPalette).display !== "none";
    const commentVisible = !!commentPalette && getComputedStyle(commentPalette).display !== "none";
    return drawingVisible || commentVisible;
  };

  function isPdfActive() {
    return document.getElementById("pdfTabContent")?.classList.contains("active");
  }

  function isPdfPagedActive() {
    return (
      isPdfActive() &&
      window.pagedViewEnabled &&
      !!window.pdfState?.pdf
    );
  }

  function getAdjacentListItem(direction) {
    const current =
      document.querySelector(`.list-sub-item.active[data-value="${window.currentSurrogate}"]`) ||
      document.querySelector(`.list-sub-item[data-value="${window.currentSurrogate}"]`);
    if (!current) return null;

    if (direction === "next") {
      let next = current.nextElementSibling;
      while (next && !next.classList.contains("list-sub-item")) next = next.nextElementSibling;
      if (next) return next;
      const nextList = current.closest(".list-contents")?.nextElementSibling;
      return nextList?.querySelector(".list-sub-item") || null;
    }

    let prev = current.previousElementSibling;
    while (prev && !prev.classList.contains("list-sub-item")) prev = prev.previousElementSibling;
    if (prev) return prev;
    const prevList = current.closest(".list-contents")?.previousElementSibling;
    const items = prevList?.querySelectorAll(".list-sub-item");
    return items?.[items.length - 1] || null;
  }

  function canNavigateForSwipe(direction) {
    if (!isPdfPagedActive()) return true;
    const s = window.pdfState || {};
    const total = Number(s.pdf?.numPages || 0);
    const page = Number(s.page || 1);
    if (direction === "next") {
      if (page < total) return true;
      return !!getAdjacentListItem("next");
    }
    if (page > 1) return true;
    return !!getAdjacentListItem("prev");
  }

  async function navLikeLowerArrow(direction) {
    const dir = direction === "next" ? 1 : -1;
    const navigateToAdjacentItem = (step) => {
      if (step === "next") {
        if (typeof window.selectNextItem === "function") return !!window.selectNextItem();
        if (typeof selectNextItem === "function") return !!selectNextItem();
        return false;
      }
      if (typeof window.selectPreviousItem === "function") return !!window.selectPreviousItem();
      if (typeof selectPreviousItem === "function") return !!selectPreviousItem();
      return false;
    };
    if (isPdfPagedActive() && typeof window.handlePdfEdgeNav === "function") {
      await window.handlePdfEdgeNav(dir);
      return;
    }
    if (isPdfActive() && !window.pagedViewEnabled) {
      window.setPdfNavIntent?.(
        direction === "next" ? "next_item_top" : "prev_item_top",
        window.currentSurrogate || window.pdfState?.surrogate || null
      );
      navigateToAdjacentItem(direction);
      return;
    }
    await window.navigate(direction);
  }

  async function pageTurnLikeEdge(direction) {
    const dir = direction === "next" ? 1 : -1;
    // Corner swipe must follow exactly the same path as edge arrow buttons.
    if (isPdfActive() && typeof window.handlePdfEdgeNav === "function") {
      await window.handlePdfEdgeNav(dir);
      return;
    }
    await window.navigate(direction);
  }

  // Bottom-right corner mapping:
  // movement up/left (away from corner) => next
  // movement down/right (toward corner) => prev
  function getCornerTurnDir(dxSigned, cornerSide, dySigned = 0) {
    if (cornerSide === "right") {
      return (dxSigned + dySigned) <= 0 ? "next" : "prev";
    }
    if (cornerSide === "left") {
      // For bottom-left thumb use: quick tap or upward/up-left motion means next.
      if (dySigned <= -4) return "next";
      if (dxSigned <= -10 && dySigned <= 8) return "next";
      return "prev";
    }
    return null;
  }

  function getCornerDistance(dxSigned, dySigned) {
    return Math.hypot(dxSigned, dySigned);
  }

  function canUseCornerSwipeGesture() {
    return (
      isPdfActive() &&
      !!window.pdfState?.pdf &&
      !isSwipeBlockedByVisibleEditUi()
    );
  }

  function getCornerOwnership(x, y) {
    if (!canUseCornerSwipeGesture()) return "";
    const w = window.innerWidth || 1;
    const h = window.innerHeight || 1;
    const footerH = document.getElementById("footerMenu")?.offsetHeight || 40;
    if (x >= (w - CORNER_GUARD_PX) && y >= (h - footerH - CORNER_GUARD_PX)) return "right";
    if (x <= CORNER_GUARD_PX && y >= (h - footerH - CORNER_GUARD_PX)) return "left";
    return "";
  }

  function ensureCornerHelpPanel() {
    let panel = document.getElementById("twCornerHelpPanel");
    if (panel) return panel;
    const t = window.translations || {};
    panel = document.createElement("div");
    panel.id = "twCornerHelpPanel";
    panel.className = "tw-corner-help-panel";
    panel.setAttribute("role", "status");
    panel.setAttribute("aria-live", "polite");
    panel.innerHTML = [
      `<div class="tw-corner-help-title">${t.corner_paging || "Corner paging"}</div>`,
      `<div class="tw-corner-help-line">${t.tap_next || "Tap: next"}</div>`,
      `<div class="tw-corner-help-line">${t.swipe_away_from_corner_next || "Swipe away from corner: next"}</div>`,
      `<div class="tw-corner-help-line">${t.swipe_into_corner_previous || "Swipe into corner: previous"}</div>`
    ].join("");
    document.body.appendChild(panel);
    return panel;
  }

  function hideCornerHelpPanel() {
    if (cornerHelpHideTimer) {
      clearTimeout(cornerHelpHideTimer);
      cornerHelpHideTimer = 0;
    }
    const panel = document.getElementById("twCornerHelpPanel");
    if (panel) panel.classList.remove("show", "left", "right");
    cornerHelpShown = false;
    cornerLongPressSide = "";
    window._twCornerHelpVisible = "0";
  }

  function showCornerHelpPanel(side) {
    const panel = ensureCornerHelpPanel();
    panel.classList.remove("left", "right");
    panel.classList.add("show", side === "left" ? "left" : "right");
    cornerHelpShown = true;
    cornerLongPressSide = side;
    window._twCornerHelpVisible = "1";
    suppressSwipeUntil = Date.now() + 700;
    if (cornerHelpHideTimer) clearTimeout(cornerHelpHideTimer);
    cornerHelpHideTimer = window.setTimeout(() => {
      hideCornerHelpPanel();
    }, 4200);
  }

  function cancelCornerLongPress() {
    if (cornerLongPressTimer) {
      clearTimeout(cornerLongPressTimer);
      cornerLongPressTimer = 0;
    }
  }

  function armCornerLongPress(side) {
    cancelCornerLongPress();
    cornerLongPressSide = side;
    cornerLongPressTimer = window.setTimeout(() => {
      cornerLongPressTimer = 0;
      if (!cornerGestureOwned || !cornerLongPressSide) return;
      showCornerHelpPanel(cornerLongPressSide);
    }, CORNER_LONG_PRESS_MS);
  }

  function resetOwnedCornerGesture() {
    cancelCornerLongPress();
    cornerGestureOwned = false;
    window._twCornerGestureOwned = "0";
    thumbStartBottomLeft = false;
    thumbStartBottomRight = false;
    thumbIntercept = false;
    thumbForcedDir = null;
  }

  function tryOpenSidebarFromEdge(dxSigned, dySigned, dtMs) {
    if (window.innerWidth >= 1200) return false;
    const sidebar = document.getElementById("sidebarContainer");
    if (!sidebar || sidebar.classList.contains("show")) return false;
    if (startX > getLeftEdgeSidebarZonePx()) return false;
    if (dtMs <= 0 || dtMs > 900) return false;
    if (dxSigned < getLeftEdgeOpenMinDxPx()) return false;
    if (Math.abs(dySigned) > Math.max(26, Math.abs(dxSigned) * 0.65)) return false;
    window.toggleSidebar?.("left-edge-swipe");
    return true;
  }

  function tryOpenEditPanelFromEdge(dxSigned, dySigned, dtMs) {
    if (window.innerWidth >= 1200) return false;
    const viewportW = Math.max(1, window.innerWidth || document.documentElement.clientWidth || 1);
    const viewportH = Math.max(1, window.innerHeight || document.documentElement.clientHeight || 1);
    const footerH = document.getElementById("footerMenu")?.offsetHeight || 40;
    const usableBottom = Math.max(0, viewportH - footerH);
    const lowerMarkerZoneTop = Math.round(usableBottom * 0.75);
    const startsInBottomRightPagerReserve =
      startX >= (viewportW - CORNER_GUARD_PX) &&
      startY >= (viewportH - footerH - CORNER_GUARD_PX);
    if (cornerGestureOwned || window._twCornerGestureOwned === "1" || getCornerOwnership(startX, startY) || startsInBottomRightPagerReserve) {
      return false;
    }
    if (startX < (viewportW - RIGHT_EDGE_EDIT_ZONE_PX)) return false;
    if (dtMs <= 0 || dtMs > 900) return false;
    // Right-edge inward swipe = move left (negative dx).
    if (dxSigned > -RIGHT_EDGE_OPEN_MIN_DX_PX) return false;
    if (Math.abs(dySigned) > Math.max(26, Math.abs(dxSigned) * 0.65)) return false;

    const isPdfNow = isPdfActive();
    const xmlActiveInPdf =
      isPdfNow &&
      !!window._pdfXmlViewState?.active &&
      !!document.getElementById("pdfTabXmlViewer");

    const wantsMarkerPanel = startY >= lowerMarkerZoneTop;

    if (wantsMarkerPanel) {
      window.initPdfMarkerUi?.();
      window.showPdfMarkerPanel?.();
      return true;
    }

    const toggles = Array.from(document.querySelectorAll(".edit-mode-toggle"));
    const primary = toggles.find(t => !t.disabled) || toggles[0];
    if (!primary) return false;

    if (!primary.checked) {
      primary.checked = true;
      primary.dispatchEvent(new Event("change", { bubbles: true }));
    }

    // On PDF tab, right-edge swipe should reveal annotation palette directly.
    if (isPdfNow && !xmlActiveInPdf) {
      window.initDrawingPalette?.();
      window.toggleDrawingPalette?.();
      const palette = document.getElementById("drawingPalette");
      if (palette) {
        palette.classList.add("show");
        palette.style.display = "flex";
        window.updateDrawingPaletteCompactMode?.(palette);
        window.ensureDrawingPaletteInBounds?.({ preferDefault: false });
      }
    }
    return true;
  }

  function triggerSwipeTurnCue(direction) {
    if (!isPdfPagedActive()) return;
    if (!canNavigateForSwipe(direction)) return;
    const wrapper = document.querySelector("#pdfTabContent .pdf-page-wrapper");
    if (!wrapper) return;
    const cls = direction === "next" ? "pdf-swipe-cue-next" : "pdf-swipe-cue-prev";
    window._pdfSwipeCueDirection = direction;
    // Keep cue active long enough so render-side turn animation can skip duplicate effect.
    window._pdfSwipeCueUntil = Date.now() + 700;
    wrapper.classList.remove("pdf-swipe-cue-next", "pdf-swipe-cue-prev");
    void wrapper.offsetWidth;
    wrapper.classList.add(cls);
    setTimeout(() => wrapper.classList.remove(cls), 180);
  }

  document.addEventListener("touchstart", (e) => {
    if (document.body.classList.contains("edit-mode")) {
      swipeStartEligible = false;
      pianoTouchActive = false;
      resetOwnedCornerGesture();
      return;
    }
    swipeStartEligible = false;
    cornerGestureOwned = false;
    if (e.target?.closest?.("#twPianoDockHost")) {
      pianoTouchActive = true;
      return;
    }
    if (isSwipeControlTarget(e.target)) {
      pianoTouchActive = false;
      return;
    }
    pianoTouchActive = false;
    if (e.touches.length > 1) {
      hadMultiTouchGesture = true;
      // Two-finger gestures (zoom/margin) should never trigger page/item swipe on release.
      suppressSwipeUntil = Date.now() + 520;
      thumbStartBottomRight = false;
      thumbStartBottomLeft = false;
      thumbIntercept = false;
      thumbForcedDir = null;
      return;
    }
    if (e.touches.length !== 1) return;
    swipeStartEligible = true;
    const t = e.touches[0];
    startX = t.clientX;
    startY = t.clientY;
    startTime = Date.now();
    leftEdgeSidebarTriggered = false;
    const ownedCornerSide = getCornerOwnership(startX, startY);
    if (ownedCornerSide) {
      swipeStartEligible = true;
      cornerGestureOwned = true;
      window._twCornerGestureOwned = "1";
      thumbStartBottomRight = ownedCornerSide === "right";
      thumbStartBottomLeft = ownedCornerSide === "left";
      thumbIntercept = false;
      thumbForcedDir = null;
      armCornerLongPress(ownedCornerSide);
      if (e.cancelable) e.preventDefault();
      return;
    }
    const viewportW = Math.max(1, window.innerWidth || document.documentElement.clientWidth || 1);
    const inLeftEdge = startX <= getLeftEdgeSidebarZonePx();
    const inRightEdge = startX >= (viewportW - RIGHT_EDGE_EDIT_ZONE_PX);
    const interactiveTarget = !!e.target?.closest?.("input, textarea, select, button, a, [contenteditable='true']");
    // iPad Safari claims edge swipes very early for history navigation.
    // Preempt default browser edge swipe when an app edge gesture starts.
    if (!interactiveTarget && (inLeftEdge || inRightEdge) && e.cancelable) {
      e.preventDefault();
    }
    thumbIntercept = false;
    thumbForcedDir = null;
    if (canUseCornerSwipeGesture()) {
      const w = window.innerWidth || 1;
      const h = window.innerHeight || 1;
      const footerH = document.getElementById("footerMenu")?.offsetHeight || 40;
      thumbStartBottomRight = startX >= (w - CORNER_GUARD_PX) && startY >= (h - footerH - CORNER_GUARD_PX);
      thumbStartBottomLeft = startX <= CORNER_GUARD_PX && startY >= (h - footerH - CORNER_GUARD_PX);
    } else {
      thumbStartBottomRight = false;
      thumbStartBottomLeft = false;
    }
  }, { passive: false });

  document.addEventListener("touchmove", (e) => {
    if (document.body.classList.contains("edit-mode")) return;
    if (!swipeStartEligible) return;
    if (pianoTouchActive) return;
    if (e.touches.length > 1) {
      hadMultiTouchGesture = true;
      suppressSwipeUntil = Date.now() + 700;
      return;
    }
    if (e.touches.length !== 1) return;
    const t = e.touches[0];
    const dxSigned = t.clientX - startX;
    const dySigned = t.clientY - startY;
    const dx = Math.abs(dxSigned);
    const dy = Math.abs(dySigned);
    const dt = Date.now() - startTime;

    if (cornerGestureOwned) {
      const cornerSide = thumbStartBottomRight ? "right" : (thumbStartBottomLeft ? "left" : "");
      if (cornerSide) {
        const movedEnoughToOwnGesture = getCornerDistance(dxSigned, dySigned) >= 10;
        if (movedEnoughToOwnGesture) cancelCornerLongPress();
        if ((thumbIntercept || movedEnoughToOwnGesture) && dt <= 1200) {
          thumbForcedDir = getCornerTurnDir(dxSigned, cornerSide, dySigned);
          thumbIntercept = true;
        }
      }
      if (e.cancelable) e.preventDefault();
      return;
    }

    // On iPad browser, trigger sidebar during move for reliability.
    if (
      !leftEdgeSidebarTriggered &&
      startX <= getLeftEdgeSidebarZonePx() &&
      dt > 0 &&
      dt <= 900 &&
      dxSigned >= getLeftEdgeOpenMinDxPx() &&
      Math.abs(dySigned) <= Math.max(26, Math.abs(dxSigned) * 0.65)
    ) {
      if (window.innerWidth < 1200) {
        const sidebar = document.getElementById("sidebarContainer");
        if (sidebar && !sidebar.classList.contains("show")) {
          window.toggleSidebar?.("left-edge-swipe-live");
          leftEdgeSidebarTriggered = true;
          suppressSwipeUntil = Date.now() + 260;
        }
      }
      if (e.cancelable) e.preventDefault();
      return;
    }

    // iPad Safari browser can steal left-edge swipe for history navigation.
    // If this looks like our sidebar edge gesture, suppress native page swipe.
    if (
      startX <= getLeftEdgeSidebarZonePx() &&
      dt > 0 &&
      dt <= 900 &&
      dxSigned > 8 &&
      Math.abs(dySigned) <= Math.max(26, Math.abs(dxSigned) * 0.65)
    ) {
      if (e.cancelable) e.preventDefault();
      return;
    }

    // Mirror on right edge: suppress Safari forward-swipe when the gesture
    // matches our inward edit/palette edge swipe.
    const viewportW = Math.max(1, window.innerWidth || document.documentElement.clientWidth || 1);
    if (
      startX >= (viewportW - RIGHT_EDGE_EDIT_ZONE_PX) &&
      dt > 0 &&
      dt <= 900 &&
      dxSigned < -8 &&
      Math.abs(dySigned) <= Math.max(26, Math.abs(dxSigned) * 0.65)
    ) {
      if (e.cancelable) e.preventDefault();
      return;
    }

    // Fast thumb swipe from bottom corners should win over scrolling.
    const cornerSide = thumbStartBottomRight ? "right" : (thumbStartBottomLeft ? "left" : "");
    if (canUseCornerSwipeGesture() && cornerSide) {
      const dt = Date.now() - startTime;
      const movedEnoughToOwnGesture = getCornerDistance(dxSigned, dySigned) >= 10;
      if ((thumbIntercept || movedEnoughToOwnGesture) && dt <= 1200) {
        thumbForcedDir = getCornerTurnDir(dxSigned, cornerSide, dySigned);
        // Intercept scrolling only; execute navigation once on touchend.
        thumbIntercept = true;
        e.preventDefault();
        return;
      }
    }

    // In PDF mode, a clear horizontal swipe should not drag the current
    // page visually before release; treat it as navigation intent only.
    if (isPdfActive() && dx > Math.max(18, dy * 1.15)) {
      if (e.cancelable) e.preventDefault();
      return;
    }

    // Let normal vertical scrolling pass through.
    if (dy > dx) return;
  }, { passive: false });

  document.addEventListener("touchend", async (e) => {
    if (document.body.classList.contains("edit-mode")) {
      swipeStartEligible = false;
      pianoTouchActive = false;
      resetOwnedCornerGesture();
      return;
    }
    if (!swipeStartEligible) return;
    swipeStartEligible = false;
    if (pianoTouchActive) {
      pianoTouchActive = false;
      resetOwnedCornerGesture();
      return;
    }
    if (hadMultiTouchGesture) {
      suppressSwipeUntil = Date.now() + 900;
      if ((e.touches?.length || 0) === 0) hadMultiTouchGesture = false;
      resetOwnedCornerGesture();
      return;
    }
    if (Date.now() < Number(window._twSuppressPagingUntil || 0)) {
      resetOwnedCornerGesture();
      return;
    }
    if (e.changedTouches.length !== 1) {
      resetOwnedCornerGesture();
      return;
    }
    if (Date.now() < suppressSwipeUntil) {
      resetOwnedCornerGesture();
      return;
    }

    const t = e.changedTouches[0];
    const dx = t.clientX - startX;
    const dy = t.clientY - startY;
    const dt = Date.now() - startTime;
    if (cornerHelpShown) {
      resetOwnedCornerGesture();
      return;
    }
    if (leftEdgeSidebarTriggered) {
      leftEdgeSidebarTriggered = false;
      resetOwnedCornerGesture();
      return;
    }
    // Left-edge sidebar swipe should always win (fullscreen or not),
    // and must not be blocked by PDF pinch guards.
    if (tryOpenSidebarFromEdge(dx, dy, dt)) return;
    if (tryOpenEditPanelFromEdge(dx, dy, dt)) return;
    if (isSwipeBlockedByVisibleEditUi()) return;

    // Thumb-style gesture: start near bottom corners.
    if (canUseCornerSwipeGesture()) {
      const cornerSide = thumbStartBottomRight ? "right" : (thumbStartBottomLeft ? "left" : "");
      const startsBottomCorner = !!cornerSide;
      const cornerDistance = getCornerDistance(dx, dy);
      const quickEnough = dt > 0 && dt <= 1200;
      if (cornerGestureOwned && startsBottomCorner) {
        if (Date.now() - lastCornerTurnAt < 180) {
          resetOwnedCornerGesture();
          return;
        }
        const dir =
          thumbForcedDir ||
          (cornerDistance <= 18 ? "next" : getCornerTurnDir(dx, cornerSide, dy));
        triggerSwipeTurnCue(dir);
        lastCornerTurnAt = Date.now();
        resetOwnedCornerGesture();
        await pageTurnLikeEdge(dir);
        return;
      }
      if (cornerSide === "left" && quickEnough && cornerDistance <= 16) {
        if (Date.now() - lastCornerTurnAt < 320) return;
        triggerSwipeTurnCue("next");
        lastCornerTurnAt = Date.now();
        await pageTurnLikeEdge("next");
        return;
      }
      if (startsBottomCorner && cornerDistance >= CORNER_SWIPE_MIN_PX && quickEnough) {
        if (Date.now() - lastCornerTurnAt < 320) return;
        const dir = getCornerTurnDir(dx, cornerSide, dy);
        triggerSwipeTurnCue(dir);
        lastCornerTurnAt = Date.now();
        await pageTurnLikeEdge(dir);
        return;
      }

      // If corner swipe already intercepted on move, force one page action and skip generic swipe routing.
      if (startsBottomCorner && thumbIntercept) {
        if (cornerDistance < CORNER_SWIPE_MIN_PX) return;
        if (Date.now() - lastCornerTurnAt < 320) return;
        const dir =
          thumbForcedDir ||
          getCornerTurnDir(dx, cornerSide, dy);
        triggerSwipeTurnCue(dir);
        lastCornerTurnAt = Date.now();
        await pageTurnLikeEdge(dir);
        return;
      }

      // Started in CT corner but didn't complete CT gesture:
      // do not fall through to middle-swipe item switching.
      if (startsBottomCorner) return;
    }

    if (cornerGestureOwned) return;

    // ✅ Abort if vertical motion dominates
    if (Math.abs(dy) > Math.abs(dx)) return;

    const minSwipePx = IS_COARSE_TOUCH ? 68 : 100;
    const minSwipeVelocity = IS_COARSE_TOUCH ? 0.16 : 0.3;
    const longEnough = Math.abs(dx) > minSwipePx;
    const fastEnough = Math.abs(dx / Math.max(1, dt)) > minSwipeVelocity;
    if (!(longEnough && fastEnough)) return;

    triggerSwipeTurnCue(dx < 0 ? "next" : "prev");
    await navLikeLowerArrow(dx < 0 ? "next" : "prev");
  }, { passive: true });

  document.addEventListener("touchcancel", () => {
    swipeStartEligible = false;
    pianoTouchActive = false;
    hadMultiTouchGesture = false;
    leftEdgeSidebarTriggered = false;
    resetOwnedCornerGesture();
    suppressSwipeUntil = Date.now() + 320;
  }, { passive: true });
}

/* --------------------------------------
   3.4️⃣  STANDALONE PULL-TO-REFRESH (MINIMAL)
   -------------------------------------- */
function initStandalonePullToRefresh() {
  if (document.body.dataset.pullRefreshBound === "1") return;
  document.body.dataset.pullRefreshBound = "1";

  const isStandaloneApp =
    window.matchMedia?.("(display-mode: standalone)")?.matches ||
    window.navigator.standalone === true;
  if (!isStandaloneApp) return;

  let tracking = false;
  let startX = 0;
  let startY = 0;
  let pullDistance = 0;
  let scrollHost = null;

  const reset = () => {
    tracking = false;
    startX = 0;
    startY = 0;
    pullDistance = 0;
    scrollHost = null;
  };

  const getActiveScrollHost = () => {
    const pdf = document.getElementById("pdfTabContent");
    if (pdf?.classList.contains("active")) return pdf;

    const text = document.getElementById("textTabContent");
    if (text?.classList.contains("active")) {
      return text;
    }
    return null;
  };

  const isAtTop = (el) => Number(el?.scrollTop || 0) <= 1;

  document.addEventListener("touchstart", (e) => {
    if (document.body.classList.contains("edit-mode")) return reset();
    if (e.touches.length !== 1) return reset();

    const host = getActiveScrollHost();
    if (!host || !isAtTop(host)) return reset();

    const t = e.touches[0];
    tracking = true;
    startX = t.clientX;
    startY = t.clientY;
    pullDistance = 0;
    scrollHost = host;
  }, { passive: true, capture: true });

  document.addEventListener("touchmove", (e) => {
    if (!tracking) return;
    if (e.touches.length !== 1) return reset();
    if (!scrollHost || !isAtTop(scrollHost)) return;

    const t = e.touches[0];
    const dx = t.clientX - startX;
    const dy = t.clientY - startY;
    if (dy <= 0) return;
    if (Math.abs(dx) > Math.abs(dy) * 1.15) return;

    pullDistance = dy;
    if (dy > 8 && e.cancelable) e.preventDefault();
  }, { passive: false, capture: true });

  document.addEventListener("touchend", () => {
    if (tracking && pullDistance >= 84) {
      window.location.reload();
    }
    reset();
  }, { passive: true, capture: true });

  document.addEventListener("touchcancel", reset, { passive: true, capture: true });
}

/* --------------------------------------
   3.5️⃣  PDF TOP OVERSCROLL GUARD (iPad Safari)
   -------------------------------------- */
function initPdfTopOverscrollGuard() {
  const pdfContainer = document.getElementById("pdfTabContent");
  if (!pdfContainer || pdfContainer.dataset.overscrollGuardBound === "1") return;
  pdfContainer.dataset.overscrollGuardBound = "1";
  const isFullscreenPdfActive = () => {
    const isPdfTabActive = !!document.getElementById("pdfTabContent")?.classList.contains("active");
    const isFull = document.body.classList.contains("app-fullscreen") ||
      !!(document.fullscreenElement || document.webkitFullscreenElement);
    return isPdfTabActive && isFull;
  };

  let startY = 0;
  let startX = 0;
  let startedAtTop = false;
  let touchStartedInPdf = false;

  pdfContainer.addEventListener("touchstart", (e) => {
    if (!isFullscreenPdfActive()) {
      startedAtTop = false;
      touchStartedInPdf = false;
      return;
    }
    if (e.touches.length !== 1) return;
    const t = e.touches[0];
    touchStartedInPdf = true;
    startY = t.clientY;
    startX = t.clientX;
    startedAtTop = (pdfContainer.scrollTop || 0) <= 1;
  }, { passive: true });

  pdfContainer.addEventListener("touchmove", (e) => {
    if (!isFullscreenPdfActive()) return;
    if (!touchStartedInPdf) return;
    if (e.touches.length !== 1) return;
    if (document.body.classList.contains("edit-mode")) return;
    const t = e.touches[0];
    const dy = t.clientY - startY;
    const dx = t.clientX - startX;
    const mostlyVertical = Math.abs(dy) > Math.abs(dx);
    const pullingDown = dy > 10;

    // Prevent iPad/Safari pull-down from stealing fullscreen/chrome state.
    // Allow normal scroll, but if the gesture started at top and pulls down,
    // block immediately so native fullscreen/chrome doesn't get dropped.
    if (startedAtTop && pullingDown && mostlyVertical && e.cancelable) {
      e.preventDefault();
    }
  }, { passive: false });

  pdfContainer.addEventListener("touchend", () => {
    touchStartedInPdf = false;
    startedAtTop = false;
  }, { passive: true });

  pdfContainer.addEventListener("touchcancel", () => {
    touchStartedInPdf = false;
    startedAtTop = false;
  }, { passive: true });
}

/* --------------------------------------
   3.6️⃣  FULLSCREEN PDF TOP-TOUCH PRIORITY
   -------------------------------------- */
function initPdfTopTouchPriorityGuard() {
  const pdfContainer = document.getElementById("pdfTabContent");
  if (!pdfContainer || pdfContainer.dataset.topTouchPriorityBound === "1") return;
  pdfContainer.dataset.topTouchPriorityBound = "1";

  let armed = false;
  let startX = 0;
  let startY = 0;
  let startAt = 0;

  const isFullscreenPdfActive = () => {
    const isPdfActive = !!document.getElementById("pdfTabContent")?.classList.contains("active");
    const isFull = document.body.classList.contains("app-fullscreen") ||
      !!(document.fullscreenElement || document.webkitFullscreenElement);
    return isPdfActive && isFull;
  };

  const getTopBandPx = () => {
    const nav = document.querySelector("nav.navbar");
    const navH = nav?.getBoundingClientRect?.().height || 44;
    return Math.max(48, Math.min(96, Math.round(navH + 8)));
  };

  const toggleEditMode = () => {
    const toggles = Array.from(document.querySelectorAll(".edit-mode-toggle"));
    const primary = toggles[0];
    if (!primary) return;
    const next = !primary.checked;
    primary.checked = next;
    primary.dispatchEvent(new Event("change", { bubbles: true }));
  };

  pdfContainer.addEventListener("touchstart", (e) => {
    if (!isFullscreenPdfActive()) return;
    if (e.touches.length !== 1) return;
    const t = e.touches[0];
    // iPad native fullscreen close control sits top-left; never capture that area.
    if (t.clientX <= 140 && t.clientY <= (getTopBandPx() + 20)) {
      armed = false;
      return;
    }
    if (t.clientY > getTopBandPx()) return;

    armed = true;
    startX = t.clientX;
    startY = t.clientY;
    startAt = Date.now();
    if (e.cancelable) e.preventDefault();
    e.stopPropagation();
  }, { passive: false, capture: true });

  pdfContainer.addEventListener("touchmove", (e) => {
    if (!armed) return;
    const t = e.touches[0];
    if (!t) return;
    const dx = Math.abs(t.clientX - startX);
    const dy = Math.abs(t.clientY - startY);
    if (dx > 14 || dy > 14) {
      armed = false;
      return;
    }
    if (e.cancelable) e.preventDefault();
    e.stopPropagation();
  }, { passive: false, capture: true });

  pdfContainer.addEventListener("touchend", (e) => {
    if (!armed) return;
    armed = false;
    if (!isFullscreenPdfActive()) return;
    if (e.changedTouches.length !== 1) return;
    const t = e.changedTouches[0];
    const dx = Math.abs(t.clientX - startX);
    const dy = Math.abs(t.clientY - startY);
    const dt = Date.now() - startAt;
    const isTap = dx <= 14 && dy <= 14 && dt <= 320;
    if (!isTap) return;

    if (e.cancelable) e.preventDefault();
    e.stopPropagation();
    toggleEditMode();
  }, { passive: false, capture: true });

  pdfContainer.addEventListener("touchcancel", () => {
    armed = false;
  }, { passive: true, capture: true });
}

/* --------------------------------------
   4️⃣  EDGE DOUBLE-TAP / DOUBLE-CLICK NAV
   -------------------------------------- */
function initEdgeTapNavigation() {
  const pdfContainer = document.getElementById("pdfTabContent");
  if (!pdfContainer) return;

  let lastTap = 0;
  let tapLock = false;
  let singleTapTimer = null;
  let touchStartX = 0;
  let touchStartY = 0;
  let touchStartAt = 0;
  let touchStartScrollTop = 0;
  let suppressTapUntil = 0;
  let lastTapInputAt = 0;
  let lastTapInputX = 0;
  let lastTapInputY = 0;

  function isPointerMouse() {
    return matchMedia("(pointer: fine)").matches;
  }

  function isPdfActive() {
    return document.getElementById("pdfTabContent")?.classList.contains("active");
  }

  async function goToNextItemFromDoubleTapEdge() {
    const currentSurrogate = window.currentSurrogate || window.pdfState?.surrogate || null;
    if (isPdfActive()) {
      const intent = (window.pagedViewEnabled && window.pdfState?.pdf)
        ? "next_item_first_page"
        : "next_item_top";
      window.setPdfNavIntent?.(intent, currentSurrogate);
    }

    if (typeof window.selectNextItem === "function") {
      const moved = await window.selectNextItem();
      if (moved) return;
    }
    if (typeof selectNextItem === "function") {
      const moved = await selectNextItem();
      if (moved) return;
    }
    await window.navigate("next");
  }

  async function onEdgeAction(direction) {
    const navDirection = direction === "right" ? "next" : "prev";
    const dir = navDirection === "next" ? 1 : -1;
    if (isPdfActive() && typeof window.handlePdfEdgeNav === "function") {
      await window.handlePdfEdgeNav(dir);
      return;
    }
    await window.navigate(navDirection);
  }

  const getViewportWidth = () => (
    window.visualViewport?.width ||
    window.innerWidth ||
    document.documentElement.clientWidth ||
    0
  );
  const isStandaloneApp =
    window.matchMedia?.("(display-mode: standalone)")?.matches ||
    window.navigator.standalone === true;
  const DOUBLE_TAP_MAX_GAP_MS = isStandaloneApp ? 560 : 400;
  const TAP_MAX_DURATION_MS = isStandaloneApp ? 340 : 280;

  function isEventInsidePdfContainer(e) {
    const path = typeof e.composedPath === "function" ? e.composedPath() : null;
    if (Array.isArray(path) && path.includes(pdfContainer)) return true;
    const target = e.target;
    return !!(target && (target === pdfContainer || pdfContainer.contains(target)));
  }

  function isLikelyDuplicateTapInput(x, y) {
    const now = Date.now();
    const duplicate =
      now - lastTapInputAt <= 64 &&
      Math.hypot(x - lastTapInputX, y - lastTapInputY) <= 20;
    if (!duplicate) {
      lastTapInputAt = now;
      lastTapInputX = x;
      lastTapInputY = y;
    }
    return duplicate;
  }

  async function processEdgeTap({ x, y, target, now, cancel }) {
    if (window._twCornerGestureOwned === "1") return;
    const tapGap = now - lastTap;
    lastTap = now;

    const isDrawingMode =
      document.body.classList.contains("edit-mode") &&
      document.querySelector(".nav-link.active")?.dataset.target === "pdfTab";

    if (target?.closest?.("button, input, select, textarea, a, .pdf-edge-nav-btn, #pdfMarginWrapper")) return;

    const dx = Math.abs(x - touchStartX);
    const dy = Math.abs(y - touchStartY);
    const dt = now - touchStartAt;
    const currentScrollTop = Number(pdfContainer.scrollTop || 0);
    const scrollDelta = Math.abs(currentScrollTop - touchStartScrollTop);
    if (scrollDelta > 6) {
      if (singleTapTimer) {
        clearTimeout(singleTapTimer);
        singleTapTimer = null;
      }
      return;
    }
    const isTap = dx <= 14 && dy <= 14 && dt <= TAP_MAX_DURATION_MS;

    if (tapGap > 0 && tapGap < DOUBLE_TAP_MAX_GAP_MS && !tapLock && !isDrawingMode) {
      const width = getViewportWidth();
      const edgeBand = Math.max(88, Math.min(160, width * 0.14));
      const isEdgeDoubleTap = x >= (width - edgeBand) || x <= edgeBand;
      if (isEdgeDoubleTap) {
        if (singleTapTimer) {
          clearTimeout(singleTapTimer);
          singleTapTimer = null;
        }
        tapLock = true;
        setTimeout(() => (tapLock = false), 500);
        cancel?.();
        await goToNextItemFromDoubleTapEdge();
        return;
      }
      // Non-edge double tap must pass through so PDF center double-tap reset can run.
    }

    // Single-tap near bottom corners turns page.
    if (!isDrawingMode && isTap) {
      const width = getViewportWidth() || 1;
      const height = window.innerHeight || 1;
      const inBottomRightZone = x >= width * 0.82 && y >= height * 0.88;
      const inBottomLeftZone = x <= width * 0.18 && y >= height * 0.88;
      if (inBottomRightZone || inBottomLeftZone) {
        if (singleTapTimer) clearTimeout(singleTapTimer);
        singleTapTimer = setTimeout(async () => {
          singleTapTimer = null;
          await onEdgeAction(inBottomRightZone ? "right" : "left");
        }, 230);
      }
    }
  }

  pdfContainer.addEventListener("touchstart", (e) => {
    if (e.touches.length > 1) {
      // Ignore follow-up touchend after pinch/zoom release.
      suppressTapUntil = Date.now() + 650;
      return;
    }
    if (e.touches.length !== 1) return;
    const t = e.touches[0];
    touchStartX = t.clientX;
    touchStartY = t.clientY;
    touchStartAt = Date.now();
    touchStartScrollTop = Number(pdfContainer.scrollTop || 0);
    if (
      isPdfActive() &&
      !!window.pdfState?.pdf &&
      !document.body.classList.contains("edit-mode")
    ) {
      const width = window.innerWidth || 1;
      const height = window.innerHeight || 1;
      const footerH = document.getElementById("footerMenu")?.offsetHeight || 40;
      const inRightCorner = touchStartX >= (width - CORNER_GUARD_PX) && touchStartY >= (height - footerH - CORNER_GUARD_PX);
      const inLeftCorner = touchStartX <= CORNER_GUARD_PX && touchStartY >= (height - footerH - CORNER_GUARD_PX);
      if (inRightCorner || inLeftCorner) {
        window._twCornerGestureOwned = "1";
      }
    }
    if (window._twCornerGestureOwned === "1" && e.cancelable) {
      e.preventDefault();
    }
  }, { passive: false, capture: true });

  pdfContainer.addEventListener("touchmove", (e) => {
    if (window._twCornerGestureOwned === "1" && e.cancelable) {
      e.preventDefault();
    }
  }, { passive: false, capture: true });

  document.addEventListener("pointerdown", (e) => {
    const panel = document.getElementById("twCornerHelpPanel");
    if (window._twCornerHelpVisible !== "1" || !panel) return;
    if (panel.contains(e.target)) return;
    if (typeof hideCornerHelpPanel === "function") {
      hideCornerHelpPanel();
      return;
    }
    panel.classList.remove("show", "left", "right");
    window._twCornerHelpVisible = "0";
  }, { passive: true, capture: true });

  // iPad Home Screen mode can route touch through Pointer Events only.
  pdfContainer.addEventListener("pointerdown", (e) => {
    if (String(e.pointerType || "") !== "touch") return;
    if (e.isPrimary === false) return;
    touchStartX = Number(e.clientX || 0);
    touchStartY = Number(e.clientY || 0);
    touchStartAt = Date.now();
    touchStartScrollTop = Number(pdfContainer.scrollTop || 0);
  }, { capture: true });

  // 👆 Double-tap on touch
  pdfContainer.addEventListener("touchend", async (e) => {
    if (Date.now() < suppressTapUntil) return;
    if (e.changedTouches.length !== 1) return;
    const touch = e.changedTouches[0];
    const x = Number(touch?.clientX || 0);
    const y = Number(touch?.clientY || 0);
    if (isLikelyDuplicateTapInput(x, y)) return;
    await processEdgeTap({
      x,
      y,
      target: e.target,
      now: Date.now(),
      cancel: () => {
        if (e.cancelable) e.preventDefault();
        e.stopPropagation();
      }
    });
  }, { passive: false, capture: true });

  // iPad Home Screen mode can route touch sequences through Pointer Events.
  pdfContainer.addEventListener("pointerup", async (e) => {
    if (String(e.pointerType || "") !== "touch") return;
    if (e.isPrimary === false) return;
    if (Date.now() < suppressTapUntil) return;
    const x = Number(e.clientX || 0);
    const y = Number(e.clientY || 0);
    if (isLikelyDuplicateTapInput(x, y)) return;
    await processEdgeTap({
      x,
      y,
      target: e.target,
      now: Date.now(),
      cancel: () => {
        if (e.cancelable) e.preventDefault();
        e.stopPropagation();
      }
    });
  }, { capture: true });

  pdfContainer.addEventListener("pointercancel", (e) => {
    if (String(e.pointerType || "") !== "touch") return;
    suppressTapUntil = Date.now() + 320;
  }, { capture: true });

  // Standalone iPad fallback: some touch chains bypass element-level listeners.
  if (isStandaloneApp) {
    document.addEventListener("touchend", async (e) => {
      if (!isEventInsidePdfContainer(e)) return;
      if (Date.now() < suppressTapUntil) return;
      if (e.changedTouches.length !== 1) return;
      const touch = e.changedTouches[0];
      const x = Number(touch?.clientX || 0);
      const y = Number(touch?.clientY || 0);
      if (isLikelyDuplicateTapInput(x, y)) return;
      await processEdgeTap({
        x,
        y,
        target: e.target,
        now: Date.now(),
        cancel: () => {
          if (e.cancelable) e.preventDefault();
          e.stopPropagation();
        }
      });
    }, { passive: false, capture: true });

    document.addEventListener("pointerup", async (e) => {
      if (!isEventInsidePdfContainer(e)) return;
      if (String(e.pointerType || "") !== "touch") return;
      if (e.isPrimary === false) return;
      if (Date.now() < suppressTapUntil) return;
      const x = Number(e.clientX || 0);
      const y = Number(e.clientY || 0);
      if (isLikelyDuplicateTapInput(x, y)) return;
      await processEdgeTap({
        x,
        y,
        target: e.target,
        now: Date.now(),
        cancel: () => {
          if (e.cancelable) e.preventDefault();
          e.stopPropagation();
        }
      });
    }, { capture: true });
  }

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
      if (x > width * 0.75 || x < width * 0.25) {
        await goToNextItemFromDoubleTapEdge();
      }
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

  const groupRow = document.querySelector(`.group-item[data-group="${token}"]`);
  const ownerUsername =
    groupRow?.dataset?.ownerUsername ||
    window.currentListOwnerUsername ||
    window.currentOwner?.username ||
    window.SESSION_USERNAME ||
    "unknown";
  const ownerDisplayName =
    window.currentListOwnerName ||
    (window.currentOwner?.username === ownerUsername ? window.currentOwner?.display_name : "") ||
    ownerUsername;
  const fileserver = window.fileServer || document.body.dataset.fileserver || "justhost";

  // 🟢 Create placeholder item
  const newItem = document.createElement("div");
  newItem.classList.add("list-sub-item");
  newItem.dataset.value = "0";           // placeholder surrogate
  newItem.dataset.token = token;
  newItem.dataset.owner = ownerUsername;
  newItem.dataset.itemRoleRank = "90";
  newItem.dataset.canEdit = "1";
  newItem.dataset.fileserver = fileserver;

  newItem.innerHTML = `
    <div class="select-item">
      <div class="item-title">• Untitled</div>
      <div class="item-owner">${ownerDisplayName} <span class="username">[${ownerUsername}]</span></div>
    </div>

    <div class="item-menu-wrapper">
      <button class="menu-button" onclick="toggleItemMenu(this); event.stopPropagation();">⋮</button>
      <div class="item-menu-dropdown">
        <div class="list-choice remove-choice" onclick="this.closest('.list-sub-item')?.remove(); event.stopPropagation();">🗑️ Remove</div>
      </div>
    </div>
  `;

  listContainer.prepend(newItem);

  // 🟢 Select placeholder
  selectItem(0, token, listContainer);
  window.currentItemOwner = ownerUsername;

  // 🟢 Enable edit mode
  const editToggle = document.querySelector(".edit-mode-toggle");
  if (editToggle) {
    editToggle.checked = true;
    document.body.classList.add("edit-mode");
  }

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




