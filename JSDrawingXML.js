logStep("JSDrawingXML.js executed");

(() => {
  const R2_LIST_ENDPOINT = "https://r2-worker.textwhisper.workers.dev/list";
  const XML_EXT_RE = /\.(xml|musicxml|mxl)$/i;
  const XML_MIME_HINT_RE = /musicxml|application\/xml|text\/xml/i;
  const XML_PLAYBACK_DIVISION = 480;
  const XML_PLAYBACK_SPEED_STORAGE_KEY = "twXmlPlaybackSpeed";
  const XML_PLAYBACK_SPEED_DEFAULT = 1;
  const XML_PLAYBACK_SPEED_OPTIONS = [0.5, 0.75, 1, 1.25, 1.5, 2];
  const XML_PLAYBACK_VISUALS_STORAGE_KEY = "twXmlPlaybackVisuals";
  const XML_LAYOUT_SCALE_STORAGE_KEY = "twXmlLayoutScale";
  const XML_LAYOUT_SCALE_MIN = 0.8;
  const XML_LAYOUT_SCALE_MAX = 1.6;
  const XML_LAYOUT_SCALE_DEFAULT = 1;
  const XML_RENDER_TRANSPOSE_MIN = -12;
  const XML_RENDER_TRANSPOSE_MAX = 12;
  const XML_LAYOUT_BASE_WIDTH = 1180;
  const XML_OSMD_OPTIONS_STORAGE_KEY = "twXmlOsmdRenderOptions";
  const XML_OSMD_DEFAULT_RENDER_OPTIONS = Object.freeze({
    autoResize: false,
    drawingParameters: "compacttight",
    drawTitle: true,
    drawCredits: true,
    drawComposer: true,
    drawLyricist: true,
    drawPartNames: true
  });
  const XML_OSMD_SETTINGS_CATALOG = Object.freeze([
    { key: "autoResize", type: "boolean", label: "Auto resize" },
    { key: "drawingParameters", type: "select", label: "Drawing mode", options: ["default", "compact", "compacttight", "thumbnail"] },
    { key: "drawTitle", type: "boolean", label: "Draw title" },
    { key: "drawSubtitle", type: "boolean", label: "Draw subtitle" },
    { key: "drawCredits", type: "boolean", label: "Draw credits" },
    { key: "drawComposer", type: "boolean", label: "Draw composer" },
    { key: "drawLyricist", type: "boolean", label: "Draw lyricist" },
    { key: "drawPartNames", type: "boolean", label: "Draw part names" },
    { key: "drawPartAbbreviations", type: "boolean", label: "Draw part abbreviations" },
    { key: "drawMeasureNumbers", type: "boolean", label: "Draw measure numbers" },
    { key: "drawLyrics", type: "boolean", label: "Draw lyrics" },
    { key: "renderSingleHorizontalStaffline", type: "boolean", label: "Single horizontal staffline" },
    { key: "newSystemFromXML", type: "boolean", label: "New system from XML" },
    { key: "newPageFromXML", type: "boolean", label: "New page from XML" },
    { key: "backend", type: "select", label: "Backend", options: ["svg", "canvas"] },
    { key: "spacingFactorSoftmax", type: "number", label: "Spacing softmax" },
    { key: "spacingBetweenTextLines", type: "number", label: "Text line spacing" },
    { key: "stretchLastSystemLine", type: "boolean", label: "Stretch last system line" },
    { key: "followCursor", type: "boolean", label: "Follow cursor" },
    { key: "disableCursor", type: "boolean", label: "Disable cursor" }
  ]);

  let osmdLoader = null;
  let jsZipLoader = null;

  function buildXmlFileStateId(surrogate = "", key = "") {
    const safeSurrogate = String(surrogate || "").trim();
    const safeKey = String(key || "").trim();
    if (!safeSurrogate || !safeKey) return "";
    return `${safeSurrogate}::${safeKey}`;
  }

  function getXmlEditHistoryStore() {
    if (!window._twXmlEditHistoryByFileId || typeof window._twXmlEditHistoryByFileId !== "object") {
      window._twXmlEditHistoryByFileId = {};
    }
    return window._twXmlEditHistoryByFileId;
  }

  function getXmlEditedStateStore() {
    if (!window._twXmlEditedStateByFileId || typeof window._twXmlEditedStateByFileId !== "object") {
      window._twXmlEditedStateByFileId = {};
    }
    return window._twXmlEditedStateByFileId;
  }

  function getXmlFileHistory(fileId = "") {
    const safeId = String(fileId || "").trim();
    if (!safeId) return [];
    const store = getXmlEditHistoryStore();
    if (!Array.isArray(store[safeId])) store[safeId] = [];
    return store[safeId];
  }

  function rebuildXmlEditedStateFromHistory(fileId = "") {
    const safeId = String(fileId || "").trim();
    if (!safeId) return;
    const history = getXmlFileHistory(safeId);
    const edited = {
      noteSourceIndexes: {},
      partIndexes: {}
    };
    history.forEach((entry) => {
      const sourceIndex = Number(entry?.sourceIndex);
      const partIndex = Number(entry?.partIndex);
      if (Number.isFinite(sourceIndex) && sourceIndex >= 0) {
        edited.noteSourceIndexes[String(Math.floor(sourceIndex))] = true;
      }
      if (Number.isFinite(partIndex) && partIndex >= 0) {
        edited.partIndexes[String(Math.floor(partIndex))] = true;
      }
    });
    getXmlEditedStateStore()[safeId] = edited;
  }

  function addXmlEditHistoryEntry(entry = {}) {
    const safeSurrogate = String(entry?.surrogate || "").trim();
    const safeKey = String(entry?.key || "").trim();
    const fileId = buildXmlFileStateId(safeSurrogate, safeKey);
    if (!fileId) return;
    const beforeXml = String(entry?.beforeXml || "");
    const afterXml = String(entry?.afterXml || "");
    if (!beforeXml || !afterXml || beforeXml === afterXml) return;
    const history = getXmlFileHistory(fileId);
    history.push({
      kind: String(entry?.kind || "xml-edit"),
      sourceIndex: Number.isFinite(Number(entry?.sourceIndex)) ? Math.max(0, Math.floor(Number(entry.sourceIndex))) : null,
      partIndex: Number.isFinite(Number(entry?.partIndex)) ? Math.max(0, Math.floor(Number(entry.partIndex))) : null,
      editedAt: Date.now(),
      beforeXml,
      afterXml
    });
    if (history.length > 120) {
      history.splice(0, history.length - 120);
    }
    rebuildXmlEditedStateFromHistory(fileId);
  }

  async function undoLastXmlEditForFile(surrogate = "", key = "") {
    const safeSurrogate = String(surrogate || "").trim();
    const safeKey = String(key || "").trim();
    const fileId = buildXmlFileStateId(safeSurrogate, safeKey);
    if (!fileId) return { ok: false, reason: "missing-file-id" };
    const history = getXmlFileHistory(fileId);
    if (!history.length) return { ok: false, reason: "empty-history" };
    const entry = history[history.length - 1];
    const beforeXml = String(entry?.beforeXml || "");
    if (!beforeXml) return { ok: false, reason: "invalid-history-entry" };
    const saved = await uploadMusicXmlTextByKey(safeKey, beforeXml);
    if (!saved) return { ok: false, reason: "upload-failed" };
    history.pop();
    rebuildXmlEditedStateFromHistory(fileId);
    return { ok: true, entry };
  }

  function applyXmlEditedVisuals(container, surrogate = "", key = "") {
    const safeSurrogate = String(surrogate || "").trim();
    const safeKey = String(key || "").trim();
    const fileId = buildXmlFileStateId(safeSurrogate, safeKey);
    const editedState = fileId ? getXmlEditedStateStore()[fileId] : null;
    const editedPartIndexes = editedState?.partIndexes || {};
    const editedNoteSourceIndexes = editedState?.noteSourceIndexes || {};

    const rows = Array.isArray(container?._twXmlStafflineEntries) ? container._twXmlStafflineEntries : [];
    rows.forEach((row) => {
      const el = row?.el;
      if (!el) return;
      if (!el.dataset.twXmlEditedBound) {
        el.dataset.twXmlEditedBound = "1";
        el.dataset.twXmlEditedFilter = el.style.filter || "";
      }
      const partIndexKey = String(Math.max(0, Number(row?.partIndex || 0)));
      if (editedPartIndexes[partIndexKey]) {
        el.style.filter = "drop-shadow(0 0 1px rgba(59,130,246,0.55)) saturate(1.06)";
      } else {
        el.style.filter = el.dataset.twXmlEditedFilter || "";
      }
    });

    const noteEls = Array.isArray(container?._twXmlPlayableNoteEls) ? container._twXmlPlayableNoteEls : [];
    noteEls.forEach((el) => {
      if (!el) return;
      const sourceIndex = Number(el.dataset.twXmlSourceIndex);
      const applyBlue = Number.isFinite(sourceIndex) && !!editedNoteSourceIndexes[String(Math.max(0, Math.floor(sourceIndex)))];
      const noteNodes = [el, ...Array.from(el.querySelectorAll("path, ellipse, circle, polygon, rect, use"))];
      noteNodes.forEach((node) => {
        if (!node?.style) return;
        if (!node.dataset.twXmlEditedBound) {
          node.dataset.twXmlEditedBound = "1";
          node.dataset.twXmlEditedFilter = node.style.filter || "";
        }
        const glow = "drop-shadow(0 0 5px rgba(37,99,235,0.95)) brightness(1.08)";
        if (applyBlue) {
          node.dataset.twXmlEditedActive = "1";
          node.dataset.twXmlEditedGlow = glow;
          if (el.dataset.twXmlSelectedActive !== "1") {
            node.style.filter = glow;
          }
        } else {
          node.dataset.twXmlEditedActive = "0";
          node.dataset.twXmlEditedGlow = "";
          if (el.dataset.twXmlSelectedActive !== "1") {
            node.style.filter = node.dataset.twXmlEditedFilter || "";
          }
        }
      });
    });
  }

  function canUseMusicXmlInlineEditing() {
    const adminFlag = window.isAdminUser;
    if (adminFlag === true || adminFlag === 1 || adminFlag === "1" || adminFlag === "true") return true;
    const roleRank = Number(window.currentUserItemRoleRank || 0);
    if (Number.isFinite(roleRank) && roleRank >= 80) return true;
    return false;
  }

  function makeFloatingPanelDraggable(panel, dragHandle) {
    if (!panel || !dragHandle) return;
    if (panel.dataset.twDragBound === "1") return;
    panel.dataset.twDragBound = "1";
    dragHandle.style.cursor = "move";
    dragHandle.style.userSelect = "none";
    dragHandle.style.touchAction = "none";

    const startDrag = (startX, startY) => {
      panel.style.position = "fixed";
      const rect = panel.getBoundingClientRect();
      panel.style.left = `${Math.round(rect.left)}px`;
      panel.style.top = `${Math.round(rect.top)}px`;
      panel.style.transform = "none";
      let originLeft = Number(rect.left || 0);
      let originTop = Number(rect.top || 0);
      const baseX = Number(startX || 0);
      const baseY = Number(startY || 0);

      const move = (clientX, clientY) => {
        const dx = Number(clientX || 0) - baseX;
        const dy = Number(clientY || 0) - baseY;
        const nextLeft = originLeft + dx;
        const nextTop = originTop + dy;
        const viewW = Math.max(320, Number(window.innerWidth || 0));
        const viewH = Math.max(240, Number(window.innerHeight || 0));
        const panelRect = panel.getBoundingClientRect();
        const panelW = Math.max(220, Number(panelRect.width || 0));
        const panelH = Math.max(120, Number(panelRect.height || 0));
        const clampedLeft = Math.max(8, Math.min(viewW - panelW - 8, nextLeft));
        const clampedTop = Math.max(8, Math.min(viewH - panelH - 8, nextTop));
        panel.style.left = `${Math.round(clampedLeft)}px`;
        panel.style.top = `${Math.round(clampedTop)}px`;
      };

      const onMouseMove = (event) => {
        move(event.clientX, event.clientY);
      };
      const onMouseUp = () => {
        document.removeEventListener("mousemove", onMouseMove, true);
        document.removeEventListener("mouseup", onMouseUp, true);
      };
      const onTouchMove = (event) => {
        const touch = event.touches?.[0];
        if (!touch) return;
        event.preventDefault();
        move(touch.clientX, touch.clientY);
      };
      const onTouchEnd = () => {
        document.removeEventListener("touchmove", onTouchMove, true);
        document.removeEventListener("touchend", onTouchEnd, true);
        document.removeEventListener("touchcancel", onTouchEnd, true);
      };

      document.addEventListener("mousemove", onMouseMove, true);
      document.addEventListener("mouseup", onMouseUp, true);
      document.addEventListener("touchmove", onTouchMove, { capture: true, passive: false });
      document.addEventListener("touchend", onTouchEnd, true);
      document.addEventListener("touchcancel", onTouchEnd, true);
    };

    dragHandle.addEventListener("mousedown", (event) => {
      if (event.target?.closest?.("button, input, textarea, select, a")) return;
      if (Number(event.button || 0) !== 0) return;
      event.preventDefault();
      startDrag(event.clientX, event.clientY);
    });

    dragHandle.addEventListener("touchstart", (event) => {
      if (event.target?.closest?.("button, input, textarea, select, a")) return;
      const touch = event.touches?.[0];
      if (!touch) return;
      event.preventDefault();
      startDrag(touch.clientX, touch.clientY);
    }, { passive: false });
  }

  function bindLongPressGesture(target, onLongPress, opts = {}) {
    if (!target || typeof onLongPress !== "function") return () => {};
    const thresholdMs = Math.max(240, Number(opts?.thresholdMs || 560));
    const moveTolerance = Math.max(6, Number(opts?.moveTolerance || 12));
    const enableMouse = opts?.mouse !== false;
    let timer = 0;
    let startX = 0;
    let startY = 0;
    let pressing = false;
    let moved = false;

    const removeDocumentTouchListeners = () => {
      document.removeEventListener("touchmove", onDocumentTouchMove, true);
      document.removeEventListener("touchend", onDocumentTouchEnd, true);
      document.removeEventListener("touchcancel", onDocumentTouchEnd, true);
    };

    const clear = () => {
      if (timer) {
        clearTimeout(timer);
        timer = 0;
      }
      pressing = false;
      moved = false;
      removeDocumentTouchListeners();
    };

    const start = (event, x, y) => {
      clear();
      pressing = true;
      moved = false;
      startX = Number(x || 0);
      startY = Number(y || 0);
      timer = window.setTimeout(() => {
        timer = 0;
        if (!pressing || moved) return;
        try {
          onLongPress(event);
        } catch {}
      }, thresholdMs);
    };

    const move = (x, y) => {
      if (!pressing) return;
      const dx = Math.abs(Number(x || 0) - startX);
      const dy = Math.abs(Number(y || 0) - startY);
      if (dx > moveTolerance || dy > moveTolerance) {
        moved = true;
        clear();
      }
    };

    const onTouchStart = (event) => {
      const touch = event.touches?.[0];
      if (!touch) return;
      start(event, touch.clientX, touch.clientY);
      document.addEventListener("touchmove", onDocumentTouchMove, { capture: true, passive: true });
      document.addEventListener("touchend", onDocumentTouchEnd, true);
      document.addEventListener("touchcancel", onDocumentTouchEnd, true);
    };
    const onTouchMove = (event) => {
      const touch = event.touches?.[0];
      if (!touch) return;
      move(touch.clientX, touch.clientY);
    };
    const onTouchEnd = () => clear();
    const onDocumentTouchMove = (event) => {
      const touch = event.touches?.[0] || event.changedTouches?.[0];
      if (!touch) return;
      move(touch.clientX, touch.clientY);
    };
    const onDocumentTouchEnd = () => clear();
    const onMouseDown = (event) => {
      if (Number(event.button || 0) !== 0) return;
      start(event, event.clientX, event.clientY);
    };
    const onMouseMove = (event) => move(event.clientX, event.clientY);
    const onMouseUp = () => clear();
    const onMouseLeave = () => clear();
    const onContextMenu = () => clear();

    target.addEventListener("touchstart", onTouchStart, { passive: true });
    target.addEventListener("touchmove", onTouchMove, { passive: true });
    target.addEventListener("touchend", onTouchEnd, true);
    target.addEventListener("touchcancel", onTouchEnd, true);
    if (enableMouse) {
      target.addEventListener("mousedown", onMouseDown, true);
      target.addEventListener("mousemove", onMouseMove, true);
      target.addEventListener("mouseup", onMouseUp, true);
      target.addEventListener("mouseleave", onMouseLeave, true);
    }
    target.addEventListener("contextmenu", onContextMenu, true);

    return () => {
      clear();
      target.removeEventListener("touchstart", onTouchStart, { passive: true });
      target.removeEventListener("touchmove", onTouchMove, { passive: true });
      target.removeEventListener("touchend", onTouchEnd, true);
      target.removeEventListener("touchcancel", onTouchEnd, true);
      removeDocumentTouchListeners();
      if (enableMouse) {
        target.removeEventListener("mousedown", onMouseDown, true);
        target.removeEventListener("mousemove", onMouseMove, true);
        target.removeEventListener("mouseup", onMouseUp, true);
        target.removeEventListener("mouseleave", onMouseLeave, true);
      }
      target.removeEventListener("contextmenu", onContextMenu, true);
    };
  }

  function normalizeRepeatPass(value) {
    const numeric = Number(value);
    return Number.isFinite(numeric) && numeric >= 1
      ? Math.max(1, Math.floor(numeric))
      : null;
  }

  function resolveSeekRepeatPass(host, explicitOverride = null) {
    return normalizeRepeatPass(explicitOverride) ||
      normalizeRepeatPass(host?._twXmlCurrentRepeatPass) ||
      1;
  }

  function findTimelineEntryForMeasureAndPass(timeline = [], repeatPassByTimelinePos = [], measureIndex = 0, repeatPass = 1) {
    if (!Array.isArray(timeline) || !timeline.length) return null;
    const safeMeasureIndex = Math.max(0, Number(measureIndex || 0));
    const safeRepeatPass = Math.max(1, Number(repeatPass || 1));
    if (safeRepeatPass > 1) {
      const passEntry = timeline.find((entry, idx) =>
        Number(entry?.measureIndex || 0) === safeMeasureIndex &&
        Number(repeatPassByTimelinePos[idx] || 1) === safeRepeatPass
      );
      if (passEntry) return passEntry;
    }
    return timeline.find((entry) => Number(entry?.measureIndex || 0) === safeMeasureIndex) || null;
  }

  let xmlPlaybackState = {
    active: false,
    surrogate: "",
    timers: [],
    endTimer: 0,
    model: null,
    activeMeasureKey: "",
    currentMeasureIndex: null,
    currentSystemBlockKey: "",
    currentMeasureStartTick: 0,
    currentMeasureEndTick: 0,
    measureTimeline: [],
    playheadAdjustmentTicks: 0,
    playheadRaf: 0,
    playbackStartPerf: 0,
    startSourceIndex: null,
    player: null,
    pendingStartByKey: new Map(),
    activeNotesByKey: new Map(),
    activeNoteIds: new Set(),
    activeMidiCounts: new Map(),
    lastAttackTickByMidi: new Map(),
    lastHighlightTickByTrack: new Map()
  };

  async function setXmlPlaybackPositionByProgress(progress, opts = {}) {
    const viewer = document.getElementById("pdfTabXmlViewer");
    const host = viewer?._twXmlStateHost || viewer;
    const safeSurrogate = String(opts?.surrogate || xmlPlaybackState.surrogate || window.currentSurrogate || "").trim();
    if (!viewer || !host || !safeSurrogate) return false;
    const meta = host._twXmlPlayheadMeta || viewer._twXmlPlayheadMeta || null;
    const timeline = Array.isArray(meta?.measureTimeline) ? meta.measureTimeline : [];
    const displayTimeline = Array.isArray(meta?.displayMeasureTimeline) && meta.displayMeasureTimeline.length
      ? meta.displayMeasureTimeline
      : timeline;
    if (!displayTimeline.length || !timeline.length) return false;
    const displayTotalTicks = Math.max(1, Number(meta?.displayTotalTicks || meta?.totalTicks || 0));
    const safeProgress = Math.max(0, Math.min(1, Number(progress || 0)));
    const repeatPassOverride = normalizeRepeatPass(opts?.repeatPassOverride);
    if (repeatPassOverride !== null) {
      host._twXmlRepeatPassOverride = repeatPassOverride;
    } else if (safeProgress <= 0 || opts?.resetRepeatOverride) {
      host._twXmlRepeatPassOverride = null;
    }
    const targetDisplayTick = Math.max(0, Math.min(displayTotalTicks, safeProgress * displayTotalTicks));
    let currentDisplayMeasure = displayTimeline[0] || null;
    for (let i = 1; i < displayTimeline.length; i += 1) {
      if (Number(displayTimeline[i].startTick || 0) <= targetDisplayTick) {
        currentDisplayMeasure = displayTimeline[i];
      } else {
        break;
      }
    }
    const measureIndex = Math.max(0, Number(currentDisplayMeasure?.measureIndex || 0));
    const measureKey = String(measureIndex);
    const repeatPassByTimelinePos = Array.isArray(meta?.repeatPassByTimelinePos) ? meta.repeatPassByTimelinePos : [];
    const preferredRepeatPass = resolveSeekRepeatPass(host, repeatPassOverride);
    const selectedTimelineEntry = findTimelineEntryForMeasureAndPass(
      timeline,
      repeatPassByTimelinePos,
      measureIndex,
      preferredRepeatPass
    );
    const playbackMeasureStart = Math.max(
      0,
      Number(
        selectedTimelineEntry?.startTick ??
        meta?.measureStartTickByIndex?.[measureKey] ??
        0
      )
    );
    const displayMeasureStart = Math.max(0, Number(currentDisplayMeasure?.startTick || 0));
    const displayMeasureEnd = Math.max(displayMeasureStart + 1, Number(currentDisplayMeasure?.endTick || (displayMeasureStart + 1)));
    const displayLocalOffset = Math.max(0, Math.min(displayMeasureEnd - displayMeasureStart, targetDisplayTick - displayMeasureStart));
    const measureSpan = Math.max(
      1,
      Number(selectedTimelineEntry?.endTick || (playbackMeasureStart + 1)) - playbackMeasureStart
    );
    const seekTick = playbackMeasureStart + Math.max(0, Math.min(measureSpan - 1, displayLocalOffset));
    const sourceIndex = Math.max(
      0,
      Number(meta?.measureSourceIndexByIndex?.[measureKey] ?? host?._twXmlSelectedSourceIndex ?? 0)
    );
    const preferredSystemBlockKey =
      String(host._twXmlSelectedSystemBlockKey || "") ||
      String(xmlPlaybackState.currentSystemBlockKey || "") ||
      window.twMusicXmlView?.getXmlSystemBlockKeyForSourceIndex?.(host, sourceIndex) ||
      "0";
    const resolvedSystemBlockKey = window.twMusicXmlView?.resolveXmlSystemBlockKeyForMeasure?.(
      host,
      preferredSystemBlockKey,
      measureIndex,
      viewer
    ) || preferredSystemBlockKey || "0";
    host._twXmlSelectedSystemBlockKey = String(resolvedSystemBlockKey || "0");
    const noteEl = viewer._twXmlPlayableNoteElsBySourceIndex?.[String(sourceIndex)] || null;
    if (noteEl) {
      window.twMusicXmlView?.setSelectedXmlNote?.(viewer, noteEl, sourceIndex);
    } else {
      window.twMusicXmlView?.clearSelectedXmlNote?.(host);
      host._twXmlSelectedSourceIndex = sourceIndex;
      host._twXmlHasExplicitStart = true;
    }
    host._twXmlSelectedStartTick = seekTick;
    host._twXmlHasExplicitStart = true;
    window.twMusicXmlView?.updateXmlPlaybackMeasureIndicator?.(viewer, { measureIndex });
    const seekProgress = Math.max(0, Math.min(1, displayLocalOffset / Math.max(1, displayMeasureEnd - displayMeasureStart)));
    window.twMusicXmlView?.positionXmlPlayheadAtProgress?.(
      host,
      host._twXmlSelectedSystemBlockKey,
      measureIndex,
      seekProgress
    );
    window.twMusicXmlView?.updateXmlPlayheadIndicator?.(host, {
      active: false,
      showValue: true,
      absoluteTick: seekTick,
      overallProgress: Math.max(0, Math.min(1, targetDisplayTick / displayTotalTicks)),
      measureProgress: seekProgress,
      measureIndex,
      repeatPassOverride: host._twXmlRepeatPassOverride
    });
    if (opts?.commit && opts?.resumePlayback) {
      try {
        await window.twMusicXmlPlay?.playXmlSequence?.(safeSurrogate, window._pdfXmlViewState?.file || null);
      } catch (err) {
        console.warn("MusicXML seek restart failed:", err);
      }
    }
    return true;
  }

  function normalizeName(name) {
    return String(name || "").trim();
  }

  function isMusicXmlFileName(name) {
    return XML_EXT_RE.test(normalizeName(name));
  }

  function isMusicXmlMime(mime) {
    return XML_MIME_HINT_RE.test(String(mime || "").toLowerCase());
  }

  function loadScriptOnce(src, key) {
    if (key === "osmd" && osmdLoader) return osmdLoader;
    if (key === "jszip" && jsZipLoader) return jsZipLoader;

    const promise = new Promise((resolve) => {
      const existing = Array.from(document.scripts).find((script) => script.src === src);
      if (existing) {
        if (key === "osmd" && window.opensheetmusicdisplay?.OpenSheetMusicDisplay) return resolve(true);
        if (key === "jszip" && window.JSZip) return resolve(true);
      }

      const script = existing || document.createElement("script");
      script.src = src;
      script.async = true;
      script.onload = () => resolve(true);
      script.onerror = () => resolve(false);
      if (!existing) document.head.appendChild(script);
    });

    if (key === "osmd") osmdLoader = promise;
    if (key === "jszip") jsZipLoader = promise;
    return promise;
  }

  async function ensureMusicXmlViewerLibs() {
    const needsZip = !window.JSZip;
    if (needsZip) {
      const zipOk = await loadScriptOnce(
        "https://cdn.jsdelivr.net/npm/jszip@3.10.1/dist/jszip.min.js",
        "jszip"
      );
      if (!zipOk || !window.JSZip) return false;
    }

    if (window.opensheetmusicdisplay?.OpenSheetMusicDisplay) return true;
    const osmdOk = await loadScriptOnce(
      "https://cdn.jsdelivr.net/npm/opensheetmusicdisplay@1.9.7/build/opensheetmusicdisplay.min.js",
      "osmd"
    );
    return !!(osmdOk && window.opensheetmusicdisplay?.OpenSheetMusicDisplay);
  }

  async function fetchUploadedXmlFiles(surrogate) {
    const row = document.querySelector(`.list-sub-item[data-value="${surrogate}"]`);
    const owner = row?.dataset.owner;
    if (!owner || !surrogate) return [];

    const prefix = `${owner}/surrogate-${surrogate}/files/`;
    const res = await fetch(`${R2_LIST_ENDPOINT}?prefix=${encodeURIComponent(prefix)}`, {
      cache: "no-store"
    });
    if (!res.ok) return [];

    const data = await res.json();
    return (Array.isArray(data) ? data : [])
      .filter((obj) => isMusicXmlFileName(obj?.key || ""))
      .map((obj) => {
        const key = String(obj.key || "");
        const name = key.split("/").pop() || "";
        const encodedKey = key
          .split("/")
          .map((segment) => encodeURIComponent(segment))
          .join("/");
        const uploadedRaw = obj?.uploaded;
        const uploadedAt = typeof uploadedRaw === "string" ? Date.parse(uploadedRaw) : Number(uploadedRaw || 0);
        const versionRaw = obj?.version;
        const versionStamp = typeof versionRaw === "string"
          ? (Date.parse(versionRaw) || Number(versionRaw || 0))
          : Number(versionRaw || 0);
        return {
          key,
          name,
          url: `https://audio.textwhisper.com/${encodedKey}?v=${obj.version || obj.uploaded || obj.etag || Date.now()}`,
          size: Number(obj.size || 0),
          uploaded: uploadedRaw || "",
          uploadedAt: Number.isFinite(uploadedAt) ? uploadedAt : 0,
          version: versionRaw || "",
          versionStamp: Number.isFinite(versionStamp) ? versionStamp : 0
        };
      })
      .sort((a, b) =>
        Number(b.uploadedAt || 0) - Number(a.uploadedAt || 0) ||
        Number(b.versionStamp || 0) - Number(a.versionStamp || 0) ||
        String(b.name || "").localeCompare(String(a.name || ""))
      );
  }

  async function getPrimaryMusicXmlFile(surrogate) {
    const files = await fetchUploadedXmlFiles(surrogate);
    return files[0] || null;
  }

  async function syncXmlEditToolbar(surrogate) {
    const toolbar = document.getElementById("xmlToolbar");
    const picker = document.getElementById("xmlFilePicker");
    const deleteBtn = document.getElementById("xmlDeleteCurrentBtn");
    const editMetaBtn = document.getElementById("xmlEditMetaBtn");
    const voiceSplitBtn = document.getElementById("xmlVoiceSplitBtn");
    const safeSurrogate = String(surrogate || window.currentSurrogate || "").trim();
    const xmlState = window._pdfXmlViewState || {};
    const xmlActiveForCurrent =
      !!xmlState.active &&
      String(xmlState.surrogate || "") === safeSurrogate &&
      String(window.currentActiveTab || "") === "pdfTab";
    const shouldShow = !!(
      toolbar &&
      picker &&
      deleteBtn &&
      editMetaBtn &&
      voiceSplitBtn &&
      safeSurrogate &&
      document.body.classList.contains("edit-mode") &&
      xmlActiveForCurrent
    );

    if (!toolbar || !picker || !deleteBtn || !editMetaBtn || !voiceSplitBtn) return;
    if (!shouldShow) {
      toolbar.style.display = "none";
      picker.disabled = true;
      deleteBtn.disabled = true;
      editMetaBtn.disabled = true;
      voiceSplitBtn.disabled = true;
      return;
    }

    toolbar.style.display = "flex";
    picker.disabled = true;
    deleteBtn.disabled = true;
    editMetaBtn.disabled = true;
    voiceSplitBtn.disabled = true;

    let files = [];
    try {
      files = await fetchUploadedXmlFiles(safeSurrogate);
    } catch (err) {
      console.warn("MusicXML toolbar sync failed:", err);
      files = [];
    }

    const xmlStateAfterFetch = window._pdfXmlViewState || {};
    const stillVisible = !!(
      document.body.classList.contains("edit-mode") &&
      String(window.currentActiveTab || "") === "pdfTab" &&
      !!xmlStateAfterFetch.active &&
      String(xmlStateAfterFetch.surrogate || "") === safeSurrogate &&
      document.getElementById("xmlToolbar") === toolbar
    );
    if (!stillVisible) {
      toolbar.style.display = "none";
      picker.disabled = true;
      deleteBtn.disabled = true;
      editMetaBtn.disabled = true;
      voiceSplitBtn.disabled = true;
      return;
    }

    const activeKey = String(xmlStateAfterFetch.file?.key || "").trim();
    const currentSelection = String(picker.value || "").trim();
    const nextSelectedKey =
      (activeKey && files.some((file) => String(file?.key || "") === activeKey) && activeKey) ||
      (currentSelection && files.some((file) => String(file?.key || "") === currentSelection) && currentSelection) ||
      String(files[0]?.key || "");

    picker.innerHTML = "";
    if (!files.length) {
      const emptyOpt = document.createElement("option");
      emptyOpt.value = "";
      emptyOpt.textContent = "No MusicXML";
      picker.appendChild(emptyOpt);
      picker.disabled = true;
      deleteBtn.disabled = true;
      editMetaBtn.disabled = true;
      voiceSplitBtn.disabled = true;
      return;
    }

    files.forEach((file) => {
      const opt = document.createElement("option");
      opt.value = String(file?.key || "");
      opt.textContent = String(file?.name || file?.key || "MusicXML");
      picker.appendChild(opt);
    });
    picker.value = nextSelectedKey;
    picker.disabled = false;

    if (picker.dataset.twBound !== "1") {
      picker.addEventListener("change", async () => {
        const selectedKey = String(picker.value || "").trim();
        const selectedFile =
          window._twXmlToolbarFiles?.find((file) => String(file?.key || "") === selectedKey) || null;
        const currentSafeSurrogate = String(picker.dataset.twSurrogate || window.currentSurrogate || "").trim();
        deleteBtn.disabled = !canDeleteMusicXmlFile(currentSafeSurrogate, selectedFile);
        editMetaBtn.disabled = !canEditMusicXmlFile(currentSafeSurrogate, selectedFile);
        voiceSplitBtn.disabled = !canVoiceSplitMusicXmlFile(currentSafeSurrogate, selectedFile);
        if (!currentSafeSurrogate || !selectedFile?.url) return;
        try {
          await openMusicXmlInPdfTab(currentSafeSurrogate, selectedFile, { setSticky: true });
        } catch (err) {
          console.warn("MusicXML toolbar switch failed:", err);
        }
      });
      picker.dataset.twBound = "1";
    }

    if (deleteBtn.dataset.twBound !== "1") {
      deleteBtn.addEventListener("click", async () => {
        if (deleteBtn.dataset.twBusy === "1") return;
        const selectedKey = String(picker.value || "").trim();
        const selectedFile =
          window._twXmlToolbarFiles?.find((file) => String(file?.key || "") === selectedKey) || null;
        const currentSafeSurrogate = String(picker.dataset.twSurrogate || window.currentSurrogate || "").trim();
        if (!currentSafeSurrogate || !selectedFile?.key) return;
        const fileName = String(selectedFile?.name || "this MusicXML file").trim();
        const confirmed = window.confirm(`Delete ${fileName}?\nThis cannot be undone.`);
        if (!confirmed) return;
        deleteBtn.dataset.twBusy = "1";
        deleteBtn.disabled = true;
        try {
          const ok = await deleteMusicXmlFile(currentSafeSurrogate, selectedFile);
          if (!ok) {
            window.alert("Unable to delete the selected MusicXML file.");
          }
        } finally {
          delete deleteBtn.dataset.twBusy;
        }
      });
      deleteBtn.dataset.twBound = "1";
    }
    editMetaBtn.onclick = async () => {
      if (editMetaBtn.dataset.twBusy === "1") return;
      const selectedKey = String(picker.value || "").trim();
      const selectedFile =
        window._twXmlToolbarFiles?.find((file) => String(file?.key || "") === selectedKey) || null;
      const currentSafeSurrogate = String(picker.dataset.twSurrogate || window.currentSurrogate || "").trim();
      if (!currentSafeSurrogate || !selectedFile?.key || !selectedFile?.url) return;
      if (!canEditMusicXmlFile(currentSafeSurrogate, selectedFile)) return;
      editMetaBtn.dataset.twBusy = "1";
      editMetaBtn.disabled = true;
      try {
        const ok = await editMusicXmlMetadata(currentSafeSurrogate, selectedFile);
        if (!ok) return;
        await refreshPdfTabXmlState(currentSafeSurrogate);
        const next =
          (window._twXmlToolbarFiles || []).find((file) => String(file?.key || "") === String(selectedFile.key || "")) ||
          selectedFile;
        if (next?.url) await openMusicXmlInPdfTab(currentSafeSurrogate, next, { setSticky: true });
      } catch (err) {
        console.warn("MusicXML metadata edit failed:", err);
        window.alert("Unable to save MusicXML metadata.");
      } finally {
        delete editMetaBtn.dataset.twBusy;
        editMetaBtn.disabled = !canEditMusicXmlFile(
          String(picker.dataset.twSurrogate || window.currentSurrogate || "").trim(),
          (window._twXmlToolbarFiles || []).find((file) => String(file?.key || "") === String(picker.value || "")) || null
        );
      }
    };
    if (voiceSplitBtn.dataset.twBound !== "1") {
      voiceSplitBtn.addEventListener("click", async () => {
        if (voiceSplitBtn.dataset.twBusy === "1") return;
        const selectedKey = String(picker.value || "").trim();
        const selectedFile =
          window._twXmlToolbarFiles?.find((file) => String(file?.key || "") === selectedKey) || null;
        const currentSafeSurrogate = String(picker.dataset.twSurrogate || window.currentSurrogate || "").trim();
        if (!currentSafeSurrogate || !selectedFile?.key || !selectedFile?.url) return;
        if (!canVoiceSplitMusicXmlFile(currentSafeSurrogate, selectedFile)) return;

        const targetKey = buildVoiceSplitMusicXmlKey(selectedFile.key);
        if (!targetKey) {
          window.alert("Voice split supports .xml/.musicxml files only.");
          return;
        }
        voiceSplitBtn.dataset.twBusy = "1";
        voiceSplitBtn.disabled = true;
        try {
          const sourceXml = await fetchMusicXmlTextFromUrl(selectedFile.url);
          const analysis = analyzeVoiceSplitNeed(sourceXml, selectedFile.name || selectedFile.key);
          const confirmResult = await openVoiceSplitConfirmPanel({
            fileName: selectedFile.name || selectedFile.key,
            analysis,
            canConvert: true
          });
          if (!confirmResult?.confirmed) return;
          const selectedScope = String(confirmResult.scope || "all").trim() || "all";
          const splitXml = splitMusicXmlChordVoices(sourceXml, { staffScope: selectedScope });
          if (!String(splitXml || "").trim()) {
            window.alert("Could not create voice-split XML.");
            return;
          }
          const changed = String(splitXml) !== String(sourceXml);
          if (!changed) {
            window.alert("No chord voice split candidates found in this file.");
            return;
          }
          const ok = await uploadMusicXmlTextByKey(targetKey, splitXml);
          if (!ok) {
            window.alert("Could not upload voice-split XML.");
            return;
          }
          await refreshPdfTabXmlState(currentSafeSurrogate);
          const latestFiles = await fetchUploadedXmlFiles(currentSafeSurrogate);
          window._twXmlToolbarFiles = latestFiles;
          const createdFile = latestFiles.find((file) => String(file?.key || "") === String(targetKey)) || null;
          if (createdFile?.url) {
            await openMusicXmlInPdfTab(currentSafeSurrogate, createdFile, { setSticky: true });
          }
        } catch (err) {
          console.warn("MusicXML voice split failed:", err);
          window.alert("Voice split failed.");
        } finally {
          delete voiceSplitBtn.dataset.twBusy;
          const selectedNow =
            (window._twXmlToolbarFiles || []).find((file) => String(file?.key || "") === String(picker.value || "")) || null;
          voiceSplitBtn.disabled = !canVoiceSplitMusicXmlFile(
            String(picker.dataset.twSurrogate || window.currentSurrogate || "").trim(),
            selectedNow
          );
          await syncXmlEditToolbar(String(picker.dataset.twSurrogate || window.currentSurrogate || "").trim());
        }
      });
      voiceSplitBtn.dataset.twBound = "1";
    }

    picker.dataset.twSurrogate = safeSurrogate;
    deleteBtn.dataset.twSurrogate = safeSurrogate;
    editMetaBtn.dataset.twSurrogate = safeSurrogate;
    voiceSplitBtn.dataset.twSurrogate = safeSurrogate;
    window._twXmlToolbarFiles = files;
    deleteBtn.disabled = !canDeleteMusicXmlFile(
      safeSurrogate,
      files.find((file) => String(file?.key || "") === nextSelectedKey) || files[0] || null
    );
    editMetaBtn.disabled = !canEditMusicXmlFile(
      safeSurrogate,
      files.find((file) => String(file?.key || "") === nextSelectedKey) || files[0] || null
    );
    voiceSplitBtn.disabled = !canVoiceSplitMusicXmlFile(
      safeSurrogate,
      files.find((file) => String(file?.key || "") === nextSelectedKey) || files[0] || null
    );
    window.lucide?.createIcons?.();
  }

  function canDeleteMusicXmlFile(surrogate, file = null) {
    const safeSurrogate = String(surrogate || window.currentSurrogate || "").trim();
    const roleRank = Number(window.currentUserItemRoleRank || 0);
    if (roleRank >= 80) return !!String(file?.key || "").trim() || !!safeSurrogate;
    const itemEl = document.querySelector(`.list-sub-item[data-value="${safeSurrogate}"]`);
    const owner = String(itemEl?.dataset?.owner || window.currentItemOwner || window.pdfState?.owner || "").trim();
    const currentUsername = String(window.currentUsername || "").trim();
    return !!(safeSurrogate && owner && currentUsername && owner === currentUsername);
  }

  function canEditMusicXmlFile(surrogate, file = null) {
    const key = String(file?.key || "").trim();
    if (!key) return false;
    const name = String(file?.name || key.split("/").pop() || "").toLowerCase();
    if (name.endsWith(".mxl")) return false;
    return canDeleteMusicXmlFile(surrogate, file);
  }

  function canVoiceSplitMusicXmlFile(surrogate, file = null) {
    const key = String(file?.key || "").trim();
    if (!key) return false;
    const name = String(file?.name || key.split("/").pop() || "").toLowerCase();
    if (!(name.endsWith(".xml") || name.endsWith(".musicxml"))) return false;
    return canEditMusicXmlFile(surrogate, file);
  }

  function getMusicXmlNoteStaffId(noteEl) {
    return String(noteEl?.getElementsByTagNameNS("*", "staff")[0]?.textContent || "1").trim() || "1";
  }

  function analyzeVoiceSplitNeed(xmlText, fileName = "") {
    const text = String(xmlText || "").trim();
    if (!text) {
      return {
        ok: false,
        parseError: true,
        needsConversion: true,
        likelyConverted: false,
        multiChordClusters: 0,
        unsplitMultiChordClusters: 0,
        singlePitchedNotes: 0,
        voice1Notes: 0,
        voice2Notes: 0,
        estimatedLowerVoiceAdds: 0
      };
    }

    const doc = new DOMParser().parseFromString(text, "application/xml");
    if (doc.getElementsByTagName("parsererror").length) {
      return {
        ok: false,
        parseError: true,
        needsConversion: true,
        likelyConverted: false,
        multiChordClusters: 0,
        unsplitMultiChordClusters: 0,
        singlePitchedNotes: 0,
        voice1Notes: 0,
        voice2Notes: 0,
        estimatedLowerVoiceAdds: 0
      };
    }

    let multiChordClusters = 0;
    let unsplitMultiChordClusters = 0;
    let singlePitchedNotes = 0;
    let voice1Notes = 0;
    let voice2Notes = 0;
    let oneStaffPerVoiceLayout = false;
    let candidateOneStaffPerVoiceLayout = false;
    const staffStatsMap = {};
    const getStaffStats = (staffIdRaw) => {
      const staffId = String(staffIdRaw || "1").trim() || "1";
      if (!staffStatsMap[staffId]) {
        staffStatsMap[staffId] = {
          staffId,
          multiChordClusters: 0,
          unsplitMultiChordClusters: 0,
          singlePitchedNotes: 0,
          voice1Notes: 0,
          voice2Notes: 0
        };
      }
      return staffStatsMap[staffId];
    };

    const parts = Array.from(doc.getElementsByTagNameNS("*", "part"));
    if (parts.length > 1) {
      let allSingleStaffPerPart = true;
      for (let p = 0; p < parts.length; p += 1) {
        const partEl = parts[p];
        const partNotes = Array.from(partEl.getElementsByTagNameNS("*", "note"));
        const staffIds = new Set();
        partNotes.forEach((noteEl) => {
          const staff = String(noteEl.getElementsByTagNameNS("*", "staff")[0]?.textContent || "1").trim() || "1";
          staffIds.add(staff);
        });
        if (staffIds.size > 1) {
          allSingleStaffPerPart = false;
          break;
        }
      }
      candidateOneStaffPerVoiceLayout = allSingleStaffPerPart;
    }

    parts.forEach((partEl) => {
      const measures = Array.from(partEl.getElementsByTagNameNS("*", "measure"));
      measures.forEach((measureEl) => {
        const noteEls = Array.from(measureEl.childNodes || [])
          .filter((node) => node && node.nodeType === 1 && localNameOf(node) === "note");
        for (let i = 0; i < noteEls.length; i += 1) {
          const start = noteEls[i];
          if (!start) continue;
          const cluster = [start];
          let j = i + 1;
          while (j < noteEls.length) {
            const note = noteEls[j];
            if (!note) break;
            const isChord = note.getElementsByTagNameNS("*", "chord").length > 0;
            if (!isChord) break;
            cluster.push(note);
            j += 1;
          }
          i = j - 1;

          const pitched = cluster.filter((note) => note.getElementsByTagNameNS("*", "rest").length === 0);
          if (!pitched.length) continue;
          const staffId = getMusicXmlNoteStaffId(pitched[0] || cluster[0] || null);
          const staffStats = getStaffStats(staffId);

          pitched.forEach((note) => {
            const voice = String(note.getElementsByTagNameNS("*", "voice")[0]?.textContent || "").trim() || "1";
            if (voice === "2") {
              voice2Notes += 1;
              staffStats.voice2Notes += 1;
            } else {
              voice1Notes += 1;
              staffStats.voice1Notes += 1;
            }
          });

          if (pitched.length === 1) {
            singlePitchedNotes += 1;
            staffStats.singlePitchedNotes += 1;
            continue;
          }

          multiChordClusters += 1;
          staffStats.multiChordClusters += 1;
          const voices = new Set(
            pitched.map((note) => String(note.getElementsByTagNameNS("*", "voice")[0]?.textContent || "").trim() || "1")
          );
          if (!voices.has("1") || !voices.has("2")) {
            unsplitMultiChordClusters += 1;
            staffStats.unsplitMultiChordClusters += 1;
          }
        }
      });
    });

    oneStaffPerVoiceLayout =
      candidateOneStaffPerVoiceLayout &&
      unsplitMultiChordClusters === 0 &&
      multiChordClusters === 0;
    const lowerName = String(fileName || "").toLowerCase();
    const likelyConvertedByName = /\.voice-split\.(xml|musicxml)$/i.test(lowerName);
    const likelySplitVoices = voice2Notes > 0 && unsplitMultiChordClusters === 0;
    const likelyConverted =
      oneStaffPerVoiceLayout || likelyConvertedByName || likelySplitVoices;
    const needsConversion = unsplitMultiChordClusters > 0;
    const totalPitchedGroups = Math.max(1, multiChordClusters + singlePitchedNotes);
    const splitPressure = unsplitMultiChordClusters / totalPitchedGroups;
    const lowGainConversion = needsConversion && unsplitMultiChordClusters > 0 && unsplitMultiChordClusters <= 20 && splitPressure < 0.2;
    const staffStats = {};
    Object.keys(staffStatsMap).forEach((staffId) => {
      const s = staffStatsMap[staffId] || {};
      const sMulti = Number(s.multiChordClusters || 0);
      const sUnsplit = Number(s.unsplitMultiChordClusters || 0);
      const sSingle = Number(s.singlePitchedNotes || 0);
      const sV1 = Number(s.voice1Notes || 0);
      const sV2 = Number(s.voice2Notes || 0);
      const sLikelySplitVoices = sV2 > 0 && sUnsplit === 0;
      const sLikelyConverted = sLikelySplitVoices || (sUnsplit === 0 && sMulti === 0);
      const sNeeds = sUnsplit > 0;
      const sTotalGroups = Math.max(1, sMulti + sSingle);
      const sPressure = sUnsplit / sTotalGroups;
      staffStats[staffId] = {
        staffId,
        parseError: false,
        oneStaffPerVoiceLayout: false,
        likelySplitVoices: sLikelySplitVoices,
        likelyConverted: sLikelyConverted,
        needsConversion: sNeeds,
        lowGainConversion: sNeeds && sUnsplit > 0 && sUnsplit <= 20 && sPressure < 0.2,
        multiChordClusters: sMulti,
        unsplitMultiChordClusters: sUnsplit,
        singlePitchedNotes: sSingle,
        voice1Notes: sV1,
        voice2Notes: sV2,
        estimatedLowerVoiceAdds: sUnsplit
      };
    });

    return {
      ok: true,
      parseError: false,
      needsConversion,
      likelyConverted,
      likelySplitVoices,
      oneStaffPerVoiceLayout,
      lowGainConversion,
      multiChordClusters,
      unsplitMultiChordClusters,
      singlePitchedNotes,
      voice1Notes,
      voice2Notes,
      estimatedLowerVoiceAdds: unsplitMultiChordClusters,
      staffStats
    };
  }

  function openVoiceSplitConfirmPanel(meta = {}) {
    return new Promise((resolve) => {
      const oldOverlay = document.getElementById("twVoiceSplitConfirmOverlay");
      if (oldOverlay) oldOverlay.remove();

      const fileName = String(meta?.fileName || "MusicXML").trim() || "MusicXML";
      const info = meta?.analysis || {};
      const parseError = !!info?.parseError;
      const needsConversion = !!info?.needsConversion;
      const canConvert = meta?.canConvert !== false;
      const allStaffStats = (info && typeof info.staffStats === "object" && info.staffStats) ? info.staffStats : {};
      const staffIds = Object.keys(allStaffStats).sort((a, b) => Number(a) - Number(b));
      let selectedScope = "all";
      const getScopeInfo = (scope) => {
        if (scope === "all") return info || {};
        return allStaffStats[String(scope)] || {
          parseError: false,
          oneStaffPerVoiceLayout: false,
          likelySplitVoices: false,
          needsConversion: false,
          lowGainConversion: false,
          multiChordClusters: 0,
          unsplitMultiChordClusters: 0,
          singlePitchedNotes: 0,
          voice1Notes: 0,
          voice2Notes: 0,
          estimatedLowerVoiceAdds: 0
        };
      };

      const overlay = document.createElement("div");
      overlay.id = "twVoiceSplitConfirmOverlay";
      overlay.style.position = "fixed";
      overlay.style.inset = "0";
      overlay.style.background = "rgba(15,23,42,0.45)";
      overlay.style.zIndex = "11000";
      overlay.style.display = "flex";
      overlay.style.alignItems = "center";
      overlay.style.justifyContent = "center";
      overlay.style.padding = "16px";

      const panel = document.createElement("div");
      panel.style.width = "min(560px, 96vw)";
      panel.style.maxHeight = "82vh";
      panel.style.overflow = "auto";
      panel.style.background = "#fff";
      panel.style.border = "1px solid rgba(15,23,42,0.16)";
      panel.style.borderRadius = "12px";
      panel.style.boxShadow = "0 18px 38px rgba(2,6,23,0.28)";
      panel.style.padding = "14px";

      const title = document.createElement("div");
      title.textContent = "Convert to split voices";
      title.style.fontSize = "16px";
      title.style.fontWeight = "700";
      title.style.marginBottom = "8px";
      panel.appendChild(title);

      const fileLine = document.createElement("div");
      fileLine.textContent = `File: ${fileName}`;
      fileLine.style.fontSize = "13px";
      fileLine.style.color = "#0f172a";
      fileLine.style.marginBottom = "8px";
      panel.appendChild(fileLine);

      const status = document.createElement("div");
      status.style.fontSize = "13px";
      status.style.marginBottom = "10px";
      status.style.padding = "8px 10px";
      status.style.borderRadius = "8px";
      status.style.border = "1px solid rgba(15,23,42,0.12)";
      panel.appendChild(status);

      const shouldShowScopeSelect =
        staffIds.length > 1 &&
        !info?.oneStaffPerVoiceLayout;
      const scopeWrap = document.createElement("div");
      scopeWrap.style.display = shouldShowScopeSelect ? "flex" : "none";
      scopeWrap.style.alignItems = "center";
      scopeWrap.style.gap = "8px";
      scopeWrap.style.marginBottom = "10px";
      const scopeLabel = document.createElement("label");
      scopeLabel.textContent = "Convert scope:";
      scopeLabel.style.fontSize = "12px";
      scopeLabel.style.color = "#475569";
      scopeLabel.style.minWidth = "90px";
      const scopeSelect = document.createElement("select");
      scopeSelect.className = "form-select form-select-sm";
      scopeSelect.style.maxWidth = "220px";
      const allOpt = document.createElement("option");
      allOpt.value = "all";
      allOpt.textContent = "All staffs";
      scopeSelect.appendChild(allOpt);
      staffIds.forEach((sid) => {
        const s = allStaffStats[sid] || {};
        const opt = document.createElement("option");
        opt.value = sid;
        opt.textContent = `Staff ${sid} (unsplit ${Number(s.unsplitMultiChordClusters || 0)})`;
        scopeSelect.appendChild(opt);
      });
      scopeWrap.appendChild(scopeLabel);
      scopeWrap.appendChild(scopeSelect);
      panel.appendChild(scopeWrap);

      const stats = document.createElement("div");
      stats.style.fontSize = "12px";
      stats.style.color = "#334155";
      stats.style.lineHeight = "1.45";
      panel.appendChild(stats);

      const note = document.createElement("div");
      note.textContent = "This creates a .voice-split copy. The original file remains unchanged.";
      note.style.fontSize = "12px";
      note.style.color = "#475569";
      note.style.marginTop = "10px";
      panel.appendChild(note);

      const actions = document.createElement("div");
      actions.style.display = "flex";
      actions.style.justifyContent = "flex-end";
      actions.style.gap = "8px";
      actions.style.marginTop = "14px";

      const cancelBtn = document.createElement("button");
      cancelBtn.type = "button";
      cancelBtn.className = "edit-btn";
      cancelBtn.textContent = "Cancel";

      const convertBtn = document.createElement("button");
      convertBtn.type = "button";
      convertBtn.className = "edit-btn";
      convertBtn.textContent = "Convert";
      convertBtn.style.background = "#1d4ed8";
      convertBtn.style.color = "#fff";
      convertBtn.style.borderColor = "#1e40af";
      const refreshScopeView = () => {
        selectedScope = String(scopeSelect.value || "all");
        const scopeInfo = getScopeInfo(selectedScope);
        const scopeParseError = !!scopeInfo?.parseError || parseError;
        const scopeNeedsConversion = !!scopeInfo?.needsConversion;
        const scopeNoSplitNeeded = !scopeParseError && !scopeNeedsConversion;

        status.style.background = "rgba(191,219,254,0.35)";
        if (scopeParseError) {
          status.textContent = "Could not analyze this XML. You can still try converting.";
          status.style.background = "rgba(252,211,77,0.2)";
        } else if (scopeNeedsConversion && scopeInfo?.lowGainConversion) {
          status.textContent = "Estimated: conversion possible, but expected gain is low.";
          status.style.background = "rgba(252,211,77,0.2)";
        } else if (scopeNeedsConversion) {
          status.textContent = "Estimated: conversion is needed.";
          status.style.background = "rgba(187,247,208,0.35)";
        } else if (scopeInfo?.likelySplitVoices || (selectedScope === "all" && /\.voice-split\.(xml|musicxml)$/i.test(fileName))) {
          status.textContent = "Estimated: already split into two voices.";
        } else if (scopeInfo?.oneStaffPerVoiceLayout || Number(scopeInfo?.multiChordClusters || 0) === 0) {
          status.textContent = "Estimated: no conversion needed (one staff line per voice).";
        } else {
          status.textContent = "Estimated: no conversion needed.";
        }

        stats.innerHTML = [
          `Chord clusters (multi-note): <b>${Number(scopeInfo?.multiChordClusters || 0)}</b>`,
          `Unsplit chord clusters: <b>${Number(scopeInfo?.unsplitMultiChordClusters || 0)}</b>`,
          `Single pitched notes: <b>${Number(scopeInfo?.singlePitchedNotes || 0)}</b>`,
          `Voice 1 notes: <b>${Number(scopeInfo?.voice1Notes || 0)}</b>`,
          `Voice 2 notes: <b>${Number(scopeInfo?.voice2Notes || 0)}</b>`,
          `Estimated lower-voice additions: <b>${Number(scopeInfo?.estimatedLowerVoiceAdds || 0)}</b>`
        ].join("<br>");

        const scopeCanConvert = canConvert && scopeNeedsConversion;
        convertBtn.textContent = "Convert";
        convertBtn.disabled = !scopeCanConvert;
        convertBtn.style.opacity = scopeCanConvert ? "1" : "0.45";
        convertBtn.style.cursor = scopeCanConvert ? "pointer" : "not-allowed";
      };
      scopeSelect.addEventListener("change", refreshScopeView);
      refreshScopeView();

      const close = (ok) => {
        overlay.remove();
        resolve({ confirmed: !!ok, scope: selectedScope });
      };
      cancelBtn.addEventListener("click", () => close(false));
      convertBtn.addEventListener("click", () => {
        if (!canConvert) return;
        close(true);
      });
      overlay.addEventListener("click", (event) => {
        if (event.target === overlay) close(false);
      });

      actions.appendChild(cancelBtn);
      actions.appendChild(convertBtn);
      panel.appendChild(actions);
      overlay.appendChild(panel);
      document.body.appendChild(overlay);
    });
  }

  function buildVoiceSplitMusicXmlKey(key = "") {
    const safeKey = String(key || "").trim();
    if (!safeKey) return "";
    const lastSlash = safeKey.lastIndexOf("/");
    const dir = lastSlash >= 0 ? safeKey.slice(0, lastSlash + 1) : "";
    const fileName = lastSlash >= 0 ? safeKey.slice(lastSlash + 1) : safeKey;
    const match = fileName.match(/^(.*?)(\.(?:musicxml|xml))$/i);
    if (!match) return "";
    const base = String(match[1] || "");
    const ext = String(match[2] || ".xml");
    const normalizedBase = /\.voice-split$/i.test(base) ? base.replace(/\.voice-split$/i, "") : base;
    return `${dir}${normalizedBase}.voice-split${ext}`;
  }

  function setMusicXmlNoteVoice(noteEl, voiceValue) {
    if (!noteEl) return;
    const safeVoice = String(voiceValue || "").trim();
    if (!safeVoice) return;
    const existing = noteEl.getElementsByTagNameNS("*", "voice")[0] || null;
    if (existing) {
      existing.textContent = safeVoice;
      return;
    }
    const doc = noteEl.ownerDocument;
    if (!doc) return;
    const voiceEl = doc.createElement("voice");
    voiceEl.textContent = safeVoice;
    const children = Array.from(noteEl.childNodes || []);
    let anchor = null;
    for (let i = 0; i < children.length; i += 1) {
      const child = children[i];
      if (!child || child.nodeType !== 1) continue;
      const name = localNameOf(child);
      if (name === "duration") anchor = child;
    }
    if (anchor?.nextSibling) {
      noteEl.insertBefore(voiceEl, anchor.nextSibling);
    } else if (anchor) {
      noteEl.appendChild(voiceEl);
    } else {
      const firstType = children.find((child) => child && child.nodeType === 1 && localNameOf(child) === "type") || null;
      if (firstType) noteEl.insertBefore(voiceEl, firstType);
      else noteEl.appendChild(voiceEl);
    }
  }

  function getMusicXmlNoteDurationRaw(noteEl) {
    if (!noteEl) return 0;
    const durationText = String(noteEl.getElementsByTagNameNS("*", "duration")[0]?.textContent || "").trim();
    const duration = Number(durationText);
    if (!Number.isFinite(duration) || duration <= 0) return 0;
    return Math.max(1, Math.round(duration));
  }

  function setMusicXmlChordFlag(noteEl, shouldBeChord) {
    if (!noteEl) return false;
    const existing = noteEl.getElementsByTagNameNS("*", "chord")[0] || null;
    if (shouldBeChord) {
      if (existing) return false;
      const doc = noteEl.ownerDocument;
      if (!doc) return false;
      const chordEl = doc.createElement("chord");
      const firstChild = Array.from(noteEl.childNodes || []).find((child) => child && child.nodeType === 1) || null;
      if (firstChild) noteEl.insertBefore(chordEl, firstChild);
      else noteEl.appendChild(chordEl);
      return true;
    }
    if (!existing) return false;
    existing.parentNode?.removeChild?.(existing);
    return true;
  }

  function hasImmediateBackupDuration(beforeNode, durationRaw) {
    const prev = beforeNode?.previousSibling || null;
    if (!prev || prev.nodeType !== 1 || localNameOf(prev) !== "backup") return false;
    const prevDuration = Number(String(prev.getElementsByTagNameNS("*", "duration")[0]?.textContent || "").trim());
    return Number.isFinite(prevDuration) && Math.max(1, Math.round(prevDuration)) === Math.max(1, Math.round(Number(durationRaw || 0)));
  }

  function insertBackupBeforeNode(measureEl, beforeNode, durationRaw) {
    if (!measureEl || !beforeNode) return false;
    const safeDuration = Math.max(1, Math.round(Number(durationRaw || 0)));
    if (!(safeDuration > 0)) return false;
    if (hasImmediateBackupDuration(beforeNode, safeDuration)) return false;
    const doc = measureEl.ownerDocument;
    if (!doc) return false;
    const backupEl = doc.createElement("backup");
    const durationEl = doc.createElement("duration");
    durationEl.textContent = String(safeDuration);
    backupEl.appendChild(durationEl);
    measureEl.insertBefore(backupEl, beforeNode);
    return true;
  }

  function splitMusicXmlChordVoices(xmlText, options = {}) {
    const requestedScope = String(options?.staffScope || "all").trim().toLowerCase();
    const targetStaffScope = requestedScope && requestedScope !== "all" ? requestedScope : "all";
    const text = String(xmlText || "").trim();
    if (!text) return "";
    const doc = new DOMParser().parseFromString(text, "application/xml");
    if (doc.getElementsByTagName("parsererror").length) return text;
    let hasAnyMultiPitchedCluster = false;
    {
      const partsScan = Array.from(doc.getElementsByTagNameNS("*", "part"));
      for (let p = 0; p < partsScan.length && !hasAnyMultiPitchedCluster; p += 1) {
        const measuresScan = Array.from(partsScan[p].getElementsByTagNameNS("*", "measure"));
        for (let m = 0; m < measuresScan.length && !hasAnyMultiPitchedCluster; m += 1) {
          const measureEl = measuresScan[m];
          const notes = Array.from(measureEl.childNodes || [])
            .filter((node) => node && node.nodeType === 1 && localNameOf(node) === "note");
          for (let i = 0; i < notes.length; i += 1) {
            const start = notes[i];
            if (!start) continue;
            const cluster = [start];
            let j = i + 1;
            while (j < notes.length) {
              const note = notes[j];
              if (!note) break;
              const isChord = note.getElementsByTagNameNS("*", "chord").length > 0;
              if (!isChord) break;
              cluster.push(note);
              j += 1;
            }
            i = j - 1;
            if (cluster.length < 2) continue;
            const clusterStaff = getMusicXmlNoteStaffId(start || null).toLowerCase();
            if (targetStaffScope !== "all" && clusterStaff !== targetStaffScope) continue;
            const pitchedCount = cluster.filter((note) => note.getElementsByTagNameNS("*", "rest").length === 0).length;
            if (pitchedCount > 1) {
              hasAnyMultiPitchedCluster = true;
              break;
            }
          }
        }
      }
    }
    if (!hasAnyMultiPitchedCluster) return text;
    let changed = false;
    const parts = Array.from(doc.getElementsByTagNameNS("*", "part"));
    parts.forEach((partEl) => {
      const measures = Array.from(partEl.getElementsByTagNameNS("*", "measure"));
      measures.forEach((measureEl) => {
        const measureChildren = Array.from(measureEl.childNodes || []).filter((node) => node && node.nodeType === 1);
        const notes = measureChildren.filter((node) => localNameOf(node) === "note");
        for (let i = 0; i < notes.length; i += 1) {
          const startNote = notes[i];
          if (!startNote) continue;
          const cluster = [startNote];
          let j = i + 1;
          while (j < notes.length) {
            const note = notes[j];
            if (!note) break;
            const isChord = note.getElementsByTagNameNS("*", "chord").length > 0;
            if (!isChord) break;
            cluster.push(note);
            j += 1;
          }
          i = j - 1;
          const clusterStaff = getMusicXmlNoteStaffId(startNote || null).toLowerCase();
          if (targetStaffScope !== "all" && clusterStaff !== targetStaffScope) continue;
          if (cluster.length < 2) {
            const single = cluster[0] || null;
            if (!single) continue;
            if (single.getElementsByTagNameNS("*", "rest").length > 0) continue;
            const singleMidi = musicXmlPitchToMidi(single, 0);
            if (!Number.isFinite(singleMidi)) continue;
            const backupDuration = getMusicXmlNoteDurationRaw(single);
            if (!(backupDuration > 0)) continue;
            setMusicXmlNoteVoice(single, "1");
            if (setMusicXmlChordFlag(single, false)) changed = true;
            const clone = single.cloneNode(true);
            setMusicXmlNoteVoice(clone, "2");
            setMusicXmlChordFlag(clone, false);
            const stemEl = clone.getElementsByTagNameNS("*", "stem")[0] || null;
            if (stemEl) stemEl.textContent = "down";
            const anchorNext = single.nextSibling || null;
            if (anchorNext) {
              measureEl.insertBefore(clone, anchorNext);
            } else {
              measureEl.appendChild(clone);
            }
            if (insertBackupBeforeNode(measureEl, clone, backupDuration)) {
              changed = true;
            }
            changed = true;
            continue;
          }
          const pitched = cluster
            .filter((note) => note.getElementsByTagNameNS("*", "rest").length === 0)
            .map((note, clusterIndex) => ({
              note,
              clusterIndex,
              midi: musicXmlPitchToMidi(note, 0)
            }))
            .filter((item) => Number.isFinite(item.midi));
          if (!pitched.length) continue;
          if (pitched.length === 1) {
            const only = pitched[0]?.note || null;
            if (!only) continue;
            const backupDuration = getMusicXmlNoteDurationRaw(only);
            if (!(backupDuration > 0)) continue;
            setMusicXmlNoteVoice(only, "1");
            if (setMusicXmlChordFlag(only, false)) changed = true;
            const clone = only.cloneNode(true);
            setMusicXmlNoteVoice(clone, "2");
            setMusicXmlChordFlag(clone, false);
            const stemEl = clone.getElementsByTagNameNS("*", "stem")[0] || null;
            if (stemEl) stemEl.textContent = "down";
            const anchorNext = cluster[cluster.length - 1]?.nextSibling || null;
            if (anchorNext) {
              measureEl.insertBefore(clone, anchorNext);
            } else {
              measureEl.appendChild(clone);
            }
            if (insertBackupBeforeNode(measureEl, clone, backupDuration)) {
              changed = true;
            }
            changed = true;
            continue;
          }
          const byPitchDesc = pitched.slice().sort((a, b) => Number(b.midi || 0) - Number(a.midi || 0));
          const voiceByNote = new Map();
          byPitchDesc.forEach((item, idx) => {
            voiceByNote.set(item.note, idx === 0 ? "1" : "2");
          });
          const notesByVoice = { "1": [], "2": [] };
          pitched
            .slice()
            .sort((a, b) => Number(a.clusterIndex || 0) - Number(b.clusterIndex || 0))
            .forEach((item) => {
              const voice = String(voiceByNote.get(item.note) || "1");
              if (!notesByVoice[voice]) notesByVoice[voice] = [];
              notesByVoice[voice].push(item.note);
            });

          const firstByVoice = {
            "1": notesByVoice["1"]?.[0] || null,
            "2": notesByVoice["2"]?.[0] || null
          };
          const firstVoice = (() => {
            const c1 = firstByVoice["1"] ? cluster.indexOf(firstByVoice["1"]) : Number.POSITIVE_INFINITY;
            const c2 = firstByVoice["2"] ? cluster.indexOf(firstByVoice["2"]) : Number.POSITIVE_INFINITY;
            return c1 <= c2 ? "1" : "2";
          })();

          ["1", "2"].forEach((voice) => {
            const list = notesByVoice[voice] || [];
            list.forEach((note, idx) => {
              setMusicXmlNoteVoice(note, voice);
              const stemEl = note.getElementsByTagNameNS("*", "stem")[0] || null;
              if (stemEl) {
                stemEl.textContent = voice === "2" ? "down" : "up";
              }
              const chordChanged = setMusicXmlChordFlag(note, idx > 0);
              if (chordChanged) changed = true;
              changed = true;
            });
            if (!list.length) return;
            if (voice === firstVoice) return;
            const firstNote = list[0];
            const durationRaw = getMusicXmlNoteDurationRaw(firstNote) || getMusicXmlNoteDurationRaw(cluster[0]);
            if (insertBackupBeforeNode(measureEl, firstNote, durationRaw)) {
              changed = true;
            }
          });
        }
      });
    });
    if (!changed) return text;
    try {
      return new XMLSerializer().serializeToString(doc);
    } catch {
      return text;
    }
  }

  function getXmlMetaEditorValues(xmlText) {
    const text = String(xmlText || "").trim();
    const doc = new DOMParser().parseFromString(text, "application/xml");
    if (doc.getElementsByTagName("parsererror").length) return null;
    const root = doc.documentElement;
    const firstText = (selector) => String(doc.querySelector(selector)?.textContent || "").trim();
    const firstCreator = (type) => {
      const creator = Array.from(doc.getElementsByTagNameNS("*", "creator"))
        .find((node) => String(node.getAttribute("type") || "").trim().toLowerCase() === type);
      return String(creator?.textContent || "").trim();
    };
    const firstRight = () =>
      String(doc.getElementsByTagNameNS("*", "rights")[0]?.textContent || "").trim();
    const firstPageCreditByType = (type) => {
      const safeType = String(type || "").trim().toLowerCase();
      const credit = Array.from(doc.getElementsByTagNameNS("*", "credit")).find((creditEl) => {
        const page = String(creditEl.getAttribute("page") || "1").trim();
        if (page !== "1") return false;
        const creditType = String(
          creditEl.getElementsByTagNameNS("*", "credit-type")[0]?.textContent || ""
        ).trim().toLowerCase();
        return creditType === safeType;
      });
      return String(credit?.getElementsByTagNameNS("*", "credit-words")[0]?.textContent || "").trim();
    };
    const allPageCreditsByType = (type) => {
      const safeType = String(type || "").trim().toLowerCase();
      return Array.from(doc.getElementsByTagNameNS("*", "credit"))
        .filter((creditEl) => {
          const page = String(creditEl.getAttribute("page") || "1").trim();
          if (page !== "1") return false;
          const creditType = String(
            creditEl.getElementsByTagNameNS("*", "credit-type")[0]?.textContent || ""
          ).trim().toLowerCase();
          return creditType === safeType;
        })
        .map((creditEl) => String(creditEl.getElementsByTagNameNS("*", "credit-words")[0]?.textContent || "").trim())
        .filter(Boolean);
    };
    const rightsText = firstRight();
    const electronicByRaw =
      firstPageCreditByType("electronic-score-by") ||
      firstPageCreditByType("engraver") ||
      allPageCreditsByType("rights").join("\n");
    const electronicFromCredit = electronicByRaw.replace(/^electronic scores?\s+by\s+/i, "").trim();
    const electronicFromRights =
      String(rightsText.match(/electronic scores?\s+by\s+([^\n\r]+)/i)?.[1] || "").trim();
    const electronicBy = electronicFromCredit || electronicFromRights;
    return {
      doc,
      root,
      values: {
        title: firstText("work > work-title") || firstText("movement-title"),
        composer: firstCreator("composer"),
        lyricist: firstCreator("lyricist"),
        arranger: firstCreator("arranger"),
        copyright: rightsText,
        electronicBy
      }
    };
  }

  function upsertXmlChild(doc, parent, tagName, textValue) {
    if (!doc || !parent || !tagName) return;
    const existing = parent.getElementsByTagNameNS("*", tagName)[0] || null;
    const safeText = String(textValue || "").trim();
    if (!safeText) {
      if (existing?.parentNode === parent) parent.removeChild(existing);
      return;
    }
    const node = existing || doc.createElement(tagName);
    node.textContent = safeText;
    if (!existing) parent.appendChild(node);
  }

  function upsertXmlCreator(doc, identificationEl, type, textValue) {
    if (!doc || !identificationEl || !type) return;
    const safeText = String(textValue || "").trim();
    const creators = Array.from(identificationEl.getElementsByTagNameNS("*", "creator"));
    const existing = creators.find((node) => String(node.getAttribute("type") || "").trim().toLowerCase() === String(type).toLowerCase()) || null;
    if (!safeText) {
      if (existing?.parentNode === identificationEl) identificationEl.removeChild(existing);
      return;
    }
    const node = existing || doc.createElement("creator");
    node.setAttribute("type", String(type));
    node.textContent = safeText;
    if (!existing) identificationEl.appendChild(node);
  }

  function upsertXmlRights(doc, identificationEl, textValue) {
    if (!doc || !identificationEl) return;
    const safeText = String(textValue || "").trim();
    const existing = identificationEl.getElementsByTagNameNS("*", "rights")[0] || null;
    if (!safeText) {
      if (existing?.parentNode === identificationEl) identificationEl.removeChild(existing);
      return;
    }
    const node = existing || doc.createElement("rights");
    node.textContent = safeText;
    if (!existing) identificationEl.appendChild(node);
  }

  function upsertFirstPageCredit(doc, root, type, textValue, opts = {}) {
    if (!doc || !root || !type) return;
    const safeType = String(type || "").trim().toLowerCase();
    const safeText = String(textValue || "").trim();
    const alignByType = String(opts.align || (safeType === "composer" ? "right" : "left")).trim().toLowerCase();
    const valign = String(opts.valign || "top").trim().toLowerCase();
    const defaultY = Number(opts.defaultY);
    const fontSize = Number(opts.fontSize);

    const credits = Array.from(root.getElementsByTagNameNS("*", "credit")).filter((creditEl) => {
      const page = String(creditEl.getAttribute("page") || "1").trim();
      if (page !== "1") return false;
      const creditType = String(
        creditEl.getElementsByTagNameNS("*", "credit-type")[0]?.textContent || ""
      ).trim().toLowerCase();
      return creditType === safeType;
    });
    const matchPrefix = String(opts.matchPrefix || "").trim().toLowerCase();
    const excludePrefix = String(opts.excludePrefix || "").trim().toLowerCase();
    const matchedCredits = credits.filter((creditEl) => {
      const words = String(creditEl.getElementsByTagNameNS("*", "credit-words")[0]?.textContent || "").trim().toLowerCase();
      if (matchPrefix && !words.startsWith(matchPrefix)) return false;
      if (excludePrefix && words.startsWith(excludePrefix)) return false;
      return true;
    });
    const existing = matchedCredits[0] || null;

    if (!safeText) {
      if (existing?.parentNode === root) root.removeChild(existing);
      return;
    }

    const creditEl = existing || doc.createElement("credit");
    if (!existing) creditEl.setAttribute("page", "1");

    let creditTypeEl = creditEl.getElementsByTagNameNS("*", "credit-type")[0] || null;
    if (!creditTypeEl) {
      creditTypeEl = doc.createElement("credit-type");
      creditEl.appendChild(creditTypeEl);
    }
    creditTypeEl.textContent = safeType;

    let wordsEl = creditEl.getElementsByTagNameNS("*", "credit-words")[0] || null;
    if (!wordsEl) {
      wordsEl = doc.createElement("credit-words");
      creditEl.appendChild(wordsEl);
    }
    wordsEl.textContent = safeText;
    wordsEl.setAttribute("justify", alignByType);
    wordsEl.setAttribute("halign", alignByType);
    wordsEl.setAttribute("valign", valign);
    if (Number.isFinite(defaultY)) {
      wordsEl.setAttribute("default-y", String(defaultY));
    } else if (valign === "bottom") {
      wordsEl.setAttribute("default-y", "-120");
    } else {
      wordsEl.removeAttribute("default-y");
    }
    if (Number.isFinite(fontSize)) {
      wordsEl.setAttribute("font-size", String(fontSize));
    } else if (valign === "bottom") {
      wordsEl.setAttribute("font-size", "10");
    } else {
      wordsEl.removeAttribute("font-size");
    }

    if (!existing) {
      const partList = root.getElementsByTagNameNS("*", "part-list")[0] || null;
      if (partList?.parentNode === root) root.insertBefore(creditEl, partList);
      else root.insertBefore(creditEl, root.firstChild || null);
    }
  }

  async function uploadMusicXmlTextByKey(key, xmlText) {
    const safeKey = String(key || "").trim();
    if (!safeKey) return false;
    const uploadUrl = `https://r2-worker.textwhisper.workers.dev/?key=${encodeURIComponent(safeKey)}`;
    const blob = new Blob([String(xmlText || "")], { type: "application/vnd.recordare.musicxml+xml" });
    try {
      const res = await fetch(uploadUrl, {
        method: "POST",
        headers: { "Content-Type": "application/vnd.recordare.musicxml+xml" },
        body: blob
      });
      return !!res.ok;
    } catch {
      return false;
    }
  }

  function openXmlMetadataEditor(initialValues = {}) {
    const initial = {
      title: String(initialValues?.title || "").trim(),
      composer: String(initialValues?.composer || "").trim(),
      lyricist: String(initialValues?.lyricist || "").trim(),
      arranger: String(initialValues?.arranger || "").trim(),
      copyright: String(initialValues?.copyright || "").trim(),
      electronicBy: String(initialValues?.electronicBy || "").trim()
    };
    const initialTopRight = [initial.composer, initial.arranger ? `Arranger: ${initial.arranger}` : ""]
      .filter(Boolean)
      .join("\n");
    return new Promise((resolve) => {
      const old = document.getElementById("twXmlMetaEditorOverlay");
      if (old?.parentNode) old.parentNode.removeChild(old);

      const overlay = document.createElement("div");
      overlay.id = "twXmlMetaEditorOverlay";
      overlay.style.cssText = [
        "position:fixed",
        "inset:0",
        "z-index:2147483000",
        "background:rgba(9,14,30,0.44)",
        "display:flex",
        "align-items:center",
        "justify-content:center",
        "padding:16px"
      ].join(";");

      const panel = document.createElement("div");
      panel.style.cssText = [
        "width:min(560px,96vw)",
        "max-height:90vh",
        "overflow:auto",
        "background:#ffffff",
        "color:#0f172a",
        "border:1px solid #cbd5e1",
        "border-radius:12px",
        "box-shadow:0 20px 48px rgba(15,23,42,0.24)",
        "padding:14px"
      ].join(";");
      panel.innerHTML = `
        <div style="font-size:16px;font-weight:700;margin-bottom:10px;">Edit MusicXML Metadata</div>
        <div style="display:grid;grid-template-columns:1fr;gap:8px;">
          <label style="font-size:12px;font-weight:600;">Title<input id="twXmlMetaTitle" type="text" style="width:100%;margin-top:4px;padding:6px 8px;border:1px solid #cbd5e1;border-radius:8px;"></label>
          <label style="font-size:12px;font-weight:600;">Top-right (composer + arranger)<textarea id="twXmlMetaTopRight" rows="2" style="width:100%;margin-top:4px;padding:6px 8px;border:1px solid #cbd5e1;border-radius:8px;resize:vertical;"></textarea></label>
          <label style="font-size:12px;font-weight:600;">Lyricist (top left)<input id="twXmlMetaLyricist" type="text" style="width:100%;margin-top:4px;padding:6px 8px;border:1px solid #cbd5e1;border-radius:8px;"></label>
          <label style="font-size:12px;font-weight:600;">Copyright (footer)<input id="twXmlMetaCopyright" type="text" style="width:100%;margin-top:4px;padding:6px 8px;border:1px solid #cbd5e1;border-radius:8px;"></label>
          <label style="font-size:12px;font-weight:600;">Electronic scores by (footer)<input id="twXmlMetaElectronicBy" type="text" style="width:100%;margin-top:4px;padding:6px 8px;border:1px solid #cbd5e1;border-radius:8px;"></label>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px;">
          <button id="twXmlMetaCancel" type="button" style="border:1px solid #cbd5e1;background:#f8fafc;color:#0f172a;padding:6px 11px;border-radius:8px;cursor:pointer;">Cancel</button>
          <button id="twXmlMetaSave" type="button" style="border:1px solid #1d4ed8;background:#2563eb;color:#fff;padding:6px 11px;border-radius:8px;cursor:pointer;">Save</button>
        </div>
      `;

      overlay.appendChild(panel);
      document.body.appendChild(overlay);

      const titleInput = panel.querySelector("#twXmlMetaTitle");
      const topRightInput = panel.querySelector("#twXmlMetaTopRight");
      const lyricistInput = panel.querySelector("#twXmlMetaLyricist");
      const copyrightInput = panel.querySelector("#twXmlMetaCopyright");
      const electronicByInput = panel.querySelector("#twXmlMetaElectronicBy");
      const cancelBtn = panel.querySelector("#twXmlMetaCancel");
      const saveBtn = panel.querySelector("#twXmlMetaSave");

      if (titleInput) titleInput.value = initial.title;
      if (topRightInput) topRightInput.value = initialTopRight;
      if (lyricistInput) lyricistInput.value = initial.lyricist;
      if (copyrightInput) copyrightInput.value = initial.copyright;
      if (electronicByInput) electronicByInput.value = initial.electronicBy;

      const parseTopRight = () => {
        const lines = String(topRightInput?.value || "")
          .split(/\r?\n/)
          .map((line) => String(line || "").trim())
          .filter(Boolean);
        const composer = String(lines[0] || "").trim();
        const arrangerLine = lines.slice(1).join(" ").trim();
        const arranger = arrangerLine.replace(/^arranger\s*:\s*/i, "").trim();
        return { composer, arranger };
      };

      const close = (result) => {
        document.removeEventListener("keydown", onKeydown, true);
        if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
        resolve(result);
      };

      const onKeydown = (event) => {
        if (event.key === "Escape") {
          event.preventDefault();
          close(null);
        } else if (event.key === "Enter" && (event.metaKey || event.ctrlKey)) {
          event.preventDefault();
          close({
            title: String(titleInput?.value || "").trim(),
            ...parseTopRight(),
            lyricist: String(lyricistInput?.value || "").trim(),
            copyright: String(copyrightInput?.value || "").trim(),
            electronicBy: String(electronicByInput?.value || "").trim()
          });
        }
      };
      document.addEventListener("keydown", onKeydown, true);

      overlay.addEventListener("click", (event) => {
        if (event.target === overlay) close(null);
      });
      cancelBtn?.addEventListener("click", () => close(null));
      saveBtn?.addEventListener("click", () => {
        close({
          title: String(titleInput?.value || "").trim(),
          ...parseTopRight(),
          lyricist: String(lyricistInput?.value || "").trim(),
          copyright: String(copyrightInput?.value || "").trim(),
          electronicBy: String(electronicByInput?.value || "").trim()
        });
      });
      setTimeout(() => titleInput?.focus?.(), 0);
    });
  }

  async function editMusicXmlMetadata(surrogate, file) {
    const safeSurrogate = String(surrogate || "").trim();
    if (!safeSurrogate || !file?.url || !file?.key) return false;
    const fileName = String(file?.name || file?.key || "").toLowerCase();
    if (fileName.endsWith(".mxl")) {
      window.alert("Metadata editing for .mxl is not supported yet. Use .xml/.musicxml.");
      return false;
    }
    const xmlText = await fetchMusicXmlTextFromUrl(file.url);
    const parsed = getXmlMetaEditorValues(xmlText);
    if (!parsed?.doc || !parsed?.root) {
      window.alert("Unable to parse MusicXML.");
      return false;
    }

    const edited = await openXmlMetadataEditor(parsed.values || {});
    if (!edited) return false;
    const title = String(edited.title || "").trim();
    const composer = String(edited.composer || "").trim();
    const lyricist = String(edited.lyricist || "").trim();
    const arranger = String(edited.arranger || "").trim();
    const copyrightText = String(edited.copyright || "").trim();
    const electronicByName = String(edited.electronicBy || "").trim();

    const doc = parsed.doc;
    const root = parsed.root;
    let work = root.getElementsByTagNameNS("*", "work")[0] || null;
    if (!work) {
      work = doc.createElement("work");
      const movement = root.getElementsByTagNameNS("*", "movement-title")[0] || null;
      if (movement?.parentNode === root) root.insertBefore(work, movement);
      else root.insertBefore(work, root.firstChild || null);
    }
    upsertXmlChild(doc, work, "work-title", title);
    let movementTitle = root.getElementsByTagNameNS("*", "movement-title")[0] || null;
    if (String(title || "").trim()) {
      if (!movementTitle) {
        movementTitle = doc.createElement("movement-title");
        root.insertBefore(movementTitle, root.firstChild || null);
      }
      movementTitle.textContent = String(title).trim();
    } else if (movementTitle?.parentNode === root) {
      root.removeChild(movementTitle);
    }

    let identification = root.getElementsByTagNameNS("*", "identification")[0] || null;
    if (!identification) {
      identification = doc.createElement("identification");
      const partList = root.getElementsByTagNameNS("*", "part-list")[0] || null;
      if (partList?.parentNode === root) root.insertBefore(identification, partList);
      else root.insertBefore(identification, root.firstChild || null);
    }
    upsertXmlCreator(doc, identification, "composer", composer);
    upsertXmlCreator(doc, identification, "lyricist", lyricist);
    upsertXmlCreator(doc, identification, "arranger", arranger);
    let safeCopyright = String(copyrightText || "").trim();
    if (safeCopyright && !/^\s*©/.test(safeCopyright)) {
      safeCopyright = `© ${safeCopyright.replace(/^\s*\(c\)\s*/i, "").trim()}`;
    }
    const safeElectronicBy = String(electronicByName || "").trim();
    const electronicLine = safeElectronicBy ? `Electronic scores by ${safeElectronicBy}` : "";

    upsertXmlRights(doc, identification, [safeCopyright, electronicLine].filter(Boolean).join(" ; "));
    upsertFirstPageCredit(doc, root, "lyricist", lyricist, { align: "left", valign: "top", defaultY: 1160 });
    upsertFirstPageCredit(doc, root, "composer", composer, {
      align: "right",
      valign: "top",
      defaultY: 1160,
      excludePrefix: "arranger:"
    });
    upsertFirstPageCredit(doc, root, "composer", arranger ? `Arranger: ${arranger}` : "", {
      align: "right",
      valign: "top",
      defaultY: 1080,
      matchPrefix: "arranger:"
    });
    upsertFirstPageCredit(doc, root, "rights", safeCopyright, {
      align: "center",
      valign: "bottom",
      defaultY: -120,
      fontSize: 10,
      excludePrefix: "electronic scores by"
    });
    upsertFirstPageCredit(doc, root, "rights", electronicLine, {
      align: "center",
      valign: "bottom",
      defaultY: -140,
      fontSize: 10,
      matchPrefix: "electronic scores by"
    });

    const out = new XMLSerializer().serializeToString(doc);
    return await uploadMusicXmlTextByKey(String(file.key || ""), out);
  }

  async function deleteMusicXmlFile(surrogate, file = null) {
    const safeSurrogate = String(surrogate || window.currentSurrogate || "").trim();
    if (!safeSurrogate) return false;

    const targetFile = file || (await getPrimaryMusicXmlFile(safeSurrogate));
    const key = String(targetFile?.key || "").trim();
    if (!key) return false;
    if (!canDeleteMusicXmlFile(safeSurrogate, targetFile)) return false;

    const url = `https://r2-worker.textwhisper.workers.dev/?key=${encodeURIComponent(key)}`;
    let deleted = false;
    try {
      const res = await fetch(url, { method: "DELETE" });
      deleted = !!res.ok;
    } catch {}

    if (!deleted) {
      try {
        const res = await fetch(url, {
          method: "POST",
          headers: { "X-HTTP-Method-Override": "DELETE" }
        });
        deleted = !!res.ok;
      } catch {}
    }

    if (!deleted) return false;

    const xmlState = window._pdfXmlViewState || {};
    const activeFileKey = String(xmlState.file?.key || "").trim();
    const deletedActiveFile =
      !!xmlState.active &&
      String(xmlState.surrogate || "") === safeSurrogate &&
      activeFileKey &&
      activeFileKey === key;

    if (window.twMusicXmlPlay?.isXmlPlaybackActive?.(safeSurrogate)) {
      window.twMusicXmlPlay?.stopXmlPlayback?.();
    }

    if (deletedActiveFile) {
      let nextFile = null;
      try {
        nextFile = await getPrimaryMusicXmlFile(safeSurrogate);
      } catch {}
      if (nextFile?.url) {
        await openMusicXmlInPdfTab(safeSurrogate, nextFile, { setSticky: true });
      } else {
        setXmlViewState(false, safeSurrogate, null);
        try {
          await window.loadPDF?.(safeSurrogate, null);
        } catch (err) {
          console.warn("MusicXML delete PDF restore failed:", err);
        }
      }
    }

    try {
      await refreshPdfTabXmlState(safeSurrogate);
    } catch (err) {
      console.warn("MusicXML delete refresh failed:", err);
    }
    return true;
  }

  function getItemTitleForSurrogate(surrogate) {
    const safeSurrogate = String(surrogate || window.currentSurrogate || "").trim();
    if (!safeSurrogate) return "";
    const row = document.querySelector(`.list-sub-item[data-value="${safeSurrogate}"]`);
    if (!row) return "";
    const direct =
      row.dataset?.title ||
      row.getAttribute?.("data-title") ||
      row.querySelector?.(".title, .item-title, .sub-item-title")?.textContent ||
      row.querySelector?.(".editable-title, .list-item-text, .text-preview")?.textContent ||
      row.textContent;
    return String(direct || "").replace(/\s+/g, " ").trim();
  }

  function decodeMusicXmlBytes(bytes) {
    const view = bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes || 0);
    if (!view.length) return "";

    const decodeWith = (encoding) => {
      try {
        return new TextDecoder(encoding, { fatal: false }).decode(view);
      } catch {
        return "";
      }
    };

    if (view.length >= 2) {
      if (view[0] === 0xff && view[1] === 0xfe) {
        return decodeWith("utf-16le");
      }
      if (view[0] === 0xfe && view[1] === 0xff) {
        return decodeWith("utf-16be");
      }
    }
    if (view.length >= 3 && view[0] === 0xef && view[1] === 0xbb && view[2] === 0xbf) {
      return decodeWith("utf-8");
    }

    const sniffLength = Math.min(view.length, 256);
    const sniffAscii = Array.from(view.slice(0, sniffLength))
      .map((value) => (value >= 32 && value <= 126 ? String.fromCharCode(value) : " "))
      .join("");
    const encodingMatch = sniffAscii.match(/encoding\s*=\s*["']([^"']+)["']/i);
    const declaredEncoding = String(encodingMatch?.[1] || "").trim().toLowerCase();
    if (declaredEncoding) {
      if (declaredEncoding === "utf-16" || declaredEncoding === "utf-16le" || declaredEncoding === "utf-16be") {
        const evenZeros = view.slice(1, Math.min(view.length, 64)).filter((_, index) => index % 2 === 0 && view[index + 1] === 0).length;
        const oddZeros = view.slice(0, Math.min(view.length, 64)).filter((_, index) => index % 2 === 0 && view[index] === 0).length;
        return oddZeros > evenZeros ? decodeWith("utf-16be") : decodeWith("utf-16le");
      }
      const declaredDecoded = decodeWith(declaredEncoding);
      if (declaredDecoded) return declaredDecoded;
    }

    if (view.length >= 8) {
      let oddZeros = 0;
      let evenZeros = 0;
      for (let i = 0; i < Math.min(view.length, 64); i += 1) {
        if (view[i] === 0) {
          if (i % 2 === 0) evenZeros += 1;
          else oddZeros += 1;
        }
      }
      if (oddZeros >= 4 || evenZeros >= 4) {
        return evenZeros > oddZeros ? decodeWith("utf-16be") : decodeWith("utf-16le");
      }
    }

    return decodeWith("utf-8");
  }

  async function extractXmlTextFromMxl(buffer) {
    const ready = await loadScriptOnce(
      "https://cdn.jsdelivr.net/npm/jszip@3.10.1/dist/jszip.min.js",
      "jszip"
    );
    if (!ready || !window.JSZip) throw new Error("JSZip unavailable");
    const zip = await window.JSZip.loadAsync(buffer);

    const containerEntry = zip.file("META-INF/container.xml");
    if (containerEntry) {
      const containerBytes = await containerEntry.async("uint8array");
      const containerText = decodeMusicXmlBytes(containerBytes);
      const doc = new DOMParser().parseFromString(containerText, "application/xml");
      const rootfile = doc.getElementsByTagName("rootfile")[0];
      const fullPath = String(rootfile?.getAttribute("full-path") || "").trim();
      if (fullPath && zip.file(fullPath)) {
        const xmlBytes = await zip.file(fullPath).async("uint8array");
        return decodeMusicXmlBytes(xmlBytes);
      }
    }

    const candidates = Object.keys(zip.files || {})
      .filter((name) => /\.xml$/i.test(name) && !/^META-INF\//i.test(name))
      .sort();
    if (candidates.length && zip.file(candidates[0])) {
      const xmlBytes = await zip.file(candidates[0]).async("uint8array");
      return decodeMusicXmlBytes(xmlBytes);
    }
    throw new Error("No XML entry found in MXL");
  }

  async function fetchMusicXmlTextFromUrl(xmlUrl) {
    const url = String(xmlUrl || "").trim();
    if (!url) return "";
    const res = await fetch(url, { cache: "no-store" });
    if (!res.ok) throw new Error(`MusicXML fetch failed: HTTP ${res.status}`);
    const buf = await res.arrayBuffer();
    const bytes = new Uint8Array(buf);
    const looksZip =
      /\.mxl(?:$|\?)/i.test(url) ||
      (bytes.length >= 4 && bytes[0] === 0x50 && bytes[1] === 0x4b && bytes[2] === 0x03 && bytes[3] === 0x04);
    if (looksZip) {
      return await extractXmlTextFromMxl(buf);
    }
    return decodeMusicXmlBytes(bytes);
  }

  function sanitizeMusicXmlForRender(xmlText) {
    const text = String(xmlText || "").trim();
    if (!text) return "";
    const doc = new DOMParser().parseFromString(text, "application/xml");
    if (doc.getElementsByTagName("parsererror").length) return text;

    let changed = false;
    Array.from(doc.getElementsByTagNameNS("*", "staff-lines")).forEach((staffLinesEl) => {
      const value = Number(String(staffLinesEl?.textContent || "").trim());
      if (Number.isFinite(value) && value >= 2) return;
      staffLinesEl.textContent = "5";
      changed = true;
    });

    if (!changed) return text;
    try {
      return new XMLSerializer().serializeToString(doc);
    } catch {
      return text;
    }
  }

  function escapeHtmlForInlineText(value) {
    return String(value || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  function getTransposeSemitonesFromAttributes(attributesEl) {
    if (!attributesEl) return null;
    const transposeEl = attributesEl.getElementsByTagNameNS("*", "transpose")[0];
    if (!transposeEl) return null;
    const chromatic = Number(
      (transposeEl.getElementsByTagNameNS("*", "chromatic")[0]?.textContent || "").trim()
    );
    const octaveChange = Number(
      (transposeEl.getElementsByTagNameNS("*", "octave-change")[0]?.textContent || "").trim()
    );
    const diatonic = Number(
      (transposeEl.getElementsByTagNameNS("*", "diatonic")[0]?.textContent || "").trim()
    );

    let semitones = 0;
    if (Number.isFinite(chromatic)) {
      semitones += chromatic;
    } else if (Number.isFinite(diatonic)) {
      // Fallback only if chromatic is missing. Rough staff-step mapping.
      semitones += Math.round((diatonic * 12) / 7);
    }
    if (Number.isFinite(octaveChange)) {
      semitones += octaveChange * 12;
    }
    return semitones;
  }

  function musicXmlPitchToMidi(noteEl, transposeSemitones = 0) {
    const pitchEl = noteEl.getElementsByTagNameNS("*", "pitch")[0];
    if (!pitchEl) return null;
    const step = (pitchEl.getElementsByTagNameNS("*", "step")[0]?.textContent || "").trim().toUpperCase();
    const octave = Number((pitchEl.getElementsByTagNameNS("*", "octave")[0]?.textContent || "").trim());
    const alter = Number((pitchEl.getElementsByTagNameNS("*", "alter")[0]?.textContent || "0").trim() || 0);
    if (!Number.isFinite(octave)) return null;
    const semis = { C: 0, D: 2, E: 4, F: 5, G: 7, A: 9, B: 11 };
    if (!(step in semis)) return null;
    const transpose = Math.round(Number(transposeSemitones) || 0);
    return Math.max(0, Math.min(127, ((octave + 1) * 12) + semis[step] + alter + transpose));
  }

  function parseMusicXmlPitches(xmlText) {
    const text = String(xmlText || "").trim();
    if (!text) return [];
    const doc = new DOMParser().parseFromString(text, "application/xml");
    if (doc.getElementsByTagName("parsererror").length) return [];
    return Array.from(doc.getElementsByTagNameNS("*", "note"))
      .filter((noteEl) =>
        !noteEl.getElementsByTagNameNS("*", "rest").length &&
        !noteEl.getElementsByTagNameNS("*", "grace").length
      )
      .map((noteEl) => musicXmlPitchToMidi(noteEl))
      .filter((midi) => Number.isFinite(midi));
  }

  function summarizeMusicXmlPitches(doc) {
    const parts = Array.from(doc?.getElementsByTagNameNS?.("*", "part") || []);
    const allPitches = [];
    let lastBassMidi = null;
    parts.forEach((partEl, partIndex) => {
      const notes = Array.from(partEl.getElementsByTagNameNS("*", "note"))
        .filter((noteEl) =>
          !noteEl.getElementsByTagNameNS("*", "rest").length &&
          !noteEl.getElementsByTagNameNS("*", "grace").length
        );
      notes.forEach((noteEl) => {
        const midi = musicXmlPitchToMidi(noteEl);
        if (Number.isFinite(midi)) allPitches.push(midi);
      });
      if (partIndex === (parts.length - 1)) {
        for (let i = notes.length - 1; i >= 0; i -= 1) {
          const midi = musicXmlPitchToMidi(notes[i]);
          if (Number.isFinite(midi)) {
            lastBassMidi = midi;
            break;
          }
        }
      }
    });
    return {
      pitches: allPitches,
      lastMidi: allPitches.length ? allPitches[allPitches.length - 1] : null,
      lastBassMidi
    };
  }

  function scaleRootToPitchClass(root) {
    const map = {
      Cb: 11, Gb: 6, Db: 1, Ab: 8, Eb: 3, Bb: 10, F: 5,
      C: 0, G: 7, D: 2, A: 9, E: 4, B: 11, "F#": 6, "C#": 1,
      "G#": 8, "D#": 3, "A#": 10
    };
    return Number.isFinite(map[String(root || "")]) ? map[String(root || "")] : null;
  }

  function chooseLikelyScaleMode(fifths, declaredMode, summary = {}, preferHeuristic = false) {
    const majorByFifths = {
      [-7]: "Cb", [-6]: "Gb", [-5]: "Db", [-4]: "Ab", [-3]: "Eb", [-2]: "Bb", [-1]: "F",
      [0]: "C", [1]: "G", [2]: "D", [3]: "A", [4]: "E", [5]: "B", [6]: "F#", [7]: "C#"
    };
    const minorByFifths = {
      [-7]: "Ab", [-6]: "Eb", [-5]: "Bb", [-4]: "F", [-3]: "C", [-2]: "G", [-1]: "D",
      [0]: "A", [1]: "E", [2]: "B", [3]: "F#", [4]: "C#", [5]: "G#", [6]: "D#", [7]: "A#"
    };
    const majorRoot = majorByFifths[Number(fifths)];
    const minorRoot = minorByFifths[Number(fifths)];
    if (!majorRoot || !minorRoot) return "";
    const fallbackMode = String(declaredMode || "major").trim().toLowerCase() === "minor" ? "min" : "maj";
    const fallbackRoot = fallbackMode === "min" ? minorRoot : majorRoot;
    const notePitches = Array.isArray(summary?.pitches) ? summary.pitches.filter((midi) => Number.isFinite(midi)) : [];
    if (!notePitches.length) return `${fallbackRoot}:${fallbackMode}`;

    const majorPc = scaleRootToPitchClass(majorRoot);
    const minorPc = scaleRootToPitchClass(minorRoot);
    if (!Number.isFinite(majorPc) || !Number.isFinite(minorPc)) return `${fallbackRoot}:${fallbackMode}`;
    const lastMidi = Number.isFinite(Number(summary?.lastMidi)) ? Number(summary.lastMidi) : notePitches[notePitches.length - 1];
    const lastPc = ((Number(lastMidi) % 12) + 12) % 12;
    const lastBassPc = Number.isFinite(Number(summary?.lastBassMidi))
      ? (((Number(summary.lastBassMidi) % 12) + 12) % 12)
      : null;
    const counts = new Map();
    notePitches.forEach((midi) => {
      const pc = ((Number(midi) % 12) + 12) % 12;
      counts.set(pc, Number(counts.get(pc) || 0) + 1);
    });

    const tonicWeight = 0.8;
    let majorScore = 0;
    let minorScore = 0;
    if (lastPc === majorPc) majorScore += 4;
    if (lastPc === minorPc) minorScore += 4;
    if (lastBassPc === majorPc) majorScore += 7;
    if (lastBassPc === minorPc) minorScore += 7;
    if (lastPc === ((majorPc + 7) % 12)) majorScore += 1;
    if (lastPc === ((minorPc + 7) % 12)) minorScore += 1;
    majorScore += tonicWeight * Number(counts.get(majorPc) || 0);
    minorScore += tonicWeight * Number(counts.get(minorPc) || 0);
    if (Number(counts.get(((minorPc + 11) % 12)) || 0) > 0) minorScore += 1.5; // raised leading tone hint

    if (!preferHeuristic && Math.abs(majorScore - minorScore) < 1.5) {
      return `${fallbackRoot}:${fallbackMode}`;
    }
    return majorScore > minorScore ? `${majorRoot}:maj` : `${minorRoot}:min`;
  }

  function parseMusicXmlScaleValue(xmlText) {
    const text = String(xmlText || "").trim();
    if (!text) return "";
    const doc = new DOMParser().parseFromString(text, "application/xml");
    if (doc.getElementsByTagName("parsererror").length) return "";
    const softwareText = Array.from(doc.getElementsByTagNameNS("*", "software"))
      .map((el) => String(el?.textContent || "").trim().toLowerCase())
      .join(" ");
    const isSoundsliceExport = softwareText.includes("soundslice");
    const pitchSummary = summarizeMusicXmlPitches(doc);
    const keyEls = Array.from(doc.getElementsByTagNameNS("*", "key"));
    if (!keyEls.length) return "";
    for (let i = 0; i < keyEls.length; i += 1) {
      const keyEl = keyEls[i];
      const fifths = Number((keyEl.getElementsByTagNameNS("*", "fifths")[0]?.textContent || "").trim());
      if (!Number.isFinite(fifths)) continue;
      const modeRaw = String(
        keyEl.getElementsByTagNameNS("*", "mode")[0]?.textContent || "major"
      ).trim().toLowerCase();
      const scaleValue = chooseLikelyScaleMode(
        fifths,
        modeRaw,
        pitchSummary,
        isSoundsliceExport
      );
      if (scaleValue) return scaleValue;
    }
    return "";
  }

  function getScaleValueFromFifths(fifths, modeRaw = "major") {
    const mode = String(modeRaw || "major").trim().toLowerCase() === "minor" ? "min" : "maj";
    const majorByFifths = {
      [-7]: "Cb",
      [-6]: "Gb",
      [-5]: "Db",
      [-4]: "Ab",
      [-3]: "Eb",
      [-2]: "Bb",
      [-1]: "F",
      [0]: "C",
      [1]: "G",
      [2]: "D",
      [3]: "A",
      [4]: "E",
      [5]: "B",
      [6]: "F#",
      [7]: "C#"
    };
    const minorByFifths = {
      [-7]: "Ab",
      [-6]: "Eb",
      [-5]: "Bb",
      [-4]: "F",
      [-3]: "C",
      [-2]: "G",
      [-1]: "D",
      [0]: "A",
      [1]: "E",
      [2]: "B",
      [3]: "F#",
      [4]: "C#",
      [5]: "G#",
      [6]: "D#",
      [7]: "A#"
    };
    const root = mode === "min" ? minorByFifths[fifths] : majorByFifths[fifths];
    return root ? `${root}:${mode}` : "";
  }

  function parseMusicXmlMeasureScaleMap(xmlText) {
    const text = String(xmlText || "").trim();
    const map = {};
    if (!text) return map;
    const doc = new DOMParser().parseFromString(text, "application/xml");
    if (doc.getElementsByTagName("parsererror").length) return map;
    const softwareText = Array.from(doc.getElementsByTagNameNS("*", "software"))
      .map((el) => String(el?.textContent || "").trim().toLowerCase())
      .join(" ");
    const isSoundsliceExport = softwareText.includes("soundslice");
    const pitchSummary = summarizeMusicXmlPitches(doc);
    const firstPart = doc.getElementsByTagNameNS("*", "part")[0];
    if (!firstPart) return map;
    const measures = Array.from(firstPart.getElementsByTagNameNS("*", "measure"));
    let currentScale = "";
    measures.forEach((measureEl, idx) => {
      const keyEl = Array.from(measureEl.childNodes || []).find((node) => {
        if (!node || node.nodeType !== 1 || localNameOf(node) !== "attributes") return false;
        return !!node.getElementsByTagNameNS?.("*", "key")[0];
      })?.getElementsByTagNameNS?.("*", "key")?.[0] || null;
      if (keyEl) {
        const fifths = Number((keyEl.getElementsByTagNameNS("*", "fifths")[0]?.textContent || "").trim());
        const modeRaw = String(
          keyEl.getElementsByTagNameNS("*", "mode")[0]?.textContent || "major"
        ).trim();
        const nextScale = Number.isFinite(fifths)
          ? (
              chooseLikelyScaleMode(fifths, modeRaw, pitchSummary, isSoundsliceExport) ||
              getScaleValueFromFifths(fifths, modeRaw)
            )
          : "";
        if (nextScale) currentScale = nextScale;
      }
      if (currentScale) {
        map[String(idx)] = currentScale;
      }
    });
    return map;
  }

  function getScaleValueFromMidi(midi, mode = "maj") {
    const noteNumber = Math.max(0, Math.min(127, Math.round(Number(midi) || 0)));
    if (!Number.isFinite(noteNumber)) return "";
    const roots = ["C", "C#", "D", "Eb", "E", "F", "F#", "G", "Ab", "A", "Bb", "B"];
    const root = roots[((noteNumber % 12) + 12) % 12] || "C";
    return `${root}:${mode === "min" ? "min" : "maj"}`;
  }

  function inferMusicXmlScaleValue(xmlText) {
    const measureScaleMap = parseMusicXmlMeasureScaleMap(xmlText);
    const declared = parseMusicXmlScaleValue(xmlText) || String(measureScaleMap["0"] || "");
    if (declared) return declared;
    const pitches = parseMusicXmlPitches(xmlText);
    if (!pitches.length) return "";
    return getScaleValueFromMidi(pitches[0], "maj");
  }

  function localNameOf(node) {
    return (node?.localName || node?.nodeName || "").toLowerCase();
  }

  function getQuarterLengthFromBeatUnit(typeText = "", dots = 0) {
    const quarterMultByType = {
      longa: 16,
      breve: 8,
      whole: 4,
      half: 2,
      quarter: 1,
      eighth: 0.5,
      "16th": 0.25,
      "32nd": 0.125,
      "64th": 0.0625,
      "128th": 0.03125
    };
    const base = Number(quarterMultByType[String(typeText || "").trim().toLowerCase()] || 0);
    if (!(base > 0)) return 0;
    let dotFactor = 1;
    let add = 0.5;
    for (let i = 0; i < Math.max(0, Number(dots) || 0); i += 1) {
      dotFactor += add;
      add *= 0.5;
    }
    return base * dotFactor;
  }

  function parseTempoBpm(doc) {
    const metronomeEls = Array.from(doc.getElementsByTagNameNS("*", "metronome"));
    for (let i = 0; i < metronomeEls.length; i += 1) {
      const metronomeEl = metronomeEls[i];
      const beatUnit = String(
        metronomeEl.getElementsByTagNameNS("*", "beat-unit")[0]?.textContent || ""
      ).trim().toLowerCase();
      const dotCount = metronomeEl.getElementsByTagNameNS("*", "beat-unit-dot").length;
      const perMinute = Number(
        metronomeEl.getElementsByTagNameNS("*", "per-minute")[0]?.textContent?.trim?.() || ""
      );
      const quarterLength = getQuarterLengthFromBeatUnit(beatUnit, dotCount);
      if (Number.isFinite(perMinute) && perMinute > 0 && quarterLength > 0) {
        return perMinute * quarterLength;
      }
      if (Number.isFinite(perMinute) && perMinute > 0) {
        return perMinute;
      }
    }

    const perMinute = Number(
      doc.getElementsByTagNameNS("*", "per-minute")[0]?.textContent?.trim?.() || ""
    );
    if (Number.isFinite(perMinute) && perMinute > 0) return perMinute;

    const soundEl = doc.querySelector("sound[tempo]");
    const soundTempo = Number(soundEl?.getAttribute("tempo") || "");
    if (Number.isFinite(soundTempo) && soundTempo > 0) return soundTempo;

    return 96;
  }

  function parseSwingSettings(doc) {
    const soundEls = Array.from(doc.getElementsByTagNameNS("*", "sound"));
    for (let i = 0; i < soundEls.length; i += 1) {
      const soundEl = soundEls[i];
      const swingEl = soundEl.getElementsByTagNameNS("*", "swing")[0];
      if (!swingEl) continue;
      const first = Number(
        swingEl.getElementsByTagNameNS("*", "first")[0]?.textContent?.trim?.() || ""
      );
      const second = Number(
        swingEl.getElementsByTagNameNS("*", "second")[0]?.textContent?.trim?.() || ""
      );
      const swingType = String(
        swingEl.getElementsByTagNameNS("*", "swing-type")[0]?.textContent || ""
      ).trim().toLowerCase();
      if (!(Number.isFinite(first) && first > 0 && Number.isFinite(second) && second > 0)) continue;
      if (swingType !== "eighth") continue;
      return {
        first,
        second,
        swingType
      };
    }
    return null;
  }

  function applySwingToPlaybackEvents(events, tracks, swing) {
    if (!swing || swing.swingType !== "eighth") return;
    const ratioTotal = Math.max(1, Number(swing.first || 0) + Number(swing.second || 0));
    const applyToList = (list) => {
      const groups = new Map();
      list.forEach((ev) => {
        if (!ev) return;
        const key = [
          String(ev.trackId || ""),
          String(ev.measureIndex || 0),
          String(ev.voice || "1")
        ].join("::");
        if (!groups.has(key)) groups.set(key, []);
        groups.get(key).push(ev);
      });
      groups.forEach((group) => {
        group.sort((a, b) =>
          Number(a.startUnit || 0) - Number(b.startUnit || 0) ||
          Number(a.sourceIndex || 0) - Number(b.sourceIndex || 0)
        );
        for (let i = 0; i < group.length - 1; i += 1) {
          const firstEv = group[i];
          const secondEv = group[i + 1];
          const firstType = String(firstEv.noteType || "").toLowerCase();
          const secondType = String(secondEv.noteType || "").toLowerCase();
          if (firstType !== "eighth") continue;
          if (secondType !== "eighth" && secondType !== "16th") continue;
          const firstStart = Number(firstEv.startUnit || 0);
          const firstDur = Number(firstEv.durationUnit || 0);
          const secondStart = Number(secondEv.startUnit || 0);
          const secondDur = Number(secondEv.durationUnit || 0);
          const measureStart = Number(firstEv.measureStartUnit || 0);
          const localStart = firstStart - measureStart;
          const beatTick = XML_PLAYBACK_DIVISION;
          if (firstDur <= 0 || secondDur <= 0) continue;
          if (Math.abs(localStart % beatTick) > 1e-6) continue;
          if (Math.abs((firstStart + firstDur) - secondStart) > 1e-6) continue;
          const pairTotal = firstDur + secondDur;
          if (Math.abs(pairTotal - beatTick) > 1) continue;
          const swungFirst = Math.max(1, Math.round((pairTotal * Number(swing.first || 0)) / ratioTotal));
          const swungSecond = Math.max(1, pairTotal - swungFirst);
          if (swungFirst === firstDur && swungSecond === secondDur) continue;
          firstEv.durationUnit = swungFirst;
          secondEv.startUnit = firstStart + swungFirst;
          secondEv.measureLocalStartUnit = localStart + swungFirst;
          secondEv.durationUnit = swungSecond;
          i += 1;
        }
      });
    };

    applyToList(Array.isArray(events) ? events : []);
    (Array.isArray(tracks) ? tracks : []).forEach((track) => applyToList(track?.events || []));
  }

  function normalizeXmlPlaybackVisualMode(value) {
    const raw = String(value || "").trim().toLowerCase();
    if (raw === "off" || raw === "0" || raw === "false") return "off";
    if (raw === "piano") return "piano";
    if (raw === "all" || raw === "1" || raw === "true") return "all";
    return "piano";
  }

  function getXmlPlaybackVisualMode() {
    try {
      const raw = localStorage.getItem(XML_PLAYBACK_VISUALS_STORAGE_KEY);
      return normalizeXmlPlaybackVisualMode(raw);
    } catch {
      return "piano";
    }
  }

  function isXmlScoreVisualsEnabled() {
    return getXmlPlaybackVisualMode() === "all";
  }

  function isXmlPianoVisualsEnabled() {
    const mode = getXmlPlaybackVisualMode();
    return mode === "piano" || mode === "all";
  }

  function getXmlPlaybackVisualsEnabled() {
    return getXmlPlaybackVisualMode() !== "off";
  }

  function setXmlPlaybackVisualMode(mode) {
    const next = normalizeXmlPlaybackVisualMode(mode);
    try {
      localStorage.setItem(XML_PLAYBACK_VISUALS_STORAGE_KEY, next);
    } catch {}
    if (next !== "all") {
      const viewer = document.getElementById("pdfTabXmlViewer");
      if (viewer) window.twMusicXmlView?.clearActiveMeasureHighlight?.(viewer);
    }
    if (next === "off") {
      window.TWPianoDock?.clearPreviewMidi?.();
    }
    window.TWPianoDock?.refreshXmlMixer?.(window.currentSurrogate);
    return next;
  }

  function cycleXmlPlaybackVisualMode() {
    const current = getXmlPlaybackVisualMode();
    const next = current === "off" ? "piano" : (current === "piano" ? "all" : "off");
    return setXmlPlaybackVisualMode(next);
  }

  function setXmlPlaybackVisualsEnabled(enabled) {
    return setXmlPlaybackVisualMode(enabled !== false ? "all" : "off");
  }

  function toggleXmlPlaybackVisuals() {
    return cycleXmlPlaybackVisualMode();
  }

  function u16be(value) {
    const n = Math.max(0, Math.min(0xffff, Math.floor(Number(value) || 0)));
    return [(n >>> 8) & 0xff, n & 0xff];
  }

  function u32be(value) {
    const n = Math.max(0, Math.min(0xffffffff, Math.floor(Number(value) || 0)));
    return [(n >>> 24) & 0xff, (n >>> 16) & 0xff, (n >>> 8) & 0xff, n & 0xff];
  }

  function encodeVlq(value) {
    let n = Math.max(0, Math.floor(Number(value) || 0));
    const bytes = [n & 0x7f];
    while ((n >>= 7) > 0) {
      bytes.unshift((n & 0x7f) | 0x80);
    }
    return bytes;
  }

  function xmlDurationRawFromNote(noteEl, divisions = 1) {
    const raw = Number((noteEl?.getElementsByTagNameNS("*", "duration")[0]?.textContent || "").trim());
    if (Number.isFinite(raw) && raw > 0) return Math.max(1, Math.round(raw));

    const typeText = String(
      noteEl?.getElementsByTagNameNS("*", "type")[0]?.textContent || ""
    ).trim().toLowerCase();
    const quarterMultByType = {
      longa: 16,
      breve: 8,
      whole: 4,
      half: 2,
      quarter: 1,
      eighth: 0.5,
      "16th": 0.25,
      "32nd": 0.125,
      "64th": 0.0625,
      "128th": 0.03125
    };
    const mult = Number(quarterMultByType[typeText] || 0);
    if (!(mult > 0)) return 0;

    const dots = noteEl.getElementsByTagNameNS("*", "dot").length;
    let dotFactor = 1;
    let add = 0.5;
    for (let i = 0; i < dots; i += 1) {
      dotFactor += add;
      add *= 0.5;
    }

    let tupletFactor = 1;
    const tm = noteEl.getElementsByTagNameNS("*", "time-modification")[0];
    if (tm) {
      const actual = Number((tm.getElementsByTagNameNS("*", "actual-notes")[0]?.textContent || "").trim());
      const normal = Number((tm.getElementsByTagNameNS("*", "normal-notes")[0]?.textContent || "").trim());
      if (Number.isFinite(actual) && actual > 0 && Number.isFinite(normal) && normal > 0) {
        tupletFactor = normal / actual;
      }
    }

    return Math.max(1, Math.round(Number(divisions || 1) * mult * dotFactor * tupletFactor));
  }

  function parseMusicXmlPlaybackEvents(xmlText) {
    const text = String(xmlText || "").trim();
    if (!text) return { events: [], noteSlots: [], tracks: [], playbackTracks: [], timeSlices: [], tempoBpm: 96 };
    const doc = new DOMParser().parseFromString(text, "application/xml");
    if (doc.getElementsByTagName("parsererror").length) {
      return { events: [], noteSlots: [], tracks: [], playbackTracks: [], timeSlices: [], tempoBpm: 96 };
    }

    const tempoBpm = Math.max(20, Math.min(480, Number(parseTempoBpm(doc) || 96)));
    const swing = parseSwingSettings(doc);
    let scoreBeats = 4;
    let scoreBeatType = 4;
    const out = [];
    const noteSlots = [];
    const measureBeatsByIndex = {};
    const measureBeatTypeByIndex = {};
    const measurePulseCountByIndex = {};
    const measureNumbersByIndex = {};
    let scorePulseCount = 4;
    let counterUsesQuarterPulse = null;
    const partMeta = new Map();
    Array.from(doc.getElementsByTagNameNS("*", "score-part")).forEach((scorePartEl, idx) => {
      const id = String(scorePartEl.getAttribute("id") || "").trim() || `P${idx + 1}`;
      const name =
        String(scorePartEl.getElementsByTagNameNS("*", "part-name")[0]?.textContent || "").trim() || id;
      partMeta.set(id, name);
    });
    const tracks = [];
    const playbackTracks = [];
    const playbackTrackById = new Map();
    const partEls = Array.from(doc.getElementsByTagNameNS("*", "part"));
    const firstPartMeasures = Array.from(partEls[0]?.getElementsByTagNameNS("*", "measure") || []);
    const parseEndingNumbers = (value) => {
      const textValue = String(value || "").trim();
      if (!textValue) return [];
      const out = new Set();
      textValue.split(",").forEach((tokenRaw) => {
        const token = String(tokenRaw || "").trim();
        if (!token) return;
        const range = token.match(/^(\d+)\s*-\s*(\d+)$/);
        if (range) {
          const a = Math.max(1, Number(range[1] || 1));
          const b = Math.max(1, Number(range[2] || 1));
          const lo = Math.min(a, b);
          const hi = Math.max(a, b);
          for (let n = lo; n <= hi; n += 1) out.add(n);
          return;
        }
        const single = Number(token);
        if (Number.isFinite(single) && single >= 1) out.add(Math.floor(single));
      });
      return Array.from(out).sort((a, b) => a - b);
    };

    const repeatMetaByMeasure = [];
    let activeEndingNumbers = [];
    firstPartMeasures.forEach((measureEl) => {
      let forward = false;
      let backward = false;
      let backwardTimes = 2;
      let startsEndingNumbers = null;
      let endsEnding = false;
      const barlines = Array.from(measureEl.getElementsByTagNameNS("*", "barline") || []);
      barlines.forEach((barlineEl) => {
        const endings = Array.from(barlineEl.getElementsByTagNameNS("*", "ending") || []);
        endings.forEach((endingEl) => {
          const type = String(endingEl.getAttribute("type") || "").trim().toLowerCase();
          const numbers = parseEndingNumbers(endingEl.getAttribute("number"));
          if (type === "start" && numbers.length) {
            startsEndingNumbers = numbers;
          } else if (type === "stop" || type === "discontinue") {
            endsEnding = true;
          }
        });
        const repeats = Array.from(barlineEl.getElementsByTagNameNS("*", "repeat") || []);
        repeats.forEach((repeatEl) => {
          const direction = String(repeatEl.getAttribute("direction") || "").trim().toLowerCase();
          if (direction === "forward") {
            forward = true;
            return;
          }
          if (direction === "backward") {
            backward = true;
            const timesRaw = Number(String(repeatEl.getAttribute("times") || "").trim());
            if (Number.isFinite(timesRaw) && timesRaw > 1) {
              backwardTimes = Math.max(2, Math.round(timesRaw));
            }
          }
        });
      });
      if (startsEndingNumbers && startsEndingNumbers.length) {
        activeEndingNumbers = startsEndingNumbers.slice();
      }
      const endingNumbers = activeEndingNumbers.slice();
      if (endsEnding) {
        activeEndingNumbers = [];
      }
      repeatMetaByMeasure.push({ forward, backward, backwardTimes, endingNumbers });
    });
    const buildRepeatMeasureOrder = (measureCount, metaByMeasure = []) => {
      const order = [];
      const backwardPasses = {};
      let repeatStart = 0;
      let seenForward = false;
      let repeatPass = 1;
      let guard = 0;
      let i = 0;
      const maxSteps = Math.max(measureCount * 24, 64);
      while (i < measureCount && guard < maxSteps) {
        guard += 1;
        const meta = metaByMeasure[i] || {};
        const endingNumbers = Array.isArray(meta.endingNumbers) ? meta.endingNumbers : [];
        const allowedByEnding =
          !endingNumbers.length || endingNumbers.includes(Math.max(1, Number(repeatPass || 1)));
        if (allowedByEnding) {
          order.push(i);
        }
        if (meta.forward) {
          repeatStart = i;
          seenForward = true;
        }
        if (meta.backward) {
          const times = Math.max(2, Number(meta.backwardTimes || 2));
          const used = Number(backwardPasses[i] || 0);
          if (used < (times - 1)) {
            backwardPasses[i] = used + 1;
            if (!seenForward) repeatStart = 0;
            repeatPass = used + 2;
            i = Math.max(0, repeatStart);
            continue;
          }
        }
        i += 1;
      }
      if (!order.length) {
        for (let mi = 0; mi < measureCount; mi += 1) order.push(mi);
      }
      return order;
    };
    const repeatedMeasureOrder = buildRepeatMeasureOrder(firstPartMeasures.length, repeatMetaByMeasure);
    let sourceIndex = 0;
    const activeTies = new Map();
    const measureDurations = [];
    const measureStartUnits = [0];

    for (let pi = 0; pi < partEls.length; pi += 1) {
      const partEl = partEls[pi];
      const partId = String(partEl.getAttribute("id") || "").trim() || `P${pi + 1}`;
      let declaredStaffCount = 1;
      Array.from(partEl.getElementsByTagNameNS("*", "measure")).forEach((measureEl) => {
        Array.from(measureEl.getElementsByTagNameNS("*", "attributes")).forEach((attributesEl) => {
          const stavesText = String(attributesEl.getElementsByTagNameNS("*", "staves")[0]?.textContent || "").trim();
          const stavesNum = Number(stavesText);
          if (Number.isFinite(stavesNum) && stavesNum > declaredStaffCount) {
            declaredStaffCount = Math.max(1, Math.floor(stavesNum));
          }
        });
        Array.from(measureEl.getElementsByTagNameNS("*", "note")).forEach((noteEl) => {
          const staffText = String(noteEl.getElementsByTagNameNS("*", "staff")[0]?.textContent || "").trim();
          const staffNum = Number(staffText);
          if (Number.isFinite(staffNum) && staffNum > declaredStaffCount) {
            declaredStaffCount = Math.max(1, Math.floor(staffNum));
          }
        });
      });
      const track = {
        id: partId,
        index: pi,
        name: String(partMeta.get(partId) || "").trim() || `Track ${pi + 1}`,
        staffCount: declaredStaffCount,
        events: []
      };
      tracks.push(track);
      const getOrCreatePlaybackTrack = (voiceRaw) => {
        const voice = String(voiceRaw || "1").trim() || "1";
        const id = `${partId}::voice::${voice}`;
        const existing = playbackTrackById.get(id);
        if (existing) return existing;
        const created = {
          id,
          index: playbackTracks.length,
          partId,
          partIndex: pi,
          voice,
          name: `${track.name} ${voice}`,
          events: []
        };
        playbackTracks.push(created);
        playbackTrackById.set(id, created);
        return created;
      };
      let divisions = Math.max(1, XML_PLAYBACK_DIVISION);
      let transposeSemitones = 0;
      let systemIndex = 0;
      let partScoreBeats = Math.max(1, Number(scoreBeats || 4));
      let partScoreBeatType = Math.max(1, Number(scoreBeatType || 4));
      const children = Array.from(partEl.getElementsByTagNameNS("*", "measure"));

      for (let mi = 0; mi < children.length; mi += 1) {
        const measureEl = children[mi];
        if (!(String(mi) in measureNumbersByIndex)) {
          measureNumbersByIndex[String(mi)] = String(measureEl.getAttribute("number") || `${mi + 1}`);
        }
        const prevStart = mi > 0 ? Number(measureStartUnits[mi - 1] || 0) : 0;
        const prevDuration = mi > 0 ? Number(measureDurations[mi - 1] || 0) : 0;
        measureStartUnits[mi] = mi > 0 ? (prevStart + prevDuration) : 0;
        const measureStartUnit = Math.max(0, Number(measureStartUnits[mi] || 0));
        let measureCursor = 0;
        let measureMaxCursor = 0;
        const voiceStarts = new Map();
        const voiceLastDur = new Map();
        let lastChordVoice = "";
        let lastChordStaffIndex = 0;
        let measureHasPitchedNote = false;

        if (mi > 0) {
          const startsNewSystem = Array.from(measureEl.childNodes || []).some((n) => {
            if (!n || n.nodeType !== 1 || localNameOf(n) !== "print") return false;
            const flag = String(n.getAttribute?.("new-system") || "").toLowerCase();
            return flag === "yes";
          });
          if (startsNewSystem) systemIndex += 1;
        }
        const nodes = Array.from(measureEl.childNodes || []).filter((n) => n && n.nodeType === 1);
        for (let ni = 0; ni < nodes.length; ni += 1) {
          const el = nodes[ni];
          const name = localNameOf(el);

          if (name === "attributes") {
            const beatsText = (el.getElementsByTagNameNS("*", "beats")[0]?.textContent || "").trim();
            const nextBeats = Number(beatsText);
            if (Number.isFinite(nextBeats) && nextBeats > 0) {
              partScoreBeats = nextBeats;
              scoreBeats = nextBeats;
            }
            const divText = (el.getElementsByTagNameNS("*", "divisions")[0]?.textContent || "").trim();
            const nextDiv = Number(divText);
            if (Number.isFinite(nextDiv) && nextDiv > 0) divisions = nextDiv;
            const beatTypeText = (el.getElementsByTagNameNS("*", "beat-type")[0]?.textContent || "").trim();
            const nextBeatType = Number(beatTypeText);
            if (Number.isFinite(nextBeatType) && nextBeatType > 0) {
              partScoreBeatType = nextBeatType;
              scoreBeatType = nextBeatType;
            }
            const nextTranspose = getTransposeSemitonesFromAttributes(el);
            if (Number.isFinite(nextTranspose)) transposeSemitones = nextTranspose;
            continue;
          }

          if (name === "backup" || name === "forward") {
            const dur = Number((el.getElementsByTagNameNS("*", "duration")[0]?.textContent || "").trim());
            if (Number.isFinite(dur) && dur > 0) {
              const deltaUnit = Math.max(
                1,
                Math.round((dur * XML_PLAYBACK_DIVISION) / Math.max(1, divisions))
              );
              measureCursor += name === "backup" ? -deltaUnit : deltaUnit;
              if (!Number.isFinite(measureCursor) || measureCursor < 0) measureCursor = 0;
              measureMaxCursor = Math.max(measureMaxCursor, measureCursor);
            }
            lastChordVoice = "";
            lastChordStaffIndex = 0;
            continue;
          }

          if (name !== "note") continue;

          const isGrace = el.getElementsByTagNameNS("*", "grace").length > 0;
          if (isGrace) continue;

          const isRest = el.getElementsByTagNameNS("*", "rest").length > 0;
          const isChord = el.getElementsByTagNameNS("*", "chord").length > 0;
          const rawVoice = (el.getElementsByTagNameNS("*", "voice")[0]?.textContent || "").trim();
          const rawStaffIndex = Number((el.getElementsByTagNameNS("*", "staff")[0]?.textContent || "").trim() || 0) || 0;
          const voice = rawVoice || (isChord && lastChordVoice ? lastChordVoice : "1");
          const staffIndex = Math.max(
            1,
            rawStaffIndex || (isChord && lastChordStaffIndex > 0 ? lastChordStaffIndex : 1)
          );
          lastChordVoice = voice;
          lastChordStaffIndex = staffIndex;
          let durRaw = xmlDurationRawFromNote(el, divisions);
          if (!(Number.isFinite(durRaw) && durRaw > 0) && isChord) {
            const prevDur = Number(voiceLastDur.get(voice) || 0);
            durRaw = Number.isFinite(prevDur) && prevDur > 0 ? prevDur : 0;
          }
          if (!(Number.isFinite(durRaw) && durRaw > 0) && isRest && !isGrace) {
            const prevDur = Number(voiceLastDur.get(voice) || 0);
            durRaw = (Number.isFinite(prevDur) && prevDur > 0)
              ? prevDur
              : Math.max(1, Math.round(divisions * 0.5));
          }
          const dur = Number.isFinite(durRaw) && durRaw > 0
            ? Math.max(1, Math.round((durRaw * XML_PLAYBACK_DIVISION) / Math.max(1, divisions)))
            : 0;
          if (!isChord && durRaw > 0) voiceLastDur.set(voice, durRaw);

          const localStartUnit = isChord ? Number(voiceStarts.get(voice) ?? measureCursor) : measureCursor;
          const startUnit = measureStartUnit + Math.max(0, localStartUnit);
          if (!isChord) voiceStarts.set(voice, localStartUnit);

          if (!isRest) {
            measureHasPitchedNote = true;
            const midi = musicXmlPitchToMidi(el, transposeSemitones);
            const clickMidi = musicXmlPitchToMidi(el, 0);
            if (Number.isFinite(midi)) {
              const currentSourceIndex = sourceIndex;
              const tieEls = Array.from(el.getElementsByTagNameNS("*", "tie"));
              const tiedEls = Array.from(el.getElementsByTagNameNS("*", "tied"));
              const tieTypes = new Set(tieEls.map((node) => String(node.getAttribute("type") || "").toLowerCase()));
              const tiedTypes = new Set(tiedEls.map((node) => String(node.getAttribute("type") || "").toLowerCase()));
              const hasTieStart = tieTypes.has("start") || tiedTypes.has("start");
              const hasTieStop = tieTypes.has("stop") || tiedTypes.has("stop");
              const tieKey = `${partId}::${voice}::${midi}`;
              const durUnit = Math.max(1, dur || Math.round(XML_PLAYBACK_DIVISION * 0.25));
              const playbackTrack = getOrCreatePlaybackTrack(voice);
              let noteXmlSnippet = "";
              try {
                noteXmlSnippet = new XMLSerializer().serializeToString(el);
              } catch {}
              const slot = {
                midi,
                clickMidi: Number.isFinite(clickMidi) ? clickMidi : midi,
                startUnit: Math.max(0, startUnit),
                systemIndex,
                measureIndex: mi,
                measureNumber: String(measureEl.getAttribute("number") || `${mi + 1}`),
                partIndex: pi,
                staffIndex,
                voice,
                sourceIndex: currentSourceIndex,
                playbackSourceIndex: currentSourceIndex,
                xmlSnippet: noteXmlSnippet
              };

              if (hasTieStop && activeTies.has(tieKey)) {
                const active = activeTies.get(tieKey);
                slot.playbackSourceIndex = Number.isFinite(Number(active?.sourceIndex))
                  ? Number(active.sourceIndex)
                  : currentSourceIndex;
                active.durationUnit = Math.max(
                  Number(active.durationUnit || 0),
                  (Math.max(0, startUnit) + durUnit) - Number(active.startUnit || 0)
                );
                if (!hasTieStart) activeTies.delete(tieKey);
              } else if (!hasTieStop || hasTieStart) {
                const ev = {
                  midi,
                  startUnit: Math.max(0, startUnit),
                  durationUnit: durUnit,
                  measureStartUnit,
                  measureLocalStartUnit: Math.max(0, localStartUnit),
                  systemIndex,
                  measureIndex: mi,
                  measureNumber: String(measureEl.getAttribute("number") || `${mi + 1}`),
                  sourceIndex: currentSourceIndex,
                  partIndex: pi,
                  staffIndex,
                  trackId: playbackTrack.id,
                  partTrackId: partId,
                  voice,
                  noteType: String(el.getElementsByTagNameNS("*", "type")[0]?.textContent || "").trim().toLowerCase()
                };
                out.push(ev);
                track.events.push(ev);
                playbackTrack.events.push(ev);
                if (hasTieStart) activeTies.set(tieKey, ev);
              }
              noteSlots.push(slot);
              sourceIndex += 1;
            }
          }

          if (!isChord && dur > 0) {
            measureCursor += dur;
            measureMaxCursor = Math.max(measureMaxCursor, measureCursor);
          }
        }

        const measureKey = String(mi);
        if (!(measureKey in measureBeatsByIndex)) {
          measureBeatsByIndex[measureKey] = Math.max(1, Number(partScoreBeats || 4));
        }
        if (!(measureKey in measureBeatTypeByIndex)) {
          measureBeatTypeByIndex[measureKey] = Math.max(1, Number(partScoreBeatType || 4));
        }
        const quarterPulseCountRaw = (Number(partScoreBeats || 0) * 4) / Math.max(1, Number(partScoreBeatType || 4));
        const expectedMeasureDuration =
          Number.isFinite(quarterPulseCountRaw) && quarterPulseCountRaw > 0
            ? Math.max(1, Math.round(XML_PLAYBACK_DIVISION * quarterPulseCountRaw))
            : 0;
        const finalMeasureDuration = Math.max(
          1,
          Number(measureDurations[mi] || 0),
          Number(measureMaxCursor || 0),
          Number(!measureHasPitchedNote ? (expectedMeasureDuration || 0) : 0)
        );
        const hasIntegerQuarterPulseCount =
          Number.isFinite(quarterPulseCountRaw) &&
          quarterPulseCountRaw > 0 &&
          Math.abs(quarterPulseCountRaw - Math.round(quarterPulseCountRaw)) < 0.0001;
        if (counterUsesQuarterPulse == null) {
          counterUsesQuarterPulse = hasIntegerQuarterPulseCount;
        }
        if (hasIntegerQuarterPulseCount && !(measureKey in measurePulseCountByIndex)) {
          const nextPulseCount = Math.max(1, Math.round(quarterPulseCountRaw));
          measurePulseCountByIndex[measureKey] = nextPulseCount;
          scorePulseCount = nextPulseCount;
        }
        measureDurations[mi] = finalMeasureDuration;
        measureStartUnits[mi + 1] = measureStartUnit + finalMeasureDuration;
      }
    }

    const sortByRenderOrder = (a, b) =>
      Number(a.systemIndex || 0) - Number(b.systemIndex || 0) ||
      Number(a.measureIndex || 0) - Number(b.measureIndex || 0) ||
      Number(a.startUnit || 0) - Number(b.startUnit || 0) ||
      Number(a.partIndex || 0) - Number(b.partIndex || 0) ||
      Number(a.sourceIndex || 0) - Number(b.sourceIndex || 0);
    const sortByPlaybackStart = (a, b) =>
      Number(a.startUnit || 0) - Number(b.startUnit || 0) ||
      Number(a.partIndex || 0) - Number(b.partIndex || 0) ||
      Number(a.midi || 0) - Number(b.midi || 0) ||
      Number(a.sourceIndex || 0) - Number(b.sourceIndex || 0);

    if (repeatedMeasureOrder.length > 1 && repeatedMeasureOrder.some((mi, idx) => mi !== idx)) {
      const measureDurByIndex = measureDurations.map((dur) => Math.max(1, Number(dur || 1)));
      const measureStartByIndex = [];
      let measureCursor = 0;
      for (let mi = 0; mi < measureDurByIndex.length; mi += 1) {
        measureStartByIndex[mi] = measureCursor;
        measureCursor += Math.max(1, Number(measureDurByIndex[mi] || 1));
      }
      const eventsByMeasure = new Map();
      out.forEach((ev) => {
        const mi = Math.max(0, Number(ev.measureIndex || 0));
        if (!eventsByMeasure.has(mi)) eventsByMeasure.set(mi, []);
        eventsByMeasure.get(mi).push(ev);
      });
      const expanded = [];
      let playbackCursor = 0;
      repeatedMeasureOrder.forEach((realMeasureIndex, visitIndex) => {
        const mi = Math.max(0, Number(realMeasureIndex || 0));
        const originalStart = Math.max(0, Number(measureStartByIndex[mi] || 0));
        const duration = Math.max(1, Number(measureDurByIndex[mi] || 1));
        const src = eventsByMeasure.get(mi) || [];
        src.forEach((ev) => {
          const localOffset = Math.max(0, Number(ev.startUnit || 0) - originalStart);
          expanded.push({
            ...ev,
            startUnit: playbackCursor + localOffset,
            measureStartUnit: playbackCursor,
            repeatVisit: visitIndex
          });
        });
        playbackCursor += duration;
      });
      out.length = 0;
      expanded.forEach((ev) => out.push(ev));
      const trackById = new Map(tracks.map((track) => [String(track.id || ""), track]));
      const playbackById = new Map(playbackTracks.map((track) => [String(track.id || ""), track]));
      tracks.forEach((track) => { track.events = []; });
      playbackTracks.forEach((track) => { track.events = []; });
      out.forEach((ev) => {
        const partBucket = trackById.get(String(ev.partTrackId || ""));
        if (partBucket) partBucket.events.push(ev);
        const playbackBucket = playbackById.get(String(ev.trackId || ""));
        if (playbackBucket) playbackBucket.events.push(ev);
      });
    }

    out.sort(sortByPlaybackStart);
    noteSlots.sort(sortByRenderOrder);
    tracks.forEach((track) => {
      track.events.sort(sortByPlaybackStart);
    });
    playbackTracks.forEach((track) => {
      track.events.sort(sortByPlaybackStart);
    });

    applySwingToPlaybackEvents(out, playbackTracks, swing);

    const timeSlices = [];
    out.forEach((ev) => {
      const startUnit = Number(ev.startUnit || 0);
      const last = timeSlices[timeSlices.length - 1];
      if (
        last &&
        Number(last.systemIndex || 0) === Number(ev.systemIndex || 0) &&
        Number(last.measureIndex || 0) === Number(ev.measureIndex || 0) &&
        Math.abs(Number(last.startUnit || 0) - startUnit) < 1e-9
      ) {
        last.notes.push(ev);
        last.durationUnit = Math.max(Number(last.durationUnit || 0), Number(ev.durationUnit || 0));
        return;
      }
      timeSlices.push({
        systemIndex: Number(ev.systemIndex || 0),
        measureIndex: Number(ev.measureIndex || 0),
        measureNumber: String(ev.measureNumber || ""),
        startUnit,
        durationUnit: Math.max(0, Number(ev.durationUnit || 0)),
        notes: [ev]
      });
    });

    return {
      events: out,
      noteSlots,
      tracks,
      playbackTracks,
      timeSlices,
      tempoBpm,
      scoreBeats,
      beatType: scoreBeatType,
      scorePulseCount,
      counterUsesQuarterPulse: counterUsesQuarterPulse !== false,
      measureBeatsByIndex,
      measureBeatTypeByIndex,
      measurePulseCountByIndex,
      measureNumbersByIndex,
      measureDurationTickByIndex: Object.fromEntries(
        measureDurations.map((duration, idx) => [String(idx), Math.max(1, Number(duration || 0))])
      ),
      repeatMeasureOrder: repeatedMeasureOrder,
      division: XML_PLAYBACK_DIVISION
    };
  }

  function buildMidiFromPlaybackModel(parsed) {
    const srcRaw = (Array.isArray(parsed?.events) ? parsed.events : [])
      .map((ev) => {
        const startTick = Math.max(0, Math.floor(Number(ev?.startUnit || 0)));
        const durationTick = Math.max(1, Math.floor(Number(ev?.durationUnit || 1)));
        const endTick = startTick + durationTick;
        const noteNumber = Math.max(0, Math.min(127, Math.floor(Number(ev?.midi || 0))));
        const channel = Math.max(1, Math.min(16, Math.floor(Number(ev?.partIndex || 0)) + 1));
        return {
          startTick,
          endTick,
          noteNumber,
          channel,
          sourceIndex: Number(ev?.sourceIndex),
          trackId: String(ev?.trackId || ""),
          measureIndex: Number(ev?.measureIndex || 0)
        };
      })
      .filter((ev) => ev.endTick > ev.startTick)
      .sort((a, b) =>
        Number(a.startTick || 0) - Number(b.startTick || 0) ||
        Number(a.channel || 1) - Number(b.channel || 1) ||
        Number(a.noteNumber || 0) - Number(b.noteNumber || 0) ||
        Number(a.sourceIndex || 0) - Number(b.sourceIndex || 0)
      );
    if (!srcRaw.length) return null;

    const src = [];
    for (let i = 0; i < srcRaw.length; i += 1) {
      const ev = srcRaw[i];
      const prev = src[src.length - 1];
      if (
        prev &&
        Number(prev.channel || 1) === Number(ev.channel || 1) &&
        Number(prev.noteNumber || 0) === Number(ev.noteNumber || 0) &&
        Number(ev.startTick || 0) < Number(prev.endTick || 0)
      ) {
        prev.endTick = Math.max(Number(prev.endTick || 0), Number(ev.endTick || 0));
        continue;
      }
      if (
        prev &&
        Number(prev.channel || 1) === Number(ev.channel || 1) &&
        Number(prev.noteNumber || 0) === Number(ev.noteNumber || 0) &&
        Number(ev.startTick || 0) === Number(prev.endTick || 0)
      ) {
        const prevStartTick = Math.max(0, Number(prev.startTick || 0));
        const prevEndTick = Math.max(prevStartTick + 1, Number(prev.endTick || 0));
        if ((prevEndTick - prevStartTick) > 1) {
          prev.endTick = prevEndTick - 1;
        }
      }
      src.push({ ...ev });
    }
    if (!src.length) return null;

    const firstOnTick = Math.max(0, Number(src[0]?.startTick || 0));
    const sourceIndexToStartTick = {};
    const pendingStartByKey = new Map();
    const events = [];

    src.forEach((ev) => {
      const startTick = Math.max(0, Number(ev.startTick || 0) - firstOnTick);
      const endTick = Math.max(startTick + 1, Number(ev.endTick || 0) - firstOnTick);
      const channel = Math.max(1, Math.min(16, Number(ev.channel || 1)));
      const noteNumber = Math.max(0, Math.min(127, Number(ev.noteNumber || 0)));
      const startKey = `${channel}:${noteNumber}:${startTick}`;
      const startMeta = {
        sourceIndex: Number(ev.sourceIndex),
        trackId: String(ev.trackId || ""),
        measureIndex: Number(ev.measureIndex || 0),
        noteNumber,
        channel,
        startTick
      };
      if (Number.isFinite(startMeta.sourceIndex) && !Object.prototype.hasOwnProperty.call(sourceIndexToStartTick, String(startMeta.sourceIndex))) {
        sourceIndexToStartTick[String(startMeta.sourceIndex)] = startTick;
      }
      if (!pendingStartByKey.has(startKey)) pendingStartByKey.set(startKey, []);
      pendingStartByKey.get(startKey).push(startMeta);
      events.push({ tick: startTick, type: "on", ch: channel - 1, nn: noteNumber, vel: 96 });
      events.push({ tick: endTick, type: "off", ch: channel - 1, nn: noteNumber, vel: 0 });
    });

    events.sort((a, b) =>
      Number(a.tick || 0) - Number(b.tick || 0) ||
      (a.type === "off" ? -1 : 1) ||
      Number(a.ch || 0) - Number(b.ch || 0) ||
      Number(a.nn || 0) - Number(b.nn || 0)
    );

    const quarterTempoBpm = window.twMusicXmlPlay?.getQuarterTempoBpm?.(parsed) || Math.max(20, Number(parsed?.tempoBpm || 96));
    const tempoUs = Math.max(1, Math.round(60000000 / quarterTempoBpm));
    const track = [
      0x00, 0xff, 0x51, 0x03,
      (tempoUs >>> 16) & 0xff,
      (tempoUs >>> 8) & 0xff,
      tempoUs & 0xff
    ];
    for (let ch = 0; ch < 16; ch += 1) {
      track.push(0x00, 0xc0 | ch, 0x00);
    }

    let lastTick = 0;
    events.forEach((ev) => {
      const tick = Math.max(0, Number(ev.tick || 0));
      const delta = Math.max(0, tick - lastTick);
      lastTick = tick;
      track.push(...encodeVlq(delta));
      if (ev.type === "on") track.push(0x90 | ev.ch, ev.nn, ev.vel > 0 ? ev.vel : 88);
      else track.push(0x80 | ev.ch, ev.nn, 0x00);
    });
    for (let ch = 0; ch < 16; ch += 1) {
      track.push(0x00, 0xb0 | ch, 64, 0);
      track.push(0x00, 0xb0 | ch, 123, 0);
    }
    track.push(0x00, 0xff, 0x2f, 0x00);

    const division = Math.max(1, Math.min(32767, Math.floor(Number(parsed?.division || XML_PLAYBACK_DIVISION))));
    const header = [
      0x4d, 0x54, 0x68, 0x64,
      0x00, 0x00, 0x00, 0x06,
      0x00, 0x00,
      0x00, 0x01,
      ...u16be(division)
    ];
    const mtrk = [0x4d, 0x54, 0x72, 0x6b, ...u32be(track.length), ...track];
    return {
      bytes: new Uint8Array([...header, ...mtrk]),
      pendingStartByKey,
      sourceIndexToStartTick,
      totalTicks: lastTick,
      tempoBpm: quarterTempoBpm
    };
  }

  function getXmlTrackStateStore() {
    if (!window._twXmlTrackStatesBySurrogate || typeof window._twXmlTrackStatesBySurrogate !== "object") {
      window._twXmlTrackStatesBySurrogate = {};
    }
    return window._twXmlTrackStatesBySurrogate;
  }

  function ensureTrackPlaybackStates(surrogate, tracks = []) {
    const safeSurrogate = String(surrogate || "").trim();
    const store = getXmlTrackStateStore();
    if (!safeSurrogate) return [];
    if (!store[safeSurrogate] || typeof store[safeSurrogate] !== "object") {
      store[safeSurrogate] = {};
    }
    const bucket = store[safeSurrogate];
    const out = [];
    tracks.forEach((track, idx) => {
      const key = String(track?.id || `track-${idx + 1}`);
      const existing = bucket[key] || {};
      bucket[key] = {
        id: key,
        name: String(track?.name || existing.name || key),
        mute: !!existing.mute,
        volume: Math.max(0, Math.min(1, Number(existing.volume ?? 1) || 1))
      };
      out.push(bucket[key]);
    });
    return out;
  }

  function getKnownTracksForSurrogate(surrogate) {
    const safeSurrogate = String(surrogate || "").trim();
    if (!safeSurrogate) return [];
    const getPlaybackTracks = (model) =>
      Array.isArray(model?.playbackTracks) && model.playbackTracks.length
        ? model.playbackTracks
        : (Array.isArray(model?.tracks) ? model.tracks : []);
    if (
      getPlaybackTracks(xmlPlaybackState?.model).length &&
      String(xmlPlaybackState.surrogate || "") === safeSurrogate
    ) {
      return getPlaybackTracks(xmlPlaybackState.model);
    }
    const viewer = document.getElementById("pdfTabXmlViewer");
    if (
      getPlaybackTracks(viewer?._twXmlPlaybackModel).length &&
      String(window._pdfXmlViewState?.surrogate || "") === safeSurrogate
    ) {
      return getPlaybackTracks(viewer._twXmlPlaybackModel);
    }
    return [];
  }

  function getTrackStateLookup(surrogate, tracks = []) {
    const states = ensureTrackPlaybackStates(surrogate, tracks);
    const lookup = new Map();
    states.forEach((state) => {
      lookup.set(String(state.id || ""), state);
    });
    return lookup;
  }

  function clampXmlZoom(value) {
    return Math.max(0.6, Math.min(3.2, Number(value) || 1));
  }

  function clampXmlLayoutScale(value) {
    const n = Number(value);
    if (!Number.isFinite(n)) return XML_LAYOUT_SCALE_DEFAULT;
    return Math.max(XML_LAYOUT_SCALE_MIN, Math.min(XML_LAYOUT_SCALE_MAX, n));
  }

  function getXmlLayoutScale() {
    try {
      return clampXmlLayoutScale(localStorage.getItem(XML_LAYOUT_SCALE_STORAGE_KEY) || XML_LAYOUT_SCALE_DEFAULT);
    } catch {
      return XML_LAYOUT_SCALE_DEFAULT;
    }
  }

  function setStoredXmlLayoutScale(value) {
    const next = clampXmlLayoutScale(value);
    try {
      localStorage.setItem(XML_LAYOUT_SCALE_STORAGE_KEY, String(next));
    } catch {}
    return next;
  }

  function getXmlLayoutWidthForScale(scale) {
    return Math.max(280, Math.round(XML_LAYOUT_BASE_WIDTH * clampXmlLayoutScale(scale)));
  }

  function clampXmlRenderTransposeSemitones(value) {
    const numeric = Math.round(Number(value) || 0);
    return Math.max(XML_RENDER_TRANSPOSE_MIN, Math.min(XML_RENDER_TRANSPOSE_MAX, numeric));
  }

  function getXmlRenderTransposeSemitones() {
    return clampXmlRenderTransposeSemitones(window._twXmlRenderTransposeSemitones || 0);
  }

  function setStoredXmlRenderTransposeSemitones(value) {
    const next = clampXmlRenderTransposeSemitones(value);
    window._twXmlRenderTransposeSemitones = next;
    return next;
  }

  function getXmlViewportWidth(host) {
    if (!host) return 0;
    const viewport = host.querySelector?.("[data-tw-xml-zoom-viewport='1']");
    return Math.max(0, Number(viewport?.clientWidth || host.clientWidth || 0));
  }

  function getXmlRenderWidth(host, scale) {
    void host;
    return getXmlLayoutWidthForScale(scale);
  }

  function asPlainObject(value) {
    if (!value || typeof value !== "object" || Array.isArray(value)) return {};
    return value;
  }

  function sanitizeOsmdOptions(value) {
    const input = asPlainObject(value);
    const out = {};
    Object.entries(input).forEach(([key, raw]) => {
      if (!String(key || "").trim()) return;
      if (typeof raw === "function" || typeof raw === "undefined") return;
      if (raw && typeof raw === "object") {
        try {
          out[key] = JSON.parse(JSON.stringify(raw));
        } catch {
          out[key] = null;
        }
        return;
      }
      out[key] = raw;
    });
    return out;
  }

  function getDefaultOsmdRenderOptions() {
    return { ...XML_OSMD_DEFAULT_RENDER_OPTIONS };
  }

  function getStoredOsmdRenderOptions() {
    try {
      const raw = localStorage.getItem(XML_OSMD_OPTIONS_STORAGE_KEY);
      if (!raw) return {};
      return sanitizeOsmdOptions(JSON.parse(raw));
    } catch {
      return {};
    }
  }

  function getResolvedOsmdRenderOptions() {
    return {
      ...getDefaultOsmdRenderOptions(),
      ...getStoredOsmdRenderOptions()
    };
  }

  function applyOsmdVisualTranspose(osmd, semitones = 0) {
    const transpose = clampXmlRenderTransposeSemitones(semitones);
    if (!transpose) return;
    const calculatorCtor = window.opensheetmusicdisplay?.TransposeCalculator;
    const sheet = osmd?.Sheet || osmd?.sheet || null;
    if (!sheet || typeof calculatorCtor !== "function") return;
    try {
      if (!osmd.TransposeCalculator) {
        osmd.TransposeCalculator = new calculatorCtor();
      }
      sheet.Transpose = transpose;
      if (typeof osmd.updateGraphic === "function") {
        osmd.updateGraphic();
      }
    } catch (err) {
      console.warn("MusicXML visual transpose failed:", err);
    }
  }

  function getOsmdSettingsCatalog() {
    return XML_OSMD_SETTINGS_CATALOG.map((item) => ({ ...item }));
  }

  function setStoredOsmdRenderOptions(options, opts = {}) {
    const merge = opts?.merge !== false;
    const base = merge ? getStoredOsmdRenderOptions() : {};
    const next = sanitizeOsmdOptions({
      ...base,
      ...asPlainObject(options)
    });
    try {
      localStorage.setItem(XML_OSMD_OPTIONS_STORAGE_KEY, JSON.stringify(next));
    } catch {}
    return next;
  }

  function resetStoredOsmdRenderOptions() {
    try {
      localStorage.removeItem(XML_OSMD_OPTIONS_STORAGE_KEY);
    } catch {}
    return {};
  }

  function getXmlRenderHostWidth(host) {
    if (!host) return 0;
    const viewport = host.querySelector?.("[data-tw-xml-zoom-viewport='1']");
    const hostRectWidth = Number(host.getBoundingClientRect?.().width || 0);
    const viewportRectWidth = Number(viewport?.getBoundingClientRect?.().width || 0);
    return Math.max(
      0,
      Number(viewport?.clientWidth || 0),
      Number(host.clientWidth || 0),
      viewportRectWidth,
      hostRectWidth
    );
  }

  function waitForNextAnimationFrame() {
    return new Promise((resolve) => {
      if (typeof requestAnimationFrame === "function") {
        requestAnimationFrame(() => resolve());
        return;
      }
      setTimeout(resolve, 16);
    });
  }

  function waitMs(ms) {
    return new Promise((resolve) => setTimeout(resolve, Math.max(0, Number(ms) || 0)));
  }

  async function waitForXmlRenderHostWidth(host, opts = {}) {
    if (!host) return 0;
    const timeoutMs = Math.max(0, Number(opts.timeoutMs || 0) || 900);
    const minWidth = Math.max(1, Number(opts.minWidth || 0) || 32);
    const now = () => (typeof performance !== "undefined" && typeof performance.now === "function")
      ? performance.now()
      : Date.now();
    const startedAt = now();
    while ((now() - startedAt) <= timeoutMs) {
      const width = getXmlRenderHostWidth(host);
      const isVisible = !!(host.isConnected && host.getClientRects?.().length);
      if (isVisible && width > minWidth) return width;
      await waitForNextAnimationFrame();
    }
    return getXmlRenderHostWidth(host);
  }

  function measureXmlNaturalWidth(host) {
    if (!host) return 0;
    const renderRoot = host.querySelector?.("[data-tw-xml-render-root='1']");
    const svg = renderRoot?.querySelector?.("svg");
    let width = 0;
    if (svg?.viewBox?.baseVal?.width) {
      width = Number(svg.viewBox.baseVal.width || 0);
    }
    if (!(width > 0) && typeof svg?.getBBox === "function") {
      try {
        const box = svg.getBBox();
        width = Number(box?.width || 0);
      } catch {}
    }
    if (!(width > 0)) {
      width = Number(renderRoot?.scrollWidth || renderRoot?.clientWidth || 0);
    }
    return width > 0 ? width : 0;
  }

  function updateXmlFitScale(host) {
    if (!host) return 1;
    const naturalWidth = measureXmlNaturalWidth(host);
    const availableWidth = Math.max(0, getXmlViewportWidth(host));
    const fitScale = (naturalWidth > 0 && availableWidth > 0)
      ? Math.min(1, availableWidth / naturalWidth)
      : 1;
    host._twXmlFitScale = Math.max(0.18, Math.min(1, fitScale || 1));
    return host._twXmlFitScale;
  }

  function applyXmlZoom(host, nextZoom) {
    if (!host) return 1;
    const userZoom = clampXmlZoom(nextZoom);
    const fitScale = updateXmlFitScale(host);
    const zoom = Math.max(0.18, Math.min(4, fitScale * userZoom));
    const surface = host.querySelector?.("[data-tw-xml-zoom-surface='1']");
    const label = host.querySelector?.("[data-tw-xml-zoom-label='1']");
    host.dataset.twXmlZoom = String(userZoom);
    if (surface) {
      surface.style.transformOrigin = "top left";
      surface.style.transform = `scale(${zoom})`;
      surface.style.width = `${100 / zoom}%`;
      surface.style.height = "";
    }
    if (label) {
      label.textContent = `${Math.round(userZoom * 100)}%`;
    }
    return zoom;
  }

  function attachXmlZoomControls(host) {
    if (!host || host.dataset.twXmlZoomBound === "1") return;
    host.dataset.twXmlZoomBound = "1";

    const setZoom = (value) => applyXmlZoom(host, value);
    const viewport = host.querySelector?.("[data-tw-xml-zoom-viewport='1']");
    const pinchPointers = new Map();
    let pinchState = null;

    const getPointerDistance = () => {
      if (pinchPointers.size < 2) return 0;
      const points = Array.from(pinchPointers.values());
      const a = points[0];
      const b = points[1];
      return Math.hypot(
        Number(b?.x || 0) - Number(a?.x || 0),
        Number(b?.y || 0) - Number(a?.y || 0)
      );
    };

    const clearPinchFrame = () => {
      if (!host._twXmlPinchFrame) return;
      cancelAnimationFrame(host._twXmlPinchFrame);
      host._twXmlPinchFrame = 0;
    };

    const resetPinchState = () => {
      pinchState = null;
      clearPinchFrame();
    };

    const schedulePinchZoom = () => {
      if (!pinchState || host._twXmlPinchFrame) return;
      host._twXmlPinchFrame = requestAnimationFrame(() => {
        host._twXmlPinchFrame = 0;
        if (!pinchState) return;
        const distance = getPointerDistance();
        if (!(distance > 0) || !(pinchState.distance > 0)) return;
        const ratio = distance / pinchState.distance;
        setZoom(pinchState.zoom * ratio);
      });
    };

    host.querySelectorAll("[data-tw-xml-zoom-action]").forEach((btn) => {
      btn.addEventListener("click", (event) => {
        event.preventDefault();
        event.stopPropagation();
        const action = String(btn.dataset.twXmlZoomAction || "");
        const current = clampXmlZoom(Number(host.dataset.twXmlZoom || 1));
        if (action === "in") {
          setZoom(current + 0.15);
          return;
        }
        if (action === "out") {
          setZoom(current - 0.15);
          return;
        }
        setZoom(1);
      });
    });

    const reflowZoom = () => {
      if (host._twXmlResizeFrame) cancelAnimationFrame(host._twXmlResizeFrame);
      host._twXmlResizeFrame = requestAnimationFrame(() => {
        host._twXmlResizeFrame = 0;
        applyXmlZoom(host, Number(host.dataset.twXmlZoom || 1));
      });
    };

    host.addEventListener("wheel", (event) => {
      if (!event.ctrlKey && !event.metaKey) return;
      event.preventDefault();
      const current = clampXmlZoom(Number(host.dataset.twXmlZoom || 1));
      const delta = Number(event.deltaY || 0);
      const next = current + (delta < 0 ? 0.12 : -0.12);
      setZoom(next);
    }, { passive: false });

    if (viewport && typeof window.PointerEvent !== "undefined") {
      viewport.style.touchAction = "pan-x pan-y";

      viewport.addEventListener("pointerdown", (event) => {
        if (event.pointerType !== "touch") return;
        pinchPointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
        if (pinchPointers.size === 2) {
          pinchState = {
            distance: getPointerDistance(),
            zoom: clampXmlZoom(Number(host.dataset.twXmlZoom || 1))
          };
        }
      });

      viewport.addEventListener("pointermove", (event) => {
        if (!pinchPointers.has(event.pointerId)) return;
        pinchPointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
        if (pinchPointers.size < 2) return;
        if (!pinchState) {
          pinchState = {
            distance: getPointerDistance(),
            zoom: clampXmlZoom(Number(host.dataset.twXmlZoom || 1))
          };
        }
        event.preventDefault();
        schedulePinchZoom();
      });

      const releasePointer = (event) => {
        if (!pinchPointers.has(event.pointerId)) return;
        pinchPointers.delete(event.pointerId);
        if (pinchPointers.size < 2) {
          resetPinchState();
          return;
        }
        pinchState = {
          distance: getPointerDistance(),
          zoom: clampXmlZoom(Number(host.dataset.twXmlZoom || 1))
        };
      };

      viewport.addEventListener("pointerup", releasePointer);
      viewport.addEventListener("pointercancel", releasePointer);
      viewport.addEventListener("pointerleave", releasePointer);
    }

    if (typeof ResizeObserver !== "undefined") {
      host._twXmlResizeObserver?.disconnect?.();
      host._twXmlResizeObserver = new ResizeObserver(() => reflowZoom());
      if (viewport) host._twXmlResizeObserver.observe(viewport);
      host._twXmlResizeObserver.observe(host);
    } else {
      window.addEventListener("resize", reflowZoom);
    }
  }

  function normalizeRenderedXmlTitle(container) {
    if (!container) return;
    const fallbackTitle = getItemTitleForSurrogate(window.currentSurrogate);
    if (!fallbackTitle) return;
    const svg = container.querySelector?.("svg");
    if (!svg) return;
    const textNodes = Array.from(svg.querySelectorAll("text"));
    textNodes.forEach((node) => {
      const text = String(node.textContent || "").replace(/\s+/g, " ").trim();
      if (text !== "Untitled Score") return;
      node.textContent = fallbackTitle;
    });
  }

  function extractXmlDisplayMeta(xmlText) {
    const text = String(xmlText || "").trim();
    if (!text) return { composer: "", arranger: "", electronic: "" };
    const doc = new DOMParser().parseFromString(text, "application/xml");
    if (doc.getElementsByTagName("parsererror").length) return { composer: "", arranger: "", electronic: "" };
    const creators = Array.from(doc.getElementsByTagNameNS("*", "creator"));
    const composer = String(
      creators.find((node) => String(node.getAttribute("type") || "").trim().toLowerCase() === "composer")?.textContent || ""
    ).trim();
    const arranger = String(
      creators.find((node) => String(node.getAttribute("type") || "").trim().toLowerCase() === "arranger")?.textContent || ""
    ).trim();
    const rights = String(doc.getElementsByTagNameNS("*", "rights")[0]?.textContent || "").trim();
    const electronicFromRights = String(rights.match(/electronic scores?\s+by\s+([^\n\r;]+)/i)?.[0] || "").trim();
    const electronicFromCredit = String(
      Array.from(doc.getElementsByTagNameNS("*", "credit")).find((creditEl) => {
        const page = String(creditEl.getAttribute("page") || "1").trim();
        if (page !== "1") return false;
        const creditType = String(
          creditEl.getElementsByTagNameNS("*", "credit-type")[0]?.textContent || ""
        ).trim().toLowerCase();
        return creditType === "rights";
      })?.getElementsByTagNameNS("*", "credit-words")[0]?.textContent || ""
    );
    const electronic = electronicFromRights || String(electronicFromCredit.match(/electronic scores?\s+by\s+([^\n\r;]+)/i)?.[0] || "").trim();
    return { composer, arranger, electronic };
  }

  function augmentRenderedXmlCredits(renderRoot, xmlText) {
    if (!renderRoot) return;
    const svg = renderRoot.querySelector?.("svg");
    if (!svg) return;
    const meta = extractXmlDisplayMeta(xmlText);
    if (!meta.arranger && !meta.electronic) return;

    const texts = Array.from(svg.querySelectorAll("text"));
    const allText = texts.map((n) => String(n.textContent || "").toLowerCase()).join(" ");
    const vb = svg.viewBox?.baseVal;
    const width = Number(vb?.width || svg.clientWidth || 1200);
    const height = Number(vb?.height || svg.clientHeight || 1600);
    const minX = Number(vb?.x || 0);
    const minY = Number(vb?.y || 0);

    if (meta.arranger && !allText.includes(String(meta.arranger).toLowerCase())) {
      const composerLower = String(meta.composer || "").toLowerCase();
      const composerHost = texts.find((el) => String(el.textContent || "").trim().toLowerCase() === composerLower) || null;
      const topTexts = texts
        .map((el) => ({ el, x: Number(el.getAttribute("x") || 0), y: Number(el.getAttribute("y") || 0) }))
        .filter((entry) => Number.isFinite(entry.y) && entry.y <= (minY + 240))
        .sort((a, b) => b.x - a.x || a.y - b.y);
      const host = composerHost || topTexts[0]?.el || null;
      if (host) {
        const tspan = document.createElementNS("http://www.w3.org/2000/svg", "tspan");
        tspan.setAttribute("x", host.getAttribute("x") || String(minX + width - 36));
        tspan.setAttribute("dy", "1.1em");
        tspan.setAttribute("font-style", "italic");
        tspan.setAttribute("font-size", "0.85em");
        tspan.textContent = `Arranger: ${meta.arranger}`;
        host.appendChild(tspan);
      } else {
        const t = document.createElementNS("http://www.w3.org/2000/svg", "text");
        t.setAttribute("x", String(minX + width - 36));
        t.setAttribute("y", String(minY + 120));
        t.setAttribute("text-anchor", "end");
        t.setAttribute("font-size", "26");
        t.setAttribute("font-style", "italic");
        t.textContent = `Arranger: ${meta.arranger}`;
        svg.appendChild(t);
      }
    }

    if (meta.electronic && !allText.includes("electronic score")) {
      const footer = document.createElementNS("http://www.w3.org/2000/svg", "text");
      footer.setAttribute("x", String(minX + (width / 2)));
      footer.setAttribute("y", String(minY + height - 24));
      footer.setAttribute("text-anchor", "middle");
      footer.setAttribute("font-size", "12");
      footer.setAttribute("fill", "#334155");
      footer.textContent = meta.electronic;
      svg.appendChild(footer);
    }
  }

  function trimRenderedXmlSvgMargins(container) {
    void container;
    return false;
  }

  function getXmlNoteSourcePanel() {
    let panel = document.getElementById("twXmlNoteSourcePanel");
    if (panel) return panel;

    panel = document.createElement("div");
    panel.id = "twXmlNoteSourcePanel";
    panel.style.position = "fixed";
    panel.style.left = "50%";
    panel.style.top = "50%";
    panel.style.transform = "translate(-50%, -50%)";
    panel.style.width = "min(680px, calc(100vw - 24px))";
    panel.style.maxHeight = "min(72vh, 760px)";
    panel.style.background = "#0b1020";
    panel.style.color = "#e5e7eb";
    panel.style.border = "1px solid #334155";
    panel.style.borderRadius = "10px";
    panel.style.boxShadow = "0 12px 40px rgba(2,6,23,0.45)";
    panel.style.zIndex = "2147483000";
    panel.style.display = "none";
    panel.style.overflow = "hidden";
    panel.innerHTML = `
      <div id="twXmlNoteSourceDragHandle" style="display:flex; align-items:center; justify-content:space-between; gap:8px; padding:10px 12px; border-bottom:1px solid #334155; background:#111827;">
        <div>
          <div id="twXmlNoteSourceTitle" style="font:600 13px system-ui, -apple-system, Segoe UI, sans-serif; color:#f8fafc;">MusicXML note source</div>
          <div id="twXmlNoteSourceSub" style="margin-top:2px; font:500 11px system-ui, -apple-system, Segoe UI, sans-serif; color:#93c5fd;"></div>
        </div>
        <div style="display:flex; gap:8px; align-items:center;">
          <button id="twXmlStaffTextEdit" type="button" style="border:1px solid #1e3a8a; border-radius:6px; padding:4px 8px; background:#1d4ed8; color:#eff6ff; cursor:pointer;">Edit staff text</button>
          <button id="twXmlNoteSourceUndo" type="button" style="border:1px solid #1e40af; border-radius:6px; padding:4px 8px; background:#1e3a8a; color:#dbeafe; cursor:pointer;">Undo</button>
          <button id="twXmlNoteSourceSave" type="button" style="border:1px solid #14532d; border-radius:6px; padding:4px 8px; background:#166534; color:#ecfdf5; cursor:pointer;">Save</button>
          <button id="twXmlNoteSourceClose" type="button" style="border:1px solid #475569; border-radius:6px; padding:4px 8px; background:#0f172a; color:#e2e8f0; cursor:pointer;">Close</button>
        </div>
      </div>
      <div style="padding:10px 12px; max-height:calc(min(72vh, 760px) - 56px); overflow:auto;">
        <div id="twXmlNoteSourceStatus" style="display:none; margin:0 0 8px 0; font:500 11px system-ui, -apple-system, Segoe UI, sans-serif;"></div>
        <textarea id="twXmlNoteSourceCode" spellcheck="false" style="width:100%; min-height:260px; resize:vertical; border:1px solid #334155; border-radius:8px; padding:10px; background:#111827; color:#e5e7eb; font:12px/1.45 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;"></textarea>
      </div>
    `;
    document.body.appendChild(panel);
    makeFloatingPanelDraggable(panel, panel.querySelector("#twXmlNoteSourceDragHandle"));
    panel.querySelector("#twXmlNoteSourceClose")?.addEventListener("click", () => {
      panel.style.display = "none";
    });
    return panel;
  }

  function getLyricSyllabicType(lyricEl) {
    if (!lyricEl) return "";
    const node = lyricEl.getElementsByTagNameNS("*", "syllabic")[0] || null;
    return String(node?.textContent || "").trim().toLowerCase();
  }

  function setLyricSyllabicType(doc, lyricEl, type = "") {
    if (!doc || !lyricEl) return;
    const safeType = String(type || "").trim().toLowerCase();
    const existing = lyricEl.getElementsByTagNameNS("*", "syllabic")[0] || null;
    if (!safeType) {
      if (existing?.parentNode) existing.parentNode.removeChild(existing);
      return;
    }
    const target = existing || doc.createElement("syllabic");
    target.textContent = safeType;
    if (!existing) {
      const textEl = lyricEl.getElementsByTagNameNS("*", "text")[0] || null;
      if (textEl?.parentNode === lyricEl) {
        lyricEl.insertBefore(target, textEl);
      } else {
        lyricEl.appendChild(target);
      }
    }
  }

  function collectPartLyricNumbers(doc, partIndex = 0) {
    const safePartIndex = Math.max(0, Number(partIndex || 0));
    const partEls = Array.from(doc.getElementsByTagNameNS("*", "part"));
    const partEl = partEls[safePartIndex] || null;
    if (!partEl) return [];
    const numbers = new Set();
    const measures = Array.from(partEl.getElementsByTagNameNS("*", "measure"));
    measures.forEach((measureEl) => {
      const nodes = Array.from(measureEl.childNodes || []).filter((n) => n && n.nodeType === 1);
      nodes.forEach((node) => {
        if (localNameOf(node) !== "note") return;
        if (node.getElementsByTagNameNS("*", "grace").length) return;
        if (node.getElementsByTagNameNS("*", "rest").length) return;
        const lyricEls = Array.from(node.getElementsByTagNameNS("*", "lyric") || []);
        lyricEls.forEach((lyricEl) => {
          const textEl = lyricEl.getElementsByTagNameNS("*", "text")[0] || null;
          if (!textEl) return;
          const lyricNumber = String(lyricEl.getAttribute("number") || "1").trim() || "1";
          numbers.add(lyricNumber);
        });
      });
    });
    return Array.from(numbers).sort((a, b) => {
      const na = Number(a);
      const nb = Number(b);
      if (Number.isFinite(na) && Number.isFinite(nb)) return na - nb;
      return String(a).localeCompare(String(b), undefined, { numeric: true, sensitivity: "base" });
    });
  }

  function collectPartLyricEntries(doc, partIndex = 0, lyricNumber = "1") {
    const safePartIndex = Math.max(0, Number(partIndex || 0));
    const safeLyricNumber = String(lyricNumber || "1").trim() || "1";
    const partEls = Array.from(doc.getElementsByTagNameNS("*", "part"));
    const partEl = partEls[safePartIndex] || null;
    if (!partEl) return [];
    const out = [];
    const measures = Array.from(partEl.getElementsByTagNameNS("*", "measure"));
    measures.forEach((measureEl) => {
      const nodes = Array.from(measureEl.childNodes || []).filter((n) => n && n.nodeType === 1);
      nodes.forEach((node) => {
        if (localNameOf(node) !== "note") return;
        if (node.getElementsByTagNameNS("*", "grace").length) return;
        if (node.getElementsByTagNameNS("*", "rest").length) return;
        const lyricEls = Array.from(node.getElementsByTagNameNS("*", "lyric") || []);
        const lyricEl = lyricEls.find((entry) => (String(entry.getAttribute("number") || "1").trim() || "1") === safeLyricNumber) || null;
        if (!lyricEl) return;
        const textEl = lyricEl.getElementsByTagNameNS("*", "text")[0] || null;
        if (!textEl) return;
        out.push({
          noteEl: node,
          lyricEl,
          textEl,
          lyricNumber: safeLyricNumber,
          measureNumber: String(measureEl.getAttribute("number") || "").trim(),
          durationRaw: Number(String(node.getElementsByTagNameNS("*", "duration")[0]?.textContent || "").trim()) || 0,
          noteType: String(node.getElementsByTagNameNS("*", "type")[0]?.textContent || "").trim().toLowerCase()
        });
      });
    });
    return out;
  }

  function collectPartLyricGroups(doc, partIndex = 0) {
    const lyricNumbers = collectPartLyricNumbers(doc, partIndex);
    return lyricNumbers
      .map((lyricNumber) => {
        const lyricEntries = collectPartLyricEntries(doc, partIndex, lyricNumber);
        if (!lyricEntries.length) return null;
        return {
          lyricNumber: String(lyricNumber || "1"),
          lyricEntries,
          initialTokens: lyricEntries.map((entry) => String(entry?.textEl?.textContent || "")),
          initialSyllabics: lyricEntries.map((entry) => getLyricSyllabicType(entry?.lyricEl))
        };
      })
      .filter(Boolean);
  }

  function resolveRenderedLyricLaneNumber(container, textNode, partIndex = 0, clientY = 0) {
    const rows = Array.isArray(container?._twXmlStafflineEntries) ? container._twXmlStafflineEntries : [];
    if (!rows.length || !textNode || typeof textNode.getBoundingClientRect !== "function") return "1";
    const targetY = Number(clientY || 0) || (() => {
      const rect = textNode.getBoundingClientRect();
      return Number(rect.top || 0) + (Number(rect.height || 0) / 2);
    })();
    const samePartRows = rows
      .filter((row) => Math.max(0, Number(row?.partIndex || 0)) === Math.max(0, Number(partIndex || 0)))
      .slice()
      .sort((a, b) => Number(a?.centerY || 0) - Number(b?.centerY || 0));
    if (!samePartRows.length) return "1";
    let bestRow = samePartRows[0];
    let bestRowDist = Infinity;
    samePartRows.forEach((row) => {
      const rowEl = row?.el;
      if (!rowEl || typeof rowEl.getBoundingClientRect !== "function") return;
      const rect = rowEl.getBoundingClientRect();
      const centerY = Number(rect.top || 0) + (Number(rect.height || 0) / 2);
      const dist = Math.abs(centerY - targetY);
      if (dist < bestRowDist) {
        bestRowDist = dist;
        bestRow = row;
      }
    });
    const systemRows = rows
      .filter((row) => Number(row?.systemBlockIndex || 0) === Number(bestRow?.systemBlockIndex || 0))
      .slice()
      .sort((a, b) => Number(a?.centerY || 0) - Number(b?.centerY || 0));
    const rowIdx = Math.max(0, systemRows.findIndex((row) => row === bestRow));
    const nextSystemRow = systemRows[rowIdx + 1] || null;
    const currentRect = bestRow?.el?.getBoundingClientRect?.();
    const nextRect = nextSystemRow?.el?.getBoundingClientRect?.();
    const lowerBound = Number(currentRect?.bottom || targetY);
    const upperBound = nextRect ? Number(nextRect.top || lowerBound + 160) : (lowerBound + 220);
    const isLikelyLyricText = (value) => {
      const text = String(value || "").trim();
      if (!text) return false;
      const lower = text.toLowerCase();
      if (
        lower === "musicxml" ||
        /^track\s+\d+/.test(lower) ||
        lower.startsWith("arranger:") ||
        lower.startsWith("electronic scores by")
      ) {
        return false;
      }
      return true;
    };
    const laneCenters = Array.from(container.querySelectorAll("svg text, svg tspan"))
      .map((node) => {
        if (!isLikelyLyricText(node?.textContent)) return null;
        if (typeof node.getBoundingClientRect !== "function") return null;
        const rect = node.getBoundingClientRect();
        const centerY = Number(rect.top || 0) + (Number(rect.height || 0) / 2);
        if (centerY <= lowerBound || centerY >= upperBound) return null;
        return centerY;
      })
      .filter((value) => Number.isFinite(value))
      .sort((a, b) => a - b);
    if (!laneCenters.length) return "1";
    const groupedCenters = [];
    laneCenters.forEach((value) => {
      const last = groupedCenters[groupedCenters.length - 1];
      if (!last || Math.abs(value - last.center) > 8) {
        groupedCenters.push({ center: value, count: 1 });
        return;
      }
      last.center = (last.center * last.count + value) / (last.count + 1);
      last.count += 1;
    });
    const lyricLaneIndex = Math.max(0, groupedCenters.findIndex((group) => Math.abs(Number(group?.center || 0) - targetY) <= 10));
    const lyricNumbers = Array.isArray(container?._twXmlLyricNumbersByPart?.[String(Math.max(0, Number(partIndex || 0)))])
      ? container._twXmlLyricNumbersByPart[String(Math.max(0, Number(partIndex || 0)))]
      : [];
    return String(lyricNumbers[lyricLaneIndex] || lyricNumbers[0] || "1");
  }

  function applyEditedLyricEntries(doc, lyricEntries = [], editedTokens = [], useSyllabic = false) {
    const entries = Array.isArray(lyricEntries) ? lyricEntries : [];
    const tokens = Array.isArray(editedTokens) ? editedTokens : [];
    if (!entries.length || entries.length !== tokens.length) return false;
    if (!useSyllabic) {
      entries.forEach((entry, idx) => {
        const textEl = entry?.textEl || null;
        if (!textEl) return;
        textEl.textContent = String(tokens[idx] || "").trim().replace(/-+$/g, "");
      });
      return true;
    }

    let insideWord = false;
    entries.forEach((entry, idx) => {
      const lyricEl = entry?.lyricEl || null;
      const textEl = entry?.textEl || null;
      if (!lyricEl || !textEl) return;
      const raw = String(tokens[idx] || "").trim();
      const hasTrailingDash = /-$/.test(raw);
      const cleanText = raw.replace(/-+$/g, "").trim();
      textEl.textContent = cleanText;
      let syllabic = "single";
      if (hasTrailingDash) {
        syllabic = insideWord ? "middle" : "begin";
        insideWord = true;
      } else {
        syllabic = insideWord ? "end" : "single";
        insideWord = false;
      }
      setLyricSyllabicType(doc, lyricEl, syllabic);
    });
    return true;
  }

  function openStaffTextEditorModal(initialTokens = [], meta = {}) {
    const BLANK_TOKEN_PLACEHOLDER = "_";
    const decodeEditorToken = (raw) => {
      const value = String(raw ?? "").trim();
      if (!value) return "";
      if (/^_+$/.test(value)) return "";
      if (/^_+-+$/.test(value)) return "";
      return value;
    };
    const formatTokensAsLines = (tokens) => {
      const safe = Array.isArray(tokens) ? tokens : [];
      return safe.map((token) => {
        const word = String(token ?? "").trim();
        return word ? word : BLANK_TOKEN_PLACEHOLDER;
      }).join("\n");
    };
    const formatTokensAsWrappedLines = (tokens, maxChars = 72) => {
      const safe = Array.isArray(tokens) ? tokens : [];
      const words = safe.map((token) => {
        const word = String(token ?? "").trim();
        return word ? word : BLANK_TOKEN_PLACEHOLDER;
      });
      if (!words.length) return "";
      const lines = [];
      let current = "";
      words.forEach((word) => {
        if (!current) {
          current = word;
          return;
        }
        if ((current.length + 1 + word.length) > maxChars) {
          lines.push(current);
          current = word;
          return;
        }
        current += ` ${word}`;
      });
      if (current) lines.push(current);
      return lines.join("\n");
    };
    const parseEditorTokensByLine = (raw, expectedCount) => {
      const lines = String(raw ?? "").replace(/\r/g, "").split("\n");
      while (lines.length > expectedCount && lines[lines.length - 1] === "") {
        lines.pop();
      }
      if (lines.length !== expectedCount) return null;
      return lines.map((line) => decodeEditorToken(line));
    };
    const parseEditorTokensByWrappedLines = (raw, expectedCount) => {
      const tokens = String(raw ?? "")
        .split(/\s+/)
        .map((value) => decodeEditorToken(value));
      const trimmed = tokens.filter((value, idx) => !(idx === tokens.length - 1 && value === ""));
      if (trimmed.length !== expectedCount) return null;
      return trimmed;
    };

    const applySyllabicDisplay = (tokens = [], syllabics = [], withSyllabic = false) => {
      const out = (Array.isArray(tokens) ? tokens : []).map((raw, idx) => {
        const word = decodeEditorToken(raw);
        if (!withSyllabic) return word.replace(/-+$/g, "");
        if (!word) return "";
        const type = String(syllabics?.[idx] || "").trim().toLowerCase();
        if (type === "begin" || type === "middle") return word ? `${word}-` : word;
        return word;
      });
      return out;
    };
    const buildWholeWordsText = (tokens = [], syllabics = [], withSyllabic = false) => {
      const safeTokens = Array.isArray(tokens) ? tokens : [];
      const words = [];
      let pending = "";
      safeTokens.forEach((raw, idx) => {
        const token = String(raw ?? "").trim();
        const clean = decodeEditorToken(token).replace(/-+$/g, "");
        const type = String(syllabics?.[idx] || "").trim().toLowerCase();
        const continues = withSyllabic
          ? /-$/.test(token)
          : (type === "begin" || type === "middle");
        if (clean) pending += clean;
        if (continues) return;
        if (pending) {
          words.push(pending);
          pending = "";
        }
      });
      if (pending) words.push(pending);
      return words.join(" ");
    };
    const wrapPlainText = (text = "", maxChars = 72) => {
      const words = String(text || "").trim().split(/\s+/).filter(Boolean);
      if (!words.length) return "";
      const lines = [];
      let current = "";
      words.forEach((word) => {
        if (!current) {
          current = word;
          return;
        }
        if ((current.length + 1 + word.length) > maxChars) {
          lines.push(current);
          current = word;
          return;
        }
        current += ` ${word}`;
      });
      if (current) lines.push(current);
      return lines.join("\n");
    };
    const buildPhraseLines = (tokens = [], syllabics = [], lyricEntries = [], withSyllabic = false) => {
      const safeTokens = Array.isArray(tokens) ? tokens : [];
      const safeEntries = Array.isArray(lyricEntries) ? lyricEntries : [];
      const words = [];
      let pendingText = "";
      let pendingMeta = null;
      safeTokens.forEach((raw, idx) => {
        const token = String(raw ?? "").trim();
        const clean = decodeEditorToken(token).replace(/-+$/g, "");
        const type = String(syllabics?.[idx] || "").trim().toLowerCase();
        const continues = withSyllabic
          ? /-$/.test(token)
          : (type === "begin" || type === "middle");
        if (!pendingMeta) pendingMeta = safeEntries[idx] || null;
        if (clean) pendingText += clean;
        if (continues) return;
        if (pendingText) {
          const entry = safeEntries[idx] || pendingMeta || null;
          words.push({
            text: pendingText,
            measureNumber: String(entry?.measureNumber || "").trim(),
            durationRaw: Number(entry?.durationRaw || 0),
            noteType: String(entry?.noteType || "").trim().toLowerCase()
          });
        }
        pendingText = "";
        pendingMeta = null;
      });
      if (!words.length) {
        return wrapPlainText(buildWholeWordsText(tokens, syllabics, withSyllabic), 72)
          .split("\n")
          .map((line) => String(line || "").trim())
          .filter(Boolean);
      }
      const isStrongEnding = (word) => /[.!?]$/.test(String(word || "").trim());
      const startsWithCapital = (word) => /^[A-ZÁÐÉÍÓÚÝÞÆÖ]/.test(String(word || "").trim());
      const isStanzaStart = (word) => /^\d+\.\s*[A-ZÁÐÉÍÓÚÝÞÆÖa-záðéíóúýþæö]/.test(String(word || "").trim());
      const isOoLike = (word) => /^(o|oo|ooo)$/i.test(String(word?.text || "").trim().replace(/[.!?,;:]+$/g, ""));
      const isLetsGoDownLead = (word, nextWords = []) => {
        const first = String(word || "").trim().toLowerCase();
        if (!/^oh[,]?$/.test(first) && !/^oh$/.test(first)) return false;
        const tail = nextWords
          .slice(0, 6)
          .map((entry) => String(entry?.text || "").trim().toLowerCase().replace(/[.,!?;:]+$/g, ""));
        return tail.includes("let's") && tail.includes("down");
      };
      const classifyWord = (idx) => {
        const text = String(words[idx]?.text || "").trim();
        if (!text) return "free";
        if (isStanzaStart(text)) return "stanza";
        let chantCount = 0;
        while ((idx + chantCount) < words.length && isOoLike(words[idx + chantCount])) chantCount += 1;
        if (chantCount >= 4) return "chant";
        if (isLetsGoDownLead(text, words.slice(idx + 1))) return "refrain";
        return "free";
      };
      const blocks = [];
      let cursor = 0;
      while (cursor < words.length) {
        const type = classifyWord(cursor);
        if (type === "chant") {
          const chantWords = [];
          while (cursor < words.length && isOoLike(words[cursor])) {
            chantWords.push(String(words[cursor]?.text || "").trim());
            cursor += 1;
          }
          if (chantWords.length) blocks.push({ type: "chant", words: chantWords });
          continue;
        }
        const blockWords = [];
        while (cursor < words.length) {
          const nextType = classifyWord(cursor);
          if (blockWords.length && (nextType === "chant" || nextType === "stanza" || (type !== "refrain" && nextType === "refrain"))) break;
          if (type === "refrain" && blockWords.length && nextType === "stanza") break;
          blockWords.push(words[cursor]);
          cursor += 1;
          const justText = String(blockWords[blockWords.length - 1]?.text || "").trim();
          if (type === "free" && isStrongEnding(justText)) break;
        }
        if (blockWords.length) blocks.push({ type, words: blockWords });
      }

      const lines = [];
      const pushBlockGap = () => {
        if (lines.length && lines[lines.length - 1] !== "") lines.push("");
      };
      const flushLineWords = (lineWords = []) => {
        const line = lineWords.join(" ").trim();
        if (line) lines.push(line);
      };
      const buildLinesForBlock = (block) => {
        if (!block?.words?.length) return;
        if (block.type === "chant") {
          lines.push(block.words.join(" "));
          return;
        }
        const rawWords = block.words.map((entry) => String(entry?.text || "").trim()).filter(Boolean);
        const local = [];
        let current = [];
        let len = 0;
        const flushLocal = () => {
          const line = current.join(" ").trim();
          if (line) local.push(line);
          current = [];
          len = 0;
        };
        rawWords.forEach((text, idx) => {
          const next = rawWords[idx + 1] || "";
          const projected = len ? (len + 1 + text.length) : text.length;
          current.push(text);
          len = projected;
          const stanzaAhead = isStanzaStart(next);
          const strongEnding = isStrongEnding(text);
          const commaEnding = /,$/.test(text);
          const capitalAhead = startsWithCapital(next);
          const longEnough = len >= 28 && current.length >= 4;
          const hardWidth = len >= (block.type === "refrain" ? 44 : 54);
          if (strongEnding || stanzaAhead) {
            flushLocal();
            return;
          }
          if (block.type === "refrain" && commaEnding && len >= 20) {
            flushLocal();
            return;
          }
          if (block.type !== "refrain" && commaEnding && longEnough && capitalAhead) {
            flushLocal();
            return;
          }
          if (hardWidth) {
            flushLocal();
          }
        });
        flushLocal();
        local.forEach((line) => lines.push(line));
      };

      blocks.forEach((block, idx) => {
        if (idx > 0) pushBlockGap();
        buildLinesForBlock(block);
      });
      return lines.length ? lines : wrapPlainText(buildWholeWordsText(tokens, syllabics, withSyllabic), 72)
        .split("\n")
        .map((line) => String(line || "").trim());
    };
    const encodeTextAsHtml = (text = "") =>
      String(text || "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/\r?\n/g, "<br>");
    const decodeHtmlToPlainText = (html = "") =>
      String(html || "")
        .replace(/<br\s*\/?>/gi, "\n")
        .replace(/<\/(div|p|li|section|article|h[1-6]|blockquote)>/gi, "\n")
        .replace(/<[^>]+>/g, "")
        .replace(/&nbsp;/g, " ")
        .replace(/&amp;/g, "&")
        .replace(/&lt;/g, "<")
        .replace(/&gt;/g, ">")
        .replace(/&quot;/g, "\"");
    const normalizeTextForDuplicateCheck = (text = "") =>
      String(text || "").replace(/\s+/g, " ").trim().toLowerCase();
    const insertTextBlockBelowHeaderHtml = (existingHtml = "", blockText = "") => {
      const safeExisting = String(existingHtml || "");
      const safeBlockText = String(blockText || "").trim();
      if (!safeBlockText) return safeExisting;
      const blockHtml = encodeTextAsHtml(safeBlockText);
      const brMatch = safeExisting.match(/<br\s*\/?>/i);
      if (brMatch && Number.isFinite(brMatch.index)) {
        const idx = Number(brMatch.index) + brMatch[0].length;
        return `${safeExisting.slice(0, idx)}<br>${blockHtml}<br>${safeExisting.slice(idx)}`;
      }
      const blockMatch = safeExisting.match(/<\/(div|p|section|article|h[1-6]|blockquote)>/i);
      if (blockMatch && Number.isFinite(blockMatch.index)) {
        const idx = Number(blockMatch.index) + blockMatch[0].length;
        return `${safeExisting.slice(0, idx)}<br><br>${blockHtml}${safeExisting.slice(idx)}`;
      }
      return `${safeExisting}${safeExisting ? "<br><br>" : ""}${blockHtml}`;
    };
    const addWrappedLyricsToCurrentItemText = async (tokens = [], syllabics = [], lyricEntries = [], withSyllabic = false) => {
      const wrappedLines = buildPhraseLines(tokens, syllabics, lyricEntries, withSyllabic);
      const wrappedBlock = wrappedLines.join("\n").trim();
      if (!wrappedBlock) {
        return { ok: false, message: "No text available to add." };
      }
      const editor = document.getElementById("myTextarea");
      const readonly = document.getElementById("myTextarea2");
      if (!editor) {
        return { ok: false, message: "Item text editor is not available." };
      }
      const currentHtml = String(editor.innerHTML || "");
      const currentPlain = decodeHtmlToPlainText(currentHtml);
      const currentNorm = normalizeTextForDuplicateCheck(currentPlain);
      const blockNorm = normalizeTextForDuplicateCheck(wrappedBlock);
      if (blockNorm && currentNorm.includes(blockNorm)) {
        const proceed = window.confirm("This lyric block looks already added in item text. Add it again?");
        if (!proceed) {
          return { ok: false, message: "Add to item text cancelled." };
        }
      }
      const nextHtml = insertTextBlockBelowHeaderHtml(currentHtml, wrappedBlock);
      editor.innerHTML = nextHtml;
      if (readonly) readonly.innerHTML = nextHtml;
      if (typeof window.textTrimmer === "function") {
        try { window.textTrimmer(); } catch {}
      }
      if (typeof insertData !== "function") {
        return { ok: false, message: "Text save function is not available." };
      }
      const saved = await insertData(true);
      if (!saved) {
        return { ok: false, message: "Could not save item text." };
      }
      return { ok: true, message: "Lyrics added to item text and saved." };
    };
    const addWrappedLyricGroupsToCurrentItemText = async (groups = [], withSyllabic = false) => {
      const blocks = (Array.isArray(groups) ? groups : [])
        .map((group) => {
          const wrappedLines = buildPhraseLines(
            Array.isArray(group?.tokens) ? group.tokens : [],
            Array.isArray(group?.syllabics) ? group.syllabics : [],
            Array.isArray(group?.lyricEntries) ? group.lyricEntries : [],
            withSyllabic
          );
          return wrappedLines.join("\n").trim();
        })
        .filter(Boolean);
      const wrappedBlock = blocks.join("\n\n").trim();
      if (!wrappedBlock) {
        return { ok: false, message: "No text available to add." };
      }
      const editor = document.getElementById("myTextarea");
      const readonly = document.getElementById("myTextarea2");
      if (!editor) {
        return { ok: false, message: "Item text editor is not available." };
      }
      const currentHtml = String(editor.innerHTML || "");
      const currentPlain = decodeHtmlToPlainText(currentHtml);
      const currentNorm = normalizeTextForDuplicateCheck(currentPlain);
      const blockNorm = normalizeTextForDuplicateCheck(wrappedBlock);
      if (blockNorm && currentNorm.includes(blockNorm)) {
        const proceed = window.confirm("This lyric block looks already added in item text. Add it again?");
        if (!proceed) {
          return { ok: false, message: "Add to item text cancelled." };
        }
      }
      const nextHtml = insertTextBlockBelowHeaderHtml(currentHtml, wrappedBlock);
      editor.innerHTML = nextHtml;
      if (readonly) readonly.innerHTML = nextHtml;
      if (typeof window.textTrimmer === "function") {
        try { window.textTrimmer(); } catch {}
      }
      if (typeof insertData !== "function") {
        return { ok: false, message: "Text save function is not available." };
      }
      const saved = await insertData(true);
      if (!saved) {
        return { ok: false, message: "Could not save item text." };
      }
      return { ok: true, message: "Lyrics added to item text and saved." };
    };

    const lyricGroups = Array.isArray(meta?.lyricGroups) ? meta.lyricGroups.filter(Boolean) : [];
    if (lyricGroups.length > 1) {
      return new Promise((resolve) => {
        const overlay = document.createElement("div");
        overlay.style.position = "fixed";
        overlay.style.inset = "0";
        overlay.style.background = "rgba(2,6,23,0.48)";
        overlay.style.zIndex = "2147483600";
        overlay.style.display = "flex";
        overlay.style.alignItems = "center";
        overlay.style.justifyContent = "center";
        overlay.style.padding = "12px";

        const panel = document.createElement("div");
        panel.style.width = "min(860px, calc(100vw - 24px))";
        panel.style.maxHeight = "min(86vh, 940px)";
        panel.style.background = "#0b1020";
        panel.style.border = "1px solid #334155";
        panel.style.borderRadius = "10px";
        panel.style.boxShadow = "0 14px 46px rgba(2,6,23,0.5)";
        panel.style.display = "flex";
        panel.style.flexDirection = "column";
        panel.style.position = "fixed";
        panel.style.left = "50%";
        panel.style.top = "50%";
        panel.style.transform = "translate(-50%, -50%)";
        panel.innerHTML = `
          <div id="twStaffTextDragHandle" style="display:flex; align-items:center; justify-content:space-between; gap:10px; padding:10px 12px; border-bottom:1px solid #334155; background:#111827;">
            <div>
              <div style="font:600 14px system-ui, -apple-system, Segoe UI, sans-serif; color:#f8fafc;">Staff text editor</div>
              <div style="margin-top:2px; font:500 11px system-ui, -apple-system, Segoe UI, sans-serif; color:#93c5fd;">
                Part ${Math.max(1, Number(meta?.partIndex || 0) + 1)} | ${lyricGroups.length} lyric lines
              </div>
            </div>
            <div style="display:flex; gap:8px; align-items:center;">
              <button id="twStaffTextUndo" type="button" style="border:1px solid #1e40af; border-radius:6px; padding:5px 10px; background:#1e3a8a; color:#dbeafe; cursor:pointer;">Undo</button>
              <button id="twStaffTextSave" type="button" style="border:1px solid #14532d; border-radius:6px; padding:5px 10px; background:#166534; color:#ecfdf5; cursor:pointer;">Save</button>
              <button id="twStaffTextCancel" type="button" style="border:1px solid #475569; border-radius:6px; padding:5px 10px; background:#0f172a; color:#e2e8f0; cursor:pointer;">Cancel</button>
            </div>
          </div>
          <div style="padding:10px 12px 0 12px; color:#cbd5e1; font:500 11px system-ui, -apple-system, Segoe UI, sans-serif;">
            All lyric lines for this part. One token per word in wrapped mode. Save keeps exact token counts per lyric line.
          </div>
          <label style="display:flex; align-items:center; gap:7px; margin:8px 12px 0 12px; color:#bfdbfe; font:500 11px system-ui, -apple-system, Segoe UI, sans-serif;">
            <input id="twStaffTextSyllabicToggle" type="checkbox" style="accent-color:#3b82f6;">
            Show and set syllabic with trailing "-"
          </label>
          <label style="display:flex; align-items:center; gap:7px; margin:8px 12px 0 12px; color:#bfdbfe; font:500 11px system-ui, -apple-system, Segoe UI, sans-serif;">
            <input id="twStaffTextWholeWordsToggle" type="checkbox" style="accent-color:#3b82f6;">
            Show whole words
          </label>
          <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; margin:8px 12px 0 12px;">
            <div></div>
            <div style="display:flex; align-items:center; gap:8px;">
              <button id="twStaffTextCopyWholeWords" type="button" style="border:1px solid #1e40af; border-radius:6px; padding:4px 8px; background:#1e3a8a; color:#dbeafe; cursor:pointer;">Copy whole text</button>
              <button id="twStaffTextAddToItemText" type="button" style="border:1px solid #14532d; border-radius:6px; padding:4px 8px; background:#166534; color:#ecfdf5; cursor:pointer;">Add to item text</button>
            </div>
          </div>
          <div id="twStaffTextWholeWordsWrap" style="display:none; margin:8px 12px 0 12px;">
            <textarea id="twStaffTextWholeWordsArea" readonly style="width:100%; min-height:96px; max-height:220px; resize:vertical; border:1px solid #334155; border-radius:8px; padding:8px; background:#0f172a; color:#bfdbfe; font:12px/1.45 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;"></textarea>
          </div>
          <div id="twStaffTextStatus" style="display:none; margin:8px 12px 0 12px; color:#fecaca; font:500 11px system-ui, -apple-system, Segoe UI, sans-serif;"></div>
          <div id="twStaffTextGroupedWrap" style="padding:8px 12px 12px 12px; overflow:auto; display:grid; gap:12px;"></div>
        `;
        overlay.appendChild(panel);
        document.body.appendChild(overlay);
        makeFloatingPanelDraggable(panel, panel.querySelector("#twStaffTextDragHandle"));

        const syllabicToggle = panel.querySelector("#twStaffTextSyllabicToggle");
        const wholeWordsToggle = panel.querySelector("#twStaffTextWholeWordsToggle");
        const wholeWordsWrap = panel.querySelector("#twStaffTextWholeWordsWrap");
        const wholeWordsArea = panel.querySelector("#twStaffTextWholeWordsArea");
        const copyWholeWordsBtn = panel.querySelector("#twStaffTextCopyWholeWords");
        const addToItemTextBtn = panel.querySelector("#twStaffTextAddToItemText");
        const undoBtn = panel.querySelector("#twStaffTextUndo");
        const saveBtn = panel.querySelector("#twStaffTextSave");
        const cancelBtn = panel.querySelector("#twStaffTextCancel");
        const statusEl = panel.querySelector("#twStaffTextStatus");
        const groupedWrap = panel.querySelector("#twStaffTextGroupedWrap");
        let withSyllabic = !!meta?.defaultSyllabicMode;
        if (syllabicToggle) syllabicToggle.checked = withSyllabic;
        const groupStates = lyricGroups.map((group) => ({
          lyricNumber: String(group?.lyricNumber || "1"),
          lyricEntries: Array.isArray(group?.lyricEntries) ? group.lyricEntries : [],
          initialSyllabics: Array.isArray(group?.initialSyllabics) ? group.initialSyllabics : [],
          initialTokens: Array.isArray(group?.initialTokens) ? group.initialTokens : [],
          textarea: null
        }));
        const activeLyricNumber = String(meta?.lyricNumber || "1").trim() || "1";
        const renderGroupEditors = (tokenSets = null) => {
          if (!groupedWrap) return;
          groupedWrap.innerHTML = "";
          groupStates.forEach((group, idx) => {
            const tokens = Array.isArray(tokenSets?.[idx]) ? tokenSets[idx] : group.initialTokens;
            const displayTokens = applySyllabicDisplay(tokens, group.initialSyllabics, withSyllabic);
            const card = document.createElement("div");
            card.style.border = String(group.lyricNumber === activeLyricNumber ? "1px solid #3b82f6" : "1px solid #334155");
            card.style.borderRadius = "8px";
            card.style.padding = "10px";
            card.style.background = String(group.lyricNumber === activeLyricNumber ? "#0f172a" : "#111827");
            card.innerHTML = `
              <div style="margin-bottom:8px; color:${group.lyricNumber === activeLyricNumber ? "#bfdbfe" : "#cbd5e1"}; font:600 12px system-ui, -apple-system, Segoe UI, sans-serif;">
                Lyric line ${group.lyricNumber}
              </div>
            `;
            const textarea = document.createElement("textarea");
            textarea.spellcheck = false;
            textarea.style.width = "100%";
            textarea.style.minHeight = "140px";
            textarea.style.resize = "vertical";
            textarea.style.border = "1px solid #334155";
            textarea.style.borderRadius = "8px";
            textarea.style.padding = "10px";
            textarea.style.background = "#111827";
            textarea.style.color = "#e5e7eb";
            textarea.style.font = "12px/1.45 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace";
            textarea.value = formatTokensAsWrappedLines(displayTokens);
            card.appendChild(textarea);
            groupedWrap.appendChild(card);
            group.textarea = textarea;
          });
        };
        const parseGroupStates = () => {
          const out = [];
          for (let i = 0; i < groupStates.length; i += 1) {
            const group = groupStates[i];
            const parsed = parseEditorTokensByWrappedLines(String(group?.textarea?.value || ""), group.initialTokens.length);
            if (!parsed) {
              return { ok: false, message: `Token count mismatch in lyric line ${group.lyricNumber}: expected ${group.initialTokens.length} tokens.` };
            }
            out.push({
              lyricNumber: group.lyricNumber,
              lyricEntries: group.lyricEntries,
              syllabics: group.initialSyllabics,
              tokens: parsed
            });
          }
          return { ok: true, groups: out };
        };
        const setWholeWordsVisible = (visible) => {
          if (wholeWordsWrap) wholeWordsWrap.style.display = visible ? "block" : "none";
        };
        const setStatus = (msg = "") => {
          const text = String(msg || "").trim();
          if (!statusEl) return;
          if (!text) {
            statusEl.style.display = "none";
            statusEl.textContent = "";
            return;
          }
          statusEl.style.display = "block";
          statusEl.textContent = text;
        };
        const updateWholeWordsPreview = () => {
          const parsedState = parseGroupStates();
          if (!parsedState.ok) return;
          const text = parsedState.groups
            .map((group) => buildWholeWordsText(group.tokens, group.syllabics, !!syllabicToggle?.checked))
            .filter(Boolean)
            .join("\n\n");
          if (wholeWordsArea) wholeWordsArea.value = text;
        };
        const close = (result) => {
          document.removeEventListener("keydown", onKeydown, true);
          overlay.remove();
          resolve(result);
        };
        const onKeydown = (event) => {
          if (event.key === "Escape") {
            event.preventDefault();
            close(null);
          }
        };
        document.addEventListener("keydown", onKeydown, true);
        overlay.addEventListener("click", (event) => {
          if (event.target === overlay) close(null);
        });
        renderGroupEditors();
        setWholeWordsVisible(!!wholeWordsToggle?.checked);
        updateWholeWordsPreview();
        groupedWrap?.addEventListener("input", () => {
          updateWholeWordsPreview();
        });
        syllabicToggle?.addEventListener("change", () => {
          const parsedState = parseGroupStates();
          if (!parsedState.ok) {
            setStatus(parsedState.message);
            if (syllabicToggle) syllabicToggle.checked = withSyllabic;
            return;
          }
          withSyllabic = !!syllabicToggle?.checked;
          renderGroupEditors(parsedState.groups.map((group) => group.tokens));
          updateWholeWordsPreview();
          setStatus("");
        });
        wholeWordsToggle?.addEventListener("change", () => {
          setWholeWordsVisible(!!wholeWordsToggle?.checked);
          updateWholeWordsPreview();
        });
        copyWholeWordsBtn?.addEventListener("click", async () => {
          updateWholeWordsPreview();
          const text = String(wholeWordsArea?.value || "");
          if (!text) {
            setStatus("No whole text available to copy.");
            return;
          }
          try {
            if (navigator.clipboard?.writeText) {
              await navigator.clipboard.writeText(text);
              setStatus("Whole text copied.");
              return;
            }
          } catch {}
          try {
            wholeWordsArea?.focus?.();
            wholeWordsArea?.select?.();
            const ok = document.execCommand("copy");
            if (ok) {
              setStatus("Whole text copied.");
              return;
            }
          } catch {}
          setStatus("Copy failed. Select the whole-text box and copy manually.");
        });
        addToItemTextBtn?.addEventListener("click", async () => {
          const parsedState = parseGroupStates();
          if (!parsedState.ok) {
            setStatus(parsedState.message);
            return;
          }
          addToItemTextBtn.disabled = true;
          saveBtn.disabled = true;
          undoBtn.disabled = true;
          try {
            const res = await addWrappedLyricGroupsToCurrentItemText(parsedState.groups, !!syllabicToggle?.checked);
            if (!res?.ok) {
              setStatus(String(res?.message || "Could not add text to item."));
              return;
            }
            close({ __twAddedToItemText: true });
          } catch (err) {
            setStatus(`Add to item text failed: ${String(err?.message || err || "unknown error")}`);
          } finally {
            addToItemTextBtn.disabled = false;
            saveBtn.disabled = false;
            undoBtn.disabled = false;
          }
        });
        cancelBtn?.addEventListener("click", () => close(null));
        undoBtn?.addEventListener("click", async () => {
          const undoHandler = typeof meta?.onUndo === "function" ? meta.onUndo : null;
          if (!undoHandler) {
            setStatus("Undo is not available for this edit.");
            return;
          }
          undoBtn.disabled = true;
          saveBtn.disabled = true;
          try {
            const res = await undoHandler();
            if (!res?.ok) {
              setStatus(String(res?.message || "Nothing to undo."));
              return;
            }
            close({ __twUndoApplied: true });
          } catch (err) {
            setStatus(`Undo failed: ${String(err?.message || err || "unknown error")}`);
          } finally {
            undoBtn.disabled = false;
            saveBtn.disabled = false;
          }
        });
        saveBtn?.addEventListener("click", () => {
          const parsedState = parseGroupStates();
          if (!parsedState.ok) {
            setStatus(parsedState.message);
            return;
          }
          close({
            lyricGroups: parsedState.groups,
            useSyllabic: !!syllabicToggle?.checked
          });
        });
        setTimeout(() => {
          const targetGroup = groupStates.find((group) => group.lyricNumber === activeLyricNumber) || groupStates[0];
          targetGroup?.textarea?.focus?.();
        }, 0);
      });
    }

    return new Promise((resolve) => {
      const overlay = document.createElement("div");
      overlay.style.position = "fixed";
      overlay.style.inset = "0";
      overlay.style.background = "rgba(2,6,23,0.48)";
      overlay.style.zIndex = "2147483600";
      overlay.style.display = "flex";
      overlay.style.alignItems = "center";
      overlay.style.justifyContent = "center";
      overlay.style.padding = "12px";

      const panel = document.createElement("div");
      panel.style.width = "min(760px, calc(100vw - 24px))";
      panel.style.maxHeight = "min(82vh, 880px)";
      panel.style.background = "#0b1020";
      panel.style.border = "1px solid #334155";
      panel.style.borderRadius = "10px";
      panel.style.boxShadow = "0 14px 46px rgba(2,6,23,0.5)";
      panel.style.display = "flex";
      panel.style.flexDirection = "column";
      panel.style.position = "fixed";
      panel.style.left = "50%";
      panel.style.top = "50%";
      panel.style.transform = "translate(-50%, -50%)";
      panel.innerHTML = `
        <div id="twStaffTextDragHandle" style="display:flex; align-items:center; justify-content:space-between; gap:10px; padding:10px 12px; border-bottom:1px solid #334155; background:#111827;">
          <div>
            <div style="font:600 14px system-ui, -apple-system, Segoe UI, sans-serif; color:#f8fafc;">Staff text editor</div>
            <div style="margin-top:2px; font:500 11px system-ui, -apple-system, Segoe UI, sans-serif; color:#93c5fd;">
              Part ${Math.max(1, Number(meta?.partIndex || 0) + 1)} | ${Math.max(0, Number(initialTokens.length || 0))} lyric tokens
            </div>
          </div>
        <div style="display:flex; gap:8px; align-items:center;">
            <button id="twStaffTextUndo" type="button" style="border:1px solid #1e40af; border-radius:6px; padding:5px 10px; background:#1e3a8a; color:#dbeafe; cursor:pointer;">Undo</button>
            <button id="twStaffTextSave" type="button" style="border:1px solid #14532d; border-radius:6px; padding:5px 10px; background:#166534; color:#ecfdf5; cursor:pointer;">Save</button>
            <button id="twStaffTextCancel" type="button" style="border:1px solid #475569; border-radius:6px; padding:5px 10px; background:#0f172a; color:#e2e8f0; cursor:pointer;">Cancel</button>
          </div>
        </div>
        <div style="padding:10px 12px 0 12px; color:#cbd5e1; font:500 11px system-ui, -apple-system, Segoe UI, sans-serif;">
          One token per line. Use "_" to keep a blank lyric token. Save keeps exact token count.
        </div>
        <label style="display:flex; align-items:center; gap:7px; margin:8px 12px 0 12px; color:#bfdbfe; font:500 11px system-ui, -apple-system, Segoe UI, sans-serif;">
          <input id="twStaffTextSyllabicToggle" type="checkbox" style="accent-color:#3b82f6;">
          Show and set syllabic with trailing "-"
        </label>
        <label style="display:flex; align-items:center; gap:7px; margin:8px 12px 0 12px; color:#bfdbfe; font:500 11px system-ui, -apple-system, Segoe UI, sans-serif;">
          <input id="twStaffTextLineEditToggle" type="checkbox" style="accent-color:#3b82f6;">
          Edit lyrics in wrapped text lines
        </label>
        <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; margin:8px 12px 0 12px;">
          <label style="display:flex; align-items:center; gap:7px; color:#bfdbfe; font:500 11px system-ui, -apple-system, Segoe UI, sans-serif;">
            <input id="twStaffTextWholeWordsToggle" type="checkbox" style="accent-color:#3b82f6;">
            Show whole words (remove syllabic split)
          </label>
          <div style="display:flex; align-items:center; gap:8px;">
            <button id="twStaffTextCopyWholeWords" type="button" style="border:1px solid #1e40af; border-radius:6px; padding:4px 8px; background:#1e3a8a; color:#dbeafe; cursor:pointer;">Copy whole text</button>
            <button id="twStaffTextAddToItemText" type="button" style="border:1px solid #14532d; border-radius:6px; padding:4px 8px; background:#166534; color:#ecfdf5; cursor:pointer;">Add to item text</button>
          </div>
        </div>
        <div id="twStaffTextWholeWordsWrap" style="display:none; margin:8px 12px 0 12px;">
          <textarea id="twStaffTextWholeWordsArea" readonly style="width:100%; min-height:72px; max-height:180px; resize:vertical; border:1px solid #334155; border-radius:8px; padding:8px; background:#0f172a; color:#bfdbfe; font:12px/1.45 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;"></textarea>
        </div>
        <div id="twStaffTextStatus" style="display:none; margin:8px 12px 0 12px; color:#fecaca; font:500 11px system-ui, -apple-system, Segoe UI, sans-serif;"></div>
        <div id="twStaffTextTokenEditWrap" style="padding:8px 12px 12px 12px; overflow:auto;">
          <textarea id="twStaffTextArea" spellcheck="false" style="width:100%; min-height:320px; max-height:62vh; resize:vertical; border:1px solid #334155; border-radius:8px; padding:10px; background:#111827; color:#e5e7eb; font:12px/1.45 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;"></textarea>
        </div>
        <div id="twStaffTextLineEditWrap" style="display:none; padding:8px 12px 12px 12px; overflow:auto;">
          <textarea id="twStaffTextLineArea" spellcheck="false" style="width:100%; min-height:320px; max-height:62vh; resize:vertical; border:1px solid #334155; border-radius:8px; padding:10px; background:#111827; color:#e5e7eb; font:12px/1.45 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;"></textarea>
        </div>
      `;
      overlay.appendChild(panel);
      document.body.appendChild(overlay);
      makeFloatingPanelDraggable(panel, panel.querySelector("#twStaffTextDragHandle"));

      const area = panel.querySelector("#twStaffTextArea");
      const lineArea = panel.querySelector("#twStaffTextLineArea");
      const lineEditToggle = panel.querySelector("#twStaffTextLineEditToggle");
      const tokenEditWrap = panel.querySelector("#twStaffTextTokenEditWrap");
      const lineEditWrap = panel.querySelector("#twStaffTextLineEditWrap");
      const syllabicToggle = panel.querySelector("#twStaffTextSyllabicToggle");
      const wholeWordsToggle = panel.querySelector("#twStaffTextWholeWordsToggle");
      const wholeWordsWrap = panel.querySelector("#twStaffTextWholeWordsWrap");
      const wholeWordsArea = panel.querySelector("#twStaffTextWholeWordsArea");
      const copyWholeWordsBtn = panel.querySelector("#twStaffTextCopyWholeWords");
      const addToItemTextBtn = panel.querySelector("#twStaffTextAddToItemText");
      const undoBtn = panel.querySelector("#twStaffTextUndo");
      const saveBtn = panel.querySelector("#twStaffTextSave");
      const cancelBtn = panel.querySelector("#twStaffTextCancel");
      const statusEl = panel.querySelector("#twStaffTextStatus");
      const initialSyllabics = Array.isArray(meta?.initialSyllabics)
        ? meta.initialSyllabics.map((s) => String(s || "").trim().toLowerCase())
        : [];
      let withSyllabic = !!meta?.defaultSyllabicMode;
      let editByLines = true;
      if (syllabicToggle) syllabicToggle.checked = withSyllabic;
      if (lineEditToggle) lineEditToggle.checked = editByLines;
      const renderEditorsFromTokens = (tokens) => {
        if (area) area.value = formatTokensAsLines(tokens);
        if (lineArea) lineArea.value = formatTokensAsWrappedLines(tokens);
      };
      const setEditMode = (wrappedMode) => {
        editByLines = !!wrappedMode;
        if (tokenEditWrap) tokenEditWrap.style.display = editByLines ? "none" : "block";
        if (lineEditWrap) lineEditWrap.style.display = editByLines ? "block" : "none";
      };
      const parseActiveEditorTokens = () => {
        if (editByLines) {
          const raw = String(lineArea?.value || "");
          const parsed = parseEditorTokensByWrappedLines(raw, initialTokens.length);
          if (!parsed) return { ok: false, reason: "wrapped-count" };
          return { ok: true, tokens: parsed };
        }
        const raw = String(area?.value || "");
        const parsed = parseEditorTokensByLine(raw, initialTokens.length);
        if (!parsed) return { ok: false, reason: "line-count" };
        return { ok: true, tokens: parsed };
      };
      renderEditorsFromTokens(applySyllabicDisplay(initialTokens, initialSyllabics, withSyllabic));
      setEditMode(editByLines);
      const updateWholeWordsPreview = () => {
        const parsedState = parseActiveEditorTokens();
        if (!parsedState.ok) return;
        const whole = buildWholeWordsText(parsedState.tokens, initialSyllabics, !!syllabicToggle?.checked);
        if (wholeWordsArea) wholeWordsArea.value = whole;
      };
      const setWholeWordsVisible = (visible) => {
        if (wholeWordsWrap) wholeWordsWrap.style.display = visible ? "block" : "none";
      };
      setWholeWordsVisible(!!wholeWordsToggle?.checked);
      updateWholeWordsPreview();

      const setStatus = (msg = "") => {
        const text = String(msg || "").trim();
        if (!statusEl) return;
        if (!text) {
          statusEl.style.display = "none";
          statusEl.textContent = "";
          return;
        }
        statusEl.style.display = "block";
        statusEl.textContent = text;
      };

      const close = (result) => {
        document.removeEventListener("keydown", onKeydown, true);
        overlay.remove();
        resolve(result);
      };

      const onKeydown = (event) => {
        if (event.key === "Escape") {
          event.preventDefault();
          close(null);
        }
      };
      document.addEventListener("keydown", onKeydown, true);

      overlay.addEventListener("click", (event) => {
        if (event.target === overlay) close(null);
      });
      syllabicToggle?.addEventListener("change", () => {
        const parsedState = parseActiveEditorTokens();
        if (!parsedState.ok) {
          setStatus(`Token count mismatch: expected ${initialTokens.length} tokens.`);
          if (syllabicToggle) syllabicToggle.checked = withSyllabic;
          return;
        }
        const nextMode = !!syllabicToggle?.checked;
        const normalized = parsedState.tokens.map((word) => String(word || "").trim().replace(/-+$/g, ""));
        const nextTokens = applySyllabicDisplay(normalized, initialSyllabics, nextMode);
        renderEditorsFromTokens(nextTokens);
        withSyllabic = nextMode;
        updateWholeWordsPreview();
        setStatus("");
      });
      lineEditToggle?.addEventListener("change", () => {
        const parsedState = parseActiveEditorTokens();
        if (!parsedState.ok) {
          setStatus(`Token count mismatch: expected ${initialTokens.length} tokens.`);
          if (lineEditToggle) lineEditToggle.checked = editByLines;
          return;
        }
        renderEditorsFromTokens(parsedState.tokens);
        setEditMode(!!lineEditToggle?.checked);
        updateWholeWordsPreview();
      });
      wholeWordsToggle?.addEventListener("change", () => {
        setWholeWordsVisible(!!wholeWordsToggle?.checked);
        updateWholeWordsPreview();
      });
      area?.addEventListener("input", () => {
        updateWholeWordsPreview();
      });
      lineArea?.addEventListener("input", () => {
        updateWholeWordsPreview();
      });
      copyWholeWordsBtn?.addEventListener("click", async () => {
        updateWholeWordsPreview();
        const text = String(wholeWordsArea?.value || "");
        if (!text) {
          setStatus("No whole text available to copy.");
          return;
        }
        try {
          if (navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(text);
            setStatus("Whole text copied.");
            return;
          }
        } catch {}
        try {
          wholeWordsArea?.focus?.();
          wholeWordsArea?.select?.();
          const ok = document.execCommand("copy");
          if (ok) {
            setStatus("Whole text copied.");
            return;
          }
        } catch {}
        setStatus("Copy failed. Select the whole-text box and copy manually.");
      });
      addToItemTextBtn?.addEventListener("click", async () => {
        const parsedState = parseActiveEditorTokens();
        if (!parsedState.ok) {
          setStatus(`Token count mismatch: expected ${initialTokens.length} tokens.`);
          return;
        }
        addToItemTextBtn.disabled = true;
        saveBtn.disabled = true;
        undoBtn.disabled = true;
        try {
          const res = await addWrappedLyricsToCurrentItemText(
            parsedState.tokens,
            initialSyllabics,
            Array.isArray(meta?.lyricEntries) ? meta.lyricEntries : [],
            !!syllabicToggle?.checked
          );
          if (!res?.ok) {
            setStatus(String(res?.message || "Could not add text to item."));
            return;
          }
          close({ __twAddedToItemText: true });
        } catch (err) {
          setStatus(`Add to item text failed: ${String(err?.message || err || "unknown error")}`);
        } finally {
          addToItemTextBtn.disabled = false;
          saveBtn.disabled = false;
          undoBtn.disabled = false;
        }
      });
      cancelBtn?.addEventListener("click", () => close(null));
      undoBtn?.addEventListener("click", async () => {
        const undoHandler = typeof meta?.onUndo === "function" ? meta.onUndo : null;
        if (!undoHandler) {
          setStatus("Undo is not available for this edit.");
          return;
        }
        undoBtn.disabled = true;
        saveBtn.disabled = true;
        try {
          const res = await undoHandler();
          if (!res?.ok) {
            setStatus(String(res?.message || "Nothing to undo."));
            return;
          }
          close({ __twUndoApplied: true });
        } catch (err) {
          setStatus(`Undo failed: ${String(err?.message || err || "unknown error")}`);
        } finally {
          undoBtn.disabled = false;
          saveBtn.disabled = false;
        }
      });
      saveBtn?.addEventListener("click", () => {
        const parsedState = parseActiveEditorTokens();
        if (!parsedState.ok) {
          setStatus(`Token count mismatch: expected ${initialTokens.length} tokens.`);
          return;
        }
        close({
          tokens: parsedState.tokens,
          useSyllabic: !!syllabicToggle?.checked
        });
      });
      setTimeout(() => (editByLines ? lineArea : area)?.focus?.(), 0);
    });
  }

  async function openStaffTextEditorFromPanel(panel) {
    if (!canUseMusicXmlInlineEditing()) {
      setXmlNoteSourceStatus(panel, "View-only mode. Admin access is required to edit.", "error");
      return false;
    }
    if (!panel) return false;
    const surrogate = String(panel.dataset.twXmlSurrogate || window.currentSurrogate || "").trim();
    const key = String(panel.dataset.twXmlFileKey || "").trim();
    const partIndex = Math.max(0, Number(panel.dataset.twXmlPartIndex || 0));
    const xmlState = window._pdfXmlViewState || {};
    const fileUrl = String(xmlState?.file?.url || "").trim();
    if (!surrogate || !key || !fileUrl) {
      setXmlNoteSourceStatus(panel, "Cannot open staff text editor: missing file metadata.", "error");
      return false;
    }

    try {
      const xmlText = await fetchMusicXmlTextFromUrl(fileUrl);
      const doc = new DOMParser().parseFromString(xmlText, "application/xml");
      if (doc.getElementsByTagName("parsererror").length) {
        setXmlNoteSourceStatus(panel, "Cannot parse XML for staff text editing.", "error");
        return false;
      }
      const lyricNumber = String(panel.dataset.twXmlLyricNumber || "1").trim() || "1";
      const lyricGroups = collectPartLyricGroups(doc, partIndex);
      const lyricEntries = collectPartLyricEntries(doc, partIndex, lyricNumber);
      if (!lyricGroups.length && !lyricEntries.length) {
        setXmlNoteSourceStatus(panel, "No lyric text found on this staff line.", "error");
        return false;
      }
      const initialTokens = lyricEntries.map((entry) => String(entry?.textEl?.textContent || ""));
      const initialSyllabics = lyricEntries.map((entry) => getLyricSyllabicType(entry?.lyricEl));
      const editedResult = await openStaffTextEditorModal(initialTokens, {
        partIndex,
        lyricNumber,
        lyricGroups,
        initialSyllabics,
        lyricEntries,
        onUndo: async () => {
          const undone = await undoLastXmlEditForFile(surrogate, key);
          if (!undone?.ok) return { ok: false, message: "No saved edits to undo." };
          await openMusicXmlInPdfTab(surrogate, null, { setSticky: window.getPreferredScoreViewMode?.() === "xml" });
          return { ok: true };
        }
      });
      if (!editedResult) return false;
      if (editedResult.__twUndoApplied) {
        setXmlNoteSourceStatus(panel, "Undo applied.", "success");
        return true;
      }
      if (editedResult.__twAddedToItemText) {
        setXmlNoteSourceStatus(panel, "Lyrics added to item text.", "success");
        return true;
      }
      const useSyllabic = !!editedResult?.useSyllabic;
      if (Array.isArray(editedResult?.lyricGroups) && editedResult.lyricGroups.length) {
        let appliedAny = false;
        editedResult.lyricGroups.forEach((group) => {
          const ok = applyEditedLyricEntries(
            doc,
            Array.isArray(group?.lyricEntries) ? group.lyricEntries : [],
            Array.isArray(group?.tokens) ? group.tokens : [],
            useSyllabic
          );
          if (ok) appliedAny = true;
        });
        if (!appliedAny) {
          setXmlNoteSourceStatus(panel, "Could not apply text update.", "error");
          return false;
        }
      } else {
        const editedTokens = Array.isArray(editedResult?.tokens) ? editedResult.tokens : [];
        const applied = applyEditedLyricEntries(doc, lyricEntries, editedTokens, useSyllabic);
        if (!applied) {
          setXmlNoteSourceStatus(panel, "Could not apply text update.", "error");
          return false;
        }
      }
      const out = new XMLSerializer().serializeToString(doc);
      const ok = await uploadMusicXmlTextByKey(key, out);
      if (!ok) {
        setXmlNoteSourceStatus(panel, "Could not save updated staff text.", "error");
        return false;
      }
      addXmlEditHistoryEntry({
        surrogate,
        key,
        beforeXml: xmlText,
        afterXml: out,
        partIndex,
        lyricNumber,
        kind: "staff-text"
      });
      setXmlNoteSourceStatus(panel, "Staff text saved. Re-rendering score...", "success");
      await openMusicXmlInPdfTab(surrogate, null, { setSticky: window.getPreferredScoreViewMode?.() === "xml" });
      return true;
    } catch (err) {
      setXmlNoteSourceStatus(panel, `Staff text edit failed: ${String(err?.message || err || "unknown error")}`, "error");
      return false;
    }
  }

  async function openStaffTextEditorForPart(opts = {}) {
    if (!canUseMusicXmlInlineEditing()) return false;
    const surrogate = String(opts?.surrogate || window.currentSurrogate || "").trim();
    const key = String(opts?.key || "").trim();
    const fileUrl = String(opts?.fileUrl || "").trim();
    const partIndex = Math.max(0, Number(opts?.partIndex || 0));
    if (!surrogate || !key || !fileUrl) return false;

    try {
      const xmlText = await fetchMusicXmlTextFromUrl(fileUrl);
      const doc = new DOMParser().parseFromString(xmlText, "application/xml");
      if (doc.getElementsByTagName("parsererror").length) return false;
      const lyricNumber = String(opts?.lyricNumber || "1").trim() || "1";
      const lyricGroups = collectPartLyricGroups(doc, partIndex);
      const lyricEntries = collectPartLyricEntries(doc, partIndex, lyricNumber);
      if (!lyricGroups.length && !lyricEntries.length) return false;

      const initialTokens = lyricEntries.map((entry) => String(entry?.textEl?.textContent || ""));
      const initialSyllabics = lyricEntries.map((entry) => getLyricSyllabicType(entry?.lyricEl));
      const editedResult = await openStaffTextEditorModal(initialTokens, {
        partIndex,
        lyricNumber,
        lyricGroups,
        initialSyllabics,
        lyricEntries,
        onUndo: async () => {
          const undone = await undoLastXmlEditForFile(surrogate, key);
          if (!undone?.ok) return { ok: false, message: "No saved edits to undo." };
          await openMusicXmlInPdfTab(surrogate, null, { setSticky: window.getPreferredScoreViewMode?.() === "xml" });
          return { ok: true };
        }
      });
      if (!editedResult) return false;
      if (editedResult.__twUndoApplied) return true;
      if (editedResult.__twAddedToItemText) return true;

      const useSyllabic = !!editedResult?.useSyllabic;
      if (Array.isArray(editedResult?.lyricGroups) && editedResult.lyricGroups.length) {
        let appliedAny = false;
        editedResult.lyricGroups.forEach((group) => {
          const ok = applyEditedLyricEntries(
            doc,
            Array.isArray(group?.lyricEntries) ? group.lyricEntries : [],
            Array.isArray(group?.tokens) ? group.tokens : [],
            useSyllabic
          );
          if (ok) appliedAny = true;
        });
        if (!appliedAny) return false;
      } else {
        const editedTokens = Array.isArray(editedResult?.tokens) ? editedResult.tokens : [];
        const applied = applyEditedLyricEntries(doc, lyricEntries, editedTokens, useSyllabic);
        if (!applied) return false;
      }
      const out = new XMLSerializer().serializeToString(doc);
      const ok = await uploadMusicXmlTextByKey(key, out);
      if (!ok) return false;
      addXmlEditHistoryEntry({
        surrogate,
        key,
        beforeXml: xmlText,
        afterXml: out,
        partIndex,
        lyricNumber,
        kind: "staff-text"
      });
      await openMusicXmlInPdfTab(surrogate, null, { setSticky: window.getPreferredScoreViewMode?.() === "xml" });
      return true;
    } catch {
      return false;
    }
  }

  function positionXmlNoteSourcePanel(panel, event = null, anchorEl = null) {
    if (!panel) return;
    const viewportW = Math.max(320, Number(window.innerWidth || 0));
    const viewportH = Math.max(240, Number(window.innerHeight || 0));
    const rect = panel.getBoundingClientRect();
    const panelW = Math.max(260, Number(rect.width || 520));
    const panelH = Math.max(180, Number(rect.height || 380));
    const margin = 10;

    let x = Number(event?.clientX);
    let y = Number(event?.clientY);
    if (!Number.isFinite(x) || !Number.isFinite(y)) {
      try {
        const noteRect = anchorEl?.getBoundingClientRect?.();
        if (noteRect) {
          x = Number(noteRect.left || 0) + (Number(noteRect.width || 0) / 2);
          y = Number(noteRect.top || 0) + (Number(noteRect.height || 0) / 2);
        }
      } catch {}
    }
    if (!Number.isFinite(x) || !Number.isFinite(y)) {
      x = viewportW / 2;
      y = viewportH / 2;
    }

    const left = Math.max(margin, Math.min(viewportW - panelW - margin, x + 14));
    const top = Math.max(margin, Math.min(viewportH - panelH - margin, y + 14));
    panel.style.left = `${Math.round(left)}px`;
    panel.style.top = `${Math.round(top)}px`;
    panel.style.transform = "none";
  }

  function setXmlNoteSourceStatus(panel, text = "", kind = "info") {
    const statusEl = panel?.querySelector?.("#twXmlNoteSourceStatus");
    if (!statusEl) return;
    const message = String(text || "").trim();
    if (!message) {
      statusEl.style.display = "none";
      statusEl.textContent = "";
      return;
    }
    statusEl.style.display = "block";
    statusEl.textContent = message;
    statusEl.style.color = kind === "error" ? "#fecaca" : (kind === "success" ? "#bbf7d0" : "#cbd5e1");
  }

  function setSelectedXmlLyricNodeBlue(container, textNode) {
    const safeContainer = container || null;
    const nextNode = textNode || null;
    if (!safeContainer) return;
    const previous = safeContainer._twXmlSelectedLyricNode || null;
    if (previous && previous !== nextNode) {
      if (previous.dataset.twXmlLyricSelectedBound === "1") {
        previous.style.fill = previous.dataset.twXmlLyricSelectedFill || "";
        previous.style.filter = previous.dataset.twXmlLyricSelectedFilter || "";
        previous.style.fontWeight = previous.dataset.twXmlLyricSelectedFontWeight || "";
      }
    }
    safeContainer._twXmlSelectedLyricNode = nextNode;
    if (!nextNode) return;
    if (nextNode.dataset.twXmlLyricSelectedBound !== "1") {
      nextNode.dataset.twXmlLyricSelectedBound = "1";
      nextNode.dataset.twXmlLyricSelectedFill = nextNode.style.fill || "";
      nextNode.dataset.twXmlLyricSelectedFilter = nextNode.style.filter || "";
      nextNode.dataset.twXmlLyricSelectedFontWeight = nextNode.style.fontWeight || "";
    }
    nextNode.style.fill = "#2563eb";
    nextNode.style.filter = "drop-shadow(0 0 2px rgba(59,130,246,0.55))";
    nextNode.style.fontWeight = "700";
  }

  function replaceMusicXmlNoteBySourceIndex(xmlText, targetSourceIndex, replacementNoteXml) {
    const safeXml = String(xmlText || "").trim();
    const safeReplacement = String(replacementNoteXml || "").trim();
    const wanted = Math.max(0, Number(targetSourceIndex || 0));
    if (!safeXml || !safeReplacement || !Number.isFinite(wanted)) return null;

    const sourceDoc = new DOMParser().parseFromString(safeXml, "application/xml");
    if (sourceDoc.getElementsByTagName("parsererror").length) return null;

    const replacementDoc = new DOMParser().parseFromString(`<root>${safeReplacement}</root>`, "application/xml");
    const replacementNote = replacementDoc.getElementsByTagNameNS("*", "note")[0] || null;
    if (!replacementNote) return null;

    const partEls = Array.from(sourceDoc.getElementsByTagNameNS("*", "part"));
    let sourceIndex = 0;
    for (let pi = 0; pi < partEls.length; pi += 1) {
      const partEl = partEls[pi];
      let transposeSemitones = 0;
      const measures = Array.from(partEl.getElementsByTagNameNS("*", "measure"));
      for (let mi = 0; mi < measures.length; mi += 1) {
        const measureEl = measures[mi];
        const nodes = Array.from(measureEl.childNodes || []).filter((n) => n && n.nodeType === 1);
        for (let ni = 0; ni < nodes.length; ni += 1) {
          const node = nodes[ni];
          const nodeName = localNameOf(node);
          if (nodeName === "attributes") {
            const nextTranspose = getTransposeSemitonesFromAttributes(node);
            if (Number.isFinite(nextTranspose)) transposeSemitones = nextTranspose;
            continue;
          }
          if (nodeName !== "note") continue;
          const isGrace = node.getElementsByTagNameNS("*", "grace").length > 0;
          const isRest = node.getElementsByTagNameNS("*", "rest").length > 0;
          if (isGrace || isRest) continue;
          const midi = musicXmlPitchToMidi(node, transposeSemitones);
          if (!Number.isFinite(midi)) continue;
          if (sourceIndex === wanted) {
            const imported = sourceDoc.importNode(replacementNote, true);
            node.parentNode?.replaceChild?.(imported, node);
            try {
              return new XMLSerializer().serializeToString(sourceDoc);
            } catch {
              return null;
            }
          }
          sourceIndex += 1;
        }
      }
    }
    return null;
  }

  async function saveEditedXmlNoteSourceFromPanel(panel) {
    if (!canUseMusicXmlInlineEditing()) {
      setXmlNoteSourceStatus(panel, "View-only mode. Admin access is required to edit.", "error");
      return false;
    }
    if (!panel) return false;
    const surrogate = String(panel.dataset.twXmlSurrogate || window.currentSurrogate || "").trim();
    const key = String(panel.dataset.twXmlFileKey || "").trim();
    const sourceIndex = Number(panel.dataset.twXmlSourceIndex || 0);
    const input = panel.querySelector("#twXmlNoteSourceCode");
    if (!surrogate || !key || !input) {
      setXmlNoteSourceStatus(panel, "Missing file metadata for save.", "error");
      return false;
    }
    const xmlState = window._pdfXmlViewState || {};
    const fileUrl = String(xmlState?.file?.url || "").trim();
    if (!fileUrl) {
      setXmlNoteSourceStatus(panel, "Cannot locate XML file URL for save.", "error");
      return false;
    }
    const editedNote = String(input.value || "").trim();
    if (!editedNote) {
      setXmlNoteSourceStatus(panel, "Note XML cannot be empty.", "error");
      return false;
    }

    setXmlNoteSourceStatus(panel, "Saving note XML...", "info");
    const saveBtn = panel.querySelector("#twXmlNoteSourceSave");
    if (saveBtn) saveBtn.disabled = true;
    try {
      const xmlText = await fetchMusicXmlTextFromUrl(fileUrl);
      const updatedXml = replaceMusicXmlNoteBySourceIndex(xmlText, sourceIndex, editedNote);
      if (!updatedXml) {
        setXmlNoteSourceStatus(panel, "Could not match note index or parse edited note XML.", "error");
        return false;
      }
      const ok = await uploadMusicXmlTextByKey(key, updatedXml);
      if (!ok) {
        setXmlNoteSourceStatus(panel, "Upload failed. Changes were not saved.", "error");
        return false;
      }
      addXmlEditHistoryEntry({
        surrogate,
        key,
        beforeXml: xmlText,
        afterXml: updatedXml,
        sourceIndex,
        partIndex: Math.max(0, Number(panel.dataset.twXmlPartIndex || 0)),
        kind: "note"
      });
      setXmlNoteSourceStatus(panel, "Saved. Re-rendering score...", "success");
      await openMusicXmlInPdfTab(surrogate, null, { setSticky: window.getPreferredScoreViewMode?.() === "xml" });
      return true;
    } catch (err) {
      setXmlNoteSourceStatus(panel, `Save failed: ${String(err?.message || err || "unknown error")}`, "error");
      return false;
    } finally {
      if (saveBtn) saveBtn.disabled = false;
    }
  }

  function showXmlNoteSourcePanel(slot, sourceIndex, event = null, anchorEl = null) {
    const xmlSnippet = String(slot?.xmlSnippet || "").trim();
    if (!xmlSnippet) return;
    const panel = getXmlNoteSourcePanel();
    const title = `MusicXML note source #${Math.max(0, Number(sourceIndex || 0))}`;
    const subtitleParts = [];
    const measureNumber = String(slot?.measureNumber || "").trim();
    if (measureNumber) subtitleParts.push(`Measure ${measureNumber}`);
    const voice = String(slot?.voice || "").trim();
    if (voice) subtitleParts.push(`Voice ${voice}`);
    const subtitle = subtitleParts.join(" | ");
    const titleEl = panel.querySelector("#twXmlNoteSourceTitle");
    const subEl = panel.querySelector("#twXmlNoteSourceSub");
    const codeEl = panel.querySelector("#twXmlNoteSourceCode");
    const isAdminEditor = canUseMusicXmlInlineEditing();
    const saveBtn = panel.querySelector("#twXmlNoteSourceSave");
    const undoBtn = panel.querySelector("#twXmlNoteSourceUndo");
    const editStaffTextBtn = panel.querySelector("#twXmlStaffTextEdit");
    if (titleEl) titleEl.textContent = title;
    if (subEl) subEl.textContent = subtitle;
    if (codeEl) codeEl.value = xmlSnippet;
    if (codeEl) codeEl.readOnly = !isAdminEditor;
    panel.dataset.twXmlSourceIndex = String(Math.max(0, Number(sourceIndex || 0)));
    panel.dataset.twXmlSurrogate = String(
      slot?._twXmlSurrogate || window._pdfXmlViewState?.surrogate || window.currentSurrogate || ""
    ).trim();
    panel.dataset.twXmlFileKey = String(window._pdfXmlViewState?.file?.key || "").trim();
    panel.dataset.twXmlPartIndex = String(Math.max(0, Number(slot?.partIndex || 0)));
    if (saveBtn) {
      saveBtn.disabled = !isAdminEditor;
      saveBtn.style.opacity = isAdminEditor ? "1" : "0.55";
      saveBtn.style.cursor = isAdminEditor ? "pointer" : "not-allowed";
    }
    if (undoBtn) {
      undoBtn.disabled = !isAdminEditor;
      undoBtn.style.opacity = isAdminEditor ? "1" : "0.55";
      undoBtn.style.cursor = isAdminEditor ? "pointer" : "not-allowed";
    }
    if (editStaffTextBtn) {
      editStaffTextBtn.disabled = !isAdminEditor;
      editStaffTextBtn.style.opacity = isAdminEditor ? "1" : "0.55";
      editStaffTextBtn.style.cursor = isAdminEditor ? "pointer" : "not-allowed";
    }
    setXmlNoteSourceStatus(panel, isAdminEditor ? "" : "View-only mode. Admin access is required to edit.");
    panel.style.display = "block";
    positionXmlNoteSourcePanel(panel, event, anchorEl);
    if (saveBtn && !saveBtn.dataset.twBound) {
      saveBtn.dataset.twBound = "1";
      saveBtn.addEventListener("click", () => {
        saveEditedXmlNoteSourceFromPanel(panel);
      });
    }
    if (editStaffTextBtn && !editStaffTextBtn.dataset.twBound) {
      editStaffTextBtn.dataset.twBound = "1";
      editStaffTextBtn.addEventListener("click", () => {
        openStaffTextEditorFromPanel(panel);
      });
    }
    if (undoBtn && !undoBtn.dataset.twBound) {
      undoBtn.dataset.twBound = "1";
      undoBtn.addEventListener("click", async () => {
        if (!canUseMusicXmlInlineEditing()) {
          setXmlNoteSourceStatus(panel, "View-only mode. Admin access is required to edit.", "error");
          return;
        }
        const panelSurrogate = String(panel.dataset.twXmlSurrogate || "").trim();
        const panelKey = String(panel.dataset.twXmlFileKey || "").trim();
        if (!panelSurrogate || !panelKey) {
          setXmlNoteSourceStatus(panel, "Missing metadata for undo.", "error");
          return;
        }
        setXmlNoteSourceStatus(panel, "Undoing last saved edit...", "info");
        undoBtn.disabled = true;
        const res = await undoLastXmlEditForFile(panelSurrogate, panelKey);
        undoBtn.disabled = false;
        if (!res?.ok) {
          setXmlNoteSourceStatus(panel, "No saved edits to undo.", "error");
          return;
        }
        setXmlNoteSourceStatus(panel, "Undo saved. Re-rendering score...", "success");
        await openMusicXmlInPdfTab(panelSurrogate, null, { setSticky: window.getPreferredScoreViewMode?.() === "xml" });
      });
    }
  }

  async function bindXmlNotePlayback(container, xmlUrl) {
    if (!container || !xmlUrl) return 0;
    container._twXmlPlayableNoteEls = [];
    container._twXmlPlayableNoteElsBySourceIndex = {};
    container._twXmlPlaybackModel = null;
    const xmlText = await fetchMusicXmlTextFromUrl(xmlUrl);
    const measureScaleMap = parseMusicXmlMeasureScaleMap(xmlText);
    container._twXmlMeasureScaleByIndex = measureScaleMap;
    const xmlScaleValue = inferMusicXmlScaleValue(xmlText);
    container._twXmlScaleValue = xmlScaleValue || "";
    if (xmlScaleValue) {
      window.TWPianoDock?.setScale?.(xmlScaleValue, { persist: false });
    }
    const parsed = parseMusicXmlPlaybackEvents(xmlText);
    const noteSlots = Array.isArray(parsed.noteSlots) ? parsed.noteSlots : [];
    if (!noteSlots.length) return 0;
    const stateHost = container?._twXmlStateHost || container;
    const safeSurrogate = String(
      stateHost?._twXmlSurrogate ||
      window._pdfXmlViewState?.surrogate ||
      window.currentSurrogate ||
      ""
    ).trim();
    const canInlineEdit = canUseMusicXmlInlineEditing();
    if (typeof container._twXmlNoteLongPressCleanup === "function") {
      try { container._twXmlNoteLongPressCleanup(); } catch {}
      container._twXmlNoteLongPressCleanup = null;
    }
    const noteLongPressCleanups = [];
    container._twXmlPlaybackModel = parsed;
    container._twXmlMeasureStats = window.twMusicXmlView?.getXmlMeasureStats?.(parsed) || {
      total: 0,
      positionByIndex: {},
      numberByIndex: {}
    };
    if (stateHost && stateHost !== container) {
      stateHost._twXmlMeasureStats = container._twXmlMeasureStats;
    }
    parsed.measureScaleByIndex = measureScaleMap;
    container._twXmlLyricNumbersByPart = {};
    try {
      parsed.tracks.forEach((track, idx) => {
        container._twXmlLyricNumbersByPart[String(idx)] = collectPartLyricNumbers(new DOMParser().parseFromString(xmlText, "application/xml"), idx);
      });
    } catch {}
    if (safeSurrogate) {
      ensureTrackPlaybackStates(safeSurrogate, parsed.playbackTracks || parsed.tracks || []);
      window.TWPianoDock?.refreshXmlMixer?.(safeSurrogate);
    }

    // Right-click on rendered lyric text opens staff text editor for that staff line.
    try {
      const previousTextHandler = container._twXmlLyricContextHandler;
      if (previousTextHandler) {
        container.removeEventListener("contextmenu", previousTextHandler, true);
      }
      const fileKey = String(window._pdfXmlViewState?.file?.key || "").trim();
      const fileUrl = String(window._pdfXmlViewState?.file?.url || xmlUrl || "").trim();
      const lyricContextHandler = (event) => {
        if (!canInlineEdit) return;
        const target = event?.target;
        if (!target || typeof target.closest !== "function") return;
        const textNode = target.closest("text, tspan");
        if (!textNode) return;
        const textContent = String(textNode.textContent || "").trim();
        if (!textContent) return;

        // Skip score header/editor labels and limit this action to likely lyric lines.
        const lower = textContent.toLowerCase();
        if (
          lower === "musicxml" ||
          /^track\s+\d+/.test(lower) ||
          lower.startsWith("arranger:") ||
          lower.startsWith("electronic scores by")
        ) {
          return;
        }

        let partIndex = 0;
        const rows = Array.isArray(container?._twXmlStafflineEntries) ? container._twXmlStafflineEntries : [];
        if (rows.length) {
          const clickY = Number(event.clientY || 0);
          let bestDist = Infinity;
          rows.forEach((row) => {
            const rowEl = row?.el;
            if (!rowEl || typeof rowEl.getBoundingClientRect !== "function") return;
            const rect = rowEl.getBoundingClientRect();
            const centerY = Number(rect.top || 0) + (Number(rect.height || 0) / 2);
            const dist = Math.abs(centerY - clickY);
            if (dist < bestDist) {
              bestDist = dist;
              partIndex = Math.max(0, Number(row?.partIndex || 0));
            }
          });
        }

        const lyricNumber = resolveRenderedLyricLaneNumber(container, textNode, partIndex, Number(event.clientY || 0));
        if (!safeSurrogate || !fileKey || !fileUrl) return;
        event.preventDefault();
        event.stopPropagation();
        setSelectedXmlLyricNodeBlue(container, textNode);
        openStaffTextEditorForPart({
          surrogate: safeSurrogate,
          key: fileKey,
          fileUrl,
          partIndex,
          lyricNumber
        });
      };
      container._twXmlLyricContextHandler = lyricContextHandler;
      container.addEventListener("contextmenu", lyricContextHandler, true);

      if (typeof container._twXmlLyricLongPressCleanup === "function") {
        try { container._twXmlLyricLongPressCleanup(); } catch {}
        container._twXmlLyricLongPressCleanup = null;
      }
      const lyricLongPressCleanup = bindLongPressGesture(container, (pressEvent) => {
        if (!canInlineEdit) return;
        const touch = pressEvent?.touches?.[0] || pressEvent?.changedTouches?.[0] || null;
        const x = Number(touch?.clientX || 0);
        const y = Number(touch?.clientY || 0);
        let target = null;
        if (document.elementFromPoint && Number.isFinite(x) && Number.isFinite(y)) {
          target = document.elementFromPoint(x, y);
        }
        const textNode = target?.closest?.("text, tspan") || null;
        if (!textNode) return;
        const fakeEvent = {
          target: textNode,
          clientY: y,
          preventDefault() {},
          stopPropagation() {}
        };
        lyricContextHandler(fakeEvent);
      }, { thresholdMs: 580, moveTolerance: 12 });
      container._twXmlLyricLongPressCleanup = lyricLongPressCleanup;
    } catch {}
    window.twMusicXmlView?.updateXmlPlaybackMeasureIndicator?.(container);

    const hotspotLayer = window.twMusicXmlView?.createHtmlNoteHotspotLayer?.(container) || null;
    const playableNoteEls = [];
    const playableBySourceIndex = {};
    const slotMetaBySourceIndex = {};
    const slotBindings = [];
    const renderedBindings = window.twMusicXmlView?.getRenderedNoteheadBindings?.(container, noteSlots) || new Map();
    const resolveBindingFromEvent = (event, fallbackEl, fallbackSlot, fallbackClickMidi, fallbackPlaybackSourceIndex, fallbackSourceIndex) => {
      let effectiveEl = fallbackEl;
      const x = Number(event?.clientX);
      const y = Number(event?.clientY);
      if (Number.isFinite(x) && Number.isFinite(y)) {
        let bestEl = null;
        let bestScore = Infinity;
        const candidates = Array.isArray(container?._twXmlPlayableNoteEls) ? container._twXmlPlayableNoteEls : [];
        candidates.forEach((candidate) => {
          if (!candidate || typeof candidate.getBoundingClientRect !== "function") return;
          let rect = null;
          try {
            rect = candidate.getBoundingClientRect();
          } catch {
            rect = null;
          }
          if (!rect || !(rect.width > 0) || !(rect.height > 0)) return;
          const centerX = Number(rect.left || 0) + (Number(rect.width || 0) / 2);
          const centerY = Number(rect.top || 0) + (Number(rect.height || 0) / 2);
          const dx = centerX - x;
          const dy = centerY - y;
          const distance = Math.hypot(dx, dy);
          const inside =
            x >= (Number(rect.left || 0) - 10) &&
            x <= (Number(rect.right || 0) + 10) &&
            y >= (Number(rect.top || 0) - 10) &&
            y <= (Number(rect.bottom || 0) + 10);
          const score = inside ? distance : (distance + 24);
          if (score < bestScore) {
            bestScore = score;
            bestEl = candidate;
          }
        });
        if (bestEl && bestScore <= 42) {
          effectiveEl = bestEl;
        }
      }
      const effectiveSourceIndex = Math.max(0, Number(effectiveEl?.dataset?.twXmlSourceIndex || fallbackSourceIndex || 0));
      const meta = slotMetaBySourceIndex[String(effectiveSourceIndex)] || {};
      return {
        el: effectiveEl,
        slot: meta.slot || fallbackSlot,
        clickMidi: Number.isFinite(Number(meta.clickMidi)) ? Number(meta.clickMidi) : Number(fallbackClickMidi),
        playbackSourceIndex: Number.isFinite(Number(meta.playbackSourceIndex))
          ? Number(meta.playbackSourceIndex)
          : Number(fallbackPlaybackSourceIndex),
        sourceIndex: effectiveSourceIndex
      };
    };
    for (let i = 0; i < noteSlots.length; i += 1) {
      const slot = noteSlots[i];
      const el = renderedBindings.get(Number(slot?.sourceIndex || 0)) || null;
      if (!el) continue;
      const midi = Number(slot?.midi);
      const rawClickMidi = Number.isFinite(Number(slot?.clickMidi)) ? Number(slot.clickMidi) : midi;
      const clickMidi = Number.isFinite(rawClickMidi)
        ? Math.max(0, Math.min(127, rawClickMidi + getXmlRenderTransposeSemitones()))
        : rawClickMidi;
      if (!Number.isFinite(midi)) continue;
      const sourceIndex = Number(slot?.sourceIndex);
      const playbackSourceIndex = Number(slot?.playbackSourceIndex);
      const slotVoice = String(slot?.voice || "").trim();
      delete el.dataset.twXmlMidi;
      el.dataset.twXmlMidi = String(clickMidi);
      el.dataset.twXmlSourceIndex = String(Number(slot?.sourceIndex || 0));
      el.dataset.twXmlPlaybackSourceIndex = String(
        Number.isFinite(playbackSourceIndex) ? playbackSourceIndex : Number(slot?.sourceIndex || 0)
      );
      el.dataset.twXmlVoice = slotVoice || "1";
      el.dataset.twXmlMeasureKey = window.twMusicXmlView?.getXmlMeasureKey?.(slot) || "";
      el.dataset.twXmlNoteRoot = "1";
      el._twXmlStateHost = container;
      el.classList.toggle("tw-xml-voice-lower", slotVoice === "2");
      el.style.cursor = "pointer";
      el.style.pointerEvents = "bounding-box";
      try {
        el.setAttribute("pointer-events", "bounding-box");
      } catch {}
      slotMetaBySourceIndex[String(Number(slot?.sourceIndex || 0))] = {
        el,
        slot,
        clickMidi,
        playbackSourceIndex: Number.isFinite(playbackSourceIndex) ? playbackSourceIndex : Number(slot?.sourceIndex || 0)
      };
      const handlePrewarm = () => {
        window.TWPianoDock?.prepareScheduledPlayback?.({ open: false }).catch(() => {});
      };
      const handleSelect = (event) => {
        if (event && "button" in event && Number(event.button || 0) !== 0) return;
        const resolved = resolveBindingFromEvent(
          event,
          el,
          slot,
          clickMidi,
          Number.isFinite(playbackSourceIndex) ? playbackSourceIndex : sourceIndex,
          sourceIndex
        );
        const activeEl = resolved.el || el;
        const activeSlot = resolved.slot || slot;
        const clickedMeasureKey = String(activeEl.dataset.twXmlMeasureKey || "");
        window.twMusicXmlView?.clearSelectedMeasureHighlight?.(container);
        const selectedPlaybackSourceIndex = Number.isFinite(Number(resolved.playbackSourceIndex))
          ? Number(resolved.playbackSourceIndex)
          : Math.max(0, Number(sourceIndex || 0));
        const clickedSystemBlockKey =
          window.twMusicXmlView?.getXmlSystemBlockKeyForMeasureKey?.(container, clickedMeasureKey, stateHost) || "";
        if (stateHost) stateHost._twXmlSelectedSystemBlockKey = clickedSystemBlockKey;
        window.twMusicXmlView?.setSelectedXmlNote?.(
          container,
          activeEl,
          selectedPlaybackSourceIndex
        );
        const playheadMeta = stateHost?._twXmlPlayheadMeta || container?._twXmlPlayheadMeta || null;
        const selectedStartTick = Math.max(
          0,
          Number(playheadMeta?.sourceIndexToStartTick?.[String(Math.max(0, Number(selectedPlaybackSourceIndex || 0)))] || 0)
        );
        if (stateHost) stateHost._twXmlSelectedStartTick = selectedStartTick;
      };
      const handlePressActivate = (event) => {
        if (!event || (event.pointerType && event.pointerType === "touch")) return;
        if (event && "button" in event && Number(event.button || 0) !== 0) return;
        el._twXmlSuppressClickUntil = Date.now() + 700;
        const resolved = resolveBindingFromEvent(
          event,
          el,
          slot,
          clickMidi,
          Number.isFinite(playbackSourceIndex) ? playbackSourceIndex : sourceIndex,
          sourceIndex
        );
        handleSelect(event);
        requestAnimationFrame(() => {
          handlePrewarm();
          Promise.resolve(
            window.twMusicXmlPlay?.playMidiPitch?.(resolved.clickMidi, {
              ignoreSustain: true,
              open: false,
              center: false
            })
          ).catch((err) => {
            console.warn("MusicXML click playback failed:", err);
            return false;
          }).then(() => {
            const clickedScale =
              String(container?._twXmlMeasureScaleByIndex?.[String(Number(resolved.slot?.measureIndex || slot?.measureIndex || 0))] || "") ||
              getScaleValueFromMidi(resolved.clickMidi, "maj");
            if (clickedScale) {
              window.TWPianoDock?.setScale?.(clickedScale, { persist: false });
            }
          });
        });
      };
      const handleTouchActivate = (event) => {
        if (!event) return;
        if (event.pointerType && event.pointerType !== "touch") return;
        if (event._twXmlTouchHandled) return;
        event._twXmlTouchHandled = true;
        handleActivate(event);
      };
      const handleActivate = async (event) => {
        if (event && "button" in event && Number(event.button || 0) !== 0) return;
        if (Number(el._twXmlSuppressClickUntil || 0) > Date.now()) {
          event.preventDefault();
          event.stopPropagation();
          return;
        }
        const suppressUntil = Number(el._twSuppressActivateUntil || 0);
        if (suppressUntil && Date.now() < suppressUntil) {
          event?.preventDefault?.();
          event?.stopPropagation?.();
          return;
        }
        event.preventDefault();
        event.stopPropagation();
        const resolved = resolveBindingFromEvent(
          event,
          el,
          slot,
          clickMidi,
          Number.isFinite(playbackSourceIndex) ? playbackSourceIndex : sourceIndex,
          sourceIndex
        );
        const playPromise = Promise.resolve(
          window.twMusicXmlPlay?.playMidiPitch?.(resolved.clickMidi, {
            ignoreSustain: true,
            open: false,
            center: false
          })
        ).catch((err) => {
          console.warn("MusicXML click playback failed:", err);
          return false;
        });
        await playPromise;
        const clickedScale =
          String(container?._twXmlMeasureScaleByIndex?.[String(Number(resolved.slot?.measureIndex || slot?.measureIndex || 0))] || "") ||
          getScaleValueFromMidi(resolved.clickMidi, "maj");
        if (clickedScale) {
          window.TWPianoDock?.setScale?.(clickedScale, { persist: false });
        }
        if (window.twMusicXmlPlay?.isXmlPlaybackActive?.(window.currentSurrogate)) {
          const activeFile = window._pdfXmlViewState?.file || null;
          Promise.resolve(window.twMusicXmlPlay?.playXmlSequence?.(window.currentSurrogate, activeFile)).catch((err) => {
            console.warn("MusicXML jump playback failed:", err);
          });
          return;
        }
      };
      const handleContextMenu = (event) => {
        event.preventDefault();
        event.stopPropagation();
        const resolved = resolveBindingFromEvent(
          event,
          el,
          slot,
          clickMidi,
          Number.isFinite(playbackSourceIndex) ? playbackSourceIndex : sourceIndex,
          sourceIndex
        );
        window.twMusicXmlView?.setSelectedXmlNote?.(
          container,
          resolved.el || el,
          Number.isFinite(Number(resolved.playbackSourceIndex)) ? Number(resolved.playbackSourceIndex) : sourceIndex
        );
        showXmlNoteSourcePanel(resolved.slot || slot, resolved.sourceIndex, event, resolved.el || el);
      };
      const handleLongPress = (event) => {
        if (!canInlineEdit) return;
        const now = Date.now();
        el._twSuppressActivateUntil = now + 700;
        event?.preventDefault?.();
        event?.stopPropagation?.();
        const resolved = resolveBindingFromEvent(
          event,
          el,
          slot,
          clickMidi,
          Number.isFinite(playbackSourceIndex) ? playbackSourceIndex : sourceIndex,
          sourceIndex
        );
        window.twMusicXmlView?.setSelectedXmlNote?.(
          container,
          resolved.el || el,
          Number.isFinite(Number(resolved.playbackSourceIndex)) ? Number(resolved.playbackSourceIndex) : sourceIndex
        );
        showXmlNoteSourcePanel(resolved.slot || slot, resolved.sourceIndex, event, resolved.el || el);
      };
      el._twXmlActivate = handleActivate;
      el._twXmlSelect = handleSelect;
      el.addEventListener("pointerdown", handlePressActivate, { passive: true });
      el.addEventListener("pointerdown", handleSelect, { passive: true });
      el.addEventListener("pointerdown", handlePrewarm, { passive: true });
      el.addEventListener("pointerup", handleTouchActivate);
      el.addEventListener("touchend", handleTouchActivate);
      el.addEventListener("contextmenu", handleContextMenu);
      noteLongPressCleanups.push(bindLongPressGesture(el, handleLongPress, { thresholdMs: 820, moveTolerance: 14, mouse: false }));
      playableNoteEls.push(el);
      playableBySourceIndex[String(Number(slot.sourceIndex || 0))] = el;
      slotBindings.push({ el, slot });
    }
    container._twXmlPlayableNoteEls = playableNoteEls;
    container._twXmlPlayableNoteElsBySourceIndex = playableBySourceIndex;
    if (typeof container._twXmlTouchActivateCleanup === "function") {
      try { container._twXmlTouchActivateCleanup(); } catch {}
      container._twXmlTouchActivateCleanup = null;
    }
    if (playableNoteEls.length) {
      const handleContainerTouchEnd = (event) => {
        if (!event || event._twXmlTouchHandled) return;
        const touch = event.changedTouches?.[0] || event.touches?.[0] || null;
        if (!touch) return;
        const fallbackEl = playableNoteEls[0] || null;
        const fallbackSourceIndex = Number(fallbackEl?.dataset?.twXmlSourceIndex || 0);
        const fallbackMeta = slotMetaBySourceIndex[String(fallbackSourceIndex)] || {};
        const syntheticEvent = {
          clientX: Number(touch.clientX || 0),
          clientY: Number(touch.clientY || 0),
          preventDefault() { try { event.preventDefault(); } catch {} },
          stopPropagation() { try { event.stopPropagation(); } catch {} }
        };
        const resolved = resolveBindingFromEvent(
          syntheticEvent,
          fallbackEl,
          fallbackMeta.slot || noteSlots[0] || null,
          Number.isFinite(Number(fallbackMeta.clickMidi)) ? Number(fallbackMeta.clickMidi) : 0,
          Number.isFinite(Number(fallbackMeta.playbackSourceIndex)) ? Number(fallbackMeta.playbackSourceIndex) : fallbackSourceIndex,
          fallbackSourceIndex
        );
        const targetEl = resolved?.el || null;
        if (!targetEl || typeof targetEl._twXmlActivate !== "function") return;
        event._twXmlTouchHandled = true;
        if (typeof targetEl._twXmlSelect === "function") {
          targetEl._twXmlSelect(syntheticEvent);
        }
        targetEl._twXmlActivate(syntheticEvent);
      };
      container.addEventListener("touchend", handleContainerTouchEnd, true);
      container._twXmlTouchActivateCleanup = () => {
        container.removeEventListener("touchend", handleContainerTouchEnd, true);
      };
    }
    window.twMusicXmlView?.buildMeasureHighlights?.(container, slotBindings);
    window.twMusicXmlView?.applyXmlTrackFocusVisual?.(stateHost || container, safeSurrogate);
    applyXmlEditedVisuals(
      container,
      safeSurrogate,
      String(window._pdfXmlViewState?.file?.key || "").trim()
    );
    container._twXmlNoteLongPressCleanup = () => {
      noteLongPressCleanups.forEach((fn) => {
        try { fn?.(); } catch {}
      });
    };
    return playableNoteEls.length;
  }

  async function renderMusicXmlInto(container, xmlUrl, opts = {}) {
    if (!container || !xmlUrl) return false;
    container._twXmlLastRenderBlockedByWidth = false;

    const ready = await ensureMusicXmlViewerLibs();
    if (!ready) return false;

    try {
      container.style.overflow = "visible";
      container.style.height = "auto";
      container.style.minHeight = "0";
      container.innerHTML = `
        <div data-tw-xml-kicker="1" style="padding:2px 6px 0 6px; font-size:10px; font-weight:600; letter-spacing:0.03em; line-height:1; color:rgba(15,23,42,0.62); text-transform:lowercase; user-select:none;">musicxml</div>
        <div data-tw-xml-zoom-viewport="1" style="overflow:auto; max-width:100%; padding:0 0 calc(var(--tw-piano-open-height, 0px) + 260px) 0; -webkit-overflow-scrolling:touch;">
          <div data-tw-xml-zoom-surface="1" style="display:inline-block; transform-origin:top left;">
            <div data-tw-xml-render-root="1"></div>
          </div>
        </div>
      `;
      attachXmlZoomControls(container);
      applyXmlZoom(container, Number(container.dataset.twXmlZoom || 1));

      const renderRoot = container.querySelector("[data-tw-xml-render-root='1']");
      if (!renderRoot) return false;
      const zoomViewport = container.querySelector("[data-tw-xml-zoom-viewport='1']");
      const zoomSurface = container.querySelector("[data-tw-xml-zoom-surface='1']");
      if (zoomViewport) zoomViewport.style.background = "#ffffff";
      if (zoomSurface) zoomSurface.style.background = "#ffffff";
      if (zoomSurface) {
        zoomSurface.style.webkitUserSelect = "none";
        zoomSurface.style.userSelect = "none";
        zoomSurface.style.webkitTouchCallout = "none";
      }
      renderRoot.style.background = "#ffffff";
      renderRoot.style.webkitUserSelect = "none";
      renderRoot.style.userSelect = "none";
      renderRoot.style.webkitTouchCallout = "none";
      renderRoot._twXmlStateHost = container;
      const hostWidth = await waitForXmlRenderHostWidth(container, { timeoutMs: 1200, minWidth: 40 });
      if (!(hostWidth > 0)) {
        container._twXmlLastRenderBlockedByWidth = true;
        return false;
      }
      const layoutScale = clampXmlLayoutScale(Number(container.dataset.twXmlLayoutScale || getXmlLayoutScale()));
      container.dataset.twXmlLayoutScale = String(layoutScale);
      const renderWidth = Math.max(360, getXmlRenderWidth(container, layoutScale));
      container.dataset.twXmlLayoutWidth = String(renderWidth);
      renderRoot.style.width = `${renderWidth}px`;
      renderRoot.style.maxWidth = "none";

      const osmd = new window.opensheetmusicdisplay.OpenSheetMusicDisplay(
        renderRoot,
        getResolvedOsmdRenderOptions()
      );
      const xmlText =
        typeof opts?.xmlTextOverride === "string" && opts.xmlTextOverride.trim()
          ? String(opts.xmlTextOverride)
          : await fetchMusicXmlTextFromUrl(xmlUrl);
      container.dataset.twXmlUiRenderPartId = "";
      const visualTranspose = getXmlRenderTransposeSemitones();
      container.dataset.twXmlRenderTranspose = String(visualTranspose);
      const sanitizedXmlText = sanitizeMusicXmlForRender(xmlText);
      await osmd.load(sanitizedXmlText || xmlText || xmlUrl);
      applyOsmdVisualTranspose(osmd, visualTranspose);
      await osmd.render();
      Array.from(renderRoot.querySelectorAll("svg")).forEach((svg) => {
        svg.style.background = "#ffffff";
        svg.style.display = "block";
        svg.style.webkitUserSelect = "none";
        svg.style.userSelect = "none";
        svg.style.webkitTouchCallout = "none";
      });
      renderRoot._twXmlOsmd = osmd;
      container._twXmlOsmd = osmd;
      normalizeRenderedXmlTitle(renderRoot);
      augmentRenderedXmlCredits(renderRoot, sanitizedXmlText || xmlText || "");
      trimRenderedXmlSvgMargins(renderRoot);
      applyXmlZoom(container, Number(container.dataset.twXmlZoom || 1));
      container.dataset.xmlUrl = String(xmlUrl);
      try {
        await bindXmlNotePlayback(renderRoot, xmlUrl);
        window.twMusicXmlView?.buildOsmdMeasureFrames?.(renderRoot, osmd);
        container._twXmlPlaybackModel = renderRoot._twXmlPlaybackModel || null;
      container._twXmlPlayheadMeta = window.twMusicXmlView?.buildXmlPlayheadMeta?.(container._twXmlPlaybackModel || null) || {
          measureTimeline: [],
          totalTicks: 0,
          sourceIndexToStartTick: {},
          measureSourceIndexByIndex: {},
          measureStartTickByIndex: {}
        };
        container._twXmlPlayableNoteEls = renderRoot._twXmlPlayableNoteEls || [];
        container._twXmlPlayableNoteElsBySourceIndex = renderRoot._twXmlPlayableNoteElsBySourceIndex || {};
        container._twXmlMeasureKeyBySourceIndex = renderRoot._twXmlMeasureKeyBySourceIndex || {};
        container._twXmlMeasureRects = renderRoot._twXmlMeasureRects || {};
        container._twXmlMeasureFrames = renderRoot._twXmlMeasureFrames || {};
        container._twXmlMeasureGroupFrames = renderRoot._twXmlMeasureGroupFrames || {};
        container._twXmlMeasureGroupFramesByGroupKey = renderRoot._twXmlMeasureGroupFramesByGroupKey || {};
        container._twXmlMeasureKeyByGroupFrameKey = renderRoot._twXmlMeasureKeyByGroupFrameKey || {};
        container._twXmlSystemFramesByBlockKey = renderRoot._twXmlSystemFramesByBlockKey || {};
        container._twXmlRenderedMeasureFramesByNumber = renderRoot._twXmlRenderedMeasureFramesByNumber || {};
        container._twXmlSystemBlockKeyByMeasureKey = renderRoot._twXmlSystemBlockKeyByMeasureKey || {};
        container._twXmlSystemBlockKeyBySourceIndex = renderRoot._twXmlSystemBlockKeyBySourceIndex || {};
        container._twXmlOsmdMeasureFramesByIndex = renderRoot._twXmlOsmdMeasureFramesByIndex || {};
        container._twXmlPlaybackVisualMap = renderRoot._twXmlPlaybackVisualMap || {
          measureFramesByKey: {},
          measureKeyBySourceIndex: {},
          systemBlockByMeasureKey: {}
        };
        container._twXmlPlayhead = renderRoot._twXmlPlayhead || renderRoot._twXmlProgressLine || null;
        container._twXmlProgressLine = container._twXmlPlayhead;
      container._twXmlActiveMeasureKey = renderRoot._twXmlActiveMeasureKey || "";
      container._twXmlSelectedMeasureKey = renderRoot._twXmlSelectedMeasureKey || "";
      container._twXmlSelectedSourceIndex = null;
      container._twXmlSelectedStartTick = null;
      container._twXmlHasExplicitStart = false;
      window.twMusicXmlView?.updateXmlPlayheadIndicator?.(container, { active: false, overallProgress: 0, measureProgress: 0, measureIndex: 0 });
      } catch (err) {
        console.warn("MusicXML note click binding failed:", err);
      }
      return true;
    } catch (err) {
      console.warn("MusicXML render failed:", err);
      return false;
    }
  }

  function setXmlViewState(active, surrogate = "", file = null) {
    window._pdfXmlViewState = {
      active: !!active,
      surrogate: String(surrogate || ""),
      file: file || null
    };
    const host = document.getElementById("pdfTabContent");
    const marginWrap = document.getElementById("pdfMarginWrapper");
    const drawingPalette = document.getElementById("drawingPalette");
    const annotationToolbar = document.getElementById("annotationToolbar");
    if (host) {
      if (active) {
        host.style.padding = "0";
        host.style.paddingBottom = "calc(var(--pdf-bottom-clearance, var(--app-footer-clearance) + 56px) + var(--tw-piano-open-height, 0px) + 520px)";
        host.style.background = "#ffffff";
      } else {
        host.style.removeProperty("padding");
        host.style.removeProperty("padding-bottom");
        host.style.removeProperty("background");
      }
    }
    if (marginWrap) {
      marginWrap.style.removeProperty("display");
    }
    if (drawingPalette) {
      if (active) {
        drawingPalette.style.display = "none";
      } else if (document.body.classList.contains("edit-mode") && window.currentActiveTab === "pdfTab") {
        drawingPalette.style.display = "flex";
      } else {
        drawingPalette.style.removeProperty("display");
      }
    }
    if (annotationToolbar) {
      if (active) {
        annotationToolbar.style.display = "none";
      } else if (document.body.classList.contains("edit-mode") && window.currentActiveTab === "pdfTab") {
        annotationToolbar.style.display = "flex";
      } else {
        annotationToolbar.style.removeProperty("display");
      }
    }
    Promise.resolve(syncXmlEditToolbar(String(surrogate || window.currentSurrogate || "").trim())).catch(() => {});
  }

  async function openMusicXmlInPdfTab(surrogate, file = null, opts = {}) {
    const safeSurrogate = String(surrogate || window.currentSurrogate || "").trim();
    if (!safeSurrogate) return false;
    setStoredXmlRenderTransposeSemitones(0);

    const targetFile = file || (await getPrimaryMusicXmlFile(safeSurrogate));
    if (!targetFile?.url) return false;
    const itemEl = document.querySelector(`.list-sub-item[data-value="${safeSurrogate}"]`);
    const owner = String(itemEl?.dataset?.owner || window.currentItemOwner || window.pdfState?.owner || "").trim() || null;

    const container = document.getElementById("pdfTabContent");
    if (!container) return false;

    if (opts?.setSticky !== false) {
      window.setPreferredScoreViewMode?.("xml");
    }
    window.pdfState = { pdf: null, owner, surrogate: safeSurrogate, page: 1 };
    window.activePdfSurrogate = safeSurrogate;
    setXmlViewState(true, safeSurrogate, targetFile);
    if (window.currentActiveTab !== "pdfTab") {
      window.switchTab?.("pdfTab");
    }
    document.getElementById("pdfMusicXmlBadge")?.remove();
    container.style.overflowY = "auto";
    container.style.overflowX = "hidden";
    container.style.height = "auto";
    container.style.maxHeight = "100%";
    container.style.display = "block";
    container.innerHTML = `
      <div id="pdfTabXmlViewer"
        style="padding:0 0 calc(var(--tw-piano-open-height, 0px) + 560px) 0; min-height:calc(100vh - 140px); background:#ffffff; overflow:visible;"></div>
    `;

    const mount = document.getElementById("pdfTabXmlViewer");
    if (!mount) return false;
    mount._twXmlSurrogate = safeSurrogate;
    mount.dataset.twXmlLayoutScale = String(getXmlLayoutScale());

    if (window.twMusicXmlView?.syncPdfXmlToggleButton) {
      window.twMusicXmlView.syncPdfXmlToggleButton(safeSurrogate);
    }
    if (window.twMusicXmlView?.syncPdfXmlPlayButton) {
      window.twMusicXmlView.syncPdfXmlPlayButton(safeSurrogate);
    }
    await syncXmlEditToolbar(safeSurrogate);
    mount.innerHTML = `<div style="padding:12px 8px; font-size:13px; color:#475569;">Loading MusicXML…</div>`;
    let ok = false;
    for (let attempt = 0; attempt < 5; attempt += 1) {
      ok = await renderMusicXmlInto(mount, targetFile.url, {
        xmlTextOverride: attempt === 0 ? opts?.xmlTextOverride : ""
      });
      if (ok) break;
      if (!mount._twXmlLastRenderBlockedByWidth) break;
      if (attempt < 4) {
        await waitMs(160);
      }
    }
    if (!ok) {
      setXmlViewState(true, safeSurrogate, targetFile);
      mount.innerHTML = `
        <div style="padding:12px 8px; color:#991b1b; font-size:13px;">
          MusicXML view failed. <a href="${targetFile.url}" target="_blank" rel="noopener">Open file directly</a>
        </div>
      `;
      if (window.twMusicXmlView?.syncPdfXmlToggleButton) {
        window.twMusicXmlView.syncPdfXmlToggleButton(safeSurrogate);
      }
      if (window.twMusicXmlView?.syncPdfXmlPlayButton) {
        window.twMusicXmlView.syncPdfXmlPlayButton(safeSurrogate);
      }
      await syncXmlEditToolbar(safeSurrogate);
      window.TWPianoDock?.refreshXmlMixer?.(safeSurrogate);
      return true;
    }
    const parsedModel = mount._twXmlPlaybackModel || null;
    if (parsedModel?.playbackTracks?.length || parsedModel?.tracks?.length) {
      ensureTrackPlaybackStates(safeSurrogate, parsedModel.playbackTracks || parsedModel.tracks || []);
    }
    try {
      await window.twMusicXmlPlay?.ensureTrackStatesReady?.(safeSurrogate);
    } catch {}
    window.TWPianoDock?.refreshXmlMixer?.(safeSurrogate);
    return true;
  }

  async function rerenderOpenMusicXmlView() {
    const viewer = document.getElementById("pdfTabXmlViewer");
    const xmlState = window._pdfXmlViewState || {};
    const safeSurrogate = String(xmlState.surrogate || window.currentSurrogate || "").trim();
    const xmlUrl = String(viewer?.dataset?.xmlUrl || xmlState.file?.url || "").trim();
    if (!viewer || !xmlUrl) return false;
    viewer.dataset.twXmlLayoutScale = String(getXmlLayoutScale());
    const selectedMeasureKey = String(viewer._twXmlSelectedMeasureKey || "");
    const selectedSourceIndex = viewer._twXmlSelectedSourceIndex;
    const wasPlaying = !!window.twMusicXmlPlay?.isXmlPlaybackActive?.(safeSurrogate);
    if (wasPlaying) {
      window.twMusicXmlPlay?.stopXmlPlayback?.();
    }
    let ok = false;
    for (let attempt = 0; attempt < 5; attempt += 1) {
      ok = await renderMusicXmlInto(viewer, xmlUrl);
      if (ok) break;
      if (!viewer._twXmlLastRenderBlockedByWidth) break;
      if (attempt < 4) {
        await waitMs(160);
      }
    }
    if (!ok) return false;
    if (selectedMeasureKey) window.twMusicXmlView?.clearSelectedMeasureHighlight?.(viewer);
    if (Number.isFinite(Number(selectedSourceIndex))) {
      const noteEl = viewer._twXmlPlayableNoteElsBySourceIndex?.[String(Number(selectedSourceIndex))] || null;
      if (noteEl) window.twMusicXmlView?.setSelectedXmlNote?.(viewer, noteEl, Number(selectedSourceIndex));
    }
    if (wasPlaying && safeSurrogate) {
      try { await window.twMusicXmlPlay?.playXmlSequence?.(safeSurrogate, xmlState.file || null); } catch {}
    }
    return true;
  }

  async function setXmlLayoutScale(value, opts = {}) {
    const next = setStoredXmlLayoutScale(value);
    const shouldRerender = opts?.rerender !== false;
    if (shouldRerender && document.getElementById("pdfTabXmlViewer")) {
      await rerenderOpenMusicXmlView();
    }
    return next;
  }

  async function setXmlRenderTransposeSemitones(value, opts = {}) {
    const next = setStoredXmlRenderTransposeSemitones(value);
    const shouldRerender = opts?.rerender !== false;
    if (shouldRerender && document.getElementById("pdfTabXmlViewer")) {
      await rerenderOpenMusicXmlView();
    }
    return next;
  }

  async function setOsmdRenderOptions(options, opts = {}) {
    const next = setStoredOsmdRenderOptions(options, opts);
    const shouldRerender = opts?.rerender !== false;
    if (shouldRerender && document.getElementById("pdfTabXmlViewer")) {
      await rerenderOpenMusicXmlView();
    }
    return next;
  }

  async function resetOsmdRenderOptions(opts = {}) {
    resetStoredOsmdRenderOptions();
    const shouldRerender = opts?.rerender !== false;
    if (shouldRerender && document.getElementById("pdfTabXmlViewer")) {
      await rerenderOpenMusicXmlView();
    }
    return getDefaultOsmdRenderOptions();
  }

  async function refreshPdfTabXmlState(surrogate) {
    const safeSurrogate = String(surrogate || window.currentSurrogate || "").trim();
    const container = document.getElementById("pdfTabContent");
    const viewer = container?.querySelector("#pdfTabXmlViewer") || null;
    const xmlState = window._pdfXmlViewState || {};
    document.getElementById("pdfMusicXmlBadge")?.remove();
    if (window.twMusicXmlView?.syncPdfXmlToggleButton) {
      window.twMusicXmlView.syncPdfXmlToggleButton(safeSurrogate);
    }
    if (window.twMusicXmlView?.syncPdfXmlPlayButton) {
      window.twMusicXmlView.syncPdfXmlPlayButton(safeSurrogate);
    }
    await syncXmlEditToolbar(safeSurrogate);
    window.TWPianoDock?.refreshXmlMixer?.(safeSurrogate);
    if (viewer) {
      const model = viewer._twXmlPlaybackModel;
      if (model?.playbackTracks?.length || model?.tracks?.length) {
        ensureTrackPlaybackStates(safeSurrogate, model.playbackTracks || model.tracks || []);
      }
    }
    if (!safeSurrogate) return null;
    if (!container) return null;

    let file = null;
    try {
      file = await getPrimaryMusicXmlFile(safeSurrogate);
    } catch (err) {
      console.warn("MusicXML state check failed:", err);
      return null;
    }
    if (viewer) {
      const viewIsActiveForSurrogate =
        !!xmlState.active &&
        String(xmlState.surrogate || "") === safeSurrogate;
      if (viewIsActiveForSurrogate && file?.url) {
        const currentUrl = String(viewer.dataset.xmlUrl || xmlState.file?.url || "").trim();
        if (file.url !== currentUrl) {
          try {
            await openMusicXmlInPdfTab(
              safeSurrogate,
              file,
              { setSticky: window.getPreferredScoreViewMode?.() === "xml" }
            );
          } catch (err) {
            console.warn("MusicXML auto-refresh failed:", err);
          }
        } else if (!xmlState.file || String(xmlState.file?.url || "") !== file.url) {
          setXmlViewState(true, safeSurrogate, file);
        }
      }
      return file;
    }
    if (!file) return null;

    if (window.twMusicXmlView?.syncPdfXmlToggleButton) {
      window.twMusicXmlView.syncPdfXmlToggleButton(safeSurrogate);
    }
    if (window.twMusicXmlView?.syncPdfXmlPlayButton) {
      window.twMusicXmlView.syncPdfXmlPlayButton(safeSurrogate);
    }
    await syncXmlEditToolbar(safeSurrogate);
    return file;
  }

  window.__twXmlPlayInternals = Object.assign(window.__twXmlPlayInternals || {}, {
    // Shared playback state.
    xmlPlaybackState,
    XML_PLAYBACK_DIVISION,
    getXmlRenderTransposeSemitones,

    // XML loading and parsing.
    getPrimaryMusicXmlFile,
    fetchMusicXmlTextFromUrl,
    parseMusicXmlPlaybackEvents,
    buildMidiFromPlaybackModel,
    getXmlMeasureStats: (parsed) => window.twMusicXmlView?.getXmlMeasureStats?.(parsed) || {
      total: 0,
      positionByIndex: {},
      numberByIndex: {}
    },
    buildXmlMeasureTimeline: (events = [], playbackModel = null) =>
      window.twMusicXmlView?.buildXmlMeasureTimeline?.(events, playbackModel) || [],

    // View coordination used during playback.
    resolveXmlSystemBlockKeyForMeasure: (host, preferredSystemBlockKey, measureIndex, fallbackHost = null) =>
      window.twMusicXmlView?.resolveXmlSystemBlockKeyForMeasure?.(host, preferredSystemBlockKey, measureIndex, fallbackHost) || "",
    positionXmlPlayheadAtProgress: (container, systemBlockKey, measureIndex, progress = 0) =>
      window.twMusicXmlView?.positionXmlPlayheadAtProgress?.(container, systemBlockKey, measureIndex, progress),
    scrollXmlPlayheadIntoView: (container, opts = {}) =>
      window.twMusicXmlView?.scrollXmlPlayheadIntoView?.(container, opts),
    updateXmlPlayheadIndicator: (container, opts = {}) => window.twMusicXmlView?.updateXmlPlayheadIndicator?.(container, opts),
    updateXmlPlaybackMeasureIndicator: (container, opts = {}) => window.twMusicXmlView?.updateXmlPlaybackMeasureIndicator?.(container, opts),
    setXmlPlaybackPositionByProgress,
    getXmlRepresentativeMeasureKey: (host, systemBlockKey, measureIndex, fallbackHost = null) =>
      window.twMusicXmlView?.getXmlRepresentativeMeasureKey?.(host, systemBlockKey, measureIndex, fallbackHost) || "",
    clearActiveMeasureHighlight: (container) => window.twMusicXmlView?.clearActiveMeasureHighlight?.(container),
    hideXmlPlayhead: (host) => window.twMusicXmlView?.hideXmlPlayhead?.(host),

    // Low-level track-state utilities.
    getTrackStateLookup,
    getXmlTrackStateStore,
    ensureTrackPlaybackStates,
    getKnownTracksForSurrogate,

    // Visual-mode query used by the play module.
    isXmlPianoVisualsEnabled
  });

  window.twMusicXml = Object.assign(window.twMusicXml || {}, {
    // File detection and loading.
    isMusicXmlFileName,
    isMusicXmlMime,
    ensureMusicXmlViewerLibs,
    fetchUploadedXmlFiles,
    getPrimaryMusicXmlFile,
    canDeleteMusicXmlFile,
    deleteMusicXmlFile,
    fetchMusicXmlTextFromUrl,

    // View rendering and tab integration.
    renderMusicXmlInto,
    refreshPdfTabXmlState,
    openMusicXmlInPdfTab,
    syncXmlEditToolbar,
    syncPdfXmlToggleButton: async (surrogate) => window.twMusicXmlView?.syncPdfXmlToggleButton?.(surrogate),
    syncPdfXmlPlayButton: async (surrogate) => window.twMusicXmlView?.syncPdfXmlPlayButton?.(surrogate),
    getXmlLayoutScale,
    setXmlLayoutScale,
    getXmlRenderTransposeSemitones,
    setXmlRenderTransposeSemitones,
    getDefaultOsmdRenderOptions,
    getStoredOsmdRenderOptions,
    getResolvedOsmdRenderOptions,
    getOsmdSettingsCatalog,
    setOsmdRenderOptions,
    resetOsmdRenderOptions,
    rerenderOpenMusicXmlView,
    setSelectedMeasureHighlight: (container, measureKey) => window.twMusicXmlView?.setSelectedMeasureHighlight?.(container, measureKey),
    clearSelectedMeasureHighlight: (container) => window.twMusicXmlView?.clearSelectedMeasureHighlight?.(container),

    // Playback-adjacent state still owned by the core module.
    getXmlPlaybackVisualMode,
    getXmlPlaybackVisualsEnabled,
    setXmlPlaybackVisualMode,
    setXmlPlaybackVisualsEnabled,
    cycleXmlPlaybackVisualMode,
    toggleXmlPlaybackVisuals,
    setXmlPlaybackPositionByProgress,
  });

})();
