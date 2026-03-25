document.addEventListener("DOMContentLoaded", () => {
  const toggle = document.getElementById("colorSettingsToggle");
  if (!toggle) return;
  const DEFAULT_APPEARANCE = {
    skin_preset: "legacy-dark",
    pattern_base: "melody",
    pattern_size: 40,
    top_banner_url: "",
    greeting_text: ""
  };

  // Inject floating panel once
  if (!document.getElementById("colorSettingsPanel")) {
    document.body.insertAdjacentHTML("beforeend", `
      <div id="colorSettingsPanel" style="display:none;position:fixed;top:60px;right:10px;width:340px;max-width:min(95vw, 340px);max-height:calc(100vh - 80px);overflow-y:auto;
        background:rgba(30,30,30,0.95);border:1px solid #444;border-radius:12px;padding:12px;z-index:20000;
        box-shadow:0 4px 12px rgba(0,0,0,0.5);color:#fff;">
        <div style="display:flex;justify-content:space-between;align-items:center;">
          <strong>🎨 Color Settings</strong>
          <button id="closeColorPanel" style="background:none;border:none;color:#aaa;font-size:18px;">✕</button>
        </div>
        <hr style="border-color:#555;">
        <div id="patternPreview" class="sidebar-section-header collapsible-group"
             style="position:relative;margin-bottom:12px;min-height:104px;padding:14px 12px;text-align:left;font-size:1rem;font-weight:bold;overflow:hidden;cursor:pointer;">
          <div style="position:relative;z-index:2;display:flex;flex-direction:column;gap:8px;width:100%;">
            <div style="font-size:13px;letter-spacing:0.04em;text-transform:uppercase;opacity:0.92;">Preview Header</div>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
              <button id="groupBannerSelectBtn" type="button" class="btn btn-sm btn-outline-light" style="white-space:nowrap;">Set banner image</button>
              <button id="groupBannerClearBtn" type="button" class="btn btn-sm btn-outline-light" style="white-space:nowrap;">Clear</button>
              <span style="font-size:11px;line-height:1.25;opacity:0.92;">Paste, drop, or click here</span>
            </div>
          </div>
        </div>
        <label class="form-label mt-2">Skin</label>
        <div class="skin-picker-bar in-panel" id="skinPickerBarPanel">
          <button class="skin-swatch" data-skin="legacy-dark" title="Legacy Dark"></button>
          <button class="skin-swatch" data-skin="silver" title="Silver"></button>
          <button class="skin-swatch" data-skin="gold" title="Gold"></button>
          <button class="skin-swatch" data-skin="blue" title="Blue"></button>
          <button class="skin-swatch" data-skin="rose" title="Rose"></button>
          <button class="skin-swatch" data-skin="green" title="Green"></button>
          <button class="skin-swatch" data-skin="red" title="Red"></button>
          <button class="skin-swatch" data-skin="purple" title="Purple"></button>
        </div>
        <label for="patternBaseSelect" class="form-label mt-2 mb-1">Pattern Base</label>
        <select id="patternBaseSelect" class="form-select form-select-sm bg-dark text-light">
          <option value="default">Default</option> 
          <option value="none">None</option>
          <option value="dots">Dots</option>
          <option value="lines">Lines</option>
          <option value="grid">Grid</option>
          <option value="waves">Waves</option>
          <option value="hearts">Hearts</option>
          <option value="flowers">Flowers (lines)</option>
          <option value="music">Music Notes</option>
          <option value="melody">Melody</option>
        </select>
        <label for="patternSizeSlider" class="form-label mt-1 mb-1">Pattern Size</label>
        <input type="range" id="patternSizeSlider" min="10" max="100" step="1" value="40" class="form-range">
        <input id="groupBannerFileInput" type="file" accept="image/*" hidden>
        <input id="groupBannerUrlInput" type="text" class="form-control form-control-sm bg-dark text-light"
               placeholder="Or paste image URL or /path/to/image.jpg"
               autocomplete="off" spellcheck="false">
        <div style="margin-top:6px;font-size:11px;color:#b8bec8;line-height:1.35;">
          Banner applies to the top TapTray group headers only.
        </div>
        <label for="groupGreetingTextInput" class="form-label mt-3 mb-1">Greeting</label>
        <input id="groupGreetingTextInput" type="text" class="form-control form-control-sm bg-dark text-light"
               placeholder="Welcome, Dinner menu, Featured, ..."
               maxlength="80" autocomplete="off" spellcheck="false">
      </div>
    `);
  }

  const sizeSlider  = document.getElementById("patternSizeSlider");
  const baseSel     = document.getElementById("patternBaseSelect");
  const closeBtn    = document.getElementById("closeColorPanel");
  const patternPreview = document.getElementById("patternPreview");
  const skinPicker  = document.getElementById("skinPickerBar");
  const skinPickerPanel  = document.getElementById("skinPickerBarPanel");
  const bannerDropzone = patternPreview;
  const bannerFileInput = document.getElementById("groupBannerFileInput");
  const bannerSelectBtn = document.getElementById("groupBannerSelectBtn");
  const bannerClearBtn = document.getElementById("groupBannerClearBtn");
  const bannerInput = document.getElementById("groupBannerUrlInput");
  const greetingTextInput = document.getElementById("groupGreetingTextInput");
  let currentAppearance = { ...DEFAULT_APPEARANCE };
  let saveTimer = null;
  const BANNER_R2_UPLOAD_BASE = "https://r2-worker.textwhisper.workers.dev";
  const BANNER_R2_PUBLIC_BASE = "https://pub-1afc23a510c147a5a857168f23ff6db8.r2.dev";

  // Toggle panel
  toggle.addEventListener("click", (e) => {
    e.preventDefault();
    e.stopPropagation();
    const panel = document.getElementById("colorSettingsPanel");
    panel.style.display = panel.style.display === "none" ? "block" : "none";
    bootstrap.Dropdown.getInstance(toggle.closest(".dropdown")?.querySelector("[data-bs-toggle='dropdown']"))?.hide();
  });
  closeBtn.addEventListener("click", () => (document.getElementById("colorSettingsPanel").style.display = "none"));

  // Helpers
  const svgUrl = (svg) => `url("data:image/svg+xml;utf8,${encodeURIComponent(svg)}")`;
  const patterns = {
    none: () => "none",
    dots: () => `repeating-radial-gradient(circle, hsla(0,0%,100%,0.5) 3px, transparent 4px)`,
    lines: () => `repeating-linear-gradient(45deg, hsla(0,0%,100%,0.4) 2px, transparent 6px)`,
    grid: (s) =>
      `repeating-linear-gradient(0deg, hsla(0,0%,100%,0.3) 2px, transparent ${s}px),
       repeating-linear-gradient(90deg, hsla(0,0%,100%,0.3) 2px, transparent ${s}px)`,
    waves: (s) =>
      svgUrl(`<svg xmlns="http://www.w3.org/2000/svg" width="${s}" height="${Math.max(8, s/2)}">
        <path d="M0 ${s/4} Q ${s/4} 0, ${s/2} ${s/4} T ${s} ${s/4}"
              stroke="white" stroke-opacity="0.3" fill="none" stroke-width="2"/>
      </svg>`),
    hearts: (s) => {
      const p = `M ${s*0.5} ${s*0.78}
                 C ${s*0.2} ${s*0.55}, ${s*0.05} ${s*0.3}, ${s*0.28} ${s*0.2}
                 C ${s*0.40} ${s*0.14}, ${s*0.50} ${s*0.22}, ${s*0.5} ${s*0.30}
                 C ${s*0.50} ${s*0.22}, ${s*0.60} ${s*0.14}, ${s*0.72} ${s*0.2}
                 C ${s*0.95} ${s*0.3}, ${s*0.8} ${s*0.55}, ${s*0.5} ${s*0.78} Z`;
      return svgUrl(`<svg xmlns="http://www.w3.org/2000/svg" width="${s}" height="${s}">
        <path d="${p}" fill="none" stroke="white" stroke-opacity="0.35"
              stroke-width="${Math.max(1, s / 30)}"/>
      </svg>`);
    },
    flowers: (s) => {
      const c = (v) => Math.round(v);
      return svgUrl(`<svg xmlns="http://www.w3.org/2000/svg" width="${s}" height="${s}">
        <g fill="none" stroke="white" stroke-opacity="0.35" stroke-width="${Math.max(1, s/40)}">
          <path d="M ${c(s/2)} ${c(s*0.1)} Q ${c(s*0.7)} ${c(s*0.3)}, ${c(s/2)} ${c(s/2)} Q ${c(s*0.3)} ${c(s*0.3)}, ${c(s/2)} ${c(s*0.1)}"/>
          <path d="M ${c(s*0.9)} ${c(s/2)} Q ${c(s*0.7)} ${c(s*0.7)}, ${c(s/2)} ${c(s/2)} Q ${c(s*0.7)} ${c(s*0.3)}, ${c(s*0.9)} ${c(s/2)}"/>
          <path d="M ${c(s/2)} ${c(s*0.9)} Q ${c(s*0.3)} ${c(s*0.7)}, ${c(s/2)} ${c(s/2)} Q ${c(s*0.7)} ${c(s*0.7)}, ${c(s/2)} ${c(s*0.9)}"/>
          <path d="M ${c(s*0.1)} ${c(s/2)} Q ${c(s*0.3)} ${c(s*0.3)}, ${c(s/2)} ${c(s/2)} Q ${c(s*0.3)} ${c(s*0.7)}, ${c(s*0.1)} ${c(s/2)}"/>
          <circle cx="${c(s/2)}" cy="${c(s/2)}" r="${c(s*0.08)}"/>
        </g>
      </svg>`);
    },
    music: (s) => {
      const sw = Math.max(1, s / 48);
      const o  = 0.35;
      const noteFill = `white`;
      return svgUrl(`
        <svg xmlns="http://www.w3.org/2000/svg" width="${s}" height="${s}" viewBox="0 0 ${s} ${s}">
          <g fill="none" stroke="white" stroke-opacity="${o}" stroke-width="${sw}">
            <!-- Staff -->
            <line x1="${s*0.08}" y1="${s*0.42}" x2="${s*0.92}" y2="${s*0.42}" stroke-opacity="0.18"/>
            <line x1="${s*0.08}" y1="${s*0.52}" x2="${s*0.92}" y2="${s*0.52}" stroke-opacity="0.12"/>

            <!-- Eighth note -->
            <ellipse cx="${s*0.28}" cy="${s*0.68}" rx="${s*0.085}" ry="${s*0.06}" fill="${noteFill}" fill-opacity="${o}"/>
            <line x1="${s*0.36}" y1="${s*0.68}" x2="${s*0.36}" y2="${s*0.28}"/>
            <path d="M ${s*0.36} ${s*0.28} Q ${s*0.5} ${s*0.26}, ${s*0.5} ${s*0.38}" />

            <!-- Quarter note -->
            <ellipse cx="${s*0.62}" cy="${s*0.66}" rx="${s*0.085}" ry="${s*0.06}" fill="${noteFill}" fill-opacity="${o}"/>
            <line x1="${s*0.70}" y1="${s*0.66}" x2="${s*0.70}" y2="${s*0.30}"/>
          </g>
        </svg>
      `);
    },
    melody: (s) => {
      const sw = Math.max(1, s / 56);
      const o  = 0.35;
      const noteFill = `white`;
      return svgUrl(`
        <svg xmlns="http://www.w3.org/2000/svg" width="${s}" height="${s}" viewBox="0 0 ${s} ${s}">
          <g fill="none" stroke="white" stroke-opacity="${o}" stroke-width="${sw}">
            <!-- Staff lines (5) -->
            <line x1="${s*0.06}" y1="${s*0.28}" x2="${s*0.94}" y2="${s*0.28}" stroke-opacity="0.18"/>
            <line x1="${s*0.06}" y1="${s*0.36}" x2="${s*0.94}" y2="${s*0.36}" stroke-opacity="0.14"/>
            <line x1="${s*0.06}" y1="${s*0.44}" x2="${s*0.94}" y2="${s*0.44}" stroke-opacity="0.14"/>
            <line x1="${s*0.06}" y1="${s*0.52}" x2="${s*0.94}" y2="${s*0.52}" stroke-opacity="0.14"/>
            <line x1="${s*0.06}" y1="${s*0.60}" x2="${s*0.94}" y2="${s*0.60}" stroke-opacity="0.18"/>

            <!-- Notes (short melody) -->
            <ellipse cx="${s*0.18}" cy="${s*0.58}" rx="${s*0.07}" ry="${s*0.05}" fill="${noteFill}" fill-opacity="${o}"/>
            <line x1="${s*0.25}" y1="${s*0.58}" x2="${s*0.25}" y2="${s*0.36}"/>

            <ellipse cx="${s*0.40}" cy="${s*0.50}" rx="${s*0.07}" ry="${s*0.05}" fill="${noteFill}" fill-opacity="${o}"/>
            <line x1="${s*0.47}" y1="${s*0.50}" x2="${s*0.47}" y2="${s*0.28}"/>

            <ellipse cx="${s*0.60}" cy="${s*0.42}" rx="${s*0.07}" ry="${s*0.05}" fill="${noteFill}" fill-opacity="${o}"/>
            <line x1="${s*0.67}" y1="${s*0.42}" x2="${s*0.67}" y2="${s*0.20}"/>
            <path d="M ${s*0.67} ${s*0.20} Q ${s*0.78} ${s*0.18}, ${s*0.78} ${s*0.28}" />

            <ellipse cx="${s*0.82}" cy="${s*0.54}" rx="${s*0.07}" ry="${s*0.05}" fill="${noteFill}" fill-opacity="${o}"/>
            <line x1="${s*0.89}" y1="${s*0.54}" x2="${s*0.89}" y2="${s*0.32}"/>
          </g>
        </svg>
      `);
    }

  };

  function normalizeSkinPreset(value) {
    const allowed = ["legacy-dark", "silver", "gold", "blue", "rose", "green", "red", "purple"];
    return allowed.includes(value) ? value : DEFAULT_APPEARANCE.skin_preset;
  }

  function normalizePatternBase(value) {
    const allowed = ["default", "none", "dots", "lines", "grid", "waves", "hearts", "flowers", "music", "melody"];
    return allowed.includes(value) ? value : DEFAULT_APPEARANCE.pattern_base;
  }

  function normalizePatternSize(value) {
    const n = Number(value);
    if (!Number.isFinite(n)) return DEFAULT_APPEARANCE.pattern_size;
    return Math.max(10, Math.min(100, Math.round(n)));
  }

  function normalizeBannerUrl(value) {
    const raw = String(value || "").trim();
    if (!raw) return "";

    if (
      raw.startsWith("/") ||
      /^https?:\/\//i.test(raw) ||
      /^data:image\//i.test(raw) ||
      /^blob:/i.test(raw)
    ) {
      return raw;
    }
    return "";
  }

  function sanitizeBannerOwnerToken(token) {
    return String(token || "").trim().replace(/[^a-zA-Z0-9_.-]/g, "") || "owner";
  }

  function bannerImageFileExtension(file) {
    const name = String(file?.name || "").toLowerCase();
    const extMatch = name.match(/\.([a-z0-9]{2,5})$/);
    if (extMatch && extMatch[1]) return extMatch[1];
    const type = String(file?.type || "").toLowerCase();
    if (type === "image/jpeg") return "jpg";
    if (type === "image/png") return "png";
    if (type === "image/webp") return "webp";
    if (type === "image/gif") return "gif";
    if (type === "image/avif") return "avif";
    return "img";
  }

  function bannerArrayBufferToHex(buffer) {
    const bytes = new Uint8Array(buffer);
    let out = "";
    for (let i = 0; i < bytes.length; i += 1) {
      out += bytes[i].toString(16).padStart(2, "0");
    }
    return out;
  }

  async function bannerFileSha256Hex(file) {
    if (!window.crypto || !window.crypto.subtle || !file) return "";
    const data = await file.arrayBuffer();
    const hashBuffer = await window.crypto.subtle.digest("SHA-256", data);
    return bannerArrayBufferToHex(hashBuffer);
  }

  function encodeBannerR2KeyPath(key) {
    return String(key || "")
      .split("/")
      .map((part) => encodeURIComponent(part))
      .join("/");
  }

  function getImageFileFromTransfer(transfer) {
    if (!transfer) return null;
    if (transfer.items && transfer.items.length) {
      for (const item of transfer.items) {
        if (item.kind === "file" && item.type && item.type.startsWith("image/")) {
          const file = item.getAsFile();
          if (file) return file;
        }
      }
    }
    if (transfer.files && transfer.files.length) {
      for (const file of transfer.files) {
        if (file && file.type && file.type.startsWith("image/")) return file;
      }
    }
    return null;
  }

  function normalizeAppearance(value = {}) {
    return {
      skin_preset: normalizeSkinPreset(String(value.skin_preset || value.skinPreset || DEFAULT_APPEARANCE.skin_preset)),
      pattern_base: normalizePatternBase(String(value.pattern_base || value.patternBase || DEFAULT_APPEARANCE.pattern_base)),
      pattern_size: normalizePatternSize(value.pattern_size ?? value.patternSize ?? DEFAULT_APPEARANCE.pattern_size),
      top_banner_url: normalizeBannerUrl(value.top_banner_url || value.topBannerUrl || ""),
      greeting_text: String(value.greeting_text || value.greetingText || "").slice(0, 80)
    };
  }

  function readAppearanceFromLocalStorage() {
    return normalizeAppearance({
      skin_preset: localStorage.getItem("skinPreset") || DEFAULT_APPEARANCE.skin_preset,
      pattern_base: localStorage.getItem("patternBaseValue") || DEFAULT_APPEARANCE.pattern_base,
      pattern_size: localStorage.getItem("patternSizeValue") || DEFAULT_APPEARANCE.pattern_size,
      top_banner_url: localStorage.getItem("topGroupBannerUrl") || "",
      greeting_text: localStorage.getItem("topGroupGreetingText") || ""
    });
  }

  function writeAppearanceToLocalStorage(appearance) {
    localStorage.setItem("skinPreset", appearance.skin_preset);
    localStorage.setItem("patternBaseValue", String(appearance.pattern_base));
    localStorage.setItem("patternSizeValue", String(appearance.pattern_size));
    if (appearance.top_banner_url) {
      localStorage.setItem("topGroupBannerUrl", appearance.top_banner_url);
    } else {
      localStorage.removeItem("topGroupBannerUrl");
    }
    if (appearance.greeting_text) {
      localStorage.setItem("topGroupGreetingText", appearance.greeting_text);
    } else {
      localStorage.removeItem("topGroupGreetingText");
    }
  }

  function getProfileOwnerAppearance() {
    const ownerAppearance = window.currentOwner?.appearance;
    if (ownerAppearance && typeof ownerAppearance === "object") {
      return normalizeAppearance(ownerAppearance);
    }
    return null;
  }

  function canSaveProfileAppearance() {
    const sessionUser = String(window.SESSION_USERNAME || "").trim();
    const profileUser = String(window.currentProfileUsername || window.currentOwner?.username || "").trim();
    return !!sessionUser && !!profileUser && sessionUser === profileUser;
  }

  function syncControlsFromAppearance() {
    sizeSlider.value = String(currentAppearance.pattern_size);
    baseSel.value = currentAppearance.pattern_base;
    if (bannerInput) {
      bannerInput.value = currentAppearance.top_banner_url;
    }
    if (greetingTextInput) {
      greetingTextInput.value = currentAppearance.greeting_text;
    }
    updateSkinPickerActive(currentAppearance.skin_preset);
  }

  function setBannerUploadBusy(isBusy) {
    if (!bannerDropzone) return;
    bannerDropzone.dataset.uploading = isBusy ? "1" : "0";
    bannerDropzone.style.opacity = isBusy ? "0.7" : "1";
    bannerDropzone.style.cursor = isBusy ? "progress" : "pointer";
  }

  async function uploadBannerImage(file) {
    if (!(file instanceof File)) {
      throw new Error("No image selected.");
    }
    if (!file.type.startsWith("image/")) {
      throw new Error("Only image files are allowed.");
    }
    if (file.size > 10 * 1024 * 1024) {
      throw new Error("Image must be 10MB or smaller.");
    }

    const ownerSegment = sanitizeBannerOwnerToken(
      window.currentProfileUsername || window.currentOwner?.username || window.SESSION_USERNAME || ""
    );
    const ext = bannerImageFileExtension(file);
    const contentHash = await bannerFileSha256Hex(file);
    const suffix = contentHash || `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
    const key = `${ownerSegment}/profile/banners/${suffix}.${ext}`;
    const uploadUrl = `${BANNER_R2_UPLOAD_BASE}/?key=${encodeURIComponent(key)}`;
    const uploadRes = await fetch(uploadUrl, {
      method: "POST",
      headers: {
        "Content-Type": file.type || "application/octet-stream"
      },
      body: file
    });
    if (!uploadRes.ok) {
      throw new Error("Image upload failed.");
    }
    return `${BANNER_R2_PUBLIC_BASE}/${encodeBannerR2KeyPath(key)}`;
  }

  async function persistAppearanceNow() {
    if (!canSaveProfileAppearance()) return;
    clearTimeout(saveTimer);
    saveTimer = null;
    try {
      const res = await fetch("/sub_update_profile_appearance.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(currentAppearance)
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data?.ok) return;
      const savedAppearance = normalizeAppearance(data.appearance || currentAppearance);
      currentAppearance = savedAppearance;
      writeAppearanceToLocalStorage(savedAppearance);
      if (window.currentOwner && typeof window.currentOwner === "object") {
        window.currentOwner.appearance = { ...savedAppearance };
      }
    } catch {}
  }

  function queueAppearanceSave() {
    if (!canSaveProfileAppearance()) return;
    clearTimeout(saveTimer);
    saveTimer = setTimeout(() => {
      persistAppearanceNow();
    }, 220);
  }

  function updateAppearance(patch = {}, options = {}) {
    currentAppearance = normalizeAppearance({ ...currentAppearance, ...patch });
    writeAppearanceToLocalStorage(currentAppearance);
    syncControlsFromAppearance();
    applySkinPreset(currentAppearance.skin_preset);
    if (options.save !== false) {
      if (options.immediate) {
        persistAppearanceNow();
      } else {
        queueAppearanceSave();
      }
    }
  }

  function styleGroup(el) {
    const size = currentAppearance.pattern_size;
    const base = currentAppearance.pattern_base;
    const bannerUrl = currentAppearance.top_banner_url;
    const isPreviewHeader = el.id === "patternPreview";
    const isTopGroup = el.parentElement?.classList?.contains("list-group-wrapper") || isPreviewHeader;

    let baseColor = "rgba(105,147,45,0.9)";
    let patternImg = "none";
    let sizeCSS = "auto";
    const imageLayers = [];
    const sizeLayers = [];
    const positionLayers = [];
    const repeatLayers = [];

    const isSkin = document.body.getAttribute("data-menu-skin") === "ep";
    if (isSkin) {
      const skinAccent = getComputedStyle(document.body).getPropertyValue("--skin-accent-soft").trim();
      if (skinAccent) baseColor = skinAccent;
    }

    if (base !== "default") {
      const px = Math.pow((Math.max(10, size) / 10), 2) * 10;
      if (base !== "none") {
        patternImg = patterns[base](px);
        sizeCSS = base === "waves"
          ? `${px}px ${Math.max(8, Math.round(px/2))}px`
          : `${px}px ${px}px`;
      }
    }

    if (bannerUrl && isTopGroup) {
      imageLayers.push(`linear-gradient(180deg, rgba(14, 18, 28, 0.18), rgba(14, 18, 28, 0.42))`);
      sizeLayers.push("cover");
      positionLayers.push("center");
      repeatLayers.push("no-repeat");

      imageLayers.push(asCssUrl(bannerUrl));
      sizeLayers.push("cover");
      positionLayers.push("center");
      repeatLayers.push("no-repeat");

      el.style.minHeight = isPreviewHeader ? "104px" : "88px";
      el.style.paddingTop = "18px";
      el.style.paddingBottom = "18px";
      el.style.boxShadow = "inset 0 1px 0 rgba(255,255,255,0.14)";
    } else {
      el.style.minHeight = "";
      el.style.paddingTop = "";
      el.style.paddingBottom = "";
      el.style.boxShadow = "";
    }

    if (patternImg !== "none") {
      imageLayers.push(patternImg);
      sizeLayers.push(sizeCSS);
      positionLayers.push("0 0");
      repeatLayers.push("repeat");
    }

    el.style.backgroundColor = baseColor;
    el.style.backgroundImage = imageLayers.length ? imageLayers.join(", ") : "none";
    el.style.backgroundSize  = sizeLayers.length ? sizeLayers.join(", ") : "auto";
    el.style.backgroundPosition = positionLayers.length ? positionLayers.join(", ") : "";
    el.style.backgroundRepeat = repeatLayers.length ? repeatLayers.join(", ") : "repeat";
    const blendModes = [];
    if (bannerUrl && isTopGroup) {
      blendModes.push("normal", "normal");
    }
    if (patternImg !== "none") {
      blendModes.push("overlay");
    }
    el.style.backgroundBlendMode = blendModes.length ? blendModes.join(", ") : "normal";
  }

  function applyAll() {
    document.querySelectorAll(".sidebar-section-header.collapsible-group")
      .forEach(styleGroup);
  }

  function asCssUrl(value) {
    const safe = String(value || "").replace(/["\\\n\r\f]/g, "\\$&");
    return `url("${safe}")`;
  }

  function clearSkinVars() {
    const root = document.body.style;
    [
      "--skin-bg",
      "--skin-surface",
      "--skin-surface-2",
      "--skin-surface-3",
      "--skin-border",
      "--skin-hover",
      "--skin-text",
      "--skin-muted",
      "--skin-accent",
      "--skin-accent-soft"
    ].forEach((v) => root.removeProperty(v));
  }

  function applySkinPreset(preset) {
    currentAppearance.skin_preset = normalizeSkinPreset(preset);

    if (preset === "legacy-dark") {
      document.body.removeAttribute("data-menu-skin");
      clearSkinVars();
      updateSkinPickerActive(currentAppearance.skin_preset);
      applyAll();
      return;
    }

    document.body.setAttribute("data-menu-skin", "ep");
    const presets = {
      silver: {
        bg: "#f1f3f6", surface: "#ffffff", surface2: "#e9edf2", surface3: "#e7ebf2",
        accent: "#3b6db4", accentSoft: "#a9c2e8", text: "#1f2430", muted: "#6b7280",
        border: "#d7dde7", hover: "#eef2f7"
      },
      gold: {
        bg: "#f5f1e8", surface: "#ffffff", surface2: "#efe3d2", surface3: "#e7d8c1",
        accent: "#b57a2a", accentSoft: "#e3c089", text: "#2a241c", muted: "#7a6a5e",
        border: "#e1d1bc", hover: "#f1e6d6"
      },
      blue: {
        bg: "#eef2f7", surface: "#ffffff", surface2: "#e3eaf5", surface3: "#d9e2f0",
        accent: "#2a62e2", accentSoft: "#8cb3ff", text: "#1b1f24", muted: "#6c727a",
        border: "#d2dbe8", hover: "#e7eef7"
      },
      rose: {
        bg: "#f5eff2", surface: "#ffffff", surface2: "#eadfe5", surface3: "#e2d5dd",
        accent: "#b06a86", accentSoft: "#d9a9bb", text: "#2a2426", muted: "#74656c",
        border: "#dcc9d2", hover: "#efe3e8"
      },
      green: {
        bg: "#eef3f0", surface: "#ffffff", surface2: "#e4ece7", surface3: "#dbe5df",
        accent: "#3f8f88", accentSoft: "#9fd1cb", text: "#24312f", muted: "#64726f",
        border: "#cad9d6", hover: "#e6efed"
      },
      red: {
        bg: "#f5eded", surface: "#ffffff", surface2: "#efe1e1", surface3: "#e8d5d5",
        accent: "#c04646", accentSoft: "#e6a2a2", text: "#2a1f1f", muted: "#7a6161",
        border: "#e4d0d0", hover: "#f0e4e4"
      },
      purple: {
        bg: "#f2eef8", surface: "#ffffff", surface2: "#e7def3", surface3: "#ddd4ee",
        accent: "#6a4fb3", accentSoft: "#b69be6", text: "#231f2a", muted: "#6c647a",
        border: "#d6cdea", hover: "#ebe3f5"
      }
    };

    const p = presets[preset];
    if (!p) return;
    const root = document.body.style;
    root.setProperty("--skin-bg", p.bg);
    root.setProperty("--skin-surface", p.surface);
    root.setProperty("--skin-surface-2", p.surface2);
    root.setProperty("--skin-surface-3", p.surface3);
    root.setProperty("--skin-border", p.border);
    root.setProperty("--skin-hover", p.hover);
    root.setProperty("--skin-text", p.text);
    root.setProperty("--skin-muted", p.muted);
    root.setProperty("--skin-accent", p.accent);
    root.setProperty("--skin-accent-soft", p.accentSoft);
    updateSkinPickerActive(currentAppearance.skin_preset);
    applyAll();
  }

  function updateSkinPickerActive(preset) {
    [skinPicker, skinPickerPanel].filter(Boolean).forEach((container) => {
      container.querySelectorAll(".skin-swatch").forEach((btn) => {
        btn.classList.toggle("is-active", btn.dataset.skin === preset);
      });
    });
  }

  // Restore local fallback until owner profile payload arrives.
  currentAppearance = readAppearanceFromLocalStorage();
  syncControlsFromAppearance();

  // Save + repaint on change
  [sizeSlider, baseSel].forEach(el => {
    el.addEventListener("input", () => {
      updateAppearance({
        pattern_size: sizeSlider.value,
        pattern_base: baseSel.value
      }, { immediate: true });
    });
  });

  if (bannerInput) {
    const saveBannerValue = () => {
      updateAppearance({
        top_banner_url: bannerInput.value
      }, { immediate: true });
    };

    bannerInput.addEventListener("input", saveBannerValue);
    bannerInput.addEventListener("change", saveBannerValue);
    bannerInput.addEventListener("paste", () => {
      requestAnimationFrame(saveBannerValue);
    });
  }

  if (greetingTextInput) {
    greetingTextInput.addEventListener("input", () => {
      currentAppearance.greeting_text = String(greetingTextInput.value || "");
      writeAppearanceToLocalStorage(currentAppearance);
      queueAppearanceSave();
    });
  }

  if (bannerSelectBtn && bannerFileInput) {
    bannerSelectBtn.addEventListener("click", (eventObj) => {
      eventObj.preventDefault();
      bannerFileInput.click();
    });
    bannerFileInput.addEventListener("change", async () => {
      const file = bannerFileInput.files && bannerFileInput.files[0] ? bannerFileInput.files[0] : null;
      if (!file) return;
      setBannerUploadBusy(true);
      try {
        const url = await uploadBannerImage(file);
        updateAppearance({ top_banner_url: url }, { immediate: true });
      } catch (err) {
        alert(err?.message || "Unable to upload image.");
      } finally {
        setBannerUploadBusy(false);
        bannerFileInput.value = "";
      }
    });
  }

  if (bannerClearBtn) {
    bannerClearBtn.addEventListener("click", (eventObj) => {
      eventObj.preventDefault();
      updateAppearance({ top_banner_url: "" }, { immediate: true });
    });
  }

  if (bannerDropzone && bannerFileInput) {
    bannerDropzone.addEventListener("click", () => {
      bannerFileInput.click();
    });
    bannerDropzone.addEventListener("dragover", (eventObj) => {
      eventObj.preventDefault();
      bannerDropzone.style.outline = "2px solid rgba(140,179,255,0.85)";
      bannerDropzone.style.outlineOffset = "-3px";
    });
    bannerDropzone.addEventListener("dragleave", () => {
      bannerDropzone.style.outline = "";
      bannerDropzone.style.outlineOffset = "";
    });
    bannerDropzone.addEventListener("drop", async (eventObj) => {
      eventObj.preventDefault();
      bannerDropzone.style.outline = "";
      bannerDropzone.style.outlineOffset = "";
      const file = getImageFileFromTransfer(eventObj.dataTransfer);
      if (!file) return;
      setBannerUploadBusy(true);
      try {
        const url = await uploadBannerImage(file);
        updateAppearance({ top_banner_url: url }, { immediate: true });
      } catch (err) {
        alert(err?.message || "Unable to upload image.");
      } finally {
        setBannerUploadBusy(false);
      }
    });
  }

  const bannerPanel = document.getElementById("colorSettingsPanel");
  if (bannerPanel) {
    bannerPanel.addEventListener("paste", async (eventObj) => {
      const file = getImageFileFromTransfer(eventObj.clipboardData);
      if (!file) return;
      eventObj.preventDefault();
      setBannerUploadBusy(true);
      try {
        const url = await uploadBannerImage(file);
        updateAppearance({ top_banner_url: url }, { immediate: true });
      } catch (err) {
        alert(err?.message || "Unable to upload image.");
      } finally {
        setBannerUploadBusy(false);
      }
    });
  }

  [skinPicker, skinPickerPanel].filter(Boolean).forEach((picker) => {
    picker.addEventListener("pointerdown", (e) => {
      const target = e.target.closest(".skin-swatch");
      if (!target) return;
      const preset = target.dataset.skin;
      if (!preset) return;
      updateAppearance({ skin_preset: preset }, { immediate: true });
    });

    picker.addEventListener("pointerenter", (e) => {
      const target = e.target.closest(".skin-swatch");
      if (!target) return;
      const preset = target.dataset.skin;
      if (!preset) return;
      applySkinPreset(preset);
    }, true);

    picker.addEventListener("pointerleave", (e) => {
      if (picker.contains(e.relatedTarget)) return;
      applySkinPreset(currentAppearance.skin_preset);
    });
  });
 
  // Paint only the preview header immediately
  styleGroup(patternPreview);
  applySkinPreset(currentAppearance.skin_preset);

  window.twApplyProfileAppearance = function twApplyProfileAppearance(owner = null) {
    const ownerAppearance = owner?.appearance || getProfileOwnerAppearance() || readAppearanceFromLocalStorage();
    currentAppearance = normalizeAppearance(ownerAppearance);
    writeAppearanceToLocalStorage(currentAppearance);
    syncControlsFromAppearance();
    applySkinPreset(currentAppearance.skin_preset);
  };

  window.addEventListener("pagehide", () => {
    if (saveTimer) {
      persistAppearanceNow();
    }
  });

  // Repaint sidebar groups when they appear (after AJAX), scoped + debounced.
  const observeTarget =
    document.getElementById("listManager") ||
    document.getElementById("sidebarContainer");
  if (observeTarget) {
    let paintTimer = null;
    const observer = new MutationObserver(() => {
      clearTimeout(paintTimer);
      paintTimer = setTimeout(applyAll, 100);
    });
    observer.observe(observeTarget, { childList: true, subtree: true });
  }


});
