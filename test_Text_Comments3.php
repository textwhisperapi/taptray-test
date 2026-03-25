<?php // dev/test-actor-bubbles-handle-right-tight.php ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>ðŸŽ­ Actor Bubbles â€” Minimal Comments</title>

<style>
body {
  /*background:#111;*/
  /*color:#eee;*/
  font-family:sans-serif;
  padding:20px;
}

#textArea {
  width:90%;
  min-height:250px;
  /*background:#222;*/
  /*color:#fff;*/
  padding:10px;
  border-radius:6px;
  line-height:1.6;
  position:relative;
  cursor:text;
}

/* === Highlighted text === */
span.hl {
  position:relative;
  background:rgba(255,255,0,.35);
  border-radius:3px;
  cursor:pointer;
  transition:background .15s ease;
}
span.hl:hover {
  background:rgba(255,255,0,.5);
}

/* === Comment bubble === */
.comment-hint {
  font-size: inherit;     /* ✅ same as surrounding text */
  line-height: 1.2;
  position: absolute;
  bottom: 100%;
  left: 0;
  display: inline-flex;
  align-items: center;
  gap: 0.4em;
  background: rgba(255,255,180,.96);
  color: #222;
  padding: 0.0em 0.6em;
  border-radius: 0.2em;
  white-space: nowrap;
  box-shadow: 0 1px 2px rgba(0,0,0,.25);
  z-index: 9999;
  transform: translateY(0.1em);
  -webkit-text-size-adjust: 100%;
  text-size-adjust: 100%;
}


.comment-hint::after {
  content:"";
  position:absolute;
  top:100%;
  left:0.9em;
  border-width:0.3em;
  border-style:solid;
  border-color:rgba(255,255,180,.96) transparent transparent transparent;
}

/* === Editable text inside bubble === */
.hint-text {
  font:inherit;
  color:#111;
  background:transparent;
  outline:none;
  border:none;
  padding:0.1em;
  margin:0;
  font-size:1.2rem; /* ✅ prevents iOS Safari zoom & shrink */
  -webkit-text-size-adjust:100%;
  text-size-adjust:100%;
}


/* === Drag handle === */
.drag-handle {
  flex:0 0 auto;
  cursor:grab;
  user-select:none;
  background:repeating-linear-gradient(to bottom,#777 0 2px,transparent 2px 4px);
  border-radius:0.1em;
  width:1.2em;
  height:1em;
  margin-left:0.4em;
}
.comment-hint.dragging .drag-handle {
  cursor:grabbing;
}

/* === Touch === */
.comment-hint, .drag-handle { touch-action:none; }

/* === Prevent iOS zoom on any editable === */
input, textarea, [contenteditable] {
  font-size:16px;
}

.hint-text {
  font-size:0.9em;       /* visually same as before */
  line-height:1.2;
}
</style>



</head>
<body>

<h3>ðŸŽ­ Minimal Bubbles â€” Tight Text + Right Handle</h3>
<p>Select text â†’ highlight. Tap highlight â†’ edit bubble. Drag handle moves. Enter saves. Long-press highlight â†’ remove.</p>

<div id="textArea" contenteditable="true">
  Here is a <span class="hl" data-id="c1">sample script line</span> you can comment on.
</div>

<script>
const area = document.getElementById('textArea');
const comments = { c1:"Nice tone here!" };
const bubbleOffsets = {};

// === Highlight creation ===
function createHighlightFromSelection() {
  const sel = window.getSelection();
  if (!sel || sel.isCollapsed) return;
  const range = sel.getRangeAt(0);
  if (!area.contains(range.commonAncestorContainer)) return;
  const span = document.createElement('span');
  span.className = 'hl';
  const id = 'c' + Math.random().toString(36).slice(2,8);
  span.dataset.id = id;
  span.textContent = sel.toString();
  range.deleteContents();
  range.insertNode(span);
  sel.removeAllRanges();
  comments[id] = "";
}
area.addEventListener('mouseup',e=>{ if(!e.target.closest('.comment-hint')) createHighlightFromSelection(); });
area.addEventListener('touchend',e=>{ if(!e.target.closest('.comment-hint')) createHighlightFromSelection(); });

// === Click or tap highlight ===
function openComment(span) {
  const id = span.dataset.id;
  let bubble = span.querySelector('.comment-hint');
  if (!bubble) bubble = createBubble(span,id);
  const txt = bubble.querySelector('.hint-text');
  txt.setAttribute('contenteditable','plaintext-only');
  requestAnimationFrame(()=>{ txt.focus(); placeCaretEnd(txt); });
  enableEdit(txt,bubble,span,id);
}
area.addEventListener('click',e=>{
  const span = e.target.closest('.hl');
  if (span) openComment(span);
});
area.addEventListener('touchstart',e=>{
  const span = e.target.closest('.hl');
  if (span) { e.preventDefault(); openComment(span); }
});

// === Remove highlight (long-press â†’ contextmenu) ===
area.addEventListener('contextmenu',e=>{
  const span = e.target.closest('.hl');
  if(!span) return;
  e.preventDefault();
  removeHighlight(span, span.dataset.id);
});

// === Bubble factory ===
function createBubble(span,id){
  const bubble = document.createElement('div');
  bubble.className='comment-hint';
  const text = document.createElement('div');
  text.className='hint-text';
  if(comments[id]) text.textContent=comments[id];
  const handle = document.createElement('div');
  handle.className='drag-handle';
  bubble.append(text,handle);
  span.appendChild(bubble);
  makeDraggable(bubble,handle,id);
  if(bubbleOffsets[id]!=null) bubble.style.left=bubbleOffsets[id]+"px";
  return bubble;
}

// === Edit behavior ===
function enableEdit(textEl,bubble,span,id){
  const finish=()=>{
    const t=textEl.textContent.trim();
    if(t){comments[id]=t;textEl.removeAttribute('contenteditable');}
    else{comments[id]="";bubble.remove();}
  };
  const onBlur=()=>{ textEl.removeEventListener('blur',onBlur); finish(); };
  textEl.addEventListener('blur',onBlur);
  textEl.addEventListener('keydown',ev=>{
    if(ev.key==="Enter"){ev.preventDefault();textEl.blur();}
  });
}
function placeCaretEnd(el){
  const r=document.createRange();r.selectNodeContents(el);r.collapse(false);
  const s=window.getSelection();s.removeAllRanges();s.addRange(r);
}
function removeHighlight(span,id){
  comments[id]="";
  span.querySelector('.comment-hint')?.remove();
  const t=document.createTextNode(span.textContent);
  span.replaceWith(t);
}

// === Dragging ===
function makeDraggable(bubble,handle,id){
  let startX,startLeft,drag=false,longPressTimer;
  const start=x=>{drag=true;bubble.classList.add('dragging');startX=x;startLeft=parseFloat(bubble.style.left)||0;};
  const move=x=>{if(!drag)return;const dx=x-startX;const nl=startLeft+dx;bubble.style.left=nl+"px";bubbleOffsets[id]=nl;};
  const stop=()=>{if(drag){drag=false;bubble.classList.remove('dragging');}};

  handle.addEventListener('mousedown',e=>{e.preventDefault();start(e.clientX);});
  document.addEventListener('mousemove',e=>move(e.clientX));
  document.addEventListener('mouseup',stop);

  handle.addEventListener('touchstart',e=>{
    clearTimeout(longPressTimer);
    longPressTimer=setTimeout(()=>{
      const t=e.touches[0];
      bubble.classList.add('dragging');
      start(t.clientX);
    },200);
  },{passive:true});
  handle.addEventListener('touchmove',e=>{
    const t=e.touches[0];move(t.clientX);
  },{passive:true});
  handle.addEventListener('touchend',()=>{clearTimeout(longPressTimer);stop();});
}

// === Load initial ===
document.querySelectorAll('[data-id]').forEach(span=>{
  const id=span.dataset.id;
  if(comments[id]) createBubble(span,id);
});
</script>
</body>
</html>
