logStep("JSCommon.js executed");

// ===============================
// Generic Progress Spinner (Init + Reuse)
// ===============================

window.initialiseProgressSpinner = function (title = "Processing...") {
  // ðŸ§© Avoid duplicates
  if (document.getElementById("progressOverlay")) return;

  const overlay = document.createElement("div");
  overlay.id = "progressOverlay";
  overlay.className = "progress-overlay";

  overlay.innerHTML = `
    <svg viewBox="0 0 100 100" width="100" height="100">
      <circle cx="50" cy="50" r="46"
              stroke="#ddd" stroke-width="4" fill="none"></circle>
      <circle id="progressSpinner" cx="50" cy="50" r="46"
              stroke="#00c27a" stroke-width="4" fill="none"
              stroke-linecap="round" transform="rotate(-90 50 50)"></circle>
      <text id="progressSpinnerText" x="50" y="55"
            text-anchor="middle" font-size="18" fill="#333">0%</text>
      <text id="progressSpinnerTitle" x="50" y="75"
            text-anchor="middle" font-size="14" fill="#888">${title}</text>
    </svg>
  `;

  document.body.appendChild(overlay);
};


// ==============================
// Generic Progress Spinner Helper
// ==============================

window.updateProgressSpinner = function (percent, titleText = null) {
  const circle = document.getElementById("progressSpinner");
  const text = document.getElementById("progressSpinnerText");
  const title = document.getElementById("progressSpinnerTitle");

  if (!circle || !text) return;

  // Clamp and normalize
  let p = Number(percent);
  if (!Number.isFinite(p)) p = 0;
  p = Math.max(0, Math.min(100, p));

  const r = 46;
  const c = 2 * Math.PI * r;

  // Initialize stroke length once
  if (!circle._dashInit) {
    circle.style.strokeDasharray = c;
    circle._dashInit = true;
  }

  // Update stroke + text
  const offset = c - (p / 100) * c;
  circle.style.strokeDashoffset = offset;
  text.textContent = Math.round(p) + "%";

  // Optional title update (e.g., "Caching", "Uploading", etc.)
  if (titleText && title) title.textContent = titleText;
};


// ==============================
// Hide / Reset Progress Spinner
// ==============================

window.hideProgressSpinner = function (delay = 200) {
  if (window.progressTimerInterval) {
    clearInterval(window.progressTimerInterval);
    window.progressTimerInterval = null;
  }
  window.progressStartTime = null;

  const overlay = document.getElementById("progressOverlay");
  if (!overlay) return;

  overlay.style.opacity = "0";
  overlay.style.transition = "opacity 0.25s ease";

  setTimeout(() => overlay.remove(), delay);
  
};



// ============================================================
// 🧩 Minimal Universal Confirmation Dialog
// ============================================================
window.showConfirm = function (message = "Are you sure?") {
  return new Promise(resolve => {
    // Remove any existing dialog
    document.getElementById("simpleConfirmOverlay")?.remove();

    // --- Overlay ---
    const overlay = document.createElement("div");
    overlay.id = "simpleConfirmOverlay";
    overlay.style.cssText = `
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,0.6);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 12000;
    `;

    // --- Box ---
    const box = document.createElement("div");
    box.style.cssText = `
      background: #1e1e1e;
      color: #eee;
      padding: 22px 28px;
      border-radius: 10px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.4);
      max-width: 320px;
      text-align: center;
      font-family: system-ui, sans-serif;
    `;
    box.innerHTML = `
      <p style="margin:0 0 16px;font-size:15px;line-height:1.4;">
        ${message}
      </p>
      <div style="display:flex;gap:12px;justify-content:center;">
        <button id="confirmYesBtn"
                style="background:#00c27a;color:#fff;border:none;
                       border-radius:6px;padding:8px 16px;cursor:pointer;
                       font-weight:600;min-width:90px;">
          OK
        </button>
        <button id="confirmCancelBtn"
                style="background:#555;color:#fff;border:none;
                       border-radius:6px;padding:8px 16px;cursor:pointer;
                       font-weight:600;min-width:90px;">
          Cancel
        </button>
      </div>
    `;

    overlay.appendChild(box);
    document.body.appendChild(overlay);

    // --- Handlers ---
    const cleanup = (val) => {
      overlay.remove();
      resolve(val);
    };

    box.querySelector("#confirmYesBtn").onclick = e => { e.stopPropagation(); cleanup(true); };
    box.querySelector("#confirmCancelBtn").onclick = e => { e.stopPropagation(); cleanup(false); };
    overlay.onclick = e => { if (e.target === overlay) cleanup(false); };

    document.addEventListener("keydown", function onEsc(ev) {
      if (ev.key === "Escape") {
        document.removeEventListener("keydown", onEsc);
        cleanup(false);
      }
    });
  });
};





