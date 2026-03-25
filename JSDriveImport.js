/* JSDriveImport.js - Google Drive overlay tree (ported from test_drive_ui2.php) */

window._importOverlayInitialized = false;

window.driveAccessToken = null;



window._fmCanEditOwner = false;
window._fmAdminProfiles = [];
window._fmAdminProfilesByToken = Object.create(null);


async function canEditOwner(ownerToken) {
  if (!ownerToken) return (window._fmCanEditOwner = false);

  if (ownerToken === window.SESSION_USERNAME) {
    return (window._fmCanEditOwner = true);
  }

  try {
    const res = await fetch(
      `/getUserRole.php?token=${encodeURIComponent(ownerToken)}`,
      { credentials: "include" }
    );
    if (!res.ok) throw new Error();

    const roleInfo = await res.json();
    const roleRank = Number(roleInfo?.role_rank || 0);
    return (window._fmCanEditOwner = roleRank >= 80);
  } catch {
    return (window._fmCanEditOwner = false);
  }
}

async function loadFmAdminProfiles() {
  if (!window.SESSION_USERNAME) {
    window._fmAdminProfiles = [];
    window._fmAdminProfilesByToken = Object.create(null);
    return [];
  }

  try {
    const res = await fetch("/getFMAdminProfiles.php", { credentials: "include" });
    if (!res.ok) throw new Error("profile list failed");
    const data = await res.json();
    const profiles = Array.isArray(data?.profiles) ? data.profiles : [];
    window._fmAdminProfiles = profiles;
    window._fmAdminProfilesByToken = Object.create(null);
    profiles.forEach((p) => {
      const token = String(p?.username || "").trim();
      if (token) window._fmAdminProfilesByToken[token] = p;
    });
    return profiles;
  } catch {
    const fallback = [{
      username: window.SESSION_USERNAME,
      display_name: window.currentOwner?.display_name || window.SESSION_USERNAME
    }];
    window._fmAdminProfiles = fallback;
    window._fmAdminProfilesByToken = Object.create(null);
    window._fmAdminProfilesByToken[window.SESSION_USERNAME] = fallback[0];
    return fallback;
  }
}

function isAdminProfile(ownerToken) {
  return !!window._fmAdminProfilesByToken?.[String(ownerToken || "").trim()];
}

function getDefaultTwOwnerToken() {
  const currentOwner = String(window.currentOwner?.username || window.currentOwnerToken || "").trim();
  if (currentOwner && isAdminProfile(currentOwner)) return currentOwner;
  if (isAdminProfile(window.SESSION_USERNAME)) return window.SESSION_USERNAME;
  const first = window._fmAdminProfiles?.[0]?.username || "";
  if (first) return first;
  return String(window.SESSION_USERNAME || currentOwner || "").trim();
}

function getTwOwnerStorageKey(paneId) {
  const scope = String(window.currentOwnerToken || window.SESSION_USERNAME || "default");
  return `fm-tw-owner-${scope}-${paneId}`;
}

function getImportPaneSplitStorageKey() {
  const scope = String(window.currentOwnerToken || window.SESSION_USERNAME || "default");
  return `fm-pane-split-${scope}`;
}

function readImportPaneSplitRatio() {
  const raw = Number.parseFloat(localStorage.getItem(getImportPaneSplitStorageKey()) || "");
  if (!Number.isFinite(raw)) return 0.5;
  return Math.min(0.8, Math.max(0.2, raw));
}

function applyImportPaneSplit(splitEl, ratio) {
  if (!splitEl) return;
  const panelA = splitEl.querySelector("#importPanelA");
  const panelB = splitEl.querySelector("#importPanelB");
  if (!panelA || !panelB) return;

  const isMobile = window.matchMedia("(max-width: 900px)").matches;
  if (isMobile) {
    panelA.style.flex = "";
    panelB.style.flex = "";
    if (typeof updateImportPanelActions === "function") {
      updateImportPanelActions(panelA);
      updateImportPanelActions(panelB);
    }
    return;
  }

  const safeRatio = Math.min(0.8, Math.max(0.2, Number(ratio) || 0.5));
  const leftPct = safeRatio * 100;
  const rightPct = 100 - leftPct;
  panelA.style.flex = `0 0 ${leftPct}%`;
  panelB.style.flex = `0 0 ${rightPct}%`;
  if (typeof updateImportPanelActions === "function") {
    updateImportPanelActions(panelA);
    updateImportPanelActions(panelB);
  }
}

function initImportPaneSplitter(splitEl) {
  if (!splitEl) return;
  const panelA = splitEl.querySelector("#importPanelA");
  const panelB = splitEl.querySelector("#importPanelB");
  if (!panelA || !panelB) return;

  let handle = splitEl.querySelector(".import-pane-resizer");
  if (!handle) {
    handle = document.createElement("div");
    handle.className = "import-pane-resizer";
    handle.setAttribute("role", "separator");
    handle.setAttribute("aria-label", "Resize file manager panes");
    splitEl.insertBefore(handle, panelB);
  }

  applyImportPaneSplit(splitEl, readImportPaneSplitRatio());

  if (
    typeof ResizeObserver === "function" &&
    !splitEl._paneResizeObserver
  ) {
    const ro = new ResizeObserver(() => {
      if (typeof updateImportPanelActions === "function") {
        updateImportPanelActions(panelA);
        updateImportPanelActions(panelB);
      }
    });
    ro.observe(panelA);
    ro.observe(panelB);
    splitEl._paneResizeObserver = ro;
  }

  if (splitEl.dataset.splitterBound === "1") return;
  splitEl.dataset.splitterBound = "1";

  const onViewportChange = () => applyImportPaneSplit(splitEl, readImportPaneSplitRatio());
  window.addEventListener("resize", onViewportChange);

  let dragging = false;
  let startX = 0;
  let startLeftWidth = 0;
  let startTotal = 0;

  const minPanePx = 220;
  const getClientX = (evt) => {
    if (evt.touches?.length) return evt.touches[0].clientX;
    if (evt.changedTouches?.length) return evt.changedTouches[0].clientX;
    return evt.clientX;
  };

  const stopDrag = () => {
    if (!dragging) return;
    dragging = false;
    document.body.classList.remove("import-pane-resizing");
    window.removeEventListener("mousemove", onMove);
    window.removeEventListener("mouseup", stopDrag);
    window.removeEventListener("touchmove", onMove);
    window.removeEventListener("touchend", stopDrag);
    window.removeEventListener("touchcancel", stopDrag);
  };

  const onMove = (evt) => {
    if (!dragging) return;
    if (evt.cancelable) evt.preventDefault();

    const handleWidth = handle.getBoundingClientRect().width || 8;
    const usable = Math.max(1, startTotal - handleWidth);
    const minLeft = Math.min(minPanePx, Math.max(80, usable * 0.45));
    const maxLeft = Math.max(minLeft, usable - minPanePx);
    const dx = getClientX(evt) - startX;
    const nextLeft = Math.min(maxLeft, Math.max(minLeft, startLeftWidth + dx));
    const ratio = nextLeft / usable;

    applyImportPaneSplit(splitEl, ratio);
    localStorage.setItem(getImportPaneSplitStorageKey(), String(ratio));
  };

  const startDrag = (evt) => {
    if (evt.type === "mousedown" && evt.button !== 0) return;
    if (window.matchMedia("(max-width: 900px)").matches) return;

    evt.preventDefault();
    const splitRect = splitEl.getBoundingClientRect();
    startX = getClientX(evt);
    startLeftWidth = panelA.getBoundingClientRect().width;
    startTotal = splitRect.width;
    dragging = true;
    document.body.classList.add("import-pane-resizing");

    window.addEventListener("mousemove", onMove);
    window.addEventListener("mouseup", stopDrag);
    window.addEventListener("touchmove", onMove, { passive: false });
    window.addEventListener("touchend", stopDrag);
    window.addEventListener("touchcancel", stopDrag);
  };

  handle.addEventListener("mousedown", startDrag);
  handle.addEventListener("touchstart", startDrag, { passive: false });
}

function persistPaneTwOwner(paneId) {
  const ownerToken = getPaneState(paneId)?.twOwnerToken;
  if (!ownerToken) return;
  localStorage.setItem(getTwOwnerStorageKey(paneId), ownerToken);
}

function getPersistedPaneTwOwner(paneId) {
  return String(localStorage.getItem(getTwOwnerStorageKey(paneId)) || "").trim();
}

function getTwOwnerForPane(paneId = window.activeDriveTreeId) {
  const fromState = String(getPaneState(paneId)?.twOwnerToken || "").trim();
  if (fromState) return fromState;
  return getDefaultTwOwnerToken();
}

function isTwProvider(provider) {
  return provider === "tw" || provider === "mywork";
}

function usesTwOwnerSelector(provider) {
  return provider === "tw" || provider === "mywork" || provider === "myworkdetail";
}

function getActiveTwOwnerForPane(paneId = window.activeDriveTreeId) {
  return getTwOwnerForPane(paneId);
}

function canEditPane(paneId = window.activeDriveTreeId) {
  const provider = getProviderForPane(paneId);
  if (!isTwProvider(provider)) return !!window._fmCanEditOwner;
  return isAdminProfile(getActiveTwOwnerForPane(paneId));
}

function updateTwOwnerCaption(panel, paneId) {
  if (!panel || !paneId) return;
  const caption = panel.querySelector(".tw-owner-caption");
  if (!caption) return;

  if (!usesTwOwnerSelector(getProviderForPane(paneId))) {
    caption.style.display = "none";
    caption.textContent = "";
    return;
  }

  const token = getActiveTwOwnerForPane(paneId);
  const profile = window._fmAdminProfilesByToken?.[token] || null;
  const label = profile?.display_name || token || "";

  caption.style.display = label ? "block" : "none";
  caption.textContent = label || "";
}

async function fetchMyWorkSharedRoot(ownerToken) {
  try {
    const token = String(ownerToken || "").trim();
    const url = token
      ? `/getMyWorkProfilesJSON.php?owner=${encodeURIComponent(token)}`
      : "/getMyWorkProfilesJSON.php";
    const res = await fetch(url, { credentials: "include" });
    if (!res.ok) return null;
    const data = await res.json();
    return Array.isArray(data?.roots) ? data.roots : null;
  } catch {
    return null;
  }
}

async function fetchMyWorkData(ownerToken) {
  const token = String(ownerToken || "").trim();
  const url = token
    ? `/getMyWorkProfilesJSON.php?owner=${encodeURIComponent(token)}`
    : "/getMyWorkProfilesJSON.php";
  const res = await fetch(url, { credentials: "include" });
  if (!res.ok) throw new Error("Failed to load My Work data");
  return res.json();
}

function formatMyWorkDate(rawValue) {
  const raw = String(rawValue || "").trim();
  if (!raw) return { date: "", time: "", days: null };

  const d = new Date(raw.replace(" ", "T"));
  if (Number.isNaN(d.getTime())) return { date: raw, time: "", days: null };

  return {
    date: d.toLocaleDateString(undefined, {
      year: "numeric",
      month: "2-digit",
      day: "2-digit"
    }),
    time: d.toLocaleTimeString(undefined, {
      hour: "2-digit",
      minute: "2-digit",
      second: "2-digit",
      hour12: false
    }),
    days: Math.max(0, Math.floor((Date.now() - d.getTime()) / 86400000))
  };
}

function getPrivacyIcon(accessLevel) {
  switch (String(accessLevel || "").toLowerCase()) {
    case "public":
      return "🌐";
    case "private":
      return "🔒";
    case "secret":
      return "🕵️";
    default:
      return "❓";
  }
}

function renderMyWorkDetailTable(hostEl, title, columns, rows) {
  const section = document.createElement("section");
  section.style.marginBottom = "14px";
  section.style.display = "flex";
  section.style.flexDirection = "column";
  section.style.flex = "1";
  section.style.minHeight = "0";

  const heading = document.createElement("div");
  heading.textContent = title;
  heading.style.fontWeight = "700";
  heading.style.margin = "4px 0 6px";
  section.appendChild(heading);

  const controls = document.createElement("div");
  controls.style.display = "flex";
  controls.style.alignItems = "center";
  controls.style.gap = "8px";
  controls.style.margin = "0 0 8px";

  const search = document.createElement("input");
  search.type = "search";
  search.placeholder = "Filter...";
  search.style.flex = "1";
  search.style.minWidth = "180px";
  search.style.padding = "4px 6px";
  search.style.border = "1px solid #ccc";
  search.style.borderRadius = "4px";

  const count = document.createElement("span");
  count.style.fontSize = "0.85rem";
  count.style.opacity = "0.75";

  controls.appendChild(search);
  controls.appendChild(count);
  section.appendChild(controls);

  const tableWrap = document.createElement("div");
  tableWrap.style.height = "100%";
  tableWrap.style.overflow = "auto";
  tableWrap.style.border = "1px solid #eee";
  tableWrap.style.borderRadius = "6px";
  tableWrap.style.flex = "1";
  tableWrap.style.minHeight = "0";

  const table = document.createElement("table");
  table.style.width = "100%";
  table.style.borderCollapse = "collapse";
  table.style.fontSize = "0.92rem";

  let sortIndex = -1;
  let sortDir = 1;
  let query = "";
  let filteredRows = rows.slice();

  const compareValues = (a, b) => {
    const na = Number(a);
    const nb = Number(b);
    if (!Number.isNaN(na) && !Number.isNaN(nb)) return na - nb;
    return String(a).localeCompare(String(b), undefined, { sensitivity: "base" });
  };

  const applyFilterAndSort = () => {
    filteredRows = rows.filter((r) => {
      if (!query) return true;
      return r.some((v) => String(v ?? "").toLowerCase().includes(query));
    });

    if (sortIndex >= 0) {
      filteredRows.sort((ra, rb) => sortDir * compareValues(ra[sortIndex] ?? "", rb[sortIndex] ?? ""));
    }
  };

  const tbody = document.createElement("tbody");
  const renderRows = () => {
    tbody.innerHTML = "";
    filteredRows.forEach((r) => {
      const tr = document.createElement("tr");
      r.forEach((v) => {
        const td = document.createElement("td");
        td.textContent = v == null ? "" : String(v);
        td.style.padding = "5px 6px";
        td.style.borderBottom = "1px solid #f0f0f0";
        tr.appendChild(td);
      });
      tbody.appendChild(tr);
    });
    count.textContent = `${filteredRows.length}/${rows.length}`;
  };

  const thead = document.createElement("thead");
  const trh = document.createElement("tr");
  columns.forEach((col, idx) => {
    const th = document.createElement("th");
    th.textContent = col;
    th.style.textAlign = "left";
    th.style.borderBottom = "1px solid #ddd";
    th.style.padding = "5px 6px";
    th.style.cursor = "pointer";
    th.title = "Sort";
    th.style.position = "sticky";
    th.style.top = "0";
    th.style.zIndex = "2";
    th.style.background = "#fff";
    th.onclick = () => {
      if (sortIndex === idx) {
        sortDir = -sortDir;
      } else {
        sortIndex = idx;
        sortDir = 1;
      }
      applyFilterAndSort();
      renderRows();
    };
    trh.appendChild(th);
  });
  thead.appendChild(trh);
  table.appendChild(thead);

  search.oninput = () => {
    query = search.value.trim().toLowerCase();
    applyFilterAndSort();
    renderRows();
  };

  applyFilterAndSort();
  renderRows();
  table.appendChild(tbody);
  tableWrap.appendChild(table);
  section.appendChild(tableWrap);
  hostEl.appendChild(section);
}

async function myWorkDetailConnect(force = false) {
  const paneId = window.activeDriveTreeId;
  const tree = document.getElementById(paneId);
  if (!tree) return;

  const ownerToken = getActiveTwOwnerForPane(paneId);

  let data = window.CACHED_MYWORK_DETAILS?.[ownerToken];
  if (force || !data) {
    try {
      data = await fetchMyWorkData(ownerToken);
      window.CACHED_MYWORK_DETAILS ||= {};
      window.CACHED_MYWORK_DETAILS[ownerToken] = data;
    } catch {
      tree.innerHTML = "<div>Failed to load My Work details</div>";
      return;
    }
  }

  tree.innerHTML = "";
  clearPaneSelection(paneId);
  tree.style.display = "flex";
  tree.style.flexDirection = "column";
  tree.style.minHeight = "0";

  const holders = Array.isArray(data?.item_holders) ? data.item_holders : [];

  const itemRows = [];
  holders.forEach((p) => {
    (p.lists || []).forEach((l) => {
      (l.items || []).forEach((it) => {
        const f = formatMyWorkDate(it.added_at);
        itemRows.push([
          p.display_name || p.username || "",
          getPrivacyIcon(l.access_level),
          l.name || l.token || "",
          it.title || `Item ${it.surrogate || ""}`,
          it.owner_username || ownerToken || "",
          it.surrogate || "",
          f.date || "",
          f.time || "",
          f.days == null ? "" : f.days
        ]);
      });
    });
  });

  renderMyWorkDetailTable(
    tree,
    "Who Has My Items",
    ["Profile", "Privacy", "List", "Filename", "Owner", "Surrogate", "Date", "Time", "Days"],
    itemRows.length ? itemRows : [["", "", "No items", "", "", "", "", "", ""]]
  );
}

function getPreviewSourceNode(sourcePaneId) {
  const state = getPaneState(sourcePaneId);
  if (!state) return null;
  if (state.previewNode) return state.previewNode;
  const selected = Array.from(state.selectedFiles || []);
  return selected.length ? selected[selected.length - 1] : null;
}

function ensureFloatingPreviewPane() {
  let pane = document.getElementById("fmFloatingPreview");
  if (pane) return pane;

  pane = document.createElement("div");
  pane.id = "fmFloatingPreview";
  pane.className = "fm-floating-preview";
  pane.innerHTML = `
    <div class="fm-preview-header">
      <span class="fm-preview-title">Preview</span>
      <button type="button" class="fm-preview-close" aria-label="Close preview">✕</button>
    </div>
    <div class="fm-preview-body">
      <div class="text-muted" style="padding:12px;">Select a file to preview.</div>
    </div>
  `;
  document.body.appendChild(pane);
  pane.style.display = "none";

  pane.querySelector(".fm-preview-close")?.addEventListener("click", () => {
    pane.style.display = "none";
  });

  const header = pane.querySelector(".fm-preview-header");
  const closeBtn = pane.querySelector(".fm-preview-close");
  if (header && !pane.dataset.dragBound) {
    pane.dataset.dragBound = "1";
    let dragging = false;
    let startX = 0;
    let startY = 0;
    let startLeft = 0;
    let startTop = 0;

    const getClient = (evt) => {
      if (evt.touches?.length) return { x: evt.touches[0].clientX, y: evt.touches[0].clientY };
      if (evt.changedTouches?.length) return { x: evt.changedTouches[0].clientX, y: evt.changedTouches[0].clientY };
      return { x: evt.clientX, y: evt.clientY };
    };

    const onMove = (evt) => {
      if (!dragging) return;
      if (evt.cancelable) evt.preventDefault();
      const pt = getClient(evt);
      const dx = pt.x - startX;
      const dy = pt.y - startY;

      const width = pane.offsetWidth;
      const height = pane.offsetHeight;
      const maxLeft = Math.max(8, window.innerWidth - width - 8);
      const maxTop = Math.max(8, window.innerHeight - height - 8);
      const nextLeft = Math.min(maxLeft, Math.max(8, startLeft + dx));
      const nextTop = Math.min(maxTop, Math.max(8, startTop + dy));

      pane.style.left = `${nextLeft}px`;
      pane.style.top = `${nextTop}px`;
      pane.style.right = "auto";
      pane.style.bottom = "auto";
    };

    const stopDrag = () => {
      if (!dragging) return;
      dragging = false;
      document.body.classList.remove("fm-preview-dragging");
      window.removeEventListener("mousemove", onMove);
      window.removeEventListener("mouseup", stopDrag);
      window.removeEventListener("touchmove", onMove);
      window.removeEventListener("touchend", stopDrag);
      window.removeEventListener("touchcancel", stopDrag);
    };

    const startDrag = (evt) => {
      if (evt.target === closeBtn || evt.target.closest(".fm-preview-close")) return;
      if (evt.type === "mousedown" && evt.button !== 0) return;
      evt.preventDefault();
      const pt = getClient(evt);
      const rect = pane.getBoundingClientRect();
      startX = pt.x;
      startY = pt.y;
      startLeft = rect.left;
      startTop = rect.top;
      dragging = true;
      document.body.classList.add("fm-preview-dragging");
      window.addEventListener("mousemove", onMove);
      window.addEventListener("mouseup", stopDrag);
      window.addEventListener("touchmove", onMove, { passive: false });
      window.addEventListener("touchend", stopDrag);
      window.addEventListener("touchcancel", stopDrag);
    };

    header.addEventListener("mousedown", startDrag);
    header.addEventListener("touchstart", startDrag, { passive: false });
  }
  return pane;
}

async function renderFloatingPreview(sourcePaneId, sourceNode) {
  const pane = ensureFloatingPreviewPane();
  const body = pane.querySelector(".fm-preview-body");
  if (!body) return;

  if (pane._previewObjectUrl) {
    try { URL.revokeObjectURL(pane._previewObjectUrl); } catch {}
    pane._previewObjectUrl = null;
  }

  if (!sourceNode) {
    body.innerHTML = `<div class="text-muted" style="padding:12px;">Select a file to preview.</div>`;
    return;
  }

  const side = sourcePaneId === "driveTree" ? "A" : "B";
  body.innerHTML = `
    <div style="font-size:12px; padding:4px 0 8px 0;">${escapeHtml(sourceNode.name || "(file)")} <span style="opacity:.7">(from ${side})</span></div>
    <div class="text-muted" style="padding:10px 0;">Loading preview...</div>
  `;

  const reqId = (window._fmPreviewReqId = (window._fmPreviewReqId || 0) + 1);
  const blob = await downloadCurrentDriveFile(sourceNode, sourcePaneId);
  if (reqId !== window._fmPreviewReqId) return;

  if (!blob) {
    body.innerHTML = `<div class="text-muted" style="padding:12px;">Preview unavailable for this file.</div>`;
    return;
  }

  const mime = String(blob.type || sourceNode.mimeType || "").toLowerCase();
  let looksLikePdf = false;
  try {
    const head = await blob.slice(0, 8).text();
    looksLikePdf = /^%PDF-/i.test(head);
  } catch {}

  const isPdfLike = isPdfNode(sourceNode) || mime.includes("pdf") || /\.pdf$/i.test(sourceNode.name || "") || looksLikePdf;
  const baseName = String(sourceNode.name || "file").trim() || "file";
  const normalizedName = isPdfLike && !/\.pdf$/i.test(baseName)
    ? `${baseName}.pdf`
    : baseName;
  const previewBlob =
    isPdfLike && !String(blob.type || "").toLowerCase().includes("pdf")
      ? new Blob([blob], { type: "application/pdf" })
      : blob;

  const objUrl = URL.createObjectURL(previewBlob);
  pane._previewObjectUrl = objUrl;
  body.innerHTML = "";

  if (isPdfLike) {
    // Use pdf.js for deterministic "fit within" behavior in the floating pane.
    if (window.pdfjsLib?.getDocument) {
      const pdfWrap = document.createElement("div");
      pdfWrap.style.cssText = "width:100%; height:100%; min-height:280px; overflow:auto; background:#fff; border:1px solid #d5d5d5; border-radius:8px; padding:6px;";
      const canvas = document.createElement("canvas");
      canvas.style.cssText = "display:block; margin:0 auto;";
      pdfWrap.appendChild(canvas);
      body.appendChild(pdfWrap);

      try {
        const loadingTask = window.pdfjsLib.getDocument(objUrl);
        const pdf = await loadingTask.promise;
        const page = await pdf.getPage(1);
        const viewport = page.getViewport({ scale: 1 });
        const maxW = Math.max(120, pdfWrap.clientWidth - 12);
        const scale = maxW / Math.max(1, viewport.width);
        const fitViewport = page.getViewport({ scale });
        const ctx = canvas.getContext("2d", { alpha: false });
        canvas.width = Math.floor(fitViewport.width);
        canvas.height = Math.floor(fitViewport.height);
        await page.render({ canvasContext: ctx, viewport: fitViewport }).promise;
      } catch {
        pdfWrap.innerHTML = `<div class="text-muted" style="padding:10px;">PDF preview failed.</div>`;
      }
    } else {
      const frame = document.createElement("iframe");
      frame.src = objUrl;
      frame.style.cssText = "width:100%; height:100%; min-height:280px; border:1px solid #d5d5d5; border-radius:8px; background:#fff;";
      body.appendChild(frame);
    }
    return;
  }

  if (mime.startsWith("audio/") || isAudioNode(sourceNode)) {
    const audio = document.createElement("audio");
    audio.controls = true;
    audio.src = objUrl;
    audio.style.width = "100%";
    body.appendChild(audio);
    return;
  }

  const link = document.createElement("a");
  link.href = objUrl;
  link.download = normalizedName;
  link.textContent = `Download ${normalizedName}`;
  link.style.display = "inline-block";
  link.style.marginTop = "8px";
  body.appendChild(link);
}

window.openFmFloatingPreviewPane = function () {
  const pane = ensureFloatingPreviewPane();
  pane.style.display = "flex";
  const sel = window._fmPreviewSelection || {};
  void renderFloatingPreview(sel.sourcePaneId || "driveTree", sel.node || null);
};

function updatePreviewPaneFromSource(sourcePaneId) {
  const sourceNode = getPreviewSourceNode(sourcePaneId);
  window._fmPreviewSelection = { sourcePaneId, node: sourceNode || null };
  const pane = document.getElementById("fmFloatingPreview");
  if (!pane || pane.style.display === "none") return;
  void renderFloatingPreview(sourcePaneId, sourceNode || null);
}



let driveTokenClient = null;

window.dropboxConnected = false;

window.onedriveConnected = false;

window.icloudConnected = false;
window._sourceAuthRefreshTargets = window._sourceAuthRefreshTargets || Object.create(null);
window._sourceProviderSessionStamp = window._sourceProviderSessionStamp || Object.create(null);

let isDragging = false;
let audioDropTargetLi = null;
let twListDropTargetLi = null;
let twListExpandTimer = null;
let twListExpandTarget = null;
let twListHoverClearTimer = null;
const TW_LIST_AUTO_EXPAND_DELAY_MS = 700;


// window.drivePaneState = {
//   driveTree:  { provider: "google" },
//   driveTreeB: { provider: "tw" }
// };

window.activeDriveTreeId = "driveTree";


window.drivePaneState = {
  driveTree: {
    provider: "google",
    selectedFiles: new Set(),
    selectedFolders: new Set(),
    previewNode: null
  },
  driveTreeB: {
    provider: "tw",
    selectedFiles: new Set(),
    selectedFolders: new Set(),
    previewNode: null
  }
};


// document.addEventListener("contextmenu", e => {
//   const li = e.target.closest(".drive-file, .tw-audio-node");
//   if (!li || !li._driveNode) return;

//   e.preventDefault();
//   showTWContextMenu(li, e.pageX, e.pageY);
// });

document.addEventListener("contextmenu", e => {
  const li = e.target.closest(".drive-file, .tw-audio-node, .tw-text-node");
  if (!li || !li._driveNode) return;

  const tree = li.closest(".tree");
  if (!tree) return;

  // 🔒 HARD GATE: TW/My Work ONLY
  if (!isTwProvider(getProviderForPane(tree.id))) return;

  e.preventDefault();
  showTWContextMenu(li, e.pageX, e.pageY);
});



function rememberSourceAuthRefreshTarget(provider, paneId) {
  if (!provider || !paneId) return;
  window._sourceAuthRefreshTargets[provider] = { paneId };
}

function consumeSourceAuthRefreshPane(provider) {
  const pending = window._sourceAuthRefreshTargets?.[provider] || null;
  if (pending?.paneId && getProviderForPane(pending.paneId) === provider) {
    delete window._sourceAuthRefreshTargets[provider];
    return pending.paneId;
  }
  delete window._sourceAuthRefreshTargets[provider];
  const activePane = window.activeDriveTreeId;
  if (getProviderForPane(activePane) === provider) return activePane;
  return null;
}

async function refreshSourcePaneAfterAuth(provider) {
  const paneId = consumeSourceAuthRefreshPane(provider);
  if (!paneId) return;
  bumpSourceProviderSessionStamp(provider);
  const state = getPaneState(paneId);
  if (state) state.forceSourceNoCacheOnce = true;
  clearSourceFolderCacheForProvider(provider);
  const prevPane = window.activeDriveTreeId;
  window.activeDriveTreeId = paneId;
  try {
    if (provider === "dropbox") await dropboxConnect(false);
    else if (provider === "onedrive") await onedriveConnect(false);
    else if (provider === "icloud") await icloudConnect(false);
  } finally {
    window.activeDriveTreeId = prevPane;
  }
}

window.addEventListener("message", async (e) => {
  if (e.origin !== location.origin) return;

  if (e.data?.type === "icloud-auth-ok") {
    window.icloudConnected = true;
    await refreshSourcePaneAfterAuth("icloud");
  }

  if (e.data?.type === "dropbox-auth-ok") {
    window.dropboxConnected = true;
    await refreshSourcePaneAfterAuth("dropbox");
  }

  if (e.data?.type === "onedrive-auth-ok") {
    window.onedriveConnected = true;
    await refreshSourcePaneAfterAuth("onedrive");
  }
});



function getPaneState(paneId = window.activeDriveTreeId) {
  return window.drivePaneState[paneId];
}

function clearPaneSelection(paneId = window.activeDriveTreeId) {
  const state = getPaneState(paneId);
  if (!state) return;
  state.selectedFiles?.clear();
  state.selectedFolders?.clear();
}

function setActiveTwListTokenInPane(paneId, listToken) {
  const state = getPaneState(paneId);
  if (!state) return;
  state.activeTwListToken = String(listToken || "").trim();
}

function consumeSourceNoCacheFlag(paneId = window.activeDriveTreeId) {
  const state = getPaneState(paneId);
  if (!state?.forceSourceNoCacheOnce) return false;
  state.forceSourceNoCacheOnce = false;
  return true;
}



function getProviderForPane(paneId) {
  return window.drivePaneState[paneId]?.provider || "google";
}

function bumpSourceProviderSessionStamp(provider) {
  if (!provider) return;
  window._sourceProviderSessionStamp[provider] = Date.now().toString(36);
}

function getExpandedListSet(paneId) {
  const state = getPaneState(paneId);
  if (!state) return null;
  if (!state.expandedLists) state.expandedLists = new Set();
  return state.expandedLists;
}

function getTreeEl(paneId) {
  return document.getElementById(paneId);
}

function ensureLocalPaneState(paneId = window.activeDriveTreeId) {
  const state = getPaneState(paneId);
  if (!state) return null;
  if (!Array.isArray(state.localFiles)) state.localFiles = [];
  return state;
}

function getLocalSelectedFolderStorageKey(paneId) {
  const scope = String(window.currentOwnerToken || window.SESSION_USERNAME || "default");
  return `fm-local-selected-folder-${scope}-${paneId}`;
}

function getPersistedLocalSelectedFolder(paneId) {
  return String(localStorage.getItem(getLocalSelectedFolderStorageKey(paneId)) || "").trim();
}

function persistLocalSelectedFolder(paneId, folderId) {
  const key = getLocalSelectedFolderStorageKey(paneId);
  const value = String(folderId || "").trim();
  if (!value) {
    localStorage.removeItem(key);
    return;
  }
  localStorage.setItem(key, value);
}

function localFileSignature(file) {
  return [
    String(file?.name || "").trim(),
    Number(file?.size || 0),
    Number(file?.lastModified || 0),
    String(file?.webkitRelativePath || "").trim()
  ].join("::");
}

function isSupportedLocalImportFile(file) {
  const name = String(file?.name || "").toLowerCase();
  const type = String(file?.type || "").toLowerCase();
  if (type === "application/pdf" || name.endsWith(".pdf")) return true;
  if (
    type.startsWith("text/") ||
    type === "application/vnd.openxmlformats-officedocument.wordprocessingml.document" ||
    /\.(txt|md|markdown|docx)$/i.test(name)
  ) return true;
  if (type.startsWith("audio/") || type.includes("audio")) return true;
  return /\.(mp3|wav|ogg|m4a|flac|aac|aif|aiff|webm|mid|midi)$/i.test(name);
}

function detectLocalMimeType(file) {
  const type = String(file?.type || "").trim().toLowerCase();
  if (type) return type;
  const name = String(file?.name || "").toLowerCase();
  if (name.endsWith(".pdf")) return "application/pdf";
  if (name.endsWith(".docx")) return "application/vnd.openxmlformats-officedocument.wordprocessingml.document";
  if (/\.(txt|md|markdown)$/i.test(name)) return "text/plain";
  if (/\.(mid|midi)$/i.test(name)) return "audio/midi";
  if (/\.(mp3|wav|ogg|m4a|flac|aac|aif|aiff|webm)$/i.test(name)) return "audio/mpeg";
  return "application/octet-stream";
}

function addLocalFilesToPane(paneId, filesLike) {
  const state = ensureLocalPaneState(paneId);
  if (!state) return { added: 0 };

  const incoming = Array.from(filesLike || []);
  const known = new Set(state.localFiles.map(localFileSignature));
  let added = 0;

  incoming.forEach((file) => {
    const sig = localFileSignature(file);
    if (known.has(sig)) return;
    known.add(sig);
    state.localFiles.push(file);
    added += 1;
  });

  return { added };
}

function buildLocalTreeRoot(files) {
  const root = {
    id: "local-root",
    name: "This Device",
    mimeType: "application/vnd.local.folder",
    children: []
  };

  const folderByPath = new Map();
  folderByPath.set("", root);

  const ensureFolder = (parentPath, segment) => {
    const full = parentPath ? `${parentPath}/${segment}` : segment;
    if (folderByPath.has(full)) return folderByPath.get(full);
    const parent = folderByPath.get(parentPath) || root;
    const folderNode = {
      id: `local-folder-${full}`,
      name: segment,
      mimeType: "application/vnd.local.folder",
      children: []
    };
    parent.children.push(folderNode);
    folderByPath.set(full, folderNode);
    return folderNode;
  };

  const sortedFiles = [...(files || [])].sort((a, b) => {
    const pa = String(a?.webkitRelativePath || a?.name || "");
    const pb = String(b?.webkitRelativePath || b?.name || "");
    return pa.localeCompare(pb, undefined, { sensitivity: "base" });
  });

  sortedFiles.forEach((file, idx) => {
    const rawPath = String(file?.webkitRelativePath || file?.name || "").trim();
    const parts = rawPath.split("/").filter(Boolean);
    if (!parts.length) return;

    const fileName = parts.pop();
    let parentPath = "";
    parts.forEach((segment) => {
      ensureFolder(parentPath, segment);
      parentPath = parentPath ? `${parentPath}/${segment}` : segment;
    });

    const parent = folderByPath.get(parentPath) || root;
    parent.children.push({
      id: `local-file-${idx}-${Number(file?.lastModified || 0)}-${Number(file?.size || 0)}`,
      name: fileName,
      mimeType: detectLocalMimeType(file),
      _localFile: file
    });
  });

  const sortChildren = (node) => {
    if (!Array.isArray(node.children)) return;
    node.children.sort((a, b) => {
      const aFolder = !!a?.mimeType?.includes("folder");
      const bFolder = !!b?.mimeType?.includes("folder");
      if (aFolder !== bFolder) return aFolder ? -1 : 1;
      return String(a?.name || "").localeCompare(String(b?.name || ""), undefined, { sensitivity: "base" });
    });
    node.children.forEach(sortChildren);
  };
  sortChildren(root);
  return root;
}

function renderLocalDevicePane(paneId, forcePicker = false) {
  const tree = document.getElementById(paneId);
  const state = ensureLocalPaneState(paneId);
  if (!tree || !state) return;

  tree.innerHTML = "";
  clearPaneSelection(paneId);

  const controls = document.createElement("div");
  controls.className = "fm-local-controls";
  controls.style.cssText = "display:flex; flex-wrap:wrap; gap:8px; padding:8px 6px;";
  controls.innerHTML = `
    <button type="button" class="btn-icon fm-local-pick-files" title="Choose files" aria-label="Choose files">
      <i data-lucide="file-plus"></i>
    </button>
    <button type="button" class="btn-icon fm-local-pick-folder" title="Choose folder" aria-label="Choose folder">
      <i data-lucide="folder-open"></i>
    </button>
    <button type="button" class="btn-icon fm-local-clear" title="Clear local files" aria-label="Clear local files">
      <i data-lucide="trash-2"></i>
    </button>
    <span style="font-size:12px; opacity:.75; align-self:center;">Mount local files/folders in this pane</span>
  `;
  tree.appendChild(controls);

  const listHost = document.createElement("div");
  tree.appendChild(listHost);

  const fileInput = document.createElement("input");
  fileInput.type = "file";
  fileInput.multiple = true;
  fileInput.hidden = true;
  fileInput.accept = ".pdf,.txt,.md,.markdown,.docx,text/plain,application/vnd.openxmlformats-officedocument.wordprocessingml.document,audio/*,.mp3,.wav,.ogg,.m4a,.flac,.aac,.aif,.aiff,.webm,.mid,.midi";

  const folderInput = document.createElement("input");
  folderInput.type = "file";
  folderInput.multiple = true;
  folderInput.hidden = true;
  folderInput.setAttribute("webkitdirectory", "");
  folderInput.setAttribute("directory", "");

  tree.appendChild(fileInput);
  tree.appendChild(folderInput);

  const renderTree = () => {
    listHost.innerHTML = "";
    clearPaneSelection(paneId);

    if (!state.localFiles.length) {
      const empty = document.createElement("div");
      empty.style.cssText = "padding:10px 8px; font-size:12px; opacity:.7;";
      empty.textContent = "No local files mounted yet. Choose files or a folder.";
      listHost.appendChild(empty);
      return;
    }

    const rootNode = buildLocalTreeRoot(state.localFiles);
    rootNode._sourceProvider = "local";
    rootNode._loaded = true;
    const selectedFolderId = getPersistedLocalSelectedFolder(paneId);
    if (selectedFolderId) {
      const markFolderSelected = (node) => {
        if (!node || !Array.isArray(node.children)) return false;
        for (const child of node.children) {
          if (String(child?.id || "") === selectedFolderId) {
            child._checked = true;
            return true;
          }
          if (markFolderSelected(child)) return true;
        }
        return false;
      };
      markFolderSelected(rootNode);
    }

    const rootLi = driveRenderNode(rootNode, paneId);
    const ul = document.createElement("ul");
    (rootNode.children || []).forEach((child) => {
      child._sourceProvider = "local";
      ul.appendChild(driveRenderNode(child, paneId));
    });
    rootLi.appendChild(ul);
    rootLi.classList.remove("collapsed");
    listHost.appendChild(rootLi);
  };

  const applyPickedFiles = (filesLike) => {
    const { added } = addLocalFilesToPane(paneId, filesLike);
    if (added) {
      showFlashMessage?.(`Mounted ${added} local file(s).`);
    }
    renderTree();
  };

  fileInput.onchange = () => {
    applyPickedFiles(fileInput.files);
    fileInput.value = "";
  };
  folderInput.onchange = () => {
    applyPickedFiles(folderInput.files);
    folderInput.value = "";
  };

  controls.querySelector(".fm-local-pick-files")?.addEventListener("click", () => fileInput.click());
  controls.querySelector(".fm-local-pick-folder")?.addEventListener("click", () => folderInput.click());
  controls.querySelector(".fm-local-clear")?.addEventListener("click", () => {
    state.localFiles = [];
    persistLocalSelectedFolder(paneId, "");
    renderTree();
  });

  renderTree();
  window.lucide?.createIcons?.();
  if (forcePicker) fileInput.click();
}


function persistPaneProvider(paneId) {
  const provider = getProviderForPane(paneId);
  localStorage.setItem(
    `fm-provider-${window.currentOwnerToken}-${paneId}`,
    provider
  );
}

function restorePaneProvider(paneId, fallback) {
  const key = `fm-provider-${window.currentOwnerToken}-${paneId}`;
  const saved = localStorage.getItem(key);
  window.drivePaneState[paneId].provider = saved || fallback;
}

function supportsScopedSourceView(provider) {
  return provider === "google" || provider === "dropbox" || provider === "onedrive" || provider === "icloud";
}

function getSourceViewModeStorageKey(paneId, provider) {
  const scope = String(window.currentOwnerToken || window.SESSION_USERNAME || "default");
  return `fm-source-view-${scope}-${paneId}-${provider}`;
}

function getSourceFolderStorageKey(paneId, provider) {
  const scope = String(window.currentOwnerToken || window.SESSION_USERNAME || "default");
  return `fm-source-folder-${scope}-${paneId}-${provider}`;
}

function getDefaultSourceRootMeta(provider) {
  switch (provider) {
    case "google": return { id: "root", name: "My Drive", provider };
    case "dropbox": return { id: "", name: "Dropbox", provider };
    case "onedrive": return { id: "root", name: "OneDrive", provider };
    case "icloud": return { id: "/", name: "iCloud Drive", provider };
    default: return { id: "", name: "Root", provider };
  }
}

function normalizeSourceFolderMeta(meta, provider) {
  if (!meta || typeof meta !== "object") return null;
  const id = String(meta.id ?? "").trim();
  const name = String(meta.name ?? "").trim();
  if (!name) return null;
  if (provider === "google" && !id) return null;
  return { id, name, provider };
}

function persistSourceViewPrefs(paneId) {
  const state = getPaneState(paneId);
  if (!state) return;
  const provider = getProviderForPane(paneId);
  if (!supportsScopedSourceView(provider)) return;
  localStorage.setItem(getSourceViewModeStorageKey(paneId, provider), String(state.sourceViewMode || "all"));
  if (state.scopedTrail?.length) {
    try {
      localStorage.setItem(
        getSourceFolderStorageKey(paneId, provider),
        JSON.stringify({ trail: state.scopedTrail, at: Date.now() })
      );
    } catch {}
  }
}

function restoreSourceViewPrefs(paneId) {
  const state = getPaneState(paneId);
  if (!state) return;
  const provider = getProviderForPane(paneId);

  state.sourceViewMode = "all";
  state.scopedTrail = [];
  state.lastSourceFolder = null;

  if (!supportsScopedSourceView(provider)) return;
  const storedMode = localStorage.getItem(getSourceViewModeStorageKey(paneId, provider));
  state.sourceViewMode = String(storedMode || "folder") === "folder" ? "folder" : "all";

  try {
    const raw = localStorage.getItem(getSourceFolderStorageKey(paneId, provider));
    const parsed = raw ? JSON.parse(raw) : null;
    const trail = Array.isArray(parsed?.trail) ? parsed.trail : [];
    const normalizedTrail = trail
      .map((meta) => normalizeSourceFolderMeta(meta, provider))
      .filter(Boolean);
    state.scopedTrail = normalizedTrail;
    state.lastSourceFolder = normalizedTrail[normalizedTrail.length - 1] || null;
  } catch {
    state.scopedTrail = [];
    state.lastSourceFolder = null;
  }
}

function updateSourceScopeBar(paneId) {
  const tree = document.getElementById(paneId);
  const panel = tree?.closest(".drive-import-panel");
  if (!panel) return;
  const header = panel.querySelector(".import-panel-header");
  const state = getPaneState(paneId);
  const provider = getProviderForPane(paneId);
  const bar = panel.querySelector(".sourceScopeBar");
  const pathEl = panel.querySelector(".sourceScopePath");
  const upBtn = panel.querySelector(".sourceScopeUpBtn");
  const rootBtn = panel.querySelector(".sourceScopeRootBtn");
  const drillWrap = panel.querySelector(".sourceDrillWrap");
  const drillToggle = panel.querySelector(".sourceDrillToggle");
  const searchInput = panel.querySelector(".sourceTreeSearchInput");

  const showScoped = supportsScopedSourceView(provider) && state?.sourceViewMode === "folder";
  if (drillWrap) drillWrap.style.display = supportsScopedSourceView(provider) ? "inline-flex" : "none";
  if (drillToggle) {
    drillToggle.checked = showScoped;
    drillToggle.disabled = !supportsScopedSourceView(provider);
  }
  if (searchInput) {
    if (showScoped && bar && pathEl) {
      if (searchInput.parentElement !== bar) {
        bar.insertBefore(searchInput, pathEl.nextSibling);
      }
      searchInput.style.marginLeft = "0";
      searchInput.style.width = "min(34vw,220px)";
    } else if (header) {
      if (searchInput.parentElement !== header) {
        header.appendChild(searchInput);
      }
      searchInput.style.marginLeft = "8px";
      searchInput.style.width = "min(42vw,220px)";
    }
  }
  if (!bar) return;
  bar.style.display = showScoped ? "flex" : "none";
  if (!showScoped) return;

  const trail = Array.isArray(state?.scopedTrail) ? state.scopedTrail : [];
  if (!trail.length) {
    if (pathEl) {
      pathEl.innerHTML = `<span class="scope-seg current" style="opacity:.75;">Select a folder to drill into</span>`;
    }
    if (upBtn) upBtn.disabled = true;
    if (rootBtn) rootBtn.disabled = true;
    return;
  }
  if (pathEl) {
    pathEl.innerHTML = trail
      .map((x, idx) => {
        const label = escapeHtml(String(x?.name || "").trim() || "Folder");
        if (idx === trail.length - 1) return `<span class="scope-seg current">${label}</span>`;
        return `<button type="button" class="scope-seg scope-jump" data-index="${idx}" style="border:0;background:none;padding:0;color:#1f4b8f;text-decoration:underline;cursor:pointer;">${label}</button>`;
      })
      .join(' <span style="opacity:.6">/</span> ');
  }
  if (upBtn) upBtn.disabled = trail.length <= 1;
  if (rootBtn) rootBtn.disabled = trail.length <= 1;
}





window.driveRefresh = async function (paneId) {
  if (!paneId) return;

  const tree = document.getElementById(paneId);
  if (!tree) return;

  window.activeDriveTreeId = paneId;

  tree.innerHTML = "";
  clearPaneSelection(paneId);

  const provider = getProviderForPane(paneId);

  switch (provider) {
    case "local":
      return localDeviceConnect(false);
    case "google":
      if (!window.driveAccessToken) return;
      if (getPaneState(paneId)?.sourceViewMode === "folder") {
        await renderScopedSourceFolder(paneId, getCurrentScopedFolderMeta(paneId), { useCache: true });
      } else {
        await driveLoadRoot("root", "My Drive", paneId);
        await driveLoadRoot("shared", "Shared with me", paneId);
      }
      break;

    case "dropbox":
      if (!window.dropboxConnected) return;
      if (getPaneState(paneId)?.sourceViewMode === "folder") {
        await renderScopedSourceFolder(paneId, getCurrentScopedFolderMeta(paneId), { useCache: true });
      } else {
        await driveLoadRoot("", "Dropbox", paneId);
      }
      break;

    case "onedrive":
      if (!window.onedriveConnected) return;
      if (getPaneState(paneId)?.sourceViewMode === "folder") {
        await renderScopedSourceFolder(paneId, getCurrentScopedFolderMeta(paneId), { useCache: true });
      } else {
        await driveLoadRoot("root", "OneDrive", paneId);
      }
      break;

    case "tw":
    case "mywork":
      await twConnect(true);
      break;
    case "myworkdetail":
      await myWorkDetailConnect(true);
      break;

    case "icloud":
      if (!window.icloudConnected) return;
      if (getPaneState(paneId)?.sourceViewMode === "folder") {
        await renderScopedSourceFolder(paneId, getCurrentScopedFolderMeta(paneId), { useCache: true });
      } else {
        await driveLoadRoot("/", "iCloud Drive", paneId);
      }
      break;
  }
};




/* ================= AUTH ================= */


function googleGetToken(force = false) {
  return new Promise((resolve, reject) => {
    if (!window.google?.accounts?.oauth2) {
      reject("Google OAuth not loaded");
      return;
    }

    if (!driveTokenClient) {
      driveTokenClient = google.accounts.oauth2.initTokenClient({
        client_id: window.GOOGLE_CLIENT_ID,
        scope: "https://www.googleapis.com/auth/drive.readonly openid email",
        callback: () => {}
      });
    }

    // Rebind handlers per request so Connect always resolves the current call.
    driveTokenClient.callback = (resp) => {
      if (!resp?.access_token) {
        reject("No access token");
        return;
      }

      window.driveAccessToken = resp.access_token;
      fetchGoogleUserEmail(resp.access_token)
        .then(email => {
          if (email) localStorage.setItem("driveLoginHint", email);
        })
        .catch(() => {});
      resolve(resp.access_token);
    };

    driveTokenClient.error_callback = () => {
      reject("Google token request failed");
    };

    driveTokenClient.requestAccessToken({
      prompt: force ? "select_account" : "",
      login_hint: force
        ? undefined
        : localStorage.getItem("driveLoginHint") || undefined
    });
  });
}

const DRIVE_PROVIDER_ICONS = {
  local:    "/icons/offline.svg",
  google:   "/icons/googledrive.png",
  dropbox:  "/icons/dropbox_0061ff.svg",
  onedrive: "/icons/onedrive2.png",
  icloud:   "/icons/icloud2.png",
  tw:       "/img/wrt.png",
  mywork:   "/img/wrt.png",
  myworkdetail: "/img/wrt.png"
};


function updateProviderIcon(selectEl) {
  const icon = selectEl
    .closest(".drive-provider-select")
    ?.querySelector(".provider-icon");

  if (!icon) return;

  const src = DRIVE_PROVIDER_ICONS[selectEl.value];
  if (!src) return;

  icon.src = src;
}



/* ================= OPEN / CLOSE (GLOBAL) ================= */

// window.openDriveImportOverlay = async function () {

window.openDriveImportOverlay = async function (defaultProvider) {

  // 🔒 LOGIN GATE (minimum)
  if (!window.SESSION_USERNAME || !window.currentOwnerToken) {
    showFlashMessage?.("Please log in to view the file manager.");
    // optional: send user to login tab if you have one
    // window.switchTab?.("loginTab");
    window._importOverlayInitialized = false;
    return;
  }

  await loadFmAdminProfiles();
  window._fmCanEditOwner = (window._fmAdminProfiles?.length || 0) > 0;
  const defaultTwOwnerToken = getDefaultTwOwnerToken();

  // If owner context changed, rebuild the overlay
  if (window._importOverlayOwnerToken !== window.currentOwnerToken) {
    window._importOverlayInitialized = false;
    window._importOverlayOwnerToken = window.currentOwnerToken;
  }


  // ------------

  
  if (window._importOverlayInitialized) {
    const host = document.getElementById("importTabContent");
    if (!host || !host.querySelector(".import-split")) {
      window._importOverlayInitialized = false;
    }
  }

  if (window._importOverlayInitialized) {
    if (typeof window.switchTab === "function") {
      window.switchTab("importTab");
    }
    initImportPaneSplitter(document.querySelector("#importTabContent .import-split"));
    const existingPreviewPane = document.getElementById("fmFloatingPreview");
    if (existingPreviewPane) existingPreviewPane.style.display = "none";

    // 🔁 Switch provider ONLY if different
    if (
      defaultProvider &&
      window.drivePaneState?.driveTree?.provider !== defaultProvider
    ) {
      window.drivePaneState.driveTree.provider = defaultProvider;

      const selA = document.querySelector(
        '#importPanelA .driveProviderSelect'
      );
      if (selA) {
        selA.value = defaultProvider;
        updateProviderIcon(selA);
      }

      window.activeDriveTreeId = "driveTree";
      driveConnect(false);
    }

    // ✅ If same provider but tree is empty, reconnect
    const treeA = document.getElementById("driveTree");
    if (treeA && treeA.children.length === 0) {
      window.activeDriveTreeId = "driveTree";
      driveConnect(false);
    }

    return;
  }





  // 🔵 First-time initialization only
  window._importOverlayInitialized = true;



  delete window._importSimilarityIndex;
  // window.driveSelectedFiles.clear();
  

  buildImportSimilarityIndex();

  if (typeof window.switchTab === "function") {
    window.switchTab("importTab");
  }

  const host = document.getElementById("importTabContent");
  if (!host) return;

  // ==================================================
  // 1️⃣ Render ONE panel (base)
  // ==================================================
  host.innerHTML = `
    <div class="import-split">

      <div class="drive-import-panel" id="importPanelA">
        <div class="import-panel-header">
          <!-- <strong class="import-title">Import from:</strong> -->

          <div class="drive-provider-select">
            <div class="drive-provider-row">
              <img class="provider-icon" alt="" />
              <select class="driveProviderSelect">
                <option value="local">This Device</option>
                <option value="google">Google Drive</option>
                <option value="dropbox">Dropbox</option>
                <option value="onedrive">OneDrive</option>
                <option value="icloud">iCloud</option>
                <option value="tw">TextWhisper</option>
                <option value="mywork">My Work</option>
                <option value="myworkdetail">My Work Detail</option>
              </select>
            </div>
            <div class="tw-owner-caption" style="display:none;"></div>
          </div>
          <div class="tw-owner-select-wrap" style="display:none;">
            <select class="twOwnerSelect" title="TextWhisper profile"></select>
          </div>
          <button class="btn-icon tw-owner-toggle" type="button" title="Select TextWhisper profile" aria-label="Select TextWhisper profile" style="display:none;">
            <i data-lucide="user"></i>
          </button>

          <!-- <div class="import-target"> ${getDriveImportTargetLabel()} </div> -->
        

          <div class="import-panel-actions">
            <button class="btn-icon" title="Refresh" aria-label="Refresh"
              onclick="driveRefresh(this.closest('.drive-import-panel').querySelector('.tree').id)">
              <i data-lucide="refresh-ccw"></i>
            </button>
            <button class="btn-icon" title="Connect" aria-label="Connect"
              onclick="driveConnectForButton(this, true)">
              <i data-lucide="plug"></i>
            </button>
            <button class="btn-icon" data-action="import" title="Import to TextWhisper" aria-label="Import to TextWhisper"
              onclick="driveCommitImport('driveTree')">
              <i data-lucide="folder-down"></i>
            </button>
            <button class="btn-icon" data-action="create-list" title="Create new list" aria-label="Create new list"
              onclick="createTwListFromButton(this)">
              <i data-lucide="folder-plus"></i>
            </button>
            <button class="btn-icon" data-action="open-preview" title="Open preview pane" aria-label="Open preview pane"
              onclick="openFmFloatingPreviewPane()">
              <i data-lucide="panel-right-open"></i>
            </button>
            <!-- <button class="btn-icon tw-trash" title="Delete">🗑</button> -->
          </div>
          <input
            class="sourceTreeSearchInput"
            type="search"
            placeholder="Search files/folders"
            style="margin-left:8px; min-width:120px; max-width:220px; width:min(42vw,220px); padding:4px 6px; border:1px solid #cfcfcf; border-radius:6px;"
          />
          <label class="sourceDrillWrap" style="margin-left:8px; display:inline-flex; align-items:center; gap:6px; font-size:12px; white-space:nowrap;">
            <input class="sourceDrillToggle" type="checkbox" />
            <span>Drill</span>
          </label>
          
        </div>  

        <div class="sourceScopeBar" style="display:none; align-items:center; gap:8px; padding:4px 6px; border-bottom:1px solid #ececec; font-size:12px;">
          <button type="button" class="sourceScopeIconBtn sourceScopeUpBtn" title="Up one folder" aria-label="Up one folder">
            <i data-lucide="arrow-up"></i>
          </button>
          <span class="sourceScopePath" style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis; flex:1;"></span>
          <button type="button" class="sourceScopeIconBtn sourceScopeRootBtn" title="Back to root folder" aria-label="Back to root folder">
            <i data-lucide="rotate-ccw"></i>
          </button>
        </div>
        <div class="import-panel-body tree" id="driveTree"></div>
      </div>

    </div>
  `;


  // ==================================================
  // 2️⃣ Clone panel A → panel B (browse)
  // ==================================================
  const split  = host.querySelector(".import-split");
  const panelA = host.querySelector("#importPanelA");
  const panelB = panelA.cloneNode(true);

  panelB.id = "importPanelB";

  // rename tree
  panelB.querySelector("#driveTree").id = "driveTreeB";

  // header text
  // panelB.querySelector(".import-title").textContent = "Browse:";


  
  // provider select (TW default)
  const selB = panelB.querySelector(".driveProviderSelect");
  selB.value = "tw";

  split.appendChild(panelB);
  initImportPaneSplitter(split);

  // refresh icons in newly injected markup
  window.lucide?.createIcons?.();

  // make delete bin sortable, enable drop
  const trash = document.querySelector(".tw-trash");

  if (trash && !trash._sortable) {
    trash._sortable = new Sortable(trash, {
      group: {
        name: "test",
        put: true
      },
      onAdd(evt) {
        const node = evt.item?._driveNode;
        if (!node) return;

        evt.item.remove();

        if (node.mimeType === "audio") {
          deleteAudioFromTWTree(node);
          return;
        }

        // ✅ reuse EXISTING function signature
        const listToken = getCurrentList()?.token;
        const surrogate = node._twSurrogate;

        if (!listToken || !surrogate) return;

        // fabricate a minimal event object (function only uses target)
        deleteItemFromTWTree(listToken, surrogate, {
          target: evt.to
        });
      }

    });
  }


  const importBtnB = panelB.querySelector(
  'button[onclick^="driveCommitImport"]'
);

if (importBtnB) {
  importBtnB.setAttribute(
    "onclick",
    "driveCommitImport('driveTreeB')"
  );
}

//pane-aware connect wrapper
window.driveConnectForButton = async function (btn, force = false) {
  const panel = btn.closest(".drive-import-panel");
  if (!panel) return;

  const tree = panel.querySelector(".tree");
  if (!tree?.id) return;

  if (force) {
    const paneId = tree.id;
    const state = getPaneState(paneId);
    const provider = getProviderForPane(paneId);
    if (supportsScopedSourceView(provider)) {
      clearSourceFolderCacheForProvider(provider);
      resetScopedTrailToRoot(paneId);
      if (state) state.forceSourceNoCacheOnce = true;
      bumpSourceProviderSessionStamp(provider);
    }
  }

  window.activeDriveTreeId = tree.id;
  await driveConnect(force);
  
};

function updateImportPanelActions(panel) {
  if (!panel) return;

  const tree = panel.querySelector(".tree");
  if (!tree?.id) return;

  const paneId = tree.id;
  const provider = getProviderForPane(paneId);
  const isMobile = window.matchMedia("(max-width: 900px)").matches;
  const headerWidth = panel.querySelector(".import-panel-header")?.getBoundingClientRect().width || 0;
  const panelWidth = panel.getBoundingClientRect().width || 0;
  const effectiveWidth = headerWidth || panelWidth;
  const compactBreakpoint = 760;
  const isCompact = isMobile || effectiveWidth < compactBreakpoint;
  const wasCompact = panel.dataset.twOwnerCompact === "1";
  panel.dataset.twOwnerCompact = isCompact ? "1" : "0";

  const importBtn = panel.querySelector('[data-action="import"]');
  const createBtn = panel.querySelector('[data-action="create-list"]');
  const twOwnerWrap = panel.querySelector(".tw-owner-select-wrap");
  const ownerToggle = panel.querySelector(".tw-owner-toggle");
  const sourceSearch = panel.querySelector(".sourceTreeSearchInput");
  const sourceDrillWrap = panel.querySelector(".sourceDrillWrap");

  if (usesTwOwnerSelector(provider) && wasCompact !== isCompact) {
    // Auto-collapse when narrowing, auto-expand when widening.
    panel.dataset.twOwnerOpen = isCompact ? "0" : "1";
  }

  if (ownerToggle) {
    ownerToggle.style.display = usesTwOwnerSelector(provider) && isCompact ? "inline-flex" : "none";
  }

  if (twOwnerWrap) {
    if (!usesTwOwnerSelector(provider)) {
      panel.dataset.twOwnerOpen = "0";
      twOwnerWrap.style.display = "none";
    } else if (isCompact) {
      twOwnerWrap.style.display = panel.dataset.twOwnerOpen === "1" ? "inline-flex" : "none";
    } else {
      twOwnerWrap.style.display = "inline-flex";
    }
  }

  if (importBtn) {
    importBtn.style.display = provider === "myworkdetail" ? "none" : "inline-flex";
  }

  if (createBtn) {
    const canEdit = canEditPane(paneId);
    createBtn.style.display = (isTwProvider(provider) && provider !== "myworkdetail" && canEdit) ? "inline-flex" : "none";
  }

  if (sourceSearch) {
    sourceSearch.style.display = isTwProvider(provider) ? "none" : "";
  }
  if (sourceDrillWrap) {
    sourceDrillWrap.style.display = supportsScopedSourceView(provider) ? "inline-flex" : "none";
  }

  updateTwOwnerCaption(panel, paneId);
  updateSourceScopeBar(paneId);
}

window.createTwListFromButton = async function (btn) {
  const panel = btn.closest(".drive-import-panel");
  if (!panel) return;

  const tree = panel.querySelector(".tree");
  if (!tree?.id) return;

  const paneId = tree.id;
  const provider = getProviderForPane(paneId);
  if (!isTwProvider(provider)) {
    showFlashMessage?.("Switch this pane to TextWhisper/My Work to create a list.");
    return;
  }
  const ownerToken = getActiveTwOwnerForPane(paneId);
  if (provider === "tw" && !isAdminProfile(ownerToken)) {
    showFlashMessage?.("You need owner/admin rights on the selected TextWhisper profile.");
    return;
  }

  const name = (prompt("New list name:") || "").trim();
  if (!name) return;

  try {
    const created = await createContentListQuiet(name, ownerToken);
    if (!created?.token) {
      alert("Could not create list.");
      return;
    }

    ["driveTree", "driveTreeB"].forEach(pid => {
      if (!isTwProvider(getProviderForPane(pid))) return;
      const added = addTwListToTree(pid, created, ownerToken);
      if (added) {
        setSelectedTwListInPane(pid, created.token);
      }
    });

    // Sync main sidebar so newly created list is immediately visible there too.
    await ensureSidebarTargetListVisible(created.token, ownerToken, created.name || name);
    showFlashMessage?.(`Selected target list: ${created.name || name}`);
  } catch (err) {
    alert("Could not create list.");
    return;
  }
};



  // ==================================================
  // 3️⃣ Layout (horizontal)
  // ==================================================
  split.style.display = "flex";

  // ==================================================
  // 4️⃣ Initialize pane state
  // ==================================================


  // window.drivePaneState = {
  //   driveTree: {
  //     provider: defaultProvider || "tw",
  //     selectedFiles: new Set(),
  //     selectedFolders: new Set()
  //   },
  //   driveTreeB: {
  //     provider: "tw",
  //     selectedFiles: new Set(),
  //     selectedFolders: new Set()
  //   }
  // };

  // ==================================================
  // 4️⃣ Initialize pane state (WITH restore)
  // ==================================================
  window.drivePaneState = {
    driveTree: {
      provider:
        localStorage.getItem(`fm-provider-${window.currentOwnerToken}-driveTree`)
        || defaultProvider
        || "tw",
      twOwnerToken: getPersistedPaneTwOwner("driveTree") || defaultTwOwnerToken,
      selectedFiles: new Set(),
      selectedFolders: new Set(),
      localFiles: [],
      previewNode: null,
      expandedLists: new Set(),
      searchQuery: "",
      sourceViewMode: "all",
      scopedTrail: [],
      lastSourceFolder: null
    },
    driveTreeB: {
      provider:
        localStorage.getItem(`fm-provider-${window.currentOwnerToken}-driveTreeB`)
        || "tw",
      twOwnerToken: getPersistedPaneTwOwner("driveTreeB") || defaultTwOwnerToken,
      selectedFiles: new Set(),
      selectedFolders: new Set(),
      localFiles: [],
      previewNode: null,
      expandedLists: new Set(),
      searchQuery: "",
      sourceViewMode: "all",
      scopedTrail: [],
      lastSourceFolder: null
    }
  };

  ["driveTree", "driveTreeB"].forEach((pid) => {
    const token = getPaneState(pid)?.twOwnerToken;
    if (!isAdminProfile(token)) {
      getPaneState(pid).twOwnerToken = defaultTwOwnerToken;
    }
    restoreSourceViewPrefs(pid);
  });


  // ==================================================
  // 5️⃣ Wire provider selects (minimal, correct)
  // ==================================================

  host.querySelectorAll(".driveProviderSelect").forEach(sel => {
    const panel = sel.closest(".drive-import-panel");
    const treeId = panel.id === "importPanelB" ? "driveTreeB" : "driveTree";
    const ownerSelect = panel.querySelector(".twOwnerSelect");
    const ownerToggle = panel.querySelector(".tw-owner-toggle");
    const drillToggle = panel.querySelector(".sourceDrillToggle");
    const pathEl = panel.querySelector(".sourceScopePath");
    const upBtn = panel.querySelector(".sourceScopeUpBtn");
    const rootBtn = panel.querySelector(".sourceScopeRootBtn");

    panel.dataset.twOwnerOpen = panel.dataset.twOwnerOpen || "0";

    sel.value = window.drivePaneState[treeId].provider;
    restoreSourceViewPrefs(treeId);
    if (drillToggle) {
      drillToggle.checked = getPaneState(treeId)?.sourceViewMode === "folder";
    }
    if (ownerSelect) {
      ownerSelect.innerHTML = "";
      window._fmAdminProfiles.forEach(profile => {
        const opt = document.createElement("option");
        opt.value = profile.username;
        const label = profile.display_name || profile.username;
        opt.textContent = `${label} [${profile.username}]`;
        ownerSelect.appendChild(opt);
      });
      ownerSelect.value = getTwOwnerForPane(treeId);
      ownerSelect.onchange = async () => {
        const chosen = ownerSelect.value;
        if (!isAdminProfile(chosen)) return;
        window.drivePaneState[treeId].twOwnerToken = chosen;
        persistPaneTwOwner(treeId);
        window._importSimilarityIndex = null;
        buildImportSimilarityIndex();
        updateImportPanelActions(panel);
        if (usesTwOwnerSelector(getProviderForPane(treeId))) {
          window.activeDriveTreeId = treeId;
          await driveConnect(true);
        }
      };
    }

    if (ownerToggle) {
      ownerToggle.onclick = () => {
        panel.dataset.twOwnerOpen = panel.dataset.twOwnerOpen === "1" ? "0" : "1";
        updateImportPanelActions(panel);
      };
    }

    updateProviderIcon(sel);
    updateImportPanelActions(panel);
    updateSourceScopeBar(treeId);

    if (drillToggle) {
      drillToggle.onchange = async () => {
        const state = getPaneState(treeId);
        if (!state) return;
        state.sourceViewMode = drillToggle.checked ? "folder" : "all";
        if (state.sourceViewMode === "folder") {
          // Keep current tree on screen; user chooses the folder to drill into.
          state.scopedTrail = [];
          state.lastSourceFolder = null;
        }
        persistSourceViewPrefs(treeId);
        updateSourceScopeBar(treeId);
        if (state.sourceViewMode === "all") {
          window.activeDriveTreeId = treeId;
          await driveConnect(false);
        }
      };
    }

    if (pathEl) {
      pathEl.onclick = async (event) => {
        const jumpBtn = event.target?.closest?.(".scope-jump");
        if (!jumpBtn) return;
        const idx = Number(jumpBtn.dataset.index);
        if (!Number.isInteger(idx)) return;
        const state = getPaneState(treeId);
        if (!state || !Array.isArray(state.scopedTrail)) return;
        if (idx < 0 || idx >= state.scopedTrail.length) return;
        state.scopedTrail = state.scopedTrail.slice(0, idx + 1);
        state.lastSourceFolder = state.scopedTrail[state.scopedTrail.length - 1] || null;
        persistSourceViewPrefs(treeId);
        window.activeDriveTreeId = treeId;
        await renderScopedSourceFolder(treeId, state.lastSourceFolder, { useCache: true });
      };
    }

    if (upBtn) {
      upBtn.onclick = async () => {
        window.activeDriveTreeId = treeId;
        await navigateScopedUp(treeId);
      };
    }

    if (rootBtn) {
      rootBtn.onclick = async () => {
        const provider = getProviderForPane(treeId);
        const state = getPaneState(treeId);
        if (!state || !supportsScopedSourceView(provider)) return;
        const root = getDefaultSourceRootMeta(provider);
        state.scopedTrail = [root];
        state.lastSourceFolder = root;
        persistSourceViewPrefs(treeId);
        window.activeDriveTreeId = treeId;
        await renderScopedSourceFolder(treeId, root, { useCache: true });
      };
    }

    sel.onchange = async () => {
      window.activeDriveTreeId = treeId;
      window.drivePaneState[treeId].provider = sel.value;
      restoreSourceViewPrefs(treeId);
      
      persistPaneProvider(treeId); 
      persistSourceViewPrefs(treeId);
      updateProviderIcon(sel);
      updateImportPanelActions(panel);
      if (drillToggle) {
        drillToggle.checked = getPaneState(treeId)?.sourceViewMode === "folder";
      }
      updateSourceScopeBar(treeId);
      if (ownerSelect && !ownerSelect.value) {
        ownerSelect.value = getTwOwnerForPane(treeId);
      }
      if (!usesTwOwnerSelector(sel.value)) {
        panel.dataset.twOwnerOpen = "0";
      }
      await driveConnect(false);
    };
  });

  host.querySelectorAll(".sourceTreeSearchInput").forEach((input) => {
    const panel = input.closest(".drive-import-panel");
    const treeId = panel?.id === "importPanelB" ? "driveTreeB" : "driveTree";
    const state = getPaneState(treeId);
    input.value = String(state?.searchQuery || "");
    input.oninput = () => {
      applyDriveTreeSearch(treeId, input.value);
    };
  });

  if (!window._fmOwnerToggleResizeBound) {
    window._fmOwnerToggleResizeBound = true;
    window.addEventListener("resize", () => {
      document.querySelectorAll(".drive-import-panel").forEach(panel => {
        updateImportPanelActions(panel);
      });
    });
  }


  // ==================================================
  // 6️⃣ Load initial content
  // ==================================================

  // Pane A: default provider (google)
  window.activeDriveTreeId = "driveTree";
  await driveConnect(false);

  // Pane B: TW browse
  window.activeDriveTreeId = "driveTreeB";
  // await twConnect();
  await driveConnect(false);

  // restore focus to pane A
  window.activeDriveTreeId = "driveTree";


  // enablePaneDrag("driveTree");
  // enablePaneDrag("driveTreeB");  

if (canEditPane("driveTree")) {
  enablePaneDrag("driveTree");
}
if (canEditPane("driveTreeB")) {
  enablePaneDrag("driveTreeB");
}

  

};


// Context menu

// function showTWContextMenuXXXX(li, x, y) {
//   const node = li._driveNode;
//   const isAudio = node.mimeType === "audio";

//   const menu = document.createElement("div");
//   menu.className = "tw-context-menu";
//   menu.style.cssText = `
//     position:fixed;
//     left:${x}px;
//     top:${y}px;
//     background:#222;
//     color:#fff;
//     border-radius:6px;
//     padding:6px 0;
//     z-index:100000;
//   `;

//   menu.innerHTML = isAudio
//     ? `<div class="item">🗑 Delete audio</div>`
//     : `<div class="item">➖ Remove from list</div>`;

//   menu.querySelector(".item").onclick = async () => {
//     menu.remove();
//     if (isAudio) {
//       await deleteAudioFromTWTree(node);
//     } else {
//       await deleteItemFromTWTree(node);
//     }
//   };

//   document.body.appendChild(menu);

//   document.addEventListener("click", () => menu.remove(), { once: true });
// }

function showTWContextMenu(li, x, y) {

  //only if you have permission
  const paneId = li?.closest(".tree")?.id || window.activeDriveTreeId;
  if (!canEditPane(paneId)) return;

  const node = li._driveNode;
  if (!node) return;

  // remove any existing menu
  document.querySelectorAll(".tw-context-menu").forEach(m => m.remove());

  const isAudio = node.mimeType === "audio";

  const menu = document.createElement("div");
  menu.className = "tw-context-menu";
  menu.style.cssText = `
    position:fixed;
    left:${x}px;
    top:${y}px;
    z-index:100000;
  `;

  const deleteItem = document.createElement("div");
  deleteItem.className = "item";
  deleteItem.textContent = isAudio
    ? "🗑 Delete audio"
    : "➖ Remove from list";

  const cancelItem = document.createElement("div");
  cancelItem.className = "item";
  cancelItem.textContent = "✖ Cancel";
  cancelItem.style.opacity = "0.6";

  deleteItem.onclick = async (e) => {
    e.stopPropagation();
    menu.remove();

    if (isAudio) {
      deleteAudioFromTWTree(node, li);
    } else {
      deleteItemFromTWTree(node, li);
    }
  };

  cancelItem.onclick = (e) => {
    e.stopPropagation();
    menu.remove();
  };

  menu.append(deleteItem, cancelItem);
  document.body.appendChild(menu);

  // click outside closes menu
  document.addEventListener(
    "click",
    () => menu.remove(),
    { once: true }
  );
}



async function deleteAudioFile(key) {
  if (!key) return false;
  const url = `https://r2-worker.textwhisper.workers.dev/?key=${encodeURIComponent(key)}`;

  try {
    const res = await fetch(url, { method: "DELETE" });
    if (res.ok) return true;
  } catch {}

  try {
    const res = await fetch(url, {
      method: "POST",
      headers: { "X-HTTP-Method-Override": "DELETE" }
    });
    return res.ok;
  } catch {
    return false;
  }
}

function deleteAudioFromTWTree(node, li) {
  const key       = node?._twAudioKey;
  const surrogate = node?._twParentSurrogate;
  if (!key || !surrogate) return;

  const fakeEvent = {
    target: {
      closest: () => null
    }
  };

  deleteAudioFile(key);

  try { li.remove(); } catch {}
}




function deleteItemFromTWTree(node, li) {
  const listToken = getCurrentList()?.token;
  const surrogate = node?._twSurrogate;
  if (!listToken || !surrogate) return;

  const fakeEvent = {
    target: {
      closest: () => null
    }
  };

  removeItemFromList(listToken, surrogate, fakeEvent);

  try { li.remove(); } catch {}
}









//---- Initialize finnished------------------






window.closeDriveImportOverlay = function () {
  const host = document.getElementById("importTabContent");
  if (!host) return;

  host.innerHTML = "";
  const previewPane = document.getElementById("fmFloatingPreview");
  if (previewPane) {
    previewPane.style.display = "none";
    if (previewPane._previewObjectUrl) {
      try { URL.revokeObjectURL(previewPane._previewObjectUrl); } catch {}
      previewPane._previewObjectUrl = null;
    }
  }
  window._importOverlayInitialized = false;
  // window.driveSelectedFiles.clear();
};

function waitForPdfIdle() {
  return new Promise(resolve => {
    requestAnimationFrame(() => {
      requestAnimationFrame(resolve);
    });
  });
}



async function fetchGoogleUserEmail(token) {
  const res = await fetch(
    "https://www.googleapis.com/oauth2/v3/userinfo",
    { headers: { Authorization: `Bearer ${token}` } }
  );
  if (!res.ok) return null;
  const data = await res.json();
  return data.email || null;
}




/* ================= CONNECT (GLOBAL) ================= */

window.driveConnect = async function (force = false) {
  // switch (driveProvider) {
  // const pane = window.activeDriveTreeId;
  const paneId = window.activeDriveTreeId;
  const driveProvider = getProviderForPane(paneId); 

  switch (driveProvider) {

    case "local":
      return localDeviceConnect(force);
    case "google":
      return googleConnect(force);
    case "dropbox":
      return dropboxConnect(force);
    case "onedrive":
      return onedriveConnect(force);
    case "tw":
    case "mywork":
      return twConnect(force);
    case "myworkdetail":
      return myWorkDetailConnect(force);
    case "icloud":
      return icloudConnect(force);
    default:
      console.warn("Unknown drive provider:", driveProvider);
  }
};

async function localDeviceConnect(force = false) {
  const paneId = window.activeDriveTreeId;
  renderLocalDevicePane(paneId, force);
  reapplyDriveTreeSearch(paneId);
}



async function googleConnect(force = false) {
  const paneId = window.activeDriveTreeId;
  if (!window.driveAccessToken || force) {
    await googleGetToken(force);
  }
  if (force) {
    clearSourceFolderCacheForProvider("google");
  }

  const tree = document.getElementById(paneId);
  if (!tree) return;

  tree.innerHTML = "";
  clearPaneSelection(paneId);

  const state = getPaneState(paneId);
  if (state?.sourceViewMode === "folder" && supportsScopedSourceView(getProviderForPane(paneId))) {
    if (force) resetScopedTrailToRoot(paneId);
    const noCache = force || consumeSourceNoCacheFlag(paneId);
    await renderScopedSourceFolder(paneId, getCurrentScopedFolderMeta(paneId), { useCache: !noCache });
    return;
  }

  await driveLoadRoot("root", "My Drive", paneId);
  await driveLoadRoot("shared", "Shared with me", paneId);
  reapplyDriveTreeSearch(paneId);
}



// async function twConnect() {
async function twConnect(force = false) {

  const paneId = window.activeDriveTreeId;
  const tree = document.getElementById(paneId);
  if (!tree) return;

  const ownerToken = getActiveTwOwnerForPane(paneId);
  const provider = getProviderForPane(paneId);

  let data = null;
  let myWorkRoots = null;

  if (provider === "mywork") {
    myWorkRoots = await fetchMyWorkSharedRoot(ownerToken);
    data = { owner: { username: ownerToken } };
  } else {
    data = window.CACHED_OWNER_LISTS?.[ownerToken];

    if (force || !data) {
      const res = await fetch(
        `/getOwnersListsJSON.php?token=${encodeURIComponent(ownerToken)}`,
        { credentials: "include" }
      );

      if (!res.ok) {
        tree.innerHTML = "<li>Failed to load TextWhisper content</li>";
        return;
      }

      data = await res.json();
      window.CACHED_OWNER_LISTS ||= {};
      window.CACHED_OWNER_LISTS[ownerToken] = data;
    }
  }

  // ---------------- RENDER ----------------

  tree.innerHTML = "";
  clearPaneSelection(paneId);

  if (!data) {
    tree.innerHTML = "<li>No TextWhisper content available</li>";
    return;
  }

  // =========================================
  // 🔊 Cloudflare audio prefetch (ONE FETCH)
  // =========================================
  // window._twCloudAudioBySurrogate = Object.create(null);
  const cloudAudioBySurrogate = Object.create(null);


  const audioOwnerToken = data?.owner?.username || ownerToken;
  try {
    const res = await fetch(
      `https://r2-worker.textwhisper.workers.dev/list?prefix=${encodeURIComponent(audioOwnerToken + "/")}`
    );

    if (res.ok) {
      const list = await res.json();
      if (Array.isArray(list)) {
        list.forEach(obj => {
          if (!obj.key) return;
          if (!/\.(mp3|wav|ogg|m4a|flac|aac|aif|aiff|webm|mid|midi)$/i.test(obj.key)) return;

          const m = obj.key.match(/surrogate-(\d+)/);
          if (!m) return;

          const s = String(m[1]);
          // (window._twCloudAudioBySurrogate[s] ||= []).push(obj.key);
          (cloudAudioBySurrogate[s] ||= []).push(obj.key);

        });
      }
    }
  } catch (err) {
    console.warn("TW Cloudflare audio fetch failed:", err);
  }

  // =========================================
  // TW lists → folder tree
  // =========================================
  // function toTWFolder(list) {
  //   const listName = list.name || list.title || "Untitled list";

  //   const itemNodes = (list.items || []).map(it => {
  //     const node = {
  //       name: it.title,
  //       mimeType: "application/pdf",
  //       surrogate: it.surrogate,
  //       _twSurrogate: it.surrogate
  //     };

  //     const surr = String(it.surrogate || "");
  //     // const audio = window._twCloudAudioBySurrogate?.[surr];
  //     const audio = cloudAudioBySurrogate[surr];


  //     if (Array.isArray(audio) && audio.length) {
  //       node.children = audio.map(k => ({
  //         name: k.split("/").pop(),
  //         mimeType: "audio",
  //         _twAudioKey: k,
  //         _twParentSurrogate: surr
  //       }));
  //     }

  //     return node;
  //   });

  //   const childFolders = (list.children || []).map(toTWFolder);

  //   return {
  //     name: listName,
  //     mimeType: "application/vnd.google-apps.folder",

  //     // 🔑 THIS IS THE FIX
  //     _twListToken: list.token,

  //     children: [...childFolders, ...itemNodes]
  //   };

  // }

  function toTWFolder(list) {
    const listName = list.name || list.title || "Untitled list";
    const listOwnerToken = String(
      list.owner_username ||
      list.owner ||
      list.owner_token ||
      ownerToken ||
      ""
    ).trim();

    /* ===============================
      📄 PDF ITEMS (WITH AUDIO CHILDREN)
      =============================== */
    const itemNodes = (list.items || []).map(it => {
      const itemOwnerToken = String(
        it.owner ||
        it.owner_username ||
        listOwnerToken ||
        ""
      ).trim();
      const node = {
        name: it.title,
        mimeType: "application/pdf",

        surrogate: it.surrogate,
        _twSurrogate: it.surrogate,
        _twOwner: itemOwnerToken || null,
        _twAddedAt: it.added_at || null
      };

      const surr = String(it.surrogate || "");
      const audio = cloudAudioBySurrogate[surr];

      if (Array.isArray(audio) && audio.length) {
        node.children = audio.map(k => ({
          name: k.split("/").pop(),
          mimeType: "audio",
          _twAudioKey: k,
          _twParentSurrogate: surr,
          _twOwner: itemOwnerToken || null
        }));
      }

      return node;
    });

    /* ===============================
      📁 CHILD LISTS (RECURSIVE)
      =============================== */
    const childFolders = (list.children || []).map(toTWFolder);

    /* ===============================
      📂 LIST ROOT NODE (THIS IS THE KEY)
      =============================== */
    return {
      name: listName,
      mimeType: "application/vnd.google-apps.folder",

      // 🔑 DIRECTLY FROM list.token (as seen in DevTools)
      _twListToken: list.token,
      _twIsListRoot: true,

      children: [...childFolders, ...itemNodes]
    };
  }


  let roots = provider === "mywork"
    ? (Array.isArray(myWorkRoots) ? myWorkRoots : [])
    : [
      ...(Array.isArray(data.owned) ? data.owned : []),
      ...(Array.isArray(data.accessible) ? data.accessible : [])
    ];

  if (provider === "mywork") {
    const expanded = getExpandedListSet(paneId);
    if (expanded) {
      expanded.add("mywork-items");
      expanded.add("mywork-followers");
    }
    if (!roots.length) {
      roots = [
        { token: "mywork-items", name: "Who Has My Items", children: [] },
        { token: "mywork-followers", name: "Who Follows My Lists", children: [] }
      ];
    }
  }

  const ul = document.createElement("ul");
  roots.forEach(rootList =>
    ul.appendChild(driveRenderNode(toTWFolder(rootList), paneId))
  );

  tree.appendChild(ul);
  reapplyDriveTreeSearch(paneId);

  // enablePaneDrag(paneId);
  if (canEditPane(paneId)) {
    enablePaneDrag(paneId);
  }

}


async function dropboxConnect(force = false) {
  const paneId = window.activeDriveTreeId;
  if (!window.dropboxConnected || force) {
    if (force) {
      clearSourceFolderCacheForProvider("dropbox");
      resetScopedTrailToRoot(paneId);
    }
    rememberSourceAuthRefreshTarget("dropbox", paneId);
    const url = force
      ? "/api/auth/dropbox/dropbox-login.php?force=1"
      : "/api/auth/dropbox/dropbox-login.php";

    window.open(url, "dropboxOAuth", "width=600,height=700");
    return;
  }

  // load tree
  // const tree = document.getElementById("driveTree");
  const tree = document.getElementById(paneId);
  if (!tree) return;

  tree.innerHTML = "";
  clearPaneSelection(paneId);

  const state = getPaneState(paneId);
  if (state?.sourceViewMode === "folder" && supportsScopedSourceView(getProviderForPane(paneId))) {
    const noCache = force || consumeSourceNoCacheFlag(paneId);
    await renderScopedSourceFolder(paneId, getCurrentScopedFolderMeta(paneId), { useCache: !noCache });
    return;
  }

  await driveLoadRoot("", "Dropbox", paneId);
  reapplyDriveTreeSearch(paneId);
}



async function onedriveConnect(force = false) {
  const paneId = window.activeDriveTreeId;
  if (!window.onedriveConnected || force) {
    if (force) {
      clearSourceFolderCacheForProvider("onedrive");
      resetScopedTrailToRoot(paneId);
    }
    rememberSourceAuthRefreshTarget("onedrive", paneId);
    const url = force
      ? "/api/auth/microsoft/onedrive-login.php?force=1"
      : "/api/auth/microsoft/onedrive-login.php";

    window.open(url, "onedriveOAuth", "width=600,height=700");
    return;
  }

  // const tree = document.getElementById("driveTree");
  const tree = document.getElementById(paneId);

  if (!tree) return;

  tree.innerHTML = "";
  clearPaneSelection(paneId);

  const state = getPaneState(paneId);
  if (state?.sourceViewMode === "folder" && supportsScopedSourceView(getProviderForPane(paneId))) {
    const noCache = force || consumeSourceNoCacheFlag(paneId);
    await renderScopedSourceFolder(paneId, getCurrentScopedFolderMeta(paneId), { useCache: !noCache });
    return;
  }

  await driveLoadRoot("root", "OneDrive", paneId);
  reapplyDriveTreeSearch(paneId);
}


async function icloudConnect(force = false) {
  const paneId = window.activeDriveTreeId;
  if (!window.icloudConnected || force) {
    if (force) {
      clearSourceFolderCacheForProvider("icloud");
      resetScopedTrailToRoot(paneId);
    }
    rememberSourceAuthRefreshTarget("icloud", paneId);
    const url = force
      ? "/api/auth/icloud/icloud-login.php?force=1"
      : "/api/auth/icloud/icloud-login.php";

    window.open(url, "icloudOAuth", "width=620,height=720");
    return;
  }

  // const tree = document.getElementById("driveTree");
  const tree = document.getElementById(paneId);

  if (!tree) return;

  tree.innerHTML = "";
  clearPaneSelection(paneId);

  const state = getPaneState(paneId);
  if (state?.sourceViewMode === "folder" && supportsScopedSourceView(getProviderForPane(paneId))) {
    const noCache = force || consumeSourceNoCacheFlag(paneId);
    await renderScopedSourceFolder(paneId, getCurrentScopedFolderMeta(paneId), { useCache: !noCache });
    return;
  }

  // root path for iCloud WebDAV listing
  await driveLoadRoot("/", "iCloud Drive", paneId);
  reapplyDriveTreeSearch(paneId);
}



/* ================= ROOT LOAD ================= */

function driveListEndpoint(folderId, paneId = window.activeDriveTreeId) {
  // switch (driveProvider) {
  const pane = paneId;
  // const driveProvider = window.drivePaneState[pane].provider;
  // const driveProvider = getActiveProvider();

  const driveProvider = getProviderForPane(pane); 

  switch (driveProvider) {

    case "google":
      return {
        url: `/File_listGoogleDrive.php?folder=${encodeURIComponent(folderId)}`,
        headers: { Authorization: `Bearer ${window.driveAccessToken}` }
      };

    case "dropbox":
      return {
        url: `/File_listDropbox.php?path=${encodeURIComponent(folderId)}`,
        headers: {} // server-side auth
      };

    case "onedrive":
      return {
        url: `/File_listOneDrive.php?folder=${encodeURIComponent(folderId)}`,
        headers: {} // server-side auth
      };

    case "icloud":
      return {
        url: `/File_listICloud.php?path=${encodeURIComponent(folderId)}`,
        headers: {} // server-side auth (session)
      };

    default:
      throw new Error("Unsupported drive provider: " + driveProvider);
  }
}

window._sourceFolderDataCache = window._sourceFolderDataCache || new Map();

function getSourceProviderIdentity(provider) {
  const p = String(provider || "").toLowerCase();
  const stamp = String(window._sourceProviderSessionStamp?.[p] || "");
  switch (p) {
    case "google":
      return String(window.driveAccessToken || localStorage.getItem("driveLoginHint") || "") + `::${stamp}`;
    default:
      return stamp;
  }
}

function sourceFolderCacheKey(paneId, folderId) {
  const provider = getProviderForPane(paneId);
  const identity = getSourceProviderIdentity(provider);
  return `${provider}::${identity}::${String(folderId ?? "")}`;
}

function clearSourceFolderCacheForProvider(provider) {
  const cache = window._sourceFolderDataCache;
  if (!(cache instanceof Map)) return;
  const prefix = `${String(provider || "")}::`;
  Array.from(cache.keys()).forEach((key) => {
    if (String(key).startsWith(prefix)) cache.delete(key);
  });
}

function resetScopedTrailToRoot(paneId) {
  const state = getPaneState(paneId);
  if (!state) return;
  const provider = getProviderForPane(paneId);
  if (!supportsScopedSourceView(provider)) return;
  const root = getDefaultSourceRootMeta(provider);
  state.scopedTrail = [root];
  state.lastSourceFolder = root;
  persistSourceViewPrefs(paneId);
}

async function fetchSourceFolderData(folderId, paneId) {
  const { url, headers } = driveListEndpoint(folderId, paneId);
  const res = await fetch(url, { headers });
  if (!res.ok) throw new Error(`Folder load failed (${res.status})`);
  return res.json();
}

async function getSourceFolderDataCached(folderId, paneId, opts = {}) {
  const useCache = opts.useCache !== false;
  const backgroundRefresh = opts.backgroundRefresh !== false;
  const onFresh = typeof opts.onFresh === "function" ? opts.onFresh : null;
  const key = sourceFolderCacheKey(paneId, folderId);
  const cache = window._sourceFolderDataCache;

  if (useCache && cache.has(key)) {
    const cached = cache.get(key);
    if (backgroundRefresh) {
      fetchSourceFolderData(folderId, paneId)
        .then((fresh) => {
          cache.set(key, fresh);
          try { onFresh?.(fresh); } catch {}
        })
        .catch(() => {});
    }
    return cached;
  }

  const data = await fetchSourceFolderData(folderId, paneId);
  cache.set(key, data);
  return data;
}

function getCurrentScopedFolderMeta(paneId) {
  const state = getPaneState(paneId);
  const provider = getProviderForPane(paneId);
  if (!state) return getDefaultSourceRootMeta(provider);
  const trail = Array.isArray(state.scopedTrail) ? state.scopedTrail : [];
  return trail[trail.length - 1] || getDefaultSourceRootMeta(provider);
}

async function renderScopedSourceFolder(paneId, folderMeta, opts = {}) {
  const tree = document.getElementById(paneId);
  if (!tree) return;

  const provider = getProviderForPane(paneId);
  if (!supportsScopedSourceView(provider)) return;

  const state = getPaneState(paneId);
  const safeMeta = normalizeSourceFolderMeta(folderMeta, provider) || getDefaultSourceRootMeta(provider);
  if (!Array.isArray(state.scopedTrail) || !state.scopedTrail.length) {
    state.scopedTrail = [safeMeta];
  } else {
    state.scopedTrail[state.scopedTrail.length - 1] = safeMeta;
  }
  state.lastSourceFolder = safeMeta;
  persistSourceViewPrefs(paneId);
  updateSourceScopeBar(paneId);

  tree.innerHTML = "";
  clearPaneSelection(paneId);

  let data;
  try {
    data = await getSourceFolderDataCached(safeMeta.id, paneId, {
      useCache: opts.useCache !== false,
      backgroundRefresh: true,
      onFresh: (fresh) => {
        // Only live-refresh the currently open scoped folder in Drill mode.
        const current = getCurrentScopedFolderMeta(paneId);
        const currentProvider = getProviderForPane(paneId);
        const stateNow = getPaneState(paneId);
        if (!tree.isConnected) return;
        if (!fresh || typeof fresh !== "object") return;
        if (String(current?.id ?? "") !== String(safeMeta.id ?? "")) return;
        if (String(currentProvider) !== String(provider)) return;
        if (stateNow?.sourceViewMode !== "folder") return;

        const freshChildren = sortNodesFolderFirst(fresh?.children || [], paneId);
        const freshUl = document.createElement("ul");
        freshChildren.forEach((child) => freshUl.appendChild(driveRenderNode(child, paneId)));
        tree.innerHTML = "";
        clearPaneSelection(paneId);
        tree.appendChild(freshUl);
        reapplyDriveTreeSearch(paneId);
        if (canEditPane(paneId)) enablePaneDrag(paneId);
      }
    });
  } catch (err) {
    tree.innerHTML = `<div style="padding:8px; color:#a33;">Could not load folder.</div>`;
    return;
  }

  const children = sortNodesFolderFirst(data?.children || [], paneId);
  const ul = document.createElement("ul");
  children.forEach((child) => ul.appendChild(driveRenderNode(child, paneId)));
  tree.appendChild(ul);

  reapplyDriveTreeSearch(paneId);
  if (canEditPane(paneId)) enablePaneDrag(paneId);
}

async function navigateScopedFolder(paneId, folderNode) {
  const provider = getProviderForPane(paneId);
  if (!supportsScopedSourceView(provider)) return;
  if (!folderNode) return;

  const state = getPaneState(paneId);
  const id = provider === "dropbox"
    ? String(folderNode.path || "").trim()
    : String(folderNode.id || "").trim();
  const name = String(folderNode.name || "Folder").trim() || "Folder";
  if (!id && provider !== "dropbox") return;

  const meta = normalizeSourceFolderMeta({ id, name, provider }, provider);
  if (!meta) return;

  state.scopedTrail = Array.isArray(state.scopedTrail) ? state.scopedTrail : [];
  state.scopedTrail.push(meta);
  state.lastSourceFolder = meta;
  persistSourceViewPrefs(paneId);
  await renderScopedSourceFolder(paneId, meta, { useCache: true });
}

async function navigateScopedUp(paneId) {
  const state = getPaneState(paneId);
  const provider = getProviderForPane(paneId);
  if (!state || !supportsScopedSourceView(provider)) return;

  const trail = Array.isArray(state.scopedTrail) ? state.scopedTrail : [];
  if (trail.length <= 1) {
    const root = getDefaultSourceRootMeta(provider);
    state.scopedTrail = [root];
    state.lastSourceFolder = root;
    persistSourceViewPrefs(paneId);
    await renderScopedSourceFolder(paneId, root, { useCache: true });
    return;
  }

  trail.pop();
  state.scopedTrail = trail;
  const next = trail[trail.length - 1];
  state.lastSourceFolder = next || null;
  persistSourceViewPrefs(paneId);
  await renderScopedSourceFolder(paneId, next, { useCache: true });
}

function sortNodesFolderFirst(nodes, paneId = window.activeDriveTreeId) {
  const list = Array.isArray(nodes) ? [...nodes] : [];
  const provider = getProviderForPane(paneId);
  if (isTwProvider(provider)) return list;

  return list.sort((a, b) => {
    const aFolder = !!String(a?.mimeType || "").toLowerCase().includes("folder");
    const bFolder = !!String(b?.mimeType || "").toLowerCase().includes("folder");
    if (aFolder !== bFolder) return aFolder ? -1 : 1;
    const aName = String(a?.name || "");
    const bName = String(b?.name || "");
    return aName.localeCompare(bName, undefined, { sensitivity: "base" });
  });
}

function getTopLevelTreeLis(treeEl) {
  if (!treeEl) return [];
  const out = [];
  Array.from(treeEl.children || []).forEach((child) => {
    if (!child || !(child instanceof HTMLElement)) return;
    if (child.tagName === "LI") {
      out.push(child);
      return;
    }
    if (child.tagName === "UL") {
      out.push(...Array.from(child.children || []).filter((el) => el?.tagName === "LI"));
    }
  });
  return out;
}

function getTreeNodeLabelText(li) {
  const label = li?.querySelector?.(":scope > .file-label, :scope > .folder-label");
  return String(label?.textContent || "").toLowerCase();
}

function filterTreeLiByQuery(li, query) {
  const q = String(query || "").trim().toLowerCase();
  const childrenUl = li.querySelector(":scope > ul");
  let childMatches = false;

  if (childrenUl) {
    Array.from(childrenUl.children || []).forEach((child) => {
      if (!(child instanceof HTMLElement) || child.tagName !== "LI") return;
      if (filterTreeLiByQuery(child, q)) childMatches = true;
    });
  }

  const selfMatch = !q || getTreeNodeLabelText(li).includes(q);
  const visible = selfMatch || childMatches;
  li.style.display = visible ? "" : "none";

  if (q && childMatches && childrenUl) {
    childrenUl.style.display = "block";
    li.classList.remove("collapsed");
  }

  return visible;
}

function applyDriveTreeSearch(paneId, rawQuery) {
  const tree = document.getElementById(paneId);
  if (!tree) return;
  const state = getPaneState(paneId);
  const query = String(rawQuery || "").trim().toLowerCase();
  if (state) state.searchQuery = query;

  const roots = getTopLevelTreeLis(tree);
  roots.forEach((li) => filterTreeLiByQuery(li, query));
}

function reapplyDriveTreeSearch(paneId) {
  const state = getPaneState(paneId);
  const query = String(state?.searchQuery || "");
  applyDriveTreeSearch(paneId, query);
}


async function driveLoadRoot(rootId, title, paneId = window.activeDriveTreeId) {
  const { url, headers } = driveListEndpoint(rootId, paneId);
  const res = await fetch(url, { headers });
  if (!res.ok) return;

  const data = await res.json();
  const container = document.getElementById(paneId);
  if (!container) return;

  const rootLi = document.createElement("li");
  rootLi.classList.add("collapsed");

  const paneIdLocal = paneId;
  const label = document.createElement("span");
  label.className = "folder-label";
  label.textContent = " 📁 " + title;

  label.onclick = async (e) => {
    e.stopPropagation();
    const state = getPaneState(paneIdLocal);
    const provider = getProviderForPane(paneIdLocal);
    if (state?.sourceViewMode === "folder" && supportsScopedSourceView(provider)) {
      const rootMeta = normalizeSourceFolderMeta(
        { id: rootId, name: title, provider },
        provider
      );
      if (rootMeta) {
        state.scopedTrail = [rootMeta];
        state.lastSourceFolder = rootMeta;
        persistSourceViewPrefs(paneIdLocal);
        await renderScopedSourceFolder(paneIdLocal, rootMeta, { useCache: true });
        return;
      }
    }
    toggleCollapsed({ li: rootLi });
  };

  rootLi.appendChild(label);

  const ul = document.createElement("ul");
  const sortedChildren = sortNodesFolderFirst(data.children || [], paneIdLocal);
  sortedChildren.forEach(child => {
    ul.appendChild(driveRenderNode(child, paneIdLocal));
  });

  rootLi.appendChild(ul);
  container.appendChild(rootLi);
  reapplyDriveTreeSearch(paneIdLocal);
}



/* ================= TREE ================= */



function driveRenderNode(node, paneId) {
  const driveProvider = getProviderForPane(paneId);
  const li = document.createElement("li");

  node._sourceProvider = driveProvider;
  li._driveNode = node; 

  const checkbox = document.createElement("input");
  checkbox.type = "checkbox";
  checkbox._driveNode = node;

  /* ================= FOLDER ================= */
  if (node.mimeType && node.mimeType.includes("folder")) {
    li.classList.add("collapsed");

    const label = document.createElement("span");
    label.className = "folder-label";
    label.textContent = " 📂 " + (node.name || "(folder)");

    // label.onclick = async (e) => {
    //   e.stopPropagation();
    //   await loadFolderChildren(node, li);
    //   toggleCollapsed({ li });
    // };

    label.onclick = async (e) => {
      e.stopPropagation();
      window.activeDriveTreeId = paneId;
      const state = getPaneState(paneId);
      if (isTwProvider(driveProvider) && node._twIsListRoot && node._twListToken) {
        setActiveTwListTokenInPane(paneId, node._twListToken);
      }
      if (state?.sourceViewMode === "folder" && supportsScopedSourceView(driveProvider)) {
        await navigateScopedFolder(paneId, node);
        return;
      }
      state.lastSourceFolder = normalizeSourceFolderMeta({
        id: driveProvider === "dropbox" ? node.path : node.id,
        name: node.name,
        provider: driveProvider
      }, driveProvider);
      persistSourceViewPrefs(paneId);
      await loadFolderChildren(node, li, paneId);
      const isCollapsed = toggleCollapsed({ li });
      if (isTwProvider(driveProvider) && node._twIsListRoot) {
        const set = getExpandedListSet(paneId);
        if (set) {
          if (!isCollapsed) set.add(node._twListToken);
          else set.delete(node._twListToken);
        }
      }
    };

    checkbox.checked = !!node._checked;
    if (checkbox.checked) {
      getPaneState(paneId)?.selectedFolders?.add(node);
    }


    checkbox.onchange = () => {
      node._checked = checkbox.checked;

      // checkbox.checked
      //   ? window.driveSelectedFolders.add(node)
      //   : window.driveSelectedFolders.delete(node);

      const state = getPaneState(paneId);

      checkbox.checked
        ? state.selectedFolders.add(node)
        : state.selectedFolders.delete(node);

      if (isTwProvider(driveProvider) && node._twIsListRoot) {
        if (checkbox.checked) {
          setActiveTwListTokenInPane(paneId, node._twListToken);
        } else if (String(state.activeTwListToken || "") === String(node._twListToken || "")) {
          setActiveTwListTokenInPane(paneId, "");
        }
      }

      if (driveProvider === "local" && node?.id) {
        if (checkbox.checked) {
          persistLocalSelectedFolder(paneId, node.id);
        } else if (getPersistedLocalSelectedFolder(paneId) === String(node.id)) {
          persistLocalSelectedFolder(paneId, "");
        }
      }

      if (Array.isArray(node.children)) {
        node.children.forEach(child => {
          if (!child._checkbox) return;

          // ⛔ TW audio is not checkable via folder toggle
          if (isTwProvider(driveProvider) && child.mimeType === "audio") return;

          child._checkbox.checked = checkbox.checked;

          if (child.mimeType?.includes("folder")) {
            // checkbox.checked
            //   ? window.driveSelectedFolders.add(child)
            //   : window.driveSelectedFolders.delete(child);
            const state = getPaneState(paneId);

            checkbox.checked
              ? state.selectedFolders.add(child)
              : state.selectedFolders.delete(child);

          } else {
            // checkbox.checked
            //   ? window.driveSelectedFiles.add(child)
            //   : window.driveSelectedFiles.delete(child);
            const state = getPaneState(paneId);

            checkbox.checked
              ? state.selectedFiles.add(child)
              : state.selectedFiles.delete(child);

          }
        });
      }
    };

    //Disable checkboxes when read-only
    if (!canEditPane(paneId)) {
      checkbox.disabled = true;
    }


    li.appendChild(checkbox);
    li.appendChild(label);

    node._checkbox = checkbox;
    if (isTwProvider(driveProvider) && node._twIsListRoot) {
      const set = getExpandedListSet(paneId);
      if (set && set.has(node._twListToken)) {
        void loadFolderChildren(node, li, paneId).then(() => {
          li.classList.remove("collapsed");
        });
      }
    }

    return li;
  }

  /* ================= TW AUDIO (leaf only) ================= */
  if (isTwProvider(driveProvider) && node.mimeType === "audio") {
    // li.classList.add("drive-file");
    li.classList.add("tw-audio-node");

    const spacer = document.createElement("span");
    spacer.style.display = "inline-block";
    spacer.style.width = "18px";

    const label = document.createElement("span");
    label.className = "file-label tw-audio-line";
    label.textContent = " 🎵 " + (node.name || "(audio)");

    li.appendChild(spacer);
    li.appendChild(label);

    //This does not work for selecting item mfrom nested audio
    // li.addEventListener("click", (e) => {
    //   e.stopPropagation();
    //   selectTWNodeFromTree(li);
    // });

    return li;
  }

  /* ================= FILE (PDF / AUDIO) ================= */
  li.classList.add("drive-file");
  li._driveNode = node;

  /* ================= Select works for audio, not pdf ================= */
  //to be reviewed
  // if (driveProvider === "tw" && node._twSurrogate) {
  //   li.style.cursor = "pointer";

  //   li.addEventListener("click", (e) => {
  //     if (
  //       e.target.closest("input") ||
  //       e.target.closest(".tw-expander") ||
  //       e.target.closest(".audio-count")
  //     ) {
  //       return;
  //     }

  //     e.stopPropagation();
  //     selectTWNodeFromTree(li);
  //   });
  // }

  
  const isAudio = isAudioNode(node);
  const isText = isTextNode(node);
  const isLocalUnsupported =
    driveProvider === "local" &&
    !!node?._localFile &&
    !isSupportedLocalImportFile(node._localFile);

  const icon = isAudio ? "🎵" : isText ? "📝" : "📄";

  let displayName = node.name || "(file)";
  if (node.mimeType === "application/pdf" && !/\.pdf$/i.test(displayName)) {
    displayName += ".pdf";
  }
  if (node.mimeType?.startsWith("audio/") && !/\.[a-z0-9]+$/i.test(displayName)) {
    const subtype = String(node.mimeType.split("/")[1] || "").toLowerCase();
    const ext = subtype && subtype !== "mpeg" ? subtype : "mp3";
    displayName += "." + ext;
  }

  const label = document.createElement("span");
  label.className = "file-label";
  if (driveProvider === "mywork" && node._twSurrogate) {
    const owner = String(node._twOwner || window.SESSION_USERNAME || "").trim();
    const addedRaw = String(node._twAddedAt || "").trim();
    let addedText = "";
    let daysSinceAdded = null;
    if (addedRaw) {
      const d = new Date(addedRaw.replace(" ", "T"));
      if (Number.isNaN(d.getTime())) {
        addedText = addedRaw;
      } else {
        addedText = d.toLocaleString(undefined, {
            year: "numeric",
            month: "2-digit",
            day: "2-digit",
            hour: "2-digit",
            minute: "2-digit",
            second: "2-digit",
            hour12: false
          });
        daysSinceAdded = Math.max(0, Math.floor((Date.now() - d.getTime()) / 86400000));
      }
    }

    const main = document.createElement("span");
    main.textContent = ` ${icon} ` + displayName;

    const meta = document.createElement("span");
    meta.style.display = "block";
    meta.style.fontSize = "0.82em";
    meta.style.opacity = "0.78";
    meta.style.marginLeft = "1.5em";
    meta.textContent = `owner: ${owner} | surrogate: ${node._twSurrogate}${addedText ? ` | added: ${addedText}` : ""}${daysSinceAdded !== null ? ` | days: ${daysSinceAdded}` : ""}`;

    label.appendChild(main);
    label.appendChild(meta);
  } else {
    label.textContent = ` ${icon} ` + displayName;
    if (isLocalUnsupported) {
      label.textContent += " (view only)";
      label.style.opacity = "0.7";
    }
  }

  label.addEventListener("click", () => {
    const paneState = getPaneState(paneId);
    if (paneState) paneState.previewNode = node;
    updatePreviewPaneFromSource(paneId);
  });

  //select item - works for pdf, not audio
  if (isTwProvider(driveProvider) && node._twSurrogate) {
    label.addEventListener("click", (e) => {
      e.stopPropagation();
      selectTWNodeFromTree(li);
    });
  }

  // const { level, score, surrogate } = estimateTWUsage(node.name);

  //only use matched surrogate for pdf, not audio
  const { level, score, surrogate } =
    isAudioNode(node)
      ? { level: "none", score: 0, surrogate: null }
      : isTwProvider(driveProvider)
        ? { level: "none", score: 0, surrogate: null }
        : estimateTWUsage(node.name, paneId);


  if (surrogate) {
    node._twSurrogate = surrogate;
    li.dataset.surrogate = surrogate;

    const s = document.createElement("span");
    s.className = "tw-surrogate-link";
    s.textContent = ` (${surrogate})`;
    s.style.cursor = "pointer";

    s.onclick = (e) => {
      e.stopPropagation();
      const row = document.querySelector(
        `.list-sub-item[data-value="${surrogate}"]`
      );
      if (!row) return showFlashMessage?.("Sidebar item not found");

      selectItem(
        surrogate,
        row.dataset.token,
        document.getElementById(`list-${row.dataset.token}`)
      );
    };

    label.appendChild(s);
  }

  if (!surrogate && isTwProvider(driveProvider) && driveProvider !== "mywork" && node._twSurrogate) {
    li.dataset.surrogate = node._twSurrogate;
    const s = document.createElement("span");
    s.className = "tw-surrogate-link";
    s.textContent = ` (${node._twSurrogate})`;
    s.style.cursor = "pointer";

    s.onclick = (e) => {
      e.stopPropagation();
      const row = document.querySelector(
        `.list-sub-item[data-value="${node._twSurrogate}"]`
      );
      if (!row) return showFlashMessage?.("Sidebar item not found");

      selectItem(
        node._twSurrogate,
        row.dataset.token,
        document.getElementById(`list-${row.dataset.token}`)
      );
    };

    label.appendChild(s);
  }

  if (isTwProvider(driveProvider)) {
    if (node._twSurrogate) {
      const dot = document.createElement("span");
      dot.className = "tw-usage-dot high";
      dot.title = "Existing item";
      li.appendChild(dot);
    }
  } else {
    const dot = document.createElement("span");
    dot.className = "tw-usage-dot " + level;
    dot.title =
      level === "high"
        ? `Likely already used (${score}%)`
        : level === "medium"
        ? `Possibly used (${score}%)`
        : `No match (${score}%)`;
    li.appendChild(dot);
  }

  // checkbox.onchange = () => {
  //   checkbox.checked
  //     ? window.driveSelectedFiles.add(node)
  //     : window.driveSelectedFiles.delete(node);
  // };

  const state = getPaneState(paneId);

  checkbox.onchange = () => {
    checkbox.checked
      ? state.selectedFiles.add(node)
      : state.selectedFiles.delete(node);
    if (checkbox.checked) {
      state.previewNode = node;
    }
    updatePreviewPaneFromSource(paneId);
  };


  if (!canEditPane(paneId) || isLocalUnsupported) {
    checkbox.disabled = true;
  }

  li.appendChild(checkbox);
  li.appendChild(label);

  /* ================= TW EXPANDABLE AUDIO ================= */
  if (
    isTwProvider(driveProvider) &&
    Array.isArray(node.children) &&
    node.children.length
  ) {
    attachTWAudioExpander({ li, label, node, paneId });
    //Select item
    li.addEventListener("click", (e) => {
      e.stopPropagation();
      selectTWNodeFromTree(li);
    });    
  }

  node._checkbox = checkbox;
  return li;
}


function isPdfNode(node) {
  if (!node) return false;
  return (
    String(node.mimeType || "").toLowerCase() === "application/pdf" ||
    /\.pdf$/i.test(String(node.name || ""))
  );
}


function isAudioNode(node) {
  if (!node) return false;
  if (node.mimeType === "audio") return true;
  if (node.mimeType?.startsWith("audio/")) return true;
  if (node.mimeType?.includes("audio")) return true;
  const name = String(node.name || "").trim();
  return /\.(mp3|wav|ogg|m4a|flac|aac|aif|aiff|mid|midi)$/i.test(name);
}

function isTextNode(node) {
  if (!node) return false;
  const mime = String(node.mimeType || "").toLowerCase();
  const name = String(node.name || "").trim();
  if (mime === "application/vnd.google-apps.document") return true;
  if (mime === "application/vnd.openxmlformats-officedocument.wordprocessingml.document") return true;
  if (mime.startsWith("text/")) return true;
  return /\.(txt|md|markdown|docx)$/i.test(name);
}


// function attachTWAudioExpander({ li, label, node }) {
function attachTWAudioExpander({ li, label, node, paneId }){

  // const driveProvider = getActiveProvider();
  const driveProvider = getProviderForPane(paneId);

  if (!isTwProvider(driveProvider) || !Array.isArray(node.children) || !node.children.length) return;

  li.classList.add("collapsed");

  // expander sits close to the pdf icon; do NOT shift the whole label
  const expander = document.createElement("span");
  expander.className = "tw-expander";
  expander.textContent = "▸";
  expander.style.cursor = "pointer";
  expander.style.marginRight = "-0.6em"; // your “looks ok” tweak

  li.insertBefore(expander, label);

  const badge = document.createElement("span");
  badge.className = "audio-count";
  badge.textContent = ` 🎵 ${node.children.length}`;
  label.appendChild(badge);

  const ul = document.createElement("ul");
  ul.style.display = "none";
  node.children.forEach(child => ul.appendChild(driveRenderNode(child, paneId)));
  li.appendChild(ul);

  const toggle = (e) => {
    if (e) e.stopPropagation();

    const open = ul.style.display === "none";
    ul.style.display = open ? "block" : "none";
    expander.textContent = open ? "▾" : "▸";
    li.classList.toggle("collapsed", !open);
  };

  expander.onclick = toggle;
  label.onclick = toggle; // clicking the node text expands too
}



async function loadFolderChildren(node, li, paneId) {
  const driveProvider = getProviderForPane(paneId);
  if (node._loaded) return;

  const ul = document.createElement("ul");

  // PRELOADED (TW)
  if (Array.isArray(node.children)) {
    const preloadedChildren = isTwProvider(driveProvider)
      ? node.children
      : sortNodesFolderFirst(node.children, paneId);
    preloadedChildren.forEach(child => ul.appendChild(driveRenderNode(child, paneId)));
    if (
      isTwProvider(driveProvider) &&
      node?._twIsListRoot &&
      preloadedChildren.length === 0
    ) {
      const empty = document.createElement("li");
      empty.className = "tw-drop-hint tw-empty-hint";
      empty.textContent = "empty";
      ul.appendChild(empty);
    }
    node._loaded = true;
    li.appendChild(ul);
    enablePaneDrag(paneId)
    reapplyDriveTreeSearch(paneId);
    return;
  }

  // LAZY (Google / Dropbox / OneDrive)
  if (
    (driveProvider === "dropbox" && node.path) ||
    (driveProvider !== "dropbox" && node.id)
  ) {
    const folderId = (driveProvider === "dropbox") ? node.path : node.id;

    const { url, headers } = driveListEndpoint(folderId, paneId);
    const res = await fetch(url, { headers });
    if (!res.ok) return;

    const data = await res.json();
    node.children = sortNodesFolderFirst(data.children || [], paneId);
    node._loaded = true;

    node.children.forEach(child => ul.appendChild(driveRenderNode(child, paneId)));
    li.appendChild(ul);
    reapplyDriveTreeSearch(paneId);
  }

  // enablePaneDrag(paneId);
  if (canEditPane(paneId)) {
    enablePaneDrag(paneId);
  }


}




function toggleCollapsed({ li, expander = null, ul = null }) {
  const isCollapsed = li.classList.toggle("collapsed");

  if (!ul) ul = li.querySelector(":scope > ul");
  if (ul) ul.style.display = isCollapsed ? "none" : "block";
  if (expander) expander.textContent = isCollapsed ? "▸" : "▾";

  return isCollapsed;
}



// ------------



/* ================= HANDOFF (GLOBAL) ================= */

function getOtherPaneId(paneId) {
  return paneId === "driveTree" ? "driveTreeB" : "driveTree";
}

function getPreferredTwTargetPaneId(sourcePaneId) {
  const sourceProvider = getProviderForPane(sourcePaneId);
  const otherPaneId = getOtherPaneId(sourcePaneId);
  const otherProvider = getProviderForPane(otherPaneId);

  const sourceIsTw = isTwProvider(sourceProvider);
  const otherIsTw = isTwProvider(otherProvider);

  // TW -> TW: always target the opposite pane.
  if (sourceIsTw && otherIsTw) return otherPaneId;
  if (sourceIsTw) return sourcePaneId;
  if (otherIsTw) return otherPaneId;
  return null;
}

function resolveImportTargetOwner(sourcePaneId) {
  const targetPaneId = getPreferredTwTargetPaneId(sourcePaneId);
  if (targetPaneId) {
    return getActiveTwOwnerForPane(targetPaneId);
  }

  return String(
    window.currentOwner?.username ||
    window.currentOwnerToken ||
    window.SESSION_USERNAME ||
    ""
  ).trim();
}

function normalizeListName(value) {
  return String(value || "")
    .normalize("NFKC")
    .replace(/\s+/g, " ")
    .trim()
    .toLowerCase();
}

function getSelectedTwTargetList(sourcePaneId) {
  function readTargetFromPane(paneId) {
    if (!paneId || !isTwProvider(getProviderForPane(paneId))) return null;
    const state = getPaneState(paneId);
    const activeToken = String(state?.activeTwListToken || "").trim();
    if (activeToken) {
      const li = Array.from(document.querySelectorAll(`#${paneId} li`)).find(
        el => String(el?._driveNode?._twListToken || "").trim() === activeToken
      );
      const firstSelectedNode = Array.from(state?.selectedFolders || [])
        .find(node => node?._twListToken);
      const name =
        String(li?._driveNode?.name || "").trim() ||
        String(firstSelectedNode?.name || "").trim() ||
        activeToken;
      return { token: activeToken, name, paneId };
    }
    const selected = Array.from(state?.selectedFolders || [])
      .filter(node => node?._twListToken);
    if (selected.length !== 1) return null;
    const node = selected[0];
    return {
      token: String(node._twListToken || "").trim(),
      name: String(node.name || "").trim(),
      paneId
    };
  }

  const preferredPaneId = getPreferredTwTargetPaneId(sourcePaneId);
  const sourceIsTw = isTwProvider(getProviderForPane(sourcePaneId));
  const otherPaneId = getOtherPaneId(sourcePaneId);
  const otherIsTw = isTwProvider(getProviderForPane(otherPaneId));

  // In TW <-> TW imports, destination is strictly the opposite pane.
  if (sourceIsTw && otherIsTw && preferredPaneId) {
    return readTargetFromPane(preferredPaneId);
  }

  const candidates = [];
  if (preferredPaneId) {
    candidates.push(preferredPaneId);
  }
  if (
    sourceIsTw &&
    !candidates.includes(sourcePaneId)
  ) {
    candidates.push(sourcePaneId);
  }
  if (
    otherIsTw &&
    !candidates.includes(otherPaneId)
  ) {
    candidates.push(otherPaneId);
  }

  for (const paneId of candidates) {
    const target = readTargetFromPane(paneId);
    if (target?.token) return target;
  }
  return null;
}

async function removeItemFromListQuiet(token, surrogate) {
  if (!token || !surrogate) return;
  try {
    const listContainer = document.querySelector(`.list-contents[data-token='${token}']`);
    const item = listContainer?.querySelector(`.list-sub-item[data-value='${surrogate}']`);
    if (item) item.remove();
  } catch {}
  try {
    await fetch("/removeItemFromList.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: `token=${encodeURIComponent(token)}&surrogate=${encodeURIComponent(surrogate)}`
    });
  } catch {}
}

async function uploadPdfWithVerification(file, surrogate, ownerUser = "") {
  const prevOwner = window.currentItemOwner;
  if (ownerUser) {
    window.currentItemOwner = ownerUser;
  }
  await handleFileUpload(file, surrogate, "pdf");
  if (ownerUser) {
    window.currentItemOwner = prevOwner;
  }
  if (typeof checkIfPDFExists === "function") {
    for (let i = 0; i < 6; i += 1) {
      const exists = await checkIfPDFExists(surrogate);
      if (exists) return;
      await new Promise(r => setTimeout(r, 350));
    }
    throw new Error("Upload did not produce a readable PDF.");
  }
}

function ensureSidebarListNode(listToken, listName = "", ownerToken = "") {
  if (!listToken || typeof window.renderList !== "function") return false;

  const currentSidebarOwner = String(
    window.currentOwner?.username ||
    window.currentOwnerToken ||
    window.currentProfileUsername ||
    ""
  ).trim();
  const targetOwner = String(ownerToken || currentSidebarOwner).trim();
  if (!targetOwner || targetOwner !== currentSidebarOwner) return false;

  const ownerGroupId = `owned-${targetOwner}`;
  const ownerContainer =
    document.getElementById(`lists-by-${ownerGroupId}`) ||
    document.querySelector(`.list-group-wrapper[data-group='${ownerGroupId}'] .group-contents`);
  if (!ownerContainer) return false;

  let listObj =
    window.CACHED_OWNER_LISTS?.[targetOwner]?.owned?.find(
      l => String(l?.token || "") === String(listToken)
    ) || null;

  if (!listObj) {
    listObj = {
      token: listToken,
      title: listName || listToken,
      name: listName || listToken,
      parent_id: null,
      owner_id: window.currentOwner?.id || 0,
      owner_username: targetOwner,
      owner_display_name: window.currentOwner?.display_name || targetOwner,
      access: "private",
      access_level: "private",
      item_count: 0,
      relationship: "owner",
      role_rank: 90,
      items: [],
      children: []
    };
    if (window.CACHED_OWNER_LISTS?.[targetOwner]) {
      window.CACHED_OWNER_LISTS[targetOwner].owned ||= [];
      const exists = window.CACHED_OWNER_LISTS[targetOwner].owned.some(
        l => String(l?.token || "") === String(listToken)
      );
      if (!exists) window.CACHED_OWNER_LISTS[targetOwner].owned.unshift(listObj);
    }
  }

  const node = window.renderList(listObj, 0, "owned");
  if (!node) return false;

  ownerContainer.prepend(node);
  const wrapper = ownerContainer.closest(".list-group-wrapper");
  const section = wrapper?.querySelector(":scope > .group-contents");
  const arrow = wrapper?.querySelector(":scope > .sidebar-section-header .group-arrow");
  if (section) section.style.display = "block";
  if (arrow) arrow.textContent = "▼";
  return true;
}

async function ensureSidebarTargetListVisible(listToken, ownerToken = "", listName = "") {
  if (!listToken) return;

  const hasListInDom = !!document.querySelector(`.group-item[data-group="${listToken}"]`);
  if (!hasListInDom) {
    ensureSidebarListNode(listToken, listName, ownerToken);
  }

  if (typeof window.expandList === "function") {
    try {
      window.expandList(listToken);
    } catch {}
  }
}

function beginImportBatchProgress(totalUploads) {
  if (!(totalUploads > 0)) return;
  window._batchUploadState = {
    active: true,
    total: totalUploads,
    done: 0,
    onTick: (done, total) => {
      const safeTotal = Math.max(1, Number(total || 0));
      const pct = Math.min(100, Math.round((Number(done || 0) * 100) / safeTotal));
      showUploadSpinner(`⏳ Importing ${done}/${safeTotal} files…`, pct, false);
      updateUploadProgress?.(pct);
    }
  };
  showUploadSpinner(`⏳ Importing 0/${totalUploads} files…`, 0, false);
}

function endImportBatchProgress() {
  if (window._batchUploadState?.active) {
    hideUploadSpinner?.();
  }
  window._batchUploadState = null;
}

async function resolveImportTargetList(paneId, pdfNodes, targetOwnerUser) {
  if (!pdfNodes?.length) return { targetList: null, suggestedName: "Imported items" };

  const sourceProvider = getProviderForPane(paneId);
  const otherPaneId = getOtherPaneId(paneId);
  const twToTw =
    isTwProvider(sourceProvider) &&
    isTwProvider(getProviderForPane(otherPaneId));

  const selectedTwTarget = getSelectedTwTargetList(paneId);
  const targetPaneId = getPreferredTwTargetPaneId(paneId) || paneId;
  const folders = getPaneState(targetPaneId)?.selectedFolders;
  let suggestedName = "Imported items";
  let hint = null;

  if (selectedTwTarget?.token && selectedTwTarget?.name) {
    suggestedName = selectedTwTarget.name;
    hint = `Selected target list: “${selectedTwTarget.name}”`;
  } else if (folders?.size === 1) {
    const folder = [...folders][0];
    if (folder?.name) {
      suggestedName = folder.name;
      hint = `Suggested from folder: “${folder.name}”`;
    }
  } else if (!twToTw) {
    const current = getCurrentList();
    if (current?.name || current?.title) {
      suggestedName = current.name || current.title;
    }
  } else {
    hint = "Select exactly one target folder on the other panel.";
  }

  let listExists = false;
  try {
    listExists = !!(await findListByName(suggestedName, targetOwnerUser));
  } catch {}

  const listName = await showDriveImportFolderModal({
    folderName: suggestedName,
    fileCount: pdfNodes.length,
    listExists: listExists || !!selectedTwTarget?.token,
    hint
  });
  if (!listName) return { targetList: null, suggestedName };

  let targetList = null;
  if (
    selectedTwTarget?.token &&
    normalizeListName(listName) === normalizeListName(selectedTwTarget.name)
  ) {
    targetList = {
      token: selectedTwTarget.token,
      name: selectedTwTarget.name
    };
  } else {
    const existing = await findListByName(listName, targetOwnerUser);
    targetList = existing?.token
      ? existing
      : await createContentListQuiet(listName, targetOwnerUser);

    if (!targetList?.token) {
      targetList = await findListByName(listName, targetOwnerUser);
    }
  }

  return { targetList, suggestedName };
}

async function syncTargetListAcrossUi(targetList, targetOwnerUser, fallbackName = "") {
  if (!targetList?.token) return;
  ["driveTree", "driveTreeB"].forEach(pid => {
    if (!isTwProvider(getProviderForPane(pid))) return;
    addTwListToTree(pid, targetList, targetOwnerUser);
    setSelectedTwListInPane(pid, targetList.token);
  });
  await ensureSidebarTargetListVisible(
    targetList.token,
    targetOwnerUser,
    targetList.name || fallbackName
  );
}


window.driveCommitImport = async function (paneId) {
  let createNewItemForAudio = false;
  const targetOwnerUser = resolveImportTargetOwner(paneId);


  const prevPane = window.activeDriveTreeId;
  window.activeDriveTreeId = paneId;

  try {
    const orderedNodes = getCheckedDriveNodesInOrder(paneId);
    const files = orderedNodes.length ? orderedNodes : getCheckedDriveFiles(paneId);
    if (!files.length) {
      alert("No files selected.");
      return;
    }

    const driveProvider = getProviderForPane(paneId);

    if (driveProvider === "google" && !window.driveAccessToken) {
      alert("Google Drive not connected.");
      return;
    }

    const targetSurrogate = window.currentSurrogate || null;

    const audioNodes = orderedNodes.length
      ? orderedNodes.filter(isAudioNode)
      : files.filter(isAudioNode);
    const pdfNodes = orderedNodes.length
      ? orderedNodes.filter(isPdfNode)
      : files.filter(isPdfNode);
    const textNodes = orderedNodes.length
      ? orderedNodes.filter((node) => isTextNode(node) && !isPdfNode(node) && !isAudioNode(node))
      : files.filter((node) => isTextNode(node) && !isPdfNode(node) && !isAudioNode(node));

    const totalPlannedUploads =
      pdfNodes.filter(n => !n?._twSurrogate).length +
      audioNodes.length +
      textNodes.length;
    beginImportBatchProgress(totalPlannedUploads);
    const batchTick = (status = "ok", fileName = "") => {
      const batchState = window._batchUploadState;
      if (!batchState?.active) return;
      batchState.done = Number(batchState.done || 0) + 1;
      if (typeof batchState.onTick === "function") {
        batchState.onTick(batchState.done, Number(batchState.total || 0), status, fileName);
      }
    };

    /* ================= AUDIO CONFIRMATION ================= */

  const hasMixedFolderImport = audioNodes.length && pdfNodes.length;

  if (audioNodes.length && !hasMixedFolderImport) {

    const result = await showDriveImportAudioModal({
      targetSurrogate,
      audioNodes
    });
    if (!result?.ok) return;

    createNewItemForAudio = !!result.createNewItem;

    // Only require target when NOT creating new items
    if (!createNewItemForAudio && !targetSurrogate) {
      if (!window._suppressImportAlerts) {
        alert("Select a TextWhisper item before importing audio.");
      }
      return;
    }
  }


    /* ================= RESOLVE TARGET LIST (PDFs) ================= */
    let targetList = null;
    let suggestedName = "Imported items";
    const listTargetNodes = [...pdfNodes, ...textNodes];
    if (listTargetNodes.length) {
      const resolved = await resolveImportTargetList(paneId, listTargetNodes, targetOwnerUser);
      targetList = resolved.targetList;
      suggestedName = resolved.suggestedName;
      if (!targetList?.token) {
        alert("Could not resolve target list.");
        return;
      }
      await syncTargetListAcrossUi(targetList, targetOwnerUser, suggestedName);
    }

    /* ================= IMPORT ================= */

    const listToken = targetList?.token || getCurrentList?.()?.token;

    const normalizeBase = (name) =>
      String(name || "")
        .normalize("NFKD")
        .replace(/\p{Diacritic}/gu, "")
        .replace(/\.[^.]+$/, "")
        .toLowerCase()
        .replace(/[^\p{L}0-9]+/gu, " ")
        .trim();

    const pdfSorted = [...pdfNodes].sort((a, b) =>
      normalizeBase(a.name).localeCompare(normalizeBase(b.name))
    );
    const audioSorted = [...audioNodes].sort((a, b) =>
      normalizeBase(a.name).localeCompare(normalizeBase(b.name))
    );
    const audioGroups = new Map();
    audioSorted.forEach(node => {
      const first = normalizeBase(node.name).split(" ").filter(Boolean)[0] || "";
      if (!first) return;
      if (!audioGroups.has(first)) audioGroups.set(first, []);
      audioGroups.get(first).push(node);
    });

    for (const node of pdfSorted) {
      const base = normalizeBase(node.name);
      let surr = node._twSurrogate || null;

      if (node._twSurrogate) {
        if (targetList?.token) {
          const sid = parseInt(String(node._twSurrogate || "").trim(), 10) || 0;
          if (sid > 0) {
            await fetch("/addItemToList.php", {
              method: "POST",
              headers: { "Content-Type": "application/x-www-form-urlencoded" },
              body: `token=${encodeURIComponent(targetList.token)}&surrogate=${encodeURIComponent(sid)}&order=0`,
              credentials: "include"
            }).catch(() => {});
          }
          const title = node.name.replace(/\.[^.]+$/, "").trim();
          notifyTwTreeNewItem(targetList.token, node._twSurrogate, title, targetOwnerUser);
        }
      } else {
        const blob = await downloadCurrentDriveFile(node);
        if (!blob) continue;

        try {
          surr = await importPdfFile(
            new File([blob], node.name, { type: "application/pdf" }),
            targetList.token,
            targetOwnerUser
          );
          batchTick("ok", node?.name || "");
        } catch (err) {
          batchTick("error", node?.name || "");
          console.warn("Import PDF failed:", node?.name, err?.message || err);
          showFlashMessage?.(`⚠️ Could not import ${node?.name || "file"}`);
          continue;
        }
        if (surr) {
          const title = node.name.replace(/\.[^.]+$/, "").trim();
          notifyTwTreeNewItem(targetList.token, surr, title, targetOwnerUser);
        }
      }

      if (hasMixedFolderImport && surr) {
        const pdfFirst = base.split(" ").filter(Boolean)[0] || "";
        const group = pdfFirst ? audioGroups.get(pdfFirst) : null;
        if (group && group.length) {
          for (const audioNode of group) {
            const blob = await downloadCurrentDriveFile(audioNode);
            if (!blob) continue;
            await handleFileUpload(
              new File([blob], audioNode.name, { type: blob.type || "audio/mpeg" }),
              surr,
              "audio"
            );
          }
          audioGroups.delete(pdfFirst);
        }
      }
    }

    const textSorted = [...textNodes].sort((a, b) =>
      normalizeBase(a.name).localeCompare(normalizeBase(b.name))
    );
    const preferCurrentForSingleText = textSorted.length === 1;
    for (const node of textSorted) {
      if (!listToken) {
        alert("No list selected.");
        return;
      }

      try {
        const result = await importTextNodeSmart(node, listToken, targetOwnerUser, {
          preferCurrent: preferCurrentForSingleText,
          sourcePaneId: paneId
        });
        const surrogate = result?.surrogate;
        if (surrogate && !result?.created) {
          const sid = parseInt(String(surrogate || "").trim(), 10) || 0;
          if (sid > 0) {
            await fetch("/addItemToList.php", {
              method: "POST",
              headers: { "Content-Type": "application/x-www-form-urlencoded" },
              body: `token=${encodeURIComponent(listToken)}&surrogate=${encodeURIComponent(sid)}&order=0`,
              credentials: "include"
            }).catch(() => {});
          }
          notifyTwTreeNewItem(
            listToken,
            surrogate,
            result.title || buildTextImportTitle(node?.name),
            targetOwnerUser
          );
        }
        if (result?.created && surrogate) {
          notifyTwTreeNewItem(listToken, surrogate, result.title || buildTextImportTitle(node?.name), targetOwnerUser);
        }
        batchTick("ok", node?.name || "");
      } catch (err) {
        batchTick("error", node?.name || "");
        console.warn("Import text failed:", node?.name, err?.message || err);
        showFlashMessage?.(`⚠️ Could not import text from ${node?.name || "file"}`);
      }
    }

    if (!hasMixedFolderImport) {
      for (let i = 0; i < audioSorted.length; i += 1) {
        const node = audioSorted[i];
        const blob = await downloadCurrentDriveFile(node);
        if (!blob) continue;

        let surr = targetSurrogate;

        if (createNewItemForAudio) {
          if (!listToken) { alert("No list selected."); return; }
          const title = node.name.replace(/\.[^.]+$/, "").trim();
          surr = await createNewItemForPDF(listToken, title, targetOwnerUser, 0);
          if (surr) {
            notifyTwTreeNewItem(listToken, surr, title, targetOwnerUser);
          }
        }

        if (!surr) continue;

        await handleFileUpload(
          new File([blob], node.name, { type: blob.type || "audio/mpeg" }),
          surr,
          "audio"
        );
      }
    } else if (audioGroups.size) {
      for (const group of audioGroups.values()) {
        for (const node of group) {
          const blob = await downloadCurrentDriveFile(node);
          if (!blob) continue;
          if (!listToken) { alert("No list selected."); return; }
          const title = node.name.replace(/\.[^.]+$/, "").trim();
          const surr = await createNewItemForPDF(listToken, title, targetOwnerUser, 0);
          if (!surr) continue;
          notifyTwTreeNewItem(listToken, surr, title, targetOwnerUser);
          await handleFileUpload(
            new File([blob], node.name, { type: blob.type || "audio/mpeg" }),
            surr,
            "audio"
          );
        }
      }
    }

    showFlashMessage?.("✅ Import completed");

  } finally {
    endImportBatchProgress();
    window.activeDriveTreeId = prevPane;
  }
};

function notifyTwTreeNewItem(listToken, surrogate, title, ownerUser, order = 0) {
  if (!listToken || !surrogate) return;
  const paneIds = ["driveTree", "driveTreeB"];
  paneIds.forEach(pid => {
    if (!isTwProvider(getProviderForPane(pid))) return;
    addTwItemToTree(pid, listToken, {
      surrogate,
      title: title || "Untitled",
      owner: ownerUser || null
    }, order);
  });
  syncSidebarItemLikeExternalDrop(listToken, surrogate, title, ownerUser, order);
}

function syncSidebarItemLikeExternalDrop(listToken, surrogate, title, ownerUser = "", order = 0) {
  const token = String(listToken || "").trim();
  const surr = String(surrogate || "").trim();
  if (!token || !surr) return;

  const listContainer = document.getElementById(`list-${token}`);
  if (!listContainer || listContainer.style.display !== "block") return;

  let itemsWrapper = listContainer.querySelector(".list-items-wrapper");
  if (!itemsWrapper) {
    itemsWrapper = document.createElement("div");
    itemsWrapper.className = "list-items-wrapper";
    listContainer.appendChild(itemsWrapper);
  }

  const existing = itemsWrapper.querySelector(`.list-sub-item[data-value="${CSS.escape(surr)}"]`);
  if (existing) return;

  const emptyRow = itemsWrapper.querySelector(".list-sub-item.text-muted");
  if (emptyRow) emptyRow.remove();

  if (typeof renderSingleListItemHTML !== "function") return;

  const rowHtml = renderSingleListItemHTML(
    {
      surrogate: surr,
      owner: ownerUser || window.currentOwner?.username || "",
      display_name: window.currentOwner?.display_name || ownerUser || "",
      title: String(title || "").trim() || `Item ${surr}`,
      fileserver: "cloudflare",
      role_rank: 90
    },
    token
  );

  const temp = document.createElement("div");
  temp.innerHTML = String(rowHtml || "").trim();
  const row = temp.firstElementChild;
  if (!row) return;

  const existingRows = Array.from(itemsWrapper.querySelectorAll(".list-sub-item[data-value]"));
  const nextIndex = existingRows.length + 1;
  row.dataset.orderIndex = String(nextIndex);
  const ownerEl = row.querySelector(".item-owner");
  if (ownerEl && !ownerEl.querySelector(".item-order")) {
    const orderEl = document.createElement("span");
    orderEl.className = "item-order";
    orderEl.textContent = `${nextIndex}.`;
    ownerEl.prepend(orderEl, document.createTextNode(" "));
  }

  row.classList.add("just-added");
  const desiredPos = Math.max(0, parseInt(order, 10) || 0);
  if (desiredPos > 0) {
    const rows = Array.from(itemsWrapper.querySelectorAll(".list-sub-item[data-value]"));
    const anchor = rows[desiredPos - 1] || null;
    if (anchor) {
      itemsWrapper.insertBefore(row, anchor);
    } else {
      itemsWrapper.appendChild(row);
    }
  } else {
    itemsWrapper.appendChild(row);
  }
  if (typeof updateListItemOrderNumbers === "function") {
    updateListItemOrderNumbers(itemsWrapper, true);
  }
  setTimeout(() => row.classList.remove("just-added"), 2500);
}

async function createContentListQuiet(name, ownerUser = null) {
  if (!window.SESSION_USERNAME) return null;

  const token = String(ownerUser || "").trim();
  const ownerParam = token && token !== window.SESSION_USERNAME
    ? `&owner=${encodeURIComponent(token)}`
    : (typeof getCreateListOwnerParam === "function" ? getCreateListOwnerParam() : "");

  const res = await fetch("/createContentList.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: `name=${encodeURIComponent(name)}${ownerParam}`
  });

  const result = await res.text();
  if (!res.ok || result !== "OK") {
    try {
      const existing = await findListByName(name, token);
      if (existing?.token) return existing;
    } catch {}
    return null;
  }

  const listsRes = await fetch(
    token ? `/getUserLists.php?owner=${encodeURIComponent(token)}` : "/getUserLists.php"
  );
  if (!listsRes.ok) return null;
  const lists = await listsRes.json();
  const created = lists.find(l => normalizeListName(l.name) === normalizeListName(name));
  return created || null;
}

function addTwListToTree(paneId, list, ownerUser = null) {
  const tree = document.getElementById(paneId);
  if (!tree || !list?.token) return null;
  if (!isTwProvider(getProviderForPane(paneId))) return null;
  if (ownerUser && ownerUser !== getActiveTwOwnerForPane(paneId)) return null;

  const existing = Array.from(tree.querySelectorAll("li")).find(
    li => li?._driveNode?._twListToken === list.token
  );
  if (existing) return existing;

  const rootUl = tree.querySelector("ul");
  if (!rootUl) return null;

  const node = {
    name: list.name || list.title || "Untitled list",
    mimeType: "application/vnd.google-apps.folder",
    _twListToken: list.token,
    _twIsListRoot: true,
    children: []
  };

  const li = driveRenderNode(node, paneId);
  if (!li) return;

  let ul = li.querySelector("ul");
  if (!ul) {
    ul = document.createElement("ul");
    li.appendChild(ul);
  }

  li.classList.remove("collapsed");
  ul.style.display = "block";

  const hint = document.createElement("li");
  hint.className = "tw-drop-hint";
  hint.textContent = "drop here";
  ul.appendChild(hint);

  const set = getExpandedListSet(paneId);
  if (set) set.add(list.token);

  const name = String(node.name || "").toLowerCase();
  const siblings = Array.from(rootUl.children).filter(
    el => el?._driveNode?._twIsListRoot
  );
  const before = siblings.find(el => {
    const n = String(el._driveNode?.name || "").toLowerCase();
    return n.localeCompare(name) > 0;
  });

  if (before) {
    rootUl.insertBefore(li, before);
  } else {
    rootUl.appendChild(li);
  }
  enablePaneDrag(paneId);
  return li;
}

function setSelectedTwListInPane(paneId, listToken) {
  const tree = document.getElementById(paneId);
  const state = getPaneState(paneId);
  if (!tree || !state || !listToken) return;

  state.selectedFiles?.clear();
  state.selectedFolders?.clear();

  tree.querySelectorAll('input[type="checkbox"]').forEach(cb => {
    cb.checked = false;
  });

  const listLi = Array.from(tree.querySelectorAll("li")).find(
    li => li?._driveNode?._twListToken === listToken
  );
  if (!listLi) return;

  const cb = listLi.querySelector(':scope > input[type="checkbox"]');
  if (cb) cb.checked = true;
  if (listLi._driveNode) state.selectedFolders.add(listLi._driveNode);
  setActiveTwListTokenInPane(paneId, listToken);
}

function addTwItemToTree(paneId, listToken, item, order = 0) {
  const tree = document.getElementById(paneId);
  if (!tree) return;

  const listLi = Array.from(tree.querySelectorAll("li")).find(
    li => li?._driveNode?._twListToken === listToken
  );
  if (!listLi) return;

  const listNode = listLi._driveNode;
  listNode.children ||= [];

  const alreadyInModel = listNode.children.some(
    n => String(n._twSurrogate || "") === String(item.surrogate)
  );

  const childNode = {
    name: item.title,
    mimeType: "application/pdf",
    surrogate: item.surrogate,
    _twSurrogate: item.surrogate,
    _twOwner: item.owner || null
  };

  if (!alreadyInModel) {
    listNode.children.push(childNode);
  }

  if (listNode._loaded && !listLi.classList.contains("collapsed")) {
    let ul = listLi.querySelector("ul");
    if (!ul) {
      ul = document.createElement("ul");
      listLi.appendChild(ul);
    }
    const hint = ul.querySelector(".tw-drop-hint");
    if (hint) hint.remove();
    const surr = String(item.surrogate || "");
    const existingLi = Array.from(ul.children).find(
      row => String(row?._driveNode?._twSurrogate || "") === surr
    );
    const desiredPos = Math.max(0, parseInt(order, 10) || 0);
    if (existingLi) {
      if (desiredPos > 0) {
        placeLiAtPdfOrder(ul, existingLi, desiredPos);
      }
      return;
    }
    const rendered = driveRenderNode(childNode, paneId);
    if (!rendered) return;
    if (desiredPos > 0) {
      placeLiAtPdfOrder(ul, rendered, desiredPos);
    } else {
      ul.appendChild(rendered);
    }
  }
}



// async function downloadCurrentDriveFile(node) {
//   const driveProvider = getProviderForPane(window.activeDriveTreeId);

//   let res;

//   if (driveProvider === "google") {
//     res = await fetch(
//       `https://www.googleapis.com/drive/v3/files/${node.id}?alt=media`,
//       { headers: { Authorization: `Bearer ${window.driveAccessToken}` } }
//     );
//   }
//   else if (driveProvider === "dropbox") {
//     res = await fetch(
//       `/File_downloadDropbox.php?path=${encodeURIComponent(node.path)}`
//     );
//   }
//   else if (driveProvider === "icloud") {
//     res = await fetch(
//       `/File_downloadICloud.php?path=${encodeURIComponent(node.path || node.id)}`
//     );
//   }
//   else {
//     console.warn("Unsupported provider:", driveProvider);
//     return null;
//   }

//   if (!res.ok) return null;
//   return await res.blob();
// }

async function downloadCurrentDriveFile(node, paneId = window.activeDriveTreeId) {
  let driveProvider = getProviderForPane(paneId);
  if (isTwProvider(driveProvider) && node?._sourceProvider && !isTwProvider(node._sourceProvider)) {
    driveProvider = node._sourceProvider;
  }
  let res;

  if (isTwProvider(driveProvider)) {
    const key = node?._twAudioKey;
    if (key) {
      res = await fetch(
        `https://r2-worker.textwhisper.workers.dev/${encodeURI(key)}`
      );
      if (!res.ok) {
        res = await fetch(
          `https://r2-worker.textwhisper.workers.dev/?key=${encodeURIComponent(key)}`
        );
      }
    } else if (isPdfNode(node) && (node?._twSurrogate || node?.surrogate)) {
      const surrogate = String(node._twSurrogate || node.surrogate || "").trim();
      const sidebarOwner = (() => {
        const row = document.querySelector(`.list-sub-item[data-value="${surrogate}"]`);
        return String(row?.dataset?.owner || "").trim();
      })();
      const ownerCandidates = [
        node?._twOwner,
        node?.owner,
        node?.owner_username,
        sidebarOwner,
        window.currentItemOwner,
        window.currentOwnerToken,
        window.currentProfileUsername,
        getActiveTwOwnerForPane(paneId),
        window.currentListOwnerUsername,
        window.currentOwner?.username,
        window.SESSION_USERNAME
      ]
        .map(v => String(v || "").trim())
        .filter(Boolean)
        .filter((v, i, arr) => arr.indexOf(v) === i);
      if (!surrogate || !ownerCandidates.length) return null;

      for (const owner of ownerCandidates) {
        const cloudTry = await fetch(
          `https://r2-worker.textwhisper.workers.dev/${encodeURIComponent(owner)}/pdf/temp_pdf_surrogate-${encodeURIComponent(surrogate)}.pdf`
        );
        if (cloudTry.ok) {
          res = cloudTry;
          break;
        }
      }
    } else {
      console.warn("Unsupported provider: tw");
      return null;
    }
  }
  else if (driveProvider === "google") {
    const authHeaders = { Authorization: `Bearer ${window.driveAccessToken}` };
    if (String(node?.mimeType || "").toLowerCase() === "application/vnd.google-apps.document") {
      res = await fetch(
        `https://www.googleapis.com/drive/v3/files/${node.id}/export?mimeType=${encodeURIComponent("text/plain")}`,
        { headers: authHeaders }
      );
    } else {
      res = await fetch(
        `https://www.googleapis.com/drive/v3/files/${node.id}?alt=media`,
        { headers: authHeaders }
      );
    }
  } 
  else if (driveProvider === "dropbox") {
    res = await fetch(
      `/File_downloadDropbox.php?path=${encodeURIComponent(node.path)}`
    );
  } 
  else if (driveProvider === "onedrive") {
    res = await fetch(
      `/File_downloadOneDrive.php?itemId=${encodeURIComponent(node.id)}`
    );
  }
  else if (driveProvider === "icloud") {
    res = await fetch(
      `/File_downloadICloud.php?path=${encodeURIComponent(node.path || node.id)}`
    );
  }
  else if (driveProvider === "local") {
    if (node?._localFile instanceof File) {
      return node._localFile;
    }
    return null;
  }
  else {
    console.warn("Unsupported provider:", driveProvider);
    return null;
  }

  if (!res?.ok) return null;
  return await res.blob();
}




async function importPdfFile(file, listToken = null, ownerUser = "") {
  const token = listToken || getCurrentList()?.token;
  if (!token) {
    throw new Error("No list selected.");
  }

  const title = (file.name || "")
    .replace(/\.pdf$/i, "")
    .trim();

  const surrogate = await createNewItemForPDF(token, title, ownerUser, 0);
  if (!surrogate) {
    throw new Error("Item creation failed.");
  }

  try {
    await uploadPdfWithVerification(file, surrogate, ownerUser);
    return surrogate;
  } catch (err) {
    await removeItemFromListQuiet(token, surrogate);
    showFlashMessage?.(`⚠️ Upload failed for "${file.name}". Item link was removed.`);
    throw err;
  }
}

function plainTextToSafeHtml(inputText) {
  const normalized = String(inputText || "").replace(/\r\n?/g, "\n");
  if (!normalized) return "";
  return escapeHtml(normalized).replace(/\n/g, "<br>");
}

function buildTextImportTitle(name) {
  return String(name || "Imported text")
    .replace(/\.(txt|md|markdown|docx)$/i, "")
    .trim() || "Imported text";
}

function normalizeImportTitle(name) {
  return String(name || "")
    .toLowerCase()
    .normalize("NFKD")
    .replace(/[\u0300-\u036f]/g, "")
    .replace(/\.(pdf|docx?|txt|md|markdown)$/i, "")
    .replace(/[^\p{L}\p{N}\s]/gu, " ")
    .replace(/\s+/g, " ")
    .trim();
}

function extractItemTitleFromRow(row) {
  if (!row) return "";
  const subject = row.querySelector(".item-subject")?.textContent || "";
  if (subject.trim()) return subject.replace(/^\s*•\s*/, "").trim();
  const title = row.querySelector(".item-title")?.textContent || "";
  return title.replace(/^\s*•\s*/, "").trim();
}

function findMatchingItemSurrogateByTitle(listToken, title) {
  if (!listToken) return null;
  const wanted = normalizeImportTitle(title);
  if (!wanted) return null;

  const rows = Array.from(document.querySelectorAll(`.list-sub-item[data-token="${listToken}"]`));
  let fallback = null;
  for (const row of rows) {
    const rowSurrogate = String(row.dataset.value || "").trim();
    if (!rowSurrogate) continue;
    const rowTitle = extractItemTitleFromRow(row);
    const normalized = normalizeImportTitle(rowTitle);
    if (!normalized) continue;
    if (normalized === wanted) return rowSurrogate;
    if (!fallback && (normalized.startsWith(wanted) || wanted.startsWith(normalized))) {
      fallback = rowSurrogate;
    }
  }
  return fallback;
}

function resolveTextImportTargetSurrogate(listToken, title, preferCurrent = false) {
  const current = String(window.currentSurrogate || "").trim();
  if (preferCurrent && current && current !== "0") return current;

  const byName = findMatchingItemSurrogateByTitle(listToken, title);
  if (byName) return byName;

  if (current && current !== "0") return current;
  return null;
}

async function loadItemHtmlForMerge(surrogate) {
  const s = String(surrogate || "").trim();
  if (!s || s === "0") return "";
  if (String(window.currentSurrogate || "") === s) {
    const area = document.getElementById("myTextarea");
    if (area) {
      const html = String(area.innerHTML || "");
      if (typeof sanitizeForSave === "function") {
        try {
          return String(sanitizeForSave(html) || "");
        } catch {}
      }
      return html;
    }
  }
  try {
    const res = await fetch(`/getText.php?q=${encodeURIComponent(s)}`, { credentials: "include" });
    if (!res.ok) return "";
    return await res.text();
  } catch {
    return "";
  }
}

function buildMergedImportedHtml(existingHtml, importedHtml, sourceName) {
  const existing = String(existingHtml || "").trim();
  const incoming = String(importedHtml || "").trim();
  if (!existing) return incoming;
  if (!incoming) return existing;

  const source = escapeHtml(sourceName || "text file");

  const container = document.createElement("div");
  container.innerHTML = existing;

  const isMeaningfulNode = (node) => {
    if (!node) return false;
    if (node.nodeType === Node.TEXT_NODE) {
      return String(node.textContent || "").replace(/\u00a0/g, " ").trim() !== "";
    }
    if (node.nodeType !== Node.ELEMENT_NODE) return false;

    const el = node;
    const html = String(el.innerHTML || "");
    if (/(img|svg|canvas|iframe|object|embed|video|audio)/i.test(html)) return true;

    const text = String(el.textContent || "").replace(/\u00a0/g, " ").trim();
    return text !== "";
  };

  const isEmptyLineNode = (node) => {
    if (!node) return false;
    if (node.nodeType === Node.TEXT_NODE) {
      return String(node.textContent || "").replace(/\u00a0/g, " ").trim() === "";
    }
    if (node.nodeType !== Node.ELEMENT_NODE) return false;
    const el = node;
    const text = String(el.textContent || "").replace(/\u00a0/g, " ").trim();
    if (text !== "") return false;
    const html = String(el.innerHTML || "").trim();
    if (!html) return true;
    return /^(\s|&nbsp;|<br\s*\/?>|<span[^>]*>\s*<\/span>)*$/i.test(html);
  };

  const nodes = Array.from(container.childNodes || []);
  const titleIndex = nodes.findIndex(isMeaningfulNode);

  let insertAfter = titleIndex;
  if (titleIndex >= 0) {
    for (let i = titleIndex + 1; i < nodes.length; i += 1) {
      if (isEmptyLineNode(nodes[i])) {
        insertAfter = i;
        break;
      }
    }
  } else {
    insertAfter = nodes.length - 1;
  }

  const marker = document.createElement("div");
  marker.innerHTML = `<strong>Imported from ${source}</strong>`;

  const spacer = document.createElement("div");
  spacer.innerHTML = "<br>";

  const incomingTpl = document.createElement("template");
  incomingTpl.innerHTML = incoming;

  const fragment = document.createDocumentFragment();
  fragment.appendChild(marker);
  fragment.appendChild(spacer);
  fragment.append(...Array.from(incomingTpl.content.childNodes));

  const refNode = container.childNodes[insertAfter + 1] || null;
  container.insertBefore(fragment, refNode);

  return container.innerHTML;
}

function findSidebarRowBySurrogate(surrogate) {
  const s = String(surrogate || "").trim();
  if (!s) return null;
  return document.querySelector(`.list-sub-item[data-value="${s}"]`);
}

async function saveItemHtmlToSurrogate(surrogate, dataname, html, ownerUser = "") {
  const body = new URLSearchParams({
    dataname: String(dataname || "Untitled").trim() || "Untitled",
    surrogate: String(surrogate || ""),
    text: String(html || "")
  });
  if (ownerUser) body.set("owner", String(ownerUser));

  const res = await fetch("/datainsert.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: body.toString()
  });

  if (!res.ok) {
    throw new Error(`Text save failed (${res.status})`);
  }
  const saved = (await res.text()).trim();
  if (!saved || String(saved) !== String(surrogate)) {
    throw new Error("Text save rejected by server.");
  }
}

function isDocxBlobOrNode(blob, node) {
  const mime = String(blob?.type || node?.mimeType || "").toLowerCase();
  const name = String(node?.name || "").toLowerCase();
  return mime === "application/vnd.openxmlformats-officedocument.wordprocessingml.document"
    || /\.docx$/i.test(name);
}

async function extractDocxTextFromBlob(blob, fileName = "import.docx") {
  const fd = new FormData();
  const safeName = /\.docx$/i.test(String(fileName || "")) ? String(fileName) : "import.docx";
  fd.append("file", blob, safeName);

  const res = await fetch("/api/extract_docx_text.php", {
    method: "POST",
    credentials: "include",
    body: fd
  });
  if (!res.ok) {
    throw new Error(`DOCX extract failed (${res.status})`);
  }
  const data = await res.json().catch(() => null);
  if (!data || data.status !== "ok") {
    throw new Error(data?.error || "DOCX extract failed");
  }
  return String(data.text || "");
}

async function importTextNodeSmart(node, listToken, ownerUser = "", options = {}) {
  const preferCurrent = !!options.preferCurrent;
  const forceNew = !!options.forceNew;
  const forceOrder = Math.max(0, parseInt(options.forceOrder, 10) || 0);
  const forcedSurrogate = String(options.forceSurrogate || "").trim();
  const sourcePaneId = options.sourcePaneId || window.activeDriveTreeId;
  const blob = await downloadCurrentDriveFile(node, sourcePaneId);
  if (!blob) throw new Error("Could not download text source.");

  const title = buildTextImportTitle(node?.name);
  const textContent = isDocxBlobOrNode(blob, node)
    ? await extractDocxTextFromBlob(blob, node?.name || "import.docx")
    : await blob.text();
  const importedHtml = plainTextToSafeHtml(textContent);

  let surrogate = forceNew
    ? ""
    : (forcedSurrogate || resolveTextImportTargetSurrogate(listToken, title, preferCurrent));
  let created = false;

  if (!surrogate) {
    surrogate = await createNewItemForPDF(listToken, title, ownerUser, forceOrder);
    if (!surrogate) throw new Error("Item creation failed.");
    created = true;
  }

  const existingRow = findSidebarRowBySurrogate(surrogate);
  const preservedTitle = extractItemTitleFromRow(existingRow);
  const dataname = created ? title : (preservedTitle || title);

  const existingHtml = created ? "" : await loadItemHtmlForMerge(surrogate);
  const mergedHtml = created
    ? importedHtml
    : buildMergedImportedHtml(existingHtml, importedHtml, node?.name || title);

  await saveItemHtmlToSurrogate(surrogate, dataname, mergedHtml, ownerUser);
  return { surrogate, created, title: dataname };
}





function getDriveImportTargetLabel(paneId = window.activeDriveTreeId) {

  //must do that better: update when user selects new target, ...or just skip this
  return "";
  //must do that better, was not always true so ...:

  const state = getPaneState(paneId);
  const folders = state.selectedFolders;
  const list = getCurrentList();

  if (folders && folders.size) {
    return "📁 A new list will be created from selected folder(s)";
  }

  if (isRealList(list)) {
    return `📄 Import into list: ${list.name || list.title}`;
  }

  return "⚠️ No target list selected";
}



function isRealList(list) {
  return list?.token && list.token !== window.SESSION_USERNAME;
}



function getSelectedItemLabel(surrogate) {
  if (!surrogate) return "(none)";

  const row = document.querySelector(`.list-sub-item[data-value="${surrogate}"]`);
  if (!row) return `Surrogate ${surrogate}`;

  // 1️⃣ Prefer explicit title node if it exists
  const titleEl =
    row.querySelector(".item-title") ||
    row.querySelector(".list-sub-item-title") ||
    row.querySelector(".item-label");

  if (titleEl && titleEl.textContent) {
    return `${titleEl.textContent.trim()} (${surrogate})`;
  }

  // 2️⃣ Fallback: first text node only (not menu text)
  for (const node of row.childNodes) {
    if (node.nodeType === Node.TEXT_NODE && node.textContent.trim()) {
      return `${node.textContent.trim()} (${surrogate})`;
    }
  }

  // 3️⃣ Last resort
  return `Surrogate ${surrogate}`;
}


function getSelectedListLabel() {
  const list = getCurrentList?.();
  const name = list?.name || list?.title || "";
  const token = list?.token || "";
  if (!token) return "(none)";
  return name ? `${name} (${token})` : token;
}

function showDriveImportAudioModal({ targetSurrogate, audioNodes }) {
  return new Promise(resolve => {
    const overlay = document.createElement("div");
    overlay.style.cssText = `
      position:fixed; inset:0;
      background:rgba(0,0,0,0.45);
      display:flex; align-items:center; justify-content:center;
      z-index:100000;
    `;

    const modal = document.createElement("div");
    modal.style.cssText = `
      background:#fff;
      padding:18px 20px;
      border-radius:8px;
      max-width:520px;
      width:92%;
      box-shadow:0 10px 30px rgba(0,0,0,.3);
    `;

    const itemLabel = getSelectedItemLabel(targetSurrogate);
    const listLabel = getSelectedListLabel();

    const fileList = audioNodes
      .map(n => `<li style="margin:4px 0;">${escapeHtml(n.name || "(audio)")}</li>`)
      .join("");

    modal.innerHTML = `
      <h3 style="margin:0 0 10px 0;">Import audio into selected item</h3>

      <div style="font-size:13px; color:#444; margin-bottom:10px;">
        <div><strong>Selected list:</strong> ${escapeHtml(listLabel)}</div>
        <div style="margin-top:6px;"><strong>Target item:</strong> ${escapeHtml(itemLabel)}</div>
      </div>

      <div style="margin:10px 0 6px 0;">
        The following <strong>${audioNodes.length}</strong> audio file(s) will be uploaded to this item:
      </div>

      <div style="margin-top:10px;">
        <label style="font-size:13px; cursor:pointer;">
          <input type="checkbox" id="audioCreateNewItem" />
          Create a new item for each audio file (in selected list)
        </label>
      </div>
      
      <div style="max-height:180px; overflow:auto; border:1px solid #ddd; border-radius:6px; padding:8px 10px;">
        <ul style="margin:0; padding-left:18px;">${fileList}</ul>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:16px;">
        <button id="audioImportCancel">Cancel</button>
        <button id="audioImportConfirm">Import audio</button>
      </div>
    `;

    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    modal.querySelector("#audioImportCancel").onclick = () => {
      overlay.remove();
      resolve(false);
    };

    // modal.querySelector("#audioImportConfirm").onclick = () => {
    //   overlay.remove();
    //   resolve(true);
    // };

    modal.querySelector("#audioImportConfirm").onclick = () => {
      const createNewItem =
        modal.querySelector("#audioCreateNewItem").checked;

      overlay.remove();
      resolve({ ok: true, createNewItem });
    };


    overlay.onclick = e => {
      if (e.target === overlay) {
        overlay.remove();
        resolve(false);
      }
    };
  });
}

// small helper: safe HTML in modal
function escapeHtml(s) {
  return String(s || "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}

function showTextDropTargetModal({ fileName, parentTitle }) {
  return new Promise((resolve) => {
    const overlay = document.createElement("div");
    overlay.style.cssText = `
      position:fixed;
      inset:0;
      background:rgba(0,0,0,0.45);
      display:flex;
      align-items:center;
      justify-content:center;
      z-index:100000;
    `;

    const modal = document.createElement("div");
    modal.style.cssText = `
      background:#fff;
      padding:18px 20px;
      border-radius:8px;
      max-width:460px;
      width:90%;
      box-shadow:0 10px 30px rgba(0,0,0,.3);
      font-size:14px;
    `;

    modal.innerHTML = `
      <div style="font-size:16px; font-weight:600; margin-bottom:8px;">
        Import text file
      </div>
      <div style="margin-bottom:12px; color:#333;">
        <div><strong>File:</strong> ${escapeHtml(fileName || "text file")}</div>
        <div><strong>Drop target:</strong> ${escapeHtml(parentTitle || "item")}</div>
      </div>
      <div style="display:flex; flex-direction:column; gap:8px; margin-bottom:14px;">
        <button id="textDropToParent" style="text-align:left; padding:10px 12px; border:1px solid #d0d0d0; border-radius:8px; background:#f8fbff; cursor:pointer;">
          Add text to parent item
        </button>
        <button id="textDropCreateNew" style="text-align:left; padding:10px 12px; border:1px solid #d0d0d0; border-radius:8px; background:#fff; cursor:pointer;">
          Create new item from file
        </button>
      </div>
      <div style="display:flex; justify-content:flex-end; gap:8px;">
        <button id="textDropCancel">Cancel</button>
      </div>
    `;

    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    modal.querySelector("#textDropToParent").onclick = () => {
      overlay.remove();
      resolve("nested");
    };
    modal.querySelector("#textDropCreateNew").onclick = () => {
      overlay.remove();
      resolve("new");
    };
    modal.querySelector("#textDropCancel").onclick = () => {
      overlay.remove();
      resolve("cancel");
    };
    overlay.onclick = (e) => {
      if (e.target === overlay) {
        overlay.remove();
        resolve("cancel");
      }
    };
  });
}


function showDriveImportFolderModal({ folderName, fileCount, listExists }) {
  return new Promise(resolve => {
    const overlay = document.createElement("div");
    overlay.style.cssText = `
      position:fixed;
      inset:0;
      background:rgba(0,0,0,0.45);
      display:flex;
      align-items:center;
      justify-content:center;
      z-index:100000;
    `;

    const modal = document.createElement("div");
    modal.style.cssText = `
      background:#fff;
      padding:20px 22px;
      border-radius:8px;
      max-width:460px;
      width:90%;
      box-shadow:0 10px 30px rgba(0,0,0,.3);
    `;

    modal.innerHTML = `
      <h3 style="margin:0 0 10px 0;">Import folder from Google Drive</h3>

      <p style="margin:0 0 10px 0;">
        You are about to import <strong>${fileCount} files</strong> from the folder<br>
        <strong>“${folderName}”</strong>.
      </p>

      <p style="margin:10px 0 6px 0;">
        The content will be added to a list with this name:
      </p>

      <input id="driveListNameInput"
        type="text"
        value="${folderName}"
        style="
          width:100%;
          padding:6px 8px;
          font-size:14px;
          box-sizing:border-box;
        "
      />

      <div style="font-size:12px; color:#666; margin-top:6px;">
        ℹ️ ${listExists
          ? "A list with this name already exists"
          : "A new list will be created"}
      </div>

      <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:16px;">
        <button id="driveImportCancel">Cancel</button>
        <button id="driveImportConfirm">Continue</button>
      </div>
    `;

    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    const input = modal.querySelector("#driveListNameInput");
    input.focus();
    input.select();

    modal.querySelector("#driveImportCancel").onclick = () => {
      overlay.remove();
      resolve(null);
    };

    modal.querySelector("#driveImportConfirm").onclick = () => {
      const name = input.value.trim();
      overlay.remove();
      resolve(name || null);
    };

    overlay.onclick = e => {
      if (e.target === overlay) {
        overlay.remove();
        resolve(null);
      }
    };
  });
}



async function countFilesInDriveFolder(folderId) {
  const { url, headers } = driveListEndpoint(folderId);

  const res = await fetch(url, { headers });
  if (!res.ok) return 0;

  const data = await res.json();
  let count = 0;

  for (const node of data.children || []) {
    if (node.mimeType?.includes("folder")) {
      const nextId =
        driveProvider === "dropbox" ? node.path : node.id;
      count += await countFilesInDriveFolder(nextId);
    } else {
      count++;
    }
  }

  return count;
}


async function findListByName(name, ownerOverride = "") {
  const ownerUser = String(ownerOverride || "").trim() || window.currentOwner?.username || "";
  const url = ownerUser
    ? `/getUserLists.php?owner=${encodeURIComponent(ownerUser)}`
    : "/getUserLists.php";
  const res = await fetch(url);
  if (!res.ok) return null;

  const lists = await res.json();
  return lists.find(
    l => normalizeListName(l.name) === normalizeListName(name)
  ) || null;
}



// function getCheckedDriveFiles() {
//   return Array.from(
//     // document.querySelectorAll("#driveTree input[type=checkbox]:checked")
//     document
//       .getElementById(window.activeDriveTreeId)
//       ?.querySelectorAll('input[type="checkbox"]:checked') || []
//   )
//     .map(cb => cb._driveNode)
//     .filter(node => node && !node.mimeType?.includes("folder"));
// }

// function getCheckedDriveFiles(paneId = window.activeDriveTreeId) {
//   return Array.from(getPaneState(paneId).selectedFiles);
// }

function getCheckedDriveFiles(paneId = window.activeDriveTreeId) {
  const state = getPaneState(paneId);
  if (!state || !state.selectedFiles) return [];
  return Array.from(state.selectedFiles);
}

function getCheckedDriveNodesInOrder(paneId = window.activeDriveTreeId) {
  const tree = document.getElementById(paneId);
  if (!tree) return [];
  return Array.from(tree.querySelectorAll('input[type="checkbox"]:checked'))
    .map(cb => cb._driveNode)
    .filter(node => node && !node.mimeType?.includes("folder"));
}







function driveReconnect() {
  localStorage.removeItem("driveConnected");
  window.driveAccessToken = null;
  driveConnect();
}




function getActiveSidebarSurrogates() {
  const set = new Set();
  document.querySelectorAll(".list-sub-item[data-value]").forEach((el) => {
    const s = String(el.dataset.value || "").trim();
    if (s) set.add(s);
  });
  return set;
}

function estimateTWUsage(fileName, paneId = window.activeDriveTreeId) {
  const index = window._importSimilarityIndex;
  if (!Array.isArray(index) || !index.length) {
    return { level: "none", score: 0, surrogate: null };
  }
  const activeSurrogates = getActiveSidebarSurrogates();
  const targetOwner = String(resolveImportTargetOwner(paneId) || "").trim().toLowerCase();

  const fn = normalizeName(fileName);
  let bestScore = 0; // ALWAYS 0..1
  let bestItem = null;

  for (const item of index) {
    const surr = String(item?.surrogate || "").trim();
    if (!surr || !activeSurrogates.has(surr)) continue;
    const itemOwner = String(item?.ownerToken || "").trim().toLowerCase();
    if (targetOwner && itemOwner !== targetOwner) continue;
    const subj = item.subject;

    // 1️⃣ exact match
    if (subj === fn) {
      return { level: "high", score: 100, surrogate: surr };
    }

    // 2️⃣ containment (either direction)
    if (
      subj.length >= 4 &&
      fn.length >= 4 &&
      (subj.includes(fn) || fn.includes(subj))
    ) {
      const words = subj.split(" ").length;

      // single-word → yellow
      if (words === 1) {
        bestScore = Math.max(bestScore, 0.7);
        bestItem = item;
        continue;
      }

      // multi-word → green
      return { level: "high", score: 95, surrogate: surr };
    }

    // 3️⃣ fuzzy similarity
    const s = ngramSimilarity(fn, subj, 3); // 0..1
    if (s > bestScore) {
      bestScore = s;
      bestItem = item;
    }
  }

  const pct = Math.round(bestScore * 100);

  if (pct >= 85) return { level: "high", score: pct, surrogate: bestItem?.surrogate || null };
  if (pct >= 65) return { level: "medium", score: pct, surrogate: bestItem?.surrogate || null };

  return { level: "none", score: pct, surrogate: null };
}






function fingerprint(str, n = 3) {
  const s = normalizeName(str);
  const grams = new Set();

  for (let i = 0; i <= s.length - n; i++) {
    grams.add(s.slice(i, i + n));
  }
  return grams;
}

function fingerprintSimilarity(a, b) {
  const A = fingerprint(a);
  const B = fingerprint(b);

  if (!A.size || !B.size) return 0;

  let intersection = 0;
  for (const g of A) {
    if (B.has(g)) intersection++;
  }

  const union = A.size + B.size - intersection;
  return Math.round((intersection / union) * 100);
}



function ngramSimilarity(a, b, n = 3) {
  const A = ngrams(a, n);
  const B = ngrams(b, n);
  return jaccard(A, B); // 0..1
}

function ngrams(str, n = 3) {
  const s = ` ${str} `;
  const grams = new Set();
  for (let i = 0; i <= s.length - n; i++) {
    grams.add(s.slice(i, i + n));
  }
  return grams;
}

function jaccard(a, b) {
  let intersection = 0;
  for (const x of a) if (b.has(x)) intersection++;
  return intersection / (a.size + b.size - intersection || 1);
}

function buildImportSimilarityIndex() {
  if (window._importSimilarityIndex) return;

  const out = [];

  function walk(lists, fallbackOwnerToken = "") {
    for (const list of lists || []) {
      const listOwnerToken = String(
        list?.owner_username ||
        list?.owner ||
        list?.owner_token ||
        fallbackOwnerToken ||
        ""
      ).trim().toLowerCase();
      for (const item of list.items || []) {
        out.push({
          surrogate: item.surrogate,
          subject: normalizeName(item.title),
          title: item.title,
          ownerToken: String(
            item?.owner ||
            item?.owner_username ||
            listOwnerToken ||
            fallbackOwnerToken ||
            ""
          ).trim().toLowerCase()
        });
      }
      if (list.children) walk(list.children, listOwnerToken || fallbackOwnerToken);
    }
  }

  const allCaches = window.CACHED_OWNER_LISTS || {};
  Object.entries(allCaches).forEach(([cacheOwnerToken, data]) => {
    if (!data) return;
    const fallbackOwnerToken = String(
      data?.owner?.username ||
      cacheOwnerToken ||
      ""
    ).trim().toLowerCase();
    walk(data.owned, fallbackOwnerToken);
    walk(data.accessible, fallbackOwnerToken);
  });

  window._importSimilarityIndex = out;
}


function normalizeName(str) {
  return (str || "")
    .toLowerCase()
    .normalize("NFKD")
    .replace(/[\u0300-\u036f]/g, "")                 // remove accents
    .replace(/\.(pdf|docx?|txt|md|markdown)$/i, "")  // remove common import extensions
    .replace(/[\p{Emoji_Presentation}\p{Emoji}\p{So}]/gu, "") // remove emoji anywhere
    .replace(/^[\s#]*/, "")                          // strip leading # / spaces
    .replace(/^\d+\s*[.\-–]?\s*/, "")                // strip leading numbers
    .replace(/[^\p{L}\p{N}\s]/gu, " ")               // drop punctuation
    .replace(/\s+/g, " ")
    .trim();
}


function canonical(str) {
  return normalizeName(str)
    .replace(/\s+/g, " ")
    .trim();
}





// function enableFileDrag(container, { source }) {
//   if (!container || container._sortable) return;

//   container._sortable = new Sortable(container, {
//     animation: 150,

//     draggable: "li.drive-file",
//     handle: ".file-label",

//     group: {
//       name: "items",
//       pull: "clone",
//       put: false
//     },

//     sort: false,
//     fallbackOnBody: true,
//     forceFallback: true,   // IMPORTANT
//     fallbackTolerance: 3,  // IMPORTANT

//     onClone(evt) {
//       const src = evt.item;
//       const clone = evt.clone;

//       clone.classList.add("list-sub-item");
//       clone.dataset.value  = src.dataset.surrogate || "";
//       clone.dataset.source = source;
//     }
//   });
// }



// function enablePaneDrag(paneId) {

//   if (!window._fmCanEditOwner) {
//     return;
//   }

//   const root = document.getElementById(paneId);
//   if (!root) return;

//   root.querySelectorAll("ul").forEach(ul => {

//     if (ul._sortable) {
//       if (isDragging) return;
//       ul._sortable.destroy();
//       delete ul._sortable;
//     }

//     ul._sortable = new Sortable(ul, {
//       draggable: "li.tw-audio-node, li.drive-file",
//       handle: ".file-label",
//       animation: 150,

//       sort: true,

//       group: {
//         name: "test",
//         pull: "clone",
//         put: true
//       },

//       /* ===============================
//         🔑 TOUCH FIX (CRITICAL)
//         =============================== */
//       delay: 350,                 // ms press before drag
//       delayOnTouchOnly: true,     // mouse unaffected
//       touchStartThreshold: 5,     // allow slight finger movement

//       fallbackOnBody: true,
//       forceFallback: true,

//       onStart(evt) {
//         isDragging = true;
//         evt.item.classList.add("dragging");
//       },

//       onEnd(evt) {
//         evt.item.classList.remove("dragging");
//         isDragging = false;
//       },

//       onAdd(evt) {
//         const toPane = evt.to.closest(".tree")?.id;
//         if (getProviderForPane(toPane) === "tw") {
//           handleTWDrop(evt);
//         }
//       }
//     });

//   });
// }

function enablePaneDrag(paneId) {

  if (!canEditPane(paneId)) return;

  const root = document.getElementById(paneId);
  if (!root) return;

  root.querySelectorAll("ul").forEach(ul => {

    if (ul._sortable) {
      if (isDragging) return;
      ul._sortable.destroy();
      delete ul._sortable;
    }

    ul._sortable = new Sortable(ul, {
      draggable: "li.tw-audio-node, li.tw-text-node, li.drive-file",
      handle: ".file-label",
      filter: 'input[type="checkbox"], label, .checkbox, .checkbox-wrapper',
      preventOnFilter: true,
      animation: 150,
      ghostClass: "fm-sortable-ghost",
      chosenClass: "fm-sortable-chosen",
      dragClass: "fm-sortable-drag",
      fallbackClass: "fm-sortable-fallback",
      removeCloneOnHide: true,
      sort: true,

      group: {
        name: "test",
        pull: (to, from, dragEl) => {
          if (dragEl?.classList?.contains("tw-audio-node")) return true;
          if (dragEl?.classList?.contains("tw-text-node")) return true;
          return "clone";
        },
        put: true
      },

      /* ===============================
         TOUCH FIX
         =============================== */
      delay: 350,
      delayOnTouchOnly: true,
      touchStartThreshold: 5,

      fallbackOnBody: true,
      forceFallback: true,

      onStart(evt) {
        isDragging = true;
        evt.item.classList.add("dragging");
        const dragNode = evt.item?._driveNode || null;
        if (evt.item && dragNode) {
          // Preserve source metadata so sidebar external drops can create real items.
          evt.item.dataset.source = "fm";
          evt.item.dataset.fmPaneId = String(paneId || "");
          evt.item.dataset.fmProvider = String(getProviderForPane(paneId) || "");
          evt.item.dataset.fmName = String(dragNode.name || "");
          evt.item.dataset.fmMime = String(dragNode.mimeType || "");
          if (dragNode.id != null) evt.item.dataset.fmId = String(dragNode.id);
          if (dragNode.path != null) evt.item.dataset.fmPath = String(dragNode.path);
          if (dragNode._twAudioKey) evt.item.dataset.fmTwAudioKey = String(dragNode._twAudioKey);
          if (dragNode._twOwner) evt.item.dataset.fmTwOwner = String(dragNode._twOwner);
          if (dragNode._twSurrogate || dragNode.surrogate) {
            evt.item.dataset.value = String(dragNode._twSurrogate || dragNode.surrogate);
          }
        }
      },

      onEnd(evt) {
        evt.item.classList.remove("dragging");
        isDragging = false;
        document.querySelectorAll(".tree.drag-over").forEach((el) => el.classList.remove("drag-over"));
        clearAudioDropTarget();
        clearTwListDropTarget();
        clearTwListExpandTimer();
        cancelTwListHoverClear();
      },
      onMove(evt) {
        updateAudioDropTarget(evt);
        updateTwListDropTarget(evt);
        const target = evt.originalEvent?.target;
        if (target && target.closest && target.closest('input[type="checkbox"]')) {
          return false;
        }
        const toPaneId = evt.to?.closest(".tree")?.id;
        const draggedNode = evt.dragged?._driveNode;
        if (
          toPaneId &&
          isTwProvider(getProviderForPane(toPaneId)) &&
          draggedNode &&
          isPdfNode(draggedNode)
        ) {
          const listLi = getTwListLiFromDropEvent(evt);
          if (!listLi) {
            return false;
          }
        }
      },

      /* =========================================
         ✅ SAME-LIST REORDER (THIS WAS MISSING)
         ========================================= */
        onUpdate: async function (evt) {
          const tree = evt.to.closest(".tree");
          if (!tree) return;

          // TW only
          if (!isTwProvider(getProviderForPane(tree.id))) return;
          
          //skip nested audio
          if (evt.item?.classList?.contains("tw-audio-node")) return;

          // 1) Find the TW list root <li> (the one whose _driveNode has _twIsListRoot)
          let listRootLi = evt.item?.closest("li"); // start from the moved item, not evt.to
          while (listRootLi && !listRootLi._driveNode?._twIsListRoot) {
            listRootLi = listRootLi.parentElement?.closest("li");
          }
          if (!listRootLi) return;

          const listToken = listRootLi._driveNode?._twListToken;
          if (!listToken) return;

          // 2) Persist order from the list’s own UL (the one that contains PDF items),
          //    not from nested ULs (like the audio UL under a PDF).
          const listUl = listRootLi.querySelector(":scope > ul");
          if (!listUl) return;

          syncSidebarOrderFromTWList(listToken, listUl);
          await persistTWOrderFromUL(listToken, listUl);
        },




      /* =========================================
         ✅ CROSS-CONTAINER DROP (IMPORT / MOVE)
         ========================================= */
      onAdd(evt) {
        // safety: ignore reorder
        if (evt.from === evt.to) return;

        const toPane = evt.to.closest(".tree")?.id;
        if (isTwProvider(getProviderForPane(toPane))) {
          handleTWDrop(evt);
        }
      }
    });

  });
}



function handlePaneDrop({ node, from, to }) {
  console.log("DROP", {
    name: node.name,
    from,
    to,
    fromProvider: getProviderForPane(from),
    toProvider: getProviderForPane(to)
  });

  showFlashMessage?.(`Dropped "${node.name}" from ${from} → ${to}`);
}


document.addEventListener("dragenter", e => {
  const tree = e.target.closest(".tree");
  if (tree) tree.classList.add("drag-over");
});

document.addEventListener("dragleave", e => {
  const tree = e.target.closest(".tree");
  if (tree) tree.classList.remove("drag-over");
});



// --------Resolving Drag Drop ---------------------------------


// function resolveTWDropContext(evt) {
//   const ul = evt.to;
//   const folderLi = ul.closest("li");
//   const listNode = folderLi?._driveNode;

//   const listToken =
//     listNode?._twListToken ||
//     getCurrentList()?.token;

//   return {
//     listToken,
//     ul,
//     index: evt.newIndex
//   };
// }

// function resolveTWDropContext(evt) {
//   const ul = evt.to;
//   const folderLi = ul.closest("li");
//   const listNode = folderLi?._driveNode;

//   if (!listNode?._twListToken) {
//     console.warn("TW drop without list token", listNode);
//     return null;
//   }

//   return {
//     listToken: listNode._twListToken,
//     ul,
//     index: evt.newIndex
//   };
// }

function resolveTWDropContext(evt) {
  const ul = evt.to;

  let li = ul.closest("li");
  while (li) {
    if (li._driveNode?._twIsListRoot) {
      return {
        listToken: li._driveNode._twListToken,
        ul,
        listLi: li
      };
    }
    li = li.parentElement?.closest("li");
  }

  const relatedLi = evt.related?.closest ? evt.related.closest("li") : null;
  let rel = relatedLi;
  while (rel) {
    if (rel._driveNode?._twIsListRoot) {
      return {
        listToken: rel._driveNode._twListToken,
        ul: rel.querySelector("ul") || ul,
        listLi: rel
      };
    }
    rel = rel.parentElement?.closest("li");
  }

  console.warn("TW drop without list root");
  return null;
}




function getTWListOrderFromUL(ul) {
  return [...ul.children]
    .map(li => li._driveNode)
    .filter(n => n && n._twSurrogate)   // PDFs only
    .map((n, i) => ({
      surrogate: n._twSurrogate,
      position: i + 1
    }));
}

function getPdfDropOrderPosition(listUl, droppedLi) {
  if (!listUl || !droppedLi) return 0;
  const allRows = [...listUl.children];
  const dropIdx = allRows.indexOf(droppedLi);
  if (dropIdx < 0) return 0;
  let beforeCount = 0;
  for (let i = 0; i < dropIdx; i += 1) {
    if (allRows[i]?._driveNode?._twSurrogate) beforeCount += 1;
  }
  return beforeCount + 1;
}

function getPdfDropOrderFromEvent(evt, listUl, droppedLi) {
  if (!evt || !listUl) return getPdfDropOrderPosition(listUl, droppedLi);

  // If Sortable dropped directly into this UL, use its exact insertion index.
  if (evt.to === listUl && Number.isInteger(evt.newIndex)) {
    const allRows = [...listUl.children];
    const stopAt = Math.max(0, Math.min(evt.newIndex, allRows.length - 1));
    let beforeCount = 0;
    for (let i = 0; i < stopAt; i += 1) {
      if (allRows[i]?._driveNode?._twSurrogate) beforeCount += 1;
    }
    return beforeCount + 1;
  }

  return getPdfDropOrderPosition(listUl, droppedLi);
}

function placeLiAtPdfOrder(listUl, li, order) {
  if (!listUl || !li) return;
  const desired = Math.max(1, parseInt(order, 10) || 1);
  const pdfRows = Array.from(listUl.children).filter((row) => row?._driveNode?._twSurrogate);
  const withoutSelf = pdfRows.filter((row) => row !== li);
  const anchor = withoutSelf[desired - 1] || null;
  if (anchor) {
    listUl.insertBefore(li, anchor);
  } else {
    listUl.appendChild(li);
  }
}



async function persistTWOrderFromUL(listToken, ul) {
  const order = getTWListOrderFromUL(ul);
  if (!order.length) return;

  await fetch("/updateListOrder.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ token: listToken, order })
  });
}

function syncSidebarOrderFromTWList(listToken, listUl) {
  const token = String(listToken || "").trim();
  if (!token || !listUl) return;

  const listContainer = document.getElementById(`list-${token}`);
  if (!listContainer || listContainer.style.display !== "block") return;

  const itemsWrapper = listContainer.querySelector(".list-items-wrapper");
  if (!itemsWrapper) return;

  const orderedSurrogates = getTWListOrderFromUL(listUl)
    .map((x) => String(x?.surrogate || "").trim())
    .filter(Boolean);
  if (!orderedSurrogates.length) return;

  const rowMap = new Map();
  Array.from(itemsWrapper.querySelectorAll(".list-sub-item[data-value]")).forEach((row) => {
    const s = String(row?.dataset?.value || "").trim();
    if (s) rowMap.set(s, row);
  });

  let moved = false;
  orderedSurrogates.forEach((s) => {
    const row = rowMap.get(s);
    if (!row) return;
    itemsWrapper.appendChild(row);
    moved = true;
  });

  if (moved && typeof updateListItemOrderNumbers === "function") {
    updateListItemOrderNumbers(itemsWrapper, true);
  }
}









function stripSurrogateSuffix(name) {
  return String(name || "")
    .replace(/\s*\(\d+\)\s*$/, "")
    .trim();
}

const audioSafeNameRe = /[^\p{L}0-9_.-]/gu;

function uniqueAudioName(name, usedNames, usedSafeNames) {
  const base = String(name || "").trim() || "audio";
  const dot = base.lastIndexOf(".");
  const stem = dot > 0 ? base.slice(0, dot) : base;
  const ext = dot > 0 ? base.slice(dot) : "";
  const safeBase = base.replace(audioSafeNameRe, "_");

  if (!usedNames.has(base) && !usedSafeNames.has(safeBase)) return base;

  let i = 1;
  let next = `${stem} (${i})${ext}`;
  let safeNext = next.replace(audioSafeNameRe, "_");
  while (usedNames.has(next) || usedSafeNames.has(safeNext)) {
    i += 1;
    next = `${stem} (${i})${ext}`;
    safeNext = next.replace(audioSafeNameRe, "_");
  }
  return next;
}

function guessAudioExtFromMime(mimeType) {
  const mt = String(mimeType || "").toLowerCase();
  if (!mt.includes("audio")) return "";
  if (mt.includes("mpeg") || mt.includes("mp3")) return ".mp3";
  if (mt.includes("wav")) return ".wav";
  if (mt.includes("ogg")) return ".ogg";
  if (mt.includes("m4a") || mt.includes("mp4")) return ".m4a";
  if (mt.includes("flac")) return ".flac";
  if (mt.includes("aac")) return ".aac";
  if (mt.includes("aiff") || mt.includes("aif")) return ".aiff";
  if (mt.includes("midi") || mt.includes("mid")) return ".mid";
  return ".mp3";
}

function ensureAudioExtension(name, mimeType) {
  const base = String(name || "").trim() || "audio";
  const ext = guessAudioExtFromMime(mimeType);
  if (!ext) return base;

  const m = base.match(/\.([a-z0-9]+)$/i);
  if (!m) return base + ext;

  const suffix = m[1].toLowerCase();
  const known = new Set(["mp3","wav","ogg","m4a","flac","aac","aif","aiff","mid","midi"]);
  const numericOnly = /^\d+$/.test(suffix);
  const looksLikeDate = /^\d{1,2}\.\d{1,2}\.\d{2,4}$/.test(base.split(" ").pop() || "");

  if (!known.has(suffix) || numericOnly || looksLikeDate) {
    return base + ext;
  }

  return base;
}


function resolveTargetPdfLi(evt) {
  const ul = evt.to;
  const i  = evt.newIndex;

  if (!ul) return null;

  // 1️⃣ Direct drop ON a PDF
  const direct = ul.children[i];
  if (direct?._driveNode?._twSurrogate) {
    return direct;
  }

  // 2️⃣ Prefer nearest PDF ABOVE
  for (let j = i - 1; j >= 0; j--) {
    const el = ul.children[j];
    if (el?._driveNode?._twSurrogate) {
      return el;
    }
  }

  // 3️⃣ Fallback: nearest PDF BELOW
  for (let j = i + 1; j < ul.children.length; j++) {
    const el = ul.children[j];
    if (el?._driveNode?._twSurrogate) {
      return el;
    }
  }

  return null;
}

function findPdfLiFromDropTarget(ul) {
  if (!ul) return null;
  let li = ul.closest("li");
  while (li) {
    if (li._driveNode?._twSurrogate) return li;
    li = li.parentElement?.closest("li");
  }
  return null;
}

function getPdfLiFromDropEvent(evt) {
  const oe = evt?.originalEvent;
  let targetEl = null;
  if (oe && typeof oe.clientX === "number" && typeof oe.clientY === "number") {
    targetEl = document.elementFromPoint(oe.clientX, oe.clientY);
  }
  if (!targetEl && oe?.target) {
    targetEl = oe.target;
  }
  if (!targetEl) return null;

  // Prefer direct drop target row if it is a TW item.
  const directLi = targetEl.closest("li");
  if (directLi?._driveNode?._twSurrogate) return directLi;

  // If drop is inside a nested UL under an item, map to that parent item.
  const nestedUl = targetEl.closest("ul");
  const parentLi = nestedUl?.parentElement?.closest?.("li");
  if (parentLi?._driveNode?._twSurrogate) return parentLi;

  return null;
}

function resolveTextDropTargetPdfLi(evt) {
  // Most reliable signal from Sortable: the row adjacent to drop location.
  const related = evt?.related;
  if (related?._driveNode?._twSurrogate) {
    return related;
  }

  // Pointer-based fallback.
  const byPointer = getPdfLiFromDropEvent(evt);
  if (byPointer?._driveNode?._twSurrogate) {
    return byPointer;
  }

  // Structural fallbacks.
  return (
    findPdfLiFromDropTarget(evt?.to) ||
    resolveTargetPdfLi(evt) ||
    null
  );
}

function restoreDroppedItemOnCancel(evt) {
  const li = evt?.item;
  if (!li) return;

  // Clone drops should just discard the clone.
  if (evt?.pullMode === "clone") {
    try { li.remove(); } catch {}
    return;
  }

  const from = evt?.from;
  if (!from) {
    try { li.remove(); } catch {}
    return;
  }

  const oldIndex = Number(evt?.oldIndex);
  const kids = from.children;
  if (Number.isInteger(oldIndex) && oldIndex >= 0 && oldIndex < kids.length) {
    from.insertBefore(li, kids[oldIndex]);
  } else {
    from.appendChild(li);
  }
}

function clearAudioDropTarget() {
  if (!audioDropTargetLi) return;
  audioDropTargetLi.classList.remove("tw-audio-drop-target");
  audioDropTargetLi = null;
}

function clearTwListDropTarget() {
  if (!twListDropTargetLi) return;
  twListDropTargetLi.classList.remove("tw-list-drop-target");
  twListDropTargetLi = null;
}

function clearTwListExpandTimer() {
  if (twListExpandTimer) {
    clearTimeout(twListExpandTimer);
    twListExpandTimer = null;
  }
  twListExpandTarget = null;
}

function scheduleTwListHoverClear() {
  if (twListHoverClearTimer) return;
  twListHoverClearTimer = setTimeout(() => {
    clearTwListDropTarget();
    twListHoverClearTimer = null;
  }, 120);
}

function cancelTwListHoverClear() {
  if (twListHoverClearTimer) {
    clearTimeout(twListHoverClearTimer);
    twListHoverClearTimer = null;
  }
}

function ensureTwListExpanded(listLi) {
  if (!listLi) return;
  const paneId = listLi.closest(".tree")?.id || window.activeDriveTreeId;
  const node = listLi._driveNode;
  if (node && !node._loaded && paneId) {
    void loadFolderChildren(node, listLi, paneId);
  }
  listLi.classList.remove("collapsed");
  let ul = listLi.querySelector("ul");
  if (!ul) {
    ul = document.createElement("ul");
    listLi.appendChild(ul);
  }
}

function getTwListLiFromDropEvent(evt) {
  const oe = evt?.originalEvent;
  let targetEl = null;
  if (oe && typeof oe.clientX === "number" && typeof oe.clientY === "number") {
    targetEl = document.elementFromPoint(oe.clientX, oe.clientY);
  }
  if (!targetEl && oe?.target) {
    targetEl = oe.target;
  }
  if (!targetEl) return null;

  // Accept hover over label, drop-hint, or any area inside the list li
  let listLi = targetEl.closest("li");
  while (listLi && !listLi._driveNode?._twIsListRoot) {
    listLi = listLi.parentElement?.closest("li");
  }
  return listLi || null;
}

function getTWListTokenFromUL(ul) {
  if (!ul) return "";
  let listLi = ul.closest("li");
  while (listLi && !listLi._driveNode?._twIsListRoot) {
    listLi = listLi.parentElement?.closest("li");
  }
  return String(listLi?._driveNode?._twListToken || "").trim();
}

function updateTwListDropTarget(evt) {
  const toPaneId = evt.to?.closest(".tree")?.id;
  if (!toPaneId || !isTwProvider(getProviderForPane(toPaneId))) {
    clearTwListDropTarget();
    clearTwListExpandTimer();
    cancelTwListHoverClear();
    return;
  }

  const draggedNode = evt.dragged?._driveNode;
  if (!draggedNode || !isPdfNode(draggedNode)) {
    clearTwListDropTarget();
    clearTwListExpandTimer();
    cancelTwListHoverClear();
    return;
  }

  const listLi = getTwListLiFromDropEvent(evt);
  if (!listLi) {
    scheduleTwListHoverClear();
    return;
  }
  cancelTwListHoverClear();

  if (!listLi) {
    clearTwListDropTarget();
    return;
  }

  if (twListDropTargetLi === listLi) return;
  clearTwListDropTarget();
  twListDropTargetLi = listLi;
  twListDropTargetLi.classList.add("tw-list-drop-target");

  if (twListDropTargetLi.classList.contains("collapsed")) {
    if (twListExpandTarget !== twListDropTargetLi) {
      clearTwListExpandTimer();
      twListExpandTarget = twListDropTargetLi;
      twListExpandTimer = setTimeout(() => {
        ensureTwListExpanded(twListExpandTarget);
        clearTwListExpandTimer();
      }, TW_LIST_AUTO_EXPAND_DELAY_MS);
    }
  } else {
    clearTwListExpandTimer();
  }
}
function updateAudioDropTarget(evt) {
  const toPaneId = evt.to?.closest(".tree")?.id;
  if (!toPaneId || !isTwProvider(getProviderForPane(toPaneId))) {
    clearAudioDropTarget();
    return;
  }

  const draggedNode = evt.dragged?._driveNode;
  if (!draggedNode || !isAudioNode(draggedNode)) {
    clearAudioDropTarget();
    return;
  }

  let pdfLi = findPdfLiFromDropTarget(evt.to);
  const related = evt.related;
  if (related?._driveNode?._twSurrogate) {
    pdfLi = related;
  } else if (related) {
    let prev = related.previousElementSibling;
    while (prev && !prev._driveNode?._twSurrogate) prev = prev.previousElementSibling;
    if (prev?._driveNode?._twSurrogate) {
      pdfLi = prev;
    } else {
      let next = related.nextElementSibling;
      while (next && !next._driveNode?._twSurrogate) next = next.nextElementSibling;
      if (next?._driveNode?._twSurrogate) pdfLi = next;
    }
  }
  if (!pdfLi && typeof evt.newIndex === "number") {
    pdfLi = resolveTargetPdfLi({ to: evt.to, newIndex: evt.newIndex });
  }
  if (!pdfLi) {
    clearAudioDropTarget();
    return;
  }

  if (audioDropTargetLi === pdfLi) return;
  clearAudioDropTarget();
  audioDropTargetLi = pdfLi;
  audioDropTargetLi.classList.add("tw-audio-drop-target");
}




function resolveTargetPdfSurrogate(evt) {
  const ul = evt.to;
  const i  = evt.newIndex;

  // 1️⃣ Check direct drop ON a PDF
  const direct = ul.children[i];
  if (direct?.classList?.contains("drive-file")) {
    const node = direct._driveNode;
    if (node?._twSurrogate) return node._twSurrogate;
  }

  // 2️⃣ Prefer nearest PDF ABOVE
  for (let j = i - 1; j >= 0; j--) {
    const el = ul.children[j];
    const node = el?._driveNode;
    if (node?._twSurrogate) return node._twSurrogate;
  }

  // 3️⃣ Fallback: nearest PDF BELOW
  for (let j = i + 1; j < ul.children.length; j++) {
    const el = ul.children[j];
    const node = el?._driveNode;
    if (node?._twSurrogate) return node._twSurrogate;
  }

  return null;
}

async function handleTWDrop(evt) {
  const li   = evt.item;
  const node = li?._driveNode;
  if (!node) return;
  clearAudioDropTarget();
  clearTwListDropTarget();

  const dropListLi = getTwListLiFromDropEvent(evt);
  const dropCtx = resolveTWDropContext(evt);
  const listToken =
    dropListLi?._driveNode?._twListToken ||
    dropCtx?.listToken ||
    dropCtx?.listLi?._driveNode?._twListToken ||
    null;

  if (!listToken) {
    restoreDroppedItemOnCancel(evt);
    showFlashMessage?.("Drop onto a list folder label.");
    return;
  }

  let dropOrder = 0;
  if (isPdfNode(node)) {
    const listLi = dropListLi || dropCtx?.listLi;
    if (listLi) {
      const listUl = listLi.querySelector("ul") || (function () {
        const ul = document.createElement("ul");
        listLi.appendChild(ul);
        return ul;
      })();
      ensureTwListExpanded(listLi);
      const hint = listUl.querySelector(".tw-drop-hint");
      if (hint) hint.remove();
      const s = node._twSurrogate || node.surrogate || li.dataset?.surrogate;
      if (s) {
        const existing = Array.from(listUl.querySelectorAll("li")).find(
          el => el?._driveNode?._twSurrogate === s
        );
        if (existing) {
          try { li.remove(); } catch {}
          dropOrder = getPdfDropOrderFromEvent(evt, listUl, existing);
          placeLiAtPdfOrder(listUl, existing, dropOrder);
        } else if (evt.to !== listUl) {
          listUl.appendChild(li);
          dropOrder = getPdfDropOrderFromEvent(evt, listUl, li);
          placeLiAtPdfOrder(listUl, li, dropOrder);
        } else {
          dropOrder = getPdfDropOrderFromEvent(evt, listUl, li);
          placeLiAtPdfOrder(listUl, li, dropOrder);
        }
      } else if (evt.to !== listUl) {
        listUl.appendChild(li);
        dropOrder = getPdfDropOrderFromEvent(evt, listUl, li);
        placeLiAtPdfOrder(listUl, li, dropOrder);
      } else {
        dropOrder = getPdfDropOrderFromEvent(evt, listUl, li);
        placeLiAtPdfOrder(listUl, li, dropOrder);
      }
    }
  }
  const sourcePaneId = evt.from?.closest(".tree")?.id || window.activeDriveTreeId;
  const destPaneId = evt.to?.closest(".tree")?.id || window.activeDriveTreeId;

  /* ===============================
     🎵 AUDIO → MOVE EXISTING NODE + STORE
     =============================== */
  if (isAudioNode(node)) {
    const sourceListToken = getTWListTokenFromUL(evt.from);
    const targetListToken = String(listToken || "").trim();
    const copyAcrossGroups =
      !!sourceListToken &&
      !!targetListToken &&
      sourceListToken !== targetListToken;

    const pdfLi =
      audioDropTargetLi ||
      findPdfLiFromDropTarget(evt.to) ||
      resolveTargetPdfLi(evt);
    const targetSurrogate = pdfLi?._driveNode?._twSurrogate;
    if (!pdfLi || !targetSurrogate) return;

    const prevOwner = window.currentItemOwner;
    const targetOwner = pdfLi?._driveNode?._twOwner || null;
    if (targetOwner) {
      window.currentItemOwner = targetOwner;
    }
    const forceCloudflare = !!node._twAudioKey;
    try {
      const existingNames = new Set();
      const existingSafeNames = new Set();
      pdfLi.querySelectorAll("li.tw-audio-node").forEach(a => {
        const n = a._driveNode?.name;
        if (n) {
          existingNames.add(n);
          existingSafeNames.add(n.replace(audioSafeNameRe, "_"));
        }
      });
      const targetNode = copyAcrossGroups ? { ...node } : node;
      if (targetNode.name) {
        targetNode.name = ensureAudioExtension(targetNode.name, targetNode.mimeType);
        if (
          existingNames.has(targetNode.name) ||
          existingSafeNames.has(targetNode.name.replace(audioSafeNameRe, "_"))
        ) {
          targetNode.name = uniqueAudioName(targetNode.name, existingNames, existingSafeNames);
        }
      }

      // ensure child UL exists
      let ul = pdfLi.querySelector("ul");
      if (!ul) {
        ul = document.createElement("ul");
        pdfLi.appendChild(ul);
      }

      let targetLi = li;
      if (copyAcrossGroups) {
        restoreDroppedItemOnCancel(evt);
        targetLi = driveRenderNode(targetNode, destPaneId);
        if (!targetLi) return;
        targetLi._driveNode = targetNode;
        ul.appendChild(targetLi);
      } else {
        // 🔑 MOVE THE DROPPED NODE — DO NOT CREATE A NEW ONE
        ul.appendChild(li);
        const cb = li.querySelector('input[type="checkbox"]');
        if (cb) cb.remove();
        li.querySelectorAll(".tw-usage-dot").forEach(d => d.remove());
        li.querySelectorAll(".tw-surrogate-link").forEach(s => s.remove());
        li.classList.add("tw-audio-node");
        li.classList.remove("drive-file");
      }

      const label = targetLi.querySelector(".file-label");
      if (label) {
        label.classList.add("tw-audio-line");
        if (targetNode.name) label.textContent = " 🎵 " + targetNode.name;
      }

      pdfLi.classList.remove("collapsed");
      ul.style.display = "block";

      enablePaneDrag(evt.to.closest(".tree")?.id || destPaneId);

      // persist audio (same path as checkbox import)
      const blob = await downloadCurrentDriveFile(node, sourcePaneId);
      if (!blob) {
        if (copyAcrossGroups) {
          try { targetLi.remove(); } catch {}
        }
        return;
      }

      const audioName = ensureAudioExtension(
        targetNode.name || node.name,
        blob.type || targetNode.mimeType || node.mimeType || "audio/mpeg"
      );
      await handleFileUpload(
        new File([blob], audioName, { type: blob.type || "audio/mpeg" }),
        targetSurrogate,
        "audio"
      );

      if (
        !copyAcrossGroups &&
        node._twAudioKey &&
        node._twParentSurrogate &&
        node._twParentSurrogate !== targetSurrogate
      ) {
        deleteAudioFile(node._twAudioKey);
      }

      if (forceCloudflare) {
        const ownerForKey =
          targetOwner || window.currentItemOwner || window.SESSION_USERNAME || "";
        if (ownerForKey && audioName) {
          targetNode._twAudioKey = `${ownerForKey}/surrogate-${targetSurrogate}/files/${audioName}`;
        }
      }
      targetNode._twParentSurrogate = targetSurrogate;
    } finally {
      if (targetOwner) {
        window.currentItemOwner = prevOwner;
      }
    }

    return;
  }

  /* ===============================
     📝 TEXT → CREATE ITEM + SAVE TO TEXT TAB
     =============================== */
  if (isTextNode(node)) {
    if (!listToken) return;
    const ownerUser =
      (isTwProvider(getProviderForPane(destPaneId)) ? getActiveTwOwnerForPane(destPaneId) : null) ||
      window.currentOwner?.username ||
      window.SESSION_USERNAME ||
      null;
    const targetPdfLi = resolveTextDropTargetPdfLi(evt);
    const targetSurrogate = String(targetPdfLi?._driveNode?._twSurrogate || "").trim();
    const targetTitle = targetSurrogate
      ? (
          extractItemTitleFromRow(findSidebarRowBySurrogate(targetSurrogate))
          || String(targetPdfLi?._driveNode?.name || "").trim()
          || "item"
        )
      : "";

    let dropMode = "smart";
    if (targetSurrogate) {
      dropMode = await showTextDropTargetModal({
        fileName: node?.name || "text file",
        parentTitle: targetTitle
      });
      if (dropMode === "cancel") {
        restoreDroppedItemOnCancel(evt);
        return;
      }
    }

    try {
      const result = await importTextNodeSmart(node, listToken, ownerUser || "", {
        preferCurrent: !targetSurrogate && dropMode !== "new",
        forceSurrogate: dropMode === "nested" ? targetSurrogate : "",
        forceNew: dropMode === "new",
        sourcePaneId: sourcePaneId
      });
      const surrogate = result?.surrogate;
      if (result?.created && surrogate) {
        notifyTwTreeNewItem(listToken, surrogate, result.title || buildTextImportTitle(node?.name), ownerUser);
      }
      if (dropMode === "nested" && targetSurrogate && String(surrogate) === String(targetSurrogate) && targetPdfLi) {
        let childUl = targetPdfLi.querySelector("ul");
        if (!childUl) {
          childUl = document.createElement("ul");
          targetPdfLi.appendChild(childUl);
        }
        childUl.appendChild(li);

        const cb = li.querySelector('input[type="checkbox"]');
        if (cb) cb.remove();
        li.querySelectorAll(".tw-usage-dot").forEach(d => d.remove());
        li.querySelectorAll(".tw-surrogate-link").forEach(s => s.remove());
        li.classList.add("tw-text-node");
        li.classList.remove("drive-file");

        const label = li.querySelector(".file-label");
        if (label) {
          label.classList.add("tw-text-line");
          label.textContent = " 📝 " + (node?.name || "Imported text");
        }

        targetPdfLi.classList.remove("collapsed");
        childUl.style.display = "block";
        enablePaneDrag(evt.to.closest(".tree")?.id || destPaneId);
      } else {
        try { li.remove(); } catch {}
      }
      showFlashMessage?.("✅ Text imported");
    } catch (err) {
      console.warn("Text import failed:", err?.message || err);
      showFlashMessage?.("⚠️ Text import failed.");
    }
    return;
  }

  /* ===============================
     📄 PDF → STORE + ORDER ONLY
     (VISUAL IS ALREADY CORRECT)
     =============================== */
  if (!isPdfNode(node)) return;

  if (!listToken) return;

  let surrogate = node._twSurrogate;
  const title = node.name.replace(/\.pdf$/i, "").trim();
  const ownerUser =
    node._twOwner ||
    (isTwProvider(getProviderForPane(destPaneId)) ? getActiveTwOwnerForPane(destPaneId) : null) ||
    window.currentOwner?.username ||
    window.SESSION_USERNAME ||
    null;

  if (surrogate) {
    const sid = parseInt(String(surrogate || "").trim(), 10) || 0;
    if (sid > 0) {
      await fetch("/addItemToList.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: `token=${encodeURIComponent(listToken)}&surrogate=${encodeURIComponent(sid)}&order=${encodeURIComponent(dropOrder || 0)}`,
        credentials: "include"
      }).catch(() => {});
    }
    notifyTwTreeNewItem(listToken, surrogate, title, ownerUser, dropOrder || 0);
    showFlashMessage?.("✅ Item added to list");
    return;
  }

  surrogate = await createNewItemForPDF(listToken, title, ownerUser || "", dropOrder || 0);
  if (!surrogate) return;

  //UPLOAD PDF
  const blob = await downloadCurrentDriveFile(node, sourcePaneId);
  if (!blob) {
    await removeItemFromListQuiet(listToken, surrogate);
    showFlashMessage?.("⚠️ Could not download source file. Item link was removed.");
    return;
  }

  try {
    await uploadPdfWithVerification(
      new File([blob], node.name, { type: "application/pdf" }),
      surrogate,
      ownerUser || ""
    );
  } catch (err) {
    await removeItemFromListQuiet(listToken, surrogate);
    showFlashMessage?.("⚠️ Upload failed. Item link was removed.");
    return;
  }

  notifyTwTreeNewItem(listToken, surrogate, title, ownerUser, dropOrder || 0);
  try { li.remove(); } catch {}
  showFlashMessage?.("✅ Item added to list");
}


// function selectTWNodeFromTree(li) {
//   const surrogate = li?._driveNode?._twSurrogate;
//   if (!surrogate) return;

//   selectItem(surrogate);
// }


function selectTWNodeFromTree(li) {
  const node = li?._driveNode;
  if (!node) return;

  const surrogate = node._twSurrogate || node._twParentSurrogate;
  if (!surrogate) return;

  const token = getTWListTokenFromLi(li);
  if (!token) return;

  selectItem(surrogate, token);
}


function getTWListTokenFromLi(li) {
  let p = li;
  while (p) {
    if (p._driveNode?._twIsListRoot) {
      return p._driveNode._twListToken || null;
    }
    p = p.parentElement?.closest("li");
  }
  return null;
}

/* =========================================================
   Dropbox Manual Sync (per-list)
   ========================================================= */

async function listSettingsGet(token, key) {
  const res = await fetch(`/list_settings.php?token=${encodeURIComponent(token)}&key=${encodeURIComponent(key)}`);
  if (!res.ok) return null;
  const data = await res.json();
  return data?.ok ? data.value : null;
}

async function listSettingsSet(token, key, value) {
  const body = `token=${encodeURIComponent(token)}&key=${encodeURIComponent(key)}&value=${encodeURIComponent(value ?? "")}`;
  const res = await fetch("/list_settings.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body
  });
  if (!res.ok) return false;
  const data = await res.json().catch(() => ({}));
  return !!data?.ok;
}

function getListTitleByToken(token) {
  const el = document.querySelector(`.group-item[data-group='${CSS.escape(token)}'] .list-title`);
  return el?.textContent?.trim() || token;
}

function ensureDropboxSyncBar(listToken) {
  const host = document.getElementById("importTabContent");
  if (!host) return;

  let bar = host.querySelector(".db-sync-bar");
  if (!bar) {
    bar = document.createElement("div");
    bar.className = "db-sync-bar";
    bar.style.cssText = `
      background: #f7f9ff;
      border: 1px solid #cfd9ff;
      color: #1a2a5e;
      padding: 8px 10px;
      border-radius: 8px;
      margin-bottom: 10px;
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      align-items: center;
      font-size: 13px;
    `;
    host.prepend(bar);
  }

  const listName = getListTitleByToken(listToken);
  bar.innerHTML = `
    <span><strong>Dropbox Sync:</strong> ${escapeHtml(listName)}</span>
    <button class="btn" id="dbLinkFolderBtn">Link selected folder</button>
    <button class="btn" id="dbClearFolderBtn">Clear link</button>
    <button class="btn" id="dbSyncNowBtn">Sync now</button>
    <span id="dbSyncStatus" style="opacity:0.8;"></span>
  `;

  const linkBtn = bar.querySelector("#dbLinkFolderBtn");
  const clearBtn = bar.querySelector("#dbClearFolderBtn");
  const syncBtn = bar.querySelector("#dbSyncNowBtn");
  const statusEl = bar.querySelector("#dbSyncStatus");

  linkBtn.onclick = async () => {
    statusEl.textContent = "";
    const paneId = "driveTree";
    const provider = getProviderForPane(paneId);
    if (provider !== "dropbox") {
      statusEl.textContent = "Switch left panel to Dropbox first.";
      return;
    }
    const state = getPaneState(paneId);
    const folders = [...(state?.selectedFolders || [])];
    if (folders.length !== 1) {
      statusEl.textContent = "Select exactly one Dropbox folder.";
      return;
    }
    const folder = folders[0];
    if (!folder?.path && folder?.path !== "") {
      statusEl.textContent = "Selected folder has no path.";
      return;
    }
    await listSettingsSet(listToken, "sync.provider", "dropbox");
    await listSettingsSet(listToken, "sync.path", folder.path);
    await listSettingsSet(listToken, "sync.enabled", "1");
    statusEl.textContent = `Linked: ${folder.path || "/"}`;
  };

  clearBtn.onclick = async () => {
    statusEl.textContent = "";
    await listSettingsSet(listToken, "sync.provider", "");
    await listSettingsSet(listToken, "sync.path", "");
    await listSettingsSet(listToken, "sync.enabled", "");
    statusEl.textContent = "Link cleared.";
  };

  syncBtn.onclick = async () => {
    statusEl.textContent = "";
    await window.syncDropboxNow(listToken);
  };
}

window.openDropboxSyncLinker = async function (listToken) {
  if (!listToken || listToken === window.SESSION_USERNAME) {
    showFlashMessage?.("Select a list to link Dropbox.");
    return;
  }
  window._dropboxSyncTargetListToken = listToken;
  await window.openDriveImportOverlay?.("dropbox");
  ensureDropboxSyncBar(listToken);
};

async function downloadDropboxFileByPath(path) {
  const res = await fetch(`/File_downloadDropbox.php?path=${encodeURIComponent(path)}`);
  if (!res.ok) return null;
  return await res.blob();
}

function isAudioName(name) {
  return /\.(mp3|wav|ogg|m4a|flac|aac|aif|aiff|mid|midi)$/i.test(name || "");
}

window.syncDropboxNow = async function (listToken) {
  if (!listToken || listToken === window.SESSION_USERNAME) {
    showFlashMessage?.("Select a list to sync.");
    return;
  }

  const provider = await listSettingsGet(listToken, "sync.provider");
  const path = await listSettingsGet(listToken, "sync.path");

  if (provider !== "dropbox" || path === null) {
    showFlashMessage?.("No Dropbox folder linked for this list.");
    return;
  }

  const status = await fetch("/api/auth/dropbox/session_status.php")
    .then(r => r.ok ? r.json() : null)
    .catch(() => null);

  if (!status?.hasToken) {
    showFlashMessage?.("Connect Dropbox first (File Manager → Dropbox).");
    return;
  }

  showFlashMessage?.("🔄 Syncing Dropbox…");

  const res = await fetch(`/File_listDropboxRecursive.php?path=${encodeURIComponent(path)}`);
  if (!res.ok) {
    showFlashMessage?.("Dropbox sync failed (list error).");
    return;
  }

  const data = await res.json();
  const files = Array.isArray(data?.files) ? data.files : [];
  if (!files.length) {
    showFlashMessage?.("No files found in Dropbox folder.");
    return;
  }

  delete window._importSimilarityIndex;
  buildImportSimilarityIndex();

  const pdfNodes = [];
  const audioNodes = [];

  for (const f of files) {
    const name = f?.name || "";
    const path = f?.path;
    if (!name || path === undefined) continue;
    if (/\.pdf$/i.test(name)) {
      pdfNodes.push({ name, path, mimeType: "application/pdf", _sourceProvider: "dropbox" });
    } else if (isAudioName(name)) {
      audioNodes.push({ name, path, mimeType: "audio", _sourceProvider: "dropbox" });
    }
  }

  if (!pdfNodes.length && !audioNodes.length) {
    showFlashMessage?.("No PDF or audio files found to sync.");
    return;
  }

  const normalizeBase = (name) =>
    String(name || "")
      .normalize("NFKD")
      .replace(/\p{Diacritic}/gu, "")
      .replace(/\.[^.]+$/, "")
      .toLowerCase()
      .replace(/[^\p{L}0-9]+/gu, " ")
      .trim();

  const pdfSorted = [...pdfNodes].sort((a, b) =>
    normalizeBase(a.name).localeCompare(normalizeBase(b.name))
  );
  const audioSorted = [...audioNodes].sort((a, b) =>
    normalizeBase(a.name).localeCompare(normalizeBase(b.name))
  );

  const audioGroups = new Map();
  audioSorted.forEach(node => {
    const first = normalizeBase(node.name).split(" ").filter(Boolean)[0] || "";
    if (!first) return;
    if (!audioGroups.has(first)) audioGroups.set(first, []);
    audioGroups.get(first).push(node);
  });

  const hasMixedFolderImport = pdfSorted.length && audioSorted.length;
  const ownerUser = window.currentOwner?.username || "";

  for (const node of pdfSorted) {
    const base = normalizeBase(node.name);
    const match = estimateTWUsage(node.name);
    let surr = match?.surrogate || null;

    if (surr) {
      const sid = parseInt(String(surr || "").trim(), 10) || 0;
      if (sid > 0) {
        await fetch("/addItemToList.php", {
          method: "POST",
          headers: { "Content-Type": "application/x-www-form-urlencoded" },
          body: `token=${encodeURIComponent(listToken)}&surrogate=${encodeURIComponent(sid)}&order=0`,
          credentials: "include"
        }).catch(() => {});
      }
      notifyTwTreeNewItem?.(listToken, surr, node.name.replace(/\.[^.]+$/, "").trim(), ownerUser);
    } else {
      const blob = await downloadDropboxFileByPath(node.path);
      if (!blob) continue;
      surr = await importPdfFile(
        new File([blob], node.name, { type: "application/pdf" }),
        listToken
      );
      if (surr) {
        notifyTwTreeNewItem?.(listToken, surr, node.name.replace(/\.[^.]+$/, "").trim(), ownerUser);
      }
    }

    if (hasMixedFolderImport && surr) {
      const pdfFirst = base.split(" ").filter(Boolean)[0] || "";
      const group = pdfFirst ? audioGroups.get(pdfFirst) : null;
      if (group && group.length) {
        for (const audioNode of group) {
          const blob = await downloadDropboxFileByPath(audioNode.path);
          if (!blob) continue;
          await handleFileUpload(
            new File([blob], audioNode.name, { type: blob.type || "audio/mpeg" }),
            surr,
            "audio"
          );
        }
        audioGroups.delete(pdfFirst);
      }
    }
  }

  if (!hasMixedFolderImport) {
    for (const node of audioSorted) {
      const blob = await downloadDropboxFileByPath(node.path);
      if (!blob) continue;
      const title = node.name.replace(/\.[^.]+$/, "").trim();
      const surr = await createNewItemForPDF(listToken, title, ownerUser, 0);
      if (!surr) continue;
      notifyTwTreeNewItem?.(listToken, surr, title, ownerUser);
      await handleFileUpload(
        new File([blob], node.name, { type: blob.type || "audio/mpeg" }),
        surr,
        "audio"
      );
    }
  } else if (audioGroups.size) {
    for (const group of audioGroups.values()) {
      for (const node of group) {
        const blob = await downloadDropboxFileByPath(node.path);
        if (!blob) continue;
        const title = node.name.replace(/\.[^.]+$/, "").trim();
        const surr = await createNewItemForPDF(listToken, title, ownerUser, 0);
        if (!surr) continue;
        notifyTwTreeNewItem?.(listToken, surr, title, ownerUser);
        await handleFileUpload(
          new File([blob], node.name, { type: blob.type || "audio/mpeg" }),
          surr,
          "audio"
        );
      }
    }
  }

  refreshListUI?.(listToken);
  showFlashMessage?.("✅ Dropbox sync completed");
};
