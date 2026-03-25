<?php
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");
$owner = isset($_GET['owner']) ? preg_replace('/[^a-zA-Z0-9._-]/', '', (string)$_GET['owner']) : 'threstir';
$surrogate = isset($_GET['surrogate']) ? preg_replace('/[^0-9]/', '', (string)$_GET['surrogate']) : '1731';
if ($owner === '') $owner = 'threstir';
if ($surrogate === '') $surrogate = '1731';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <title>PDF Zoom Test</title>
  <style>
    :root {
      --bg: #0f1116;
      --panel: #191d27;
      --text: #e8edf8;
      --muted: #9aa7bd;
      --line: #2f3647;
      --accent: #5db3ff;
      --warn: #ff7d7d;
    }
    html, body {
      margin: 0;
      background: var(--bg);
      color: var(--text);
      font-family: "Segoe UI", Arial, sans-serif;
      height: 100%;
      overscroll-behavior: none;
    }
    .bar {
      position: sticky;
      top: 0;
      z-index: 10;
      padding: 10px;
      border-bottom: 1px solid var(--line);
      background: linear-gradient(180deg, #171b25 0%, #121620 100%);
      display: grid;
      gap: 8px;
    }
    .row {
      display: flex;
      gap: 8px;
      align-items: center;
      flex-wrap: wrap;
    }
    .row label {
      font-size: 13px;
      color: var(--muted);
    }
    input[type="text"], input[type="number"] {
      background: #0f1420;
      color: var(--text);
      border: 1px solid var(--line);
      border-radius: 6px;
      padding: 6px 8px;
      min-width: 84px;
    }
    button {
      background: #243049;
      color: var(--text);
      border: 1px solid #3a4b6f;
      border-radius: 7px;
      padding: 7px 10px;
      cursor: pointer;
    }
    .small {
      font-size: 12px;
      color: var(--muted);
    }
    #viewer {
      height: calc(100vh - 190px);
      overflow: auto;
      touch-action: pan-x pan-y;
      border-top: 1px solid var(--line);
      border-bottom: 1px solid var(--line);
      background: #0a0d14;
      -webkit-overflow-scrolling: touch;
    }
    #content {
      transform-origin: 0 0;
      will-change: transform;
      padding: 14px 10px 18px;
      min-height: 100%;
      box-sizing: border-box;
    }
    .page {
      margin: 0 auto 14px;
      width: max-content;
      background: white;
      box-shadow: 0 8px 24px rgba(0, 0, 0, 0.28);
      position: relative;
    }
    .page-index {
      position: absolute;
      left: 6px;
      top: 6px;
      font-size: 11px;
      color: #121212;
      background: rgba(255, 255, 255, 0.75);
      padding: 1px 4px;
      border-radius: 3px;
    }
    #stats {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
      gap: 6px;
      padding: 8px 10px;
      border-bottom: 1px solid var(--line);
      background: #101522;
    }
    .stat {
      border: 1px solid var(--line);
      border-radius: 6px;
      padding: 6px;
      font-size: 12px;
    }
    #log {
      height: 120px;
      overflow: auto;
      padding: 8px 10px 18px;
      margin: 0;
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      font-size: 12px;
      color: #b6f7c3;
      background: #0c111b;
      white-space: pre-wrap;
      border-top: 1px solid var(--line);
    }
    .warn { color: var(--warn); }
  </style>
</head>
<body>
  <div class="bar">
    <div class="row">
      <label>owner</label>
      <input id="owner" type="text" value="<?= htmlspecialchars($owner, ENT_QUOTES) ?>" />
      <label>surrogate</label>
      <input id="surrogate" type="number" value="<?= htmlspecialchars($surrogate, ENT_QUOTES) ?>" />
      <button id="loadBtn" type="button">Load PDF</button>
      <span class="small" id="status">idle</span>
    </div>
    <div class="row">
      <label><input id="blockNative" type="checkbox" /> preventDefault on 2-finger touch</label>
      <label><input id="applyTransform" type="checkbox" checked /> apply CSS transform on pinch</label>
      <label><input id="resetScroll" type="checkbox" /> force scrollTop=0 during pinch</label>
      <button id="clearLog" type="button">Clear Log</button>
      <span class="small">Try pinch in/out and watch counters for duplicate/unstable streams.</span>
    </div>
  </div>

  <div id="stats"></div>
  <div id="viewer">
    <div id="content"></div>
  </div>
  <pre id="log"></pre>

  <script src="assets/pdf.min.js"></script>
  <script>
    (function () {
      const ownerEl = document.getElementById('owner');
      const surrogateEl = document.getElementById('surrogate');
      const loadBtn = document.getElementById('loadBtn');
      const statusEl = document.getElementById('status');
      const viewer = document.getElementById('viewer');
      const content = document.getElementById('content');
      const logEl = document.getElementById('log');
      const statsEl = document.getElementById('stats');
      const blockNativeEl = document.getElementById('blockNative');
      const applyTransformEl = document.getElementById('applyTransform');
      const resetScrollEl = document.getElementById('resetScroll');
      const clearLogBtn = document.getElementById('clearLog');

      if (!window.pdfjsLib) {
        statusEl.textContent = 'pdf.min.js did not load';
        statusEl.classList.add('warn');
        return;
      }

      window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'assets/pdf.worker.min.js';

      const counters = {
        touchstart: 0,
        touchmove: 0,
        touchend: 0,
        touchcancel: 0,
        pointerdown: 0,
        pointermove: 0,
        pointerup: 0,
        pointercancel: 0,
        gesturestart: 0,
        gesturechange: 0,
        gestureend: 0,
        wheel: 0
      };

      let activeGesture = null;
      let transformState = { scale: 1, tx: 0, ty: 0 };
      let rafId = 0;
      let pendingTransform = null;

      function updateStats() {
        statsEl.innerHTML = Object.keys(counters)
          .map((k) => `<div class="stat"><strong>${k}</strong><br>${counters[k]}</div>`)
          .join('');
      }

      function ts() {
        const d = new Date();
        return d.toISOString().slice(11, 23);
      }

      function log(line) {
        logEl.textContent += `[${ts()}] ${line}\n`;
        logEl.scrollTop = logEl.scrollHeight;
      }

      function clamp(n, min, max) {
        return Math.max(min, Math.min(max, n));
      }

      function touchCenter(touches) {
        return {
          x: (touches[0].clientX + touches[1].clientX) / 2,
          y: (touches[0].clientY + touches[1].clientY) / 2
        };
      }

      function touchDistance(touches) {
        const dx = touches[0].clientX - touches[1].clientX;
        const dy = touches[0].clientY - touches[1].clientY;
        return Math.hypot(dx, dy);
      }

      function queueTransform(next) {
        pendingTransform = next;
        if (rafId) return;
        rafId = requestAnimationFrame(() => {
          rafId = 0;
          if (!pendingTransform) return;
          transformState = pendingTransform;
          pendingTransform = null;
          content.style.transform = `translate3d(${Math.round(transformState.tx)}px, ${Math.round(transformState.ty)}px, 0) scale(${transformState.scale.toFixed(4)})`;
          if (resetScrollEl.checked) {
            viewer.scrollTop = 0;
          }
        });
      }

      function eventLine(name, e) {
        const t = e.touches ? e.touches.length : 0;
        const ct = e.changedTouches ? e.changedTouches.length : 0;
        const pid = e.pointerId != null ? ` pid=${e.pointerId}` : '';
        const pt = e.pointerType ? ` ptype=${e.pointerType}` : '';
        const sc = typeof e.scale === 'number' ? ` scale=${e.scale.toFixed(3)}` : '';
        const d = typeof e.deltaY === 'number' ? ` dy=${e.deltaY.toFixed(2)}` : '';
        return `${name} touches=${t} changed=${ct}${pid}${pt}${sc}${d}`;
      }

      const instrumentedEvents = [
        'touchstart', 'touchmove', 'touchend', 'touchcancel',
        'pointerdown', 'pointermove', 'pointerup', 'pointercancel',
        'gesturestart', 'gesturechange', 'gestureend', 'wheel'
      ];

      instrumentedEvents.forEach((name) => {
        viewer.addEventListener(name, (e) => {
          counters[name] += 1;
          updateStats();

          if (name === 'touchstart' && e.touches.length === 2) {
            const rect = content.getBoundingClientRect();
            const center = touchCenter(e.touches);
            const originX = rect.left - transformState.tx;
            const originY = rect.top - transformState.ty;
            const anchorLocalX = (center.x - rect.left) / Math.max(0.0001, transformState.scale);
            const anchorLocalY = (center.y - rect.top) / Math.max(0.0001, transformState.scale);
            activeGesture = {
              startDistance: touchDistance(e.touches),
              startCenter: center,
              baseScale: transformState.scale,
              baseTx: transformState.tx,
              baseTy: transformState.ty,
              originX,
              originY,
              anchorLocalX,
              anchorLocalY
            };
            log(eventLine(name, e) + ' [two-finger-start]');
            if (blockNativeEl.checked && e.cancelable) e.preventDefault();
            return;
          }

          if (name === 'touchmove' && activeGesture && e.touches.length === 2) {
            const nowDistance = touchDistance(e.touches);
            const nowCenter = touchCenter(e.touches);
            const ratio = activeGesture.startDistance > 0 ? nowDistance / activeGesture.startDistance : 1;
            const scale = clamp(activeGesture.baseScale * ratio, 0.4, 4.0);
            // Keep the same content point under the gesture center while scaling.
            const tx = nowCenter.x - activeGesture.originX - (scale * activeGesture.anchorLocalX);
            const ty = nowCenter.y - activeGesture.originY - (scale * activeGesture.anchorLocalY);

            if (applyTransformEl.checked) {
              queueTransform({ scale, tx, ty });
            }
            if (blockNativeEl.checked && e.cancelable) e.preventDefault();
            return;
          }

          if ((name === 'touchend' || name === 'touchcancel') && activeGesture) {
            if (e.touches.length < 2) {
              log(eventLine(name, e) + ' [two-finger-end]');
              activeGesture = null;
            }
          }

          if (name === 'gesturestart' || name === 'gesturechange' || name === 'gestureend') {
            log(eventLine(name, e));
          }
        }, { passive: false });
      });

      async function disableServiceWorkerInterference() {
        try {
          if ('serviceWorker' in navigator) {
            const regs = await navigator.serviceWorker.getRegistrations();
            for (const reg of regs) {
              try { await reg.unregister(); } catch (_) {}
            }
            if (navigator.serviceWorker.controller) {
              log('service worker controller still active for this tab; reload once to fully detach');
            } else {
              log('service workers unregistered for this origin');
            }
          }
        } catch (err) {
          log(`service worker unregister failed: ${err && err.message ? err.message : String(err)}`);
        }
      }

      async function fetchPdfBlobUrl(owner, surrogate) {
        const stamp = Date.now();
        const baseUrl = `https://r2-worker.textwhisper.workers.dev/${encodeURIComponent(owner)}/pdf/temp_pdf_surrogate-${encodeURIComponent(surrogate)}.pdf?_=${stamp}`;
        const localFallbackUrl = `File_getPDF.php?owner=${encodeURIComponent(owner)}&surrogate=${encodeURIComponent(surrogate)}&type=pdf&_=${stamp}`;

        async function fetchCandidate(url, label) {
          log(`network fetch (${label}): ${url}`);
          let res;
          try {
            res = await fetch(url, { cache: 'reload' });
          } catch (err) {
            log(`fetch error (${label}): ${err && err.message ? err.message : String(err)}`);
            return null;
          }
          const type = String(res.headers.get('Content-Type') || '');
          const len = Number(res.headers.get('Content-Length') || 0);
          log(`response (${label}): status=${res.status} type=${type || '-'} len=${len || '-'}`);
          if (!res.ok) return null;
          if (!type.toLowerCase().includes('pdf')) return null;
          if (len === 0) return null;
          const blob = await res.blob();
          if (!blob || blob.size <= 1024) return null;
          return URL.createObjectURL(blob);
        }

        const cloudflareBlobUrl = await fetchCandidate(baseUrl, 'cloudflare');
        if (cloudflareBlobUrl) return cloudflareBlobUrl;

        // Local endpoint fallback for diagnostics if Cloudflare is blocked.
        const localBlobUrl = await fetchCandidate(localFallbackUrl, 'local');
        if (localBlobUrl) return localBlobUrl;
        return null;
      }

      async function renderPdf(owner, surrogate) {
        statusEl.textContent = 'loading...';
        statusEl.classList.remove('warn');
        content.innerHTML = '';
        content.style.transform = 'translate3d(0,0,0) scale(1)';
        transformState = { scale: 1, tx: 0, ty: 0 };

        const blobUrl = await fetchPdfBlobUrl(owner, surrogate);
        if (!blobUrl) {
          statusEl.textContent = 'load failed (cloudflare/cache/local all failed)';
          statusEl.classList.add('warn');
          log('ERROR: no valid PDF source resolved');
          return;
        }
        log(`PDF object URL ready`);

        try {
          const task = window.pdfjsLib.getDocument({ url: blobUrl });
          const pdf = await task.promise;
          statusEl.textContent = `loaded ${pdf.numPages} pages`;

          const maxPages = Math.min(pdf.numPages, 8);
          const hostWidth = Math.max(320, viewer.clientWidth - 24);
          for (let p = 1; p <= maxPages; p += 1) {
            const page = await pdf.getPage(p);
            const base = page.getViewport({ scale: 1 });
            const cssScale = hostWidth / base.width;
            const viewport = page.getViewport({ scale: cssScale });

            const wrap = document.createElement('div');
            wrap.className = 'page';

            const badge = document.createElement('div');
            badge.className = 'page-index';
            badge.textContent = `p${p}`;
            wrap.appendChild(badge);

            const canvas = document.createElement('canvas');
            canvas.width = Math.floor(viewport.width);
            canvas.height = Math.floor(viewport.height);
            canvas.style.width = `${Math.floor(viewport.width)}px`;
            canvas.style.height = `${Math.floor(viewport.height)}px`;
            wrap.appendChild(canvas);
            content.appendChild(wrap);

            const ctx = canvas.getContext('2d', { alpha: false });
            await page.render({ canvasContext: ctx, viewport }).promise;
          }

          if (pdf.numPages > maxPages) {
            log(`rendered first ${maxPages}/${pdf.numPages} pages for fast testing`);
          }
        } catch (err) {
          console.error(err);
          statusEl.textContent = 'load failed';
          statusEl.classList.add('warn');
          log(`ERROR: ${err && err.message ? err.message : String(err)}`);
        } finally {
          try { URL.revokeObjectURL(blobUrl); } catch (_) {}
        }
      }

      loadBtn.addEventListener('click', () => {
        renderPdf(ownerEl.value.trim(), surrogateEl.value.trim());
      });

      clearLogBtn.addEventListener('click', () => {
        logEl.textContent = '';
        Object.keys(counters).forEach((k) => { counters[k] = 0; });
        updateStats();
      });

      updateStats();
      (async () => {
        await disableServiceWorkerInterference();
        renderPdf(ownerEl.value.trim(), surrogateEl.value.trim());
      })();
    })();
  </script>
</body>
</html>
