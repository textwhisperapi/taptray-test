    // Global music pin state
    // window._pinnedMusicPlayer = null;
    
    // 🎵 Global pinned playback state
    window._pinnedMusicPlayer = null;
    window.isPinnedMode = localStorage.getItem("isPinnedMode") === "true";
    window._defaultPinnedState ||= {};
    window._lastPinnedState ||= {};
    
    // Keep music panel/pinned overlays in a sane state after iPad app switches.
    window.syncMusicPanelState = function () {
      const panel = document.getElementById("musicTabContent");
      if (!panel) return;
      const musicActive = panel.classList.contains("active");
      const musicVisible = panel.classList.contains("visible");
      const musicBtnActive = !!document.querySelector('.footer-tab-btn.active[data-target="musicTab"]');
      const hasPinned = !!document.querySelector(".musicPanel-bottomPinned, .musicPanel-floating");
      const keepVisible = musicActive || musicVisible || musicBtnActive || hasPinned;

      if (!keepVisible) {
        panel.classList.remove("visible");
        Object.assign(panel.style, { height: "", padding: "", overflow: "", background: "" });
      }

      document.body.classList.toggle("music-panel-open", panel.classList.contains("visible") || hasPinned);
    };

    if (!window._musicPanelGhostGuardBound) {
      window._musicPanelGhostGuardBound = true;
      const sync = () => window.syncMusicPanelState?.();
      document.addEventListener("visibilitychange", () => {
        if (document.visibilityState === "visible") sync();
      });
      window.addEventListener("focus", sync);
      window.addEventListener("pageshow", sync);
      window.addEventListener("resize", sync);
      setTimeout(sync, 100);
    }
    
    
    
    
    
    function makeReadableLabel(url) {
      try {
        const parts = url.split("/").filter(Boolean);
        const lastSegment = decodeURIComponent(parts.pop() || "").trim();
    
        if (lastSegment && lastSegment.length > 6) {
          return lastSegment;
        }
        return url;
      } catch (e) {
        return url;
      }
    }
    
    
    
    function normalize(url) {
      return url.replace(/\/+$/, "");
    }
    
    
    
    
    // 🔒 Track both active and default pinned players
    // _pinnedState = currently pinned per surrogate
    // _defaultPinnedState = last known pinned (fallback)
    window._pinnedState = loadFromStorage("pinnedPlayersBySurrogate");
    window._defaultPinnedState = loadFromStorage("defaultPinnedPlayersBySurrogate");
    
    // Generic safe loader
    function loadFromStorage(key) {
      try {
        const raw = localStorage.getItem(key);
        if (!raw) return {};
        const data = JSON.parse(raw);
        return (typeof data === "object" && data !== null) ? data : {};
      } catch (err) {
        console.warn(`⚠️ Failed to parse ${key} from localStorage`, err);
        return {};
      }
    }
    
    
    
    function savePinnedStateToStorage() {
      try {
        localStorage.setItem("pinnedPlayersBySurrogate", JSON.stringify(window._pinnedState || {}));
        localStorage.setItem("defaultPinnedPlayersBySurrogate", JSON.stringify(window._defaultPinnedState || {}));
      } catch (err) {
        console.warn("⚠️ Failed to save pinned/default states:", err);
      }
    }
    
    
    
    window.getTextContent = function (el) {
      return el?.innerText.trim() || "";
    };
    
    
    

window.renderMusicPanel = async function () {

  // stop running MIDI players
  try { window._activeMidiPlayer?.stop?.() } catch {}

  const musicTabContent = document.getElementById("musicTabContent");
  Object.assign(musicTabContent.style, { height:"", padding:"", overflow:"", background:"" });
  window.syncMusicPanelState?.();

  const surrogate = window.currentSurrogate;
  if (!surrogate) return;

  const midiList = document.getElementById("midiList");
  if (!midiList) return;

  const isEditor = window.currentUserItemRoleRank >= 80;


  midiList.innerHTML = "";

  //render controls first
  // renderMusicPanelRoles();

  // ========== Cached audio list (instant UI) ==========
  // const cachedAudio = loadCachedAudioList(surrogate);
  // if (!cachedAudio.length) {
  //   midiList.innerHTML = "<p class='text-muted'>Loading music files...</p>";
  // }

  // ============================================================
  // 🎵 BUILD ITEMS ARRAY
  // includes: uploaded files + links from getTextLinks.php
  // ============================================================

  const items = [];
  const seenYoutubeIDs = new Set();
  const AUDIO_FILE_RE = /\.(mp3|wav|ogg|m4a|flac|aac|aif|aiff|webm)$/i;

  function getHostFromUrl(rawUrl) {
    if (!rawUrl || typeof rawUrl !== "string") return "";
    try {
      return new URL(rawUrl).hostname.toLowerCase();
    } catch {
      try {
        return new URL(`https://${rawUrl}`).hostname.toLowerCase();
      } catch {
        return "";
      }
    }
  }

  function getKnownMusicSource(rawUrl) {
    const host = getHostFromUrl(rawUrl);
    if (!host) return null;
    if (host === "youtu.be" || host.includes("youtube.com")) return "youtube";
    if (host.includes("open.spotify.com") || host === "spotify.link") return "spotify";
    if (host.includes("soundcloud.com")) return "soundcloud";
    if (host.includes("soundslice.com")) return "soundslice";
    if (host.includes("music.apple.com")) return "apple";
    if (host.includes("bandcamp.com")) return "bandcamp";
    return null;
  }

  // ============================================================
  // 1) ADD UPLOADED FILES (Cloudflare only)
  // ============================================================
  try {
    const files = await fetchUploadedFiles(surrogate);

    const midiFiles  = files.filter(f => /\.(mid|midi)$/i.test(f.name));
    const audioFiles = files.filter(f => /\.(mp3|wav|ogg|m4a|flac|aac|aif|aiff|webm)$/i.test(f.name));

    midiFiles.forEach(file => {
      items.push({
        type: "midi",
        label: `🎼 MIDI – ${file.name}`,
        url: file.url,
        name: file.name,
        fileMeta: file
      });
    });

    audioFiles.forEach(file => {
      items.push({
        type: "audio",
        label: `🔊 ${file.name}`,
        url: file.url,
        name: file.name,
        fileMeta: file
      });
    });

  } catch (err) {
    console.warn("🎵 Music fetch failed", err);
  }

  // ============================================================
  // 2) ADD LINKS FROM DATABASE VIA getTextLinks.php (no textarea)
  // ============================================================

  let serverLinks = [];
  const cacheKey   = `textLinks-cache-${surrogate}`;
  const versionKey = `textLinks-version-${surrogate}`;

  // load cached version instantly
  const cached = localStorage.getItem(cacheKey);
  const cachedVer = localStorage.getItem(versionKey) || "";

  if (cached) {
    try {
      const arr = JSON.parse(cached);
      if (Array.isArray(arr)) serverLinks = arr;
    } catch {}
  }

  // background update
  if (navigator.onLine) {
    fetch(`/getTextLinks.php?surrogate=${surrogate}&v=${cachedVer}`)
      .then(async (res) => {
        const serverVersion = res.headers.get("X-Text-Version") || "";

        if (res.status === 304) return; // unchanged

        const data  = await res.json();
        const links = Array.isArray(data.links) ? data.links : [];

        localStorage.setItem(cacheKey, JSON.stringify(links));
        localStorage.setItem(versionKey, data.version || serverVersion);

        // refresh safely (no loops)
        setTimeout(() => {
          if (window.currentSurrogate == surrogate) {
            window.renderMusicPanel?.();
          }
        }, 50);
      })
      .catch(err => console.warn("❌ getTextLinks failed", err));
  }

  // Convert server links → items[]
  for (const url of serverLinks) {
    if (!url || typeof url !== "string") continue;

    // 🔥 YouTube dedupe by videoID only
    const videoId = extractYouTubeID(url);
    if (videoId) {
    
      if (!seenYoutubeIDs.has(videoId)) {
        seenYoutubeIDs.add(videoId);
    
        items.push({
          type: "embed",
          label: `${getMusicIconHTML("youtube")}${makeReadableLabel(url)}`,
          url: `https://www.youtube.com/embed/${videoId}`,
          rawUrl: url,
          pinnable: true,
          _metaFetchUrl: `https://www.youtube.com/watch?v=${videoId}`,
          _metaType: "youtube"
        });

      }
      continue;
    }


    const cleanUrl = url.split("?")[0];
    const source = getKnownMusicSource(url);

    // audio link
    if (AUDIO_FILE_RE.test(cleanUrl)) {
      items.push({
        type: "audio",
        label: `🔊 ${makeReadableLabel(cleanUrl)}`,
        url: cleanUrl,
        rawUrl: url
      });
      continue;
    }

    // Reject non-audio links from unknown sources.
    if (!source) continue;

    // spotify
    if (source === "spotify") {
      const kind = cleanUrl.includes("/album/") ? "album" :
                   cleanUrl.includes("/playlist/") ? "playlist" : "track";
      const id = cleanUrl.split("/").pop();

      items.push({
        type: "spotify",
        label: `🎧 ${makeReadableLabel(cleanUrl)}`,
        url: `https://open.spotify.com/embed/${kind}/${id}`,
        rawUrl: cleanUrl,
        pinnable: true,
        _metaFetchUrl: cleanUrl,
        _metaType: "spotify"
      });
      continue;
    }

    // soundcloud
    if (source === "soundcloud") {
      items.push({
        type: "soundcloud",
        label: `${getMusicIconHTML("soundcloud")}${makeReadableLabel(cleanUrl)}`,
        url: url,
        pinnable: true,
        _metaFetchUrl: cleanUrl,
        _metaType: "soundcloud"
      });
      continue;
    }

    // soundslice
    if (source === "soundslice") {
      items.push({
        type: "link",
        label: `🎼 ${makeReadableLabel(cleanUrl)}`,
        url: cleanUrl,
        _metaFetchUrl: cleanUrl,
        _metaType: "soundslice"
      });
      continue;
    }

    // other known music sources (e.g. Apple Music, Bandcamp)
    items.push({
      type: "link",
      label: `${getMusicIconHTML(source)}${makeReadableLabel(cleanUrl)}`,
      url: cleanUrl
    });
  }

  // ============================================================
  // 3) RENDER LIST
  // ============================================================
  // midiList.innerHTML = "";

  items.forEach(item => {

    // 🔎 ROLE FILTER — uploaded files only, forgiving
    if (item.fileMeta && item.type !== "midi" && !fileMatchesActiveRole(item.name)) {
      return; // skip silently
    }

    const wrapper = document.createElement("div");
    wrapper.className = "musicPanel-item mb-2";
    wrapper.dataset.type = item.type;
    wrapper.dataset.url  = item.url;
    if (item.rawUrl) wrapper.dataset.raw = item.rawUrl;
    if (item.name)   wrapper.dataset.name = item.name;

    const header = document.createElement("div");
    header.className = "musicPanel-header drag-handle";
    header.style.display = "flex";
    header.style.justifyContent = "space-between";

    const label = document.createElement("div");
    label.className = "musicPanel-title";
    if (item.type !== "link") label.classList.add("clickable");

    if (item.type === "link") {
      const a = document.createElement("a");
      a.href = item.url;
      a.target = "_blank";
      a.className = "link-primary";
      a.textContent = item.label;
      a.style.textDecoration = "underline";
      a.style.cursor = "pointer";
      label.appendChild(a);
    } else {
      label.innerHTML = item.label;
    }

    header.appendChild(label);

    // ★ keep pin + default checkbox (as in your version)
    if (item.pinnable !== false && item.type !== "link") {
      const defChk = document.createElement("input");
      defChk.type = "checkbox";
      defChk.className = "musicPanel-defaultChk";

      const defInfo = window._defaultPinnedState?.[surrogate];
      if (defInfo && defInfo.filename === item.name) {
        defChk.checked = true;
      }

      defChk.onclick = (e) => {
        e.stopPropagation();
        document.querySelectorAll(".musicPanel-defaultChk").forEach(chk => {
          if (chk !== defChk) chk.checked = false;
        });

        if (defChk.checked) {
          window._defaultPinnedState[surrogate] = { filename: item.name, type: item.type };
        } else {
          delete window._defaultPinnedState[surrogate];
        }
        savePinnedStateToStorage();
      };

      const pinBtn = document.createElement("button");
      pinBtn.className = "musicPanel-pinBtn";
      pinBtn.textContent = "📌";
      pinBtn.onclick = (e) => {
        e.stopPropagation();
        window.togglePin(wrapper);
      };

      const rightGroup = document.createElement("div");
      rightGroup.style.display = "inline-flex";
      rightGroup.style.alignItems = "center";
      rightGroup.style.gap = "6px";

      rightGroup.appendChild(defChk);
      rightGroup.appendChild(pinBtn);

      header.appendChild(rightGroup);
    }

    const playerBox = document.createElement("div");
    playerBox.className = "musicPlayerBox";
    playerBox.style.display = "none";

    wrapper.appendChild(header);
    wrapper.appendChild(playerBox);

    wrapper.addEventListener("click", (e) => {
      const isInteractive = e.target.closest(
        ".midi-player-wrapper, .musicPanel-pinBtn, .musicPanel-deleteBtn, .loop-btn, button, a, input, select, svg, [data-lucide]"
      );
      if (isInteractive) return;

      if (wrapper.classList.contains("musicPanel-floating") ||
          wrapper.classList.contains("musicPanel-bottomPinned")) return;

      renderMusicPlayer(wrapper, item);
    });

    midiList.appendChild(wrapper);
  });

  // ============================================================
  // 4) Upgrade titles for Spotify/SoundCloud/Soundslice
  // ============================================================
  setTimeout(() => {
    items.forEach(item => {
      if (!item._metaFetchUrl) return;
      fetch(`/fetchMeta.php?url=${encodeURIComponent(item._metaFetchUrl)}`)
        .then(res => res.json())
        .then(meta => {
          const title = meta.ogTitle || meta.title;
          if (!title) return;

          const wrapper = [...document.querySelectorAll(".musicPanel-item")]
            .find(el => normalize(el.dataset.url) === normalize(item.url));

          const labelDiv = wrapper?.querySelector(".musicPanel-title");
          if (labelDiv) {
            const icon = getMusicIconHTML(item._metaType);
            const newLabel = `${icon}${title}`;
            item.label = newLabel;

            if (item.type === "link") {
              const a = labelDiv.querySelector("a");
              if (a) a.innerHTML = newLabel;
            } else {
              labelDiv.innerHTML = newLabel;
            }
          }
        })
        .catch(err => console.warn("❌ Metadata fetch failed:", err));
    });
  }, 300);

  // ============================================================
  // 5) SHOW UPLOADER FOR EDITORS
  // ============================================================

  renderMusicPanelRoles();

  // if (isEditor && !document.getElementById("musicInsertBox")) {
  //   const toggleWrapper = document.createElement("div");
  //   toggleWrapper.className = "mt-3";

  //   toggleWrapper.innerHTML = `
  //     <button id="musicInsertToggleBtn" class="music-upload-toggle">
  //       🎵 ${window.translations?.add_music || 'Add music'}
  //     </button>
  //     <button id="importMusicDropboxBtn" class="music-dropbox-btn">
  //       <img src="/icons/dropbox_0061ff.svg" alt="Dropbox"
  //            style="width:18px;height:18px;vertical-align:middle;margin-right:6px;">
  //       ${window.translations?.import_from_dropbox || 'Dropbox'}
  //     </button>
  //     <button id="recordMusicBtn" class="music-recording">
  //       🎙️ ${window.translations?.record_music || 'Record'}
  //     </button>

  //     <div id="musicInsertBox" class="hidden music-upload-box">
  //       <p class="text-muted">
  //         ${window.translations?.drop_music_here || 'Drop your music here'}
  //         ${window.translations?.or || 'or'}
  //         <span class="upload-link" onclick="document.getElementById('musicFileInput').click()">
  //           ${window.translations?.select_file_to_upload || 'select a file to upload'}
  //         </span>.
  //       </p>
  //       <input type="file" id="musicFileInput" accept="audio/*,.midi,.aif,.aiff" style="display:none;" />
  //       <input type="text" id="musicLinkInput"
  //              placeholder="${window.translations?.paste_music_url || 'or paste any audio, MIDI, Spotify, Soundslice URL...'}"
  //              class="music-upload-input" />
  //       <button onclick="handleMusicLinkPaste()" class="music-upload-add-btn">
  //         ➕ ${window.translations?.add_link || 'Add Link'}
  //       </button>
  //     </div>
  //   `;

  //   const musicFileInput = toggleWrapper.querySelector("#musicFileInput");
  //   musicFileInput.onchange = (e) => {
  //     const file = e.target.files[0];
  //     if (file) handleFileUpload(file, surrogate, "audio");
  //     e.target.value = "";
  //   };

  //   midiList.appendChild(toggleWrapper);

  //   const toggleBtn = toggleWrapper.querySelector("#musicInsertToggleBtn");
  //   const insertBox = toggleWrapper.querySelector("#musicInsertBox");

  //   toggleBtn.onclick = () => {
  //     const isOpen = !insertBox.classList.contains("hidden");
  //     insertBox.classList.toggle("hidden");
  //     toggleBtn.textContent = isOpen
  //       ? `🎵 ${window.translations?.add_music || 'Add music'}`
  //       : `✖ ${window.translations?.close || 'Close'}`;
  //   };

  //   const recordBtn = toggleWrapper.querySelector("#recordMusicBtn");
  //   recordBtn.onclick = () => {
  //     const panel = document.getElementById("recordingPanel");
  //     if (!panel) return;
  //     const isHidden = panel.classList.contains("hidden");
  //     panel.classList.toggle("hidden", !isHidden);
  //   };

  //   const dbBtn = toggleWrapper.querySelector("#importMusicDropboxBtn");
  //   dbBtn.onclick = () => {
  //     window.importMusicFromDropbox?.();
  //   };
  // }


if (isEditor && !document.getElementById("musicInsertBox")) {
  const toggleWrapper = document.createElement("div");
  toggleWrapper.className = "mt-3";

  toggleWrapper.innerHTML = `
    <!-- Record -->

    <!-- Add music toggle -->
    <button id="musicInsertToggleBtn" class="music-btn">
      🎵 ${window.translations?.add_music || 'Add music'}
    </button>

    <button id="recordMusicBtn" class="music-btn record">
      🎙️ ${window.translations?.record_music || 'Record'}
    </button>

    <button id="openGlobalPianoBtn" class="music-btn">
      🎹 Piano
    </button>

    <!-- Add music panel -->
    <div id="musicInsertBox" class="hidden music-upload-box">

      <!-- Providers -->
      <div class="music-provider-row">
        <button id="importMusicDeviceBtn" class="music-btn provider">
          <i data-lucide="folder" style="color:#f5c542; width:18px; height:18px;"></i>
          ${window.translations?.import_from_device || 'This device'}
        </button>

        <button id="importMusicDropboxBtn" class="music-btn provider">
          <img src="/icons/dropbox_0061ff.svg"
               style="height:18px; vertical-align:middle; margin-right:6px;">
          ${window.translations?.import_from_dropbox || 'Dropbox'}
        </button>

        <button id="importMusicDriveBtn" class="music-btn provider">
          <img src="/icons/googledrive.png"
               style="height:14px; vertical-align:middle; margin-right:6px;">
          ${window.translations?.import_from_google_drive || 'Google Drive'}
        </button>
      </div>

      <!-- Add link -->
      <input type="text"
             id="musicLinkInput"
             class="music-upload-input"
             placeholder="${window.translations?.paste_music_url || 'Paste audio, MIDI, Spotify, Soundslice URL…'}" />

      <button class="music-btn"
              onclick="handleMusicLinkPaste()">
        ➕ ${window.translations?.add_link || 'Add Link'}
      </button>

      <input type="file"
             id="musicFileInput"
             accept="audio/*,.midi,.aif,.aiff"
             style="display:none;" />
    </div>
  `;

  midiList.appendChild(toggleWrapper);
  window.lucide?.createIcons();

  // file input
  toggleWrapper.querySelector("#musicFileInput").onchange = e => {
    const file = e.target.files[0];
    if (file) handleFileUpload(file, surrogate, "audio");
    e.target.value = "";
  };

  // toggle add music
  const toggleBtn = toggleWrapper.querySelector("#musicInsertToggleBtn");
  const insertBox = toggleWrapper.querySelector("#musicInsertBox");

  toggleBtn.onclick = () => {
    insertBox.classList.toggle("hidden");
  };

  // record
  toggleWrapper.querySelector("#recordMusicBtn").onclick = () => {
    document.getElementById("recordingPanel")?.classList.toggle("hidden");
  };

  toggleWrapper.querySelector("#openGlobalPianoBtn").onclick = (e) => {
    e?.preventDefault?.();
    e?.stopPropagation?.();

    // Piano is allowed only on text/pdf context; close music panel first.
    const panel = document.getElementById("musicTabContent");
    if (panel?.classList.contains("visible")) {
      panel.classList.remove("visible");
      window.syncMusicPanelState?.();
    }

    const pdfActive = !!document.getElementById("pdfTabContent")?.classList.contains("active");
    const targetTab = pdfActive ? "pdfTab" : "textTab";
    if (typeof window.switchTab === "function") {
      window.switchTab(targetTab);
    }

    // Open after tab/panel state settles.
    requestAnimationFrame(() => window.TWPianoDock?.open?.());
    setTimeout(() => window.TWPianoDock?.open?.(), 40);
  };

  // providers
  toggleWrapper.querySelector("#importMusicDropboxBtn").onclick =
    () => window.importMusicFromDropbox?.();

  toggleWrapper.querySelector("#importMusicDriveBtn").onclick =
    () => window.importMusicFromGoogleDrive?.();

  toggleWrapper.querySelector("#importMusicDeviceBtn").onclick = () => {
    toggleWrapper.querySelector("#musicFileInput")?.click();
  };
}





  // renderMusicPanelRoles();


};

    
// ================================    
    
    
    async function renderMusicPlayer(wrapper, item, forceOpen = false) {
        const container = wrapper.querySelector(".musicPlayerBox");
        if (!container) return;
        
        const isVisible = container.style.display !== "none";
        const { type, url, name } = item;
        
    
        
        // Collapse logic
        if (isVisible && !forceOpen) {
        container.style.display = "none";
        // Don't clear container.innerHTML to preserve state (like MIDI playing)
        const deleteBtn = wrapper.querySelector(".musicPanel-deleteBtn");
        if (deleteBtn) deleteBtn.remove();
        return;
        }
        
    
    
        // ✅ Skip if already rendered
        if (container.dataset.renderedFor === item.url) {
          container.style.display = "block";
          return;
        }
        
        // Track what's rendered
        container.dataset.renderedFor = item.url;
        
        // Reset container
        container.style.display = "block";
        container.innerHTML = "";
            
        
        // Expand logic
        container.style.display = "block";
        
        
        // Only add delete button if needed
        const header = wrapper.querySelector(".musicPanel-header");
        if (header && !header.querySelector(".musicPanel-deleteBtn")) {
        addDeleteButton(wrapper, item);
        }
        
        // For all types except MIDI: re-render always
        if (type !== "midi") {
        container.innerHTML = "";
        }
        
        // Default player styles
        container.style.resize = "";
        container.style.overflow = "";
        container.style.height = "";
        container.style.minHeight = "";
        container.style.maxHeight = "";
        
        if (type === "spotify") {
        const clean = url.split("?")[0];
        const kind = clean.includes("/album/") ? "album" :
                     clean.includes("/playlist/") ? "playlist" : "track";
        const id = clean.split("/").pop();
        const iframe = document.createElement("iframe");
        iframe.src = `https://open.spotify.com/embed/${kind}/${id}`;
        iframe.width = "100%";
        iframe.height = "80";
        iframe.frameBorder = "0";
        iframe.allow = "autoplay; clipboard-write; encrypted-media; fullscreen; picture-in-picture";
        iframe.style.borderRadius = "12px";
        container.appendChild(iframe);
        }
    
        else if (type === "soundcloud") {
          const iframe = document.createElement("iframe");
          iframe.width = "100%";
          iframe.height = "166";
          iframe.scrolling = "no";
          iframe.frameBorder = "no";
          iframe.allow = "autoplay";
          iframe.src = `https://w.soundcloud.com/player/?url=${encodeURIComponent(url)}`;
          container.appendChild(iframe);
        }
    
        
    
    
    
    else if (type === "audio") {
      const playerWrap = document.createElement("div");
      playerWrap.className = "custom-audio-player";
    
      const audio = document.createElement("audio");
      audio.src = url;
      audio.preload = "metadata";
      audio.controls = true; 
    
      // --- Controls row ---
      const controlsRow = document.createElement("div");
      controlsRow.className = "controls-row";
    
      const startBtn = document.createElement("button");
      startBtn.className = "player-btn";
      startBtn.innerHTML = `<i data-lucide="skip-back"></i>`;
    
      const back10Btn = document.createElement("button");
      back10Btn.className = "player-btn with-number";
      back10Btn.innerHTML = `
        <i data-lucide="rotate-ccw"></i>
        <span class="btn-number">10</span>
      `;
    
      const playBtn = document.createElement("button");
      playBtn.className = "player-btn big";
      playBtn.innerHTML = `<i data-lucide="play"></i>`;
    
      const fwd10Btn = document.createElement("button");
      fwd10Btn.className = "player-btn with-number";
      fwd10Btn.innerHTML = `
        <i data-lucide="rotate-cw"></i>
        <span class="btn-number">10</span>
      `;
    
      const endBtn = document.createElement("button");
      endBtn.className = "player-btn";
      endBtn.innerHTML = `<i data-lucide="skip-forward"></i>`;
      
      // ✅ Add Download button here
        const downloadBtn = document.createElement("button");
        downloadBtn.className = "player-btn";
        downloadBtn.innerHTML = `<i data-lucide="download"></i>`;
        downloadBtn.title = "Download";
        
        downloadBtn.onclick = async (e) => {
          e.stopPropagation();
          try {
            const response = await fetch(url);
            if (!response.ok) throw new Error("Failed to fetch file");
            const blob = await response.blob();
            const blobUrl = window.URL.createObjectURL(blob);
        
            const a = document.createElement("a");
            a.href = blobUrl;
            a.download = name || "audio-file";
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        
            window.URL.revokeObjectURL(blobUrl);
          } catch (err) {
            alert("❌ Download failed: " + err.message);
          }
        };
    
    
      controlsRow.appendChild(startBtn);
      controlsRow.appendChild(back10Btn);
      controlsRow.appendChild(playBtn);
      controlsRow.appendChild(fwd10Btn);
      controlsRow.appendChild(endBtn);
      controlsRow.appendChild(downloadBtn);
    
      // --- Progress row ---
      const progressRow = document.createElement("div");
      progressRow.className = "progress-row";
      
      progressRow.draggable = false;
      progressRow.style.cursor = "default";
    
      const progress = document.createElement("input");
      progress.type = "range";
      progress.min = 0;
      progress.step = 0.1;
      progress.value = 0;
      progress.className = "progress-bar";
    
      const timeLabels = document.createElement("div");
      timeLabels.className = "time-labels";
      const elapsed = document.createElement("span");
      elapsed.className = "elapsed";
      elapsed.textContent = "0:00";
      const duration = document.createElement("span");
      duration.className = "duration";
      duration.textContent = "0:00";
      timeLabels.appendChild(elapsed);
      timeLabels.appendChild(duration);
    
      const speed = document.createElement("select");
      [0.5, 1, 1.25, 1.5, 2].forEach(val => {
        const opt = document.createElement("option");
        opt.value = val;
        opt.textContent = `${val}x`;
        if (val === 1) opt.selected = true;
        speed.appendChild(opt);
      });
      speed.className = "speed-select";
    
      progressRow.appendChild(progress);
      progressRow.appendChild(timeLabels);
      progressRow.appendChild(speed);
      
      progressRow.draggable = false;
      progressRow.style.cursor = "default";  
      
    // --- Loop mode button ---
    // --- Loop mode button ---
    const loop = document.createElement("button");
    loop.className = "player-btn loop-btn";
    updateLoopButton(loop);
    
    loop.onclick = () => {
      // cycle 0 ➜ 1 ➜ 2 ➜ 0
      window.musicLoopMode = (window.musicLoopMode + 1) % 3;
      title = updateLoopButton(loop);
      showFlashMessage?.( title );
    };
    
    progressRow.appendChild(loop);
    progressRow.appendChild(progress);
    progressRow.appendChild(timeLabels);
    progressRow.appendChild(speed);
    
    // --- Spotify-style icon + color logic ---
    function updateLoopButton(btn) {
      const mode = window.musicLoopMode || 0;
    
      // Spotify-style green for active modes
      const green = "#1db954";
      const gray  = "#999";
    
      let icon  = "repeat";
      let color = gray;
      let title = "🔁 Loop Off";
      
      if (mode === 1) {
        icon  = "repeat-1";
        color = green;
        title = "🔁 Loop Current Song";
        
      } 
      else if (mode === 2) {
        icon  = "infinity";     
        color = green;
        title = "🔁 Loop Playlist";
        
      }
    
      btn.innerHTML = `<i data-lucide="${icon}" 
                         style="color:${color};width:20px;height:20px;"></i>`;
      btn.title = title;
      window.lucide?.createIcons();
      
      return title;
    }
    
    
      
        // Add filename label only for pinned player
        if (wrapper.classList.contains("musicPanel-floating")) {
            wrapper.style.position = "relative"; // ensure absolute child works
            
            // remove old label (avoid duplicates)
            // const oldLabel = wrapper.querySelector(".musicPanel-modeLabel");
            // if (oldLabel) oldLabel.remove();
            
            // create and append new label
            // const label = document.createElement("div");
            // label.className = "musicPanel-modeLabel";
            // label.textContent = (name || "").replace(/\.[^.]+$/, ""); // remove file extension
            // wrapper.appendChild(label);
          
            // 🎯 Add unpin button (top-right corner)
            const unpinBtn = document.createElement("button");
            unpinBtn.className = "musicPanel-unpinBtn";
            unpinBtn.title = "Unpin player";
            unpinBtn.innerHTML = "📍"; // or use an <i data-lucide="x"> if you prefer an icon
            
            unpinBtn.onclick = (e) => {
              e.stopPropagation();
              if (typeof window.togglePin === "function") {
                window.togglePin(wrapper);
              }
            };
            
            // remove any previous unpin buttons (avoid duplicates)
            wrapper.querySelectorAll(".musicPanel-unpinBtn").forEach(b => b.remove());
            wrapper.appendChild(unpinBtn);
          
        }
    
    
    
    
      
    
      // --- Logic ---
      let seeking = false;
    
      playBtn.onclick = (e) => {
        e.stopPropagation();
        if (audio.paused) {
          audio.play();
          playBtn.innerHTML = `<i data-lucide="pause"></i>`;
        } else {
          audio.pause();
          playBtn.innerHTML = `<i data-lucide="play"></i>`;
        }
        lucide.createIcons();
      };
    
      startBtn.onclick = () => (audio.currentTime = 0);
    //   endBtn.onclick = () => (audio.currentTime = audio.duration || 0);
        endBtn.onclick = async (e) => {
          e.stopPropagation();
          try { audio.pause(); } catch {}
        
          // 🎵 Use the existing global helper
          if (typeof window.playNextPinnedAudio === "function") {
            await window.playNextPinnedAudio();
          } else {
            console.warn("⚠️ playNextPinnedAudio() missing");
          }
        };
      
      back10Btn.onclick = () => (audio.currentTime = Math.max(0, audio.currentTime - 10));
      fwd10Btn.onclick = () => (audio.currentTime = Math.min(audio.duration || 0, audio.currentTime + 10));
    
      audio.addEventListener("loadedmetadata", () => {
        progress.max = audio.duration || 0;
        duration.textContent = formatTime(audio.duration || 0);
      });
    
      audio.addEventListener("timeupdate", () => {
        if (!seeking) {
          progress.value = audio.currentTime;
          elapsed.textContent = formatTime(audio.currentTime);
        }
      });
      
    //   -----------------------
    
    // --- Loop behavior on song end ---
    audio.addEventListener("ended", async () => {
      if (window.musicLoopMode === 1) {
        // 🔂 Repeat same track
        audio.currentTime = 0;
        try {
          await audio.play();
          console.log("🔂 Replaying same track");
        } catch (err) {
          console.warn("⚠️ Replay failed:", err);
        }
      } 
      else if (window.musicLoopMode === 2) {
        // 🔁 Loop playlist
        console.log("🔁 Moving to next pinned audio in list...");
        if (typeof window.playNextPinnedAudio === "function") {
          await window.playNextPinnedAudio();
        } else {
          console.warn("⚠️ playNextPinnedAudio() not defined.");
        }
      } 
      else {
        // ⏹️ No loop
        playBtn.innerHTML = `<i data-lucide="play"></i>`;
        lucide.createIcons();
      }
    });
    
    
    
    
    //   -----------------------
    
      progress.addEventListener("input", (e) => {
        seeking = true;
        const val = parseFloat(e.target.value);
        elapsed.textContent = formatTime(val);
      });
    
      progress.addEventListener("change", (e) => {
        const val = parseFloat(e.target.value);
        audio.currentTime = val;
        seeking = false;
      });
    
      speed.onchange = (e) => {
        audio.playbackRate = parseFloat(e.target.value);
      };
    
      function formatTime(sec) {
        if (!isFinite(sec)) return "0:00";
        const m = Math.floor(sec / 60);
        const s = Math.floor(sec % 60);
        return `${m}:${s.toString().padStart(2, "0")}`;
      }
    
      // --- Build layout ---
    //   playerWrap.appendChild(controlsRow);
    //   playerWrap.appendChild(progressRow);
    //   container.appendChild(playerWrap);
    
      playerWrap.appendChild(controlsRow);
      container.appendChild(playerWrap);
      container.appendChild(progressRow); 
    
      lucide.createIcons();
      
        if (!window.musicPlayers) window.musicPlayers = [];
        window.musicPlayers.push({
          audio,
          wrapper,           // keep direct reference to its panel item
          url: item.url,     // canonical logical URL
          playBtn,
          startBtn,
          endBtn,
          back10Btn,
          fwd10Btn,
          progress,
          speed,
          elapsed,
          duration
        });
    
        
        window.musicController = {
          play(index) {
            musicPlayers[index]?.audio.play();
          },
          pause(index) {
            musicPlayers[index]?.audio.pause();
          },
          seek(index, seconds) {
            if (musicPlayers[index]) musicPlayers[index].audio.currentTime = seconds;
          },
          setSpeed(index, rate) {
            if (musicPlayers[index]) musicPlayers[index].audio.playbackRate = rate;
          },
          stopAll() {
            musicPlayers.forEach(p => p.audio.pause());
          }
        };
    
        
      
    }
    
    
    
    
    else if (type === "midi") {
      const isAlreadyLoaded = container.querySelector(".midi-player-wrapper");
    
      if (!isAlreadyLoaded) {
        container.innerHTML = "";
        const midiWrap = document.createElement("div");
        midiWrap.className = "midi-player-wrapper";
        container.appendChild(midiWrap);
    
        const surrogate = window.currentSurrogate;
    
        // ✅ capture vars to avoid ReferenceError
        const fileUrl = item.url;  // already set in items[] building
        const fileName = name;
    
        const ensureReady = () => {
          if (typeof window.renderCompactMidiPlayer === "function") {
            // ✅ pass surrogate, fileName, and fileUrl explicitly
            window.renderCompactMidiPlayer(midiWrap, surrogate, fileName, fileUrl);
            if (wrapper.classList.contains("musicPanel-floating")) {
              adjustPinnedPlayerPosition(wrapper);
            }
          } else {
            console.warn("⏳ Waiting for compactMidiPlayer.js to load...");
            setTimeout(ensureReady, 100);
          }
        };
    
        ensureReady();
      }
    }
    
    
    
    
        else if (type === "soundslice") {
        const iframe = document.createElement("iframe");
        iframe.src = url + "/embed/";
        iframe.width = "100%";
        iframe.height = "300";
        iframe.frameBorder = "0";
        iframe.allowFullscreen = true;
        container.appendChild(iframe);
        }
        
        else if (type === "embed") {
        // Styling
        container.style.resize = "vertical";
        container.style.overflow = "hidden";
        // container.style.overflow = "auto";
        container.style.minHeight = "150px";
        container.style.height = "200px";
        container.style.maxHeight = "500px";
        
        const iframe = document.createElement("iframe");
        iframe.src = url;
        iframe.style.width = "100%";
        iframe.style.height = "100%";
        iframe.style.border = "none";
        iframe.allow = "autoplay; encrypted-media";
        iframe.allowFullscreen = true;
        container.appendChild(iframe);
        }
        
        else {
        fetch(`/fetchMeta.php?url=${encodeURIComponent(url)}`)
          .then(res => res.json())
          .then(meta => {
            const title = meta.ogTitle || meta.title || "";
            const desc = meta.description || "";
        
            if (title || desc) {
              const info = document.createElement("div");
              info.className = "music-link-meta";
              info.style.fontSize = "13px";
              info.style.color = "#aaa";
              info.style.padding = "4px 0";
        
              if (title) {
                const titleEl = document.createElement("div");
                titleEl.textContent = title;
                titleEl.style.fontWeight = "bold";
                info.appendChild(titleEl);
              }
        
              if (desc) {
                const descEl = document.createElement("div");
                descEl.textContent = desc;
                info.appendChild(descEl);
              }
        
              container.appendChild(info);
            }
          })
          .catch(err => console.warn("Meta fetch failed", err));
        }
    }
    
    
    
    
    window.stopAllMusicLikePanel = async function () {
        
      // 1) Stop all players
      try { window._activeMidiPlayer?.stop?.() } catch {}
      try { window.musicController?.stopAll?.() } catch {}
    
      // 2) Remember open items + pinned player
      const openUrls = [...document.querySelectorAll('.musicPanel-item .musicPlayerBox')]
        .filter(box => box.style.display !== 'none')
        .map(box => box.closest('.musicPanel-item')?.dataset.url)
        .filter(Boolean);
    
      const pinnedUrl = window._pinnedMusicPlayer?.dataset?.url || null;
    
      // 3) Rebuild the panel
      await window.renderMusicPanel?.();
    
      // 4) Restore previously open players
      openUrls.forEach(url => {
        const w = [...document.querySelectorAll('.musicPanel-item')].find(x => x.dataset.url === url);
        if (w) {
          const item = { type: w.dataset.type, url: w.dataset.url, name: w.dataset.name };
          try { renderMusicPlayer(w, item, true) } catch {}
        }
      });
    
      // 5) Re-pin the pinned player
      if (pinnedUrl) {
        const w = [...document.querySelectorAll('.musicPanel-item')].find(x => x.dataset.url === pinnedUrl);
        if (w && !w.classList.contains('musicPanel-floating')) {
          try { window.togglePin(w) } catch {}
        }
      }
    };
    
    
    
    // To Be continued...
    // window.pinAllActiveMusicPlayers = function () {
    //   const panel = document.querySelector('.musicPanel');
    //   if (!panel || panel.style.display === 'none') return;
    
    //   const activePlayers = [...document.querySelectorAll('.musicPanel-item .musicPlayerBox')]
    //     .filter(box => box.style.display !== 'none')
    //     .map(box => box.closest('.musicPanel-item'))
    //     .filter(el => el && !el.classList.contains('musicPanel-floating'));
    
    //   activePlayers.forEach(el => {
    //     try {
    //       window.togglePin(el);
    //     } catch (err) {
    //       console.warn("❌ Failed to pin player:", el, err);
    //     }
    //   });
    // };
    
    
    
    
    window.isUserEditor = function () {
      const role = window.currentUserRole;
      const username = document.body.dataset.username;
      const owner = window.currentItemOwner;
      return (
        role === "owner" ||
        role === "admin" ||
        role === "editor" ||
        (owner && username && owner === username)
      );
    };
    
    
    
    
    
    async function handleMusicLinkPaste() {
      const input = document.getElementById("musicLinkInput");
      const link = input?.value.trim();
      if (!link) return;
    
      const textArea = document.getElementById("myTextarea");
    //   const updated = (textArea.value.trim() + "\n" + link).trim();
      const updated = (textArea.innerText.trim() + "\n" + link).trim();
      
    
      const ok = await updateLinkData(updated, "insert");
      if (ok) {
        input.value = "";
        setTimeout(() => renderMusicPanel(), 300);
      }
    }
    
    
    
    function removeUrlFromText(text, url) {
      if (!text || !url) return text || "";
    
      const normalizedText = String(text).replace(/\r\n/g, "\n");
      const escapedUrl = url.replace(/[-\/\\^$*+?.()|[\]{}]/g, "\\$&");
      const urlRegex = new RegExp(escapedUrl, "g");
    
      const updated = normalizedText
        .split("\n")
        .map((line) => {
          if (!line.includes(url)) return line;
    
          const lineWithoutUrl = line.replace(urlRegex, "");
          const collapsed = lineWithoutUrl
            .replace(/[ \t]{2,}/g, " ")
            .replace(/\s+([,.;:!?])/g, "$1")
            .trim();
    
          return collapsed;
        })
        .filter((line, index, lines) => {
          if (line !== "") return true;
    
          const prev = lines[index - 1];
          return prev !== "";
        })
        .join("\n")
        .replace(/\n{3,}/g, "\n\n")
        .trim();
    
      return updated;
    }
    
    window.deleteMusicLink = async function(url) {
      const textarea = document.getElementById("myTextarea");
      if (!textarea) return;
    
      const currentText = (typeof htmlToPlainText === "function")
        ? htmlToPlainText(textarea.innerHTML || "")
        : (textarea.innerText || "");
    
      const updated = removeUrlFromText(currentText, url);
    
      await updateLinkData(updated, "delete");
    
      const wrapper =
        [...document.querySelectorAll(".musicPanel-item")]
          .find(el =>
            (el.dataset.raw && normalize(el.dataset.raw) === normalize(url)) ||
            (el.dataset.url && normalize(el.dataset.url) === normalize(url))
          );
    
      if (wrapper) {
        wrapper.remove();
        showFlashNear(wrapper, "🗑️ Link removed");
      } else {
        console.warn("⚠️ No musicPanel-item found for url:", url);
      }
    };
    
    
    
    window.playMidiFile = async function (name, surrogate, container) {
      const wrapper = document.createElement("div");
      wrapper.className = "midi-player-wrapper";
      container.innerHTML = "";
      container.appendChild(wrapper);
    
      if (!window.renderCompactMidiPlayer) {
        await import('/assets/compactMidiPlayer.js');
      }
    
      window.renderCompactMidiPlayer(wrapper, surrogate, name);
    };
    
    
    
    
    
    // ===== Audio cache helpers =====
    
    function computeAudioVersion(list) {
      return list.map(f => `${f.key}:${f.size || 0}`).sort().join("|");
    }
    
    function loadCachedAudioList(surrogate) {
      try {
        const raw = localStorage.getItem(`audioList-${surrogate}`);
        return raw ? JSON.parse(raw) : [];
      } catch {
        return [];
      }
    }
    
    function saveCachedAudioList(surrogate, list) {
      localStorage.setItem(`audioList-${surrogate}`, JSON.stringify(list));
      localStorage.setItem(
        `audioListVersion-${surrogate}`,
        computeAudioVersion(list)
      );
    }
    
    function audioListChanged(surrogate, newList) {
      const oldVer = localStorage.getItem(`audioListVersion-${surrogate}`) || "";
      const newVer = computeAudioVersion(newList);
      return oldVer !== newVer;
    }
    
    
    
  
    
    async function fetchUploadedFiles(surrogate) {
      const row = document.querySelector(`.list-sub-item[data-value="${surrogate}"]`);
      const owner = row?.dataset.owner;
    
      if (!owner) return [];
    
      // 1️⃣ Load cached audio first (instant UI)
      const cached = loadCachedAudioList(surrogate);
      if (cached.length) {
        // Start background refresh (does NOT block first paint)
        updateAudioListInBackground(surrogate, owner);
        return cached;
      }

      // First open without cache: fetch immediately so panel is not empty.
      const fresh = await updateAudioListInBackground(surrogate, owner, { force: true, rerender: false });
      return Array.isArray(fresh) ? fresh : [];
    }
    
    // ===== Background updater =====
    async function updateAudioListInBackground(surrogate, owner, opts = {}) {
      try {
        const force = !!opts.force;
        const rerender = opts.rerender !== false;
        let list = [];
    
        const audioRegex = /\.(mp3|wav|ogg|m4a|flac|aac|aif|aiff|webm|mid|midi)$/i;

        // Anti-loop throttle (per surrogate) with trailing retry
        if (!window._r2ListThrottle) window._r2ListThrottle = {};
        if (!window._r2ListRetry) window._r2ListRetry = {};
        const throttleKey = `${owner}:${surrogate}`;
        const now = Date.now();
        const last = window._r2ListThrottle[throttleKey] || 0;
        if (!force && now - last < 1200) {
          console.warn("⛔ Throttled repeated R2 LIST");
          if (!window._r2ListRetry[throttleKey]) {
            const wait = 1250 - (now - last);
            window._r2ListRetry[throttleKey] = setTimeout(() => {
              window._r2ListRetry[throttleKey] = null;
              updateAudioListInBackground(surrogate, owner);
            }, Math.max(50, wait));
          }
          return [];
        }
        window._r2ListThrottle[throttleKey] = now;

        const prefix = `${owner}/surrogate-${surrogate}/files/`;
        const res = await fetch(
          `https://r2-worker.textwhisper.workers.dev/list?prefix=${encodeURIComponent(prefix)}`,
          { cache: "no-store" }
        );
        if (!res.ok) return [];

        const data = await res.json();
        list = (data || [])
          .filter(obj => audioRegex.test(obj.key || ""))
          .map(obj => ({
            key: obj.key,
            name: obj.key.split("/").pop(),
            size: obj.size,
            url: `https://audio.textwhisper.com/${obj.key}?v=${obj.version || obj.uploaded || obj.etag || Date.now()}`,
            fileServer: "cloudflare"
          }));
    
        // 2️⃣ If unchanged → done
        if (!audioListChanged(surrogate, list)) return list;
    
        // 3️⃣ Save new version
        saveCachedAudioList(surrogate, list);
    
        // 4️⃣ Only re-render if user is viewing this surrogate AND music tab open
        const isSame = window.currentSurrogate == surrogate;
        const tab = document.getElementById("musicTabContent");
        const isVisible = tab && tab.classList.contains("visible");
    
        if (rerender && isSame && isVisible) {
          console.log("🎵 Audio list changed → gentle rerender");
          setTimeout(() => window.renderMusicPanel?.(), 120);
        }
        return list;
    
      } catch (err) {
        console.warn("Audio background update error:", err);
        return [];
      }
    }
    
    
    
    function updateMusicBadge(count) {
      const musicBtn = document.querySelector('.footer-tab-btn[data-target="musicTab"]');
      if (!musicBtn) return;
    
      let badge = musicBtn.querySelector(".footer-chat-badge");
    
      if (count > 0) {
        if (!badge) {
          badge = document.createElement("span");
          badge.className = "footer-chat-badge";
          musicBtn.appendChild(badge);
        }
        badge.textContent = count;
        badge.classList.remove("zero");
      } else {
        badge?.remove();
      }
    }
    
    
    
    function refreshMusicIndicator() {
      const total = midiFiles.length + soundsliceLinks.length;
      updateMusicBadge(total);
    }
    
    
    
    window.playAudioFile = async function (name, url, container) {
      console.log("🎧 Creating audio for", name, "→", url);
    
      const wrapper = document.createElement("div");
      wrapper.className = "musicPanel-item musicPanel-player"; // ensure it's .musicPanel-item
      container.innerHTML = "";
      container.appendChild(wrapper);
    
      const header = document.createElement("div");
      header.className = "musicPanel-header";
    
      const title = document.createElement("span");
      title.className = "musicPanel-title";
      title.textContent = name;
    
      const pinBtn = document.createElement("button");
      pinBtn.className = "musicPanel-pinBtn";
      pinBtn.textContent = "📌";
      pinBtn.title = "Pin this player";
      pinBtn.addEventListener("click", () => window.togglePin(wrapper)); // ✅ shared pin logic
    
      header.appendChild(title);
      header.appendChild(pinBtn);
    
      const audio = document.createElement("audio");
      audio.controls = true;
      audio.src = url;
      audio.className = "musicPanel-audio";
    
      wrapper.appendChild(header);
      wrapper.appendChild(audio);
    };
    
    
  
    
    window.togglePin = function (wrapper) {
      const musicTabContent = document.getElementById("musicTabContent");
      const pinBtn = wrapper.querySelector(".pin-btn, .musicPanel-pinBtn");
      const type = wrapper.dataset.type;
      const isAudio = type === "audio";
      const isPinned = wrapper.classList.contains("musicPanel-bottomPinned") || wrapper.classList.contains("musicPanel-floating");
      const surrogate = window.currentSurrogate;
      if (!surrogate) return;
    
      // ----------------------------------
      // 🔹 UNPIN (general unpin)
      // ----------------------------------
      if (isPinned || wrapper === window._pinnedMusicPlayer) {
        const pinned = window._pinnedMusicPlayer || wrapper;
    
        pinned.classList.remove("musicPanel-bottomPinned", "musicPanel-floating");
        pinned._resizeObserver?.disconnect?.();
        pinned._resizeObserver = null;
    
        Object.assign(pinned.style, {
          position: "", top: "", left: "", bottom: "", width: "",
          cursor: "", zIndex: ""
        });
    
        // restore list
        const midiList = document.getElementById("midiList");
        if (midiList) midiList.appendChild(pinned);
        document.querySelectorAll(".musicPanel-item").forEach(el => (el.style.display = ""));
        Object.assign(musicTabContent.style, { height: "", padding: "", overflow: "", background: "" });
    
        if (pinBtn) pinBtn.textContent = "📌";
    
        // 🧹 clear globals
        window._pinnedMusicPlayer = null;
        window.isPinnedMode = false;
        localStorage.setItem("isPinnedMode", "false");
        window.updateMusicPinIndicator();
        window.syncMusicPanelState?.();
    
        console.log("📍 Unpinned current player (global off)");
        return;
      }
    
      // ----------------------------------
      // 🔹 PIN
      // ----------------------------------
      document.querySelectorAll(".musicPanel-item").forEach(el => {
        if (el !== wrapper) el.style.display = "none";
      });
    
      wrapper._pinnedSurrogate = surrogate;
    
      if (isAudio) {
        wrapper.classList.add("musicPanel-bottomPinned");
        Object.assign(wrapper.style, {
          position: "fixed", zIndex: "9999", cursor: "grab"
        });
    
        requestAnimationFrame(() => {
          const footer = document.getElementById("footerMenu");
          const footerH = footer ? footer.offsetHeight : 42;
          wrapper.style.bottom = `${footerH + 6}px`;
          wrapper.style.top = "";
        });
    
        makeDraggable(wrapper, wrapper);
      } else {
        wrapper.classList.add("musicPanel-floating");
        Object.assign(wrapper.style, {
          position: "fixed", zIndex: "9999", cursor: "grab",
          top: "", bottom: "", height: "auto", overflow: "visible"
        });
        makeDraggable(wrapper, wrapper);
      }
    
      // auto expand if needed
      const playerBox = wrapper.querySelector(".musicPlayerBox");
      if (playerBox && playerBox.style.display === "none") {
        playerBox.style.display = "block";
        try {
          renderMusicPlayer(wrapper, { type, url: wrapper.dataset.url, name: wrapper.dataset.name }, true);
        } catch (err) {
          console.warn("⚠️ Auto-expand on pin failed:", err);
        }
      }
    
      if (pinBtn) pinBtn.textContent = "📍";
    
      // hide list visually
      Object.assign(musicTabContent.style, {
        height: "0", padding: "0", overflow: "hidden", background: "transparent"
      });
    
      // ✅ Save global + surrogate info
      const filename = wrapper.dataset.name || "";
      window._pinnedMusicPlayer = wrapper;
      window.isPinnedMode = true;
      localStorage.setItem("isPinnedMode", "true");
      window.updateMusicPinIndicator();
    
      // Save per-surrogate "last pinned"
      window._lastPinnedState[surrogate] = { filename, type, time: Date.now() };
      window._defaultPinnedState[surrogate] ||= { filename, type };
      window.syncMusicPanelState?.();
    
      console.log("📌 Global pinned mode active —", surrogate, filename);
    };
    
    
    
    function addDeleteButton(wrapper, item) {
      const header = wrapper.querySelector(".musicPanel-header");
      if (!header) return;
    
      // Avoid duplicate buttons
      if (header.querySelector(".musicPanel-deleteBtn")) return;
    
      // Determine type: file or link
      const isFile = item?.name && item?.fileMeta;
      const isLink = item?.url && !item.fileMeta;
    
      if (!isFile && !isLink) return;
    
      // Skip if no permission for file delete
      if (isFile) {
        const file = item.fileMeta;
    //    const canDelete = file.owner === window.currentUsername || window.isAdminUser;
        const canDelete = (file.owner === window.currentUsername) || (window.currentUserItemRoleRank >= 80);
        if (!canDelete) return;
      }
    
      const deleteBtn = document.createElement("button");
      deleteBtn.className = "musicPanel-deleteBtn";
      deleteBtn.textContent = "🗑️";
      deleteBtn.title = isFile ? "Delete this file" : "Remove this link";
    
        deleteBtn.onclick = async (e) => {
          e.stopPropagation();
        
        if (isFile) {
          const file = item.fileMeta;
        
          showCenteredWarning(`Delete <b>${file.name}</b>?<br>This cannot be undone.`, () => {
            const key = file.key;
            if (!key) {
              alert("❌ Missing file key for Cloudflare delete.");
              return;
            }
            fetch(`https://r2-worker.textwhisper.workers.dev/?key=${encodeURIComponent(key)}`, {
              method: "DELETE"
            })
            .then(res => {
              if (res.ok) {
                wrapper.remove();
                showFlashNear(wrapper, "🗑️ Deleted from Cloudflare");
              } else {
                alert("❌ Failed to delete (Cloudflare): " + res.statusText);
              }
            })
            .catch(err => {
              console.warn("Delete error:", err);
              alert("❌ Delete failed. See console.");
            });
          });
        }
    
        
        //   if (isLink) {
        //     const linkToDelete = item.rawUrl || item.url;
        //     deleteMusicLink(linkToDelete, wrapper);
        //   }  
          
            if (isLink) {
              const linkToDelete = wrapper.dataset.raw || item.rawUrl || item.url; // prefer the exact pasted URL
              deleteMusicLink(linkToDelete);
            }
    
          
        };
    
    
    
      // Add next to title
      const label = header.querySelector(".musicPanel-title");
      if (label && deleteBtn.parentNode !== header) {
        label.insertAdjacentElement("afterend", deleteBtn);
      }
    }
    
    
    
    function makeDraggable(handle, target) {
      let offsetX = 0, offsetY = 0, isDragging = false;
      let longPressTimeout, wasDragged = false;
    
      target.style.position = "fixed";
    
      function onMove(e) {
        if (!isDragging) return;
        e.preventDefault();
    
        wasDragged = true; // ✅ mark that we actually moved
    
        const clientX = e.touches ? e.touches[0].clientX : e.clientX;
        const clientY = e.touches ? e.touches[0].clientY : e.clientY;
    
        const newLeft = clientX - offsetX;
        const newTop = clientY - offsetY;
    
        const maxLeft = window.innerWidth - target.offsetWidth;
        const maxTop = window.innerHeight - target.offsetHeight;
    
        target.style.left = `${Math.min(Math.max(0, newLeft), maxLeft)}px`;
        target.style.top  = `${Math.min(Math.max(0, newTop), maxTop)}px`;
      }
    
      function onEnd() {
        isDragging = false;
        document.body.style.userSelect = "";
        document.body.style.webkitUserSelect = "";
        target.classList.remove("dragging");
    
        document.removeEventListener("mousemove", onMove);
        document.removeEventListener("mouseup", onEnd);
        document.removeEventListener("touchmove", onMove);
        document.removeEventListener("touchend", onEnd);
    
        if (wasDragged) {
          // ✅ suppress next click (on both handle + target)
          const suppressClick = (e) => {
            e.stopPropagation();
            e.preventDefault();
          };
    
          handle.addEventListener("click", suppressClick, true);
          target.addEventListener("click", suppressClick, true);
    
          // clear after a tick (once click would have fired)
          setTimeout(() => {
            handle.removeEventListener("click", suppressClick, true);
            target.removeEventListener("click", suppressClick, true);
          }, 0);
        }
    
        // optional snap-back near bottom
        const rect = target.getBoundingClientRect();
        const distFromBottom = window.innerHeight - (rect.top + rect.height);
        if (distFromBottom < 100) {
        //   target.style.bottom = "42px";
          target.style.bottom = "2.625em";
          target.style.top = "auto";
        }
    
        wasDragged = false; // reset
      }
    
      function startDrag(e) {
        e.preventDefault();
        isDragging = true;
        wasDragged = false;
        target.classList.add("dragging");
    
        // release bottom anchor → position freely
        const rect = target.getBoundingClientRect();
        target.style.top = `${rect.top}px`;
        target.style.left = `${rect.left}px`;
        target.style.bottom = "auto";
        target.style.transform = "none";
    
        document.body.style.userSelect = "none";
    
        const clientX = e.touches ? e.touches[0].clientX : e.clientX;
        const clientY = e.touches ? e.touches[0].clientY : e.clientY;
        offsetX = clientX - rect.left;
        offsetY = clientY - rect.top;
    
        document.addEventListener("mousemove", onMove);
        document.addEventListener("mouseup", onEnd);
        document.addEventListener("touchmove", onMove, { passive: false });
        document.addEventListener("touchend", onEnd);
      }
    
    //   handle.addEventListener("mousedown", startDrag);
    //   handle.addEventListener("touchstart", (e) => {
    //     longPressTimeout = setTimeout(() => startDrag(e), 300);
    //   });
    
        handle.addEventListener("mousedown", (e) => {
          // 🛑 Ignore clicks on UI controls (progress, select, buttons)
          if (e.target.closest("input, select, button, .loop-btn")) return;
          startDrag(e);
        });
        
        handle.addEventListener("touchstart", (e) => {
          // 🛑 Ignore touch on UI controls
          if (e.target.closest("input, select, button, .loop-btn")) return;
          longPressTimeout = setTimeout(() => startDrag(e), 300);
        });
      
      handle.addEventListener("touchend", () => clearTimeout(longPressTimeout));
    }
    
    
    
    
    
    function extractYouTubeID(url) {
      try {
        const u = new URL(url);
        if (u.hostname === "youtu.be") return u.pathname.slice(1);
        if (u.hostname.includes("youtube.com")) {
          if (u.pathname.startsWith("/watch")) return u.searchParams.get("v");
          if (u.pathname.startsWith("/shorts/")) return u.pathname.split("/").pop();
        }
      } catch {
        return null;
      }
    }
    
    
    
    // window.musicPlatformIcons = {
    //   spotify: '<img src="https://cdn.simpleicons.org/spotify/1DB954" class="music-icon" alt="Spotify">',
    //   youtube: '<img src="https://cdn.simpleicons.org/youtube/FF0000" class="music-icon" alt="YouTube">',
    //   bandcamp: '<img src="https://cdn.simpleicons.org/bandcamp/629AA9" class="music-icon" alt="Bandcamp">',
    //   apple: '<img src="https://cdn.simpleicons.org/applemusic/FA57C1" class="music-icon" alt="Apple Music">',
    //   soundslice: '<img src="/icons/soundslice.webp" class="music-icon" alt="Soundslice" style="width:20px; height:20px;">',
    //   soundcloud: '<img src="/icons/soundcloud.svg" class="music-icon" alt="SoundCloud" style="width:20px; height:20px;">'
    // };
    
    window.musicPlatformIcons = {
      spotify: '<img src="/icons/spotify_1DB954.svg" class="music-icon" alt="Spotify">',
      youtube: '<img src="/icons/youtube_FF0000.svg" class="music-icon" alt="YouTube">',
      bandcamp: '<img src="/icons/bandcamp_629AA9.svg" class="music-icon" alt="Bandcamp">',
      apple: '<img src="/icons/applemusic_FA57C1.svg" class="music-icon" alt="Apple Music">',
      soundslice: '<img src="/icons/soundslice.webp" class="music-icon" alt="Soundslice" style="width:20px; height:20px;">',
      soundcloud: '<img src="/icons/soundcloud.svg" class="music-icon" alt="SoundCloud" style="width:20px; height:20px;">'
    };
        
    
    window.musicFallbackEmojis = {
      youtube: "🎥",
      spotify: "🎧",
      bandcamp: "🎵",
      apple: "🍎",
      soundslice: "🎼",
      soundcloud: "🎵"
    };    
    
    
    // Shared helper
    window.getMusicIconHTML = function (type) {
      const raw = window.musicPlatformIcons[type];
      const emoji = window.musicFallbackEmojis[type] || "🔗";
    
      if (typeof raw === "string" && raw.includes('<img') && raw.includes('src=')) {
        return raw;
      } else {
        return `<span class="music-icon">${emoji}</span>`;
      }
    };
    
    
    
    function adjustPinnedPlayerPosition(wrapper) {
    //   if (!wrapper || !wrapper.classList.contains("musicPanel-floating")) return;
    
    //   requestAnimationFrame(() => {
    //     const height = Math.round(wrapper.offsetHeight);
    //     const margin = 8; // Safety gap from bottom edge
    
    //     // Clamp to keep within viewport
    //     const maxTop = window.innerHeight - height - 42 - margin;
    //     const top = Math.max(12, maxTop); // Never go above top: 12px
    
    //     wrapper.style.top = `${top}px`;
    //   });
    }
    
    
    
    function escapeRegExp(string) {
      return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }
    
    
    
    function showCenteredWarning(message, onConfirm) {
      const overlay = document.createElement("div");
      overlay.style.position = "fixed";
      overlay.style.top = "0";
      overlay.style.left = "0";
      overlay.style.right = "0";
      overlay.style.bottom = "0";
      overlay.style.background = "rgba(0, 0, 0, 0.5)";
      overlay.style.zIndex = "9999";
      overlay.style.display = "flex";
      overlay.style.alignItems = "center";
      overlay.style.justifyContent = "center";
    
      const box = document.createElement("div");
      box.style.background = "#333";
      box.style.padding = "20px";
      box.style.borderRadius = "8px";
      box.style.boxShadow = "0 4px 12px rgba(0,0,0,0.3)";
      box.style.color = "#fff";
      box.style.maxWidth = "300px";
      box.style.textAlign = "center";
      box.style.fontSize = "14px";
      box.innerHTML = `<p style="margin-bottom: 12px;">${message}</p>`;
    
      const confirmBtn = document.createElement("button");
      confirmBtn.textContent = "Delete";
      confirmBtn.style.marginRight = "10px";
      confirmBtn.style.background = "#d9534f";
      confirmBtn.style.color = "#fff";
      confirmBtn.style.border = "none";
      confirmBtn.style.padding = "6px 12px";
      confirmBtn.style.cursor = "pointer";
      confirmBtn.style.borderRadius = "4px";
    
      const cancelBtn = document.createElement("button");
      cancelBtn.textContent = "Cancel";
      cancelBtn.style.padding = "6px 12px";
      cancelBtn.style.borderRadius = "4px";
      cancelBtn.style.border = "none";
      cancelBtn.style.cursor = "pointer";
      cancelBtn.style.background = "#aaa";
      cancelBtn.style.color = "#fff";
    
      confirmBtn.onclick = () => {
        document.body.removeChild(overlay);
        onConfirm();
      };
    
      cancelBtn.onclick = () => {
        document.body.removeChild(overlay);
      };
    
      box.appendChild(confirmBtn);
      box.appendChild(cancelBtn);
      overlay.appendChild(box);
      document.body.appendChild(overlay);
    }
    
    
    
    /**
     * 🎵 Refreshes music panel and restores previous open/pinned state for given surrogate
     */

    
    window.showMusicPanelForCurrentItem = async function (surrogate) {
      if (!surrogate) surrogate = window.currentSurrogate;
      if (!surrogate) return;
    
      try { window._activeMidiPlayer?.stop?.(); } catch {}
      try { window.musicController?.stopAll?.(); } catch {}
    
      try {
        await window.renderMusicPanel?.();
      } catch (err) {
        console.warn("⚠️ renderMusicPanel failed:", err);
      }
    
      // 🧠 If global pinned mode is ON → auto-pin last or default
        // 🧠 Global pinned mode → auto-pin the stored default player (preferred)
        // if (window.isPinnedMode) {
        //   const info = window._defaultPinnedState?.[surrogate] || window._lastPinnedState?.[surrogate];
        //   if (info && info.filename) {
        //     const selector = `.musicPanel-item[data-name="${CSS.escape(info.filename)}"][data-type="${info.type || "audio"}"]`;
        //     const wrapper = document.querySelector(selector);
        //     if (wrapper) {
        //       window.togglePin?.(wrapper);
        //       return; // show pinned player, not list
        //     }
        //   }
        // }
      if (window.isPinnedMode) {

        // 1️⃣ Checkbox default or last pinned (highest priority)
        const info =
          window._defaultPinnedState?.[surrogate] ||
          window._lastPinnedState?.[surrogate];

        if (info?.filename) {
          const selector =
            `.musicPanel-item[data-name="${CSS.escape(info.filename)}"][data-type="${info.type || "audio"}"]`;
          const wrapper = document.querySelector(selector);
          if (wrapper) {
            window.togglePin?.(wrapper);
            return;
          }
        }

        // 2️⃣ Role-based fallback (only if no checkbox default)
        const role = window.currentMusicRole;
        if (role && role !== "All") {
          const match = [...document.querySelectorAll(".musicPanel-item")]
            .find(w => _norm(w.dataset.name || "").includes(_norm(role)));

          if (match) {
            window.togglePin?.(match);
            return;
          }
        }
      }

    
    
      console.log("🎵 Showing normal music list for surrogate:", surrogate);
    };
    
    
    // 🎯 Update the small global pin indicator on the music button
    window.updateMusicPinIndicator = function () {
      const btn = document.querySelector('.footer-tab-btn[data-target="musicTab"]');
      if (!btn) return;
    
      // remove any existing pin badge first
      btn.querySelector('.music-pin-indicator')?.remove();
    
      if (window.isPinnedMode) {
        const pin = document.createElement('div');
        pin.className = 'music-pin-indicator';
        pin.textContent = '📍';
        btn.appendChild(pin);
      }
    };
    
    
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", () => window.updateMusicPinIndicator());
    } else {
      window.updateMusicPinIndicator();
    }
    
    // 🎵 Enable drag-drop upload directly into music panel
    document.addEventListener("DOMContentLoaded", () => {
      const musicPanel = document.getElementById("musicTabContent");
      if (!musicPanel) return;
    
      musicPanel.addEventListener("dragover", (e) => {
        if (!window.currentSurrogate) return;
        if (!e.dataTransfer?.types.includes("Files")) return;
        e.preventDefault();
        musicPanel.classList.add("drag-over");
      });
    
      musicPanel.addEventListener("dragleave", (e) => {
        e.preventDefault();
        musicPanel.classList.remove("drag-over");
      });
    
      musicPanel.addEventListener("drop", async (e) => {
        e.preventDefault();
        musicPanel.classList.remove("drag-over");
    
        const file = e.dataTransfer?.files?.[0];
        if (!file) return;
    
        const ext = file.name.split(".").pop().toLowerCase();
        const type = ["mp3","wav","ogg","m4a","flac","aac","aif","aiff","mid","midi"].includes(ext) 
                       ? (ext === "mid" || ext === "midi" ? "midi" : "audio") 
                       : null;
    
        if (!type) {
          alert("❌ Unsupported file type for music panel.");
          return;
        }
    
        await handleFileUpload(file, window.currentSurrogate, type);
      });
    });
    
    
    document.addEventListener("DOMContentLoaded", () => {
      if (document.getElementById("recordingPanel")) return;
    
      const panel = document.createElement("div");
      panel.id = "recordingPanel";
      panel.className = "recording-panel hidden";
    
      panel.innerHTML = `
        <div class="recording-header">
          <span>🎙️ ${window.translations?.record_music || "Record Music"}</span>
          <button id="closeRecordingPanel">✖</button>
        </div>
        <div class="recording-body">
          <div class="rec-timer" id="recordingTimer">00:00.0</div>
          <div class="waveform" id="recordingWave"></div>
    
          <button id="recordAudioBtn" class="record-btn">
            <i data-lucide="mic"></i>
          </button>
    
          <!-- 🎛 Noise filtering options -->
          <div class="recording-options" style="margin-top:12px; font-size:14px;">
            <label>
              <input type="checkbox" id="optNoiseSuppression">
              ${window.translations?.noise_suppression || "Noise Suppression"}
            </label><br>
            <label>
              <input type="checkbox" id="optEchoCancel">
              ${window.translations?.echo_cancellation || "Echo Cancellation"}
            </label><br>
            <label>
              <input type="checkbox" id="optAutoGain">
              ${window.translations?.auto_gain || "Auto Gain Control"}
            </label>
          </div>
    
          <div id="recordingControls" style="display:none; margin-top:16px;">
            <div id="recordingPreviewWrap"></div>
            <input id="recordingName" type="text" 
                   placeholder="${window.translations?.enter_name || "Enter recording name"}"
                   style="width:100%; margin-top:8px; padding:6px;" />
            <div class="actions">
              <button id="uploadRecordingBtn" class="confirm-btn">⬆️ ${window.translations?.upload || "Upload"}</button>
              <button id="discardRecordingBtn" class="cancel-btn">🗑️ ${window.translations?.discard || "Discard"}</button>
            </div>
          </div>
        </div>
      `;
    
      document.body.appendChild(panel);
    
    
      // Restore last-used options from localStorage
      ["optNoiseSuppression","optEchoCancel","optAutoGain"].forEach(id => {
        const saved = localStorage.getItem(id);
        if (saved !== null) document.getElementById(id).checked = saved === "true";
        document.getElementById(id).addEventListener("change", e => {
          localStorage.setItem(id, e.target.checked);
        });
      });
    
      // Close button
      panel.querySelector("#closeRecordingPanel").onclick = () => {
        panel.classList.add("hidden");
      };
    
      // === Recording logic ===
      let mediaRecorder, recordedChunks = [], isRecording = false, lastBlob = null, timerInterval, startTime = 0;
      let activeStream = null;
    
      const recordBtn = panel.querySelector("#recordAudioBtn");
      const timerEl = panel.querySelector("#recordingTimer");
      const waveEl = panel.querySelector("#recordingWave");
      const controls = panel.querySelector("#recordingControls");
      const nameInput = panel.querySelector("#recordingName");
    
      function formatTime(ms) {
        const totalSec = ms / 1000;
        const m = Math.floor(totalSec / 60);
        const s = Math.floor(totalSec % 60);
        const d = Math.floor((ms % 1000) / 100);
        return `${m}:${s.toString().padStart(2,"0")}.${d}`;
      }
    
      function startTimer() {
        startTime = Date.now();
        timerInterval = setInterval(() => {
          timerEl.textContent = formatTime(Date.now() - startTime);
          waveEl.style.backgroundPositionX = `-${(Date.now()-startTime)/50}px`;
        }, 100);
      }
      function stopTimer() {
        clearInterval(timerInterval);
        return Date.now() - startTime;
      }
    
      function finalizeRecording(blob, mime) {
        lastBlob = blob;
    
        const wrap = document.getElementById("recordingPreviewWrap");
        wrap.innerHTML = "";
    
        const fileUrl = URL.createObjectURL(blob);
        renderCustomAudioPlayer(wrap, fileUrl);
    
        controls.style.display = "block";
      }
    
      recordBtn.onclick = async () => {
        if (!isRecording) {
          try {
            // 🔹 Read user-selected noise filtering options
            const noiseSuppression = document.getElementById("optNoiseSuppression").checked;
            const echoCancellation = document.getElementById("optEchoCancel").checked;
            const autoGainControl = document.getElementById("optAutoGain").checked;
    
            activeStream = await navigator.mediaDevices.getUserMedia({
              audio: {
                echoCancellation,
                noiseSuppression,
                autoGainControl
              }
            });
    
            const mime = MediaRecorder.isTypeSupported("audio/mp4;codecs=aac")
              ? "audio/mp4;codecs=aac"
              : "audio/webm;codecs=opus";
    
            mediaRecorder = new MediaRecorder(activeStream, {
              mimeType: mime,
              audioBitsPerSecond: 192000
            });
    
            recordedChunks = [];
            lastBlob = null;
    
            mediaRecorder.ondataavailable = ev => {
              if (ev.data.size > 0) recordedChunks.push(ev.data);
            };
    
            mediaRecorder.onstop = () => {
              const duration = stopTimer();
              const blob = new Blob(recordedChunks, { type: mime });
    
              if (activeStream) {
                activeStream.getTracks().forEach(track => track.stop());
                activeStream = null;
              }
    
              if (mime.includes("webm") && window.fixWebmDuration) {
                window.fixWebmDuration(blob, duration, fixedBlob => {
                  finalizeRecording(fixedBlob, mime);
                });
              } else {
                finalizeRecording(blob, mime);
              }
            };
    
            mediaRecorder.start();
            isRecording = true;
            recordBtn.innerHTML = `<i data-lucide="square"></i>`;
            lucide.createIcons();
            startTimer();
          } catch (err) {
            alert("❌ Cannot access microphone: " + err.message);
          }
        } else {
          mediaRecorder.stop();
          isRecording = false;
          recordBtn.innerHTML = `<i data-lucide="mic"></i>`;
          lucide.createIcons();
        }
      };
    
      // Upload confirmed recording
      panel.querySelector("#uploadRecordingBtn").onclick = () => {
        if (!lastBlob) return alert("❌ No recording available.");
    
        const name = nameInput.value.trim() || `recording-${Date.now()}`;
        const mime = lastBlob.type || "audio/mp4";
        const ext = mime.includes("mp4") ? "m4a" : "webm";
    
        const file = new File([lastBlob], `${name}.${ext}`, { type: mime });
    
        handleFileUpload(file, window.currentSurrogate, "audio");
    
        panel.classList.add("hidden");
        nameInput.value = "";
        document.getElementById("recordingPreviewWrap").innerHTML = "";
        controls.style.display = "none";
    
        if (typeof renderMusicPanel === "function") {
          setTimeout(() => renderMusicPanel(), 500);
        }
      };
    
      // Discard
      panel.querySelector("#discardRecordingBtn").onclick = () => {
        lastBlob = null;
        document.getElementById("recordingPreviewWrap").innerHTML = "";
        nameInput.value = "";
        controls.style.display = "none";
        timerEl.textContent = "00:00.0";
      };
    });
    
    
    // === Custom Audio Player (patched) ===
    function renderCustomAudioPlayer(container, url) {
      const playerWrap = document.createElement("div");
      playerWrap.className = "custom-audio-player";
    
      const audio = document.createElement("audio");
      audio.src = url;
      audio.preload = "metadata";
    
      // Controls row
      const controlsRow = document.createElement("div");
      controlsRow.className = "controls-row";
    
      const back10Btn = document.createElement("button");
      back10Btn.className = "player-btn with-number";
      back10Btn.innerHTML = `<i data-lucide="rotate-ccw"></i><span class="btn-number">10</span>`;
    
      const playBtn = document.createElement("button");
      playBtn.className = "player-btn big";
      playBtn.innerHTML = `<i data-lucide="play"></i>`;
    
      const fwd10Btn = document.createElement("button");
      fwd10Btn.className = "player-btn with-number";
      fwd10Btn.innerHTML = `<i data-lucide="rotate-cw"></i><span class="btn-number">10</span>`;
    
      controlsRow.appendChild(back10Btn);
      controlsRow.appendChild(playBtn);
      controlsRow.appendChild(fwd10Btn);
    
      // Progress row
      const progressRow = document.createElement("div");
      progressRow.className = "progress-row";
      
      progressRow.draggable = false;
      progressRow.style.cursor = "default";
    
    
      const progress = document.createElement("input");
      progress.type = "range";
      progress.min = 0;
      progress.step = 0.1;
      progress.value = 0;
      progress.className = "progress-bar";
    
      const timeLabels = document.createElement("div");
      timeLabels.className = "time-labels";
      const elapsed = document.createElement("span");
      elapsed.className = "elapsed";
      elapsed.textContent = "0:00";
      const duration = document.createElement("span");
      duration.className = "duration";
      duration.textContent = "0:00";
      timeLabels.appendChild(elapsed);
      timeLabels.appendChild(duration);
    
      progressRow.appendChild(progress);
      progressRow.appendChild(timeLabels);
      
      progressRow.draggable = false;
      progressRow.style.cursor = "default";
      
      // Logic
      let seeking = false;
      playBtn.onclick = () => {
        if (audio.paused) {
          audio.play();
          playBtn.innerHTML = `<i data-lucide="pause"></i>`;
        } else {
          audio.pause();
          playBtn.innerHTML = `<i data-lucide="play"></i>`;
        }
        lucide.createIcons();
      };
    
      back10Btn.onclick = () => (audio.currentTime = Math.max(0, audio.currentTime - 10));
      fwd10Btn.onclick = () => (audio.currentTime = Math.min(audio.duration || 0, audio.currentTime + 10));
    
      audio.addEventListener("loadedmetadata", () => {
        progress.max = audio.duration || 0;
        duration.textContent = formatTime(audio.duration);
      });
    
      audio.addEventListener("timeupdate", () => {
        if (!seeking) {
          progress.value = audio.currentTime;
          elapsed.textContent = formatTime(audio.currentTime);
        }
      });
    
      progress.addEventListener("input", e => {
        seeking = true;
        elapsed.textContent = formatTime(parseFloat(e.target.value));
      });
    
      progress.addEventListener("change", e => {
        audio.currentTime = parseFloat(e.target.value);
        seeking = false;
      });
    
      // 🔹 Reset UI when playback ends
      audio.addEventListener("ended", () => {
        playBtn.innerHTML = `<i data-lucide="play"></i>`;
        progress.value = 0;
        elapsed.textContent = "0:00";
        lucide.createIcons();
      });
    
      function formatTime(sec) {
        if (!isFinite(sec)) return "0:00";
        const m = Math.floor(sec / 60);
        const s = Math.floor(sec % 60);
        return `${m}:${s.toString().padStart(2, "0")}`;
      }
    
    //   playerWrap.appendChild(controlsRow);
    //   playerWrap.appendChild(progressRow);
    //   container.appendChild(playerWrap);
      
      playerWrap.appendChild(controlsRow);
      container.appendChild(playerWrap);
      container.appendChild(progressRow);  
    
      lucide.createIcons();
    }
    
    
    
    
    // ------------------------------------------------------
    // 📦 Import audio or MIDI files directly from Dropbox
    // ------------------------------------------------------
    window.importMusicFromDropbox = async function () {
      const surrogate = window.currentSurrogate;
      if (!surrogate) {
        alert(window.translations?.select_item_first || "⚠️ Please select an item first.");
        return;
      }
    
      const ok = await initDropbox?.();
      if (!ok) return;
    
      Dropbox.choose({
        linkType: "direct",
        multiselect: true,
        extensions: [".mp3", ".wav", ".ogg", ".m4a", ".flac", ".aac", ".aif", ".aiff", ".mid", ".midi"],
        success: async (files) => {
          if (!files?.length) return;
    
          showFlashMessage?.(`📦 Importing ${files.length} file(s) from Dropbox…`);
    
          for (const f of files) {
            const fileUrl = f.link
              .replace("www.dropbox.com", "dl.dropboxusercontent.com")
              .replace("dropbox.com/s/", "dl.dropboxusercontent.com/s/")
              .replace("?dl=0", "")
              .replace("?raw=1", "");
    
            const ext = f.name.split(".").pop().toLowerCase();
            const type =
              ["mid", "midi"].includes(ext) ? "midi" :
              ["mp3","wav","ogg","m4a","flac","aac","aif","aiff"].includes(ext) ? "audio" : null;
    
            if (!type) {
              console.warn("Skipping unsupported file:", f.name);
              continue;
            }
    
            try {
              const blob = await fetch(fileUrl).then(r => r.blob());
              const file = new File([blob], f.name, { type: blob.type || "application/octet-stream" });
    
              await handleFileUpload(file, surrogate, type);
              console.log(`✅ Uploaded ${f.name} as ${type}`);
            } catch (err) {
              console.error(`❌ Failed to import ${f.name}:`, err);
              showFlashMessage?.(`⚠️ Failed: ${f.name}`);
            }
          }
    
          setTimeout(() => renderMusicPanel(), 1000);
          showFlashMessage?.("✅ Dropbox import complete!");
        },
        cancel: () => showFlashMessage?.("❌ Dropbox selection canceled."),
      });
    };
    
    
  window.importMusicFromGoogleDrive = async function () {
    const surrogate = window.currentSurrogate;
    if (!surrogate) {
      alert("Select an item first.");
      return;
    }

    // load picker
    await new Promise(r => gapi.load("picker", r));

    const token = await getGoogleDriveToken();
    if (!token) return;

    const picker = new google.picker.PickerBuilder()
      .addView(
        new google.picker.View(google.picker.ViewId.DOCS)
          .setMimeTypes(
            "audio/mpeg,audio/wav,audio/ogg,audio/x-m4a,audio/flac,audio/aac,audio/aiff,audio/midi"
          )
      )
      .enableFeature(google.picker.Feature.MULTISELECT_ENABLED)
      .setOAuthToken(token)
      .setOrigin(window.location.origin)
      .setCallback(async data => {
        if (data.action !== google.picker.Action.PICKED) return;

        for (const doc of data.docs || []) {
          const res = await fetch(
            `https://www.googleapis.com/drive/v3/files/${doc.id}?alt=media`,
            { headers: { Authorization: `Bearer ${token}` } }
          );

          if (!res.ok) continue;

          const blob = await res.blob();
          const ext = doc.name.split(".").pop().toLowerCase();
          const type = ["mid", "midi"].includes(ext) ? "midi" : "audio";

          const file = new File([blob], doc.name, { type: blob.type || "application/octet-stream" });
          await handleFileUpload(file, surrogate, type);
        }

        renderMusicPanel();
      })
      .build();

    picker.setVisible(true);
  };




    window.musicLoopMode = 0; // 0=off, 1=song, 2=list
    
    
   
    
    // 🎵 Move to next surrogate and play its default (or pinned) audio
    window.playNextPinnedAudio = async function () {
      // 0️⃣ Validate
      const cur = window.currentSurrogate;
      const list = document.querySelector(`.list-contents[data-token='${window.currentListToken}']`);
      if (!cur || !list) {
        console.warn("⚠️ Missing currentSurrogate or active list.");
        return false;
      }
    
      // 1️⃣ Find NEXT surrogate
      const items = [...list.querySelectorAll(".list-sub-item")];
      if (!items.length) return false;
    
      const idx = items.findIndex(el => el.dataset.value === String(cur));
      const nextEl = items[(idx + 1) % items.length];
      if (!nextEl) return false;
    
      const nextSurrogate = nextEl.dataset.value;
      const nextToken = nextEl.dataset.token;
    
      console.log("➡️ Next surrogate:", nextSurrogate);
      selectItem(nextSurrogate, nextToken); // triggers renderMusicPanel()
    
      // 2️⃣ Wait until the new musicPanel is actually rendered
      let tries = 0;
      while (tries++ < 40) {
        const ready = document.querySelector(".musicPanel-item");
        if (ready) break;
        await new Promise(r => setTimeout(r, 100));
      }
    
      // 3️⃣ Get pinned/default info
      let pinnedInfo =
        window._defaultPinnedState?.[nextSurrogate] ||
        window._pinnedState?.[nextSurrogate] ||
        null;
    
      let wrapper = null;
    
      if (pinnedInfo) {
        const selector = `.musicPanel-item[data-name="${CSS.escape(pinnedInfo.filename)}"][data-type="${CSS.escape(pinnedInfo.type || 'audio')}"]`;
        wrapper = document.querySelector(selector);
      }
    
      // 🎯 If no pinned/default, pick first playable
      if (!wrapper) {
        wrapper = document.querySelector(`.musicPanel-item[data-type="audio"], .musicPanel-item[data-type="midi"]`);
        if (wrapper) {
          pinnedInfo = {
            filename: wrapper.dataset.name,
            type: wrapper.dataset.type
          };
          console.log("📌 Auto-selecting first playable:", pinnedInfo.filename);
        } else {
          console.warn("⚠️ No playable item found for next surrogate.");
          return false;
        }
      }
    
      // 4️⃣ Ensure panel is visible before pinning
      const musicTabContent = document.getElementById("musicTabContent");
      musicTabContent?.classList.add("visible");
    
      // 5️⃣ Pin the player if not already pinned
      if (!wrapper.classList.contains("musicPanel-bottomPinned") && !wrapper.classList.contains("musicPanel-floating")) {
        console.log("📌 Pinning now:", pinnedInfo.filename);
        await new Promise(r => setTimeout(r, 200)); // give layout time to settle
        window.togglePin?.(wrapper);
      }
    
      // 6️⃣ Wait for audio to exist
      for (let i = 0; i < 20; i++) {
        const a = document.querySelector(".musicPanel-bottomPinned audio, .musicPanel-floating audio");
        if (a) break;
        await new Promise(r => setTimeout(r, 100));
      }
    
      // 7️⃣ Start playback
      if (typeof window.startCurrentPinnedAudio === "function") {
        const ok = await window.startCurrentPinnedAudio();
        if (ok) {
          console.log("✅ Playing pinned/default audio in next surrogate:", nextSurrogate);
          return true;
        }
      }
    
      console.warn("⚠️ Failed to start pinned/default audio in next surrogate.");
      return false;
    };
    
    
    
    
    
    // 🎧 Starts playback of the currently pinned audio player
    window.startCurrentPinnedAudio = async function () {
      const w = document.querySelector(".musicPanel-bottomPinned, .musicPanel-floating");
      if (!w) {
        console.warn("❌ No pinned wrapper found.");
        return false;
      }
    
      const findPlayer = () =>
        (window.musicPlayers || []).find(p => p.wrapper === w);
    
      let player = findPlayer();
    
      // 🔧 If player not built yet, render it and wait for registration
      if (!player) {
        const item = {
          type: w.dataset.type || "audio",
          url: w.dataset.url,
          name: w.dataset.name
        };
        console.log("🎬 Forcing renderMusicPlayer for pinned item:", item);
    
        if (typeof renderMusicPlayer === "function") {
          renderMusicPlayer(w, item, true);
        }
    
        // Wait up to ~2s for window.musicPlayers to update
        for (let i = 0; i < 20 && !player; i++) {
          await new Promise(r => setTimeout(r, 100));
          player = findPlayer();
        }
      }
    
      if (!player || !player.audio) {
        console.warn("❌ Could not resolve an audio player for the pinned wrapper.");
        return false;
      }
    
      // ▶️ Start playback and update icon
      try {
        await player.audio.play();
        const playBtn = w.querySelector(".player-btn.big");
        if (playBtn) {
          playBtn.innerHTML = `<i data-lucide="pause"></i>`;
          window.lucide?.createIcons();
        }
        console.log("✅ Pinned audio is playing:", player.url);
        return true;
      } catch (err) {
        console.warn("⚠️ Playback failed:", err);
        return false;
      }
    };

//Render the roles selection at the bottom 

//window.renderMusicPanelRoles = function renderMusicPanelRoles() {
window.renderMusicPanelRoles = function renderMusicPanelRoles() {

  const owner  = window.currentItemOwner;
  const member = window.SESSION_USERNAME;
  //Lets show the roles although not logged in
  // if (!owner || !member) return;
  if (!owner) return;

  const panel = document.getElementById("musicTabContent");
  if (!panel) return;



  const midiList = document.getElementById("midiList");
  if (!midiList) return;

  if (window._musicRolesOwner !== owner) {
    panel.querySelector(".mp-settings")?.remove();
  }
  window._musicRolesOwner = owner;

  if (panel.querySelector(".mp-settings")) return;



  

  const canEdit =
    (owner === member || window.currentUserItemRoleRank >= 80) &&
    navigator.onLine;

  const wrap = document.createElement("div");
  wrap.className = "mp-settings";
  wrap.innerHTML = `
    <div class="mp-box">
      <div class="mp-wrap"></div>
    </div>
  `;
  // midiList.appendChild(wrap);
  panel.appendChild(wrap);


  const el = wrap.querySelector(".mp-wrap");

  // === DROP TARGET FOR ROLE REORDER ===
  el.addEventListener("dragover", e => {
    e.preventDefault();

    const dragging = el.querySelector(".dragging");
    if (!dragging) return;

    const target = e.target.closest(".mp-role");
    if (!target || target === dragging) return;

    el.insertBefore(dragging, target);
  });

  el.addEventListener("drop", () => {
    roles = [...el.querySelectorAll(".mp-role")]
      .map(d => d.childNodes[0].textContent.trim())
      .filter(r => r && r !== "+");

    saveRoles();
  });

  // continue --------

  let roles = [];
  let active = "All";

  const api = (u, o) => fetch(u, o).then(r => r.json());

  // async function load() {
  //   const r1 = await api(
  //     `/getMemberRoles_json.php?owner=${encodeURIComponent(owner)}`
  //   );
  //   roles = Array.isArray(r1.roles) ? r1.roles : ["All"];

  //   const r2 = await api(
  //     `/getMemberRoleAssignment.php?owner=${encodeURIComponent(owner)}`
  //   );
  //   active = r2.role || "All";
  //   window.currentMusicRole = active; 

  //   draw();
  // }

  async function load() {
    try {
      if (typeof window.getMusicRoleFilters === "function") {
        const data = await window.getMusicRoleFilters(owner);
        roles = Array.isArray(data?.roles) ? data.roles : ["All"];
        active = data?.active || "All";
      } else {
        roles = ["All"];
        active = "All";
      }
    } catch (err) {
      console.warn("⚠️ Failed to load music roles:", err);
      roles = ["All"];
      active = "All";
    }

    if (!roles.includes("All")) roles.unshift("All");
    window.currentMusicRole = active || "All";
    draw();
  }


  async function saveRoles() {
    await api("/updateMemberRoles_json.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ owner, roles })
    });
  }

  // async function assign(role) {
  //   active = role;
  //   window.currentMusicRole = role;   // 🔑 expose active role
  //   draw();

  //   await api("/updateMemberRoleAssignment.php", {
  //     method: "POST",
  //     headers: { "Content-Type": "application/json" },
  //     body: JSON.stringify({ owner, role })
  //   });

  //   window.renderMusicPanel?.();      // 🔄 re-filter immediately
  // }

  async function assign(role) {
    active = role;
    window.currentMusicRole = role;
    draw();

    const isLoggedIn = !!window.SESSION_USERNAME;

    try {
      if (isLoggedIn && navigator.onLine) {
        await api("/updateMemberRoleAssignment.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ owner, role })
        });
      } else {
        // store locally per owner (offline-friendly)
        localStorage.setItem(
          `musicRole-${owner}`,
          role
        );
      }
    } catch (err) {
      // Fallback to local cache if request fails
      localStorage.setItem(
        `musicRole-${owner}`,
        role
      );
    }

    window.renderMusicPanel?.();
  }


  function draw() {
    el.innerHTML = "";

    roles.forEach(r => {
      const d = document.createElement("div");
      d.className = "mp-role" + (r === active ? " active" : "");
      d.textContent = r;
      d.onclick = () => assign(r);

      // 🔀 drag support (admin only, except "All")
      d.draggable = canEdit && r !== "All";

      d.ondragstart = () => d.classList.add("dragging");
      d.ondragend   = () => d.classList.remove("dragging");      

      if (canEdit && r !== "All") {
        const x = document.createElement("span");
        x.className = "mp-del";
        x.textContent = "×";
        x.onclick = e => {
          e.stopPropagation();
          removeRole(r);
        };
        d.appendChild(x);

        d.ondblclick = () => startRename(r, d);
      }

      el.appendChild(d);
    });

    if (canEdit) {
      const add = document.createElement("div");
      add.className = "mp-role add";
      add.textContent = "+";
      add.onclick = startAdd;
      el.appendChild(add);
    }
  }

  function startAdd() {
    showInput("", val => {
      if (!val || roles.includes(val)) return;
      roles.push(val);
      saveRoles().then(() => assign(val));
    });
  }

  function startRename(oldRole, pill) {
    showInput(oldRole, val => {
      if (!val || val === oldRole || roles.includes(val)) return;
      roles = roles.map(r => (r === oldRole ? val : r));
      if (active === oldRole) active = val;
      saveRoles().then(draw);
    }, pill);
  }

  function showInput(value, onDone, replaceEl = null) {
    const input = document.createElement("input");
    input.className = "mp-input";
    input.value = value;
    input.placeholder = "Role…";

    input.onkeydown = e => {
      if (e.key === "Enter") finish();
      if (e.key === "Escape") draw();
    };
    input.onblur = finish;

    function finish() {
      const v = input.value.trim();
      draw();
      if (v && v !== value) onDone(v);
    }

    if (replaceEl) {
      replaceEl.replaceWith(input);
    } else {
      el.appendChild(input);
    }

    input.focus();
    input.select();
  }

  // async function removeRole(role) {
  //   roles = roles.filter(r => r !== role);
  //   if (active === role) active = "All";
  //   await saveRoles();
  //   draw();
  // }
  async function removeRole(role) {
    if (!confirm(`Delete role "${role}"?`)) return;

    roles = roles.filter(r => r !== role);
    if (active === role) active = "All";
    await saveRoles();
    draw();
  }


  load();
};


function _norm(s) {
  return s
    .toLowerCase()
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "");
}

function fileMatchesActiveRole(filename) {
  const role = window.currentMusicRole || window.getCurrentRole();
  if (role === "All") return true;
  
  if (!filename) return true;
  return _norm(filename).includes(_norm(role));
}


window.getCurrentRole = function () {
  if (window.currentMusicRole) return window.currentMusicRole;

  const owner = window.currentItemOwner;
  if (!owner) return "All";

  // fire once
  if (window._loadingMusicRole) return "All";
  window._loadingMusicRole = true;

  fetch(`/getMemberRoleAssignment.php?owner=${encodeURIComponent(owner)}`)
    .then(r => r.json())
    .then(d => {
      window.currentMusicRole = d.role || "All";
      window._loadingMusicRole = false;

      // 🔁 rerender now that role is known
      window.renderMusicPanel?.();
    })
    .catch(() => {
      window.currentMusicRole = "All";
      window._loadingMusicRole = false;
    });

  return "All";
};
    
    
