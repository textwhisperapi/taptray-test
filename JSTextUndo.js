/* ============================================================
   TextWhisper — Unified Undo System (2025)
   ------------------------------------------------------------
   Supports ANY reversible UI action:
     • drawing stroke
     • drawing bubble move
     • comment bubble move
     • comment text edit
     • highlight add/remove
     • comment add/remove
     • typing
     • future tools…

   Architecture:
     window.UndoStack = [ { type, undo:Function, redo?:Function } ]
   Reversible and tool-agnostic.
   ============================================================ */

(function () {

  // ---- GLOBAL STACK ---------------------------------------------------------

  window.UndoStack = window.UndoStack || [];

  // ---- API ------------------------------------------------------------------

  /**
   * Push a reversible action into the undo stack.
   *
   * Usage:
   *   pushUndo({
   *     type: "drawing-move",
   *     undo: () => { ... revert this change ... }
   *   });
   *
   * @param {Object} action
   * @param {String} action.type - action category (for debugging/logging)
   * @param {Function} action.undo - how to revert
   * @param {Function} [action.redo] - optional redo
   */
  window.pushUndo = function (action) {
    if (!action || typeof action.undo !== "function") {
      console.warn("❌ pushUndo: invalid action", action);
      return;
    }
    window.UndoStack.push(action);
    // console.log("UNDO PUSH:", action.type);
  };


  /**
   * Undo last behavior.
   * This is the new core undo entry point.
   */
  window.undoLastAction = function () {
    if (!Array.isArray(window.UndoStack) || window.UndoStack.length === 0) {
      showFlashMessage?.("⚠️ Nothing to undo");
      return;
    }

    const action = window.UndoStack.pop();
    try {
      action.undo();
      showFlashMessage?.("↶ Undo");
      // console.log("UNDO:", action.type);
    } catch (err) {
      console.error("❌ Undo failed:", err, action);
      showFlashMessage?.("⚠️ Undo failed");
    }
  };


  /**
   * Backwards-compat alias for the toolbar / palette.
   * The Undo button can continue calling this, or you can
   * point it directly to undoLastAction if you prefer.
   */
  window.undoLastTextChange = function () {
    window.undoLastAction();
  };


  // ---------------------------------------------------------------------------
  // OPTIONAL FUTURE: REDO SYSTEM
  // Keep a second stack, push undone actions.
  // ---------------------------------------------------------------------------

  // window.RedoStack = [];
  // window.redoLastAction = function () { ... }

})();
