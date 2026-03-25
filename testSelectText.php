<!DOCTYPE html>
<html lang="is">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Mode + Highlight Test</title>
<style>
body {
  font-family: system-ui, sans-serif;
  background: #f0f0f0;
  padding: 1em;
}
#controls {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 10px;
}
button {
  padding: 6px 12px;
  border: 1px solid #ccc;
  background: #fff;
  border-radius: 6px;
  cursor: pointer;
}
button.active {
  background: #007bff;
  color: #fff;
}

#textBox {
  background: #fff;
  padding: 1em;
  border: 1px solid #ccc;
  border-radius: 10px;
  line-height: 1.6;
  min-height: 200px;
  user-select: text;
  -webkit-user-select: text;
  touch-action: auto;
}
span.hl { background: yellow; }
</style>
</head>
<body>

<h3>📱 Edit Mode + Sub-Mode Test</h3>

<div id="controls">
  <button id="toggleEditBtn">✏️ Edit Mode: OFF</button>
  <button id="subWriteBtn" disabled>Edit (Write)</button>
  <button id="subHighlightBtn" disabled>Edit (Highlight)</button>
</div>

<div id="textBox" contenteditable="false">
Núna ertu hjá mér, Nína..<br>
Strýkur mér um vangann, Nína.<br>
Ó, haltu' í höndina á mér, Nína.<br>
Því þú veist að ég mun aldrei aftur.<br>
Ég mun aldrei, aldrei aftur.<br>
Aldrei aftur eiga stund með þér.
</div>

<script>
const box = document.getElementById('textBox');
let startRange = null;
let editMode = false;          // main flag
let editSubMode = 'write';     // sub-flag: write | highlight

// --- helpers ---
function caretRangeFromPointCompat(x, y) {
  if (document.caretRangeFromPoint) return document.caretRangeFromPoint(x, y);
  if (document.caretPositionFromPoint) {
    const pos = document.caretPositionFromPoint(x, y);
    const r = document.createRange();
    r.setStart(pos.offsetNode, pos.offset);
    r.collapse(true);
    return r;
  }
  return null;
}

// --- touch selection logic ---
function enableTouchSelect() {
  box.addEventListener('touchstart', onTouchStart, {passive:true});
  box.addEventListener('touchmove', onTouchMove, {passive:true});
  box.addEventListener('touchend', onTouchEnd, {passive:true});
}
function disableTouchSelect() {
  box.removeEventListener('touchstart', onTouchStart);
  box.removeEventListener('touchmove', onTouchMove);
  box.removeEventListener('touchend', onTouchEnd);
}
function onTouchStart(e) {
  const t = e.touches[0];
  const r = caretRangeFromPointCompat(t.clientX, t.clientY);
  if (!r) return;
  startRange = r;
  const s = getSelection();
  s.removeAllRanges();
  s.addRange(r);
}
function onTouchMove(e) {
  if (!startRange) return;
  const t = e.touches[0];
  const end = caretRangeFromPointCompat(t.clientX, t.clientY);
  if (!end) return;
  const r = document.createRange();
  r.setStart(startRange.startContainer, startRange.startOffset);
  r.setEnd(end.endContainer, end.endOffset);
  const s = getSelection();
  s.removeAllRanges();
  s.addRange(r);
}
function onTouchEnd() {
  if (!editMode || editSubMode !== 'highlight') return;

  const s = getSelection();
  if (!s || s.isCollapsed) return;
  const r = s.getRangeAt(0);
  const span = document.createElement('span');
  span.className = 'hl';
  span.appendChild(r.extractContents());
  r.insertNode(span);
  s.removeAllRanges();
  startRange = null;
}

// --- mode switching ---
function applyMode() {
  const toggleBtn = document.getElementById('toggleEditBtn');
  const writeBtn = document.getElementById('subWriteBtn');
  const highlightBtn = document.getElementById('subHighlightBtn');

  if (!editMode) {
    // VIEW MODE
    toggleBtn.textContent = '✏️ Edit Mode: OFF';
    box.contentEditable = false;
    box.style.touchAction = 'auto';
    disableTouchSelect();
    writeBtn.disabled = true;
    highlightBtn.disabled = true;
    console.log('👁 View mode');
  } else {
    // EDIT MODE ON
    toggleBtn.textContent = '✏️ Edit Mode: ON';
    writeBtn.disabled = false;
    highlightBtn.disabled = false;

    if (editSubMode === 'write') {
      writeBtn.classList.add('active');
      highlightBtn.classList.remove('active');
      box.contentEditable = true;
      box.style.touchAction = 'auto';
      disableTouchSelect();
      console.log('✏️ Edit (Write)');
    } else if (editSubMode === 'highlight') {
      highlightBtn.classList.add('active');
      writeBtn.classList.remove('active');
      box.contentEditable = false;
      box.style.touchAction = 'none'; // ⭐ allow touch drag highlight
      enableTouchSelect();
      console.log('🖍 Edit (Highlight)');
    }
  }
}

// --- button bindings ---
document.getElementById('toggleEditBtn').onclick = () => {
  editMode = !editMode;
  applyMode();
};

document.getElementById('subWriteBtn').onclick = () => {
  editSubMode = 'write';
  applyMode();
};

document.getElementById('subHighlightBtn').onclick = () => {
  editSubMode = 'highlight';
  applyMode();
};

// init
applyMode();
</script>

</body>
</html>
