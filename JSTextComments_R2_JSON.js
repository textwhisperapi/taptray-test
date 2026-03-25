/* ============================================================
   TextWhisper — JSTextComments.js
   Inline text highlights + draggable comment bubbles.
   Works per-user and syncs to Cloudflare R2 (parallel to annotations).
   ============================================================ */

(function () {

  // ====== INTERNAL STATE ======
  const COMMENTS = {};        // { id: text }
  const ANCHORS = {};         // { id: { quote, offset, context_before, context_after } }
  const BUBBLE_OFFSETS = {};  // { id: leftOffset }
  let AREA, OWNER, ANNOTATOR, SURROGATE;
  
  
// 🌐 Make live state globally visible (for JSEvents or debugging)
window._COMMENTS = COMMENTS;
window._ANCHORS = ANCHORS;
window._BUBBLE_OFFSETS = BUBBLE_OFFSETS;



  // ====== INIT ======
  window.initTextComments = function (selector, opts = {}) {
    AREA = document.querySelector(selector);
    OWNER = opts.owner;
    ANNOTATOR = opts.annotator;
    SURROGATE = opts.surrogate;

    if (!AREA) return console.warn("initTextComments: invalid selector");

    bindTextSelection();
    bindHighlightClick();
    bindContextMenu();

    console.log("💬 Text comments initialized for", ANNOTATOR);

    // 🧩 Rebuild any stored spans/bubbles already in DOM
    AREA.querySelectorAll("span.hl").forEach(span => {
      const id = span.dataset.id || ("c" + Math.random().toString(36).slice(2, 8));
      span.dataset.id = id;
      const bubble = span.querySelector(".comment-hint");
      if (bubble) {
        const handle = bubble.querySelector(".comment-drag-handle");
        if (handle) makeDraggable(bubble, handle, id);
      }
    });

    // 🧩 Restore existing bubbles’ content & handlers
    AREA.querySelectorAll("span.hl").forEach(span => {
      const id = span.dataset.id;
      if (!id) return;
      const bubble = span.querySelector(".comment-hint");
      if (bubble) {
        const textEl = bubble.querySelector(".comment-text");
        if (textEl) COMMENTS[id] = textEl.textContent.trim();
        ANCHORS[id] = extractAnchorContext(span);
        BUBBLE_OFFSETS[id] = parseFloat(bubble.style.left) || 0;
        const handle = bubble.querySelector(".comment-drag-handle");
        if (handle) makeDraggable(bubble, handle, id);
      }
    });
  };


  // ====== CREATE HIGHLIGHT ON SELECTION ======
  function bindTextSelection() {
    ["mouseup", "touchend"].forEach(ev =>
      AREA.addEventListener(ev, e => {
        if (e.target.closest(".comment-hint")) return;
        const sel = window.getSelection();
        if (!sel || sel.isCollapsed) return;
        const range = sel.getRangeAt(0);
        if (!AREA.contains(range.commonAncestorContainer)) return;

        const span = document.createElement("span");
        span.className = "hl";
        const id = "c" + Math.random().toString(36).slice(2, 8);
        span.dataset.id = id;
        span.textContent = sel.toString();

        range.deleteContents();
        range.insertNode(span);
        sel.removeAllRanges();

        COMMENTS[id] = "";
        ANCHORS[id] = extractAnchorContext(span);
        createBubble(span, id, true);
      })
    );
  }


  // ====== CLICK HIGHLIGHT → OPEN/EDIT ======
  function bindHighlightClick() {
    AREA.addEventListener("click", e => {
      const span = e.target.closest(".hl");
      if (!span) return;
      const id = span.dataset.id;
      const existing = span.querySelector(".comment-hint");
      if (existing) {
        const textEl = existing.querySelector(".comment-text");
        textEl.setAttribute("contenteditable", "plaintext-only");
        textEl.focus();
        placeCaretEnd(textEl);
        enableEdit(textEl, existing, span, id);
        return;
      }
      createBubble(span, id, true);
    });
  }


  // ====== RIGHT CLICK → REMOVE HIGHLIGHT ======
  function bindContextMenu() {
    AREA.addEventListener("contextmenu", e => {
      const span = e.target.closest(".hl");
      if (!span) return;
      e.preventDefault();
      removeHighlight(span, span.dataset.id);
    });
  }


  // ====== CREATE COMMENT BUBBLE ======
  function createBubble(span, id, focusNow = false) {
    const bubble = document.createElement("div");
    bubble.className = "comment-hint";

    const textEl = document.createElement("div");
    textEl.className = "comment-text";
    if (COMMENTS[id]) textEl.textContent = COMMENTS[id];

    const handle = document.createElement("div");
    handle.className = "comment-drag-handle";

    bubble.append(textEl, handle);
    span.appendChild(bubble);
    makeDraggable(bubble, handle, id);

    if (BUBBLE_OFFSETS[id] != null)
      bubble.style.left = BUBBLE_OFFSETS[id] + "px";

    if (focusNow) {
      textEl.setAttribute("contenteditable", "plaintext-only");
      requestAnimationFrame(() => { textEl.focus(); placeCaretEnd(textEl); });
      enableEdit(textEl, bubble, span, id);
    }

    return bubble;
  }


    if (typeof window.saveUserComments !== "function") {
      window.saveUserComments = async function () {
        console.warn("⚠️ saveUserComments placeholder — real function not loaded yet");
      };
    }


    // ====== EDIT COMMENT TEXT ======
    
        
    function enableEdit(textEl, bubble, span, id) {
      const finish = async () => {
        const val = textEl.textContent.trim();
        if (val) COMMENTS[id] = val;
        else removeHighlight(span, id);
        textEl.removeAttribute("contenteditable");
        syncReadonlyMirrorFromEditable();
    
        // 💾 Auto-save to R2
        try {
          await saveUserComments?.();
          console.log("💬 Auto-saved comment to R2");
        } catch (err) {
          console.warn("⚠️ Auto-save failed:", err);
        }
      };
    
      // 🖋 Allow Shift+Enter for newlines, Enter = save
      textEl.addEventListener(
        "keydown",
        ev => {
          if (ev.key === "Enter" && !ev.shiftKey) {
            ev.preventDefault();
            ev.stopPropagation();
            document.execCommand("insertHTML", false, ""); // block newline
            textEl.blur(); // triggers finish()
            return false;
          }
        },
        true // capture phase → fires before parent contenteditables
      );
    
      // 🧩 Blur (desktop or touch) → finish + save
      textEl.addEventListener(
        "blur",
        () => {
          finish();
        },
        { once: true }
      );
    
      // 🪶 Mobile tap-out workaround
      textEl.addEventListener("touchend", e => {
        if (!textEl.contains(e.relatedTarget)) finish();
      });
    }





  // ====== UTIL ======
  function placeCaretEnd(el) {
    const r = document.createRange(); r.selectNodeContents(el); r.collapse(false);
    const s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
  }

    function removeHighlight(span, id) {
      delete COMMENTS[id];
      delete ANCHORS[id];
      span.querySelector(".comment-hint")?.remove();
      span.replaceWith(document.createTextNode(span.textContent));
      syncReadonlyMirrorFromEditable();   // 👈 add here
    }


  // ====== DRAGGING ======
  function makeDraggable(bubble, handle, id) {
    let startX, startLeft, dragging = false, timer;

    const start = x => {
      dragging = true;
      bubble.classList.add("dragging");
      startX = x;
      startLeft = parseFloat(bubble.style.left) || 0;
    };
    const move = x => {
      if (!dragging) return;
      const dx = x - startX;
      const newLeft = startLeft + dx;
      bubble.style.left = newLeft + "px";
      BUBBLE_OFFSETS[id] = newLeft;
    };
    const stop = () => {
      dragging = false;
      bubble.classList.remove("dragging");
    };

    handle.addEventListener("mousedown", e => { e.preventDefault(); start(e.clientX); });
    document.addEventListener("mousemove", e => move(e.clientX));
    document.addEventListener("mouseup", stop);

    handle.addEventListener("touchstart", e => {
      timer = setTimeout(() => start(e.touches[0].clientX), 200);
    }, { passive: true });
    handle.addEventListener("touchmove", e => move(e.touches[0].clientX), { passive: true });
    handle.addEventListener("touchend", () => { clearTimeout(timer); stop(); });
  }


  // ====== ANCHOR CONTEXT (for fuzzy restore) ======
  function extractAnchorContext(span) {
    const clone = AREA.cloneNode(true);
    clone.querySelectorAll(".comment-hint").forEach(el => el.remove());
    const cleanText = clone.innerHTML.replace(/<br\s*\/?>/gi, "\n");
    const plainText = clone.textContent;
    const quote = span.textContent;
    const idx = plainText.indexOf(quote);
    const before = plainText.slice(Math.max(0, idx - 20), idx);
    const after = plainText.slice(idx + quote.length, idx + quote.length + 20);
    return { quote, context_before: before, context_after: after, offset: idx };
  }


  // ====== SAVE TO R2 ======
//   window.saveUserComments = async function () {
//     const surrogate = window.currentSurrogate;
//     const annotator = window.SESSION_USERNAME;
//     const owner = window.currentItemOwner;
//     const page = 1;

//     if (!surrogate || !annotator) return console.warn("⚠️ Missing surrogate or annotator");

//     const data = Object.keys(COMMENTS).map(id => ({
//       id, text: COMMENTS[id], owner, annotator, surrogate, page,
//       anchor: ANCHORS[id], offset: BUBBLE_OFFSETS[id] || 0
//     }));

//     if (!data.length) return console.log("💬 No comments to save.");

//     const blob = new Blob([JSON.stringify(data, null, 2)], { type: "application/json" });
//     // const url = `https://r2-worker.textwhisper.workers.dev/${annotator}/comments/comments-${surrogate}-p${page}.json`;
    
//     console.log(owner, annotator, surrogate, page, blob); 
    
//     const url = `https://r2-worker.textwhisper.workers.dev/${owner}/comments/users/${annotator}/comments-${surrogate}-p${page}.json`;



//     try {
//       const res = await fetch(url, { method: "PUT", body: blob });
//       if (!res.ok) throw new Error(`Save failed: ${res.status}`);
//       console.log(`💾 Saved ${data.length} comments → ${url}`);
//     } catch (err) {
//       console.error("❌ Failed to save comments:", err);
//     }
//   };
  
  
    window.saveUserComments = async function () {
      const surrogate = window.currentSurrogate;
      const annotator = window.SESSION_USERNAME;
      const owner = window.currentItemOwner;
      const page = 1;
    
      if (!surrogate || !annotator) return console.warn("⚠️ Missing surrogate or annotator");
    
      // 🧩 Pull live bubble texts before saving
      document.querySelectorAll(".comment-hint .comment-text").forEach(el => {
        const id = el.closest(".hl")?.dataset.id;
        if (id) COMMENTS[id] = el.textContent.trim();
      });
    
      const data = Object.keys(COMMENTS).map(id => ({
        id,
        text: COMMENTS[id],
        owner,
        annotator,
        surrogate,
        page,
        anchor: ANCHORS[id],
        offset: BUBBLE_OFFSETS[id] || 0
      }));
    
      if (!data.length) return console.log("💬 No comments to save.");
    
      const blob = new Blob([JSON.stringify(data, null, 2)], { type: "application/json" });
    
      console.log("💬 Saving comments | surrogate:", surrogate, "| owner:", owner, "| annotator:", annotator, "| blob:", blob.size);
    
      const url = `https://r2-worker.textwhisper.workers.dev/${owner}/comments/users/${annotator}/comments-${surrogate}-p${page}.json`;
    
      try {
        const res = await fetch(`${url}?key=${owner}/comments/users/${annotator}/comments-${surrogate}-p${page}.json`, {
          method: "POST",
          body: blob
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        console.log(`💾 Saved ${data.length} comments → ${url}`);
      } catch (err) {
        console.error("❌ Failed to save comments:", err);
      }
    };

  


    // ====== LOAD FROM R2 ======
    window.loadUserComments = async function (owner, surrogate) {
      const annotator = window.SESSION_USERNAME || "guest";
    //   const url = `https://r2-worker.textwhisper.workers.dev/${owner}/comments/users/${annotator}/comments-${surrogate}.json`;
      const url = `https://r2-worker.textwhisper.workers.dev/${owner}/comments/users/${annotator}/comments-${surrogate}-p1.json`;

    
      try {
        const res = await fetch(url, { cache: "no-store" });
        if (!res.ok) {
          console.log("💬 No comment JSON found for", surrogate);
          return;
        }
    
        const arr = await res.json();
        console.log(`💬 Loaded ${arr.length} comments for`, surrogate);
    
        for (const c of arr) {
          COMMENTS[c.id] = c.text;
          ANCHORS[c.id] = c.anchor;
          BUBBLE_OFFSETS[c.id] = c.offset || 0;
    
          // locate or rebuild highlight
          let span = AREA.querySelector(`[data-id='${c.id}']`);
          if (!span) {
            // fallback: reinsert highlight by anchor quote if not found
            const match = findAnchorByQuote(c.anchor?.quote);
            if (match) {
              const range = match.range;
              span = document.createElement("span");
              span.className = "hl";
              span.dataset.id = c.id;
              range.surroundContents(span);
            }
          }
    
          if (span) {
            const bubble = createBubble(span, c.id, false);
            const textEl = bubble.querySelector(".comment-text");
            if (textEl) textEl.textContent = c.text;
          }
        }
      } catch (err) {
        console.error("❌ loadUserComments error:", err);
      }
    };
    
    // 🧩 Helper to find where to re-anchor a highlight if the span was lost
    function findAnchorByQuote(quote) {
      if (!quote || !AREA) return null;
      const walker = document.createTreeWalker(AREA, NodeFilter.SHOW_TEXT);
      while (walker.nextNode()) {
        const node = walker.currentNode;
        const idx = node.nodeValue.indexOf(quote);
        if (idx !== -1) {
          const range = document.createRange();
          range.setStart(node, idx);
          range.setEnd(node, idx + quote.length);
          return { node, range };
        }
      }
      return null;
    }

  
    // ====== SYNC MIRROR (T2) FROM EDITABLE (T1) ======
    function syncReadonlyMirrorFromEditable() {
      const editDiv   = document.getElementById("myTextarea");
      const mirrorDiv = document.getElementById("myTextarea2");
      if (!editDiv || !mirrorDiv) return;
    
      // clone to keep <br> and highlight spans
      const clone = editDiv.cloneNode(true);
    
      // remove live bubble UIs only
      clone.querySelectorAll(".comment-hint").forEach(el => el.remove());
    
      // push clean HTML into mirror
      mirrorDiv.innerHTML = clone.innerHTML;
    }
  
  

})();
