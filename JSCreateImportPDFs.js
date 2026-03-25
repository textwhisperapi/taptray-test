logStep("JSCreateImport.js executed");

/* JSCreateImportPDFs.js — ultra-simple: createNewItemForPDF(listToken, title) + handleFileUpload */
(() => {
  const log = (...a) => console.log("[Importer]", ...a);
  const err = (...a) => console.error("[Importer]", ...a);

  const qs  = (s, r = document) => r.querySelector(s);
  const isPdf = f => f && (f.type === "application/pdf" || /\.pdf$/i.test(f.name || ""));
  const nameToText = n => (n || "").replace(/\.pdf$/i, "").trim();


  async function uploadPdf(file, surrogate) {
    if (typeof window.handleFileUpload !== "function") {
      throw new Error("handleFileUpload(file, surrogate, type) not found (jsDragDropPDF.js).");
    }
    await window.handleFileUpload(file, surrogate, "pdf");
  }

  // One pass: create (returns surrogate) → upload
    async function processFile(file) {
      const token = getListToken();
      const title = nameToText(file.name);
      log("creating:", title);
    
      const surrogate = await createNewItemForPDF(token, title);
      if (!surrogate) throw new Error("createNewItemForPDF did not return a surrogate.");
    
      log("uploading:", file.name, "→", surrogate);
      await uploadPdf(file, surrogate);
    
      // ✅ now safe to select (PDF exists / cacheable)
      if (typeof selectItem === "function") {
        const listContainer = document.getElementById("list-" + token);
        selectItem(surrogate, token, listContainer);
      }
    }


  // Minimal modal
  function openImportModal(initialFiles) {
    qs("#importFilesModal")?.remove();
    const currentList = getCurrentList();
    if (!currentList) {
      alert("⚠️ Open/select a list first.");
      return;
    }
    const token = currentList.token;
    const listName = currentList.name;

    const modal = document.createElement("div");
    modal.id = "importFilesModal";
    modal.style.cssText = `
      position:fixed; inset:0; z-index:200000; display:flex; align-items:center; justify-content:center;
      background:rgba(0,0,0,.55);
    `;
    modal.innerHTML = `
      <div style="width:min(640px,92vw); max-height:86vh; overflow:hidden; background:#fff; border-radius:12px; box-shadow:0 10px 30px rgba(0,0,0,.3); display:grid; grid-template-rows:auto 1fr auto;">
        <header style="padding:12px 14px; border-bottom:1px solid #eee; display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
          <strong style="font-size:15px;">📥 Import PDFs → items</strong>
          <span style="margin-left:auto; font-size:12px; color:#666;">
            List: <b>${listName}</b>
          </span>
          <span style="font-size:12px; color:#999;">
            Token: <code>${token}</code>
          </span>
          <button id="closeImport" style="margin-left:12px; border:0; background:#eee; padding:6px 10px; border-radius:6px; cursor:pointer;">Close</button>
        </header>
        <section style="padding:12px 14px; overflow:auto;">
          <div id="dropZone" tabindex="0" style="border:2px dashed #c7c7c7; border-radius:10px; padding:18px; text-align:center; margin-bottom:12px;">
            <div style="font-size:24px; line-height:1;">⬇️</div>
            <div style="font-size:13px; color:#555;">Drop PDFs here or</div>
            <button id="browseBtn" type="button" style="background:none; border:0; color:#0a66ff; text-decoration:underline; cursor:pointer;">browse</button>
            <input id="fileInput" type="file" accept="application/pdf" multiple hidden />
          </div>
          <div style="font-weight:600; margin:8px 0;">Pending (<span id="fileCount">0</span>)</div>
          <ul id="fileList" style="list-style:none; padding:0; margin:0; display:grid; gap:8px;"></ul>
          <pre id="statusBox" style="margin-top:10px; font-size:12px; color:#444; white-space:pre-wrap; display:none;"></pre>
        </section>
        <footer style="padding:12px 14px; border-top:1px solid #eee; display:flex; gap:8px; justify-content:flex-end;">
          <button id="commitBtn" style="background:#0a66ff; color:#fff; border:0; padding:8px 12px; border-radius:8px; cursor:pointer;">Commit import</button>
          <button id="clearBtn"  style="background:#f5f5f5; color:#333; border:0; padding:8px 12px; border-radius:8px; cursor:pointer;">Clear</button>
        </footer>
      </div>
    `;
    document.body.appendChild(modal);

    const dropZone  = qs("#dropZone", modal);
    const browseBtn = qs("#browseBtn", modal);
    const fileInput = qs("#fileInput", modal);
    const listEl    = qs("#fileList", modal);
    const countEl   = qs("#fileCount", modal);
    const statusBox = qs("#statusBox", modal);

    const stash = [];

    function render() {
      countEl.textContent = String(stash.length);
      listEl.innerHTML = stash.map((f, i) => `
        <li style="display:flex; align-items:center; justify-content:space-between; border:1px solid #eee; padding:8px 10px; border-radius:8px;">
          <span style="font-size:13px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:60%;">${f.name}</span>
          <span class="status" data-i="${i}" style="font-size:12px; color:#666; margin-left:8px;">
            ${f.status || "⏳ pending"}
          </span>
          <button data-i="${i}" class="rmBtn" style="background:#fee; color:#900; border:0; padding:4px 8px; border-radius:6px; cursor:pointer;">Remove</button>
        </li>
      `).join("");
    
      listEl.querySelectorAll(".rmBtn").forEach(btn => {
        btn.onclick = () => { stash.splice(+btn.dataset.i, 1); render(); };
      });
    }


    function addFiles(files) {
      const arr = Array.from(files || []).filter(isPdf);
      stash.push(...arr);
      render();
    }

    // wire
    qs("#closeImport", modal).onclick = () => modal.remove();
    qs("#clearBtn",  modal).onclick = () => { stash.length = 0; render(); statusBox.style.display = "none"; statusBox.textContent = ""; };
    browseBtn.onclick = () => fileInput.click();
    fileInput.onchange = () => addFiles(fileInput.files);

    // --- Drop zone specific ---
    // --- Drop zone specific ---
    ["dragenter","dragover"].forEach(evt =>
      dropZone.addEventListener(evt, e => {
        e.preventDefault();
        e.stopPropagation();
        dropZone.classList.add("drag-active");
        modal.classList.add("drag-active"); // highlight modal too
      })
    );
    
    ["dragleave","drop"].forEach(evt =>
      dropZone.addEventListener(evt, e => {
        e.preventDefault();
        e.stopPropagation();
        dropZone.classList.remove("drag-active");
        modal.classList.remove("drag-active");
      })
    );
    
    dropZone.addEventListener("drop", e => {
      e.preventDefault();
      e.stopPropagation();
      if (e.dataTransfer?.files) addFiles(e.dataTransfer.files);
    });
    
    // --- Global safety net (so dropping outside doesn’t navigate away) ---
    // ["dragenter","dragover","dragleave","drop"].forEach(evt =>
    //   modal.addEventListener(evt, e => {
    //     e.preventDefault();
    //     e.stopPropagation();
    //   })
    // );

    // --- Safer global safety net ---
    // Only prevent navigation if dropping directly on the modal background
    ["dragenter","dragover","dragleave","drop"].forEach(evt =>
      modal.addEventListener(evt, e => {
        if (e.target === modal) {
          e.preventDefault();
          e.stopPropagation();
        }
      })
    );


    if (initialFiles?.length) addFiles(initialFiles);

    qs("#commitBtn", modal).onclick = async (e) => {
      e.preventDefault?.(); // optional, doesn’t hurt
    
      if (!stash.length) {
        alert("No files to import.");
        return;
      }
    
      statusBox.style.display = "block";
      statusBox.textContent = `Importing ${stash.length} file(s)…`;
    
      let ok = 0, fail = 0, skip = 0;
    
      for (let i = 0; i < stash.length; i++) {
        const file = stash[i];
    
        // ✅ skip if already processed earlier
        if (file.status && file.status !== "⏳ pending") {
          continue;
        }
    
        const statusEl = listEl.querySelector(`.status[data-i="${i}"]`);
    
        try {
          const currentList = getCurrentList();
          if (!currentList) throw new Error("No current list selected.");
          const title = nameToText(file.name);
          const surrogate = await createNewItemForPDF(currentList.token, title);
    
          if (await checkIfPDFExists(surrogate)) {
            file.status = "⏭️ skipped";
            if (statusEl) statusEl.textContent = file.status;
            skip++;
            continue;
          }
    
          await uploadPdf(file, surrogate);
          file.status = "✅ done";
          if (statusEl) statusEl.textContent = file.status;
          ok++;
    
        } catch (err) {
          file.status = "❌ failed";
          if (statusEl) statusEl.textContent = file.status;
          console.error(err);
          fail++;
        }
      }
    
      statusBox.textContent += `\n\nFinished. Imported: ${ok} • Skipped: ${skip} • Failed: ${fail}`;
    };



  }

  // public entry
  window.openImportItemFilesModal = openImportModal;
})();





window.openImportItemsFromDropbox = async function (event) {
  // 🧭 1️⃣ Get current list first (sync)
  const currentList = getCurrentList();
  let token = currentList?.token || window.lastUsedListToken;
  let listName = currentList?.name || window.lastUsedListName;

  if (!token) {
    alert(window.translations?.select_list_to_continue || "Select a list to continue.");
    return;
  }

  // 🚫 2️⃣ Skip root “All Content”
  if (token === window.SESSION_USERNAME) {
    alert(window.translations?.select_list_to_continue || "Select a list to continue.");
    return;
  }

  // ⚡ 4️⃣ Prepare SDK *beforehand* (cached global init)
  if (!window.Dropbox) {
    showFlashMessage?.("⚙️ Loading Dropbox SDK…");
    await initDropbox?.();
  }

  // ⚠️ 5️⃣ Run Dropbox chooser
  try {
    Dropbox.choose({
      linkType: "direct",
      multiselect: true,
      extensions: [".pdf"],

      success: async (files) => {
        if (!files?.length) return;

        showFlashMessage?.(
          `📦 Importing ${files.length} file(s) into "${listName}"…`
        );

        for (const f of files) {
          const title = f.name.replace(/\.pdf$/i, "").trim();

          // ⭐ Normalize Dropbox PDF URL + force binary content
          let fileUrl = f.link
            .replace("www.dropbox.com", "dl.dropboxusercontent.com")
            .replace("dropbox.com/s/", "dl.dropboxusercontent.com/s/")
            .replace("?dl=0", "")
            .replace("?raw=1", "");

          if (!fileUrl.includes("?")) fileUrl += "?dl=1";
          else fileUrl += "&dl=1";

          try {
            // Create item for this PDF
            const surrogate = await createNewItemForPDF(token, title);
            if (!surrogate) throw new Error("Failed to create item");

            // ⭐ Ensure new item has owner + cloudflare metadata
            const newItemEl = document.querySelector(
              `.list-sub-item[data-value="${surrogate}"]`
            );

            if (newItemEl) {
              newItemEl.dataset.owner = window.SESSION_USERNAME;
              newItemEl.dataset.fileserver = "cloudflare";
            }

            // ⭐ Virtual-select for the uploader
            window.currentSurrogate = surrogate;
            window.currentItemOwner = window.SESSION_USERNAME;

            // ⭐ Ensure #directPdfUrl exists (create hidden input if needed)
            let urlInput = document.getElementById("directPdfUrl");
            if (!urlInput) {
              urlInput = document.createElement("input");
              urlInput.type = "hidden";
              urlInput.id = "directPdfUrl";
              document.body.appendChild(urlInput);
            }
            urlInput.value = fileUrl;

            // ⭐ Upload the PDF to Cloudflare / backend
            await uploadDirectPdfUrl();

            console.log(`✅ Imported ${title} → surrogate ${surrogate}`);
          } catch (err) {
            console.error(`❌ Failed to import ${f.name}:`, err);
            showFlashMessage?.(`⚠️ Could not import ${f.name}`);
          }
        }

        showFlashMessage?.("✅ Dropbox import finished!");
      },

      cancel: () => showFlashMessage?.("❌ Dropbox selection canceled."),
    });
  } catch (err) {
    console.error("⚠️ Dropbox chooser error:", err);
    alert("Dropbox chooser blocked or failed to open.");
  }
};



//21.12.2025
// ===============================
// Create items from Google Drive (multi-select)
// Mirrors openImportItemsFromDropbox
// ===============================
window.openImportItemsFromGoogleDrive = async function () {
  try {
    // 1️⃣ Resolve current list
    const currentList = getCurrentList();
    const token = currentList?.token;
    const listName = currentList?.name;

    if (!token) {
      alert(window.translations?.select_list_to_continue || "Select a list to continue.");
      return;
    }

    // 🚫 Skip root “All Content”
    if (token === window.SESSION_USERNAME) {
      alert(window.translations?.select_list_to_continue || "Select a list to continue.");
      return;
    }

    // 2️⃣ Preconditions
    if (!window.gapi || !window.google?.accounts?.oauth2) {
      alert("Google API not loaded yet.");
      return;
    }

    // 3️⃣ Load Picker
    await new Promise(resolve => gapi.load("picker", resolve));

    // 4️⃣ Get OAuth token (Drive read-only)
    const driveAccessToken = await getGoogleDriveToken();
    if (!driveAccessToken) {
      showFlashMessage?.("❌ Google authorization failed.");
      return;
    }

    // 5️⃣ Build Picker (PDFs, multi-select)
    // const view = new google.picker.View(google.picker.ViewId.DOCS)
    const view = new google.picker.DocsView(google.picker.ViewId.DOCS)

      .setIncludeFolders(true)
      .setSelectFolderEnabled(false) 
      .setMimeTypes("application/pdf");

      
    const picker = new google.picker.PickerBuilder()
      .addView(view)
      .enableFeature(google.picker.Feature.MULTISELECT_ENABLED)
      .setOAuthToken(driveAccessToken)
      .setOrigin(window.location.origin)
      .setCallback(async (data) => {
        if (data.action !== google.picker.Action.PICKED) return;

        const docs = data.docs || [];
        if (!docs.length) return;

        showFlashMessage?.(
          `📦 Importing ${docs.length} file(s) into "${listName}"…`
        );

        for (const doc of docs) {
          try {
            const title = (doc.name || "Imported PDF").replace(/\.pdf$/i, "").trim();

            // 6️⃣ Create item
            const surrogate = await createNewItemForPDF(token, title);
            if (!surrogate) throw new Error("Failed to create item");

            // Ensure ownership metadata (same intent as Dropbox path)
            const newItemEl = document.querySelector(
              `.list-sub-item[data-value="${surrogate}"]`
            );
            if (newItemEl) {
              newItemEl.dataset.owner = window.SESSION_USERNAME;
              newItemEl.dataset.fileserver = window.fileServer || "cloudflare";
            }

            // 7️⃣ Download PDF from Drive
            const res = await fetch(
              `https://www.googleapis.com/drive/v3/files/${doc.id}?alt=media`,
              { headers: { Authorization: `Bearer ${driveAccessToken}` } }
            );
            if (!res.ok) throw new Error(`Download failed (${res.status})`);

            const blob = await res.blob();
            if (blob.type && blob.type !== "application/pdf") {
              throw new Error("Downloaded file is not a PDF");
            }

            // 8️⃣ Upload via existing pipeline
            const file = new File([blob], doc.name || "imported.pdf", {
              type: "application/pdf",
            });

            await window.handleFileUpload(file, surrogate, "pdf");

            console.log(`✅ Imported ${doc.name} → surrogate ${surrogate}`);
          } catch (err) {
            console.error(`❌ Failed to import ${doc?.name}:`, err);
            showFlashMessage?.(`⚠️ Could not import ${doc?.name || "file"}`);
          }
        }

        showFlashMessage?.("✅ Google Drive import finished!");
      })
      .build();

    picker.setVisible(true);

  } catch (err) {
    console.error("⚠️ Google Drive chooser error:", err);
    alert("Google Drive import failed.");
  }
};




// Programmatic creator for PDF imports (no input box, no fallbacks).
// - Creates the item via createItemSubject(title, listToken)
// - Injects a .list-sub-item row into the left UI
// - Returns the new surrogate (string)

async function createNewItemForPDF(listToken, title, ownerOverride = "", order = 0) {
  if (!listToken) listToken = window.currentListToken;
  if (!listToken) throw new Error("No current list selected.");
  title = String(title || "").trim();
  if (!title) throw new Error("Empty title.");

  const ownerUser = String(ownerOverride || window.currentOwner?.username || "").trim();
  if (!ownerUser) throw new Error("No selected owner.");
  const { surrogate } = await createItemSubject(title, listToken, ownerUser, order);
  if (!surrogate) throw new Error("createItemSubject did not return a surrogate.");

  // 🧩 Find the exact .list-contents inside this list’s own container
  const list = document.querySelector(`.list-group-item[data-group='${listToken}']`);
  if (!list) throw new Error("⚠️ List not found for list token: " + listToken);

  const listContainer = list.querySelector(`.list-contents#list-${listToken}`);
  if (!listContainer) throw new Error("⚠️ .list-contents missing for " + listToken);

  // ✅ Ensure visible
  listContainer.style.display = "block";
  list.classList.add("active-list");
  const arrow = list.querySelector(".arrow");
  if (arrow) arrow.textContent = "▼";

  // ✅ Create item using the same renderer as normal sidebar rows.
  const itemData = {
    surrogate,
    owner: ownerUser,
    display_name: window.currentOwner?.display_name || ownerUser,
    title,
    fileserver: window.fileServer || "justhost",
    role_rank: 90
  };
  const html = renderSingleListItemHTML(itemData, listToken);
  const nodeWrap = document.createElement("div");
  nodeWrap.innerHTML = String(html || "").trim();
  const row = nodeWrap.firstElementChild;
  if (!row) throw new Error("Failed to render new item row.");

  let itemsWrapper = listContainer.querySelector(".list-items-wrapper");
  if (!itemsWrapper) {
    itemsWrapper = document.createElement("div");
    itemsWrapper.className = "list-items-wrapper";
    listContainer.appendChild(itemsWrapper);
  }

  const existingRows = Array.from(itemsWrapper.querySelectorAll(".list-sub-item[data-value]"));
  const nextIndex = existingRows.length + 1;
  row.dataset.orderIndex = String(nextIndex);

  const ownerEl = row.querySelector(".item-owner");
  if (ownerEl && !ownerEl.querySelector(".item-order")) {
    const orderEl = document.createElement("span");
    orderEl.className = "item-order";
    orderEl.textContent = `${nextIndex}.`;
    ownerEl.prepend(orderEl, document.createTextNode(" "));
  }

  // ✅ Insert item visually at requested order (0=last, N=position).
  const desiredPos = Math.max(0, parseInt(order, 10) || 0);
  if (desiredPos > 0) {
    const rows = Array.from(itemsWrapper.querySelectorAll(".list-sub-item[data-value]"));
    const anchor = rows[desiredPos - 1] || null;
    if (anchor) {
      itemsWrapper.insertBefore(row, anchor);
    } else {
      itemsWrapper.appendChild(row);
    }
  } else {
    itemsWrapper.appendChild(row);
  }
  if (typeof updateListItemOrderNumbers === "function") {
    updateListItemOrderNumbers(itemsWrapper, true);
  }

  row.classList.add("just-added");
  setTimeout(() => row.classList.remove("just-added"), 3000);

  console.log(`✅ Inserted new item ${surrogate} into visual list ${listToken}`);
  return surrogate;
}








function getCurrentList() {
  // Helper to find from cached JSON
  function findInCache(token) {
    // Try in-memory caches first
    const caches = [window._addToListCache?.my, window._addToListCache?.other];
    for (const lists of caches) {
      if (!lists) continue;
      const match = lists.find(l => l.token === token);
      if (match) return match.name || "(unnamed list)";
    }

    // Try offline cache
    try {
      const offlineKeys = Object.keys(localStorage).filter(k => k.startsWith("offline-lists-"));
      for (const key of offlineKeys) {
        const data = JSON.parse(localStorage.getItem(key));
        const found = data.owned?.find(l => l.token === token) || data.accessible?.find(l => l.token === token);
        if (found) return found.name || "(unnamed list)";
      }
    } catch (err) {
      console.warn("⚠️ Cache lookup failed:", err);
    }

    return null;
  }

  // Determine current token
  const activeItem = document.querySelector(".list-sub-item.active");
  const activeToken =
    activeItem?.dataset.token ||
    document.querySelector(".group-item.active, .group-item.open")?.dataset.group ||
    window.currentListToken ||
    window.location.pathname.split("/").filter(Boolean)[0] ||
    null;

  if (!activeToken) return null;

  // Prefer cache data
  const cachedName = findInCache(activeToken);
  if (cachedName) {
    return { token: activeToken, name: cachedName };
  }

  // Fallback to DOM if cache not found
  const group = document.querySelector(`.group-item[data-group="${activeToken}"]`);
  const domName = group?.dataset.name || group?.querySelector(".list-title")?.textContent?.trim() || "(unnamed list)";

  return { token: activeToken, name: domName };
}






