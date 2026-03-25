logStep("JSTextDrawing.js executed");

/* ============================================================
   TextWhisper — JSTextDrawing.js
   Inline freehand drawings anchored to text + draggable bubbles.

   Architecture (2025 Updated)
   ----------------------------
   • Persistent Storage:
       All drawings are stored inside the unified `window.textmarks`
       array alongside highlights and comments:

         window.textmarks = [
           {
             id,
             type: "drawing",
             image,        // dataURL PNG
             anchor,       // quote + context metadata
             offset,       // pixel offset relative to anchor <span>
             owner,
             annotator,
             surrogate
           },
           ...
         ];

   • Runtime Cache:
       window.DRAWINGS = {}  
         - Holds drawing metadata while the item is loaded.
         - Rebuilt on each load.
         - Used only for UI rendering + dragging.

   • Save Pipeline:
         saveCurrentDrawing()   → pushes drawing entry into textmarks[]
         saveTextMarks()        → sends entire textmarks[] to backend

       (The drawing module no longer writes to TEXTMARKS.*
        or any legacy structures.)

   • Load Pipeline:
         loadUserComments()     → reads backend JSON, populates
                                  window.textmarks + window.DRAWINGS,
                                  then calls renderDrawingFromTextmarks()

   • UI Responsibilities:
       - Record drawing strokes on overlay
       - Crop canvas to bounding box
       - Anchor drawing to a <span.draw-anchor> inside the text
       - Create draggable drawing bubble with PNG snapshot
       - Update offsets on drag and reflect changes into textmarks[]

   • Legacy structures removed:
       - window.TEXTMARKS.drawings
       - TM.drawings
       - COMMENTS / ANCHORS / BUBBLE_OFFSETS

   This module now operates fully on:
       window.DRAWINGS (runtime)
       window.textmarks (persistent)
   ============================================================ */


(function () {

  // ============================================================
  // 🌐 Ensure TEXTMARKS exists
  // ============================================================

    // Runtime cache for drawings (UI only, per-page)
    window.DRAWINGS = window.DRAWINGS || {};
    const DRAWINGS = window.DRAWINGS;


  let overlay = null;
  let ctx = null;
  let drawing = false;
  let AREA = null;

  let startX = 0, startY = 0;
  // First stroke position in overlay-local coordinates (kept for debugging if needed)
  window._drawStart = null;

  /* ============================================================
     INIT OVERLAY
     ============================================================ */
  function initOverlay() {
    AREA = document.getElementById("myTextarea");
    if (!AREA) return;

    if (overlay) {
      resizeOverlay();
      return;
    }

    const parent = AREA.parentElement || document.body;
    if (getComputedStyle(parent).position === "static") {
      parent.style.position = "relative";
    }

    overlay = document.createElement("canvas");
    overlay.id = "twDrawingOverlay";
    overlay.style.position = "absolute";
    overlay.style.pointerEvents = "none"; // enabled only in draw mode
    overlay.style.zIndex = 2500; // under bubbles
    overlay.style.top = "0";
    overlay.style.left = "0";
    overlay.style.touchAction = "none";
    overlay.style.background = "transparent";

    parent.appendChild(overlay);

    ctx = overlay.getContext("2d", { willReadFrequently: true });
    resizeOverlay();
    bindEvents();
  }

  function resizeOverlay() {
    if (!overlay || !AREA) return;

    // Size: match AREA's client box
    const rect = AREA.getBoundingClientRect();
    const parentRect = (AREA.parentElement || document.body).getBoundingClientRect();

    const width = rect.width;
    const height = rect.height;

    overlay.width = width;
    overlay.height = height;
    overlay.style.width = width + "px";
    overlay.style.height = height + "px";

    // Position overlay relative to its parent container
    overlay.style.left = (rect.left - parentRect.left) + "px";
    overlay.style.top  = (rect.top  - parentRect.top)  + "px";
  }

  window.addEventListener("resize", resizeOverlay);
  window.addEventListener("scroll", resizeOverlay);

  /* ============================================================
     DRAW ENGINE
     ============================================================ */
  function bindEvents() {
    if (!overlay) return;

    // MOUSE
    overlay.addEventListener("mousedown", e => {
      if (!window.EditMode) return;       
      if (!window.isDrawingMode) return;

        // Snapshot drawing canvas BEFORE stroke
        const before = ctx.getImageData(0, 0, overlay.width, overlay.height);
        
        pushUndo({
          type: "drawing-stroke",
          undo: () => ctx.putImageData(before, 0, 0)
        });
        
      drawing = true;
      
      const p = localPos(e);

      startX = p.x;
      startY = p.y;

      if (!window._drawStart) {
        window._drawStart = { x: p.x, y: p.y };
      }

        ctx.strokeStyle = window.currentDrawColor || "#f4511e";
        ctx.lineWidth   = window.currentDrawWidth || 2;
        ctx.lineCap     = "round";
        ctx.lineJoin    = "round";
    
    
    });

    window.addEventListener("mousemove", e => {
      if (!drawing || !window.isDrawingMode) return;
      const p = localPos(e);

        ctx.strokeStyle = window.currentDrawColor;
        ctx.lineWidth   = window.currentDrawWidth;
        
        ctx.beginPath();
        ctx.moveTo(startX, startY);
        ctx.lineTo(p.x, p.y);
        ctx.stroke();
    

      startX = p.x;
      startY = p.y;
    });

    window.addEventListener("mouseup", () => {
      drawing = false;
    });

    // TOUCH
    overlay.addEventListener("touchstart", e => {
      if (!window.EditMode) return;     
      if (!window.isDrawingMode) return;
      const t = e.touches[0];
      const p = localPos(t);

        // Snapshot drawing canvas BEFORE stroke
        const before = ctx.getImageData(0, 0, overlay.width, overlay.height);
        
        pushUndo({
          type: "drawing-stroke",
          undo: () => ctx.putImageData(before, 0, 0)
        });

      drawing = true;
      startX = p.x;
      startY = p.y;

      if (!window._drawStart) {
        window._drawStart = { x: p.x, y: p.y };
      }

        ctx.strokeStyle = window.currentDrawColor || "#f4511e";
        ctx.lineWidth   = window.currentDrawWidth || 2;
        ctx.lineCap     = "round";
        ctx.lineJoin    = "round";
    
    }, { passive: true });

    overlay.addEventListener("touchmove", e => {
      if (!window.EditMode) return;     
      if (!drawing || !window.isDrawingMode) return;
      const t = e.touches[0];
      const p = localPos(t);

        ctx.strokeStyle = window.currentDrawColor;
        ctx.lineWidth   = window.currentDrawWidth;
        
        ctx.beginPath();
        ctx.moveTo(startX, startY);
        ctx.lineTo(p.x, p.y);
        ctx.stroke();
    
    

      startX = p.x;
      startY = p.y;
      e.preventDefault();
    }, { passive: false });

    overlay.addEventListener("touchend", () => {
      drawing = false;
    });
  }

  function localPos(e) {
    const r = overlay.getBoundingClientRect();
    const clientX = e.clientX ?? (e.touches && e.touches[0]?.clientX) ?? 0;
    const clientY = e.clientY ?? (e.touches && e.touches[0]?.clientY) ?? 0;
    return {
      x: clientX - r.left,
      y: clientY - r.top
    };
  }


/* ============================================================
   SAVE DRAWING (anchor → inline span → TEXTMARKS)
   ============================================================ */

    window.saveCurrentDrawing = function () {
      if (!overlay || !ctx) return;
    
      // Nothing drawn?
      if (isBlank(overlay)) return;
    
      const oldPE = overlay.style.pointerEvents;
      overlay.style.pointerEvents = "none";
    
      const AREA = document.getElementById("myTextarea");
      if (!AREA) {
        overlay.style.pointerEvents = oldPE;
        return;
      }
    
      // 1) Extract drawn bounds
      const bbox = getDrawingBounds(overlay, ctx);
      if (!bbox) {
        ctx.clearRect(0, 0, overlay.width, overlay.height);
        window._drawStart = null;
        overlay.style.pointerEvents = oldPE;
        return;
      }
    
      // 2) Crop + convert to PNG
      const cropped = cropCanvas(overlay, bbox);
      const dataUrl = cropped.toDataURL("image/png");
      const id = "d" + Math.random().toString(36).slice(2, 8);
    
      // 3) Determine caret position for anchor
      const overlayRect = overlay.getBoundingClientRect();
      const anchorClientX = overlayRect.left + bbox.x + bbox.width / 2;
      const anchorClientY = overlayRect.top  + bbox.y + bbox.height / 2;
      
      let range = caretRangeInTextArea(anchorClientX, anchorClientY, AREA);
      if (!range) {
        range = document.createRange();
        range.selectNodeContents(AREA);
        range.collapse(false);
      }      
    
      // 4) Insert anchor span
      const anchorSpan = document.createElement("span");
      anchorSpan.className = "draw-anchor";
      anchorSpan.dataset.id = id;
      anchorSpan.textContent = "\u200B";
      anchorSpan.style.position = "relative";
      anchorSpan.style.display = "inline-block";
    
      range.insertNode(anchorSpan);
    
      // 5) Compute local bubble offset
      const spanRect = anchorSpan.getBoundingClientRect();
    
      const bubbleLeftAbs = overlayRect.left + bbox.x + window.scrollX;
      const bubbleTopAbs  = overlayRect.top  + bbox.y + window.scrollY;
    
      const bubbleLeftLocal = bubbleLeftAbs - (spanRect.left + window.scrollX) - 3;
      const bubbleTopLocal  = bubbleTopAbs  - (spanRect.top  + window.scrollY) - 2;
  
    
      // 6) Build anchor context
      const anchor = buildAnchorContext(anchorSpan);
    
      // 7) Create entry
      const entry = {
        id,
        type: "drawing",
        owner: window.currentItemOwner,
        annotator: window.SESSION_USERNAME,
        surrogate: window.currentSurrogate,
        image: dataUrl,
        anchor,
        offset: {
          left: bubbleLeftLocal,
          top: bubbleTopLocal
        }
      };
    
      // 8) Runtime DRAWINGS (UI only)
      window.DRAWINGS = window.DRAWINGS || {};
      window.DRAWINGS[id] = entry;
    
      // 9) Create drawing bubble UI
    //   const bubble = createBubble(anchorSpan, cropped, id);
      window.renderDrawingFromTextmarks(id, window.DRAWINGS[id], AREA);
      
      
    //   makeDraggable(bubble, id);
    
      // 10) Update textmarks (persistent JSON)
      window.textmarks = window.textmarks || [];
      window.textmarks = window.textmarks.filter(x => x.id !== id); // remove old entry if exists
      window.textmarks.push(entry);
      
    // TM.drawings[id] = entry;

        
      // Cleanup
      ctx.clearRect(0, 0, overlay.width, overlay.height);
      window._drawStart = null;
      overlay.style.pointerEvents = oldPE;
    
      console.log("🎨 Drawing updated textmarks:", entry);
    };
    
        
    /**
     * caretRangeInTextArea()
     *
     * Anchors drawing strokes ONLY to text inside #myTextarea.
     * Works even when bubbles (z-index above canvas) sit under the pointer.
     *
     * Logic:
     * 1) Try native caretRangeFromPointCompat at the CANVAS POINT (not bubble point)
     * 2) If that caret is NOT inside the text area → fallback:
     *      • Find nearest text line vertically
     *      • Find nearest character horizontally (binary search)
     * 3) If no text found → anchor at end of text area
     */
    function caretRangeInTextArea(canvasClientX, canvasClientY, area) {
      if (!area) area = document.getElementById("myTextarea");
      if (!area) return null;
    
      // 1️⃣ First, try the native caret from the CANVAS location
      let base = caretRangeFromPointCompat(canvasClientX, canvasClientY);
      if (base && area.contains(base.startContainer)) {
        return base; // Perfect hit inside text
      }
    
      // 2️⃣ Fallback: find nearest TEXT NODE in the textarea
      const walker = document.createTreeWalker(
        area,
        NodeFilter.SHOW_TEXT,
        {
          acceptNode(node) {
            return node.nodeValue && node.nodeValue.trim()
              ? NodeFilter.FILTER_ACCEPT
              : NodeFilter.FILTER_REJECT;
          }
        }
      );
    
      let bestNode = null;
      let bestOffset = 0;
      let bestDistY = Infinity;
    
      while (walker.nextNode()) {
        const node = walker.currentNode;
    
        const full = document.createRange();
        full.selectNodeContents(node);
    
        const rects = full.getClientRects();
        if (!rects.length) continue;
    
        for (const rect of rects) {
          const cy = rect.top + rect.height / 2;
          const distY = Math.abs(cy - canvasClientY);
    
          if (distY < bestDistY) {
            bestDistY = distY;
            bestNode = node;
    
            // ---- Horizontal character offset (binary search) ----
            let lo = 0;
            let hi = node.nodeValue.length;
    
            while (lo < hi) {
              const mid = (lo + hi) >> 1;
              const test = document.createRange();
              test.setStart(node, mid);
              test.setEnd(node, mid + 1);
    
              const r = test.getBoundingClientRect();
              if (!r.width) break; // invisible character
    
              const midX = r.left + r.width / 2;
    
              if (midX < canvasClientX) lo = mid + 1;
              else hi = mid;
            }
    
            bestOffset = lo;
          }
        }
      }
    
      // 3️⃣ Found nearest text node
      const result = document.createRange();
    
      if (bestNode) {
        const max = bestNode.nodeValue.length;
        if (bestOffset < 0) bestOffset = 0;
        if (bestOffset > max) bestOffset = max;
    
        result.setStart(bestNode, bestOffset);
        result.collapse(true);
        return result;
      }
    
      // 4️⃣ No text at all → anchor at end of AREA
      result.selectNodeContents(area);
      result.collapse(false);
      return result;
    }


    


  /* ============================================================
     DETECT / CROP DRAWING BOUNDS  (like the test file)
     ============================================================ */
  function isBlank(c) {
    if (!c.width || !c.height) return true;
    const pix = c.getContext("2d").getImageData(0, 0, c.width, c.height).data;
    for (let i = 3; i < pix.length; i += 4) {
      if (pix[i] !== 0) return false;
    }
    return true;
  }

  function getDrawingBounds(canvas, ctx) {
    const w = canvas.width;
    const h = canvas.height;
    if (!w || !h) return null;

    const imgData = ctx.getImageData(0, 0, w, h);
    const data = imgData.data;

    let minX = w, minY = h, maxX = -1, maxY = -1;

    for (let y = 0; y < h; y++) {
      for (let x = 0; x < w; x++) {
        const idx = (y * w + x) * 4;
        const alpha = data[idx + 3];
        if (alpha !== 0) {
          if (x < minX) minX = x;
          if (y < minY) minY = y;
          if (x > maxX) maxX = x;
          if (y > maxY) maxY = y;
        }
      }
    }

    if (maxX === -1) return null;

    return {
      x: minX,
      y: minY,
      width: maxX - minX + 1,
      height: maxY - minY + 1
    };
  }

  function cropCanvas(srcCanvas, bbox) {
    const off = document.createElement("canvas");
    off.width = bbox.width;
    off.height = bbox.height;
    const offCtx = off.getContext("2d", { alpha: true });

    offCtx.drawImage(
      srcCanvas,
      bbox.x, bbox.y, bbox.width, bbox.height,
      0, 0, bbox.width, bbox.height
    );

    return off;
  }

  /* ============================================================
     CARET HELPERS (same idea as test demo)
     ============================================================ */
  function caretRangeFromPointCompat(x, y) {
    if (document.caretRangeFromPoint) {
      return document.caretRangeFromPoint(x, y);
    }
    if (document.caretPositionFromPoint) {
      const pos = document.caretPositionFromPoint(x, y);
      if (!pos) return null;
      const r = document.createRange();
      r.setStart(pos.offsetNode, pos.offset);
      r.collapse(true);
      return r;
    }
    return null;
  }

  /* ============================================================
     ANCHOR CONTEXT FOR DRAWINGS
     ============================================================ */
  function buildAnchorContext(span) {
    const area = document.getElementById("myTextarea");
    const quote = span.textContent || "\u200B";

    let offset = -1;
    let context_before = "";
    let context_after = "";

    if (area && span) {
      offset = findTextOffset(span);

      try {
        const clone = area.cloneNode(true);
        clone.querySelectorAll(".comment-hint").forEach(el => el.remove());
        clone.querySelectorAll("canvas").forEach(el => el.remove());
        // Keep draw anchors in text for counting offset
        const plainText = clone.textContent || "";

        if (offset >= 0 && offset <= plainText.length) {
          const beforeStart = Math.max(0, offset - 80);
          const afterStart  = offset + quote.length;

          context_before = plainText.slice(beforeStart, offset);
          context_after  = plainText.slice(afterStart, afterStart + 80);
        }
      } catch (err) {
        console.warn("buildAnchorContext failed:", err);
      }
    }

    return {
      quote,
      offset,
      context_before,
      context_after
    };
  }

  function findTextOffset(span) {
    const area = document.getElementById("myTextarea");
    if (!area) return -1;

    const walker = document.createTreeWalker(area, NodeFilter.SHOW_TEXT);
    let pos = 0;

    while (walker.nextNode()) {
      const node = walker.currentNode;
      if (node === span.firstChild) return pos;
      pos += node.textContent.length;
    }
    return pos;
  }

  /* ============================================================
     BUBBLE (relative to anchor span, using stored offsets)
     ============================================================ */


    function makeDraggable(bubble, id) {
      let drag = false, sx = 0, sy = 0, ox = 0, oy = 0;
    
      const handle = bubble.querySelector(".drawing-drag-handle");
      if (!handle) return;
    
      // --- NEW: store old position before drag starts ---
      let undoOldLeft = 0;
      let undoOldTop = 0;
    
      const start = (x, y) => {
        drag = true;
    
        const r = bubble.getBoundingClientRect();
        const pr = bubble.offsetParent.getBoundingClientRect();
    
        ox = r.left - pr.left;
        oy = r.top  - pr.top;
    
        // NEW: capture undo baseline BEFORE moving
        undoOldLeft = ox;
        undoOldTop  = oy;
    
        sx = x;
        sy = y;
      };
    
      const move = (x, y) => {
        if (!drag) return;
    
        const nx = ox + (x - sx);
        const ny = oy + (y - sy);
    
        bubble.style.left = nx + "px";
        bubble.style.top  = ny + "px";
    
        // runtime DRAWINGS cache
        if (DRAWINGS[id]) {
          DRAWINGS[id].offset = { left: nx, top: ny };
        }
    
        // persistent textmarks
        if (Array.isArray(window.textmarks)) {
          const entry = window.textmarks.find(x => x.id === id);
          if (entry) {
            entry.offset = { left: nx, top: ny };
          }
        }
      };
    
      const stop = () => {
        if (!drag) return;
        drag = false;
    
        // --- NEW: register undo only when position actually changed ---
        const finalLeft = parseFloat(bubble.style.left) || 0;
        const finalTop  = parseFloat(bubble.style.top)  || 0;
    
        if (finalLeft !== undoOldLeft || finalTop !== undoOldTop) {
          window.pushUndo({
            type: "drawing-move",
            undo: () => {
              bubble.style.left = undoOldLeft + "px";
              bubble.style.top  = undoOldTop + "px";
    
              // restore DRAWINGS entry
              if (DRAWINGS[id]) {
                DRAWINGS[id].offset = { left: undoOldLeft, top: undoOldTop };
              }
    
              // restore textmarks entry
              if (Array.isArray(window.textmarks)) {
                const entry = window.textmarks.find(x => x.id === id);
                if (entry) {
                  entry.offset = { left: undoOldLeft, top: undoOldTop };
                }
              }
            }
          });
        }
      };
    
      // Mouse
      handle.addEventListener("mousedown", e => {
        e.preventDefault();
        start(e.clientX, e.clientY);
      });
      document.addEventListener("mouseup", stop);
      document.addEventListener("mousemove", e => move(e.clientX, e.clientY));
    
      // Touch
      handle.addEventListener("touchstart", e => {
        const t = e.touches[0];
        start(t.clientX, t.clientY);
      }, { passive: true });
    
      handle.addEventListener("touchmove", e => {
        const t = e.touches[0];
        move(t.clientX, t.clientY);
      }, { passive: true });
    
      handle.addEventListener("touchend", stop);
    }
  

  /* ============================================================
     RESTORE DRAWING (used by comments loader)
     ============================================================ */

    window.renderDrawingFromTextmarks = function (id, data, area) {
      if (!area || !data) return;
    
      const offset = data.offset || { left: 0, top: 0 };
      const anchor = data.anchor || {};
      let span = null;
    
      // Use stored text offset
      if (typeof anchor.offset === "number") {
        span = insertSpanAtTextOffset(area, anchor.offset, id);
      } else {
        // Legacy fallback: find existing <span> or create a new one
        span = area.querySelector(`span.draw-anchor[data-id='${id}']`);
        if (!span) {
          span = document.createElement("span");
          span.className = "draw-anchor";
          span.dataset.id = id;
          span.textContent = "\u200B";
          area.insertBefore(span, area.firstChild);
        }
      }
    
      span.style.position = "relative";
      span.style.display = "inline-block";
    
      const bubble = document.createElement("div");
      bubble.className = "drawing-bubble";
      bubble.style.position = "absolute";
      bubble.style.left = offset.left + "px";
      bubble.style.top = offset.top + "px";
      bubble.style.background = "transparent";
      bubble.style.border = "none";
      bubble.style.zIndex = 3000;
    
      const img = new Image();
      img.onload = () => {
        const cnv = document.createElement("canvas");
        cnv.width = img.width;
        cnv.height = img.height;
        cnv.getContext("2d").drawImage(img, 0, 0);
        bubble.appendChild(cnv);
    
        // Drag handle
        const handle = document.createElement("div");
        handle.className = "drawing-drag-handle";
        handle.style.position = "absolute";
        handle.style.top = "-14px";
        handle.style.right = "0";
        handle.style.width = "14px";
        handle.style.height = "14px";
        handle.style.cursor = "grab";
        handle.style.background = "transparent";
        handle.style.borderRadius = "4px";
    
        bubble.appendChild(handle);
    
        span.appendChild(bubble);
        makeDraggable(bubble, id);
        bindDeleteContextForDrawing(bubble, id);
      };
    
      img.src = data.dataUrl || data.image;
    };
  

  /* ============================================================
     PUBLIC: ENABLE DRAW MODE
     ============================================================ */
  window.enableDrawingMode = function (on) {
    window.isDrawingMode = !!on;
    if (on) {
      if (!overlay) initOverlay();
      if (overlay) overlay.style.pointerEvents = "auto";
    } else {
      if (overlay) overlay.style.pointerEvents = "none";
    }
  };

  // Insert a draw-anchor span at a given plain-text offset (used by restore)
  function insertSpanAtTextOffset(area, offset, id) {
    const walker = document.createTreeWalker(area, NodeFilter.SHOW_TEXT);
    let pos = 0;

    while (walker.nextNode()) {
      const node = walker.currentNode;
      const len = node.textContent.length;

      if (offset <= pos + len) {
        const localOffset = offset - pos;

        const range = document.createRange();
        range.setStart(node, localOffset);
        range.collapse(true);

        const span = document.createElement("span");
        span.className = "draw-anchor";
        span.dataset.id = id;
        span.textContent = "\u200B";
        span.style.position = "relative";
        span.style.display = "inline-block";

        range.insertNode(span);
        return span;
      }

      pos += len;
    }

    // Fallback: append at end
    const span = document.createElement("span");
    span.className = "draw-anchor";
    span.dataset.id = id;
    span.textContent = "\u200B";
    span.style.position = "relative";
    span.style.display = "inline-block";
    area.appendChild(span);
    return span;
  }

    // ============================================================
    // 🗑 RIGHT-CLICK / LONG-PRESS DELETE FOR DRAWINGS
    // ============================================================
    function bindDeleteContextForDrawing(bubble, id) {
    
      bubble.addEventListener("contextmenu", e => {
        e.preventDefault();
        e.stopPropagation();
    
        const menu = document.createElement("div");
        menu.className = "drawing-delete-menu";
        menu.textContent = "🗑️ Delete drawing?";
        Object.assign(menu.style, {
          position: "fixed",
          top: `${e.clientY}px`,
          left: `${e.clientX}px`,
          background: "#fff8dc",
          border: "1px solid #ccc",
          borderRadius: "6px",
          padding: "6px 10px",
          boxShadow: "0 2px 6px rgba(0,0,0,.15)",
          cursor: "pointer",
          zIndex: 99999,
          fontSize: "14px",
          fontFamily: "system-ui,sans-serif",
          userSelect: "none"
        });
    
        document.body.appendChild(menu);
    
        const cleanup = () => menu.remove();
    
        menu.addEventListener("click", () => {
          window.deleteSingleDrawing(id);
          cleanup();
        });
    
        document.addEventListener("click", ev2 => {
          if (!menu.contains(ev2.target)) cleanup();
        }, { once: true });
    
        setTimeout(cleanup, 3000);
      });
    
      // --- LONG PRESS DELETE (touch) ---
      let pressTimer = null;
    
      bubble.addEventListener("touchstart", () => {
        pressTimer = setTimeout(() => {
          const rect = bubble.getBoundingClientRect();
          const evtX = rect.left + rect.width / 2;
          const evtY = rect.top;
    
          const fakeEvent = { clientX: evtX, clientY: evtY, preventDefault(){} };
          bubble.dispatchEvent(new CustomEvent("contextmenu", { detail: fakeEvent }));
        }, 650);  // long press = 650ms
      }, { passive: true });
    
      bubble.addEventListener("touchend", () => {
        clearTimeout(pressTimer);
      });
    }



    // Prevent scrolling while dragging drawing bubbles
    function disableScroll() {
      document.body.style.overflow = "hidden";
      document.documentElement.style.overflow = "hidden";
    }
    
    function enableScroll() {
      document.body.style.overflow = "";
      document.documentElement.style.overflow = "";
    }
    
    // Attach global drag behavior
    document.addEventListener("mousedown", e => {
      if (e.target.closest(".drawing-drag-handle")) {
        disableScroll();
      }
    });
    
    document.addEventListener("mouseup", () => {
      enableScroll();
    });
    
    // MOBILE TOUCH
    document.addEventListener("touchstart", e => {
      if (e.target.closest(".drawing-drag-handle")) {
        disableScroll();
      }
    }, { passive: false });
    
    document.addEventListener("touchend", enableScroll, { passive: false });
    document.addEventListener("touchcancel", enableScroll, { passive: false });




})(); 





// ==========================================





window.deleteSingleDrawing = function (id) {
  const area = document.getElementById("myTextarea");
  if (!area) return;

  // Remove anchor span
  const span = area.querySelector(`span.draw-anchor[data-id='${id}']`);
  if (span) span.remove();

  // Remove bubble (old and new formats supported)
  const bubble = document.querySelector(`.drawing-bubble[data-anchor-id='${id}']`)
              || document.querySelector(`.drawing-bubble[data-id='${id}']`);

  if (bubble) bubble.remove();

  // --- Remove from runtime DRAWINGS ---
  if (window.DRAWINGS && window.DRAWINGS[id]) {
    delete window.DRAWINGS[id];
  }

  // --- Remove from textmarks array ---
  if (Array.isArray(window.textmarks)) {
    window.textmarks = window.textmarks.filter(entry => entry.id !== id);
  }

  // Persist removal
  window.debouncedSaveTextMarks?.("deleteDrawing");

  console.log("🗑️ Deleted drawing:", id);
};


