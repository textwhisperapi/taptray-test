logStep("JSPdfMarkers.js executed");

window._pdfMarkerPlacementState = null;
window._pdfMarkerMoveState = null;
window._pdfMarkerActionSuppressUntil = 0;
window._pdfMarkerDbLoadState = window._pdfMarkerDbLoadState || Object.create(null);
window._pdfMarkerDbSaveTimers = window._pdfMarkerDbSaveTimers || Object.create(null);
window._pdfMarkerDirtyState = window._pdfMarkerDirtyState || Object.create(null);
window._pdfMarkerLastLocalChange = window._pdfMarkerLastLocalChange || Object.create(null);
window._pdfMarkerDomRefreshTimer = window._pdfMarkerDomRefreshTimer || 0;
window._pdfMarkerSessionCache = window._pdfMarkerSessionCache || Object.create(null);

function getPdfMarkerStorageKey({ owner, surrogate, layer }) {
  const safeOwner = String(owner || "").trim();
  const safeSurrogate = String(surrogate || "").trim();
  const safeLayer = layer === "owner" ? "owner" : "self";
  const sessionUser = String(window.SESSION_USERNAME || "guest").trim() || "guest";
  if (!safeSurrogate) return "";
  if (safeLayer === "owner") {
    return safeOwner ? `pdf-markers-owner-${safeOwner}-${safeSurrogate}` : `pdf-markers-owner-${safeSurrogate}`;
  }
  if (!safeOwner) return `pdf-markers-self-${safeSurrogate}`;
  return `pdf-markers-self-${safeOwner}-${safeSurrogate}-${sessionUser}`;
}

function readPdfMarkerStorageCandidate(key) {
  if (!key) return null;
  try {
    const raw = localStorage.getItem(key);
    if (!raw) return null;
    const parsed = JSON.parse(raw);
    return Array.isArray(parsed) ? parsed : null;
  } catch {
    return null;
  }
}

function getPdfMarkerSessionSnapshot({ owner, surrogate, layer }) {
  const sessionKey = getPdfMarkerSessionKey({ owner, surrogate, layer });
  return Array.isArray(window._pdfMarkerSessionCache[sessionKey])
    ? window._pdfMarkerSessionCache[sessionKey].map(normalizePdfMarkerEntry)
    : [];
}

function writePdfMarkersLocal({ owner, surrogate, layer, markers }) {
  const safeMarkers = Array.isArray(markers) ? markers.map(normalizePdfMarkerEntry) : [];
  const sessionKey = getPdfMarkerSessionKey({ owner, surrogate, layer });
  if (sessionKey) {
    window._pdfMarkerSessionCache[sessionKey] = safeMarkers.slice();
  }
  const key = getPdfMarkerStorageKey({ owner, surrogate, layer });
  if (!key) return;
  localStorage.setItem(key, JSON.stringify(safeMarkers));
}

function resolvePdfMarkerDbPayload({ owner, surrogate, layer, dbMarkers }) {
  const safeDbMarkers = Array.isArray(dbMarkers) ? dbMarkers.map(normalizePdfMarkerEntry) : [];
  const localMarkers = window.getPdfMarkers({ owner, surrogate, layer });
  if (Array.isArray(localMarkers) && localMarkers.length) {
    return localMarkers;
  }
  return safeDbMarkers;
}

function getPdfMarkerSyncKey({ owner, surrogate, layer }) {
  const safeOwner = String(owner || "").trim();
  const safeSurrogate = String(surrogate || "").trim();
  const safeLayer = layer === "owner" ? "owner" : "self";
  const sessionUser = String(window.SESSION_USERNAME || "guest").trim() || "guest";
  if (!safeSurrogate) return "";
  if (safeLayer === "owner") {
    return `${safeOwner || "-"}::${safeSurrogate}::owner`;
  }
  return `${safeOwner || "-"}::${safeSurrogate}::self::${sessionUser}`;
}

function getPdfMarkerSessionKey({ owner, surrogate, layer }) {
  return getPdfMarkerSyncKey({ owner, surrogate, layer });
}

function normalizePdfMarkerEntry(entry) {
  const page = Math.max(1, Math.floor(Number(entry?.page || 1)));
  const xPct = Math.max(0, Math.min(1, Number(entry?.xPct ?? 0.5)));
  const yPct = Math.max(0, Math.min(1, Number(entry?.yPct || 0)));
  const rawEndPage = Number(entry?.endPage || 0);
  const endPage = Number.isFinite(rawEndPage) && rawEndPage > 0 ? Math.max(1, Math.floor(rawEndPage)) : null;
  const endXPct = endPage != null ? Math.max(0, Math.min(1, Number(entry?.endXPct ?? 0.5))) : null;
  const endYPct = endPage != null ? Math.max(0, Math.min(1, Number(entry?.endYPct ?? 0.5))) : null;
  return {
    id: String(entry?.id || `m-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`),
    page,
    xPct,
    yPct,
    endPage,
    endXPct,
    endYPct,
    createdAt: Number(entry?.createdAt || Date.now())
  };
}

function getPdfMarkerLayerLabel(layer) {
  return layer === "owner" ? "Shared marker" : "Private marker";
}

function getPdfMarkerZoneLabel(zone) {
  const safeZone = String(zone || "middle");
  if (safeZone === "upper") return window.translations?.upper || "upper";
  if (safeZone === "lower") return window.translations?.lower || "lower";
  return window.translations?.middle || "middle";
}

function getPdfMarkerLabel(marker) {
  const pct = Number(marker?.yPct || 0);
  const zone = pct < 0.28 ? "upper" : (pct > 0.72 ? "lower" : "middle");
  return `${window.translations?.page || "Page"} ${Number(marker?.page || 1)} - ${getPdfMarkerZoneLabel(zone)}`;
}

function getPdfMarkerEndLabel(marker) {
  const startPage = Number(marker?.page || 0);
  const endPage = Number(marker?.endPage || 0);
  const startYPct = Number(marker?.yPct || 0);
  const endYPct = Number(marker?.endYPct || 0);
  const isForward =
    endPage > startPage ||
    (endPage === startPage && endYPct >= startYPct);
  return isForward
    ? (window.translations?.end || "End")
    : (window.translations?.jump_to || "Jump to");
}

function isPdfMarkerJumpLink(marker) {
  const startPage = Number(marker?.page || 0);
  const endPage = Number(marker?.endPage || 0);
  const startYPct = Number(marker?.yPct || 0);
  const endYPct = Number(marker?.endYPct || 0);
  return endPage < startPage || (endPage === startPage && endYPct < startYPct);
}

function ensurePdfMarkerDock() {
  let dock = document.getElementById("pdfMarkerDock");
  if (!dock) {
    dock = document.createElement("div");
    dock.id = "pdfMarkerDock";
    dock.className = "pdf-marker-dock";
    dock.innerHTML = `<div class="pdf-marker-dock-list"></div>`;
    document.body.appendChild(dock);
  }
  bindMarkerDockInteractions(dock);
  return dock;
}

function schedulePdfMarkerDomRefresh(attempt = 0) {
  if (window._pdfMarkerDomRefreshTimer) {
    clearTimeout(window._pdfMarkerDomRefreshTimer);
  }
  window._pdfMarkerDomRefreshTimer = window.setTimeout(() => {
    window._pdfMarkerDomRefreshTimer = 0;
    const surrogate = String(window.pdfState?.surrogate || window.currentSurrogate || "").trim();
    const owner = String(window.pdfState?.owner || window.getPdfOwnerForSurrogate?.() || "").trim();
    const hasWrappers = !!document.querySelector("#pdfTabContent .pdf-page-wrapper");
    if ((!surrogate || !hasWrappers) && attempt < 8) {
      schedulePdfMarkerDomRefresh(attempt + 1);
      return;
    }
    if (!surrogate || !hasWrappers) return;
    window.renderAllPdfMarkers?.(owner, surrogate);
  }, Math.min(180, 40 + (attempt * 20)));
}

window.schedulePdfMarkerRestore = function (ownerOverride, surrogateOverride, attempts = 2) {
  const owner = String(ownerOverride || window.pdfState?.owner || window.getPdfOwnerForSurrogate?.() || "").trim();
  const surrogate = String(surrogateOverride || window.pdfState?.surrogate || window.currentSurrogate || "").trim();
  if (!surrogate) return;
  window.fetchPdfMarkersFromDb?.(owner, surrogate);
  const token = `${owner}::${surrogate}::${Date.now()}`;
  window._pdfMarkerRestoreToken = token;
  const delays = [0, 90, 220, 420, 760];
  const run = (index) => {
    if (window._pdfMarkerRestoreToken !== token) return;
    if (String(window.pdfState?.surrogate || window.currentSurrogate || "") !== surrogate) return;
    if (document.querySelector("#pdfTabContent .pdf-page-wrapper")) {
      window.renderAllPdfMarkers?.(owner, surrogate);
    }
    if (index >= Math.max(0, attempts - 1)) return;
    setTimeout(() => run(index + 1), delays[Math.min(index + 1, delays.length - 1)]);
  };
  requestAnimationFrame(() => run(0));
};

window.queueInitialPdfMarkerRestore = function (ownerOverride, surrogateOverride) {
  window.schedulePdfMarkerRestore?.(ownerOverride, surrogateOverride, 2);
};

window.fetchPdfMarkersFromDb = async function (ownerOverride, surrogateOverride, force = false) {
  const surrogate = String(surrogateOverride || window.pdfState?.surrogate || window.currentSurrogate || "").trim();
  const owner = String(ownerOverride || window.pdfState?.owner || window.getPdfOwnerForSurrogate?.() || "").trim();
  const sessionUser = String(window.SESSION_USERNAME || "guest").trim() || "guest";
  if (!surrogate || sessionUser === "guest") return false;
  const loadKey = `${owner}::${surrogate}`;
  const currentState = window._pdfMarkerDbLoadState[loadKey];
  if (!force && currentState?.status === "loaded") return true;
  if (!force && currentState?.promise) return currentState.promise;

  const promise = (async () => {
    const requestStartedAt = Date.now();
    try {
      const url = `/getPdfMarkers.php?surrogate=${encodeURIComponent(surrogate)}${owner ? `&owner=${encodeURIComponent(owner)}` : ""}`;
      const res = await fetch(url, { credentials: "include", cache: "no-store" });
      const json = await res.json().catch(() => ({}));
      if (!res.ok || json?.status !== "success") {
        throw new Error(json?.error || `HTTP ${res.status}`);
      }
      const ownerSyncKey = getPdfMarkerSyncKey({ owner, surrogate, layer: "owner" });
      const selfSyncKey = getPdfMarkerSyncKey({ owner, surrogate, layer: "self" });
      const ownerChangedAfterRequest = Number(window._pdfMarkerLastLocalChange[ownerSyncKey] || 0) > requestStartedAt;
      const selfChangedAfterRequest = Number(window._pdfMarkerLastLocalChange[selfSyncKey] || 0) > requestStartedAt;
      if (!ownerChangedAfterRequest && !window._pdfMarkerDirtyState[ownerSyncKey] && !window._pdfMarkerDbSaveTimers[ownerSyncKey]) {
        writePdfMarkersLocal({
          owner,
          surrogate,
          layer: "owner",
          markers: resolvePdfMarkerDbPayload({ owner, surrogate, layer: "owner", dbMarkers: json.ownerMarkers || [] })
        });
      }
      if (!selfChangedAfterRequest && !window._pdfMarkerDirtyState[selfSyncKey] && !window._pdfMarkerDbSaveTimers[selfSyncKey]) {
        writePdfMarkersLocal({
          owner,
          surrogate,
          layer: "self",
          markers: resolvePdfMarkerDbPayload({ owner, surrogate, layer: "self", dbMarkers: json.userMarkers || [] })
        });
      }
      window._pdfMarkerDbLoadState[loadKey] = { status: "loaded", loadedAt: Date.now() };
      if (String(window.pdfState?.surrogate || window.currentSurrogate || "") === surrogate) {
        window.renderAllPdfMarkers?.(owner, surrogate);
      }
      return true;
    } catch (err) {
      console.warn("pdf markers DB load failed:", err);
      window._pdfMarkerDbLoadState[loadKey] = { status: "failed", loadedAt: Date.now() };
      return false;
    }
  })();

  window._pdfMarkerDbLoadState[loadKey] = { status: "loading", promise, loadedAt: Date.now() };
  return promise;
};

window.queuePdfMarkersDbSave = function ({ owner, surrogate, layer, markers }) {
  const sessionUser = String(window.SESSION_USERNAME || "guest").trim() || "guest";
  if (sessionUser === "guest") return;
  const syncKey = getPdfMarkerSyncKey({ owner, surrogate, layer });
  if (!syncKey) return;
  if (window._pdfMarkerDbSaveTimers[syncKey]) {
    clearTimeout(window._pdfMarkerDbSaveTimers[syncKey]);
  }
  window._pdfMarkerLastLocalChange[syncKey] = Date.now();
  window._pdfMarkerDirtyState[syncKey] = true;
  window._pdfMarkerDbSaveTimers[syncKey] = window.setTimeout(async () => {
    delete window._pdfMarkerDbSaveTimers[syncKey];
    try {
      const res = await fetch("/savePdfMarkers.php", {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          owner: String(owner || ""),
          surrogate: String(surrogate || ""),
          layer: layer === "owner" ? "owner" : "self",
          markers: Array.isArray(markers) ? markers.map(normalizePdfMarkerEntry) : []
        })
      });
      const json = await res.json().catch(() => ({}));
      if (!res.ok || json?.status !== "success") {
        throw new Error(json?.error || `HTTP ${res.status}`);
      }
      delete window._pdfMarkerDirtyState[syncKey];
    } catch (err) {
      console.warn("pdf markers DB save failed:", err);
    }
  }, 220);
};

function updatePdfMarkerEntry({ owner, surrogate, layer, markerId, patch }) {
  const safeLayer = layer === "owner" ? "owner" : "self";
  const markers = window.getPdfMarkers({ owner, surrogate, layer: safeLayer }).map((marker) =>
    marker.id === markerId ? normalizePdfMarkerEntry({ ...marker, ...patch }) : marker
  );
  window.savePdfMarkers({ owner, surrogate, layer: safeLayer, markers });
  window.renderAllPdfMarkers(owner, surrogate);
}

function bindMarkerButtonInteractions(button, markerPayload, jumpFn, options = null) {
  if (!button) return;
  let longPressTimer = 0;
  let longPressFired = false;
  const opts = options || {};
  const handleContextMenu = (event) => {
    event.preventDefault();
    event.stopPropagation();
    clearPress();
    window._pdfMarkerActionSuppressUntil = Date.now() + 700;
    window.showPdfMarkerActionMenu?.(markerPayload, event);
  };
  const clearPress = () => {
    if (longPressTimer) {
      clearTimeout(longPressTimer);
      longPressTimer = 0;
    }
  };
  const armPress = (event) => {
    clearPress();
    longPressFired = false;
    longPressTimer = window.setTimeout(() => {
      longPressTimer = 0;
      longPressFired = true;
      window._pdfMarkerActionSuppressUntil = Date.now() + 700;
      if (typeof opts.onLongPress === "function") {
        opts.onLongPress(event);
      } else {
        window.showPdfMarkerActionMenu?.(markerPayload, event);
      }
    }, 520);
  };
  button.addEventListener("pointerdown", armPress);
  button.addEventListener("pointerup", clearPress);
  button.addEventListener("pointercancel", clearPress);
  if (opts.bindContextMenu !== false) {
    button.addEventListener("contextmenu", handleContextMenu);
  }
  button.addEventListener("click", (event) => {
    if (longPressFired || Date.now() < Number(window._pdfMarkerActionSuppressUntil || 0)) {
      event.preventDefault();
      event.stopPropagation();
      longPressFired = false;
      return;
    }
    jumpFn?.(event);
  });
}

function bindMarkerPinDragInteractions(button, markerPayload, options = null) {
  if (!button || button.dataset.markerDragBound === "1") return;
  button.dataset.markerDragBound = "1";
  const opts = options || {};

  const beginDrag = (event) => {
    const wrapper = button.closest(".pdf-page-wrapper");
    if (!wrapper) return;
    const pageKey = opts.isEnd ? "endPage" : "page";
    const xKey = opts.isEnd ? "endXPct" : "xPct";
    const yKey = opts.isEnd ? "endYPct" : "yPct";
    const page = Math.max(1, Math.floor(Number(wrapper.dataset.page || markerPayload?.[pageKey] || 1)));
    const owner = String(markerPayload?.owner || "").trim();
    const surrogate = String(markerPayload?.surrogate || "").trim();
    const layer = String(markerPayload?.layer || "self");
    const markerId = String(markerPayload?.id || "");
    if (!surrogate || !markerId) return;

    const updateFromPoint = (clientX, clientY) => {
      const rect = wrapper.getBoundingClientRect();
      const xPct = Math.max(0, Math.min(1, (clientX - rect.left) / Math.max(1, rect.width)));
      const yPct = Math.max(0, Math.min(1, (clientY - rect.top) / Math.max(1, rect.height)));
      button.style.left = `${Math.max(2.4, Math.min(97.6, xPct * 100))}%`;
      button.style.top = `${Math.max(1.8, Math.min(96.2, yPct * 100))}%`;
      return { xPct, yPct };
    };

    const activePointerId = Number(event?.pointerId);
    const startX = Number(event?.clientX || 0);
    const startY = Number(event?.clientY || 0);
    let lastPos = null;
    let dragging = false;
    window._pdfMarkerActionSuppressUntil = Date.now() + 900;
    if (Number.isFinite(activePointerId) && typeof button.setPointerCapture === "function") {
      try { button.setPointerCapture(activePointerId); } catch {}
    }

    const move = (moveEvent) => {
      if (Number.isFinite(activePointerId) && moveEvent.pointerId !== activePointerId) return;
      const clientX = Number(moveEvent?.clientX || 0);
      const clientY = Number(moveEvent?.clientY || 0);
      if (!dragging && Math.hypot(clientX - startX, clientY - startY) < 6) return;
      if (!dragging) {
        dragging = true;
        button.classList.add("is-dragging");
      }
      lastPos = updateFromPoint(clientX, clientY);
      if (moveEvent?.cancelable) moveEvent.preventDefault();
    };

    const end = (endEvent) => {
      if (Number.isFinite(activePointerId) && endEvent.pointerId !== activePointerId) return;
      window.removeEventListener("pointermove", move, true);
      window.removeEventListener("pointerup", end, true);
      window.removeEventListener("pointercancel", end, true);
      button.classList.remove("is-dragging");
      if (!dragging) {
        window.showPdfMarkerActionMenu?.(markerPayload, endEvent);
        return;
      }
      if (!lastPos) return;
      updatePdfMarkerEntry({
        owner,
        surrogate,
        layer,
        markerId,
        patch: opts.isEnd
          ? { endPage: page, endXPct: lastPos.xPct, endYPct: lastPos.yPct }
          : { page, xPct: lastPos.xPct, yPct: lastPos.yPct }
      });
      if (endEvent?.cancelable) endEvent.preventDefault();
    };

    window.addEventListener("pointermove", move, true);
    window.addEventListener("pointerup", end, true);
    window.addEventListener("pointercancel", end, true);
    if (event?.cancelable) event.preventDefault();
  };

  bindMarkerButtonInteractions(button, markerPayload, null, {
    onLongPress: (event) => {
      beginDrag(event);
    }
  });
}

function bindMarkerDockInteractions(dock) {
  if (!dock || dock.dataset.markerDockBound === "1") return;
  dock.dataset.markerDockBound = "1";
  let longPressTimer = 0;
  const clearPress = () => {
    if (longPressTimer) {
      clearTimeout(longPressTimer);
      longPressTimer = 0;
    }
  };
  const openPanelFromDock = (event) => {
    if (event?.target?.closest?.(".pdf-marker-dock-btn")) return;
    event?.preventDefault?.();
    event?.stopPropagation?.();
    clearPress();
    window.showPdfMarkerPanel?.();
  };
  dock.addEventListener("pointerdown", (event) => {
    if (event.target.closest(".pdf-marker-dock-btn")) return;
    clearPress();
    longPressTimer = window.setTimeout(() => {
      longPressTimer = 0;
      openPanelFromDock(event);
    }, 520);
  });
  dock.addEventListener("pointerup", clearPress);
  dock.addEventListener("pointercancel", clearPress);
  dock.addEventListener("pointerleave", clearPress);
  dock.addEventListener("touchstart", (event) => {
    if (event.target.closest(".pdf-marker-dock-btn")) return;
    clearPress();
    longPressTimer = window.setTimeout(() => {
      longPressTimer = 0;
      openPanelFromDock(event);
    }, 520);
  }, { passive: true });
  dock.addEventListener("touchend", clearPress, { passive: true });
  dock.addEventListener("touchcancel", clearPress, { passive: true });
  dock.addEventListener("contextmenu", openPanelFromDock);
}

function bindMarkerDockButtonInteractions(button, markerPayload, jumpFn) {
  if (!button || button.dataset.markerDockBound === "1") return;
  button.dataset.markerDockBound = "1";

  const owner = String(markerPayload?.owner || "").trim();
  const surrogate = String(markerPayload?.surrogate || "").trim();
  const layer = String(markerPayload?.layer || "self");
  const markerId = String(markerPayload?.id || "");

  const beginDockDrag = (event) => {
    if (!surrogate || !markerId) return;
    const activePointerId = Number(event?.pointerId);
    const startX = Number(event?.clientX || 0);
    const startY = Number(event?.clientY || 0);
    let dragging = false;
    window._pdfMarkerActionSuppressUntil = Date.now() + 900;
    if (Number.isFinite(activePointerId) && typeof button.setPointerCapture === "function") {
      try { button.setPointerCapture(activePointerId); } catch {}
    }

    const move = (moveEvent) => {
      if (Number.isFinite(activePointerId) && moveEvent.pointerId !== activePointerId) return;
      const clientX = Number(moveEvent.clientX || 0);
      const clientY = Number(moveEvent.clientY || 0);
      if (!dragging && Math.hypot(clientX - startX, clientY - startY) < 6) return;
      if (!dragging) {
        dragging = true;
        button.classList.add("is-dragging");
      }
      if (moveEvent.cancelable) moveEvent.preventDefault();
    };

    const end = (endEvent) => {
      if (Number.isFinite(activePointerId) && endEvent.pointerId !== activePointerId) return;
      window.removeEventListener("pointermove", move, true);
      window.removeEventListener("pointerup", end, true);
      window.removeEventListener("pointercancel", end, true);
      button.classList.remove("is-dragging");
      if (!dragging) {
        window.showPdfMarkerActionMenu?.(markerPayload, endEvent);
        return;
      }
      const target = document.elementFromPoint(Number(endEvent.clientX || 0), Number(endEvent.clientY || 0));
      const wrapper = target?.closest?.(".pdf-page-wrapper");
      if (!wrapper) return;
      const rect = wrapper.getBoundingClientRect();
      const endPage = Math.max(1, Math.floor(Number(wrapper.dataset.page || markerPayload?.page || 1)));
      const endXPct = Math.max(0, Math.min(1, (Number(endEvent.clientX || 0) - rect.left) / Math.max(1, rect.width)));
      const endYPct = Math.max(0, Math.min(1, (Number(endEvent.clientY || 0) - rect.top) / Math.max(1, rect.height)));
      updatePdfMarkerEntry({
        owner,
        surrogate,
        layer,
        markerId,
        patch: { endPage, endXPct, endYPct }
      });
      showFlashMessage?.(window.translations?.marker_end_set || "Marker end set.");
      if (endEvent.cancelable) endEvent.preventDefault();
    };

    window.addEventListener("pointermove", move, true);
    window.addEventListener("pointerup", end, true);
    window.addEventListener("pointercancel", end, true);
  };

  bindMarkerButtonInteractions(button, markerPayload, jumpFn, {
    bindContextMenu: true,
    onLongPress: (event) => {
      beginDockDrag(event);
    }
  });
}

function getOrderedPdfMarkers(owner, surrogate) {
  const ownerMarkers = window.getPdfMarkers({ owner, surrogate, layer: "owner" }).map((marker) => ({ ...marker, layer: "owner" }));
  const selfMarkers = window.getPdfMarkers({ owner, surrogate, layer: "self" }).map((marker) => ({ ...marker, layer: "self" }));
  return [...selfMarkers, ...ownerMarkers]
    .sort((a, b) => (a.page - b.page) || (a.yPct - b.yPct) || (a.createdAt - b.createdAt));
}

function getPdfMarkerActorProfile(owner, currentLayer) {
  const safeOwner = String(owner || "").trim();
  const isOwner = currentLayer === "owner" && !!safeOwner;
  const username = isOwner
    ? safeOwner
    : (String(window.SESSION_USERNAME || "guest").trim() || "guest");
  const displayName = isOwner
    ? (window.currentOwner?.display_name || safeOwner || "Owner")
    : (window.SESSION_DISPLAY_NAME || username || "Me");
  const avatarUrl = isOwner
    ? (window.currentOwner?.avatar_url || window.CURRENT_PROFILE_AVATAR_URL || "")
    : (window.SESSION_AVATAR_URL || "");
  return { username, displayName, avatarUrl, isOwner };
}

function schedulePdfMarkerTargetPrewarm(owner, surrogate, markers) {
  if (!window.pagedViewEnabled || !window.pdfState?.pdf) return;
  if (String(window.pdfState?.surrogate || "") !== String(surrogate || "")) return;
  const uniquePages = Array.from(new Set(
    (Array.isArray(markers) ? markers : [])
      .map((marker) => Math.max(1, Math.floor(Number(marker?.page || 0))))
      .filter(Boolean)
  )).sort((a, b) => a - b);
  window.setPinnedPagedPages?.(owner, surrogate, uniquePages);
  if (!uniquePages.length) return;
  const currentPage = Number(window.pdfState?.page || 1);
  const pagesToWarm = uniquePages
    .slice()
    .sort((a, b) => Math.abs(a - currentPage) - Math.abs(b - currentPage));
  const token = `${owner}::${surrogate}::${pagesToWarm.join(",")}`;
  if (window._pdfMarkerPrewarmToken === token) return;
  window._pdfMarkerPrewarmToken = token;
  const run = () => {
    pagesToWarm.forEach((pageNum, index) => {
      setTimeout(() => {
        if (window._pdfMarkerPrewarmToken !== token) return;
        window.prewarmPagedPageRange?.(window.pdfState.pdf, owner, surrogate, pageNum, 1);
      }, index * 110);
    });
  };
  if (typeof window.requestIdleCallback === "function") {
    window.requestIdleCallback(run, { timeout: 400 });
  } else {
    setTimeout(run, 120);
  }
}

window.getPdfMarkers = function ({ owner, surrogate, layer }) {
  const key = getPdfMarkerStorageKey({ owner, surrogate, layer });
  if (!key) {
    return getPdfMarkerSessionSnapshot({ owner, surrogate, layer });
  }
  try {
    const parsed = readPdfMarkerStorageCandidate(key);
    const result = Array.isArray(parsed)
      ? parsed.map(normalizePdfMarkerEntry)
          .sort((a, b) => (a.page - b.page) || (a.yPct - b.yPct) || (a.createdAt - b.createdAt))
      : [];
    const sessionKey = getPdfMarkerSessionKey({ owner, surrogate, layer });
    if (result.length && sessionKey) {
      window._pdfMarkerSessionCache[sessionKey] = result.slice();
    }
    if (!result.length) {
      return getPdfMarkerSessionSnapshot({ owner, surrogate, layer });
    }
    return result;
  } catch {
    return getPdfMarkerSessionSnapshot({ owner, surrogate, layer });
  }
};

window.savePdfMarkers = function ({ owner, surrogate, layer, markers }) {
  const safeMarkers = Array.isArray(markers) ? markers.map(normalizePdfMarkerEntry) : [];
  const syncKey = getPdfMarkerSyncKey({ owner, surrogate, layer });
  if (syncKey) window._pdfMarkerLastLocalChange[syncKey] = Date.now();
  writePdfMarkersLocal({ owner, surrogate, layer, markers: safeMarkers });
  window.queuePdfMarkersDbSave?.({ owner, surrogate, layer, markers: safeMarkers });
};

window.getPdfMarkerBuckets = function (ownerOverride, surrogateOverride) {
  const owner = String(ownerOverride || window.pdfState?.owner || window.getPdfOwnerForSurrogate?.() || "").trim();
  const surrogate = String(surrogateOverride || window.pdfState?.surrogate || window.currentSurrogate || "").trim();
  return {
    owner,
    surrogate,
    ownerMarkers: window.getPdfMarkers({ owner, surrogate, layer: "owner" }),
    selfMarkers: window.getPdfMarkers({ owner, surrogate, layer: "self" })
  };
};

window.canPlaceOwnerPdfMarker = function (ownerOverride) {
  const owner = String(ownerOverride || window.pdfState?.owner || window.getPdfOwnerForSurrogate?.() || "").trim();
  return !!owner && !!window.canAnnotateOwnerBaseLayer?.(owner);
};

window.getCurrentPdfMarkerLayer = function (ownerOverride) {
  const owner = String(ownerOverride || window.pdfState?.owner || window.getPdfOwnerForSurrogate?.() || "").trim();
  if (window.annotationLayerTarget === "owner" && window.canPlaceOwnerPdfMarker(owner)) {
    return "owner";
  }
  return "self";
};

window.renderPdfMarkersForWrapper = function (wrapper, ownerOverride, surrogateOverride) {
  if (!wrapper) return;
  const markerLayer = wrapper.querySelector(".pdf-marker-layer");
  if (!markerLayer) return;
  const { owner, surrogate, ownerMarkers, selfMarkers } = window.getPdfMarkerBuckets(ownerOverride, surrogateOverride);
  if (!surrogate) return;
  const pageNum = Math.max(1, Math.floor(Number(wrapper.dataset.page || 1)));
  markerLayer.innerHTML = "";

  const addMarkerButtons = (markers, layer) => {
    markers
      .filter((marker) => Number(marker.page || 0) === pageNum)
      .forEach((marker) => {
        const allMarkers = getOrderedPdfMarkers(owner, surrogate);
        const displayIndex = Math.max(1, allMarkers.findIndex((entry) => entry.id === marker.id && entry.layer === layer) + 1);
        const pin = document.createElement("button");
        pin.type = "button";
        pin.className = `pdf-marker-pin ${layer === "owner" ? "is-owner" : "is-self"}`;
        pin.dataset.markerId = marker.id;
        pin.dataset.markerLayer = layer;
        pin.dataset.page = String(marker.page);
        pin.dataset.markerLabel = window.translations?.start || "Start";
        pin.style.left = `${Math.max(2.4, Math.min(97.6, marker.xPct * 100))}%`;
        pin.style.top = `${Math.max(1.8, Math.min(96.2, marker.yPct * 100))}%`;
        pin.title = `${getPdfMarkerLayerLabel(layer)}: ${getPdfMarkerLabel(marker)}`;
        pin.setAttribute("aria-label", pin.title);
        pin.textContent = String(displayIndex);
        bindMarkerPinDragInteractions(pin, { ...marker, layer, owner, surrogate });
        pin.addEventListener("click", (event) => {
          if (Date.now() < Number(window._pdfMarkerActionSuppressUntil || 0)) {
            event.preventDefault();
            event.stopPropagation();
            return;
          }
          event.preventDefault();
          event.stopPropagation();
          const jumpTarget = Number(marker.endPage || 0) > 0
            ? {
                ...marker,
                page: Number(marker.endPage || marker.page || 1),
                yPct: Number(marker.endYPct ?? marker.yPct ?? 0),
                xPct: Number(marker.endXPct ?? marker.xPct ?? 0.5),
                layer,
                owner,
                surrogate
              }
            : { ...marker, layer, owner, surrogate };
          window.jumpToPdfMarker?.(jumpTarget, { instant: false });
        });
        markerLayer.appendChild(pin);
      });
  };

  const addEndMarkerButtons = (markers, layer) => {
    markers
      .filter((marker) => Number(marker.endPage || 0) === pageNum)
      .forEach((marker) => {
        const allMarkers = getOrderedPdfMarkers(owner, surrogate);
        const displayIndex = Math.max(1, allMarkers.findIndex((entry) => entry.id === marker.id && entry.layer === layer) + 1);
        const pin = document.createElement("button");
        pin.type = "button";
        pin.className = `pdf-marker-pin is-end ${isPdfMarkerJumpLink(marker) ? "is-end-jump-link" : ""} ${layer === "owner" ? "is-owner" : "is-self"}`.trim();
        pin.dataset.markerLabel = getPdfMarkerEndLabel(marker);
        pin.style.left = `${Math.max(2.4, Math.min(97.6, Number(marker.endXPct || 0.5) * 100))}%`;
        pin.style.top = `${Math.max(1.8, Math.min(96.2, Number(marker.endYPct || 0.5) * 100))}%`;
        pin.title = `${getPdfMarkerLayerLabel(layer)} end: ${getPdfMarkerLabel({ page: marker.endPage, yPct: marker.endYPct })}`;
        pin.setAttribute("aria-label", pin.title);
        pin.innerHTML = `<span class="pdf-marker-pin-text">${displayIndex}</span>`;
        bindMarkerPinDragInteractions(pin, { ...marker, layer, owner, surrogate, isEnd: true }, { isEnd: true });
        pin.addEventListener("click", (event) => {
          if (Date.now() < Number(window._pdfMarkerActionSuppressUntil || 0)) {
            event.preventDefault();
            event.stopPropagation();
            return;
          }
          event.preventDefault();
          event.stopPropagation();
          window.jumpToPdfMarker?.({ ...marker, layer, owner, surrogate }, { instant: false });
        });
        markerLayer.appendChild(pin);
      });
  };

  addMarkerButtons(ownerMarkers, "owner");
  addMarkerButtons(selfMarkers, "self");
  addEndMarkerButtons(ownerMarkers, "owner");
  addEndMarkerButtons(selfMarkers, "self");
  window.refreshPdfMarkerDock?.(owner, surrogate);
};

window.renderAllPdfMarkers = function (ownerOverride, surrogateOverride) {
  const owner = String(ownerOverride || window.pdfState?.owner || window.getPdfOwnerForSurrogate?.() || "").trim();
  const surrogate = String(surrogateOverride || window.pdfState?.surrogate || window.currentSurrogate || "").trim();
  if (!surrogate) return;
  const orderedMarkers = getOrderedPdfMarkers(owner, surrogate);
  const wrappers = Array.from(document.querySelectorAll("#pdfTabContent .pdf-page-wrapper"));
  wrappers.forEach((wrapper) => window.renderPdfMarkersForWrapper(wrapper, owner, surrogate));
  window.refreshPdfMarkerDock?.(owner, surrogate);
  window.refreshPdfMarkerPanel?.();
  schedulePdfMarkerTargetPrewarm(owner, surrogate, orderedMarkers);
};

window.setPdfMarkerPlacementMode = function (layer = "self") {
  const owner = String(window.pdfState?.owner || window.getPdfOwnerForSurrogate?.() || "").trim();
  const surrogate = String(window.pdfState?.surrogate || window.currentSurrogate || "").trim();
  if (!owner || !surrogate) return;
  const requestedLayer = layer === "active" ? window.getCurrentPdfMarkerLayer(owner) : layer;
  const safeLayer = requestedLayer === "owner" && window.canPlaceOwnerPdfMarker(owner) ? "owner" : "self";
  window._pdfMarkerPlacementState = {
    owner,
    surrogate,
    layer: safeLayer,
    armedAt: Date.now()
  };
  window.updatePdfMarkerPaletteUi?.();
  showFlashMessage?.(safeLayer === "owner"
    ? (window.translations?.tap_score_place_owner_marker || "Tap the score to place an owner marker.")
    : (window.translations?.tap_score_place_your_marker || "Tap the score to place your marker."));
};

window.clearPdfMarkerPlacementMode = function () {
  window._pdfMarkerPlacementState = null;
  window.updatePdfMarkerPaletteUi?.();
};

window.clearPdfMarkerMoveMode = function () {
  window._pdfMarkerMoveState = null;
};

window.addPdfMarkerAtPoint = function ({ owner, surrogate, layer, page, xPct, yPct }) {
  const safeLayer = layer === "owner" ? "owner" : "self";
  const markers = window.getPdfMarkers({ owner, surrogate, layer: safeLayer });
  const marker = normalizePdfMarkerEntry({
    id: `m-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
    page,
    xPct,
    yPct,
    createdAt: Date.now()
  });
  markers.push(marker);
  window.savePdfMarkers({ owner, surrogate, layer: safeLayer, markers });
  window.renderAllPdfMarkers(owner, surrogate);
  showFlashMessage?.(safeLayer === "owner"
    ? (window.translations?.owner_marker_added || "Owner marker added.")
    : (window.translations?.marker_added || "Marker added."));
  return marker;
};

window.deletePdfMarker = function ({ owner, surrogate, layer, markerId }) {
  const safeLayer = layer === "owner" ? "owner" : "self";
  const markers = window.getPdfMarkers({ owner, surrogate, layer: safeLayer })
    .filter((marker) => marker.id !== markerId);
  window.savePdfMarkers({ owner, surrogate, layer: safeLayer, markers });
  window.renderAllPdfMarkers(owner, surrogate);
};

window.clearPdfMarkerEnd = function ({ owner, surrogate, layer, markerId }) {
  updatePdfMarkerEntry({
    owner,
    surrogate,
    layer,
    markerId,
    patch: { endPage: null, endXPct: null, endYPct: null }
  });
};

window.showPdfMarkerActionMenu = function (markerPayload, anchorEvent = null) {
  const overlay = document.getElementById("pdfMarkerActionOverlay");
  if (!overlay) return;
  overlay.dataset.markerOwner = String(markerPayload?.owner || "");
  overlay.dataset.markerSurrogate = String(markerPayload?.surrogate || "");
  overlay.dataset.markerLayer = String(markerPayload?.layer || "self");
  overlay.dataset.markerId = String(markerPayload?.id || "");
  overlay.dataset.markerPart = markerPayload?.isEnd ? "end" : "start";
  const title = overlay.querySelector(".pdf-marker-action-title");
  const menu = overlay.querySelector(".pdf-marker-action-menu");
  const deleteBtn = overlay.querySelector('[data-marker-menu-action="delete"]');
  const addEndBtn = overlay.querySelector('[data-marker-menu-action="add-end"]');
  if (title) title.textContent = getPdfMarkerLabel(markerPayload || {});
  if (deleteBtn) {
    deleteBtn.textContent = markerPayload?.isEnd
      ? (window.translations?.delete_end || "Delete end")
      : (window.translations?.delete || "Delete");
  }
  if (addEndBtn) {
    addEndBtn.hidden = !!markerPayload?.isEnd;
    addEndBtn.textContent = window.translations?.add_end_marker || "Add end marker";
  }
  if (menu) {
    const vw = window.innerWidth || document.documentElement.clientWidth || 0;
    const vh = window.innerHeight || document.documentElement.clientHeight || 0;
    const margin = 12;
    const rootStyles = getComputedStyle(document.documentElement);
    const footerHeight = Math.max(
      0,
      Math.round(parseFloat(rootStyles.getPropertyValue("--app-footer-height")) || 0)
    );
    const footerClearance = footerHeight + 20;
    const maxBottom = Math.max(margin, vh - footerClearance - margin);
    let anchorX = Math.round(vw / 2);
    let anchorY = Math.round(vh / 2);
    if (anchorEvent && Number.isFinite(anchorEvent.clientX) && Number.isFinite(anchorEvent.clientY)) {
      anchorX = Math.round(anchorEvent.clientX);
      anchorY = Math.round(anchorEvent.clientY);
    } else if (anchorEvent?.currentTarget?.getBoundingClientRect) {
      const rect = anchorEvent.currentTarget.getBoundingClientRect();
      anchorX = Math.round(rect.left + (rect.width / 2));
      anchorY = Math.round(rect.top + (rect.height / 2));
    }
    const menuWidth = Math.min(320, Math.max(220, vw - 28));
    const menuHeight = Math.max(190, Math.round(menu.getBoundingClientRect().height || 220));
    let left = anchorX - Math.round(menuWidth / 2);
    let top = anchorY - menuHeight - 74;
    if (top < margin) {
      top = Math.min(maxBottom - menuHeight, anchorY - 46);
    }
    left = Math.max(margin, Math.min(vw - menuWidth - margin, left));
    top = Math.max(margin, Math.min(maxBottom - menuHeight, top));
    menu.style.left = `${left}px`;
    menu.style.top = `${top}px`;
  }
  overlay.classList.add("is-open");
};

window.hidePdfMarkerActionMenu = function () {
  const overlay = document.getElementById("pdfMarkerActionOverlay");
  overlay?.classList.remove("is-open");
  const menu = overlay?.querySelector(".pdf-marker-action-menu");
  if (menu) {
    menu.style.left = "";
    menu.style.top = "";
  }
};

window.jumpToPdfMarker = async function (markerLike, options = {}) {
  const marker = normalizePdfMarkerEntry(markerLike || {});
  const owner = String(markerLike?.owner || window.pdfState?.owner || window.getPdfOwnerForSurrogate?.() || "").trim();
  const surrogate = String(markerLike?.surrogate || window.pdfState?.surrogate || window.currentSurrogate || "").trim();
  const container = document.getElementById("pdfTabContent");
  if (!container || !surrogate) return false;
  const instant = options?.instant !== false;

  if (window.pagedViewEnabled && window.pdfState?.pdf) {
    const targetPage = Math.max(1, Math.min(Number(window.pdfState.pdf.numPages || 1), marker.page));
    if (Number(window.pdfState?.page || 0) !== targetPage) {
      const rendered = await window.renderSinglePDFPage?.(window.pdfState.pdf, targetPage, owner, surrogate, container);
      if (!rendered) return false;
    }
  }

  const wrappers = Array.from(container.querySelectorAll(".pdf-page-wrapper"));
  const wrapper = wrappers.find((entry) => Number(entry.dataset.page || 0) === marker.page) || wrappers[0] || null;
  if (!wrapper) return false;
  const maxScroll = Math.max(0, container.scrollHeight - container.clientHeight);
  const anchorTop = wrapper.offsetTop + (wrapper.offsetHeight * marker.yPct) - Math.round(container.clientHeight * 0.28);
  const targetTop = Math.max(0, Math.min(maxScroll, anchorTop));
  requestAnimationFrame(() => {
    container.scrollTo({ top: targetTop, behavior: instant ? "auto" : "smooth" });
    window.showPdfPageIndicator?.(
      surrogate,
      marker.page,
      Math.max(1, Number(window.pdfState?.pdf?.numPages || container.querySelectorAll(".pdf-page-wrapper").length || 1))
    );
  });
  return true;
};

window.refreshPdfMarkerDock = function (ownerOverride, surrogateOverride) {
  const dock = ensurePdfMarkerDock();
  if (!dock) return;
  const owner = String(ownerOverride || window.pdfState?.owner || window.getPdfOwnerForSurrogate?.() || "").trim();
  const surrogate = String(surrogateOverride || window.pdfState?.surrogate || window.currentSurrogate || "").trim();
  if (!surrogate) return;
  const allMarkers = surrogate ? getOrderedPdfMarkers(owner, surrogate) : [];
  const list = dock.querySelector(".pdf-marker-dock-list");
  if (!list) return;
  list.innerHTML = "";
  dock.classList.toggle("is-visible", allMarkers.length > 0);
  if (!allMarkers.length) return;
  allMarkers.forEach((marker, index) => {
    const btn = document.createElement("button");
    btn.type = "button";
    btn.className = `pdf-marker-dock-btn ${marker.layer === "owner" ? "is-owner" : "is-self"}`;
    btn.textContent = String(index + 1);
    btn.title = `${getPdfMarkerLayerLabel(marker.layer)}: ${getPdfMarkerLabel(marker)}`;
    bindMarkerDockButtonInteractions(
      btn,
      { ...marker, owner, surrogate, layer: marker.layer },
      () => {
        window.jumpToPdfMarker({ ...marker, owner, surrogate, layer: marker.layer }, { instant: true });
      }
    );
    list.appendChild(btn);
  });
};

window.refreshPdfMarkerPanel = function () {
  const overlay = document.getElementById("pdfMarkerPanelOverlay");
  if (!overlay) return;
  const owner = String(overlay.dataset.owner || window.pdfState?.owner || window.getPdfOwnerForSurrogate?.() || "").trim();
  const surrogate = String(overlay.dataset.surrogate || window.pdfState?.surrogate || window.currentSurrogate || "").trim();
  if (!surrogate) return;
  if (!overlay.dataset.surrogate) overlay.dataset.surrogate = surrogate;
  if (!overlay.dataset.owner && owner) overlay.dataset.owner = owner;
  const ownerMarkers = window.getPdfMarkers({ owner, surrogate, layer: "owner" });
  const selfMarkers = window.getPdfMarkers({ owner, surrogate, layer: "self" });
  const canOwner = window.canPlaceOwnerPdfMarker(owner);
  const ownerSection = overlay.querySelector('[data-marker-section="owner"]');
  const selfSection = overlay.querySelector('[data-marker-section="self"]');
  const placeBtn = overlay.querySelector('[data-marker-action="place-active"]');
  const selfLayerBtn = overlay.querySelector('[data-marker-layer="self"]');
  const ownerLayerBtn = overlay.querySelector('[data-marker-layer="owner"]');
  const actorBtn = overlay.querySelector('[data-marker-role="actor-button"]');
  const actorAvatar = overlay.querySelector('[data-marker-role="actor-avatar"]');
  overlay.classList.toggle("is-armed", !!window._pdfMarkerPlacementState || !!window._pdfMarkerMoveState);
  const currentLayer = window.getCurrentPdfMarkerLayer(owner);
  if (selfLayerBtn) {
    selfLayerBtn.classList.toggle("active", currentLayer !== "owner");
  }
  if (ownerLayerBtn) {
    ownerLayerBtn.disabled = !canOwner;
    ownerLayerBtn.hidden = !canOwner;
    ownerLayerBtn.classList.toggle("active", currentLayer === "owner");
  }
  if (actorBtn && actorAvatar) {
    const actor = getPdfMarkerActorProfile(owner, currentLayer);
    const avatarSrc = typeof window.twResolveAvatarUrl === "function"
      ? window.twResolveAvatarUrl(
          {
            username: actor.username,
            display_name: actor.displayName,
            avatar_url: actor.avatarUrl
          },
          actor.displayName || actor.username
        )
      : (actor.avatarUrl || "/default-avatar.png");
    actorAvatar.src = avatarSrc;
    actorAvatar.alt = `${actor.displayName || actor.username} avatar`;
    actorBtn.classList.toggle("is-owner-layer", !!actor.isOwner);
    actorBtn.classList.toggle("is-self-layer", !actor.isOwner);
    actorBtn.title = actor.displayName || actor.username || "";
  }
  if (placeBtn) {
    placeBtn.disabled = !surrogate || (currentLayer === "owner" && !canOwner);
    placeBtn.textContent = window.translations?.place_marker || "Place marker";
  }

  const renderList = (host, markers, layer, emptyText, canDelete = true) => {
    if (!host) return;
    host.innerHTML = "";
    if (!markers.length) {
      const empty = document.createElement("div");
      empty.className = "pdf-marker-empty";
      empty.textContent = emptyText;
      host.appendChild(empty);
      return;
    }
    markers.forEach((marker, index) => {
      const row = document.createElement("div");
      row.className = "pdf-marker-row";
      const jumpBtn = document.createElement("button");
      jumpBtn.type = "button";
      jumpBtn.className = "pdf-marker-row-main";
      jumpBtn.innerHTML = `
        <span class="pdf-marker-row-badge ${layer === "owner" ? "is-owner" : "is-self"}">${index + 1}</span>
        <span class="pdf-marker-row-text">${getPdfMarkerLabel(marker)}</span>
      `;
      jumpBtn.addEventListener("click", () => {
        window.jumpToPdfMarker({ ...marker, owner, surrogate, layer }, { instant: true });
        window.hidePdfMarkerPanel?.();
      });
      const deleteBtn = document.createElement("button");
      deleteBtn.type = "button";
      deleteBtn.className = "pdf-marker-row-delete";
      deleteBtn.textContent = window.translations?.delete || "Delete";
      deleteBtn.disabled = !canDelete;
      deleteBtn.addEventListener("click", () => {
        if (!canDelete) return;
        window.deletePdfMarker({ owner, surrogate, layer, markerId: marker.id });
      });
      row.appendChild(jumpBtn);
      row.appendChild(deleteBtn);
      host.appendChild(row);
    });
  };

  renderList(selfSection, selfMarkers, "self", window.translations?.no_personal_markers_yet || "No personal markers yet.", true);
  renderList(
    ownerSection,
    ownerMarkers,
    "owner",
    canOwner
      ? "No shared markers yet."
      : "Shared markers unavailable here.",
    canOwner
  );
};

window.showPdfMarkerPanel = function () {
  const overlay = document.getElementById("pdfMarkerPanelOverlay");
  if (!overlay) return;
  const owner = String(window.pdfState?.owner || window.getPdfOwnerForSurrogate?.() || "").trim();
  const surrogate = String(window.pdfState?.surrogate || window.currentSurrogate || "").trim();
  if (!surrogate) return;
  overlay.dataset.owner = owner;
  overlay.dataset.surrogate = surrogate;
  overlay.classList.add("is-open");
  window.refreshPdfMarkerPanel?.();
};

window.hidePdfMarkerPanel = function () {
  document.getElementById("pdfMarkerPanelOverlay")?.classList.remove("is-open");
};

window.updatePdfMarkerPaletteUi = function () {
  const placeBtn = document.querySelector('#drawingPalette .Palette-btn[data-action="placemarker"]');
  const listBtn = document.querySelector('#drawingPalette .Palette-btn[data-action="showmarkers"]');
  const owner = String(window.pdfState?.owner || window.getPdfOwnerForSurrogate?.() || "").trim();
  const currentLayer = window.getCurrentPdfMarkerLayer(owner);
  const canOwner = window.canPlaceOwnerPdfMarker(owner);
  if (placeBtn) {
    placeBtn.classList.toggle("active", !!window._pdfMarkerPlacementState);
    placeBtn.disabled = currentLayer === "owner" && !canOwner;
    placeBtn.title = currentLayer === "owner"
      ? (window.translations?.place_owner_marker || "Place owner marker")
      : (window.translations?.place_my_marker || "Place my marker");
  }
  if (listBtn) {
    listBtn.title = window.translations?.rehearsal_markers || "Rehearsal markers";
  }
};

window.initPdfMarkerUi = function () {
  if (!document.getElementById("pdfMarkerPanelOverlay")) {
    const markerOverlay = document.createElement("div");
    markerOverlay.id = "pdfMarkerPanelOverlay";
    markerOverlay.className = "pdf-marker-panel-overlay";
    markerOverlay.innerHTML = `
      <div class="pdf-marker-panel" role="dialog" aria-modal="true" aria-labelledby="pdfMarkerPanelTitle">
        <div class="pdf-marker-panel-header">
          <div>
            <h3 id="pdfMarkerPanelTitle">${window.translations?.rehearsal_markers || "Markers"}</h3>
          </div>
          <div class="pdf-marker-panel-header-tools">
            <button type="button" class="pdf-marker-actor-btn is-self-layer" data-marker-role="actor-button" aria-label="${window.translations?.selected_markers_scope || "Selected markers"}">
              <img class="pdf-marker-actor-avatar" data-marker-role="actor-avatar" src="/default-avatar.png" alt="${window.translations?.selected_markers_scope || "Selected markers"}">
            </button>
            <button type="button" class="pdf-marker-panel-close" aria-label="${window.translations?.close || "Close"}">×</button>
          </div>
        </div>
        <p class="pdf-marker-panel-copy">${window.translations?.marker_panel_copy || "Store markers for repeats and quick return points in scores."}</p>
        <div class="pdf-marker-panel-layer">
          <div class="pdf-marker-panel-layer-label">${window.translations?.mark || "Mark:"}</div>
          <div class="pdf-marker-panel-layer-toggle" role="group" aria-label="${window.translations?.markers || "Markers"}">
            <button type="button" class="pdf-marker-layer-btn" data-marker-layer="self">${window.translations?.private_markers || "Private markers"}</button>
            <button type="button" class="pdf-marker-layer-btn is-owner" data-marker-layer="owner">${window.translations?.shared_markers || "Shared markers"}</button>
          </div>
        </div>
        <div class="pdf-marker-panel-actions">
          <button type="button" class="pdf-marker-panel-btn" data-marker-action="place-active">${window.translations?.place_marker || "Place marker"}</button>
        </div>
        <div class="pdf-marker-panel-sections">
          <section class="pdf-marker-section">
            <h4>${window.translations?.private_markers || "Private markers"}</h4>
            <div class="pdf-marker-list" data-marker-section="self"></div>
          </section>
          <section class="pdf-marker-section">
            <h4>${window.translations?.shared_markers || "Shared markers"}</h4>
            <div class="pdf-marker-list" data-marker-section="owner"></div>
          </section>
        </div>
      </div>
    `;
    document.body.appendChild(markerOverlay);

    markerOverlay.addEventListener("click", (e) => {
      if (e.target === markerOverlay || e.target.closest(".pdf-marker-panel-close")) {
        window.hidePdfMarkerPanel?.();
        return;
      }
      const actionBtn = e.target.closest("[data-marker-action]");
      const layerBtn = e.target.closest("[data-marker-layer]");
      if (layerBtn) {
        const owner = String(markerOverlay.dataset.owner || window.pdfState?.owner || window.getPdfOwnerForSurrogate?.() || "").trim();
        const surrogate = String(markerOverlay.dataset.surrogate || window.pdfState?.surrogate || window.currentSurrogate || "").trim();
        const nextLayer = layerBtn.getAttribute("data-marker-layer") === "owner" ? "owner" : "self";
        if (nextLayer !== "owner" || window.canPlaceOwnerPdfMarker(owner)) {
          if (typeof window.setAnnotationLayerTarget === "function") {
            window.setAnnotationLayerTarget(nextLayer);
          } else {
            window.annotationLayerTarget = nextLayer;
          }
          window.prepareDrawingActorUI?.(owner);
          window.updatePdfMarkerPaletteUi?.();
          window.refreshPdfMarkerPanel?.();
          window.renderAllPdfMarkers?.(owner, surrogate);
        }
        return;
      }
      if (!actionBtn) return;
      if (actionBtn.getAttribute("data-marker-action") === "place-active") {
        window.setPdfMarkerPlacementMode?.("active");
        window.hidePdfMarkerPanel?.();
        return;
      }
      window.hidePdfMarkerPanel?.();
    });
  }

  ensurePdfMarkerDock();

  if (!document.getElementById("pdfMarkerActionOverlay")) {
    const actionOverlay = document.createElement("div");
    actionOverlay.id = "pdfMarkerActionOverlay";
    actionOverlay.className = "pdf-marker-action-overlay";
    actionOverlay.innerHTML = `
      <div class="pdf-marker-action-menu" role="dialog" aria-modal="true" aria-labelledby="pdfMarkerActionTitle">
        <div class="pdf-marker-action-title" id="pdfMarkerActionTitle">${window.translations?.marker || "Marker"}</div>
        <button type="button" class="pdf-marker-action-btn" data-marker-menu-action="add">${window.translations?.add_another || "Add another"}</button>
        <button type="button" class="pdf-marker-action-btn" data-marker-menu-action="add-end">${window.translations?.add_end_marker || "Add end marker"}</button>
        <button type="button" class="pdf-marker-action-btn" data-marker-menu-action="move">${window.translations?.reposition || "Reposition"}</button>
        <button type="button" class="pdf-marker-action-btn is-danger" data-marker-menu-action="delete">${window.translations?.delete || "Delete"}</button>
        <button type="button" class="pdf-marker-action-btn is-secondary" data-marker-menu-action="close">${window.translations?.close || "Close"}</button>
      </div>
    `;
    document.body.appendChild(actionOverlay);
    actionOverlay.addEventListener("click", (e) => {
      if (e.target === actionOverlay) {
        window.hidePdfMarkerActionMenu?.();
        return;
      }
      const btn = e.target.closest("[data-marker-menu-action]");
      if (!btn) return;
      const action = btn.getAttribute("data-marker-menu-action");
      const owner = String(actionOverlay.dataset.markerOwner || "");
      const surrogate = String(actionOverlay.dataset.markerSurrogate || "");
      const layer = String(actionOverlay.dataset.markerLayer || "self");
      const markerId = String(actionOverlay.dataset.markerId || "");
      const markerPart = String(actionOverlay.dataset.markerPart || "start");
      if (action === "add") {
        window.setPdfMarkerPlacementMode?.(layer);
      } else if (action === "add-end") {
        window._pdfMarkerMoveState = { owner, surrogate, layer, markerId, markerPart: "end" };
        showFlashMessage?.(window.translations?.tap_new_end_marker_position || "Tap the new end marker position.");
      } else if (action === "move") {
        window._pdfMarkerMoveState = { owner, surrogate, layer, markerId, markerPart };
        showFlashMessage?.(
          markerPart === "end"
            ? (window.translations?.tap_new_end_marker_position || "Tap the new end marker position.")
            : (window.translations?.tap_new_marker_position || "Tap the new marker position.")
        );
      } else if (action === "delete") {
        if (markerPart === "end") {
          window.clearPdfMarkerEnd?.({ owner, surrogate, layer, markerId });
        } else {
          window.deletePdfMarker?.({ owner, surrogate, layer, markerId });
        }
      }
      window.hidePdfMarkerActionMenu?.();
    });
  }

  const pdfContainer = document.getElementById("pdfTabContent");
  if (pdfContainer && pdfContainer.dataset.pdfMarkerCaptureBound !== "1") {
    pdfContainer.dataset.pdfMarkerCaptureBound = "1";
    const handlePlacementEvent = (e, point) => {
      const placement = window._pdfMarkerPlacementState;
      const moveState = window._pdfMarkerMoveState;
      if (!placement && !moveState) return;
      if (e.target.closest(".pdf-marker-pin")) return;
      const wrapper = e.target.closest(".pdf-page-wrapper");
      if (!wrapper) return;
      const rect = wrapper.getBoundingClientRect();
      const clientX = Number(point?.clientX);
      const clientY = Number(point?.clientY);
      if (!Number.isFinite(clientX) || !Number.isFinite(clientY)) return;
      const xPct = Math.max(0, Math.min(1, (clientX - rect.left) / Math.max(1, rect.width)));
      const yPct = Math.max(0, Math.min(1, (clientY - rect.top) / Math.max(1, rect.height)));
      const page = Math.max(1, Math.floor(Number(wrapper.dataset.page || 1)));
      if (moveState) {
        updatePdfMarkerEntry({
          owner: moveState.owner,
          surrogate: moveState.surrogate,
          layer: moveState.layer,
          markerId: moveState.markerId,
          patch: moveState.markerPart === "end"
            ? { endPage: page, endXPct: xPct, endYPct: yPct }
            : { page, xPct, yPct }
        });
        window.clearPdfMarkerMoveMode?.();
        showFlashMessage?.(
          moveState.markerPart === "end"
            ? (window.translations?.end_marker_repositioned || "End marker repositioned.")
            : (window.translations?.marker_repositioned || "Marker repositioned.")
        );
      } else if (placement) {
        window.addPdfMarkerAtPoint?.({
          owner: placement.owner,
          surrogate: placement.surrogate,
          layer: placement.layer,
          page,
          xPct,
          yPct
        });
        window.clearPdfMarkerPlacementMode?.();
      }
      e.preventDefault();
      e.stopPropagation();
    };

    pdfContainer.addEventListener("pointerdown", (e) => {
      handlePlacementEvent(e, e);
    }, true);

    pdfContainer.addEventListener("touchstart", (e) => {
      handlePlacementEvent(e, e.changedTouches?.[0] || e.touches?.[0] || null);
    }, { capture: true, passive: false });
  }

  if (pdfContainer && pdfContainer.dataset.pdfMarkerObserverBound !== "1") {
    pdfContainer.dataset.pdfMarkerObserverBound = "1";
    const observer = new MutationObserver((records) => {
      let shouldRefresh = false;
      for (const record of records) {
        const added = Array.from(record.addedNodes || []);
        const removed = Array.from(record.removedNodes || []);
        const changedNodes = added.concat(removed);
        if (changedNodes.some((node) => {
          if (!(node instanceof HTMLElement)) return false;
          if (node.classList?.contains("pdf-page-wrapper")) return true;
          return !!node.querySelector?.(".pdf-page-wrapper");
        })) {
          shouldRefresh = true;
          break;
        }
      }
      if (shouldRefresh) schedulePdfMarkerDomRefresh();
    });
    observer.observe(pdfContainer, { childList: true, subtree: true });
    window._pdfMarkerDomObserver = observer;
  }

  const activeOwner = String(window.pdfState?.owner || window.getPdfOwnerForSurrogate?.() || "").trim();
  const activeSurrogate = String(window.pdfState?.surrogate || window.currentSurrogate || "").trim();
  if (activeSurrogate && document.querySelector("#pdfTabContent .pdf-page-wrapper")) {
    window.renderAllPdfMarkers?.(activeOwner, activeSurrogate);
  }
};
