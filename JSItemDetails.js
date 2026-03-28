logStep("JSItemDetails.js executed");

(function () {
  const TT_R2_UPLOAD_BASE = "https://r2-worker.textwhisper.workers.dev";
  const TT_R2_PUBLIC_BASE = "https://pub-1afc23a510c147a5a857168f23ff6db8.r2.dev";

  function esc(value) {
    return String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  function parseItemText(rawText) {
    const lines = String(rawText || "")
      .split(/\r?\n/)
      .map((line) => line.trim())
      .filter(Boolean);

    const title = lines[0] || "";
    const rest = title ? lines.slice(1) : lines;
    let description = "";
    let price = "";
    let image = "";
    let allergens = "";

    for (const line of rest) {
      if (!image && /^https?:\/\/\S+\.(png|jpe?g|webp|gif)(\?\S*)?$/i.test(line)) {
        image = line;
        continue;
      }
      if (!price) {
        const match = line.match(/(\d{1,4}(?:[.,]\d{2})?)\s?(?:kr|isk|eur|\$|€)\b/i);
        if (match) price = match[0];
      }
      if (!allergens && /^allergens?\s*:/i.test(line)) {
        allergens = line.replace(/^allergens?\s*:/i, "").trim();
        continue;
      }
      if (!description) description = line;
    }

    return { title, description, price, image, allergens };
  }

  function getSelectedRow() {
    const surrogate = String(window.currentSurrogate || "").trim();
    return surrogate
      ? document.querySelector(`.list-sub-item[data-value="${CSS.escape(surrogate)}"]`)
      : document.querySelector(".list-sub-item.active");
  }

  function canManageSelectedItem() {
    const row = getSelectedRow();
    if (!document.body.classList.contains("logged-in")) return false;
    const roleRank = Number.parseInt(
      String(row?.dataset?.itemRoleRank || window.currentUserItemRoleRank || "0"),
      10
    );
    return roleRank >= 90;
  }

  function isItemDetailEditMode() {
    if (!canManageSelectedItem()) return false;
    return Array.from(document.querySelectorAll(".edit-mode-toggle")).some((toggle) => !!toggle.checked);
  }

  function currentEditorText() {
    const editor = document.getElementById("myTextarea");
    return String(window._T2_RAWTEXT || editor?.innerText || "").trim();
  }

  function ttSanitizeOwnerToken(token) {
    return String(token || "").trim().replace(/[^a-zA-Z0-9_.-]/g, "") || "owner";
  }

  function ttEncodeR2KeyPath(key) {
    return String(key || "").split("/").map((part) => encodeURIComponent(part)).join("/");
  }

  function ttImageFileExtension(file) {
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

  function ttGetImageFileFromTransfer(transfer) {
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

  function ttArrayBufferToHex(buffer) {
    const bytes = new Uint8Array(buffer);
    let out = "";
    for (let i = 0; i < bytes.length; i += 1) out += bytes[i].toString(16).padStart(2, "0");
    return out;
  }

  async function ttFileSha256Hex(file) {
    if (!window.crypto?.subtle || !file) return "";
    const data = await file.arrayBuffer();
    const hashBuffer = await window.crypto.subtle.digest("SHA-256", data);
    return ttArrayBufferToHex(hashBuffer);
  }

  async function ttUploadItemImageToR2(file, surrogate) {
    if (!(file instanceof File)) throw new Error("No file selected.");
    if (!file.type.startsWith("image/")) throw new Error("Only image files are allowed.");
    if (file.size > 10 * 1024 * 1024) throw new Error("Image must be 10MB or smaller.");
    const ownerSegment = ttSanitizeOwnerToken(window.currentItemOwner || window.currentProfileUsername || window.SESSION_USERNAME || "");
    const ext = ttImageFileExtension(file);
    const contentHash = await ttFileSha256Hex(file);
    const suffix = contentHash || `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
    const key = `${ownerSegment}/menu-items/${String(surrogate || "0")}/${suffix}.${ext}`;
    const uploadUrl = `${TT_R2_UPLOAD_BASE}/?key=${encodeURIComponent(key)}`;
    const uploadRes = await fetch(uploadUrl, {
      method: "POST",
      headers: { "Content-Type": file.type || "application/octet-stream" },
      body: file
    });
    if (!uploadRes.ok) throw new Error("R2 upload failed.");
    return `${TT_R2_PUBLIC_BASE}/${ttEncodeR2KeyPath(key)}`;
  }

  function getFormValues() {
    return {
      short_description: document.getElementById("ttItemShortDescription")?.value?.trim() || "",
      detailed_description: document.getElementById("ttItemDetailedDescription")?.value?.trim() || "",
      price_label: document.getElementById("ttItemPrice")?.value?.trim() || "",
      image_url: document.getElementById("ttItemImage")?.value?.trim() || "",
      allergens: document.getElementById("ttItemAllergens")?.value?.trim() || "",
      is_available: document.getElementById("ttItemAvailable")?.checked ? 1 : 0
    };
  }

  function setSaveState(message, state = "idle") {
    const badge = document.getElementById("ttItemSaveState");
    if (!badge) return;
    badge.textContent = message || "";
    badge.dataset.state = state;
  }

  function updateImagePreview(url, title = "") {
    const mediaEl = document.getElementById("ttItemMedia");
    if (!mediaEl) return;
    const next = String(url || "").trim();
    if (!next) {
      mediaEl.innerHTML = `<div class="tt-item-media-placeholder">Food image preview</div>`;
      return;
    }
    mediaEl.innerHTML = `<img class="tt-item-media-img" src="${esc(next)}" alt="${esc(title || "Menu item")}">`;
  }

  function applySettingsToForm(settings) {
    const title = document.getElementById("ttItemTitle")?.textContent?.trim() || "Menu item";
    const priceEl = document.getElementById("ttItemPrice");
    const shortDescEl = document.getElementById("ttItemShortDescription");
    const detailedDescEl = document.getElementById("ttItemDetailedDescription");
    const imageEl = document.getElementById("ttItemImage");
    const allergensEl = document.getElementById("ttItemAllergens");
    const availableEl = document.getElementById("ttItemAvailable");
    if (!priceEl || !shortDescEl || !detailedDescEl || !imageEl || !allergensEl || !availableEl) return;
    priceEl.value = settings.price_label || "";
    shortDescEl.value = settings.short_description || settings.public_description || "";
    detailedDescEl.value = settings.detailed_description || "";
    imageEl.value = settings.image_url || "";
    allergensEl.value = settings.allergens || "";
    availableEl.checked = Number(settings.is_available ?? 1) === 1;
    updateImagePreview(settings.image_url || "", title);
  }

  async function loadSavedSettings(surrogate) {
    if (!surrogate || String(surrogate) === "0") return;
    try {
      const res = await fetch(`/getItemSettings.php?surrogate=${encodeURIComponent(String(surrogate))}`, {
        credentials: "include"
      });
      const data = await res.json();
      if (data?.status !== "OK" || !data.data) return;
      applySettingsToForm(data.data);
      setSaveState(data.data.updated_at ? "Saved" : "Using text defaults", "ok");
    } catch (error) {
      console.error("loadSavedSettings failed:", error);
      setSaveState("Save unavailable", "error");
    }
  }

  function scheduleSave(surrogate) {
    clearTimeout(window.taptrayItemSettingsSaveTimer);
    setSaveState("Saving...", "saving");
    window.taptrayItemSettingsSaveTimer = setTimeout(async () => {
      try {
        const payload = { surrogate: Number(surrogate), ...getFormValues() };
        const res = await fetch("/saveItemSettings.php", {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (data?.status !== "OK") throw new Error(data?.message || "Save failed");
        setSaveState("Saved", "ok");
      } catch (error) {
        console.error("saveItemSettings failed:", error);
        setSaveState("Save failed", "error");
      }
    }, 450);
  }

  function bindImageInteractions(surrogate) {
    const shell = document.querySelector(".tt-item-shell");
    const mediaEl = document.getElementById("ttItemMedia");
    const imageEl = document.getElementById("ttItemImage");
    if (!shell || !mediaEl || !imageEl) return;

    const setBusy = (busy) => {
      shell.dataset.imageUploading = busy ? "1" : "0";
      mediaEl.classList.toggle("is-uploading", !!busy);
      setSaveState(busy ? "Uploading image..." : (document.getElementById("ttItemSaveState")?.textContent || "Saved"), busy ? "saving" : "ok");
    };

    const uploadFile = async (file) => {
      if (!file) return;
      setBusy(true);
      try {
        const imageUrl = await ttUploadItemImageToR2(file, surrogate);
        imageEl.value = imageUrl;
        updateImagePreview(imageUrl, document.getElementById("ttItemTitle")?.textContent?.trim() || "");
        scheduleSave(surrogate);
        showFlashMessage?.("Image uploaded");
      } catch (error) {
        console.error("ttUploadItemImageToR2 failed:", error);
        setSaveState(error?.message || "Image upload failed", "error");
      } finally {
        setBusy(false);
      }
    };

    mediaEl.addEventListener("dragover", (eventObj) => {
      eventObj.preventDefault();
      mediaEl.classList.add("is-dragover");
    });
    mediaEl.addEventListener("dragleave", () => {
      mediaEl.classList.remove("is-dragover");
    });
    mediaEl.addEventListener("drop", (eventObj) => {
      eventObj.preventDefault();
      mediaEl.classList.remove("is-dragover");
      const file = ttGetImageFileFromTransfer(eventObj.dataTransfer);
      if (file) uploadFile(file);
    });
    shell.addEventListener("paste", (eventObj) => {
      const file = ttGetImageFileFromTransfer(eventObj.clipboardData);
      if (!file) return;
      eventObj.preventDefault();
      uploadFile(file);
    });
  }

  function bindSettingsInputs(surrogate) {
    const title = document.getElementById("ttItemTitle")?.textContent?.trim() || "";
    const ids = ["ttItemPrice", "ttItemShortDescription", "ttItemDetailedDescription", "ttItemImage", "ttItemAllergens", "ttItemAvailable"];
    ids.forEach((id) => {
      const el = document.getElementById(id);
      if (!el) return;
      const eventName = el.tagName === "INPUT" && el.type === "checkbox" ? "change" : "input";
      el.addEventListener(eventName, () => {
        if (id === "ttItemImage") {
          updateImagePreview(el.value, title);
        }
        scheduleSave(surrogate);
      });
    });
    bindImageInteractions(surrogate);
  }

  function currentSelectedTitle(parsedTitle = "") {
    const selectedText = String(document.getElementById("selectedItemTitle")?.textContent || "")
      .replace(/^•\s*/, "")
      .replace(/\s*\[[^\]]+\]\s*$/, "")
      .trim();
    const currentText = String(document.getElementById("ttItemTitle")?.textContent || "").trim();
    return String(selectedText || currentText || parsedTitle || "Menu item").trim();
  }

  function currentNotesBody(rawText = "") {
    const normalized = String(rawText || "").replace(/\r\n/g, "\n");
    const lines = normalized.split("\n");
    if (!lines.length) return "";
    return lines.slice(1).join("\n").replace(/^\n+/, "");
  }

  function syncItemNotesEditors(fullText) {
    const nextText = String(fullText || "");
    window._T2_RAWTEXT = nextText;
    const editor = document.getElementById("myTextarea");
    const mirror = document.getElementById("myTextarea2");
    if (editor) editor.innerText = nextText;
    if (mirror) mirror.innerText = nextText;
  }

  function scheduleNotesSave(surrogate, title) {
    clearTimeout(window.taptrayItemNotesSaveTimer);
    setSaveState("Saving...", "saving");
    window.taptrayItemNotesSaveTimer = setTimeout(async () => {
      try {
        const notesValue = document.getElementById("ttItemNotesInput")?.value ?? "";
        const cleanTitle = String(title || "Menu item").trim() || "Menu item";
        const textPayload = notesValue.trim()
          ? `${cleanTitle}\n${notesValue.replace(/\r\n/g, "\n")}`
          : cleanTitle;
        syncItemNotesEditors(textPayload);

        const body = new URLSearchParams();
        body.set("dataname", cleanTitle);
        body.set("surrogate", String(Number(surrogate) || 0));
        body.set("token", String(window.currentListToken || ""));
        body.set("text", textPayload);

        const res = await fetch("/datainsert_to_list.php", {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8" },
          body: body.toString()
        });
        if (!res.ok) throw new Error("Save failed");
        setSaveState("Saved", "ok");
      } catch (error) {
        console.error("saveItemNotes failed:", error);
        setSaveState("Save failed", "error");
      }
    }, 450);
  }

  function bindNotesInput(surrogate) {
    const notesEl = document.getElementById("ttItemNotesInput");
    if (!notesEl) return;
    notesEl.addEventListener("input", () => {
      scheduleNotesSave(surrogate, currentSelectedTitle());
    });
  }

  window.showTaptrayItemPreview = function showTaptrayItemPreview(surrogate = null, token = null) {
    const itemSurrogate = String(surrogate || window.currentSurrogate || "").trim();
    const listToken = String(token || window.currentListToken || "").trim();
    const ownerToken = String(
      window.currentListOwnerUsername ||
      window.currentOwner?.username ||
      window.currentProfileUsername ||
      window.SESSION_USERNAME ||
      ""
    ).trim();
    const previewUrl =
      `/menu_preview.php?owner=${encodeURIComponent(ownerToken)}`
      + (listToken ? `&token=${encodeURIComponent(listToken)}` : "")
      + (itemSurrogate && itemSurrogate !== "0" ? `&surrogate=${encodeURIComponent(itemSurrogate)}` : "");

    if (typeof window.showHomeTab === "function") {
      window.showHomeTab(previewUrl);
    } else {
      window.location.href = previewUrl;
    }
  };

  function renderSelectedItemWorkspace() {
    const host = document.getElementById("pdfTabContent");
    if (!host) return;

    const rawText = currentEditorText();
    const parsed = parseItemText(rawText);
    const selectedTitle = currentSelectedTitle(parsed.title || "Select an item");
    const notesBody = currentNotesBody(rawText);
    const row = getSelectedRow();
    const canManage = canManageSelectedItem();
    const editMode = isItemDetailEditMode();
    const shortDescription = String(row?.dataset?.shortDescription || row?.dataset?.publicDescription || parsed.description || "").trim();
    const detailedDescription = String(row?.dataset?.detailedDescription || "").trim();
    const priceLabel = String(row?.dataset?.priceLabel || parsed.price || "").trim();
    const imageUrl = String(row?.dataset?.imageUrl || parsed.image || "").trim();
    const allergens = String(row?.dataset?.allergens || parsed.allergens || "").trim();

    const imageHtml = imageUrl
      ? `<img class="tt-item-media-img" src="${esc(imageUrl)}" alt="${esc(selectedTitle)}">`
      : `<div class="tt-item-media-placeholder">Food image preview</div>`;

    if (!canManage || !editMode) {
      const shortDescriptionHtml = shortDescription
        ? `
          <div class="tt-field">
            <div class="tt-item-view-label">Short description</div>
            <div class="tt-item-view-copy"><p>${esc(shortDescription)}</p></div>
          </div>
        `
        : "";
      const detailLines = String(detailedDescription || shortDescription || "")
        .split(/\r?\n/)
        .map((line) => line.trim())
        .filter(Boolean);
      const detailsHtml = detailLines.length
        ? `
          <div class="tt-field">
            <div class="tt-item-view-label">Detailed description</div>
            <div class="tt-item-view-copy">${detailLines.map((line) => `<p>${esc(line)}</p>`).join("")}</div>
          </div>
        `
        : `<div class="tt-item-view-copy"><p>No customer-facing description available.</p></div>`;
      const allergensHtml = allergens
        ? `<div class="tt-item-view-meta"><strong>Allergens:</strong> ${esc(allergens)}</div>`
        : "";

      host.innerHTML = `
        <div class="tt-item-details">
          <div class="tt-item-shell">
            <div class="tt-item-media">${imageHtml}</div>
            <div class="tt-item-main">
              <div class="tt-item-header">
                <div>
                  <div class="tt-item-kicker">Menu item details</div>
                  <h2 class="tt-item-view-title">${esc(selectedTitle)}</h2>
                </div>
                ${priceLabel ? `<div class="tt-item-view-price">${esc(priceLabel)}</div>` : ""}
              </div>
              ${shortDescriptionHtml}
              ${detailsHtml}
              ${allergensHtml}
            </div>
          </div>
        </div>
      `;
      return;
    }

    const manageImageHtml = imageUrl
      ? `<img class="tt-item-media-img" src="${esc(imageUrl)}" alt="${esc(selectedTitle)}">`
      : `<div class="tt-item-media-placeholder">Food image preview</div>`;

    host.innerHTML = `
      <div class="tt-item-details">
        <div class="tt-item-shell">
          <div id="ttItemMedia" class="tt-item-media">${manageImageHtml}</div>
          <div class="tt-item-main">
            <div class="tt-item-header">
              <div>
                <div class="tt-item-kicker">Menu item details</div>
                <h2 id="ttItemTitle">${esc(selectedTitle)}</h2>
                <div id="ttItemSaveState" class="tt-item-save-state">Using text defaults</div>
              </div>
              <div class="tt-item-price-wrap">
                <label for="ttItemPrice">Price</label>
                <input id="ttItemPrice" class="tt-input" type="text" placeholder="e.g. 3490 ISK" value="${esc(priceLabel)}">
              </div>
            </div>
            <div class="tt-item-grid">
              <div class="tt-field">
                <label for="ttItemShortDescription">Short description</label>
                <textarea id="ttItemShortDescription" class="tt-textarea" rows="3" placeholder="Short customer-facing description">${esc(shortDescription)}</textarea>
              </div>
              <div class="tt-field">
                <label for="ttItemDetailedDescription">Detailed description</label>
                <textarea id="ttItemDetailedDescription" class="tt-textarea" rows="5" placeholder="Expanded customer-facing description">${esc(detailedDescription)}</textarea>
              </div>
              <div class="tt-field">
                <label for="ttItemImage">Food image URL</label>
                <input id="ttItemImage" class="tt-input" type="url" placeholder="https://example.com/dish.jpg" value="${esc(imageUrl)}">
              </div>
              <div class="tt-field">
                <label for="ttItemAllergens">Allergens</label>
                <input id="ttItemAllergens" class="tt-input" type="text" placeholder="e.g. dairy, nuts, shellfish" value="${esc(allergens)}">
              </div>
              <div class="tt-field tt-toggle-row">
                <label class="tt-check"><input id="ttItemAvailable" type="checkbox" checked> Available now</label>
                <label class="tt-check"><input id="ttItemFeatured" type="checkbox"> Featured item</label>
              </div>
            </div>
            <button id="ttItemPreviewBtn" class="tt-item-preview-btn" type="button">Preview recipe</button>
            <div class="tt-item-notes">
              <div class="tt-item-notes-label">Internal recipe / prep notes</div>
              <textarea id="ttItemNotesInput" class="tt-textarea tt-item-notes-input" rows="10" placeholder="Kitchen prep, recipe details, plating notes">${esc(notesBody)}</textarea>
            </div>
          </div>
        </div>
      </div>
    `;

    const surrogate = String(window.currentSurrogate || "").trim();
    bindSettingsInputs(surrogate);
    bindNotesInput(surrogate);
    loadSavedSettings(surrogate);
    document.getElementById("ttItemPreviewBtn")?.addEventListener("click", () => {
      window.showTaptrayItemPreview?.(surrogate, window.currentListToken || "");
    });
  }

  function renderListWorkspace() {
    const host = document.getElementById("pdfTabContent");
    if (!host) return;

    const token = String(window.currentListToken || "").trim();
    const listTitle =
      document.getElementById(`list-title-${token}`)?.textContent?.trim() ||
      document.getElementById("selectedItemTitle")?.textContent?.trim() ||
      "Select an item";

    host.innerHTML = `
      <div class="tt-item-details">
        <div class="tt-item-shell">
          <div class="tt-item-main">
            <div class="tt-item-header">
              <div>
                <div class="tt-item-kicker">Menu item details</div>
                <h2 class="tt-item-view-title">${esc(listTitle)}</h2>
              </div>
            </div>
            <div class="tt-item-view-copy">
              <p>Select an item from the menu to open its details here.</p>
            </div>
          </div>
        </div>
      </div>
    `;
  }

  window.taptrayRefreshItemDetails = function taptrayRefreshItemDetails() {
    if (String(window.currentSurrogate || "").trim() && String(window.currentSurrogate) !== "0") {
      renderSelectedItemWorkspace();
    } else {
      renderListWorkspace();
    }
  };

  window.loadPDF = async function loadPDF(surrogate) {
    if (surrogate && String(surrogate).trim() !== "0") {
      window.currentSurrogate = String(surrogate);
      renderSelectedItemWorkspace();
    } else {
      renderListWorkspace();
    }
  };

  window.loadPDFOffline = async function loadPDFOffline(surrogate) {
    return window.loadPDF(surrogate);
  };

  window.exportPDF = function exportPDF() {};
  window.printPDF = function printPDF() {};
  window.refreshPdfFitPageHeightLayout = function refreshPdfFitPageHeightLayout() {};
  window.getPdfFitHeightMetrics = function getPdfFitHeightMetrics() { return null; };
  window.updatePdfTopChromePositions = function updatePdfTopChromePositions() {};

  document.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll(".edit-mode-toggle").forEach((toggle) => {
      if (toggle.dataset.itemDetailsBound === "1") return;
      toggle.dataset.itemDetailsBound = "1";
      toggle.addEventListener("change", () => {
        const pdfTab = document.getElementById("pdfTabContent");
        if (!pdfTab || !pdfTab.classList.contains("active")) return;
        window.taptrayRefreshItemDetails?.();
      });
    });
    if (document.getElementById("pdfTabContent")) {
      window.taptrayRefreshItemDetails?.();
    }
  });
})();
