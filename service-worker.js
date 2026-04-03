const swVersion = "v182" // TapTray test baseline
const USER_LOCALE = "en";  // adjust dynamically if you want
const CACHE_NAME = `taptray-cache-${swVersion}`;
const OFFLINE_URL = "/index.php";
const PDF_CACHE = "taptray-offline-items";
const ANNO_CACHE = "taptray-annotations";
const LANG_CACHE = "taptray-lang";
const MANUAL_CACHE = "taptray-cache-manual";
const EP_CACHE = "taptray-event-planner";

const DEV_MODE = self.location.search.includes("dev=1");

const ASSETS_TO_CACHE = [
  "/", "/index.php",

  // Core JS
  `/JSCommon.js?v=${swVersion}`,
  `/JSFunctions.js?v=${swVersion}`,
  `/JSText.js?v=${swVersion}`,
  `/JSTextUndo.js?v=${swVersion}`,
  `/JSEvents.js?v=${swVersion}`,
  `/JSEventsHeaderTabs.js?v=${swVersion}`,
  `/JSDrawingXML.js?v=${swVersion}`,
  `/JSDrawingXMLPlay.js?v=${swVersion}`,

  // Styles
  `/myStyles.css?v=${swVersion}`,
  `/myStylesText.css?v=${swVersion}`,
  `/myStylesPalette.css?v=${swVersion}`,
  // Vendor essentials
  "/assets/bootstrap.min.css",
  "/assets/bootstrap.bundle.min.js",
  "/assets/jquery.min.js",

  // PDF engine (needed early)
  "/assets/pdf.min.js",
  "/assets/pdf.worker.min.js",

  // Icons & manifest
  // "/manifest.json",
  // "/favicon.ico",
  // "/img/wrt.png",

  // Event planner shell assets (read-only offline phase)
  "/ep_event_planner.css",
  "/ep_event_planner.js",

];


self.skipWaiting();
self.addEventListener('activate', () => clients.claim());
self.addEventListener("message", (event) => {
  if (event?.data?.type === "SKIP_WAITING") {
    self.skipWaiting();
  }
});

self.addEventListener("push", (event) => {
  const fallback = {
    title: "New message",
    body: "You have a new alert.",
    url: "/",
    sound: "silent"
  };

  let data = fallback;
  try {
    if (event.data) {
      data = { ...fallback, ...(event.data.json() || {}) };
    }
  } catch (err) {
    try {
      data = { ...fallback, body: event.data ? String(event.data.text() || fallback.body) : fallback.body };
    } catch (_ignore) {
      data = fallback;
    }
  }

  const targetUrl = typeof data.url === "string" && data.url.trim() ? data.url : "/";
  const title = data.title || fallback.title;
  const body = data.body || fallback.body;
  event.waitUntil((async () => {
    await self.registration.showNotification(title, {
      body,
      icon: "/icons/wrt-v2.png",
      badge: "/icons/wrt-v2.png",
      data: { url: targetUrl },
      renotify: true,
      tag: `chat-alert-${Date.now()}`
    });
  })());
});

self.addEventListener("notificationclick", (event) => {
  event.notification.close();
  const clickUrl = (event.notification && event.notification.data && event.notification.data.url) || "/";

  event.waitUntil((async () => {
    const clientList = await clients.matchAll({ type: "window", includeUncontrolled: true });
    for (const client of clientList) {
      try {
        if (client.url && client.url.includes(self.location.origin)) {
          if (typeof client.navigate === "function") {
            await client.navigate(clickUrl);
          }
          await client.focus();
          return;
        }
      } catch (_ignore) {}
    }
    await clients.openWindow(clickUrl);
  })());
});


// --- INSTALL ---

self.addEventListener("install", (event) => {

  // --- 🕐 Limit total install duration (prevents long hangs on slow networks)
  const installPromise = (async () => {
    const assetCache = await caches.open(CACHE_NAME);

    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 15000); // 15s timeout

    // ✅ Cache lightweight core assets only
    await Promise.allSettled(
      ASSETS_TO_CACHE.map(async (url) => {
        try {
          const res = await fetch(url, { signal: controller.signal });
          if (res.ok) await assetCache.put(url, res.clone());
        } catch (err) {
          // Don’t spam console — keep install fast and quiet
          if (self.DEBUG_SW) console.warn("⚠️ Cache skip:", url, err.message);
        }
      })
    );

    clearTimeout(timeout);

    // 🌍 Cache only current language file if available
    try {
      const langUrl = `/lang/${USER_LOCALE}.php`;
      const langCache = await caches.open(LANG_CACHE);
      const res = await fetch(langUrl, { credentials: "include" });
      if (res.ok) {
        await langCache.put(langUrl, res.clone());
      }
    } catch (err) {
      if (self.DEBUG_SW) console.warn("⚠️ Could not cache language:", err.message);
    }
  })();

  event.waitUntil(installPromise);
  self.skipWaiting();
});


// --- ACTIVATE ---
self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys().then(async (keys) => {
      // 🧹 remove caches not in the current version set
      await Promise.all(
        keys.map((k) => {
          if (![CACHE_NAME, PDF_CACHE, ANNO_CACHE, LANG_CACHE, MANUAL_CACHE, EP_CACHE].includes(k)) {
            return caches.delete(k);
          }
        })
      );

      // 🧽 run legacy cleanup (v1461 and earlier)
      await runExtraCacheCleanup();
    })
  );
  self.clients.claim();
});


async function runExtraCacheCleanup() {
  const CLEANUP_THRESHOLD = 1461;
  const CURRENT_VERSION = (() => {
    const m = (typeof swVersion !== "undefined" ? swVersion : "v0").match(/v(\d+)/);
    return m ? parseInt(m[1], 10) : 0;
  })();

  let cleaned = false;
  const keys = await caches.keys();

  for (const key of keys) {
    const match = key.match(/v(\d+)/);
    const ver = match ? parseInt(match[1], 10) : null;
    if (ver && ver < CLEANUP_THRESHOLD) {
      await caches.delete(key);
      cleaned = true;
    }
  }

  const pdfCache = await caches.open(PDF_CACHE);
  for (const req of await pdfCache.keys()) {
    if (
      req.url.includes("/File_getPDF.php") ||
      req.url.includes("/serveFile.php") ||
      req.url.includes("/getAnnotation.php")
    ) {
      await pdfCache.delete(req);
      cleaned = true;
    }
  }

  if (cleaned) {
  } else {
  }
}



// --- FETCH ---
self.addEventListener("fetch", (event) => {

  if (DEV_MODE) {
    // Completely bypass Service Worker logic
    return;
  }  

  const { request } = event;
  const url = new URL(request.url);
  const isIframeNav =
    request.destination === "iframe" ||
    request.headers.get("Sec-Fetch-Dest") === "iframe";
  
  
// =======================================================
// 1️⃣ OFFLINE SPA NAVIGATION FALLBACK
// =======================================================
if (request.mode === "navigate") {
  if (isIframeNav) {
    event.respondWith(
      (async () => {
        try {
          const online = await fetch(request); // online iframe load
          const isEventPlanner =
            url.pathname.includes("/ep_event_planner.php") ||
            url.pathname.includes("/TW_EventPlanner.php");
          if (isEventPlanner && online && online.ok) {
            const epCache = await caches.open(EP_CACHE);
            await epCache.put(request, online.clone());
          }
          return online;
        } catch {
          const isEventPlanner =
            url.pathname.includes("/ep_event_planner.php") ||
            url.pathname.includes("/TW_EventPlanner.php");
          if (isEventPlanner) {
            const epCache = await caches.open(EP_CACHE);
            const cachedPlanner =
              (await epCache.match(request)) ||
              (await epCache.match(url.pathname, { ignoreSearch: true }));
            if (cachedPlanner) return cachedPlanner;
          }

          const cache = await caches.open(CACHE_NAME);
          const cached = await cache.match(request);
          if (cached) return cached;

          // Do NOT return the app shell inside an iframe (prevents recursive iframes)
          const offlineMsg = isEventPlanner
            ? "Event planner offline: read-only cached view."
            : "Offline content unavailable.";
          return new Response(
            `<!doctype html><html><head><meta charset="utf-8"><title>Offline</title></head><body style="font-family: system-ui; padding: 16px;">${offlineMsg}</body></html>`,
            { status: 200, headers: { "Content-Type": "text/html; charset=utf-8" } }
          );
        }
      })()
    );
    return;
  }
  // Let auth/OAuth and API navigations pass through untouched
  if (url.pathname.startsWith("/api/")) {
    event.respondWith(fetch(request));
    return;
  }

  event.respondWith(
    (async () => {
      try {
        return await fetch(request); // online
      } catch {
        // Offline → return cached index.php shell
        const cache = await caches.open(CACHE_NAME);
        return (
          (await cache.match("/index.php", { ignoreSearch: true })) ||
          (await cache.match("/", { ignoreSearch: true })) ||
          new Response("<h1>Offline</h1>", { status: 200 })
        );
      }
    })()
  );
  return;   // ← IMPORTANT: stop processing
}
  

  
// --- AUTH-SAFE PAGES: always live ---
if (
  url.pathname === "/index.php" ||
  url.pathname === "/login.php" ||
  url.pathname === "/process_login.php" ||
  url.pathname === "/google_callback.php"
) {
  // Always fetch fresh version from server
  event.respondWith(
    fetch(request).catch(() =>
      // Fallback to last cached shell if offline
      caches.match(OFFLINE_URL)
    )
  );
  return;
}
  
  // 🧠 Skip caching entirely for Cloudflare R2 resources when needed
//   if (
//     url.hostname.includes("r2.dev") ||
//     url.hostname.includes("r2-worker.textwhisper.workers.dev")
//   ) {
//     event.respondWith(fetch(request)); // always go to network
//     return; // prevent other handlers from catching it
//   }
  

  // Languages
  if (url.pathname.startsWith("/lang/")) {
    event.respondWith((async () => {
      const cache = await caches.open("textwhisper-lang");
      const cached = await cache.match(request);
      if (cached) return cached;

      try {
        const fresh = await fetch(request);
        if (fresh.ok) cache.put(request, fresh.clone());
        return fresh;
      } catch {
        return cached || Response.error();
      }
    })());
    return;
  }

  // PDF.js worker (required for offline PDF rendering)
  if (url.pathname === "/assets/pdf.worker.min.js") {
    event.respondWith(
      caches.open(CACHE_NAME).then(async (cache) => {
        const cached = await cache.match(request);
        if (cached) return cached;
        try {
          const res = await fetch(request);
          if (res.ok) cache.put(request, res.clone());
          return res;
        } catch {
          return cached || Response.error();
        }
      })
    );
    return;
  }

  // Event planner static assets use changing query params; match ignoring search.
  if (url.pathname === "/ep_event_planner.css" || url.pathname === "/ep_event_planner.js") {
    event.respondWith((async () => {
      const cache = await caches.open(EP_CACHE);
      try {
        const fresh = await fetch(request, { cache: "no-store" });
        if (fresh && fresh.ok) {
          await cache.put(request, fresh.clone());
          await cache.put(url.pathname, fresh.clone());
        }
        return fresh;
      } catch {
        const cached =
          (await cache.match(request)) ||
          (await cache.match(url.pathname, { ignoreSearch: true })) ||
          (await caches.match(url.pathname, { ignoreSearch: true }));
        return cached || new Response("", { status: 503 });
      }
    })());
    return;
  }

  // Event planner data endpoints (Phase 1: offline read-only)
  if (url.pathname.startsWith("/ep_") && url.pathname.endsWith(".php") && request.method === "GET") {
    event.respondWith((async () => {
      const cache = await caches.open(EP_CACHE);
      try {
        const fresh = await fetch(request, { cache: "no-store" });
        if (fresh && fresh.ok) {
          await cache.put(request, fresh.clone());
        }
        return fresh;
      } catch {
        const cached =
          (await cache.match(request)) ||
          (await cache.match(url.pathname, { ignoreSearch: true }));
        if (cached) return cached;
        return new Response(JSON.stringify({ status: "offline", message: "Planner data unavailable offline." }), {
          status: 503,
          headers: { "Content-Type": "application/json" }
        });
      }
    })());
    return;
  }
  
  // Skip interception for uploads (so progress works)
  if (
    url.hostname.includes("r2-worker.textwhisper.workers.dev") // Cloudflare direct uploads
  ) {
    return; // Let the browser handle normally
  }


  // тЬЕ Handle getOwnersListsJSON.php offline
    if (url.pathname.endsWith("/getOwnersListsJSON.php")) {
      event.respondWith((async () => {
        const manualCache = await caches.open(MANUAL_CACHE);
        const token = url.searchParams.get("token") || "";
        const stableKey = token ? `/offline/owners/${encodeURIComponent(token)}.json` : null;
        try {
          const fresh = await fetch(request);
          if (fresh.ok && fresh.headers.get("Content-Type")?.includes("application/json")) {
            await manualCache.put(request, fresh.clone());
            if (stableKey) {
              await manualCache.put(stableKey, fresh.clone());
            }
          }
          return fresh;
        } catch {
          console.warn("тЪая╕П Offline fallback for getOwnersListsJSON");
          // Prefer stable key if present (prevents empty fallback overwriting)
          if (stableKey) {
            const stable = await manualCache.match(stableKey);
            if (stable) return stable;
          }
          const cached = await manualCache.match(request);
          if (cached) return cached;
          // Fallback JSON structure so JS doesn't crash
          return new Response(JSON.stringify({
            owner: {},
            owned: [],
            accessible: []
          }), { headers: { "Content-Type": "application/json" }, status: 200 });
        }
      })());
      return;
    }
    
    // тЬЕ Handle chat badge request offline
    if (url.pathname.endsWith("/getChatBadge.php")) {
      event.respondWith(new Response(JSON.stringify({ count: 0 }), {
        headers: { "Content-Type": "application/json" },
        status: 200
      }));
      return;
    }
    
  // тЬЕ Handle getListItemsJSON.php offline
    if (url.pathname.endsWith("/getListItemsJSON.php")) {
      event.respondWith((async () => {
        const cache = await caches.open(MANUAL_CACHE);
        const listToken = url.searchParams.get("list") || "";
        const stableKey = listToken
          ? `/offline/list-items-json/${encodeURIComponent(listToken)}.json`
          : null;
        try {
          const fresh = await fetch(request);
          if (fresh.ok && fresh.headers.get("Content-Type")?.includes("application/json")) {
            await cache.put(request, fresh.clone());
            if (stableKey) await cache.put(stableKey, fresh.clone());
          }
          return fresh;
        } catch {
          console.warn("тЪая╕П Offline fallback for getListItemsJSON");
          if (stableKey) {
            const stable = await cache.match(stableKey);
            if (stable) return stable;
          }
          const cached = await cache.match(request);
          if (cached) return cached;
          // Provide empty but valid JSON so .json() won't fail
          return new Response(JSON.stringify({
            status: "offline",
            list: [],
            items: []
          }), {
            headers: { "Content-Type": "application/json" },
            status: 200
          });
        }
      })());
      return;
    }


    



  // Skip dynamic PHP endpoints (except index/includes)
  if (
    url.pathname.endsWith(".php") &&
    !url.pathname.startsWith("/includes/") &&
    url.pathname !== "/index.php"
  ) {
    event.respondWith(
      (async () => {
        try {
          return await fetch(request);
        } catch {
          if (request.url.includes("/getListItems.php")) {
            return new Response("<div>тЪая╕П Items unavailable offline</div>", {
              headers: { "Content-Type": "text/html" }
            });
          }
          if (request.url.includes("/getText.php")) {
            return new Response("", { status: 200 });
          }
          return new Response("", { status: 200 });
        }
      })()
    );
    return;
  }

  // Never cache list-manipulation endpoints
  const nonCacheable = [
    "/addItemToList.php",
    "/removeItemFromList.php",
    "/updateListOrder.php",
    "/getUserLists.php",
    "/getUserListsOther.php"
  ];
  if (nonCacheable.some(path => url.pathname.endsWith(path))) {
    event.respondWith(fetch(request));
    return;
  }

  // Navigation тЖТ network first, fallback offline
  //Duplicate
//   if (request.mode === "navigate") {
//     event.respondWith(
//       (async () => {
//         try {
//           return await fetch(request);
//         } catch {
//           return (await caches.match(OFFLINE_URL)) || (await caches.match("/"));
//         }
//       })()
//     );
//     return;
//   }

  // PDFs via serveFile
  if (request.url.includes("/serveFile.php")) {
    const type = url.searchParams.get("type");
    const cacheName = type === "annotation" ? ANNO_CACHE : PDF_CACHE;
    const cacheKey = request.url;

    event.respondWith(
      caches.open(cacheName).then(async (cache) => {
        const cached = await cache.match(cacheKey);
        if (cached) return cached;
        try {
          const res = await fetch(request);
          if (res.ok) cache.put(cacheKey, res.clone());
          return res;
        } catch {
          return new Response(`тЪая╕П ${type} unavailable offline.`, { status: 503 });
        }
      })
    );
    return;
  }

  // PDFs direct
    if (request.url.endsWith(".pdf")) {
      event.respondWith(
        caches.open("textwhisper-offline-pdfs").then(async (cache) => {
          const cached = await cache.match(request.url);
          if (cached) {
            return cached;
          }
          try {
            const res = await fetch(request);
            if (res.ok && res.headers.get("Content-Type")?.includes("pdf")) {
              cache.put(request.url, res.clone());
            }
            return res;
          } catch {
            return new Response("⚠️ PDF unavailable offline.", { status: 503 });
          }
        })
      );
      return;
    }


  // Annotation PNG overlays
  if (
    request.url.includes("/annotations/annotation-") ||
    request.url.includes("/annotations/users/")
  ) {
    event.respondWith(
      caches.open(ANNO_CACHE).then(async (cache) => {
        const cached = await cache.match(request.url);
        if (cached) return cached;
        try {
          const res = await fetch(request);
          if (res.ok) cache.put(request.url, res.clone());
          return res;
        } catch {
          const transparent1x1 =
            "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQI12P4DwQACfsD/VE/5gAAAABJRU5ErkJggg==";
          return new Response(atob(transparent1x1), {
            headers: { "Content-Type": "image/png" }
          });
        }
      })
    );
    return;
  }
  
  // CDN assets
  if (
    request.url.includes("cdn.jsdelivr.net") ||
    request.url.includes("code.jquery.com") ||
    request.url.includes("cdnjs.cloudflare.com") ||
    request.url.includes("unpkg.com")   // ← FIX ADDED
  ) {
    event.respondWith(
      caches.match(request).then((cached) =>
        cached ||
        fetch(request).then((res) => {
          if (res.ok) caches.open(CACHE_NAME).then((c) => c.put(request, res.clone()));
          return res;
        }).catch(() =>
          new Response("", { status: 200 })
        )
      )
    );
    return;
  }

  // Scripts & Styles тЖТ network-first
//   if (request.destination === "script" || request.destination === "style") {
//     const cacheKey = request.url;
//     event.respondWith(
//       (async () => {
//         try {
//           const res = await fetch(request, { cache: "no-store" });
//           if (res.ok) {
//             const cache = await caches.open(CACHE_NAME);
//             cache.put(cacheKey, res.clone());
//           }
//           return res;
//         } catch (err) {
//           console.warn("тЪая╕П Network failed for", cacheKey, err);
//           const cached = await caches.match(cacheKey);
//           return cached || new Response("", { status: 503 });
//         }
//       })()
//     );
//     return;
//   }

    // Scripts & Styles — network-first to avoid sticky stale bundles
    if (request.destination === "script" || request.destination === "style") {
      const cacheKey = request.url;
      event.respondWith(
        (async () => {
          const cache = await caches.open(CACHE_NAME);

          try {
            const res = await fetch(request, { cache: "no-store" });
            if (res.ok) {
              cache.put(cacheKey, res.clone());
            }
            return res;
          } catch {
            const cached = await cache.match(cacheKey);
            return cached || new Response("", { status: 503 });
          }
        })()
      );
      return;
    }


  // Images — cache-first + background refresh
  if (request.destination === "image") {
    const cacheKey = request.url;
    event.respondWith(
      caches.open(CACHE_NAME).then(async (cache) => {
        const cached = await cache.match(cacheKey);
        if (cached) {
          event.waitUntil(
            fetch(request)
              .then((res) => {
                if (res.ok) cache.put(cacheKey, res.clone());
              })
              .catch(() => {})
          );
          return cached;
        }
        try {
          const res = await fetch(request);
          if (res.ok) {
            cache.put(cacheKey, res.clone());
          }
          return res;
        } catch {
          return new Response("", { status: 503 });
        }
      })
    );
    return;
  }

  // Default
  event.respondWith(
    caches.match(request).then((cached) =>
      cached || fetch(request).catch(() => new Response("", { status: 200 }))
    )
  );
});
