logStep("JSText.js executed");

// =====================================================
// 🔄 Global Text Refresh Function
// Reloads text + comments + saved drawings
// Clears any unsaved drawing overlay
// =====================================================
window.refreshText = function () {
  const s = window.currentSurrogate;
  const t = window.currentListToken;

  if (!s || !t) {
    console.warn("⚠️ refreshText(): No item selected.");
    return;
  }

  // Remember the active tool (write, highlight, comment, draw)
  const tool = window.activeTextTool;

  // 🎨 Clear any unsaved drawing overlay
  const overlay = document.getElementById("twDrawingOverlay");
  if (overlay) {
    const ctx = overlay.getContext("2d");
    ctx.clearRect(0, 0, overlay.width, overlay.height);
  }

  window._drawStart = null; // safe to reset; simply last-stroke reference

  // 🔄 Reload this item (text + comments + saved drawings)
  window.selectItem?.(s, t, null);

  // ♻️ Re-apply the active tool mode (required so drawing works again)
  if (tool === "draw") {
    window.enableDrawingMode?.(true);
  } else {
    window.enableDrawingMode?.(false);
  }

  // Also restore global tool flag
  window.activeTextTool = tool;

//   console.log(`🔄 refreshText(): item reloaded, active tool restored: ${tool}`);
  logStep("refreshText");

};

window.TWPlaySync ||= {
  pollTimer: null,
  inFlight: false,
  suppressPublishOnce: false,
  lastSeenByToken: {},
  lastPublishedKey: "",
  lastPublishedAt: 0,
  pendingRemotePage: null,
  failureCount: 0,
  nextPollAt: 0
};
window.TWPlayOwner ||= {
  state: null,
  pollTimer: null,
  heartbeatTimer: null,
  claimAttemptedFor: {},
  lastOwnerDeniedAt: 0,
  noOwnerSinceByToken: {}
};

const TW_PLAY_NO_OWNER_AUTO_OFF_MS = 30000;

function twIsPlayModeEnabled() {
  if (!document.body.classList.contains("logged-in")) return false;
  if (window.twPlayMode === true) return true;
  try {
    return localStorage.getItem("twPlayMode") === "1";
  } catch {
    return false;
  }
}

function twGetPlaySyncChannel(preferredToken = "") {
  const explicit = String(preferredToken || "").trim();
  if (explicit) return explicit;
  return String(
    window.currentOwnerToken ||
    window.currentOwner?.username ||
    ""
  ).trim();
}

function twGetPlayAdminScopeToken() {
  return String(window.currentOwnerToken || "").trim();
}

function twGetActivePlayOwnerToken(preferredToken = "") {
  return String(
    preferredToken ||
    window.TWPlayOwner?.state?.channelToken ||
    window.currentOwnerToken ||
    ""
  ).trim();
}

function resolveInsertOwnerUsername(token = "") {
  const listToken = String(token || window.currentListToken || "").trim();
  const groupRow = listToken
    ? document.querySelector(`.group-item[data-group="${listToken}"]`)
    : null;
  return String(
    groupRow?.dataset?.ownerUsername ||
    window.currentListOwnerUsername ||
    window.currentItemOwner ||
    window.currentOwner?.username ||
    window.currentProfileUsername ||
    ""
  ).trim();
}

function twEnsurePlayOwnerChannelContext(token) {
  const current = window.TWPlayOwner?.state || null;
  if (!token) return;
  if (!current || String(current.channelToken || "") !== String(token)) {
    window.TWPlayOwner.state = { channelToken: token, adminsLoaded: false, admins: [] };
    if (window.TWPlayOwner?.noOwnerSinceByToken) {
      window.TWPlayOwner.noOwnerSinceByToken[token] = 0;
    }
    twSetPlayOwnerUI(window.TWPlayOwner.state);
  }
}

function twMaybeAutoDisableFollowerPlay(status) {
  if (!twIsPlayModeEnabled()) return;
  if (!status) return;
  if (!navigator.onLine) return;
  // Keep play mode sticky for admins/controllers.
  if (window.isAdminUser || status.can_control || status.is_owner) return;

  const token = String(status.channelToken || twGetPlayAdminScopeToken() || "").trim();
  if (!token) return;
  const owner = status.owner || null;
  const tracker = window.TWPlayOwner;
  tracker.noOwnerSinceByToken ||= {};

  if (owner && owner.username) {
    tracker.noOwnerSinceByToken[token] = 0;
    return;
  }

  const now = Date.now();
  const since = Number(tracker.noOwnerSinceByToken[token] || 0);
  if (!since) {
    tracker.noOwnerSinceByToken[token] = now;
    return;
  }
  if ((now - since) < TW_PLAY_NO_OWNER_AUTO_OFF_MS) return;

  tracker.noOwnerSinceByToken[token] = now;
  window.twSetPlayMode?.(false);
  showFlashMessage?.("Play mode turned off: no conductor active.");
}

window.twOnPlayScopeChanged = function (preferredToken = "") {
  const token = String(preferredToken || window.currentOwnerToken || "").trim();
  if (!token) return;
  const prevState = window.TWPlayOwner?.state || {};
  const prevToken = String(prevState.channelToken || "");
  const wasOwnerOnPrev = !!prevState.is_owner;
  if (prevToken === token) return;

  if (wasOwnerOnPrev && prevToken) {
    twReleasePlayOwner(prevToken);
  }
  twEnsurePlayOwnerChannelContext(token);
  if (!twIsPlayModeEnabled()) {
    twSetPlayOwnerUI(window.TWPlayOwner.state);
    return;
  }

  // Force immediate owner/admin refresh for new group/profile scope.
  twHandlePlayModeEnabledInternal();
  twFetchPlayOwnerAdmins();
};

function twGetPlayClientId() {
  try {
    let id = sessionStorage.getItem("twPlayClientId");
    if (!id) {
      id = `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
      sessionStorage.setItem("twPlayClientId", id);
    }
    return id;
  } catch {
    return "";
  }
}

async function twFetchJson(url, options = {}, timeoutMs = 4500) {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), Math.max(1200, Number(timeoutMs) || 4500));
  try {
    const res = await fetch(url, { ...options, signal: controller.signal });
    let data = null;
    try {
      data = await res.json();
    } catch {
      data = null;
    }
    return { ok: res.ok, status: res.status, data };
  } catch (err) {
    return { ok: false, status: 0, data: null, error: err };
  } finally {
    clearTimeout(timeout);
  }
}

// Passive network health for background tasks only:
// avoid repeated slow fetch attempts; rely on cache until probe is healthy again.
window.TWNetworkHealth ||= {
  mode: "unknown", // unknown | healthy | degraded
  failures: 0,
  retryTimer: null,
  probing: false
};

function twBackgroundNetworkOk() {
  if (!navigator.onLine) return false;
  return (window.TWNetworkHealth?.mode || "unknown") !== "degraded";
}

function twScheduleHealthRetry() {
  const h = window.TWNetworkHealth;
  if (!h || h.retryTimer) return;
  h.retryTimer = setTimeout(() => {
    h.retryTimer = null;
    twProbeNetworkHealth(true);
  }, 60000);
}

async function twProbeNetworkHealth(force = false) {
  const h = window.TWNetworkHealth;
  if (!h) return false;
  if (!navigator.onLine) {
    h.mode = "degraded";
    h.failures = 0;
    return false;
  }
  if (!force && h.mode === "healthy") return true;
  if (h.probing) return h.mode === "healthy";
  h.probing = true;

  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 900);
  try {
    const res = await fetch(`/getText.php?q=0&nh=${Date.now()}`, {
      method: "HEAD",
      cache: "no-store",
      signal: controller.signal
    });
    if (res && res.ok) {
      h.mode = "healthy";
      h.failures = 0;
      return true;
    }
  } catch {}
  finally {
    clearTimeout(timeout);
    h.probing = false;
  }

  h.failures = Number(h.failures || 0) + 1;
  h.mode = "degraded";
  twScheduleHealthRetry();
  return false;
}

function twNotePublishDenied() {
  const owner = window.TWPlayOwner || {};
  const now = Date.now();
  if (now - Number(owner.lastOwnerDeniedAt || 0) < 3500) return;
  owner.lastOwnerDeniedAt = now;
  twFetchPlayOwnerStatus();
}

function twSetPlayOwnerUI(state) {
  const playBtn = document.getElementById("playModeButton");
  const dropdown = document.getElementById("playOwnerDropdown");
  const avatar = document.getElementById("playOwnerAvatar");
  const ownerBtn = document.getElementById("playOwnerBtn");
  const label = document.getElementById("playOwnerLabel");
  const adminList = document.getElementById("playAdminList");
  if (!dropdown || !avatar || !label || !adminList || !playBtn || !ownerBtn) return;

  const playOn = twIsPlayModeEnabled();
  const owner = state?.owner || null;
  let paused = false;
  try {
    paused = localStorage.getItem("twPlaySyncPaused") === "1";
  } catch {}
  const isCurrentPlayer = !!state?.is_owner;
  ownerBtn.classList.toggle("play-owner-live", playOn && isCurrentPlayer && !paused);
  ownerBtn.classList.toggle("play-owner-paused", playOn && isCurrentPlayer && paused);

  if (!playOn) {
    playBtn.style.display = "inline-flex";
    dropdown.style.display = "none";
    return;
  }

  playBtn.style.display = "none";
  dropdown.style.display = "block";
  const ownerAvatar = (typeof window.twResolveAvatarUrl === "function")
    ? window.twResolveAvatarUrl(owner || {}, owner?.display_name || owner?.username || "Unassigned")
    : (owner.avatar_url || "/default-avatar.png");
  avatar.src = ownerAvatar || window.SESSION_AVATAR_URL || "/default-avatar.png";
  avatar.onerror = function () {
    this.onerror = null;
    this.src = "/default-avatar.png";
  };

  const ownerName = owner?.display_name || owner?.username || "Unassigned";
  label.textContent = `Play owner: ${ownerName}`;
  if (!state?.adminsLoaded) {
    adminList.innerHTML = `<div class="dropdown-item-text text-muted small">Click avatar to load admins</div>`;
  }
}
window.twRefreshPlayOwnerUi = function () {
  twSetPlayOwnerUI(window.TWPlayOwner?.state || null);
};

async function twFetchPlayOwnerStatus() {
  if (!navigator.onLine) return null;
  const token = twGetPlayAdminScopeToken();
  if (!token) return null;
  twEnsurePlayOwnerChannelContext(token);

  try {
    const res = await twFetchJson(
      `/api/play_mode_owner.php?action=status&token=${encodeURIComponent(token)}`,
      { cache: "no-store" }
    );
    if (!res.ok) return null;
    const data = res.data;
    if (!data || data.status !== "ok") return null;
    const prev = window.TWPlayOwner.state || {};
    const merged = { ...prev, ...data };
    merged.channelToken = token;
    if (
      String(prev.channelToken || "") === String(token) &&
      prev.adminsLoaded &&
      Array.isArray(prev.admins) &&
      !Array.isArray(data.admins)
    ) {
      merged.adminsLoaded = true;
      merged.admins = prev.admins;
    }
    window.TWPlayOwner.state = merged;
    twSetPlayOwnerUI(merged);
    twMaybeAutoDisableFollowerPlay(merged);
    return merged;
  } catch {
    return null;
  }
}

async function twFetchPlayOwnerAdmins() {
  if (!navigator.onLine) return null;
  const token = twGetPlayAdminScopeToken();
  if (!token) return null;
  twEnsurePlayOwnerChannelContext(token);
  const adminList = document.getElementById("playAdminList");

  try {
    const res = await twFetchJson(
      `/api/play_mode_owner.php?action=list_admins&token=${encodeURIComponent(token)}`,
      { cache: "no-store" }
    );
    if (!res.ok) {
      if (adminList) {
        adminList.innerHTML = `<div class="dropdown-item-text text-muted small">Could not load admins for profile token '${token}' (HTTP ${Number(res.status || 0)}).</div>`;
      }
      return null;
    }
    const data = res.data;
    if (!data || data.status !== "ok") {
      if (adminList) {
        adminList.innerHTML = `<div class="dropdown-item-text text-muted small">Could not load admins for profile token '${token}'.</div>`;
      }
      return null;
    }
    const merged = { ...(window.TWPlayOwner.state || {}), ...data, channelToken: token, adminsLoaded: true };
    window.TWPlayOwner.state = merged;
    twSetPlayOwnerUI(merged);
    twRenderPlayAdminList(merged);
    return merged;
  } catch {
    if (adminList) {
      adminList.innerHTML = `<div class="dropdown-item-text text-muted small">Could not load admins for profile token '${token}' (network error).</div>`;
    }
    return null;
  }
}

function twRenderPlayAdminList(state) {
  const adminList = document.getElementById("playAdminList");
  if (!adminList) return;
  const admins = Array.isArray(state?.admins) ? state.admins : [];
  const ownerUser = state?.owner?.username || "";
  const canControl = !!state?.can_control;

  if (!admins.length) {
    adminList.innerHTML = `<div class="dropdown-item-text text-muted small">No admins found</div>`;
    return;
  }

  adminList.innerHTML = admins.map((a) => {
    const username = String(a?.username || "");
    const display = String(a?.display_name || username);
    const avatar = String(a?.avatar_url || "/default-avatar.png");
    const resolvedAvatar = (typeof window.twResolveAvatarUrl === "function")
      ? window.twResolveAvatarUrl({ avatar_url: avatar, username, display_name: display }, display)
      : (avatar || "/default-avatar.png");
    const marker = (username === ownerUser) ? " ✓" : "";
    const disabledAttr = canControl ? "" : "disabled";
    const buttonClass = canControl ? "dropdown-item" : "dropdown-item disabled";
    const safeDisplay = display.replace(/</g, "&lt;").replace(/>/g, "&gt;");
    const safeAvatar = resolvedAvatar.replace(/"/g, "&quot;");
    return `
      <button type="button" class="${buttonClass} play-admin-choice" data-play-owner-user="${username}" ${disabledAttr}>
        <img src="${safeAvatar}" alt="" class="play-admin-avatar" onerror="this.onerror=null;this.src='/default-avatar.png';">
        <span class="play-admin-name">${safeDisplay}</span>
        <span class="play-admin-marker">${marker}</span>
      </button>
    `;
  }).join("");
}

async function twAssignPlayOwner(targetUser) {
  if (!navigator.onLine || !targetUser) return false;
  const token = twGetActivePlayOwnerToken();
  if (!token) return false;

  try {
    const res = await twFetchJson("/api/play_mode_owner.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        action: "assign",
        token,
        target_user: targetUser
      })
    });
    const data = res.data;
    if (!res.ok || !data || data.status !== "ok") return false;
    window.TWPlayOwner.state = { ...(window.TWPlayOwner.state || {}), ...data };
    await twFetchPlayOwnerAdmins();
    return true;
  } catch {
    return false;
  }
}

async function twClaimPlayOwner(takeover = false) {
  if (!navigator.onLine) return false;
  const token = twGetActivePlayOwnerToken();
  if (!token) return false;

  try {
    const res = await twFetchJson("/api/play_mode_owner.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        action: takeover ? "takeover" : "claim",
        token
      })
    });
    const data = res.data;
    if (!res.ok || !data || data.status !== "ok") return false;
    window.TWPlayOwner.state = data;
    twSetPlayOwnerUI(data);
    return true;
  } catch {
    return false;
  }
}

async function twHeartbeatPlayOwner() {
  const state = window.TWPlayOwner?.state;
  if (!state?.is_owner) return;
  const token = twGetActivePlayOwnerToken();
  if (!token) return;
  try {
    const res = await twFetchJson("/api/play_mode_owner.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "heartbeat", token })
    });
    if (!res.ok && (res.status === 409 || res.status === 403)) {
      window.TWPlayOwner.state = { ...(window.TWPlayOwner.state || {}), is_owner: false };
      twSetPlayOwnerUI(window.TWPlayOwner.state);
      twNotePublishDenied();
    }
  } catch {}
}

async function twReleasePlayOwner(forcedToken = "") {
  const state = window.TWPlayOwner?.state;
  if (!state?.is_owner) return;
  const token = String(forcedToken || twGetActivePlayOwnerToken() || "").trim();
  if (!token) return;
  try {
    await twFetchJson("/api/play_mode_owner.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "release", token })
    });
  } catch {}
}

function twCanPublishPlaySelection() {
  return !!window.TWPlayOwner?.state?.is_owner;
}

function twIsPlaySyncPaused() {
  try {
    return localStorage.getItem("twPlaySyncPaused") === "1";
  } catch {
    return false;
  }
}

async function twHandlePlayModeEnabledInternal() {
  const token = twGetActivePlayOwnerToken();
  if (!token) return;
  const status = await twFetchPlayOwnerStatus();
  if (status?.can_control && !status?.owner) {
    await twClaimPlayOwner(false);
    await twFetchPlayOwnerStatus();
  }
  setTimeout(() => {
    window.twPublishCurrentPlaySelection?.({ force: true, includeAnnotation: true });
  }, 120);
}

window.twHandlePlayModeEnabled = function () {
  twHandlePlayModeEnabledInternal();
};

window.twHandlePlayModeDisabled = function () {
  twReleasePlayOwner();
  if (window.TWPlayOwner?.noOwnerSinceByToken) {
    window.TWPlayOwner.noOwnerSinceByToken = {};
  }
  window.TWPlayOwner.state = null;
  twSetPlayOwnerUI(null);
};

async function twPostPlaySelection(channelToken, surrogate, itemToken, listOpen = null, opts = {}) {
  const payload = {
    token: channelToken,
    surrogate,
    item_token: itemToken || channelToken,
    list_open: (typeof listOpen === "boolean" ? listOpen : null),
    event_type: String(opts.eventType || "").trim() || null,
    publisher_client_id: twGetPlayClientId() || null
  };
  const pageNum = Number(opts.pageNum || 0);
  if (Number.isFinite(pageNum) && pageNum > 0) {
    payload.page_num = Math.max(1, Math.floor(pageNum));
  }
  const pageMode = String(opts.pageMode || "").trim();
  if (pageMode === "paged" || pageMode === "continuous") {
    payload.page_mode = pageMode;
  }
  let res = await twFetchJson("/api/play_sync_publish.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload)
  });
  if (!res.ok && res.status === 0) {
    await new Promise(r => setTimeout(r, 140));
    res = await twFetchJson("/api/play_sync_publish.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    });
  }
  if (!res.ok && (res.status === 409 || res.status === 403)) {
    twNotePublishDenied();
  }
  if (!res.ok && res.status === 429) {
    return false;
  }
  return res.ok;
}

async function twPublishPlayListSwitch(listToken, listOpen = null) {
  if (!navigator.onLine) return;
  if (!twIsPlayModeEnabled()) return;
  if (twIsPlaySyncPaused()) return;
  if (!listToken) return;
  if (!twCanPublishPlaySelection()) return;

  const sync = window.TWPlaySync;
  const channelToken = twGetPlaySyncChannel();
  const key = `${channelToken}:${listToken}:__list__:${String(listOpen)}`;
  const now = Date.now();
  if (sync.lastPublishedKey === key && now - (sync.lastPublishedAt || 0) < 800) return;

  sync.lastPublishedKey = key;
  sync.lastPublishedAt = now;

  try {
    if (!channelToken) return;
    await twPostPlaySelection(channelToken, "", listToken, listOpen, { eventType: "list" });
  } catch (err) {
    console.warn("⚠️ play list sync publish failed:", err);
  }
}
window.twPublishPlayListSwitch = twPublishPlayListSwitch;

async function twPublishPlaySelection(token, surrogate) {
  if (!navigator.onLine) return;
  if (!twIsPlayModeEnabled()) return;
  if (twIsPlaySyncPaused()) return;
  if (!token || !surrogate || surrogate === "0") return;
  if (!twCanPublishPlaySelection()) return;

  const sync = window.TWPlaySync;
  const channelToken = twGetPlaySyncChannel();
  const key = `${channelToken}:${token}:${surrogate}`;
  const now = Date.now();
  if (sync.lastPublishedKey === key && now - (sync.lastPublishedAt || 0) < 1000) return;

  sync.lastPublishedKey = key;
  sync.lastPublishedAt = now;

  try {
    if (!channelToken) return;
    await twPostPlaySelection(channelToken, surrogate, token, true, { eventType: "selection" });
  } catch (err) {
    console.warn("⚠️ play sync publish failed:", err);
  }
}

async function twPublishPlayPage(itemToken, surrogate, pageNum, pageMode = "") {
  if (!navigator.onLine) return;
  if (!twIsPlayModeEnabled()) return;
  if (twIsPlaySyncPaused()) return;
  if (!twCanPublishPlaySelection()) return;
  if (!itemToken || !surrogate) return;
  const safePage = Math.max(1, Math.floor(Number(pageNum || 0)));
  if (!safePage) return;

  const sync = window.TWPlaySync;
  const channelToken = twGetPlaySyncChannel();
  const mode = (pageMode === "paged" || pageMode === "continuous") ? pageMode : "";
  const key = `${channelToken}:${itemToken}:${surrogate}:page:${safePage}:${mode}`;
  const now = Date.now();
  if (sync.lastPublishedKey === key && now - (sync.lastPublishedAt || 0) < 450) return;

  sync.lastPublishedKey = key;
  sync.lastPublishedAt = now;

  try {
    if (!channelToken) return;
    await twPostPlaySelection(channelToken, surrogate, itemToken, true, {
      eventType: "page",
      pageNum: safePage,
      pageMode: mode
    });
  } catch (err) {
    console.warn("⚠️ play page sync publish failed:", err);
  }
}
window.twPublishPlayPage = twPublishPlayPage;

async function twPublishPlayAnnotation(itemToken, surrogate) {
  if (!navigator.onLine) return;
  if (!twIsPlayModeEnabled()) return;
  if (twIsPlaySyncPaused()) return;
  if (!twCanPublishPlaySelection()) return;
  if (!itemToken || !surrogate) return;

  const sync = window.TWPlaySync;
  const channelToken = twGetPlaySyncChannel();
  const key = `${channelToken}:${itemToken}:${surrogate}:annotation`;
  const now = Date.now();
  if (sync.lastPublishedKey === key && now - (sync.lastPublishedAt || 0) < 350) return;

  sync.lastPublishedKey = key;
  sync.lastPublishedAt = now;

  try {
    if (!channelToken) return;
    await twPostPlaySelection(channelToken, surrogate, itemToken, true, {
      eventType: "annotation"
    });
  } catch (err) {
    console.warn("⚠️ play annotation sync publish failed:", err);
  }
}
window.twPublishPlayAnnotation = twPublishPlayAnnotation;

window.twPublishCurrentPlaySelection = async function (opts = {}) {
  if (!twIsPlayModeEnabled()) return false;
  if (twIsPlaySyncPaused()) return false;

  const token = String(window.currentListToken || "").trim();
  const surrogate = String(window.currentSurrogate || "").trim();
  if (!token || !surrogate || surrogate === "0") return false;

  if (opts && opts.force) {
    const sync = window.TWPlaySync || {};
    sync.lastPublishedKey = "";
    sync.lastPublishedAt = 0;
  }

  await twPublishPlaySelection(token, surrogate);
  if (opts && opts.includeAnnotation) {
    await twPublishPlayAnnotation(token, surrogate);
  }
  return true;
};

function twQueueRemotePdfPage(pageNum, pageMode, surrogate, itemToken) {
  const sync = window.TWPlaySync || {};
  const safePage = Math.max(1, Math.floor(Number(pageNum || 0)));
  if (!safePage) return;
  sync.pendingRemotePage = {
    pageNum: safePage,
    pageMode: String(pageMode || ""),
    surrogate: String(surrogate || ""),
    itemToken: String(itemToken || ""),
    at: Date.now()
  };
  const apply = window.twApplyRemotePdfPage;
  if (typeof apply === "function") {
    apply(safePage, {
      mode: String(pageMode || ""),
      surrogate: String(surrogate || ""),
      itemToken: String(itemToken || "")
    });
  }
}

function twShouldFollowRemoteSelection() {
  if (!navigator.onLine) return false;
  if (!twIsPlayModeEnabled()) return false;
  if (!twGetPlaySyncChannel()) return false;
  if (twIsPlaySyncPaused()) return false;
  return true;
}

function twSetListExpandedState(listToken, expanded) {
  if (!listToken) return false;
  const node = document.querySelector(`.group-item[data-group='${CSS.escape(listToken)}']`);
  if (!node) return false;
  const groupContents = node.querySelector(":scope > .group-contents");
  const listContents = node.querySelector(":scope > .list-contents");
  const arrow = node.querySelector(":scope > .list-header-row .arrow");
  if (!groupContents || !listContents) return false;

  const display = expanded ? "block" : "none";
  groupContents.style.display = display;
  listContents.style.display = display;
  if (arrow) arrow.textContent = expanded ? "▼" : "▶";
  return true;
}

function twApplyRemoteListState(itemToken, listOpen) {
  if (!itemToken) return;
  if (listOpen === false) {
    twSetListExpandedState(itemToken, false);
    return;
  }
  twSetListExpandedState(itemToken, true);
}

function twEnsureListExpanded(itemToken) {
  if (!itemToken) return;
  const expandedByNative = (typeof window.expandList === "function")
    ? window.expandList(itemToken) === true
    : false;
  if (!expandedByNative) {
    twSetListExpandedState(itemToken, true);
  }
}

function twCollapseListIfOpen(listToken) {
  if (!listToken) return;
  twSetListExpandedState(listToken, false);
}

function twCollapseAllListsExcept(keepToken) {
  const keep = String(keepToken || "");
  if (!keep) return;
  const keepSet = new Set([keep]);

  const keepNode = document.querySelector(`.group-item[data-group='${CSS.escape(keep)}']`);
  let ancestor = keepNode?.parentElement?.closest(".group-item[data-group]") || null;
  while (ancestor) {
    const token = String(ancestor.getAttribute("data-group") || "");
    if (token) keepSet.add(token);
    ancestor = ancestor.parentElement?.closest(".group-item[data-group]") || null;
  }

  document.querySelectorAll(".group-item[data-group]").forEach((node) => {
    const token = String(node.getAttribute("data-group") || "");
    if (!token || keepSet.has(token)) return;
    twSetListExpandedState(token, false);
  });
}

async function twPollPlaySelectionNow() {
  if (!twShouldFollowRemoteSelection()) return;
  const sync = window.TWPlaySync;
  if (sync.inFlight) return;
  if (Date.now() < Number(sync.nextPollAt || 0)) return;

  const token = twGetPlaySyncChannel();
  const since = Number(sync.lastSeenByToken[token] || 0);
  sync.inFlight = true;

  try {
    const res = await twFetchJson(
      `/api/play_sync_fetch.php?token=${encodeURIComponent(token)}&since=${encodeURIComponent(since)}`,
      { cache: "no-store" }
    );
    if (!res.ok) {
      if (res.status === 403 || res.status === 401) {
        sync.failureCount = 0;
        sync.nextPollAt = Date.now() + 4000;
      } else {
        sync.failureCount = Math.min(6, Number(sync.failureCount || 0) + 1);
        sync.nextPollAt = Date.now() + Math.min(12000, 800 * (2 ** sync.failureCount));
      }
      return;
    }

    sync.failureCount = 0;
    sync.nextPollAt = 0;
    const data = res.data;
    if (!data || data.status !== "ok" || data.changed !== true) return;

    const updatedAt = Number(data.updated_at || 0);
    if (updatedAt > since) {
      sync.lastSeenByToken[token] = updatedAt;
    }

    const remoteToken = String(data.token || token);
    const itemToken = String(data.item_token || remoteToken);
    const remoteSurrogate = String(data.surrogate || "");
    const eventType = String(data.event_type || (remoteSurrogate ? "selection" : "list"));
    const listOpen = (typeof data.list_open === "boolean") ? data.list_open : null;
    const pageNum = Math.max(0, Math.floor(Number(data.page_num || 0)));
    const pageMode = String(data.page_mode || "");
    if (remoteToken !== token) return;

    if (eventType === "list" || !remoteSurrogate || remoteSurrogate === "0") {
      if (itemToken) {
        sync.suppressPublishOnce = true;
        try {
          if (listOpen === false) {
            twSetListExpandedState(itemToken, false);
          } else {
            twEnsureListExpanded(itemToken);
          }
          const currentList = String(window.currentListToken || "");
          if (itemToken !== currentList) {
            window.currentListToken = itemToken;
            window.currentSurrogate = "";
            const targetUrl = `/${itemToken}`;
            if (window.location.pathname !== targetUrl) {
              window.history.replaceState({}, "", targetUrl);
            }
            if (typeof window.applyDeepLink === "function") {
              window.applyDeepLink();
            }
          }
          twApplyRemoteListState(itemToken, listOpen);
        } catch {}
      }
      return;
    }

    const currentListBefore = String(window.currentListToken || "");
    const sameItem = String(window.currentSurrogate || "") === remoteSurrogate && currentListBefore === itemToken;

    if (eventType === "annotation") {
      if (!sameItem) return;
      try {
        await window.invalidateVisiblePdfAnnotationCache?.();
        await window.reloadVisiblePdfAnnotations?.();
      } catch {}
      return;
    }

    if (eventType === "page") {
      // Paging preference only controls in-item page jumps.
      // Cross-item selection should still follow conductor even when paging follow is off.
      if (sameItem) {
        if (!window.followConductorPaging) return;
        twQueueRemotePdfPage(pageNum, pageMode, remoteSurrogate, itemToken);
        return;
      }
    } else if (sameItem) {
      return;
    }

    sync.suppressPublishOnce = true;
    twEnsureListExpanded(itemToken || remoteToken);
    twCollapseAllListsExcept(itemToken || remoteToken);
    window.selectItem?.(remoteSurrogate, itemToken || remoteToken, null);
    if (eventType === "page" && window.followConductorPaging) {
      twQueueRemotePdfPage(pageNum, pageMode, remoteSurrogate, itemToken || remoteToken);
    }
  } catch (err) {
    sync.failureCount = Math.min(6, Number(sync.failureCount || 0) + 1);
    sync.nextPollAt = Date.now() + Math.min(12000, 800 * (2 ** sync.failureCount));
    console.warn("⚠️ play sync fetch failed:", err);
  } finally {
    sync.inFlight = false;
  }
}

function twStartPlaySyncPolling() {
  const sync = window.TWPlaySync;
  if (sync.pollTimer) return;

  sync.pollTimer = setInterval(() => {
    twPollPlaySelectionNow();
  }, 2000);

  document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "visible") twPollPlaySelectionNow();
  });
  window.addEventListener("focus", twPollPlaySelectionNow);

  setTimeout(() => twPollPlaySelectionNow(), 600);
}

function twStartPlayOwnerLoop() {
  const owner = window.TWPlayOwner;
  if (!owner.pollTimer) {
    owner.pollTimer = setInterval(async () => {
      if (!twIsPlayModeEnabled()) return;
      await twFetchPlayOwnerStatus();
    }, 3000);
  }
  if (!owner.heartbeatTimer) {
    owner.heartbeatTimer = setInterval(() => {
      if (!twIsPlayModeEnabled()) return;
      twHeartbeatPlayOwner();
    }, 4000);
  }
}

function twInitPlayOwnerUIBindings() {
  const playBtn = document.getElementById("playModeButton");
  const ownerBtn = document.getElementById("playOwnerBtn");
  const adminList = document.getElementById("playAdminList");
  const stopBtn = document.getElementById("playStopBtn");
  if (!playBtn || !ownerBtn || !adminList || !stopBtn || ownerBtn.dataset.bound === "1") return;
  ownerBtn.dataset.bound = "1";

  playBtn.addEventListener("click", () => {
    window.twSetPlayMode?.(true);
  });

  ownerBtn.addEventListener("click", () => {
    twFetchPlayOwnerAdmins();
  });

  adminList.addEventListener("click", async (e) => {
    const btn = e.target.closest(".play-admin-choice");
    if (!btn || btn.disabled) return;
    const targetUser = String(btn.dataset.playOwnerUser || "");
    if (!targetUser) return;
    const ok = await twAssignPlayOwner(targetUser);
    if (ok) {
      showFlashMessage?.(`🎛️ Play mode owner: ${targetUser}`);
      await twFetchPlayOwnerStatus();
    } else {
      showFlashMessage?.("⚠️ Could not assign play mode owner.");
    }
  });

  stopBtn.addEventListener("click", () => {
    window.twSetPlayMode?.(false);
  });
}

function twBootPlayUiForShell() {
  const isLoggedIn = document.body.classList.contains("logged-in");
  const isTapTrayShell = document.body?.dataset?.appMode === "taptray";
  if (!isLoggedIn && isTapTrayShell) {
    window.twSetPlayMode?.(false);
    twSetPlayOwnerUI(null);
    return;
  }

  twStartPlaySyncPolling();
  twStartPlayOwnerLoop();
  twInitPlayOwnerUIBindings();
  if (twIsPlayModeEnabled()) {
    twHandlePlayModeEnabledInternal();
  } else {
    twSetPlayOwnerUI(null);
  }
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", () => {
    twBootPlayUiForShell();
  });
} else {
  twBootPlayUiForShell();
}



function getTextXXXX(surrogate) {
  const area = document.getElementById("myTextarea");
  if (area) {
    // Remove highlight UI from previous item
    area.querySelectorAll("span.hl, .comment-hint").forEach(el => {
      el.outerHTML = el.innerHTML;
    });
  }

  return new Promise((resolve, reject) => {
    const t1 = document.getElementById("myTextarea");
    const t2 = document.getElementById("myTextarea2");

    if (!surrogate || surrogate === "0") {
      if (t1) t1.innerHTML = "";
      if (t2) t2.innerHTML = "";
      resolve();
      return;
    }

    // ---- ONLINE ----
    fetch(`/getText.php?q=${surrogate}`)
      .then(r => r.text())
      .then(data => {

    // Always treat DB response as canonical HTML
    let html = data
      .replace(/\r?\n/g, "<br>")
      .replace(/[\u200B\u200C\u200D\u2060\uFEFF\u202F]/g, "");


        if (t1) t1.innerHTML = html;
        if (t2) t2.innerHTML = html;

        // canonical plain text
        // window._T2_RAWTEXT = htmlToPlainText(html);
        // 🔹 Store both HTML and plaintext
        window._T2_RAWHTML = html;
        window._T2_RAWTEXT = htmlToPlainText(html);

        requestAnimationFrame(() => textTrimmer?.());
        resolve();
      })
      .catch(err => {
        console.error("❌ Error fetching text:", err);
        reject(err);
      });
  });
  
logStep("getText");  
}


function getTextYYYY(surrogate) {
  const area = document.getElementById("myTextarea");
  if (area) {
    area.querySelectorAll("span.hl, .comment-hint").forEach(el => {
      el.outerHTML = el.innerHTML;
    });
  }

  return new Promise(async (resolve, reject) => {
    const t1 = document.getElementById("myTextarea");
    const t2 = document.getElementById("myTextarea2");

    if (!surrogate || surrogate === "0") {
      if (t1) t1.innerHTML = "";
      if (t2) t2.innerHTML = "";
      resolve();
      return;
    }

    const textKey    = `offline-text-${surrogate}`;   // existing offline storage
    const versionKey = `offline-text-version-${surrogate}`; // NEW but shared with online + offline
    const cached     = localStorage.getItem(textKey);
    const cachedVer  = localStorage.getItem(versionKey) || "";

    // --------------------------------------------------
    // 🔌 OFFLINE → use stored text ONLY (unchanged)
    // --------------------------------------------------
    if (!navigator.onLine) {
      if (cached) {
        if (t1) t1.innerHTML = cached;
        if (t2) t2.innerHTML = cached;

        window._T2_RAWHTML = cached;
        window._T2_RAWTEXT = htmlToPlainText(cached);

        requestAnimationFrame(() => textTrimmer?.());
        resolve();
        return;
      }

      // offline but no cache
      if (t1) t1.innerHTML = "";
      if (t2) t2.innerHTML = "";

      resolve();
      return;
    }

    // --------------------------------------------------
    // 🌐 ONLINE → CACHE FIRST (instant UI), then refresh
    // --------------------------------------------------

    // 1️⃣ Serve cached text immediately if exists
    if (cached) {
      if (t1) t1.innerHTML = cached;
      if (t2) t2.innerHTML = cached;

      window._T2_RAWHTML = cached;
      window._T2_RAWTEXT = htmlToPlainText(cached);

      requestAnimationFrame(() => textTrimmer?.());
      // Do NOT resolve yet — we want server refresh afterwards
    }

    // 2️⃣ Fetch from server with version check
    fetch(`/getText.php?q=${surrogate}&v=${cachedVer}`)
      .then(async res => {
        const serverVersion = res.headers.get("X-Text-Version") || "";

        // ✔ If server says “no change”
        if (res.status === 304) {
          resolve();
          return;
        }

        // ✔ Text changed → load full HTML
        const data = await res.text();

        // Clean HTML exactly like before
        let html = data
          .replace(/\r?\n/g, "<br>")
          .replace(/[\u200B\u200C\u200D\u2060\uFEFF\u202F]/g, "");

        // Write to UI
        if (t1) t1.innerHTML = html;
        if (t2) t2.innerHTML = html;

        window._T2_RAWHTML = html;
        window._T2_RAWTEXT = htmlToPlainText(html);

        // Save updated cache + version
        localStorage.setItem(textKey, html);
        localStorage.setItem(versionKey, serverVersion);

        requestAnimationFrame(() => textTrimmer?.());
        resolve();
      })
      .catch(err => {
        console.error("❌ Error fetching text:", err);
        reject(err);
      });

  });

  logStep("getText");
}


function getText(surrogate) {
  const metaEl = document.getElementById("textMetaFooter");

  return new Promise(async (resolve, reject) => {
    if (!surrogate || surrogate === "0") {
      window._T2_RAWHTML = "";
      window._T2_RAWTEXT = "";
      if (metaEl) {
        metaEl.textContent = "";
        metaEl.style.display = "none";
      }
      resolve();
      return;
    }

    const textKey    = `offline-text-${surrogate}`;
    const versionKey = `offline-text-version-${surrogate}`;
    const metaKey    = `offline-text-meta-${surrogate}`;
    const cached     = localStorage.getItem(textKey);
    const cachedVer  = localStorage.getItem(versionKey) || "";
    const cachedMeta = localStorage.getItem(metaKey);
    // --------------------------------------------------
    // 🔌 OFFLINE MODE
    // --------------------------------------------------
    if (!navigator.onLine) {
      if (cached) updateUIwithHTML(cached);
      if (cachedMeta) {
        try { setTextMeta(JSON.parse(cachedMeta)); } catch {}
      }
      resolve();
      return;
    }

    // --------------------------------------------------
    // ⚡ CACHE-FIRST DISPLAY
    // --------------------------------------------------
    if (cached) {
      updateUIwithHTML(cached);
      // Continue version check
    }

    // --------------------------------------------------
    // 🔍 HEAD VERSION CHECK
    // --------------------------------------------------
    let serverVersion = "";
    let headMeta = null;
    try {
      const headRes = await fetch(`/getText.php?q=${surrogate}`, {
        method: "HEAD",
        cache: "no-store"
      });
      serverVersion = headRes.headers.get("X-Text-Version") || "";
      headMeta = extractTextMetaFromHeaders(headRes);
    } catch {
      resolve();
      return;
    }

    // No change
    if (cached && cachedVer === serverVersion) {
      if (headMeta) setTextMeta(headMeta);
      resolve();
      return;
    }

    // --------------------------------------------------
    // 🌐 FETCH FULL TEXT
    // --------------------------------------------------
    fetch(`/getText.php?q=${surrogate}`, { cache: "no-store" })
      .then(r => {
        const meta = extractTextMetaFromHeaders(r);
        if (meta) {
          setTextMeta(meta);
          localStorage.setItem(metaKey, JSON.stringify(meta));
        }
        return r.text();
      })
      .then(text => {
        let html = text
          .replace(/\r?\n/g, "<br>")
          .replace(/[\u200B\u200C\u200D\u2060\uFEFF\u202F]/g, "");

        updateUIwithHTML(html);

        // Cache
        localStorage.setItem(textKey, html);
        localStorage.setItem(versionKey, serverVersion);

        resolve();
      })
      .catch(err => {
        console.error("❌ Error fetching text:", err);
        reject(err);
      });
  });

  // --------------------------------------------------
  // Helper function inside getText()
  // --------------------------------------------------
  function updateUIwithHTML(html) {
    window._T2_RAWHTML = html;
    window._T2_RAWTEXT = htmlToPlainText(html);
  }
}

function extractTextMetaFromHeaders(res) {
  if (!res || !res.headers) return null;
  const owner = res.headers.get("X-Text-Owner") || "";
  const createdUser = res.headers.get("X-Text-Created-User") || "";
  const createdTime = res.headers.get("X-Text-Created-Time") || "";
  const updatedUser = res.headers.get("X-Text-Updated-User") || "";
  const updatedTime = res.headers.get("X-Text-Updated-Time") || "";
  if (!owner && !createdUser && !updatedUser) return null;
  return { owner, createdUser, createdTime, updatedUser, updatedTime };
}

function setTextMeta(meta) {
  const metaEl = document.getElementById("textMetaFooter");
  if (!metaEl) return;
  if (!meta) {
    metaEl.textContent = "";
    metaEl.style.display = "none";
    return;
  }
  const fmt = (s) => {
    if (!s) return "";
    const d = new Date(String(s).replace(" ", "T"));
    return isNaN(d.getTime()) ? String(s) : d.toLocaleString();
  };
  const norm = (s) => String(s || "").trim().replace(" ", "T");
  const sameMoment =
    norm(meta.createdTime) &&
    norm(meta.updatedTime) &&
    norm(meta.createdTime) === norm(meta.updatedTime);

  const created = meta.createdUser
    ? `Created by ${meta.createdUser}${meta.createdTime ? " · " + fmt(meta.createdTime) : ""}`
    : "";
  const updated = meta.updatedUser
    ? `Changed by ${meta.updatedUser}${meta.updatedTime ? " · " + fmt(meta.updatedTime) : ""}`
    : "";

  if (!created && !updated) {
    metaEl.textContent = "";
    metaEl.style.display = "none";
    return;
  }

  if (created && (!updated || sameMoment)) {
    metaEl.textContent = created;
    metaEl.style.display = "block";
    return;
  }

  if (!created && updated) {
    metaEl.textContent = updated;
    metaEl.style.display = "block";
    return;
  }

  metaEl.innerHTML = `${created}<br>${updated}`;
  metaEl.style.display = "block";
}






function renameItem(surrogate, listToken) {
  // Close menus
  document.querySelectorAll(".item-menu-wrapper").forEach(m => m.classList.remove("open"));

  const item = document.querySelector(`.list-sub-item[data-value="${surrogate}"]`);
  if (!item) return;

  const titleEl = item.querySelector(".item-title");
  if (!titleEl) return;

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
        
        // 🔄 If this item is currently open, refresh its content
        if (window.currentSurrogate == surrogate) {
          const container = document.getElementById(`list-${listToken}`) || null;
          window.selectItem?.(surrogate, listToken, container);
        }
      } else {
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



function updateLinkDataXXXX(updatedText, context = "link-update") {
  return new Promise(resolve => {
    const surrogate = window.currentSurrogate || "0";
    if (!surrogate || surrogate === "0") {
      console.warn("⚠️ No surrogate selected for link update");
      return resolve(false);
    }

    const xhr = new XMLHttpRequest();
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;

      const body = xhr.status === 200 ? (xhr.responseText || "").trim() : "0";
      const responseSurrogate = parseInt(body, 10);

      if (responseSurrogate > 0) {
        // 🔹 Treat updatedText as HTML; normalize line breaks
        const html = updatedText.replace(/\r?\n/g, "<br>");
        window._T2_RAWHTML = html;
        window._T2_RAWTEXT = htmlToPlainText(html);

        if (context !== "delete") showFlashMessage?.("✅ Links updated");
        resolve(true);
      } else {
        showFlashMessage?.("🚫 Failed to update links");
        resolve(false);
      }
    };

    xhr.open("POST", "/datainsert.php", true);
    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    const dataname = htmlToPlainText(updatedText).split("\n")[0] || "Untitled";
    xhr.send(
      `dataname=${encodeURIComponent(dataname)}&surrogate=${encodeURIComponent(surrogate)}&text=${encodeURIComponent(updatedText)}`
    );
  });
}


function updateLinkData(updatedText, context = "link-update") {
  return new Promise(resolve => {
    const surrogate = window.currentSurrogate || "0";
    if (!surrogate || surrogate === "0") {
      console.warn("⚠️ No surrogate selected for link update");
      return resolve(false);
    }

    // 🔎 1) Ensure some spacing between lyrics and links
    // We assume updatedText is plain text with \n
    const addSpacingBeforeLinks = (text) => {
      if (!text) return text;

      const lines = text.split(/\r?\n/);
      const urlRegex = /(https?:\/\/|www\.)/i;

      // Find first line that looks like it contains a URL
      let firstLinkIdx = -1;
      for (let i = 0; i < lines.length; i++) {
        if (urlRegex.test(lines[i])) {
          firstLinkIdx = i;
          break;
        }
      }
      if (firstLinkIdx <= 0) return text; // no links or only links

      // Count blank lines immediately above the first link line
      let j = firstLinkIdx - 1;
      let blankCount = 0;
      while (j >= 0 && lines[j].trim() === "") {
        blankCount++;
        j--;
      }

      const needed = 2 - blankCount; // want at least 2 empty lines
      if (needed > 0) {
        const padding = new Array(needed).fill("");
        lines.splice(firstLinkIdx, 0, ...padding);
      }

      return lines.join("\n");
    };

    // 2) Apply spacing rule
    let textWithSpacing = addSpacingBeforeLinks(updatedText || "");

    // 3) Convert text → HTML, then sanitize exactly like normal save
    let htmlRaw = textWithSpacing.replace(/\r?\n/g, "<br>");
    let html = htmlRaw;
    try {
      html = sanitizeForSave(htmlRaw);
    } catch (err) {
      console.warn("⚠️ sanitizeForSave failed in updateLinkData, using raw HTML", err);
    }

    // 4) Compute dataname from sanitized plain text (first line)
    const plain = htmlToPlainText(html);
    const dataname = (plain.split("\n")[0] || "Untitled").trim();

    const xhr = new XMLHttpRequest();
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;

      const body = xhr.status === 200 ? (xhr.responseText || "").trim() : "0";
      const responseSurrogate = parseInt(body, 10);

      if (responseSurrogate > 0) {
        window._T2_RAWHTML = html;
        window._T2_RAWTEXT = htmlToPlainText(html);

        if (context !== "delete") showFlashMessage?.("✅ Links updated");
        resolve(true);
      } else {
        showFlashMessage?.("🚫 Failed to update links");
        resolve(false);
      }
    };

    xhr.open("POST", "/datainsert.php", true);
    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    xhr.send(
      `dataname=${encodeURIComponent(dataname)}&surrogate=${encodeURIComponent(surrogate)}&text=${encodeURIComponent(html)}`
    );
  });
}




window.lastItemLoadTimes = window.lastItemLoadTimes || {};
window.lastCacheUpdate = window.lastCacheUpdate || {};


function insertData(force = false) {
  return new Promise(async resolve => {
    const surrogate = window.currentSurrogate || "0";
    const token = getEffectiveToken();

    // Skip if not in edit mode
    const isEditing = [...document.querySelectorAll(".edit-mode-toggle")]
      .some(toggle => toggle.checked);
    if (!isEditing && !force) return resolve(false);
    
    //Safety: prevent saving while trimmer is active
    const slider = document.getElementById("b");
    if (slider && slider.value !== "0") {
        showFlashMessage("⚠️ Text is trimmed. Set slider to 0 before saving.");
        return resolve(false);
    }
    

    const editable = document.getElementById("myTextarea");
    if (!editable) {
      const html = sanitizeForSave(String(window._T2_RAWHTML || "").trim());
      if (!html) return resolve(false);

      const dataname = extractTitleFromHTML(html);
      const xhr = new XMLHttpRequest();
      xhr.onreadystatechange = async function () {
        if (xhr.readyState !== 4) return;

        const body = xhr.status === 200 ? (xhr.responseText || "").trim() : "0";
        const responseSurrogate = parseInt(body, 10);

        if (responseSurrogate > 0) {
          window.currentSurrogate = responseSurrogate;
          window.currentListToken = token;
          window.history.pushState({}, "", `/${token}/${responseSurrogate}`);
          resolve(true);
        } else {
          showFlashMessage?.("🚫 Save failed — no rights or server error");
          resolve(false);
        }
      };

      xhr.open("POST", "/datainsert_to_list.php", true);
      xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
      const ownerUser = resolveInsertOwnerUsername(token);
      xhr.send(
        `dataname=${encodeURIComponent(dataname)}&surrogate=${encodeURIComponent(surrogate)}&text=${encodeURIComponent(html)}&token=${encodeURIComponent(token)}${ownerUser ? `&owner=${encodeURIComponent(ownerUser)}` : ""}`
      );

      showFlashMessage?.("💾 Saved");
      tMod = 0;
      return;
    }

    // 🧹 Clone DOM for safe cleanup
    const clone = editable.cloneNode(true);

    // 1) Remove UI elements ONLY  
    clone.querySelectorAll(".comment-hint").forEach(el => el.remove());
    clone.querySelectorAll("span.hl").forEach(el => el.replaceWith(...el.childNodes));
    clone.querySelectorAll("span.draw-anchor").forEach(el => el.remove());
    clone.querySelectorAll("canvas").forEach(el => el.remove());

    // 2) Canonical sanitize for save (no text manipulation)
    let html = sanitizeForSave(clone.innerHTML);
    console.log(html);



    // ===========================================================
    // 3) Compute dataname from PLAIN text (first line)
    // ===========================================================
    // const dataname = (htmlToPlainText(html).split("\n")[0] || "Untitled").trim();
    const dataname = extractTitleFromHTML(html);


    // ===========================================================
    // 4) SEND CLEAN HTML (this is the canonical anchor source)
    // ===========================================================
    const xhr = new XMLHttpRequest();
    xhr.onreadystatechange = async function () {
      if (xhr.readyState !== 4) return;

      const body = xhr.status === 200 ? (xhr.responseText || "").trim() : "0";
      const responseSurrogate = parseInt(body, 10);

      if (responseSurrogate > 0) {
        // UI bookkeeping unchanged
        window.currentSurrogate = responseSurrogate;
        window.currentListToken = token;
        window.history.pushState({}, "", `/${token}/${responseSurrogate}`);

        let item = document.querySelector(`.list-sub-item[data-value="${responseSurrogate}"]`);
        if (!item) {
          item = document.querySelector(`.list-sub-item[data-value="0"][data-token="${token}"]`);
        }
        if (!item) item = document.querySelector(`.list-sub-item[data-value="${responseSurrogate}"]`);

        if (item) {
          const username = window.SESSION_USERNAME;
          const preservedOwner =
            item.dataset.owner ||
            window.currentItemOwner ||
            username;
        
          item.dataset.value = responseSurrogate;
          item.dataset.token = token;
          // Preserve real item owner (admins may save on behalf of another owner).
          item.dataset.owner = preservedOwner;
          item.dataset.itemRoleRank = "90";
          item.dataset.canEdit = "1";
          item.dataset.fileserver = window.fileServer || "justhost";
          window.currentItemOwner = preservedOwner;
        
          const subjectEl = item.querySelector(".item-subject");
          const titleEl   = item.querySelector(".item-title");
        
          // Update .item-subject (older templates)
          if (subjectEl) {
            subjectEl.textContent = `• ${dataname}`;
          }
        
          //Update visible UI title (newer templates)
          if (titleEl) {
            titleEl.innerHTML = `• ${dataname} <span class="username">[${username}]</span>`;
            titleEl.setAttribute("onclick", `selectItem(${responseSurrogate}, '${token}')`);
          }
        
          // Keep owner label unchanged for delegated/admin saves.
        
          // Blink to show update
          item.classList.add("flash");
          setTimeout(() => item.classList.remove("flash"), 600);
        }


        // Always store annotations
        try {
          await saveTextMarks();
          console.log("💬 Comments saved (text unchanged)");
        } catch (err) {
          console.warn("⚠️ Comment save skipped:", err);
        }

        resolve(true);
      } else {
        showFlashMessage?.("🚫 Save failed — no rights or server error");
        resolve(false);
      }
    };

    xhr.open("POST", "/datainsert_to_list.php", true);
    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    const ownerUser = resolveInsertOwnerUsername(token);
    xhr.send(
      `dataname=${encodeURIComponent(dataname)}&surrogate=${encodeURIComponent(surrogate)}&text=${encodeURIComponent(html)}&token=${encodeURIComponent(token)}${ownerUser ? `&owner=${encodeURIComponent(ownerUser)}` : ""}`
    );

    showFlashMessage?.("💾 Saved");
    tMod = 0;
  });
}




function sanitizeForSave(html = "") {
  if (!html) return "";

  // 1) Remove <script> ... </script>
  html = html.replace(/<script[\s\S]*?<\/script>/gi, "");

  // 2) Remove <style> ... </style>
  html = html.replace(/<style[\s\S]*?<\/style>/gi, "");

  // 3) Remove HTML comments <!-- ... -->
  html = html.replace(/<!--[\s\S]*?-->/g, "");

  // 4) Remove inline JS handlers (onclick="...", onload="...", etc.)
  html = html.replace(/\son\w+="[^"]*"/gi, "");
  html = html.replace(/\son\w+='[^']*'/gi, "");

  // 5) Remove javascript: URLs
  html = html.replace(/(href|src)\s*=\s*["']javascript:[^"']*["']/gi, "");

  // 6) Remove dangerous embed/iframe/object
  html = html.replace(/<\/?(iframe|object|embed|applet|meta|link)[^>]*>/gi, "");

  // DO NOT:
  // - modify <br>
  // - modify <div> or <p>
  // - modify spans
  // - convert headings
  // - rewrite attributes
  // - collapse whitespace

  return html;
}


function extractTitleFromHTML(html = "") {
    // Convert <br> to newline
    let text = html.replace(/<br\s*\/?>/gi, "\n");

    // Convert common block tags + headings to newline
    text = text.replace(/<\/?(div|p|section|article|blockquote|h[1-6])[^>]*>/gi, "\n");

    // Strip all remaining tags
    text = text.replace(/<\/?[^>]+>/g, "");

    // Split into lines
    const lines = text.split(/\n/);

    // Find first non-empty line
    for (const line of lines) {
        const t = line.trim();
        if (t.length > 0) return t;
    }

    return "Untitled";
}





function deleteData() {
  const segments = window.location.pathname.split("/").filter(Boolean);
  const surrogate = segments.length > 1 ? segments[1] : "0";

  const editToggle = document.querySelector(".edit-mode-toggle");
  if (!editToggle || !editToggle.checked) return;

  const text = String(window._T2_RAWTEXT || "");
  const dataname = text.split("\n")[0];

  if (!surrogate || surrogate === "0") {
    alert("⚠️ Please select an item to delete!");
    return;
  }

  console.log(`🗑 Deleting surrogate: ${surrogate}`);

  const xmlhttp = window.XMLHttpRequest
    ? new XMLHttpRequest()
    : new ActiveXObject("Microsoft.XMLHTTP");

  xmlhttp.onreadystatechange = function () {
    if (xmlhttp.readyState === 4 && xmlhttp.status === 200) {
      console.log("✅ Delete Response:", xmlhttp.responseText);
      window._T2_RAWHTML = "";
      window._T2_RAWTEXT = "";
      removeCurrentFromList(surrogate);
    }
  };

  xmlhttp.open("POST", "/datadelete.php", true);
  xmlhttp.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
  xmlhttp.send(
    "dataname=" + encodeURIComponent(dataname) +
    "&surrogate=" + encodeURIComponent(surrogate)
  );
}



function selectItem(surrogate, token = null, listContainer = null) {

  console.log("📄 selectItem called:", token, surrogate);
  const previousSurrogate = String(window.currentSurrogate || "").trim();
  const nextSurrogate = String(surrogate || "").trim();

  // Save pending drawing changes before leaving the current item.
  // The autosave routine itself skips when nothing is dirty.
  if (previousSurrogate && nextSurrogate && previousSurrogate !== nextSurrogate) {
    window.autoSaveAnnotations?.({
      force: true,
      reason: "item-switch",
      from: previousSurrogate,
      to: nextSurrogate
    });
  }

  function resolvePreferredMainTab() {
    const xmlModeActive = window.getPreferredScoreViewMode?.() === "xml";
    if (xmlModeActive) return "pdfTab";

    const savedTab = localStorage.getItem("twActiveMainTab");
    if (savedTab === "pdfTab" || savedTab === "textTab") return savedTab;

    if (window.currentActiveTab === "pdfTab" || window.currentActiveTab === "textTab") {
      return window.currentActiveTab;
    }
    return "pdfTab";
  }

  function enforceMainTab(targetTab) {
    if (targetTab !== "pdfTab" && targetTab !== "textTab") return;
    const alreadyActive = String(window.currentActiveTab || "") === targetTab;
    const alreadyVisible = !!document.getElementById(targetTab + "Content")?.classList.contains("active");
    if (alreadyActive && alreadyVisible) return;
    if (typeof window.switchTab === "function") {
      window.switchTab(targetTab);
      return;
    }
    // Fallback when tab system is not ready yet.
    document.querySelectorAll(".main-tab-content.active").forEach(el => el.classList.remove("active"));
    document.getElementById(targetTab + "Content")?.classList.add("active");
    window.currentActiveTab = targetTab;
  }

  function isFileManagerActive() {
    const importBtn = document.querySelector('.footer-tab-btn[data-target="importTab"]');
    if (importBtn?.classList.contains("active")) return true;
    return !!document.getElementById("importTabContent")?.classList.contains("active");
  }
    
  // 🏠 Leave home mode ONLY if home is active
  if (document.getElementById("homeTabContent")) {
    document.getElementById("homeTabContent").remove();
  }

  // Keep focus on File Manager when selecting items from TW import trees.
  if (!isFileManagerActive()) {
    // Always enforce one consistent main tab before loading item content.
    enforceMainTab(resolvePreferredMainTab());
  }

  
  if (!token) {
    const el = document.querySelector(`.list-sub-item[data-value="${surrogate}"]`);
    token = el?.dataset.token || el?.closest("[data-token]")?.dataset.token || null;
  }

  // Cache owner list JSON for this token when online (for offline refresh)
  const ownerHint = window.currentListOwnerUsername || window.currentOwner?.username || null;
  if (navigator.onLine) {
    cacheOwnerJsonForToken?.(token, ownerHint);
  } else {
    queueOwnerCacheToken?.(token);
  }

  
//Critical fix: remove old highlight spans BEFORE loading new text
  highlightSelectedItem(surrogate, document.getElementById(`list-${token}`) || document);

  // 🧩 Prevent accidental double-processing
  if (
    window.currentSurrogate === surrogate &&
    window.currentListToken === token &&
    Date.now() - (window.lastItemLoadTimes[surrogate] || 0) < 80
  ) {
    // highlightSelectedItem(surrogate, document.getElementById(`list-${token}`) || document);
    return;
  }

  // 🧠 Update globals
  window.lastItemLoadTimes[surrogate] = Date.now();
  window.currentListToken = token;
  try {
    localStorage.setItem("last-selected-list-token", token || "");
    localStorage.setItem("last-selected-surrogate", String(surrogate || ""));
    const ownerHint = window.currentListOwnerUsername || window.currentOwner?.username || "";
    if (ownerHint) localStorage.setItem("last-selected-list-owner", ownerHint);
  } catch {}

  const el = document.querySelector(`.list-sub-item[data-value="${surrogate}"]`);
  const itemOwner = el?.dataset.owner;
  window.currentItemOwner = itemOwner;
  window.currentUserItemRoleRank = parseInt(el?.dataset.itemRoleRank || "0", 10);
  window.canEditCurrentSurrogate = el?.dataset.canEdit === "1";

  const newURL = `/${token}/${surrogate}`;
  window.history.pushState({}, "", newURL);

  // 🧾 Update title bar
  const titleEl = document.getElementById("selectedItemTitle");
  if (titleEl) {
    const rawTitle =
      el?.querySelector(".item-subject")?.textContent?.trim() ||
      el?.querySelector(".item-title")?.textContent?.trim() ||
      "";
    const title = rawTitle
      .replace(/^•\s*/, "")
      .replace(/\s*\[[^\]]+\]\s*$/, "")
      .trim();
    if (title) {
      titleEl.textContent = title;
    } else {
      titleEl.textContent = window.currentListTitle || (window.translations?.select_an_item || "Select an item");
    }
  }

  // 🩵 OFFLINE BRANCH
    // 🩵 OFFLINE BRANCH
    if (!navigator.onLine) {
      let text = localStorage.getItem(`offline-text-${surrogate}`);
    
      if (!text) {
        // fallback to modern offline loader
        text = getOfflineText?.(surrogate) ?? "";
      }
    
      if (text) {
        selectOfflineItem(surrogate, text);
      } else {
        showFlashMessage("⚠️ This item is not available offline.");
      }
      return;
    }


  // 🌐 ONLINE BRANCH
  const syncState = window.TWPlaySync || {};
  if (syncState.suppressPublishOnce) {
    syncState.suppressPublishOnce = false;
  } else {
    twPublishPlaySelection(token, String(surrogate));
  }
  
  window.currentSurrogate = surrogate;
  
  getText(surrogate).then(async () => {
    // window.currentSurrogate = surrogate;
    

    // ✅ Highlight sidebar item   ---already done
    // highlightSelectedItem(surrogate, listContainer || document);

    // ✅ Initialize inline comment logic
    const editable = document.getElementById("myTextarea");
    if (editable) {
      initTextComments("#myTextarea", {
        owner: window.currentItemOwner,
        annotator: window.SESSION_USERNAME,
        surrogate
      });

      // 💬 Load user’s saved comment bubbles from Cloudflare R2
      await loadUserComments(window.currentItemOwner, surrogate);
    }

    // ✅ Refresh item details / products tab (if visible)
    const pdfVisible = document.getElementById("pdfTabContent")?.classList.contains("active");
    const activeTab = window.currentActiveTab || (pdfVisible ? "pdfTab" : "textTab");
    if (activeTab === "pdfTab") {
      if (navigator.onLine) {
        window.loadPDF(surrogate, null);
      } else {
        window.loadPDFOffline(surrogate);
      }
    }

    // ✅ Refresh music tab (if open)
    if (document.getElementById("musicTabContent")?.classList.contains("visible")) {
      window.showMusicPanelForCurrentItem?.(surrogate);
    }

    // ♻️ Background cache refresh (once per hour)
    if (twBackgroundNetworkOk() && typeof updateOfflineCachesForSurrogate === "function") {
      const lastUpdate = window.lastCacheUpdate[surrogate] || 0;
      if (Date.now() - lastUpdate > 3600000) {
        setTimeout(() => {
          console.log(`♻️ Background cache refresh for surrogate ${surrogate}`);
          updateOfflineCachesForSurrogate(surrogate);
          window.lastCacheUpdate[surrogate] = Date.now();
        }, 200);
      }
    }
  });
  
  logStep("selectItem");
}

async function cacheOwnerJsonForToken(token, ownerHint = null) {
  try {
    if (!token || !navigator.onLine || !("caches" in window)) return;
    if (!twBackgroundNetworkOk()) {
      queueOwnerCacheToken(token);
      if (ownerHint) queueOwnerCacheToken(ownerHint);
      return;
    }
    const stableKey = `/offline/owners/${encodeURIComponent(token)}.json`;
    const cache = await caches.open("textwhisper-cache-manual");
    const existing = await cache.match(stableKey);
    if (existing) return;

    const res = await fetch(`/getOwnersListsJSON.php?token=${encodeURIComponent(token)}`, {
      credentials: "include"
    });
    if (!res.ok) {
      if (ownerHint) {
        return await cacheOwnerJsonForToken(ownerHint, null);
      }
      return;
    }
    const ct = res.headers.get("Content-Type") || "";
    if (!ct.includes("application/json")) {
      if (ownerHint) {
        return await cacheOwnerJsonForToken(ownerHint, null);
      }
      return;
    }
    const data = await res.clone().json().catch(() => null);
    if (!data) {
      if (ownerHint) {
        return await cacheOwnerJsonForToken(ownerHint, null);
      }
      return;
    }

    const body = JSON.stringify(data);
    await cache.put(stableKey, new Response(body, {
      headers: { "Content-Type": "application/json" }
    }));

    const ownerUsername = data?.owner?.username;
    if (ownerUsername && ownerUsername !== token) {
      const ownerKey = `/offline/owners/${encodeURIComponent(ownerUsername)}.json`;
      await cache.put(ownerKey, new Response(body, {
        headers: { "Content-Type": "application/json" }
      }));
    }

    if (ownerHint && ownerHint !== token && ownerHint !== ownerUsername) {
      const ownerKey = `/offline/owners/${encodeURIComponent(ownerHint)}.json`;
      await cache.put(ownerKey, new Response(body, {
        headers: { "Content-Type": "application/json" }
      }));
    }
  } catch {}
}

function queueOwnerCacheToken(token) {
  try {
    if (!token) return;
    const raw = localStorage.getItem("pending-cache-tokens") || "[]";
    const set = new Set(JSON.parse(raw));
    set.add(token);
    localStorage.setItem("pending-cache-tokens", JSON.stringify(Array.from(set)));
  } catch {}
}

function flushOwnerCacheQueue() {
  try {
    const raw = localStorage.getItem("pending-cache-tokens") || "[]";
    const tokens = JSON.parse(raw);
    if (!Array.isArray(tokens) || !tokens.length) return;
    localStorage.removeItem("pending-cache-tokens");
    tokens.forEach(t => cacheOwnerJsonForToken(t));
  } catch {}
}

window.addEventListener("online", () => {
  twProbeNetworkHealth(true).then((ok) => {
    if (ok) flushOwnerCacheQueue();
  });
});

if (navigator.onLine) {
  setTimeout(() => { twProbeNetworkHealth(true); }, 0);
}



function selectOfflineItem(surrogate, text) {
  console.log("📴 Offline select item:", surrogate);


    highlightSelectedItem(surrogate, document);


  // 🧩 Prevent quick double-clicks
  window.lastItemLoadTimes ||= {};
  if (
    window.currentSurrogate === surrogate &&
    Date.now() - (window.lastItemLoadTimes[surrogate] || 0) < 80
  ) {
    // highlightSelectedItem(surrogate, document);
    return;
  }
  window.lastItemLoadTimes[surrogate] = Date.now();

  const el = document.querySelector(`.list-sub-item[data-value="${surrogate}"]`);
  if (!el) {
    console.warn("⚠️ selectOfflineItem: no element for", surrogate);
    return;
  }

  const listEl = el.closest(".list-group-item, .list-contents");
  const listToken = listEl?.dataset.group || listEl?.dataset.token || "";
  if (!listToken) {
    console.warn("⚠️ No list token found for offline item:", surrogate);
    return;
  }

  const owner = el.dataset.owner || window.SESSION_USERNAME;

  // 🧾 Set globals
  window.currentSurrogate = surrogate;
  window.currentItemOwner = owner;
  window.currentListToken = listToken;
  window.currentUserItemRoleRank = parseInt(el.dataset.itemRoleRank || "0", 10);
  window.canEditCurrentSurrogate = el.dataset.canEdit === "1";

  // 🔗 Update URL using that list token
  const newUrl = `/${listToken}/${surrogate}`;
  if (window.location.pathname !== newUrl) {
    window.history.pushState({}, "", newUrl);
  }

  // 🧾 Load cached text
  const txt = text || "";

  const looksLikeHTML = /<\/?[a-z][\s\S]*>/i.test(txt);
  const html = looksLikeHTML
    ? txt.replace(/\r?\n/g, "<br>")
    : escapeHtml(txt).replace(/\r?\n/g, "<br>");

  window._T2_RAWHTML = html;
  window._T2_RAWTEXT = htmlToPlainText(html);

  // 🟦 NEW: Load cached comments + highlights (textmarks)
  try {
    const annotator = window.SESSION_USERNAME || owner;
    const raw = localStorage.getItem(`offline-comments-${surrogate}-${annotator}`);

    if (raw) {
      const parsed = JSON.parse(raw);
      window.textmarks = parsed.comments || [];
    } else {
      window.textmarks = [];
    }

    // Apply marks (highlights + (pre-span) comments + drawings)
    window.textmarks = window.textmarks || [];
  } catch (err) {
    console.warn("⚠️ Failed to load offline textmarks:", err);
    window.textmarks = [];
  }

  if (typeof window.loadUserComments === "function") {
    window.loadUserComments(owner, surrogate);
  }

  // -------------------------------------------------------------

  // 🧱 Update title
  const titleEl = document.getElementById("selectedItemTitle");
  if (titleEl) {
    const cleanText = window._T2_RAWTEXT;
    titleEl.textContent = cleanText.split("\n")[0]?.trim() || "Untitled";
  }

  //Highlight and refresh -- Already done
//   highlightSelectedItem(surrogate, document);
  window.showMusicPanelForCurrentItem?.(surrogate);

  const pdfTab = document.getElementById("pdfTabContent");
  if (pdfTab?.classList.contains("active") || pdfTab?.classList.contains("visible")) {
    window.loadPDFOffline?.(surrogate);
  }
}



async function cacheTempPDFForOffline(url) {
  try {
    const res = await fetch(url);
    const type = res.headers.get("Content-Type");

    if (!res.ok) throw new Error("Response not OK");
    if (!type.includes("pdf")) throw new Error("Response is not a PDF");

    const cache = await caches.open("textwhisper-offline-pdfs");
    await cache.put(url, res.clone());

    console.log("✅ PDF successfully cached:", url);
  } catch (err) {
    console.warn("❌ PDF not cached properly:", err);
  }
}

//updateOfflineCachesForSurrogate, need to test if something similair is in JSFunctions_Offline.js



function htmlToPlainText(html = "") {
  return html
    .replace(/<br\s*\/?>/gi, "\n")
    .replace(/<\/(div|p|li|section|article)>/gi, "\n")
    .replace(/<\/?[^>]+>/g, "")
    .replace(/&nbsp;/g, " ")
    .replace(/&amp;/g, "&")
    .replace(/&lt;/g, "<")
    .replace(/&gt;/g, ">")
    .trim();
}


function sanitizeForeignHtmlBlocks(html = "") {
  // Create a temporary DOM container
  const div = document.createElement("div");
  div.innerHTML = html;

  // Define rules for what should be removed
  const isForeignBlock = (el) => {
    if (!el) return false;

    return (
      // Bing/AI lyric cards and containers
      (el.id && /^C_[0-9A-F]+/i.test(el.id)) ||
      el.classList.contains("b_acf_card") ||
      el.classList.contains("b_lyrics_card") ||
      el.classList.contains("b_acf_bckgnd") ||

      // Lyrics title blocks
      el.querySelector("h2.b_topTitle") ||

      // Very large styled divs (common in pasted widgets)
      ((el.tagName === "DIV") &&
        (el.style.width || el.style.height) &&
        (parseInt(el.style.width) > 300 || parseInt(el.style.height) > 300))
    );
  };

  // Remove offending blocks
  div.querySelectorAll("div").forEach((el) => {
    if (isForeignBlock(el)) el.remove();
  });

  return div.innerHTML;
}


function cleanHtml(html = "") {
  if (!html) return "";

  // 1. Remove invisible zero-width Unicode characters (highlight killer)
  html = html.replace(/[\u200B\u200C\u200D\u2060\uFEFF\u202F]/g, "");

  // 2. Remove large foreign pasted widgets (lyric cards, bing embeds, etc.)
  html = stripForeignHtmlBlocks(html);

  // 3. Remove dangerous attributes (onclick, style, event handlers)
  html = html.replace(/\s*(on\w+)=["'][^"']*["']/gi, "");
  html = html.replace(/\s*style=["'][^"']*["']/gi, "");

  // 4. Remove stray <canvas> garbage or handles
  html = html.replace(/<canvas[^>]*>[\s\S]*?<\/canvas>/gi, "");

  // 5. Remove empty wrapper divs created by pasted content
  html = html.replace(/<div>\s*<\/div>/gi, "");

  return html;
}


// --------------------------------------------
// ------------ Cloak -------------------------
// --------------------------------------------

/* ============================================================
   TEXTWHISPER — CLOAK SYSTEM (Top + Bottom)
   ============================================================ */

/* ============================================================
   TEXTWHISPER — CLOAK SYSTEM (Top + Bottom + Floating Toolbar)
   ============================================================ */

/* ============================================================
   TEXTWHISPER — CLOAK SYSTEM (Top + Bottom + Floating Toolbar)
   ============================================================ */

(function initCloakSystem() {

  // Master cloak anchor = whole area that contains both text columns
  const CLOAK_AREA = document.querySelector(".textareas-container");
  if (!CLOAK_AREA) return;

  const parent = CLOAK_AREA;
  const parentStyle = getComputedStyle(parent);
  if (parentStyle.position === "static") parent.style.position = "relative";

  /* ----------------------------
   * GLOBAL STATE
   * ---------------------------- */
  window.CLOAK_ENABLED = false;
  window.CLOAK_BLUR = 5;  // initial ~40%

  // Estimate line height from first textarea if present
  const cs = { lineHeight: "20px", fontSize: "16px" };
  const lineHeight =
    parseFloat(cs.lineHeight) ||
    parseFloat(cs.fontSize || "16") * 1.4 ||
    24;

  let topHeight = 0;                // top cloak fully at top
  let bottomStart = lineHeight * 3; // bottom shows ~3 lines

  /* ----------------------------
   * CREATE OVERLAYS
   * ---------------------------- */
  const topOverlay = document.createElement("div");
  const bottomOverlay = document.createElement("div");
  topOverlay.className = "tw-cloak-overlay tw-cloak-top";
  bottomOverlay.className = "tw-cloak-overlay tw-cloak-bottom";

  const topMask = document.createElement("div");
  const bottomMask = document.createElement("div");
  topMask.className = "tw-cloak-mask";
  bottomMask.className = "tw-cloak-mask";

  const topHandle = document.createElement("div");
  const bottomHandle = document.createElement("div");
  topHandle.className = "tw-cloak-handle";
  bottomHandle.className = "tw-cloak-handle";

  topOverlay.appendChild(topMask);
  topMask.appendChild(topHandle);

  bottomOverlay.appendChild(bottomMask);
  bottomMask.appendChild(bottomHandle);

  parent.appendChild(topOverlay);
  parent.appendChild(bottomOverlay);

  /* ----------------------------
   * FLOATING BLUR TOOLBAR
   * ---------------------------- */
  const toolbar = document.createElement("div");
  toolbar.id = "twCloakToolbar";
  toolbar.innerHTML = `
    <span>blur</span>
    <input id="twCloakBlur" type="range" min="0" max="12" value="${window.CLOAK_BLUR}" />
  `;
  parent.appendChild(toolbar);

  const blurSlider = toolbar.querySelector("#twCloakBlur");

  /* ----------------------------
   * HEAT COLOR BLENDING
   * ---------------------------- */
  function applyHeat(maskEl) {
    const blur = window.CLOAK_BLUR;
    const heat = Math.min(1, blur / 12);

    const r = Math.round(255 - (255 - 150) * heat);
    const g = Math.round(250 - (250 - 190) * heat);
    const b = Math.round(200 - (200 - 255) * heat);
    const alpha = 0.3 + heat * 0.25;

    maskEl.style.backgroundColor = `rgba(${r},${g},${b},${alpha})`;
    maskEl.style.backdropFilter = blur > 0 ? `blur(${blur}px)` : "none";
  }

  /* ----------------------------
   * GEOMETRY SYNC
   * ---------------------------- */
  function syncGeometry() {
    const rect = CLOAK_AREA.getBoundingClientRect();
    const parentRect = parent.getBoundingClientRect();

    const left = rect.left - parentRect.left;
    const top = rect.top - parentRect.top;
    const width = rect.width;
    const height = rect.height;

    // Stretch overlays across whole container
    [topOverlay, bottomOverlay].forEach(el => {
      el.style.left = left + "px";
      el.style.top = top + "px";
      el.style.width = width + "px";
      el.style.height = height + "px";
    });

    // Clamp drag limits
    const maxTop = height - 40;
    topHeight = Math.max(0, Math.min(topHeight, maxTop));

    const maxBottom = height - 40;
    bottomStart = Math.max(40, Math.min(bottomStart, maxBottom));

    // Apply geometry
    topMask.style.height = topHeight + "px";
    bottomMask.style.top = bottomStart + "px";
    bottomMask.style.height = Math.max(0, height - bottomStart) + "px";

    // Floating toolbar (top-right of CLOAK_AREA)
    toolbar.style.top = "6px";
    toolbar.style.right = "8px";
  }

  requestAnimationFrame(syncGeometry);
  window.addEventListener("resize", () => requestAnimationFrame(syncGeometry));
  window.addEventListener("scroll", () => requestAnimationFrame(syncGeometry), true);

  /* ----------------------------
   * DRAGGING LOGIC
   * ---------------------------- */
  const dragTop = { active: false, startY: 0, startVal: 0 };
  const dragBottom = { active: false, startY: 0, startVal: 0 };

  function beginDrag(dragObj, getter, setter) {
    return function (e) {
      dragObj.active = true;
      dragObj.startY = e.touches ? e.touches[0].clientY : e.clientY;
      dragObj.startVal = getter();
      document.body.style.userSelect = "none";
      e.preventDefault();
    };
  }

  topHandle.addEventListener("mousedown", beginDrag(dragTop, () => topHeight, v => topHeight = v));
  bottomHandle.addEventListener("mousedown", beginDrag(dragBottom, () => bottomStart, v => bottomStart = v));

  topHandle.addEventListener("touchstart", beginDrag(dragTop, () => topHeight, v => topHeight = v), { passive:false });
  bottomHandle.addEventListener("touchstart", beginDrag(dragBottom, () => bottomStart, v => bottomStart = v), { passive:false });

  window.addEventListener("mousemove", e => {
    if (!dragTop.active && !dragBottom.active) return;

    const y = e.clientY;
    if (dragTop.active) topHeight = dragTop.startVal + (y - dragTop.startY);
    if (dragBottom.active) bottomStart = dragBottom.startVal + (y - dragBottom.startY);

    syncGeometry();
  });

  window.addEventListener("touchmove", e => {
    if (!dragTop.active && !dragBottom.active) return;

    const y = e.touches[0].clientY;
    if (dragTop.active) topHeight = dragTop.startVal + (y - dragTop.startY);
    if (dragBottom.active) bottomStart = dragBottom.startVal + (y - dragBottom.startY);

    syncGeometry();
    e.preventDefault();
  }, { passive:false });

  window.addEventListener("mouseup", () => {
    dragTop.active = dragBottom.active = false;
    document.body.style.userSelect = "";
  });
  window.addEventListener("touchend", () => {
    dragTop.active = dragBottom.active = false;
    document.body.style.userSelect = "";
  });

  /* ----------------------------
   * ON/OFF TOGGLE
   * ---------------------------- */
  const mainToggle = document.getElementById("toggleCloak");

  function setCloakEnabled(on) {
    window.CLOAK_ENABLED = !!on;

    const display = on ? "block" : "none";
    topOverlay.style.display = display;
    bottomOverlay.style.display = display;
    toolbar.style.display = on ? "flex" : "none";

    if (mainToggle && mainToggle.checked !== on) {
      mainToggle.checked = on;
    }
  }

  if (mainToggle) {
    mainToggle.addEventListener("change", () => {
      setCloakEnabled(mainToggle.checked);
    });
  }

  /* ----------------------------
   * BLUR CONTROL
   * ---------------------------- */
  blurSlider.addEventListener("input", () => {
    window.CLOAK_BLUR = parseInt(blurSlider.value, 10) || 0;
    applyHeat(topMask);
    applyHeat(bottomMask);
  });

  // Initial heat + visibility
  applyHeat(topMask);
  applyHeat(bottomMask);
  setCloakEnabled(mainToggle?.checked || false);

})();
