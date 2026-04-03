logStep("JSFunctions.js executed");

// Mark first page load
window.isInitialLoad = true;

window.onbeforeunload = closingCode;

function closingCode(){
  if (tMod > 0) {
    insertData(true, "closing"); // force save, with context
    tMod = 0;
  }
  return null;
}

const TW_AVATAR_COLORS = [
  "#2f5eab",
  "#1f8a70",
  "#d4521a",
  "#7a4fd3",
  "#0f766e",
  "#b45309",
  "#be185d",
  "#2563eb",
  "#0ea5a4",
  "#a21caf",
  "#2d6a4f",
  "#f97316"
];

function twHashString(value) {
  const str = String(value || "");
  let hash = 2166136261;
  for (let i = 0; i < str.length; i += 1) {
    hash ^= str.charCodeAt(i);
    hash += (hash << 1) + (hash << 4) + (hash << 7) + (hash << 8) + (hash << 24);
  }
  return hash >>> 0;
}

function twAvatarInitials(name) {
  if (!name) return "?";
  const parts = String(name).trim().split(/\s+/);
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

function twAvatarDataUrl(seed, initials) {
  const hash = twHashString(seed);
  const primary = TW_AVATAR_COLORS[hash % TW_AVATAR_COLORS.length];
  const secondary = TW_AVATAR_COLORS[(hash >>> 8) % TW_AVATAR_COLORS.length];
  const accent = primary === secondary
    ? TW_AVATAR_COLORS[(hash >>> 16) % TW_AVATAR_COLORS.length]
    : secondary;
  const safeInitials = (initials || "?").slice(0, 2).toUpperCase();
  const svg = `
    <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">
      <defs>
        <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0%" stop-color="${primary}"/>
          <stop offset="100%" stop-color="${accent}"/>
        </linearGradient>
      </defs>
      <rect width="64" height="64" rx="32" fill="url(#g)"/>
      <text x="50%" y="52%" text-anchor="middle" dominant-baseline="middle"
        font-family="system-ui, -apple-system, Segoe UI, sans-serif"
        font-size="24" font-weight="700" fill="#ffffff">${safeInitials}</text>
    </svg>
  `.trim();
  return `data:image/svg+xml;utf8,${encodeURIComponent(svg)}`;
}

function twResolveAvatarUrl(member, fallbackName) {
  const raw = (member?.avatar_url || member?.avatarUrl || "").trim();
  if (raw && !raw.includes("default-avatar.png")) {
    const normalized = raw.startsWith("//") ? `${location.protocol}${raw}` : raw;
    if (normalized.startsWith("/uploads/avatars/")) {
      const filename = normalized.split("/").pop() || "";
      if (filename) return `/avatar-file.php?name=${encodeURIComponent(filename)}`;
    }
    try {
      const url = new URL(normalized, location.origin);
      if (url.origin !== location.origin) {
        return `/avatar-proxy.php?url=${encodeURIComponent(url.href)}`;
      }
      return url.href;
    } catch {
      return raw;
    }
  }
  const name = fallbackName
    || member?.display_name
    || member?.username
    || member?.email
    || member?.title
    || "";
  const seed = member?.member_id
    || member?.id
    || member?.email
    || member?.username
    || member?.token
    || name
    || "user";
  return twAvatarDataUrl(seed, twAvatarInitials(name || seed));
}

window.twResolveAvatarUrl = twResolveAvatarUrl;
window.twAvatarDataUrl = twAvatarDataUrl;
window.twAvatarInitials = twAvatarInitials;
window.twGetLastProfileUser = function twGetLastProfileUser() {
  return String(
    window.currentUsername ||
    window.SESSION_USERNAME ||
    document.body?.dataset?.loggedInUser ||
    ""
  ).trim();
};
window.twLastProfileStorageKey = function twLastProfileStorageKey() {
  const user = window.twGetLastProfileUser?.() || "";
  return user ? `last-selected-list-owner:${user}` : "last-selected-list-owner:__global__";
};
window.twLastProfileCookieName = function twLastProfileCookieName() {
  const user = window.twGetLastProfileUser?.() || "";
  const safe = user.replace(/[^A-Za-z0-9_]/g, "_");
  return safe ? `tw_last_profile_${safe}` : "tw_last_profile";
};
window.twGetRememberedLastProfile = function twGetRememberedLastProfile() {
  const key = window.twLastProfileStorageKey?.() || "";
  try {
    const fromScoped = String((key ? localStorage.getItem(key) : "") || "").trim();
    if (fromScoped) return fromScoped;
    // Backward-compatible global fallback for offline/no-session startup.
    const fromGlobal = String(localStorage.getItem("last-selected-list-owner:__global__") || "").trim();
    return fromGlobal;
  } catch {
    return "";
  }
};
window.twRememberLastProfile = function twRememberLastProfile(token) {
  const key = window.twLastProfileStorageKey?.() || "";
  const cookieName = window.twLastProfileCookieName?.() || "";
  const candidate = String(token || "").trim();
  if (!/^[A-Za-z0-9._-]{2,80}$/.test(candidate)) return;
  try {
    if (key) localStorage.setItem(key, candidate);
    // Always keep a global pointer so offline startup still has an owner hint
    // after session timeout/logout.
    localStorage.setItem("last-selected-list-owner:__global__", candidate);
  } catch {}
  const secure = location.protocol === "https:" ? "; Secure" : "";
  if (cookieName) {
    document.cookie =
      `${cookieName}=${encodeURIComponent(candidate)}; Path=/; Max-Age=15552000; SameSite=Lax${secure}`;
  }
  document.cookie =
    `tw_last_profile=${encodeURIComponent(candidate)}; Path=/; Max-Age=15552000; SameSite=Lax${secure}`;
};

window.twHandleAvatarError = function (img) {
  if (!img || img.dataset.avatarFallbackDone) return;
  const name = img.getAttribute("alt") || "User";
  img.dataset.avatarFallbackDone = "1";
  img.onerror = null;
  img.src = twAvatarDataUrl(name, twAvatarInitials(name));
};

function updateHomeDropdownCurrentProfile(ownerData, ownerToken) {
  const item = document.getElementById("homeCurrentProfileItem");
  if (!item) return;

  const loggedIn = (window.currentUsername || "").trim();
  const token = String(ownerToken || window.currentOwnerToken || "").trim();
  const owner = ownerData || window.currentOwner || {};
  const username = String(owner?.username || token || "").trim();

  if (!username || username === loggedIn) {
    item.style.display = "none";
    return;
  }

  item.style.display = "";
  const link = document.getElementById("homeCurrentProfileLink");
  if (link) link.href = "/" + encodeURIComponent(username);

  const label = owner?.display_name || owner?.name || username;
  const labelEl = document.getElementById("homeCurrentProfileLabel");
  if (labelEl) labelEl.textContent = label;

  const avatarEl = document.getElementById("homeCurrentProfileAvatar");
  if (avatarEl) {
    avatarEl.src = twResolveAvatarUrl(owner, label);
  }
}

window.updateHomeDropdownCurrentProfile = updateHomeDropdownCurrentProfile;

function updateHomeRecentProfiles(ownerData, ownerToken) {
  const mount = document.getElementById("homeRecentProfilesMount");
  if (!mount) return;

  const loggedIn = (window.currentUsername || "").trim();
  const token = String(ownerToken || window.currentOwnerToken || "").trim();
  const owner = ownerData || window.currentOwner || {};
  const username = String(owner?.username || token || "").trim();

  let list = [];
  try { list = JSON.parse(localStorage.getItem("homeRecentProfiles") || "[]"); } catch {}

  if (username && username !== loggedIn) {
    list = list.filter(p => p && p.username && p.username !== username);
    list.unshift({
      username,
      display_name: owner?.display_name || username,
      avatar_url: owner?.avatar_url || ""
    });
    list = list.filter(p => p.username !== loggedIn);
    list = list.slice(0, 4);
    localStorage.setItem("homeRecentProfiles", JSON.stringify(list));
  }

  const parent = mount.parentElement;
  if (!parent) return;
  parent.querySelectorAll(".home-recent-profile, .home-recent-divider").forEach(el => el.remove());

  const visible = list.filter(p => p && p.username && p.username !== username);
  if (visible.length < 1) return;

  const divider = document.createElement("li");
  divider.className = "dropdown-divider home-recent-divider";
  parent.insertBefore(divider, mount.nextSibling);

  visible.forEach(p => {
    const li = document.createElement("li");
    li.className = "home-recent-profile";
    li.innerHTML = `
      <a class="dropdown-item d-flex align-items-center gap-2" href="/${encodeURIComponent(p.username)}">
        <img class="home-avatar" src="${twResolveAvatarUrl(p, p.display_name || p.username)}" alt="Profile avatar">
        ${(window.homeToLabel || window.translations?.home_to || "Home to:")} ${p.display_name || p.username}
      </a>`;
    parent.insertBefore(li, divider.nextSibling);
  });
}

window.updateHomeRecentProfiles = updateHomeRecentProfiles;

if (!window.twAvatarErrorListenerAttached) {
  window.twAvatarErrorListenerAttached = true;
  document.addEventListener("error", (event) => {
    const img = event.target;
    if (!(img instanceof HTMLImageElement)) return;
    if (img.dataset && img.dataset.avatarFallbackDone) return;
    if (!img.getAttribute("alt")) return;
    window.twHandleAvatarError(img);
  }, true);
}




var target = "";

function getTarget() {
  // Use effective token
  return getEffectiveToken();
}


window.showHomeTab = function showHomeTab(url) {
  // ✅ Guard 1: must have a target container
  const wrapper = document.getElementById("mainContentWrapper");
  if (!wrapper) return false;

  // ✅ Guard 2: only when NO surrogate is selected
  const allowSelectedItemPreview =
    typeof url === "string" && url.indexOf("/menu_preview.php") === 0;
  if (!allowSelectedItemPreview && window.currentSurrogate && window.currentSurrogate !== "0") {
    return false;
  }

  // ✅ Guard 3: must have a usable URL
  if (!url || typeof url !== "string") {
    console.warn("Home tab skipped: invalid URL", url);
    return false;
  }

  // 🔄 Cleanup previous home (idempotent)
  document.getElementById("homeTabContent")?.remove();

  // 🔄 Deactivate other main tabs
  document
    .querySelectorAll(".main-tab-content.active")
    .forEach(el => el.classList.remove("active"));

  // 🏠 Create home tab
  const home = document.createElement("div");
  home.id = "homeTabContent";
  home.className = "main-tab-content active";
  home.style.cssText = `
    display: flex;
    flex-direction: column;
    flex: 1;
    min-height: 0;
    box-sizing: border-box;
  `;

  if (!document.getElementById("homeTabSwitcherStyles")) {
    const style = document.createElement("style");
    style.id = "homeTabSwitcherStyles";
    style.textContent = `
      .tw-home-tabs {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        max-width: calc(100% - 24px);
        padding: 6px 10px;
        margin: 8px 12px 6px;
        border-radius: 999px;
        background: linear-gradient(120deg, #f6efe3, #eef6ff);
        border: 1px solid rgba(40, 95, 230, 0.14);
        box-shadow: 0 8px 18px rgba(20, 17, 12, 0.08);
        overflow-x: auto;
        white-space: nowrap;
        scrollbar-width: none;
      }
      .tw-home-tabs::-webkit-scrollbar {
        display: none;
      }
      .tw-home-tab {
        border: 1px solid rgba(20, 17, 12, 0.14);
        background: rgba(255, 255, 255, 0.9);
        color: #1b1a17;
        padding: 4px 10px;
        height: 28px;
        border-radius: 999px;
        font-family: "Bodoni MT", "Didot", "Garamond", "Georgia", serif;
        font-size: 13px;
        font-weight: 600;
        letter-spacing: 0.3px;
        cursor: pointer;
        transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
      }
      .tw-home-tab:hover {
        transform: translateY(-1px);
        box-shadow: 0 6px 12px rgba(20, 17, 12, 0.12);
      }
      .tw-home-tab.is-active {
        border-color: rgba(241, 90, 42, 0.7);
        background: linear-gradient(135deg, #fff6ed, #ffffff);
        color: #b0421d;
      }
      @media (max-width: 720px) {
        .tw-home-tabs {
          padding: 4px 8px;
          margin: 6px 10px 4px;
        }
        .tw-home-tab {
          height: 26px;
          padding: 3px 10px;
          font-size: 12px;
        }
      }
    `;
    document.head.appendChild(style);
  }

  const owner = window.currentOwner || null;
  const homePage = (owner?.home_page || "").trim();
  const baseHomeUrl = "/TW_Home.php";
  const tutorialUrl = `${baseHomeUrl}#tutoring-videos`;
  const ownerToken = window.currentOwnerToken ? encodeURIComponent(window.currentOwnerToken) : "";
  const eventUrl = ownerToken ? `/ep_event_planner.php?owner=${ownerToken}` : "/ep_event_planner.php";
  const menuToken = window.currentListToken ? encodeURIComponent(window.currentListToken) : "";
  const surrogateToken = window.currentSurrogate ? encodeURIComponent(window.currentSurrogate) : "";
  const previewUrl = `/menu_preview.php?owner=${ownerToken}${menuToken ? `&token=${menuToken}` : ""}${surrogateToken && surrogateToken !== "0" ? `&surrogate=${surrogateToken}` : ""}`;
  const [urlBase, urlHash] = url.split("#");
  const isProfileActive = homePage && url === homePage;
  const isTutorialActive = urlBase === baseHomeUrl && urlHash === "tutoring-videos";
  const isEventActive = urlBase === eventUrl;
  const isPreviewActive = urlBase === previewUrl;
  const isHomeActive = !isProfileActive && !isTutorialActive && !isEventActive && !isPreviewActive;
  const homeActiveClass = isHomeActive ? " is-active" : "";
  const profileActiveClass = isProfileActive ? " is-active" : "";
  const tutorialActiveClass = isTutorialActive ? " is-active" : "";
  const eventActiveClass = isEventActive ? " is-active" : "";
  const previewActiveClass = isPreviewActive ? " is-active" : "";
  home.style.paddingBottom = "0";

  home.innerHTML = `
    <div class="tw-home-tabs">
      <button class="tw-home-tab${homeActiveClass}" type="button" data-home-tab="home">
        TapTray
      </button>
      <button class="tw-home-tab${tutorialActiveClass}" type="button" data-home-tab="tutorials">
        Tutorials
      </button>
      <button class="tw-home-tab${eventActiveClass}" type="button" data-home-tab="events">
        Event planner
      </button>
      <button class="tw-home-tab${previewActiveClass}" type="button" data-home-tab="preview">
        Menu preview
      </button>
    </div>
    <iframe
      src="${url}"
      style="flex:1 1 auto;min-height:0;height:100%;width:100%;border:none;background:#fff;"
    ></iframe>
  `;

  home.querySelector('[data-home-tab="home"]')?.addEventListener("click", () => {
    window.showHomeTab(baseHomeUrl);
  });

  home.querySelector('[data-home-tab="tutorials"]')?.addEventListener("click", () => {
    window.showHomeTab(tutorialUrl);
  });

  home.querySelector('[data-home-tab="events"]')?.addEventListener("click", () => {
    window.showHomeTab(eventUrl);
  });

  home.querySelector('[data-home-tab="preview"]')?.addEventListener("click", () => {
    window.showHomeTab(previewUrl);
  });

  wrapper.appendChild(home);

  // Event Planner sometimes mounts a blank iframe on startup.
  // Keep the fix local to this iframe path: one watchdog + one retry.
  const iframe = home.querySelector("iframe");
  if (iframe && isEventActive) {
    let settled = false;
    let retried = false;
    let watchdog = 0;

    const withRetryStamp = (src) => {
      const sep = src.includes("?") ? "&" : "?";
      return `${src}${sep}_tw_ep_retry=${Date.now()}`;
    };

    const isIframeBlank = () => {
      try {
        const doc = iframe.contentDocument;
        if (!doc) return false;
        const href = String(iframe.contentWindow?.location?.href || "");
        if (href === "about:blank") return true;
        const body = doc.body;
        const text = String(body?.textContent || "").trim();
        const childCount = Number(body?.children?.length || 0);
        return childCount === 0 && text === "";
      } catch {
        return false;
      }
    };

    const armWatchdog = () => {
      if (watchdog) clearTimeout(watchdog);
      watchdog = setTimeout(() => {
        if (!home.isConnected || settled || retried) return;
        retried = true;
        iframe.src = withRetryStamp(url);
        armWatchdog();
      }, 3500);
    };

    iframe.addEventListener("load", () => {
      if (watchdog) clearTimeout(watchdog);
      setTimeout(() => {
        if (!home.isConnected || settled) return;
        if (isIframeBlank() && !retried) {
          retried = true;
          iframe.src = withRetryStamp(url);
          armWatchdog();
          return;
        }
        settled = true;
      }, 120);
    }, { once: false });

    armWatchdog();
  }

  return true;
};





function highlightSelectedItem(surrogate, scopeEl = document) {
  const scopeLabel = scopeEl === document ? "[global document]" : `#${scopeEl.id || "[no-id element]"}`;
//   console.log(`[highlightSelectedItem] 🔍 Trying to highlight surrogate: ${surrogate} in scope: ${scopeLabel}`);

  // 🧹 always remove previous highlights globally
  document.querySelectorAll(".list-sub-item.active").forEach(el => el.classList.remove("active"));

  // ✅ find the correct instance in current scope
  const targetItem = scopeEl.querySelector(`.list-sub-item[data-value="${surrogate}"]`);

  if (targetItem) {
    targetItem.classList.add("active");
    // console.log(`[highlightSelectedItem] ✅ Highlighted item: ${surrogate} in ${scopeLabel}`);
  } else {
    console.warn(`[highlightSelectedItem] ❌ Item not found for surrogate ${surrogate} in scope: ${scopeLabel}`);
  }
}



/**
 * selectItem(surrogate, token, listContainer)
 *
 * Main function to handle selecting an item in a list.
 *
 * Behavior:
 * - On first page load/refresh: if textarea is prefilled by PHP, skip getText()
 *   to avoid double reload and keep text visible instantly. Still highlights the item.
 * - On user clicks: always reload text via getText(), even if selecting the same item.
 * - Always updates URL, highlights sidebar, sets currentSurrogate/currentListToken,
 *   and refreshes PDF/music panels as needed.
 */











function tryAddItemToCurrentList(surrogate, token) {
    const sid = parseInt(surrogate, 10) || 0;
    if (sid <= 0) return Promise.resolve();
    return fetch("/addItemToList.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: `token=${token}&surrogate=${sid}`,
        credentials: "include" 
    })
    .then(res => res.json())
    .then(data => {
        if (data.status !== "OK") {
            console.warn("❌ addItemToList failed:", data.error || "Unknown error");
        }
    });
}







function removeCurrentFromList(surrogate) {
    const items = document.querySelectorAll(`.list-sub-item[data-value='${surrogate}']`);

    if (!items.length) {
        console.warn("⚠️ No item found to remove for surrogate:", surrogate);
        return;
    }

    items.forEach(item => {
        const wasActive = item.classList.contains("active");
        const nextItem = item.nextElementSibling || item.parentElement.querySelector(`.list-sub-item:not([data-value='${surrogate}'])`);
        item.remove();

        if (wasActive && nextItem) {
            nextItem.classList.add("active");
            const nextSurrogate = nextItem.dataset.value;
            // Find token from closest parent
            const token = nextItem.closest(".list-contents")?.dataset.token || null;
            if (nextSurrogate && token) {
                selectItem(nextSurrogate, token);

                setTimeout(() => {
                  // Update global state first
                  window.currentSurrogate = nextSurrogate;
                  window.currentListToken = token;
                  history.replaceState({}, "", `/${token}/${nextSurrogate}`);
            
                }, 50);                
                
            }
        }
    });

    // If no items remain anywhere with this surrogate, clear textareas and update URL
    if (document.querySelectorAll(`.list-sub-item[data-value='${surrogate}']`).length === 0) {
        document.querySelectorAll(".textareas-container textarea").forEach(el => el.value = "");
        // Update URL to just user or default token if you have a way
        const currentToken = getEffectiveToken ? getEffectiveToken() : '';
        window.history.pushState({}, "", currentToken ? `/${currentToken}` : "/");
    }

    // Invalidate import similarity cache so external trees stop marking deleted items as existing.
    if (typeof window !== "undefined") {
        window._importSimilarityIndex = null;
        const importHost = document.getElementById("importTabContent");
        if (importHost && typeof window.driveRefresh === "function") {
            ["driveTree", "driveTreeB"].forEach((paneId) => {
                const tree = document.getElementById(paneId);
                if (!tree || !tree.children.length) return;
                const maybePromise = window.driveRefresh(paneId);
                if (maybePromise && typeof maybePromise.catch === "function") {
                    maybePromise.catch(() => {});
                }
            });
        }
    }

    console.log("✅ Removed item(s) with surrogate", surrogate);
}


window.setListPrivacy = async function (token, level) {
  const options = ["public", "private", "secret"];
  if (!level || !options.includes(level)) {
    alert("🚫 Invalid privacy setting.");
    return;
  }

  try {
    const res = await fetch("/setListPrivacy.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: `token=${encodeURIComponent(token)}&access_level=${encodeURIComponent(level)}`
    });

    const data = await res.json();

    if (data.status === "success") {
      showFlashMessage(`✅ Privacy set to: ${level}`);

      const iconMap = { public: "🌐", private: "🔒", secret: "🕵️" };
      // 🔄 update the icon in the **open** menu (if present)
      const menuIcon = document.getElementById(`menu-privacy-icon-${token}`);
      if (menuIcon) menuIcon.textContent = iconMap[level] || "❓";

      // 🧾 stamp new access on the list row so next open reads the right state
      const row = document.querySelector(`.group-item[data-group="${CSS.escape(token)}"]`);
      if (row) row.dataset.access = level;

    } else {
      throw new Error(data.error || "Failed to update privacy.");
    }
  } catch (err) {
    console.error("❌ Privacy update failed:", err);
    alert("❌ Could not update list privacy.");
  }
};




function setListPrivacyPrompt(token, button) {
//   console.log("🔒 Set list privacy selected...");

  // Remove existing menus
  document.querySelectorAll(".inline-list-selector").forEach(el => el.remove());

  const t = window.translations || {};
  const levels = [
    { label: "🌐 " + (t.privacy_public || "Public"), value: "public" },
    { label: "🔒 " + (t.privacy_private || "Private"), value: "private" },
    { label: "🕵️ " + (t.privacy_secret || "Secret"), value: "secret" }
  ];

  const dropdown = document.createElement("div");
  dropdown.classList.add("inline-list-selector");
  dropdown.style.position = "absolute";
  dropdown.style.top = `${button.offsetTop + button.offsetHeight}px`;
  dropdown.style.left = `${button.offsetLeft}px`;
  dropdown.style.zIndex = 1000;

  levels.forEach(({ label, value }) => {
    const choice = document.createElement("div");
    choice.classList.add("list-choice");
    choice.textContent = label;
    choice.dataset.level = value;

    choice.addEventListener("click", function (event) {
      event.stopPropagation();
      setListPrivacy(token, this.dataset.level.trim());
      dropdown.remove();
    });

    dropdown.appendChild(choice);
  });

  // Close when clicking outside
  setTimeout(() => {
    const handler = (e) => {
      if (!dropdown.contains(e.target)) {
        dropdown.remove();
        document.removeEventListener("click", handler);
      }
    };
    document.addEventListener("click", handler);
  }, 10);

  button.parentElement.appendChild(dropdown);
}


 var tMod = 0;


    
let lastUsedListToken = null;
let lastUsedListName = null;



function loadUserList() {
    console.log("📋 Loading friend groups...");

    const userContainer = document.getElementById("userList");
    if (!userContainer) {
        console.error("❌ User list container not found!");
        return;
    }

    // Load the structure (My Friends + Lists Chat Members)
    fetch("/getFriendsLists.php")
        .then(res => res.text())
        .then(html => {
            userContainer.innerHTML = html;

            // Bind click on all preloaded friend items
            userContainer.querySelectorAll(".user-item").forEach(bindFriendClick);

            // Attach expand handlers for each list subgroup
            userContainer.querySelectorAll(".friends-list-subgroup-header[data-load='true']").forEach(header => {
                header.addEventListener("click", async () => {
                    const wrapper = header.closest(".friends-list-subgroup");
                    const content = wrapper?.querySelector(".list-contents");
                    const arrow = header.querySelector(".arrow");
                    const token = header.dataset.token;

                    if (!content) return;

                    const isExpanded = content.classList.contains("friends-content-visible");

                    // Toggle visibility
                    content.classList.toggle("friends-content-visible", !isExpanded);
                    content.classList.toggle("friends-content-hidden", isExpanded);
                    arrow.textContent = isExpanded ? "▶" : "▼";

                    // Lazy load if not yet loaded
                    if (!isExpanded && !content.dataset.loaded) {
                        try {
                            await loadChatListMembers(token, content);
                            content.dataset.loaded = "true";

                            // Attach click to any newly loaded friends
                            content.querySelectorAll(".user-item").forEach(bindFriendClick);
                        } catch (err) {
                            console.error("❌ Failed to load members:", err);
                            content.innerHTML = `<p class="text-danger">Failed to load members.</p>`;
                        }
                    }
                });
            });

            bindSidebarSearch();
        })
        .catch(err => {
            console.error("❌ Failed to load friend groups:", err);
            userContainer.innerHTML = `<p class="text-danger">Failed to load friends.</p>`;
        });

    function bindFriendClick(item) {
        item.addEventListener("click", e => {
            e.preventDefault();
            e.stopPropagation();
            openFriendActionMenu(item);
        });
    }
}

function openFriendProfileFromRow(item) {
  const selectedUser = (item?.dataset?.user || "").trim();
  if (!selectedUser) return;
  console.log(`👤 Friend profile: ${selectedUser}`);
  window.history.pushState({}, "", `/${selectedUser}`);
  document.querySelector(".tab-link[data-target='listsTab']")?.click();
}

function openFriendChatFromRow(item) {
  const memberId = Number(item?.dataset?.memberId || 0);
  const fallbackName = (item?.dataset?.displayName || item?.dataset?.user || "Direct Message").trim();
  if (!memberId) {
    alert("Chat unavailable for this member.");
    return;
  }
  if (typeof window.openDMChatWithMember === "function") {
    window.openDMChatWithMember(memberId, fallbackName);
    return;
  }
  alert("Chat is not available yet.");
}

function closeFriendActionMenu() {
  const existing = document.getElementById("friendsActionMenu");
  if (existing) existing.remove();
}

function openFriendActionMenu(item) {
  if (!item) return;
  closeFriendActionMenu();

  const username = (item.dataset.user || "").trim();
  if (!username) return;

  const displayName = (item.dataset.displayName || username).trim();
  const safeText = (value) => String(value || "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
  const menu = document.createElement("div");
  menu.id = "friendsActionMenu";
  menu.className = "tw-context-menu";
  menu.style.position = "fixed";
  menu.style.zIndex = "100000";
  menu.style.minWidth = "190px";
  menu.innerHTML = `
    <div class="item" data-action="profile">👤 Go to ${safeText(displayName)} profile</div>
    <div class="item" data-action="chat">💬 Chat with ${safeText(displayName)}</div>
  `;

  document.body.appendChild(menu);
  const rect = item.getBoundingClientRect();
  const margin = 8;
  let left = rect.left + margin;
  let top = rect.bottom + 4;
  const menuRect = menu.getBoundingClientRect();
  if (left + menuRect.width > window.innerWidth - 8) {
    left = Math.max(8, window.innerWidth - menuRect.width - 8);
  }
  if (top + menuRect.height > window.innerHeight - 8) {
    top = Math.max(8, rect.top - menuRect.height - 4);
  }
  menu.style.left = `${left}px`;
  menu.style.top = `${top}px`;

  menu.addEventListener("click", (e) => {
    const action = e.target?.dataset?.action;
    if (!action) return;
    e.stopPropagation();
    if (action === "profile") openFriendProfileFromRow(item);
    if (action === "chat") openFriendChatFromRow(item);
    closeFriendActionMenu();
  });

  setTimeout(() => {
    document.addEventListener("click", function outsideHandler(ev) {
      if (!menu.contains(ev.target)) {
        closeFriendActionMenu();
        document.removeEventListener("click", outsideHandler);
      }
    });
  }, 0);
}



function loadChatListMembers(token, container) {
  if (!token || !container) return Promise.resolve(); // Always return a Promise

  container.innerHTML = "<p style='padding: 4px 8px;'>Loading members…</p>";

  //return fetch(`/getListAccess.php?token=${encodeURIComponent(token)}`)
  return fetch(`/getFriendsListsMembers.php?token=${encodeURIComponent(token)}`)

    .then(res => res.json())
    .then(data => {
      if (!Array.isArray(data)) {
        container.innerHTML = "<p class='text-danger'>Failed to load members.</p>";
        return;
      }

      // 🧠 Sort by role priority then alphabetically
      const rolePriority = {
        owner: 0,
        admin: 1,
        editor: 2,
        commenter: 3,
        viewer: 4,
        paused: 5,
        request: 6
      };

      data.sort((a, b) => {
        const pA = rolePriority[a.role] ?? 99;
        const pB = rolePriority[b.role] ?? 99;
        if (pA !== pB) return pA - pB;
        return (a.display_name || a.email || "").localeCompare(b.display_name || b.email || "");
      });

      // ✅ Render HTML
      const html = data.map(member => {
        const username = member.username || member.email || "unknown";
        const display = member.display_name || username;
        const avatar = twResolveAvatarUrl(member, display);
        const safeDisplay = typeof escapeHTML === "function" ? escapeHTML(display) : display;
        const safeUsername = typeof escapeHTML === "function" ? escapeHTML(username) : username;

        let badge = "👥";
        switch (member.role) {
          case "owner": badge = "👑"; break;
          case "admin": badge = "🛡"; break;
          case "editor": badge = "✏️"; break;
          case "commenter": badge = "💬"; break;
          case "viewer": badge = "👁"; break;
          case "paused": badge = "⏸"; break;
          case "request": badge = "❓"; break;
        }

        const safeMemberId = Number(member.member_id || 0);

        return `
          <a href="#" class="user-item friends-user-item" data-user="${safeUsername}" data-member-id="${safeMemberId}" data-display-name="${safeDisplay}">
            <img src="${avatar}" class="user-avatar" alt="${safeDisplay}" data-avatar-seed="${safeUsername}" data-avatar-name="${safeDisplay}" onerror="twHandleAvatarError(this)" />
            <span>${badge} ${safeDisplay} [${safeUsername}]</span>
          </a>`;
      }).join("");

      container.innerHTML = html;

      // 🔗 Bind user click events
      container.querySelectorAll(".user-item").forEach(item => {
        item.addEventListener("click", e => {
          e.preventDefault();
          e.stopPropagation();
          openFriendActionMenu(item);
        });
      });
    })
    .catch(err => {
      console.error(`❌ Error loading members for ${token}`, err);
      container.innerHTML = "<p class='text-danger'>Could not load members.</p>";
    });
}



function toggleListMembers(header) {
    // console.log("toggleListMembers",header);  
    const content = header?.nextElementSibling;
    const arrow = header.querySelector(".arrow");
    const token = header.dataset.token;
    
// console.log("🔍 Toggling this header:", header);
// console.log("📦 Found content sibling:", header.nextElementSibling);
    

    if (!content) {
        console.warn("⚠️ toggleListMembers: content not found for token", token);
        return;
    }

    const isExpanded = content.classList.contains("friends-content-visible");

    console.log(`📂 Toggling list: ${token}`, { isExpanded });

    // content.classList.toggle("friends-content-visible", !isExpanded);
    // content.classList.toggle("friends-content-hidden", isExpanded);
if (isExpanded) {
  content.classList.remove("friends-content-visible");
  content.classList.add("friends-content-hidden");
} else {
  content.classList.remove("friends-content-hidden");
  content.classList.add("friends-content-visible");
}

    if (arrow) arrow.textContent = isExpanded ? "▶" : "▼";

    if (!isExpanded && !content.dataset.loaded) {
        loadChatListMembers(token, content)
            .then(() => {
                content.dataset.loaded = "true";
            })
            .catch(err => {
                console.error("❌ Failed to load members", err);
                content.innerHTML = `<p class="text-danger">Failed to load members</p>`;
            });
    }
}



function toggleUserGroup(header) {
  // Prevent accidental nested toggle
  if (header.closest(".friends-list-subgroup")) {
    return; // 🛑 Don't toggle top group from nested click
  }

  const arrow = header.querySelector(".arrow");
  const content = header.nextElementSibling;

  if (!arrow || !content) return;

  const isVisible = content.classList.contains("friends-content-visible");
  content.classList.toggle("friends-content-visible", !isVisible);
  content.classList.toggle("friends-content-hidden", isVisible);
  arrow.textContent = isVisible ? "▶" : "▼";
}



function addItemToList(token, surrogate, event) {
    const sid = parseInt(surrogate, 10) || 0;
    if (sid <= 0) {
        console.warn("❌ addItemToList blocked invalid surrogate:", surrogate);
        return;
    }
    fetch("/addItemToList.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: `token=${encodeURIComponent(token)}&surrogate=${encodeURIComponent(sid)}`,
        credentials: "include" 
    })
    .then(res => res.json())
    .then(data => {
        if (data.status !== "OK") {
            console.warn("❌ Add failed:", data.error || "Unknown error");
            return;
        }

        const item = document.querySelector(`.list-sub-item[data-value='${surrogate}']`);
        if (item) {
            item.classList.add("flash");
            setTimeout(() => item.classList.remove("flash"), 500);
        }

        updateLastUsedLabels();

        const container = document.getElementById(`list-${token}`);
        const header = document.querySelector(`.group-item[data-group='${token}']`);

        // ✅ If container is visible (expanded), reload its content
        if (container && container.style.display === "block") {
            fetch(`/getListItems.php?list=${token}`)
                .then(res => res.text())
                .then(html => {
                    container.innerHTML = html;
                });
        }

        // ✅ Update count safely using .list-count span
        if (header) {
            const countSpan = header.querySelector(".list-count");
            if (countSpan) {
                const current = parseInt(countSpan.textContent.replace(/\D/g, ""), 10);
                countSpan.textContent = `(${current + 1})`;
            }
        }

        // ✅ Close dropdowns and menu
        setTimeout(() => {
            document.querySelectorAll(".inline-list-selector").forEach(el => el.remove());
            const menu = document.querySelector(`.list-sub-item[data-value="${surrogate}"] .item-menu-dropdown`);
            if (menu) menu.style.display = "none";
        }, 50);
    });
}


function addItemToLastUsedList(surrogate) {
    if (!lastUsedListToken || !lastUsedListName) {
        alert("❌ No previous list selected.");
        return;
    }

    addItemToList(lastUsedListToken, surrogate, event);

    // ✅ Close open menu (if visible)
    document.querySelectorAll(".item-menu-dropdown").forEach(menu => {
        menu.style.display = "none";
    });
}


function updateLastUsedLabels() {
    document.querySelectorAll(".last-used-entry").forEach(el => {
        if (lastUsedListName) {
            el.style.display = "block";
            const span = el.querySelector(".last-used-name");
            if (span) span.textContent = lastUsedListName;
        } else {
            el.style.display = "none";
        }
    });
}


// Simple in-memory cache for dropdown list fetches keyed by owner username.
window._addToListCache = window._addToListCache || { myByOwner: {} };

function getAddToListOwnerUsername() {
  return (window.currentProfileUsername || window.SESSION_USERNAME || "").trim();
}

function getUserListsUrlForOwner(ownerUsername = "", topLevelOnly = false) {
  const owner = String(ownerUsername || "").trim();
  const params = new URLSearchParams();
  if (owner) params.set("owner", owner);
  if (topLevelOnly) params.set("topLevel", "1");
  const qp = params.toString();
  return `/getUserLists.php${qp ? `?${qp}` : ""}`;
}

function getSelectedProfileLabel() {
  const labelEl = document.getElementById("homeCurrentProfileLabel");
  const label = String(labelEl?.textContent || "").trim();
  return label || getAddToListOwnerUsername();
}

async function addItemToListPrompt(surrogate, button) {
  // Close any other open inline pickers first
  document.querySelectorAll(".inline-list-selector").forEach(el => el.remove());

  const selectedOwner = getAddToListOwnerUsername();
  const sessionOwner = String(window.SESSION_USERNAME || "").trim();
  const selectedLabel = getSelectedProfileLabel();

  async function loadListsForOwner(ownerUsername) {
    const owner = String(ownerUsername || "").trim();
    let lists = window._addToListCache.myByOwner[owner];
    if (!lists) {
      const res = await fetch(getUserListsUrlForOwner(owner, true)).then(r => r.json());
      lists = Array.isArray(res?.lists) ? res.lists : (Array.isArray(res) ? res : []);
      if (lists.some(l => typeof l.id === "number")) {
        lists.sort((a, b) => (Number(b.id) || 0) - (Number(a.id) || 0));
      }
      window._addToListCache.myByOwner[owner] = lists;
    }
    return lists;
  }

  const dropdown = document.createElement("div");
  dropdown.classList.add("inline-list-selector");
  dropdown.style.position   = "absolute";
  dropdown.style.top        = `${button.offsetTop + button.offsetHeight}px`;
  dropdown.style.left       = `${button.offsetLeft}px`;
  dropdown.style.maxHeight  = "280px";
  dropdown.style.overflowY  = "auto";
  dropdown.style.minWidth   = "260px";
  dropdown.style.zIndex     = 1000;

  // Helper to render a single list row
  function renderChoice(list) {
    const choice = document.createElement("div");
    choice.className = "list-choice list-tree-child";
    // If list has owner_username, show it in brackets (other users' lists)
    let label = list.name || list.title || "(untitled)";
    if (list.owner_username) label += ` [${list.owner_username}]`;
    choice.innerHTML = `<span class="tree-leaf-marker">└</span><span class="tree-leaf-label">➕ ${escapeHtml(label)}</span>`;
    choice.addEventListener("click", (e) => {
      e.stopPropagation();
      addItemToList(list.token, surrogate, e);
    });
    return choice;
  }

  // Collapsible section builder (lazy fetch)
  function makeSection({ title, startOpen, fetcher, onPrefill, includeNewButton, newListOwner }) {
    const section = document.createElement("div");
    section.className = "list-tree-section";

    const header = document.createElement("div");
    header.className = "list-section-header list-tree-parent";
    header.style.cursor = "pointer";
    header.innerHTML = `<span class="arrow">${startOpen ? "▼" : "▶"}</span> ${title}`;
    section.appendChild(header);

    const body = document.createElement("div");
    body.className = "list-section-body";
    body.style.display = startOpen ? "block" : "none";
    const children = document.createElement("div");
    children.className = "list-tree-children";
    body.appendChild(children);
    section.appendChild(body);

    const arrowEl = header.querySelector(".arrow");

    // If prefill requested (e.g., "My Lists"), load immediately
    if (startOpen && onPrefill) {
      onPrefill(children);
    }

    let loading = false;
    header.addEventListener("click", async (e) => {
      e.stopPropagation();
      const isOpen = body.style.display === "block";
      body.style.display = isOpen ? "none" : "block";
      arrowEl.textContent = isOpen ? "▶" : "▼";

      if (!isOpen && !body.dataset.loaded && !loading) {
        loading = true;
        children.innerHTML = `<div class="list-choice muted list-tree-child">Loading…</div>`;
        try {
          const lists = await fetcher();
          children.innerHTML = "";
          if (lists && lists.length) {
            lists.forEach(l => children.appendChild(renderChoice(l)));
          } else {
            children.innerHTML = `<div class="list-choice muted list-tree-child">(none)</div>`;
          }
          if (includeNewButton) {
            const newBtn = document.createElement("div");
            newBtn.className = "list-choice list-tree-child";
            newBtn.innerHTML = `<span class="tree-leaf-marker">└</span><span class="tree-leaf-label">🆕 New list…</span>`;
            newBtn.addEventListener("click", (ev) => {
              ev.stopPropagation();
              showNewListInput(newBtn, surrogate, newListOwner);
            });
            children.appendChild(newBtn);
          }
          body.dataset.loaded = "1";
        } catch (err) {
          console.warn("Section load failed:", err);
          children.innerHTML = `<div class="list-choice text-danger list-tree-child">Failed to load</div>`;
        } finally {
          loading = false;
        }
      }
    });

    return section;
  }

  if (window.lastUsedListToken && window.lastUsedListName) {
    const quickSection = makeSection({
      title: "🕘 Last used",
      startOpen: false,
      fetcher: async () => [{
        token: window.lastUsedListToken,
        name: window.lastUsedListName
      }]
    });
    dropdown.appendChild(quickSection);
  }

  const selectedSection = makeSection({
    title: `📂 Lists of ${selectedLabel}`,
    startOpen: false,
    includeNewButton: true,
    newListOwner: selectedOwner,
    fetcher: async () => {
      try {
        return await loadListsForOwner(selectedOwner);
      } catch (err) {
        console.warn("getUserLists failed:", err);
        throw err;
      }
    }
  });
  dropdown.appendChild(selectedSection);

  if (sessionOwner && sessionOwner !== selectedOwner) {
    const myListsSection = makeSection({
      title: "📁 My lists",
      startOpen: false,
      includeNewButton: true,
      newListOwner: sessionOwner,
      fetcher: async () => {
        return await loadListsForOwner(sessionOwner);
      }
    });
    dropdown.appendChild(myListsSection);
  }

  // Spacer so the last item isn't jammed against the edge
  const spacer = document.createElement("div");
  spacer.style.height = "10px";
  dropdown.appendChild(spacer);

  // Mount next to button
  button.parentElement.appendChild(dropdown);

  // Close when clicking outside (you already have a global listener, but this is extra-safe)
  setTimeout(() => {
    const off = (e) => {
      if (!dropdown.contains(e.target)) {
        dropdown.remove();
        document.removeEventListener("click", off);
      }
    };
    document.addEventListener("click", off);
  }, 0);
}


function removeItemFromList(token, surrogate, event) {
  fetch("/removeItemFromList.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: `token=${encodeURIComponent(token)}&surrogate=${encodeURIComponent(surrogate)}`
  })
  .then(res => res.json())
  .then(data => {
    if (data.status === "OK") {
      // ✅ Remove only from the current list container
      const listContainer = document.querySelector(`.list-contents[data-token='${token}']`);
      if (listContainer) {
        const item = listContainer.querySelector(`.list-sub-item[data-value='${surrogate}']`);
        if (item) item.remove();
      }

      // ✅ Close the dropdown
      const openMenu = event.target.closest(".item-menu-dropdown");
      if (openMenu) openMenu.style.display = "none";
    } else {
      alert(`❌ ${data.message || data.error || "Failed to remove item."}`);

    }
  })
  .catch(err => {
    console.error("removeItemFromList failed:", err);
    alert("⚠️ Network error while removing item.");
  });
}

function deleteItemFromMenu(surrogate, event) {
  if (!surrogate) return;
  if (!confirm("Delete this item from all lists?")) return;

  const row = document.querySelector(`.list-sub-item[data-value='${surrogate}']`);
  const rawTitle = row?.querySelector(".item-title")?.textContent || "";
  const dataname = rawTitle.replace(/^\s*•\s*/, "").trim() || `surrogate-${surrogate}`;

  fetch("/datadelete.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: `dataname=${encodeURIComponent(dataname)}&surrogate=${encodeURIComponent(surrogate)}`
  })
    .then(res => res.json().catch(() => ({})))
    .then(data => {
      if (data.status === "OK") {
        removeCurrentFromList(surrogate);
        showFlashMessage?.("🗑️ Item deleted");
      } else {
        alert(`❌ ${data.message || data.error || "Failed to delete item."}`);
      }
    })
    .catch(err => {
      console.error("deleteItemFromMenu failed:", err);
      alert("⚠️ Network error while deleting item.");
    })
    .finally(() => {
      const openMenu = event?.target?.closest?.(".item-menu-dropdown");
      if (openMenu) openMenu.style.display = "none";
    });
}

function parseTapTrayItemText(rawText, fallbackTitle = "") {
  const lines = String(rawText || "")
    .split(/\r?\n/)
    .map(line => line.trim())
    .filter(Boolean);

  const title = fallbackTitle || lines[0] || "Menu item";
  const body = fallbackTitle ? lines : lines.slice(1);
  let image = "";
  let description = "";
  let detailedDescription = "";
  let allergens = "";
  let price = "";

  body.forEach((line) => {
    if (!image && /^https?:\/\/\S+\.(png|jpe?g|webp|gif)(\?\S*)?$/i.test(line)) {
      image = line;
      return;
    }
    if (!allergens && /^allergens?\s*:/i.test(line)) {
      allergens = line.replace(/^allergens?\s*:/i, "").trim();
      return;
    }
    if (!price) {
      const priceMatch = line.match(/(?:ISK|EUR|USD|GBP|kr|\$|€|£)\s?\d+(?:[.,]\d{2})?|\d+(?:[.,]\d{2})?\s?(?:ISK|EUR|USD|GBP|kr|€|£)/i);
      if (priceMatch) {
        price = priceMatch[0];
      }
    }
    if (!description) {
      description = line;
      return;
    }
    detailedDescription += (detailedDescription ? "\n" : "") + line;
  });

  return { title, image, description, detailedDescription, allergens, price };
}

function buildTapTrayExpandedItemMarkup(parsed) {
  const media = parsed.image
    ? `<img src="${escapeHtml(parsed.image)}" alt="${escapeHtml(parsed.title)}" loading="lazy">`
    : `<div class="taptray-tree-item-placeholder">Food image</div>`;

  const title = parsed.title
    ? `<div class="taptray-tree-item-title">${escapeHtml(parsed.title)}</div>`
    : "";
  const allergens = parsed.allergens
    ? `<div class="taptray-tree-item-meta"><strong>Allergens:</strong> ${escapeHtml(parsed.allergens)}</div>`
    : "";
  const price = parsed.price
    ? `<div class="taptray-tree-item-price">${escapeHtml(parsed.price)}</div>`
    : "";
  const expandedDescription = String(parsed.detailedDescription || parsed.description || "").trim();
  const description = expandedDescription
    ? `<div class="taptray-tree-item-description">${escapeHtml(expandedDescription)}</div>`
    : `<div class="taptray-tree-item-description is-empty">Add a customer-facing description for this item.</div>`;

  return `
    <div class="taptray-tree-item-body">
      <div class="taptray-tree-item-media">${media}</div>
      <div class="taptray-tree-item-copy">
        ${title}
        ${price}
        ${description}
        ${allergens}
      </div>
    </div>
  `;
}

function mergeTapTrayRowSettings(row, parsed) {
  if (!row) return parsed;
  const next = { ...parsed };
  const image = String(row.dataset.imageUrl || "").trim();
  const description = String(row.dataset.shortDescription || row.dataset.publicDescription || "").trim();
  const detailedDescription = String(row.dataset.detailedDescription || "").trim();
  const price = String(row.dataset.priceLabel || "").trim();
  const allergens = String(row.dataset.allergens || "").trim();
  if (image) next.image = image;
  if (description) next.description = description;
  if (detailedDescription) next.detailedDescription = detailedDescription;
  if (price) next.price = price;
  if (allergens) next.allergens = allergens;
  return next;
}

function getTapTrayCart() {
  if (!window.taptrayCart || typeof window.taptrayCart !== "object") {
    window.taptrayCart = {};
  }
  return window.taptrayCart;
}

function persistTapTrayCart() {
  return getTapTrayCart();
}

function tapTrayCartFromDraftOrder(draftOrder) {
  const next = {};
  const items = Array.isArray(draftOrder?.items) ? draftOrder.items : [];
  items.forEach((item) => {
    const key = String(item?.surrogate || "").trim();
    if (!key) return;
    next[key] = {
      ...item,
      surrogate: key,
      quantity: Number(item?.quantity || 0),
    };
  });
  return next;
}

function tapTrayCartFromDraftOrders(draftOrders) {
  const next = {};
  const rows = Array.isArray(draftOrders) ? draftOrders : [];
  rows.forEach((draftOrder) => {
    const draftCart = tapTrayCartFromDraftOrder(draftOrder);
    Object.keys(draftCart).forEach((key) => {
      next[key] = draftCart[key];
    });
  });
  return next;
}

function applyTapTrayDraftOrder(draftOrder) {
  window.taptrayDraftOrder = draftOrder && typeof draftOrder === "object" ? draftOrder : null;
  window.taptrayDraftOrders = window.taptrayDraftOrder ? [window.taptrayDraftOrder] : [];
  window.taptrayCart = tapTrayCartFromDraftOrders(window.taptrayDraftOrders);
  return window.taptrayCart;
}

function applyTapTrayDraftOrders(draftOrders) {
  window.taptrayDraftOrders = Array.isArray(draftOrders) ? draftOrders.filter((entry) => entry && typeof entry === "object") : [];
  window.taptrayDraftOrder = window.taptrayDraftOrders[0] || null;
  window.taptrayCart = tapTrayCartFromDraftOrders(window.taptrayDraftOrders);
  return window.taptrayCart;
}

function getTapTrayDraftOrders() {
  return Array.isArray(window.taptrayDraftOrders) ? window.taptrayDraftOrders : [];
}

async function mutateTapTrayDraftOrder(action, item) {
  const response = await fetch("/taptray_cart_update.php", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "Accept": "application/json"
    },
    credentials: "same-origin",
    body: JSON.stringify({ action, item })
  });
  const data = await response.json().catch(() => null);
  if (!response.ok || !data || !data.ok) {
    throw new Error(data && data.error ? data.error : "TapTray could not update the order.");
  }
  if (Array.isArray(data.draft_orders)) {
    applyTapTrayDraftOrders(data.draft_orders);
  } else {
    applyTapTrayDraftOrder(data.draft_order || null);
  }
  document.dispatchEvent(new CustomEvent("taptray:cart-updated", { detail: { cart: getTapTrayCart() } }));
  return data.draft_order || null;
}

function parseTapTrayPriceValue(label = "") {
  const raw = String(label || "").trim();
  if (!raw) return 0;
  const match = raw.match(/(\d+(?:[.,]\d{1,2})?)/);
  if (!match) return 0;
  const normalized = match[1].replace(",", ".");
  const value = Number.parseFloat(normalized);
  return Number.isFinite(value) ? value : 0;
}

function getTapTrayActiveOrders() {
  const orders = Array.isArray(window.taptrayActiveOrders) ? window.taptrayActiveOrders : [];
  return orders.filter((order) => {
    const status = String(order?.status || "").trim();
    return status === "queued" || status === "in_process" || status === "making" || status === "ready";
  });
}

function getTapTrayPastOrders() {
  return Array.isArray(window.taptrayPastOrders) ? window.taptrayPastOrders : [];
}

function getTapTrayActiveOrder() {
  return getTapTrayActiveOrders()[0] || null;
}

function getTapTrayOwnerKey(source) {
  const ownerId = Number(source?.owner_id || 0);
  if (ownerId > 0) return `o:${ownerId}`;
  const username = String(source?.owner_username || "").trim();
  if (username) return `u:${username.toLowerCase()}`;
  const name = String(source?.owner_display_name || "").trim();
  return name ? `n:${name.toLowerCase()}` : "n:taptray";
}

function getTapTrayOwnerLabel(source) {
  return String(source?.owner_display_name || source?.owner_username || "TapTray").trim() || "TapTray";
}

function triggerTapTrayReadyAlert() {
  const bar = document.getElementById("taptrayOrderBar");
  const sidebar = document.getElementById("sidebarContainer");
  if (!bar) return;
  bar.classList.remove("is-ready-alert");
  sidebar?.classList.remove("taptray-sidebar-ready-alert");
  void bar.offsetWidth;
  bar.classList.add("is-ready-alert");
  sidebar?.classList.add("taptray-sidebar-ready-alert");
  window.clearTimeout(window.tapTrayReadyAlertTimer);
  window.tapTrayReadyAlertTimer = window.setTimeout(() => {
    bar.classList.remove("is-ready-alert");
    sidebar?.classList.remove("taptray-sidebar-ready-alert");
  }, 1600);
}

function getTapTrayActiveOrderItem(surrogate) {
  const key = String(surrogate);
  for (const order of getTapTrayActiveOrders()) {
    if (!Array.isArray(order.items)) continue;
    const found = order.items.find((item) => String(item?.surrogate || "") === key);
    if (found) return found;
  }
  return null;
}

function getTapTrayLockedLabel(status) {
  const value = String(status || "").trim();
  if (value === "ready") return "Ready";
  if (value === "queued") return "Queued";
  if (value === "closed") return "Closed";
  return "Making";
}

function getTapTrayStatusClass(status) {
  const value = String(status || "").trim();
  if (value === "queued") return "is-queued";
  if (value === "ready") return "is-ready";
  if (value === "closed") return "is-closed";
  return "is-making";
}

function clearTapTrayCartForReturnedOrderIfMatched() {
  const params = new URLSearchParams(window.location.search || "");
  const targetOrderRef = String(params.get("taptray_order") || "").trim();
  if (!targetOrderRef) return;
  window.taptrayCart = {};
}

function getTapTrayOrderShortNumber(order) {
  return `#${Number(order?.id || 0)}`;
}

function getTapTrayOrderDisplayName(order) {
  const explicitName = String(order?.order_name || "").trim();
  if (explicitName) return explicitName;
  const items = Array.isArray(order?.items) ? order.items : [];
  if (!items.length) return "Order";
  const firstTitle = String(items[0]?.title || "Order").trim() || "Order";
  const extra = Math.max(0, items.length - 1);
  return extra > 0 ? `${firstTitle} +${extra}` : firstTitle;
}

function renderTapTrayOrderRow(item, options = {}) {
  const qty = Number(item?.quantity || 0);
  const price = String(item?.price_label || "").trim();
  const description = String(item?.short_description || item?.public_description || "").trim();
  const imageUrl = String(item?.image_url || "").trim();
  const surrogate = String(item?.surrogate || "").trim();
  const shortDescription = description.length > 72
    ? `${description.slice(0, 69).trimEnd()}...`
    : description;
  const mediaHtml = imageUrl
    ? `<div class="item-thumb"><img src="${escapeHtml(imageUrl)}" alt="${escapeHtml(String(item?.title || "Menu item"))}"></div>`
    : `<div class="item-thumb is-placeholder"><span>IMG</span></div>`;
  const priceHtml = price
    ? `<div class="item-price-chip">${escapeHtml(price)}</div>`
    : `<div class="item-price-chip is-placeholder">Set price</div>`;
  const actionLabel = String(options.actionLabel || "").trim();
  const statusClass = getTapTrayStatusClass(options.status || actionLabel);
  const orderMeta = String(options.orderMeta || "").trim();
  const actionHtml = options.locked
    ? `<div class="taptray-status-badge ${statusClass}" aria-label="${escapeHtml(actionLabel || "Making")}">${escapeHtml(actionLabel || "Making")}</div>`
    : `<button class="item-square-action" onclick="taptrayReduceItem(this, ${Number(surrogate || 0)}); event.stopPropagation();">${escapeHtml(actionLabel || "Cancel")}</button>`;

  return `
    <div class="list-sub-item taptray-order-list-item" data-value="${escapeHtml(surrogate)}">
      <div class="taptray-menu-row" style="flex:1;">
        <div class="item-media-rail">
          <div class="item-square-main">
            ${mediaHtml}
            <div class="item-qty-badge">${qty}</div>
          </div>
        </div>
        <div class="taptray-menu-copy">
          <div class="item-head">
            <div class="item-title">${escapeHtml(String(item?.title || "Menu item"))}</div>
          </div>
          <div class="item-summary${shortDescription ? "" : " is-placeholder"}">${escapeHtml(shortDescription || "Ordered item")}</div>
        </div>
        <div class="item-action-square item-action-square-static">
          ${priceHtml}
          <div class="item-square-actions">
            ${actionHtml}
          </div>
        </div>
      </div>
    </div>
  `;
}

function renderTapTrayOrderGroup(group, options = {}) {
  const label = String(group?.label || "TapTray").trim() || "TapTray";
  const subtotal = Number(group?.subtotal || 0);
  const subtotalText = subtotal > 0 ? `${Math.round(subtotal)}` : "";
  const rows = Array.isArray(group?.rows) ? group.rows.join("") : "";
  const payButton = options.showPay
    ? `<button class="taptray-group-pay-btn" type="button" data-order-reference="${escapeHtml(String(group?.orderReference || "").trim())}">Pay this shop${subtotalText ? ` ${escapeHtml(subtotalText)}` : ""}</button>`
    : "";
  const metaText = String(group?.meta || "").trim();

  return `
    <section class="taptray-order-group">
      <div class="taptray-order-group-head">
        <div class="taptray-order-group-copy">
          <div class="taptray-order-group-title">${escapeHtml(label)}</div>
          ${metaText ? `<div class="taptray-order-group-meta">${escapeHtml(metaText)}</div>` : ""}
        </div>
        ${payButton}
      </div>
      <div class="taptray-order-group-items">
        ${rows}
      </div>
    </section>
  `;
}

function updateTapTrayOrderBar() {
  const bar = document.getElementById("taptrayOrderBar");
  const title = document.getElementById("taptrayOrderTitle");
  const meta = document.getElementById("taptrayOrderMeta");
  const payBtn = document.getElementById("taptrayPayBtn");
  const itemsHost = document.getElementById("taptrayOrderItems");
  const toggleBtn = document.getElementById("taptrayOrderToggle");
  const chevron = document.getElementById("taptrayOrderChevron");
  if (!bar || !title || !meta || !payBtn || !itemsHost || !toggleBtn || !chevron) return;

  const activeOrders = getTapTrayActiveOrders();
  const draftOrders = getTapTrayDraftOrders();
  const pastOrders = getTapTrayPastOrders();
  const cart = getTapTrayCart();
  const cartEntries = Object.values(cart);
  const activeEntries = activeOrders.flatMap((order) => Array.isArray(order.items) ? order.items.map((item) => ({
    ...item,
    _taptrayOrderStatus: order.status || "in_process",
    _taptrayOrderMeta: `${getTapTrayOrderShortNumber(order)} · ${getTapTrayOrderDisplayName(order)}`,
  })) : []);
  const totalQty = activeEntries.reduce((sum, item) => sum + Number(item?.quantity || 0), 0)
    + cartEntries.reduce((sum, item) => sum + Number(item?.quantity || 0), 0);
  const totalPrice = cartEntries.reduce((sum, item) => sum + (parseTapTrayPriceValue(item?.price_label) * Number(item?.quantity || 0)), 0);
  const uniqueMerchantCount = new Set([
    ...draftOrders.map((order) => getTapTrayOwnerKey(order)),
    ...activeOrders.map((order) => getTapTrayOwnerKey(order)),
  ]).size;
  const wasHidden = bar.hidden;
  if (bar.dataset.expanded !== "0" && bar.dataset.expanded !== "1") {
    bar.dataset.expanded = "1";
  }
  let isExpanded = bar.dataset.expanded === "1";

  if (totalQty <= 0 && pastOrders.length <= 0) {
    bar.hidden = true;
    bar.dataset.expanded = "0";
    title.textContent = "Order";
    meta.textContent = "No items selected";
    payBtn.disabled = true;
    itemsHost.hidden = true;
    itemsHost.innerHTML = "";
    toggleBtn.setAttribute("aria-expanded", "false");
    chevron.textContent = "▸";
    persistTapTrayCart();
    return;
  }

  bar.hidden = false;
  if (wasHidden) {
    bar.dataset.expanded = "1";
    isExpanded = true;
  }
  const priceText = totalPrice > 0 ? ` · ${Math.round(totalPrice)}` : "";
  if (activeOrders.length === 1) {
    title.textContent = "Order";
    meta.textContent = `${getTapTrayOrderShortNumber(activeOrders[0])} · ${getTapTrayOrderDisplayName(activeOrders[0])}`;
  } else {
    title.textContent = "Order";
    const groupText = uniqueMerchantCount > 1 ? `${uniqueMerchantCount} shops · ` : "";
    const statusText = activeEntries.length ? `${activeOrders.length} active order${activeOrders.length === 1 ? "" : "s"} · ` : "";
    meta.textContent = `${groupText}${statusText}${totalQty} item${totalQty === 1 ? "" : "s"}${priceText}`;
  }
  const singleDraftOrder = draftOrders.length === 1 ? draftOrders[0] : null;
  payBtn.disabled = !singleDraftOrder;
  payBtn.hidden = !singleDraftOrder;
  payBtn.dataset.orderReference = singleDraftOrder ? String(singleDraftOrder.order_reference || "") : "";
  payBtn.textContent = "Pay";
  toggleBtn.setAttribute("aria-expanded", isExpanded ? "true" : "false");
  chevron.textContent = isExpanded ? "▾" : "▸";
  itemsHost.hidden = !isExpanded;
  persistTapTrayCart();
  const pastMarkup = pastOrders.length ? `
    <details class="taptray-order-history">
      <summary class="taptray-order-history-title">Past orders (${pastOrders.length})</summary>
      ${pastOrders.map((order) => {
        const pastItems = Array.isArray(order.items) ? order.items : [];
        return `
          <div class="taptray-order-history-group">
            <div class="taptray-order-history-meta">${escapeHtml(`${getTapTrayOrderShortNumber(order)} · ${getTapTrayOrderDisplayName(order)}`)}</div>
            ${pastItems.map((item) => renderTapTrayOrderRow(item, {
              locked: true,
              actionLabel: "Closed",
              status: "closed",
              orderMeta: `${getTapTrayOrderShortNumber(order)} · ${getTapTrayOrderDisplayName(order)}`,
            })).join("")}
          </div>
        `;
      }).join("")}
    </details>
  ` : "";
  const draftMarkup = draftOrders.map((draftOrder) => {
    const draftItems = Array.isArray(draftOrder?.items) ? draftOrder.items : [];
    const subtotal = draftItems.reduce((sum, item) => sum + (parseTapTrayPriceValue(item?.price_label) * Number(item?.quantity || 0)), 0);
    return renderTapTrayOrderGroup({
      label: getTapTrayOwnerLabel(draftOrder),
      orderReference: String(draftOrder?.order_reference || "").trim(),
      subtotal,
      meta: `${draftItems.reduce((sum, item) => sum + Number(item?.quantity || 0), 0)} item${draftItems.reduce((sum, item) => sum + Number(item?.quantity || 0), 0) === 1 ? "" : "s"}`,
      rows: draftItems.map((item) => renderTapTrayOrderRow(item, {
        locked: false,
        actionLabel: "Cancel",
      })),
    }, { showPay: true });
  }).join("");
  const activeMarkup = activeOrders.map((order) => {
    const orderItems = Array.isArray(order?.items) ? order.items : [];
    return renderTapTrayOrderGroup({
      label: getTapTrayOwnerLabel(order),
      meta: `${getTapTrayOrderShortNumber(order)} · ${getTapTrayOrderDisplayName(order)}`,
      rows: orderItems.map((item) => renderTapTrayOrderRow({
        ...item,
        _taptrayOrderStatus: order.status || "in_process",
        _taptrayOrderMeta: `${getTapTrayOrderShortNumber(order)} · ${getTapTrayOrderDisplayName(order)}`,
      }, {
        locked: true,
        actionLabel: getTapTrayLockedLabel(order.status || "in_process"),
        status: order.status || "making",
        orderMeta: `${getTapTrayOrderShortNumber(order)} · ${getTapTrayOrderDisplayName(order)}`,
      })),
    }, { showPay: false });
  }).join("");
  itemsHost.innerHTML = [
    draftMarkup,
    activeMarkup,
    pastMarkup,
  ].join("");
}

function openTapTrayOrderBarFromDeepLink() {
  const params = new URLSearchParams(window.location.search || "");
  const targetOrderRef = String(params.get("taptray_order") || "").trim();
  if (!targetOrderRef) return;

  const bar = document.getElementById("taptrayOrderBar");
  if (!bar || bar.hidden) return;

  const hasMatchingActiveOrder = getTapTrayActiveOrders().some((order) =>
    String(order?.order_reference || "").trim() === targetOrderRef
  );
  const hasMatchingPastOrder = getTapTrayPastOrders().some((order) =>
    String(order?.order_reference || "").trim() === targetOrderRef
  );
  if (!hasMatchingActiveOrder && !hasMatchingPastOrder) return;

  bar.dataset.expanded = "1";
  updateTapTrayOrderBar();
  bar.scrollIntoView({ behavior: "smooth", block: "nearest" });

  const nextUrl = new URL(window.location.href);
  nextUrl.searchParams.delete("taptray_order");
  window.history.replaceState({}, "", nextUrl.pathname + nextUrl.search + nextUrl.hash);
}

function updateTapTraySelectionButtons(surrogate) {
  const cart = getTapTrayCart();
  const qty = Number(cart[String(surrogate)]?.quantity || 0);
  document.querySelectorAll(`.list-sub-item[data-value="${CSS.escape(String(surrogate))}"]:not(.taptray-order-list-item)`).forEach((row) => {
    row.dataset.selectedQty = String(qty);
    row.querySelectorAll(".taptray-order-btn").forEach((button) => {
      button.textContent = "Order";
      button.disabled = false;
      button.classList.toggle("is-selected", qty > 0);
    });
    const badge = row.querySelector(".item-qty-badge");
    if (badge) {
      badge.textContent = qty > 0 ? String(qty) : "";
      badge.hidden = qty <= 0;
    }
  });
}

window.taptraySelectItem = async function taptraySelectItem(button, surrogate, token) {
  const row = button?.closest(".list-sub-item") || document.querySelector(`.list-sub-item[data-value="${CSS.escape(String(surrogate))}"]`);
  const title = row?.querySelector(".item-title, .item-subject")?.textContent?.replace(/^•\s*/, "").trim() || "Menu item";
  const priceLabel = String(row?.dataset?.priceLabel || "").trim();
  const shortDescription = String(row?.dataset?.shortDescription || row?.dataset?.publicDescription || "").trim();
  const detailedDescription = String(row?.dataset?.detailedDescription || "").trim();
  const imageUrl = String(row?.dataset?.imageUrl || "").trim();
  const payload = {
    surrogate: Number(surrogate || 0),
    token: String(token || ""),
    owner_id: Number(row?.closest(".group-item")?.dataset?.owner || 0),
    owner_username: String(row?.closest(".group-item")?.dataset?.ownerUsername || window.currentOwner?.username || "").trim(),
    owner_display_name: String(row?.closest(".group-item")?.dataset?.ownerDisplayName || window.currentOwner?.display_name || window.currentOwner?.username || "").trim(),
    title,
    price_label: priceLabel,
    short_description: shortDescription,
    detailed_description: detailedDescription,
    image_url: imageUrl,
  };
  try {
    await mutateTapTrayDraftOrder("add", payload);
    updateTapTraySelectionButtons(String(surrogate));
    updateTapTrayOrderBar();
    showFlashMessage?.(`Added ${title}`);
  } catch (error) {
    showFlashMessage?.(error?.message || "Could not update order.");
  }
}

window.taptrayReduceItem = async function taptrayReduceItem(button, surrogate) {
  const key = String(surrogate);
  const current = getTapTrayCart()[key];
  if (!current) return;
  try {
    await mutateTapTrayDraftOrder("reduce", current);
    updateTapTraySelectionButtons(key);
    updateTapTrayOrderBar();
  } catch (error) {
    showFlashMessage?.(error?.message || "Could not update order.");
  }
}

window.taptrayRemoveItem = async function taptrayRemoveItem(button, surrogate) {
  const key = String(surrogate);
  const current = getTapTrayCart()[key];
  if (!current) return;
  try {
    await mutateTapTrayDraftOrder("remove", current);
    updateTapTraySelectionButtons(key);
    updateTapTrayOrderBar();
  } catch (error) {
    showFlashMessage?.(error?.message || "Could not update order.");
  }
}

document.addEventListener("DOMContentLoaded", () => {
  document.addEventListener("taptray:cart-updated", () => updateTapTrayOrderBar());
  document.addEventListener("taptray:active-order-updated", (event) => {
    const draftOrder = event?.detail?.draftOrder && typeof event.detail.draftOrder === "object" ? event.detail.draftOrder : null;
    const draftOrders = Array.isArray(event?.detail?.draftOrders) ? event.detail.draftOrders : [];
    const orders = Array.isArray(event?.detail?.orders) ? event.detail.orders : [];
    const pastOrders = Array.isArray(event?.detail?.pastOrders) ? event.detail.pastOrders : [];
    const order = event?.detail?.order && typeof event.detail.order === "object" ? event.detail.order : null;
    const hasSeenReadyStateBefore = window.tapTrayReadyRefs instanceof Set;
    const previousReadyRefs = hasSeenReadyStateBefore ? window.tapTrayReadyRefs : new Set();
    const nextReadyRefs = new Set(
      orders
        .filter((entry) => String(entry?.status || "").trim() === "ready")
        .map((entry) => String(entry?.order_reference || "").trim())
        .filter(Boolean)
    );
    const hasNewReadyOrder = [...nextReadyRefs].some((ref) => !previousReadyRefs.has(ref));
    window.tapTrayReadyRefs = nextReadyRefs;
    applyTapTrayDraftOrders(draftOrders.length ? draftOrders : (draftOrder ? [draftOrder] : []));
    window.taptrayActiveOrders = orders;
    window.taptrayPastOrders = pastOrders;
    window.taptrayActiveOrder = order;
    clearTapTrayCartForReturnedOrderIfMatched();
    updateTapTrayOrderBar();
    const surrogates = new Set(Object.keys(getTapTrayCart()));
    const activeItems = orders.flatMap((entry) => Array.isArray(entry?.items) ? entry.items : []);
    activeItems.forEach((item) => {
      const key = String(item?.surrogate || "").trim();
      if (key) surrogates.add(key);
    });
    surrogates.forEach((key) => updateTapTraySelectionButtons(key));
    if (hasSeenReadyStateBefore && hasNewReadyOrder) {
      triggerTapTrayReadyAlert();
    }
    openTapTrayOrderBarFromDeepLink();
  });
  updateTapTrayOrderBar();
  openTapTrayOrderBarFromDeepLink();
});

document.addEventListener("click", (event) => {
  const payBtn = event.target.closest("#taptrayPayBtn");
  if (payBtn) {
    const orderReference = String(payBtn.dataset.orderReference || "").trim();
    if (!orderReference) {
      return;
    }
    event.preventDefault();
    event.stopPropagation();
    persistTapTrayCart();
    window.location.href = `/checkout.php?order_reference=${encodeURIComponent(orderReference)}`;
    return;
  }

  const groupPayBtn = event.target.closest(".taptray-group-pay-btn");
  if (groupPayBtn) {
    const orderReference = String(groupPayBtn.dataset.orderReference || "").trim();
    if (!orderReference) return;
    event.preventDefault();
    event.stopPropagation();
    persistTapTrayCart();
    window.location.href = `/checkout.php?order_reference=${encodeURIComponent(orderReference)}`;
    return;
  }

  const toggle = event.target.closest("#taptrayOrderToggle, .taptray-order-copy, #taptrayOrderChevron");
  if (!toggle) {
    return;
  }

  const bar = document.getElementById("taptrayOrderBar");
  if (!bar || bar.hidden) {
    return;
  }

  bar.dataset.expanded = bar.dataset.expanded === "1" ? "0" : "1";
  updateTapTrayOrderBar();
});

window.toggleTreeItemExpand = async function toggleTreeItemExpand(trigger, surrogate, token) {
  const row = trigger?.closest(".list-sub-item") || document.querySelector(`.list-sub-item[data-value="${CSS.escape(String(surrogate))}"]`);
  if (!row) return;

  if (window.innerWidth > 900) {
    const container = document.getElementById(`list-${token}`) || null;
    selectItem(surrogate, token, container);
    return;
  }

  const existingSibling = row.nextElementSibling;
  const isOpen = row.classList.contains("expanded") && existingSibling?.classList?.contains("item-expand-row");

  document.querySelectorAll(".list-sub-item.expanded").forEach((item) => item.classList.remove("expanded"));
  document.querySelectorAll(".item-expand-row").forEach((panelRow) => panelRow.remove());

  if (isOpen) return;

  row.classList.add("expanded");
  const panelRow = document.createElement("div");
  panelRow.className = "item-expand-row";
  panelRow.innerHTML = `<div class="item-expand-panel"><div class="taptray-tree-item-loading">Loading item details...</div></div>`;
  row.insertAdjacentElement("afterend", panelRow);

  const title = row.querySelector(".item-title, .item-subject")?.textContent?.replace(/^•\s*/, "").trim() || "Menu item";
  const panel = panelRow.querySelector(".item-expand-panel");

  try {
    const res = await fetch(`/getText.php?q=${encodeURIComponent(String(surrogate))}`, {
      credentials: "include"
    });
    if (!res.ok) throw new Error(`Failed to load item ${surrogate}`);
    const rawText = await res.text();
    const parsed = mergeTapTrayRowSettings(row, parseTapTrayItemText(rawText, title));
    panel.innerHTML = buildTapTrayExpandedItemMarkup(parsed);
  } catch (error) {
    console.error("toggleTreeItemExpand failed:", error);
    panel.innerHTML = `<div class="taptray-tree-item-error">Could not load item details.</div>`;
  }
}


function getCreateListOwnerParam(ownerUsername = "") {
  const targetOwner = String(ownerUsername || window.currentProfileUsername || "").trim();
  if (targetOwner && targetOwner !== window.SESSION_USERNAME) {
    return `&owner=${encodeURIComponent(targetOwner)}`;
  }
  return "";
}

function showNewListInput(container, surrogate, ownerUsername = "") {
    // Remove the "New list..." label
    container.style.display = "none";

    const input = document.createElement("input");
    input.type = "text";
    input.placeholder = "New list name...";
    input.classList.add("new-list-input");
    input.style.width = "100%";
    input.style.marginTop = "4px";

    input.addEventListener("keydown", function (e) {
        if (e.key === "Enter") {
            const name = input.value.trim();
            if (!name) return;

            // Create the list
            fetch("/createContentList.php", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: `name=${encodeURIComponent(name)}${getCreateListOwnerParam(ownerUsername)}`
            })
            .then(res => res.text())
            .then(result => {
                if (result === "OK") {
                    const selectedOwner = String(ownerUsername || getAddToListOwnerUsername()).trim();
                    if (window._addToListCache?.myByOwner && selectedOwner in window._addToListCache.myByOwner) {
                        delete window._addToListCache.myByOwner[selectedOwner];
                    }
                    fetch(getUserListsUrlForOwner(selectedOwner, true))
                        .then(res => res.json())
                        .then(lists => {
                            const newList = lists.find(l => l.name === name);
                            if (newList) {
                                lastUsedListToken = newList.token;
                                lastUsedListName = newList.name;
                                addItemToList(newList.token, surrogate, e);
            
                                // ✅ Remove input + reveal label again
                                input.remove();
                                container.style.display = "";
            
                                // ✅ Reload sidebar and auto-expand new list
                                if (typeof loadUserContentLists === "function") {
                                    loadUserContentLists(newList.token, surrogate);
                                }
                            }
                        });
                } else {
                    alert("❌ Failed: " + result);
                }
            });

        }
    });

    container.parentElement.insertBefore(input, container.nextSibling);
    input.focus();
}



/**
 * loadUserContentLists(expandToken, highlightSurrogate)
 *
 * URL rules:
 * - /<username>/<surrogate?>         → show that user's profile
 * - /<listToken>/<surrogate?>        → expand that specific list (by token)
 * - /                                → if logged in, redirect to your profile;
 *                                      if not logged in, show the "welcome" profile
 *
 * Behavior:
 * - When a username is in the URL → always show that user’s profile, whether logged in or not.
 * - When a list token is in the URL:
 *   - Logged in → expand inside your own profile if the list belongs in your space
 *                 (owned, favorite, invited).
 *   - If the list is not in your space, or you are not logged in → open in the owner’s profile.
 *
 * Refresh:
 * - Keeps the current view consistent (same profile, same expanded list/item).
 */




function initListSorting() {
  const isTapTrayShell = document.body?.dataset?.appMode === "taptray";
  const isLoggedIn = document.body.classList.contains("logged-in");
  if (isTapTrayShell && !isLoggedIn) return;

  // 🟢 LIST-LEVEL SORT (move entire lists)
  document.querySelectorAll(".group-contents, .list-contents").forEach(wrapper => {
    if (wrapper.dataset.sortableBound) return;
    wrapper.dataset.sortableBound = "true";

    new Sortable(wrapper, {
      animation: 150,
      draggable: ".group-item",
      handle: ".list-header-row",
      delay: 200,
      delayOnTouchOnly: true,
      ghostClass: "dragging",
      group: "lists",
      fallbackOnBody: true,
      swapThreshold: 0.65,

      // 🔒 Disable all item Sortables while dragging a list
      onStart(evt) {
        window._disabledItemSortables = [];
        document.querySelectorAll(".list-items-wrapper").forEach(el => {
          const sortable = el._sortable; // internal Sortable reference
          if (sortable && !sortable.option("disabled")) {
            sortable.option("disabled", true);
            window._disabledItemSortables.push(sortable);
          }
        });
      },

      // 🔓 Re-enable after drop
      onEnd: async function (evt) {
        (window._disabledItemSortables || []).forEach(sortable =>
          sortable.option("disabled", false)
        );
        window._disabledItemSortables = [];

        const movedToken = evt.item.dataset.group;
        const dropContainer = evt.to;

        // robust section resolution across nested DOM
        let section =
          dropContainer?.dataset?.section ||
          dropContainer.closest("[data-section]")?.dataset?.section ||
          dropContainer.closest(".list-group-wrapper")?.dataset?.section ||
          (function () {
            const g = dropContainer.closest(".list-group-wrapper")?.dataset?.group || "";
            if (g.startsWith("owned-")) return "owned";
            if (g === "invited-lists") return "invited";
            if (g === "followed-lists") return "followed";
            const itm = evt.item;
            if (itm?.dataset?.section) return itm.dataset.section;
            if (itm?.classList.contains("invited-list")) return "invited";
            if (itm?.classList.contains("followed-list")) return "followed";
            return "owned";
          })();

        const parentGroupItem = dropContainer.closest(".group-item");
        const parentToken = parentGroupItem ? parentGroupItem.dataset.group : null;

        const order = [...dropContainer.querySelectorAll(":scope > .group-item")].map(
          el => el.dataset.group
        );

        console.log("📂 Section:", section, "Moved:", movedToken, "Parent:", parentToken, "Order:", order);

        try {
          const payload =
            section === "owned"
              ? { section, movedToken, parentToken, order }
              : { section, order };

          const res = await fetch("/updateContentListOrder.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(payload)
          });
          const result = await res.json();
          console.log("📬 Server responded:", result);
        } catch (err) {
          console.error("❌ Failed to update list order:", err);
        }
      }
    });
  });

  // 🟢 ITEM-LEVEL SORT (move items within lists)
  // document.querySelectorAll(".list-items-wrapper").forEach(container => {
  //   if (container.dataset.itemSortableBound) return;
  //   container.dataset.itemSortableBound = "true";

  //   const itemSortable = new Sortable(container, {
  //     animation: 150,
  //     draggable: ".list-sub-item",
  //     delay: 200,
  //     delayOnTouchOnly: true,
  //     ghostClass: "sortable-ghost",
  //     group: { name: "items", pull: "clone", put: true },

  //     onAdd: async function (evt) {
  //       const sourceToken = evt.from.closest(".list-contents")?.dataset.token;
  //       const targetToken = evt.to.closest(".list-contents")?.dataset.token;
  //       const surrogate = evt.item.dataset.value;

  //       console.log(`➕ Item ${surrogate} moved from ${sourceToken} → ${targetToken}`);

  //       // 1️⃣ Add item to target list in DB
  //       try {
  //         await fetch("/addItemToList.php", {
  //           method: "POST",
  //           headers: { "Content-Type": "application/x-www-form-urlencoded" },
  //           body: `token=${encodeURIComponent(targetToken)}&surrogate=${encodeURIComponent(surrogate)}`,
  //           credentials: "include" 
  //         });
  //       } catch (err) {
  //         console.error("❌ Failed to move item:", err);
  //       }

  //       // 2️⃣ Update order in target list
  //       const targetOrder = [...evt.to.querySelectorAll(".list-sub-item")].map((n, i) => ({
  //         surrogate: n.dataset.value,
  //         position: i + 1
  //       }));
  //       await fetch("/updateListOrder.php", {
  //         method: "POST",
  //         headers: { "Content-Type": "application/json" },
  //         body: JSON.stringify({ token: targetToken, order: targetOrder })
  //       });

  //       // 3️⃣ Update order in source list
  //       const sourceOrder = [...evt.from.querySelectorAll(".list-sub-item")].map((n, i) => ({
  //         surrogate: n.dataset.value,
  //         position: i + 1
  //       }));
  //       await fetch("/updateListOrder.php", {
  //         method: "POST",
  //         headers: { "Content-Type": "application/json" },
  //         body: JSON.stringify({ token: sourceToken, order: sourceOrder })
  //       });
  //     },

  //     onEnd: async function (evt) {
  //       // Same-list reorder only
  //       if (evt.from !== evt.to) return;

  //       const listToken = evt.to.closest(".list-contents")?.dataset.token;
  //       const order = [...evt.to.querySelectorAll(".list-sub-item")].map((item, index) => ({
  //         surrogate: item.dataset.value,
  //         position: index + 1
  //       }));

  //       try {
  //         await fetch("/updateListOrder.php", {
  //           method: "POST",
  //           headers: { "Content-Type": "application/json" },
  //           body: JSON.stringify({ token: listToken, order })
  //         });
  //       } catch (err) {
  //         console.error("❌ Failed to update item order:", err);
  //       }
  //     }
  //   });

  //   // 🧩 Save reference so we can temporarily disable it
  //   container._sortable = itemSortable;
  // });

  // 🟢 ITEM-LEVEL SORT (move items within lists)
  document.querySelectorAll(".list-items-wrapper").forEach(container => {
    if (container.dataset.itemSortableBound) return;
    container.dataset.itemSortableBound = "true";

    const itemSortable = new Sortable(container, {
      animation: 150,
      draggable: ".list-sub-item[data-value]",
      delay: 200,
      delayOnTouchOnly: true,
      ghostClass: "sortable-ghost",
      group: { name: "items", pull: "clone", put: true },

      onAdd: async function (evt) {
        const item        = evt.item;
        const surrogate   = item.dataset.value;
        const source      = item.dataset.source; // 🔑 present = external
        const sourceToken = evt.from.closest(".list-contents")?.dataset.token;
        const targetToken = evt.to.closest(".list-contents")?.dataset.token;

        if (!targetToken) return;

        /* ================= EXTERNAL SOURCE ================= */
        if (source) {
          if (getListOrderMode(targetToken) === "alpha") {
            setListOrderMode(targetToken, "number");
            updateListOrderControlsState(targetToken, "number");
            showFlashMessage?.("Switched to # order for manual drop position");
          }

          // For clone/external drops, Sortable's newDraggableIndex can be 0 even when dropped lower.
          // Prefer the concrete DOM insertion index.
          const droppedIndexRaw = Number.isInteger(evt.newIndex)
            ? evt.newIndex
            : evt.newDraggableIndex;
          const droppedIndex = Number.isFinite(droppedIndexRaw)
            ? Math.max(0, droppedIndexRaw)
            : null;
          const dropOrder = droppedIndex !== null ? droppedIndex + 1 : 0;

          const titleHint =
            item.dataset.title ||
            item.querySelector(".item-title, .item-subject, .file-label")?.textContent ||
            "";
          const ownerHint = item.dataset.owner || window.currentOwner?.username || "";
          const displayHint = item.dataset.displayName || window.currentOwner?.display_name || ownerHint;
          const fileserverHint = "cloudflare";

          // remove visual clone ONLY for external drops
          item.remove();

          try {
            let ensuredSurrogate = String(surrogate || "").trim();
            const listContainer = document.getElementById(`list-${targetToken}`);
            let itemsWrapper = evt.to;
            if (!itemsWrapper || !itemsWrapper.classList.contains("list-items-wrapper")) {
              itemsWrapper = listContainer?.querySelector(".list-items-wrapper") || null;
            }

            // If this item already exists in the target list, treat external drop as reorder.
            if (ensuredSurrogate && itemsWrapper) {
              const existingRow = itemsWrapper.querySelector(`.list-sub-item[data-value="${CSS.escape(ensuredSurrogate)}"]`);
              if (existingRow) {
                const siblings = Array.from(itemsWrapper.querySelectorAll(".list-sub-item[data-value]"))
                  .filter((row) => row !== existingRow);
                const anchor =
                  droppedIndex !== null && droppedIndex < siblings.length
                    ? siblings[droppedIndex]
                    : null;
                if (anchor) {
                  itemsWrapper.insertBefore(existingRow, anchor);
                } else {
                  itemsWrapper.appendChild(existingRow);
                }

                updateListItemOrderNumbers(itemsWrapper, true);
                const targetOrder = [...itemsWrapper.querySelectorAll(".list-sub-item[data-value]")]
                  .map((n, i) => ({ surrogate: Number(n.dataset.value), position: i + 1 }))
                  .filter((x) => Number.isInteger(x.surrogate) && x.surrogate > 0);
                if (targetOrder.length) {
                  await fetch("/updateListOrder.php", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ token: targetToken, order: targetOrder })
                  });
                }

                updateLastUsedLabels?.();
                initListSorting?.();
                applyListOrderMode?.(targetToken);
                return;
              }
            }

            const sourcePaneId = String(item.dataset.fmPaneId || "");
            const sourceProvider = String(item.dataset.fmProvider || "");
            const sourceName = String(item.dataset.fmName || titleHint || "Imported file");
            const sourceMime = String(item.dataset.fmMime || "");
            const sourceId = String(item.dataset.fmId || "");
            const sourcePath = String(item.dataset.fmPath || "");
            const sourceTwAudioKey = String(item.dataset.fmTwAudioKey || "");
            const sourceTwOwner = String(item.dataset.fmTwOwner || "");

            if (!ensuredSurrogate && source === "fm") {
              const lowerName = sourceName.toLowerCase();
              const looksAudio = /^audio\//.test(sourceMime) || /\.(mp3|wav|ogg|m4a|flac|aac|aif|aiff|webm|mid|midi)$/i.test(lowerName);
              const looksText = /^text\//.test(sourceMime) || /\.(txt|md|markdown|docx)$/i.test(lowerName) || sourceMime === "application/vnd.openxmlformats-officedocument.wordprocessingml.document";
              const titleBase = sourceName.replace(/\.[^.]+$/, "").trim() || "Imported item";

              const fmNode = {
                name: sourceName,
                mimeType: sourceMime || (looksAudio ? "audio/mpeg" : (looksText ? "text/plain" : "application/pdf")),
                _sourceProvider: sourceProvider || null
              };
              if (sourceId) fmNode.id = sourceId;
              if (sourcePath) fmNode.path = sourcePath;
              if (sourceTwAudioKey) fmNode._twAudioKey = sourceTwAudioKey;
              if (sourceTwOwner) fmNode._twOwner = sourceTwOwner;

              if (looksText && typeof importTextNodeSmart === "function") {
                const ownerUser = window.currentOwner?.username || "";
                const imported = await importTextNodeSmart(fmNode, targetToken, ownerUser, {
                  forceNew: true,
                  forceOrder: dropOrder,
                  sourcePaneId: sourcePaneId || window.activeDriveTreeId
                });
                ensuredSurrogate = String(imported?.surrogate || "").trim();
              } else {
                ensuredSurrogate = await createNewItemForPDF(
                  targetToken,
                  titleBase,
                  window.currentOwner?.username || "",
                  dropOrder
                );
                const blob = await downloadCurrentDriveFile(fmNode, sourcePaneId || window.activeDriveTreeId);
                if (!blob) throw new Error("Could not download source file.");
                if (looksAudio) {
                  await handleFileUpload(
                    new File([blob], sourceName, { type: blob.type || sourceMime || "audio/mpeg" }),
                    ensuredSurrogate,
                    "audio"
                  );
                } else {
                  await uploadPdfWithVerification(
                    new File([blob], sourceName, { type: "application/pdf" }),
                    ensuredSurrogate,
                    window.currentOwner?.username || ""
                  );
                }
              }
            } else if (ensuredSurrogate) {
              if ((parseInt(ensuredSurrogate, 10) || 0) <= 0) {
                throw new Error("Invalid surrogate from external drop.");
              }
              await fetch("/addItemToList.php", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: `token=${encodeURIComponent(targetToken)}&surrogate=${encodeURIComponent(ensuredSurrogate)}`,
                credentials: "include"
              });
            } else {
              throw new Error("Missing surrogate for external drop.");
            }

            // ✅ Optimistic visual row immediately, using the same sidebar renderer.
            if (listContainer && listContainer.style.display === "block") {
              let itemsWrapper = evt.to;
              if (!itemsWrapper || !itemsWrapper.classList.contains("list-items-wrapper")) {
                itemsWrapper = listContainer.querySelector(".list-items-wrapper");
              }
              if (!itemsWrapper) {
                itemsWrapper = document.createElement("div");
                itemsWrapper.className = "list-items-wrapper";
                listContainer.appendChild(itemsWrapper);
              }

              const exists = itemsWrapper.querySelector(`.list-sub-item[data-value="${CSS.escape(String(ensuredSurrogate))}"]`);
              if (!exists && typeof renderSingleListItemHTML === "function") {
                const rowHtml = renderSingleListItemHTML(
                  {
                    surrogate: ensuredSurrogate,
                    owner: ownerHint,
                    display_name: displayHint,
                    title: String(titleHint || "").replace(/^\s*[•📝📄]\s*/, "").trim() || `Item ${ensuredSurrogate}`,
                    fileserver: fileserverHint,
                    role_rank: 90
                  },
                  targetToken
                );
                const temp = document.createElement("div");
                temp.innerHTML = String(rowHtml || "").trim();
                const row = temp.firstElementChild;
                if (row) {
                  const existingRows = Array.from(itemsWrapper.querySelectorAll(".list-sub-item[data-value]"));
                  const maxIndex = existingRows.reduce((max, el) => {
                    const n = parseInt(el.dataset.orderIndex || "0", 10);
                    return Number.isFinite(n) && n > max ? n : max;
                  }, 0);
                  const nextIndex = maxIndex + 1;
                  row.dataset.orderIndex = String(nextIndex);

                  const ownerEl = row.querySelector(".item-owner");
                  if (ownerEl && !ownerEl.querySelector(".item-order")) {
                    const orderEl = document.createElement("span");
                    orderEl.className = "item-order";
                    orderEl.textContent = `${nextIndex}.`;
                    ownerEl.prepend(orderEl, document.createTextNode(" "));
                  }

                  const controls = document.querySelector(`.list-order-toggle[data-token="${CSS.escape(targetToken)}"]`);
                  if (controls) {
                    const currentCount = parseInt(controls.dataset.count || "0", 10) || 0;
                    controls.dataset.count = String(currentCount + 1);
                  }

                  row.classList.add("just-added");
                  const anchor =
                    droppedIndex !== null && droppedIndex < existingRows.length
                      ? existingRows[droppedIndex]
                      : null;
                  if (anchor) {
                    itemsWrapper.insertBefore(row, anchor);
                  } else {
                    itemsWrapper.appendChild(row);
                  }
                  updateListItemOrderNumbers(itemsWrapper, true);
                  setTimeout(() => row.classList.remove("just-added"), 3000);
                }
              }

              const targetOrder = [...itemsWrapper.querySelectorAll(".list-sub-item[data-value]")]
                .map((n, i) => ({ surrogate: Number(n.dataset.value), position: i + 1 }))
                .filter((x) => Number.isInteger(x.surrogate) && x.surrogate > 0);
              if (targetOrder.length) {
                await fetch("/updateListOrder.php", {
                  method: "POST",
                  headers: { "Content-Type": "application/json" },
                  body: JSON.stringify({ token: targetToken, order: targetOrder })
                });
              }

              // Keep this strictly single-item visual update; avoid full-list re-render side effects.
            }

            updateLastUsedLabels?.();
            initListSorting?.();
            applyListOrderMode?.(targetToken);
          } catch (err) {
            console.error("External drop failed:", err);
          }

          return; // ⛔ stop here — no reorder logic
        }

        /* ================= INTERNAL MOVE (TW ORIGINAL BEHAVIOR) ================= */

        console.log(`➕ Item ${surrogate} moved from ${sourceToken} → ${targetToken}`);

        // 1️⃣ Add item to target list
        try {
          if ((parseInt(surrogate, 10) || 0) <= 0) throw new Error("Invalid surrogate for move.");
          await fetch("/addItemToList.php", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: `token=${encodeURIComponent(targetToken)}&surrogate=${encodeURIComponent(surrogate)}`,
            credentials: "include"
          });
        } catch (err) {
          console.error("❌ Failed to move item:", err);
        }

        // 2️⃣ Update target list order
        const targetOrder = [...evt.to.querySelectorAll(".list-sub-item[data-value]")]
          .map((n, i) => ({ surrogate: Number(n.dataset.value), position: i + 1 }))
          .filter((x) => Number.isInteger(x.surrogate) && x.surrogate > 0);
        await fetch("/updateListOrder.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ token: targetToken, order: targetOrder })
        });

        // 3️⃣ Update source list order
        const sourceOrder = [...evt.from.querySelectorAll(".list-sub-item[data-value]")]
          .map((n, i) => ({ surrogate: Number(n.dataset.value), position: i + 1 }))
          .filter((x) => Number.isInteger(x.surrogate) && x.surrogate > 0);
        await fetch("/updateListOrder.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ token: sourceToken, order: sourceOrder })
        });

        applyListOrderMode(targetToken);
        applyListOrderMode(sourceToken);
      },

      onEnd: async function (evt) {
        const item = evt.item;

        // 🚫 External drops must not trigger reorder
        if (item?.dataset?.source) return;

        // Same-list reorder only
        if (evt.from !== evt.to) return;

        const listToken = evt.to.closest(".list-contents")?.dataset.token;
        if (listToken && getListOrderMode(listToken) === "alpha") {
          showFlashMessage?.("A-Z order is on — set order to # to drag");
          applyListOrderMode(listToken);
          return;
        }

        const order = [...evt.to.querySelectorAll(".list-sub-item[data-value]")]
          .map((item, index) => ({ surrogate: Number(item.dataset.value), position: index + 1 }))
          .filter((x) => Number.isInteger(x.surrogate) && x.surrogate > 0);

        try {
          await fetch("/updateListOrder.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ token: listToken, order })
          });
        } catch (err) {
          console.error("❌ Failed to update item order:", err);
        }

        updateListItemOrderNumbers(evt.to, true);
      }
    });

    // 🧩 Save reference so list drag disabling still works
    container._sortable = itemSortable;
  });


}

// function initListSorting_new() {
//   /* =========================================================
//      🟢 LIST-LEVEL SORT (unchanged)
//      ========================================================= */
//   document.querySelectorAll(".group-contents, .list-contents").forEach(wrapper => {
//     if (wrapper.dataset.sortableBound) return;
//     wrapper.dataset.sortableBound = "true";

//     new Sortable(wrapper, {
//       animation: 150,
//       draggable: ".group-item",
//       handle: ".list-header-row",
//       delay: 200,
//       delayOnTouchOnly: true,
//       ghostClass: "dragging",
//       group: "lists",
//       fallbackOnBody: true,
//       swapThreshold: 0.65,

//       onStart() {
//         window._disabledItemSortables = [];
//         document.querySelectorAll(".list-items-wrapper").forEach(el => {
//           const s = el._sortable;
//           if (s && !s.option("disabled")) {
//             s.option("disabled", true);
//             window._disabledItemSortables.push(s);
//           }
//         });
//       },

//       onEnd: async function (evt) {
//         (window._disabledItemSortables || []).forEach(s =>
//           s.option("disabled", false)
//         );
//         window._disabledItemSortables = [];

//         const movedToken = evt.item.dataset.group;
//         const dropContainer = evt.to;

//         let section =
//           dropContainer?.dataset?.section ||
//           dropContainer.closest("[data-section]")?.dataset?.section ||
//           dropContainer.closest(".list-group-wrapper")?.dataset?.section ||
//           "owned";

//         const parentToken =
//           dropContainer.closest(".group-item")?.dataset.group || null;

//         const order = [...dropContainer.querySelectorAll(":scope > .group-item")]
//           .map(el => el.dataset.group);

//         await fetch("/updateContentListOrder.php", {
//           method: "POST",
//           headers: { "Content-Type": "application/json" },
//           body: JSON.stringify({ section, movedToken, parentToken, order })
//         });
//       }
//     });
//   });

//   /* =========================================================
//      🟢 ITEM-LEVEL SORT (extended)
//      ========================================================= */
//   document.querySelectorAll(".list-items-wrapper").forEach(container => {
//     if (container.dataset.itemSortableBound) return;
//     container.dataset.itemSortableBound = "true";

//     const sortable = new Sortable(container, {
//       animation: 150,
//       draggable: ".list-sub-item",
//       delay: 200,
//       delayOnTouchOnly: true,
//       ghostClass: "sortable-ghost",
//       group: { name: "items", pull: "clone", put: true },

//       /* =======================
//          ➕ ADD (INTERNAL + EXTERNAL)
//          ======================= */
//       onAdd: async function (evt) {
//         const item = evt.item;
//         const surrogate = item.dataset.value;
//         const source = item.dataset.source;   // external if defined
//         const targetToken =
//           evt.to.closest(".list-contents")?.dataset.token;

//         // Always remove visual clone
//         item.remove();

//         if (!surrogate || !targetToken) return;

//         /* -------- External source (Drive, etc.) -------- */
//         if (source) {
//           await fetch("/addItemToList.php", {
//             method: "POST",
//             headers: { "Content-Type": "application/x-www-form-urlencoded" },
//             body:
//               `token=${encodeURIComponent(targetToken)}` +
//               `&surrogate=${encodeURIComponent(surrogate)}`,
//             credentials: "include"
//           });

//           const container = document.getElementById(`list-${targetToken}`);
//           if (container && container.style.display === "block") {
//             fetch(`/getListItems.php?list=${targetToken}`)
//               .then(r => r.text())
//               .then(html => (container.innerHTML = html));
//           }

//           updateLastUsedLabels?.();
//           return;
//         }

//         /* -------- Internal TW move -------- */
//         const sourceToken =
//           evt.from.closest(".list-contents")?.dataset.token;

//         await fetch("/addItemToList.php", {
//           method: "POST",
//           headers: { "Content-Type": "application/x-www-form-urlencoded" },
//           body:
//             `token=${encodeURIComponent(targetToken)}` +
//             `&surrogate=${encodeURIComponent(surrogate)}`,
//           credentials: "include"
//         });

//         const updateOrder = async (el, token) => {
//           const order = [...el.querySelectorAll(".list-sub-item")]
//             .map((n, i) => ({ surrogate: n.dataset.value, position: i + 1 }));
//           await fetch("/updateListOrder.php", {
//             method: "POST",
//             headers: { "Content-Type": "application/json" },
//             body: JSON.stringify({ token, order })
//           });
//         };

//         await updateOrder(evt.to, targetToken);
//         if (sourceToken && evt.from !== evt.to) {
//           await updateOrder(evt.from, sourceToken);
//         }
//       },

//       /* =======================
//          ↕️ SAME-LIST REORDER
//          ======================= */
//       onEnd: async function (evt) {
//         if (evt.from !== evt.to) return;

//         const listToken =
//           evt.to.closest(".list-contents")?.dataset.token;
//         if (!listToken) return;

//         const order = [...evt.to.querySelectorAll(".list-sub-item")]
//           .map((el, i) => ({
//             surrogate: el.dataset.value,
//             position: i + 1
//           }));

//         await fetch("/updateListOrder.php", {
//           method: "POST",
//           headers: { "Content-Type": "application/json" },
//           body: JSON.stringify({ token: listToken, order })
//         });
//       }
//     });

//     container._sortable = sortable;
//   });
// }




function saveListToFavorites(listToken) {
    fetch("/favoriteList.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: "token=" + encodeURIComponent(listToken)
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === "OK") {
            console.log("✅ List favorited!");
            loadUserContentLists(); // to refresh sidebar
        } else {
            alert("❌ " + data.message);
        }
    });
}


function deleteList(token) {
  if (!confirm("Delete this list? This cannot be undone.")) return;

  fetch('/deleteList.php?token=' + encodeURIComponent(token))
    .then(r => r.text())
    .then(async (res) => {
      const reply = String(res || "").trim().toLowerCase();
      if (reply !== "list deleted") {
        alert("Delete failed: " + (res || "unknown response"));
        return;
      }

      const safeToken = CSS.escape(String(token || ""));

      // 1) Remove all DOM instances for the deleted list.
      document
        .querySelectorAll(`.group-item[data-group="${safeToken}"]`)
        .forEach((wrapper) => {
          const menu = wrapper.querySelector(".list-menu-wrapper");
          if (menu) menu.classList.remove("open");
          wrapper.remove();
        });
      document
        .querySelectorAll(`.list-contents[data-token="${safeToken}"], .group-contents[data-token="${safeToken}"]`)
        .forEach((el) => el.remove());

      // 2) Prune in-memory owner cache to prevent stale re-renders.
      const pruneTree = (arr) => {
        if (!Array.isArray(arr)) return [];
        return arr
          .filter((n) => String(n?.token || "") !== String(token))
          .map((n) => {
            if (Array.isArray(n?.children)) n.children = pruneTree(n.children);
            return n;
          });
      };
      if (window.CACHED_OWNER_LISTS && typeof window.CACHED_OWNER_LISTS === "object") {
        Object.keys(window.CACHED_OWNER_LISTS).forEach((ownerKey) => {
          const data = window.CACHED_OWNER_LISTS[ownerKey];
          if (!data) return;
          data.owned = pruneTree(data.owned);
          data.accessible = pruneTree(data.accessible);
        });
      }

      // 3) Patch cached owner-list JSON entries in-place (keep offline reliability for other lists).
      const pruneOwnerPayload = (payload) => {
        if (!payload || typeof payload !== "object") return payload;
        const next = { ...payload };
        next.owned = pruneTree(Array.isArray(payload.owned) ? payload.owned : []);
        next.accessible = pruneTree(Array.isArray(payload.accessible) ? payload.accessible : []);
        return next;
      };
      try {
        if ("caches" in window) {
          const cacheNames = await caches.keys();
          for (const cacheName of cacheNames) {
            const cache = await caches.open(cacheName);

            const reqs = await cache.keys();
            for (const req of reqs) {
              let u;
              try {
                u = new URL(req.url, window.location.origin);
              } catch {
                continue;
              }
              const isOwnerSnapshot = u.pathname.startsWith("/offline/owners/") && u.pathname.endsWith(".json");
              const isOwnerApi = u.pathname.endsWith("/getOwnersListsJSON.php");
              if (!isOwnerSnapshot && !isOwnerApi) continue;

              const cached = await cache.match(req);
              if (!cached || !cached.ok) continue;
              const contentType = String(cached.headers.get("Content-Type") || "").toLowerCase();
              if (!contentType.includes("application/json")) continue;

              let json;
              try {
                json = await cached.clone().json();
              } catch {
                continue;
              }
              const patched = pruneOwnerPayload(json);
              await cache.put(
                req,
                new Response(JSON.stringify(patched), {
                  headers: { "Content-Type": "application/json" },
                  status: 200
                })
              );
            }
          }
        }
      } catch (err) {
        console.warn("Cache patch after delete failed:", err);
      }

      // 4) Re-fetch sidebar from server and rebuild.
      if (typeof loadUserContentLists === "function") {
        await loadUserContentLists();
      }

      // 5) Patch sidebar snapshot cache in-place (remove only deleted list nodes).
      try {
        const stripListFromSnapshot = (html, listToken) => {
          const src = String(html || "");
          if (!src.trim()) return src;
          const host = document.createElement("div");
          host.innerHTML = src;
          const s = CSS.escape(String(listToken || ""));
          host
            .querySelectorAll(`.group-item[data-group="${s}"], .list-contents[data-token="${s}"], .group-contents[data-token="${s}"]`)
            .forEach((el) => el.remove());
          return host.innerHTML;
        };
        for (let i = 0; i < localStorage.length; i += 1) {
          const key = localStorage.key(i);
          if (!key || !key.startsWith("sidebarSnapshot:")) continue;
          const html = localStorage.getItem(key);
          const nextHtml = stripListFromSnapshot(html, token);
          if (nextHtml !== html) {
            localStorage.setItem(key, nextHtml);
          }
        }
      } catch (err) {
        console.warn("Sidebar snapshot patch failed:", err);
      }
    })
    .catch((err) => {
      alert("Delete failed: " + (err?.message || "network error"));
    });
}




function renameList(token) {
    
  // 🧼 Close any open menus
  document.querySelectorAll(".list-menu-wrapper").forEach(menu => menu.classList.remove("open"));

  const titleSpan = document.getElementById("list-title-" + token);
  if (!titleSpan) return;


  const currentName = titleSpan.textContent.trim();
  const input = document.createElement("input");
  input.type = "text";
  input.className = "rename-list-input";
  input.value = currentName;

  titleSpan.replaceWith(input);
  input.focus();
  input.select();

  function saveRename() {
    const newName = input.value.trim();
    if (!newName || newName === currentName) {
      input.replaceWith(titleSpan); // cancel if unchanged
      return;
    }

    fetch("/renameList.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ token, name: newName })
    })
    .then(res => res.json())
    .then(data => {
      if (data.status === "success") {
        const newSpan = document.createElement("span");
        newSpan.className = "list-title";
        newSpan.id = "list-title-" + token;
        newSpan.textContent = newName;
        input.replaceWith(newSpan);
      } else {
        alert("Rename failed.");
        input.replaceWith(titleSpan);
      }
    })
    .catch(() => {
      alert("Error renaming.");
      input.replaceWith(titleSpan);
    });
  }

  input.addEventListener("blur", saveRename);
  input.addEventListener("keydown", function (e) {
    if (e.key === "Enter") {
      e.preventDefault();
      saveRename();
    } else if (e.key === "Escape") {
      input.replaceWith(titleSpan);
    }
  });
}


function showFlashNear(el, message) {
  const flash = document.createElement("div");
  flash.className = "flash-msg";
  flash.textContent = message;

  const parent = el.closest(".list-group-item") || document.body;
  parent.style.position = "relative"; // ensure context
  parent.appendChild(flash);

  // Trigger fade-in
  requestAnimationFrame(() => {
    flash.style.opacity = "1";
  });

  setTimeout(() => {
    flash.style.opacity = "0";
    setTimeout(() => flash.remove(), 300);
  }, 1500);
}


function convertDriveLinkToDirectPdf(url) {
  const match = url.match(/\/file\/d\/([a-zA-Z0-9_-]+)/);
  if (match) {
    return `https://drive.google.com/uc?export=download&id=${match[1]}`;
  }
  return null;
}




function toggleYearGroup(header) {
    const arrow = header.querySelector(".year-arrow");
    const content = header.nextElementSibling;

    const isOpen = content.style.display === "block";
    content.style.display = isOpen ? "none" : "block";
    arrow.textContent = isOpen ? "▶" : "▼";
}



function getCurrentItemElement() {
  const surrogate = window.currentSurrogate;
  if (!surrogate) return null;

  // try within current list container if it exists
  const token = window.currentListToken;
  const listContainer = document.getElementById(`list-${token}`);
  if (listContainer) {
    const el = listContainer.querySelector(`.list-sub-item[data-value="${surrogate}"]`);
    if (el) return el;
  }

  // 🩵 fallback: find globally (offline mode, or list not rendered yet)
  return document.querySelector(`.list-sub-item[data-value="${surrogate}"]`);
}


function selectNextItem() {
  const current = getCurrentItemElement();
  if (!current) return false;

  let next = current.nextElementSibling;
  while (next && (!next.classList.contains("list-sub-item") || !next.dataset.value)) {
    next = next.nextElementSibling;
  }

  // fallback: next list group
  if (!next) {
    const nextList = current.closest(".list-contents")?.nextElementSibling;
    next = nextList?.querySelector(".list-sub-item[data-value]");
  }

  if (next) {
    const token = window.currentListToken;
    const listContainer = next.closest(".list-contents") || document;
    selectItem(next.dataset.value, token, listContainer);
    return true;
  }
  return false;
}

function selectPreviousItem() {
  const current = getCurrentItemElement();
  if (!current) return false;

  let prev = current.previousElementSibling;
  while (prev && (!prev.classList.contains("list-sub-item") || !prev.dataset.value)) {
    prev = prev.previousElementSibling;
  }

  // fallback: previous list group
  if (!prev) {
    const prevList = current.closest(".list-contents")?.previousElementSibling;
    const items = prevList?.querySelectorAll(".list-sub-item[data-value]");
    prev = items?.[items.length - 1];
  }

  if (prev) {
    const token = window.currentListToken;
    const listContainer = prev.closest(".list-contents") || document;
    selectItem(prev.dataset.value, token, listContainer);
    return true;
  }
  return false;
}



window.resetApp = function () {
  const seenVersion = localStorage.getItem("lastSeenVersion") || "(unknown)";
  navigator.serviceWorker.getRegistrations().then(regs => {
    regs.forEach(reg => reg.unregister());
    caches.keys().then(keys => keys.forEach(k => caches.delete(k)));
    localStorage.clear();

    setTimeout(() => {
      alert(`🧹 Cleared version ${seenVersion}. Reloading fresh...`);
      location.href = location.pathname + "?fresh=" + Date.now();
    }, 300);
  });
};


function getEffectiveToken() {
    const pathParts = window.location.pathname
      .split("/")
      .filter(Boolean)
      .map(part => {
        try {
          return decodeURIComponent(part);
        } catch {
          return part;
        }
      });

    const ignoredParts = new Set([
      "index.php",
      "textwhisper-test",
      "textwhisper-live",
      "tw-light"
    ]);

    const hasFileExtension = (part) => /\.[a-z0-9]{1,5}$/i.test(part);
    const isLikelyToken = (part) => {
      if (!part || ignoredParts.has(part.toLowerCase())) return false;
      if (hasFileExtension(part)) return false;
      // username/list token style: letters, numbers, underscore, dash, dot
      return /^[A-Za-z0-9._-]{2,80}$/.test(part);
    };

    const tokenFromUrl = pathParts.find(isLikelyToken) || null;
    const sessionUser = document.body.dataset.loggedInUser || window.SESSION_USERNAME || null;
    const lastOpenedOwner = window.twGetRememberedLastProfile?.() || "";

    if (tokenFromUrl) return tokenFromUrl;
    if (sessionUser) {
      if (lastOpenedOwner && isLikelyToken(lastOpenedOwner)) return lastOpenedOwner;
      return sessionUser;
    }
    if (!navigator.onLine && lastOpenedOwner && isLikelyToken(lastOpenedOwner)) {
      return lastOpenedOwner;
    }
    return "welcome";
}

// not implemented yet 
function searchPublicLists(query) {
  if (!query || query.length < 2) {
    document.getElementById('publicListResults').innerHTML = '';
    return;
  }

  fetch(`/searchPublicLists.php?q=${encodeURIComponent(query)}`)
    .then(res => res.text())
    .then(html => {
      document.getElementById('publicListResults').innerHTML = html;
    });
}


function showRenameListInput(container, token) {
  // ✅ Close all menus
  document.querySelectorAll('.list-menu-wrapper').forEach(menu => menu.classList.remove('open'));

  // ✅ Prevent duplicate inputs
  if (document.querySelector(`#list-title-${token} + input.new-list-input`)) return;

  const titleSpan = document.getElementById(`list-title-${token}`);
  if (!titleSpan) return;


  // Hide current title temporarily
  titleSpan.style.display = "none";

  const input = document.createElement("input");
  input.type = "text";
  input.value = titleSpan.textContent.trim();
  input.placeholder = "Rename list...";
  input.classList.add("new-list-input");
  input.style.width = "100%";
  input.style.marginTop = "4px";

  input.addEventListener("keydown", function (e) {
    if (e.key === "Enter") {
      const name = input.value.trim();
      if (!name) return;

      fetch("/list_UpdateName.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: `token=${encodeURIComponent(token)}&name=${encodeURIComponent(name)}`
      })
      .then(res => res.json())
      .then(data => {
        if (data.status === "success") {
          const newSpan = document.createElement("span");
          newSpan.className = "list-title";
          newSpan.id = "list-title-" + token;
          newSpan.textContent = name;

          titleSpan.replaceWith(newSpan);
          showFlashNear(newSpan, "✅ List renamed");

          // ✅ Ensure menu button remains functional
          const button = newSpan.closest(".list-header-row")?.querySelector(".menu-button");
          if (button) {
            button.onclick = function (e) {
              toggleListMenu(this);
              e.stopPropagation();
            };
          }
        } else {
          alert("❌ Rename failed: " + (data.message || "Unknown error"));
        }
        cleanup();
      })
      .catch(err => {
        alert("❌ Rename error: " + err.message);
        cleanup();
      });
    } else if (e.key === "Escape") {
      cleanup();
    }
  });

  function cleanup() {
    input.remove();
    titleSpan.style.display = "";
  }

  titleSpan.parentElement.insertBefore(input, titleSpan.nextSibling);
  input.focus();
}


function confirmDeleteList(token) {
  const groupEl = document.querySelector(`[data-group='${token}']`);
  if (!groupEl) return;

  if (!confirm("🗑️ Are you sure you want to delete this list?\nThis cannot be undone.")) return;

  fetch("/list_Delete.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: `token=${encodeURIComponent(token)}`
  })
    .then(res => res.json())
    .then(data => {
      if (data.status === "success") {
        showFlashNear(groupEl, "🗑️ List deleted");
        groupEl.remove();
      } else {
        alert("❌ Delete failed: " + (data.message || "Unknown error"));
      }
    })
    .catch(err => alert("❌ Delete failed: " + err.message));
}


async function createNewList(groupToken) {
  // ✅ Require login
  if (!window.SESSION_USERNAME) {
    showFlashMessage(window.LANG?.login_required || "Login required");
    return;
  }

  // 🕒 Wait until wrapper exists
  let groupWrapper = null;
  for (let i = 0; i < 4; i++) {
    groupWrapper = document.querySelector(`.list-group-wrapper[data-group='${groupToken}']`);
    if (groupWrapper) break;
    await new Promise(r => setTimeout(r, 100));
  }
  if (!groupWrapper) return console.warn("❌ No group wrapper found for", groupToken);

  // 🔍 Locate container
  const container =
    groupWrapper.querySelector(".group-contents") ||
    document.getElementById(`lists-by-${groupToken}`);
  if (!container) return console.warn("❌ No group container found for", groupToken);

  // ✅ Ensure visible
  container.style.display = "block";

  // 🧹 Prevent duplicates
  if (container.querySelector(".new-list-pending")) return;

  // 🔧 Build input row
  const tempToken = "new-temp-" + Math.random().toString(36).substring(2, 8);
  const div = document.createElement("div");
  div.className = "list-group-item group-item new-list-pending";
  div.dataset.group = tempToken;

  // Input wrapper (flex for input + cancel)
  const row = document.createElement("div");
  row.className = "list-header-row";
  row.style.display = "flex";
  row.style.alignItems = "center";
  row.style.gap = "4px";

  const arrow = document.createElement("span");
  arrow.className = "arrow";
  arrow.textContent = "▶";

  const input = document.createElement("input");
  input.type = "text";
  input.className = "new-list-input";
  input.placeholder = window.translations?.new_list_name || "New list name...";
  input.style.flex = "1";
  input.style.marginTop = "4px";

  // ✖ cancel button
  const cancelBtn = document.createElement("button");
  cancelBtn.textContent = "✖";
  cancelBtn.title = "Cancel";
  cancelBtn.style.cssText = `
    background:none;
    border:none;
    color:#999;
    cursor:pointer;
    font-size:14px;
    margin-top:4px;
    padding:0 4px;
  `;
  cancelBtn.onclick = () => div.remove();

  row.append(arrow, input, cancelBtn);
  div.appendChild(row);

  // Insert at top
  const firstItem = container.querySelector(".list-group-item");
  firstItem ? container.insertBefore(div, firstItem) : container.appendChild(div);

  input.focus();

  // 🧠 Handle Enter / Escape
  input.addEventListener("keydown", async e => {
    if (e.key === "Escape") return div.remove();
    if (e.key !== "Enter") return;

    const name = input.value.trim();
    if (!name) return div.remove();
    input.disabled = true;
    cancelBtn.disabled = true;

    try {
      const res = await fetch("/createContentList.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: `name=${encodeURIComponent(name)}${getCreateListOwnerParam()}`
      });
      const result = await res.text();

      if (result === "OK") {
        showFlashMessage?.("✅ List created");
        if (typeof loadUserContentLists === "function") await loadUserContentLists();

        // expand group after creation
        const updatedGroup = document.querySelector(
          `.list-group-wrapper[data-group='${groupToken}']`
        );
        const updatedContainer =
          updatedGroup?.querySelector(".group-contents") ||
          document.getElementById(`lists-by-${groupToken}`);
        if (updatedContainer) {
          updatedContainer.style.display = "block";
          const arrow = updatedGroup.querySelector(".group-arrow");
          if (arrow) arrow.textContent = "▼";
        }} 
      else {
        alert("❌ Failed to create list: " + result);
      }
    } catch (err) {
      alert("❌ Network error: " + err.message);
    }

    div.remove();
  });
}


window.createContentList = async function (name) {
  if (!window.SESSION_USERNAME) {
    throw new Error("Not logged in");
  }

  // 1️⃣ Create list
  const res = await fetch("/createContentList.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: `name=${encodeURIComponent(name)}${getCreateListOwnerParam()}`
  });

  if (!res.ok) return null;

  const result = await res.text();
  if (result !== "OK") return null;

  // 2️⃣ Fetch lists and locate the new one
  const selectedOwner = getAddToListOwnerUsername();
  if (window._addToListCache?.myByOwner && selectedOwner in window._addToListCache.myByOwner) {
    delete window._addToListCache.myByOwner[selectedOwner];
  }
  const listsRes = await fetch(getUserListsUrlForOwner(selectedOwner, true));
  if (!listsRes.ok) return null;

  const lists = await listsRes.json();
  const created = lists.find(l => l.name === name);

  // 3️⃣ Optional: refresh sidebar
  if (created && typeof loadUserContentLists === "function") {
    loadUserContentLists(created.token);
  }

  return created || null; // must include .token
};




function shareList(el) {
  const token = el.dataset.token;
  const baseUrl = window.location.origin;
  const url = `${baseUrl}/${token}`;

  if (navigator.share) {
    navigator.share({
      title: "Check out this list",
      url
    }).catch(err => {
      console.warn("Share failed or cancelled:", err);
    });
  } else {
    navigator.clipboard.writeText(url).then(() => {
      showFlashMessage("🔗 Link copied to clipboard!");
    });
  }
}


function shareLink(token, surrogate = null) {
  const baseUrl = window.location.origin;
  const url = surrogate ? `${baseUrl}/${token}/${surrogate}` : `${baseUrl}/${token}`;

  if (navigator.share) {
    navigator.share({ title: "Check this out", url }).catch(() => {});
  } else {
    navigator.clipboard.writeText(url).then(() => {
      showFlashMessage("🔗 Link copied to clipboard!");
    });
  }
}

function shareGroup(groupToken) {
  const token = String(groupToken || "").replace(/^(owned-|invited-)/, "");
  if (!token) return;
  shareLink(token);
}


async function createNewItemInline(listToken) {
  // 🧹 Close any open menus
  document.querySelectorAll(".list-menu-wrapper.open").forEach(m => m.classList.remove("open"));
  document.querySelectorAll(".item-menu-dropdown").forEach(m => (m.style.display = "none"));

  // 1️⃣ Find the correct list container
  const listContainer = document.getElementById("list-" + listToken);
  if (!listContainer) {
    console.warn("⚠️ createNewItemInline: list container not found for", listToken);
    return;
  }

  // 🔄 Ensure list is open
  expandList(listToken);

  const itemsWrapper =
    listContainer.querySelector(".list-items-wrapper") || listContainer;

  // 2️⃣ Create inline input placeholder
  const wrapper = document.createElement("div");
  wrapper.className = "list-sub-item new-item-wrapper";
  wrapper.style.display = "flex";
  wrapper.style.alignItems = "center";
  wrapper.style.gap = "4px";

  const input = document.createElement("input");
  input.type = "text";
  input.className = "new-item-input";
  input.placeholder = "New Item…";
  input.style.flex = "1";

  // ✖️ Cancel button
  const cancelBtn = document.createElement("button");
  cancelBtn.textContent = "✖";
  cancelBtn.title = "Cancel";
  cancelBtn.className = "cancel-new-item-btn";
  cancelBtn.style.cssText = `
    background: none;
    border: none;
    color: #999;
    cursor: pointer;
    font-size: 14px;
    padding: 0 4px;
  `;

  cancelBtn.onclick = () => wrapper.remove();

  wrapper.append(input, cancelBtn);

  // ✅ Prepend so it's above the first item
  itemsWrapper.prepend(wrapper);
  input.focus();

  // 3️⃣ Handle Enter / Escape
  input.addEventListener("keydown", async e => {
    if (e.key === "Escape") return wrapper.remove();
    if (e.key !== "Enter") return;

    e.preventDefault();
    const title = input.value.trim();
    if (!title) return wrapper.remove();
    input.disabled = true;
    cancelBtn.disabled = true;

    try {
      const ownerUser = window.currentOwner?.username || "";
      if (!ownerUser) {
        alert("❌ No selected owner. Please switch to the owner's profile and try again.");
        wrapper.remove();
        return;
      }
      const { surrogate } = await createItemSubject(title, listToken, ownerUser);
      console.log("✅ Created new item:", surrogate, "in list", listToken);

      // 5️⃣ Update in-memory JSON (offline)
      if (window.ownersListsJSON) {
        const findList = (lists, token) => {
          for (const l of lists) {
            if (l.token === token) return l;
            if (l.children) {
              const f = findList(l.children, token);
              if (f) return f;
            }
          }
          return null;
        };
        const targetList = findList(window.ownersListsJSON, listToken);
        if (targetList) {
          targetList.items = targetList.items || [];
          targetList.items.unshift({
            surrogate,
            owner: ownerUser || window.SESSION_USERNAME,
            display_name: window.SESSION_DISPLAY_NAME || window.SESSION_USERNAME,
            title,
            fileserver: "cloudflare",
            role_rank: 90
          });
        }
      }

      // 6️⃣ Replace input with real item
      const html = renderSingleListItemHTML(
        {
          surrogate,
          owner: ownerUser || window.SESSION_USERNAME,
          display_name: window.SESSION_DISPLAY_NAME || window.SESSION_USERNAME,
          title,
          fileserver: "cloudflare",
          role_rank: 90
        },
        listToken
      );
      const el = document.createElement("div");
      el.innerHTML = html.trim();
      const newItem = el.firstElementChild;
      itemsWrapper.replaceChild(newItem, wrapper);

      // 7️⃣ Activate
      newItem.classList.add("active");
      selectItem(surrogate, listToken, listContainer);
      newItem.scrollIntoView({ block: "nearest", behavior: "smooth" });
      showFlashMessage?.("✅ Item created");
    } catch (err) {
      console.error("❌ Error creating new item:", err);
      showFlashMessage?.("❌ Failed to create item");
      wrapper.remove();
    }
  });
}



// Simple: open a *list* by token, set globals, update URL.
window.expandList = (token) => {
  const g = document.querySelector(`.group-item[data-group="${token}"]`);
  if (!g) return console.warn("⚠️ expandList: no list for token", token), false;

  const c = g.querySelector(":scope > .list-contents") || document.getElementById(`list-${token}`);
  if (!c) return console.warn("⚠️ expandList: no .list-contents for", token), false;

  if (c.style.display !== "block") {
    g.querySelector(":scope > .list-header-row")?.click();
  }

  window.currentListToken = token;
  // Ensure list JSON is cached when a list is opened (online)
  const ownerHint = g?.dataset?.ownerUsername || window.currentOwner?.username || null;
  if (navigator.onLine) {
    window.cacheOwnerJsonForToken?.(token, ownerHint);
  } else {
    window.queueOwnerCacheToken?.(token);
  }
  try {
    localStorage.setItem("last-selected-list-token", token || "");
    if (ownerHint) window.twRememberLastProfile?.(ownerHint);
  } catch {}
  history.replaceState({}, "", `/${token}/${window.currentSurrogate || "0"}`);
  return true;
};



function createNewItemFromMenu() {
  // Prefer whatever we already know
  let token =
    window.currentListToken ||
    document.querySelector(".group-item.active-list")?.dataset.group ||
    document.querySelector(".list-contents[data-token]:not([style*='display:none'])")?.dataset.token;

  // Last resort: first visible list header on screen
  if (!token) {
    token = document.querySelector(".group-item .list-header-row")?.closest(".group-item")?.dataset.group || null;
    if (token) {
      // reflect selection for consistency
      window.currentListToken = token;
      history.replaceState({}, "", `/${token}/${window.currentSurrogate || "0"}`);
    }
  }

  if (token) {
    createNewItemInline(token);
  } else {
    alert("⚠️ Please open a list first (or create one).");
  }
}


//Moved to JSText.js
function renameItemXXXXX(surrogate, listToken) {
  // Close menus
  document.querySelectorAll(".item-menu-wrapper").forEach(m => m.classList.remove("open"));

  const item = document.querySelector(`.list-sub-item[data-value="${surrogate}"]`);
  if (!item) return;

  const titleEl = item.querySelector(".item-title");
  if (!titleEl) return;

//   const currentName = titleEl.textContent.replace(/^•\s*/, "").replace(/\s*\[.*\]$/, "").trim();
  // 🩹 Strip bullet (•) and trailing owner info like [username]
  const currentName = titleEl.textContent
    .replace(/^[•\-\u2022\s]+/, "")  // remove bullet or dash + leading spaces
    .replace(/\s*\[.*\]$/, "")       // remove [owner] suffix
    .trim();


  const input = document.createElement("input");
  input.type = "text";
  input.className = "rename-item-input";
  input.value = currentName;

  titleEl.replaceWith(input);
  input.focus();
  input.select();

  async function saveRename() {
    const newName = input.value.trim();
    if (!newName || newName === currentName) {
      input.replaceWith(titleEl); // cancel
      return;
    }

    try {
      const res = await fetch("/renameItem.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ surrogate, name: newName })
      });
      const data = await res.json();

      if (data.status === "success") {
        const newSpan = document.createElement("span");
        newSpan.className = "item-title";
        newSpan.innerHTML = `• ${newName} <span style="color:gray; font-size:0.9em;">[${window.SESSION_USERNAME}]</span>`;
        newSpan.setAttribute("onclick", `selectItem(${surrogate}, '${listToken}')`);
        input.replaceWith(newSpan);
        
        // 🧩 Update open textareas if this item is currently loaded
        if (window.currentSurrogate == surrogate) {
        const t1 = document.getElementById("myTextarea");
        const t2 = document.getElementById("myTextarea2");
        if (t1 && data.newText) t1.value = data.newText;
        if (t2 && data.newText) t2.value = data.newText;
        }
      }

      else {
        alert("Rename failed.");
        input.replaceWith(titleEl);
      }
    } catch (err) {
      alert("Error renaming.");
      input.replaceWith(titleEl);
    }
  }

  input.addEventListener("blur", saveRename);
  input.addEventListener("keydown", e => {
    if (e.key === "Enter") {
      e.preventDefault();
      saveRename();
    } else if (e.key === "Escape") {
      input.replaceWith(titleEl);
    }
  });
}


async function createItemSubject(title, listToken, ownerUser = "", order = 1) {
  return fetch("/datainsert_to_list.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body:
      `dataname=${encodeURIComponent(title)}` +
      `&surrogate=0` +
      `&token=${encodeURIComponent(listToken)}` +
      `&order=${encodeURIComponent(order)}` +
      `&text=${encodeURIComponent(title)}` +
      (ownerUser ? `&owner=${encodeURIComponent(ownerUser)}` : "")
  })
    .then(res => res.text())
    .then(surrogate => {
      if (parseInt(surrogate) > 0) {
        return { surrogate };
      } else {
        throw new Error("Server did not return a valid surrogate");
      }
    });
}


function toggleCreateMenu(btn) {
  const menu = btn.nextElementSibling;
  document.querySelectorAll(".create-menu").forEach(m => {
    if (m !== menu) m.classList.remove("show");
  });
  menu.classList.toggle("show");
}


//called from the "Create" menu button
function closeCreateMenu() {
  document.querySelectorAll(".create-menu").forEach(menu => {
    menu.classList.remove("show");
  });
  document.querySelectorAll(".create-btn").forEach(btn => btn.classList.remove("active"));
}

document.addEventListener("click", e => {
  if (!e.target.closest(".create-dropdown")) {
    closeCreateMenu();
  }
});




// =========================================================
// renderOwnerLists(sectionId, lists, options)
// =========================================================


// =========================================================
// 🔍 Recursive filter that matches list titles AND item titles
// =========================================================
function filterTree(nodes, query) {
  if (!Array.isArray(nodes)) return [];
  const out = [];

  for (const node of nodes) {
    const listMatch  = node.title?.toLowerCase().includes(query);
    const items      = Array.isArray(node.items) ? node.items : [];
    const itemMatches = items.filter(it => it.title?.toLowerCase().includes(query));
    const childMatches = filterTree(node.children || [], query);

    if (listMatch || itemMatches.length || childMatches.length) {
      out.push({
        ...node,
        items: itemMatches.length ? itemMatches : (listMatch ? items : []), // only include items when list or items match
        children: childMatches,
        _hasMatch: true
      });
    }
  }
  return out;
}



function bindSidebarSearchXXX() {
  const searchInput = document.getElementById("searchSidebar");
  if (!searchInput) return;

  searchInput.oninput = () => {
    const q = (searchInput.value || "").toLowerCase().trim();
    
    // 🟦 1️⃣ Handle Friends tab separately
    const activeFriendsTab = document.querySelector("#usersTab.sidebar-tab-content.active");
    if (activeFriendsTab) {
      // show/hide friends
      document.querySelectorAll("#usersTab .user-item").forEach(el => {
        const match = el.textContent.toLowerCase().includes(q);
        el.style.display = match ? "" : "none";
      });

      // hide empty subgroups
      document.querySelectorAll("#usersTab .friends-list-subgroup").forEach(group => {
        const visible = group.querySelector(".user-item:not([style*='display: none'])");
        group.style.display = visible ? "" : "none";
      });

      return; // ✅ stop here so lists search doesn’t run
    }

    // 🟦 2️⃣ Lists search (current logic)    
    
    const tokenOrUser = window.currentOwnerToken;
    const cached = window.CACHED_OWNER_LISTS?.[tokenOrUser];

    // If nothing cached yet, bail
    if (!cached) return;

    // When cleared → rebuild full sidebar
    if (!q) {
      loadUserContentLists();
      return;
    }

    // Split groups from cached payload
    const owned    = Array.isArray(cached.owned) ? cached.owned : [];
    const acc      = Array.isArray(cached.accessible) ? cached.accessible : [];
    const invited  = acc.filter(l => l.relationship === "invited" || l.relationship === "both");
    const followed = acc.filter(l => l.relationship === "followed");

    // Filter each group by list titles + item titles
    const fOwned    = filterTree(owned, q);
    const fInvited  = filterTree(invited, q);
    const fFollowed = filterTree(followed, q);

    // Re-render results (keep section headers consistent)
    const listManager = document.getElementById("listManager");
    if (!listManager) return;
    listManager.innerHTML = "";

    if (fOwned.length) {
      renderOwnerLists(tokenOrUser, fOwned, {
        label: `Lists by: ${cached.owner?.display_name || tokenOrUser}`,
        groupId: `owned-${tokenOrUser}`,
        icon: "👑",
        avatarUrl: twResolveAvatarUrl(cached.owner, cached.owner?.display_name || tokenOrUser),
        ownerDisplayName: cached.owner?.display_name || tokenOrUser,
        showOwnerMeta: true
      });
    }
    if (fInvited.length) {
      renderOwnerLists(`${tokenOrUser}-invited`, fInvited, {
        label: window.translations?.my_groups || "💬 My Groups",
        groupId: "invited-lists",
        icon: "💬",
        showOwnerMeta: false
      });
    }
    if (fFollowed.length) {
      renderOwnerLists(`${tokenOrUser}-followed`, fFollowed, {
        label: `⭐ ${window.translations?.followed_lists || "Followed lists"}`,
        groupId: "followed-lists",
        icon: "⭐",
        showOwnerMeta: false
      });
    }

    // Auto-open all three sections during search
    document.querySelectorAll(".list-group-wrapper .group-contents").forEach(c => {
      c.style.display = "block";
      const arrow = c.previousElementSibling?.querySelector(".group-arrow");
      if (arrow) arrow.textContent = "▼";
    });

    refreshInlineChatBadges();

    // ✅ re-enable item drag/drop after sidebar rebuild
    if (typeof initListSorting === "function") initListSorting();    
        
  };
}


function bindSidebarSearchYYY_Working() {
  const searchInput = document.getElementById("searchSidebar");
  if (!searchInput) return;

  searchInput.oninput = () => {
    const q = (searchInput.value || "").toLowerCase().trim();

    // 🟦 1️⃣ Handle Friends tab separately
    const activeFriendsTab = document.querySelector("#usersTab.sidebar-tab-content.active");
    if (activeFriendsTab) {
      document.querySelectorAll("#usersTab .user-item").forEach(el => {
        const match = el.textContent.toLowerCase().includes(q);
        el.style.display = match ? "" : "none";
      });

      document.querySelectorAll("#usersTab .friends-list-subgroup").forEach(group => {
        const visible = group.querySelector(".user-item:not([style*='display: none'])");
        group.style.display = visible ? "" : "none";
      });
      return;
    }

    // 🟦 2️⃣ Lists search (enhanced)
    const tokenOrUser = window.currentOwnerToken;
    const cached = window.CACHED_OWNER_LISTS?.[tokenOrUser];
    if (!cached) return;

    // When cleared → rebuild full sidebar
    if (!q) {
      loadUserContentLists();
      return;
    }

    // ✅ Combine all lists (owned + accessible)
    const owned = Array.isArray(cached.owned) ? cached.owned : [];
    const accessible = Array.isArray(cached.accessible) ? cached.accessible : [];

    // ✅ Infer relationship types for invited/followed if not explicitly tagged
    const invited = accessible.filter(l =>
      (l.relationship && l.relationship.toLowerCase().includes("invite")) ||
      (l.access && l.access.toLowerCase().includes("invite")) ||
      (!l.relationship && (l.role_rank < 90 || l.can_edit === 0))
    );

    const followed = accessible.filter(l =>
      (l.relationship && l.relationship.toLowerCase().includes("follow")) ||
      (l.access && l.access.toLowerCase().includes("follow"))
    );

    // ✅ Everything else that isn’t owned/invited/followed → generic shared lists
    const others = accessible.filter(l => !invited.includes(l) && !followed.includes(l));

    // Filter each group by list titles + item titles
    const fOwned    = filterTree(owned, q);
    const fInvited  = filterTree(invited, q);
    const fFollowed = filterTree(followed, q);
    const fOthers   = filterTree(others, q);

    // ✅ Re-render results
    const listManager = document.getElementById("listManager");
    if (!listManager) return;
    listManager.innerHTML = "";

    if (fOwned.length) {
      renderOwnerLists(tokenOrUser, fOwned, {
        label: `Lists by: ${cached.owner?.display_name || tokenOrUser}`,
        groupId: `owned-${tokenOrUser}`,
        icon: "👑",
        avatarUrl: twResolveAvatarUrl(cached.owner, cached.owner?.display_name || tokenOrUser),
        ownerDisplayName: cached.owner?.display_name || tokenOrUser,
        showOwnerMeta: true
      });
    }

    if (fInvited.length) {
      renderOwnerLists(`${tokenOrUser}-invited`, fInvited, {
        label: window.translations?.my_groups || "💬 My Groups",
        groupId: "invited-lists",
        icon: "💬",
        showOwnerMeta: true
      });
    }

    if (fOthers.length) {
      renderOwnerLists(`${tokenOrUser}-shared`, fOthers, {
        label: "🤝 Shared Lists",
        groupId: "shared-lists",
        icon: "🤝",
        showOwnerMeta: true
      });
    }

    if (fFollowed.length) {
      renderOwnerLists(`${tokenOrUser}-followed`, fFollowed, {
        label: `⭐ ${window.translations?.followed_lists || "Followed lists"}`,
        groupId: "followed-lists",
        icon: "⭐",
        showOwnerMeta: false
      });
    }

    // Auto-open all visible sections
    document.querySelectorAll(".list-group-wrapper .group-contents").forEach(c => {
      c.style.display = "block";
      const arrow = c.previousElementSibling?.querySelector(".group-arrow");
      if (arrow) arrow.textContent = "▼";
    });

    refreshInlineChatBadges();

    if (typeof initListSorting === "function") initListSorting();
  };
}


function bindSidebarSearch() {
  const searchInput = document.getElementById("searchSidebar");
  if (!searchInput) return;

  searchInput.oninput = () => {
    let q = (searchInput.value || "").toLowerCase().trim();

    // 🟦 1️⃣ Handle Friends tab separately
    const activeFriendsTab = document.querySelector("#usersTab.sidebar-tab-content.active");
    if (activeFriendsTab) {
      document.querySelectorAll("#usersTab .user-item").forEach(el => {
        const match = el.textContent.toLowerCase().includes(q);
        el.style.display = match ? "" : "none";
      });

      document.querySelectorAll("#usersTab .friends-list-subgroup").forEach(group => {
        const visible = group.querySelector(".user-item:not([style*='display: none'])");
        group.style.display = visible ? "" : "none";
      });
      return;
    }

    // 🟦 2️⃣ Lists search
    const tokenOrUser = window.currentOwnerToken;
    const cached = window.CACHED_OWNER_LISTS?.[tokenOrUser];
    if (!cached) return;

    // When cleared → rebuild full sidebar
    if (!q) {
      loadUserContentLists();
      return;
    }

    // ✅ Combine all lists (owned + accessible)
    const owned = Array.isArray(cached.owned) ? cached.owned : [];
    const accessible = Array.isArray(cached.accessible) ? cached.accessible : [];

    const invited = accessible.filter(l =>
      (l.relationship && l.relationship.toLowerCase().includes("invite")) ||
      (l.access && l.access.toLowerCase().includes("invite")) ||
      (!l.relationship && (l.role_rank < 90 || l.can_edit === 0))
    );

    const followed = accessible.filter(l =>
      (l.relationship && l.relationship.toLowerCase().includes("follow")) ||
      (l.access && l.access.toLowerCase().includes("follow"))
    );

    const others = accessible.filter(l => !invited.includes(l) && !followed.includes(l));

    // ✅ Canonical rule: a list is "All Content" if token === owner_username
    const isAllContent = list =>
      list &&
      list.token &&
      list.owner_username &&
      list.token.trim().toLowerCase() === list.owner_username.trim().toLowerCase();

    // ✅ Only include "All Content" lists when query starts with all:
    const allowAllContent = q.startsWith("all:");
    if (allowAllContent) q = q.replace(/^all:/, "").trim();

    // ✅ Recursive cleaner — removes All Content lists at any nesting level
    function stripAllContentRecursive(nodes) {
      if (!Array.isArray(nodes)) return [];
      return nodes
        .filter(l => allowAllContent || !isAllContent(l))
        .map(l => ({
          ...l,
          children: stripAllContentRecursive(l.children)
        }));
    }

    // ✅ Wrapper around filterTree that applies the recursive cleaner
    const safeFilterTree = (lists, query) => {
      const filtered = filterTree(lists, query);
      return stripAllContentRecursive(filtered);
    };

    // ✅ Filter each group
    const fOwned    = safeFilterTree(owned, q);
    const fInvited  = safeFilterTree(invited, q);
    const fFollowed = safeFilterTree(followed, q);
    const fOthers   = safeFilterTree(others, q);

    // ✅ Re-render results
    const listManager = document.getElementById("listManager");
    if (!listManager) return;
    listManager.innerHTML = "";

    if (fOwned.length) {
      renderOwnerLists(tokenOrUser, fOwned, {
        label: `Lists by: ${cached.owner?.display_name || tokenOrUser}`,
        groupId: `owned-${tokenOrUser}`,
        icon: "👑",
        avatarUrl: twResolveAvatarUrl(cached.owner, cached.owner?.display_name || tokenOrUser),
        ownerDisplayName: cached.owner?.display_name || tokenOrUser,
        showOwnerMeta: true
      });
    }

    if (fInvited.length) {
      renderOwnerLists(`${tokenOrUser}-invited`, fInvited, {
        label: window.translations?.my_groups || "💬 My Groups",
        groupId: "invited-lists",
        icon: "💬",
        showOwnerMeta: true
      });
    }

    if (fOthers.length) {
      renderOwnerLists(`${tokenOrUser}-shared`, fOthers, {
        label: "🤝 Shared Lists",
        groupId: "shared-lists",
        icon: "🤝",
        showOwnerMeta: true
      });
    }

    if (fFollowed.length) {
      renderOwnerLists(`${tokenOrUser}-followed`, fFollowed, {
        label: `⭐ ${window.translations?.followed_lists || "Followed lists"}`,
        groupId: "followed-lists",
        icon: "⭐",
        showOwnerMeta: false
      });
    }

    // expand all visible sections
    document.querySelectorAll(".list-group-wrapper .group-contents").forEach(c => {
      c.style.display = "block";
      const arrow = c.previousElementSibling?.querySelector(".group-arrow");
      if (arrow) arrow.textContent = "▼";
    });

    refreshInlineChatBadges();
    if (typeof initListSorting === "function") initListSorting();
  };
}







// =========================================================
// 🔽 Toggle group open/close with caching
// =========================================================


function bindListGroupToggles() {
  document.querySelectorAll(".sidebar-section-header.collapsible-group").forEach(header => {
    const wrapper = header.closest(".list-group-wrapper");
    const content = wrapper?.querySelector(".group-contents");
    const arrow = header.querySelector(".group-arrow");
    const ownerToken = wrapper?.dataset.owner;
    const key = `group-expanded:${ownerToken}`;
    if (!content) return;

    const isOpen = localStorage.getItem(key) === "true";
    content.style.display = isOpen ? "block" : "none";
    if (arrow) arrow.textContent = isOpen ? "▼" : "▶";

    // prevent double binding
    if (header.dataset.bound) return;
    header.dataset.bound = "1";

    header.addEventListener("click", async e => {
      if (e.target.closest(".menu-button")) return;
      const nowOpen = content.style.display !== "block";
      content.style.display = nowOpen ? "block" : "none";
      if (arrow) arrow.textContent = nowOpen ? "▼" : "▶";
      localStorage.setItem(key, nowOpen);

      if (!nowOpen) return;

      if (!content.dataset.loaded) {
        const data = window.CACHED_OWNER_LISTS?.[ownerToken];
        if (!data) return;

        // Pick correct array (owned or accessible)
        const lists = data.owned || data.accessible || [];

        if (Array.isArray(lists)) {
          content.innerHTML = "";
          lists.forEach(list => content.appendChild(window.renderList(list)));
          content.dataset.loaded = "1";
        }

        // ✅ rebind nested toggles + menus safely after rendering
        requestAnimationFrame(() => {
          refreshInlineChatBadges?.();
          initListSorting?.();
        });
      }
    });
  });
}


function renderSingleListItemHTML(item, token, index = null) {
  const t = window.translations || {};
  const surrogate    = item.surrogate;
  const ownerUser    = item.owner || "";
  const title        = item.title || "";
  const fileserver   = item.fileserver || "";
  const role_rank    = item.role_rank || "";
  const priceLabel   = String(item.price_label || "").trim();
  const shortDescriptionValue = String(item.short_description || item.public_description || "").trim();
  const detailedDescriptionValue = String(item.detailed_description || "").trim();
  const imageUrl     = String(item.image_url || "").trim();
  const shortDescription = shortDescriptionValue.length > 72
    ? `${shortDescriptionValue.slice(0, 69).trimEnd()}...`
    : shortDescriptionValue;

  const orderIndexAttr = Number.isInteger(index) ? ` data-order-index="${index + 1}"` : "";
  const titleAttr = title ? ` data-title="${escapeHtml(title)}"` : "";
  const priceHtml = priceLabel ? `<div class="item-price-chip">${escapeHtml(priceLabel)}</div>` : `<div class="item-price-chip is-placeholder">Set price</div>`;
  const descriptionHtml = shortDescription
    ? `<div class="item-summary">${escapeHtml(shortDescription)}</div>`
    : `<div class="item-summary is-placeholder">Add short description</div>`;
  const mediaHtml = imageUrl
    ? `<div class="item-thumb"><img src="${escapeHtml(imageUrl)}" alt="${escapeHtml(title || "Menu item")}"></div>`
    : `<div class="item-thumb is-placeholder"><span>IMG</span></div>`;
  const actionSquareHtml = `
    <div class="item-action-square">
      ${priceHtml}
      <button class="taptray-order-btn" onclick="taptraySelectItem(this, ${surrogate}, '${escapeHtml(token)}'); event.stopPropagation();">Order</button>
    </div>`;

  return `
    <div class="list-sub-item"
         data-value="${surrogate}"
         data-token="${token}"
         data-owner="${ownerUser}"
         data-item-role-rank="${role_rank}"
         data-fileserver="${fileserver}"
         data-price-label="${escapeHtml(priceLabel)}"
         data-short-description="${escapeHtml(shortDescriptionValue)}"
         data-detailed-description="${escapeHtml(detailedDescriptionValue)}"
         data-public-description="${escapeHtml(shortDescriptionValue)}"
         data-image-url="${escapeHtml(imageUrl)}"
         data-allergens="${escapeHtml(String(item.allergens || "").trim())}"${orderIndexAttr}${titleAttr}>
      <div class="select-item taptray-menu-row" style="flex:1;" onclick="toggleTreeItemExpand(this, ${surrogate}, '${escapeHtml(token)}'); event.stopPropagation();">
        <div class="item-media-rail">
          <div class="item-square-main">
            ${mediaHtml}
            <div class="item-qty-badge" hidden></div>
          </div>
        </div>
        <div class="taptray-menu-copy">
          <div class="item-head">
            <div class="item-title">${escapeHtml(title)}</div>
          </div>
          ${descriptionHtml}
        </div>
        ${actionSquareHtml}
      </div>
      <div class="item-menu-wrapper menu">
        <button class="menu-button"
                onclick="toggleItemMenu(this); event.stopPropagation();">⋮</button>
        <div class="item-menu-dropdown">
          <div class="list-choice"
               onclick="showTaptrayItemPreview(${surrogate}, '${token}'); event.stopPropagation();">
            👁 Preview
          </div>
          <div class="list-choice"
               onclick="addItemToListPrompt(${surrogate}, this); event.stopPropagation();">
            ➕ ${t.add_to_list || "Add to list..."}
          </div>
          <div class="list-choice"
               onclick="renameItem(${surrogate}, '${token}'); event.stopPropagation();">
            ✏️ ${t.rename_list || "Rename item"}
          </div>
          <div class="list-choice"
               onclick="shareLink('${token}', ${surrogate}); event.stopPropagation();">
            🔗 ${t.share_item || "Share this item"}
          </div>
          <div class="list-choice"
               onclick="removeItemFromList('${token}', ${surrogate}, event); event.stopPropagation();">
            🗑 ${t.remove_from_list || "Remove from list"}
          </div>
          <div class="list-choice remove-choice"
               onclick="deleteItemFromMenu(${surrogate}, event); event.stopPropagation();">
            🧨 ${t.delete || "Delete"}
          </div>
        </div>
      </div>
    </div>
  `;
}


function renderListItems(container, token, items) {
  const t = window.translations || {};

// console.log("renderListItems items sample:", items[0]);
    
  const html = `
    <div class="list-items-wrapper">
      ${
        (items && items.length)
          ? items.map((it, index) => {
              const surrogate    = it.surrogate;
              const ownerUser    = it.owner || "";
              const title        = it.title || "";
              const fileserver   = it.fileserver || "";
              const role_rank    = it.role_rank || "";
              const priceLabel   = String(it.price_label || "").trim();
              const shortDescriptionValue = String(it.short_description || it.public_description || "").trim();
              const detailedDescriptionValue = String(it.detailed_description || "").trim();
              const imageUrl     = String(it.image_url || "").trim();
              const shortDescription = shortDescriptionValue.length > 72
                ? `${shortDescriptionValue.slice(0, 69).trimEnd()}...`
                : shortDescriptionValue;
              const priceHtml = priceLabel ? `<div class="item-price-chip">${escapeHtml(priceLabel)}</div>` : `<div class="item-price-chip is-placeholder">Set price</div>`;
              const descriptionHtml = shortDescription
                ? `<div class="item-summary">${escapeHtml(shortDescription)}</div>`
                : `<div class="item-summary is-placeholder">Add short description</div>`;
              const mediaHtml = imageUrl
                ? `<div class="item-thumb"><img src="${escapeHtml(imageUrl)}" alt="${escapeHtml(title || "Menu item")}"></div>`
                : `<div class="item-thumb is-placeholder"><span>IMG</span></div>`;
              const actionSquareHtml = `
                <div class="item-action-square">
                  ${priceHtml}
                  <button class="taptray-order-btn" onclick="taptraySelectItem(this, ${surrogate}, '${escapeHtml(token)}'); event.stopPropagation();">Order</button>
                </div>`;

              return `
                <div class="list-sub-item"
                     data-value="${surrogate}"
                     data-token="${token}"
                     data-owner="${ownerUser}"
                     data-item-role-rank="${role_rank}"
                     data-fileserver="${fileserver}"
                     data-price-label="${escapeHtml(priceLabel)}"
                     data-short-description="${escapeHtml(shortDescriptionValue)}"
                     data-detailed-description="${escapeHtml(detailedDescriptionValue)}"
                     data-public-description="${escapeHtml(shortDescriptionValue)}"
                     data-image-url="${escapeHtml(imageUrl)}"
                     data-allergens="${escapeHtml(String(it.allergens || "").trim())}"
                     data-order-index="${index + 1}"
                     data-title="${escapeHtml(title)}">

                    <div class="select-item taptray-menu-row" style="flex:1;" onclick="toggleTreeItemExpand(this, ${surrogate}, '${escapeHtml(token)}'); event.stopPropagation();">
                      <div class="item-media-rail">
                        <div class="item-square-main">
                          ${mediaHtml}
                          <div class="item-qty-badge" hidden></div>
                        </div>
                      </div>
                      <div class="taptray-menu-copy">
                        <div class="item-head">
                          <div class="item-title">${escapeHtml(title)}</div>
                        </div>
                        ${descriptionHtml}
                      </div>
                      ${actionSquareHtml}
                    </div>


                  <div class="item-menu-wrapper">
                    <button class="menu-button"
                            onclick="toggleItemMenu(this); event.stopPropagation();">⋮</button>
                    <div class="item-menu-dropdown">
                      <div class="list-choice"
                           onclick="showTaptrayItemPreview(${surrogate}, '${token}'); event.stopPropagation();">
                        👁 Preview
                      </div>
                      <div class="list-choice"
                           onclick="addItemToListPrompt(${surrogate}, this); event.stopPropagation();">
                        ➕ ${t.add_to_list || "Add to list..."}
                      </div>
                      <div class="list-choice"
                           onclick="renameItem(${surrogate}, '${token}'); event.stopPropagation();">
                        ✏️ ${t.rename_list || "Rename item"}
                      </div>
                      <div class="list-choice"
                           onclick="shareLink('${token}', ${surrogate}); event.stopPropagation();">
                        🔗 ${t.share_item || "Share this item"}
                      </div>
                      <div class="list-choice"
                           onclick="removeItemFromList('${token}', ${surrogate}, event); event.stopPropagation();">
                        🗑 ${t.remove_from_list || "Remove from list"}
                      </div>
                      <div class="list-choice remove-choice"
                           onclick="deleteItemFromMenu(${surrogate}, event); event.stopPropagation();">
                        🧨 ${t.delete || "Delete"}
                      </div>
                    </div>
                  </div>
                </div>
              `;
            }).join("")
          : `<div class="list-sub-item text-muted">(empty)</div>`
      }
    </div>
  `;
  container.innerHTML = html;
  container.dataset.loaded = "1";

  if (typeof applyAll === "function") applyAll();
  
  // ✅ Rebind item-level drag sorting (this was lost on refresh/search)
  if (typeof initListSorting === "function") initListSorting();  

  applyListOrderMode(token);

  (items || []).forEach((item) => {
    const key = String(item?.surrogate || "").trim();
    if (key) updateTapTraySelectionButtons(key);
  });
  updateTapTrayOrderBar();

  // Cache last rendered items for offline expansion
  try {
    if (Array.isArray(items)) {
      localStorage.setItem(`last-rendered-items:${token}`, JSON.stringify(items));
    }
  } catch {}
  

     // Close when menu item clicked
     //Creates a problem when renaming, ath betur
    // container.querySelectorAll(".item-menu-dropdown .list-choice").forEach(choice =>
    //   choice.addEventListener("click", e => {
    //     const wrapper = choice.closest(".item-menu-wrapper");
    //     if (wrapper) wrapper.classList.remove("open");
    
    //     const dropdown = choice.closest(".item-menu-dropdown");
    //     if (dropdown) dropdown.style.display = "none";
    //   })
    // );


  
}

function updateListItemOrderNumbers(container, updateIndex = false) {
  if (!container) return;
  const items = container.querySelectorAll(".list-sub-item[data-value]");
  let i = 1;
  items.forEach(item => {
    const orderEl = item.querySelector(".item-order");
    if (updateIndex) {
      item.dataset.orderIndex = String(i);
      if (orderEl) orderEl.textContent = `${i}.`;
    } else {
      const idx = parseInt(item.dataset.orderIndex || "0", 10) || i;
      if (orderEl) orderEl.textContent = `${idx}.`;
    }
    i++;
  });
}

function ensureListOrderIndices(container) {
  if (!container) return;
  const items = Array.from(container.querySelectorAll(".list-sub-item[data-value]"));
  let next = 1;
  items.forEach((item) => {
    const existing = parseInt(item.dataset.orderIndex || "", 10);
    if (!Number.isFinite(existing) || existing <= 0) {
      item.dataset.orderIndex = String(next);
      const ownerEl = item.querySelector(".item-owner");
      if (ownerEl && !ownerEl.querySelector(".item-order")) {
        const orderEl = document.createElement("span");
        orderEl.className = "item-order";
        orderEl.textContent = `${next}.`;
        ownerEl.prepend(orderEl, document.createTextNode(" "));
      }
    }
    next += 1;
  });
}

function getListOrderMode(token) {
  return localStorage.getItem(`list-order-mode:${token}`) || "number";
}

function setListOrderMode(token, mode) {
  localStorage.setItem(`list-order-mode:${token}`, mode);
}

function getListOrderBubbleText(count, mode) {
  const suffix = mode === "alpha" ? "A" : "#";
  return `${count}${suffix}`;
}

function getListOrderBubbleColors(mode) {
  if (mode === "alpha") {
    return { bg: "#fff1f1", text: "#8a2a2a", border: "#efc8c8" };
  }
  return { bg: "#eef6ff", text: "#1d4f8c", border: "#c9dcf2" };
}

function updateListOrderControlsState(token, mode) {
  const controls = document.querySelector(`.list-order-toggle[data-token="${CSS.escape(token)}"]`);
  if (!controls) return;
  const count = controls.dataset.count || "0";
  const bubble = controls.querySelector(".list-order-bubble");
  if (bubble) {
    const colors = getListOrderBubbleColors(mode);
    bubble.textContent = getListOrderBubbleText(count, mode);
    bubble.style.background = colors.bg;
    bubble.style.color = colors.text;
    controls.style.borderColor = colors.border;
  }
}

function applyListOrderMode(token) {
  const mode = getListOrderMode(token);
  updateListOrderControlsState(token, mode);

  const listEl = document.getElementById(`list-${token}`);
  if (!listEl) return;

  const wrapper = listEl.querySelector(".list-items-wrapper") || listEl;
  const items = Array.from(wrapper.querySelectorAll(".list-sub-item[data-value]"));
  if (!items.length) return;
  ensureListOrderIndices(wrapper);
  if (mode === "alpha") {
    items.sort((a, b) => {
      const ta = (
        a.dataset.title ||
        a.querySelector(".item-title")?.textContent ||
        ""
      ).replace(/^\s*[•]+\s*/, "").trim().toLowerCase();
      const tb = (
        b.dataset.title ||
        b.querySelector(".item-title")?.textContent ||
        ""
      ).replace(/^\s*[•]+\s*/, "").trim().toLowerCase();
      return ta.localeCompare(tb, undefined, { numeric: true, sensitivity: "base" });
    });
  } else {
    items.sort((a, b) => {
      const na = parseInt(a.dataset.orderIndex || "0", 10);
      const nb = parseInt(b.dataset.orderIndex || "0", 10);
      return na - nb;
    });
  }

  items.forEach(item => wrapper.appendChild(item));
  updateListItemOrderNumbers(wrapper, false);
}


// Small helper so titles/usernames are safe in HTML (in case you don't already have one)
function escapeHtml(s) {
  return String(s)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

function getChatUnreadCount(token) {
  const a = Number((window.UNREAD_COUNTS || {})[token] || 0);
  const b = Number((window.unreadChatMap || {})[token] || 0);
  return Math.max(a, b, 0);
}


// =========================================================
// loadUserContentLists()
// =========================================================

function twSetFileManagerButtonVisible(visible) {
  const btn = document.querySelector('.footer-tab-btn[data-target="importTab"]');
  if (!btn) return;
  const show = !!visible && document.body.classList.contains("logged-in");
  btn.style.display = show ? "" : "none";

  if (!show && btn.classList.contains("active")) {
    window.switchTab?.("textTab");
  }
}
window.twSetFileManagerButtonVisible = twSetFileManagerButtonVisible;

function twProfileHasAdminAccess(ownerData = null) {
  if (!document.body.classList.contains("logged-in")) return false;
  if (!ownerData || typeof ownerData !== "object") return false;

  const sessionUser = String(window.SESSION_USERNAME || "").trim();
  const ownerUser = String(ownerData?.owner?.username || "").trim();
  if (sessionUser && ownerUser && sessionUser === ownerUser) return true;

  const stack = [];
  if (Array.isArray(ownerData?.owned)) stack.push(...ownerData.owned);
  if (Array.isArray(ownerData?.accessible)) stack.push(...ownerData.accessible);

  while (stack.length) {
    const node = stack.pop();
    if (!node || typeof node !== "object") continue;
    const rank = Number(node.role_rank || 0);
    if (rank >= 80) return true;
    if (Array.isArray(node.children) && node.children.length) {
      stack.push(...node.children);
    }
  }
  return false;
}

function twSetSidebarCompactMode(canManage) {
  const sidebar = document.getElementById("sidebarContainer");
  if (!sidebar) return;
  sidebar.classList.toggle("is-viewer-compact", !canManage);
}

async function loadUserContentLists() {
    
logStep("Started loading lists");

    
  const tokenOrUser = getEffectiveToken();
  // Keep profile scope tied to resolved owner, not URL list tokens.
  window.currentProfileToken = window.currentProfileToken || "";
  const listManager = document.getElementById("listManager");
  if (!listManager) return;

  const renderLists = async (data) => {
    if (!data?.owner) {
      listManager.innerHTML =
        "<div class='text-danger p-2'>Offline: owner data not cached.</div>";
      return;
    }
    const ownerToken = data.owner.username || tokenOrUser;
    window.CACHED_OWNER_LISTS = { [ownerToken]: data };
    window.currentOwnerToken = ownerToken;
    window.currentOwner = data.owner;
    window.currentProfileUsername = data.owner?.username || ownerToken;
    window.currentProfileToken = ownerToken;
    const selectedItemTitle = document.getElementById("selectedItemTitle");
    if (selectedItemTitle && (!window.currentSurrogate || window.currentSurrogate === "0")) {
      selectedItemTitle.textContent = data.owner?.display_name || ownerToken;
    }
    window.twApplyProfileAppearance?.(data.owner);
    window.twRememberLastProfile?.(window.currentProfileUsername);
    window.twOnPlayScopeChanged?.(ownerToken);
    const canManageProfile = twProfileHasAdminAccess(data);
    twSetFileManagerButtonVisible(canManageProfile);
    twSetSidebarCompactMode(canManageProfile);
    updateHomeDropdownCurrentProfile(data.owner, ownerToken);
    updateHomeRecentProfiles(data.owner, ownerToken);
    window.twFetchPlayOwnerStatus?.();
    listManager.innerHTML = "";

    if (data.owned?.length) {
      await renderOwnerLists(ownerToken, data.owned, {
        label: `Lists by: ${data.owner?.display_name || ownerToken}`,
        groupId: `owned-${ownerToken}`,
        section: "owned",
        icon: "👑",
        avatarUrl: twResolveAvatarUrl(data.owner, data.owner?.display_name || ownerToken),
        ownerDisplayName: data.owner?.display_name || ownerToken,
        showOwnerMeta: true
      });
    }

    const acc = Array.isArray(data.accessible) ? data.accessible : [];
    const invitedRoot  = acc.find(l => l.relationship === "invited_group");
    const followedRoot = acc.find(l => l.relationship === "followed_group");

    if (invitedRoot && invitedRoot.children?.length) {
      await renderOwnerLists(`invited-${ownerToken}`, invitedRoot.children, {
        label: window.translations?.my_groups || "💬 My Groups",
        groupId: "invited-lists",
        section: "invited",
        icon: "💬"
      });
    }

    if (followedRoot && followedRoot.children?.length) {
      await renderOwnerLists(`followed-${ownerToken}`, followedRoot.children, {
        label: `⭐ ${window.translations?.followed_lists || "Followed lists"}`,
        groupId: "followed-lists",
        section: "followed",
        icon: "⭐"
      });
    }

    initListSorting?.();
    bindSidebarSearch();
    if (window.currentSurrogate && String(window.currentSurrogate) !== "0") {
      applyDeepLink();
    }
  };

  listManager.innerHTML = "<div class='text-muted p-2'>Loading lists…</div>";

  const listsUrl = `/getOwnersListsJSON.php?token=${encodeURIComponent(tokenOrUser)}`;
  const stableKey = `/offline/owners/${encodeURIComponent(tokenOrUser)}.json`;
  const backgroundOk =
    navigator.onLine &&
    (typeof window.twBackgroundNetworkOk === "function" ? window.twBackgroundNetworkOk() : true);

  if (!backgroundOk) {
    try {
      let cachedRes = null;
      if ("caches" in window) {
        cachedRes = await caches.match(stableKey);
      }
      if (cachedRes && cachedRes.ok) {
        const cachedData = await cachedRes.json();
        if (cachedData && (cachedData.owner || cachedData.owned || cachedData.accessible)) {
          await renderLists(cachedData);
          logStep("Finished loading lists");
          return;
        }
      }
    } catch {}
  }

  try {
    const fetchOwnerLists = async (url) => {
      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), 2600);
      try {
        const res = await fetch(url, { cache: "no-store", signal: controller.signal });
        return res.json();
      } finally {
        clearTimeout(timeout);
      }
    };
    const hasUsablePayload = (data) => {
      const hasLists =
        (Array.isArray(data?.owned) && data.owned.length > 0) ||
        (Array.isArray(data?.accessible) && data.accessible.length > 0);
      return !!data?.owner || hasLists;
    };

    let data = await fetchOwnerLists(listsUrl);
    if (!hasUsablePayload(data) && navigator.onLine) {
      await new Promise(r => setTimeout(r, 220));
      const retryUrl = `${listsUrl}&retry=${Date.now()}`;
      data = await fetchOwnerLists(retryUrl);
    }
    if (!hasUsablePayload(data)) {
      throw new Error("Empty list payload");
    }

    // Hard-fix: always persist under stable key on successful load
    try {
      if ("caches" in window) {
        const cache = await caches.open("textwhisper-cache-manual");
        const body = JSON.stringify(data);
        await cache.put(stableKey, new Response(body, {
          headers: { "Content-Type": "application/json" }
        }));

        const ownerUsername = data?.owner?.username;
        if (ownerUsername && ownerUsername !== tokenOrUser) {
          const ownerKey = `/offline/owners/${encodeURIComponent(ownerUsername)}.json`;
          await cache.put(ownerKey, new Response(body, {
            headers: { "Content-Type": "application/json" }
          }));
        }
      }
    } catch {}

    await renderLists(data);

    const isTapTrayShell = document.body?.dataset?.appMode === "taptray";
    const isLoggedIn = document.body.classList.contains("logged-in");
    if (!isTapTrayShell || isLoggedIn) {
      setTimeout(() => {
        warmListItemsCacheForOwnerData(data);
      }, 60);
    }
  } catch (err) {
    console.error("❌ loadUserContentLists failed:", err);
    try {
      const owner = window.twGetRememberedLastProfile?.() || "";
      if (owner && "caches" in window) {
        const key = `/offline/owners/${encodeURIComponent(owner)}.json`;
        const res = await caches.match(key);
        if (res && res.ok) {
          const data = await res.json();
          if (data?.owner) {
            await renderLists(data);
            logStep("Finished loading lists");
            return;
          }
        }
      }
    } catch {}

    // Last resort for offline startup after logout/session timeout:
    // show lists explicitly marked for offline use.
    if (!navigator.onLine && typeof window.loadAllOfflineLists === "function") {
      try {
        await window.loadAllOfflineLists();
        if (typeof showFlashMessage === "function") {
          showFlashMessage("Offline mode: loaded saved lists.");
        }
        return;
      } catch (offlineErr) {
        console.warn("⚠️ loadAllOfflineLists fallback failed:", offlineErr);
      }
    }

    if (typeof showFlashMessage === "function") {
      showFlashMessage("Offline content unavailable.");
    }
    listManager.innerHTML =
      "<div class='text-danger p-2'>Offline: list data not cached yet.</div>";
  }
  
  // // HOME VIEW — only once
  // if (window.currentSurrogate === "" || window.currentSurrogate == null) {
  //   const owner = window.currentOwner;
  //
  //   if (owner?.home_mode === "page" && owner.home_page) {
  //     window.showHomeTab(owner.home_page);
  //   }
  // }

  // HOME VIEW — only when NO surrogate is selected
  if (!window.currentSurrogate || window.currentSurrogate === "0") {
    const owner = window.currentOwner;
    const isTapTrayShell = document.body?.dataset?.appMode === "taptray";
    if (owner && !isTapTrayShell) {
      const isLoggedIn = !!window.SESSION_USERNAME;
      const ownerToken = window.currentOwnerToken || window.currentListToken || "";
      const homeUrl = ownerToken
        ? `/TW_Home.php?owner=${encodeURIComponent(ownerToken)}`
        : "/TW_Home.php";
      const eventUrl = ownerToken
        ? `/ep_event_planner.php?owner=${encodeURIComponent(ownerToken)}`
        : "/ep_event_planner.php";
      window.showHomeTab(isLoggedIn ? eventUrl : homeUrl);
    }
  }

  logStep("Finished loading lists");
}





// =========================================================
// renderOwnerLists(ownerToken, lists, opts)
// Renders a *section* (owner / invited / followed)
// =========================================================
window.renderOwnerLists = async function (ownerToken, lists, opts = {}) {
  const {
    label = "",
    groupId = `owned-${ownerToken}`,
    section = "owned",          // passed in by loader
    icon = "",
    avatarUrl = "",
    ownerDisplayName = ownerToken,
    showOwnerMeta = false,
  } = opts;
  const t = window.translations || {};
  const importLabel = t.import || "Import";
  const importFromDeviceLabel = t.import_from_device || "this device";
  const resolvedAvatarUrl = twResolveAvatarUrl(
    { avatar_url: avatarUrl, username: ownerToken, display_name: ownerDisplayName },
    ownerDisplayName
  );
  const ownerAppearance = window.currentOwner?.appearance || {};
  const greetingText = String(ownerAppearance.greeting_text || "").trim();
  const effectiveGreeting = greetingText || ownerDisplayName;

  const listManager = document.getElementById("listManager");
  if (!listManager) return;

  const wrapper = document.createElement("div");
  wrapper.className = "list-group-wrapper";
  wrapper.dataset.group = groupId;
  wrapper.dataset.owner = ownerToken;
  wrapper.dataset.section = section; // 🟢 stamp here too

  const headerInner = showOwnerMeta
    ? `
      <img src="${resolvedAvatarUrl}" alt="avatar" class="list-owner-avatar"
           data-avatar-seed="${escapeHtml(ownerToken)}"
           data-avatar-name="${escapeHtml(ownerDisplayName)}"
           onerror="twHandleAvatarError(this)" />
      <div class="list-owner-text">
        <div class="list-label">${escapeHtml(effectiveGreeting)}</div>
        <div class="list-name">${escapeHtml(ownerDisplayName)}</div>
      </div>`
    : `<span class="list-name">${escapeHtml(label || "")}</span>`;

    // ✅ Build owner / group header (with optional right-aligned menu button)
wrapper.innerHTML = `
  <div class="sidebar-section-header collapsible-group"
       style="display:flex; align-items:center; justify-content:space-between;">

    <div style="display:flex; align-items:center; gap:6px;">
      <span class="group-arrow">▶</span>
      ${headerInner}
    </div>

  ${
    showOwnerMeta
      ? `
      <div class="menu" style="display:flex; align-items:center; gap:6px;">

        <span id="chat-unread-${ownerToken}"
              class="chat-inline-badge${getChatUnreadCount(ownerToken) > 0 ? " unread" : ""}"
              onclick="event.stopPropagation(); openChatFromMenu('${ownerToken}')"
              title="${t.general_chat || "General Chat"}">
          ${getChatUnreadCount(ownerToken)}
        </span>

        <button class="menu-button"
                onclick="toggleMenu(this); event.stopPropagation();">⋮</button>

        <div class="menu-dropdown">

          <div class="menu-item"
              onclick="closeAllMenus(); createNewList('owned-${ownerToken}'); event.stopPropagation();">
            🆕 ${t.create_new_list || "Create New List"}
          </div>

          <div class="menu-item"
              onclick="closeAllMenus(); shareGroup('owned-${ownerToken}'); event.stopPropagation();">
            🔗 ${t.share_group || "Share Group"}
          </div>

          <div class="menu-item"
              onclick="closeAllMenus(); openImportItemFilesModal(); event.stopPropagation();">
            📁 ${importLabel}: ${importFromDeviceLabel}
          </div>

          <div class="menu-item"
              onclick="closeAllMenus(); openDriveImportOverlay('tw'); event.stopPropagation();">
            <img src="/img/wrt.png"
                style="height:18px; vertical-align:middle; margin-right:6px;"> 
            ${importLabel}: TapTray
          </div>        

          <div class="menu-item"
              onclick="closeAllMenus(); openDriveImportOverlay('dropbox'); event.stopPropagation();">
            <img src="/icons/dropbox_0061ff.svg"
                style="height:18px; vertical-align:middle; margin-right:6px;"> 
            ${importLabel}: Dropbox
          </div>

          <div class="menu-item"
              onclick="closeAllMenus(); openDriveImportOverlay('google'); event.stopPropagation();">
            <img src="/icons/googledrive.png"
                style="height:14px; vertical-align:middle; margin-right:6px;">
            ${importLabel}: Google Drive
          </div>

          <div class="menu-item"
              onclick="closeAllMenus(); openDriveImportOverlay('onedrive'); event.stopPropagation();">
            <img src="/icons/onedrive2.png"
                style="height:18px; vertical-align:middle; margin-right:6px;"> 
            ${importLabel}: OneDrive
          </div>

          <div class="menu-item"
              onclick="closeAllMenus(); openDriveImportOverlay('icloud'); event.stopPropagation();">
            <img src="/icons/icloud2.png"
                style="height:12px; vertical-align:middle; margin-right:6px;"> 
            ${importLabel}: iCloud
          </div>                   

        </div>
      </div>
      `
      : ``
  }

  </div>

  <div class="group-contents"
       id="lists-by-${groupId}"
       data-section="${section}"
       style="display:none;"></div>
`;




  // const container = wrapper.querySelector(`#lists-by-${groupId}`);

  const container = wrapper.querySelector(`#lists-by-${CSS.escape(groupId)}`);

  listManager.appendChild(wrapper);

  // 🔗 Bind menu button lazily (no pre-rendered dropdown)
//   const menuButton = wrapper.querySelector(".menu-button");
//   if (menuButton) {
//     menuButton.addEventListener("click", e => {
//       e.stopPropagation();
//       // At the owner header level, pass the ownerToken as both the "list token" and the owner
//       generateListMenu(e, ownerToken, ownerToken, 90);
//     });
//   }

  // Render lists (propagate section)
//   container.innerHTML = "";
//   (Array.isArray(lists) ? lists : []).forEach(list =>
//     container.appendChild(window.renderList(list, 0, section))
//   );
  
  // Render lists (propagate section)
  (Array.isArray(lists) ? lists : []).forEach(list => {
      if (section === "invited" && list.relationship === "inviter_group") {
        container.appendChild(renderOwnerRow(list, section));
      } else {
        container.appendChild(window.renderList(list, 0, section));
      }
  });
  

  const header = wrapper.querySelector(".sidebar-section-header");
  const arrow  = header.querySelector(".group-arrow");

  header.addEventListener("click", (e) => {
    if (e.target.closest(".menu-button")) return;
    
    const isOpen = container.style.display === "block";
    const nowOpen = !isOpen;
    
    container.style.display = nowOpen ? "block" : "none";
    if (arrow) arrow.textContent = nowOpen ? "▼" : "▶";
    
    // ✅ save expanded/collapsed state per section
    localStorage.setItem(`group-expanded:${ownerToken}:${section}`, nowOpen ? "true" : "false");
  });
  
  
    // ✅ Restore expanded/collapsed state for this top group
    const key = `group-expanded:${ownerToken}:${section}`;
    const saved = localStorage.getItem(key);
    
    if (section === "owned" || saved === "true") {
      container.style.display = "block";
      const arrow = wrapper.querySelector(".group-arrow");
      if (arrow) arrow.textContent = "▼";
    } else if (saved === null) {
      // First visit fallback
      if (section === "owned") {
        container.style.display = "block";
        const arrow = wrapper.querySelector(".group-arrow");
        if (arrow) arrow.textContent = "▼";
      }
    }
  

  refreshInlineChatBadges?.();
};




// -------------------------
// Toggle unified or item menus
// -------------------------


// --- universal toggle ---
function toggleMenu(button) {
  const dropdown = button.nextElementSibling;
  if (!dropdown) return;

  // Close all *other* menus first
   closeAllMenus(button);

  // Toggle current one
  const isOpen = dropdown.style.display === "block";
  dropdown.style.display = isOpen ? "none" : "block";
}



function toggleItemMenu(button) {
  const dropdown = button.nextElementSibling;
  const wrapper = button.closest(".item-menu-wrapper");
  const item = button.closest(".list-sub-item");
  const wasPortaled = dropdown?.dataset?.portaled === "1";

  // close all other menus (owner, list, and item) + clear open states
  closeAllMenus(button);

  // toggle this one
  const willOpen = dropdown.style.display !== "block";
  dropdown.style.display = willOpen ? "block" : "none";
  if (wrapper) wrapper.classList.toggle("open", willOpen);
  if (item) item.classList.toggle("menu-open", willOpen);

  if (dropdown && willOpen) {
    if (!wasPortaled) {
      if (wrapper && !wrapper.dataset.menuPortalId) {
        wrapper.dataset.menuPortalId = `menu-portal-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
        wrapper.id = wrapper.dataset.menuPortalId;
      }
      dropdown.dataset.portalParentId = wrapper?.dataset?.menuPortalId || "";
      dropdown.dataset.portalParentSelector = ".item-menu-wrapper";
      dropdown.dataset.portaled = "1";
      dropdown.classList.add("menu-portal");
      document.body.appendChild(dropdown);
    }
    const rect = button.getBoundingClientRect();
    dropdown.style.position = "fixed";
    dropdown.style.right = "auto";
    dropdown.style.width = "auto";
    dropdown.style.maxWidth = "260px";
    const ddRect = dropdown.getBoundingClientRect();
    const width = ddRect.width || dropdown.offsetWidth || 160;
    const minLeft = 8;
    const maxLeft = Math.max(minLeft, window.innerWidth - width - 8);
    const left = Math.min(maxLeft, Math.max(minLeft, rect.right - width));
    dropdown.style.left = `${Math.round(left)}px`;
    dropdown.style.top = `${Math.round(rect.bottom + 4)}px`;
  } else if (dropdown && !willOpen && wasPortaled) {
    const parentId = dropdown.dataset.portalParentId;
    const parent = parentId ? document.getElementById(parentId) : button.closest(".item-menu-wrapper");
    if (parent) parent.appendChild(dropdown);
    dropdown.classList.remove("menu-portal");
    dropdown.style.position = "";
    dropdown.style.left = "";
    dropdown.style.top = "";
    dropdown.style.right = "";
    dropdown.style.width = "";
    dropdown.style.maxWidth = "";
    dropdown.dataset.portaled = "0";
  }
}


// --- close one or all ---
function closeMenu(el) {
//   const dd = el.closest(".menu-dropdown");
//   if (dd) dd.style.display = "none";
}



function closeAllMenus(exceptEl = null) {
  document.querySelectorAll(".menu-dropdown, .item-menu-dropdown").forEach(m => {
    if (exceptEl && m.previousElementSibling === exceptEl) return;

    if (m.style.display === "block") m.style.display = "none";

    m.closest(".menu, .list-menu-wrapper")?.classList.remove("open");
    m.closest(".item-menu-wrapper")?.classList.remove("open");
    m.closest(".group-item")?.classList.remove("menu-open");
    m.closest(".list-sub-item")?.classList.remove("menu-open");

    if (m.classList.contains("menu-portal")) {
      const parentId = m.dataset.portalParentId;
      const parent = parentId ? document.getElementById(parentId) : null;
      if (parent) {
        parent.appendChild(m);
        parent.classList.remove("open");
        parent.closest(".list-sub-item")?.classList.remove("menu-open");
      }
      m.classList.remove("menu-portal");
      m.style.position = "";
      m.style.left = "";
      m.style.top = "";
      m.style.right = "";
      m.style.width = "";
      m.style.maxWidth = "";
      m.dataset.portaled = "0";
    }
  });

  // hard reset any lingering open state
  document.querySelectorAll(".list-sub-item.menu-open").forEach(el => el.classList.remove("menu-open"));
  document.querySelectorAll(".item-menu-wrapper.open").forEach(el => el.classList.remove("open"));
}



// --- universal outside-click handler ---
document.addEventListener("click", (e) => {
  // ignore clicks inside any menu or its dropdown
  if (e.target.closest(".menu, .menu-dropdown, .item-menu-dropdown")) return;
  closeAllMenus();
});




function renderOwnerRow(list, section = "invited") {
  const token = list.token;
  const ownerName = list.owner_display_name || list.owner_username || "Unknown";
  const ownerId = list.owner_id || "";
  const ownerAvatar = twResolveAvatarUrl(
    {
      avatar_url: list.owner_avatar_url,
      username: list.owner_username,
      display_name: ownerName,
      id: ownerId
    },
    ownerName
  );
  const children = list.children || [];
  const roleRank = list.role_rank || 0;

  // 🔹 unread chat count
  const unread = getChatUnreadCount(token);
  const chatBadge = `
    <span id="chat-unread-${token}"
          class="chat-inline-badge${unread > 0 ? " unread" : ""}"
          onclick="event.stopPropagation(); openChatFromMenu('${token}')"
          title="Open chat">${unread}</span>
  `;

  const node = document.createElement("div");
  node.className = `list-group-item group-item ${section}-owner`;
  node.dataset.group = token;
  node.dataset.owner = ownerId;
  node.dataset.section = section;
  node.dataset.roleRank = roleRank;
  node.dataset.access = list.access; // ✅ add this

    node.innerHTML = `
      <div class="list-header-row">
        <span class="arrow">▶</span>
    
        <div style="flex:1; display:flex; align-items:center; gap:8px;">
          <img src="${ownerAvatar}"
               alt="${escapeHtml(ownerName)}"
               class="list-owner-avatar"
               data-avatar-seed="${escapeHtml(String(ownerId || ownerName))}"
               data-avatar-name="${escapeHtml(ownerName)}"
               onerror="twHandleAvatarError(this)" />
          <span class="list-title" id="list-title-${token}">
            ${escapeHtml(ownerName)}
          </span>
        </div>
    
        <div class="list-menu-wrapper">
          ${chatBadge}
          <button class="menu-button"
                  data-token="${token}"
                  data-owner="${ownerId}"
                  title="Options">⋮</button>
        </div>
      </div>
    
      <div class="group-contents"
           data-token="${token}"
           data-section="${section}"
           style="display:none;"></div>
    `;


  // 🔗 menu click → owner menu logic
  const menuButton = node.querySelector(".menu-button");
  if (menuButton) {
    menuButton.addEventListener("click", e => {
      e.stopPropagation();
      generateInnviterMenu(token, ownerId, roleRank, e);
    });
  }

  // 🔽 Expand/collapse logic with localStorage
  const header = node.querySelector(".list-header-row");
  const arrowEl = node.querySelector(".arrow");
  const groupContents = node.querySelector(".group-contents");

  const stateKey = `invited-owner-expanded:${token}`;
  const savedState = localStorage.getItem(stateKey);
  const startOpen = savedState === "true"; // remember user preference
  groupContents.style.display = startOpen ? "block" : "none";
  arrowEl.textContent = startOpen ? "▼" : "▶";

//   header.addEventListener("click", e => {
//     if (e.target.closest(".menu-button")) return;
//     e.stopPropagation();

//     const isOpen = groupContents.style.display === "block";
//     const newDisplay = isOpen ? "none" : "block";
//     groupContents.style.display = newDisplay;
//     arrowEl.textContent = isOpen ? "▶" : "▼";
//     localStorage.setItem(stateKey, String(!isOpen));
//   });
  
  
  //Now, instead of expanding the owners lists, 
  //we update te url and go to the owners profile
  header.addEventListener("click", e => {
    if (e.target.closest(".menu-button")) return;
    e.stopPropagation();
    localStorage.setItem(stateKey, "true");

    // 🔑 INVITED OWNER GROUP → go directly to owner profile
    viewOwnerProfile(token);
  });
  

  // 🔹 Render invited lists (children)
  if (children.length) {
    children.forEach(child => {
      const childNode = window.renderList(child, 1, section);
      if (childNode) groupContents.appendChild(childNode);
    });
  }

  return node;
}




function generateInnviterMenu(token, ownerId = null, roleRank = 0, event) {
  event.stopPropagation();

  // 🔹 Use same dropdown builder for consistent placement & style
  toggleListMenu(event, token, ownerId, roleRank);

  const wrapper = event.currentTarget.closest(".list-menu-wrapper");
  const dropdown = wrapper?.querySelector(".list-menu-dropdown");
  if (!dropdown) return;

  // 🧩 Two key actions only
  dropdown.innerHTML = `
    <div class="list-choice" onclick="openChatFromMenu('${token}'); event.stopPropagation();">
      💬 ${window.translations?.general_chat || "General Chat"}
    </div>
    <div class="list-choice" onclick="shareGroup('${token}'); event.stopPropagation();">
      🔗 ${window.translations?.share_group || "Share Group"}
    </div>
    <div class="list-choice" onclick="viewOwnerProfile('${token}'); event.stopPropagation();">
      👤 ${window.translations?.view_owner_profile || "View Owner Profile"}
    </div>
  `;

  dropdown.querySelectorAll(".list-choice").forEach(choice =>
    choice.addEventListener("click", () => wrapper.classList.remove("open"))
  );
}

function viewOwnerProfile(token) {
  // normalize
  if (token.startsWith("invited-")) {
    token = token.substring("invited-".length);
  }

  window.currentProfileUsername = token;
  window.currentProfileToken = token;
  window.twRememberLastProfile?.(token);
  window.twOnPlayScopeChanged?.(token);

  console.log(`👤 Viewing owner profile: ${token}`);

  // update the URL
  window.history.pushState({}, "", `/${token}`);
  // Profile root means no item is selected.
  window.currentSurrogate = "";

  // ensure we switch to the Lists tab
  const tab = document.querySelector(".tab-link[data-target='listsTab']");
  if (tab) {
    tab.click();
  }

  // 🚀 ensure loadUserContentLists runs after tab switch
  if (typeof loadUserContentLists === "function") {
    setTimeout(() => loadUserContentLists(), 50);
  } else {
    location.href = `/${token}`; // fallback: hard reload
  }
  setTimeout(() => {
    const kitchenFrame = document.querySelector('#homeTabContent iframe[src^="/menu_orders.php"]');
    if (!kitchenFrame) return;
    window.showHomeTab?.("/menu_orders.php");
  }, 80);
}


function collectListTokensRecursive(lists, out = []) {
  if (!Array.isArray(lists)) return out;
  for (const list of lists) {
    if (list?.token) out.push(list.token);
    if (Array.isArray(list?.children) && list.children.length) {
      collectListTokensRecursive(list.children, out);
    }
  }
  return out;
}

function getOfflineListItemsCacheKey(token) {
  return `/offline/list-items/${encodeURIComponent(token)}.html`;
}

async function getCachedListItemsHtml(token) {
  try {
    if (!token || !("caches" in window)) return "";
    const cache = await caches.open("textwhisper-cache-manual");
    const cached = await cache.match(getOfflineListItemsCacheKey(token));
    if (!cached || !cached.ok) return "";
    return await cached.text();
  } catch {
    return "";
  }
}

async function fetchAndCacheListItemsHtml(token) {
  try {
    if (!token || !navigator.onLine || !("caches" in window)) return "";
    const res = await fetch(`/getListItems.php?list=${encodeURIComponent(token)}`, {
      credentials: "include"
    });
    if (!res.ok) return "";
    const html = await res.text();
    if (!html || !html.trim()) return "";
    const cache = await caches.open("textwhisper-cache-manual");
    await cache.put(
      getOfflineListItemsCacheKey(token),
      new Response(html, { headers: { "Content-Type": "text/html" } })
    );
    return html;
  } catch {
    return "";
  }
}

async function warmListItemsCacheForOwnerData(data, maxLists = 24) {
  try {
    if (!navigator.onLine || !("caches" in window) || !data) return;
    const tokens = [
      ...collectListTokensRecursive(data.owned || []),
      ...collectListTokensRecursive(data.accessible || [])
    ];
    const uniqueTokens = Array.from(new Set(tokens)).slice(0, maxLists);
    for (const token of uniqueTokens) {
      const cached = await getCachedListItemsHtml(token);
      if (cached) continue;
      await fetchAndCacheListItemsHtml(token);
    }
  } catch {}
}




// =========================================================
// renderList(list) – single list + nested children
// (includes chat badge + lazy loading items)
// =========================================================
// renderList(list, level=0, section='owned')
window.renderList = function renderList(list, level = 0, section = "owned") {
  const listToken    = list.token;
  const isAllContent = !!(listToken && list.owner_username &&
    listToken.trim().toLowerCase() === list.owner_username.trim().toLowerCase());
  const name = (isAllContent && list.owner_display_name)
    ? list.owner_display_name
    : list.title;
  const count    = list.item_count || 0;
  const ownerId  = list.owner_id;
  const children = list.children || [];
  const items    = list.items || [];
  const roleRank = list.role_rank || 0;
  const isOffline = (JSON.parse(localStorage.getItem("offline-enabled-lists") || "[]")).includes(listToken);
  const orderMode = getListOrderMode(listToken);
  const orderColors = getListOrderBubbleColors(orderMode);


  const autoExpand = list._hasMatch === true;
  const ownerUsername =
    list.owner_username ||
    list.owner ||
    list.owner_token ||
    window.currentOwner?.username ||
    window.currentProfileUsername ||
    "";
  const isOwnerRoot =
    !!(listToken && ownerUsername && listToken.trim().toLowerCase() === ownerUsername.trim().toLowerCase());
  const isExpanded = !isOwnerRoot && (
    autoExpand ||
    listToken === window.expandToken ||
    (section !== "invited" && list.expanded)
  );
  const arrow      = isExpanded ? "▼" : "▶";
  const display    = isExpanded ? "block" : "none";

  const node = document.createElement("div");
  node.className = `list-group-item group-item ${section}-list${isExpanded ? " active-list" : ""}`;
  const listIndentPx = Math.min(Math.max(level, 0), 6) * 6;
  node.style.setProperty("--list-header-indent", `${listIndentPx}px`);
  node.dataset.group = listToken;
  node.dataset.owner = ownerId;
  node.dataset.ownerUsername = list.owner_username || window.currentOwner?.username || window.currentProfileUsername || "";
  node.dataset.ownerDisplayName = list.owner_display_name || list.owner_username || "";
  node.dataset.section = section;           
  node.dataset.roleRank = roleRank; // 🟢 stamp role rank for generateListMenu
  node.dataset.access = list.access; // ✅ add this

  const unread = getChatUnreadCount(listToken);
  const chatBadge = `<span id="chat-unread-${listToken}" class="chat-inline-badge${unread>0?" unread":""}"
                        onclick="event.stopPropagation(); openChatFromMenu('${listToken}')"
                        title="Open chat">${unread}</span>`;

  const ownerLabel = list.owner_display_name || list.owner_username || "";
  const showOwnerLine = section !== "owned" && ownerLabel;

  node.innerHTML = `
    <div class="list-header-row" style="display:flex; align-items:center;">
      <span class="arrow">${arrow}</span>
  
      <div style="flex:1; display:flex; flex-direction:column;">

        <span class="list-title ${isOffline ? 'offline-flagged' : ''}" id="list-title-${listToken}">
          ${escapeHtml(name)}
        </span>        
                
        ${showOwnerLine
          ? `<span class="list-owner" style="font-size:11px; color:#aaa;">
               ${escapeHtml(ownerLabel)}
             </span>`
          : ""}

      </div>
  
      <span class="list-order-toggle"
            data-token="${listToken}"
            data-count="${count}"
            style="display:inline-flex; border:1px solid ${orderColors.border}; border-radius:999px; overflow:hidden; margin-left:6px; align-items:center; font-size:12px;">
        <button type="button" class="list-order-bubble"
                title="Sort in concert order # or A-Z"
                style="padding:3px 4px; font-size:10px; border:0; background:${orderColors.bg}; color:${orderColors.text}; cursor:pointer; line-height:1;">
          ${getListOrderBubbleText(count, orderMode)}
        </button>
      </span>
      ${chatBadge}
  
      <div class="list-menu-wrapper">
        <button class="menu-button" data-token="${listToken}" data-owner="${ownerId}">⋮</button>
      </div>
    </div>
  
    <div class="group-contents" data-token="${listToken}" data-section="${section}"
         style="display:${display};"></div>
    <div class="list-contents" id="list-${listToken}" data-token="${listToken}" data-owner="${ownerId}"
         style="display:${display};"></div>
  `;

  // 🔗 Attach menu button click handler lazily
  const menuButton = node.querySelector(".menu-button");
  if (menuButton) {
    menuButton.addEventListener("click", e => {
      e.stopPropagation();
      toggleListMenu(e, listToken, ownerId, roleRank);
    });
  }

  // ORDER MODE TOGGLE (bubble)
  const orderToggle = node.querySelector(".list-order-toggle");
  if (orderToggle) {
    orderToggle.addEventListener("click", (e) => {
      const bubble = e.target.closest(".list-order-bubble");
      if (!bubble) return;
      e.stopPropagation();
      const current = getListOrderMode(listToken);
      const next = current === "alpha" ? "number" : "alpha";
      setListOrderMode(listToken, next);
      applyListOrderMode(listToken);
    });
  }


  // Toggle expand/collapse
  const header = node.querySelector(".list-header-row");
  const arrowEl = node.querySelector(".arrow");
  const groupContents = node.querySelector(".group-contents");
  const listContents  = node.querySelector(".list-contents");

  header.addEventListener("click", async (e) => {
    if (e.target.closest(".list-order-toggle")) return; // don't toggle when order bubble clicked
    if (e.target.closest(".menu-button")) return; // don’t toggle when menu clicked
    e.stopPropagation();
    const isOpen = groupContents.style.display === "block";
    const newDisplay = section === "invited" ? "block" : (isOpen ? "none" : "block");
    const nowOpen = newDisplay === "block";
    groupContents.style.display = newDisplay;
    listContents.style.display  = newDisplay;
    arrowEl.textContent = nowOpen ? "▼" : "▶";
    // Publish list open/collapse immediately (before async loading below).
    window.twPublishPlayListSwitch?.(listToken, nowOpen);

    // In "My Groups", always keep opened group at the top.
    if (nowOpen && section === "invited" && node.parentElement) {
      groupContents.style.display = "block";
      listContents.style.display = "block";
      arrowEl.textContent = "▼";
      node.parentElement.prepend(node);
      node.scrollIntoView({ block: "start", behavior: "smooth" });
      requestAnimationFrame(() => {
        groupContents.style.display = "block";
        listContents.style.display = "block";
        arrowEl.textContent = "▼";
      });
    }

    

    if (nowOpen && !listContents.dataset.loaded && !list._hasMatch) {
      const ownerData = window.CACHED_OWNER_LISTS?.[window.currentOwnerToken];
      const allLists = [...(ownerData?.owned || []), ...(ownerData?.accessible || [])];
    
      const target = findListByToken(allLists, listToken);
      if (target && Array.isArray(target.items)) {
        if (target.items.length) {
          renderListItems(listContents, listToken, target.items);
        } else if (Array.isArray(target.children) && target.children.length) {
          listContents.innerHTML = "";
          listContents.dataset.loaded = "1";
          listContents.dataset.jsonRendered = "1";
        } else {
          renderListItems(listContents, listToken, target.items);
        }
      } else {
        let html = await getCachedListItemsHtml(listToken);
        if (!html && navigator.onLine) {
          html = await fetchAndCacheListItemsHtml(listToken);
        }

        if (html) {
          listContents.innerHTML = html;
          listContents.dataset.loaded = "1";
          listContents.dataset.jsonRendered = "0";
          initListSorting?.();
        } else {
          listContents.innerHTML = navigator.onLine
            ? `<div class='text-muted p-2'>(empty)</div>`
            : `<div class='text-muted p-2'>Offline: list items not cached yet.</div>`;
        }
      }
    }
    
  window.currentListToken = listToken;
  window.currentListTitle = list.title || "";
  window.currentListOwnerUsername =
    list.owner_username ||
    node.dataset.ownerUsername ||
    window.currentProfileUsername ||
    window.currentOwner?.username ||
    "";
  window.currentListOwnerName = list.owner_display_name
    || window.currentOwner?.display_name
    || window.currentOwner?.username
    || window.currentListOwnerUsername;
  const titleEl = document.getElementById("selectedItemTitle");
  if (titleEl) {
    const isOwnerList = window.currentListOwnerUsername
      && listToken.toLowerCase() === window.currentListOwnerUsername.toLowerCase();
    const header = isOwnerList
      ? window.currentListOwnerName
      : [window.currentListOwnerName, window.currentListTitle].filter(Boolean).join(" - ");
    titleEl.textContent = header || (window.translations?.select_an_item || "Select an item");
  }
  const currentSurrogate = window.currentSurrogate || "0";
//   history.replaceState({}, "", isOpen ? "/" : `/${token}/${currentSurrogate}`);
  // ✅ refined URL update (don’t depend on expand/collapse)
      const newUrl =
        currentSurrogate && currentSurrogate !== "0"
          ? `/${listToken}/${currentSurrogate}`
          : `/${listToken}`;
      if (window.location.pathname !== newUrl) {
        history.replaceState({}, "", newUrl);
      }  
  try {
    localStorage.setItem("last-selected-list-token", listToken || "");
    if (window.currentListOwnerUsername) {
      window.twRememberLastProfile?.(window.currentListOwnerUsername);
    }
  } catch {}
    
  });

  // Auto-expand if search match brought it in
//   if (autoExpand && Array.isArray(items) && items.length && !listContents.dataset.loaded) {
//     renderListItems(listContents, token, items);
//   }
  
     // Auto-render items if offline or matched in search
    if ((autoExpand || list.itemsLoadedOffline) && Array.isArray(items) && items.length && !listContents.dataset.loaded) {
      renderListItems(listContents, listToken, items);
    }
 

  // Render children recursively
  if (children.length) {
    children.forEach(child => {
      const childNode = window.renderList(child, level + 1, section); 
      if (childNode) groupContents.appendChild(childNode);
    });
  }

  return node;
};




function findListByToken(lists, token) {
  for (const list of lists) {
    if (list.token === token) return list;
    if (list.children?.length) {
      const found = findListByToken(list.children, token);
      if (found) return found;
    }
  }
  return null;
}



// =========================================================
// Chat badge refresher – run after renders/toggles
// =========================================================
// function refreshInlineChatBadgesXXX() {
//   const counts = window.UNREAD_COUNTS || {};
//   document.querySelectorAll(".list-group-item").forEach(node => {
//     const token = node.dataset.group;
//     const el = node.querySelector(`#chat-unread-${token}`);
//     if (!el) return;

//     const n = parseInt(counts[token] || 0, 10);
//     el.textContent = isNaN(n) ? 0 : n;
//     el.classList.toggle("muted", !n);
//     el.style.display = "inline-flex";
//   });
// }

function refreshInlineChatBadges() {
  const counts = window.UNREAD_COUNTS || window.unreadChatMap || {};

  // ✅ include list rows AND top section headers (Lists by...)
  document.querySelectorAll(".list-group-item, .sidebar-section-header").forEach(node => {
    // token may be on dataset (list rows), but top header badge id is still chat-unread-${ownerToken}
    const token = node.dataset.group || node.closest(".list-group-wrapper")?.dataset?.owner;
    if (!token) return;

    const el = node.querySelector(`#chat-unread-${CSS.escape(token)}`);
    if (!el) return;

    const n = parseInt(counts[token] || 0, 10);
    el.textContent = isNaN(n) ? 0 : n;
    el.classList.toggle("unread", n > 0);
    el.style.display = "inline-flex";
  });
}
// 


//helper to refresh a single list
async function refreshListUI(token, { focusSurrogate = null } = {}) {
  const container = document.getElementById(`list-${token}`);
  if (!container) return;

  // Detect which renderer this list is using
  const isJSONRendered =
    !!container.querySelector(".list-items-wrapper") ||
    container.dataset.jsonRendered === "1";

  try {
    if (isJSONRendered) {
      // Re-render via JSON
      const data = await fetch(`/getListItemsJSON.php?list=${encodeURIComponent(token)}`).then(r => r.json());
      container.innerHTML = "";
      renderListItems(container, token, data.items || []);
      container.dataset.loaded = "1";
      container.dataset.jsonRendered = "1";
    } else {
      // Re-render via server HTML (legacy markup with menus, etc.)
      const html = await fetch(`/getListItems.php?list=${encodeURIComponent(token)}`).then(r => r.text());
      container.innerHTML = html;
    }

    // Flash / focus the newly added item (if provided)
    if (focusSurrogate) {
      const newItem = container.querySelector(`.list-sub-item[data-value='${focusSurrogate}']`);
      if (newItem) {
        newItem.classList.add("flash");
        setTimeout(() => newItem.classList.remove("flash"), 700);
      }
    }
  } catch (err) {
    console.warn("refreshListUI failed:", err);
  }
}


// call it after your lists render
// It opens the correct top group, expands parent lists, 
// lazy-loads items if needed, and selects the surrogate.

function applyDeepLink() {
  const parts = window.location.pathname.split("/").filter(Boolean);
  const listToken = parts[0] || null;
  const surrogate = parts[1] || null;
  
//   if (!listToken) return;
// meaning if All Content, then do not auto expand
    // Skip auto-expand if URL points to its own root All Content (no surrogate)
    const ownerUsername = window.currentOwner?.username || window.currentProfileUsername || "";
    if (
      !listToken ||
      (listToken === window.currentListToken && !surrogate) ||
      (!surrogate && ownerUsername && listToken.toLowerCase() === ownerUsername.toLowerCase())
    ) return;



  const node = document.querySelector(`.group-item[data-group='${CSS.escape(listToken)}']`);
  if (!node) {
    return;
  }

  // Ensure list JSON is cached when a deep link opens a list (online)
  const ownerHint = node?.dataset?.ownerUsername || window.currentOwner?.username || null;
  if (navigator.onLine) {
    window.cacheOwnerJsonForToken?.(listToken, ownerHint);
  } else {
    window.queueOwnerCacheToken?.(listToken);
  }

  // Ensure top group (Owners / Invited / Followed) is open
  const wrapper = node.closest(".list-group-wrapper");
  const groupContents = wrapper?.querySelector(":scope > .group-contents");
  const groupArrow = wrapper?.querySelector(":scope > .sidebar-section-header .group-arrow");
  if (groupContents && groupContents.style.display !== "block") {
    groupContents.style.display = "block";
    if (groupArrow) groupArrow.textContent = "▼";
  }

  // Open ancestors (root→target), lazy-load items if needed
  const openChain = async () => {
    const chain = [];
    let cur = node;
    while (cur) { chain.unshift(cur); cur = cur.parentElement?.closest(".group-item"); }

    for (const el of chain) {
      const header = el.querySelector(":scope > .list-header-row");
      const listContents = el.querySelector(":scope > .list-contents");
      if (!header || !listContents) continue;

      if (listContents.style.display !== "block") {
        header.click();                   // triggers your existing fetch+render
        await new Promise(r => setTimeout(r, 30)); // let the toggle run
      }
      if (surrogate && !listContents.dataset.loaded) {
        // wait briefly for items to load (best-effort, non-blocking timeout)
        await new Promise(r => {
          const start = Date.now();
          const id = setInterval(() => {
            if (listContents.dataset.loaded || Date.now() - start > 1500) {
              clearInterval(id); r();
            }
          }, 60);
        });
      }
    }
  };

  openChain().then(() => {
    if (!surrogate) {
    //   node.scrollIntoView({ block: "start", behavior: "smooth" });
      return;
    }
    const listContents = node.querySelector(":scope > .list-contents");
    selectItem(surrogate, listToken, listContents || null);
    highlightSelectedItem(surrogate, listContents || document);
    const targetItem = (listContents || document).querySelector(`.list-sub-item[data-value='${CSS.escape(surrogate)}']`);
    // targetItem?.scrollIntoView({ block: "start", behavior: "smooth" });
  });
}



function toggleListMenu(event, token, ownerId, roleRank) {
  const button  = event?.currentTarget;
  const wrapper = button?.closest(".list-menu-wrapper, .menu");
  if (!wrapper) return;
  const group = wrapper.closest(".group-item");

  // 🔁 If already open → close and stop
  if (wrapper.classList.contains("open")) {
    wrapper.classList.remove("open");
    group?.classList.remove("menu-open");
    return;
  }

  // 🆕 Otherwise close others, build, and open
  closeAllMenus(button);
  generateListMenu(event, token, ownerId, roleRank);
}



function generateListMenu(event, token, ownerId, roleRank) {
  event.stopPropagation();
//   closeAllMenus();


  // Close any open menus
//   document.querySelectorAll(".list-menu-wrapper.open, .menu.open")
//     .forEach(m => m.classList.remove("open"));

  const button = event.currentTarget;
  const wrapper = button.closest(".list-menu-wrapper, .menu");
  if (!wrapper) return;
  const group = wrapper.closest(".group-item");

  // Toggle state
//   if (wrapper.classList.contains("open")) {
//     wrapper.classList.remove("open");
//     return;
//   }

  const rank = parseInt(roleRank || 0, 10);
  const t = window.translations || {};

  let html = `
    <div class="list-choice menu-item" onclick="shareLink('${token}'); event.stopPropagation();">
      🔗 ${t.share_list || "Share this list"}
    </div>
    <div class="list-choice menu-item" onclick="openChatFromMenu('${token}'); event.stopPropagation();">
      💬 ${t.list_chat || "List Chat"}
    </div>
    <div class="list-choice menu-item toggle-offline-status" data-token="${token}"
         onclick="toggleOfflineStatus('${token}', this); event.stopPropagation();">
      📥 ${t.make_offline || "Make available offline"}
    </div>
    <div class="list-choice menu-item toggle-list-status"
         onclick="toggleMyList('${token}', this); event.stopPropagation();">
      ⭐ ${t.toggle_my_list || "Add / Remove from My Lists"}
    </div>
  `;

    if (rank >= 60) {
      // ✅ Get the element representing this list
      const listEl = document.querySelector(
        `.group-item[data-group='${token}'], .list-contents[data-token='${token}']`
      );
    
      // ✅ Read the privacy level from dataset, default to 'private'
      const level = listEl?.dataset.access || 'private';
    
      // ✅ Map to emoji icon
      const iconMap = { public: "🌐", private: "🔒", secret: "🕵️" };
      const privacyIcon = iconMap[level] || "❓";
    
      html += `
        <div class="list-choice menu-item submenu-parent"
             onclick="setListPrivacyPrompt('${token}', this); event.stopPropagation();">
          <span id="menu-privacy-icon-${token}" class="menu-icon">${privacyIcon}</span>
          ${t.set_list_privacy || "Set List Privacy"}
        </div>
        <div class="list-choice menu-item" onclick="renameList('${token}'); event.stopPropagation();">
          ✏️ ${t.rename_list || "Rename List"}
        </div>
        <div class="list-choice menu-item" onclick="confirmDeleteList('${token}'); event.stopPropagation();">
          🗑️ ${t.delete_list || "Delete List"}
        </div>
        <div class="list-choice menu-item" onclick="createNewItemInline('${token}'); event.stopPropagation();">
          ➕ ${t.create_new_item || "Create New Item"}
        </div>
      `;
    }



  // Build or update dropdown
  let dropdown = wrapper.querySelector(".list-menu-dropdown, .menu-dropdown");
  if (!dropdown) {
    dropdown = document.createElement("div");
    dropdown.className = "list-menu-dropdown menu-dropdown";
    wrapper.appendChild(dropdown);
  }

  dropdown.innerHTML = html;
  wrapper.classList.add("open");
  group?.classList.add("menu-open");

  // Close on outside click
//   setTimeout(() => {
//     const off = (e) => {
//       if (!wrapper.contains(e.target)) {
//         wrapper.classList.remove("open");
//         document.removeEventListener("click", off);
//       }
//     };
//     document.addEventListener("click", off);
//   }, 50);

  // Close when menu item clicked
    dropdown.querySelectorAll(".list-choice, .menu-item").forEach(choice =>
      choice.addEventListener("click", e => {
        // 🧩 don't close if this item *is* a submenu parent
        if (choice.classList.contains("submenu-parent")) return;
        wrapper.classList.remove("open");
        group?.classList.remove("menu-open");
      })
    );


  updateOfflineMenuLabels?.();
}




let currentUploadXHR = null; // keep reference to active upload

// Sidebar snapshot to survive SW cache clears
(() => {
  const SNAP_KEY = () => {
    const version = window.appVersion || "v0";
    const locale = String(window.currentLocale || document.documentElement.lang || "en").toLowerCase();
    const profile = window.currentProfileUsername || window.SESSION_USERNAME || "default";
    return `sidebarSnapshot:${version}:${locale}:${profile}`;
  };

  const pruneSidebarSnapshots = (keepKey = "") => {
    try {
      const keys = [];
      for (let i = 0; i < localStorage.length; i += 1) {
        const k = localStorage.key(i);
        if (k && k.startsWith("sidebarSnapshot:") && k !== keepKey) keys.push(k);
      }
      keys.forEach((k) => localStorage.removeItem(k));
    } catch {}
  };

  const restoreSidebarSnapshot = () => {
    const sidebar = document.getElementById("sidebarContainer");
    const content = sidebar?.querySelector(".sidebar-content");
    if (!content) return;
    let html = "";
    try {
      html = localStorage.getItem(SNAP_KEY()) || "";
    } catch {}
    if (html && html.trim() && !content.querySelector(".list-group-wrapper, .group-item")) {
      content.innerHTML = html;
    }
  };

  const saveSidebarSnapshot = () => {
    const sidebar = document.getElementById("sidebarContainer");
    const content = sidebar?.querySelector(".sidebar-content");
    if (!content) return;
    if (!content.querySelector(".list-group-wrapper, .group-item")) return;
    const key = SNAP_KEY();
    const html = content.innerHTML || "";
    try {
      localStorage.setItem(key, html);
      return;
    } catch (err) {
      // Quota can be exceeded on mobile browsers; keep app functional.
      if (!(err && (err.name === "QuotaExceededError" || err.code === 22))) return;
    }

    try {
      pruneSidebarSnapshots(key);
      localStorage.setItem(key, html);
      return;
    } catch {}

    try {
      localStorage.setItem(key, html.slice(0, 120000));
    } catch {}
  };

  document.addEventListener("DOMContentLoaded", () => {
    restoreSidebarSnapshot();

    const sidebar = document.getElementById("sidebarContainer");
    if (!sidebar) return;
    if (document.body?.dataset?.appMode === "taptray") return;
    let t = null;
    const observer = new MutationObserver(() => {
      clearTimeout(t);
      t = setTimeout(saveSidebarSnapshot, 200);
    });
    observer.observe(sidebar, { childList: true, subtree: true });
  });
})();
