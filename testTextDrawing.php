<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Anchored Drawing Test</title>

<style>
  body {
    font-family: system-ui;
    padding: 20px;
    background: #fafafa;
  }

  #toolbar {
    margin-bottom: 10px;
  }

  #textBox {
    width: 600px;
    min-height: 300px;
    border: 1px solid #aaa;
    padding: 12px;
    background: white;
    line-height: 1.6;
    font-size: 18px;
  }

  .draw-anchor {
    display: inline-block;
    width: 0;
    height: 0;
    position: relative;
  }

  .drawing-bubble {
    position: absolute;
    background: #fff;
    border: 1px solid #333;
    box-shadow: 0 2px 6px rgba(0,0,0,0.25);
    padding: 4px;
    border-radius: 6px;
    z-index: 999;
  }

  .drawing-canvas {
    border: 1px solid #ddd;
    background: #fff;
  }

  .drag-handle {
    width: 100%;
    text-align: right;
    font-size: 12px;
    cursor: move;
    color: #444;
  }
</style>
</head>

<body>

<h2>Anchored Drawing Bubble Demo</h2>

<div id="toolbar">
  <button id="addDrawingBtn">🎨 Add Drawing</button>
</div>

<div id="wrap" style="position:relative;">
  <div id="textBox" contenteditable="true">
    Núna ertu hjá mér, Nína..<br>
    Strýkur mér um vangann, Nína.<br>
    Ó, haltu' í höndina á mér, Nína.<br>
    Því þú veist að ég mun aldrei aftur.<br>
    Ég mun aldrei, aldrei aftur.<br>
    Aldrei aftur eiga stund með þér.
  </div>
</div>

<script>
let pendingAnchor = false;
let anchorCount = 0;

document.getElementById("addDrawingBtn").onclick = () => {
  pendingAnchor = true;
  alert("Click somewhere inside the text to place the drawing anchor.");
};

// Click inside the text to place anchor
document.getElementById("textBox").addEventListener("click", e => {
  if (!pendingAnchor) return;
  pendingAnchor = false;

  const sel = window.getSelection();
  if (!sel.rangeCount) return;
  const range = sel.getRangeAt(0);

  // Create a unique ID
  const id = "d" + (++anchorCount);

  // Insert invisible anchor span
  const anchor = document.createElement("span");
  anchor.className = "draw-anchor";
  anchor.dataset.id = id;

  range.insertNode(anchor);

  // Create the drawing bubble
  createDrawingBubble(anchor);
});

// Create drawing bubble
function createDrawingBubble(anchor) {
  const bubble = document.createElement("div");
  bubble.className = "drawing-bubble";
  bubble.dataset.anchor = anchor.dataset.id;

  const handle = document.createElement("div");
  handle.className = "drag-handle";
  handle.textContent = "⣿";

  const canvas = document.createElement("canvas");
  canvas.className = "drawing-canvas";
  canvas.width = 200;
  canvas.height = 150;

  bubble.appendChild(handle);
  bubble.appendChild(canvas);
  document.body.appendChild(bubble);

  makeDraggable(bubble, handle);
  initDrawingCanvas(canvas);

  positionBubble(anchor, bubble);

  // Keep bubble synced to anchor position
  new ResizeObserver(() => positionBubble(anchor, bubble)).observe(textBox);
  new MutationObserver(() => positionBubble(anchor, bubble))
    .observe(textBox, { childList: true, subtree: true, characterData: true });
}

// Position bubble relative to anchor span
function positionBubble(anchor, bubble) {
  const rect = anchor.getBoundingClientRect();
  if (!rect.width && !rect.height) return; // Anchor not visible

  bubble.style.left = (rect.left + window.scrollX + 10) + "px";
  bubble.style.top  = (rect.top  + window.scrollY - 10) + "px";
}

// Make bubble draggable
function makeDraggable(el, handle) {
  let startX, startY, startLeft, startTop, dragging = false;

  handle.addEventListener("mousedown", e => {
    dragging = true;
    startX = e.clientX;
    startY = e.clientY;
    const r = el.getBoundingClientRect();
    startLeft = r.left;
    startTop = r.top;
    e.preventDefault();
  });

  document.addEventListener("mousemove", e => {
    if (!dragging) return;
    el.style.left = (startLeft + (e.clientX - startX)) + "px";
    el.style.top  = (startTop  + (e.clientY - startY)) + "px";
  });

  document.addEventListener("mouseup", () => dragging = false);
}

// Init drawing canvas
function initDrawingCanvas(canvas) {
  const ctx = canvas.getContext("2d");
  let drawing = false;

  function getPos(e) {
    const r = canvas.getBoundingClientRect();
    const x = (e.touches ? e.touches[0].clientX : e.clientX) - r.left;
    const y = (e.touches ? e.touches[0].clientY : e.clientY) - r.top;
    return { x, y };
  }

  canvas.addEventListener("mousedown", e => {
    drawing = true;
    const { x, y } = getPos(e);
    ctx.beginPath();
    ctx.moveTo(x, y);
  });

  canvas.addEventListener("mousemove", e => {
    if (!drawing) return;
    const { x, y } = getPos(e);
    ctx.lineTo(x, y);
    ctx.strokeStyle = "#000";
    ctx.lineWidth = 2;
    ctx.lineCap = "round";
    ctx.stroke();
  });

  document.addEventListener("mouseup", () => drawing = false);
}
</script>

</body>
</html>
