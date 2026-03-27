logStep("JSEventsHeaderTabs.js executed");
const SIDEBAR_STATE_KEY = "twSidebarOpen";
const FULLSCREEN_TOGGLE_DEBOUNCE_MS = 450;
const FULLSCREEN_NATIVE_FALLBACK_DELAY_MS = 130;

function rememberSidebarState(isOpen) {
  return;
}

function wasSidebarOpen() {
  return true;
}

window.getFullscreenElement = function () {
  return document.fullscreenElement || document.webkitFullscreenElement || null;
};

window.isPseudoFullscreenActive = function () {
  return window.__appPseudoFullscreenActive === "1";
};

window.isFullscreenActive = function () {
  return !!window.getFullscreenElement() || window.isPseudoFullscreenActive();
};

window.setPseudoFullscreen = function (enabled) {
  window.__appPseudoFullscreenActive = enabled ? "1" : "0";
  const isFull = !!window.getFullscreenElement() || enabled;
  const btn = document.querySelector('[data-target="fullscreen"]');
  if (btn) btn.classList.toggle("active", isFull);
  document.body.classList.toggle("app-fullscreen", isFull);
  if (typeof window.applyFullscreenChromeVisibility === "function") {
    window.applyFullscreenChromeVisibility(isFull);
  }
  if (enabled) {
    setTimeout(() => window.scrollTo(0, 1), 60);
  }
};

window.applyFullscreenChromeVisibility = function (isFullscreen) {
  const hide = !!isFullscreen;
  const nodes = document.querySelectorAll("nav.navbar, .top-edit-toolbar");
  const EMPTY_SENTINEL = "__tw_empty__";
  nodes.forEach((el) => {
    if (!el) return;
    if (hide) {
      // Snapshot current inline styles once, then force-hide.
      if (el.dataset.twFsHidden !== "1") {
        const prevDisplay = el.style.getPropertyValue("display");
        const prevPointer = el.style.getPropertyValue("pointer-events");
        el.dataset.twFsPrevDisplay = prevDisplay === "" ? EMPTY_SENTINEL : prevDisplay;
        el.dataset.twFsPrevPointer = prevPointer === "" ? EMPTY_SENTINEL : prevPointer;
      }
      el.style.setProperty("display", "none", "important");
      el.style.setProperty("pointer-events", "none", "important");
      el.dataset.twFsHidden = "1";
    } else {
      // Only restore elements that this fullscreen helper actually hid.
      if (el.dataset.twFsHidden !== "1") return;
      const prevDisplay = el.dataset.twFsPrevDisplay;
      const prevPointer = el.dataset.twFsPrevPointer;
      if (prevDisplay && prevDisplay !== EMPTY_SENTINEL) {
        el.style.setProperty("display", prevDisplay);
      } else {
        el.style.removeProperty("display");
      }
      if (prevPointer && prevPointer !== EMPTY_SENTINEL) {
        el.style.setProperty("pointer-events", prevPointer);
      } else {
        el.style.removeProperty("pointer-events");
      }
      delete el.dataset.twFsHidden;
      delete el.dataset.twFsPrevDisplay;
      delete el.dataset.twFsPrevPointer;
    }
  });
  if (typeof window.ensureFullscreenExitControl === "function") {
    const exitBtn = window.ensureFullscreenExitControl();
    if (exitBtn) {
      exitBtn.style.display = hide ? "inline-flex" : "none";
    }
  }
  window.updatePdfTopChromePositions?.();
};

window.ensureFullscreenExitControl = function () {
  let btn = document.getElementById("appFullscreenExitBtn");
  if (btn) return btn;
  btn = document.createElement("button");
  btn.id = "appFullscreenExitBtn";
  btn.className = "app-fullscreen-exit-btn";
  btn.type = "button";
  btn.setAttribute("aria-label", window.translations?.close_fullscreen || "Close fullscreen");
  btn.title = window.translations?.close_fullscreen || "Close fullscreen";
  btn.textContent = window.translations?.close || "Close";
  btn.style.display = "none";
  btn.addEventListener("click", (e) => {
    e.preventDefault();
    e.stopPropagation();
    const fsBtn = document.querySelector('[data-target="fullscreen"]');
    if (fsBtn) {
      fsBtn.click();
      return;
    }
    if (window.getFullscreenElement()) {
      Promise.resolve(window.exitAppFullscreen?.()).catch(() => {});
    }
    window.setPseudoFullscreen?.(false);
  });
  document.body.appendChild(btn);
  return btn;
};

window.requestAppFullscreen = function () {
  window.ensureFullscreenExitControl?.();
  const candidates = [document.documentElement, document.body].filter(Boolean);
  for (const el of candidates) {
    if (typeof el.requestFullscreen === "function") return Promise.resolve(el.requestFullscreen());
    if (typeof el.webkitRequestFullscreen === "function") return Promise.resolve(el.webkitRequestFullscreen());
  }
  return Promise.reject(new Error("Fullscreen API is not supported in this browser."));
};

window.exitAppFullscreen = function () {
  if (document.exitFullscreen) return document.exitFullscreen();
  if (document.webkitExitFullscreen) return document.webkitExitFullscreen();
  return Promise.resolve();
};

window.enterAppFullscreenMode = function (opts = {}) {
  const iosScrollFallback = !!opts.iosScrollFallback;
  const preferPseudoOnly = !!opts.preferPseudoOnly;
  const preferNativeOnly = !!opts.preferNativeOnly;
  if (preferNativeOnly) {
    return Promise.resolve(window.requestAppFullscreen())
      .then(() => {
        setTimeout(() => {
          if (!window.getFullscreenElement()) {
            console.warn("❌ Native fullscreen was not entered.");
          }
        }, FULLSCREEN_NATIVE_FALLBACK_DELAY_MS);
      })
      .catch((err) => {
        if (err) console.warn("❌ Native fullscreen request failed:", err);
        if (iosScrollFallback) {
          setTimeout(() => window.scrollTo(0, 1), 60);
        }
      });
  }
  window.setPseudoFullscreen(true);
  if (preferPseudoOnly) {
    if (iosScrollFallback) {
      setTimeout(() => window.scrollTo(0, 1), 60);
    }
    return Promise.resolve();
  }
  return Promise.resolve(window.requestAppFullscreen())
    .then(() => {
      setTimeout(() => {
        if (!window.getFullscreenElement()) {
          window.setPseudoFullscreen(true);
        }
      }, FULLSCREEN_NATIVE_FALLBACK_DELAY_MS);
    })
    .catch((err) => {
      if (err) console.warn("❌ Fullscreen request failed:", err);
      window.setPseudoFullscreen(true);
      if (iosScrollFallback) {
        setTimeout(() => window.scrollTo(0, 1), 60);
      }
    });
};

window.exitAppFullscreenMode = function () {
  if (window.getFullscreenElement()) {
    Promise.resolve(window.exitAppFullscreen()).catch((err) => {
      console.warn("❌ Exit fullscreen failed:", err);
    });
  }
  window.setPseudoFullscreen(false);
};

// ✅ Shared sidebar toggle
window.toggleSidebar = function (source = "", event = null) {
  const sidebar = document.getElementById("sidebarContainer");
  if (!sidebar) {
    console.warn("❌ Sidebar not found");
    return;
  }

  if (window.innerWidth < 1200) {
    sidebar.classList.toggle("show");
    rememberSidebarState(sidebar.classList.contains("show"));
    if (event) event.stopPropagation(); // Only stop if explicitly passed
  } else {
  }
};

window.addEventListener("message", function (event) {
  if (event.origin !== window.location.origin) return;
  if (!event.data || event.data.type !== "tw-close-sidebar") return;
  const sidebar = document.getElementById("sidebarContainer");
  if (sidebar && window.innerWidth < 1200) {
    sidebar.classList.remove("show");
    rememberSidebarState(false);
  }
});

window.restoreTapTrayMobileSidebar = function () {
  if (window.innerWidth > 900) return;
  const sidebar = document.getElementById("sidebarContainer");
  if (sidebar) {
    sidebar.classList.add("show");
  }
  document.querySelector('.tab-link[data-target="listsTab"]')?.click();
};

window.closeTapTrayMobileSidebar = function () {
  if (window.innerWidth > 900) return;
  const sidebar = document.getElementById("sidebarContainer");
  if (sidebar) {
    sidebar.classList.remove("show");
    rememberSidebarState(false);
  }
};


document.addEventListener("DOMContentLoaded", function () {
  const sidebar = document.getElementById("sidebarContainer");
  const floatBtn = document.getElementById("floatBtn");
  const toggleButton = document.getElementById("openSidebar");

  if (!sidebar) {
    console.warn("❌ Sidebar container not found");
    return;
  }

  // On first open, default the mobile sidebar to visible. After that,
  // preserve the user's explicit open/closed choice in session storage.
  const pathSegments = window.location.pathname.split("/").filter(Boolean);
  const isRootOrUserOnly = pathSegments.length <= 1;
  const deepLinkParams = new URLSearchParams(window.location.search || "");
  const hasChatDeepLink = !!(deepLinkParams.get("open_chat_token") || "").trim();
  if (window.innerWidth < 1200 && isRootOrUserOnly && !hasChatDeepLink) {
    sidebar.classList.toggle("show", wasSidebarOpen());
  }

  // ✅ Hamburger toggle
  if (toggleButton) {
    toggleButton.addEventListener("click", function (e) {
      window.toggleSidebar("hamburger", e);
    });
  }

  // ✅ ⋮ floating button toggle
  if (floatBtn) {
    floatBtn.addEventListener("click", () => {
      window.toggleSidebar("floatBtn");
    });
  }

// ✅ Click/tap outside closes sidebar (capture to avoid stopPropagation)
document.addEventListener("pointerdown", function (e) {
  if (window.innerWidth >= 1200) return;
  if (!sidebar.classList.contains("show")) return;
  if (sidebar.contains(e.target)) return;
  if (e.target.closest("#openSidebar")) return;
  if (e.target.closest("#floatBtn")) return;
  if (e.target.closest('[data-target="sidebar"]')) return;
  if (e.target.closest("footer")) return;
  sidebar.classList.remove("show");
  rememberSidebarState(false);
}, true);



  // ✅ Double-click inside closes sidebar
  sidebar.addEventListener("dblclick", () => {
    if (sidebar.classList.contains("show")) {
      sidebar.classList.remove("show");
      rememberSidebarState(false);
    }
  });
});



document.addEventListener("DOMContentLoaded", function () {


const TAB_STORAGE_KEY = "twActiveMainTab";
const PDF_META_RETRY_MAX = 20;
const PDF_META_RETRY_DELAY_MS = 120;

function persistActiveMainTabPreference() {
  const current = String(window.currentActiveTab || "");
  if (current === "pdfTab" || current === "textTab") {
    localStorage.setItem(TAB_STORAGE_KEY, current);
    return;
  }
  const pdfActive = !!document.getElementById("pdfTabContent")?.classList.contains("active");
  const textActive = !!document.getElementById("textTabContent")?.classList.contains("active");
  if (pdfActive) localStorage.setItem(TAB_STORAGE_KEY, "pdfTab");
  else if (textActive) localStorage.setItem(TAB_STORAGE_KEY, "textTab");
}

// Single source for the preferred score view mode.
window.getPreferredScoreViewMode = function () {
  return window._pdfXmlModeSticky ? "xml" : "pdf";
};

window.setPreferredScoreViewMode = function (mode) {
  const nextMode = String(mode || "").toLowerCase() === "xml" ? "xml" : "pdf";
  window._pdfXmlModeSticky = nextMode === "xml";
  return nextMode;
};

function loadPdfWhenItemReady(surrogate, attempt = 0) {
  if (!surrogate) return;
  if (window.currentActiveTab !== "pdfTab") return;
  const safeSurrogate = String(surrogate);
  if (String(window._pdfLoadingFor || "") === safeSurrogate) return;

  var el = document.querySelector('.list-sub-item[data-value="' + safeSurrogate + '"]');
  var ownerReady = !!(el && el.dataset && el.dataset.owner);
  if (!ownerReady) {
    if (attempt < PDF_META_RETRY_MAX) {
      setTimeout(function () {
        loadPdfWhenItemReady(safeSurrogate, attempt + 1);
      }, PDF_META_RETRY_DELAY_MS);
    }
    return;
  }

  const pdfContainer = document.getElementById("pdfTabContent");
  const hasRenderedPdf =
    !!pdfContainer?.querySelector(".pdf-page-wrapper, .pdf-page-canvas, #pdfViewerFrame");
  const stateSurrogate = String(window.pdfState?.surrogate || "");
  const renderedForCurrent = hasRenderedPdf && stateSurrogate === safeSurrogate;
  if (String(window.pdfLoadedFor || "") === safeSurrogate && renderedForCurrent) return;

  if (navigator.onLine) {
    if (typeof window.loadPDF === "function") {
      window._pdfLoadingFor = safeSurrogate;
      Promise.resolve(window.loadPDF(safeSurrogate, null))
        .then(function () {
          if (String(window.pdfState?.surrogate || "") === safeSurrogate) {
            window.pdfLoadedFor = safeSurrogate;
          }
        })
        .catch(function () {
          if (String(window.pdfLoadedFor || "") === safeSurrogate) {
            window.pdfLoadedFor = "";
          }
        })
        .finally(function () {
          if (String(window._pdfLoadingFor || "") === safeSurrogate) {
            window._pdfLoadingFor = "";
          }
        });
    }
  } else if (typeof window.loadPDFOffline === "function") {
    window._pdfLoadingFor = safeSurrogate;
    Promise.resolve(window.loadPDFOffline(safeSurrogate))
      .then(function () {
        window.pdfLoadedFor = safeSurrogate;
      })
      .catch(function () {
        if (String(window.pdfLoadedFor || "") === safeSurrogate) {
          window.pdfLoadedFor = "";
        }
      })
      .finally(function () {
        if (String(window._pdfLoadingFor || "") === safeSurrogate) {
          window._pdfLoadingFor = "";
        }
      });
  }
}

function getActiveFooterTabTarget() {
  var active = document.querySelector('.footer-tab-btn.active[data-target]');
  var target = active ? active.getAttribute("data-target") : "";
  return (target === "textTab" || target === "pdfTab") ? target : "";
}

function getActiveContentTabTarget() {
  var textActive = document.getElementById("textTabContent")?.classList.contains("active");
  var pdfActive = document.getElementById("pdfTabContent")?.classList.contains("active");
  if (pdfActive) return "pdfTab";
  if (textActive) return "textTab";
  return "";
}

window.switchTab = function (targetId) {
  if (!targetId) return;
  var wasPdfActive = document.getElementById("pdfTabContent")?.classList.contains("active");

  // --- Header nav (.nav-link) active state ---
  var navLinks = document.querySelectorAll('.nav-link');
  for (var i = 0; i < navLinks.length; i++) {
    navLinks[i].classList.remove('active');
  }
  var navBtn = document.querySelector('.nav-link[data-target="' + targetId + '"]');
  if (navBtn) navBtn.classList.add('active');

  // --- Footer buttons active state (limit to the main content tabs) ---
  var footerTargets = ['textTab', 'pdfTab'];
  for (var j = 0; j < footerTargets.length; j++) {
    var btn = document.querySelector('.footer-tab-btn[data-target="' + footerTargets[j] + '"]');
    if (btn) btn.classList.remove('active');
  }
  if (footerTargets.indexOf(targetId) !== -1) {
    var activeBtn = document.querySelector('.footer-tab-btn[data-target="' + targetId + '"]');
    if (activeBtn) activeBtn.classList.add('active');
  }

  // --- Show the requested tab content ---
  var allContents = document.querySelectorAll('.main-tab-content');
  for (var k = 0; k < allContents.length; k++) {
    allContents[k].classList.remove('active');
  }
  var tabContent = document.getElementById(targetId + 'Content');
  if (tabContent) tabContent.classList.add('active');

  // Release heavy PDF runtime memory when leaving the PDF tab.
  if (wasPdfActive && targetId !== "pdfTab") {
    window.clearPdfState?.();
  }

  // Keep a simple flag for other scripts (e.g., drag-drop overlay)
  window.currentActiveTab = targetId;
  document.body.classList.toggle("pdf-tab-active", targetId === "pdfTab");

  // Persist selected main tab across refreshes (text/pdf only).
  if (targetId === "textTab" || targetId === "pdfTab") {
    localStorage.setItem(TAB_STORAGE_KEY, targetId);
  }

  // --- Toolbars (edit mode only) ---
  var isEditing = document.body.classList.contains('edit-mode');
  var safeSurrogate = String(window.currentSurrogate || "");
  var textToolbar = document.getElementById('textToolbar');
  var drawingPallette = document.getElementById('drawingPallette');

  if (textToolbar) {
    textToolbar.style.display = (targetId === 'textTab' && isEditing) ? 'flex' : 'none';
  }
  if (drawingPallette) {
    drawingPallette.style.display = 'none';
  }

  // --- TapTray products/details tab: render current selection ---
  if (targetId === 'pdfTab') {
    var surrogate = window.currentSurrogate;
    if (surrogate) {
      window.loadPDF?.(surrogate, null);
    } else {
      window.loadPDF?.("", null);
    }
  }
  updatePaletteVisibility(targetId);

  // ✅ Force re-apply text toolbar visibility after tab switch
  if (targetId === 'textTab') {
    var toggles = document.querySelectorAll('.edit-mode-toggle');
    var editingNow = Array.from(toggles).some(t => t.checked);
    if (textToolbar) {
      textToolbar.style.display = editingNow ? 'flex' : 'none';
    }
  }
};

  const hasSelectedItem = !!(window.currentSurrogate && String(window.currentSurrogate) !== "0");
  const savedSessionTab = String(sessionStorage.getItem(TAB_STORAGE_KEY) || "");
  const savedLocalTab = String(localStorage.getItem(TAB_STORAGE_KEY) || "");
  const preferredStartupTab =
    savedSessionTab === "pdfTab" || savedSessionTab === "textTab"
      ? savedSessionTab
      : (savedLocalTab === "pdfTab" || savedLocalTab === "textTab" ? savedLocalTab : "");
  if (preferredStartupTab) {
    window.switchTab(preferredStartupTab);
  } else if (hasSelectedItem) {
    window.switchTab("pdfTab");
  }

  const isTapTrayGuest =
    document.body?.dataset?.appMode === "taptray" &&
    !document.body.classList.contains("logged-in");

  // Keep footer selection and visible content in sync (profile switches can desync).
  var syncingTabs = false;
  function syncTabsIfNeeded() {
    if (syncingTabs) return;
    var homeActive = document.getElementById("homeTabContent")?.classList.contains("active");
    if (homeActive) return; // Do not override Event Planner/Home pseudo-tab.
    var footerTarget = getActiveFooterTabTarget();
    var contentTarget = getActiveContentTabTarget();
    var activeNow = contentTarget || footerTarget;
    if (activeNow) {
      document.body.classList.toggle("pdf-tab-active", activeNow === "pdfTab");
    }
    var desired = footerTarget || contentTarget;
    if (!desired) return;
    if (footerTarget && contentTarget && footerTarget === contentTarget) return;
    syncingTabs = true;
    window.switchTab(desired);
    syncingTabs = false;
  }

  if (!isTapTrayGuest) {
    // Initial delayed sync after other startup scripts settle.
    setTimeout(syncTabsIfNeeded, 120);
    setTimeout(syncTabsIfNeeded, 600);
  } else {
    window.currentActiveTab = "pdfTab";
  }

  // Observe active class mutations on footer/content and auto-heal mismatch.
  var tabObserver = null;
  if (!isTapTrayGuest) {
    tabObserver = new MutationObserver(syncTabsIfNeeded);
    document.querySelectorAll(".footer-tab-btn, #textTabContent, #pdfTabContent").forEach(function (el) {
      tabObserver.observe(el, { attributes: true, attributeFilter: ["class"] });
    });
  }
  window.addEventListener("beforeunload", function () {
    persistActiveMainTabPreference();
    tabObserver?.disconnect();
  });
  document.addEventListener("visibilitychange", function () {
    if (document.visibilityState === "hidden") persistActiveMainTabPreference();
  });


    // ✅ Handle Header Tab Clicks (Text, PDF, Music)
    document.querySelectorAll(".nav-link").forEach(tab => {
        tab.addEventListener("click", function () {
            let targetId = this.getAttribute("data-target");

            if (targetId === "pdfTab") {
              window.setPreferredScoreViewMode?.("pdf");
            }
            switchTab(targetId);
        });
    });

    // ✅ Update Content When Selecting a New Item
    // the selectItem function is in JSFunctions.js

    window.embedGoogleDrivePDF_Back = function (url) {
        let pdfContainer = document.getElementById("pdfTabContent");
        if (!pdfContainer) return console.error("❌ PDF container not found!");

        pdfContainer.innerHTML = `<iframe id="pdfViewerFrame" src="${url.replace("/view", "/preview")}" allow="autoplay"></iframe>`;
    };

    window.embedGoogleDrivePDF = function (url) {
        let pdfContainer = document.getElementById("pdfTabContent");
        if (!pdfContainer) return console.error("❌ PDF container not found!");
        
        pdfContainer.innerHTML = `<iframe id="pdfViewerFrame" src="${url.replace("/view", "/preview")}" allow="autoplay"></iframe>`;
    };
    
    // ✅ Home icon click handler
    document.querySelectorAll(".home-icon").forEach(homeIcon => {
      homeIcon.addEventListener("click", function () {
        const targetUrl = "/" + (window.SESSION_USERNAME || document.body.dataset.loggedInUser || "");
    
        if (window.isFullscreenActive()) {
          const exitPromise = window.getFullscreenElement()
            ? Promise.resolve(window.exitAppFullscreen())
            : Promise.resolve();
          exitPromise.then(() => {
            window.setPseudoFullscreen(false);
            setTimeout(() => {
              window.location.href = targetUrl;
            }, 50);
          }).catch(() => {
            window.setPseudoFullscreen(false);
            window.location.href = targetUrl;
          });
        } else {
          window.location.href = targetUrl;
        }
      });
    });
    

    //Print PDF
    // const printBtn = document.getElementById("printPdfBtn");
    // if (printBtn) {
    //   printBtn.addEventListener("click", () => {
    //     const frame = document.getElementById("pdfViewerFrame");
    //     if (!frame) {
    //       alert("⚠️ No PDF loaded to print.");
    //       return;
    //     }
    //     frame.contentWindow.focus();
    //     frame.contentWindow.print();
    //   });
    // }


});




//--------------------------
//---------------------------

window.initFooterMenu = function () {
  if (window.lucide) {
    lucide.createIcons();
  }

  document.querySelectorAll(".footer-tab-btn").forEach(btn => {
    btn.addEventListener("click", function () {
      const target = btn.dataset.target;


        if (target === "sidebar") {
         window.toggleSidebar("footer");
         return;
        }

        window.closeTapTrayMobileSidebar?.();

        if (target === "chatTab") {
          const chat = document.getElementById("chatContainer");
          const isVisible = chat.style.display !== "none" && chat.style.display !== "";
        
        
        
          if (isVisible) {
            chat.style.display = "none";
            stopChatPolling();
            if (chat.dataset.fullscreenActivated === "1") {
              const fullscreenBtn = document.querySelector('[data-target="fullscreen"]');
              if (fullscreenBtn && window.isFullscreenActive()) fullscreenBtn.click();
              delete chat.dataset.fullscreenActivated;
            }
            const header = document.getElementById("chatHeaderTitle");
            if (header) header.textContent = "List Chat";
            window.restoreTapTrayMobileSidebar?.();
            return;
          }
        
          
        
          // 🔁 Selector menu first if unread
          if (hasUnreadMessages()) {
            showUnreadListSelector();
          } else {
            //always show the list   when opened from footer button
            showUnreadListSelector();  
            //openChatFromCurrentList();
          }

          return;
        }

        if (target === "calendarTab") {
          const ownerToken = window.currentOwnerToken || "";
          const eventUrl = ownerToken
            ? `/ep_event_planner.php?owner=${encodeURIComponent(ownerToken)}`
            : "/ep_event_planner.php";
          if (window.currentSurrogate && window.currentSurrogate !== "0") {
            window.currentSurrogate = "0";
          }
          const openedInHomeTab =
            typeof window.showHomeTab === "function" && window.showHomeTab(eventUrl);
          if (!openedInHomeTab) {
            window.location.href = eventUrl;
          }
          return;
        }

        if (target === "menuOrdersTab") {
          const ordersUrl = "/menu_orders.php";
          if (window.currentSurrogate && window.currentSurrogate !== "0") {
            window.currentSurrogate = "0";
          }
          const openedInHomeTab =
            typeof window.showHomeTab === "function" && window.showHomeTab(ordersUrl);
          if (!openedInHomeTab) {
            window.location.href = ordersUrl;
          }
          return;
        }

        if (target === "reservationsTab") {
          const ownerToken = window.currentOwnerToken || window.currentProfileUsername || "";
          const reservationsUrl = ownerToken
            ? `/rp_reservations.php?owner=${encodeURIComponent(ownerToken)}`
            : "/rp_reservations.php";
          if (window.currentSurrogate && window.currentSurrogate !== "0") {
            window.currentSurrogate = "0";
          }
          const openedInHomeTab =
            typeof window.showHomeTab === "function" && window.showHomeTab(reservationsUrl);
          if (!openedInHomeTab) {
            window.location.href = reservationsUrl;
          }
          return;
        }

        if (target === "menuPreviewTab") {
          const ownerToken = window.currentOwnerToken || "";
          const listToken = window.currentListToken || "";
          const surrogate = window.currentSurrogate || "";
          const previewUrl =
            `/menu_preview.php?owner=${encodeURIComponent(ownerToken)}`
            + (listToken ? `&token=${encodeURIComponent(listToken)}` : "")
            + (surrogate && surrogate !== "0" ? `&surrogate=${encodeURIComponent(surrogate)}` : "");
          const openedInHomeTab =
            typeof window.showHomeTab === "function" && window.showHomeTab(previewUrl);
          if (!openedInHomeTab) {
            window.location.href = previewUrl;
          }
          return;
        }

        if (target === "fullscreen") {
          const now = Date.now();
          const lastToggleAt = Number(window.__twLastFullscreenToggleTs || 0);
          if (now - lastToggleAt < FULLSCREEN_TOGGLE_DEBOUNCE_MS) return;
          window.__twLastFullscreenToggleTs = now;

          const isIos = /iPhone|iPad|iPod/i.test(navigator.userAgent);
          const isStandalone =
            window.navigator.standalone === true ||
            window.matchMedia("(display-mode: standalone)").matches;
          const nativeOnlyFullscreen = isIosSafariBrowser() && !isStandalone;

          if (!window.isFullscreenActive()) {
            window.enterAppFullscreenMode?.({
              iosScrollFallback: isIos && !isStandalone,
              preferNativeOnly: nativeOnlyFullscreen
            });
          } else {
            window.exitAppFullscreenMode?.();
          }
          return;
        }

      if (target === "pdfTab") {
        window.setPreferredScoreViewMode?.("pdf");
      }

      if (typeof switchTab === "function") {
        switchTab(target);
      }
    });
  });

  // Track fullscreen changes for active button state
  const syncFullscreenUiState = () => {
    const hasNativeFullscreen = !!window.getFullscreenElement();
    if (hasNativeFullscreen) {
      window.__twNativeFullscreenEngaged = "1";
    }
    if (!hasNativeFullscreen && window.__twNativeFullscreenEngaged === "1") {
      window.__twNativeFullscreenEngaged = "0";
      window.setPseudoFullscreen(false);
    }
    const isFull = hasNativeFullscreen || window.isPseudoFullscreenActive();
    const btn = document.querySelector('[data-target="fullscreen"]');
    if (btn) btn.classList.toggle("active", isFull);
    document.body.classList.toggle("app-fullscreen", isFull);
    if (typeof window.applyFullscreenChromeVisibility === "function") {
      window.applyFullscreenChromeVisibility(isFull);
    }
  };
  document.addEventListener("fullscreenchange", syncFullscreenUiState);
  document.addEventListener("webkitfullscreenchange", syncFullscreenUiState);
  syncFullscreenUiState();

};


//--------------------------


// Install app

let deferredPrompt = null;
const installAppMenuItem = document.getElementById('installAppMenuItem');

function isStandaloneAppMode() {
  return !!(
    window.matchMedia('(display-mode: standalone)').matches ||
    window.navigator.standalone
  );
}

function updateInstallAppMenuLabel() {
  if (!installAppMenuItem) return;
  const labelText = window.translations?.install_app || "Install App";
  installAppMenuItem.innerHTML = `<i data-lucide="download" class="me-1"></i> ${labelText}`;
  window.lucide?.createIcons?.();
}

function ensureInstallHelpModal() {
  let overlay = document.getElementById("twInstallHelpOverlay");
  if (overlay) return overlay;
  const assetVersion = encodeURIComponent(String(window.appVersion || Date.now()));
  overlay = document.createElement("div");
  overlay.id = "twInstallHelpOverlay";
  overlay.className = "tw-install-help-overlay";
  overlay.setAttribute("aria-hidden", "true");
  overlay.innerHTML = `
    <div class="tw-install-help-card" role="dialog" aria-modal="true" aria-labelledby="twInstallHelpTitle">
      <button type="button" class="tw-install-help-close" aria-label="Close">×</button>
      <div class="tw-install-help-kicker">Install on iPad</div>
      <h3 id="twInstallHelpTitle">Add to Home Screen</h3>
      <p>Safari on iPad does not show a normal install prompt. Use these steps:</p>
      <div class="tw-install-help-steps">
        <div class="tw-install-help-step">
          <div class="tw-install-help-visual tw-install-help-visual-image">
            <img src="/img/iPad%20Add%20to%20home.PNG?v=${assetVersion}" alt="Tap the Share button in Safari.">
          </div>
          <div class="tw-install-help-step-num">1</div>
          <div class="tw-install-help-step-text">Tap the Share button.</div>
          <div class="tw-install-help-step-num">2</div>
          <div class="tw-install-help-step-text">Scroll down and select Add to Home Screen.</div>
        </div>
        <div class="tw-install-help-step">
          <div class="tw-install-help-visual tw-install-help-visual-image">
            <img src="/img/iPad%20add%20to%20home%20confirm.PNG?v=${assetVersion}" alt="Tap Add.">
          </div>
          <div class="tw-install-help-step-num">3</div>
          <div class="tw-install-help-step-text">Tap Add.</div>
        </div>
      </div>
      <div class="tw-install-help-actions">
        <button type="button" class="tw-install-help-primary">OK</button>
      </div>
    </div>
  `;
  document.body.appendChild(overlay);
  const close = () => {
    overlay.classList.remove("is-open");
    overlay.setAttribute("aria-hidden", "true");
  };
  overlay.addEventListener("click", (e) => {
    if (e.target === overlay || e.target.closest(".tw-install-help-close") || e.target.closest(".tw-install-help-primary")) {
      close();
    }
  });
  return overlay;
}

function showInstallHelpModal() {
  const overlay = ensureInstallHelpModal();
  overlay.classList.add("is-open");
  overlay.setAttribute("aria-hidden", "false");
}

// Capture when the browser says app is installable
window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault();
  deferredPrompt = e;
  if (installAppMenuItem) installAppMenuItem.style.display = 'block';
  updateInstallAppMenuLabel();
});

// Hide item if already installed
if (isStandaloneAppMode()) {
  if (installAppMenuItem) installAppMenuItem.style.display = 'none';
} else {
  updateInstallAppMenuLabel();
}

function isIosSafariBrowser() {
  const ua = navigator.userAgent || "";
  const isIosDevice = /iPhone|iPad|iPod/i.test(ua) || (navigator.platform === "MacIntel" && navigator.maxTouchPoints > 1);
  const isSafari = /Safari/i.test(ua) && !/CriOS|FxiOS|EdgiOS|OPiOS/i.test(ua);
  return isIosDevice && isSafari;
}

// When user clicks Install App
installAppMenuItem?.addEventListener('click', async () => {
  if (deferredPrompt) {
    deferredPrompt.prompt();
    await deferredPrompt.userChoice;
    deferredPrompt = null;
  } else if (isIosSafariBrowser()) {
    showInstallHelpModal();
  } else {
    alert('To install, use your browser’s “Add to Home Screen” option.');
  }
});





window.printPDF = async function () {
  // ✅ Case 1: Online (iframe embed)
  const frame = document.getElementById("pdfViewerFrame");
  if (frame) {
    try {
      frame.contentWindow.focus();
      frame.contentWindow.print();
      return;
    } catch (err) {
      console.error("❌ Failed to print from iframe:", err);
    }
  }

  // ✅ Case 2: Offline (PDF.js canvases + annotation overlays)
  const container = document.getElementById("pdfTabContent");
  if (!container) {
    alert("⚠️ No PDF loaded to print.");
    return;
  }

  const pageWrappers = container.querySelectorAll(".pdf-page-wrapper");
  if (!pageWrappers.length) {
    alert("⚠️ No PDF content found to print.");
    return;
  }

  try {
    let subject =
      document.getElementById("selectedItemTitle")?.textContent.trim() ||
      "Document";
    subject = subject.replace(/^•\s*/, ""); // remove leading bullet
    subject = subject.replace(/[\/\\:*?"<>|#]/g, ""); // strip illegal filename chars

    // ✅ Always force A4 and fit images
    const printWindow = window.open("", "pdfPrintWindow");
    printWindow.document.write(`
      <html>
      <head>
        <title>${subject}</title>
        <style>
          @page {
            size: A4;
            margin: 0;
          }
          body {
            margin: 0;
            padding: 0;
            text-align: center;
          }
          body img {
            display: block;
            width: 100%;       /* ✅ fit page width */
            height: auto;      /* ✅ keep aspect ratio */
            margin: 0 auto;
            page-break-inside: avoid;
            break-inside: avoid;
          }
        </style>
      </head>
      <body>
    `);

    // ✅ Merge canvases per page → export as <img>
    pageWrappers.forEach((wrapper) => {
      const pdfCanvas = wrapper.querySelector("canvas.pdf-page-canvas");
      if (!pdfCanvas) return;

      const allCanvases = wrapper.querySelectorAll("canvas");
      const merged = document.createElement("canvas");
      merged.width = pdfCanvas.width;
      merged.height = pdfCanvas.height;
      const ctx = merged.getContext("2d");

      ctx.drawImage(pdfCanvas, 0, 0);
      allCanvases.forEach((c) => {
        if (c !== pdfCanvas && c.width > 0 && c.height > 0) {
          ctx.drawImage(
            c,
            0,
            0,
            c.width,
            c.height,
            0,
            0,
            merged.width,
            merged.height
          );
        }
      });

      const imgData = merged.toDataURL("image/png");
      printWindow.document.write(`<img src="${imgData}" />`);
    });

    printWindow.document.write("</body></html>");
    printWindow.document.close();

    // ✅ Trigger print (once only)
    printWindow.onload = () => {
      if (printWindow._printed) return;
      printWindow._printed = true;

      printWindow.focus();
      printWindow.print();

      // Close only on desktop
      if (!/Android|iPhone|iPad/i.test(navigator.userAgent)) {
        printWindow.close();
      }
    };
  } catch (err) {
    console.error("❌ Print failed:", err);
    alert("⚠️ Could not prepare PDF for printing.");
  }
};

window.exportPDF = async function () {
  try {
    const state = window.pdfState || {};
    const surrogate = state.surrogate || window.currentSurrogate;
    if (!surrogate) {
      alert("⚠️ No PDF loaded to export.");
      return;
    }

    const itemEl = document.querySelector(`.list-sub-item[data-value="${surrogate}"]`);
    const owner = state.owner || itemEl?.dataset?.owner;
    if (!owner) {
      alert("⚠️ Missing PDF owner metadata.");
      return;
    }

    const pdfUrl = `https://r2-worker.textwhisper.workers.dev/${owner}/pdf/temp_pdf_surrogate-${surrogate}.pdf`;

    let title =
      document.getElementById("selectedItemTitle")?.textContent?.trim() ||
      `item-${surrogate}`;
    title = title.replace(/^•\s*/, "");
    title = title.replace(/[\/\\:*?"<>|#]+/g, "_");
    if (!title) title = `item-${surrogate}`;

    const res = await fetch(pdfUrl, { method: "GET", cache: "no-store" });
    if (!res.ok) {
      throw new Error(`HTTP ${res.status}`);
    }

    const blob = await res.blob();
    const blobUrl = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = blobUrl;
    a.download = `${title}.pdf`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(blobUrl), 1000);
    window.showFlashMessage?.("✅ PDF exported");
  } catch (err) {
    console.error("❌ PDF export failed:", err);
    alert("⚠️ Could not export this PDF.");
  }
};





// ----------------




// let currentDrawTool = "pen"; // default

// function setDrawTool(tool) {
//   currentDrawTool = tool;
//   const btn = document.getElementById("drawToolBtn");
//   btn.textContent =
//     tool === "pen" ? "✏️" :
//     tool === "highlight" ? "🖍️" :
//     tool === "eraser" ? "🧽" :
//     "🔤"; // ✅ fallback = Text

//   document.getElementById("drawToolMenu").classList.add("hidden");

//   // Hook into your existing drawing logic
//   window.activeAnnotationTool = tool;
// }





// document.addEventListener("DOMContentLoaded", () => {
//   const btn = document.getElementById("drawToolBtn");
//   const menu = document.getElementById("drawToolMenu");

//   btn.addEventListener("click", () => {
//     menu.classList.toggle("hidden");
//   });

//   menu.querySelectorAll("div").forEach(item => {
//     item.addEventListener("click", () => {
//       setDrawTool(item.dataset.tool);
//     });
//   });

//   // close menu on outside click
//   document.addEventListener("click", e => {
//     if (!menu.contains(e.target) && !btn.contains(e.target)) {
//       menu.classList.add("hidden");
//     }
//   });
// });



// ============================================================
// 🧩 Palette visibility toggling (respects edit mode)
// ============================================================
window.updatePaletteVisibility = function (activeTab) {
  const isEditing   = document.body.classList.contains("edit-mode");
  const commentPal  = document.getElementById("commentPalette");
  const commentBtn  = document.getElementById("toggleCommentPalette");
  const drawingPal  = document.getElementById("drawingPalette");
  const annotationToolbar = document.getElementById("annotationToolbar");
  const xmlViewActiveForCurrent =
    !!window._pdfXmlViewState?.active &&
    !!document.getElementById("pdfTabXmlViewer");

  const hide = (el) => { if (!el) return; el.classList.remove("show"); el.style.display = "none"; };
  const show = (el) => { if (!el) return; el.classList.add("show");    el.style.display = "flex"; };
  const syncPdfAnnotationUi = () => {
    if (activeTab) window.currentActiveTab = activeTab;
    const pdfEditing = isEditing && (activeTab === "pdfTab" || activeTab === "pdf");
    if (!pdfEditing) {
      hide(drawingPal);
      hide(annotationToolbar);
      return;
    }
    if (typeof window.toggleDrawingPalette === "function") {
      window.toggleDrawingPalette();
    }
  };

  if (!isEditing) { hide(commentPal); syncPdfAnnotationUi(); return; }

  if (activeTab === "pdfTab" || activeTab === "pdf") {
    hide(commentPal);
    syncPdfAnnotationUi();
  } else if (activeTab === "textTab" || activeTab === "text") {
    // only show text palette if its toggle is active
    const on = !!commentBtn?.classList.contains("active");
    if (on) show(commentPal); else hide(commentPal);
    hide(drawingPal);
    hide(annotationToolbar);
  } else {
    hide(commentPal);
    hide(drawingPal);
    hide(annotationToolbar);
  }
};










