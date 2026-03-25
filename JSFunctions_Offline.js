logStep("JSFunctions_Offline.js executed");

function showOfflineBanner() {
  if (!navigator.onLine) {
    const banner = document.createElement("div");
    banner.textContent = "📴 You’re offline — viewing cached content.";
    banner.classList.add("offline-banner");
    document.body.prepend(banner);
  }
}




function toggleOfflineStatus(token, el) {
  const titleEl = document.getElementById(`list-title-${token}`);

  // Fallback-safe localized strings
  const t = window.translations || {};
  const makeOffline = t.make_offline || "Make available offline";
  const removeOffline = t.remove_offline || "Remove from offline";
  const offlineRemovedMsg = t.offline_removed || "Offline copy removed";

  if (isListFlaggedOffline(token)) {
    localStorage.removeItem(`offline-list-${token}`);
    removeFromOfflineFlags(token);
    el.textContent = "📥 " + makeOffline;

    if (titleEl) titleEl.classList.remove("offline-flagged");

    showFlashNear(el, offlineRemovedMsg);
  } else {
    makeListOffline(token).then(() => {
      el.textContent = "📤 " + removeOffline;

      if (titleEl) titleEl.classList.add("offline-flagged");

    //   refreshPdfCacheForList(token); // ❌ redundant
    });
  }
}





function isListFlaggedOffline(token) {
    const flagged = JSON.parse(localStorage.getItem("offline-enabled-lists") || "[]");
    return flagged.includes(token);
}



function updateOfflineMenuLabels() {
  const t = window.translations || {};
  const makeOffline = t.make_offline || "Make available offline";
  const removeOffline = t.remove_offline || "Remove from offline";

  const flaggedTokens = JSON.parse(localStorage.getItem("offline-enabled-lists") || "[]");

  document.querySelectorAll(".toggle-offline-status").forEach(el => {
    const token = el.dataset.token;
    const titleEl = document.getElementById(`list-title-${token}`);

    if (flaggedTokens.includes(token)) {
      // ✅ Mark menu button
      el.textContent = "📤 " + removeOffline;
      el.classList.add("offline-enabled");

      // ✅ Mark title visually (green bubble / offline flag)
      if (titleEl) titleEl.classList.add("offline-flagged");
    } else {
      el.textContent = "📥 " + makeOffline;
      el.classList.remove("offline-enabled");

      if (titleEl) titleEl.classList.remove("offline-flagged");
    }
  });
}


function refreshOfflineListIfFlagged(token) {
  if (isListFlaggedOffline(token)) {
    setTimeout(() => {
      console.log(`🔄 Refreshing offline cache for list: ${token}`);
      makeListOffline(token);
    }, 100); // Delay ensures DOM order is settled after changes
  }
}



function flagListOffline(token) {
    const flagged = new Set(JSON.parse(localStorage.getItem("offline-enabled-lists") || "[]"));
    flagged.add(token);
    localStorage.setItem("offline-enabled-lists", JSON.stringify(Array.from(flagged)));
}

function removeFromOfflineFlags(token) {
    const flagged = JSON.parse(localStorage.getItem("offline-enabled-lists") || "[]");
    const updated = flagged.filter(t => t !== token);
    localStorage.setItem("offline-enabled-lists", JSON.stringify(updated));
}



/**
 * 🔍 checkAndUpdateCache()
 *
 * Checks if a resource in cache is up-to-date using HTTP headers
 * (ETag, Last-Modified, X-* version hints). If stale or missing,
 * it re-fetches and updates the cache.
 *
 * Returns one of:
 *  "up-to-date" → cache present and headers match
 *  "updated"    → re-fetched and stored new version
 *  "cached"     → offline but cache used
 *  "missing"    → no cache and offline
 */
async function checkAndUpdateCache(url, {
  owner,
  surrogate,
  page = null,
  type = "pdf",  // e.g. pdf, annot, audio
  cacheName = "textwhisper-offline-pdfs",
  versionHeaders = ["X-Pdf-Version", "X-Annotation-Version", "ETag", "Last-Modified"]
} = {}) {
  try {
    const cache = await caches.open(cacheName);
    const cleanUrl = url.split("?")[0];
    const keyParts = [type, owner, surrogate];
    if (page) keyParts.push(`p${page}`);
    const versionKey = `${type}-version-${keyParts.join("-")}`;

    const storedVersion = localStorage.getItem(versionKey);
    const cached = await cache.match(cleanUrl);

    // 📴 Offline shortcut
    if (!navigator.onLine) return cached ? "cached" : "missing";

    // 🔍 Lightweight HEAD check
    const headRes = await fetch(cleanUrl, { method: "HEAD", cache: "no-store" });
    if (!headRes.ok) return cached ? "cached" : "missing";

    // Extract any recognizable version hint
    const newVersion =
      versionHeaders.map(h => headRes.headers.get(h)).find(Boolean) ||
      headRes.headers.get("Content-Length") ||
      Date.now().toString();

    if (newVersion === storedVersion && cached) {
      // 🟢 Already current
      return "up-to-date";
    }

    // ♻️ Update cache
    const res = await fetch(cleanUrl, { cache: "reload" });
    if (res.ok) {
      await cache.put(cleanUrl, res.clone());
      localStorage.setItem(versionKey, newVersion);
      console.log(`💾 Updated ${type} cache: ${cleanUrl}`);
      return "updated";
    }

    return cached ? "cached" : "missing";
  } catch (err) {
    console.warn(`⚠️ checkAndUpdateCache(${type}) failed:`, url, err);
    return "missing";
  }
}


// async function makeListOfflineXXX(token) {
//   console.log("📥 Preparing offline cache:", token);
//   initialiseProgressSpinner("Caching list...");
//   updateProgressSpinner(0);

//   try {
//     // 1️⃣ Fetch full JSON structure (lists + items)
//     const res = await fetch(`/getOwnersListsJSON.php?token=${token}`, { credentials: "include" });
//     if (!res.ok) throw new Error(`Server ${res.status}`);
//     const data = await res.json();
//     if (!data?.owned && !data?.accessible) throw new Error("Invalid list structure");

//     // 2️⃣ Locate target list
//     const findList = (t, lists = []) =>
//       lists.reduce((f, l) => f || (l.token === t ? l : findList(t, l.children)), null);

//     const targetList = findList(token, data.owned) || findList(token, data.accessible);
//     if (!targetList) throw new Error("List not found in data");

//     const items = targetList.items || [];
//     if (!items.length) throw new Error("List has no items");

//     // 3️⃣ Cache all items
//     let done = 0;
//     for (const { surrogate, owner, fileserver } of items) {
//       if (!surrogate || !owner) continue;
//       try {
//         // 📝 Text
//         const textRes = await fetch(`/getText.php?q=${surrogate}`, { credentials: "include" });
//         if (textRes.ok) {
//           const text = await textRes.text();
//           localStorage.setItem(`offline-text-${surrogate}`, text);
//         }

//         // 💬 Comments + highlights (textmarks JSON)
//         try {
//           const annotator = window.SESSION_USERNAME || owner;
//           const commentsRes = await fetch(
//             `/getTextComments.php?surrogate=${encodeURIComponent(surrogate)}&annotator=${encodeURIComponent(annotator)}`,
//             { credentials: "include" }
//           );

//           if (commentsRes.ok) {
//             const raw = (await commentsRes.text()).trim();
//             const key = `offline-comments-${surrogate}-${annotator}`;

//             if (raw) {
//               localStorage.setItem(key, raw);    // raw JSON string
//             } else {
//               localStorage.removeItem(key);      // no comments stored
//             }
//           }
//         } catch (e) {
//           console.warn(`⚠️ Failed to cache comments for ${surrogate}:`, e);
//         }

//         // 📄 PDF / ✏️ Annotations / 🎵 Audio
//         await Promise.all([
//           cachePdfUrls(surrogate, fileserver, owner),
//           cacheAnnotations(surrogate, fileserver, owner),
//           cacheAudioFiles(surrogate, fileserver, owner)
//         ]);

//         console.log(`📦 ${surrogate} → cached`);
//       } catch (err) {
//         console.warn(`⚠️ ${surrogate} failed:`, err);
//       }

//       updateProgressSpinner(++done / items.length * 100);
//     }

//     // 4️⃣ Store offline data + flag UI
//     const listData = { owner: data.owner, owned: [], accessible: [targetList] };
//     localStorage.setItem(`offline-lists-${token}`, JSON.stringify(listData));
//     flagListOffline(token);

//     const name =
//       document.getElementById(`list-title-${token}`)?.textContent ||
//       targetList.title ||
//       "Offline List";

//     localStorage.setItem(`offline-name-${token}`, name);
//     document.getElementById(`list-title-${token}`)?.classList.add("offline-flagged");

//     hideProgressSpinner();
//     showFlashNear(document.body, `✅ Cached ${items.length} items`);
//     console.log(`✅ Done: list ${token}`);
//   } catch (err) {
//     hideProgressSpinner();
//     console.error("❌ Offline caching failed:", err);
//     showFlashNear(document.body, `❌ Offline caching failed: ${err.message}`);
//   }
// }


// async function makeListOfflineXXX(token) {
//   console.log("📥 Preparing offline cache:", token);
//   initialiseProgressSpinner("Caching list...");
//   updateProgressSpinner(0);

//   try {
//     // 1️⃣ Fetch full JSON structure (lists + items)
//     const res = await fetch(`/getOwnersListsJSON.php?token=${token}`, { credentials: "include" });
//     if (!res.ok) throw new Error(`Server ${res.status}`);
//     const data = await res.json();
//     if (!data?.owned && !data?.accessible) throw new Error("Invalid list structure");

//     // 2️⃣ Locate target list
//     const findList = (t, lists = []) =>
//       lists.reduce((f, l) => f || (l.token === t ? l : findList(t, l.children)), null);

//     const targetList = findList(token, data.owned) || findList(token, data.accessible);
//     if (!targetList) throw new Error("List not found in data");

//     const items = targetList.items || [];
//     if (!items.length) throw new Error("List has no items");

//     // 3️⃣ Cache all items
//     let textFailed = false;   // ← add before loop
//     let done = 0;
//     for (const { surrogate, owner, fileserver } of items) {
//       if (!surrogate || !owner) continue;

//       try {
//         //
//         // 📝 TEXT (with VERSION)
//         //
//         const textRes = await fetch(`/getText.php?q=${surrogate}`, { credentials: "include" });
//         if (textRes.ok) {
//           const text = await textRes.text();
//           const serverVer = textRes.headers.get("X-Text-Version") || "";

//           localStorage.setItem(`offline-text-${surrogate}`, text);
//           if (serverVer) {
//             localStorage.setItem(`offline-text-version-${surrogate}`, serverVer);
//           }
//         } else {
//             textFailed = true;   // ← add
//         }        

//         //
//         // 💬 COMMENTS (with VERSION)
//         //
//         try {
//           const annotator = window.SESSION_USERNAME || owner;
//           const commentsRes = await fetch(
//             `/getTextComments.php?surrogate=${encodeURIComponent(surrogate)}&annotator=${encodeURIComponent(annotator)}`,
//             { credentials: "include" }
//           );

//           if (commentsRes.ok) {
//             const raw = (await commentsRes.text()).trim();
//             const serverVer = commentsRes.headers.get("X-Comments-Version") || "";

//             const key    = `offline-comments-${surrogate}-${annotator}`;
//             const verKey = `offline-comments-version-${surrogate}-${annotator}`;

//             if (raw) {
//               localStorage.setItem(key, raw);
//               if (serverVer) localStorage.setItem(verKey, serverVer);
//             } else {
//               localStorage.removeItem(key);
//               localStorage.removeItem(verKey);
//             }
//           }
//         } catch (e) {
//           console.warn(`⚠️ Failed to cache comments for ${surrogate}:`, e);
//         }

//         //
//         // 📄 PDF / ✏️ Annotations / 🎵 Audio (unchanged)
//         //
//         await Promise.all([
//           cachePdfFiles(surrogate, fileserver, owner),
//           cachePdfAnnotations(surrogate, fileserver, owner),
//           cacheAudioFiles(surrogate, fileserver, owner)
//         ]);

//         console.log(`📦 ${surrogate} → cached`);
//       } catch (err) {
//         console.warn(`⚠️ ${surrogate} failed:`, err);
//         textFailed = true;     // ← add if item completely fails
//       }

//       updateProgressSpinner(++done / items.length * 100);
//     }
    
//     //Do not mark offline if text missing for any item
//     if (textFailed) {
//       throw new Error("Some items failed to cache text");
//     }    

//     // 4️⃣ Store offline list metadata + flag UI
//     const listData = { owner: data.owner, owned: [], accessible: [targetList] };
//     localStorage.setItem(`offline-lists-${token}`, JSON.stringify(listData));
//     flagListOffline(token);

//     const name =
//       document.getElementById(`list-title-${token}`)?.textContent ||
//       targetList.title ||
//       "Offline List";

//     localStorage.setItem(`offline-name-${token}`, name);
//     document.getElementById(`list-title-${token}`)?.classList.add("offline-flagged");

//     hideProgressSpinner();
//     showFlashNear(document.body, `✅ Cached ${items.length} items`);
//     console.log(`✅ Done: list ${token}`);

//   } catch (err) {
//     hideProgressSpinner();
//     console.error("❌ Offline caching failed:", err);
//     showFlashNear(document.body, `❌ Offline caching failed: ${err.message}`);
//   }
// }


async function makeListOffline(token) {
  console.log("📥 Preparing offline cache:", token);
  initialiseProgressSpinner("Caching list...");
  updateProgressSpinner(0);

  try {
    // 1️⃣ Fetch full JSON structure (lists + items)
    const res = await fetch(`/getOwnersListsJSON.php?token=${token}`, { credentials: "include" });
    if (!res.ok) throw new Error(`Server ${res.status}`);
    const data = await res.json();
    if (!data?.owned && !data?.accessible) throw new Error("Invalid list structure");

    // 2️⃣ Locate target list
    const findList = (t, lists = []) =>
      lists.reduce((f, l) => f || (l.token === t ? l : findList(t, l.children)), null);

    const targetList = findList(token, data.owned) || findList(token, data.accessible);
    if (!targetList) throw new Error("List not found in data");

    const items = targetList.items || [];
    if (!items.length) throw new Error("List has no items");

    // 2b️⃣ Cache music role filters for owners in this list (align with offline flow)
    const owners = [...new Set(items.map(i => i.owner).filter(Boolean))];
    if (owners.length) {
      for (const owner of owners) {
        await getMusicRoleFilters(owner);
      }
    }

    // 3️⃣ Cache all items
    let textFailed = false;
    let done = 0;

    for (const { surrogate, owner, fileserver } of items) {
      if (!surrogate || !owner) continue;

      try {
        //
        // 📝 TEXT (WITH VERSION CHECK — PATCHED)
        //
        const cachedVersion =
          localStorage.getItem(`offline-text-version-${surrogate}`) || "";

        const textRes = await fetch(
          `/getText.php?q=${surrogate}&v=${cachedVersion}`,
          { credentials: "include" }
        );

        const serverVersion =
          textRes.headers.get("X-Text-Version") || "";

        if (serverVersion === cachedVersion) {
          console.log("💨 Text unchanged:", surrogate);
        } else {
          const text = await textRes.text();
          localStorage.setItem(`offline-text-${surrogate}`, text);
          localStorage.setItem(`offline-text-version-${surrogate}`, serverVersion);
          console.log("🆕 Cached updated text:", surrogate);
        }


        //
        // 💬 COMMENTS (WITH VERSION CHECK — PATCHED)
        //
        try {
          const annotator = window.SESSION_USERNAME || owner;
        
          const cachedCommentVersion =
            localStorage.getItem(`offline-comments-version-${surrogate}-${annotator}`) || "";
        
          const commentsRes = await fetch(
            `/getTextComments.php?surrogate=${encodeURIComponent(surrogate)}&annotator=${encodeURIComponent(annotator)}&v=${cachedCommentVersion}`,
            { credentials: "include" }
          );
        
          const serverCommentVersion =
            commentsRes.headers.get("X-Comments-Version") || "";
        
          const key    = `offline-comments-${surrogate}-${annotator}`;
          const verKey = `offline-comments-version-${surrogate}-${annotator}`;
        
            if (commentsRes.status === 304 || serverCommentVersion === cachedCommentVersion) {
              console.log("💨 Comments unchanged:", surrogate);
              // keep existing cache
            } else {
              const raw = (await commentsRes.text()).trim();
            
              if (raw.length > 0) {
                let parsed = {};
                try { parsed = JSON.parse(raw); } catch {}
            
                const comments = parsed.comments || [];
            
                const obj = {
                  surrogate,
                  owner,
                  annotator,
                  updated_at: serverCommentVersion,
                  comments
                };
            
                localStorage.setItem(key, JSON.stringify(obj));
                localStorage.setItem(verKey, serverCommentVersion);
              } else {
                // only delete if server explicitly returns empty JSON body
                localStorage.removeItem(key);
                localStorage.removeItem(verKey);
              }
            }

        } catch (e) {
          console.warn(`⚠️ Failed to cache comments for ${surrogate}:`, e);
        }
    
        

        //
        // 📄 PDF / ✏️ Annotations / 🎵 Audio
        //
        await Promise.all([
          cachePdfFiles(surrogate, fileserver, owner),
          cachePdfAnnotations(surrogate, fileserver, owner),
          cacheAudioFiles(surrogate, fileserver, owner)
        ]);

        console.log(`📦 ${surrogate} → cached`);
      } catch (err) {
        console.warn(`⚠️ ${surrogate} failed:`, err);
        textFailed = true;
      }

      updateProgressSpinner(++done / items.length * 100);
    }

    if (textFailed) {
      throw new Error("Some items failed to cache text");
    }

    // 4️⃣ Store offline list metadata + flag UI
    const listData = { owner: data.owner, owned: [], accessible: [targetList] };
    localStorage.setItem(`offline-lists-${token}`, JSON.stringify(listData));
    flagListOffline(token);

    const name =
      document.getElementById(`list-title-${token}`)?.textContent ||
      targetList.title ||
      "Offline List";

    localStorage.setItem(`offline-name-${token}`, name);
    document.getElementById(`list-title-${token}`)?.classList.add("offline-flagged");

    hideProgressSpinner();
    showFlashNear(document.body, `✅ Cached ${items.length} items`);
    console.log(`✅ Done: list ${token}`);

  } catch (err) {
    hideProgressSpinner();
    console.error("❌ Offline caching failed:", err);
    showFlashNear(document.body, `❌ Offline caching failed: ${err.message}`);
  }
}



async function cachePdfFiles(surrogate, fileServerRead, owner) {
  const versionKey = `pdf-version-${owner}-${surrogate}`;
  const storedVersion = localStorage.getItem(versionKey) || "0";

  const cache = await caches.open("textwhisper-offline-pdfs");

  // Canonical URL — same format as viewer
  const baseUrl = `https://r2-worker.textwhisper.workers.dev/${owner}/pdf/temp_pdf_surrogate-${surrogate}.pdf`;

  // 1️⃣ HEAD check (fast)
  let head;
  try {
    head = await fetch(baseUrl, { method: "HEAD", cache: "no-store" });
    if (!head.ok) return;            // PDF does not exist
  } catch {
    return;                          // offline → skip
  }

  const newVersion =
    head.headers.get("X-Pdf-Version") ||
    head.headers.get("ETag") ||
    head.headers.get("Last-Modified") ||
    storedVersion;

  // 2️⃣ If same version AND cached → skip
  const cached = await cache.match(baseUrl);
  if (newVersion === storedVersion && cached) return;

  // 3️⃣ Otherwise download newest PDF
  try {
    const fresh = await fetch(baseUrl, { cache: "no-store" });
    if (fresh.ok && fresh.headers.get("Content-Type")?.includes("pdf")) {
      await cache.put(baseUrl, fresh.clone());
      localStorage.setItem(versionKey, newVersion);
    }
  } catch {
    // ignore — offline or failure
  }
}




// async function cachePdfUrls(surrogate, fileserver, owner) {
//   if (!owner) return console.warn("⚠️ cachePdfUrls: missing owner for", surrogate), false;

//   const fs = (fileserver || "").trim().toLowerCase();
//   const url = (fs === "cloudflare" || fs === "r2" || fs === "worker")
//     ? `https://r2-worker.textwhisper.workers.dev/${owner}/pdf/temp_pdf_surrogate-${surrogate}.pdf`
//     : `/File_getPDF.php?type=pdf&owner=${owner}&surrogate=${surrogate}`;

//   try {
//     // ⚡ Fast check: skip if already in cache and not stale
//     const status = await checkAndUpdateCache(url, {
//       owner,
//       surrogate,
//       type: "pdf",
//       cacheName: "textwhisper-offline-pdfs"
//     });

//     // 🧩 Interpret result
//     if (status === "up-to-date") {
//       // console.log(`📄 PDF ${surrogate} already cached`);
//       return false; // no update needed
//     }

//     if (status === "stale") {
//       console.log(`📄 PDF ${surrogate} updated`);
//       return true;
//     }

//     if (status === "cached") return false;
//     if (status === "missing") {
//       console.warn(`⚠️ PDF ${surrogate} missing from cache`);
//       return false;
//     }

//     return true;
//   } catch (err) {
//     console.warn("⚠️ cachePdfUrls failed:", surrogate, err);
//     return false;
//   }
// }




async function cachePdfAnnotations(surrogate, fileserver, owner) {
  if (!owner) return console.warn(`⚠️ cachePdfAnnotations: missing owner for ${surrogate}`), false;

  const fs = (fileserver || "").trim().toLowerCase();
  const annotator = window.SESSION_USERNAME || owner;
  const cache = await caches.open("textwhisper-annotations");

  // 🧠 Cloudflare-style URLs for pages (1..n)
  const basePrefix = `${owner}/annotations/annotation-${surrogate}-p`;
  const userPrefix = `${owner}/annotations/users/${annotator}/annotation-${surrogate}-p`;

  // 📴 If already in cache, skip everything
  const keys = await cache.keys();
  const hasCached = keys.some(req => req.url.includes(`annotation-${surrogate}-`));
  if (hasCached) {
    // console.log(`🧩 Annotations for ${surrogate} already cached — skipping`);
    return true;
  }

  // 📴 Skip entirely if offline
  if (!navigator.onLine) return true;

  // =========================
  // ☁️ CLOUDFLARE / WORKER
  // =========================
  if (["cloudflare", "r2", "worker"].includes(fs)) {
    const listUrls = [
      `https://r2-worker.textwhisper.workers.dev/list?prefix=${encodeURIComponent(basePrefix)}`,
      `https://r2-worker.textwhisper.workers.dev/list?prefix=${encodeURIComponent(userPrefix)}`
    ];

    for (const listUrl of listUrls) {
      try {
        const listRes = await fetch(listUrl, { cache: "no-store" });
        if (!listRes.ok) continue;

        const objects = await listRes.json();
        const pngs = objects.filter(o => o.key.endsWith(".png"));

        for (const obj of pngs) {
          const fileUrl = `https://r2-worker.textwhisper.workers.dev/${obj.key}`;
          const res = await fetch(fileUrl, { cache: "reload" });
          if (res.ok && res.headers.get("Content-Type")?.includes("image")) {
            await cache.put(fileUrl, res.clone());
            console.log(`🧩 Cached annotation: ${fileUrl}`);
          }
        }
      } catch (err) {
        console.warn(`⚠️ Failed to cache annotations for prefix ${listUrl}:`, err);
      }
    }

    return true;
  }

  // =========================
  // 💻 PHP BACKEND (JustHost)
  // =========================
  try {
    const urls = [
      `/File_getAnnotation.php?surrogate=${surrogate}&owner=${owner}&annotator=${owner}`,
      `/File_getAnnotation.php?surrogate=${surrogate}&owner=${owner}&annotator=${annotator}`
    ];

    for (const url of urls) {
      const res = await fetch(url, { credentials: "include", cache: "reload" });
      if (res.ok && res.headers.get("Content-Type")?.includes("image")) {
        await cache.put(res.url, res.clone());
        console.log("💾 Cached annotation:", res.url);
      }
    }
  } catch (err) {
    console.warn("⚠️ PHP annotation caching failed:", err);
  }

  return true;
}




// ==========================
// 🎵 Cache Audio Files + Metadata for Offline
// ==========================


// async function cacheAudioFilesXXXX(surrogate, fileserver, owner) {
//   try {
//     if (!owner) {
//       console.warn("⚠️ cacheAudioFiles: missing owner for", surrogate);
//       return false;
//     }

//     const fs = (fileserver || "").trim().toLowerCase();
//     if (!["cloudflare", "r2", "worker"].includes(fs)) {
//       console.log(`ℹ️ Skipping audio cache for ${surrogate} (not Cloudflare)`);
//       return false;
//     }

//     const prefix = `${owner}/surrogate-${surrogate}/files/`;
//     const listUrl = `https://r2-worker.textwhisper.workers.dev/list?prefix=${encodeURIComponent(prefix)}`;
//     const publicBase = `https://pub-1afc23a510c147a5a857168f23ff6db8.r2.dev`;

//     // ✅ Correct live cache name
//     const metaCache = await caches.open("textwhisper-music-metadata");

//     // ⚡ Check if metadata is already cached (skip if recent)
//     const metaKey = `${location.origin}/cloudflare-list?surrogate=${surrogate}`;
//     const existing = await metaCache.match(metaKey);
//     if (existing) {
//       console.log(`📦 Using existing metadata for ${surrogate}`);
//       return true;
//     }

//     // ☁️ Fetch Cloudflare list
//     const res = await fetch(listUrl, { cache: "no-store" });
//     if (!res.ok) throw new Error(`HTTP ${res.status}`);
//     const data = await res.json();
//     if (!Array.isArray(data)) throw new Error("Invalid Cloudflare list response");

//     // 💾 Store metadata with the same key as live version
//     await metaCache.put(
//       metaKey,
//       new Response(JSON.stringify(data), {
//         headers: { "Content-Type": "application/json" }
//       })
//     );

//     // 🎧 Optional fast header check (don’t cache, just verify)
//     const audioRegex = /\.(mp3|wav|ogg|m4a|flac|aac|aif|aiff|mid|midi|webm)$/i;
//     const validFiles = data.filter(obj => audioRegex.test(obj.key));
//     console.log(`🎧 Stored metadata for ${validFiles.length} audio files (${surrogate})`);

//     return true;
//   } catch (err) {
//     console.warn("⚠️ cacheAudioFiles failed:", surrogate, err);
//     return false;
//   }
// }


async function cacheAudioFiles(surrogate, fileServer, owner) {
  try {
    if (!owner) {
      console.warn("⚠️ cacheAudioFiles: missing owner for", surrogate);
      return false;
    }

    // Cloudflare only
    if ((fileServer || "").trim().toLowerCase() !== "cloudflare") {
      console.log(`ℹ️ Skipping audio cache for ${surrogate} (not Cloudflare)`);
      return false;
    }

    const prefix = `${owner}/surrogate-${surrogate}/files/`;
    const listUrl = `https://r2-worker.textwhisper.workers.dev/list?prefix=${encodeURIComponent(prefix)}`;
    const publicBase = `https://pub-1afc23a510c147a5a857168f23ff6db8.r2.dev`;

    const manifestKey = `audioList-${surrogate}`;

    let serverList = [];

    // ======================================================
    // 1️⃣ ONLINE: Fetch manifest from Cloudflare R2
    // ======================================================
    if (navigator.onLine) {
      try {
        const res = await fetch(listUrl, { cache: "no-store" });
        if (res.ok) {
          const data = await res.json();

          // Worker returns a plain array, not {objects:[]}
          serverList = Array.isArray(data) ? data : [];
        }
      } catch (err) {
        console.warn("⚠️ Manifest fetch failed:", err);
      }
    }

    // ======================================================
    // 2️⃣ OFFLINE fallback: use stored manifest
    // ======================================================
    if (!serverList.length) {
      const raw = localStorage.getItem(manifestKey);
      if (raw) {
        try {
          serverList = JSON.parse(raw);
        } catch {
          console.warn("⚠️ Corrupt stored audio manifest");
          return false;
        }
      } else {
        console.log("📴 No stored audio manifest for", surrogate);
        return false;
      }
    }

    // ======================================================
    // 3️⃣ Build UI list (NOT the actual audio files)
    // ======================================================
    const uiList = serverList.map(obj => ({
      key: obj.key,
      name: obj.key.split("/").pop(),
      size: obj.size || 0,
      url: `${publicBase}/${obj.key}`,
      fileServer: "cloudflare"
    }));

    // ======================================================
    // 4️⃣ Save UI list via existing helpers
    // ======================================================
    saveCachedAudioList(surrogate, uiList);

    console.log(`🎵 Saved audio file list for ${surrogate}: ${uiList.length} items`);

    // ======================================================
    // 5️⃣ Placeholder: actual audio caching can be added here
    // ======================================================
    // TODO: If we want to pre-cache audio blobs in the future,
    //       the logic can be added here. For now, audio is cached
    //       on-demand in the music player when the user plays it.

    return true;

  } catch (err) {
    console.error("❌ cacheAudioFiles failed:", err);
    return false;
  }
}

async function getMusicRoleFilters(owner) {
  const rolesKey = `musicRoles-${owner}`;
  const activeKey = `musicRole-${owner}`;

  const readCache = () => {
    let roles = ["All"];
    try {
      const cached = localStorage.getItem(rolesKey);
      roles = cached ? JSON.parse(cached) : ["All"];
    } catch {
      roles = ["All"];
    }
    const active = localStorage.getItem(activeKey) || "All";
    return { roles, active };
  };

  if (!owner) return readCache();

  // Offline: return cached values
  if (!navigator.onLine) return readCache();

  let roles = ["All"];
  let active = "All";

  try {
    const r1 = await fetch(
      `/getMemberRoles_json.php?owner=${encodeURIComponent(owner)}`,
      { credentials: "include" }
    );
    if (r1.ok) {
      const data = await r1.json();
      roles = Array.isArray(data.roles) ? data.roles : ["All"];
      localStorage.setItem(rolesKey, JSON.stringify(roles));
    }
  } catch (err) {
    console.warn("⚠️ Failed to fetch music roles for", owner, err);
    return readCache();
  }

  if (window.SESSION_USERNAME) {
    try {
      const r2 = await fetch(
        `/getMemberRoleAssignment.php?owner=${encodeURIComponent(owner)}`,
        { credentials: "include" }
      );
      if (r2.ok) {
        const data = await r2.json();
        active = data.role || "All";
        localStorage.setItem(activeKey, active);
      }
    } catch (err) {
      console.warn("⚠️ Failed to fetch music role assignment for", owner, err);
      const cachedActive = localStorage.getItem(activeKey);
      if (cachedActive) active = cachedActive;
    }
  } else {
    const cachedActive = localStorage.getItem(activeKey);
    if (cachedActive) active = cachedActive;
  }

  return { roles, active };
}

window.getMusicRoleFilters = getMusicRoleFilters;





async function loadAllOfflineLists() {
  const tokens = JSON.parse(localStorage.getItem("offline-enabled-lists") || "[]");

  const container = document.getElementById("listManager");
  if (!container) return;

  if (!tokens.length) {
    container.innerHTML = "<p class='text-light'>⚠️ No lists are available offline.</p>";
    return;
  }

  // 1️⃣ Load all offline lists first
  for (const token of tokens) {
    console.log("📦 Loading offline list:", token);
    await loadOfflineList(token);
  }

  // ✅ 1b: Re-apply offline menu labels and green indicator
  if (typeof updateOfflineMenuLabels === "function") {
    updateOfflineMenuLabels();
  }

  // ✅ 1c: Also mark offline-flagged titles visually
  tokens.forEach(token => {
    const titleEl = document.getElementById(`list-title-${token}`);
    if (titleEl) titleEl.classList.add("offline-flagged");
  });

  // 2️⃣ Expand the correct group if a surrogate is in the URL
  const urlParts = window.location.pathname.split("/");
  const currentSurrogate = urlParts[2] || null;

  if (currentSurrogate) {
    const listItem = document.querySelector(`.list-sub-item[data-value="${currentSurrogate}"]`);
    if (listItem) {
      const parentList = listItem.closest(".list-group-item, .list-group-wrapper");
      if (parentList) {
        const listContents = parentList.querySelector(".list-contents, .group-contents");
        if (listContents) listContents.style.display = "block";
        parentList.classList.add("active-list");
        const arrow = parentList.querySelector(".arrow, .group-arrow");
        if (arrow) arrow.textContent = "▼";
      }

      const text = listItem.querySelector(".item-title")?.getAttribute("data-full-text");
      if (text) {
        console.log("🧠 Auto-selecting item after offline refresh:", currentSurrogate);
        selectOfflineItem(currentSurrogate, text);
      }
    }
  }
}





function loadOfflineList(token) {
  const raw = localStorage.getItem(`offline-lists-${token}`);
  if (!raw) {
    console.warn("⚠️ No offline data for token:", token);
    return;
  }

  let data;
  try {
    data = JSON.parse(raw);
  } catch {
    console.error("❌ Failed to parse offline JSON for token:", token);
    return;
  }

  const container = document.getElementById("offlineLists");
  if (!container) return;

  // 🧩 Flatten accessible groups (💬 invited + ⭐ followed)
  let accessibleLists = [];
  if (Array.isArray(data.accessible)) {
    for (const group of data.accessible) {
      if (Array.isArray(group.children)) {
        accessibleLists.push(...group.children);
      }
    }
  }

  // 🧩 Merge owned + accessible for display
  const allLists = [...(data.owned || []), ...accessibleLists];

  for (const list of allLists) {
    const items = Array.isArray(list.items) ? list.items : [];

    const listHtml = `
      <div class="list-group-wrapper" data-group="${list.token}">
        <div class="sidebar-section-header collapsible-group">
          <span class="group-arrow">▶</span>
          <span class="list-title offline-flagged" id="list-title-${list.token}">
            ${list.title}
          </span>
          <span class="list-count">(${items.length})</span>
        </div>
        <div class="group-contents" id="list-${list.token}" style="display:none;">
          ${items.map(item => `
            <div class="list-sub-item"
                 data-value="${item.surrogate}"
                 data-owner="${item.owner}"
                 data-fileserver="${item.fileserver}">
              <span class="item-title"
                    onclick="selectOfflineItem('${item.surrogate}', getOfflineText(${item.surrogate}))">
                • ${item.title}
              </span>
            </div>
          `).join("")}
        </div>
      </div>
    `;

    container.insertAdjacentHTML("beforeend", listHtml);
  }

  // 🧩 Refresh menu button labels and icons
  updateOfflineMenuLabels?.();
}




function convertDriveLinkToDirectPdf(url) {
  const match = url.match(/\/file\/d\/([a-zA-Z0-9_-]+)/);
  if (match) {
    return `https://drive.google.com/uc?export=download&id=${match[1]}`;
  }
  return null;
}


function getTokenForCurrentUser() {
    const username = window.location.pathname.split("/")[1] || "default";
    const token = localStorage.getItem(`username-map-${username}`);
    return token || username;
}





async function embedOfflinePDF(fileId) {
    const cachedUrl = `https://drive.google.com/uc?export=download&id=${fileId}`;
    const container = document.getElementById("pdfTabContent");
    container.innerHTML = ""; // Clear previous content

    try {
        // Attempt to load from cache first
        const cache = await caches.open('textwhisper-offline-pdfs');
        const response = await cache.match(cachedUrl);
        if (!response) {
            throw new Error('No matching PDF found in cache.');
        }

        const blob = await response.blob();
        const blobUrl = URL.createObjectURL(blob);

        container.innerHTML = `
        <iframe src="${blobUrl}"
                width="100%"
                height="600px"
                type="application/pdf"></iframe>`;

        console.log("✅ PDF loaded successfully from offline cache.");

    } catch (err) {
        container.innerHTML = "<p style='text-align: center;'>PDF not available offline.</p>";
        console.error("Failed to load cached PDF:", err);
    }
}



function requireLoginNotice() {
    alert("🔒 You must be logged in to use this feature.");
}



window.loadPDFOffline = async function (surrogate) {
  // Unified path: reuse the main PDF loader for both online and offline behavior.
  // loadPDF() already uses cache-first fetch and falls back safely when offline.
  try {
    if (typeof window.loadPDF === "function") {
      await window.loadPDF(surrogate);
      return;
    }
    throw new Error("loadPDF is not available");
  } catch (err) {
    const container = document.getElementById("pdfTabContent");
    if (container) {
      container.innerHTML = "<p style='text-align:center;'>⚠️ PDF not available offline.</p>";
    }
    console.warn("⚠️ Offline PDF load failed:", err.message || err);
  }
};





async function cachePDFOfflineWithVersion(surrogate) {
console.log("⚠️ ⚠️ f cachePDFOfflineWithVersion",surrogate)    
  const el = document.querySelector(`.list-sub-item[data-value="${surrogate}"]`);
  const owner = el?.dataset.owner;

  if (!owner) {
    console.warn("⚠️ Cannot cache PDF with version — owner missing for surrogate", surrogate);
    return;
  }

  // Cloudflare-only canonical URL
  const baseUrl = `https://r2-worker.textwhisper.workers.dev/${owner}/pdf/temp_pdf_surrogate-${surrogate}.pdf`;

  const versionedUrl = baseUrl.includes("?")
    ? `${baseUrl}&v=${Date.now()}`
    : `${baseUrl}?v=${Date.now()}`;

  try {
    const res = await fetch(versionedUrl, { cache: "no-store" });
    const type = res.headers.get("Content-Type") || "";

    if (!res.ok || !type.includes("pdf")) {
      console.warn("⚠️ PDF refresh fetch failed:", baseUrl);
      return;
    }

    const cache = await caches.open("textwhisper-offline-pdfs");
    // Always normalize back to canonical (non-versioned) URL for lookup
    await cache.put(baseUrl, res.clone());

    console.log("✅ Updated offline PDF cache for:", baseUrl);
  } catch (err) {
    console.warn("⚠️ Failed to update PDF offline cache:", err);
  }
}



async function makeListOfflineFromItems(token, items) {

    
  const texts = new Array(items.length);

  const jobs = items.map(async (item, index) => {
    const surrogate = item.dataset.value;
    const owner = item.dataset.owner;

    if (!surrogate || !owner) {
      console.warn("⚠️ Skipping item — missing surrogate or owner.");
      return;
    }

    try {
      // 🔹 Cache text
      const response = await fetch(`/getText.php?q=${surrogate}`);
      const text = await response.text();
      if (!text) {
        console.warn(`⚠️ No text for surrogate ${surrogate}`);
        return;
      }

      texts[index] = { surrogate, text, owner };

      // 🔹 Cache the surrogate’s PDF (Cloudflare or PHP depending on fileserver)
      await removeOldCachedPDF(surrogate);
      await cachePDFOffline(surrogate);

      // 🔹 Scan text for external PDF links and cache them too
      const urls = text.match(/https?:\/\/[^\s"']+/g) || [];
      for (let url of urls) {
        if (url.includes("soundslice.com/slices/")) continue;
        try {
          const res = await fetch(url);
          const type = res.headers.get("Content-Type") || "";
          if (res.ok && type.includes("pdf")) {
            const cache = await caches.open("textwhisper-offline-pdfs");
            await cache.put(url, res.clone());
          }
        } catch {}
      }
    } catch (err) {
      console.warn(`❌ Failed to process surrogate ${surrogate}:`, err);
    }
  });

  await Promise.allSettled(jobs);

  localStorage.setItem(`offline-list-${token}`, JSON.stringify(texts));
  flagListOffline(token);
}




async function removeOldCachedPDF(surrogate) {
  const el = document.querySelector(`.list-sub-item[data-value="${surrogate}"]`);
  const owner = el?.dataset.owner;

  if (!owner) {
    console.warn("⚠️ Cannot remove cached PDF — owner not found for surrogate", surrogate);
    return;
  }

  const cache = await caches.open("textwhisper-offline-pdfs");

  // Cloudflare-only canonical URLs
  const cloudflareUrl = `https://r2-worker.textwhisper.workers.dev/${owner}/pdf/temp_pdf_surrogate-${surrogate}.pdf`;
  const publicR2Url = `https://pub-1afc23a510c147a5a857168f23ff6db8.r2.dev/${owner}/pdf/temp_pdf_surrogate-${surrogate}.pdf`;

  const keys = await cache.keys();
  let removedCount = 0;

  for (const request of keys) {
    const url = request.url;
    if (
      url === cloudflareUrl ||
      url === publicR2Url
    ) {
      await cache.delete(request);
      console.log("🧹 Removed cached PDF:", url);
      removedCount++;
    }
  }

  if (removedCount === 0) {
    console.log("ℹ️ No cached PDF found to remove for", surrogate);
  } else {
    console.log(`✅ Cleared ${removedCount} cached PDF(s) for surrogate ${surrogate}`);
  }

  // 🧩 Force next load to skip cache once
  sessionStorage.setItem("skipPDFCacheOnce", "1");

  // 🧠 Also reset stored version (forces version re-check)
  localStorage.removeItem(`pdf-version-${owner}-${surrogate}`);
}




async function cachePDFOffline(surrogate) {
  const el = document.querySelector(`.list-sub-item[data-value="${surrogate}"]`);
  const owner = el?.dataset.owner;

  if (!owner) {
    console.warn("⚠️ Cannot cache PDF — owner missing for surrogate", surrogate);
    return;
  }

  const url = `https://r2-worker.textwhisper.workers.dev/${owner}/pdf/temp_pdf_surrogate-${surrogate}.pdf`;

  try {
    const res = await fetch(url, { cache: "reload" });
    const type = res.headers.get("Content-Type") || "";

    if (!res.ok || !type.includes("pdf")) {
      throw new Error("Not a valid PDF response");
    }

    const cache = await caches.open("textwhisper-offline-pdfs");
    await cache.put(url, res.clone());

    console.log("✅ PDF cached successfully:", url);
  } catch (err) {
    console.warn("⚠️ Failed to cache PDF:", url, err);
  }
}




/**
 * refreshOfflineListIfOrderChanged
 * Check if the offline list order has changed compared to the cached version.
 * Optionally delays execution to allow DOM rendering to complete after list injection.
 *
 * @param {string} token - The list token identifier.
 * @param {boolean} delay - Whether to delay the check (useful after async DOM updates).
 */

function refreshOfflineListIfOrderChanged(token, delay = false) {
  if (!isListFlaggedOffline(token)) return;

  const runCheck = () => {
    const container = document.getElementById(`list-${token}`);
    if (!container) return;

    const itemsNow = [...container.querySelectorAll(".list-sub-item")];
    if (itemsNow.length === 0) {
      console.log(`⏳ Skipping order check for ${token}, list still empty`);
      return;
    }

    const currentOrder = itemsNow.map(i => i.dataset.value);
    const raw = localStorage.getItem(`offline-list-${token}`);

    if (!raw) {
      console.log("📥 No existing offline list — saving fresh.");
      return makeListOfflineFromItems(token, itemsNow);
    }

    try {
      const cached = JSON.parse(raw);
      const cachedOrder = cached.map(x => x.surrogate);
      const isSame = currentOrder.join() === cachedOrder.join();

      if (!isSame) {
        console.log(`🔁 Order changed for token: ${token}`);
        console.log("🔍 Current DOM order:", currentOrder);
        console.log("🗃 Cached offline order:", cachedOrder);
        makeListOfflineFromItems(token, itemsNow);
      } else {
        console.log("✅ Order unchanged — no offline update needed.");
      }
    } catch (err) {
      console.warn("⚠️ Failed to compare cached order. Forcing refresh.");
      makeListOfflineFromItems(token, itemsNow);
    }
  };

  if (delay) {
    setTimeout(runCheck, 100);
  } else {
    runCheck();
  }
}


async function refreshPdfCacheForList(token) {
  const container = document.getElementById(`list-${token}`);
  if (!container) {
    showFlashNear(document.body, "❌ List container not found");
    return;
  }

  const items = Array.from(container.querySelectorAll(".list-sub-item"));
  let refreshed = 0;
  for (const item of items) {
    const surrogate = item.dataset.value;
    const owner = item.dataset.owner;
    if (!surrogate || !owner) continue;

    const url = `https://r2-worker.textwhisper.workers.dev/${owner}/pdf/temp_pdf_surrogate-${surrogate}.pdf`;
    const request = new Request(url, { cache: "reload" });

    try {
      const res = await fetch(request);
      if (res.ok && res.headers.get("Content-Type")?.includes("pdf")) {
        const cache = await caches.open("textwhisper-offline-pdfs");
        await cache.put(request, res.clone());
        refreshed++;
      }
    } catch (e) {
      console.warn(`❌ PDF refresh failed for ${surrogate}:`, e);
    }
  }

  showFlashNear(document.body, `🔄 Refreshed ${refreshed} PDF${refreshed !== 1 ? "s" : ""}`);
}


async function initTranslations() {
  const locale = window.currentLocale || document.documentElement.lang || "en";
  const url = `/lang/${locale}.php`;
  const existing = (window.translations && typeof window.translations === "object")
    ? window.translations
    : {};

  try {
    // --- Online first ---
    const res = await fetch(url);
    if (!res.ok) throw new Error(`HTTP ${res.status}`);

    const text = await res.text();
    if (!text.trim()) throw new Error("Empty translation file");

    try {
      const parsed = JSON.parse(text);
      if (parsed && typeof parsed === "object") {
        // Merge so server-injected translations remain available.
        window.translations = { ...existing, ...parsed };
      } else {
        window.translations = existing;
      }
      console.log("🌍 Loaded translations online:", locale);
      return;
    } catch (err) {
      throw new Error("Invalid JSON in translations: " + err.message);
    }
  } catch (err) {
    console.warn("⚠️ Online translations failed:", err.message);

    // --- Offline fallback from cache ---
    try {
      const cache = await caches.open("textwhisper-lang");
      const cached = await cache.match(url);
      if (cached) {
        const cachedText = await cached.text();
        const parsed = cachedText.trim() ? JSON.parse(cachedText) : {};
        window.translations = (parsed && typeof parsed === "object")
          ? { ...existing, ...parsed }
          : existing;
        console.log("🌍 Loaded translations from cache:", locale);
        return;
      }
    } catch (err2) {
      console.warn("⚠️ Cached translations failed:", err2.message);
    }

    // --- Final fallback ---
    console.warn("⚠️ No offline translation payload, keeping server translations");
    window.translations = existing;
  }
}




document.addEventListener("DOMContentLoaded", async () => {
  await initTranslations();        // load translations (online or cached)
  updateOfflineMenuLabels?.();     // refresh menu buttons with correct text
});


async function clearAnnotationCacheFor(surrogate) {
  try {
    const cache = await caches.open("textwhisper-annotations");
    const keys = await cache.keys();
    for (const req of keys) {
      if (req.url.includes(`annotation-${surrogate}-`)) {
        await cache.delete(req);
        console.log("🧹 Deleted old annotation cache:", req.url);
      }
    }
    console.log(`🧽 Cleared annotation cache for surrogate ${surrogate}`);
  } catch (err) {
    console.warn("⚠️ clearAnnotationCacheFor failed:", err);
  }
}
