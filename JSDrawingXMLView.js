logStep("JSDrawingXMLView.js executed");

(() => {
  const XML_HIDE_OTHER_VOICES_STORAGE_KEY = "twXmlHideOtherVoices";
  const XML_SHRINK_OTHER_VOICES_STORAGE_KEY = "twXmlShrinkOtherVoices";

  function getXmlHideOtherVoicesEnabled() {
    try {
      return String(localStorage.getItem(XML_HIDE_OTHER_VOICES_STORAGE_KEY) || "") === "1";
    } catch {
      return false;
    }
  }

  function getXmlShrinkOtherVoicesEnabled() {
    try {
      return String(localStorage.getItem(XML_SHRINK_OTHER_VOICES_STORAGE_KEY) || "") === "1";
    } catch {
      return false;
    }
  }

  function applyXmlSystemCompaction(host, systemShiftByKey = {}, enabled = false) {
    const renderRoot = host?.querySelector?.("[data-tw-xml-render-root='1']") || host;
    const svg = renderRoot?.querySelector?.("svg");
    if (!svg) return;
    const frames = host?._twXmlSystemFramesByBlockKey || renderRoot?._twXmlSystemFramesByBlockKey || {};
    const frameEntries = Object.entries(frames)
      .map(([key, frame]) => ({
        key: String(key || ""),
        y: Number(frame?.y || 0),
        h: Math.max(1, Number(frame?.height || 0))
      }))
      .filter((entry) => entry.key)
      .sort((a, b) => a.y - b.y);
    const resolveBlockKeyForY = (y) => {
      if (!frameEntries.length) return "";
      let best = frameEntries[0];
      let bestDist = Infinity;
      for (let i = 0; i < frameEntries.length; i += 1) {
        const entry = frameEntries[i];
        const top = Number(entry.y || 0);
        const bottom = top + Number(entry.h || 0);
        if (y >= top && y <= bottom) return entry.key;
        const dist = y < top ? (top - y) : (y - bottom);
        if (dist < bestDist) {
          bestDist = dist;
          best = entry;
        }
      }
      return String(best?.key || "");
    };

    const nodes = Array.from(svg.children || []).filter((node) => {
      if (!(node instanceof Element)) return false;
      if (node.getAttribute("data-tw-xml-measure-overlay-layer") === "1") return false;
      if (node.getAttribute("data-tw-xml-progress-overlay-layer") === "1") return false;
      return true;
    });
    nodes.forEach((node) => {
      if (!node.dataset.twXmlSystemShiftBound) {
        node.dataset.twXmlSystemShiftTransform = node.style.transform || "";
        node.dataset.twXmlSystemShiftTransition = node.style.transition || "";
        node.dataset.twXmlSystemShiftTransformOrigin = node.style.transformOrigin || "";
        node.dataset.twXmlSystemShiftTransformBox = node.style.transformBox || "";
        node.dataset.twXmlSystemShiftBound = "1";
      }
      if (!enabled) {
        node.style.transform = node.dataset.twXmlSystemShiftTransform || "";
        node.style.transition = node.dataset.twXmlSystemShiftTransition || "";
        node.style.transformOrigin = node.dataset.twXmlSystemShiftTransformOrigin || "";
        node.style.transformBox = node.dataset.twXmlSystemShiftTransformBox || "";
        return;
      }
      let box = null;
      try {
        box = typeof node.getBBox === "function" ? node.getBBox() : null;
      } catch {
        box = null;
      }
      const centerY = Number(box?.y || 0) + (Math.max(1, Number(box?.height || 0)) / 2);
      const blockKey = resolveBlockKeyForY(centerY);
      const shiftY = Number(systemShiftByKey?.[String(blockKey || "")] || 0);
      node.style.transition = "transform 90ms ease";
      node.style.transformBox = "fill-box";
      node.style.transformOrigin = "center center";
      node.style.transform = Math.abs(shiftY) > 0.2 ? `translateY(${shiftY}px)` : "";
    });
  }

  function applyMeasureRectVisual(rect, mode = "off") {
    if (!rect) return;
    const safeMode = String(mode || "off");
    if (safeMode === "selected") {
      rect.style.opacity = "1";
      rect.setAttribute("fill-opacity", "0.22");
      return;
    }
    if (safeMode === "active") {
      rect.style.opacity = "1";
      rect.setAttribute("fill-opacity", "0.14");
      return;
    }
    rect.style.opacity = "0";
    rect.setAttribute("fill-opacity", "0.16");
  }

  function isEditableTarget(target) {
    if (!target || !(target instanceof Element)) return false;
    if (target.closest("input, textarea, select, [contenteditable='true'], [contenteditable=''], .ql-editor")) {
      return true;
    }
    return !!target.closest?.("[role='textbox']");
  }

  function getXmlPlayButtons() {
    return Array.from(document.querySelectorAll("#pdfXmlPlayBtn, #twPianoXmlPlayBtn"));
  }

  function setXmlPlayButtonVisual(btn, mode) {
    if (!btn) return;
    const safeMode = mode === "pause" ? "pause" : "play";
    if (btn.id === "twPianoXmlPlayBtn") {
      btn.innerHTML = `<i data-lucide="${safeMode}"></i>`;
      if (window.lucide && typeof window.lucide.createIcons === "function") {
        try { window.lucide.createIcons(); } catch {}
      }
      return;
    }
    btn.textContent = safeMode === "pause" ? "■" : "▶";
  }

  function hideXmlPlayhead(host) {
    const line = host?._twXmlPlayhead || host?._twXmlProgressLine;
    if (!line) return;
    line.style.opacity = "0";
  }

  function getXmlMeasureNumber(host, measureIndex, fallbackHost = null) {
    const key = String(Math.max(0, Number(measureIndex || 0)));
    return (
      String(host?._twXmlMeasureStats?.numberByIndex?.[key] || "") ||
      String(fallbackHost?._twXmlMeasureStats?.numberByIndex?.[key] || "")
    );
  }

  function getXmlRenderedMeasureFrameByNumber(host, measureNumber, fallbackHost = null) {
    const key = String(measureNumber || "").trim();
    if (!key) return null;
    return (
      host?._twXmlRenderedMeasureFramesByNumber?.[key] ||
      fallbackHost?._twXmlRenderedMeasureFramesByNumber?.[key] ||
      null
    );
  }

  function getXmlRenderedMeasureFrame(host, measureIndex, fallbackHost = null) {
    const indexKey = String(Math.max(0, Number(measureIndex || 0)));
    const measureNumber = getXmlMeasureNumber(host, measureIndex, fallbackHost);
    const hasZeroBasedIds =
      Object.prototype.hasOwnProperty.call(host?._twXmlRenderedMeasureFramesByNumber || {}, "0") ||
      Object.prototype.hasOwnProperty.call(fallbackHost?._twXmlRenderedMeasureFramesByNumber || {}, "0");
    const byIndex =
      host?._twXmlRenderedMeasureFramesByNumber?.[indexKey] ||
      fallbackHost?._twXmlRenderedMeasureFramesByNumber?.[indexKey] ||
      null;
    const byMeasureNumber = getXmlRenderedMeasureFrameByNumber(host, measureNumber, fallbackHost);
    if (hasZeroBasedIds) {
      return byIndex || byMeasureNumber || null;
    }
    return byMeasureNumber || byIndex || null;
  }

  function getXmlSystemBlockKeyForRenderedMeasure(host, measureIndex, fallbackHost = null) {
    const frame = getXmlRenderedMeasureFrame(host, measureIndex, fallbackHost);
    if (!frame) return "";
    const centerY = Number(frame.y || 0) + (Math.max(8, Number(frame.height || 0)) / 2);
    const systemEntries = [
      ...Object.entries(host?._twXmlSystemFramesByBlockKey || {}),
      ...Object.entries(fallbackHost?._twXmlSystemFramesByBlockKey || {})
    ]
      .filter(([key], idx, arr) => arr.findIndex(([otherKey]) => otherKey === key) === idx)
      .map(([blockKey, systemFrame]) => ({
        blockKey: String(blockKey || ""),
        frame: systemFrame
      }))
      .filter((entry) => entry.blockKey && entry.frame)
      .sort((a, b) => Number(a.frame?.y || 0) - Number(b.frame?.y || 0) || Number(a.blockKey) - Number(b.blockKey));
    if (!systemEntries.length) return "";
    let bestBlockKey = "";
    let bestDistance = Infinity;
    for (const entry of systemEntries) {
      const top = Number(entry.frame?.y || 0);
      const bottom = top + Math.max(8, Number(entry.frame?.height || 0));
      if (centerY >= top && centerY <= bottom) return entry.blockKey;
      const distance = centerY < top ? (top - centerY) : (centerY - bottom);
      if (distance < bestDistance) {
        bestDistance = distance;
        bestBlockKey = entry.blockKey;
      }
    }
    return bestBlockKey;
  }

  function getXmlPlayheadFrame(host, measureKey, fallbackHost = null) {
    const key = String(measureKey || "");
    const measureIndex =
      Number(
        host?._twXmlPlaybackVisualMap?.measureFramesByKey?.[key]?.measureIndex ??
        fallbackHost?._twXmlPlaybackVisualMap?.measureFramesByKey?.[key]?.measureIndex
    );
    const renderedMeasureFrame = getXmlRenderedMeasureFrame(host, measureIndex, fallbackHost);
    if (renderedMeasureFrame) return renderedMeasureFrame;
    const primary =
      host?._twXmlMeasureGroupFrames?.[key] ||
      host?._twXmlMeasureFrames?.[key] ||
      null;
    if (primary) return primary;
    const fallbackPrimary =
      fallbackHost?._twXmlMeasureGroupFrames?.[key] ||
      fallbackHost?._twXmlMeasureFrames?.[key] ||
      null;
    if (fallbackPrimary) return fallbackPrimary;
    if (Number.isFinite(measureIndex) && measureIndex >= 0) {
      return (
        host?._twXmlOsmdMeasureFramesByIndex?.[String(Math.max(0, measureIndex))] ||
        fallbackHost?._twXmlOsmdMeasureFramesByIndex?.[String(Math.max(0, measureIndex))] ||
        null
      );
    }
    return null;
  }

  function getXmlSystemFrame(host, systemBlockKey, fallbackHost = null) {
    const key = String(systemBlockKey || "");
    if (!key) return null;
    return (
      host?._twXmlSystemFramesByBlockKey?.[key] ||
      fallbackHost?._twXmlSystemFramesByBlockKey?.[key] ||
      null
    );
  }

  function clampPlayheadRectToSvg(line, rect) {
    const safeRect = {
      x: Number(rect?.x || 0),
      y: Number(rect?.y || 0),
      width: Math.max(6, Number(rect?.width || 0)),
      height: Math.max(12, Number(rect?.height || 0))
    };
    const svg = line?.ownerSVGElement || null;
    if (!svg) return safeRect;
    const vb = svg.viewBox?.baseVal;
    if (vb && Number(vb.width || 0) > 0 && Number(vb.height || 0) > 0) {
      const minX = Number(vb.x || 0);
      const minY = Number(vb.y || 0);
      const maxX = minX + Number(vb.width || 0);
      const maxY = minY + Number(vb.height || 0);
      safeRect.x = Math.max(minX, Math.min(maxX - safeRect.width, safeRect.x));
      safeRect.y = Math.max(minY, Math.min(maxY - safeRect.height, safeRect.y));
      return safeRect;
    }
    return safeRect;
  }

  function getXmlSystemBlockKeyForMeasureKey(host, measureKey, fallbackHost = null) {
    const key = String(measureKey || "");
    return (
      String(host?._twXmlSystemBlockKeyByMeasureKey?.[key] || "") ||
      String(fallbackHost?._twXmlSystemBlockKeyByMeasureKey?.[key] || "")
    );
  }

  function getXmlSystemBlockKeyForSourceIndex(host, sourceIndex, fallbackHost = null) {
    const key = String(Math.max(0, Number(sourceIndex || 0)));
    return (
      String(host?._twXmlSystemBlockKeyBySourceIndex?.[key] || "") ||
      String(fallbackHost?._twXmlSystemBlockKeyBySourceIndex?.[key] || "")
    );
  }

  function getXmlPlayheadGroupKey(systemBlockKey, measureIndex) {
    const blockKey = String(systemBlockKey || "");
    if (!blockKey) return "";
    return `${blockKey}:${Math.max(0, Number(measureIndex || 0))}`;
  }

  function getXmlRepresentativeMeasureKey(host, systemBlockKey, measureIndex, fallbackHost = null) {
    const groupKey = getXmlPlayheadGroupKey(systemBlockKey, measureIndex);
    return (
      String(host?._twXmlMeasureKeyByGroupFrameKey?.[groupKey] || "") ||
      String(fallbackHost?._twXmlMeasureKeyByGroupFrameKey?.[groupKey] || "")
    );
  }

  function getXmlSystemStartLeadX(host, systemBlockKey, measureIndex, frame, fallbackHost = null) {
    if (!frame) return null;
    const blockKey = String(systemBlockKey || "");
    if (!blockKey) return null;
    const targetMeasureIndex = Math.max(0, Number(measureIndex || 0));
    const systemFrame = getXmlSystemFrame(host, blockKey, fallbackHost);
    if (!systemFrame) return null;
    const candidateMeasureIndexes = [
      ...Object.keys(host?._twXmlMeasureGroupFramesByGroupKey || {}),
      ...Object.keys(fallbackHost?._twXmlMeasureGroupFramesByGroupKey || {})
    ]
      .filter((key, idx, arr) => arr.indexOf(key) === idx)
      .filter((key) => String(key).startsWith(`${blockKey}:`))
      .map((key) => Number(String(key).split(":")[1] || -1))
      .filter((value) => Number.isFinite(value) && value >= 0)
      .sort((a, b) => a - b);
    if (!candidateMeasureIndexes.length || candidateMeasureIndexes[0] !== targetMeasureIndex) {
      return null;
    }
    const frameX = Number(frame.x || 0);
    const systemX = Number(systemFrame.x || 0);
    if (!(frameX > systemX + 2)) return null;
    return systemX;
  }

  function resolveXmlSystemBlockKeyForMeasure(host, preferredSystemBlockKey, measureIndex, fallbackHost = null) {
    const groupKey = getXmlPlayheadGroupKey(preferredSystemBlockKey, measureIndex);
    const direct =
      host?._twXmlMeasureGroupFramesByGroupKey?.[groupKey] ||
      fallbackHost?._twXmlMeasureGroupFramesByGroupKey?.[groupKey];
    if (direct) return String(preferredSystemBlockKey || "");

    const targetMeasureIndex = Math.max(0, Number(measureIndex || 0));
    const renderedBlockKey = getXmlSystemBlockKeyForRenderedMeasure(host, targetMeasureIndex, fallbackHost);
    if (renderedBlockKey) return renderedBlockKey;

    const candidates = [
      ...Object.keys(host?._twXmlMeasureGroupFramesByGroupKey || {}),
      ...Object.keys(fallbackHost?._twXmlMeasureGroupFramesByGroupKey || {})
    ]
      .filter((key, idx, arr) => arr.indexOf(key) === idx)
      .filter((key) => key.endsWith(`:${targetMeasureIndex}`))
      .map((key) => String(key.split(":")[0] || ""))
      .filter(Boolean)
      .sort((a, b) => Number(a) - Number(b));
    if (candidates.length) {
      const preferred = Number(preferredSystemBlockKey || 0);
      const forward = candidates.find((key) => Number(key) >= preferred);
      return String(forward || candidates[0] || "");
    }

    const allEntries = [
      ...Object.keys(host?._twXmlMeasureGroupFramesByGroupKey || {}),
      ...Object.keys(fallbackHost?._twXmlMeasureGroupFramesByGroupKey || {})
    ]
      .filter((key, idx, arr) => arr.indexOf(key) === idx)
      .map((key) => {
        const parts = String(key).split(":");
        return {
          blockKey: String(parts[0] || ""),
          measureIndex: Number(parts[1] || -1)
        };
      })
      .filter((entry) => entry.blockKey && Number.isFinite(entry.measureIndex) && entry.measureIndex >= 0)
      .sort((a, b) => a.measureIndex - b.measureIndex || Number(a.blockKey) - Number(b.blockKey));
    if (!allEntries.length) return "";
    const nextVisible = allEntries.find((entry) => entry.measureIndex >= targetMeasureIndex);
    if (nextVisible?.blockKey) return String(nextVisible.blockKey);
    const prevVisible = allEntries[allEntries.length - 1];
    return String(prevVisible?.blockKey || "");
  }

  function positionXmlPlayhead(host, measureKey) {
    const line = host?._twXmlPlayhead || host?._twXmlProgressLine;
    const frame = getXmlPlayheadFrame(host, measureKey);
    const measureIndex = Number(host?._twXmlPlaybackVisualMap?.measureFramesByKey?.[String(measureKey || "")]?.measureIndex);
    const renderedSystemBlockKey = Number.isFinite(measureIndex) && measureIndex >= 0
      ? getXmlSystemBlockKeyForRenderedMeasure(host, measureIndex, host)
      : "";
    const mappedSystemBlockKey = getXmlSystemBlockKeyForMeasureKey(host, measureKey);
    const systemBlockKey = renderedSystemBlockKey || mappedSystemBlockKey;
    if (!line || !frame) {
      hideXmlPlayhead(host);
      return;
    }
    const systemFrame = getXmlSystemFrame(host, systemBlockKey);
    const verticalFrame = systemFrame || frame;
    const isPlaybackActive = !!window.__twXmlPlayInternals?.xmlPlaybackState?.active;
    const leadStartX = isPlaybackActive
      ? null
      : getXmlSystemStartLeadX(host, systemBlockKey, measureIndex, frame, host);
    const rect = clampPlayheadRectToSvg(line, {
      x: Number((leadStartX ?? Number(frame.x || 0)) || 0) + 2,
      y: Number((systemFrame?.y ?? verticalFrame?.y ?? frame.y) || 0),
      width: 7,
      height: Math.max(14, Number((systemFrame?.height ?? verticalFrame?.height ?? frame.height) || 0))
    });
    line.setAttribute("x", String(rect.x));
    line.setAttribute("y", String(rect.y));
    line.setAttribute("width", String(rect.width));
    line.setAttribute("height", String(rect.height));
    line.style.opacity = "1";
  }

  function positionXmlPlayheadAtElement(host, el, systemBlockKey = "") {
    const line = host?._twXmlPlayhead || host?._twXmlProgressLine;
    if (!line || !el || typeof el.getBBox !== "function") return false;
    let box = null;
    try {
      box = el.getBBox();
    } catch {
      box = null;
    }
    if (!box || !(Number(box.width || 0) > 0) || !(Number(box.height || 0) > 0)) return false;
    const systemFrame = getXmlSystemFrame(host, systemBlockKey, host);
    const verticalFrame = systemFrame || {
      x: Number(box.x || 0),
      y: Number(box.y || 0),
      width: Math.max(8, Number(box.width || 0)),
      height: Math.max(14, Number(box.height || 0))
    };
    const width = 7;
    const noteCenterX = Number(box.x || 0) + (Number(box.width || 0) / 2);
    const rect = clampPlayheadRectToSvg(line, {
      x: noteCenterX - (width / 2),
      y: Number((systemFrame?.y ?? verticalFrame?.y ?? box.y) || 0),
      width,
      height: Math.max(14, Number((systemFrame?.height ?? verticalFrame?.height ?? box.height) || 0))
    });
    line.setAttribute("x", String(rect.x));
    line.setAttribute("y", String(rect.y));
    line.setAttribute("width", String(rect.width));
    line.setAttribute("height", String(rect.height));
    line.style.opacity = "1";
    return true;
  }

  function positionXmlPlayheadAtProgress(container, systemBlockKey, measureIndex, progress = 0) {
    const host = container?._twXmlStateHost || container;
    const renderedSystemBlockKey = getXmlSystemBlockKeyForRenderedMeasure(host, measureIndex, container);
    const effectiveSystemBlockKey = String(renderedSystemBlockKey || systemBlockKey || "");
    const representativeMeasureKey = getXmlRepresentativeMeasureKey(host, effectiveSystemBlockKey, measureIndex, container);
    const groupKey = getXmlPlayheadGroupKey(effectiveSystemBlockKey, measureIndex);
    const groupFrame =
      host?._twXmlMeasureGroupFramesByGroupKey?.[groupKey] ||
      container?._twXmlMeasureGroupFramesByGroupKey?.[groupKey] ||
      null;
    const osmdFrame =
      host?._twXmlOsmdMeasureFramesByIndex?.[String(Math.max(0, Number(measureIndex || 0)))] ||
      container?._twXmlOsmdMeasureFramesByIndex?.[String(Math.max(0, Number(measureIndex || 0)))] ||
      null;
    const renderedMeasureFrame = getXmlRenderedMeasureFrame(host, measureIndex, container);
    const systemFrame = getXmlSystemFrame(host, effectiveSystemBlockKey, container) || renderedMeasureFrame || osmdFrame;
    let syntheticFrame = null;
    if (!renderedMeasureFrame && !groupFrame && systemFrame) {
      const blockKey = String(effectiveSystemBlockKey || "");
      const candidateKeys = [
        ...Object.keys(host?._twXmlMeasureGroupFramesByGroupKey || {}),
        ...Object.keys(container?._twXmlMeasureGroupFramesByGroupKey || {})
      ]
        .filter((key, idx, arr) => arr.indexOf(key) === idx)
        .filter((key) => String(key).startsWith(`${blockKey}:`))
        .map((key) => ({
          key,
          measureIndex: Number(String(key).split(":")[1] || -1)
        }))
        .filter((entry) => Number.isFinite(entry.measureIndex) && entry.measureIndex > Number(measureIndex || 0))
        .sort((a, b) => a.measureIndex - b.measureIndex);
      const nextVisible = candidateKeys.length
        ? (
            host?._twXmlMeasureGroupFramesByGroupKey?.[candidateKeys[0].key] ||
            container?._twXmlMeasureGroupFramesByGroupKey?.[candidateKeys[0].key] ||
            null
          )
        : null;
      const startX = Number(systemFrame.x || 0);
      const endX = nextVisible
        ? Math.max(startX + 8, Number(nextVisible.x || 0))
        : (startX + Math.max(8, Number(systemFrame.width || 0)));
      syntheticFrame = {
        x: startX,
        y: Number(systemFrame.y || 0),
        width: Math.max(8, endX - startX),
        height: Math.max(8, Number(systemFrame.height || 0))
      };
    }
    const frame =
      renderedMeasureFrame ||
      groupFrame ||
      syntheticFrame ||
      osmdFrame ||
      null;
    const verticalFrame = systemFrame || frame;
    const line = host?._twXmlPlayhead || host?._twXmlProgressLine || container?._twXmlPlayhead || container?._twXmlProgressLine;
    if (!frame || !line) {
      const key = representativeMeasureKey;
      if (key) {
        positionXmlPlayhead(host, key);
        return;
      }
      hideXmlPlayhead(host);
      return;
    }
    const clamped = Math.max(0, Math.min(1, Number(progress) || 0));
    const width = 7;
    const travel = Math.max(0, Number(frame.width || 0) - width);
    let basePlayheadX = Number(frame.x || 0) + (clamped * travel);
    const rect = clampPlayheadRectToSvg(line, {
      x: basePlayheadX,
      y: Number((systemFrame?.y ?? verticalFrame?.y ?? frame.y) || 0),
      width,
      height: Math.max(14, Number((systemFrame?.height ?? verticalFrame?.height ?? frame.height) || 0))
    });
    line.setAttribute("x", String(rect.x));
    line.setAttribute("y", String(rect.y));
    line.setAttribute("width", String(rect.width));
    line.setAttribute("height", String(rect.height));
    line.style.opacity = "1";
  }

  function scrollXmlPlayheadIntoView(container, opts = {}) {
    const host = container?._twXmlStateHost || container;
    if (!host) return false;
    const playhead =
      host?._twXmlPlayhead ||
      host?._twXmlProgressLine ||
      container?._twXmlPlayhead ||
      container?._twXmlProgressLine ||
      null;
    const viewport = document.getElementById("pdfTabContent");
    if (!playhead || !viewport) return false;

    const p = playhead.getBoundingClientRect?.();
    const v = viewport.getBoundingClientRect?.();
    if (!p || !v) return false;

    const dock = document.getElementById("twPianoDock");
    const dockRect = dock?.getBoundingClientRect?.();
    const dockVisible = !!dockRect && String(window.getComputedStyle?.(dock)?.display || "") !== "none";
    const dockHeight = dockVisible ? Number(dockRect.height || 0) : 0;
    const baseExtra = Math.max(0, Number(opts.extraBottom || 99));
    const topPad = Math.max(8, Number(opts.topPad || 24));
    const playheadHeight = Math.max(2, Number(p.height || 2));
    const dynamicExtra = Math.max(0, Math.min(140, Math.round(playheadHeight * 0.35)));
    const extra = baseExtra + dynamicExtra;
    const systemBlockIndex = Math.max(0, Number(opts.systemBlockKey || 0));
    const shouldCenter = !!opts.centerOnSystemChange && systemBlockIndex > 0;

    const topBound = Number(v.top || 0) + topPad;
    const bottomBound = Number(v.bottom || 0) - dockHeight - extra;
    const playheadTopY = Number(p.top || 0);
    const playheadBottomY = Number(p.bottom || (Number(p.top || 0) + playheadHeight));
    const playheadMidY = playheadTopY + (playheadHeight / 2);

    let delta = 0;
    if (shouldCenter) {
      const targetCenterY = topBound + ((bottomBound - topBound) / 2);
      delta = playheadMidY - targetCenterY;
    } else if (playheadTopY < topBound) {
      delta = playheadTopY - topBound;
    } else if (playheadBottomY > bottomBound) {
      delta = playheadBottomY - bottomBound;
    } else {
      return false;
    }
    if (!Number.isFinite(delta) || Math.abs(delta) < 1) return false;
    const targetTop = Math.max(0, Number(viewport.scrollTop || 0) + delta);
    const smoothFast = opts?.smoothFast !== false;
    if (smoothFast && typeof requestAnimationFrame === "function") {
      const startTop = Number(viewport.scrollTop || 0);
      const distance = targetTop - startTop;
      if (Math.abs(distance) < 2) {
        viewport.scrollTop = targetTop;
        return true;
      }
      if (viewport._twXmlAutoScrollAnim) {
        try { cancelAnimationFrame(viewport._twXmlAutoScrollAnim); } catch {}
        viewport._twXmlAutoScrollAnim = 0;
      }
      const durationMs = Math.max(90, Math.min(160, Math.round(Math.abs(distance) * 0.2)));
      const startTs = performance.now();
      const easeOutCubic = (t) => 1 - Math.pow(1 - t, 3);
      const step = (ts) => {
        const elapsed = Math.max(0, ts - startTs);
        const t = Math.max(0, Math.min(1, elapsed / durationMs));
        const eased = easeOutCubic(t);
        viewport.scrollTop = Math.max(0, startTop + (distance * eased));
        if (t < 1) {
          viewport._twXmlAutoScrollAnim = requestAnimationFrame(step);
          return;
        }
        viewport._twXmlAutoScrollAnim = 0;
      };
      viewport._twXmlAutoScrollAnim = requestAnimationFrame(step);
      return true;
    }
    viewport.scrollTop = targetTop;
    return true;
  }

  function buildXmlMeasureTimeline(events = [], playbackModel = null) {
    const repeatOrder = Array.isArray(playbackModel?.repeatMeasureOrder) ? playbackModel.repeatMeasureOrder : [];
    const hasRepeatFlow = repeatOrder.length > 1 && repeatOrder.some((measureIndex, idx) => Number(measureIndex || 0) !== idx);
    if (hasRepeatFlow) {
      const declaredDurations = playbackModel?.measureDurationTickByIndex || {};
      const timeline = [];
      let tickCursor = 0;
      repeatOrder.forEach((measureIndexRaw) => {
        const measureIndex = Math.max(0, Number(measureIndexRaw || 0));
        const declaredDuration = Math.max(1, Number(declaredDurations?.[String(measureIndex)] || 0));
        timeline.push({
          measureIndex,
          startTick: tickCursor,
          endTick: tickCursor + declaredDuration
        });
        tickCursor += declaredDuration;
      });
      if (timeline.length) return timeline;
    }

    const byMeasure = new Map();
    (Array.isArray(events) ? events : []).forEach((ev) => {
      const measureIndex = Math.max(0, Number(ev?.measureIndex || 0));
      const startTick = Math.max(0, Number(ev?.startTick || 0));
      const endTick = Math.max(startTick + 1, startTick + Math.max(1, Number(ev?.durationTick || 1)));
      if (!byMeasure.has(measureIndex)) {
        byMeasure.set(measureIndex, { measureIndex, startTick, endTick });
        return;
      }
      const item = byMeasure.get(measureIndex);
      item.startTick = Math.min(item.startTick, startTick);
      item.endTick = Math.max(item.endTick, endTick);
    });
    const declaredDurations = playbackModel?.measureDurationTickByIndex;
    const declaredNumbers = playbackModel?.measureNumbersByIndex;
    const declaredIndexes = new Set();
    if (declaredNumbers && typeof declaredNumbers === "object") {
      Object.keys(declaredNumbers).forEach((key) => {
        const idx = Number(key);
        if (Number.isFinite(idx) && idx >= 0) declaredIndexes.add(idx);
      });
    }
    if (declaredDurations && typeof declaredDurations === "object") {
      Object.keys(declaredDurations).forEach((key) => {
        const idx = Number(key);
        if (Number.isFinite(idx) && idx >= 0) declaredIndexes.add(idx);
      });
    }
    if (declaredIndexes.size) {
      const maxIndex = Math.max(...Array.from(declaredIndexes.values()));
      const timeline = [];
      let tickCursor = 0;
      for (let measureIndex = 0; measureIndex <= maxIndex; measureIndex += 1) {
        const existing = byMeasure.get(measureIndex);
        const existingSpan = existing
          ? Math.max(1, Number(existing.endTick || 0) - Number(existing.startTick || 0))
          : 0;
        const declaredDuration = Number(declaredDurations?.[String(measureIndex)] || 0);
        const measureDuration = Math.max(
          1,
          Number.isFinite(declaredDuration) && declaredDuration > 0
            ? declaredDuration
            : existingSpan
        );
        timeline.push({
          measureIndex,
          startTick: tickCursor,
          endTick: tickCursor + measureDuration
        });
        tickCursor += measureDuration;
      }
      return timeline;
    }
    return Array.from(byMeasure.values()).sort((a, b) => Number(a.startTick || 0) - Number(b.startTick || 0));
  }

  function getXmlMeasureKey(slotOrMeta) {
    const systemIndex = Math.max(0, Number(slotOrMeta?.systemIndex || 0));
    const measureIndex = Math.max(0, Number(slotOrMeta?.measureIndex || 0));
    return `${systemIndex}:${measureIndex}`;
  }

  function clearActiveMeasureHighlight(container) {
    const host = container?._twXmlStateHost || container;
    if (!host) return;
    const key = String(host._twXmlActiveMeasureKey || "");
    const rects = host._twXmlMeasureRects;
    if (!key || !rects || !rects[key]) {
      host._twXmlActiveMeasureKey = "";
      return;
    }
    const isSelected = String(host._twXmlSelectedMeasureKey || "") === key;
    applyMeasureRectVisual(rects[key], isSelected ? "selected" : "off");
    hideXmlPlayhead(host);
    host._twXmlActiveMeasureKey = "";
  }

  function clearSelectedMeasureHighlight(container) {
    const host = container?._twXmlStateHost || container;
    if (!host) return;
    const key = String(host._twXmlSelectedMeasureKey || "");
    const rects = host._twXmlMeasureRects;
    if (!key || !rects || !rects[key]) {
      host._twXmlSelectedMeasureKey = "";
      return;
    }
    const isActive = String(host._twXmlActiveMeasureKey || "") === key;
    applyMeasureRectVisual(rects[key], isActive ? "active" : "off");
    host._twXmlSelectedMeasureKey = "";
  }

  function ensureXmlSelectedMarker(host, markerRoot) {
    if (!host || !markerRoot) return null;
    if (host._twXmlSelectedMarker && host._twXmlSelectedMarker.isConnected) {
      return host._twXmlSelectedMarker;
    }
    markerRoot.style.position = markerRoot.style.position || "relative";
    const marker = document.createElement("div");
    marker.setAttribute("data-tw-xml-selected-marker", "1");
    marker.style.position = "absolute";
    marker.style.width = "0";
    marker.style.height = "0";
    marker.style.borderRadius = "999px";
    marker.style.border = "2px solid rgba(249,115,22,0.98)";
    marker.style.background = "rgba(253,186,116,0.18)";
    marker.style.boxShadow = "0 0 0 3px rgba(253,186,116,0.20)";
    marker.style.pointerEvents = "none";
    marker.style.zIndex = "19";
    marker.style.opacity = "0";
    marker.style.transition = "opacity 40ms ease";
    markerRoot.appendChild(marker);
    host._twXmlSelectedMarker = marker;
    return marker;
  }

  function clearSelectedXmlNote(container) {
    const host = container?._twXmlStateHost || container;
    const selectedEl = host?._twXmlSelectedNoteEl;
    if (selectedEl) selectedEl.dataset.twXmlSelectedActive = "0";
    const marker = host?._twXmlSelectedMarker || null;
    if (marker) marker.style.opacity = "0";
    if (host) {
      host._twXmlSelectedNoteEl = null;
      host._twXmlSelectedSourceIndex = null;
      host._twXmlHasExplicitStart = false;
    }
  }

  function setSelectedXmlNote(container, el, sourceIndex = null) {
    const host = container?._twXmlStateHost || container;
    if (!host || !el) return;
    if (host._twXmlSelectedNoteEl === el && String(host._twXmlSelectedSourceIndex ?? "") === String(sourceIndex ?? "")) {
      return;
    }
    clearSelectedXmlNote(host);
    const renderRoot = container?.matches?.("[data-tw-xml-render-root='1']")
      ? container
      : (host?.querySelector?.("[data-tw-xml-render-root='1']") || container);
    const markerRoot = container?.querySelector?.('[data-tw-xml-note-overlay="1"]') || renderRoot;
    const marker = ensureXmlSelectedMarker(host, markerRoot);
    if (marker) {
      try {
        const noteRect = typeof el.getBoundingClientRect === "function" ? el.getBoundingClientRect() : null;
        const rootRect = typeof container?.getBoundingClientRect === "function" ? container.getBoundingClientRect() : null;
        if (noteRect && rootRect) {
          const scaleX = Math.max(0.001, Number(rootRect.width || 0) / Math.max(1, Number(container.offsetWidth || container.clientWidth || 1)));
          const scaleY = Math.max(0.001, Number(rootRect.height || 0) / Math.max(1, Number(container.offsetHeight || container.clientHeight || 1)));
          const padX = 4;
          const padY = 4;
          const left = Math.max(0, ((noteRect.left - rootRect.left) / scaleX) - padX);
          const top = Math.max(0, ((noteRect.top - rootRect.top) / scaleY) - padY);
          const width = Math.max(10, (noteRect.width / scaleX) + (padX * 2));
          const height = Math.max(10, (noteRect.height / scaleY) + (padY * 2));
          marker.style.borderRadius = `${Math.max(8, Math.round(Math.max(width, height) * 0.5))}px`;
          marker.style.left = `${left}px`;
          marker.style.top = `${top}px`;
          marker.style.width = `${width}px`;
          marker.style.height = `${height}px`;
          marker.style.opacity = "1";
        }
      } catch {}
    }
    el.dataset.twXmlSelectedActive = "1";
    host._twXmlSelectedNoteEl = el;
    host._twXmlSelectedSourceIndex = Number.isFinite(Number(sourceIndex)) ? Number(sourceIndex) : null;
    host._twXmlSelectedStartTick = null;
    host._twXmlHasExplicitStart = true;
  }

  function setSelectedMeasureHighlight(container, measureKey) {
    const host = container?._twXmlStateHost || container;
    if (!host) return;
    const key = String(measureKey || "");
    if (!key) {
      clearSelectedMeasureHighlight(host);
      return;
    }
    if (String(host._twXmlSelectedMeasureKey || "") === key) return;
    clearSelectedMeasureHighlight(host);
    const rects = host._twXmlMeasureRects;
    if (!rects || !rects[key]) return;
    applyMeasureRectVisual(rects[key], "selected");
    host._twXmlSelectedMeasureKey = key;
  }

  function getRenderedNoteheadElements(container) {
    if (!container) return [];
    const selectors = [
      "svg g.vf-stavenote .vf-notehead",
      "svg g.vf-note .vf-notehead",
      "svg g.vf-notehead",
      "svg .vf-notehead"
    ];
    const isRestLike = (node) => {
      let cur = node;
      while (cur && cur !== container) {
        const cls = String(cur.getAttribute?.("class") || "").toLowerCase();
        if (cls.includes("rest")) return true;
        cur = cur.parentElement;
      }
      return false;
    };
    const looksPlayableNotehead = (node) => {
      if (!node || isRestLike(node)) return false;
      const noteheadBits = node.querySelectorAll?.(".vf-notehead");
      if (noteheadBits?.length) return true;
      const curvedBits = node.querySelectorAll?.("ellipse, circle");
      const pathBits = node.querySelectorAll?.("path, polygon");
      const rectBits = node.querySelectorAll?.("rect");
      if (!(curvedBits?.length) && !(pathBits?.length)) return false;
      if ((!pathBits || !pathBits.length) && rectBits?.length) return false;
      if (typeof node.getBBox !== "function") return true;
      try {
        const box = node.getBBox();
        if (!box || !(box.width > 0) || !(box.height > 0)) return false;
        if (box.width > (box.height * 2.2)) return false;
        if (box.height > (box.width * 1.28)) return false;
      } catch {
        return false;
      }
      return true;
    };
    for (let si = 0; si < selectors.length; si += 1) {
      const raw = Array.from(container.querySelectorAll(selectors[si]));
      if (!raw.length) continue;
      const out = [];
      const seen = new Set();
      raw.forEach((el) => {
        const target = (el.matches?.("g.vf-notehead") ? el : (el.closest?.("g.vf-notehead") || el));
        if (!target || seen.has(target) || !looksPlayableNotehead(target)) return;
        seen.add(target);
        out.push(target);
      });
      if (out.length) return out;
    }
    return [];
  }

  function getStafflineGeometry(el, idx = 0) {
    let box = null;
    try {
      box = typeof el?.getBBox === "function" ? el.getBBox() : null;
    } catch {
      box = null;
    }
    const x = Number(box?.x || 0);
    const y = Number(box?.y || 0);
    const width = Math.max(1, Number(box?.width || 0));
    const height = Math.max(1, Number(box?.height || 0));
    const lineCenters = [];
    if (el?.querySelectorAll) {
      const minLineWidth = Math.max(40, width * 0.45);
      Array.from(el.querySelectorAll("path, line, rect")).forEach((child) => {
        let childBox = null;
        try {
          childBox = typeof child?.getBBox === "function" ? child.getBBox() : null;
        } catch {
          childBox = null;
        }
        if (!childBox) return;
        const childWidth = Number(childBox.width || 0);
        const childHeight = Number(childBox.height || 0);
        if (childWidth < minLineWidth) return;
        if (childHeight > 6) return;
        lineCenters.push(Number(childBox.y || 0) + (Math.max(1, childHeight) / 2));
      });
    }
    lineCenters.sort((a, b) => a - b);
    const sortTopY = lineCenters.length ? lineCenters[0] : y;
    const sortCenterY = lineCenters.length
      ? (lineCenters.reduce((sum, value) => sum + value, 0) / lineCenters.length)
      : (y + (height / 2));
    return {
      el,
      idx,
      x,
      y,
      width,
      height,
      sortTopY,
      sortCenterY
    };
  }

  function getOneStaffDisplayPartOrder(trackCount) {
    const count = Math.max(0, Number(trackCount || 0));
    if (count <= 1) return count === 1 ? [0] : [];
    const out = [0, count - 1];
    for (let idx = 1; idx < (count - 1); idx += 1) out.push(idx);
    return out;
  }

  function getRenderedNoteheadBindings(container, noteSlots = []) {
    if (!container || !Array.isArray(noteSlots) || !noteSlots.length) return new Map();
    const parsed = container?._twXmlPlaybackModel || container?._twXmlStateHost?._twXmlPlaybackModel || null;
    const slots = noteSlots
      .slice()
      .sort((a, b) =>
        Number(a?.staffIndex || 1) - Number(b?.staffIndex || 1) ||
        Number(a?.measureIndex || 0) - Number(b?.measureIndex || 0) ||
        Number(a?.startUnit || 0) - Number(b?.startUnit || 0) ||
        Number(a?.partIndex || 0) - Number(b?.partIndex || 0) ||
        Number(a?.midi || a?.clickMidi || 0) - Number(b?.midi || b?.clickMidi || 0) ||
        Number(a?.sourceIndex || 0) - Number(b?.sourceIndex || 0)
      );
    const staffLayout = (() => {
      const tracks = Array.isArray(parsed?.tracks) ? parsed.tracks : [];
      const allSingleStaff = tracks.length > 0 && tracks.every((track) => Math.max(1, Number(track?.staffCount || 1)) === 1);
      if (allSingleStaff) {
        const displayOrder = getOneStaffDisplayPartOrder(tracks.length);
        if (displayOrder.length) {
          return displayOrder.map((partIndex) => ({ partIndex, staffIndex: 1 }));
        }
      }
      const out = [];
      tracks.forEach((track, partIndex) => {
        const staffCount = Math.max(1, Number(track?.staffCount || 1));
        for (let staffIndex = staffCount; staffIndex >= 1; staffIndex -= 1) {
          out.push({ partIndex, staffIndex });
        }
      });
      if (out.length) return out;
      const fallbackParts = Math.max(
        1,
        Array.from(new Set(slots.map((slot) => Number(slot?.partIndex || 0)))).length
      );
      return Array.from({ length: fallbackParts }, (_, partIndex) => ({ partIndex, staffIndex: 1 }));
    })();
    const stafflineBlockSize = Math.max(1, Number(staffLayout.length || 0));

    const makeQueueKey = (partIndex, staffIndex) =>
      `${Math.max(0, Number(partIndex || 0))}:${Math.max(1, Number(staffIndex || 1))}`;
    const groupSlotClusters = (items = []) => {
      if (!Array.isArray(items) || !items.length) return [];
      const clusters = [];
      items.forEach((slot) => {
        const last = clusters[clusters.length - 1];
        const sameTime =
          last &&
          Number(last.measureIndex || 0) === Number(slot?.measureIndex || 0) &&
          Number(last.startUnit || 0) === Number(slot?.startUnit || 0);
        if (!sameTime) {
          clusters.push({
            measureIndex: Number(slot?.measureIndex || 0),
            startUnit: Number(slot?.startUnit || 0),
            items: [slot]
          });
          return;
        }
        last.items.push(slot);
      });
      return clusters;
    };
    const slotQueuesByStaff = new Map();
    slots.forEach((slot) => {
      const key = makeQueueKey(slot?.partIndex, slot?.staffIndex);
      if (!slotQueuesByStaff.has(key)) {
        slotQueuesByStaff.set(key, { slots: [], cursor: 0 });
      }
      slotQueuesByStaff.get(key).slots.push(slot);
    });

    const preferredStafflines = Array.isArray(container?._twXmlStafflineEntries) && container._twXmlStafflineEntries.length
      ? container._twXmlStafflineEntries.map((entry, idx) => ({
          el: entry?.el || null,
          idx,
          x: Number(entry?.x || 0),
          y: Number(entry?.y || 0),
          sortTopY: Number(entry?.sortTopY || entry?.y || 0),
          sortCenterY: Number(entry?.sortCenterY || entry?.centerY || entry?.y || 0),
          partIndex: Number(entry?.partIndex || 0),
          staffIndex: 1
        })).filter((entry) => entry.el)
      : [];
    const stafflines = (preferredStafflines.length ? preferredStafflines : Array.from(container.querySelectorAll("g.staffline"))
      .map((el, idx) => getStafflineGeometry(el, idx)))
      .sort((a, b) => a.sortTopY - b.sortTopY || a.sortCenterY - b.sortCenterY || a.y - b.y || a.x - b.x || a.idx - b.idx);
    if (!stafflines.length) return new Map();

    const bindings = new Map();
    for (let i = 0; i < stafflines.length; i += stafflineBlockSize) {
      const block = stafflines.slice(i, i + stafflineBlockSize);
      block.forEach((row, rowPosition) => {
        const layoutEntry = staffLayout[rowPosition] || null;
        const partIndex = Number.isFinite(Number(row?.partIndex))
          ? Math.max(0, Number(row.partIndex || 0))
          : Math.max(0, Number(layoutEntry?.partIndex || 0));
        const staffIndex = Number.isFinite(Number(row?.staffIndex))
          ? Math.max(1, Number(row.staffIndex || 1))
          : Math.max(1, Number(layoutEntry?.staffIndex || 1));
        const queueState = slotQueuesByStaff.get(makeQueueKey(partIndex, staffIndex));
        if (!queueState || !Array.isArray(queueState.slots)) return;
        const rowNoteheads = getRenderedNoteheadElements(row.el)
          .map((el, idx2) => {
            let box = null;
            try {
              box = typeof el.getBBox === "function" ? el.getBBox() : null;
            } catch {
              box = null;
            }
            return {
              el,
              idx: idx2,
              x: Number(box?.x || 0) + (Number(box?.width || 0) / 2),
              y: Number(box?.y || 0) + (Number(box?.height || 0) / 2)
            };
          })
          .sort((a, b) => a.x - b.x || a.idx - b.idx);
        if (!rowNoteheads.length) return;
        const remaining = Math.max(0, Number(queueState.slots.length || 0) - Number(queueState.cursor || 0));
        if (remaining <= 0) return;
        const upcomingSlots = queueState.slots.slice(queueState.cursor, queueState.cursor + remaining);
        const slotClusters = groupSlotClusters(upcomingSlots);
        let consumed = 0;
        let noteheadCursor = 0;
        const rowSlotClusters = [];
        for (let ci = 0; ci < slotClusters.length && consumed < rowNoteheads.length; ci += 1) {
          const need = Math.max(1, Number(slotClusters[ci]?.items?.length || 0));
          if ((noteheadCursor + need) > rowNoteheads.length) break;
          rowSlotClusters.push(slotClusters[ci]);
          consumed += need;
          noteheadCursor += need;
        }
        if (consumed !== rowNoteheads.length) {
          const bindCount = Math.min(rowNoteheads.length, remaining);
          for (let bi = 0; bi < bindCount; bi += 1) {
            const slot = queueState.slots[queueState.cursor + bi];
            bindings.set(Number(slot?.sourceIndex || 0), rowNoteheads[bi]?.el || rowNoteheads[bi]);
          }
          queueState.cursor += bindCount;
          return;
        }
        noteheadCursor = 0;
        for (let ci = 0; ci < rowSlotClusters.length; ci += 1) {
          const sourceItems = (rowSlotClusters[ci]?.items || [])
            .slice()
            .sort((a, b) =>
              Number(a?.midi || a?.clickMidi || 0) - Number(b?.midi || b?.clickMidi || 0) ||
              Number(a?.sourceIndex || 0) - Number(b?.sourceIndex || 0)
            );
          const renderedItems = rowNoteheads
            .slice(noteheadCursor, noteheadCursor + sourceItems.length)
            .map((item) => item)
            .sort((a, b) => b.y - a.y || a.idx - b.idx);
          const pairCount = Math.min(renderedItems.length, sourceItems.length);
          for (let pi = 0; pi < pairCount; pi += 1) {
            bindings.set(Number(sourceItems[pi]?.sourceIndex || 0), renderedItems[pi]?.el || renderedItems[pi]);
          }
          noteheadCursor += sourceItems.length;
        }
        queueState.cursor += consumed;
      });
    }

    return bindings;
  }

  function applyXmlTrackFocusVisual(container, surrogate = null) {
    const host = container?._twXmlStateHost || container;
    const renderRoot = host?.querySelector?.("[data-tw-xml-render-root='1']") || host;
    const labelHost =
      (host && (host._twXmlStaffLabels || host._twXmlRowMeta) ? host : null) ||
      renderRoot ||
      host;
    if (!host || !renderRoot) return;
    const safeSurrogate = String(surrogate || window.currentSurrogate || "").trim();
    const parsed = host?._twXmlPlaybackModel || renderRoot?._twXmlPlaybackModel || null;
    const trackCount = Math.max(1, Number(parsed?.tracks?.length || 0) || 1);
    const rowMeta = Array.isArray(labelHost?._twXmlRowMeta)
      ? labelHost._twXmlRowMeta.filter(Boolean)
      : (Array.isArray(host?._twXmlRowMeta) ? host._twXmlRowMeta.filter(Boolean) : []);
    const rows = Array.isArray(labelHost?._twXmlStafflineEntries)
      ? labelHost._twXmlStafflineEntries
      : (Array.isArray(host?._twXmlStafflineEntries) ? host._twXmlStafflineEntries : []);
    const labelEntries = Object.entries(labelHost?._twXmlStaffLabels || {})
      .map(([key, label]) => {
        let box = null;
        try {
          box = typeof label?.getBBox === "function" ? label.getBBox() : null;
        } catch {
          box = null;
        }
        const rowIndex = Math.max(0, Number(String(key || "").split(":")[1] || 0));
        return {
          key,
          label,
          rowIndex,
          y: Number(box?.y || 0),
          partIndex: rowMeta[rowIndex] ? Math.max(0, Number(rowMeta[rowIndex].partIndex || 0)) : (rowIndex % trackCount),
          systemBlockIndex: rowMeta[rowIndex] ? Math.max(0, Number(rowMeta[rowIndex].systemBlockIndex || 0)) : Math.floor(rowIndex / trackCount)
        };
      })
      .sort((a, b) => a.y - b.y || a.rowIndex - b.rowIndex);
    if (!rows.length && !labelEntries.length) return;
    // Clear any legacy whole-SVG system transforms before row-level focus transforms.
    applyXmlSystemCompaction(host, {}, false);

    const tracks = safeSurrogate ? (window.twMusicXml?.getTrackPlaybackStates?.(safeSurrogate) || []) : [];
    const allNearFull = tracks.length
      ? tracks.every((track) => !track.mute && Number(track.volume || 0) >= 0.95)
      : true;
    const focusedTrack = allNearFull
      ? null
      : (tracks.find((track) => Number(track.volume || 0) >= 0.95 && !track.mute) || null);
    const focusedTrackId = String(focusedTrack?.id || "");
    const focusedPartTrackId = (() => {
      const raw = String(focusedTrackId || "").trim();
      if (!raw) return "";
      const voiceSep = raw.indexOf("::voice::");
      if (voiceSep > 0) return raw.slice(0, voiceSep);
      return raw;
    })();
    const focusedPartIndex = focusedPartTrackId
      ? Math.max(0, (Array.isArray(parsed?.tracks) ? parsed.tracks : []).findIndex((track) => String(track?.id || "") === focusedPartTrackId))
      : -1;
    const isFilteredUiRender = !!String(host?.dataset?.twXmlUiRenderPartId || "").trim();
    const hasTrackFocusVisual = !isFilteredUiRender && focusedPartIndex >= 0;
    const hideOtherVoices = getXmlHideOtherVoicesEnabled() && !isFilteredUiRender;
    // Stable mode: keep hide-only. Shrink/compaction requires re-rendering filtered XML.
    const compactSystems = false;
    const focusedStafflinePartIndex = hasTrackFocusVisual ? focusedPartIndex : -1;
    const rowShiftByIndex = {};
    if (compactSystems && rows.length > 1) {
      const rowsBySystem = {};
      rows.forEach((row, idx) => {
        const node = row?.el;
        if (!node) return;
        let box = null;
        try {
          box = typeof node.getBBox === "function" ? node.getBBox() : null;
        } catch {
          box = null;
        }
        const centerY = Number(box?.y || 0) + (Math.max(1, Number(box?.height || 0)) / 2);
        const systemBlockIndex = Math.max(0, Number(row?.systemBlockIndex ?? Math.floor(idx / trackCount)));
        if (!rowsBySystem[systemBlockIndex]) rowsBySystem[systemBlockIndex] = [];
        rowsBySystem[systemBlockIndex].push({
          idx,
          centerY,
          partIndex: Math.max(0, Number(row?.partIndex ?? (idx % trackCount))),
          hidden: Math.max(0, Number(row?.partIndex ?? (idx % trackCount))) !== focusedStafflinePartIndex
        });
      });
      const orderedSystemKeys = Object.keys(rowsBySystem)
        .map((k) => Math.max(0, Number(k)))
        .sort((a, b) => a - b);
      let cumulativeShift = 0;
      orderedSystemKeys.forEach((systemKey) => {
        const entries = (rowsBySystem[systemKey] || [])
          .slice()
          .sort((a, b) => Number(a.centerY || 0) - Number(b.centerY || 0));
        if (!entries.length) return;
        const visible = entries.filter((entry) => !entry.hidden);
        if (!visible.length) return;
        const topY = Number(entries[0].centerY || 0);
        const systemShift = -cumulativeShift;
        visible.forEach((entry, visibleIdx) => {
          // Collapse visible rows to the top of their system, then shift full system upward.
          const targetY = topY + (visibleIdx * 2);
          rowShiftByIndex[String(entry.idx)] = systemShift + (targetY - Number(entry.centerY || 0));
        });
        const minCenter = Number(entries[0].centerY || 0);
        const maxCenter = Number(entries[entries.length - 1].centerY || minCenter);
        const originalSpan = Math.max(0, maxCenter - minCenter);
        const compactSpan = Math.max(0, (visible.length - 1) * 2);
        const removableHeight = Math.max(0, Math.min(originalSpan, originalSpan - compactSpan));
        cumulativeShift += removableHeight;
      });
    }
    rows.forEach((row, idx) => {
      const node = row?.el;
      const displayOrderPartIndex = Math.max(0, Number(row?.partIndex ?? (idx % trackCount)));
      if (!node) return;
      if (!node.dataset.twXmlFocusBound) {
        node.dataset.twXmlFocusOpacity = node.style.opacity || "";
        node.dataset.twXmlFocusFilter = node.style.filter || "";
        node.dataset.twXmlFocusTransition = node.style.transition || "";
        node.dataset.twXmlFocusVisibility = node.style.visibility || "";
        node.dataset.twXmlFocusPointerEvents = node.style.pointerEvents || "";
        node.dataset.twXmlFocusTransform = node.style.transform || "";
        node.dataset.twXmlFocusTransformOrigin = node.style.transformOrigin || "";
        node.dataset.twXmlFocusTransformBox = node.style.transformBox || "";
        node.dataset.twXmlFocusBound = "1";
      }
      if (!hasTrackFocusVisual) {
        node.style.opacity = node.dataset.twXmlFocusOpacity || "";
        node.style.filter = node.dataset.twXmlFocusFilter || "";
        node.style.transition = node.dataset.twXmlFocusTransition || "";
        node.style.visibility = node.dataset.twXmlFocusVisibility || "";
        node.style.pointerEvents = node.dataset.twXmlFocusPointerEvents || "";
        node.style.transform = node.dataset.twXmlFocusTransform || "";
        node.style.transformOrigin = node.dataset.twXmlFocusTransformOrigin || "";
        node.style.transformBox = node.dataset.twXmlFocusTransformBox || "";
        return;
      }
      node.style.transition = "opacity 90ms ease, filter 90ms ease, transform 90ms ease";
      node.style.transformBox = "";
      node.style.transformOrigin = "";
      node.style.transform = "";
      if (displayOrderPartIndex === focusedStafflinePartIndex) {
        node.style.opacity = "1";
        node.style.filter = "drop-shadow(0 0 1px rgba(96,165,250,0.45)) saturate(1.04)";
        node.style.visibility = "visible";
        node.style.pointerEvents = "auto";
        return;
      }
      if (hideOtherVoices) {
        node.style.opacity = "0";
        node.style.filter = "none";
        node.style.visibility = "hidden";
        node.style.pointerEvents = "none";
        return;
      }
      node.style.opacity = "0.32";
      node.style.filter = "saturate(0.6) brightness(0.95)";
      node.style.visibility = "visible";
      node.style.pointerEvents = "auto";
    });
    if (compactSystems) {
      rows.forEach((row, idx) => {
        const node = row?.el;
        if (!node) return;
        const shiftY = Number(rowShiftByIndex[String(idx)] || 0);
        node.style.transformBox = "fill-box";
        node.style.transformOrigin = "center center";
        node.style.transform = Math.abs(shiftY) > 0.2 ? `translateY(${shiftY}px)` : "";
      });
    }

    labelEntries.forEach((entry) => {
      const { label, rowIndex, partIndex } = entry;
      if (!label) return;
      if (!label.dataset.twXmlFocusBound) {
        label.dataset.twXmlFocusOpacity = label.style.opacity || "";
        label.dataset.twXmlFocusFilter = label.style.filter || "";
        label.dataset.twXmlFocusVisibility = label.style.visibility || "";
        label.dataset.twXmlFocusTransform = label.style.transform || "";
        label.dataset.twXmlFocusTransformOrigin = label.style.transformOrigin || "";
        label.dataset.twXmlFocusFill = label.getAttribute("fill") || "";
        label.dataset.twXmlFocusFontWeight = label.getAttribute("font-weight") || "";
        label.dataset.twXmlFocusBound = "1";
      }
      if (!hasTrackFocusVisual) {
        label.style.opacity = label.dataset.twXmlFocusOpacity || "";
        label.style.filter = label.dataset.twXmlFocusFilter || "";
        label.style.visibility = label.dataset.twXmlFocusVisibility || "";
        label.style.transform = label.dataset.twXmlFocusTransform || "";
        label.style.transformOrigin = label.dataset.twXmlFocusTransformOrigin || "";
        label.setAttribute("fill", label.dataset.twXmlFocusFill || "rgba(15,23,42,0.82)");
        label.setAttribute("font-weight", label.dataset.twXmlFocusFontWeight || "600");
        return;
      }
      label.style.transition = "opacity 90ms ease, filter 90ms ease, transform 90ms ease";
      label.style.transformOrigin = "";
      label.style.transform = "";
      if (partIndex === focusedPartIndex) {
        label.style.opacity = "1";
        label.style.filter = "drop-shadow(0 0 1px rgba(96,165,250,0.45))";
        label.style.visibility = "visible";
        label.setAttribute("fill", "rgba(30,64,175,0.95)");
        label.setAttribute("font-weight", "700");
        return;
      }
      if (hideOtherVoices) {
        label.style.opacity = "0";
        label.style.filter = "none";
        label.style.visibility = "hidden";
        return;
      }
      label.style.opacity = "0.42";
      label.style.filter = "saturate(0.6)";
      label.style.visibility = "visible";
      label.setAttribute("fill", "rgba(100,116,139,0.72)");
      label.setAttribute("font-weight", "600");
    });
    if (compactSystems) {
      labelEntries.forEach((entry) => {
        const label = entry?.label;
        if (!label) return;
        const shiftY = Number(rowShiftByIndex[String(Math.max(0, Number(entry.rowIndex || 0)))] || 0);
        label.style.transformOrigin = "center center";
        label.style.transform = Math.abs(shiftY) > 0.2 ? `translateY(${shiftY}px)` : "";
      });
    }

    if (!hasTrackFocusVisual) return;
    const viewport = host?.querySelector?.("[data-tw-xml-zoom-viewport='1']");
    if (!viewport || typeof viewport.getBoundingClientRect !== "function") return;
    const preferredBlockIndex = Math.max(
      0,
      Number(
        host?._twXmlSelectedSystemBlockKey ||
        window.__twXmlPlayInternals?.xmlPlaybackState?.currentSystemBlockKey ||
        0
      )
    );
    const targetMeta =
      labelEntries.find((entry) => entry.partIndex === focusedPartIndex && entry.systemBlockIndex === preferredBlockIndex) ||
      labelEntries.find((entry) => entry.partIndex === focusedPartIndex) ||
      rows.find((entry) => Math.max(0, Number(entry?.partIndex || 0)) === focusedPartIndex && Math.max(0, Number(entry?.systemBlockIndex || 0)) === preferredBlockIndex) ||
      rows.find((entry) => Math.max(0, Number(entry?.partIndex || 0)) === focusedPartIndex) ||
      null;
    const target =
      (targetMeta && targetMeta.label) ||
      (targetMeta && targetMeta.el) ||
      null;
    if (!target?.getBoundingClientRect) return;
    const viewRect = viewport.getBoundingClientRect();
    const rowRect = target.getBoundingClientRect();
    const rowCenter = rowRect.top + (rowRect.height / 2);
    const viewCenter = viewRect.top + (viewRect.height / 2);
    const delta = rowCenter - viewCenter;
    if (Math.abs(delta) < 16) return;
    try {
      viewport.scrollTo({ top: Math.max(0, viewport.scrollTop + delta), behavior: "smooth" });
    } catch {
      viewport.scrollTop = Math.max(0, viewport.scrollTop + delta);
    }
  }

  function createHtmlNoteHotspotLayer(container) {
    if (!container) return null;
    container.querySelector?.('[data-tw-xml-note-overlay="1"]')?.remove();
    container.style.position = "relative";
    const layer = document.createElement("div");
    layer.setAttribute("data-tw-xml-note-overlay", "1");
    layer.style.position = "absolute";
    layer.style.inset = "0";
    layer.style.pointerEvents = "none";
    layer.style.zIndex = "18";
    container.appendChild(layer);
    return layer;
  }

  function buildMeasureHighlights(container, slotBindings = []) {
    if (!container) return;
    const svg = container.querySelector("svg");
    if (!svg) return;
    const stateHost = container?._twXmlStateHost || container;
    const parsedModel = stateHost?._twXmlPlaybackModel || container?._twXmlPlaybackModel || null;
    const parsedTracks = Array.isArray(parsedModel?.tracks) ? parsedModel.tracks : [];
    const filteredUiPartId = String(stateHost?.dataset?.twXmlUiRenderPartId || container?.dataset?.twXmlUiRenderPartId || "").trim();
    const filteredUiTrackIndex = filteredUiPartId
      ? Math.max(0, parsedTracks.findIndex((track) => String(track?.id || "").trim() === filteredUiPartId))
      : -1;
    const allTrackLabels = parsedTracks.map((track, idx) => {
      const rawName = String(track?.name || "").trim().replace(/\s+/g, " ");
      if (rawName) {
        if (rawName.startsWith(`${idx + 1}.`) || rawName.startsWith(`${idx + 1} `)) {
          return rawName;
        }
        return `${idx + 1}. ${rawName}`;
      }
      return "";
    });
    const parsedTrackLabels = filteredUiTrackIndex >= 0
      ? [String(allTrackLabels[filteredUiTrackIndex] || `Track ${filteredUiTrackIndex + 1}`)]
      : allTrackLabels;
    container._twXmlPlaybackVisualMap = {
      measureFramesByKey: {},
      measureKeyBySourceIndex: {},
      systemBlockByMeasureKey: {}
    };
    container._twXmlMeasureRects = {};
    container._twXmlStaffLabels = {};
    container._twXmlRowMeta = [];
    container._twXmlStafflineEntries = [];
    container._twXmlStafflineMapByPartIndex = {};
    if (stateHost && stateHost !== container) {
      stateHost._twXmlRowMeta = [];
    }
    if (stateHost && stateHost !== container) {
      stateHost._twXmlStafflineEntries = [];
      stateHost._twXmlStafflineMapByPartIndex = {};
    }
    container._twXmlMeasureFrames = {};
    container._twXmlMeasureGroupFrames = {};
    container._twXmlMeasureGroupFramesByGroupKey = {};
    container._twXmlMeasureKeyByGroupFrameKey = {};
    container._twXmlSystemBlockKeyByMeasureKey = {};
    container._twXmlSystemBlockKeyBySourceIndex = {};
    container._twXmlSystemBlockKeyByMeasureIndex = {};
    container._twXmlSystemFramesByBlockKey = {};
    container._twXmlRenderedMeasureFramesByNumber = {};
    container._twXmlMeasureKeyBySourceIndex = {};
    clearActiveMeasureHighlight(container);
    clearSelectedMeasureHighlight(container);

    svg.querySelector('[data-tw-xml-measure-overlay-layer="1"]')?.remove();
    svg.querySelector('[data-tw-xml-progress-overlay-layer="1"]')?.remove();
    const svgNs = "http://www.w3.org/2000/svg";
    const layer = document.createElementNS(svgNs, "g");
    layer.setAttribute("data-tw-xml-measure-overlay-layer", "1");
    layer.setAttribute("pointer-events", "none");
    const progressLayer = document.createElementNS(svgNs, "g");
    progressLayer.setAttribute("data-tw-xml-progress-overlay-layer", "1");
    progressLayer.setAttribute("pointer-events", "none");

    const noteItems = [];
    const trackCount = filteredUiTrackIndex >= 0
      ? 1
      : Math.max(
          1,
          Array.from(
            new Set(
              (Array.isArray(slotBindings) ? slotBindings : [])
                .map((binding) => Number(binding?.slot?.partIndex || 0))
                .filter((value) => Number.isFinite(value) && value >= 0)
            )
          ).length
        );
    slotBindings.forEach((binding) => {
      const el = binding?.el;
      const slot = binding?.slot;
      if (!el || !slot) return;
      let box = null;
      try {
        box = typeof el.getBBox === "function" ? el.getBBox() : null;
      } catch {
        box = null;
      }
      if (!box || !(box.width > 0) || !(box.height > 0)) return;
      noteItems.push({
        el,
        slot,
        sourceIndex: Math.max(0, Number(slot.sourceIndex || 0)),
        measureIndex: Math.max(0, Number(slot.measureIndex || 0)),
        minX: Number(box.x || 0),
        maxX: Number(box.x || 0) + Number(box.width || 0),
        minY: Number(box.y || 0),
        maxY: Number(box.y || 0) + Number(box.height || 0),
        centerY: Number(box.y || 0) + (Number(box.height || 0) / 2)
      });
    });
    if (!noteItems.length) return;

    const heights = noteItems
      .map((item) => Math.max(1, item.maxY - item.minY))
      .sort((a, b) => a - b);
    const medianHeight = heights[Math.floor(heights.length / 2)] || 12;
    const globalMinX = Math.min(...noteItems.map((item) => Number(item.minX || 0)));
    const staffLabelX = Math.max(10, globalMinX - 8);
    const byY = noteItems.slice().sort((a, b) => a.centerY - b.centerY || a.minX - b.minX);
    const rowBreakThreshold = Math.max(14, medianHeight * 2.4);
    const rows = [];
    byY.forEach((item) => {
      const row = rows[rows.length - 1];
      if (!row || Math.abs(item.centerY - row.anchorY) > rowBreakThreshold) {
        rows.push({
          anchorY: item.centerY,
          items: [item]
        });
        return;
      }
      row.items.push(item);
      row.anchorY = (row.anchorY + item.centerY) / 2;
    });

    let detectedSystemBlockIndex = 0;
    let previousRowBottom = null;
    const systemGapThreshold = Math.max(24, medianHeight * 4.5);
    rows.forEach((row, idx) => {
      const rowItems = Array.isArray(row?.items) ? row.items : [];
      const rowTop = rowItems.length ? Math.min(...rowItems.map((item) => Number(item.minY || 0))) : 0;
      const rowBottom = rowItems.length ? Math.max(...rowItems.map((item) => Number(item.maxY || 0))) : rowTop;
      row._twRowTop = rowTop;
      row._twRowBottom = rowBottom;
      if (previousRowBottom !== null && (rowTop - previousRowBottom) > systemGapThreshold) {
        detectedSystemBlockIndex += 1;
      }
      row._twSystemBlockIndex = detectedSystemBlockIndex;
      previousRowBottom = rowBottom;
    });

    const groupedFrames = new Map();

    rows.forEach((row, rowIndex) => {
      const byMeasure = new Map();
      row.items.forEach((item) => {
        const key = `${rowIndex}:${item.measureIndex}`;
        if (!byMeasure.has(key)) {
          byMeasure.set(key, {
            key,
            measureIndex: item.measureIndex,
            minX: item.minX,
            maxX: item.maxX,
            minY: item.minY,
            maxY: item.maxY,
            sourceIndexes: [item.sourceIndex]
          });
        } else {
          const entry = byMeasure.get(key);
          entry.minX = Math.min(entry.minX, item.minX);
          entry.maxX = Math.max(entry.maxX, item.maxX);
          entry.minY = Math.min(entry.minY, item.minY);
          entry.maxY = Math.max(entry.maxY, item.maxY);
          entry.sourceIndexes.push(item.sourceIndex);
        }
      });

      const entries = Array.from(byMeasure.values()).sort((a, b) => Number(a.measureIndex || 0) - Number(b.measureIndex || 0));
      if (!entries.length) return;
      const rowMinY = Math.min(...row.items.map((item) => item.minY));
      const rowMaxY = Math.max(...row.items.map((item) => item.maxY));
      const avgWidth = entries.reduce((sum, entry) => sum + Math.max(1, entry.maxX - entry.minX), 0) / Math.max(1, entries.length);
      const padX = Math.max(8, avgWidth * 0.07);
      const padY = Math.max(8, medianHeight * 1.4);
      const rowPartCounts = {};
      row.items.forEach((item) => {
        const key = String(Math.max(0, Number(item?.slot?.partIndex || 0)));
        rowPartCounts[key] = Math.max(0, Number(rowPartCounts[key] || 0)) + 1;
      });
      const partIndex = Object.entries(rowPartCounts)
        .sort((a, b) => Number(b[1] || 0) - Number(a[1] || 0) || Number(a[0] || 0) - Number(b[0] || 0))
        .map(([key]) => Math.max(0, Number(key || 0)))[0] ?? 0;
      const rowFrame = {
        rowIndex,
        partIndex,
        systemBlockIndex: Math.max(0, Number(row._twSystemBlockIndex || 0)),
        top: rowMinY - padY,
        bottom: rowMaxY + padY,
        centerY: ((rowMinY - padY) + (rowMaxY + padY)) / 2
      };
      container._twXmlRowMeta[rowIndex] = rowFrame;
      if (stateHost && stateHost !== container) {
        if (!Array.isArray(stateHost._twXmlRowMeta)) stateHost._twXmlRowMeta = [];
        stateHost._twXmlRowMeta[rowIndex] = rowFrame;
      }
      for (let i = 0; i < entries.length; i += 1) {
        const entry = entries[i];
        const prev = entries[i - 1] || null;
        const next = entries[i + 1] || null;
        const left = prev
          ? Math.max(entry.minX - padX, (prev.maxX + entry.minX) / 2)
          : (entry.minX - padX);
        const right = next
          ? Math.min(entry.maxX + padX, (entry.maxX + next.minX) / 2)
          : (entry.maxX + padX);
        const rect = document.createElementNS(svgNs, "rect");
        rect.setAttribute("x", String(left));
        rect.setAttribute("y", String(rowMinY - padY));
        rect.setAttribute("width", String(Math.max(8, right - left)));
        rect.setAttribute("height", String(Math.max(8, (rowMaxY - rowMinY) + (padY * 2))));
        rect.setAttribute("rx", "6");
        rect.setAttribute("ry", "6");
        rect.setAttribute("fill", "#93c5fd");
        rect.setAttribute("fill-opacity", "0.16");
        rect.style.opacity = "0";
        rect.style.transition = "opacity 90ms ease";
        layer.appendChild(rect);
        container._twXmlMeasureRects[entry.key] = rect;
        container._twXmlSystemBlockKeyByMeasureKey[entry.key] = String(Math.max(0, Number(row._twSystemBlockIndex || 0)));
        container._twXmlSystemBlockKeyByMeasureIndex[String(Math.max(0, Number(entry.measureIndex || 0)))] =
          String(Math.max(0, Number(row._twSystemBlockIndex || 0)));
        container._twXmlMeasureFrames[entry.key] = {
          x: left,
          y: rowMinY - padY,
          width: Math.max(8, right - left),
          height: Math.max(8, (rowMaxY - rowMinY) + (padY * 2))
        };
        container._twXmlPlaybackVisualMap.measureFramesByKey[entry.key] = {
          x: left,
          y: rowMinY - padY,
          width: Math.max(8, right - left),
          height: Math.max(8, (rowMaxY - rowMinY) + (padY * 2)),
          measureIndex: Math.max(0, Number(entry.measureIndex || 0)),
          systemBlockKey: String(Math.max(0, Number(row._twSystemBlockIndex || 0)))
        };
        container._twXmlPlaybackVisualMap.systemBlockByMeasureKey[entry.key] = String(Math.max(0, Number(row._twSystemBlockIndex || 0)));
        const groupKey = `${Math.max(0, Number(row._twSystemBlockIndex || 0))}:${Math.max(0, Number(entry.measureIndex || 0))}`;
        let currentGroup = groupedFrames.get(groupKey) || null;
        if (!currentGroup) {
          currentGroup = {
            x: left,
            y: rowMinY - padY,
            width: Math.max(8, right - left),
            height: Math.max(8, (rowMaxY - rowMinY) + (padY * 2))
          };
          groupedFrames.set(groupKey, currentGroup);
        } else {
          const minX = Math.min(Number(currentGroup.x || 0), left);
          const minY = Math.min(Number(currentGroup.y || 0), rowMinY - padY);
          const maxX = Math.max(
            Number(currentGroup.x || 0) + Number(currentGroup.width || 0),
            left + Math.max(8, right - left)
          );
          const maxY = Math.max(
            Number(currentGroup.y || 0) + Number(currentGroup.height || 0),
            (rowMinY - padY) + Math.max(8, (rowMaxY - rowMinY) + (padY * 2))
          );
          currentGroup.x = minX;
          currentGroup.y = minY;
          currentGroup.width = Math.max(8, maxX - minX);
          currentGroup.height = Math.max(8, maxY - minY);
        }
        container._twXmlMeasureGroupFramesByGroupKey[groupKey] = currentGroup;
        if (!container._twXmlMeasureKeyByGroupFrameKey[groupKey]) {
          container._twXmlMeasureKeyByGroupFrameKey[groupKey] = entry.key;
        }
        container._twXmlMeasureGroupFrames[entry.key] = currentGroup;
        entry.sourceIndexes.forEach((sourceIndex) => {
          container._twXmlMeasureKeyBySourceIndex[String(sourceIndex)] = entry.key;
          container._twXmlSystemBlockKeyBySourceIndex[String(sourceIndex)] = String(Math.max(0, Number(row._twSystemBlockIndex || 0)));
          container._twXmlPlaybackVisualMap.measureKeyBySourceIndex[String(sourceIndex)] = entry.key;
        });
      }
    });

    const staffPartOrder = filteredUiTrackIndex >= 0
      ? [0]
      : (() => {
          const allSingleStaff = parsedTracks.length > 0 && parsedTracks.every((track) => Math.max(1, Number(track?.staffCount || 1)) === 1);
          if (allSingleStaff) {
            const displayOrder = getOneStaffDisplayPartOrder(parsedTracks.length || trackCount);
            if (displayOrder.length) return displayOrder;
          }
          const order = [];
          parsedTracks.forEach((track, idx) => {
            const count = Math.max(1, Number(track?.staffCount || 1));
            for (let staffIdx = 0; staffIdx < count; staffIdx += 1) {
              order.push(idx);
            }
          });
          if (order.length) return order;
          const fallbackCount = Math.max(1, parsedTracks.length || trackCount);
          return Array.from({ length: fallbackCount }, (_, idx) => idx);
        })();
    const stafflineBlockSize = Math.max(1, staffPartOrder.length);

    const stafflineEntries = Array.from(svg.querySelectorAll("g.staffline"))
      .map((el, idx) => {
        const geom = getStafflineGeometry(el, idx);
        return {
          el,
          idx,
          y: Number(geom.y || 0),
          x: Number(geom.x || 0),
          height: Math.max(1, Number(geom.height || 0)),
          centerY: Number(geom.sortCenterY || 0),
          labelY: Number(geom.y || 0),
          sortTopY: Number(geom.sortTopY || 0),
          sortCenterY: Number(geom.sortCenterY || 0)
        };
      })
      .sort((a, b) => a.sortTopY - b.sortTopY || a.sortCenterY - b.sortCenterY || a.y - b.y || a.x - b.x || a.idx - b.idx);

    stafflineEntries.forEach((entry, idx) => {
      entry.partIndex = Math.max(0, Number(staffPartOrder[idx % stafflineBlockSize] ?? 0));
      entry.systemBlockIndex = Math.floor(idx / stafflineBlockSize);
    });
    const stafflineMapByPartIndex = {};
    stafflineEntries.forEach((entry) => {
      const key = String(Math.max(0, Number(entry.partIndex || 0)));
      if (!Array.isArray(stafflineMapByPartIndex[key])) stafflineMapByPartIndex[key] = [];
      stafflineMapByPartIndex[key].push(entry);
    });
    container._twXmlStaffLabels = {};
    // Test mode: rely on OSMD-native staff/part labels instead of custom overlays.
    container._twXmlStafflineEntries = stafflineEntries;
    container._twXmlStafflineMapByPartIndex = stafflineMapByPartIndex;
    if (stateHost && stateHost !== container) {
      stateHost._twXmlStafflineEntries = stafflineEntries;
      stateHost._twXmlStafflineMapByPartIndex = stafflineMapByPartIndex;
    }

    const visibleMeasureEntries = Object.entries(container._twXmlSystemBlockKeyByMeasureIndex || {})
      .map(([measureKey, blockKey]) => ({
        measureIndex: Math.max(0, Number(measureKey || 0)),
        blockKey: String(blockKey || "")
      }))
      .filter((entry) => entry.blockKey)
      .sort((a, b) => a.measureIndex - b.measureIndex || Number(a.blockKey) - Number(b.blockKey));
    if (visibleMeasureEntries.length) {
      const filledMap = {};
      const first = visibleMeasureEntries[0];
      filledMap[String(first.measureIndex)] = first.blockKey;
      for (let i = 1; i < visibleMeasureEntries.length; i += 1) {
        const prev = visibleMeasureEntries[i - 1];
        const next = visibleMeasureEntries[i];
        filledMap[String(next.measureIndex)] = next.blockKey;
        for (let mi = prev.measureIndex + 1; mi < next.measureIndex; mi += 1) {
          filledMap[String(mi)] = next.blockKey !== prev.blockKey ? next.blockKey : prev.blockKey;
        }
      }
      container._twXmlSystemBlockKeyByMeasureIndex = {
        ...container._twXmlSystemBlockKeyByMeasureIndex,
        ...filledMap
      };
    }

    const blockUnions = new Map();
    Object.entries(container._twXmlMeasureGroupFramesByGroupKey).forEach(([groupKey, frame]) => {
      const blockKey = String(groupKey.split(":")[0] || "");
      if (!blockKey || !frame) return;
      const current = blockUnions.get(blockKey);
      const x = Number(frame.x || 0);
      const y = Number(frame.y || 0);
      const right = x + Number(frame.width || 0);
      const bottom = y + Number(frame.height || 0);
      if (!current) {
        blockUnions.set(blockKey, { x, y, right, bottom });
        return;
      }
      current.x = Math.min(current.x, x);
      current.y = Math.min(current.y, y);
      current.right = Math.max(current.right, right);
      current.bottom = Math.max(current.bottom, bottom);
    });
    const stafflineFrames = Array.from(svg.querySelectorAll("g.staffline"))
      .map((el, idx) => {
        try {
          const box = typeof el.getBBox === "function" ? el.getBBox() : null;
          if (!box || !(box.width > 0) || !(box.height > 0)) return null;
          return {
            idx,
            x: Number(box.x || 0),
            y: Number(box.y || 0),
            right: Number(box.x || 0) + Number(box.width || 0),
            bottom: Number(box.y || 0) + Number(box.height || 0)
          };
        } catch {
          return null;
        }
      })
      .filter(Boolean)
      .sort((a, b) => Number(a.y || 0) - Number(b.y || 0) || Number(a.x || 0) - Number(b.x || 0) || Number(a.idx || 0) - Number(b.idx || 0));

    if (stafflineFrames.length >= trackCount) {
      for (let i = 0, blockIndex = 0; i < stafflineFrames.length; i += trackCount, blockIndex += 1) {
        const block = stafflineFrames.slice(i, i + trackCount);
        if (!block.length) continue;
        const minX = Math.min(...block.map((frame) => Number(frame.x || 0)));
        const minY = Math.min(...block.map((frame) => Number(frame.y || 0)));
        const maxRight = Math.max(...block.map((frame) => Number(frame.right || 0)));
        const maxBottom = Math.max(...block.map((frame) => Number(frame.bottom || 0)));
        const fallbackUnion = blockUnions.get(String(blockIndex));
        container._twXmlSystemFramesByBlockKey[String(blockIndex)] = {
          x: Number.isFinite(Number(fallbackUnion?.x)) ? Math.min(minX, Number(fallbackUnion.x || 0)) : minX,
          y: minY,
          width: Math.max(
            8,
            Math.max(maxRight, Number.isFinite(Number(fallbackUnion?.right)) ? Number(fallbackUnion.right || 0) : maxRight) -
              (Number.isFinite(Number(fallbackUnion?.x)) ? Math.min(minX, Number(fallbackUnion.x || 0)) : minX)
          ),
          height: Math.max(8, maxBottom - minY)
        };
      }
    } else {
      blockUnions.forEach((union, blockKey) => {
        container._twXmlSystemFramesByBlockKey[blockKey] = {
          x: Number(union.x || 0),
          y: Number(union.y || 0),
          width: Math.max(8, Number(union.right || 0) - Number(union.x || 0)),
          height: Math.max(8, Number(union.bottom || 0) - Number(union.y || 0))
        };
      });
    }

    const measureContentBox = (measureEl) => {
      if (!measureEl) return null;
      try {
        const box = typeof measureEl.getBBox === "function" ? measureEl.getBBox() : null;
        if (box && Number(box.width || 0) > 0 && Number(box.height || 0) > 0) {
          return {
            x: Number(box.x || 0),
            y: Number(box.y || 0),
            width: Math.max(8, Number(box.width || 0)),
            height: Math.max(8, Number(box.height || 0))
          };
        }
      } catch {}
      const children = Array.from(measureEl.children || []);
      const candidates = children.filter((child) => {
        const cls = String(child.getAttribute?.("class") || "").toLowerCase();
        return !cls.includes("lyrics");
      });
      const targets = candidates.length ? candidates : [measureEl];
      let minX = Infinity;
      let minY = Infinity;
      let maxX = -Infinity;
      let maxY = -Infinity;
      targets.forEach((target) => {
        try {
          const box = typeof target.getBBox === "function" ? target.getBBox() : null;
          if (!box || !(box.width > 0) || !(box.height > 0)) return;
          minX = Math.min(minX, Number(box.x || 0));
          minY = Math.min(minY, Number(box.y || 0));
          maxX = Math.max(maxX, Number(box.x || 0) + Number(box.width || 0));
          maxY = Math.max(maxY, Number(box.y || 0) + Number(box.height || 0));
        } catch {}
      });
      if (!Number.isFinite(minX) || !Number.isFinite(minY) || !(maxX > minX) || !(maxY > minY)) return null;
      return {
        x: minX,
        y: minY,
        width: Math.max(8, maxX - minX),
        height: Math.max(8, maxY - minY)
      };
    };
    const renderedMeasureFramesByNumber = {};
    Array.from(svg.querySelectorAll("g.vf-measure[id]")).forEach((el) => {
      const measureNumber = String(el.getAttribute("id") || "").trim();
      if (!measureNumber) return;
      const box = measureContentBox(el);
      if (!box) return;
      const minX = Number(box.x || 0);
      const minY = Number(box.y || 0);
      const maxX = minX + Number(box.width || 0);
      const maxY = minY + Number(box.height || 0);
      const current = renderedMeasureFramesByNumber[measureNumber];
      if (!current) {
        renderedMeasureFramesByNumber[measureNumber] = {
          x: minX,
          y: minY,
          width: Math.max(8, maxX - minX),
          height: Math.max(8, maxY - minY)
        };
        return;
      }
      const nextMinX = Math.min(Number(current.x || 0), minX);
      const nextMinY = Math.min(Number(current.y || 0), minY);
      const nextMaxX = Math.max(Number(current.x || 0) + Number(current.width || 0), maxX);
      const nextMaxY = Math.max(Number(current.y || 0) + Number(current.height || 0), maxY);
      current.x = nextMinX;
      current.y = nextMinY;
      current.width = Math.max(8, nextMaxX - nextMinX);
      current.height = Math.max(8, nextMaxY - nextMinY);
    });
    container._twXmlRenderedMeasureFramesByNumber = renderedMeasureFramesByNumber;
    const progressLine = document.createElementNS(svgNs, "rect");
    progressLine.setAttribute("data-tw-xml-progress-line", "1");
    progressLine.setAttribute("rx", "3");
    progressLine.setAttribute("ry", "3");
    progressLine.setAttribute("fill", "#f97316");
    progressLine.setAttribute("fill-opacity", "0.95");
    progressLine.setAttribute("stroke", "#ffedd5");
    progressLine.setAttribute("stroke-width", "1");
    progressLine.style.opacity = "0";
    progressLine.style.transition = "opacity 110ms ease, transform 110ms ease";
    progressLayer.appendChild(progressLine);
    container._twXmlPlayhead = progressLine;
    container._twXmlProgressLine = progressLine;

    slotBindings.forEach((binding) => {
      const el = binding?.el;
      const sourceIndex = Math.max(0, Number(binding?.slot?.sourceIndex || 0));
      if (!el) return;
      const renderedKey = String(container._twXmlMeasureKeyBySourceIndex[String(sourceIndex)] || "");
      if (renderedKey) el.dataset.twXmlMeasureKey = renderedKey;
    });

    svg.insertBefore(layer, svg.firstChild || null);
    svg.appendChild(progressLayer);
  }

  function readOsmdRect(target) {
    if (!target || typeof target !== "object") return null;
    const shape =
      target.PositionAndShape ||
      target.positionAndShape ||
      target.boundingBox ||
      target.BoundingBox ||
      null;
    if (!shape || typeof shape !== "object") return null;
    const abs =
      shape.AbsolutePosition ||
      shape.absolutePosition ||
      shape.Position ||
      shape.position ||
      null;
    const size =
      shape.Size ||
      shape.size ||
      null;
    const x = Number(abs?.x ?? abs?.X ?? shape.x ?? shape.X);
    const y = Number(abs?.y ?? abs?.Y ?? shape.y ?? shape.Y);
    const width = Number(size?.width ?? size?.Width ?? shape.width ?? shape.Width);
    const height = Number(size?.height ?? size?.Height ?? shape.height ?? shape.Height);
    if (!Number.isFinite(x) || !Number.isFinite(y) || !(width > 0) || !(height > 0)) return null;
    return { x, y, width, height };
  }

  function buildOsmdMeasureFrames(container, osmd) {
    const measureList = osmd?.GraphicSheet?.MeasureList;
    if (!container || !Array.isArray(measureList) || !measureList.length) return false;
    const guessedGroupFrames = container?._twXmlMeasureGroupFramesByGroupKey || {};
    const framesByIndex = {};
    measureList.forEach((bucket, measureIndex) => {
      const items = Array.isArray(bucket)
        ? bucket
        : (bucket && typeof bucket === "object" ? Object.values(bucket) : []);
      let minX = Infinity;
      let minY = Infinity;
      let maxX = -Infinity;
      let maxY = -Infinity;
      items.forEach((item) => {
        const rect = readOsmdRect(item);
        if (!rect) return;
        minX = Math.min(minX, Number(rect.x || 0));
        minY = Math.min(minY, Number(rect.y || 0));
        maxX = Math.max(maxX, Number(rect.x || 0) + Number(rect.width || 0));
        maxY = Math.max(maxY, Number(rect.y || 0) + Number(rect.height || 0));
      });
      if (!Number.isFinite(minX) || !Number.isFinite(minY) || !(maxX > minX) || !(maxY > minY)) return;
      const frame = {
        x: minX,
        y: minY,
        width: Math.max(8, maxX - minX),
        height: Math.max(8, maxY - minY)
      };
      framesByIndex[String(Math.max(0, Number(measureIndex || 0)))] = frame;
    });
    if (!Object.keys(framesByIndex).length) return false;
    const overlapIndexes = Object.keys(framesByIndex)
      .map((measureIndex) => Number(measureIndex))
      .filter((measureIndex) => Number.isFinite(measureIndex) && framesByIndex[String(measureIndex)] && guessedGroupFrames)
      .filter((measureIndex) => {
        const guessedKey = Object.keys(guessedGroupFrames).find((key) => key.endsWith(`:${measureIndex}`));
        return !!guessedKey;
      });
    if (overlapIndexes.length) {
      const ratiosX = [];
      const ratiosY = [];
      overlapIndexes.forEach((measureIndex) => {
        const osmdFrame = framesByIndex[String(measureIndex)];
        const guessedKey = Object.keys(guessedGroupFrames).find((key) => key.endsWith(`:${measureIndex}`));
        const guessedFrame = guessedKey ? guessedGroupFrames[guessedKey] : null;
        if (!osmdFrame || !guessedFrame) return;
        if (Number(osmdFrame.width || 0) > 0 && Number(guessedFrame.width || 0) > 0) {
          ratiosX.push(Number(guessedFrame.width || 0) / Number(osmdFrame.width || 1));
        }
        if (Number(osmdFrame.height || 0) > 0 && Number(guessedFrame.height || 0) > 0) {
          ratiosY.push(Number(guessedFrame.height || 0) / Number(osmdFrame.height || 1));
        }
      });
      const pickPositiveMedian = (values, fallback) => {
        const sorted = values
          .filter((value) => Number.isFinite(value) && value > 0)
          .sort((a, b) => a - b);
        return sorted.length ? Number(sorted[Math.floor(sorted.length / 2)] || fallback) : fallback;
      };
      const pickMedian = (values, fallback) => {
        const sorted = values
          .filter((value) => Number.isFinite(value))
          .sort((a, b) => a - b);
        return sorted.length ? Number(sorted[Math.floor(sorted.length / 2)] || fallback) : fallback;
      };
      const scaleX = pickPositiveMedian(ratiosX, 1);
      const scaleY = pickPositiveMedian(ratiosY, scaleX);
      const offsetsX = [];
      const offsetsY = [];
      overlapIndexes.forEach((measureIndex) => {
        const osmdFrame = framesByIndex[String(measureIndex)];
        const guessedKey = Object.keys(guessedGroupFrames).find((key) => key.endsWith(`:${measureIndex}`));
        const guessedFrame = guessedKey ? guessedGroupFrames[guessedKey] : null;
        if (!osmdFrame || !guessedFrame) return;
        offsetsX.push(Number(guessedFrame.x || 0) - (Number(osmdFrame.x || 0) * scaleX));
        offsetsY.push(Number(guessedFrame.y || 0) - (Number(osmdFrame.y || 0) * scaleY));
      });
      const offsetX = pickMedian(offsetsX, 0);
      const offsetY = pickMedian(offsetsY, 0);
      Object.keys(framesByIndex).forEach((key) => {
        const frame = framesByIndex[key];
        if (!frame) return;
        frame.x = (Number(frame.x || 0) * scaleX) + offsetX;
        frame.y = (Number(frame.y || 0) * scaleY) + offsetY;
        frame.width = Math.max(8, Number(frame.width || 0) * scaleX);
        frame.height = Math.max(8, Number(frame.height || 0) * scaleY);
      });
    }
    container._twXmlOsmdMeasureFramesByIndex = framesByIndex;
    if (!container._twXmlPlaybackVisualMap || typeof container._twXmlPlaybackVisualMap !== "object") {
      container._twXmlPlaybackVisualMap = {
        measureFramesByKey: {},
        measureKeyBySourceIndex: {},
        systemBlockByMeasureKey: {}
      };
    }
    container._twXmlPlaybackVisualMap.osmdMeasureFramesByIndex = { ...framesByIndex };
    return true;
  }

  function buildXmlPlayheadMeta(parsed) {
    const events = Array.isArray(parsed?.events) ? parsed.events : [];
    const sourceIndexToStartTick = {};
    const measureSourceIndexByIndex = {};
    const measureStartTickByIndex = {};
    const normalized = events
      .map((ev) => {
        const sourceIndex = Math.max(0, Number(ev?.sourceIndex || 0));
        const startTick = Math.max(0, Math.floor(Number(ev?.startUnit || 0)));
        const durationTick = Math.max(1, Math.floor(Number(ev?.durationUnit || 1)));
        if (!(String(sourceIndex) in sourceIndexToStartTick)) {
          sourceIndexToStartTick[String(sourceIndex)] = startTick;
        } else {
          sourceIndexToStartTick[String(sourceIndex)] = Math.min(
            Number(sourceIndexToStartTick[String(sourceIndex)] || 0),
            startTick
          );
        }
        const measureIndex = Math.max(0, Number(ev?.measureIndex || 0));
        const measureKey = String(measureIndex);
        if (!(measureKey in measureSourceIndexByIndex) || startTick < Number(sourceIndexToStartTick[String(measureSourceIndexByIndex[measureKey])] || Infinity)) {
          measureSourceIndexByIndex[measureKey] = sourceIndex;
        }
        return {
          measureIndex,
          startTick,
          endTick: startTick + durationTick
        };
      })
      .sort((a, b) => Number(a.startTick || 0) - Number(b.startTick || 0));
    const measureTimeline = buildXmlMeasureTimeline(normalized, parsed);
    measureTimeline.forEach((entry) => {
      const measureIndex = Math.max(0, Number(entry?.measureIndex || 0));
      const key = String(measureIndex);
      if (!(key in measureStartTickByIndex)) {
        measureStartTickByIndex[key] = Math.max(0, Number(entry?.startTick || 0));
      }
    });
    const totalTicks = measureTimeline.length
      ? measureTimeline.reduce((max, entry) => Math.max(max, Number(entry?.endTick || 0)), 0)
      : normalized.reduce((max, ev) => Math.max(max, Number(ev.endTick || 0)), 0);
    const pickupLeadTicks = 0;
    const declaredDurations = parsed?.measureDurationTickByIndex || {};
    const declaredIndexes = Object.keys(declaredDurations)
      .map((key) => Number(key))
      .filter((value) => Number.isFinite(value) && value >= 0)
      .sort((a, b) => a - b);
    const displayMeasureTimeline = [];
    if (declaredIndexes.length) {
      let cursor = 0;
      const maxIndex = Math.max(...declaredIndexes);
      for (let measureIndex = 0; measureIndex <= maxIndex; measureIndex += 1) {
        const dur = Math.max(1, Number(declaredDurations?.[String(measureIndex)] || 1));
        displayMeasureTimeline.push({ measureIndex, startTick: cursor, endTick: cursor + dur });
        cursor += dur;
      }
    } else {
      const seen = new Set();
      measureTimeline.forEach((entry) => {
        const measureIndex = Math.max(0, Number(entry?.measureIndex || 0));
        if (seen.has(measureIndex)) return;
        seen.add(measureIndex);
        displayMeasureTimeline.push({
          measureIndex,
          startTick: Math.max(0, Number(entry?.startTick || 0)),
          endTick: Math.max(1, Number(entry?.endTick || 0))
        });
      });
      displayMeasureTimeline.sort((a, b) => Number(a.startTick || 0) - Number(b.startTick || 0));
    }
    const displayTotalTicks = Math.max(
      1,
      displayMeasureTimeline.length
        ? displayMeasureTimeline.reduce((max, entry) => Math.max(max, Number(entry?.endTick || 0)), 0)
        : Number(totalTicks || 0)
    );
    const displayStartByMeasureIndex = {};
    const displayEndByMeasureIndex = {};
    displayMeasureTimeline.forEach((entry) => {
      const measureIndex = Math.max(0, Number(entry?.measureIndex || 0));
      const key = String(measureIndex);
      if (!(key in displayStartByMeasureIndex)) {
        displayStartByMeasureIndex[key] = Math.max(0, Number(entry?.startTick || 0));
        displayEndByMeasureIndex[key] = Math.max(
          Number(displayStartByMeasureIndex[key] || 0) + 1,
          Number(entry?.endTick || (Number(displayStartByMeasureIndex[key] || 0) + 1))
        );
      }
    });
    const repeatOrder = Array.isArray(parsed?.repeatMeasureOrder) ? parsed.repeatMeasureOrder : [];
    const repeatPassByTimelinePos = [];
    const repeatPassStartMeasureByPass = {};
    const repeatPassEndMeasureByPass = {};
    let repeatPassCount = 1;
    let prevMeasureIndex = null;
    let firstRepeatStartMeasure = null;
    let firstRepeatEndMeasure = null;
    const sequenceForPasses = repeatOrder.length
      ? repeatOrder.map((measureIndex) => Math.max(0, Number(measureIndex || 0)))
      : measureTimeline.map((entry) => Math.max(0, Number(entry?.measureIndex || 0)));
    sequenceForPasses.forEach((measureIndex, idx) => {
      if (idx === 0) {
        repeatPassStartMeasureByPass["1"] = measureIndex;
      }
      if (idx > 0 && Number.isFinite(prevMeasureIndex) && measureIndex < prevMeasureIndex) {
        if (!Number.isFinite(firstRepeatStartMeasure)) {
          firstRepeatStartMeasure = measureIndex;
          firstRepeatEndMeasure = Math.max(0, Number(prevMeasureIndex || 0));
        }
        repeatPassEndMeasureByPass[String(repeatPassCount)] = Math.max(0, Number(prevMeasureIndex || 0));
        repeatPassCount += 1;
        repeatPassStartMeasureByPass[String(repeatPassCount)] = measureIndex;
      }
      prevMeasureIndex = measureIndex;
    });
    if (Number.isFinite(prevMeasureIndex)) {
      repeatPassEndMeasureByPass[String(repeatPassCount)] = Math.max(0, Number(prevMeasureIndex || 0));
    }
    if (Number.isFinite(firstRepeatStartMeasure)) {
      // Mirror playback fallback: if no explicit repeat start exists, jump target is song start.
      repeatPassStartMeasureByPass["1"] = Math.max(0, Number(firstRepeatStartMeasure || 0));
    }
    if (Number.isFinite(firstRepeatEndMeasure)) {
      repeatPassEndMeasureByPass["1"] = Math.max(0, Number(firstRepeatEndMeasure || 0));
    }
    let timelinePass = 1;
    let timelinePrevMeasureIndex = null;
    measureTimeline.forEach((entry, idx) => {
      const measureIndex = Math.max(0, Number(entry?.measureIndex || 0));
      if (idx > 0 && Number.isFinite(timelinePrevMeasureIndex) && measureIndex < timelinePrevMeasureIndex) {
        timelinePass += 1;
      }
      repeatPassByTimelinePos[idx] = Math.max(1, timelinePass);
      timelinePrevMeasureIndex = measureIndex;
    });
    const hasRepeatFlow =
      repeatPassCount > 1 ||
      (repeatOrder.length > 1 && repeatOrder.some((measureIndex, idx) => Number(measureIndex || 0) !== idx));
    return {
      measureTimeline,
      displayMeasureTimeline,
      totalTicks: Math.max(0, Number(totalTicks || 0)),
      pickupLeadTicks,
      displayTotalTicks,
      sourceIndexToStartTick,
      measureSourceIndexByIndex,
      measureStartTickByIndex,
      displayStartByMeasureIndex,
      displayEndByMeasureIndex,
      repeatPassByTimelinePos,
      repeatPassStartMeasureByPass,
      repeatPassEndMeasureByPass,
      repeatPassCount: Math.max(1, Number(repeatPassCount || 1)),
      hasRepeatFlow
    };
  }

  function resolveEffectiveRepeatPass(host, opts = {}, currentRepeatPass = 1) {
    const overrideRepeatPassRaw = Number(opts?.repeatPassOverride);
    const hostRepeatPassRaw = Number(host?._twXmlRepeatPassOverride);
    const effectiveRepeatPass = Number.isFinite(overrideRepeatPassRaw) && overrideRepeatPassRaw >= 1
      ? Math.max(1, Math.floor(overrideRepeatPassRaw))
      : (Number.isFinite(hostRepeatPassRaw) && hostRepeatPassRaw >= 1
        ? Math.max(1, Math.floor(hostRepeatPassRaw))
        : Math.max(1, Math.floor(Number(currentRepeatPass || 1))));
    if (host) host._twXmlCurrentRepeatPass = effectiveRepeatPass;
    return effectiveRepeatPass;
  }

  function resolveStaticRepeatMarkerTicks(meta, effectiveRepeatPass = 1) {
    const passStartMap = meta?.repeatPassStartMeasureByPass || {};
    const passEndMap = meta?.repeatPassEndMeasureByPass || {};
    const staticStartMeasureIndex = Math.max(
      0,
      Number(passStartMap["1"] ?? passStartMap["2"] ?? 0)
    );
    const staticEndMeasureIndex = Math.max(
      staticStartMeasureIndex,
      Number(passEndMap["1"] ?? passEndMap[String(effectiveRepeatPass)] ?? staticStartMeasureIndex)
    );
    const startTick = Math.max(
      0,
      Number(meta?.displayStartByMeasureIndex?.[String(staticStartMeasureIndex)] || 0)
    );
    const endTick = Math.max(
      startTick,
      Number(meta?.displayEndByMeasureIndex?.[String(staticEndMeasureIndex)] || startTick)
    );
    return { startTick, endTick };
  }

  function updateXmlPlayheadIndicator(container, opts = {}) {
    const host = container?._twXmlStateHost || container;
    const roots = [
      host,
      document.getElementById("twPianoXmlStatusBar")
    ].filter((root, idx, arr) => root && arr.indexOf(root) === idx);
    const meta = host?._twXmlPlayheadMeta || null;
    const stats = host?._twXmlMeasureStats || null;
    const playbackModel = host?._twXmlPlaybackModel || document.getElementById("pdfTabXmlViewer")?._twXmlPlaybackModel || null;
    const measureIndex = Math.max(0, Number(opts?.measureIndex || 0));
    const active = !!opts?.active;
    const showValue = active || !!opts?.showValue;
    const overall = Math.max(0, Math.min(1, Number(opts?.overallProgress || 0)));
    const timeline = Array.isArray(meta?.measureTimeline) ? meta.measureTimeline : [];
    const totalTicks = Math.max(1, Number(meta?.totalTicks || 1));
    const absoluteTick = Math.max(
      0,
      Number.isFinite(Number(opts?.absoluteTick))
        ? Number(opts.absoluteTick)
        : (overall * totalTicks)
    );
    let currentMeasure = timeline[0] || null;
    let currentMeasurePos = 0;
    for (let i = 1; i < timeline.length; i += 1) {
      if (Number(timeline[i]?.startTick || 0) <= absoluteTick) {
        currentMeasure = timeline[i];
        currentMeasurePos = i;
      }
      else break;
    }
    const measureKey = String(measureIndex);
    const declaredBeats = Math.max(
      1,
      Number(playbackModel?.measureBeatsByIndex?.[measureKey] || playbackModel?.scoreBeats || 4)
    );
    const declaredBeatType = Math.max(
      1,
      Number(playbackModel?.measureBeatTypeByIndex?.[measureKey] || playbackModel?.beatType || 4)
    );
    const pulseCount = Math.max(
      0,
      Number(playbackModel?.measurePulseCountByIndex?.[measureKey] || playbackModel?.scorePulseCount || 0)
    );
    const useQuarterPulseForMeasure = !!playbackModel?.counterUsesQuarterPulse && pulseCount > 0;
    const totalBeatCount = Math.max(1, Math.round(useQuarterPulseForMeasure ? pulseCount : declaredBeats));
    const divisionBase = Math.max(1, Number(window.__twXmlPlayInternals?.XML_PLAYBACK_DIVISION || 480));
    const measureStartTick = Math.max(0, Number(currentMeasure?.startTick || 0));
    const elapsedInMeasure = Math.max(0, absoluteTick - measureStartTick);
    const beatTicks = Math.max(
      1,
      Number(playbackModel?.division || divisionBase || 1) *
        (useQuarterPulseForMeasure ? 1 : (4 / declaredBeatType))
    );
    const currentBeatCount = Math.max(
      1,
      Math.min(totalBeatCount, 1 + Math.floor(elapsedInMeasure / beatTicks))
    );
    const beatLabel = `${currentBeatCount}`;
    const repeatPassByTimelinePos = Array.isArray(meta?.repeatPassByTimelinePos) ? meta.repeatPassByTimelinePos : [];
    const repeatPassCount = Math.max(1, Number(meta?.repeatPassCount || 1));
    const hasRepeatFlow = !!meta?.hasRepeatFlow;
    const currentRepeatPass = Math.max(
      1,
      Number(repeatPassByTimelinePos[Math.max(0, currentMeasurePos)] || 1)
    );
    const effectiveRepeatPass = resolveEffectiveRepeatPass(host, opts, currentRepeatPass);
    roots.forEach((root) => {
      const track = root?.querySelector?.('[data-tw-xml-playhead-track="1"]');
      const fill = root?.querySelector?.('[data-tw-xml-playhead-fill="1"]');
      const head = root?.querySelector?.('[data-tw-xml-playhead-head="1"]');
      const label = root?.querySelector?.('[data-tw-xml-playhead-label="1"]');
      const repeatBadge =
        root?.querySelector?.('[data-tw-xml-repeat-onbar="1"]') ||
        root?.querySelector?.('[data-tw-xml-repeat-indicator="1"]');
      const repeatEndBadge = root?.querySelector?.('[data-tw-xml-repeat-end-onbar="1"]');
      const markersHost = root?.querySelector?.('[data-tw-xml-measure-markers="1"]');
      if (!track || !fill) return;
      fill.style.width = `${Math.max(0, Math.min(100, overall * 100))}%`;
      fill.style.opacity = active ? "1" : (showValue ? "0.78" : "0.28");
      track.style.opacity = active ? "1" : (showValue ? "0.96" : "0.8");
      if (head) {
        head.style.left = `calc(${Math.max(0, Math.min(100, overall * 100))}% - 5px)`;
        head.style.opacity = showValue ? "1" : "0.38";
      }
      if (label) {
        label.textContent = showValue ? beatLabel : "--";
        label.style.opacity = showValue ? "1" : "0.7";
      }
      if (repeatBadge) {
        const showRepeat = hasRepeatFlow && repeatPassCount > 1;
        if (!showRepeat) {
          repeatBadge.textContent = "";
          repeatBadge.style.opacity = "0";
          if (repeatEndBadge) {
            repeatEndBadge.textContent = "";
            repeatEndBadge.style.opacity = "0";
          }
        } else {
          const { startTick, endTick } = resolveStaticRepeatMarkerTicks(meta, effectiveRepeatPass);
          const displayTicks = Math.max(1, Number(meta?.displayTotalTicks || meta?.totalTicks || 1));
          const startLeftPct = Math.max(2, Math.min(100, (startTick / displayTicks) * 100));
          const endLeftPct = Math.max(0, Math.min(100, (endTick / displayTicks) * 100));
          const repeatCounterText = `|:${Math.max(1, Number(effectiveRepeatPass || 1))}`;
          repeatBadge.textContent = repeatCounterText;
          repeatBadge.style.left = `${startLeftPct}%`;
          repeatBadge.style.opacity = "1";
          if (repeatEndBadge) {
            repeatEndBadge.textContent = ":|";
            repeatEndBadge.style.left = `${endLeftPct}%`;
            repeatEndBadge.style.opacity = "1";
          }
        }
      }
      if (markersHost && meta && stats) {
        const measureEntries = Array.isArray(meta.displayMeasureTimeline) ? meta.displayMeasureTimeline : [];
        const totalMeasureCount = Math.max(0, Number(stats.total || measureEntries.length || 0));
        const pickupLeadTicks = Math.max(0, Number(meta.pickupLeadTicks || 0));
        const displayTotalTicks = Math.max(1, Number(meta.displayTotalTicks || meta.totalTicks || 1));
        const markerSignature = `${totalMeasureCount}:${displayTotalTicks}:${pickupLeadTicks}:${measureEntries.length}`;
        if (markersHost.dataset.twXmlMarkerSig !== markerSignature) {
          markersHost.dataset.twXmlMarkerSig = markerSignature;
          markersHost.innerHTML = "";
          const chosen = new Set();
          measureEntries.forEach((entry, idx) => {
            const mi = Math.max(0, Number(entry?.measureIndex || 0));
            const key = String(mi);
            const pos = Math.max(0, Number(stats?.positionByIndex?.[key] || (idx + 1)));
            if (!(pos > 0)) return;
            const isSparse =
              pos === 1 ||
              pos === totalMeasureCount ||
              pos % 4 === 0;
            if (!isSparse || chosen.has(key)) return;
            chosen.add(key);
            const marker = document.createElement("div");
            marker.className = "tw-piano-xml-marker";
            marker.dataset.measureIndex = key;
            const displayTick = mi === 0
              ? 0
              : Math.max(0, Number(entry?.startTick || 0) + pickupLeadTicks);
            const leftPct = Math.max(0, Math.min(100, (displayTick / displayTotalTicks) * 100));
            marker.style.left = `${leftPct}%`;
            marker.textContent = String(stats?.numberByIndex?.[key] || `${mi + 1}`);
            markersHost.appendChild(marker);
          });
        }
        markersHost.querySelectorAll(".tw-piano-xml-marker").forEach((markerEl) => {
          const isCurrent = String(markerEl?.dataset?.measureIndex || "") === measureKey;
          markerEl.classList.toggle("is-current", isCurrent);
          markerEl.style.opacity = isCurrent ? "1" : "0.78";
        });
      }
    });
  }

  function updateXmlPlayheadFromSourceIndex(container, sourceIndex, opts = {}) {
    const host = container?._twXmlStateHost || container;
    const meta = host?._twXmlPlayheadMeta || null;
    const safeSourceIndex = Math.max(0, Number(sourceIndex || 0));
    const startTick = Math.max(0, Number(meta?.sourceIndexToStartTick?.[String(safeSourceIndex)] || 0));
    const totalTicks = Math.max(1, Number(meta?.totalTicks || 0));
    const timeline = Array.isArray(meta?.measureTimeline) ? meta.measureTimeline : [];
    let currentMeasure = timeline[0] || null;
    for (let i = 1; i < timeline.length; i += 1) {
      if (Number(timeline[i].startTick || 0) <= startTick) {
        currentMeasure = timeline[i];
      } else {
        break;
      }
    }
    const measureStart = Math.max(0, Number(currentMeasure?.startTick || 0));
    const measureEnd = Math.max(measureStart + 1, Number(currentMeasure?.endTick || (measureStart + 1)));
    const measureSpan = Math.max(1, measureEnd - measureStart);
    const displayTotalTicks = Math.max(1, Number(meta?.displayTotalTicks || meta?.totalTicks || 1));
    const displayAbsoluteTick = startTick;
    const displayMeasureProgress = Math.max(
      0,
      Math.min(
        1,
        (startTick - measureStart) / measureSpan
      )
    );
    const preferredSystemBlockKey =
      String(host?._twXmlSelectedSystemBlockKey || "") ||
      getXmlSystemBlockKeyForSourceIndex(host, safeSourceIndex) ||
      "0";
    const resolvedSystemBlockKey = resolveXmlSystemBlockKeyForMeasure(
      host,
      preferredSystemBlockKey,
      Math.max(0, Number(currentMeasure?.measureIndex || 0)),
      container
    ) || preferredSystemBlockKey || "0";
    const anchorEl =
      host?.querySelector?.(`[data-tw-xml-playback-source-index="${safeSourceIndex}"]`) ||
      host?.querySelector?.(`[data-tw-xml-source-index="${safeSourceIndex}"]`) ||
      null;
    const anchored = positionXmlPlayheadAtElement(host, anchorEl, resolvedSystemBlockKey);
    if (!anchored) {
      positionXmlPlayheadAtProgress(
        host,
        resolvedSystemBlockKey,
        Math.max(0, Number(currentMeasure?.measureIndex || 0)),
        displayMeasureProgress
      );
    }
    updateXmlPlayheadIndicator(host, {
      active: !!opts?.active,
      absoluteTick: startTick,
      overallProgress: Math.max(0, Math.min(1, displayAbsoluteTick / displayTotalTicks)),
      measureProgress: displayMeasureProgress,
      measureIndex: Math.max(0, Number(currentMeasure?.measureIndex || 0))
    });
  }

  function getXmlMeasureStats(parsed) {
    const declaredNumbers = parsed?.measureNumbersByIndex;
    if (declaredNumbers && typeof declaredNumbers === "object" && Object.keys(declaredNumbers).length) {
      const orderedIndexes = Object.keys(declaredNumbers)
        .map((key) => Number(key))
        .filter((value) => Number.isFinite(value))
        .sort((a, b) => a - b);
      const positionByIndex = {};
      const numberByIndex = {};
      orderedIndexes.forEach((measureIndex, idx) => {
        const key = String(measureIndex);
        positionByIndex[key] = idx + 1;
        numberByIndex[key] = String(declaredNumbers[key] || `${measureIndex + 1}`);
      });
      return {
        total: orderedIndexes.length,
        positionByIndex,
        numberByIndex
      };
    }
    const slices = Array.isArray(parsed?.timeSlices) ? parsed.timeSlices : [];
    const byIndex = new Map();
    slices.forEach((slice) => {
      const measureIndex = Math.max(0, Number(slice?.measureIndex || 0));
      if (byIndex.has(measureIndex)) return;
      byIndex.set(measureIndex, String(slice?.measureNumber || `${measureIndex + 1}`));
    });
    if (!byIndex.size) {
      const events = Array.isArray(parsed?.events) ? parsed.events : [];
      events.forEach((ev) => {
        const measureIndex = Math.max(0, Number(ev?.measureIndex || 0));
        if (byIndex.has(measureIndex)) return;
        byIndex.set(measureIndex, String(ev?.measureNumber || `${measureIndex + 1}`));
      });
    }
    const orderedIndexes = Array.from(byIndex.keys()).sort((a, b) => a - b);
    const positionByIndex = {};
    const numberByIndex = {};
    orderedIndexes.forEach((measureIndex, idx) => {
      positionByIndex[String(measureIndex)] = idx + 1;
      numberByIndex[String(measureIndex)] = byIndex.get(measureIndex) || `${measureIndex + 1}`;
    });
    return {
      total: orderedIndexes.length,
      positionByIndex,
      numberByIndex
    };
  }

  function updateXmlPlaybackMeasureIndicator(container, opts = {}) {
    const host =
      container?._twXmlStateHost ||
      container ||
      document.getElementById("pdfTabXmlViewer");
    const stats = host?._twXmlMeasureStats || null;
    const measureIndex = Number(opts?.measureIndex);
    const roots = [
      host,
      document.getElementById("twPianoXmlStatusBar")
    ].filter((root, idx, arr) => root && arr.indexOf(root) === idx);
    roots.forEach((root) => {
      const systemBadge = root?.querySelector?.('[data-tw-xml-system-indicator="1"]');
      const measureBadge = root?.querySelector?.('[data-tw-xml-measure-indicator="1"]');
      const preferredSystemBlockKey =
        String(host?._twXmlSelectedSystemBlockKey || "") ||
        String(window.__twXmlPlayInternals?.xmlPlaybackState?.currentSystemBlockKey || "") ||
        "0";
      const resolvedSystemBlockKey = Number.isFinite(measureIndex)
        ? (
            resolveXmlSystemBlockKeyForMeasure(
              host,
              preferredSystemBlockKey,
              Math.max(0, measureIndex),
              container
            ) || preferredSystemBlockKey || "0"
          )
        : preferredSystemBlockKey;
      const systemIndexNum = Number(resolvedSystemBlockKey);
      const displaySystem = Number.isFinite(systemIndexNum)
        ? String(Math.max(1, Math.floor(systemIndexNum) + 1))
        : "-";
      if (systemBadge) {
        systemBadge.textContent = displaySystem;
        systemBadge.style.opacity = displaySystem === "-" ? "0.72" : "1";
      }

      if (!measureBadge) return;
      if (!Number.isFinite(measureIndex)) {
        const total = Math.max(0, Number(stats?.total || 0));
        measureBadge.textContent = total > 0 ? `- / ${total}` : "-";
        measureBadge.style.opacity = "0.72";
        return;
      }
      const key = String(Math.max(0, measureIndex));
      const total = Math.max(0, Number(stats?.total || 0));
      const measureNumber = String(stats?.numberByIndex?.[key] || `${Math.max(0, measureIndex) + 1}`);
      const position = Math.max(0, Number(stats?.positionByIndex?.[key] || 0));
      measureBadge.textContent =
        total > 0 && position > 0
          ? `${measureNumber} / ${total}`
          : measureNumber;
      measureBadge.style.opacity = "1";
    });
  }

  async function syncPdfXmlToggleButton(surrogate) {
    const btn = document.getElementById("pdfXmlToggleBtn");
    const pianoEndBtn = document.getElementById("twPianoXmlEndToggleBtn");
    const footerPdfBtn = document.querySelector('.footer-tab-btn[data-target="pdfTab"]');
    const footerPianoBtn = document.querySelector('.footer-tab-btn[data-target="pianoTab"]');
    const api = window.twMusicXml || {};
    const safeSurrogate = String(surrogate || window.currentSurrogate || "").trim();
    const xmlState = window._pdfXmlViewState || {};
    const xmlViewActiveForCurrent =
      !!xmlState.active &&
      !!safeSurrogate &&
      String(xmlState.surrogate || "").trim() === safeSurrogate;
    const prefersXmlMode = xmlViewActiveForCurrent;

    if (btn) {
      btn.disabled = false;
      btn.style.opacity = "1";
      btn.style.filter = "";
      btn.innerHTML = '<i data-lucide="play"></i>';
    }
    if (pianoEndBtn) {
      pianoEndBtn.textContent = prefersXmlMode ? "PDF" : "XML";
      pianoEndBtn.title = prefersXmlMode ? "Back to PDF" : "Open playable score";
      pianoEndBtn.setAttribute("aria-label", prefersXmlMode ? "Back to PDF" : "Open playable score");
    }
    let enabled = false;
    if (safeSurrogate) {
      try {
        const file = await api.getPrimaryMusicXmlFile?.(safeSurrogate);
        enabled = !!file?.url;
      } catch (err) {
        console.warn("MusicXML toggle sync failed:", err);
      }
    }

    if (footerPianoBtn) {
      footerPianoBtn.dataset.twXmlAvailable = enabled ? "1" : "0";
      footerPianoBtn.classList.toggle("is-disabled", !enabled);
      footerPianoBtn.setAttribute("aria-disabled", enabled ? "false" : "true");
    }

    const xmlActiveForCurrent =
      xmlViewActiveForCurrent &&
      String(window.currentActiveTab || "") === "pdfTab";
    if (footerPianoBtn) {
      footerPianoBtn.classList.toggle("active", xmlActiveForCurrent);
    }
    if (footerPdfBtn && String(window.currentActiveTab || "") === "pdfTab") {
      footerPdfBtn.classList.toggle("active", !xmlActiveForCurrent);
    }

    if (prefersXmlMode) {
      if (btn) {
        btn.title = "Back to PDF";
        btn.setAttribute("aria-label", "Back to PDF");
      }
      window.lucide?.createIcons?.();
      window.TWPianoDock?.refreshXmlMixer?.(safeSurrogate);
      return;
    }

    if (btn) {
      btn.title = "Open playable score";
      btn.setAttribute("aria-label", "Open playable score");
    }

    if (!safeSurrogate) {
      if (btn) {
        btn.disabled = true;
        btn.style.opacity = "0.45";
      }
      window.TWPianoDock?.refreshXmlMixer?.(safeSurrogate);
      return;
    }

    if (btn) {
      btn.disabled = !enabled;
      btn.style.opacity = enabled ? "1" : "0.45";
    }
    if (!enabled && btn) {
      btn.disabled = true;
      btn.style.opacity = "0.45";
    }
    window.lucide?.createIcons?.();
    window.TWPianoDock?.refreshXmlMixer?.(safeSurrogate);
  }

  async function syncPdfXmlPlayButton(surrogate) {
    const buttons = getXmlPlayButtons();
    if (!buttons.length) return;

    const playApi = window.twMusicXmlPlay || {};
    const api = window.twMusicXml || {};
    const safeSurrogate = String(surrogate || window.currentSurrogate || "").trim();
    const isPlaying = !!playApi.isXmlPlaybackActive?.(safeSurrogate);

    buttons.forEach((btn) => {
      btn.disabled = false;
      btn.style.opacity = "1";
    });

    if (isPlaying) {
      buttons.forEach((btn) => {
        setXmlPlayButtonVisual(btn, "pause");
        btn.title = "Stop MusicXML playback";
        btn.setAttribute("aria-label", "Stop MusicXML playback");
        if (btn.id === "twPianoXmlPlayBtn") btn.classList.add("is-playing");
      });
      return;
    }

    buttons.forEach((btn) => {
      setXmlPlayButtonVisual(btn, "play");
      btn.title = "Play MusicXML";
      btn.setAttribute("aria-label", "Play MusicXML");
      if (btn.id === "twPianoXmlPlayBtn") btn.classList.remove("is-playing");
    });

    if (!safeSurrogate) {
      buttons.forEach((btn) => {
        btn.disabled = true;
        btn.style.opacity = "0.45";
        if (btn.id === "twPianoXmlPlayBtn") btn.classList.remove("is-playing");
      });
      return;
    }

    try {
      const file = await api.getPrimaryMusicXmlFile?.(safeSurrogate);
      const enabled = !!file?.url;
      buttons.forEach((btn) => {
        btn.disabled = !enabled;
        btn.style.opacity = enabled ? "1" : "0.45";
        if (!enabled && btn.id === "twPianoXmlPlayBtn") btn.classList.remove("is-playing");
      });
    } catch (err) {
      console.warn("MusicXML play sync failed:", err);
      buttons.forEach((btn) => {
        btn.disabled = true;
        btn.style.opacity = "0.45";
        if (btn.id === "twPianoXmlPlayBtn") btn.classList.remove("is-playing");
      });
    }
  }

  function bindXmlGlobalShortcuts() {
    if (window.__twXmlSpacebarBound) return;
    window.__twXmlSpacebarBound = true;

    document.addEventListener("keydown", (event) => {
      if (event.defaultPrevented) return;
      if (event.repeat) return;
      if (!(event.code === "Space" || event.key === " ")) return;
      if (isEditableTarget(event.target)) return;

      const playApi = window.twMusicXmlPlay || {};
      const safeSurrogate = String(window.currentSurrogate || "").trim();
      if (!safeSurrogate) return;

      const xmlActive = !!playApi.isXmlPlaybackActive?.(safeSurrogate);
      const inPdfTab = String(window.currentActiveTab || "") === "pdfTab";
      const pianoOpen = !!window.TWPianoDock?.isOpen?.();
      if (!xmlActive && !inPdfTab && !pianoOpen) return;

      event.preventDefault();
      Promise.resolve(playApi.toggleXmlPlayback?.(safeSurrogate)).catch((err) => {
        console.warn("MusicXML spacebar toggle failed:", err);
      });
    });
  }

  window.twMusicXmlView = Object.assign(window.twMusicXmlView || {}, {
    applyMeasureRectVisual,
    isEditableTarget,
    getXmlPlayButtons,
    setXmlPlayButtonVisual,
    hideXmlPlayhead,
    getXmlMeasureNumber,
    getXmlRenderedMeasureFrameByNumber,
    getXmlPlayheadFrame,
    getXmlSystemFrame,
    getXmlSystemBlockKeyForMeasureKey,
    getXmlSystemBlockKeyForSourceIndex,
    getXmlPlayheadGroupKey,
    getXmlRepresentativeMeasureKey,
    scrollXmlPlayheadIntoView,
    resolveXmlSystemBlockKeyForMeasure,
    positionXmlPlayhead,
    positionXmlPlayheadAtProgress,
    buildXmlMeasureTimeline,
    buildOsmdMeasureFrames,
    buildXmlPlayheadMeta,
    getXmlMeasureKey,
    clearActiveMeasureHighlight,
    clearSelectedMeasureHighlight,
    clearSelectedXmlNote,
    setSelectedXmlNote,
    setSelectedMeasureHighlight,
    getRenderedNoteheadElements,
    getRenderedNoteheadBindings,
    createHtmlNoteHotspotLayer,
    applyXmlTrackFocusVisual,
    buildMeasureHighlights,
    positionXmlPlayheadAtElement,
    updateXmlPlayheadIndicator,
    updateXmlPlayheadFromSourceIndex,
    getXmlMeasureStats,
    updateXmlPlaybackMeasureIndicator,
    syncPdfXmlToggleButton,
    syncPdfXmlPlayButton
  });

  bindXmlGlobalShortcuts();
})();
