<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Anchored Drawing — Stable Test</title>

<style>
  body {
    font-family: system-ui, sans-serif;
    background: #f5f5f5;
    padding: 20px;
  }

  #toolbar {
    margin-bottom: 12px;
  }
  #toolbar button {
    padding: 8px 14px;
    margin-right: 6px;
    font-size: 14px;
    cursor: pointer;
  }

  /* Layout wrapper */
  #wrap {
    position: relative;
    width: 700px;
    margin: 0 auto;
  }

  /* Scroller / border / padding lives here */
  #textScroller {
    padding: 12px;
    background: white;
    border: 1px solid #ccc;
  }

  /* Pure content area – NO padding/border/position here */
  #textBox {
    min-height: 260px;
    line-height: 1.6;
    font-size: 18px;
    outline: none;
  }

  /* Overlay canvas aligned with textBox content (offset by padding) */
  #overlayCanvas {
    position: absolute;
    top: 12px;   /* match textScroller padding-top */
    left: 12px;  /* match textScroller padding-left */
    pointer-events: none; /* enabled in draw mode */
    background: transparent;
    z-index: 2;
  }

  .draw-anchor {
    display: inline-block !important;
    width: 0 !important;
    height: 0 !important;
    pointer-events: none !important;
  }

  /* Transparent bubble – only canvas + tiny drag handle */
  .drawing-bubble {
    position: absolute;
    padding: 0;
    margin: 0;
    border: none;
    background: transparent;
    z-index: 999;
  }

  .drawing-bubble canvas {
    display: block;
    background: transparent;
  }

  .drag-handle {
    position: absolute;
    top: -14px;
    right: 0;
    font-size: 11px;
    padding: 1px 4px;
    border-radius: 4px;
    background: rgba(255,255,255,0.7);
    border: 1px solid rgba(0,0,0,0.2);
    cursor: move;
    user-select: none;
  }
</style>
</head>
<body>

<h2>Anchored Drawing — “Draw → Save → Bubble” Demo</h2>
<p>
Draw on the overlay, then press <strong>“💾 Save drawing”</strong> to create a cropped,
anchored bubble that follows the text when you edit it.
</p>

<div id="toolbar">
  <button id="drawToggle">✏️ Draw mode</button>
  <button id="clearOverlay">🧽 Clear overlay</button>
  <button id="saveDrawing">💾 Save drawing as anchored bubble</button>
</div>

<div id="wrap">
  <div id="textScroller">
    <div id="textBox" contenteditable="true">
      Núna ertu hjá mér, Nína..<br>
      Strýkur mér um vangann, Nína.<br>
      Ó, haltu' í höndina á mér, Nína.<br>
      Því þú veist að ég mun aldrei aftur.<br>
      Ég mun aldrei, aldrei aftur.<br>
      Aldrei aftur eiga stund með þér.
    </div>
  </div>

  <canvas id="overlayCanvas"></canvas>
</div>

<script>
const wrap = document.getElementById("wrap");
const textBox = document.getElementById("textBox");
const textScroller = document.getElementById("textScroller");
const overlayCanvas = document.getElementById("overlayCanvas");
const octx = overlayCanvas.getContext("2d", { alpha: true });

let drawMode = false;
let isDrawing = false;
let anchorCount = 0;

/* ========= Resize overlay to match textBox ========= */
function resizeOverlay() {
  const rect = textBox.getBoundingClientRect();
  overlayCanvas.width = rect.width;
  overlayCanvas.height = rect.height;
}
resizeOverlay();

if (window.ResizeObserver) {
  new ResizeObserver(resizeOverlay).observe(textBox);
} else {
  window.addEventListener("resize", resizeOverlay);
}

/* ========= Draw mode toggle ========= */
const drawToggleBtn = document.getElementById("drawToggle");
drawToggleBtn.addEventListener("click", () => {
  drawMode = !drawMode;
  overlayCanvas.style.pointerEvents = drawMode ? "auto" : "none";
  drawToggleBtn.textContent = drawMode ? "✔ Drawing ON" : "✏️ Draw mode";
});

/* ========= Overlay drawing ========= */
function getOverlayPos(e) {
  const rect = overlayCanvas.getBoundingClientRect();
  const clientX = e.touches ? e.touches[0].clientX : e.clientX;
  const clientY = e.touches ? e.touches[0].clientY : e.clientY;
  return {
    x: clientX - rect.left,
    y: clientY - rect.top
  };
}

overlayCanvas.addEventListener("mousedown", e => {
  if (!drawMode) return;
  isDrawing = true;
  const { x, y } = getOverlayPos(e);
  octx.beginPath();
  octx.moveTo(x, y);
});

overlayCanvas.addEventListener("mousemove", e => {
  if (!isDrawing) return;
  const { x, y } = getOverlayPos(e);
  octx.lineTo(x, y);
  octx.strokeStyle = "#000";
  octx.lineWidth = 2;
  octx.lineCap = "round";
  octx.stroke();
});

document.addEventListener("mouseup", () => {
  isDrawing = false;
});

// touch
overlayCanvas.addEventListener("touchstart", e => {
  if (!drawMode) return;
  isDrawing = true;
  const { x, y } = getOverlayPos(e);
  octx.beginPath();
  octx.moveTo(x, y);
}, { passive: true });

overlayCanvas.addEventListener("touchmove", e => {
  if (!isDrawing) return;
  const { x, y } = getOverlayPos(e);
  octx.lineTo(x, y);
  octx.strokeStyle = "#000";
  octx.lineWidth = 2;
  octx.lineCap = "round";
  octx.stroke();
  e.preventDefault();
}, { passive: false });

overlayCanvas.addEventListener("touchend", () => {
  isDrawing = false;
});

/* ========= Clear overlay ========= */
document.getElementById("clearOverlay").addEventListener("click", () => {
  octx.clearRect(0, 0, overlayCanvas.width, overlayCanvas.height);
});

/* ========= Save drawing as anchored bubble ========= */
document.getElementById("saveDrawing").addEventListener("click", () => {

  // 🔥 FIX: prevent canvas from blocking caretRangeFromPoint
  const restoreDrawMode = drawMode;
  overlayCanvas.style.pointerEvents = "none";

  const bbox = getDrawingBounds(overlayCanvas, octx);
  if (!bbox) {
    alert("No drawing found to save.");
    if (restoreDrawMode) overlayCanvas.style.pointerEvents = "auto";
    return;
  }

  const croppedCanvas = cropCanvas(overlayCanvas, bbox);
  const overlayRect = overlayCanvas.getBoundingClientRect();

  const bubbleLeft = overlayRect.left + bbox.x + window.scrollX;
  const bubbleTop  = overlayRect.top  + bbox.y + window.scrollY;

  const anchorClientX = overlayRect.left + bbox.x + bbox.width / 2;
  const anchorClientY = overlayRect.top  + bbox.y + bbox.height / 2;

  // NOW caretRangeFromPoint hits TEXT CORRECTLY 🎯
  const range =
    caretRangeFromPointCompat(anchorClientX, anchorClientY) ||
    getFallbackRange();

  const anchorId = "d" + (++anchorCount);
  const anchorSpan = document.createElement("span");
  anchorSpan.className = "draw-anchor";
  anchorSpan.dataset.id = anchorId;
  range.insertNode(anchorSpan);

  const bubble = createDrawingBubble(anchorSpan, croppedCanvas);

  setupAnchorTracking(anchorSpan, bubble, bubbleLeft, bubbleTop);

  // Restore drawing mode pointer events
  if (restoreDrawMode) {
    overlayCanvas.style.pointerEvents = "auto";
  }

  octx.clearRect(0, 0, overlayCanvas.width, overlayCanvas.height);
});


/* ========= Bounding box detection ========= */
function getDrawingBounds(canvas, ctx) {
  const w = canvas.width;
  const h = canvas.height;
  const data = ctx.getImageData(0, 0, w, h).data;

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

/* ========= Caret helpers ========= */
function caretRangeFromPointCompat(x, y) {
  if (document.caretRangeFromPoint) {
    return document.caretRangeFromPoint(x, y);
  }
  const pos = document.caretPositionFromPoint?.(x, y);
  if (pos) {
    const r = document.createRange();
    r.setStart(pos.offsetNode, pos.offset);
    r.collapse(true);
    return r;
  }
  return null;
}

function getFallbackRange() {
  const r = document.createRange();
  r.selectNodeContents(textBox);
  r.collapse(false);
  return r;
}

/* ========= Bubble creation ========= */
function createDrawingBubble(anchorSpan, imageCanvas) {
  const bubble = document.createElement("div");
  bubble.className = "drawing-bubble";
  bubble.dataset.anchorId = anchorSpan.dataset.id;

  const innerCanvas = document.createElement("canvas");
  innerCanvas.width = imageCanvas.width;
  innerCanvas.height = imageCanvas.height;

  const ictx = innerCanvas.getContext("2d", { alpha: true });
  ictx.drawImage(imageCanvas, 0, 0);

  bubble.appendChild(innerCanvas);

  const handle = document.createElement("div");
  handle.className = "drag-handle";
  handle.textContent = "⠿";
  bubble.appendChild(handle);

  document.body.appendChild(bubble);

  makeDraggable(bubble, handle);
  return bubble;
}

/* ========= Anchor tracking with preserved offset ========= */
function setupAnchorTracking(anchorSpan, bubble, desiredLeft, desiredTop) {
  let dx = 0, dy = 0;
  let initialized = false;

  function initOffset() {
    const a = anchorSpan.getBoundingClientRect();
    const anchorLeft = a.left + window.scrollX;
    const anchorTop  = a.top  + window.scrollY;

    dx = desiredLeft - anchorLeft;
    dy = desiredTop  - anchorTop;
    initialized = true;

    apply();
  }

  function apply() {
    if (!initialized) return;
    const rect = anchorSpan.getBoundingClientRect();
    const anchorLeft = rect.left + window.scrollX;
    const anchorTop  = rect.top  + window.scrollY;

    bubble.style.left = (anchorLeft + dx) + "px";
    bubble.style.top  = (anchorTop  + dy) + "px";
  }

  // Wait for DOM reflow before computing initial offset
  requestAnimationFrame(() => {
    requestAnimationFrame(initOffset);
  });

  // Text input should move anchor
  textBox.addEventListener("input", () => {
    requestAnimationFrame(apply);
  });

  // Scroll / resize
  window.addEventListener("scroll", apply, { passive: true });
  window.addEventListener("resize", apply);

  // Layout changes
  if (window.ResizeObserver) {
    new ResizeObserver(apply).observe(textBox);
    new ResizeObserver(apply).observe(textScroller);
  }

  // DOM mutations in the scroller (adds/removes lines, etc.)
  const mo = new MutationObserver(() => {
    requestAnimationFrame(apply);
  });
  mo.observe(textScroller, {
    childList: true,
    subtree: true,
    characterData: true
  });
}

/* ========= Draggable bubble ========= */
function makeDraggable(el, handle) {
  let dragging = false;
  let startX = 0, startY = 0;
  let startLeft = 0, startTop = 0;

  handle.addEventListener("mousedown", e => {
    dragging = true;
    const r = el.getBoundingClientRect();
    startX = e.clientX;
    startY = e.clientY;
    startLeft = r.left + window.scrollX;
    startTop  = r.top  + window.scrollY;
    e.preventDefault();
  });

  document.addEventListener("mousemove", e => {
    if (!dragging) return;
    const dx = e.clientX - startX;
    const dy = e.clientY - startY;
    el.style.left = (startLeft + dx) + "px";
    el.style.top  = (startTop  + dy) + "px";
  });

  document.addEventListener("mouseup", () => {
    dragging = false;
  });
}
</script>

</body>
</html>
