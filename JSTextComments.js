logStep("JSTextComments.js executed");

/* ============================================================
   TextWhisper — JSTextComments.js
   Inline text highlights, draggable comment bubbles,
   and robust anchor restoration (2025 Hybrid SimHash Engine)

   OVERVIEW
   ------------------------------------------------------------
   TextWhisper uses a unified annotation model that remains
   stable even when the underlying editable HTML is changed by
   the user (edits, reformatting, HTML cleanup, paste events,
   invisible character removal, mobile autocorrect, etc.).

   All annotations (highlights, comments, drawings) live in:
       window.textmarks = [
         { id, type:"highlight", anchor, offset, color, ... },
         { id, type:"comment",   text, anchor, offset, ... },
         { id, type:"drawing",   image, anchor, offset, ... }
       ];

   • This array is THE master model and is exactly what is
     persisted to the backend. The DOM is never the source of truth.

   • Drawing metadata is runtime-only:
         window.DRAWINGS
     (rebuilt on load; not stored as live DOM nodes)

   ------------------------------------------------------------
   TEXTWHISPER ANCHOR ENGINE (2025)
   ------------------------------------------------------------
   Anchors must survive:
     - structural HTML changes
     - whitespace normalization
     - invisible character cleanup
     - copy/paste reformatting
     - edits before/after the highlight
     - repeated/duplicate sentences
     - mobile text rewrites

   To achieve this, TextWhisper now uses a **hybrid anchoring
   strategy**:

     1) Approximate region detection:
          - 64-bit SimHash fingerprint of the highlighted text
          - length of the highlight
          - a short prefix (“mini”) of the canonical text

     2) DOM-aware refinement:
          - a ±200-char localized search window
          - find the exact original quote inside the predicted region
          - fallback to positional locator only if needed

     3) DOM range reconstruction:
          - map canonical offsets → actual text nodes
          - wrap the restored range in <span.hl>

   This system allows accurate restoration even when the editable
   content has been heavily modified.

   SAFETY GUARANTEE (2025)
   ------------------------------------------------------------
   A failed anchor restoration NEVER wipes annotations.

   • If restoreSpanForAnchor() fails for one highlight,
     only that highlight is skipped — not the entire batch.

   • saveCurrentComments() preserves all “orphaned” annotations
     (ones that did not restore into DOM), preventing accidental
     deletion of all annotations for a surrogate.

   This solves the historical problem where one mismatching
   highlight could cause all entries to be overwritten with an
   empty list on the next save.

   ============================================================ */


 /* ============================================================
    TEXTWHISPER — ARCHITECTURE MAP (2025 FINAL)
    ------------------------------------------------------------
    This file is organized into clear, documented sections.
    Keep all new code within its proper subsystem.
    ------------------------------------------------------------

    1. GLOBAL STATE
       - AREA, SURROGATE, OWNER, ANNOTATOR, textmarks, drawings
       - These are the only global variables shared across flows.

    2. INITIALIZATION
       - initTextComments()
       - initCommentPalette()
       - Event binding, palette setup, persistent UI state.

    3. HIGHLIGHT / COMMENT ENGINE (UI interactions)
       - bindTextSelection()
       - bindHighlightClick()
       - bindContextMenu()
       - createBubble(), positionCommentBubble()
       - makeDraggable()
       - removeHighlight()
       - enableEdit()
       - All DOM-manipulation for direct user actions.

    4. ANCHOR ENGINE (core anchoring subsystem)
       - normalizePlainForAnchor()
       - buildCanonicalPlain()
       - extractAnchorContext()
       - restoreSpanForAnchor()    ← main hybrid SimHash restorer
       - findLocalizedAnchorIndex()
       - findLocalizedAnchorIndexFromPlainOffset()  ← local refinement
       - buildRangeFromPlainOffsets()
       - fuzzyLocateQuote()
       This subsystem ensures highlights survive structural edits.

    5. MODEL SYNC (DOM → window.textmarks → DB)
       - saveCurrentComments()      ← preserves orphaned anchors
       - saveCurrentDrawing()
       - saveTextMarks()
       - debouncedSaveTextMarks()

    6. LOADING PIPELINE (DB → textmarks → DOM)
       - loadUserComments()
       - restoreOffsets()
       These rebuild the full annotation UI from the master model.

    7. MIRROR SYNC (editable → readonly)
       - syncReadonlyMirrorFromEditable()

    8. DRAWING ENGINE
       - renderDrawingFromTextmarks()
       - drawing anchors, drag handlers, runtime cache

    9. TOUCH & SELECTION ENGINE
       - updateTouchActionForMode()
       - enableSmartTouchSelection()
       - caretRangeFromPointCompat()

    10. COMMENT PALETTE UI
        - attachColorPicker()
        - deleteCommentsForCurrentText()
        - toggleCommentVisibility()

    11. SNAPSHOT / DEBUG
        - snapshotTextState()

    ------------------------------------------------------------
    NOTES:
    • Keep the ANCHOR ENGINE grouped — it is critical.
    • All restoration & matching must use canonical text builders.
    • Never scatter DOM cleanup; do it only in the loader.
    • window.textmarks is always the source of truth.
    ============================================================ */



// ============================================================
// GLOBAL STATE (centralized for clarity & stability)
// ============================================================

let AREA = null;         // The editable text container (#myTextarea)
let OWNER = null;        // Text owner (permissions)
let ANNOTATOR = null;    // Current user who annotates
let SURROGATE = null;    // Current surrogate ID
let LAST_TEXT_STATE = null;  // Snapshot of HTML state (undo support)

window.textmarks = window.textmarks || [];  // Unified annotation model
window.DRAWINGS  = window.DRAWINGS  || {};  // Runtime-only drawing cache


(function () {


    // ====== INIT ======
    window.initTextComments = function (selector, opts = {}) {
    
      // 1) Resolve globals
      AREA      = document.querySelector(selector);
      OWNER     = opts.owner || null;
      ANNOTATOR = opts.annotator || null;
      SURROGATE = opts.surrogate || null;
    
      if (!AREA) {
        console.warn("initTextComments: invalid selector:", selector);
        return;
      }
    
      // 2) Prevent double-binding
      if (AREA.__twhisper_initialized) {
        console.log("TextComments already initialized — skipping rebind.");
        return;
      }
      AREA.__twhisper_initialized = true;
    
      // 3) Event handlers
      bindTextSelection();
      bindHighlightClick();
      bindContextMenu();
      enableSmartTouchSelection(AREA);
    
      console.log("💬 Text comments initialized");
    
      // 4) Restore bubble drag handles
      AREA.querySelectorAll("span.hl").forEach(span => {
        const id = span.dataset.id || ("c" + Math.random().toString(36).slice(2, 8));
        span.dataset.id = id;
    
        const bubble = span.querySelector(".comment-hint");
        if (bubble) {
          const handle = bubble.querySelector(".comment-drag-handle");
          if (handle) makeDraggable(bubble, handle, id);
        }
      });
    };


    // ====== CREATE HIGHLIGHT ON SELECTION ======

    function bindTextSelection() {
      ["mouseup", "touchend"].forEach(ev =>
        AREA.addEventListener(ev, e => {
          if (!window.EditMode) return;
          if (e.target.closest(".comment-hint")) return;
    
          const mode = window.activeTextTool || "write";
          if (mode !== "highlight" && mode !== "comment") return;
    
          const sel = window.getSelection();
          if (!sel || sel.isCollapsed) return;
    
          const range = sel.getRangeAt(0);
          if (!AREA.contains(range.commonAncestorContainer)) return;
    
          // Do NOT use sel.toString() — we want pure text nodes only
          const selectedText = range.cloneContents().textContent;
          if (!selectedText || !selectedText.trim()) {
            sel.removeAllRanges();
            return;
          }
    
          // --- Create span wrapper ---
          const span = document.createElement("span");
          span.className = "hl";
          
            // ---------------------------------------
            // RESET FLAGS (Normal highlight baseline)
            // ---------------------------------------
            span.dataset.highlightAll = "false";
            span.dataset.hlMaster = "false";
            span.dataset.hlClone = "false";
            
            // If user selected "highlight-all"
            const toggle = document.getElementById("hlAllToggle");
            if (toggle && toggle.checked) {
                // Only THIS span is the master
                span.dataset.highlightAll = "true";
                span.dataset.hlMaster = "true";
            }
          
    
          // Assign color
          let color = "#ffeb3b";
          if (mode === "highlight") color = window.currentHighlightColor || color;
          if (mode === "comment")   color = window.currentCommentColor || color;
    
          span.dataset.color = color;
        //   span.style.backgroundColor = color;
          span.style.backgroundColor = toRgba(color, 0.4);
    
          // Unique ID
          const id = "c" + Math.random().toString(36).slice(2, 8);
          span.dataset.id = id;
            
            // If user enabled highlight-all, store it in the span
            // const toggle = document.getElementById("hlAllToggle");
            // if (toggle && toggle.checked) {
            //     span.dataset.highlightAll = "true";
            // }
          
    
            //Safe wrapper — never corrupts text or line breaks
            wrapRangeSafely(range, span);

    
          sel.removeAllRanges();
    
          // --- Undo registration ---
          if (mode === "highlight" && typeof window.pushUndo === "function") {
            window.pushUndo({
              type: "highlight-add",
              undo: () => {
                if (!span.isConnected) return;
                const parent = span.parentNode;
                while (span.firstChild) parent.insertBefore(span.firstChild, span);
                span.remove();
    
                if (Array.isArray(window.textmarks)) {
                  window.textmarks = window.textmarks.filter(x => x.id !== id);
                }
                syncReadonlyMirrorFromEditable?.();
              }
            });
          }
    
          // --- Comment mode: auto-create bubble ---
          if (mode === "comment") {
            window.pushUndo({
              type: "comment-create",
              undo: () => {
                const bubble = span.querySelector(".comment-hint");
                if (bubble) bubble.remove();
                if (span.isConnected) {
                  const parent = span.parentNode;
                  while (span.firstChild) parent.insertBefore(span.firstChild, span);
                  span.remove();
                }
                window.saveCurrentComments?.();
                syncReadonlyMirrorFromEditable?.();
              }
            });
    
            createBubble(span, id, true);
          }
    
          // update textmarks
          window.saveCurrentComments?.();
    
          // schedule save
          if (mode === "highlight") {
            window.debouncedSaveTextMarks?.("highlight");
          }
        })
      );
    }


    // ====== CLICK HIGHLIGHT → OPEN/EDIT ======
    function bindHighlightClick() {
      AREA.addEventListener("click", e => {
        if (!window.EditMode) return;
    
        const span = e.target.closest("span.hl");
        if (!span) return;
        
        //WRITE MODE: do NOT open bubbles — allow normal caret editing
        if ((window.activeTextTool || "write") === "write") {
          return; // important: let the event fall through to normal editing
        }
        
    
        const id = span.dataset.id;
        if (!id) return;
    
        // If clicking inside bubble → allow edit but avoid triggering highlight click
        if (e.target.closest(".comment-hint")) return;
    
        // Look for existing bubble
        const existing = span.querySelector(".comment-hint");
    
        if (existing) {
          // Enter edit mode
          const textEl = existing.querySelector(".comment-text");
          if (!textEl) return;
    
          textEl.setAttribute("contenteditable", "plaintext-only");
          textEl.focus();
          placeCaretEnd(textEl);
    
          enableEdit(textEl, existing, span, id);
          return;
        }
    
        // No bubble → create one
        const bubble = createBubble(span, id, true);
    
        // ensure anchor is up-to-date
        window.saveCurrentComments?.();
      });
    }


    function bindContextMenu() {
      AREA.addEventListener("contextmenu", e => {
        if (!window.EditMode) return;
    
        const span = e.target.closest(".hl");
        if (!span) return;
    
        // Prevent native menu
        e.preventDefault();
    
        // Prevent triggering from bubble handle drag
        if (e.target.closest(".comment-drag-handle")) return;
    
        const id = span.dataset.id;
        if (!id) return;
    
        // Remove any existing delete menus first
        document.querySelectorAll(".comment-delete-menu").forEach(m => m.remove());
    
        // Create menu
        const menu = document.createElement("div");
        menu.className = "comment-delete-menu";
        menu.textContent = "🗑️ Delete highlight";
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
          zIndex: 999999,
          fontSize: "14px",
          fontFamily: "system-ui,sans-serif",
          userSelect: "none"
        });
    
        document.body.appendChild(menu);
    
        const cleanup = () => menu.remove();
    
        // When clicked → remove highlight using unified logic
        menu.addEventListener("click", () => {
          removeHighlight(span, id);   // ⭐ Unified deletion path
          cleanup();
        });
    
        // Close on outside click
        document.addEventListener(
          "mousedown",
          ev2 => {
            if (!menu.contains(ev2.target)) cleanup();
          },
          { once: true }
        );
      });
    }


  // ====== CREATE COMMENT BUBBLE ======
    function createBubble(span, id, focusNow = false) {
      const bubble = document.createElement("div");
      bubble.className = "comment-hint";
    
      const textEl = document.createElement("div");
      textEl.className = "comment-text";
    
      // Load comment text from textmarks (if exists)
      if (Array.isArray(window.textmarks)) {
        const entry = window.textmarks.find(x => x.id === id && x.type === "comment");
        if (entry && entry.text) {
          textEl.textContent = entry.text;
        }
      }
    
      const handle = document.createElement("div");
      handle.className = "comment-drag-handle";
    
      bubble.append(textEl, handle);
      span.appendChild(bubble);
    
      makeDraggable(bubble, handle, id);
    
      // ---- Calculate initial position ----
      let savedOffset = null;
    
      if (Array.isArray(window.textmarks)) {
        const entry = window.textmarks.find(x => x.id === id);
        if (entry && entry.offset) savedOffset = entry.offset;
      }
    
      if (savedOffset) {
        // Restore previous position
        bubble.style.left = `${savedOffset.left}px`;
        bubble.style.top  = `${savedOffset.top}px`;
      } else {
        // First-time placement
        const areaRect = AREA.getBoundingClientRect();
        const spanRect = span.getBoundingClientRect();
    
        const desiredFromArea = 400;
        const bubbleWidth = 100;
        const margin = 8;
    
        const bubbleLeftRelativeToSpan =
          areaRect.left + desiredFromArea - spanRect.left;
    
        const maxLeftRelativeToSpan =
          areaRect.right - bubbleWidth - spanRect.left - margin;
    
        const leftPos = Math.min(bubbleLeftRelativeToSpan, maxLeftRelativeToSpan);
        bubble.style.left = `${leftPos}px`;
    
        // top defaults to browser auto (usually 0)
      }
    
      // ---- If user starts editing immediately ----
      if (focusNow) {
        textEl.setAttribute("contenteditable", "plaintext-only");
        requestAnimationFrame(() => {
          textEl.focus();
          placeCaretEnd(textEl);
          enableEdit(textEl, bubble, span, id);
        });
      }
    
      return bubble;
    }
    
    

  
    function positionCommentBubble(span, bubble, offset = null) {
        const spanRect = span.getBoundingClientRect();
        const areaRect = AREA.getBoundingClientRect();
    
        const left = (spanRect.left - areaRect.left) + (offset?.left ?? 20);
        const top  = (spanRect.top  - areaRect.top)  + (offset?.top ?? -10);
    
        bubble.style.left = left + "px";
        bubble.style.top  = top + "px";
    }



    let COMMENT_OVERLAY = null;
    
    function createCommentOverlay() {
      if (!window.EditMode) return;    
      if (!AREA) return null;
    
      const parent = AREA.parentElement || document.body;
    
      // make parent able to position absolute children
      if (getComputedStyle(parent).position === "static") {
        parent.style.position = "relative";
      }
    
      if (!COMMENT_OVERLAY || !COMMENT_OVERLAY.isConnected) {
        const overlay = document.createElement("div");
        overlay.id = "commentOverlay";
    
        Object.assign(overlay.style, {
          position: "absolute",
          top: "0",
          left: "0",
          width: "100%",
          height: "100%",
          pointerEvents: "none",  // bubbles override individually
          zIndex: "3000"
        });
    
        parent.appendChild(overlay);
        COMMENT_OVERLAY = overlay;
      }
    
      return COMMENT_OVERLAY;
    }


    // ====== EDIT COMMENT TEXT ======
    function enableEdit(textEl, bubble, span, id) {
      if (!window.EditMode) return;
    
      let finished = false;
    
      // ⭐ Capture undo baseline BEFORE any edit happens
      const undoOldText = textEl.textContent;
      const undoOldOffset = {
        left: parseFloat(bubble.style.left) || 0,
        top:  parseFloat(bubble.style.top)  || 0
      };
    
      const finish = async (reason = "") => {
        if (finished) return;
        finished = true;
    
        console.log("💾 Finishing edit:", reason);
    
        const newText = textEl.textContent.trim();
        const newLeft = parseFloat(bubble.style.left) || 0;
        const newTop  = parseFloat(bubble.style.top)  || 0;
    
        const changed =
          newText !== undoOldText ||
          newLeft !== undoOldOffset.left ||
          newTop  !== undoOldOffset.top;
    
        // ⭐ Simplified UNDO for comment edit
        if (changed) {
          window.pushUndo({
            type: "comment-edit",
            undo: () => {
              // Restore old text
              textEl.textContent = undoOldText;
    
              // Restore old bubble position
              bubble.style.left = undoOldOffset.left + "px";
              bubble.style.top  = undoOldOffset.top  + "px";
    
              // Rebuild model entirely from DOM
              window.saveCurrentComments?.();
              syncReadonlyMirrorFromEditable?.();
            }
          });
        }
    
        // ============================================================
        // ORIGINAL LOGIC (Simplified delete branch now)
        // ============================================================
    
        if (!newText) {
          // ⭐ Undo baseline for comment delete
          window.pushUndo({
            type: "comment-delete",
            undo: () => {
              if (!span || !span.isConnected) return;
    
              const restored = createBubble(span, id, false);
    
              // restore text
              const t = restored.querySelector(".comment-text");
              if (t) t.textContent = undoOldText;
    
              // restore position
              restored.style.left = undoOldOffset.left + "px";
              restored.style.top  = undoOldOffset.top  + "px";
    
              // rebuild model
              window.saveCurrentComments?.();
              syncReadonlyMirrorFromEditable?.();
            }
          });
    
          // 🔻 actual delete
          bubble?.remove();
    
          if (Array.isArray(window.textmarks)) {
            window.textmarks = window.textmarks.filter(x => x.id !== id);
          }
    
          window.debouncedSaveTextMarks?.("commentDelete");
        }
    
        else {
          // Update full model from DOM (this is all we need)
          if (typeof window.saveCurrentComments === "function") {
            window.saveCurrentComments();
          }
    
          // Save new state
          window.debouncedSaveTextMarks?.("editComment");
        }
    
        // Cleanup UI state
        textEl.removeAttribute("contenteditable");
        setTextAreaInteraction(false);
    
        if (typeof syncReadonlyMirrorFromEditable === "function") {
          syncReadonlyMirrorFromEditable();
        }
    
        // Remove listeners
        document.removeEventListener("mousedown", handleOutsideClick, true);
        document.removeEventListener("touchstart", handleOutsideClick, true);
        textEl.removeEventListener("focusout", finishOnFocusOut, true);
      };
    
      const handleOutsideClick = e => {
        if (!bubble.contains(e.target)) finish("outsideClick");
      };
    
      const finishOnFocusOut = () => finish("focusout");
    
      // Activate editing mode
      textEl.addEventListener("focus", () => setTextAreaInteraction(true));
      textEl.addEventListener("blur", () => finish("blur"), { once: true });
    
      document.addEventListener("mousedown", handleOutsideClick, true);
      document.addEventListener("touchstart", handleOutsideClick, true);
      textEl.addEventListener("focusout", finishOnFocusOut, true);
    }
    

      // ====== UTIL ======
    function placeCaretEnd(el) {
        const r = document.createRange();
        r.selectNodeContents(el);
        r.collapse(false);
        const s = window.getSelection();
        s.removeAllRanges();
        s.addRange(r);
    }

    
    function removeHighlight(span, id) {
      if (!span) return;
    
      const parent = span.parentNode;
      if (!parent) return;
    
      // 🔹 Remove bubble *before* we capture children (we don't want to undo the bubble UI via this)
      const bubble = span.querySelector(".comment-hint");
      if (bubble) bubble.remove();
    
      // 🔹 Capture the exact child nodes that currently make up the highlighted content
      const childNodes = Array.from(span.childNodes); // text + <br> etc., no bubble
    
      // 🔹 Capture the relevant textmarks entry (if any)
      let savedEntry = null;
      if (Array.isArray(window.textmarks)) {
        savedEntry = window.textmarks.find(x => x.id === id);
      }
    
      // ⭐ UNDO: re-wrap the SAME nodes back into a <span.hl> at the same place
      if (typeof window.pushUndo === "function") {
        window.pushUndo({
          type: "highlight-remove",
          undo: () => {
            if (!parent || !parent.isConnected) return;
            if (!childNodes.length) return;
    
            // All child nodes must still be in the same parent to safely re-wrap
            const allStillThere = childNodes.every(node => node.parentNode === parent);
            if (!allStillThere) return;
    
            const firstNode = childNodes[0];
    
            // Create new span.hl and insert before the first child
            const restoredSpan = document.createElement("span");
            restoredSpan.className = "hl";
            restoredSpan.dataset.id = id;
    
            parent.insertBefore(restoredSpan, firstNode);
    
            // Move the ORIGINAL nodes back into the span (no text duplication)
            childNodes.forEach(node => {
              if (node.parentNode === parent) {
                restoredSpan.appendChild(node);
              }
            });
    
            // Restore textmarks entry
            if (savedEntry) {
              // Remove any existing entries with this id first
              if (Array.isArray(window.textmarks)) {
                window.textmarks = window.textmarks.filter(x => x.id !== id);
                window.textmarks.push(JSON.parse(JSON.stringify(savedEntry)));
              }
    
              // If this was a comment entry, restore the bubble UI
              if (savedEntry.type === "comment" && typeof createBubble === "function") {
                createBubble(restoredSpan, id, false);
              }
            }
    
            // Sync mirror if used
            if (typeof syncReadonlyMirrorFromEditable === "function") {
              syncReadonlyMirrorFromEditable();
            }
          }
        });
      }
    
      // 🔻 ACTUAL REMOVAL (no text touching, just unwrapping)
    
      childNodes.forEach(node => {
        parent.insertBefore(node, span); // move children out of span
      });
      span.remove(); // remove the wrapper
    
      // Remove from textmarks
      if (Array.isArray(window.textmarks)) {
        window.textmarks = window.textmarks.filter(entry => entry.id !== id);
      }
    
      // Optional mirror + save (like before)
      if (typeof syncReadonlyMirrorFromEditable === "function") {
        syncReadonlyMirrorFromEditable();
      }
    
      try {
        window.debouncedSaveTextMarks?.("removeHighlight");
      } catch (err) {
        console.error("❌ Failed to save after deletion:", err);
      }
    }



    // ====== DRAGGING with undo ======
    function makeDraggable(bubble, handle, id) {
      let startX, startY, startLeft, startTop, dragging = false;
    
      //NEW: undo baseline
      let undoOldLeft = 0;
      let undoOldTop  = 0;
    
      const start = (x, y) => {
        dragging = true;
        bubble.classList.add("dragging");
        setTextAreaInteraction(true);
    
        const parentRect = bubble.offsetParent.getBoundingClientRect();
        const bubbleRect = bubble.getBoundingClientRect();
    
        startLeft = bubbleRect.left - parentRect.left;
        startTop  = bubbleRect.top  - parentRect.top;
        startX = x;
        startY = y;
    
        //store undo baseline BEFORE any movement
        undoOldLeft = startLeft;
        undoOldTop  = startTop;
      };
    
      const move = (x, y) => {
        if (!dragging) return;
    
        const dx = x - startX;
        const dy = y - startY;
    
        const newLeft = Math.max(0, startLeft + dx);
        const newTop  = Math.max(-40, startTop + dy);
    
        bubble.style.left = `${newLeft}px`;
        bubble.style.top  = `${newTop}px`;
      };
    
      const stop = () => {
        if (!dragging) return;
        dragging = false;
        bubble.classList.remove("dragging");
        setTextAreaInteraction(false);
    
        const left = parseFloat(bubble.style.left) || 0;
        const top  = parseFloat(bubble.style.top)  || 0;
    
        //Only push undo if something actually moved
        if (left !== undoOldLeft || top !== undoOldTop) {
          window.pushUndo({
            type: "comment-move",
            undo: () => {
              // restore bubble position
              bubble.style.left = undoOldLeft + "px";
              bubble.style.top  = undoOldTop + "px";
    
              // restore in textmarks
              if (Array.isArray(window.textmarks)) {
                const entry = window.textmarks.find(
                  x => x.id === id && (x.type === "comment" || x.type === "highlight")
                );
                if (entry) {
                  entry.offset = { left: undoOldLeft, top: undoOldTop };
                }
              }
            }
          });
        }
    
        // ---- Persist offset using your normal pipeline ----
    
        // This updates ALL textmarks based on current DOM
        if (typeof window.saveCurrentComments === "function") {
          window.saveCurrentComments();
        }
    
        // Extra safety: update this entry
        if (Array.isArray(window.textmarks)) {
          const span = bubble.closest("span.hl");
          const entry = window.textmarks.find(
            x => x.id === id && (x.type === "comment" || x.type === "highlight")
          );
          if (entry) {
            entry.offset = { left, top };
            if (span) {
              entry.anchor = extractAnchorContext(span);
            }
          }
        }
    
        // Save to DB (debounced)
        window.debouncedSaveTextMarks?.("dragComment");
      };
    
      // ----- EVENTS -----
    
      // Mouse
      handle.addEventListener("mousedown", e => {
        if (!window.EditMode) return;
        e.preventDefault();
        start(e.clientX, e.clientY);
      });
      document.addEventListener("mousemove", e => move(e.clientX, e.clientY));
      document.addEventListener("mouseup", stop);
    
      // Touch
      handle.addEventListener("touchstart", e => {
        if (!window.EditMode) return;
        const t = e.touches[0];
        start(t.clientX, t.clientY);
      }, { passive: true });
    
      handle.addEventListener("touchmove", e => {
        const t = e.touches[0];
        move(t.clientX, t.clientY);
      }, { passive: true });
    
      handle.addEventListener("touchend", stop);
    }
    
  

    function setTextAreaInteraction(disabled) {
        const area = document.getElementById("myTextarea");
        if (!area) return;
        
        if (disabled) {
          area.style.userSelect = "none";
          area.classList.add("bubble-interaction-mode");
        } else {
          area.style.userSelect = "";
          area.classList.remove("bubble-interaction-mode");
        }
    }



// ====== SimHash ======

// ============================================================
// 📌 UNIVERSAL SIMHASH (text fingerprint, 64-bit) — HEX SAFE
// ============================================================
function simhash(str) {
  str = (str || "")
    .toLowerCase()
    .normalize("NFKC")
    .replace(/\s+/g, " ")
    .trim();

  const grams = [];
  for (let i = 0; i < str.length - 1; i++) {
    grams.push(str.slice(i, i + 2));
  }

  const bits = new Array(64).fill(0);

  for (const g of grams) {
    const h = hash64(g);
    for (let i = 0; i < 64; i++) {
      bits[i] += (h >> BigInt(i)) & 1n ? 1 : -1;
    }
  }

  let fp = 0n;
  for (let i = 0; i < 64; i++) {
    if (bits[i] > 0) fp |= (1n << BigInt(i));
  }

  return fp.toString(16);     // <-- HEX OUTPUT (fix)
}

function hash64(str) {
  let h = 0x9e3779b97f4a7c15n;
  for (let i = 0; i < str.length; i++) {
    h ^= BigInt(str.charCodeAt(i));
    h *= 0xbf58476d1ce4e5b9n;
    h ^= h >> 31n;
    h *= 0x94d049bb133111ebn;
  }
  return h & ((1n << 64n) - 1n);
}

function simhashDistance(a, b) {
  const x = BigInt("0x" + a) ^ BigInt("0x" + b);
  let dist = 0;
  let tmp = x;
  while (tmp) {
    tmp &= (tmp - 1n);
    dist++;
  }
  return dist;
}



// ====== ANCHOR CONTEXT ======



function extractAnchorContext(span) {
  if (!AREA || !span) return null;

  // 1) Canonical plain text
  const plain = buildCanonicalPlain(AREA);

  // 2) Extract actual highlighted text, normalized
  const clone = span.cloneNode(true);
  clone.querySelectorAll(".comment-hint").forEach(el => el.remove());
  const quote = clone.textContent || "";
  const qNorm = quote.replace(/[\u200B-\u200F\uFEFF]/g, "");

  // 3) Canonical start offset (first occurrence inside span)
  const start = findLocalizedAnchorIndex(span, qNorm, plain);
  const end = start !== -1 ? start + qNorm.length : -1;

  // 4) Build SimHash + mini prefix
  const mini = qNorm.slice(0, 12);
  const simfp = simhash(qNorm);
  const length = qNorm.length;

  return {
    mini,
    simfp,
    length,
    start,
    end,
    highlightAll: span.dataset.highlightAll === "true" ? true : false
  };
}





function normalizePlainForAnchor(html = "") {
  return (html || "")
    .replace(/<br\s*\/?>/gi, "\n")         // our only linebreak rule
    .replace(/&nbsp;/g, " ")               // canonical spaces
    .replace(/[\u200B-\u200F\uFEFF]/g, ""); // remove invisible chars
}




    window.normalizePlainForAnchor = normalizePlainForAnchor;
    


// Return the plain-text offset of the FIRST character of <span>
// Return the plain-text offset of the FIRST character of <span>
function findLocalizedAnchorIndex(span, quote, plainDocument) {
  if (!span) return -1;

  let pos = 0;
  let spanStartPos = -1;
  const walker = document.createTreeWalker(AREA, NodeFilter.SHOW_TEXT);

  // 1) Locate the first text node that lives inside this span
  while (walker.nextNode()) {
    const node = walker.currentNode;
    const text = node.nodeValue || "";

    if (span.contains(node)) {
      // This is the first text node inside <span>
      spanStartPos = pos;
      break;
    }

    pos += text.length;
  }

  // If we successfully mapped the span into canonical text
  if (spanStartPos !== -1) {
    // If we have a quote, try to align to the best match near this position
    if (quote && quote.length) {
      const plain = plainDocument || "";
      const candidates = [];
      let idx = plain.indexOf(quote);

      while (idx !== -1) {
        candidates.push(idx);
        idx = plain.indexOf(quote, idx + 1);
      }

      if (!candidates.length) {
        // No matching quote at all → fall back to raw span position
        return spanStartPos;
      }

      if (candidates.length === 1) {
        // Only one occurrence → safe
        return candidates[0];
      }

      // Multiple occurrences → choose the one closest to spanStartPos
      let best = candidates[0];
      let bestDist = Math.abs(best - spanStartPos);

      for (let i = 1; i < candidates.length; i++) {
        const d = Math.abs(candidates[i] - spanStartPos);
        if (d < bestDist) {
          bestDist = d;
          best = candidates[i];
        }
      }

      return best;
    }

    // No quote provided, but we still have a canonical DOM offset
    return spanStartPos;
  }

  // Absolute last resort: we couldn’t map DOM → plain at all
  if (quote && quote.length && plainDocument) {
    return plainDocument.indexOf(quote); // may be -1
  }

  return -1;
}


    


    /* ============================================================
       SAVE ALL ANNOTATIONS (TEXTMARKS → DB)
       ============================================================ */

    
/* ============================================================
   SAVE ALL ANNOTATIONS (TEXTMARKS → DB)
   ============================================================ */
function saveCurrentComments() {
  const AREA = document.getElementById("myTextarea");
  if (!AREA) return;

  const prevMarks = Array.isArray(window.textmarks) ? window.textmarks : [];

  // 1️⃣ Keep drawings as-is
  const drawings = prevMarks.filter(x => x.type === "drawing");

  // 2️⃣ Index previous entries by id
  const prevById = {};
  prevMarks.forEach(m => {
    if (m && m.id) prevById[m.id] = m;
  });

  const nextMarks = [...drawings];

  // Track which ids we successfully rebuilt from DOM
  const aliveIds = new Set();

  // 3️⃣ Rebuild highlights/comments from DOM
  AREA.querySelectorAll("span.hl").forEach(span => {
    // -----------------------------------------------------
    // SKIP cloned highlight-all spans (only visual copies)
    // -----------------------------------------------------
    if (span.dataset.hlClone === "true") {
      return;
    }

    const id = span.dataset.id;
    if (!id) return;

    aliveIds.add(id);

    const prev = prevById[id] || {};
    const bubble = span.querySelector(".comment-hint");

    const offset = {
      left: bubble ? parseFloat(bubble.style.left) || 0 : 0,
      top:  bubble ? parseFloat(bubble.style.top)  || 0 : 0
    };

    const text = bubble?.querySelector(".comment-text")?.textContent.trim() || "";
    const type = text ? "comment" : "highlight";

    const color =
      span.dataset.color ||
      span.style.backgroundColor ||
      prev.color ||
      null;

    // Always recompute anchor from current DOM so it matches edited text
    const anchor = extractAnchorContext(span) || prev.anchor || null;

    nextMarks.push({
      id,
      type,
      text,
      color,
      anchor,
      offset,
      highlightAll: span.dataset.highlightAll === "true",
      hlMaster: span.dataset.hlMaster === "true",
      hlClone: false,              // we never save clones
      owner: window.currentItemOwner,
      annotator: window.SESSION_USERNAME,
      surrogate: window.currentSurrogate
    });
  });

  // 4️⃣ Preserve any previous highlights/comments that did NOT make it into the DOM
  //    (e.g. anchor couldn't be restored, or temporary load issue)
  prevMarks.forEach(m => {
    if ((m.type === "highlight" || m.type === "comment") &&
        m.id && !aliveIds.has(m.id)) {
      nextMarks.push(m);
    }
  });

  window.textmarks = nextMarks;
}
    
        

    
    
    window.saveTextMarks = async function () {
      const surrogate = window.currentSurrogate;
      const owner     = window.currentItemOwner;
      const annotator = window.SESSION_USERNAME;
      
      if (!window.EditMode) return;
      
      //Must be logged in to save anything
      if (!window.SESSION_USERNAME) {
        showFlashMessage?.("Please login");
        return false;
      }
  
    
      if (!surrogate || !owner || !annotator) {
        console.warn("⚠️ Missing surrogate/owner/annotator for save");
        return;
      }
    
      // Ensure storage exists
      window.textmarks = window.textmarks || [];
    
      // 1️⃣ Sync highlights + comments from DOM
      saveCurrentComments();
    
      // 2️⃣ Sync drawings from DRAWINGS{} runtime cache
      saveCurrentDrawing();
    
      // 3️⃣ Prepare payload
      const payload = {
        surrogate,
        owner,
        annotator,
        comments: window.textmarks
      };
    
      console.log("💾 Saving textmarks (umbrella):", payload);
    
      // 4️⃣ Save to server
      try {
        const res = await fetch("/updateTextComments.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(payload)
        });
        
        if (res.ok) {
            showFlashMessage?.("✅ Comments adnd drawings saved");
        }
    
        if (!res.ok) {
          console.error("❌ Save failed:", res.status, res.statusText);
        }
      } catch (err) {
        console.error("❌ Save error:", err);
      }
    };
        


  // Debounced wrapper
  // was debouncedSaveUserComments
    let _saveTimer = null;
    window.debouncedSaveTextMarks = function (reason = "auto") {

      clearTimeout(_saveTimer);
      _saveTimer = setTimeout(() => {
        window.saveTextMarks()
          .then(() => console.log("💾 Debounced save ok:", reason))
          .catch(err => console.warn("⚠️ Debounced save failed:", err));
      }, 600);
    };


    function restoreOffsets() {
        if (!AREA) return;

        Object.entries(BUBBLE_OFFSETS).forEach(([id, pos]) => {
          const span = AREA.querySelector(`span.hl[data-id="${id}"]`);
          const bubble = span?.querySelector(".comment-hint");
          if (!bubble) return;
    
          let left = 0, top = 0;
    
          if (pos && typeof pos === "object") {
            left = Number(pos.left) || 0;
            top  = Number(pos.top)  || 0;
          } else if (!isNaN(parseFloat(pos))) {
            left = parseFloat(pos);
          }
    
          bubble.style.position = "absolute";
          bubble.style.left = `${left}px`;
          bubble.style.top  = `${top}px`;
          bubble.style.minHeight = "28px";
          bubble.style.zIndex = 3000;
    
          BUBBLE_OFFSETS[id] = { left, top };
        });
    }



    // window.loadUserComments = async function (owner, surrogate) {
    // //   const annotator = window.SESSION_USERNAME || "guest";        
    //     // 1) Do NOT load annotations if user is not logged in
    //     if (!window.SESSION_USERNAME) {
    //     //   console.log("🛈 No user logged in — skipping annotation fetch");
    //       window.textmarks = [];
    //       return; // <- STOP HERE
    //     }
        
    //     const annotator = window.SESSION_USERNAME;
        
    //   const AREA = document.getElementById("myTextarea");
    
    //   if (!AREA) return;
    
    //   // ============================================================
    //   // 1) CLEAN DOM: remove all UI-only elements
    //   // ============================================================
    //   AREA.querySelectorAll(".comment-hint").forEach(el => el.remove());
    //   AREA.querySelectorAll("span.hl").forEach(el => { el.outerHTML = el.innerHTML; });
    //   AREA.querySelectorAll("span.draw-anchor").forEach(el => el.remove());
    
    //   if (window.COMMENT_OVERLAY) {
    //     window.COMMENT_OVERLAY.innerHTML = "";
    //   }
    
    //   // ============================================================
    //   // 2) FETCH USER ANNOTATIONS (OFFLINE SAFE)
    //   // ============================================================
    //   const offlineKey = `offline-comments-${surrogate}-${annotator}`;
    //   let arr = [];
    
    //   if (!navigator.onLine) {
    //     // 📴 OFFLINE → Load cached JSON
    //     const raw = (localStorage.getItem(offlineKey) || "").trim();
    //     if (raw) {
    //       try {
    //         const parsed = JSON.parse(raw);
    //         arr = Array.isArray(parsed.comments) ? parsed.comments : [];
    //       } catch (e) {
    //         console.warn("⚠️ Offline comment JSON invalid:", e);
    //       }
    //     } else {
    //       console.log("📴 Offline: no cached annotations");
    //     }
    //     } else {
    //         // 🌐 ONLINE → fetch fresh JSON and cache it
    //         try {
    //             const url = `/getTextComments.php?surrogate=${surrogate}&annotator=${annotator}`;
    //             const res = await fetch(url);
        
    //             if (res.ok) {
    //                 const raw = (await res.text()).trim();
    //                 if (raw) {
    //                     const parsed = JSON.parse(raw);
    //                     arr = Array.isArray(parsed.comments) ? parsed.comments : [];
        
    //                     // Cache for offline
    //                     localStorage.setItem(offlineKey, raw);
    //                 }
    //             }
    //         } catch (err) {
    //             console.warn("⚠️ Comment fetch failed:", err);
    //         }
    //     }

    
    //   // Unified source of truth
    //   window.textmarks = arr;
    
    //   // Runtime drawings cache
    //   window.DRAWINGS = {};
    
    //   // ============================================================
    //   // 3) MATERIALIZE HIGHLIGHTS + COMMENTS + DRAWINGS
    //   // ============================================================
    //   for (const item of arr) {
    //     const { id, type, anchor, offset = { left: 0, top: 0 }, text = "", image, color } = item;
    
    //     // ---- HIGHLIGHT / COMMENT ----
    //     if (type === "highlight" || type === "comment") {
    //       const span = restoreSpanForAnchor(id, anchor, AREA);
    //       if (!span) continue;
    
    //       // Color (opacity 0.4 for readability)
    //       if (color) {
    //         span.dataset.color = color;
    //         span.style.backgroundColor = toRgba(color, 0.4);
    //       }
    
    //       // Comment bubble
    //       if (type === "comment") {
    //         const bubble = createBubble(span, id, false);
    //         const t = bubble.querySelector(".comment-text");
    //         if (t) t.textContent = text;
    
    //         bubble.style.left = offset.left + "px";
    //         bubble.style.top = offset.top + "px";
    //       }
    
    //       continue;
    //     }
    
    //     // ---- DRAWING ----
    //     if (type === "drawing" && image) {
    //       window.DRAWINGS[id] = { dataUrl: image, anchor, offset };
    
    //       if (typeof window.renderDrawingFromTextmarks === "function") {
    //         window.renderDrawingFromTextmarks(id, window.DRAWINGS[id], AREA);
    //       }
    
    //       continue;
    //     }
    //   }
    
    //   console.log("Loaded annotations:", arr.length);
    // };
    
    




window.loadUserComments = async function (owner, surrogate) {

  if (!window.SESSION_USERNAME) {
    window.textmarks = [];
    return;
  }

  const annotator = window.SESSION_USERNAME;
  const AREA = document.getElementById("myTextarea");
  if (!AREA) return;

  function clearUI() {
    AREA.querySelectorAll(".comment-hint").forEach(el => el.remove());
    AREA.querySelectorAll("span.hl").forEach(el => { el.outerHTML = el.innerHTML; });
    AREA.querySelectorAll("span.draw-anchor").forEach(el => el.remove());
    if (window.COMMENT_OVERLAY) window.COMMENT_OVERLAY.innerHTML = "";
  }

  clearUI();

  const cacheKey   = `offline-comments-${surrogate}-${annotator}`;
  const versionKey = `offline-comments-version-${surrogate}-${annotator}`;

  let arr = [];

  // ============================================================
  // 📴 OFFLINE → cache only
  // ============================================================
  if (!navigator.onLine) {
    const raw = localStorage.getItem(cacheKey);
    if (raw) {
      try { arr = JSON.parse(raw).comments || []; } catch {}
    }
    window.textmarks = arr;
    window.DRAWINGS = {};
    materializeCommentsAndDrawings(AREA, arr);
    return;
  }

  // ============================================================
  // 🌐 ONLINE
  // ============================================================
  const cachedRaw = localStorage.getItem(cacheKey);
  const cachedVer = localStorage.getItem(versionKey) || "";
  const hasCache  = !!cachedRaw;

  // 1️⃣ If we have cache → render instantly
  if (hasCache) {
    try {
      arr = JSON.parse(cachedRaw).comments || [];
      window.textmarks = arr;
      window.DRAWINGS = {};
      materializeCommentsAndDrawings(AREA, arr);
    } catch {}
  }

  // 2️⃣ Fetch from server, but if no cache we will show *only the server response* once
  fetch(`/getTextComments.php?surrogate=${surrogate}&annotator=${annotator}&v=${cachedVer}`)
    .then(async res => {
      const serverVer = res.headers.get("X-Comments-Version") || "";

      // If we *have* cache and version is same → no need to re-render
      if (hasCache && res.status === 304) return;

      // If we have NO cache and server says 304 → nothing to render
      if (!hasCache && res.status === 304) return;

      // Server has data
      const data = await res.json();
      const comments = data.comments || [];

      // Save cache
      localStorage.setItem(cacheKey, JSON.stringify(data));
      localStorage.setItem(versionKey, data.updated_at || serverVer);

      // If there was no cache, UI is still empty → now render
      clearUI();
      window.textmarks = comments;
      window.DRAWINGS = {};
      materializeCommentsAndDrawings(AREA, comments);
    })
    .catch(err => console.warn("⚠ comments fetch failed:", err));
};





    
    
    function materializeCommentsAndDrawings(AREA, arr) {
      for (const item of arr) {
        const { id, type, anchor, offset = { left: 0, top: 0 }, text = "", image, color } = item;
    
        if (type === "highlight" || type === "comment") {
          const span = restoreSpanForAnchor(id, anchor, AREA);
          if (!span) continue;
    
          if (color) {
            span.dataset.color = color;
            span.style.backgroundColor = toRgba(color, 0.4);
          }
    
          if (type === "comment") {
            const bubble = createBubble(span, id, false);
            const t = bubble.querySelector(".comment-text");
            if (t) t.textContent = text;
    
            bubble.style.left = offset.left + "px";
            bubble.style.top = offset.top + "px";
          }
    
          continue;
        }
    
        if (type === "drawing" && image) {
          window.DRAWINGS[id] = { dataUrl: image, anchor, offset };
          if (typeof window.renderDrawingFromTextmarks === "function")
            window.renderDrawingFromTextmarks(id, window.DRAWINGS[id], AREA);
        }
      }
    }
    
    

function toRgba(color, alpha = 1) {
  if (!color) return color;

  color = color.trim();

  // --- HEX: #rgb or #rrggbb ---
  if (color[0] === "#") {
    let hex = color.slice(1);

    // #rgb → #rrggbb
    if (hex.length === 3) {
      hex = hex.split("").map(ch => ch + ch).join("");
    }

    if (hex.length === 6) {
      const r = parseInt(hex.slice(0, 2), 16);
      const g = parseInt(hex.slice(2, 4), 16);
      const b = parseInt(hex.slice(4, 6), 16);
      if ([r, g, b].some(n => Number.isNaN(n))) return color;
      return `rgba(${r}, ${g}, ${b}, ${alpha})`;
    }

    // unsupported hex length → give up gracefully
    return color;
  }

  // --- rgb(...) or rgba(...) ---
  if (/^rgba?/i.test(color)) {
    // grab all numeric parts (r, g, b, [a])
    const nums = color.match(/[\d.]+/g);
    if (!nums || nums.length < 3) return color;

    const r = parseFloat(nums[0]);
    const g = parseFloat(nums[1]);
    const b = parseFloat(nums[2]);

    if ([r, g, b].some(n => Number.isNaN(n))) return color;

    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
  }

  // anything else (named colors etc.) → leave unchanged
  return color;
}




function buildCanonicalPlain(root) {
  let out = "";
  const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);

  while (walker.nextNode()) {
    out += walker.currentNode.nodeValue;
  }

  // Final sanitization only (no structural transforms)
  return out.replace(/[\u200B-\u200F\uFEFF]/g, "");
}






// Refines a plain-text offset using a local DOM-aware search.
// Attempts to find the exact location of `quote` within ~200 chars window.
function findLocalizedAnchorIndexFromPlainOffset(AREA, approx, quote, plain) {
  if (!quote) return approx;

  // define local search window
  const window = 200;
  const start = Math.max(0, approx - window);
  const end   = Math.min(plain.length, approx + window);

  const localSlice = plain.slice(start, end);

  const localIdx = localSlice.indexOf(quote);
  if (localIdx === -1) {
    // fallback to original function if available
    return findLocalizedAnchorIndex(AREA, quote, plain);
  }

  return start + localIdx;
}



function restoreSpanForAnchor(id, anchor, AREA) {
  if (!AREA || !anchor) return null;

  try {
    const plain = buildCanonicalPlain(AREA) || "";
    const textLen = plain.length;

    const {
      simfp,
      length,
      mini,
      start: storedStart,
      end: storedEnd,
      highlightAll
    } = anchor;

    if (!length || length <= 0) return null;

    // ============================================================
    // 0) **CANONICAL OFFSET FIRST — ABSOLUTE PRIORITY**
    // ============================================================
    if (
      typeof storedStart === "number" &&
      storedStart >= 0 &&
      typeof storedEnd === "number" &&
      storedEnd > storedStart &&
      storedEnd <= textLen
    ) {
      // If highlight-all → restore all occurrences based on canonical text
      if (highlightAll) {
        return restoreAllOccurrences(
          id,
          { ...anchor, start: storedStart, end: storedEnd },
          AREA,
          plain
        );
      }

      // Try building range exactly at the saved offsets
      const range = buildRangeFromPlainOffsets(AREA, storedStart, storedEnd);
      if (range) {
        return wrapSpan(id, range);
      }

      // If this fails, we STILL try local refinement
      // but ONLY around the storedStart ±200
      const quote = plain.slice(storedStart, storedEnd);
      const refined = findLocalizedAnchorIndexFromPlainOffset(
        AREA,
        storedStart,
        quote,
        plain
      );

      if (refined !== -1) {
        const r2 = buildRangeFromPlainOffsets(AREA, refined, refined + length);
        if (r2) return wrapSpan(id, r2);
      }

      // Only if both fail → fall through to SimHash fallback
    }

    // ============================================================
    // 1) If we have no fingerprint, restoration is impossible
    // ============================================================
    if (!simfp) return null;

    // ============================================================
    // 2) Mini-prefix fast path — BUT ONLY IF NOT A DUPLICATED WORD
    //    (i.e., matches must be unique)
    // ============================================================
    let predictedStart = -1;
    let quote = "";

    if (mini) {
      const allMatches = [];
      let idx = plain.indexOf(mini);
      while (idx !== -1) {
        allMatches.push(idx);
        idx = plain.indexOf(mini, idx + 1);
      }

      // Only use mini if UNIQUE
      if (allMatches.length === 1) {
        predictedStart = allMatches[0];
        quote = plain.slice(predictedStart, predictedStart + length);
      }
    }

    // ============================================================
    // 3) SimHash global sliding window — LAST RESORT
    // ============================================================
    if (predictedStart === -1 && textLen >= length) {
      let bestIdx = -1;
      let bestDist = 999;

      for (let i = 0; i <= textLen - length; i++) {
        const slice = plain.slice(i, i + length);
        const fp2 = simhash(slice);
        const d = simhashDistance(simfp, fp2);
        if (d < bestDist) {
          bestDist = d;
          bestIdx = i;
        }
      }

      if (bestIdx === -1 || bestDist > 8) {
        return null; // too different → can't restore
      }

      predictedStart = bestIdx;
      quote = plain.slice(predictedStart, predictedStart + length);
    }

    if (predictedStart === -1) return null;

    // ============================================================
    // 4) Local refinement around predictedStart ±200
    // ============================================================
    const refinedStart = findLocalizedAnchorIndexFromPlainOffset(
      AREA,
      predictedStart,
      quote,
      plain
    );

    const finalStart = refinedStart !== -1 ? refinedStart : predictedStart;
    const finalEnd = finalStart + length;

    if (finalStart < 0 || finalEnd > textLen) return null;

    // ============================================================
    // 5) highlight-all mode
    // ============================================================
    if (highlightAll) {
      return restoreAllOccurrences(
        id,
        { ...anchor, start: finalStart, end: finalEnd },
        AREA,
        plain
      );
    }

    // ============================================================
    // 6) Build DOM range
    // ============================================================
    const range = buildRangeFromPlainOffsets(AREA, finalStart, finalEnd);
    if (!range) return null;

    return wrapSpan(id, range);

  } catch (err) {
    console.warn("⚠️ restoreSpanForAnchor failed for id", id, err);
    return null;
  }
}


function restoreAllOccurrences(id, anchor, AREA, plain) {
  const { mini, length } = anchor;
  if (!mini || !length || length <= 0) return null;

  // Lookup color from textmarks
  let color = null;
  if (Array.isArray(window.textmarks)) {
    const entry = window.textmarks.find(x => x.id === id);
    if (entry && entry.color) color = entry.color;
  }

  let idx = 0;
  let firstSpan = null;

  while (true) {
    idx = plain.indexOf(mini, idx);
    if (idx === -1) break;

    const end = idx + length;
    const range = buildRangeFromPlainOffsets(AREA, idx, end);
    if (range) {

      const spanId = firstSpan
        ? "c" + Math.random().toString(36).slice(2, 8) // clone = new id
        : id;                                         // first = original id

      const span = wrapSpan(spanId, range);

      // Only the FIRST span keeps highlightAll=true
        if (!firstSpan) {
            // MASTER
            span.dataset.highlightAll = "true";
            span.dataset.hlMaster = "true";
            span.dataset.hlClone = "false";
        } else {
            // CLONE
            span.dataset.highlightAll = "false";
            span.dataset.hlMaster = "false";
            span.dataset.hlClone = "true";
        }


      // Color all occurrences
      if (color) {
        span.dataset.color = color;
        span.style.backgroundColor = toRgba(color, 0.4);
      }

      if (!firstSpan) firstSpan = span;
    }

    idx += Math.max(mini.length, 1);
  }

  return firstSpan;
}








function wrapSpan(id, range) {
  const span = document.createElement("span");
  span.className = "hl";
  span.dataset.id = id;
  wrapRangeSafely(range, span);
  return span;
}





function wrapRangeSafely(range, span) {
  const frag = range.extractContents();
  span.appendChild(frag);
  range.insertNode(span);
  return span;
}




    function buildRangeFromPlainOffsets(root, start, end) {
      const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
      let pos = 0;
      let startNode = null, endNode = null;
      let startOffset = 0, endOffset = 0;
    
      while (walker.nextNode()) {
        const node = walker.currentNode;
        const len = node.nodeValue.length;
    
        // find start node
        if (!startNode && start < pos + len) {
          startNode = node;
          startOffset = start - pos;
        }
    
        // find end node
        if (startNode && end <= pos + len) {
          endNode = node;
          endOffset = end - pos;
          break;
        }
    
        pos += len;
      }
    
      if (!startNode || !endNode) return null;
    
      const range = document.createRange();
      range.setStart(startNode, startOffset);
      range.setEnd(endNode, endOffset);
      return range;
    }


    function findLocalizedAnchorIndexByDOMPosition(AREA, quote, plain) {
      if (!quote) return -1;
    
      // find all matches
      const matches = [];
      let i = plain.indexOf(quote);
      while (i !== -1) {
        matches.push(i);
        i = plain.indexOf(quote, i + 1);
      }
      if (!matches.length) return -1;
      if (matches.length === 1) return matches[0];
    
      // find span's approximate location in DOM
      const domOffset = getApproximateDOMOffset(AREA);
      if (domOffset == null) return matches[0];
    
      let best = matches[0];
      let bestDist = Math.abs(matches[0] - domOffset);
    
      for (const m of matches) {
        const dist = Math.abs(m - domOffset);
        if (dist < bestDist) {
          best = m;
          bestDist = dist;
        }
      }
      return best;
    }



    
    function getApproximateDOMOffset(root) {
      const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
      let pos = 0;
      const spans = [];
    
      // get all highlight start nodes
      walker.currentNode = root;
      while (walker.nextNode()) {
        const node = walker.currentNode;
        if (node.parentNode.classList?.contains("hl")) {
          spans.push(pos);
        }
        pos += node.nodeValue.length;
      }
    
      // take average
      if (!spans.length) return null;
      return spans.reduce((a,b) => a+b, 0) / spans.length;
    }


    // -----------------------------------------------------------
    // Very small fuzzy locator (Levenshtein distance <= 2)
    // Used ONLY as last resort in restoreSpanForAnchor()
    // -----------------------------------------------------------
    function fuzzyLocateQuote(quote, plain) {
      if (!quote || !plain) return -1;
    
      const maxDistance = 2;
      const qlen = quote.length;
    
      for (let i = 0; i <= plain.length - qlen; i++) {
        const slice = plain.slice(i, i + qlen);
        if (levenshteinDistance(slice, quote) <= maxDistance) {
          return i;
        }
      }
    
      return -1;
    }

    // Tiny Levenshtein implementation (fast enough for short quotes)
    function levenshteinDistance(a, b) {
      const m = a.length, n = b.length;
      if (Math.abs(m - n) > 2) return 999;  // optimize: skip obvious mismatches
    
      const dp = Array.from({ length: m + 1 }, () =>
        new Array(n + 1).fill(0)
      );
    
      for (let i = 0; i <= m; i++) dp[i][0] = i;
      for (let j = 0; j <= n; j++) dp[0][j] = j;
    
      for (let i = 1; i <= m; i++) {
        for (let j = 1; j <= n; j++) {
          const cost = a[i - 1] === b[j - 1] ? 0 : 1;
          dp[i][j] = Math.min(
            dp[i - 1][j] + 1,        // deletion
            dp[i][j - 1] + 1,        // insertion
            dp[i - 1][j - 1] + cost  // substitution
          );
        }
      }
    
      return dp[m][n];
    }



  // Sync mirror
  function syncReadonlyMirrorFromEditable() {
    const editDiv   = document.getElementById("myTextarea");
    const mirrorDiv = document.getElementById("myTextarea2");
    if (!editDiv || !mirrorDiv) return;

    const clone = editDiv.cloneNode(true);
    clone.querySelectorAll(".comment-hint").forEach(el => el.remove());
    mirrorDiv.innerHTML = clone.innerHTML;
  }
  window.syncReadonlyMirrorFromEditable = syncReadonlyMirrorFromEditable;




 
  

})(); // end IIFE








// ====== SNAPSHOT TEXT STATE ======
function snapshotTextState() {
  const area = document.getElementById("myTextarea");
  if (area) LAST_TEXT_STATE = area.innerHTML;
}

// ============================================================
// 🧩 Modern Comment Palette (uses TEXTMARKS + drawing API)
// ============================================================

window.initCommentPalette = function () {
  if (document.getElementById("commentPalette")) return;

  const Palette = document.createElement("div");
  Palette.id = "commentPalette";

  Palette.innerHTML = `
    <div class="Palette-content">

      <div class="Palette-drag-handle" title="Move">
        <i data-lucide="move"></i>
      </div>

      <div class="Palette-section text">
        <button class="Palette-btn" data-tool="write">
          <i data-lucide="text"></i><span>Write</span>
        </button>
        <button class="Palette-btn" data-format="bold">
          <i data-lucide="bold"></i><span>Bold</span>
        </button>
        <button class="Palette-btn" data-format="italic">
          <i data-lucide="italic"></i><span>Italic</span>
        </button>
        <button class="Palette-btn" data-format="underline">
          <i data-lucide="underline"></i><span>Underline</span>
        </button>
      </div>

      <div class="Palette-section tools">
        
        <div class="Palette-highlight-wrapper" style="position:relative;">
          <button class="Palette-btn" data-tool="highlight">
            <i data-lucide="highlighter"></i><span>Highlight</span>
          </button>
        
            <div id="highlightOptionsPanel_comments" class="pen-options hidden">
              <label class="pen-label">Color:</label>
              <input type="color" id="highlightColorInline_comments" value="#ffeb3b">
            
              <label class="hl-all-toggle" style="display:flex; align-items:center; gap:5px; margin-top:1px;">
                <input type="checkbox" id="hlAllToggle" style="margin:0;">
                <span>all</span>
              </label>
            </div>

        </div>

        
        <div class="Palette-comment-wrapper" style="position:relative;">
          <button class="Palette-btn" data-tool="comment">
            <i data-lucide="message-square"></i><span>Comment</span>
          </button>
        
          <div id="commentOptionsPanel" class="pen-options hidden">
            <label class="pen-label">Color:</label>
            <input type="color" id="commentColorInline" value="#4aa3ff">
          </div>
        </div>

        
        <div class="Palette-draw-wrapper" style="position:relative;">
          <button class="Palette-btn" data-tool="draw">
            <i data-lucide="pencil"></i><span>Draw</span>
          </button>
            <div id="drawOptionsPanel" class="pen-options hidden">
            
              <div class="pen-option-block">
                <label class="pen-label">Width:</label>
                <input type="range" id="drawWidthInline" min="1" max="12" step="0.5" value="2">
              </div>
            
              <div class="pen-option-block">
                <label class="pen-label">Color:</label>
                <input type="color" id="drawColorInline" value="#f4511e">
              </div>
            </div>

        </div>

      </div>

      <div class="Palette-section actions">
        <button class="Palette-btn" data-action="save">
          <i data-lucide="save"></i><span>Save</span>
        </button>
        <button class="Palette-btn" data-action="delete">
          <i data-lucide="trash-2"></i><span>Delete</span>
        </button>
        <button class="Palette-btn" data-action="undo">
          <i data-lucide="rotate-ccw"></i><span>Undo</span>
        </button>
        <button class="Palette-btn" data-action="refresh">
          <i data-lucide="refresh-ccw"></i><span>Refresh</span>
        </button>
      </div>

    </div>
  `;

  document.body.appendChild(Palette);
  
  enableDynamicPaletteFlip(Palette);

  if (typeof window.enablePaletteDragging === "function") {
    window.enablePaletteDragging(Palette);
  }
  if (typeof window.enableDynamicPaletteFlip === "function") {
    window.enableDynamicPaletteFlip();
  }

  const toolButtons = Palette.querySelectorAll(".Palette-btn[data-tool]");
  window.activeTextTool = "write";

  toolButtons.forEach(btn => {
    btn.addEventListener("click", e => {
      e.stopPropagation();
      const tool = btn.dataset.tool;

      toolButtons.forEach(b => b.classList.remove("active"));
      btn.classList.add("active");

      window.activeTextTool = tool;
      window.updateTouchActionForMode?.();

      if (tool === "draw") {
        window.enableDrawingMode?.(true);
      } else {
        window.enableDrawingMode?.(false);
      }
    //Reset highlight-all toggle when switching to other tools
      if (tool !== "highlight") {
        const toggle = document.getElementById("hlAllToggle");
        if (toggle) toggle.checked = false;
      }      
      
    });
  });

  // ACTION BUTTONS
  Palette.querySelectorAll(".Palette-btn[data-action]").forEach(btn => {
    btn.addEventListener("click", e => {
      e.stopPropagation();
      const action = btn.dataset.action;

      switch (action) {
        case "refresh":
          window.refreshText?.();
          break;

        case "save":
          window.saveTextMarks?.();
          break;

        case "delete":
          window.deleteCommentsForCurrentText?.();
          break;

        case "undo":
          window.undoLastAction?.();
          break;

        case "toggle-visibility":
          window.toggleCommentVisibility?.();
          break;
      }
    });
  });

  // TEXT FORMATTING
  Palette.querySelectorAll(".Palette-btn[data-format]").forEach(btn => {
    btn.addEventListener("click", e => {
      e.stopPropagation();
      const format = btn.dataset.format;
      const area = document.getElementById("myTextarea");
      if (!area) return;
      area.focus();
      try {
        document.execCommand(format, false, null);
        window.syncReadonlyMirrorFromEditable?.();
      } catch (err) {
        console.warn("⚠ formatting failed:", err);
      }
      btn.classList.add("active");
      setTimeout(() => btn.classList.remove("active"), 180);
    });
  });

  (function activateLucideIcons() {
    if (window.lucide && typeof window.lucide.createIcons === "function") {
      window.lucide.createIcons({ icons: window.lucide.icons });
    } else {
      setTimeout(activateLucideIcons, 300);
    }
  })();

  Palette.querySelector('[data-tool="write"]')?.classList.add("active");
  
    //  -------------------------
    
    
    
// =========================================
// COMMENT PALETTE TOOL PANEL CONTROLLER
// =========================================

// Register the tool -> panel map (PDF style)
const commentToolPanels = {
  highlight: document.getElementById("highlightOptionsPanel_comments"),
  // later: draw: document.getElementById("drawOptionsPanel_comments")
};

// Clicking tools toggles the correct panel (PDF logic)
toolButtons.forEach(btn => {
  btn.addEventListener("click", e => {
    e.stopPropagation();
    const tool = btn.dataset.tool;

    // Close ALL panels (PDF logic)
    Object.values(commentToolPanels).forEach(p => p?.classList.add("hidden"));

    // Open the selected tool's panel
    if (commentToolPanels[tool]) {
      commentToolPanels[tool].classList.toggle("hidden");
    }
  });
});



// =========================================
// COMMENT HIGHLIGHTER COLOR PICKER (PDF style)
// =========================================

function attachColorPicker({
  palette,
  tool,            // e.g. "highlight", "comment", "draw"
  panelId,         // the popout div ID
  inputId,         // the <input type=color> ID
  storageKey,      // e.g. "commentColor"
  defaultColor     // fallback
}) {

  const btn   = palette.querySelector(`.Palette-btn[data-tool="${tool}"]`);
  const panel = document.getElementById(panelId);
  const input = document.getElementById(inputId);

  if (!btn || !panel || !input) return;

  // 1) Restore saved color
  const saved = localStorage.getItem(storageKey) || defaultColor;
  input.value = saved;

  // global value
  const varName = "current" + tool.charAt(0).toUpperCase() + tool.slice(1) + "Color";
  window[varName] = saved;

  // show color in active state
  btn.style.setProperty("--accent-color", saved);

  // 2) Open popout
  btn.addEventListener("click", (e) => {
    e.stopPropagation();
    document.querySelectorAll(".pen-options").forEach(p => p.classList.add("hidden"));
    panel.classList.toggle("hidden");
  });

  // 3) Store + apply color
  input.addEventListener("input", (e) => {
    const color = e.target.value;

    window[varName] = color;
    localStorage.setItem(storageKey, color);
    btn.style.setProperty("--accent-color", color);
  });

  // 4) Close popout on outside click
  document.addEventListener("click", (e) => {
    if (!palette.contains(e.target)) panel.classList.add("hidden");
  });
}



attachColorPicker({
  palette: Palette,
  tool: "highlight",
  panelId: "highlightOptionsPanel_comments",
  inputId: "highlightColorInline_comments",
  storageKey: "commentHighlightColor",
  defaultColor: "#ffeb3b"
});

attachColorPicker({
  palette: Palette,
  tool: "comment",
  panelId: "commentOptionsPanel",
  inputId: "commentColorInline",
  storageKey: "commentColor",
  defaultColor: "#4aa3ff"
});


// DRAW COLOR PICKER
attachColorPicker({
  palette: Palette,
  tool: "draw",
  panelId: "drawOptionsPanel",
  inputId: "drawColorInline",
  storageKey: "drawColor",
  defaultColor: "#f4511e"
});



// ---------------------
// DRAW WIDTH
const drawWidth = document.getElementById("drawWidthInline");
window.currentDrawWidth = parseFloat(localStorage.getItem("drawWidth") || 2);
drawWidth.value = window.currentDrawWidth;

drawWidth.addEventListener("input", (e) => {
  window.currentDrawWidth = parseFloat(e.target.value);
  localStorage.setItem("drawWidth", window.currentDrawWidth);
});


  
  
};




// ====== DELETE ALL COMMENTS/HIGHLIGHTS/Drawings ======
window.deleteCommentsForCurrentText = async function () {
//   snapshotTextState();
    window.snapshotLastTextState?.("before-delete-comments");

  const surrogate = window.currentSurrogate;
  const owner = window.currentItemOwner;
  const annotator = window.SESSION_USERNAME;

  if (!surrogate || !annotator) return;

  const confirmed = await showConfirm("🗑 Delete ALL annotations (highlights, comments, drawings)?");
  if (!confirmed) return;

  try {
    // 1) Server delete
    await fetch("/deleteTextComments.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ surrogate, owner, annotator })
    });

    // 2) Remove all annotation DOM
    const area = document.getElementById("myTextarea");
    if (area) {
      // remove comment bubbles + highlights
        area.querySelectorAll(".comment-hint, span.hl").forEach(el => {
          if (el.classList.contains("comment-hint")) {
            // Just remove the bubble UI
            el.remove();
          } else {
            // unwrap highlight span but keep all its children (text + <br> etc.)
            const parent = el.parentNode;
            if (!parent) return;
            while (el.firstChild) {
              parent.insertBefore(el.firstChild, el);
            }
            el.remove();
          }
        });


      // remove drawing anchors & bubbles
      area.querySelectorAll("span.draw-anchor").forEach(el => el.remove());
      document.querySelectorAll(".drawing-bubble").forEach(el => el.remove());
    }

    // 3) Clear runtime caches
    window.DRAWINGS = {};  
    window.textmarks = []; // <-- unified model

    // 4) Mirror (if used)
    const mirrorDiv = document.getElementById("myTextarea2");
    if (mirrorDiv && area) mirrorDiv.innerHTML = area.innerHTML;

    // 5) Refresh item to ensure clean state
    if (window.currentSurrogate) {
      window.selectItem?.(window.currentSurrogate, window.currentListToken);
    }

    showFlashMessage?.("✅ All annotations deleted");
  } catch (err) {
    console.error("❌ Delete comments failed:", err);
    showFlashMessage?.("⚠️ Could not delete comments");
  }
};



// === 👁 TOGGLE SHOW/HIDE COMMENTS (bubbles only) ===
window.toggleCommentVisibility = function () {
  const area = document.getElementById("myTextarea");
  if (!area) return;

  const btn = document.querySelector('.Palette-btn[data-action="toggle-visibility"]');
  const hidden = area.classList.toggle("comments-hidden");

  area.querySelectorAll(".comment-hint").forEach(bubble => {
    bubble.style.display = hidden ? "none" : "flex";
  });

  const icon = btn?.querySelector("i[data-lucide]");
  const label = btn?.querySelector("span");
  if (icon) {
    icon.setAttribute("data-lucide", hidden ? "eye-off" : "eye");
    window.lucide?.createIcons();
  }
  if (label) label.textContent = hidden ? "Show comments" : "Hide cmts";

  console.log(hidden ? "🙈 Comments hidden" : "👁 Comments visible");
};

// ============================================================
// 🔧 Update touch behavior
// ============================================================


function updateTouchActionForMode() {
  const area = document.getElementById("myTextarea");
  if (!area) return;

  const isEditing = document.body.classList.contains("edit-mode");
  const tool = window.activeTextTool || "write";

  if (!isEditing) {
    area.style.touchAction  = "auto";
    area.style.userSelect   = "text";
    area.contentEditable    = "false";
    return;
  }

  // ✏ WRITE MODE — normal behavior
  if (tool === "write") {
    area.style.touchAction  = "auto";
    area.style.userSelect   = "text";
    area.contentEditable    = "true";
    return;
  }

  // 🖍 HIGHLIGHT / COMMENT MODE — EASY SELECTION (our old fix!)
  if (tool === "highlight" || tool === "comment") {
    area.style.touchAction  = "none";      // prevents scrolling, allows selection
    area.style.userSelect   = "text";      // allow highlight selection
    area.contentEditable    = "false";     // CRITICAL — enables pure selection mode
    return;
  }

  // 🎨 DRAW — scroll but no selection
  if (tool === "draw") {
    area.style.touchAction  = "auto";
    area.style.userSelect   = "none";
    area.contentEditable    = "false";
    return;
  }
}
window.updateTouchActionForMode = updateTouchActionForMode;


// ============================================================
// 📱 Smart Touch Selection Engine for Highlight/Comment Mode
// (Restores easy mobile text selection we previously solved)
// ============================================================
function enableSmartTouchSelection(area) {

  let startRange = null;
  let startX = 0, startY = 0;
  let selecting = false;

  const MIN_X_DRAG = 6;         // horizontal drag threshold (px)
  const HORIZ_BIAS = 1.6;       // must be more horizontal than vertical

  area.addEventListener("touchstart", e => {
    const tool = window.activeTextTool;
    const isEditing = document.body.classList.contains("edit-mode");

    if (!isEditing || (tool !== "highlight" && tool !== "comment")) return;
    if (e.target.closest(".comment-hint")) return;

    const t = e.touches[0];
    startX = t.clientX;
    startY = t.clientY;

    const r = caretRangeFromPointCompat(t.clientX, t.clientY);
    if (!r) return;

    startRange = r;

    const sel = getSelection();
    sel.removeAllRanges();
    sel.addRange(r);

    selecting = false;  
  }, { passive: true });

  area.addEventListener("touchmove", e => {
    if (!startRange) return;

    const tool = window.activeTextTool;
    if (tool !== "highlight" && tool !== "comment") return;

    const t = e.touches[0];
    const dx = t.clientX - startX;
    const dy = t.clientY - startY;

    // Not yet selecting — evaluate gesture intent
    if (!selecting) {
      // Must be horizontal-biased drag
      if (Math.abs(dx) > MIN_X_DRAG && Math.abs(dx) > Math.abs(dy) * HORIZ_BIAS) {
        selecting = true;
      } else {
        // Not selecting yet → allow scroll
        return;
      }
    }

    // Once selecting, block scroll
    e.preventDefault();

    const end = caretRangeFromPointCompat(t.clientX, t.clientY);
    if (!end) return;

    const sel = getSelection();
    sel.removeAllRanges();

    const r = document.createRange();
    r.setStart(startRange.startContainer, startRange.startOffset);
    r.setEnd(end.endContainer, end.endOffset);
    sel.addRange(r);
  }, { passive: false });

  area.addEventListener("touchend", () => {
    startRange = null;
    selecting = false;
  });
}

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
