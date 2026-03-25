const epState = {
  groups: [],
  ownerProfileType: "person",
  ownerGroupType: "",
  locale: "",
  events: [],
  allEvents: [],
  currentGroupId: null,
  memberId: null,
  ownerId: null,
  invitedMembers: [],
  groupMembers: {},
  ownerToken: "",
  editMode: false,
  eventFilters: {
    myEvents: false,
    fromDate: "",
    category: "",
    groupId: ""
  },
  categoryColors: {},
  categoryCatalog: [],
  memberSort: { key: "group", dir: "asc" },
  inviteSearch: "",
  memberSearch: "",
  currentGroupMembers: [],
  currentGroupData: null,
  attendanceFilters: {
    mode: "year",
    year: String(new Date().getFullYear()),
    from: "",
    to: "",
    groupId: "",
    category: ""
  },
  attendanceReport: null,
  attendanceCollapsedRoles: {},
  polls: [],
  dmUnreadByMemberId: {},
  pollSyncTimer: null,
  pollSyncBusy: false,
  pollSyncSignature: "",
  calloutEventId: 0,
  calloutWindowDays: 7,
  imageSuggestions: []
};

const EP_R2_UPLOAD_BASE = "https://r2-worker.textwhisper.workers.dev";
const EP_R2_PUBLIC_BASE = "https://pub-1afc23a510c147a5a857168f23ff6db8.r2.dev";

const epEl = (id) => document.getElementById(id);

function epGetChoirSourceDocument() {
  if (window.parent && window.parent !== window && window.parent.document) {
    return window.parent.document;
  }
  return document;
}

function epGetChoirItems() {
  const doc = epGetChoirSourceDocument();
  const nodes = doc.querySelectorAll(
    ".list-group-wrapper[data-group='invited-lists'] .group-item"
  );
  const sidebarItems = Array.from(nodes).map((node) => {
    const titleEl = node.querySelector(".list-title");
    const avatarEl = node.querySelector(".list-owner-avatar");
    const title = titleEl ? titleEl.textContent.trim() : "";
    return {
      key: `sidebar-${node.dataset.group || ""}`,
      token: node.dataset.group || "",
      title,
      avatarUrl: avatarEl ? avatarEl.getAttribute("src") : "",
      type: "sidebar"
    };
  }).filter((item) => item.token && item.title);
  if (sidebarItems.length) return sidebarItems;
  return (epState.twGroups || []).map((group) => ({
    key: `tw-${group.token}`,
    token: group.token,
    title: group.title || "Group",
    avatarUrl: group.avatarUrl || "",
    type: "tw_group"
  }));
}

function epGetOwnerAvatarItem() {
  const parentWindow = window.parent && window.parent !== window ? window.parent : null;
  if (!parentWindow) return null;
  const ownerName = parentWindow.currentOwner?.display_name
    || parentWindow.currentOwner?.username
    || parentWindow.SESSION_DISPLAY_NAME
    || parentWindow.SESSION_USERNAME
    || "";
  if (!ownerName) return null;
  const avatarUrl = parentWindow.currentOwner?.avatar_url || "";
  const token = parentWindow.currentOwnerToken || parentWindow.SESSION_USERNAME || ownerName;
  return {
    key: `owner-${token}`,
    token,
    title: ownerName,
    avatarUrl,
    type: "owner"
  };
}

function epGetChoirInitials(title) {
  if (!title) return "?";
  const parts = title.trim().replace(/^•\s*/, "").split(/\s+/);
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

const EP_AVATAR_COLORS = [
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

function epHashString(value) {
  const str = String(value || "");
  let hash = 2166136261;
  for (let i = 0; i < str.length; i += 1) {
    hash ^= str.charCodeAt(i);
    hash += (hash << 1) + (hash << 4) + (hash << 7) + (hash << 8) + (hash << 24);
  }
  return hash >>> 0;
}

function epAvatarInitials(name) {
  if (!name) return "?";
  const parts = String(name).trim().split(/\s+/);
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

function epAvatarDataUrl(seed, initials) {
  const hash = epHashString(seed);
  const primary = EP_AVATAR_COLORS[hash % EP_AVATAR_COLORS.length];
  const secondary = EP_AVATAR_COLORS[(hash >>> 8) % EP_AVATAR_COLORS.length];
  const accent = primary === secondary
    ? EP_AVATAR_COLORS[(hash >>> 16) % EP_AVATAR_COLORS.length]
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

function epResolveAvatarUrl(member, fallbackName) {
  if (typeof window.twResolveAvatarUrl === "function") {
    return window.twResolveAvatarUrl(member, fallbackName);
  }
  const raw = (member?.avatar_url || member?.avatarUrl || "").trim();
  if (raw && !raw.includes("default-avatar.png")) return raw;
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
  return epAvatarDataUrl(seed, epAvatarInitials(name || seed));
}

function epHandleAvatarError(img) {
  if (!img || img.dataset.avatarFallbackDone) return;
  const seed = img.dataset.avatarSeed || img.alt || "user";
  const name = img.dataset.avatarName || img.alt || "";
  img.dataset.avatarFallbackDone = "1";
  img.onerror = null;
  img.src = epAvatarDataUrl(seed, epAvatarInitials(name || seed));
}

function epLoadChoirState(key, fallback) {
  try {
    const raw = localStorage.getItem(key);
    return raw ? JSON.parse(raw) : fallback;
  } catch (err) {
    return fallback;
  }
}

function epSaveChoirState(key, value) {
  try {
    localStorage.setItem(key, JSON.stringify(value));
  } catch (err) {
    // ignore storage errors
  }
}

async function epLoadCategoryCatalog() {
  const data = await epGet("/ep_categories.php");
  if (data.status !== "OK") {
    epState.categoryCatalog = [];
    return;
  }
  epState.categoryCatalog = (data.categories || []).map((entry) => ({
    id: epNormalizeCategoryName(entry.category || entry.id || entry.name || ""),
    description: String(entry.description || ""),
    color: String(entry.color || "")
  })).filter((entry) => entry.id);
  epRefreshCategoryPickers();
}

function epNormalizeCategoryName(value) {
  return String(value || "").trim();
}

function epLoadCategoryColors() {
  epState.categoryColors = {};
}

function epSaveCategoryColors() {
  // no-op (DB is source of truth)
}

function epHslToHex(h, s, l) {
  const sat = s / 100;
  const light = l / 100;
  const c = (1 - Math.abs(2 * light - 1)) * sat;
  const x = c * (1 - Math.abs(((h / 60) % 2) - 1));
  const m = light - c / 2;
  let r = 0;
  let g = 0;
  let b = 0;
  if (h >= 0 && h < 60) {
    r = c;
    g = x;
  } else if (h >= 60 && h < 120) {
    r = x;
    g = c;
  } else if (h >= 120 && h < 180) {
    g = c;
    b = x;
  } else if (h >= 180 && h < 240) {
    g = x;
    b = c;
  } else if (h >= 240 && h < 300) {
    r = x;
    b = c;
  } else if (h >= 300 && h < 360) {
    r = c;
    b = x;
  }
  const toHex = (value) => {
    const hex = Math.round((value + m) * 255).toString(16).padStart(2, "0");
    return hex;
  };
  return `#${toHex(r)}${toHex(g)}${toHex(b)}`;
}

function epCategoryBaseColor(category) {
  const safe = String(category || "").trim().toLowerCase();
  if (!safe) {
    return "#d8dee9";
  }
  const hue = (epHashString(safe) * 137 + 90) % 360;
  return epHslToHex(hue, 70, 70);
}

function epGetCategoryColorHex(category) {
  const name = epNormalizeCategoryName(category);
  if (!name) return epCategoryBaseColor("");
  const entry = epFindCategoryEntry(name);
  if (entry && entry.color) return entry.color;
  const custom = epState.categoryColors && epState.categoryColors[name];
  if (custom) return custom;
  return epCategoryBaseColor(name);
}

function epSetCategoryColor(category, color) {
  const name = epNormalizeCategoryName(category);
  if (!name) return;
  const value = String(color || "").trim();
  if (!value) return;
  const entry = epFindCategoryEntry(name);
  if (entry) {
    entry.color = value;
  }
  epState.categoryColors = epState.categoryColors || {};
  epState.categoryColors[name] = value;
}

function epEnsureCategoryInCatalog(category) {
  const name = epNormalizeCategoryName(category);
  if (!name) return;
  if (!epFindCategoryEntry(name)) {
    epState.categoryCatalog.push({
      id: name,
      description: "",
      color: epState.categoryColors?.[name] || ""
    });
  }
}

function epFindCategoryEntry(category) {
  const name = epNormalizeCategoryName(category);
  if (!name) return null;
  return (epState.categoryCatalog || []).find(
    (entry) => String(entry.id || "").trim().toLowerCase() === name.toLowerCase()
  ) || null;
}

function epUpsertCategoryEntry(entry) {
  const id = epNormalizeCategoryName(entry.id);
  if (!id) return null;
  const existing = epFindCategoryEntry(id);
  if (existing) {
    existing.description = entry.description || "";
    existing.color = entry.color || existing.color || "";
  } else {
    epState.categoryCatalog.push({
      id,
      description: entry.description || "",
      color: entry.color || ""
    });
  }
  return epFindCategoryEntry(id);
}

async function epCreateCategoryIfMissing(category, overrides = {}) {
  const name = epNormalizeCategoryName(category);
  if (!name || !epState.canManage) return;
  if (epFindCategoryEntry(name)) return;
  const payload = {
    action: "create",
    category: name,
    description: overrides.description || "",
    color: overrides.color || epGetCategoryColorHex(name)
  };
  const res = await epPost("/ep_categories.php", payload);
  if (res.status === "OK") {
    epUpsertCategoryEntry({
      id: res.category?.category || name,
      description: res.category?.description || payload.description,
      color: res.category?.color || payload.color
    });
    epRefreshCategoryPickers();
  }
}

async function epUpdateCategoryEntry(category, changes) {
  const name = epNormalizeCategoryName(category);
  if (!name || !epState.canManage) return;
  const entry = epFindCategoryEntry(name);
  if (!entry) return;
  const payload = {
    action: "update",
    category: name,
    new_category: changes.newId || name,
    description: changes.description ?? entry.description ?? "",
    color: changes.color ?? entry.color ?? ""
  };
  const res = await epPost("/ep_categories.php", payload);
  if (res.status === "OK") {
    epUpsertCategoryEntry({
      id: payload.new_category_id,
      description: payload.description,
      color: payload.color
    });
    if (payload.new_category_id.toLowerCase() !== name.toLowerCase()) {
      epState.categoryCatalog = (epState.categoryCatalog || []).filter(
        (item) => String(item.id || "").trim().toLowerCase() !== name.toLowerCase()
      );
    }
    epRefreshCategoryPickers();
  }
}

async function epRenameCategory(oldId, newId) {
  const oldName = epNormalizeCategoryName(oldId);
  const newName = epNormalizeCategoryName(newId);
  if (!oldName || !newName || !epState.canManage) return;
  const entry = epFindCategoryEntry(oldName);
  if (!entry) return;
  await epUpdateCategoryEntry(oldName, { newId: newName, description: entry.description, color: entry.color });
}

async function epDeleteCategory(category, confirmUse = false) {
  const name = epNormalizeCategoryName(category);
  if (!name || !epState.canManage) return;
  const res = await epPost("/ep_categories.php", {
    action: "delete",
    category: name,
    confirm: confirmUse ? 1 : 0
  });
  if (res.status === "warn") {
    const count = Number(res.count || 0);
    if (confirm(`"${name}" is used by ${count} event${count === 1 ? "" : "s"}. Remove anyway?`)) {
      return epDeleteCategory(name, true);
    }
    return;
  }
  if (res.status === "OK") {
    epState.categoryCatalog = (epState.categoryCatalog || []).filter(
      (entry) => String(entry.id || "").trim().toLowerCase() !== name.toLowerCase()
    );
    epFlashMessage("Category removed");
    epRefreshCategoryPickers();
  } else if (res.status === "error") {
    alert(res.message || "Unable to remove category.");
  }
}

function epSetChoirSelection(choir) {
  epSaveChoirState("epChoirSelected", choir);
}

function epUpdateChoirRecents(choir) {
  const recents = epLoadChoirState("epChoirRecent", []);
  const next = [choir, ...recents.filter((item) => item.key !== choir.key)];
  epSaveChoirState("epChoirRecent", next.slice(0, 12));
}

function epBuildChoirList() {
  const panel = epEl("epChoirPanel");
  if (!panel) return;
  panel.querySelectorAll(".ep-choir-chip, .ep-choir-empty").forEach((node) => node.remove());
  epSyncSelectedGroupFromParent();
  const ownerItem = epGetOwnerAvatarItem();
  const items = epGetChoirItems();
  if (!items.length && !ownerItem) {
    const empty = document.createElement("span");
    empty.className = "ep-choir-empty";
    empty.textContent = "No groups yet.";
    panel.appendChild(empty);
    return;
  }
  const selected = epLoadChoirState("epChoirSelected", null);
  const recents = epLoadChoirState("epChoirRecent", []);
  const recentOrder = recents.map((item) => item.key);
  const selectedItem = selected
    ? items.find((item) => item.key === selected.key) || null
    : null;
  const ordered = [];
  const seenTokens = new Set();
  const seenAvatars = new Set();
  const pushUnique = (item) => {
    if (!item) return;
    const tokenKey = item.token || item.key;
    const avatarKey = (item.avatarUrl || "").trim();
    if (seenTokens.has(tokenKey)) return;
    if (avatarKey && seenAvatars.has(avatarKey)) return;
    seenTokens.add(tokenKey);
    if (avatarKey) seenAvatars.add(avatarKey);
    ordered.push(item);
  };
  pushUnique(ownerItem);
  pushUnique(selectedItem);
  items
    .filter((item) => recentOrder.includes(item.key))
    .sort((a, b) => recentOrder.indexOf(a.key) - recentOrder.indexOf(b.key))
    .forEach((item) => pushUnique(item));
  items
    .filter((item) => !recentOrder.includes(item.key))
    .forEach((item) => pushUnique(item));
  ordered.slice(0, 4).forEach((item, index) => {
    const button = document.createElement("button");
    button.type = "button";
    button.className = "ep-choir-chip";
    if (selected && selected.key === item.key) {
      button.classList.add("active");
    }
    const resolvedAvatar = epResolveAvatarUrl(
      { avatar_url: item.avatarUrl, avatarUrl: item.avatarUrl, token: item.token, title: item.title },
      item.title
    );
    const avatar = `<img class="ep-choir-avatar"
      src="${epEscape(resolvedAvatar)}"
      alt="${epEscape(item.title)}"
      data-avatar-seed="${epEscape(item.token || item.key || item.title || "group")}"
      data-avatar-name="${epEscape(item.title)}"
      onerror="epHandleAvatarError(this)">`;
    button.innerHTML = `
      ${avatar}
      ${index === 0 ? `<span class="ep-choir-title">${epEscape(item.title)}</span>` : ""}
    `;
    button.addEventListener("click", (eventObj) => {
      eventObj.preventDefault();
      eventObj.stopPropagation();
      epSetChoirSelection(item);
      epUpdateChoirRecents(item);
      epBuildChoirList();
      const parentWindow = window.parent && window.parent !== window ? window.parent : window;
      const parentDoc = parentWindow.document;
      const header = parentDoc
        ? parentDoc.querySelector(`.group-item[data-group="${CSS.escape(item.token)}"] .list-header-row`)
        : null;
      if (header) {
        header.click();
        return;
      }
      if (typeof parentWindow.expandList === "function") {
        parentWindow.expandList(item.token);
        return;
      }
      if (typeof parentWindow.selectItem === "function") {
        parentWindow.selectItem(item.token, item.token);
        return;
      }
      if (item.token) {
        window.location.href = `/${encodeURIComponent(item.token)}`;
      }
    });
    panel.appendChild(button);
  });
}

function epSyncSelectedGroupFromParent() {
  const parentWindow = window.parent && window.parent !== window ? window.parent : null;
  if (!parentWindow) return false;
  const ownerItem = epGetOwnerAvatarItem();
  const items = epGetChoirItems();
  if (!items.length && !ownerItem) return false;
  const ownerToken = parentWindow.currentOwnerToken || "";
  const ownerUsername = parentWindow.currentOwner?.username || parentWindow.SESSION_USERNAME || "";
  const candidates = new Set();
  if (ownerToken) {
    candidates.add(ownerToken);
    if (!ownerToken.startsWith("invited-")) {
      candidates.add(`invited-${ownerToken}`);
    }
  }
  if (ownerUsername) {
    candidates.add(`invited-${ownerUsername}`);
  }
  const match = items.find((item) => candidates.has(item.token)) || ownerItem;
  if (!match) return false;
  epSetChoirSelection(match);
  if (match.type !== "owner") {
    epUpdateChoirRecents(match);
  }
  return true;
}

async function epLoadTwGroups() {
  const token = epState.username || "";
  if (!token) return;
  try {
    const res = await fetch(`/getOwnersListsJSON.php?token=${encodeURIComponent(token)}`, {
      credentials: "same-origin"
    });
    const data = await res.json();
    const acc = Array.isArray(data.accessible) ? data.accessible : [];
    const invitedRoot = acc.find((entry) => entry.relationship === "invited_group");
    const groups = invitedRoot && Array.isArray(invitedRoot.children)
      ? invitedRoot.children
      : [];
    epState.twGroups = groups.map((group) => ({
      token: group.token,
      title: group.title,
      avatarUrl: group.owner_avatar_url || ""
    })).filter((group) => group.token && group.title);
  } catch (err) {
    epState.twGroups = [];
  }
}

async function epInitChoirPicker() {
  await epLoadTwGroups();
  epBuildChoirList();
  epSyncSelectedGroupFromParent();
  const doc = epGetChoirSourceDocument();
  const listManager = doc.getElementById("listManager");
  if (!listManager) return;
  const handleSidebarClick = (eventObj) => {
    const row = eventObj.target.closest(".group-item");
    if (!row) return;
    const wrapper = row.closest(".list-group-wrapper");
    if (!wrapper || wrapper.dataset.group !== "invited-lists") return;
    const token = row.dataset.group || "";
    if (!token) return;
    const items = epGetChoirItems();
    const match = items.find((item) => item.token === token);
    if (!match) return;
    epSetChoirSelection(match);
    epUpdateChoirRecents(match);
    epBuildChoirList();
  };
  listManager.addEventListener("click", handleSidebarClick, true);
  const observer = new MutationObserver(() => {
    epBuildChoirList();
  });
  observer.observe(listManager, { childList: true, subtree: true });
  setInterval(() => {
    epSyncSelectedGroupFromParent();
  }, 2000);
}

async function epRefreshParentSidebarLists() {
  const parentWindow = window.parent && window.parent !== window ? window.parent : null;
  if (!parentWindow) return false;
  if (typeof parentWindow.loadUserContentLists !== "function") return false;
  try {
    await parentWindow.loadUserContentLists();
    return true;
  } catch (err) {
    return false;
  }
}

function epEscape(value) {
  return String(value)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

function epNormalize(value) {
  return String(value)
    .toLowerCase()
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .replace(/\s+/g, " ")
    .trim();
}

function epFormatEventMeta(event) {
  const dateLabel = epFormatDatePlain(event.starts_at);
  const parts = [dateLabel].filter(Boolean);
  return parts.map((part) => `<span>${part}</span>`).join('<span class="ep-sep">•</span>');
}

function epSeriesLabel(seriesId) {
  const raw = String(seriesId || "").trim();
  if (!raw) return "";
  const clean = raw.replace(/^SER-/i, "");
  const compact = clean.replace(/[^a-zA-Z0-9]/g, "");
  const short = compact ? compact.slice(-6).toUpperCase() : raw.slice(-6).toUpperCase();
  return `Series ${short}`;
}

function epWithOwner(url) {
  if (!epState.ownerToken) return url;
  const joiner = url.includes("?") ? "&" : "?";
  return `${url}${joiner}owner=${encodeURIComponent(epState.ownerToken)}`;
}

async function epGet(url) {
  const res = await fetch(epWithOwner(url), {
    credentials: "same-origin",
    cache: "no-store"
  });
  return res.json();
}

async function epPost(url, payload) {
  const body = { ...payload };
  if (epState.ownerToken && !body.owner) {
    body.owner = epState.ownerToken;
  }
  const res = await fetch(url, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    credentials: "same-origin",
    body: JSON.stringify(body)
  });
  return res.json();
}

function epFormatDate(value) {
  if (!value) return "";
  return `<strong>${value}</strong>`;
}

function epFlashMessage(text, duration = 2000) {
  if (typeof window.showFlashMessage === "function") {
    window.showFlashMessage(text, duration);
    return;
  }
  const flash = document.createElement("div");
  flash.textContent = text;
  flash.style.position = "fixed";
  flash.style.top = "50%";
  flash.style.left = "50%";
  flash.style.transform = "translate(-50%, -50%)";
  flash.style.background = "#222";
  flash.style.color = "#fff";
  flash.style.padding = "12px 20px";
  flash.style.borderRadius = "10px";
  flash.style.boxShadow = "0 4px 12px rgba(0,0,0,0.3)";
  flash.style.zIndex = "9999";
  flash.style.fontSize = "16px";
  flash.style.opacity = "0";
  flash.style.transition = "opacity 0.3s ease-in-out";

  document.body.appendChild(flash);

  requestAnimationFrame(() => {
    flash.style.opacity = "1";
  });

  setTimeout(() => {
    flash.style.opacity = "0";
    setTimeout(() => flash.remove(), 500);
  }, duration);
}

function epParseEventDate(value) {
  if (!value) return null;
  const trimmed = String(value).trim();
  if (!trimmed) return null;
  const normalized = trimmed.includes("T") ? trimmed : trimmed.replace(" ", "T");
  const date = new Date(normalized);
  return Number.isNaN(date.getTime()) ? null : date;
}

function epFormatDateLabel(date) {
  const locale = (navigator.languages && navigator.languages[0]) || navigator.language;
  const dateFormatter = new Intl.DateTimeFormat(locale, {
    day: "2-digit",
    month: "2-digit",
    year: "numeric"
  });
  const dateParts = dateFormatter.formatToParts(date);
  const order = dateParts
    .filter((part) => part.type === "day" || part.type === "month" || part.type === "year")
    .map((part) => part.type);
  const day = dateParts.find((part) => part.type === "day")?.value;
  const month = dateParts.find((part) => part.type === "month")?.value;
  const year = dateParts.find((part) => part.type === "year")?.value;
  return order[0] === "year" && day && month && year
    ? `${day}.${month}.${year}`
    : dateFormatter.format(date);
}

function epFormatDatePlain(value) {
  if (!value) return "";
  const date = epParseEventDate(value);
  if (!date) return String(value);
  const locale = (navigator.languages && navigator.languages[0]) || navigator.language;
  const weekday = new Intl.DateTimeFormat(locale, { weekday: "short" }).format(date);
  const dateLabel = epFormatDateLabel(date);
  const timeLabel = new Intl.DateTimeFormat(locale, {
    hour: "2-digit",
    minute: "2-digit"
  }).format(date);
  return `${weekday} ${dateLabel} ${timeLabel}`.trim();
}

function epFormatDateHeader(date) {
  if (!(date instanceof Date) || Number.isNaN(date.getTime())) return "";
  const locale = (navigator.languages && navigator.languages[0]) || navigator.language;
  const weekday = new Intl.DateTimeFormat(locale, { weekday: "short" }).format(date);
  const dateLabel = epFormatDateLabel(date);
  const timeLabel = new Intl.DateTimeFormat(locale, {
    hour: "2-digit",
    minute: "2-digit"
  }).format(date);
  return `${weekday} ${dateLabel} ${timeLabel}`.trim();
}

function epComputeEndDateFromWindow(fromDateValue, nextDaysValue) {
  const fromDate = String(fromDateValue || "").trim();
  const windowDays = Number(nextDaysValue || 0);
  if (!fromDate || !Number.isFinite(windowDays) || windowDays <= 0) return "";
  const start = new Date(`${fromDate}T00:00:00`);
  if (Number.isNaN(start.getTime())) return "";
  start.setDate(start.getDate() + Math.max(0, Math.floor(windowDays) - 1));
  const pad = (n) => String(n).padStart(2, "0");
  return `${start.getFullYear()}-${pad(start.getMonth() + 1)}-${pad(start.getDate())}`;
}

function epFormatMonthTitle(date, locale) {
  const monthLabel = new Intl.DateTimeFormat(locale, { month: "long" }).format(date);
  const yearLabel = new Intl.DateTimeFormat(locale, { year: "numeric" }).format(date);
  const monthName = (/^M\d{1,2}$/i.test(monthLabel) || /^\d+$/.test(monthLabel))
    ? new Intl.DateTimeFormat("en", { month: "long" }).format(date)
    : monthLabel;
  return `${monthName} ${yearLabel}`.trim();
}

function epPad2(value) {
  return String(value).padStart(2, "0");
}

function epGetLocalDateKey(date) {
  return `${date.getFullYear()}-${epPad2(date.getMonth() + 1)}-${epPad2(date.getDate())}`;
}

function epHashString(value) {
  let hash = 0;
  const text = String(value || "");
  for (let i = 0; i < text.length; i += 1) {
    hash = (hash * 31 + text.charCodeAt(i)) >>> 0;
  }
  return hash;
}

function epCategoryColor(category) {
  const base = epGetCategoryColorHex(category);
  return epToSoftColor(base, 0.35);
}

function epToSoftColor(color, alpha = 0.35) {
  const value = String(color || "").trim();
  const short = /^#([0-9a-fA-F]{3})$/;
  const long = /^#([0-9a-fA-F]{6})$/;
  if (short.test(value)) {
    const hex = value.slice(1);
    const r = parseInt(hex[0] + hex[0], 16);
    const g = parseInt(hex[1] + hex[1], 16);
    const b = parseInt(hex[2] + hex[2], 16);
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
  }
  if (long.test(value)) {
    const hex = value.slice(1);
    const r = parseInt(hex.slice(0, 2), 16);
    const g = parseInt(hex.slice(2, 4), 16);
    const b = parseInt(hex.slice(4, 6), 16);
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
  }
  return value;
}

function epBuildEventBackground(colors) {
  if (!colors.length) return "";
  if (colors.length === 1) return colors[0];
  if (colors.length === 2) {
    return `linear-gradient(135deg, ${colors[0]} 0% 50%, ${colors[1]} 50% 100%)`;
  }
  return `linear-gradient(135deg, ${colors[0]} 0% 33%, ${colors[1]} 33% 66%, ${colors[2]} 66% 100%)`;
}

function epEventIsCheckedIn(event) {
  const status = String(event?.my_checkin || "").toLowerCase();
  if (status === "in") return true;
  if (status === "out") return false;
  return Number(event?.is_member || 0) === 1 || Number(event?.all_members || 0) === 1;
}

function epBuildCalendarMonth(date, eventsByDate, locale) {
  const monthEl = document.createElement("div");
  monthEl.className = "ep-calendar-month";
  const title = epFormatMonthTitle(date, locale);
  monthEl.innerHTML = `<h3>${title}</h3>`;

  const weekdays = document.createElement("div");
  weekdays.className = "ep-calendar-weekdays";
  const weekdayBase = new Date(2021, 7, 1);
  for (let i = 0; i < 7; i += 1) {
    const label = new Intl.DateTimeFormat(locale, { weekday: "short" }).format(
      new Date(weekdayBase.getFullYear(), weekdayBase.getMonth(), weekdayBase.getDate() + i)
    );
    const span = document.createElement("span");
    span.textContent = label;
    weekdays.appendChild(span);
  }
  monthEl.appendChild(weekdays);

  const grid = document.createElement("div");
  grid.className = "ep-calendar-grid";
  const year = date.getFullYear();
  const month = date.getMonth();
  const firstDay = new Date(year, month, 1).getDay();
  const daysInMonth = new Date(year, month + 1, 0).getDate();

  for (let i = 0; i < firstDay; i += 1) {
    const empty = document.createElement("div");
    empty.className = "ep-calendar-day is-empty";
    grid.appendChild(empty);
  }

  const today = new Date();
  const todayKey = epGetLocalDateKey(today);
  for (let day = 1; day <= daysInMonth; day += 1) {
    const cellDate = new Date(year, month, day);
    const key = epGetLocalDateKey(cellDate);
    const dayEl = document.createElement("div");
    dayEl.className = "ep-calendar-day";
    dayEl.textContent = day;
    if (key === todayKey) {
      dayEl.classList.add("is-today");
    }
    const hasEvents = eventsByDate.has(key);
    if (hasEvents) {
      const entry = eventsByDate.get(key);
      if (key < todayKey) {
        dayEl.classList.add("is-past");
        dayEl.style.background = "rgba(200, 200, 200, 0.6)";
      } else {
        const colors = Array.from(entry.colors).slice(0, 3);
        const background = epBuildEventBackground(colors);
        if (background) dayEl.style.background = background;
      }
      dayEl.classList.add("has-event");
      if (entry.checkedIn) {
        dayEl.classList.add("is-checked-in");
      }
      if (entry.titles.length) {
        const label = entry.titles.slice(0, 4).join(", ");
        const more = entry.titles.length > 4 ? ` +${entry.titles.length - 4}` : "";
        dayEl.title = `${label}${more}`;
      }
    }
    dayEl.dataset.dateKey = key;
    let longPressTimer = null;
    let longPressTriggered = false;
    const clearLongPress = () => {
      if (longPressTimer) {
        clearTimeout(longPressTimer);
        longPressTimer = null;
      }
    };
    dayEl.addEventListener("click", (eventObj) => {
      if (longPressTriggered) {
        eventObj.preventDefault();
        eventObj.stopPropagation();
        longPressTriggered = false;
        return;
      }
      if (hasEvents) {
        epShowCalendarEvents(key, dayEl);
        return;
      }
      epShowCalendarCreateMenu(key, dayEl, { forceOpen: true });
    });
    dayEl.addEventListener("contextmenu", (eventObj) => {
      eventObj.preventDefault();
      eventObj.stopPropagation();
      epShowCalendarCreateMenu(key, dayEl, { forceOpen: true });
    });
    dayEl.addEventListener("pointerdown", (eventObj) => {
      if (eventObj.pointerType !== "touch") return;
      clearLongPress();
      longPressTriggered = false;
      longPressTimer = setTimeout(() => {
        longPressTriggered = true;
        epShowCalendarCreateMenu(key, dayEl, { forceOpen: true });
      }, 600);
    });
    dayEl.addEventListener("pointerup", clearLongPress);
    dayEl.addEventListener("pointercancel", clearLongPress);
    dayEl.addEventListener("pointerleave", clearLongPress);
    grid.appendChild(dayEl);
  }

  monthEl.appendChild(grid);
  return monthEl;
}

function epRenderCalendar() {
  const track = epEl("epCalendarTrack");
  if (!track) return;
  track.innerHTML = "";
  const popout = epEl("epCalendarPopout");
  if (popout) {
    popout.classList.remove("active");
    popout.innerHTML = '<button class="ep-calendar-popout-close" type="button" aria-label="Close">×</button>';
    popout.dataset.dateKey = "";
  }
  const locale = (navigator.languages && navigator.languages[0]) || navigator.language;
  const eventsByDate = new Map();

  const sourceEvents = epState.allEvents.length ? epState.allEvents : epState.events;
  sourceEvents.forEach((event) => {
    const date = epParseEventDate(event.starts_at);
    if (!date) return;
    const key = epGetLocalDateKey(date);
    const entry = eventsByDate.get(key) || {
      colors: new Set(),
      titles: [],
      events: [],
      checkedIn: false
    };
    const isAllMembersEvent = Number(event.all_members || 0) === 1;
    const groupColors = (!isAllMembersEvent && Array.isArray(event.groups))
      ? event.groups.map((group) => group.color).filter(Boolean)
      : [];
    if (groupColors.length) {
      groupColors.forEach((color) => entry.colors.add(epToSoftColor(color)));
    } else {
    const category = String(event.category || "").trim();
    entry.colors.add(epCategoryColor(category));
    }
    entry.titles.push(event.title || "Untitled");
    entry.events.push(event);
    if (epEventIsCheckedIn(event)) {
      entry.checkedIn = true;
    }
    eventsByDate.set(key, entry);
  });

  epState.calendarEventsByDate = eventsByDate;
  const today = new Date();
  const filterDate = epState.eventFilters.fromDate
    ? new Date(`${epState.eventFilters.fromDate}T00:00:00`)
    : null;
  const anchor = (filterDate && !Number.isNaN(filterDate.getTime())) ? filterDate : today;
  const base = new Date(anchor.getFullYear(), anchor.getMonth(), 1);
  const monthsToShow = 8;
  for (let i = 0; i < monthsToShow; i += 1) {
    const monthDate = new Date(base.getFullYear(), base.getMonth() + i, 1);
    track.appendChild(epBuildCalendarMonth(monthDate, eventsByDate, locale));
  }
}

function epFormatTimeShort(value) {
  const date = epParseEventDate(value);
  if (!date) return "";
  const locale = (navigator.languages && navigator.languages[0]) || navigator.language;
  return new Intl.DateTimeFormat(locale, {
    hour: "2-digit",
    minute: "2-digit"
  }).format(date);
}

function epBuildRoleSummary(checkedInMembers, ownerId) {
  const roleCounts = {};
  checkedInMembers.forEach((member) => {
    const isOwner = Number(member.member_id) === Number(ownerId);
    const role = ((member.role || "").trim() || (isOwner ? "Owner" : "Undefined")).trim();
    if (!role) return;
    const key = role.toLowerCase();
    roleCounts[key] = roleCounts[key] || { label: role, count: 0 };
    roleCounts[key].count += 1;
  });
  const roleKeys = Object.keys(roleCounts);
  const orderHints = [
    "soprano", "sopran", "s",
    "alto", "alt", "a",
    "tenor", "tenor1", "tenor2", "ten", "t",
    "baritone", "bari", "bar",
    "bass", "bass1", "bass2", "b"
  ];
  const orderedKeys = roleKeys.sort((a, b) => {
    const labelA = roleCounts[a].label.toLowerCase().replace(/\s+/g, "");
    const labelB = roleCounts[b].label.toLowerCase().replace(/\s+/g, "");
    const idxA = orderHints.findIndex((hint) => labelA.startsWith(hint));
    const idxB = orderHints.findIndex((hint) => labelB.startsWith(hint));
    if (idxA === idxB) {
      return roleCounts[a].label.localeCompare(roleCounts[b].label);
    }
    if (idxA === -1) return 1;
    if (idxB === -1) return -1;
    return idxA - idxB;
  });
  const roleNames = orderedKeys.map((k) => roleCounts[k].label);
  const roleTotals = orderedKeys.map((k) => roleCounts[k].count);
  const totalMembers = checkedInMembers.length;
  const totalByRole = roleTotals.reduce((sum, value) => sum + value, 0);
  const roleSummary = roleNames
    .map((name, idx) => `${name} ${roleTotals[idx]}`)
    .join(", ");
  const summary = roleKeys.length
    ? `${roleSummary} • Total: ${totalByRole}`
    : `Total: ${totalMembers}`;
  return {
    summary,
    hasRoles: roleKeys.length > 0,
    roleNames,
    roleTotals,
    totalMembers
  };
}

function epOpenEventFromCalendar(eventId) {
  const list = epEl("epEventList");
  if (!list) return;
  const row = list.querySelector(`[data-event-id="${eventId}"]`);
  if (!row) return;
  row.open = true;
  row.scrollIntoView({ behavior: "smooth", block: "start" });
}

function epOpenEventFromReport(eventId, anchorEl) {
  const id = Number(eventId || 0);
  if (!id) return;
  const source = (epState.events || []).find((event) => Number(event.id) === id)
    || (epState.allEvents || []).find((event) => Number(event.id) === id);
  if (!source) return;

  const popout = epEl("epAttendancePopout");
  if (!popout) return;
  if (popout.dataset.eventId === String(id) && popout.classList.contains("active")) {
    epResetCalendarPopout(popout);
    return;
  }

  const eventDate = epParseEventDate(source.starts_at);
  const title = eventDate ? epFormatDateHeader(eventDate) : (source.title || "Event");
  popout.innerHTML = '<button class="ep-calendar-popout-close" type="button" aria-label="Close">×</button>';
  const heading = document.createElement("h4");
  heading.textContent = title;
  const listEl = document.createElement("div");
  listEl.className = "ep-calendar-popout-list";
  const row = epBuildEventRow(source, { showDate: false });
  row.classList.add("ep-calendar-popout-row");
  listEl.appendChild(row);
  popout.appendChild(heading);
  popout.appendChild(listEl);
  popout.dataset.eventId = String(id);
  popout.classList.add("active");

  const closeBtn = popout.querySelector(".ep-calendar-popout-close");
  if (closeBtn) {
    closeBtn.addEventListener("click", (eventObj) => {
      eventObj.preventDefault();
      eventObj.stopPropagation();
      epResetCalendarPopout(popout);
    });
  }

  const panel = anchorEl ? anchorEl.closest(".ep-attendance-panel") : document.querySelector(".ep-attendance-panel");
  if (panel && anchorEl) {
    const panelRect = panel.getBoundingClientRect();
    const anchorRect = anchorEl.getBoundingClientRect();
    const left = Math.min(
      Math.max(0, anchorRect.left - panelRect.left),
      Math.max(0, panelRect.width - popout.offsetWidth - 8)
    );
    const top = Math.min(
      Math.max(0, anchorRect.bottom - panelRect.top + 8),
      Math.max(0, panelRect.height - popout.offsetHeight - 8)
    );
    popout.style.left = `${left}px`;
    popout.style.top = `${top}px`;
  }
}

function epResetCalendarPopout(popout) {
  if (!popout) return;
  popout.classList.remove("active");
  popout.classList.remove("is-create-menu");
  popout.innerHTML = '<button class="ep-calendar-popout-close" type="button" aria-label="Close">×</button>';
  popout.dataset.dateKey = "";
  popout.dataset.eventId = "";
  popout.dataset.source = "";
  popout.dataset.mode = "";
}

function epShowCalendarCreateMenu(dateKey, anchorEl, options = {}) {
  if (!epState.canManage) return;
  const popout = epEl("epCalendarPopout");
  if (!popout) return;
  if (!options.forceOpen
      && popout.dataset.dateKey === dateKey
      && popout.dataset.mode === "create-menu"
      && popout.classList.contains("active")) {
    epResetCalendarPopout(popout);
    return;
  }

  const [yearStr, monthStr, dayStr] = dateKey.split("-");
  const titleDate = new Date(
    Number(yearStr),
    Number(monthStr) - 1,
    Number(dayStr)
  );
  const title = epFormatDateHeader(titleDate);
  const categories = (epState.categoryCatalog || []).slice();
  const categoryOptionsHtml = [
    '<option value="">Category</option>',
    ...categories.map((entry) => {
      const id = String(entry.id || "").trim();
      if (!id) return "";
      return `<option value="${epEscape(id)}">${epEscape(id)}</option>`;
    }).filter(Boolean)
  ].join("");
  const groupOptionsHtml = (epState.groups || []).map((group) => {
    const id = Number(group.id || 0);
    if (!id) return "";
    const isAllMembers = Number(group.is_all_members || 0) === 1;
    const color = group.color || "#9fb7f0";
    const name = String(group.name || "Group");
    return `
      <label>
        <input type="checkbox" value="${id}" ${isAllMembers ? "checked" : ""}>
        <span class="ep-group-swatch" style="background:${epEscape(color)}"></span>
        ${epEscape(name)}
      </label>
    `;
  }).filter(Boolean).join("");

  popout.innerHTML = '<button class="ep-calendar-popout-close" type="button" aria-label="Close">×</button>';
  const heading = document.createElement("h4");
  heading.textContent = `${title} · Create event`;
  const formEl = document.createElement("form");
  formEl.className = "ep-calendar-create-form";
  formEl.innerHTML = `
    <input class="ep-input" name="title" type="text" maxlength="255" placeholder="Event title" required>
    <input class="ep-input" name="location" type="text" maxlength="255" placeholder="Location">
    <label class="ep-field">
      <span>Category</span>
      <select class="ep-input" name="category">${categoryOptionsHtml}</select>
    </label>
    <label class="ep-field">
      <span>Start time</span>
      <input class="ep-input" name="time" type="time" value="10:00" required>
    </label>
    <label class="ep-field">
      <span>End time</span>
      <input class="ep-input" name="end_time" type="time">
    </label>
    <div class="ep-field">
      <span>Groups</span>
      <div class="ep-group-picker ep-calendar-create-groups">
        ${groupOptionsHtml || "<div class='ep-panel-sub'>Create groups first.</div>"}
      </div>
    </div>
    <div class="ep-calendar-create-actions">
      <button class="ep-btn" type="submit">Create</button>
      <button class="ep-btn ghost" type="button" data-role="cancel-create">Cancel</button>
    </div>
  `;
  popout.appendChild(heading);
  popout.appendChild(formEl);
  popout.dataset.dateKey = dateKey;
  popout.dataset.mode = "create-menu";
  popout.classList.add("is-create-menu");
  popout.classList.add("active");

  formEl.addEventListener("submit", async (eventObj) => {
    eventObj.preventDefault();
    eventObj.stopPropagation();
    const titleInput = formEl.elements.title;
    const locationInput = formEl.elements.location;
    const categoryInput = formEl.elements.category;
    const timeInput = formEl.elements.time;
    const endTimeInput = formEl.elements.end_time;
    const eventTitle = String(titleInput?.value || "").trim();
    const locationValue = String(locationInput?.value || "").trim();
    const categoryValue = String(categoryInput?.value || "").trim();
    const timeValue = String(timeInput?.value || "10:00").trim();
    const endTimeValue = String(endTimeInput?.value || "").trim();
    if (!eventTitle) return;
    const startsAt = `${dateKey} ${timeValue}:00`;
    const endsAt = endTimeValue ? `${dateKey} ${endTimeValue}:00` : null;
    const groupInputs = formEl.querySelectorAll(".ep-calendar-create-groups input:checked");
    const selectedIds = Array.from(groupInputs)
      .map((input) => Number(input.value))
      .filter((value) => Number.isFinite(value) && value > 0);
    const allMembersGroupIds = (epState.groups || [])
      .filter((group) => Number(group.is_all_members || 0) === 1)
      .map((group) => Number(group.id));
    const allMembersSelected = selectedIds.some((id) => allMembersGroupIds.includes(id));
    const groupIds = selectedIds.filter((id) => !allMembersGroupIds.includes(id));
    const res = await epPost("/ep_events.php", {
      action: "create",
      title: eventTitle,
      category: categoryValue,
      location: locationValue,
      starts_at: startsAt,
      ends_at: endsAt,
      all_members: allMembersSelected ? 1 : 0,
      group_ids: groupIds
    });
    if (res.status === "OK") {
      epFlashMessage("Event created.");
      epResetCalendarPopout(popout);
      await epLoadEvents();
      return;
    }
    epFlashMessage(res.message || "Unable to create event.");
  });
  const cancelBtn = formEl.querySelector('[data-role="cancel-create"]');
  if (cancelBtn) {
    cancelBtn.addEventListener("click", (eventObj) => {
      eventObj.preventDefault();
      eventObj.stopPropagation();
      epResetCalendarPopout(popout);
    });
  }

  const closeBtn = popout.querySelector(".ep-calendar-popout-close");
  if (closeBtn) {
    closeBtn.addEventListener("click", (eventObj) => {
      eventObj.preventDefault();
      eventObj.stopPropagation();
      epResetCalendarPopout(popout);
    });
  }

  if (anchorEl) {
    const panel = anchorEl.closest(".ep-calendar-panel");
    if (panel) {
      const panelRect = panel.getBoundingClientRect();
      const anchorRect = anchorEl.getBoundingClientRect();
      const left = Math.min(
        Math.max(0, anchorRect.left - panelRect.left),
        Math.max(0, panelRect.width - popout.offsetWidth - 8)
      );
      const top = Math.min(
        Math.max(0, anchorRect.bottom - panelRect.top + 8),
        Math.max(0, panelRect.height - popout.offsetHeight - 8)
      );
      popout.style.left = `${left}px`;
      popout.style.top = `${top}px`;
    }
  }
}

async function epShowCalendarEvents(dateKey, anchorEl, options = {}) {
  const popout = epEl("epCalendarPopout");
  if (!popout) return;
  const eventsByDate = epState.calendarEventsByDate;
  if (!eventsByDate || !eventsByDate.has(dateKey)) {
    epResetCalendarPopout(popout);
    return;
  }
  if (!options.forceOpen && popout.dataset.dateKey === dateKey) {
    epResetCalendarPopout(popout);
    return;
  }
  const entry = eventsByDate.get(dateKey);
  const locale = (navigator.languages && navigator.languages[0]) || navigator.language;
  const [yearStr, monthStr, dayStr] = dateKey.split("-");
  const list = entry.events
    .slice()
    .sort((a, b) => String(a.starts_at || "").localeCompare(String(b.starts_at || "")));
  const primaryDate = list.length ? epParseEventDate(list[0].starts_at) : null;
  const titleDate = primaryDate || new Date(
    Number(yearStr),
    Number(monthStr) - 1,
    Number(dayStr)
  );
  const title = epFormatDateHeader(titleDate);
  popout.innerHTML = '<button class="ep-calendar-popout-close" type="button" aria-label="Close">×</button>';
  const heading = document.createElement("h4");
  heading.textContent = title;
  const listEl = document.createElement("div");
  listEl.className = "ep-calendar-popout-list";
  const listWithNotes = await Promise.all(list.map(async (event) => {
    const existing = String(event.notes || "").trim();
    if (existing) {
      return { ...event, __resolvedNotes: existing };
    }
    const eventId = Number(event.id || 0);
    if (!eventId) {
      return { ...event, __resolvedNotes: "" };
    }
    try {
      const data = await epGet(`/ep_event_checkins.php?event_id=${eventId}`);
      const fetched = (data && data.status === "OK" && data.event)
        ? String(data.event.notes || "").trim()
        : "";
      return { ...event, __resolvedNotes: fetched };
    } catch (err) {
      return { ...event, __resolvedNotes: "" };
    }
  }));

  listWithNotes.forEach((event) => {
    const wrap = document.createElement("div");
    wrap.className = "ep-calendar-popout-event-block";
    const eventId = Number(event.id || 0);
    const row = epBuildEventRow(event, { showDate: false });
    row.classList.add("ep-calendar-popout-row");
    wrap.appendChild(row);
    const pollHtml = epRenderCalendarEventPolls(event);
    if (pollHtml) {
      const pollWrap = document.createElement("div");
      pollWrap.className = "ep-calendar-popout-polls";
      pollWrap.innerHTML = pollHtml;
      wrap.appendChild(pollWrap);
    }
    if (eventId > 0) {
      const commentsWrap = document.createElement("div");
      commentsWrap.className = "ep-calendar-popout-comments";
      commentsWrap.innerHTML = epEventCommentsWidgetHtml(eventId);
      wrap.appendChild(commentsWrap);
      const commentsWidget = commentsWrap.querySelector('[data-role="event-comments"]');
      if (commentsWidget) {
        epBindEventCommentsWidget(commentsWidget, eventId);
      }
    }
    listEl.appendChild(wrap);
  });
  popout.appendChild(heading);
  popout.appendChild(listEl);
  popout.dataset.dateKey = dateKey;
  popout.classList.add("active");
  const closeBtn = popout.querySelector(".ep-calendar-popout-close");
  if (closeBtn) {
    closeBtn.addEventListener("click", (eventObj) => {
      eventObj.preventDefault();
      eventObj.stopPropagation();
      epResetCalendarPopout(popout);
    });
  }
  if (anchorEl) {
    const panel = anchorEl.closest(".ep-calendar-panel");
    if (panel) {
      const panelRect = panel.getBoundingClientRect();
      const anchorRect = anchorEl.getBoundingClientRect();
      const left = Math.min(
        Math.max(0, anchorRect.left - panelRect.left),
        Math.max(0, panelRect.width - popout.offsetWidth - 8)
      );
      const top = Math.min(
        Math.max(0, anchorRect.bottom - panelRect.top + 8),
        Math.max(0, panelRect.height - popout.offsetHeight - 8)
      );
      popout.style.left = `${left}px`;
      popout.style.top = `${top}px`;
    }
  }
}

function epBindCalendarControls() {
  const track = epEl("epCalendarTrack");
  const prev = epEl("epCalendarPrev");
  const next = epEl("epCalendarNext");
  if (!track) return;
  const scrollByAmount = () => Math.max(240, track.clientWidth * 0.9);
  const scroll = (dir) => {
    track.scrollBy({ left: scrollByAmount() * dir, behavior: "smooth" });
  };
  if (prev) {
    prev.addEventListener("click", (eventObj) => {
      eventObj.stopPropagation();
      scroll(-1);
    });
  }
  if (next) {
    next.addEventListener("click", (eventObj) => {
      eventObj.stopPropagation();
      scroll(1);
    });
  }
}

function epInitials(name) {
  if (!name) return "?";
  const parts = name.trim().split(/\s+/);
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

function epToInputDate(value) {
  if (!value) return "";
  const trimmed = value.trim();
  if (trimmed.includes("T")) return trimmed.slice(0, 16);
  const parts = trimmed.split(" ");
  if (parts.length === 2) {
    return `${parts[0]}T${parts[1].slice(0, 5)}`;
  }
  return trimmed;
}

function epMemberDisplayName(member) {
  return (member.display_name || member.username || member.email || "Member").trim();
}

async function epOpenMemberDirectChat(member) {
  const memberId = Number(member?.member_id || 0);
  if (!memberId || memberId === Number(epState.memberId || 0)) return;
  const displayName = epMemberDisplayName(member);
  const hostWindow = window.parent && window.parent !== window ? window.parent : window;
  const markLocalRead = () => {
    epState.dmUnreadByMemberId[memberId] = 0;
    if (epState.currentGroupData) {
      epRenderGroupMembersPanel(epState.currentGroupData);
    }
  };

  if (typeof hostWindow.openDMChatWithMember === "function") {
    await Promise.resolve(hostWindow.openDMChatWithMember(memberId, displayName));
    markLocalRead();
    return;
  }

  let res;
  try {
    res = await fetch("/chatThreadStartDM.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      credentials: "same-origin",
      body: `member_id=${encodeURIComponent(memberId)}`
    });
  } catch (err) {
    alert("Chat is not available yet.");
    return;
  }

  let data = null;
  try {
    data = await res.json();
  } catch (err) {
    alert("Chat is not available yet.");
    return;
  }

  if (!res.ok || data?.status !== "OK" || !data?.token) {
    alert(data?.message || "Chat is not available yet.");
    return;
  }

  if (typeof hostWindow.openChatFromMenu === "function") {
    hostWindow.openChatFromMenu(data.token);
    markLocalRead();
    return;
  }

  if (typeof hostWindow.selectList === "function" && typeof hostWindow.openChat === "function") {
    hostWindow.currentListToken = data.token;
    hostWindow.selectList(data.token, data?.meta?.chat_name || displayName);
    hostWindow.openChat();
    markLocalRead();
    return;
  }

  alert("Chat is not available yet.");
}

function epBindMemberAvatarDirectChat(targetEl, member, options = {}) {
  if (!targetEl || !member) return;
  const memberId = Number(member.member_id || 0);
  if (!memberId || memberId === Number(epState.memberId || 0)) return;
  const displayName = epMemberDisplayName(member);
  const stopSummaryToggle = options.stopSummaryToggle !== false;

  targetEl.style.cursor = "pointer";
  targetEl.title = `1:1 chat with ${displayName}`;
  targetEl.setAttribute("role", "button");
  targetEl.setAttribute("tabindex", "0");

  const activate = async (eventObj) => {
    if (eventObj) {
      eventObj.preventDefault();
      eventObj.stopPropagation();
    }
    await epOpenMemberDirectChat(member);
  };

  targetEl.addEventListener("click", activate);
  targetEl.addEventListener("keydown", (eventObj) => {
    if (eventObj.key === "Enter" || eventObj.key === " ") {
      activate(eventObj);
    }
  });

  if (stopSummaryToggle) {
    targetEl.addEventListener("mousedown", (eventObj) => {
      eventObj.stopPropagation();
    });
  }
}

async function epLoadDirectMessageUnreadMap() {
  try {
    const res = await fetch("/chatThreadList.php", {
      credentials: "same-origin",
      cache: "no-store"
    });
    const data = await res.json();
    if (data.status !== "OK" || !Array.isArray(data.threads)) {
      epState.dmUnreadByMemberId = {};
      return;
    }
    const map = {};
    data.threads.forEach((thread) => {
      const otherId = Number(thread?.other_member?.id || 0);
      if (!otherId) return;
      map[otherId] = Number(thread.unread || 0);
    });
    epState.dmUnreadByMemberId = map;
  } catch (err) {
    epState.dmUnreadByMemberId = {};
  }
}

function epBindCategoryPicker(scope) {
  const picker = scope && scope.querySelector
    ? scope.querySelector('[data-role="category-picker"]')
    : null;
  if (!picker) return;
  const input = picker.querySelector("input[name='category']");
  const trigger = picker.querySelector('[data-role="category-trigger"]');
  const dropdown = picker.querySelector('[data-role="category-dropdown"]');
  if (!input || !trigger || !dropdown) return;

  const setValue = (value) => {
    input.value = value || "";
    const label = value ? value : "No category";
    const color = epGetCategoryColorHex(value);
    trigger.innerHTML = `
      <span class="ep-category-dot" style="background:${color}" aria-hidden="true"></span>
      <span>${epEscape(label)}</span>
    `;
  };

  const buildOptions = () => {
    const categories = (epState.categoryCatalog || []).slice();
    const options = [{ value: "", label: "No category" }]
      .concat(categories.map((entry) => ({ value: entry.id, label: entry.id })));
    dropdown.innerHTML = options.map((opt) => {
      const color = epGetCategoryColorHex(opt.value);
      return `
        <button type="button" class="ep-category-option" data-value="${epEscape(opt.value)}">
          <span class="ep-category-dot" style="background:${color}"></span>
          <span>${epEscape(opt.label)}</span>
        </button>
      `;
    }).join("");
  };

  if (!picker.dataset.bound) {
    picker.dataset.bound = "1";
    trigger.addEventListener("click", (eventObj) => {
      eventObj.preventDefault();
      dropdown.classList.toggle("is-open");
    });
    dropdown.addEventListener("click", (eventObj) => {
      const target = eventObj.target.closest(".ep-category-option");
      if (!target) return;
      setValue(target.dataset.value || "");
      dropdown.classList.remove("is-open");
    });
    document.addEventListener("click", (eventObj) => {
      if (!picker.contains(eventObj.target)) {
        dropdown.classList.remove("is-open");
      }
    });
  }

  buildOptions();
  setValue(input.value || "");
}

function epRefreshCategoryPickers() {
  document.querySelectorAll('[data-role="category-picker"]').forEach((picker) => {
    epBindCategoryPicker(picker);
  });
}

function epRenderCategorySettings() {
  const listEl = epEl("epCategorySettingsList");
  if (!listEl) return;
  const categories = (epState.categoryCatalog || []).slice();
  listEl.innerHTML = "";
  categories.forEach((entry) => {
    const name = epNormalizeCategoryName(entry.id);
    if (!name) return;
    const row = document.createElement("div");
    row.className = "ep-category-row";
    row.dataset.categoryName = name;
    row.innerHTML = `
      <input type="text" class="ep-category-id" value="${epEscape(name)}" aria-label="Category id" ${epState.canManage ? "" : "disabled"}>
      <input type="text" class="ep-category-desc" value="${epEscape(entry.description || "")}" aria-label="Category description" ${epState.canManage ? "" : "disabled"}>
      <input type="color" class="ep-category-color-input" value="${epGetCategoryColorHex(name)}" aria-label="Category color" ${epState.canManage ? "" : "disabled"}>
      ${epState.canManage ? '<button type="button" class="ep-btn ghost ep-category-remove">Remove</button>' : ""}
    `;
    listEl.appendChild(row);
  });
  const form = epEl("epCategorySettingsForm");
  if (form) {
    form.classList.toggle("ep-hidden", !epState.canManage);
  }
  epRefreshCategoryPickers();
}

function epBindCategorySettings() {
  const form = epEl("epCategorySettingsForm");
  const listEl = epEl("epCategorySettingsList");
  if (form) {
    form.addEventListener("submit", async (eventObj) => {
      eventObj.preventDefault();
      if (!epState.canManage) return;
      const name = epNormalizeCategoryName(form.category.value);
      if (!name) return;
      const description = epNormalizeCategoryName(form.description.value);
      const color = form.color.value || epGetCategoryColorHex(name);
      const res = await epPost("/ep_categories.php", {
        action: "create",
        category: name,
        description,
        color
      });
      if (res.status === "OK") {
        epUpsertCategoryEntry({
          id: res.category?.category || name,
          description: res.category?.description || description,
          color: res.category?.color || color
        });
        form.reset();
        form.color.value = "#9fb7f0";
        epRenderCategorySettings();
        epUpdateCategoryList(epState.allEvents.length ? epState.allEvents : epState.events);
      } else {
        alert(res.message || "Unable to add category.");
      }
    });
  }
  if (listEl) {
    listEl.addEventListener("click", async (eventObj) => {
      const target = eventObj.target;
      if (!(target instanceof HTMLElement)) return;
      if (!target.classList.contains("ep-category-remove")) return;
      if (!epState.canManage) return;
      const row = target.closest(".ep-category-row");
      if (!row) return;
      const name = epNormalizeCategoryName(row.dataset.categoryName);
      if (!name) return;
      if (!confirm(`Remove category "${name}"?`)) return;
      await epDeleteCategory(name);
      epRenderCategorySettings();
      epUpdateCategoryList(epState.allEvents.length ? epState.allEvents : epState.events);
      epRenderCalendar();
    });
    listEl.addEventListener("change", (eventObj) => {
      const target = eventObj.target;
      if (!(target instanceof HTMLElement)) return;
      const row = target.closest(".ep-category-row");
      if (!row) return;
      const oldName = epNormalizeCategoryName(row.dataset.categoryName);
      if (!oldName) return;
      if (!epState.canManage) return;
      if (target.classList.contains("ep-category-color-input")) {
        const color = target.value;
        epSetCategoryColor(oldName, color);
        epUpdateCategoryEntry(oldName, { color });
        epRenderCalendar();
        return;
      }
      if (target.classList.contains("ep-category-id")) {
        const nextName = epNormalizeCategoryName(target.value);
        if (!nextName || nextName.toLowerCase() === oldName.toLowerCase()) {
          target.value = oldName;
          return;
        }
        const exists = !!epFindCategoryEntry(nextName);
        if (exists) {
          target.value = oldName;
          return;
        }
        epRenameCategory(oldName, nextName);
        row.dataset.categoryName = nextName;
        epRenderCategorySettings();
        epUpdateCategoryList(epState.allEvents.length ? epState.allEvents : epState.events);
        epRenderCalendar();
        return;
      }
      if (target.classList.contains("ep-category-desc")) {
        const description = epNormalizeCategoryName(target.value);
        epUpdateCategoryEntry(oldName, { description });
      }
    });
  }
}

function epMemberSortWeight(value, map) {
  if (!value) return 999;
  const key = String(value || "").trim().toLowerCase();
  return Object.prototype.hasOwnProperty.call(map, key) ? map[key] : 998;
}

function epMemberGroupBubbles(member) {
  const groups = Array.isArray(member.groups) ? member.groups : [];
  const visible = groups.filter((group) => Number(group.is_all_members || 0) !== 1);
  if (!visible.length) return "";
  const orderMap = new Map(epState.groups.map((group, index) => [Number(group.id), index]));
  const sorted = visible.slice().sort((a, b) => {
    const orderA = orderMap.has(Number(a.id)) ? orderMap.get(Number(a.id)) : Number.MAX_SAFE_INTEGER;
    const orderB = orderMap.has(Number(b.id)) ? orderMap.get(Number(b.id)) : Number.MAX_SAFE_INTEGER;
    if (orderA !== orderB) return orderB - orderA;
    return String(a.name || "").localeCompare(String(b.name || ""));
  });
  const dots = sorted.map((group) => {
    const color = group.color || "#9fb7f0";
    const name = group.name || "Group";
    return `<span class="ep-member-group-dot" style="background:${color}" title="${epEscape(name)}"></span>`;
  }).join("");
  return `<div class="ep-member-group-bubbles" aria-hidden="true">${dots}</div>`;
}

function epMemberGroupOrderKey(member) {
  const groups = Array.isArray(member.groups) ? member.groups : [];
  const orderMap = new Map(epState.groups.map((group, index) => [Number(group.id), index]));
  let minOrder = Number.MAX_SAFE_INTEGER;
  groups.forEach((group) => {
    if (Number(group.is_all_members || 0) === 1) return;
    const order = orderMap.has(Number(group.id)) ? orderMap.get(Number(group.id)) : Number.MAX_SAFE_INTEGER;
    if (order < minOrder) minOrder = order;
  });
  return minOrder;
}

function epSortMembers(members, isAllMembersGroup) {
  const sortKey = epState.memberSort.key || "member";
  const dir = epState.memberSort.dir === "desc" ? -1 : 1;
  const accessOrder = {
    owner: 0,
    admin: 1,
    editor: 2,
    commenter: 3,
    viewer: 4,
    paused: 5
  };
  const roleOrder = {
    leader: 0
  };
  const sorted = members.slice();
  sorted.sort((a, b) => {
    if (isAllMembersGroup) {
      const ownerA = String(a.access_role || "").trim().toLowerCase() === "owner";
      const ownerB = String(b.access_role || "").trim().toLowerCase() === "owner";
      if (ownerA !== ownerB) return ownerA ? -1 : 1;
    }
    const nameA = epMemberDisplayName(a).toLowerCase();
    const nameB = epMemberDisplayName(b).toLowerCase();
    if (sortKey === "group") {
      const groupA = epMemberGroupOrderKey(a);
      const groupB = epMemberGroupOrderKey(b);
      if (groupA !== groupB) return (groupA - groupB) * dir;
      return nameA < nameB ? -dir : nameA > nameB ? dir : 0;
    }
    if (sortKey === "role") {
      const roleA = (a.role || "").trim().toLowerCase();
      const roleB = (b.role || "").trim().toLowerCase();
      const weightA = epMemberSortWeight(roleA, roleOrder);
      const weightB = epMemberSortWeight(roleB, roleOrder);
      if (weightA !== weightB) return (weightA - weightB) * dir;
      if (roleA !== roleB) return roleA < roleB ? -dir : dir;
      return nameA < nameB ? -dir : nameA > nameB ? dir : 0;
    }
    if (sortKey === "access" && isAllMembersGroup) {
      const accessA = (a.access_role || "").trim().toLowerCase();
      const accessB = (b.access_role || "").trim().toLowerCase();
      const weightA = epMemberSortWeight(accessA, accessOrder);
      const weightB = epMemberSortWeight(accessB, accessOrder);
      if (weightA !== weightB) return (weightA - weightB) * dir;
      return nameA < nameB ? -dir : nameA > nameB ? dir : 0;
    }
    return nameA < nameB ? -dir : nameA > nameB ? dir : 0;
  });
  return sorted;
}

function epUpdateMemberSortIndicators(header) {
  if (!header) return;
  const labels = header.querySelectorAll(".ep-sortable");
  labels.forEach((label) => {
    const key = label.dataset.sortKey || "";
    const indicator = label.querySelector(".ep-sort-indicator");
    if (!indicator) return;
    if (key === epState.memberSort.key) {
      indicator.textContent = epState.memberSort.dir === "asc" ? "▲" : "▼";
    } else {
      indicator.textContent = "";
    }
  });
}

function epLoadMemberSortPreference() {
  try {
    const raw = localStorage.getItem("epMemberSort");
    if (!raw) return;
    const parsed = JSON.parse(raw);
    if (parsed && typeof parsed === "object") {
      const key = String(parsed.key || "").trim();
      const dir = String(parsed.dir || "").trim();
      if (key) epState.memberSort.key = key;
      if (dir === "asc" || dir === "desc") epState.memberSort.dir = dir;
    }
  } catch {}
}

function epSaveMemberSortPreference() {
  try {
    localStorage.setItem("epMemberSort", JSON.stringify(epState.memberSort));
  } catch {}
}

function epGetRoleGroupNames() {
  const isTruthyFlag = (value) => {
    if (value === true || value === 1) return true;
    const raw = String(value ?? "").trim().toLowerCase();
    return raw === "1" || raw === "true" || raw === "yes" || raw === "on";
  };
  const seen = new Set();
  const names = [];
  (epState.groups || []).forEach((group) => {
    if (isTruthyFlag(group.is_all_members)) return;
    if (!isTruthyFlag(group.is_role_group) && !isTruthyFlag(group.is_role_default)) return;
    const name = String(group.name || "").trim();
    if (!name) return;
    const key = name.toLowerCase();
    if (seen.has(key)) return;
    seen.add(key);
    names.push(name);
  });
  return names;
}

function epFindRoleGroupByName(name) {
  const needle = String(name || "").trim().toLowerCase();
  if (!needle) return null;
  return (epState.groups || []).find((group) =>
    Number(group.is_all_members || 0) !== 1 &&
    Number(group.is_role_group || 0) === 1 &&
    String(group.name || "").trim().toLowerCase() === needle
  ) || null;
}

function epBindRoleHybrid(row, input, memberId) {
  if (!row || !input || input.disabled) return;
  const dropdown = row.querySelector(".ep-role-dropdown");
  const toggleBtn = row.querySelector(".ep-role-open");
  if (!dropdown) return;

  input.dataset.lastCommittedRole = (input.value || "").trim();
  input.dataset.roleCommitBusy = "0";

  const commit = async () => {
    if (!epState.currentGroupId) return;
    if (input.dataset.roleCommitBusy === "1") return;
    const nextRole = (input.value || "").trim();
    const prevRole = input.dataset.lastCommittedRole || "";
    if (nextRole === prevRole) return;

    input.dataset.roleCommitBusy = "1";
    const res = await epPost("/ep_group_members.php", {
      action: "add",
      group_id: epState.currentGroupId,
      member_id: memberId,
      role: nextRole
    });
    input.dataset.roleCommitBusy = "0";
    if (res.status === "OK") {
      input.dataset.lastCommittedRole = nextRole;
      // If typed role matches a role-group name, move member to that role group.
      // If no match, keep membership unchanged and only save role text.
      const matchedRoleGroup = epFindRoleGroupByName(nextRole);
      if (matchedRoleGroup) {
        const moveRes = await epPost("/ep_group_members.php", {
          action: "add",
          group_id: Number(matchedRoleGroup.id),
          member_id: memberId,
          role: nextRole
        });
        if (moveRes.status === "OK") {
          epApplyMemberDropResult(memberId, Number(matchedRoleGroup.id), nextRole);
          epRenderGroups();
        } else {
          alert(moveRes.message || "Unable to move member to role group.");
        }
      } else if (epState.currentGroupData && Array.isArray(epState.currentGroupData.members)) {
        const localMember = epState.currentGroupData.members.find((m) => Number(m.member_id) === Number(memberId));
        if (localMember) localMember.role = nextRole;
      }
      return;
    }
    alert(res.message || "Unable to update member group.");
  };

  const renderOptions = () => {
    const roleNames = epGetRoleGroupNames();
    if (!roleNames.length) {
      dropdown.innerHTML = "<div class=\"ep-member-option-role\">No role groups</div>";
      return;
    }
    dropdown.innerHTML = roleNames
      .map((name) => `<button class="ep-member-option" type="button" data-role-value="${epEscape(name)}">${epEscape(name)}</button>`)
      .join("");
  };

  const openDropdown = () => {
    renderOptions();
    dropdown.classList.add("is-open");
  };
  const closeDropdown = () => {
    dropdown.classList.remove("is-open");
  };

  input.addEventListener("focus", openDropdown);
  input.addEventListener("input", openDropdown);
  input.addEventListener("blur", () => {
    setTimeout(() => {
      closeDropdown();
      commit();
    }, 120);
  });
  input.addEventListener("change", commit);
  input.addEventListener("keydown", (eventObj) => {
    if (eventObj.key === "Enter") {
      eventObj.preventDefault();
      commit();
      input.blur();
      return;
    }
    if (eventObj.key === "ArrowDown") {
      openDropdown();
    }
  });

  dropdown.addEventListener("mousedown", (eventObj) => {
    const option = eventObj.target.closest("[data-role-value]");
    if (!option) return;
    eventObj.preventDefault();
    input.value = option.dataset.roleValue || "";
    input.dispatchEvent(new Event("change", { bubbles: true }));
    closeDropdown();
  });

  if (toggleBtn) {
    toggleBtn.addEventListener("mousedown", (eventObj) => {
      eventObj.preventDefault();
      openDropdown();
      input.focus();
    });
  }
}

function epRenderGroups() {
  const list = epEl("epGroupList");
  if (!list) return;
  list.innerHTML = "";

  if (!epState.groups.length) {
    list.innerHTML = "<div class='ep-card'><p>No groups yet.</p></div>";
    return;
  }

  epState.groups.forEach((group) => {
    const members = epState.groupMembers[group.id] || [];
    const memberCount = members.length;
    const isRoleGroup = Number(group.is_all_members || 0) !== 1 && Number(group.is_role_group || 0) === 1;
    const preview = members.slice(0, 4);
    const extraCount = Math.max(0, members.length - preview.length);
    const groupColor = group.color || "#9fb7f0";
    const avatarMarkup = preview.map((member) => {
      const displayName = member.display_name || member.username || member.email || "Member";
      const avatarUrl = epResolveAvatarUrl(member, displayName);
      const seed = member.member_id || member.email || member.username || displayName;
      return `
        <div class="ep-avatar" title="${displayName}">
          <img src="${avatarUrl}" alt="${displayName}"
               data-avatar-seed="${seed}"
               data-avatar-name="${displayName}"
               onerror="epHandleAvatarError(this)">
        </div>
      `;
    }).join("");
    const card = document.createElement("button");
    card.type = "button";
    card.className = "ep-card ep-group-card";
    card.dataset.groupId = group.id;
    card.dataset.isAllMembers = Number(group.is_all_members || 0) === 1 ? "1" : "0";
    if (epState.currentGroupId === group.id) {
      card.classList.add("active");
    }
    card.innerHTML = `
      <div class="ep-group-title">
        <div class="ep-group-title-main">
          <span class="ep-group-swatch" style="background:${groupColor}"></span>
          <h4>${group.name}</h4>
        </div>
        <div class="ep-group-title-tags">
          ${isRoleGroup ? `<span class="ep-group-role-flag">Role group</span>` : ""}
          <span class="ep-group-count" title="${memberCount} members">Members ${memberCount}</span>
        </div>
      </div>
      <p>${group.description || "No description"}</p>
      <div class="ep-group-avatars">
        ${avatarMarkup}
        ${extraCount > 0 ? `<div class="ep-avatar ep-avatar-count">+${extraCount}</div>` : ""}
      </div>
    `;
    card.addEventListener("click", () => {
      epSelectGroup(group.id, group.name);
    });
    if (epState.canManage && card.dataset.isAllMembers !== "1") {
      card.classList.add("is-draggable");
      card.draggable = true;
      card.addEventListener("dragstart", epHandleGroupDragStart);
      card.addEventListener("dragend", epHandleGroupDragEnd);
      card.addEventListener("dragover", epHandleGroupDragOver);
      card.addEventListener("dragleave", epHandleGroupDragLeave);
      card.addEventListener("drop", epHandleGroupDrop);
    }
    list.appendChild(card);
  });
}

let epDraggingGroupId = null;
let epDraggingMember = null;

function epHandleGroupDragStart(eventObj) {
  const card = eventObj.currentTarget;
  if (epDraggingMember) {
    eventObj.preventDefault();
    return;
  }
  epDraggingGroupId = Number(card.dataset.groupId || 0);
  card.classList.add("is-dragging");
  if (eventObj.dataTransfer) {
    eventObj.dataTransfer.effectAllowed = "move";
    eventObj.dataTransfer.setData("application/x-ep-group", String(epDraggingGroupId));
    eventObj.dataTransfer.setData("text/plain", String(epDraggingGroupId));
  }
}

function epHandleGroupDragEnd(eventObj) {
  const card = eventObj.currentTarget;
  card.classList.remove("is-dragging");
  card.classList.remove("is-dragover");
  epDraggingGroupId = null;
}

function epHandleGroupDragOver(eventObj) {
  const target = eventObj.currentTarget;
  const acceptsMemberDrop = target.dataset.isAllMembers !== "1";
  const canDropGroup = !!epDraggingGroupId;
  const canDropMember = !!epDraggingMember && acceptsMemberDrop;
  if (!canDropGroup && !canDropMember) return;
  eventObj.preventDefault();
  if (eventObj.dataTransfer) {
    eventObj.dataTransfer.dropEffect = canDropMember ? "copy" : "move";
  }
  target.classList.add("is-dragover");
}

function epHandleGroupDragLeave(eventObj) {
  eventObj.currentTarget.classList.remove("is-dragover");
}

function epApplyMemberDropResult(memberId, targetGroupId, fallbackRole = "") {
  const idNum = Number(memberId || 0);
  if (!idNum) return;
  const targetGroup = (epState.groups || []).find((group) => Number(group.id) === Number(targetGroupId));
  const targetIsRoleGroup = Number(targetGroup?.is_role_group || 0) === 1;
  const targetRole = targetIsRoleGroup
    ? String(targetGroup?.name || "").trim()
    : String(fallbackRole || "").trim();
  let memberSnapshot = null;

  if (epState.currentGroupData && Array.isArray(epState.currentGroupData.members)) {
    const member = epState.currentGroupData.members.find((m) => Number(m.member_id) === idNum);
    if (member) {
      memberSnapshot = { ...member };
      member.role = targetRole;
      member.groups = Array.isArray(member.groups) ? member.groups : [];
      const hasTarget = member.groups.some((g) => Number(g.id) === Number(targetGroupId));
      if (targetIsRoleGroup) {
        member.groups = member.groups.filter((g) => {
          const full = (epState.groups || []).find((group) => Number(group.id) === Number(g.id));
          return !(full && Number(full.is_role_group || 0) === 1);
        });
      }
      if (!hasTarget && targetGroup) {
        member.groups.push({
          id: Number(targetGroup.id),
          name: targetGroup.name || "",
          color: targetGroup.color || "",
          is_all_members: Number(targetGroup.is_all_members || 0),
          is_role_group: Number(targetGroup.is_role_group || 0)
        });
      }
    }
  }

  if (!memberSnapshot && Array.isArray(epState.currentGroupMembers)) {
    const member = epState.currentGroupMembers.find((m) => Number(m.member_id) === idNum);
    if (member) memberSnapshot = { ...member, role: targetRole };
  }

  // Keep left-side group avatar previews in sync without full reload.
  if (memberSnapshot && epState.groupMembers && typeof epState.groupMembers === "object") {
    const upsertInGroup = (groupId) => {
      const gid = Number(groupId || 0);
      if (!gid) return;
      epState.groupMembers[gid] = Array.isArray(epState.groupMembers[gid]) ? epState.groupMembers[gid] : [];
      const list = epState.groupMembers[gid];
      const index = list.findIndex((m) => Number(m.member_id) === idNum);
      if (index >= 0) {
        list[index] = { ...list[index], ...memberSnapshot, role: targetRole };
      } else {
        list.push({ ...memberSnapshot, role: targetRole });
      }
    };
    const removeFromGroup = (groupId) => {
      const gid = Number(groupId || 0);
      if (!gid || !Array.isArray(epState.groupMembers[gid])) return;
      epState.groupMembers[gid] = epState.groupMembers[gid].filter((m) => Number(m.member_id) !== idNum);
    };

    if (targetIsRoleGroup) {
      (epState.groups || []).forEach((group) => {
        if (Number(group.is_role_group || 0) === 1 && Number(group.id) !== Number(targetGroupId)) {
          removeFromGroup(group.id);
        }
      });
    }
    upsertInGroup(targetGroupId);
  }

  const row = document.querySelector(`.ep-member[data-member-id="${idNum}"]`);
  if (!row) return;
  row.dataset.memberRole = targetRole;
  const roleInput = row.querySelector(".ep-member-group-role");
  if (roleInput) {
    roleInput.value = targetRole;
    roleInput.dataset.lastCommittedRole = targetRole;
  }
  const oldBubbles = row.querySelector(".ep-member-group-bubbles");
  if (epState.currentGroupData?.members) {
    const member = epState.currentGroupData.members.find((m) => Number(m.member_id) === idNum);
    if (member) {
      const temp = document.createElement("div");
      temp.innerHTML = epMemberGroupBubbles(member);
      const next = temp.firstElementChild;
      if (next && oldBubbles) {
        oldBubbles.replaceWith(next);
      } else if (next) {
        const roleWrap = row.querySelector(".ep-member-role");
        if (roleWrap) roleWrap.prepend(next);
      } else if (oldBubbles) {
        oldBubbles.remove();
      }
    }
  }
}

async function epHandleGroupDrop(eventObj) {
  eventObj.preventDefault();
  const target = eventObj.currentTarget;
  target.classList.remove("is-dragover");
  const targetId = Number(target.dataset.groupId || 0);
  if (!targetId) return;
  const isAllMembersTarget = target.dataset.isAllMembers === "1";

  if (epDraggingMember && !isAllMembersTarget) {
    const { memberId, role } = epDraggingMember;
    if (!memberId) return;
    const targetGroup = (epState.groups || []).find((group) => Number(group.id) === Number(targetId));
    const targetIsRoleGroup = Number(targetGroup?.is_role_group || 0) === 1;
    const res = await epPost("/ep_group_members.php", {
      action: "add",
      group_id: targetId,
      member_id: memberId,
      role: targetIsRoleGroup ? (role || "") : "",
      skip_role_update: targetIsRoleGroup ? 0 : 1
    });
    if (res.status !== "OK") {
      alert(res.message || "Unable to add member.");
      return;
    }
    epApplyMemberDropResult(memberId, targetId, role || "");
    epRenderGroups();
    return;
  }

  if (!epDraggingGroupId || epDraggingGroupId === targetId || isAllMembersTarget) return;

  const fromIndex = epState.groups.findIndex((group) => group.id === epDraggingGroupId);
  const toIndex = epState.groups.findIndex((group) => group.id === targetId);
  if (fromIndex < 0 || toIndex < 0) return;

  const [moved] = epState.groups.splice(fromIndex, 1);
  epState.groups.splice(toIndex, 0, moved);
  epRenderGroups();
  epRenderEventGroupPicker();
  await epPersistGroupOrder();
}

function epHandleMemberDragStart(eventObj) {
  const row = eventObj.currentTarget;
  const blocked = eventObj.target && eventObj.target.closest("input, button, select, textarea, a, label");
  if (blocked) {
    eventObj.preventDefault();
    return;
  }
  const memberId = Number(row.dataset.memberId || 0);
  if (!memberId) {
    eventObj.preventDefault();
    return;
  }
  const roleInput = row.querySelector(".ep-member-group-role");
  const role = roleInput ? roleInput.value.trim() : (row.dataset.memberRole || "");
  epDraggingMember = { memberId, role };
  row.classList.add("is-dragging");
  if (eventObj.dataTransfer) {
    eventObj.dataTransfer.effectAllowed = "copy";
    const payload = JSON.stringify(epDraggingMember);
    eventObj.dataTransfer.setData("application/x-ep-member", payload);
    eventObj.dataTransfer.setData("text/plain", payload);
  }
}

function epHandleMemberDragEnd(eventObj) {
  const row = eventObj.currentTarget;
  row.classList.remove("is-dragging");
  epDraggingMember = null;
}

async function epPersistGroupOrder() {
  if (!epState.canManage) return;
  const order = epState.groups
    .filter((group) => Number(group.is_all_members || 0) !== 1)
    .map((group) => group.id);
  if (!order.length) return;
  const res = await epPost("/ep_groups.php", {
    action: "reorder",
    group_ids: order
  });
  if (res.status !== "OK") {
    alert(res.message || "Unable to save group order.");
    await epLoadGroups();
  }
}

async function epSelectGroup(groupId, groupName) {
  epState.currentGroupId = groupId;
  epRenderGroups();

  const label = epEl("epMemberGroupLabel");
  if (label) label.textContent = groupName;

  const groupEditForm = epEl("epGroupEditForm");
  const selectedGroup = (epState.groups || []).find((g) => Number(g.id) === Number(groupId));
  if (groupEditForm && selectedGroup && !groupEditForm.classList.contains("ep-hidden")) {
    epPopulateGroupEditForm(groupEditForm, selectedGroup);
  }

  const editBtn = epEl("epEditGroupBtn");
  const deleteBtn = epEl("epDeleteGroupBtn");
  const refreshBtn = epEl("epRefreshGroupMembersBtn");
  if (editBtn) editBtn.disabled = false;
  if (deleteBtn) deleteBtn.disabled = false;
  if (refreshBtn) refreshBtn.disabled = false;

  const list = epEl("epGroupMembers");
  if (!list) return;
  list.innerHTML = "<div class='ep-panel-sub'>Loading members...</div>";

  const data = await epGet(`/ep_group_members.php?group_id=${groupId}`);
  if (data.status !== "OK") {
    list.innerHTML = "<div class='ep-panel-sub'>Unable to load members.</div>";
    return;
  }
  epState.currentGroupIsAllMembers = Number(data.is_all_members || 0) === 1;
  epState.currentGroupData = {
    members: Array.isArray(data.members) ? data.members : [],
    pendingInvites: Array.isArray(data.pending_invites) ? data.pending_invites : [],
    isAllMembersGroup: !!data.is_all_members,
    accessListToken: data.access_list_token || ""
  };
  await epLoadDirectMessageUnreadMap();
  epRenderGroupMembersPanel(epState.currentGroupData);
}

function epPopulateGroupEditForm(groupEditForm, group) {
  if (!groupEditForm || !group) return;
  groupEditForm.name.value = group.name || "";
  const isAllMembers = Number(group.is_all_members || 0) === 1;
  groupEditForm.name.readOnly = isAllMembers;
  groupEditForm.name.classList.toggle("ep-input-readonly", isAllMembers);
  if (isAllMembers) {
    groupEditForm.name.title = "All Members name is protected.";
  } else {
    groupEditForm.name.removeAttribute("title");
  }
  groupEditForm.description.value = group.description || "";
  if (groupEditForm.color) {
    groupEditForm.color.value = group.color || "#9fb7f0";
  }
  if (groupEditForm.is_role_group) {
    const roleValue = Number(group.is_role_group || 0) === 1;
    groupEditForm.is_role_group.checked = !isAllMembers && roleValue;
    groupEditForm.is_role_group.disabled = isAllMembers;
  }
}

function epRenderGroupMembersPanel(data) {
  const list = epEl("epGroupMembers");
  if (!list) return;
  if (!data) {
    list.innerHTML = "<div class='ep-panel-sub'>Select a group to add members.</div>";
    return;
  }
  list.innerHTML = "";
  const members = Array.isArray(data.members) ? data.members : [];
  epState.currentGroupMembers = members;
  const searchQuery = String(epState.memberSearch || "").trim().toLowerCase();
  const pendingInvites = Array.isArray(data.pendingInvites) ? data.pendingInvites : [];

  const isOwner = epState.canManage;
  const isAllMembersGroup = !!data.isAllMembersGroup;
  const accessListToken = data.accessListToken || "";
  if (isOwner || isAllMembersGroup) {
    const header = document.createElement("div");
    header.className = "ep-member ep-member-header";
    if (isAllMembersGroup) {
      header.innerHTML = `
        <span class="ep-member-name-label ep-sortable" data-sort-key="member">Member<span class="ep-sort-indicator"></span></span>
        <span class="ep-member-role-label ep-role-header">
          <span class="ep-member-group-label ep-sortable" data-sort-key="group">Gr<span class="ep-sort-indicator"></span></span>
          <span class="ep-member-role-label-text ep-sortable" data-sort-key="role">Role<span class="ep-sort-indicator"></span></span>
        </span>
        <span class="ep-member-access-label ep-sortable" data-sort-key="access">Access<span class="ep-sort-indicator"></span></span>
      `;
    } else if (isOwner) {
      header.innerHTML = `
        <span class="ep-member-name-label ep-sortable" data-sort-key="member">Member<span class="ep-sort-indicator"></span></span>
        <span class="ep-member-role-label ep-role-header">
          <span class="ep-member-group-label ep-sortable" data-sort-key="group">Gr<span class="ep-sort-indicator"></span></span>
          <span class="ep-member-role-label-text ep-sortable" data-sort-key="role">Role<span class="ep-sort-indicator"></span></span>
        </span>
        <span class="ep-member-remove-label">Remove</span>
      `;
    }
    const sortLabels = header.querySelectorAll(".ep-sortable");
    sortLabels.forEach((label) => {
      label.addEventListener("click", () => {
        const key = label.dataset.sortKey || "member";
        if (epState.memberSort.key === key) {
          epState.memberSort.dir = epState.memberSort.dir === "asc" ? "desc" : "asc";
        } else {
          epState.memberSort.key = key;
          epState.memberSort.dir = "asc";
        }
        epSaveMemberSortPreference();
        epRenderGroupMembersPanel(epState.currentGroupData);
      });
    });
    epUpdateMemberSortIndicators(header);
    list.appendChild(header);
  }

  if (!members.length) {
    const empty = document.createElement("div");
    empty.className = "ep-member-list empty";
    empty.textContent = "No members yet.";
    list.appendChild(empty);
  }

  let sortedMembers = epSortMembers(members, isAllMembersGroup);
  if (searchQuery) {
    sortedMembers = sortedMembers.filter((member) => {
      const name = epMemberDisplayName(member).toLowerCase();
      const email = String(member.email || "").toLowerCase();
      const role = String(member.role || "").toLowerCase();
      return name.includes(searchQuery) || email.includes(searchQuery) || role.includes(searchQuery);
    });
  }

  sortedMembers.forEach((member) => {
    const row = document.createElement("div");
    row.className = "ep-member";
    row.dataset.memberId = String(member.member_id || "");
    const displayName = epMemberDisplayName(member);
    const avatarUrl = epResolveAvatarUrl(member, displayName);
    const dmUnreadCount = Number(epState.dmUnreadByMemberId[Number(member.member_id)] || 0);
    const dmUnreadLabel = dmUnreadCount > 99 ? "99+" : String(dmUnreadCount);
    const chatButtonHtml = `
      <button class="ep-btn ghost ep-member-chat-icon"
              type="button"
              data-member-id="${member.member_id}"
              title="Chat with ${epEscape(displayName)}"
              aria-label="Chat with ${epEscape(displayName)}"
              ${Number(member.member_id) === Number(epState.memberId) ? "disabled" : ""}>
        <span class="ep-member-chat-glyph">💬</span>
        ${dmUnreadCount > 0 ? `<span class="ep-member-chat-unread">${dmUnreadLabel}</span>` : ""}
      </button>
    `;
    const roleValue = member.role || "";
    row.dataset.memberRole = roleValue;
    const accessRole = member.access_role || "";
    if (isAllMembersGroup) {
      const canEditAccess = isOwner && accessRole !== "owner" && accessListToken;
      const accessControl = accessRole === "owner"
        ? `<span class="ep-member-access-label">Owner</span>`
        : `<select class="ep-member-access" data-email="${member.email || ""}" ${canEditAccess ? "" : "disabled"}>
            <option value="viewer" ${accessRole === "viewer" ? "selected" : ""}>Viewer</option>
            <option value="commenter" ${accessRole === "commenter" ? "selected" : ""}>Commenter</option>
            <option value="editor" ${accessRole === "editor" ? "selected" : ""}>Editor</option>
            <option value="admin" ${accessRole === "admin" ? "selected" : ""}>Admin</option>
            <option value="paused" ${accessRole === "paused" ? "selected" : ""}>Paused</option>
            <option value="remove">Remove</option>
          </select>`;
      row.innerHTML = `
        ${chatButtonHtml}
        <img src="${avatarUrl}" alt="${displayName}"
             data-avatar-seed="${member.member_id || member.email || member.username || displayName}"
             data-avatar-name="${displayName}"
             onerror="epHandleAvatarError(this)">
        <span>${displayName}</span>
        <div class="ep-member-role">
          ${epMemberGroupBubbles(member)}
          <div class="ep-member-dropdown-wrap">
            <input class="ep-member-group-role"
                   type="text"
                   value="${epEscape(roleValue)}"
                   placeholder="Select or type role"
                   autocomplete="off"
                   autocapitalize="off"
                   spellcheck="false"
                   data-member-id="${member.member_id}" ${isOwner ? "" : "disabled"}>
            <button class="ep-role-open" type="button" aria-label="Show role options" ${isOwner ? "" : "disabled"}>▾</button>
            <div class="ep-member-dropdown ep-role-dropdown"></div>
          </div>
          ${accessControl}
        </div>
      `;
      if (isOwner) {
        const groupRoleInput = row.querySelector(".ep-member-group-role");
        if (groupRoleInput) {
          epBindRoleHybrid(row, groupRoleInput, member.member_id);
        }
        const select = row.querySelector("select");
        if (select) {
          select.addEventListener("change", async () => {
            const email = select.dataset.email || "";
            if (!email || !accessListToken) return;
            const res = await fetch("/chatChangeInviteRole.php", {
              method: "POST",
              headers: { "Content-Type": "application/x-www-form-urlencoded" },
              body: `token=${encodeURIComponent(accessListToken)}&email=${encodeURIComponent(email)}&role=${encodeURIComponent(select.value)}`,
              credentials: "same-origin"
            }).then((response) => response.json());
            if (res.status === "success") {
              epSelectGroup(epState.currentGroupId, epEl("epMemberGroupLabel").textContent || "Members");
            } else {
              alert(res.message || "Unable to update access role.");
            }
          });
        }
      }
    } else {
      row.innerHTML = `
        ${chatButtonHtml}
        <img src="${avatarUrl}" alt="${displayName}"
             data-avatar-seed="${member.member_id || member.email || member.username || displayName}"
             data-avatar-name="${displayName}"
             onerror="epHandleAvatarError(this)">
        <span>${displayName}</span>
        <div class="ep-member-role">
          ${epMemberGroupBubbles(member)}
          ${isOwner ? `<div class="ep-member-dropdown-wrap">
                          <input class="ep-member-group-role"
                                 type="text"
                                 value="${epEscape(roleValue)}"
                                 placeholder="Select or type role"
                                 autocomplete="off"
                                 autocapitalize="off"
                                 spellcheck="false"
                                 data-member-id="${member.member_id}">
                          <button class="ep-role-open" type="button" aria-label="Show role options">▾</button>
                          <div class="ep-member-dropdown ep-role-dropdown"></div>
                        </div>`
                    : `<span>${roleValue || "-"}</span>`}
          ${isOwner ? `<button class="ep-btn ghost ep-member-remove" type="button" data-member-id="${member.member_id}" aria-label="Remove member" title="Remove">✕</button>` : ""}
        </div>
      `;
      if (isOwner) {
        const input = row.querySelector(".ep-member-group-role");
        if (input) {
          epBindRoleHybrid(row, input, member.member_id);
        }
        const removeBtn = row.querySelector(".ep-member-remove");
        if (removeBtn) {
          removeBtn.addEventListener("click", async () => {
            if (!epState.currentGroupId) return;
            if (!confirm("Remove member from this group?")) return;
            const res = await epPost("/ep_group_members.php", {
              action: "remove",
              group_id: epState.currentGroupId,
              member_id: member.member_id
            });
            if (res.status === "OK") {
              epSelectGroup(epState.currentGroupId, epEl("epMemberGroupLabel").textContent || "Members");
              epLoadGroupMembersSummary().then(epRenderGroups);
            } else {
              alert(res.message || "Unable to remove member.");
            }
          });
        }
      }
    }

    const chatBtn = row.querySelector(".ep-member-chat-icon");
    if (chatBtn) {
      chatBtn.addEventListener("click", (eventObj) => {
        eventObj.preventDefault();
        eventObj.stopPropagation();
        epOpenMemberDirectChat(member);
      });
    }

    if (isOwner && isAllMembersGroup) {
      row.classList.add("is-draggable");
      row.draggable = true;
      row.addEventListener("dragstart", epHandleMemberDragStart);
      row.addEventListener("dragend", epHandleMemberDragEnd);
    }

    list.appendChild(row);
  });

  if (isOwner && pendingInvites.length) {
    const pendingHeader = document.createElement("div");
    pendingHeader.className = "ep-member ep-member-header ep-member-header-pending";
    pendingHeader.setAttribute("role", "button");
    pendingHeader.setAttribute("tabindex", "0");
    pendingHeader.innerHTML = `
      <span class="ep-member-name-label">Pending invites</span>
      <span class="ep-member-pending-count">${pendingInvites.length}</span>
      <button class="ep-btn ghost ep-member-pending-resend-all" type="button">Resend all</button>
      <span class="ep-member-pending-toggle">▶</span>
    `;
    const pendingWrap = document.createElement("div");
    pendingWrap.className = "ep-member-pending-list ep-hidden";
    pendingInvites.forEach((invite) => {
      const row = document.createElement("div");
      row.className = "ep-member ep-member-pending";
      row.innerHTML = `
        <div class="ep-member-pending-avatar">⏳</div>
        <div>
          <div>${invite.email || "Invite"}</div>
          <div class="ep-panel-sub">Pending</div>
        </div>
        <div class="ep-member-pending-actions">
          <button class="ep-btn ghost ep-member-pending-resend" type="button">Resend</button>
          <button class="ep-btn ghost ep-member-pending-remove" type="button">Remove</button>
        </div>
      `;
      const resendBtn = row.querySelector(".ep-member-pending-resend");
      if (resendBtn) {
        resendBtn.addEventListener("click", async (event) => {
          event.stopPropagation();
          resendBtn.disabled = true;
          resendBtn.classList.add("is-loading");
          const res = await epPost("/ep_group_members.php", {
            action: "invite_resend",
            group_id: epState.currentGroupId,
            email: invite.email || ""
          });
          if (res.status === "OK") {
            epFlashMessage("✅ Invite resent.");
          } else {
            epFlashMessage(res.message || "Unable to resend invite.");
          }
          resendBtn.disabled = false;
          resendBtn.classList.remove("is-loading");
        });
      }
      const removeBtn = row.querySelector(".ep-member-pending-remove");
      if (removeBtn) {
        removeBtn.addEventListener("click", async (event) => {
          event.stopPropagation();
          removeBtn.disabled = true;
          const res = await epPost("/ep_group_members.php", {
            action: "invite_remove",
            group_id: epState.currentGroupId,
            email: invite.email || ""
          });
          if (res.status === "OK") {
            epFlashMessage("🗑️ Invite removed.");
            row.remove();
            const pendingCountEl = pendingHeader.querySelector(".ep-member-pending-count");
            if (pendingCountEl) {
              const count = Number(pendingCountEl.textContent || 0) - 1;
              pendingCountEl.textContent = Math.max(0, count);
            }
          } else {
            epFlashMessage(res.message || "Unable to remove invite.");
            removeBtn.disabled = false;
          }
        });
      }
      pendingWrap.appendChild(row);
    });
    list.appendChild(pendingHeader);
    list.appendChild(pendingWrap);

    const resendAllBtn = pendingHeader.querySelector(".ep-member-pending-resend-all");
    if (resendAllBtn) {
      resendAllBtn.addEventListener("click", async (event) => {
        event.stopPropagation();
        const prevText = resendAllBtn.textContent;
        resendAllBtn.textContent = "Sending...";
        resendAllBtn.disabled = true;
        resendAllBtn.classList.add("is-loading");
        const res = await epPost("/ep_group_members.php", {
          action: "invite_resend_all",
          group_id: epState.currentGroupId
        });
        if (res.status === "OK") {
          const sent = Number(res.sent || 0);
          epFlashMessage(sent ? `✅ Resent to ${sent} invite${sent === 1 ? "" : "s"}` : "ℹ️ No pending invites.");
        } else {
          epFlashMessage(res.message || "Unable to resend invites.");
        }
        resendAllBtn.textContent = prevText;
        resendAllBtn.disabled = false;
        resendAllBtn.classList.remove("is-loading");
      });
    }

    const pendingToggle = (event) => {
      if (event.target && event.target.closest(".ep-member-pending-resend-all")) return;
      const isHidden = pendingWrap.classList.contains("ep-hidden");
      pendingWrap.classList.toggle("ep-hidden", !isHidden);
      pendingHeader.querySelector(".ep-member-pending-toggle").textContent = isHidden ? "▼" : "▶";
    };
    pendingHeader.addEventListener("click", pendingToggle);
    pendingHeader.addEventListener("keydown", (event) => {
      if (event.key === "Enter" || event.key === " ") {
        event.preventDefault();
        pendingToggle(event);
      }
    });
  }

  epRenderInviteList(members);
}
function epRenderInviteList(groupMembers = null) {
  const list = epEl("epInviteList");
  if (!list) return;
  list.innerHTML = "";
  const activeMembers = Array.isArray(groupMembers) ? groupMembers : (epState.currentGroupMembers || []);

  if (!epState.currentGroupId) {
    list.innerHTML = "<div class='ep-panel-sub'>Select a group to add members.</div>";
    return;
  }
  if (epState.currentGroupIsAllMembers) {
    list.innerHTML = "<div class='ep-panel-sub'>All members are already included.</div>";
    return;
  }

  if (!epState.invitedMembers.length) {
    list.innerHTML = "<div class='ep-panel-sub'>No invited members available.</div>";
    return;
  }

  const existingIds = new Set(activeMembers.map((m) => Number(m.member_id)));
  let filtered = epState.invitedMembers.filter((m) => !existingIds.has(Number(m.member_id)));
  const query = String(epState.inviteSearch || "").trim().toLowerCase();
  if (query) {
    filtered = filtered.filter((member) => {
      const displayName = epMemberDisplayName(member).toLowerCase();
      const email = String(member.email || "").toLowerCase();
      const role = String(member.role || "").toLowerCase();
      return displayName.includes(query) || email.includes(query) || role.includes(query);
    });
  }

  if (!filtered.length) {
    list.innerHTML = query
      ? "<div class='ep-panel-sub'>No invited members match your search.</div>"
      : "<div class='ep-panel-sub'>All invited members are already in this group.</div>";
    return;
  }

  filtered.forEach((member) => {
    const card = document.createElement("div");
    card.className = "ep-invite-card";
    const displayName = epMemberDisplayName(member);
    const avatarUrl = epResolveAvatarUrl(member, displayName);
    card.innerHTML = `
      <img src="${avatarUrl}" alt="${displayName}"
           data-avatar-seed="${member.member_id || member.email || member.username || displayName}"
           data-avatar-name="${displayName}"
           onerror="epHandleAvatarError(this)">
      <div>
        <div>${displayName}</div>
        <div class="ep-panel-sub">${member.email}</div>
        ${member.role ? `<div class="ep-panel-sub">Role: ${member.role}</div>` : ""}
      </div>
      <button class="ep-btn ghost" type="button">Add</button>
    `;
    const btn = card.querySelector("button");
    if (btn) {
      btn.addEventListener("click", async () => {
        if (!epState.currentGroupId) return;
        const res = await epPost("/ep_group_members.php", {
          action: "add",
          group_id: epState.currentGroupId,
          member_id: member.member_id
        });
        if (res.status === "OK") {
          epSelectGroup(epState.currentGroupId, epEl("epMemberGroupLabel").textContent || "Members");
          epLoadInvitedMembers();
          epLoadGroupMembersSummary().then(epRenderGroups);
        } else {
          alert(res.message || "Unable to add member.");
        }
      });
    }
    list.appendChild(card);
  });
}

function epBuildEventRow(event, options = {}) {
  const { showDate = true, showNote = true } = options;
  const seriesLabel = epSeriesLabel(event.recurring_series_id);
  const eventNote = String(event.__resolvedNotes || event.notes || "").trim();
  const details = document.createElement("details");
  details.className = "ep-event-row";
  details.dataset.eventId = event.id;
  details.dataset.nextStatus = "";

  details.innerHTML = `
    <summary>
      <div class="ep-event-line1">
        <h4>${event.title}${event.owner_display_name || event.owner_username ? ` • ${epEscape(event.owner_display_name || event.owner_username)}` : ""}</h4>
        <div class="ep-event-line1-actions">
          <button class="ep-btn warm ep-inline-checkin" type="button" data-role="checkin-inline">Check in</button>
        </div>
      </div>
      <div class="ep-event-line2">
        <div class="ep-avatar-row" data-role="avatars"></div>
      </div>
      <div class="ep-event-line4">
        <div class="ep-event-bottom">
          ${showDate ? `<span class="ep-event-top-meta">${epFormatEventMeta(event)}</span>` : ""}
          ${seriesLabel ? `<span class="ep-event-series" title="${epEscape(String(event.recurring_series_id || ""))}">${epEscape(seriesLabel)}</span>` : ""}
          ${event.location ? `<span class="ep-event-location">${epEscape(event.location)}</span>` : ""}
          <span class="ep-event-groups" data-role="group-names"></span>
          ${showNote && eventNote ? `<span class="ep-event-note">${epEscape(eventNote)}</span>` : ""}
          <span class="ep-event-groups-meta" data-role="group-meta"></span>
        </div>
      </div>
    </summary>
    <div class="ep-event-expanded" data-role="expanded">
      <div class="ep-panel-sub">Loading details...</div>
    </div>
  `;

  details.addEventListener("toggle", () => {
    if (details.open) {
      epLoadEventDetail(event.id, details, event);
    }
  });

  epLoadEventAvatars(event.id, details);
  return details;
}

function epUpdateCategoryList(events) {
  const list = epEl("epCategoryList");
  const filter = epEl("epFilterCategory");
  const reportFilter = epEl("epAttendanceCategory");
  if (!list && !filter && !reportFilter) return;
  const seen = new Set();
  const categories = [];
  const add = (value) => {
    const name = epNormalizeCategoryName(value);
    if (!name || name.toLowerCase() === "general") return;
    if (seen.has(name)) return;
    seen.add(name);
    categories.push(name);
  };
  (epState.categoryCatalog || []).forEach((entry) => add(entry.id));
  if (list) {
    list.innerHTML = categories.map((cat) => `<option value="${epEscape(cat)}"></option>`).join("");
  }
  if (filter) {
    const current = filter.value || "";
    filter.innerHTML = `<option value="">All categories</option>` +
      categories.map((cat) => `<option value="${epEscape(cat)}">${epEscape(cat)}</option>`).join("");
    filter.value = current;
  }
  if (reportFilter) {
    const current = epState.attendanceFilters.category || reportFilter.value || "";
    reportFilter.innerHTML = `<option value="">All categories</option>` +
      categories.map((cat) => `<option value="${epEscape(cat)}">${epEscape(cat)}</option>`).join("");
    reportFilter.value = current;
    epState.attendanceFilters.category = reportFilter.value || "";
  }
  epRenderCategorySettings();
}

function epUpdateGroupFilter() {
  const filter = epEl("epFilterGroup");
  const reportFilter = epEl("epAttendanceGroup");
  if (!filter && !reportFilter) return;
  const current = filter ? (filter.value || "") : "";
  const currentReport = epState.attendanceFilters.groupId || (reportFilter ? (reportFilter.value || "") : "");
  const options = [
    `<option value="">All groups</option>`,
    ...epState.groups.map((group) => (
      `<option value="${group.id}">${epEscape(group.name || "Group")}</option>`
    ))
  ];
  if (filter) {
    filter.innerHTML = options.join("");
    filter.value = current;
  }
  if (reportFilter) {
    reportFilter.innerHTML = options.join("");
    reportFilter.value = currentReport;
    epState.attendanceFilters.groupId = reportFilter.value || "";
  }
}


function epRenderEvents() {
  const list = epEl("epEventList");
  if (!list) return;
  list.innerHTML = "";

  if (!epState.events.length) {
    list.innerHTML = "<div class='ep-card'><p>No events yet.</p></div>";
    return;
  }

  epState.events.forEach((event) => {
    list.appendChild(epBuildEventRow(event, { showDate: true }));
  });
}

function epEventCommentAuthor(comment) {
  return (comment?.display_name || comment?.username || "Member").trim();
}

function epEventCommentsWidgetHtml(eventId) {
  const id = Number(eventId || 0);
  return `
    <div class="ep-hero-comments" data-role="event-comments" data-event-id="${id}">
      <div class="ep-hero-comments-list" data-role="comment-list">
        <div class="ep-panel-sub">Loading comments...</div>
      </div>
      <form class="ep-hero-comments-form" data-role="comment-form">
        <input type="text" maxlength="1000" placeholder="Add event comment..." data-role="comment-input">
        <button class="ep-btn ghost" type="submit">Send</button>
      </form>
    </div>
  `;
}

function epRenderEventCommentsList(listEl, comments) {
  if (!listEl) return;
  const rows = Array.isArray(comments) ? comments : [];
  if (!rows.length) {
    listEl.innerHTML = "<div class='ep-panel-sub'>No comments yet.</div>";
    return;
  }
  listEl.innerHTML = rows.map((entry) => {
    const displayName = epEventCommentAuthor(entry);
    const avatarUrl = epResolveAvatarUrl(entry, displayName);
    const text = epEscape(String(entry.comment || "")).replace(/\n/g, "<br>");
    const when = epFormatDatePlain(entry.created_at || "");
    const canEditFresh = !!entry.can_edit_fresh;
    return `
      <div class="ep-hero-comment-item">
        <img src="${epEscape(avatarUrl)}" alt="${epEscape(displayName)}"
             class="ep-comment-member-avatar"
             data-member-id="${Number(entry.member_id || 0)}"
             data-username="${epEscape(String(entry.username || ""))}"
             data-display-name="${epEscape(displayName)}"
             data-avatar-url="${epEscape(String(entry.avatar_url || ""))}"
             data-avatar-seed="${epEscape(String(entry.member_id || entry.username || displayName))}"
             data-avatar-name="${epEscape(displayName)}"
             onerror="epHandleAvatarError(this)">
        <div class="ep-hero-comment-body">
          <div class="ep-hero-comment-head">
            <span class="ep-hero-comment-author">${epEscape(displayName)}</span>
            <span class="ep-hero-comment-time">${epEscape(when || "")}</span>
            ${canEditFresh
              ? `<button class="ep-comment-edit-btn" type="button" data-role="comment-edit" data-comment-id="${Number(entry.id || 0)}">Edit</button>`
              : ""}
          </div>
          <div class="ep-hero-comment-text">${text}</div>
        </div>
      </div>
    `;
  }).join("");
}

function epBindEventCommentAvatars(listEl, comments) {
  if (!listEl) return;
  const rows = Array.isArray(comments) ? comments : [];
  if (!rows.length) return;
  const byMemberId = new Map();
  rows.forEach((entry) => {
    const memberId = Number(entry?.member_id || 0);
    if (!memberId || byMemberId.has(memberId)) return;
    byMemberId.set(memberId, {
      member_id: memberId,
      username: String(entry?.username || ""),
      display_name: String(entry?.display_name || entry?.username || "Member"),
      avatar_url: String(entry?.avatar_url || "")
    });
  });
  listEl.querySelectorAll(".ep-comment-member-avatar").forEach((avatarImg) => {
    const memberId = Number(avatarImg.dataset.memberId || 0);
    const member = byMemberId.get(memberId);
    if (!member) return;
    epBindMemberAvatarDirectChat(avatarImg, member, { stopSummaryToggle: false });
  });
}

async function epLoadEventCommentsInto(widgetEl, eventId) {
  if (!widgetEl) return;
  const listEl = widgetEl.querySelector('[data-role="comment-list"]');
  if (!listEl) return;
  const id = Number(eventId || 0);
  if (!id) {
    listEl.innerHTML = "";
    widgetEl.classList.add("ep-hidden");
    return;
  }
  widgetEl.classList.remove("ep-hidden");
  listEl.innerHTML = "<div class='ep-panel-sub'>Loading comments...</div>";
  try {
    const data = await epGet(`/ep_event_comments.php?event_id=${id}`);
    if (data.status !== "OK") {
      listEl.innerHTML = `<div class='ep-panel-sub'>${epEscape(data.message || "Unable to load comments.")}</div>`;
      return;
    }
    const comments = data.comments || [];
    epRenderEventCommentsList(listEl, comments);
    epBindEventCommentAvatars(listEl, comments);
  } catch (err) {
    listEl.innerHTML = "<div class='ep-panel-sub'>Unable to load comments.</div>";
  }
}

function epBindEventCommentsWidget(widgetEl, eventId) {
  if (!widgetEl) return;
  const id = Number(eventId || 0);
  widgetEl.dataset.eventId = String(id);
  const stop = (eventObj) => {
    eventObj.stopPropagation();
  };
  if (!widgetEl.dataset.boundComments) {
    widgetEl.addEventListener("pointerdown", stop);
    widgetEl.addEventListener("mousedown", stop);
    widgetEl.addEventListener("click", stop);
    widgetEl.addEventListener("touchstart", stop, { passive: true });

    const form = widgetEl.querySelector('[data-role="comment-form"]');
    const input = widgetEl.querySelector('[data-role="comment-input"]');
    if (form && input) {
      form.addEventListener("submit", async (eventObj) => {
        eventObj.preventDefault();
        eventObj.stopPropagation();
        const targetEventId = Number(widgetEl.dataset.eventId || 0);
        const comment = String(input.value || "").trim();
        if (!targetEventId || !comment) return;
        const submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.disabled = true;
        try {
          const res = await epPost("/ep_event_comments.php", {
            event_id: targetEventId,
            comment
          });
          if (res.status !== "OK") {
            alert(res.message || "Unable to add comment.");
            return;
          }
          input.value = "";
          await epLoadEventCommentsInto(widgetEl, targetEventId);
        } finally {
          if (submitBtn) submitBtn.disabled = false;
        }
      });
    }
    widgetEl.addEventListener("click", async (eventObj) => {
      const editBtn = eventObj.target.closest('[data-role="comment-edit"]');
      if (!editBtn) return;
      eventObj.preventDefault();
      eventObj.stopPropagation();
      const targetEventId = Number(widgetEl.dataset.eventId || 0);
      const commentId = Number(editBtn.getAttribute("data-comment-id") || 0);
      if (!targetEventId || !commentId) return;
      const commentItem = editBtn.closest(".ep-hero-comment-item");
      const currentTextEl = commentItem ? commentItem.querySelector(".ep-hero-comment-text") : null;
      const current = currentTextEl ? currentTextEl.textContent.trim() : "";
      const next = prompt("Edit comment", current);
      if (next === null) return;
      const value = String(next).trim();
      if (!value) {
        alert("Comment cannot be empty.");
        return;
      }
      editBtn.disabled = true;
      try {
        const res = await epPost("/ep_event_comments.php", {
          action: "update",
          event_id: targetEventId,
          comment_id: commentId,
          comment: value
        });
        if (res.status !== "OK") {
          alert(res.message || "Unable to edit comment.");
          return;
        }
        await epLoadEventCommentsInto(widgetEl, targetEventId);
      } finally {
        editBtn.disabled = false;
      }
    });
    widgetEl.dataset.boundComments = "1";
  }
  epLoadEventCommentsInto(widgetEl, id);
}

function epRenderHeroCallout() {
  const card = epEl("epHeroCallout");
  const display = epEl("epHeroCalloutDisplay");
  const editBtn = epEl("epHeroCalloutEditBtn");
  const checkinBtn = epEl("epHeroCalloutCheckinBtn");
  const titleEl = epEl("epHeroCalloutTitle");
  const metaEl = epEl("epHeroCalloutMeta");
  const notesEl = epEl("epHeroCalloutNotes");
  const commentsEl = epEl("epHeroCalloutComments");
  const windowEl = epEl("epHeroCalloutWindow");
  const mediaWrap = epEl("epHeroCalloutMediaWrap");
  const imageEl = epEl("epHeroCalloutImage");
  const editForm = epEl("epHeroCalloutEditForm");
  if (!card || !display || !titleEl || !metaEl || !notesEl || !mediaWrap || !imageEl) return;

  const source = (epState.allEvents && epState.allEvents.length)
    ? epState.allEvents
    : (epState.events || []);
  const now = new Date();
  const days = Number(epState.calloutWindowDays || 7);
  const windowStart = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 0, 0, 0, 0);
  const windowEnd = new Date(windowStart.getTime());
  windowEnd.setDate(windowEnd.getDate() + Math.max(1, Math.floor(days)));
  windowEnd.setMilliseconds(-1);
  const timeline = (source || [])
    .map((event) => {
      const start = epParseEventDate(event.starts_at);
      const endRaw = epParseEventDate(event.ends_at);
      const end = endRaw || (start ? new Date(start.getTime() + (2 * 60 * 60 * 1000)) : null);
      return { event, start, end };
    })
    .filter((entry) => entry.start)
    .filter((entry) => entry.end && entry.end >= windowStart && entry.start <= windowEnd)
    .sort((a, b) => epEntryTime(a.start) - epEntryTime(b.start));

  if (!timeline.length) {
    epState.calloutEventId = 0;
    titleEl.textContent = "Next events";
    metaEl.textContent = "No event scheduled yet.";
    notesEl.textContent = "Create an event note to show callouts here.";
    card.classList.add("is-empty");
    card.classList.remove("has-media");
    mediaWrap.classList.add("ep-hidden");
    imageEl.src = "";
    imageEl.removeAttribute("alt");
    if (editForm) editForm.classList.add("ep-hidden");
    display.classList.remove("is-editable");
    display.removeAttribute("title");
    if (editBtn) editBtn.disabled = true;
    if (checkinBtn) {
      checkinBtn.disabled = true;
      checkinBtn.textContent = "Check in";
    }
    if (windowEl) {
      windowEl.innerHTML = "";
      windowEl.classList.add("ep-hidden");
    }
    if (commentsEl) {
      commentsEl.classList.add("ep-hidden");
      const listEl = commentsEl.querySelector('[data-role="comment-list"]');
      if (listEl) listEl.innerHTML = "";
    }
    const heroPopout = epEl("epHeroEventPopout");
    if (heroPopout) epResetCalendarPopout(heroPopout);
    return;
  }

  let active = timeline.find((entry) => entry.start <= now && entry.end >= now) || null;
  let label = "Current event";
  if (!active) {
    active = timeline.find((entry) => entry.start >= now) || timeline[timeline.length - 1];
    label = active.start >= now ? "Next events" : "Latest events";
  }

  const event = active.event || {};
  epState.calloutEventId = Number(event.id || 0);
  const title = String(event.title || "").trim() || "Untitled event";
  const notes = String(event.notes || "").trim();
  const when = epFormatDatePlain(event.starts_at);
  const location = String(event.location || "").trim();
  const category = String(event.category || "").trim();
  const imageUrl = String(event.image_url || "").trim();
  const isIn = epEventIsCheckedIn(event);

  const metaParts = [];
  if (when) metaParts.push(when);
  if (location) metaParts.push(location);
  if (category) metaParts.push(category);

  titleEl.textContent = `${label}: ${title}`;
  metaEl.textContent = metaParts.length ? metaParts.join(" | ") : "No schedule details.";
  notesEl.textContent = notes || "No event notes added.";
  card.classList.toggle("is-empty", !notes);
  display.classList.toggle("is-editable", epState.calloutEventId > 0);
  if (epState.calloutEventId > 0) {
    display.title = "Click to open event details";
  } else {
    display.removeAttribute("title");
  }
  if (editBtn) {
    editBtn.classList.toggle("ep-hidden", !epState.canManage);
    editBtn.disabled = epState.calloutEventId <= 0;
  }
  if (checkinBtn) {
    checkinBtn.classList.remove("ep-hidden");
    checkinBtn.disabled = epState.calloutEventId <= 0;
    checkinBtn.textContent = isIn ? "Check out" : "Check in";
    checkinBtn.classList.toggle("ep-inline-checkin-in", isIn);
  }
  if (commentsEl) {
    epBindEventCommentsWidget(commentsEl, epState.calloutEventId);
  }
  if (imageUrl) {
    imageEl.src = imageUrl;
    imageEl.alt = `${title} image`;
    mediaWrap.classList.remove("ep-hidden");
    card.classList.add("has-media");
  } else {
    imageEl.src = "";
    imageEl.removeAttribute("alt");
    mediaWrap.classList.add("ep-hidden");
    card.classList.remove("has-media");
  }

  if (windowEl) {
    const upcoming = timeline.filter((entry) => (
      entry.end
      && entry.end >= now
      && Number(entry.event?.id || 0) !== Number(event.id || 0)
    ));
    if (!upcoming.length) {
      windowEl.innerHTML = "";
      windowEl.classList.add("ep-hidden");
    } else {
      const dayKey = (dateObj) => {
        if (!(dateObj instanceof Date)) return "";
        const y = dateObj.getFullYear();
        const m = String(dateObj.getMonth() + 1).padStart(2, "0");
        const d = String(dateObj.getDate()).padStart(2, "0");
        return `${y}-${m}-${d}`;
      };
      const activeDayKey = dayKey(active.start);
      const sameDayAsFirst = upcoming.filter((entry) => dayKey(entry.start) === activeDayKey);
      const laterFuture = upcoming.filter((entry) => dayKey(entry.start) > activeDayKey);

      const renderMainStyleCard = (entry) => {
        const item = entry.event || {};
        const imageUrl = String(item.image_url || "").trim();
        const location = String(item.location || "").trim();
        const notes = String(item.notes || "").trim();
        const category = String(item.category || "").trim();
        const metaParts = [epFormatDatePlain(item.starts_at), location, category].filter(Boolean);
        const isIn = epEventIsCheckedIn(item);
        return `
          <div class="ep-hero-callout${imageUrl ? " has-media" : ""}" data-role="peer-card" data-event-id="${Number(item.id || 0)}">
            <div class="ep-hero-callout-layout">
              <div class="ep-hero-callout-content">
                <div class="ep-hero-callout-label">Important messages</div>
                <div class="ep-hero-callout-title">Next events: ${epEscape(item.title || "Untitled event")}</div>
                <div class="ep-hero-callout-meta">${epEscape(metaParts.join(" | "))}</div>
                <p class="ep-hero-callout-notes">${epEscape(notes || "No event notes added.")}</p>
                <div class="ep-hero-callout-actions">
                  <button class="ep-btn warm ep-inline-checkin${isIn ? " ep-inline-checkin-in" : ""}" type="button" data-role="peer-checkin" data-event-id="${Number(item.id || 0)}">${isIn ? "Check out" : "Check in"}</button>
                  ${epState.canManage ? `<button class="ep-btn ghost ep-inline-checkin" type="button" data-role="peer-edit" data-event-id="${Number(item.id || 0)}">Edit</button>` : ""}
                </div>
              </div>
              ${imageUrl ? `
                <div class="ep-hero-callout-media">
                  <img src="${epEscape(imageUrl)}" alt="${epEscape(item.title || "Event image")}">
                </div>
              ` : ""}
            </div>
          </div>
        `;
      };

      const renderCompactCard = (entry) => {
        const item = entry.event || {};
        const imageUrl = String(item.image_url || "").trim();
        const location = String(item.location || "").trim();
        const category = String(item.category || "").trim();
        const metaParts = [epFormatDatePlain(item.starts_at), location, category].filter(Boolean);
        const isIn = epEventIsCheckedIn(item);
        return `
          <div class="ep-hero-callout ep-hero-callout-peer ep-hero-callout-peer-compact" data-role="peer-card" data-event-id="${Number(item.id || 0)}">
            ${imageUrl ? `
              <div class="ep-hero-callout-compact-thumb">
                <img src="${epEscape(imageUrl)}" alt="${epEscape(item.title || "Event image")}">
              </div>
            ` : ""}
            <div class="ep-hero-callout-compact-body">
              <div class="ep-hero-callout-title">${epEscape(item.title || "Untitled event")}</div>
              <div class="ep-hero-callout-meta">${epEscape(metaParts.join(" | "))}</div>
            </div>
            <div class="ep-hero-callout-actions ep-hero-callout-compact-actions">
              <button class="ep-btn warm ep-inline-checkin${isIn ? " ep-inline-checkin-in" : ""}" type="button" data-role="peer-checkin" data-event-id="${Number(item.id || 0)}">${isIn ? "Check out" : "Check in"}</button>
              ${epState.canManage ? `<button class="ep-btn ghost ep-inline-checkin" type="button" data-role="peer-edit" data-event-id="${Number(item.id || 0)}">Edit</button>` : ""}
            </div>
          </div>
        `;
      };
      windowEl.innerHTML = `
        ${sameDayAsFirst.map((entry) => renderMainStyleCard(entry)).join("")}
        ${laterFuture.map((entry) => renderCompactCard(entry)).join("")}
      `;
      windowEl.classList.remove("ep-hidden");
      windowEl.querySelectorAll('[data-role="peer-card"]').forEach((cardEl) => {
        cardEl.addEventListener("click", (eventObj) => {
          if (eventObj.target.closest("#epHeroEventPopout")) return;
          eventObj.preventDefault();
          eventObj.stopPropagation();
          const nextId = Number(cardEl.getAttribute("data-event-id") || 0);
          if (!nextId) return;
          epState.calloutEventId = nextId;
          epOpenCalloutEventPopout(true, "upcoming", cardEl);
        });
      });
      windowEl.querySelectorAll('[data-role="peer-checkin"]').forEach((btn) => {
        btn.addEventListener("click", async (eventObj) => {
          eventObj.preventDefault();
          eventObj.stopPropagation();
          const eventId = Number(btn.getAttribute("data-event-id") || 0);
          if (!eventId) return;
          await epToggleEventCheckinById(eventId);
        });
      });
      windowEl.querySelectorAll('[data-role="peer-edit"]').forEach((btn) => {
        btn.addEventListener("click", (eventObj) => {
          eventObj.preventDefault();
          eventObj.stopPropagation();
          const eventId = Number(btn.getAttribute("data-event-id") || 0);
          if (!eventId) return;
          epOpenHeroCalloutEditor(eventId);
        });
      });
    }
  }
}

function epEntryTime(date) {
  return date instanceof Date ? date.getTime() : Number.MAX_SAFE_INTEGER;
}

function epOpenCalloutEventPopout(forceOpen = false, source = "", anchorEl = null) {
  const event = epFindEventById(epState.calloutEventId);
  if (!event) return;
  const popout = epEl("epHeroEventPopout");
  if (!popout) return;
  if (anchorEl && popout.parentElement !== anchorEl) {
    anchorEl.appendChild(popout);
  }
  const resolvedSource = String(source || popout.dataset.source || "main").toLowerCase();
  const eventId = Number(event.id || 0);
  if (!forceOpen && popout.dataset.eventId === String(eventId) && popout.classList.contains("active")) {
    epResetCalendarPopout(popout);
    return;
  }
  const eventDate = epParseEventDate(event.starts_at);
  const title = eventDate ? epFormatDateHeader(eventDate) : (event.title || "Event");
  popout.innerHTML = '<button class="ep-calendar-popout-close" type="button" aria-label="Close">×</button>';
  const heading = document.createElement("h4");
  heading.textContent = title;
  const listEl = document.createElement("div");
  listEl.className = "ep-calendar-popout-list";
  const row = epBuildEventRow(event, { showDate: false, showNote: resolvedSource === "upcoming" });
  row.classList.add("ep-calendar-popout-row");
  listEl.appendChild(row);
  const pollHtml = epRenderCalendarEventPolls(event);
  if (pollHtml) {
    const pollWrap = document.createElement("div");
    pollWrap.className = "ep-calendar-popout-polls";
    pollWrap.innerHTML = pollHtml;
    listEl.appendChild(pollWrap);
  }
  const showCommentsInPopout = resolvedSource === "upcoming";
  if (showCommentsInPopout) {
    const commentsWrap = document.createElement("div");
    commentsWrap.className = "ep-calendar-popout-comments";
    commentsWrap.innerHTML = epEventCommentsWidgetHtml(eventId);
    listEl.appendChild(commentsWrap);
  }
  popout.appendChild(heading);
  popout.appendChild(listEl);
  popout.dataset.eventId = String(eventId);
  popout.dataset.source = resolvedSource;
  popout.classList.add("active");
  if (!popout.dataset.boundClickGuard) {
    popout.addEventListener("click", (eventObj) => {
      eventObj.stopPropagation();
    });
    popout.dataset.boundClickGuard = "1";
  }
  const closeBtn = popout.querySelector(".ep-calendar-popout-close");
  if (closeBtn) {
    closeBtn.addEventListener("click", (eventObj) => {
      eventObj.preventDefault();
      eventObj.stopPropagation();
      epResetCalendarPopout(popout);
    });
  }
  if (showCommentsInPopout) {
    const commentsWidget = popout.querySelector('[data-role="event-comments"]');
    if (commentsWidget) {
      epBindEventCommentsWidget(commentsWidget, eventId);
      const commentInput = commentsWidget.querySelector('[data-role="comment-input"]');
      if (commentInput) {
        setTimeout(() => {
          commentInput.focus({ preventScroll: true });
        }, 30);
      }
    }
  }
}

async function epToggleEventCheckinById(eventId) {
  const id = Number(eventId || 0);
  const event = epFindEventById(id);
  if (!event) return;
  const isIn = epEventIsCheckedIn(event);
  const nextStatus = isIn ? "out" : "in";
  const res = await epPost("/ep_checkins.php", {
    event_id: Number(event.id),
    status: nextStatus
  });
  if (res.status !== "OK") {
    alert(res.message || "Unable to update check-in.");
    return;
  }
  epUpdateEventCheckinState(event.id, nextStatus);
  epRenderHeroCallout();
}

async function epToggleCalloutCheckin() {
  await epToggleEventCheckinById(epState.calloutEventId);
}

function epOpenHeroCalloutEditor(eventId = 0) {
  if (!epState.canManage) return;
  const id = Number(eventId || epState.calloutEventId || 0);
  if (!id) return;
  const event = epFindEventById(id);
  if (!event) return;
  epState.calloutEventId = id;
  const editForm = epEl("epHeroCalloutEditForm");
  const notesInput = epEl("epHeroEditNotes");
  const imageUrlInput = epEl("epHeroEditImageUrl");
  const imageFileInput = epEl("epHeroEditImageFile");
  const imagePreview = epEl("epHeroImagePreview");
  const imagePreviewImg = epEl("epHeroImagePreviewImg");
  if (!editForm || !notesInput || !imageUrlInput || !imageFileInput || !imagePreview || !imagePreviewImg) return;
  notesInput.value = String(event.notes || "");
  imageUrlInput.value = String(event.image_url || "");
  imageFileInput.value = "";
  epToggleImagePreview(imagePreview, imagePreviewImg, imageUrlInput.value);
  editForm.classList.remove("ep-hidden");
  notesInput.focus();
  notesInput.select();
}

function epFindEventById(eventId) {
  const id = Number(eventId || 0);
  if (!id) return null;
  return (epState.allEvents || []).find((event) => Number(event.id) === id)
    || (epState.events || []).find((event) => Number(event.id) === id)
    || null;
}

function epUpdateEventInState(eventId, patch) {
  const id = Number(eventId || 0);
  if (!id || !patch) return;
  const apply = (list) => {
    if (!Array.isArray(list)) return;
    list.forEach((event) => {
      if (Number(event.id) === id) {
        Object.assign(event, patch);
      }
    });
  };
  apply(epState.events);
  apply(epState.allEvents);
}

function epSanitizeOwnerToken(token) {
  const raw = String(token || "").trim().replace(/^invited-/, "");
  return raw.replace(/[^a-zA-Z0-9_.-]/g, "") || "owner";
}

function epEncodeR2KeyPath(key) {
  return String(key || "")
    .split("/")
    .map((part) => encodeURIComponent(part))
    .join("/");
}

function epImageFileExtension(file) {
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

function epArrayBufferToHex(buffer) {
  const bytes = new Uint8Array(buffer);
  let out = "";
  for (let i = 0; i < bytes.length; i += 1) {
    out += bytes[i].toString(16).padStart(2, "0");
  }
  return out;
}

async function epFileSha256Hex(file) {
  if (!window.crypto || !window.crypto.subtle || !file) return "";
  const data = await file.arrayBuffer();
  const hashBuffer = await window.crypto.subtle.digest("SHA-256", data);
  return epArrayBufferToHex(hashBuffer);
}

async function epUploadCalloutImageToR2(file, event) {
  if (!(file instanceof File)) {
    throw new Error("No file selected.");
  }
  if (!file.type.startsWith("image/")) {
    throw new Error("Only image files are allowed.");
  }
  if (file.size > 10 * 1024 * 1024) {
    throw new Error("Image must be 10MB or smaller.");
  }
  const ownerSegment = epSanitizeOwnerToken(
    event?.owner_username || epState.ownerToken || epState.username || ""
  );
  const ext = epImageFileExtension(file);
  const contentHash = await epFileSha256Hex(file);
  const suffix = contentHash || `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
  const key = `${ownerSegment}/events/images/${suffix}.${ext}`;
  const uploadUrl = `${EP_R2_UPLOAD_BASE}/?key=${encodeURIComponent(key)}`;
  const uploadRes = await fetch(uploadUrl, {
    method: "POST",
    headers: {
      "Content-Type": file.type || "application/octet-stream"
    },
    body: file
  });
  if (!uploadRes.ok) {
    throw new Error("R2 upload failed.");
  }
  return `${EP_R2_PUBLIC_BASE}/${epEncodeR2KeyPath(key)}`;
}

function epGetImageSuggestionListEl() {
  const listId = "epImageSuggestionsList";
  let listEl = document.getElementById(listId);
  if (!listEl) {
    listEl = document.createElement("datalist");
    listEl.id = listId;
    document.body.appendChild(listEl);
  }
  return listEl;
}

function epApplyImageSuggestions(scope = document) {
  const listEl = epGetImageSuggestionListEl();
  const values = Array.from(new Set((epState.imageSuggestions || [])
    .map((value) => String(value || "").trim())
    .filter((value) => value !== "")));
  listEl.innerHTML = values.map((value) => `<option value="${epEscape(value)}"></option>`).join("");
  const inputs = [];
  if (scope instanceof Element && scope.matches('input[name="image_url"], #epHeroEditImageUrl')) {
    inputs.push(scope);
  }
  if (scope && typeof scope.querySelectorAll === "function") {
    scope.querySelectorAll('input[name="image_url"], #epHeroEditImageUrl').forEach((el) => inputs.push(el));
  }
  inputs.forEach((input) => {
    input.setAttribute("list", listEl.id);
  });
}

function epRenderImageSuggestionGallery(container) {
  const ctx = container && container._epImageSuggestionCtx;
  if (!ctx) return;
  const { gallery, urlInput, previewWrap, previewImg } = ctx;
  if (!gallery || !urlInput) return;
  const items = (epState.imageSuggestions || []).slice(0, 18);
  if (!items.length) {
    gallery.innerHTML = "";
    gallery.classList.add("ep-hidden");
    return;
  }
  const activeUrl = String(urlInput.value || "").trim();
  gallery.innerHTML = items.map((url) => {
    const safeUrl = epEscape(url);
    const active = activeUrl === url ? " active" : "";
    return `
      <div class="ep-image-suggestion-cell" data-image-url="${safeUrl}">
        <button class="ep-image-suggestion-item${active}" type="button" data-image-url="${safeUrl}" title="Use image">
          <img src="${safeUrl}" alt="Recent image">
        </button>
        ${epState.canManage ? `<button class="ep-image-suggestion-remove" type="button" data-remove-image-url="${safeUrl}" aria-label="Remove image" title="Remove image">×</button>` : ""}
      </div>
    `;
  }).join("");
  gallery.classList.remove("ep-hidden");
  if (!gallery.dataset.boundImageSuggestions) {
    let lastLongPressAt = 0;
    gallery.addEventListener("click", (eventObj) => {
      if (Date.now() - lastLongPressAt < 650 && !eventObj.target.closest("[data-remove-image-url]")) {
        eventObj.preventDefault();
        eventObj.stopPropagation();
        return;
      }
      const removeBtn = eventObj.target.closest("[data-remove-image-url]");
      if (removeBtn) {
        eventObj.preventDefault();
        eventObj.stopPropagation();
        const removeUrl = String(removeBtn.getAttribute("data-remove-image-url") || "").trim();
        if (removeUrl) {
          epRemoveImageSuggestion(removeUrl);
        }
        return;
      }
      const btn = eventObj.target.closest("[data-image-url]");
      if (!btn) return;
      const nextUrl = String(btn.getAttribute("data-image-url") || "").trim();
      if (!nextUrl) return;
      urlInput.value = nextUrl;
      epToggleImagePreview(previewWrap, previewImg, nextUrl);
      epRememberImageSuggestion(nextUrl);
      epRenderImageSuggestionGallery(container);
    });
    let holdTimer = null;
    let holdCell = null;
    const clearHold = () => {
      if (holdTimer) {
        clearTimeout(holdTimer);
        holdTimer = null;
      }
    };
    gallery.addEventListener("pointerdown", (eventObj) => {
      const cell = eventObj.target.closest(".ep-image-suggestion-cell");
      if (!cell) return;
      const pointerType = String(eventObj.pointerType || "");
      if (pointerType !== "touch" && pointerType !== "pen") return;
      clearHold();
      holdCell = cell;
      holdTimer = setTimeout(() => {
        if (!holdCell) return;
        holdCell.classList.add("show-remove");
        lastLongPressAt = Date.now();
        holdTimer = null;
      }, 550);
    });
    ["pointerup", "pointercancel", "pointerleave"].forEach((eventName) => {
      gallery.addEventListener(eventName, () => {
        clearHold();
      });
    });
    gallery.addEventListener("pointerdown", (eventObj) => {
      const cell = eventObj.target.closest(".ep-image-suggestion-cell");
      if (!cell) {
        gallery.querySelectorAll(".ep-image-suggestion-cell.show-remove").forEach((node) => {
          node.classList.remove("show-remove");
        });
      } else if (!cell.classList.contains("show-remove")) {
        gallery.querySelectorAll(".ep-image-suggestion-cell.show-remove").forEach((node) => {
          if (node !== cell) node.classList.remove("show-remove");
        });
      }
    });
    gallery.dataset.boundImageSuggestions = "1";
  }
}

function epRefreshImageSuggestionGalleries() {
  document.querySelectorAll("[data-image-ux-bound='1']").forEach((container) => {
    epRenderImageSuggestionGallery(container);
  });
}

function epRememberImageSuggestion(url) {
  const clean = String(url || "").trim();
  if (!clean) return;
  const deduped = [clean, ...(epState.imageSuggestions || []).filter((item) => item !== clean)];
  epState.imageSuggestions = deduped.slice(0, 60);
  epApplyImageSuggestions();
  epRefreshImageSuggestionGalleries();
}

async function epRemoveImageSuggestion(url) {
  const clean = String(url || "").trim();
  if (!clean || !epState.canManage) return;
  const res = await epPost("/ep_events.php", {
    action: "image_suggestion_remove",
    image_url: clean
  });
  if (res.status !== "OK") {
    alert(res.message || "Unable to remove image.");
    return;
  }
  epState.imageSuggestions = (epState.imageSuggestions || []).filter((item) => item !== clean);
  epApplyImageSuggestions();
  epRefreshImageSuggestionGalleries();
  epFlashMessage("Image removed");
}

async function epLoadImageSuggestions() {
  if (!epState.canManage) return;
  const res = await epPost("/ep_events.php", {
    action: "image_suggestions",
    limit: 40
  });
  if (res.status !== "OK") return;
  epState.imageSuggestions = Array.isArray(res.images)
    ? res.images.map((item) => String(item || "").trim()).filter((item) => item)
    : [];
  epApplyImageSuggestions();
  epRefreshImageSuggestionGalleries();
}

function epGetImageFileFromTransfer(transfer) {
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

function epToggleImagePreview(previewWrap, previewImg, url) {
  if (!previewWrap || !previewImg) return;
  const next = String(url || "").trim();
  if (!next) {
    previewImg.src = "";
    previewWrap.classList.add("ep-hidden");
    return;
  }
  previewImg.src = next;
  previewWrap.classList.remove("ep-hidden");
}

function epBindImageFieldInteractions({
  container,
  dropzone,
  urlInput,
  fileInput,
  fileTrigger,
  previewWrap,
  previewImg,
  resolveEvent
}) {
  if (!container || !urlInput || !fileInput) return;
  if (container.dataset.imageUxBound === "1") return;
  container.dataset.imageUxBound = "1";
  epApplyImageSuggestions(container);
  let gallery = container.querySelector("[data-role='image-suggestions']");
  if (!gallery) {
    gallery = document.createElement("div");
    gallery.className = "ep-image-suggestion-grid ep-hidden";
    gallery.setAttribute("data-role", "image-suggestions");
    if (dropzone && dropzone.parentNode) {
      dropzone.insertAdjacentElement("afterend", gallery);
    } else {
      container.appendChild(gallery);
    }
  }
  container._epImageSuggestionCtx = { gallery, urlInput, previewWrap, previewImg };
  epRenderImageSuggestionGallery(container);

  const setBusy = (busy) => {
    container.dataset.imageUploading = busy ? "1" : "0";
    if (dropzone) {
      dropzone.classList.toggle("is-uploading", !!busy);
      dropzone.textContent = busy ? "Uploading image..." : "Paste, drop, or select image";
    }
  };

  const applyUrl = (url) => {
    urlInput.value = String(url || "").trim();
    epToggleImagePreview(previewWrap, previewImg, urlInput.value);
    if (urlInput.value) {
      epRememberImageSuggestion(urlInput.value);
    }
  };

  const uploadFile = async (file) => {
    if (!file) return;
    setBusy(true);
    try {
      const event = typeof resolveEvent === "function" ? resolveEvent() : null;
      const imageUrl = await epUploadCalloutImageToR2(file, event);
      applyUrl(imageUrl);
      epFlashMessage("Image uploaded");
    } catch (err) {
      alert(err?.message || "Unable to upload image.");
    } finally {
      setBusy(false);
    }
  };

  fileInput.addEventListener("change", () => {
    const file = fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
    uploadFile(file);
  });
  if (fileTrigger) {
    fileTrigger.addEventListener("click", (eventObj) => {
      eventObj.preventDefault();
      fileInput.click();
    });
  }

  if (dropzone) {
    dropzone.addEventListener("click", () => {
      fileInput.click();
    });
    dropzone.addEventListener("dragover", (eventObj) => {
      eventObj.preventDefault();
      dropzone.classList.add("is-dragover");
    });
    dropzone.addEventListener("dragleave", () => {
      dropzone.classList.remove("is-dragover");
    });
    dropzone.addEventListener("drop", (eventObj) => {
      eventObj.preventDefault();
      dropzone.classList.remove("is-dragover");
      const file = epGetImageFileFromTransfer(eventObj.dataTransfer);
      if (file) uploadFile(file);
    });
  }

  container.addEventListener("paste", (eventObj) => {
    const file = epGetImageFileFromTransfer(eventObj.clipboardData);
    if (!file) return;
    eventObj.preventDefault();
    uploadFile(file);
  });

  urlInput.addEventListener("input", () => {
    epToggleImagePreview(previewWrap, previewImg, urlInput.value);
    epRenderImageSuggestionGallery(container);
  });
  epToggleImagePreview(previewWrap, previewImg, urlInput.value);
}

function epBindHeroCalloutEditor() {
  const display = epEl("epHeroCalloutDisplay");
  const editBtn = epEl("epHeroCalloutEditBtn");
  const checkinBtn = epEl("epHeroCalloutCheckinBtn");
  const editForm = epEl("epHeroCalloutEditForm");
  const notesInput = epEl("epHeroEditNotes");
  const imageUrlInput = epEl("epHeroEditImageUrl");
  const imageFileInput = epEl("epHeroEditImageFile");
  const imageSelectBtn = epEl("epHeroEditImageSelectBtn");
  const imageDropzone = epEl("epHeroImageDropzone");
  const imagePreview = epEl("epHeroImagePreview");
  const imagePreviewImg = epEl("epHeroImagePreviewImg");
  const cancelBtn = epEl("epHeroCalloutEditCancel");
  if (!display || !editForm || !notesInput || !imageUrlInput || !imageFileInput || !cancelBtn) return;

  epBindImageFieldInteractions({
    container: editForm,
    dropzone: imageDropzone,
    urlInput: imageUrlInput,
    fileInput: imageFileInput,
    fileTrigger: imageSelectBtn,
    previewWrap: imagePreview,
    previewImg: imagePreviewImg,
    resolveEvent: () => epFindEventById(epState.calloutEventId)
  });

  const openEditor = () => epOpenHeroCalloutEditor(epState.calloutEventId);

  if (!display.dataset.boundCalloutPopout) {
    display.addEventListener("click", (eventObj) => {
      if (eventObj.target.closest("button")) return;
      const heroCard = display.closest("#epHeroCallout");
      epOpenCalloutEventPopout(false, "main", heroCard || null);
    });
    display.dataset.boundCalloutPopout = "1";
  }

  if (editBtn && !editBtn.dataset.boundCalloutOpen) {
    editBtn.addEventListener("click", (eventObj) => {
      eventObj.preventDefault();
      eventObj.stopPropagation();
      openEditor();
    });
    editBtn.dataset.boundCalloutOpen = "1";
  }

  if (checkinBtn && !checkinBtn.dataset.boundCalloutCheckin) {
    checkinBtn.addEventListener("click", async (eventObj) => {
      eventObj.preventDefault();
      eventObj.stopPropagation();
      await epToggleCalloutCheckin();
    });
    checkinBtn.dataset.boundCalloutCheckin = "1";
  }

  if (!cancelBtn.dataset.boundCalloutEdit) {
    cancelBtn.addEventListener("click", () => {
      editForm.classList.add("ep-hidden");
    });
    cancelBtn.dataset.boundCalloutEdit = "1";
  }

  if (!editForm.dataset.boundCalloutEdit) {
    editForm.addEventListener("submit", async (eventObj) => {
      eventObj.preventDefault();
      const event = epFindEventById(epState.calloutEventId);
      if (!event) return;
      if (editForm.dataset.imageUploading === "1") {
        alert("Image is still uploading. Please wait.");
        return;
      }
      let imageUrl = imageUrlInput.value.trim();
      try {
        const res = await epPost("/ep_events.php", {
          action: "update",
          event_id: Number(event.id),
          notes: notesInput.value.trim(),
          image_url: imageUrl
        });
        if (res.status !== "OK") {
          alert(res.message || "Unable to save message.");
          return;
        }
        epUpdateEventInState(event.id, {
          notes: notesInput.value.trim(),
          image_url: imageUrl
        });
        if (imageUrl) {
          epRememberImageSuggestion(imageUrl);
        }
        editForm.classList.add("ep-hidden");
        epRenderHeroCallout();
        epRenderEvents();
      } catch (err) {
        alert(err?.message || "Unable to upload image.");
      }
    });
    editForm.dataset.boundCalloutEdit = "1";
  }
}

function epBindEventImageUploader(form, eventResolver) {
  if (!form) return;
  const urlInput = form.querySelector('[name="image_url"]');
  const fileInput = form.querySelector('[name="image_file"]');
  const fileTrigger = form.querySelector('[data-role="image-select"]');
  const dropzone = form.querySelector('[data-role="image-drop"]');
  const previewWrap = form.querySelector('[data-role="image-preview"]');
  const previewImg = previewWrap ? previewWrap.querySelector("img") : null;
  if (!urlInput || !fileInput || !dropzone || !previewWrap || !previewImg) return;
  epBindImageFieldInteractions({
    container: form,
    dropzone,
    urlInput,
    fileInput,
    fileTrigger,
    previewWrap,
    previewImg,
    resolveEvent: eventResolver
  });
}

function epGetPollEventSource() {
  const source = (epState.allEvents && epState.allEvents.length) ? epState.allEvents : epState.events;
  const unique = [];
  const seen = new Set();
  (source || []).forEach((event) => {
    const id = Number(event.id);
    if (!id || seen.has(id)) return;
    seen.add(id);
    unique.push({
      id,
      title: event.title || `Event #${id}`,
      starts_at: event.starts_at || ""
    });
  });
  unique.sort((a, b) => {
    const da = epParseEventDate(a.starts_at);
    const db = epParseEventDate(b.starts_at);
    const ta = da ? da.getTime() : Number.MAX_SAFE_INTEGER;
    const tb = db ? db.getTime() : Number.MAX_SAFE_INTEGER;
    return ta - tb;
  });
  return unique;
}

function epRenderPollEventSelect() {
  const select = epEl("epPollEventId");
  if (!select) return;
  const current = select.value || "";
  const events = epGetPollEventSource();
  select.innerHTML = `<option value="">Select event</option>` + events
    .map((event) => `<option value="${event.id}">${epEscape(event.title)}${event.starts_at ? ` (${epEscape(epFormatDatePlain(event.starts_at))})` : ""}</option>`)
    .join("");
  if (current && Array.from(select.options).some((option) => option.value === current)) {
    select.value = current;
  }
}

function epPollVoterAvatarHtml(voter) {
  const displayName = voter.display_name || voter.username || "Member";
  const avatarUrl = epResolveAvatarUrl(voter, displayName);
  const seed = voter.member_id || voter.username || displayName;
  return `<span class="ep-poll-voter" title="${epEscape(displayName)}">
    <img src="${avatarUrl}" alt="${epEscape(displayName)}"
      data-avatar-seed="${epEscape(String(seed))}"
      data-avatar-name="${epEscape(displayName)}"
      onerror="epHandleAvatarError(this)">
  </span>`;
}

function epPollOptionHtml(poll, option, participantCount) {
  const mine = (poll.my_option_ids || []).includes(Number(option.id));
  const voters = Array.isArray(option.voters) ? option.voters : [];
  const count = Number(option.vote_count || voters.length || 0);
  const progress = participantCount > 0 ? Math.round((count / participantCount) * 100) : 0;
  const preview = voters.slice(0, 8);
  const extra = Math.max(0, voters.length - preview.length);
  return `
    <div class="ep-poll-option-row${mine ? " active" : ""}">
      <button class="ep-poll-option${mine ? " active" : ""}" type="button"
        data-role="poll-option"
        data-poll-id="${Number(poll.id)}"
        data-option-id="${Number(option.id)}">
        <span class="ep-poll-option-top">
          <span class="ep-poll-option-text">${epEscape(option.option_text || "Option")}</span>
          <span class="ep-poll-option-meta">
            <span class="ep-poll-voters">
              ${preview.map((voter) => epPollVoterAvatarHtml(voter)).join("")}
              ${extra > 0 ? `<span class="ep-poll-voter ep-poll-voter-count">+${extra}</span>` : ""}
            </span>
            <span class="ep-poll-option-stats">
              <span class="ep-poll-count">${count}</span>
              <span class="ep-poll-stat-label"> vote${count === 1 ? "" : "s"}</span>
            </span>
          </span>
        </span>
      </button>
      <div class="ep-poll-progress" aria-hidden="true">
        <span style="width: ${Math.max(0, Math.min(100, progress))}%;"></span>
        <span class="ep-poll-progress-label">${progress}%</span>
      </div>
    </div>
  `;
}

function epPollParticipantCount(poll) {
  const participants = new Set();
  (poll.options || []).forEach((option) => {
    (option.voters || []).forEach((voter) => {
      const id = Number(voter.member_id);
      if (id > 0) participants.add(id);
    });
  });
  return participants.size;
}

function epGetPollsForEvent(eventId) {
  const id = Number(eventId || 0);
  if (!id) return [];
  return (epState.polls || []).filter((poll) => Number(poll.event_id) === id);
}

function epPollOptionCompactHtml(poll, option, participantCount) {
  const mine = (poll.my_option_ids || []).includes(Number(option.id));
  const voters = Array.isArray(option.voters) ? option.voters : [];
  const count = Number(option.vote_count || voters.length || 0);
  const progress = participantCount > 0 ? Math.round((count / participantCount) * 100) : 0;
  const preview = voters.slice(0, 5);
  const extra = Math.max(0, voters.length - preview.length);
  return `
    <button class="ep-poll-compact-option${mine ? " active" : ""}" type="button"
      data-role="poll-option"
      data-poll-id="${Number(poll.id)}"
      data-option-id="${Number(option.id)}">
      <span class="ep-poll-compact-top">
        <span class="ep-poll-compact-text">${epEscape(option.option_text || "Option")}</span>
        <span class="ep-poll-compact-meta">
          <span class="ep-poll-compact-voters">
            ${preview.map((voter) => epPollVoterAvatarHtml(voter)).join("")}
            ${extra > 0 ? `<span class="ep-poll-voter ep-poll-voter-count">+${extra}</span>` : ""}
          </span>
          <span class="ep-poll-compact-stats">${count}</span>
        </span>
      </span>
      <span class="ep-poll-compact-progress" aria-hidden="true">
        <span style="width: ${Math.max(0, Math.min(100, progress))}%;"></span>
        <span class="ep-poll-progress-label">${progress}%</span>
      </span>
    </button>
  `;
}

function epRenderCalendarEventPolls(event) {
  const polls = epGetPollsForEvent(event.id);
  if (!polls.length) return "";
  return polls.map((poll) => {
    const participantCount = epPollParticipantCount(poll);
    return `
      <div class="ep-calendar-poll-card">
        <div class="ep-calendar-poll-head">
          <span class="ep-calendar-poll-label">Poll</span>
          <span class="ep-calendar-poll-mode">${poll.allow_multiple ? "Multi" : "Single"}</span>
        </div>
        <div class="ep-calendar-poll-question">${epEscape(poll.question || "Poll")}</div>
        <div class="ep-calendar-poll-options">
          ${(poll.options || []).map((option) => epPollOptionCompactHtml(poll, option, participantCount)).join("")}
        </div>
      </div>
    `;
  }).join("");
}

function epRenderPolls() {
  const list = epEl("epPollList");
  if (!list) return;
  if (!epState.polls.length) {
    list.innerHTML = "<div class='ep-card'><p>No polls yet.</p></div>";
    return;
  }
  list.innerHTML = epState.polls.map((poll) => {
    const participantCount = epPollParticipantCount(poll);
    return `
    <div class="ep-poll-card">
      <div class="ep-poll-head">
        <h4 class="ep-poll-title">${epEscape(poll.question || "Poll")}</h4>
        <span class="ep-panel-sub">#${Number(poll.id)} • ${poll.allow_multiple ? "Multiple choice" : "Single choice"}</span>
      </div>
      <div class="ep-panel-sub">
        Event: ${epEscape(poll.event_title || `#${poll.event_id || ""}`)}${poll.owner_display_name ? ` • ${epEscape(poll.owner_display_name)}` : ""}
      </div>
      <div class="ep-panel-sub">
        ${participantCount > 0 ? `${participantCount} participant${participantCount === 1 ? "" : "s"}` : "No votes yet"}
      </div>
      <div class="ep-poll-options">
        ${(poll.options || []).map((option) => epPollOptionHtml(poll, option, participantCount)).join("")}
      </div>
      ${epState.canManage ? `
        <div class="ep-poll-actions">
          <button class="ep-btn ghost" type="button" data-role="poll-delete" data-poll-id="${Number(poll.id)}">Delete</button>
        </div>
      ` : ""}
    </div>
  `;
  }).join("");
}

function epPollsSignature(polls) {
  const normalized = (polls || []).map((poll) => ({
    id: Number(poll.id || 0),
    my: (poll.my_option_ids || []).map((id) => Number(id)).sort((a, b) => a - b),
    options: (poll.options || []).map((option) => ({
      id: Number(option.id || 0),
      count: Number(option.vote_count || (Array.isArray(option.voters) ? option.voters.length : 0) || 0),
      voters: (option.voters || [])
        .map((voter) => Number(voter.member_id || 0))
        .filter((id) => id > 0)
        .sort((a, b) => a - b)
    }))
  }));
  return JSON.stringify(normalized);
}

function epApplyPollState(polls) {
  epState.polls = polls || [];
  epState.pollSyncSignature = epPollsSignature(epState.polls);
  epRenderPolls();

  // If calendar popout is open, refresh only that popout content.
  const popout = epEl("epCalendarPopout");
  const dateKey = popout ? (popout.dataset.dateKey || "") : "";
  if (dateKey) {
    const dayEl = document.querySelector(`.ep-calendar-day[data-date-key="${dateKey}"]`);
    if (dayEl) {
      epShowCalendarEvents(dateKey, dayEl, { forceOpen: true });
    }
  }
}

async function epLoadPolls() {
  const data = await epGet("/ep_polls.php");
  if (data.status !== "OK") {
    epFlashMessage(data.message || "Unable to load polls.");
    return;
  }
  epApplyPollState(data.polls || []);
}

async function epSyncPollsSilently() {
  if (epState.pollSyncBusy) return;
  if (document.hidden) return;
  epState.pollSyncBusy = true;
  try {
    const data = await epGet("/ep_polls.php");
    if (data.status !== "OK") return;
    const nextPolls = data.polls || [];
    const nextSig = epPollsSignature(nextPolls);
    if (nextSig === epState.pollSyncSignature) return;
    epApplyPollState(nextPolls);
  } catch (err) {
    // Silent sync: ignore transient poll errors.
  } finally {
    epState.pollSyncBusy = false;
  }
}

function epStartPollSync() {
  if (epState.pollSyncTimer) return;
  epState.pollSyncTimer = setInterval(() => {
    epSyncPollsSilently();
  }, 7000);
}

async function epSubmitVote(pollId, optionIds) {
  const res = await epPost("/ep_polls.php", {
    action: "vote",
    poll_id: Number(pollId),
    option_ids: (optionIds || []).map((id) => Number(id)).filter((id) => id > 0)
  });
  if (res.status !== "OK") {
    epFlashMessage(res.message || "Unable to save vote.");
    return;
  }
  await epLoadPolls();
  const popout = epEl("epCalendarPopout");
  const dateKey = popout ? (popout.dataset.dateKey || "") : "";
  if (dateKey) {
    const dayEl = document.querySelector(`.ep-calendar-day[data-date-key="${dateKey}"]`);
    if (dayEl) {
      epShowCalendarEvents(dateKey, dayEl, { forceOpen: true });
    }
  }
}

async function epHandlePollOptionVote(pollId, optionId) {
  const poll = epState.polls.find((entry) => Number(entry.id) === Number(pollId));
  if (!poll || !optionId) return;
  const current = new Set((poll.my_option_ids || []).map((id) => Number(id)));
  if (poll.allow_multiple) {
    if (current.has(optionId)) {
      current.delete(optionId);
    } else {
      current.add(optionId);
    }
    await epSubmitVote(pollId, Array.from(current));
  } else {
    await epSubmitVote(pollId, [optionId]);
  }
}

function epBindPolls() {
  const form = epEl("epPollForm");
  const list = epEl("epPollList");
  const toggleBtn = epEl("epTogglePollForm");
  if (!epState.canManage) {
    if (form) form.classList.add("ep-hidden");
    if (toggleBtn) toggleBtn.classList.add("ep-hidden");
  } else if (toggleBtn && form) {
    toggleBtn.addEventListener("click", () => {
      form.classList.toggle("ep-hidden");
      const isOpen = !form.classList.contains("ep-hidden");
      toggleBtn.textContent = isOpen ? "Cancel" : "Create poll";
      toggleBtn.classList.toggle("cancel", isOpen);
      if (!isOpen) {
        form.reset();
      }
    });
  }
  if (form) {
    form.addEventListener("submit", async (eventObj) => {
      eventObj.preventDefault();
      const eventId = Number(form.event_id.value || 0);
      const question = (form.question.value || "").trim();
      const options = (form.options.value || "")
        .split(/\r?\n/)
        .map((line) => line.trim())
        .filter((line) => line);
      const allowMultiple = form.allow_multiple && form.allow_multiple.checked ? 1 : 0;
      if (!eventId || !question || options.length < 2) {
        epFlashMessage("Select event, add question, and at least two options.");
        return;
      }
      const res = await epPost("/ep_polls.php", {
        action: "create",
        event_id: eventId,
        question,
        allow_multiple: allowMultiple,
        options
      });
      if (res.status !== "OK") {
        epFlashMessage(res.message || "Unable to create poll.");
        return;
      }
      form.reset();
      form.classList.add("ep-hidden");
      if (toggleBtn) {
        toggleBtn.textContent = "Create poll";
        toggleBtn.classList.remove("cancel");
      }
      await epLoadPolls();
      epFlashMessage("Poll created.");
    });
  }

  if (list) {
    list.addEventListener("click", async (eventObj) => {
      const voteBtn = eventObj.target.closest('[data-role="poll-option"]');
      if (voteBtn) {
        const pollId = Number(voteBtn.dataset.pollId || 0);
        const optionId = Number(voteBtn.dataset.optionId || 0);
        await epHandlePollOptionVote(pollId, optionId);
        return;
      }

      const deleteBtn = eventObj.target.closest('[data-role="poll-delete"]');
      if (deleteBtn) {
        const pollId = Number(deleteBtn.dataset.pollId || 0);
        if (!pollId) return;
        if (!confirm("Delete this poll?")) return;
        const res = await epPost("/ep_polls.php", {
          action: "delete",
          poll_id: pollId
        });
        if (res.status !== "OK") {
          epFlashMessage(res.message || "Unable to delete poll.");
          return;
        }
        await epLoadPolls();
      }
    });
  }

  const calendarPopout = epEl("epCalendarPopout");
  if (calendarPopout) {
    calendarPopout.addEventListener("click", async (eventObj) => {
      const voteBtn = eventObj.target.closest('[data-role="poll-option"]');
      if (!voteBtn) return;
      eventObj.preventDefault();
      eventObj.stopPropagation();
      const pollId = Number(voteBtn.dataset.pollId || 0);
      const optionId = Number(voteBtn.dataset.optionId || 0);
      await epHandlePollOptionVote(pollId, optionId);
    });
  }
}

function epApplyEditMode() {
  const toggleFormBtn = epEl("epToggleEventForm");
  const eventForm = epEl("epEventForm");
  const canManage = epState.canManage;
  if (toggleFormBtn) toggleFormBtn.classList.toggle("ep-hidden", !canManage);
  if (!canManage && eventForm) eventForm.classList.add("ep-hidden");
}

function epGetMyStatusFromEvent(data) {
  const checkins = data.checkins || [];
  const entry = checkins.find((c) => Number(c.member_id) === epState.memberId);
  if (entry) return entry.status;
  const isDefaultIn = Number(data.is_member || 0) === 1 || Number(data.event?.all_members || 0) === 1;
  return isDefaultIn ? "in" : "out";
}

function epUpdateEventCheckinState(eventId, status) {
  const updateList = (list) => {
    const target = list.find((event) => Number(event.id) === Number(eventId));
    if (target) {
      target.my_checkin = status;
    }
  };
  updateList(epState.events);
  updateList(epState.allEvents);
  const popout = epEl("epCalendarPopout");
  const dateKey = popout ? popout.dataset.dateKey : "";
  epRenderCalendar();
  epRenderHeroCallout();
  const heroPopout = epEl("epHeroEventPopout");
  if (heroPopout && heroPopout.classList.contains("active")
      && heroPopout.dataset.eventId === String(Number(eventId))) {
    epOpenCalloutEventPopout(true);
  }
  if (dateKey) {
    const dayEl = document.querySelector(`.ep-calendar-day[data-date-key="${dateKey}"]`);
    if (dayEl) {
      epShowCalendarEvents(dateKey, dayEl);
    }
  }
}

async function epLoadEventAvatars(eventId, details, dataOverride) {
  const data = dataOverride || await epGet(`/ep_event_checkins.php?event_id=${eventId}`);
  if (data.status !== "OK") return;
  const avatars = details.querySelector('[data-role="avatars"]');
  const inlineBtn = details.querySelector('[data-role="checkin-inline"]');
  const groupNamesEl = details.querySelector('[data-role="group-names"]');
  const groupMetaEl = details.querySelector('[data-role="group-meta"]');
  if (!avatars) return;

  avatars.innerHTML = "";
  const members = data.members || [];
  const checkins = data.checkins || [];
  const groupNames = data.event && Number(data.event.all_members) === 1
    ? ["All members"]
    : (data.groups || [])
      .map((g) => (g.name || "").trim())
      .filter((name) => name);
  const checkinMap = {};
  checkins.forEach((c) => {
    checkinMap[Number(c.member_id)] = c.status;
  });
  const checkedInMembers = members.filter((m) => {
    const status = Object.prototype.hasOwnProperty.call(checkinMap, Number(m.member_id))
      ? checkinMap[Number(m.member_id)]
      : "in";
    return status === "in";
  });
  const roleInfo = epBuildRoleSummary(checkedInMembers, data.event?.created_by_member_id);
  if (groupNamesEl) {
    const groupsLabel = groupNames.join(", ");
    if (roleInfo.hasRoles) {
      if (groupsLabel) {
        groupNamesEl.textContent = groupsLabel;
        if (groupMetaEl) {
          groupMetaEl.textContent = roleInfo.summary;
        }
      } else {
        groupNamesEl.textContent = roleInfo.summary;
        if (groupMetaEl) groupMetaEl.textContent = "";
      }
    } else if (groupsLabel) {
      groupNamesEl.textContent = groupsLabel;
      if (groupMetaEl) groupMetaEl.textContent = "";
    } else {
      groupNamesEl.textContent = `${roleInfo.totalMembers}`;
      if (groupMetaEl) groupMetaEl.textContent = "";
    }
  }

  const myStatus = epGetMyStatusFromEvent(data);
  const isMemberInScope = Number(data.is_member || 0) === 1
    || members.some((m) => Number(m.member_id) === epState.memberId)
    || checkins.some((c) => Number(c.member_id) === epState.memberId);
  if (isMemberInScope && data.current_user && !members.some((m) => Number(m.member_id) === epState.memberId)) {
    members.push(data.current_user);
  }
  const isIn = myStatus === "in";
  details.dataset.nextStatus = isIn ? "out" : "in";
  if (inlineBtn) {
    inlineBtn.disabled = false;
    inlineBtn.textContent = isIn ? "Check out" : "Check in";
    inlineBtn.classList.toggle("ep-inline-checkin-in", isIn);
    inlineBtn.onclick = async (eventObj) => {
      eventObj.stopPropagation();
      if (!details.dataset.nextStatus) return;
      const res = await epPost("/ep_checkins.php", {
        event_id: eventId,
        status: details.dataset.nextStatus
      });
      if (res.status === "OK") {
        epUpdateEventCheckinState(eventId, details.dataset.nextStatus);
        epLoadEventDetail(eventId, details);
      }
    };
  }
  if (!members.length) {
    avatars.innerHTML = "<span class='ep-panel-sub'>No members</span>";
    return;
  }
  const checkedIn = members.filter((m) => {
    const status = Object.prototype.hasOwnProperty.call(checkinMap, Number(m.member_id))
      ? checkinMap[Number(m.member_id)]
      : "in";
    return status === "in";
  });
  const me = checkedIn.find((m) => Number(m.member_id) === epState.memberId);
  const others = checkedIn.filter((m) => Number(m.member_id) !== epState.memberId);
  const ordered = (me ? [me, ...others] : others);
  const preview = ordered.slice(0, 8);
  const extraCount = Math.max(0, ordered.length - preview.length);
  preview.forEach((m) => {
    const avatar = document.createElement("div");
    const isSelf = Number(m.member_id) === epState.memberId && myStatus === "in";
    avatar.className = `ep-avatar${isSelf ? " ep-avatar-self" : ""}`;
    const displayName = m.display_name || m.username || m.email || "Member";
    const avatarUrl = epResolveAvatarUrl(m, displayName);
    avatar.innerHTML = `<img src="${avatarUrl}" alt="${displayName}"
      data-avatar-seed="${m.member_id || m.email || m.username || displayName}"
      data-avatar-name="${displayName}"
      onerror="epHandleAvatarError(this)">`;
    const avatarImg = avatar.querySelector("img");
    if (avatarImg) {
      epBindMemberAvatarDirectChat(avatarImg, m, { stopSummaryToggle: true });
    }
    avatars.appendChild(avatar);
  });
  if (extraCount > 0) {
    const extra = document.createElement("div");
    extra.className = "ep-avatar ep-avatar-count";
    extra.textContent = `+${extraCount}`;
    avatars.appendChild(extra);
  }
}

async function epLoadEventDetail(eventId, details, eventData) {
  const container = details.querySelector('[data-role="expanded"]');
  if (!container) return;

  const data = await epGet(`/ep_event_checkins.php?event_id=${eventId}`);
  if (data.status !== "OK") {
    container.innerHTML = "<div class='ep-panel-sub'>Unable to load event.</div>";
    return;
  }

  const linkedIds = new Set((data.groups || []).map((g) => g.id));
  const members = data.members || [];
  const checkins = data.checkins || [];
  const isOwnerClient = epState.canManage || Number(data.event?.created_by_member_id) === epState.memberId;
  const canEdit = isOwnerClient;
  const myStatus = epGetMyStatusFromEvent(data);
  const isIn = myStatus === "in";

  details.dataset.nextStatus = isIn ? "out" : "in";

  const allSelected = data.event && Number(data.event.all_members) === 1;
  const groupPicker = epState.groups.map((g) => {
    const isAllMembersGroup = Number(g.is_all_members || 0) === 1;
    const checked = linkedIds.has(g.id) || (allSelected && isAllMembersGroup) ? "checked" : "";
    return `<label><input type="checkbox" value="${g.id}" ${checked}>${g.name}</label>`;
  }).join("");
  const groupChips = (data.event && Number(data.event.all_members) === 1)
    ? `<span class="ep-chip">All members</span>`
    : (data.groups || []).map((g) => `<span class="ep-chip">${g.name}</span>`).join("");

  const checkinMap = {};
  checkins.forEach((c) => {
    checkinMap[Number(c.member_id)] = c.status;
  });
  const checkedInMembers = members.filter((m) => {
    const status = Object.prototype.hasOwnProperty.call(checkinMap, Number(m.member_id))
      ? checkinMap[Number(m.member_id)]
      : "in";
    return status === "in";
  });
  const roleInfo = epBuildRoleSummary(checkedInMembers, data.event?.created_by_member_id);
  const memberRows = members.map((m) => {
    const id = Number(m.member_id);
    const status = Object.prototype.hasOwnProperty.call(checkinMap, id)
      ? checkinMap[id]
      : "in";
    const label = status === "out" ? "Checked out ✖" : "Checked in";
    const displayName = m.display_name || m.username || m.email || "Member";
    const avatarUrl = epResolveAvatarUrl(m, displayName);
    const displayRole = m.role
      || (id === Number(data.event?.created_by_member_id) ? "Owner" : "Undefined");
    const roleLabel = displayRole ? `• ${displayRole}` : "•";
    const toggleLabel = status === "out" ? "Check in" : "Check out";
    const actionClass = status === "out" ? "" : " ep-inline-checkin-in";
    return `
    <div class="ep-member">
      <img src="${avatarUrl}" alt="${displayName}"
           class="ep-member-chat-avatar"
           data-member-id="${id}"
           data-avatar-seed="${m.member_id || m.email || m.username || displayName}"
           data-avatar-name="${displayName}"
           onerror="epHandleAvatarError(this)">
      <span>${displayName} ${roleLabel}</span>
      ${isOwnerClient
        ? `<button class="ep-btn warm ep-inline-checkin${actionClass} ep-member-toggle" type="button" data-member-id="${id}" data-next-status="${status === "out" ? "in" : "out"}">${toggleLabel}</button>`
        : `<span class="ep-status ${status}">${label}</span>`
      }
    </div>
  `;
  }).join("");

  if (!isOwnerClient) {
    container.innerHTML = `
      <div>
        <div class="ep-panel-sub">Groups linked to this event</div>
        <div class="ep-chip-row">${groupChips || "<div class='ep-panel-sub'>No groups yet.</div>"}</div>
      </div>
      <div>
        <div class="ep-panel-sub">Members in scope</div>
        <div class="ep-member-list">${memberRows || "<div class='ep-member-list empty'>No members.</div>"}</div>
      </div>
    `;
    const memberLookup = new Map(members.map((m) => [Number(m.member_id || 0), m]));
    container.querySelectorAll(".ep-member-chat-avatar").forEach((avatarImg) => {
      const memberId = Number(avatarImg.dataset.memberId || 0);
      const member = memberLookup.get(memberId);
      if (!member) return;
      epBindMemberAvatarDirectChat(avatarImg, member, { stopSummaryToggle: false });
    });
    epLoadEventAvatars(eventId, details, data);
    return;
  }

  const eventMemberIds = new Set(members.map((m) => Number(m.member_id)));
  const availableInvites = epState.invitedMembers.filter((m) => !eventMemberIds.has(Number(m.member_id)));
  const inviteCards = availableInvites.map((member) => {
    const displayName = member.display_name || member.username || member.email || "Member";
    const avatarUrl = epResolveAvatarUrl(member, displayName);
    const roleLine = member.role ? `<div class="ep-panel-sub">Role: ${epEscape(member.role)}</div>` : "";
    return `
      <div class="ep-invite-card">
        <img src="${avatarUrl}" alt="${epEscape(displayName)}"
             data-avatar-seed="${epEscape(member.member_id || member.email || member.username || displayName)}"
             data-avatar-name="${epEscape(displayName)}"
             onerror="epHandleAvatarError(this)">
        <div>
          <div>${epEscape(displayName)}</div>
          <div class="ep-panel-sub">${epEscape(member.email || "")}</div>
          ${roleLine}
        </div>
        <button class="ep-btn ghost" type="button" data-role="add-invite" data-member-id="${member.member_id}">Add</button>
      </div>
    `;
  }).join("");

  const ownerLabel = data.event?.owner_display_name || "";
  const creatorLabel = data.event?.creator_display_name || "";
  const createdLabel = data.event?.created_at ? epFormatDatePlain(data.event.created_at) : "";
  const seriesId = String(data.event?.recurring_series_id || eventData?.recurring_series_id || "").trim();
  const seriesLabel = epSeriesLabel(seriesId);
  const metaParts = [];
  if (ownerLabel) metaParts.push(`Owner: ${ownerLabel}`);
  if (creatorLabel) metaParts.push(`Created by: ${creatorLabel}`);
  if (createdLabel) metaParts.push(`Created: ${createdLabel}`);
  if (seriesLabel) metaParts.push(seriesLabel);
  const metaText = metaParts.join(" • ");

  container.innerHTML = `
    <div class="ep-hidden"></div>
    <div>
      ${metaText ? `<div class="ep-event-meta-small">${epEscape(metaText)}</div>` : ""}
      ${canEdit ? `
      <div class="ep-detail-actions">
        <button class="ep-btn ghost" type="button" data-role="edit-top">Edit event</button>
        <button class="ep-btn ghost" type="button" data-role="delete">Delete event</button>
      </div>
      ` : ""}
      <div class="ep-panel-sub">Members in scope</div>
      <div class="ep-member-list">${memberRows || "<div class='ep-member-list empty'>No members.</div>"}</div>
      <div class="ep-panel-sub ep-role-summary">${roleInfo.summary}</div>
    </div>
    ${canEdit ? `
    <form class="ep-inline-edit ep-hidden" data-role="edit-form">
      <div class="ep-panel-sub">Edit event details</div>
      <input type="text" name="title" value="${data.event.title || ""}" required>
      <div class="ep-category-picker" data-role="category-picker">
        <input type="hidden" name="category" value="${epEscape(data.event.category || "")}">
        <button class="ep-category-trigger" type="button" data-role="category-trigger">
          <span class="ep-category-dot" aria-hidden="true"></span>
          <span>Category</span>
        </button>
        <div class="ep-category-dropdown" data-role="category-dropdown"></div>
      </div>
      <input type="text" name="location" value="${data.event.location || ""}">
      <label class="ep-field">
        <span>Starts</span>
        <input type="datetime-local" name="starts_at" value="${epToInputDate(data.event.starts_at)}" step="60" required>
      </label>
      <label class="ep-field">
        <span>Ends</span>
        <input type="datetime-local" name="ends_at" value="${epToInputDate(data.event.ends_at || "")}" step="60">
      </label>
      <textarea name="notes" rows="3" placeholder="Notes (optional): key points, callouts, reminders">${epEscape(data.event.notes || "")}</textarea>
      <input type="hidden" name="image_url" value="${epEscape(data.event.image_url || "")}">
      <div class="ep-image-uploader" data-role="event-image-uploader">
        <input class="ep-image-file-input" type="file" name="image_file" accept="image/*">
        <button class="ep-btn ghost ep-image-file-trigger" type="button" data-role="image-select">Select image</button>
        <div class="ep-image-dropzone" data-role="image-drop">Paste, drop, or select image</div>
        <div class="ep-image-preview ep-hidden" data-role="image-preview">
          <img src="" alt="Event image preview">
        </div>
      </div>
      <div class="ep-panel-sub">Groups for this event</div>
      <div class="ep-group-picker" data-role="group-picker">
        ${groupPicker || "<div class='ep-panel-sub'>No groups yet.</div>"}
      </div>
      ${seriesId ? `
      <label class="ep-field ep-recurring-check">
        <input type="checkbox" data-role="apply-series">
        <span>Apply changes to entire ${epEscape(seriesLabel)} (except notes)</span>
      </label>
      ` : ""}
      <div class="ep-inline-edit-actions">
        <button class="ep-btn" type="submit">Save changes</button>
        <button class="ep-btn ghost" type="button" data-role="cancel-edit">Cancel</button>
      </div>
    </form>
    ` : ""}
    ${canEdit ? `
    <div class="ep-event-add-member">
      <div class="ep-panel-sub">Add member to this event</div>
      <div class="ep-invite-list">
        ${inviteCards || "<div class=\"ep-panel-sub\">No invited members available.</div>"}
      </div>
    </div>
    ` : ""}
  `;
  const memberLookup = new Map(members.map((m) => [Number(m.member_id || 0), m]));
  container.querySelectorAll(".ep-member-chat-avatar").forEach((avatarImg) => {
    const memberId = Number(avatarImg.dataset.memberId || 0);
    const member = memberLookup.get(memberId);
    if (!member) return;
    epBindMemberAvatarDirectChat(avatarImg, member, { stopSummaryToggle: false });
  });

  const editForm = container.querySelector('[data-role="edit-form"]');
  if (editForm) {
    epBindCategoryPicker(editForm);
    epBindEventImageUploader(editForm, () => data.event || eventData || null);
  }


  const checkinBtn = container.querySelector('[data-role="checkin"]');
  if (checkinBtn) {
    checkinBtn.remove();
  }

  const memberToggles = container.querySelectorAll(".ep-member-toggle");
  memberToggles.forEach((btn) => {
    btn.addEventListener("click", async () => {
      const memberId = Number(btn.dataset.memberId || 0);
      const nextStatus = btn.dataset.nextStatus;
      if (!memberId || !nextStatus) return;
      const res = await epPost("/ep_checkins.php", {
        event_id: eventId,
        member_id: memberId,
        status: nextStatus
      });
      if (res.status === "OK") {
        await epLoadEventDetail(eventId, details, eventData);
      }
    });
  });

  const inviteButtons = container.querySelectorAll('[data-role="add-invite"]');
  inviteButtons.forEach((btn) => {
    btn.addEventListener("click", async () => {
      const memberId = Number(btn.dataset.memberId || 0);
      if (!memberId) return;
      const res = await epPost("/ep_checkins.php", {
        event_id: eventId,
        member_id: memberId,
        status: "in"
      });
      if (res.status === "OK") {
        await epLoadEventDetail(eventId, details, eventData);
      } else {
        alert(res.message || "Unable to add member.");
      }
    });
  });

  if (canEdit) {
    const openEditForm = () => {
      const form = container.querySelector('[data-role="edit-form"]');
      if (!form) return;
      form.classList.remove("ep-hidden");
      requestAnimationFrame(() => {
        form.scrollIntoView({ behavior: "smooth", block: "start", inline: "nearest" });
        const titleInput = form.querySelector('input[name="title"]');
        if (titleInput) titleInput.focus({ preventScroll: true });
      });
    };
    container.querySelectorAll('[data-role="edit"], [data-role="edit-top"]').forEach((editBtn) => {
      editBtn.addEventListener("click", openEditForm);
    });

    const deleteBtn = container.querySelector('[data-role="delete"]');
    if (deleteBtn) {
      deleteBtn.addEventListener("click", async () => {
        if (!confirm("Delete this event?")) return;
        const res = await epPost("/ep_events.php", {
          action: "delete",
          event_id: eventId
        });
        if (res.status === "OK") {
          epLoadEvents();
        } else {
          alert(res.message || "Unable to delete event.");
        }
      });
    }
  }

  if (canEdit) {
    const cancelEdit = container.querySelector('[data-role="cancel-edit"]');
    if (cancelEdit && editForm) {
      cancelEdit.addEventListener("click", () => {
        editForm.classList.add("ep-hidden");
      });
    }
    if (editForm) {
      editForm.addEventListener("submit", async (eventObj) => {
        eventObj.preventDefault();
        if (editForm.dataset.imageUploading === "1") {
          alert("Image is still uploading. Please wait.");
          return;
        }
        const form = eventObj.currentTarget;
        const payload = {
          action: "update",
          event_id: eventId,
          title: form.title.value.trim(),
          category: (form.category ? form.category.value.trim() : ""),
          location: form.location.value.trim(),
          starts_at: epGetDateValue(form.starts_at),
          ends_at: epGetDateValue(form.ends_at) || null,
          notes: form.notes.value.trim(),
          image_url: form.image_url ? form.image_url.value.trim() : ""
        };
        if (!payload.title || !payload.starts_at) {
          alert("Title and start date are required.");
          return;
        }
        const picker = form.querySelector('[data-role="group-picker"]');
        const groupInputs = picker ? picker.querySelectorAll("input:checked") : [];
        const selectedIds = Array.from(groupInputs)
          .map((input) => Number(input.value))
          .filter((value) => Number.isFinite(value));
        const allMembersGroupIds = epState.groups
          .filter((group) => Number(group.is_all_members || 0) === 1)
          .map((group) => Number(group.id));
        const allMembersSelected = selectedIds.some((id) => allMembersGroupIds.includes(id));
        const groupIds = selectedIds.filter((id) => !allMembersGroupIds.includes(id));
        const applySeriesCheckbox = form.querySelector('[data-role="apply-series"]');
        const applySeries = !!(applySeriesCheckbox && applySeriesCheckbox.checked && seriesId);
        if (applySeries) {
          payload.update_scope = "series";
          payload.group_ids = allMembersSelected ? [] : groupIds;
          payload.all_members = allMembersSelected ? 1 : 0;
        }
        const [res, groupRes] = applySeries
          ? [await epPost("/ep_events.php", payload), { status: "OK" }]
          : await Promise.all([
            epPost("/ep_events.php", payload),
            epPost("/ep_event_groups.php", {
              action: "set",
              event_id: eventId,
              group_ids: allMembersSelected ? [] : groupIds,
              all_members: allMembersSelected ? 1 : 0
            })
          ]);
        if (res.status === "OK" && groupRes.status === "OK") {
          if (payload.image_url) {
            epRememberImageSuggestion(payload.image_url);
          }
          editForm.classList.add("ep-hidden");
          if (applySeries) {
            epFlashMessage("Recurring series updated.");
          }
          epLoadEvents();
          epLoadEventDetail(eventId, details, eventData);
          const categoryValue = epNormalizeCategoryName(payload.category);
          if (categoryValue) {
            epCreateCategoryIfMissing(categoryValue);
          }
        } else {
          alert(res.message || groupRes.message || "Unable to update event.");
        }
      });
    }
  }

  epLoadEventAvatars(eventId, details, data);
}

async function epToggleCheckin(eventId, details) {
  if (!details.dataset.nextStatus) {
    await epLoadEventDetail(eventId, details);
  }
  if (!details.dataset.nextStatus) return;
  const res = await epPost("/ep_checkins.php", {
    event_id: eventId,
    status: details.dataset.nextStatus
  });
  if (res.status === "OK") {
    epUpdateEventCheckinState(eventId, details.dataset.nextStatus);
    epLoadEventDetail(eventId, details);
  }
}

async function epLoadGroups() {
  const data = await epGet("/ep_groups.php");
  if (data.status === "OK") {
    epState.groups = data.groups || [];
    epState.ownerProfileType = String(data.owner_profile_type || "person").trim().toLowerCase() || "person";
    epState.ownerGroupType = String(data.owner_group_type || "").trim().toLowerCase();
    if (typeof data.owner_locale !== "undefined") {
      epState.locale = String(data.owner_locale || epState.locale || "").trim().toLowerCase();
    }
    if (!epState.currentGroupId && epState.groups.length) {
      epState.currentGroupId = epState.groups[0].id;
    }
    await epLoadGroupMembersSummary();
    epRenderGroups();
    epRenderEventGroupPicker();
    if (epState.currentGroupId) {
      const current = epState.groups.find((g) => g.id === epState.currentGroupId);
      epSelectGroup(epState.currentGroupId, current ? current.name : "Members");
    }
    if (!epState.currentGroupId) {
      const refreshBtn = epEl("epRefreshGroupMembersBtn");
      const editBtn = epEl("epEditGroupBtn");
      const deleteBtn = epEl("epDeleteGroupBtn");
      if (refreshBtn) refreshBtn.disabled = true;
      if (editBtn) editBtn.disabled = true;
      if (deleteBtn) deleteBtn.disabled = true;
    }
    epUpdateGroupFilter();
  }
}

function epRenderEventGroupPicker() {
  const picker = epEl("epEventGroupPicker");
  if (!picker) return;
  picker.innerHTML = "";
  if (!epState.groups.length) {
    picker.innerHTML = "<div class='ep-panel-sub'>Create groups first.</div>";
    return;
  }
  epState.groups.forEach((group) => {
    const label = document.createElement("label");
    const groupColor = group.color || "#9fb7f0";
    const isAllMembers = Number(group.is_all_members || 0) === 1;
    label.innerHTML = `
      <input type="checkbox" value="${group.id}" ${isAllMembers ? "checked" : ""}>
      <span class="ep-group-swatch" style="background:${groupColor}"></span>
      ${group.name}
    `;
    picker.appendChild(label);
  });
}

function epSetDefaultEventGroups() {
  const picker = epEl("epEventGroupPicker");
  if (!picker) return;
  const inputs = picker.querySelectorAll("input[type='checkbox']");
  const allMemberIds = epState.groups
    .filter((group) => Number(group.is_all_members || 0) === 1)
    .map((group) => String(group.id));
  inputs.forEach((input) => {
    if (allMemberIds.includes(input.value)) {
      input.checked = true;
    } else {
      input.checked = false;
    }
  });
}

async function epLoadGroupMembersSummary() {
  const summaries = {};
  const requests = epState.groups.map(async (group) => {
    const data = await epGet(`/ep_group_members.php?group_id=${group.id}`);
    summaries[group.id] = data.status === "OK" ? (data.members || []) : [];
  });
  await Promise.all(requests);
  epState.groupMembers = summaries;
}

async function epLoadInvitedMembers() {
  const list = epEl("epInviteList");
  try {
    const data = await epGet("/ep_invited_members.php");
    if (data.status === "OK") {
      epState.invitedMembers = data.members || [];
      epRenderInviteList();
      return;
    }
    if (list) list.innerHTML = "<div class='ep-panel-sub'>Unable to load invited members.</div>";
  } catch (err) {
    if (list) list.innerHTML = "<div class='ep-panel-sub'>Error loading invited members.</div>";
  }
}

function epUpdateAttendanceModeUI() {
  const mode = String(epState.attendanceFilters.mode || "year").toLowerCase();
  const isPeriod = mode === "period";
  const yearWrap = epEl("epAttendanceYearWrap");
  const fromWrap = epEl("epAttendanceFromWrap");
  const toWrap = epEl("epAttendanceToWrap");
  if (yearWrap) yearWrap.classList.toggle("ep-hidden", isPeriod);
  if (fromWrap) fromWrap.classList.toggle("ep-hidden", !isPeriod);
  if (toWrap) toWrap.classList.toggle("ep-hidden", !isPeriod);
}

function epGetIsoWeek(date) {
  const temp = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()));
  const day = temp.getUTCDay() || 7;
  temp.setUTCDate(temp.getUTCDate() + 4 - day);
  const yearStart = new Date(Date.UTC(temp.getUTCFullYear(), 0, 1));
  return Math.ceil((((temp - yearStart) / 86400000) + 1) / 7);
}

function epDayLetter(date) {
  const letters = ["S", "M", "T", "W", "T", "F", "S"];
  return letters[date.getDay()] || "?";
}

function epRoleSortKey(roleName) {
  const valueRaw = String(roleName || "").trim();
  if (!valueRaw) return 9999;
  const value = epNormalize(valueRaw);
  const collapsed = value.replace(/[^a-z0-9]/g, "");

  // Parse part number in either direction: "1B", "B1", "1. Bassi", "Bass2".
  const numFirst = collapsed.match(/^([12])(s|a|t|b)/);
  const letterFirst = collapsed.match(/(s|a|t|b)([12])$/);
  const explicitPair = collapsed.match(/([12])(s|a|t|b)|(s|a|t|b)([12])/);
  const part = numFirst
    ? Number(numFirst[1])
    : (letterFirst ? Number(letterFirst[2]) : (explicitPair ? Number(explicitPair[1] || explicitPair[4]) : 1));

  // Voice detection:
  // 1) explicit pair like 1B / B2
  // 2) first SATB letter occurrence in the role token
  let voice = explicitPair ? (explicitPair[2] || explicitPair[3] || "") : "";
  if (!voice) {
    const hits = ["s", "a", "t", "b"]
      .map((v) => ({ v, idx: collapsed.indexOf(v) }))
      .filter((entry) => entry.idx >= 0)
      .sort((a, b) => a.idx - b.idx);
    voice = hits.length ? hits[0].v : "";
  }

  if (voice === "s") return 10 + part;
  if (voice === "a") return 20 + part;
  if (voice === "t") return 30 + part;
  if (voice === "b") return 40 + part;
  return 9999;
}

function epRenderAttendanceReport(data) {
  const summaryEl = epEl("epAttendanceSummary");
  const listEl = epEl("epAttendanceList");
  if (!summaryEl || !listEl) return;

  const attendants = Array.isArray(data?.attendants) ? data.attendants : [];
  const membersRaw = Array.isArray(data?.members) ? data.members : attendants;
  const members = membersRaw.filter((member) => Number(member.member_id || 0) !== Number(epState.ownerId || 0));
  const events = Array.isArray(data?.events) ? data.events : [];
  const checkins = Array.isArray(data?.checkins) ? data.checkins : [];
  const inScope = Array.isArray(data?.in_scope) ? data.in_scope : [];
  const totalEvents = Number(data?.total_events || 0);
  const uniqueAttendants = members.length;
  const startLabel = data?.period_start ? epFormatDatePlain(data.period_start) : "";
  const endLabel = data?.period_end ? epFormatDatePlain(data.period_end) : "";
  summaryEl.textContent = `${uniqueAttendants} attendant${uniqueAttendants === 1 ? "" : "s"} • ${totalEvents} event${totalEvents === 1 ? "" : "s"}${startLabel && endLabel ? ` • ${startLabel} → ${endLabel}` : ""}`;

  if (!members.length || !events.length) {
    listEl.innerHTML = "<div class='ep-panel-sub'>No members/events in this period.</div>";
    return;
  }

  const explicitStatusMap = {};
  checkins.forEach((entry) => {
    const eventId = Number(entry.event_id || 0);
    const memberId = Number(entry.member_id || 0);
    if (!eventId || !memberId) return;
    const status = String(entry.status || "in").toLowerCase() === "out" ? "out" : "in";
    explicitStatusMap[`${memberId}:${eventId}`] = status;
  });
  const inScopeMap = {};
  inScope.forEach((entry) => {
    const eventId = Number(entry.event_id || 0);
    const memberId = Number(entry.member_id || 0);
    if (!eventId || !memberId) return;
    inScopeMap[`${memberId}:${eventId}`] = true;
  });

  const eventFutureMap = {};
  events.forEach((event) => {
    const eventId = Number(event.id || 0);
    const eventDate = epParseEventDate(event.starts_at);
    eventFutureMap[eventId] = !!eventDate && eventDate.getTime() > Date.now();
  });

  const headCols = events.map((event) => {
    const eventDate = epParseEventDate(event.starts_at);
    if (!eventDate) {
      return `<th class="ep-att-col" title="${epEscape(event.title || "Event")}">?</th>`;
    }
    const eventId = Number(event.id || 0);
    const isFuture = !!eventFutureMap[eventId];
    const weekNo = epGetIsoWeek(eventDate);
    const day = epDayLetter(eventDate);
    const mm = String(eventDate.getMonth() + 1).padStart(2, "0");
    const dd = String(eventDate.getDate()).padStart(2, "0");
    return `
      <th class="ep-att-col${isFuture ? " is-future" : ""}" title="${epEscape(`${event.title || "Event"} • ${epFormatDatePlain(event.starts_at || "")}`)}">
        <div class="ep-att-day">${day}</div>
        <div class="ep-att-week">W${String(weekNo).padStart(2, "0")}</div>
        <div class="ep-att-date">${mm}/${dd}</div>
      </th>
    `;
  }).join("");
  const totalHeadCol = `<th class="ep-att-col ep-att-total-col">Total</th>`;

  const roleBuckets = {};
  members.forEach((member) => {
    const displayName = epMemberDisplayName(member);
    const avatarUrl = epResolveAvatarUrl(member, displayName);
    const role = String(member.role || "").trim();
    const roleLabel = role ? ` • ${epEscape(role)}` : "";
    const roleName = role || "Unassigned";
    const memberId = Number(member.member_id || 0);
    let count = 0;
    if (!roleBuckets[roleName]) {
      roleBuckets[roleName] = {
        members: 0,
        total: 0,
        byEvent: {}
      };
    }
    roleBuckets[roleName].members += 1;
    const cells = events.map((event) => {
      const eventId = Number(event.id || 0);
      const isFuture = !!eventFutureMap[eventId];
      const key = `${memberId}:${eventId}`;
      const hasExplicit = Object.prototype.hasOwnProperty.call(explicitStatusMap, key);
      const explicitStatus = hasExplicit ? explicitStatusMap[key] : "";
      const isIn = hasExplicit
        ? explicitStatus === "in"
        : !!inScopeMap[key];
      if (isIn) {
        count += 1;
        roleBuckets[roleName].total += 1;
        roleBuckets[roleName].byEvent[eventId] = (roleBuckets[roleName].byEvent[eventId] || 0) + 1;
      }
      const isOut = explicitStatus === "out";
      const marker = isIn ? "✓" : (isOut ? "✕" : "");
      const clickable = marker ? " is-clickable" : "";
      const attr = marker ? ` data-event-id="${eventId}"` : "";
      return `<td class="ep-att-cell${isIn ? " is-in" : ""}${isOut ? " is-out" : ""}${isFuture ? " is-future" : ""}${clickable}"${attr}>${marker}</td>`;
    }).join("");
    roleBuckets[roleName].memberRows = roleBuckets[roleName].memberRows || [];
    roleBuckets[roleName].memberRows.push({
      displayName,
      avatarUrl,
      roleLabel,
      count,
      cells,
      member
    });
  });

  const sortedRoles = Object.keys(roleBuckets)
    .sort((a, b) => {
      const wa = epRoleSortKey(a);
      const wb = epRoleSortKey(b);
      if (wa !== wb) return wa - wb;
      return a.localeCompare(b);
    });

  const roleSections = sortedRoles
    .map((roleName) => {
      const bucket = roleBuckets[roleName];
      const roleKeyRaw = epNormalize(roleName).replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "");
      const roleKey = roleKeyRaw || "unassigned";
      const isCollapsed = Object.prototype.hasOwnProperty.call(epState.attendanceCollapsedRoles, roleKey)
        ? !!epState.attendanceCollapsedRoles[roleKey]
        : true;
      const subtotalCells = events.map((event) => {
        const eventId = Number(event.id || 0);
        const value = Number(bucket.byEvent[eventId] || 0);
        const isFuture = !!eventFutureMap[eventId];
        return `<td class="ep-att-cell ep-att-subtotal-cell${isFuture ? " is-future" : ""}">${value > 0 ? value : ""}</td>`;
      }).join("");
      const memberRows = (bucket.memberRows || []).map((row) => `
        <tr class="ep-att-member-row${isCollapsed ? " ep-hidden" : ""}" data-role-key="${epEscape(roleKey)}">
          <th class="ep-att-member" scope="row">
            <span class="ep-att-member-content">
              <img src="${row.avatarUrl}" alt="${epEscape(row.displayName)}"
                   data-avatar-seed="${row.member.member_id || row.member.username || row.displayName}"
                   data-avatar-name="${epEscape(row.displayName)}"
                   onerror="epHandleAvatarError(this)">
              <span class="ep-att-member-name">${epEscape(row.displayName)}${row.roleLabel}</span>
            </span>
          </th>
          <td class="ep-att-cell ep-att-total-cell">${row.count}</td>
          ${row.cells}
        </tr>
      `).join("");
      return `
        <tbody class="ep-att-role-group" data-role-key="${epEscape(roleKey)}">
          <tr class="ep-att-role-row${isCollapsed ? " is-collapsed" : ""}" data-role-key="${epEscape(roleKey)}">
            <th class="ep-att-member ep-att-role-label" scope="row">
              <button class="ep-att-role-toggle" type="button" data-role-key="${epEscape(roleKey)}" aria-expanded="${isCollapsed ? "false" : "true"}">
                <span class="ep-att-role-caret">${isCollapsed ? "▶" : "▼"}</span>
                <span>${epEscape(roleName)}</span>
              </button>
            </th>
            <td class="ep-att-cell ep-att-subtotal-cell ep-att-total-cell">${bucket.total}</td>
            ${subtotalCells}
          </tr>
          ${memberRows}
        </tbody>
      `;
    }).join("");

  const grandTotals = { total: 0, byEvent: {} };
  sortedRoles.forEach((roleName) => {
    const bucket = roleBuckets[roleName];
    if (!bucket) return;
    grandTotals.total += Number(bucket.total || 0);
    events.forEach((event) => {
      const eventId = Number(event.id || 0);
      grandTotals.byEvent[eventId] = (grandTotals.byEvent[eventId] || 0) + Number(bucket.byEvent[eventId] || 0);
    });
  });
  const grandTotalCells = events.map((event) => {
    const eventId = Number(event.id || 0);
    const value = Number(grandTotals.byEvent[eventId] || 0);
    const isFuture = !!eventFutureMap[eventId];
    return `<td class="ep-att-cell ep-att-subtotal-cell ep-att-grand-cell${isFuture ? " is-future" : ""}">${value > 0 ? value : ""}</td>`;
  }).join("");
  const grandTotalSection = `
    <tfoot>
      <tr class="ep-att-subtotal-row ep-att-grand-row">
        <th class="ep-att-member ep-att-subtotal-label ep-att-grand-label" scope="row">
          <span>Grand Total</span>
        </th>
        <td class="ep-att-cell ep-att-subtotal-cell ep-att-grand-cell ep-att-total-cell">${grandTotals.total}</td>
        ${grandTotalCells}
      </tr>
    </tfoot>
  `;

  listEl.innerHTML = `
    <div class="ep-attendance-table-wrap">
      <table class="ep-attendance-table">
        <thead>
          <tr>
            <th class="ep-att-member ep-att-member-head">Members / Events</th>
            ${totalHeadCol}
            ${headCols}
          </tr>
        </thead>
        ${roleSections}
        ${grandTotalSection}
      </table>
    </div>
  `;
  listEl.querySelectorAll(".ep-att-role-toggle[data-role-key]").forEach((toggle) => {
    toggle.addEventListener("click", () => {
      const roleKey = toggle.dataset.roleKey || "";
      if (!roleKey) return;
      const escapedRoleKey = typeof CSS !== "undefined" && CSS.escape
        ? CSS.escape(roleKey)
        : roleKey.replace(/"/g, '\\"');
      const group = listEl.querySelector(`.ep-att-role-group[data-role-key="${escapedRoleKey}"]`);
      if (!group) return;
      const memberRows = group.querySelectorAll(`.ep-att-member-row[data-role-key="${escapedRoleKey}"]`);
      const roleRow = group.querySelector(`.ep-att-role-row[data-role-key="${escapedRoleKey}"]`);
      const nextCollapsed = !memberRows[0] || !memberRows[0].classList.contains("ep-hidden");
      memberRows.forEach((row) => row.classList.toggle("ep-hidden", nextCollapsed));
      if (roleRow) roleRow.classList.toggle("is-collapsed", nextCollapsed);
      const caret = toggle.querySelector(".ep-att-role-caret");
      if (caret) caret.textContent = nextCollapsed ? "▶" : "▼";
      toggle.setAttribute("aria-expanded", nextCollapsed ? "false" : "true");
      epState.attendanceCollapsedRoles[roleKey] = nextCollapsed;
    });
  });
  listEl.querySelectorAll(".ep-att-cell.is-clickable[data-event-id]").forEach((cell) => {
    cell.addEventListener("click", () => {
      epOpenEventFromReport(cell.dataset.eventId, cell);
    });
  });
}

async function epLoadAttendanceReport() {
  const summaryEl = epEl("epAttendanceSummary");
  const listEl = epEl("epAttendanceList");
  if (summaryEl) summaryEl.textContent = "Loading attendants...";
  if (listEl) listEl.innerHTML = "";

  const mode = String(epState.attendanceFilters.mode || "year").toLowerCase();
  const params = new URLSearchParams();
  if (mode === "period" && epState.attendanceFilters.from && epState.attendanceFilters.to) {
    params.set("from", epState.attendanceFilters.from);
    params.set("to", epState.attendanceFilters.to);
  } else {
    const year = String(epState.attendanceFilters.year || "").trim();
    if (year) params.set("year", year);
  }
  if (epState.attendanceFilters.groupId) {
    params.set("group_id", epState.attendanceFilters.groupId);
  }
  if (epState.attendanceFilters.category) {
    params.set("category", epState.attendanceFilters.category);
  }

  const query = params.toString();
  const data = await epGet(`/ep_attendance_report.php${query ? `?${query}` : ""}`);
  if (data.status !== "OK") {
    if (summaryEl) summaryEl.textContent = "Unable to load attendants.";
    if (listEl) listEl.innerHTML = "<div class='ep-panel-sub'>Try another period.</div>";
    return;
  }
  epState.attendanceReport = data;
  epRenderAttendanceReport(data);
}

function epBindAttendanceControls() {
  const modeEl = epEl("epAttendanceMode");
  const yearEl = epEl("epAttendanceYear");
  const fromEl = epEl("epAttendanceFrom");
  const toEl = epEl("epAttendanceTo");
  const groupEl = epEl("epAttendanceGroup");
  const categoryEl = epEl("epAttendanceCategory");
  const loadBtn = epEl("epAttendanceLoad");

  if (!modeEl || !yearEl || !fromEl || !toEl || !groupEl || !categoryEl || !loadBtn) return;

  modeEl.value = epState.attendanceFilters.mode || "year";
  yearEl.value = epState.attendanceFilters.year || "";
  fromEl.value = epState.attendanceFilters.from || "";
  toEl.value = epState.attendanceFilters.to || "";
  groupEl.value = epState.attendanceFilters.groupId || "";
  categoryEl.value = epState.attendanceFilters.category || "";
  epUpdateAttendanceModeUI();

  modeEl.addEventListener("change", () => {
    epState.attendanceFilters.mode = modeEl.value || "year";
    epUpdateAttendanceModeUI();
  });
  yearEl.addEventListener("change", () => {
    epState.attendanceFilters.year = yearEl.value || "";
  });
  fromEl.addEventListener("change", () => {
    epState.attendanceFilters.from = fromEl.value || "";
  });
  toEl.addEventListener("change", () => {
    epState.attendanceFilters.to = toEl.value || "";
  });
  groupEl.addEventListener("change", () => {
    epState.attendanceFilters.groupId = groupEl.value || "";
    epLoadAttendanceReport();
  });
  categoryEl.addEventListener("change", () => {
    epState.attendanceFilters.category = categoryEl.value || "";
    epLoadAttendanceReport();
  });
  loadBtn.addEventListener("click", () => {
    epLoadAttendanceReport();
  });
}

async function epLoadEvents() {
  const params = new URLSearchParams();
  if (epState.eventFilters.myEvents) {
    params.set("my_events", "1");
  }
  if (epState.eventFilters.groupId) {
    params.set("group_id", epState.eventFilters.groupId);
  }
  if (epState.eventFilters.fromDate) {
    params.set("start", `${epState.eventFilters.fromDate} 00:00:00`);
  }
  if (epState.eventFilters.category) {
    params.set("category", epState.eventFilters.category);
  }
  const query = params.toString();
  const dedupeEvents = (events) => {
    const seen = new Set();
    return (events || []).filter((event) => {
      const id = Number(event.id);
      if (!id || seen.has(id)) return false;
      seen.add(id);
      return true;
    });
  };
  const [listData, calendarData] = await Promise.all([
    epGet(`/ep_events.php${query ? `?${query}` : ""}`),
    epGet("/ep_events.php")
  ]);
  const listEvents = listData.status === "OK" ? dedupeEvents(listData.events) : [];
  if (calendarData.status === "OK") {
    const allEventsCandidate = dedupeEvents(calendarData.events);
    if (allEventsCandidate.length || listEvents.length === 0) {
      epState.allEvents = allEventsCandidate;
    }
  }
  if (listData.status === "OK") {
    epState.events = listEvents;
    epRenderEvents();
  }
  epRenderHeroCallout();
  epRenderPollEventSelect();
  const categorySource = epState.allEvents.length ? epState.allEvents : epState.events;
  epUpdateCategoryList(categorySource);
  epRenderCalendar();
}

async function epInit() {
  const shell = document.querySelector(".ep-shell");
  epLoadMemberSortPreference();
  epState.memberId = Number((shell && shell.dataset.memberId) || 0);
  epState.ownerId = Number((shell && shell.dataset.ownerId) || epState.memberId);
  epState.ownerToken = (shell && shell.dataset.owner) || "";
  epState.locale = ((shell && shell.dataset.locale) || "").trim().toLowerCase();
  epState.canManage = (shell && shell.dataset.canManage) === "1";
  if (!epState.canManage && epState.memberId && epState.ownerId === epState.memberId) {
    epState.canManage = true;
  }
  epState.username = (shell && shell.dataset.username) || "";
  epBindHeroCalloutEditor();
  if (!epState.eventFilters.fromDate) {
    const today = new Date();
    const pad = (n) => String(n).padStart(2, "0");
    epState.eventFilters.fromDate = `${today.getFullYear()}-${pad(today.getMonth() + 1)}-${pad(today.getDate())}`;
  }
  epLoadCategoryColors();
  if (window.parent && window.parent !== window) {
    document.addEventListener("pointerdown", () => {
      window.parent.postMessage({ type: "tw-close-sidebar" }, "*");
    }, { passive: true });
  }

  await epLoadGroups();
  await epLoadInvitedMembers();
  await epLoadCategoryCatalog();
  await epLoadEvents();
  await epLoadImageSuggestions();
  epBindPolls();
  await epLoadPolls();
  epStartPollSync();
  epBindAttendanceControls();
  await epLoadAttendanceReport();
  epBindCalendarControls();
  epRenderEventGroupPicker();
  epSetDefaultEventGroups();
  await epInitChoirPicker();
  epRenderCategorySettings();
  epBindCategorySettings();

  const groupForm = epEl("epGroupForm");
  const toggleGroupFormBtn = epEl("epToggleGroupForm");
  const cancelGroupCreate = epEl("epCancelGroupCreate");
  const toggleGroupDefaultsBtn = epEl("epToggleGroupDefaultsBtn");
  const groupDefaultsPanel = epEl("epGroupDefaultsPanel");
  const closeGroupDefaultsBtn = epEl("epCloseGroupDefaultsBtn");
  const convertChoirRolesBtn = epEl("epConvertChoirRolesBtn");
  const choirRolesConfirmPanel = epEl("epChoirRolesConfirmPanel");
  const confirmChoirRolesBtn = epEl("epConfirmChoirRolesBtn");
  const cancelChoirRolesBtn = epEl("epCancelChoirRolesBtn");
  const choirRolesPreview = epEl("epChoirRolesPreview");
  const createChoirDefaultsBtn = epEl("epCreateChoirDefaultsBtn");
  const choirDefaultsConfirmPanel = epEl("epChoirDefaultsConfirmPanel");
  const confirmChoirDefaultsBtn = epEl("epConfirmChoirDefaultsBtn");
  const cancelChoirDefaultsBtn = epEl("epCancelChoirDefaultsBtn");
  const choirTypeSelect = epEl("epChoirTypeSelect");
  const choirTypePreview = epEl("epChoirTypePreview");
  const createCategoryDefaultsBtn = epEl("epCreateCategoryDefaultsBtn");
  const categoryDefaultsConfirmPanel = epEl("epCategoryDefaultsConfirmPanel");
  const confirmCategoryDefaultsBtn = epEl("epConfirmCategoryDefaultsBtn");
  const cancelCategoryDefaultsBtn = epEl("epCancelCategoryDefaultsBtn");
  const categoryDefaultsLangSelect = epEl("epCategoryDefaultsLangSelect");
  const categoryDefaultsPreview = epEl("epCategoryDefaultsPreview");
  const createListDefaultsBtn = epEl("epCreateListDefaultsBtn");
  const listDefaultsConfirmPanel = epEl("epListDefaultsConfirmPanel");
  const confirmListDefaultsBtn = epEl("epConfirmListDefaultsBtn");
  const cancelListDefaultsBtn = epEl("epCancelListDefaultsBtn");
  const listDefaultsPreview = epEl("epListDefaultsPreview");
  const ownerProfileTypeSelect = epEl("epOwnerProfileTypeSelect");
  const ownerGroupTypeSelect = epEl("epOwnerGroupTypeSelect");
  const ownerLanguageSelect = epEl("epOwnerLanguageSelect");
  const saveOwnerProfileBtn = epEl("epSaveOwnerProfileBtn");
  const createAllDefaultsBtn = epEl("epCreateAllDefaultsBtn");
  const allDefaultsConfirmPanel = epEl("epAllDefaultsConfirmPanel");
  const confirmAllDefaultsBtn = epEl("epConfirmAllDefaultsBtn");
  const cancelAllDefaultsBtn = epEl("epCancelAllDefaultsBtn");
  const allDefaultsPreview = epEl("epAllDefaultsPreview");
  if (!epState.canManage && groupForm) {
    groupForm.classList.add("ep-hidden");
  }
  if (!epState.canManage && toggleGroupFormBtn) {
    toggleGroupFormBtn.classList.add("ep-hidden");
  }
  if (!epState.canManage && toggleGroupDefaultsBtn) {
    toggleGroupDefaultsBtn.classList.add("ep-hidden");
  }
  if (!epState.canManage && groupDefaultsPanel) {
    groupDefaultsPanel.classList.add("ep-hidden");
  }
  const choirDefaultsByType = {
    mixed: ["Sopran", "Alt", "Tenór", "Bassi"],
    men: ["1.Tenór", "2.Tenór", "Baritón", "Bassi"],
    women: ["1.Sopran", "2.Sopran", "1.Alt", "2.Alt"]
  };
  let choirRolePreviewItems = [];
  const epHideDefaultsConfirmPanels = () => {
    if (allDefaultsConfirmPanel) allDefaultsConfirmPanel.classList.add("ep-hidden");
    if (choirRolesConfirmPanel) choirRolesConfirmPanel.classList.add("ep-hidden");
    if (choirDefaultsConfirmPanel) choirDefaultsConfirmPanel.classList.add("ep-hidden");
    if (categoryDefaultsConfirmPanel) categoryDefaultsConfirmPanel.classList.add("ep-hidden");
    if (listDefaultsConfirmPanel) listDefaultsConfirmPanel.classList.add("ep-hidden");
  };
  const epRenderChoirDefaultsPreview = () => {
    if (!choirTypePreview) return;
    const type = choirTypeSelect ? String(choirTypeSelect.value || "mixed") : "mixed";
    const groups = choirDefaultsByType[type] || choirDefaultsByType.mixed;
    choirTypePreview.textContent = `Will create: ${groups.join(", ")}`;
  };
  const epApplyOwnerGroupTypeDefault = () => {
    if (!choirTypeSelect) return;
    const type = String(epState.ownerGroupType || "").trim().toLowerCase();
    if (type && choirDefaultsByType[type]) {
      choirTypeSelect.value = type;
    }
  };
  const epApplyOwnerProfileSettings = () => {
    if (ownerProfileTypeSelect) {
      ownerProfileTypeSelect.value = epState.ownerProfileType === "group" ? "group" : "person";
    }
    if (ownerGroupTypeSelect) {
      ownerGroupTypeSelect.value = epState.ownerGroupType || "";
      ownerGroupTypeSelect.disabled = !ownerProfileTypeSelect || ownerProfileTypeSelect.value !== "group";
    }
    if (ownerLanguageSelect) {
      const localeKey = String(epState.locale || "").trim().toLowerCase();
      const optionExists = !!ownerLanguageSelect.querySelector(`option[value="${CSS.escape(localeKey || "en")}"]`);
      ownerLanguageSelect.value = optionExists ? localeKey : "en";
    }
  };
  const epCategoryDefaultsByLocale = {
    is: ["Æfing", "Tónleikar", "Party", "Fundur"],
    en: ["Rehearsal", "Concert", "Party", "Meeting"]
  };
  const epListDefaultsByLocale = {
    is: ["Næsta gigg", "Vortónleikar", "Lokið", "Lagasafn"],
    en: ["Next gig", "Spring concert", "Completed", "Song library"]
  };
  const epApplyCategoryLocaleDefault = () => {
    if (!categoryDefaultsLangSelect) return;
    const currentLocale = String(epState.locale || "").trim().toLowerCase();
    categoryDefaultsLangSelect.value = currentLocale.startsWith("is") ? "is" : "en";
  };
  const epRenderCategoryDefaultsPreview = () => {
    if (!categoryDefaultsPreview) return;
    const localeKey = categoryDefaultsLangSelect
      ? String(categoryDefaultsLangSelect.value || "en").toLowerCase()
      : "en";
    const list = epCategoryDefaultsByLocale[localeKey] || epCategoryDefaultsByLocale.en;
    categoryDefaultsPreview.textContent = `Will create: ${list.join(", ")}`;
  };
  const epRenderListDefaultsPreview = () => {
    if (!listDefaultsPreview) return;
    const localeKey = categoryDefaultsLangSelect
      ? String(categoryDefaultsLangSelect.value || "en").toLowerCase()
      : "en";
    const list = epListDefaultsByLocale[localeKey] || epListDefaultsByLocale.en;
    listDefaultsPreview.textContent = `Will create: ${list.join(", ")}. Also nests All Content inside ${list[list.length - 1]}.`;
  };
  const epRenderAllDefaultsPreview = () => {
    if (!allDefaultsPreview) return;
    const localeKey = categoryDefaultsLangSelect
      ? String(categoryDefaultsLangSelect.value || "en").toLowerCase()
      : "en";
    const choirType = choirTypeSelect ? String(choirTypeSelect.value || "mixed") : "mixed";
    const choirGroups = choirDefaultsByType[choirType] || choirDefaultsByType.mixed;
    const categories = epCategoryDefaultsByLocale[localeKey] || epCategoryDefaultsByLocale.en;
    const lists = epListDefaultsByLocale[localeKey] || epListDefaultsByLocale.en;
    allDefaultsPreview.textContent = [
      `Groups: ${choirGroups.join(", ")}`,
      `Categories: ${categories.join(", ")}`,
      `Lists: ${lists.join(", ")}`
    ].join(" • ");
  };
  const epSaveOwnerProfileSettings = async () => {
    const profileType = ownerProfileTypeSelect ? String(ownerProfileTypeSelect.value || "person") : "person";
    const groupType = ownerGroupTypeSelect && profileType === "group"
      ? String(ownerGroupTypeSelect.value || "")
      : "";
    const locale = ownerLanguageSelect
      ? String(ownerLanguageSelect.value || "en").trim().toLowerCase()
      : (epState.locale || "en");
    const res = await epPost("/ep_groups.php", {
      action: "update_owner_profile",
      profile_type: profileType,
      group_type: groupType,
      locale
    });
    if (res.status !== "OK") {
      return { ok: false, message: res.message || "Unable to save profile settings." };
    }
    epState.ownerProfileType = String(res.owner_profile_type || profileType).trim().toLowerCase();
    epState.ownerGroupType = String(res.owner_group_type || groupType).trim().toLowerCase();
    epState.locale = String(res.owner_locale || locale || epState.locale || "").trim().toLowerCase();
    epApplyOwnerProfileSettings();
    epApplyOwnerGroupTypeDefault();
    epApplyCategoryLocaleDefault();
    epRenderChoirDefaultsPreview();
    epRenderCategoryDefaultsPreview();
    epRenderListDefaultsPreview();
    epRenderAllDefaultsPreview();
    return { ok: true };
  };
  const epSetGroupCreateOpen = (open) => {
    if (!groupForm) return;
    groupForm.classList.toggle("ep-hidden", !open);
    if (toggleGroupFormBtn) {
      toggleGroupFormBtn.textContent = open ? "Cancel" : "Add group";
      toggleGroupFormBtn.classList.toggle("cancel", open);
    }
    if (!open) {
      groupForm.reset();
      if (groupForm.color) groupForm.color.value = "#9fb7f0";
    }
  };
  if (toggleGroupFormBtn && groupForm) {
    toggleGroupFormBtn.addEventListener("click", () => {
      const nextOpen = groupForm.classList.contains("ep-hidden");
      epSetGroupCreateOpen(nextOpen);
    });
  }
  if (cancelGroupCreate) {
    cancelGroupCreate.addEventListener("click", () => epSetGroupCreateOpen(false));
  }
  if (toggleGroupDefaultsBtn && groupDefaultsPanel) {
    toggleGroupDefaultsBtn.addEventListener("click", () => {
      const open = groupDefaultsPanel.classList.contains("ep-hidden");
      groupDefaultsPanel.classList.toggle("ep-hidden", !open);
      if (open) {
        epApplyOwnerProfileSettings();
        epApplyOwnerGroupTypeDefault();
        epRenderChoirDefaultsPreview();
        epApplyCategoryLocaleDefault();
        epRenderCategoryDefaultsPreview();
        epRenderListDefaultsPreview();
        epRenderAllDefaultsPreview();
      }
      if (!open) epHideDefaultsConfirmPanels();
    });
  }
  if (closeGroupDefaultsBtn && groupDefaultsPanel) {
    closeGroupDefaultsBtn.addEventListener("click", () => {
      groupDefaultsPanel.classList.add("ep-hidden");
      epHideDefaultsConfirmPanels();
    });
  }
  if (choirTypeSelect) {
    choirTypeSelect.addEventListener("change", () => {
      epRenderChoirDefaultsPreview();
      epRenderAllDefaultsPreview();
    });
    epRenderChoirDefaultsPreview();
  }
  if (ownerProfileTypeSelect && ownerGroupTypeSelect) {
    ownerProfileTypeSelect.addEventListener("change", () => {
      ownerGroupTypeSelect.disabled = ownerProfileTypeSelect.value !== "group";
      if (ownerProfileTypeSelect.value !== "group") {
        ownerGroupTypeSelect.value = "";
      }
    });
  }
  if (saveOwnerProfileBtn) {
    saveOwnerProfileBtn.addEventListener("click", async () => {
      saveOwnerProfileBtn.disabled = true;
      const result = await epSaveOwnerProfileSettings();
      saveOwnerProfileBtn.disabled = false;
      if (result.ok) {
        epFlashMessage("Profile settings saved.");
      } else {
        epFlashMessage(result.message || "Unable to save profile settings.");
      }
    });
  }
  epApplyOwnerProfileSettings();
  if (categoryDefaultsLangSelect) {
    categoryDefaultsLangSelect.addEventListener("change", () => {
      epRenderCategoryDefaultsPreview();
      epRenderListDefaultsPreview();
      epRenderAllDefaultsPreview();
    });
    epApplyCategoryLocaleDefault();
    epRenderCategoryDefaultsPreview();
    epRenderListDefaultsPreview();
    epRenderAllDefaultsPreview();
  }
  if (createAllDefaultsBtn && allDefaultsConfirmPanel) {
    createAllDefaultsBtn.addEventListener("click", () => {
      epHideDefaultsConfirmPanels();
      allDefaultsConfirmPanel.classList.remove("ep-hidden");
      epApplyOwnerProfileSettings();
      epApplyOwnerGroupTypeDefault();
      epApplyCategoryLocaleDefault();
      epRenderChoirDefaultsPreview();
      epRenderCategoryDefaultsPreview();
      epRenderListDefaultsPreview();
      epRenderAllDefaultsPreview();
    });
  }
  if (cancelAllDefaultsBtn && allDefaultsConfirmPanel) {
    cancelAllDefaultsBtn.addEventListener("click", () => {
      allDefaultsConfirmPanel.classList.add("ep-hidden");
    });
  }
  if (confirmAllDefaultsBtn) {
    confirmAllDefaultsBtn.addEventListener("click", async () => {
      const locale = categoryDefaultsLangSelect
        ? String(categoryDefaultsLangSelect.value || "en").trim().toLowerCase()
        : "en";
      const choirType = choirTypeSelect ? String(choirTypeSelect.value || "mixed") : "mixed";
      confirmAllDefaultsBtn.disabled = true;
      const saveResult = await epSaveOwnerProfileSettings();
      if (!saveResult.ok) {
        confirmAllDefaultsBtn.disabled = false;
        epFlashMessage(saveResult.message || "Unable to save profile settings.");
        return;
      }
      const groupsRes = await epPost("/ep_groups.php", {
        action: "create_default_choir_groups",
        choir_type: choirType,
        locale
      });
      const categoriesRes = await epPost("/ep_categories.php", {
        action: "create_default_categories",
        locale
      });
      const listsRes = await epPost("/ep_groups.php", {
        action: "create_default_lists",
        locale
      });
      confirmAllDefaultsBtn.disabled = false;

      if (groupsRes.status === "OK" && categoriesRes.status === "OK" && listsRes.status === "OK") {
        const gCreated = Number(groupsRes.created_groups || 0);
        const gSkipped = Number(groupsRes.skipped_groups || 0);
        const cCreated = Number(categoriesRes.created_categories || 0);
        const cSkipped = Number(categoriesRes.skipped_categories || 0);
        const lCreated = Number(listsRes.created_lists || 0);
        const lSkipped = Number(listsRes.skipped_lists || 0);
        await Promise.all([
          epLoadGroups(),
          epLoadCategoryCatalog()
        ]);
        await epRefreshParentSidebarLists();
        epRenderCategorySettings();
        epUpdateCategoryList(epState.allEvents.length ? epState.allEvents : epState.events);
        epRenderCalendar();
        if (allDefaultsConfirmPanel) allDefaultsConfirmPanel.classList.add("ep-hidden");
        epFlashMessage(`All defaults done. Groups ${gCreated}/${gSkipped}, Categories ${cCreated}/${cSkipped}, Lists ${lCreated}/${lSkipped}.`);
      } else {
        const msg = groupsRes.message || categoriesRes.message || listsRes.message || "Unable to create all defaults.";
        epFlashMessage(msg);
      }
    });
  }
  if (groupForm) {
    groupForm.addEventListener("submit", async (e) => {
      e.preventDefault();
      const form = e.currentTarget;
      const payload = {
        action: "create",
        name: form.name.value.trim(),
        description: form.description.value.trim(),
        color: form.color ? form.color.value : "",
        is_role_group: form.is_role_group && form.is_role_group.checked ? 1 : 0
      };
      if (!payload.name) return;
      const res = await epPost("/ep_groups.php", payload);
      if (res.status === "OK") {
        epSetGroupCreateOpen(false);
        epState.currentGroupId = Number(res.group_id || 0) || epState.currentGroupId;
        epLoadGroups();
      }
    });
  }
  if (convertChoirRolesBtn) {
    convertChoirRolesBtn.addEventListener("click", async () => {
      convertChoirRolesBtn.disabled = true;
      const preview = await epPost("/ep_groups.php", {
        action: "preview_choir_roles_to_groups"
      });
      if (preview.status !== "OK") {
        convertChoirRolesBtn.disabled = false;
        epFlashMessage(preview.message || "Unable to preview choir roles.");
        return;
      }
      const roles = Array.isArray(preview.roles) ? preview.roles : [];
      if (!roles.length) {
        convertChoirRolesBtn.disabled = false;
        epFlashMessage("No choir-like roles found.");
        return;
      }
      choirRolePreviewItems = roles;
      if (choirRolesPreview) {
        const roleLines = roles.map((entry) => {
          const label = String(entry.name || "").trim() || "Role";
          const count = Number(entry.count || 0);
          const existing = Number(entry.existing_group_id || 0) > 0 ? " (group exists)" : "";
          return `${label} (${count})${existing}`;
        });
        choirRolesPreview.textContent = `Roles: ${roleLines.join(", ")}`;
      }
      if (choirDefaultsConfirmPanel) choirDefaultsConfirmPanel.classList.add("ep-hidden");
      if (choirRolesConfirmPanel) choirRolesConfirmPanel.classList.remove("ep-hidden");
      convertChoirRolesBtn.disabled = false;
    });
  }
  if (cancelChoirRolesBtn && choirRolesConfirmPanel) {
    cancelChoirRolesBtn.addEventListener("click", () => {
      choirRolesConfirmPanel.classList.add("ep-hidden");
    });
  }
  if (confirmChoirRolesBtn) {
    confirmChoirRolesBtn.addEventListener("click", async () => {
      const roles = choirRolePreviewItems.map((entry) => String(entry.name || "").trim()).filter(Boolean);
      confirmChoirRolesBtn.disabled = true;
      const res = await epPost("/ep_groups.php", {
        action: "convert_choir_roles_to_groups",
        roles
      });
      confirmChoirRolesBtn.disabled = false;
      if (res.status === "OK") {
        const createdGroups = Number(res.created_groups || 0);
        const updatedMembers = Number(res.updated_members || 0);
        const feedback = createdGroups || updatedMembers
          ? `Converted roles: ${createdGroups} groups, ${updatedMembers} members.`
          : (res.message || "No choir-like roles found.");
        epFlashMessage(feedback);
        if (choirRolesConfirmPanel) choirRolesConfirmPanel.classList.add("ep-hidden");
        await epLoadGroups();
        if (epState.currentGroupId) {
          const current = epState.groups.find((g) => Number(g.id) === Number(epState.currentGroupId));
          if (current) {
            await epSelectGroup(epState.currentGroupId, current.name || "Members");
          }
        }
      } else {
        epFlashMessage(res.message || "Unable to convert choir roles.");
      }
    });
  }
  if (createChoirDefaultsBtn && choirDefaultsConfirmPanel) {
    createChoirDefaultsBtn.addEventListener("click", () => {
      if (choirRolesConfirmPanel) choirRolesConfirmPanel.classList.add("ep-hidden");
      if (categoryDefaultsConfirmPanel) categoryDefaultsConfirmPanel.classList.add("ep-hidden");
      choirDefaultsConfirmPanel.classList.remove("ep-hidden");
      epRenderChoirDefaultsPreview();
    });
  }
  if (cancelChoirDefaultsBtn && choirDefaultsConfirmPanel) {
    cancelChoirDefaultsBtn.addEventListener("click", () => {
      choirDefaultsConfirmPanel.classList.add("ep-hidden");
    });
  }
  if (confirmChoirDefaultsBtn) {
    confirmChoirDefaultsBtn.addEventListener("click", async () => {
      const choirType = choirTypeSelect ? String(choirTypeSelect.value || "mixed") : "mixed";
      confirmChoirDefaultsBtn.disabled = true;
      const res = await epPost("/ep_groups.php", {
        action: "create_default_choir_groups",
        choir_type: choirType,
        locale: epState.locale || ""
      });
      confirmChoirDefaultsBtn.disabled = false;
      if (res.status === "OK") {
        const createdCount = Number(res.created_groups || 0);
        const skippedCount = Number(res.skipped_groups || 0);
        epState.ownerGroupType = String(res.group_type || epState.ownerGroupType || "").trim().toLowerCase();
        epFlashMessage(`Created ${createdCount} default groups${skippedCount ? ` (${skippedCount} skipped)` : ""}.`);
        await epLoadGroups();
        choirDefaultsConfirmPanel.classList.add("ep-hidden");
      } else {
        epFlashMessage(res.message || "Unable to create default choir groups.");
      }
    });
  }
  if (createCategoryDefaultsBtn && categoryDefaultsConfirmPanel) {
    createCategoryDefaultsBtn.addEventListener("click", () => {
      if (choirRolesConfirmPanel) choirRolesConfirmPanel.classList.add("ep-hidden");
      if (choirDefaultsConfirmPanel) choirDefaultsConfirmPanel.classList.add("ep-hidden");
      if (listDefaultsConfirmPanel) listDefaultsConfirmPanel.classList.add("ep-hidden");
      categoryDefaultsConfirmPanel.classList.remove("ep-hidden");
      epApplyCategoryLocaleDefault();
      epRenderCategoryDefaultsPreview();
      epRenderListDefaultsPreview();
    });
  }
  if (cancelCategoryDefaultsBtn && categoryDefaultsConfirmPanel) {
    cancelCategoryDefaultsBtn.addEventListener("click", () => {
      categoryDefaultsConfirmPanel.classList.add("ep-hidden");
    });
  }
  if (confirmCategoryDefaultsBtn) {
    confirmCategoryDefaultsBtn.addEventListener("click", async () => {
      const locale = categoryDefaultsLangSelect
        ? String(categoryDefaultsLangSelect.value || "en").trim().toLowerCase()
        : "en";
      confirmCategoryDefaultsBtn.disabled = true;
      const res = await epPost("/ep_categories.php", {
        action: "create_default_categories",
        locale
      });
      confirmCategoryDefaultsBtn.disabled = false;
      if (res.status === "OK") {
        const created = Number(res.created_categories || 0);
        const skipped = Number(res.skipped_categories || 0);
        epFlashMessage(`Created ${created} default categories${skipped ? ` (${skipped} skipped)` : ""}.`);
        await epLoadCategoryCatalog();
        epRenderCategorySettings();
        epUpdateCategoryList(epState.allEvents.length ? epState.allEvents : epState.events);
        epRenderCalendar();
        if (categoryDefaultsConfirmPanel) categoryDefaultsConfirmPanel.classList.add("ep-hidden");
      } else {
        epFlashMessage(res.message || "Unable to create default categories.");
      }
    });
  }
  if (createListDefaultsBtn && listDefaultsConfirmPanel) {
    createListDefaultsBtn.addEventListener("click", () => {
      if (choirRolesConfirmPanel) choirRolesConfirmPanel.classList.add("ep-hidden");
      if (choirDefaultsConfirmPanel) choirDefaultsConfirmPanel.classList.add("ep-hidden");
      if (categoryDefaultsConfirmPanel) categoryDefaultsConfirmPanel.classList.add("ep-hidden");
      listDefaultsConfirmPanel.classList.remove("ep-hidden");
      epApplyCategoryLocaleDefault();
      epRenderListDefaultsPreview();
    });
  }
  if (cancelListDefaultsBtn && listDefaultsConfirmPanel) {
    cancelListDefaultsBtn.addEventListener("click", () => {
      listDefaultsConfirmPanel.classList.add("ep-hidden");
    });
  }
  if (confirmListDefaultsBtn) {
    confirmListDefaultsBtn.addEventListener("click", async () => {
      const locale = categoryDefaultsLangSelect
        ? String(categoryDefaultsLangSelect.value || "en").trim().toLowerCase()
        : "en";
      confirmListDefaultsBtn.disabled = true;
      const res = await epPost("/ep_groups.php", {
        action: "create_default_lists",
        locale
      });
      confirmListDefaultsBtn.disabled = false;
      if (res.status === "OK") {
        const created = Number(res.created_lists || 0);
        const skipped = Number(res.skipped_lists || 0);
        const nestedHint = Number(res.nested_all_content || 0) === 1 ? " All Content nested." : "";
        await epRefreshParentSidebarLists();
        epFlashMessage(`Created ${created} default lists${skipped ? ` (${skipped} skipped)` : ""}.${nestedHint}`);
        if (listDefaultsConfirmPanel) listDefaultsConfirmPanel.classList.add("ep-hidden");
      } else {
        epFlashMessage(res.message || "Unable to create default lists.");
      }
    });
  }

  const editGroupBtn = epEl("epEditGroupBtn");
  const deleteGroupBtn = epEl("epDeleteGroupBtn");
  const refreshGroupMembersBtn = epEl("epRefreshGroupMembersBtn");
  const groupEditForm = epEl("epGroupEditForm");
  const cancelGroupEdit = epEl("epCancelGroupEdit");

  if (!epState.canManage) {
    if (editGroupBtn) editGroupBtn.disabled = true;
    if (deleteGroupBtn) deleteGroupBtn.disabled = true;
    if (groupEditForm) groupEditForm.classList.add("ep-hidden");
  }

  if (editGroupBtn && groupEditForm) {
    editGroupBtn.addEventListener("click", () => {
      const group = epState.groups.find((g) => g.id === epState.currentGroupId);
      if (!group) return;
      groupEditForm.classList.remove("ep-hidden");
      epPopulateGroupEditForm(groupEditForm, group);
    });
  }

  if (cancelGroupEdit && groupEditForm) {
    cancelGroupEdit.addEventListener("click", () => {
      groupEditForm.classList.add("ep-hidden");
    });
  }

  if (groupEditForm) {
    let roleToggleBusy = false;
    const submitGroupEdit = async () => {
      if (!epState.currentGroupId) return;
      const payload = {
        action: "update",
        group_id: epState.currentGroupId,
        name: groupEditForm.name.value.trim(),
        description: groupEditForm.description.value.trim(),
        color: groupEditForm.color ? groupEditForm.color.value : "",
        is_role_group: groupEditForm.is_role_group && groupEditForm.is_role_group.checked ? 1 : 0
      };
      if (!payload.name) return;
      const res = await epPost("/ep_groups.php", payload);
      if (res.status === "OK") {
        await epLoadGroups();
        return true;
      } else {
        alert(res.message || "Unable to update group.");
        return false;
      }
    };

    groupEditForm.addEventListener("submit", async (eventObj) => {
      eventObj.preventDefault();
      const ok = await submitGroupEdit();
      if (ok) {
        groupEditForm.classList.add("ep-hidden");
      }
    });

    if (groupEditForm.is_role_group) {
      groupEditForm.is_role_group.addEventListener("change", async () => {
        if (roleToggleBusy) return;
        roleToggleBusy = true;
        await submitGroupEdit();
        roleToggleBusy = false;
      });
    }
  }

  if (refreshGroupMembersBtn) {
    refreshGroupMembersBtn.addEventListener("click", async () => {
      if (!epState.currentGroupId) return;
      const selectedGroup = epState.groups.find((group) => Number(group.id) === Number(epState.currentGroupId));
      refreshGroupMembersBtn.disabled = true;
      try {
        await epSelectGroup(epState.currentGroupId, selectedGroup ? selectedGroup.name : "Members");
      } finally {
        refreshGroupMembersBtn.disabled = false;
      }
    });
  }

  if (deleteGroupBtn) {
    deleteGroupBtn.addEventListener("click", async () => {
      if (!epState.currentGroupId) return;
      const selectedGroup = epState.groups.find((g) => g.id === epState.currentGroupId);
      if (selectedGroup && Number(selectedGroup.is_all_members || 0) === 1) {
        alert("All Members group cannot be deleted.");
        return;
      }
      if (!confirm("Delete this group?")) return;
      const res = await epPost("/ep_groups.php", {
        action: "delete",
        group_id: epState.currentGroupId
      });
      if (res.status === "OK") {
        epState.currentGroupId = null;
        const label = epEl("epMemberGroupLabel");
        if (label) label.textContent = "Select a group";
        const members = epEl("epGroupMembers");
        if (members) members.innerHTML = "<div class='ep-panel-sub'>No members yet.</div>";
        const inviteList = epEl("epInviteList");
        if (inviteList) inviteList.innerHTML = "<div class='ep-panel-sub'>Select a group to add members.</div>";
        if (refreshGroupMembersBtn) refreshGroupMembersBtn.disabled = true;
        if (editGroupBtn) editGroupBtn.disabled = true;
        if (deleteGroupBtn) deleteGroupBtn.disabled = true;
        if (groupEditForm) groupEditForm.classList.add("ep-hidden");
        epLoadGroups();
      } else {
        alert(res.message || "Unable to delete group.");
      }
    });
  }

  const toggleFormBtn = epEl("epToggleEventForm");
  const eventForm = epEl("epEventForm");
  if (!epState.canManage) {
    if (toggleFormBtn) toggleFormBtn.classList.add("ep-hidden");
    if (eventForm) eventForm.classList.add("ep-hidden");
  }
  if (toggleFormBtn && eventForm) {
    const eventsPanel = toggleFormBtn.closest(".ep-panel.ep-events");
    if (eventsPanel) {
      eventsPanel.classList.toggle("is-creating", !eventForm.classList.contains("ep-hidden"));
    }
    toggleFormBtn.textContent = eventForm.classList.contains("ep-hidden")
      ? "Create event"
      : "Cancel";
    toggleFormBtn.classList.toggle("cancel", !eventForm.classList.contains("ep-hidden"));
  }
  if (eventForm) {
    epBindCategoryPicker(eventForm);
    epBindEventImageUploader(eventForm, () => null);
  }
  if (toggleFormBtn && eventForm) {
    toggleFormBtn.addEventListener("click", () => {
      const eventsPanel = toggleFormBtn.closest(".ep-panel.ep-events");
      const isOpen = !eventForm.classList.contains("ep-hidden");
      if (isOpen) {
        eventForm.classList.add("ep-hidden");
        toggleFormBtn.textContent = "Create event";
        toggleFormBtn.classList.remove("cancel");
        if (eventsPanel) eventsPanel.classList.remove("is-creating");
        return;
      }
      epOpenCreateEventFormForDate("");
    });
  }

  if (eventForm) {
    eventForm.addEventListener("submit", async (e) => {
      e.preventDefault();
      if (eventForm.dataset.imageUploading === "1") {
        alert("Image is still uploading. Please wait.");
        return;
      }
      const form = e.currentTarget;
      const mode = form.dataset.mode || "create";
      const isRecurring = form.recurring && form.recurring.checked;
      const groupPicker = epEl("epEventGroupPicker");
      const groupInputs = groupPicker ? groupPicker.querySelectorAll("input:checked") : [];
      const selectedIds = Array.from(groupInputs)
        .map((input) => Number(input.value))
        .filter((value) => Number.isFinite(value));
      const allMembersGroupIds = epState.groups
        .filter((group) => Number(group.is_all_members || 0) === 1)
        .map((group) => Number(group.id));
      const allMembersSelected = selectedIds.some((id) => allMembersGroupIds.includes(id));
      const groupIds = selectedIds.filter((id) => !allMembersGroupIds.includes(id));
      const payload = {
        action: mode === "edit" ? "update" : (isRecurring ? "create_recurring" : "create"),
        title: form.title.value.trim(),
        category: (form.category ? form.category.value.trim() : ""),
        location: form.location.value.trim(),
        starts_at: epGetDateValue(form.starts_at),
        ends_at: epGetDateValue(form.ends_at) || null,
        notes: form.notes.value.trim(),
        image_url: form.image_url ? form.image_url.value.trim() : "",
        all_members: allMembersSelected ? 1 : 0
      };
      if (!payload.title || !payload.starts_at) {
        alert("Title and start date are required.");
        return;
      }
      if (mode === "edit") {
        payload.event_id = Number(form.dataset.eventId || 0);
        if (!payload.event_id) return;
      }
  if (isRecurring) {
    payload.recurring_frequency = form.recurring_frequency.value;
    payload.recurring_until = epGetDateValue(form.recurring_until, "Y-m-d");
    payload.rotate_groups = form.rotate_groups && form.rotate_groups.checked ? 1 : 0;
    if (!allMembersSelected) {
      payload.group_ids = groupIds;
    }
  } else if (!allMembersSelected && groupIds.length) {
        payload.group_ids = groupIds;
      }
      const res = await epPost("/ep_events.php", payload);
      if (res.status === "OK") {
        if (isRecurring && res.series_id) {
          epFlashMessage(`Created ${Number(res.created || 0)} events in ${epSeriesLabel(res.series_id)}.`);
        }
        if (payload.image_url) {
          epRememberImageSuggestion(payload.image_url);
        }
        form.reset();
        eventForm.classList.add("ep-hidden");
        const eventsPanel = toggleFormBtn ? toggleFormBtn.closest(".ep-panel.ep-events") : null;
        if (eventsPanel) eventsPanel.classList.remove("is-creating");
        epLoadEvents();
        const categoryValue = epNormalizeCategoryName(payload.category);
        if (categoryValue) {
          epCreateCategoryIfMissing(categoryValue);
        }
        form.dataset.mode = "create";
        form.dataset.eventId = "";
        if (toggleFormBtn) {
          toggleFormBtn.textContent = "Create event";
          toggleFormBtn.classList.remove("cancel");
        }
      }
    });
  }

  const refreshBtn = epEl("epRefreshBtn");
  if (refreshBtn) {
    refreshBtn.addEventListener("click", async (eventObj) => {
      eventObj.preventDefault();
      eventObj.stopPropagation();
      await epLoadGroups();
      await epLoadEvents();
    });
  }

  const myEventsToggle = epEl("epFilterMyEvents");
  if (myEventsToggle) {
    myEventsToggle.addEventListener("change", () => {
      epState.eventFilters.myEvents = !!myEventsToggle.checked;
      epLoadEvents();
    });
  }

  const fromDateInput = epEl("epFilterFromDate");
  if (fromDateInput) {
    if (!fromDateInput.value && epState.eventFilters.fromDate) {
      if (fromDateInput._flatpickr) {
        fromDateInput._flatpickr.setDate(epState.eventFilters.fromDate, true);
      } else {
        fromDateInput.value = epState.eventFilters.fromDate;
      }
    }
    fromDateInput.addEventListener("change", () => {
      epState.eventFilters.fromDate = fromDateInput.value || "";
      epLoadEvents();
    });
  }

  const nextDaysSelect = epEl("epTopNextDays");
  if (nextDaysSelect) {
    const currentDays = Number(epState.calloutWindowDays || 7);
    nextDaysSelect.value = String(currentDays);
    if (nextDaysSelect.value !== String(currentDays)) {
      nextDaysSelect.value = "7";
      epState.calloutWindowDays = 7;
    }
    const swallowPopoutToggle = (eventObj) => {
      eventObj.stopPropagation();
    };
    nextDaysSelect.addEventListener("pointerdown", swallowPopoutToggle);
    nextDaysSelect.addEventListener("mousedown", swallowPopoutToggle);
    nextDaysSelect.addEventListener("click", swallowPopoutToggle);
    nextDaysSelect.addEventListener("touchstart", swallowPopoutToggle, { passive: true });
    nextDaysSelect.addEventListener("change", () => {
      const next = Number(nextDaysSelect.value || 7);
      epState.calloutWindowDays = Number.isFinite(next) && next > 0 ? Math.floor(next) : 7;
      epRenderHeroCallout();
    });
  }

  const categoryFilter = epEl("epFilterCategory");
  if (categoryFilter) {
    categoryFilter.addEventListener("change", () => {
      epState.eventFilters.category = categoryFilter.value || "";
      epLoadEvents();
    });
  }

  const groupFilter = epEl("epFilterGroup");
  if (groupFilter) {
    groupFilter.addEventListener("change", () => {
      epState.eventFilters.groupId = groupFilter.value || "";
      epLoadEvents();
    });
  }


  const inviteRefresh = epEl("epInviteRefresh");
  if (inviteRefresh) {
    if (!epState.canManage) {
      inviteRefresh.disabled = true;
    } else {
      inviteRefresh.addEventListener("click", async () => {
        inviteRefresh.disabled = true;
        await epLoadInvitedMembers();
        inviteRefresh.disabled = false;
      });
    }
  }

  const inviteSearch = epEl("epInviteSearch");
  if (inviteSearch) {
    inviteSearch.value = epState.inviteSearch || "";
    inviteSearch.addEventListener("input", () => {
      epState.inviteSearch = inviteSearch.value || "";
      epRenderInviteList();
    });
  }

  const inviteSend = epEl("epInviteSend");
  const inviteEmails = epEl("epInviteEmails");
  if (!epState.canManage) {
    if (inviteSend) inviteSend.disabled = true;
    if (inviteEmails) inviteEmails.disabled = true;
  } else if (inviteSend) {
    inviteSend.addEventListener("click", epSendGroupInvites);
  }

  const memberSearch = epEl("epMemberSearch");
  if (memberSearch) {
    memberSearch.value = epState.memberSearch || "";
    memberSearch.addEventListener("input", () => {
      epState.memberSearch = memberSearch.value || "";
      epRenderGroupMembersPanel(epState.currentGroupData);
    });
  }

  if (eventForm) {
    eventForm.recurring?.addEventListener("change", () => {
      epToggleRecurringFields(eventForm);
    });
    eventForm.starts_at?.addEventListener("change", () => {
      epDefaultRecurringUntil(eventForm);
    });
  }

  const syncEditMode = () => {
    const toggles = Array.from(document.querySelectorAll(".edit-mode-toggle"));
    const stored = localStorage.getItem("twEditMode");
    const storedEdit = stored === "1";
    epState.editMode = toggles.length ? toggles.some((toggle) => toggle.checked) : storedEdit;
    epApplyEditMode();
  };
  syncEditMode();
  window.addEventListener("storage", (eventObj) => {
    if (eventObj.key === "twEditMode") {
      syncEditMode();
    }
  });
}

document.addEventListener("DOMContentLoaded", epInit);

function epOpenEditEvent(event) {
  const form = epEl("epEventForm");
  const toggleBtn = epEl("epToggleEventForm");
  if (!form) return;
  form.classList.remove("ep-hidden");
  form.dataset.mode = "edit";
  form.dataset.eventId = event.id;
  form.title.value = event.title || "";
  if (form.category) form.category.value = event.category || "";
  form.location.value = event.location || "";
  form.starts_at.value = epToInputDate(event.starts_at || "");
  form.ends_at.value = epToInputDate(event.ends_at || "");
  form.notes.value = event.notes || "";
  if (form.image_url) {
    form.image_url.value = event.image_url || "";
    form.image_url.dispatchEvent(new Event("input", { bubbles: true }));
  }
  if (form.recurring) form.recurring.checked = false;
  epToggleRecurringFields(form);
  if (toggleBtn) toggleBtn.textContent = "Editing event";
}

function epToggleRecurringFields(form) {
  const fields = form.querySelectorAll(".ep-recurring-field");
  const isRecurring = form.recurring && form.recurring.checked;
  fields.forEach((field) => {
    field.classList.toggle("ep-hidden", !isRecurring);
  });
  if (isRecurring) {
    epDefaultRecurringUntil(form);
  }
}

function epDefaultRecurringUntil(form) {
  if (!form.recurring || !form.recurring.checked) return;
  if (!form.starts_at.value) return;
  if (form.recurring_until.value) return;
  const start = new Date(form.starts_at.value);
  if (Number.isNaN(start.getTime())) return;
  const end = new Date(start);
  end.setMonth(end.getMonth() + 3);
  const pad = (n) => String(n).padStart(2, "0");
  const dateValue = `${end.getFullYear()}-${pad(end.getMonth() + 1)}-${pad(end.getDate())}`;
  form.recurring_until.value = dateValue;
}

function epGetDateValue(input, format = "Y-m-d H:i:00") {
  if (!input) return "";
  const value = (input.value || "").trim();
  if (input._flatpickr) {
    const fp = input._flatpickr;
    if (fp.selectedDates && fp.selectedDates.length) {
      return fp.formatDate(fp.selectedDates[0], format);
    }
  }
  return value;
}

function epCleanInviteEmail(raw) {
  if (!raw) return "";
  // mirror chat cleanEmail behavior
  let email = raw.trim().replace(/^[<('"`]+|[>)"'`]+$/g, "");
  const match = email.match(/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/);
  return match ? match[1].toLowerCase() : "";
}

function epExtractInviteEmails(raw) {
  if (!raw) return [];
  const matches = raw.match(/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/g);
  if (matches && matches.length) {
    return matches.map((email) => email.toLowerCase());
  }
  return raw
    .split(/[\s,;]+/)
    .map(epCleanInviteEmail)
    .filter(Boolean);
}

async function epSendGroupInvites() {
  if (!epState.canManage) return;
  const input = epEl("epInviteEmails");
  if (!input) return;
  const token = epState.ownerToken || epState.username || "";
  if (!token) {
    alert("Unable to resolve list owner for invites.");
    return;
  }

  const emails = Array.from(new Set(epExtractInviteEmails(input.value)));

  if (!emails.length) {
    alert("No valid email addresses found.");
    return;
  }

  try {
    await Promise.all(
      emails.map((email) =>
        fetch("/chatInviteToList.php", {
          method: "POST",
          headers: { "Content-Type": "application/x-www-form-urlencoded" },
          body: `token=${encodeURIComponent(token)}&email=${encodeURIComponent(email)}&role=viewer`
        })
      )
    );
    alert("Invites sent.");
    input.value = "";
    await epLoadInvitedMembers();
  } catch (err) {
    alert("Unable to send invites.");
  }
}

function epSetDefaultStart(form) {
  if (!form || !form.starts_at) return;
  const now = new Date();
  const start = new Date(
    now.getFullYear(),
    now.getMonth(),
    now.getDate(),
    10,
    0,
    0,
    0
  );
  if (form.starts_at._flatpickr) {
    form.starts_at._flatpickr.setDate(start, true);
    return;
  }
  const pad = (n) => String(n).padStart(2, "0");
  const dateValue = `${start.getFullYear()}-${pad(start.getMonth() + 1)}-${pad(start.getDate())}`;
  const timeValue = `${pad(start.getHours())}:${pad(start.getMinutes())}`;
  form.starts_at.value = form.starts_at.type === "datetime-local"
    ? `${dateValue}T${timeValue}`
    : `${dateValue} ${timeValue}`;
}

function epSetStartFromDateKey(form, dateKey) {
  if (!form || !form.starts_at || !dateKey) return;
  const date = new Date(`${dateKey}T10:00:00`);
  if (Number.isNaN(date.getTime())) return;
  if (form.starts_at._flatpickr) {
    form.starts_at._flatpickr.setDate(date, true);
    return;
  }
  const pad = (n) => String(n).padStart(2, "0");
  const dateValue = `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
  const timeValue = `${pad(date.getHours())}:${pad(date.getMinutes())}`;
  form.starts_at.value = form.starts_at.type === "datetime-local"
    ? `${dateValue}T${timeValue}`
    : `${dateValue} ${timeValue}`;
}

function epPrepareCreateEventForm(form, dateKey = "") {
  if (!form) return;
  form.classList.remove("ep-hidden");
  form.dataset.mode = "create";
  form.dataset.eventId = "";
  form.reset();
  if (form.category) form.category.value = "";
  if (form.image_url) {
    form.image_url.value = "";
    form.image_url.dispatchEvent(new Event("input", { bubbles: true }));
  }
  if (dateKey) {
    epSetStartFromDateKey(form, dateKey);
  } else {
    epSetDefaultStart(form);
  }
  epToggleRecurringFields(form);
  epBindCategoryPicker(form);
  epSetDefaultEventGroups();
}

function epOpenCreateEventFormForDate(dateKey = "") {
  if (!epState.canManage) return;
  const form = epEl("epEventForm");
  const toggleFormBtn = epEl("epToggleEventForm");
  if (!form) return;
  const eventsSection = document.querySelector(".ep-section.ep-events");
  if (eventsSection && !eventsSection.open) eventsSection.open = true;
  epPrepareCreateEventForm(form, dateKey);
  if (toggleFormBtn) {
    toggleFormBtn.textContent = "Cancel";
    toggleFormBtn.classList.add("cancel");
    const eventsPanel = toggleFormBtn.closest(".ep-panel.ep-events");
    if (eventsPanel) eventsPanel.classList.add("is-creating");
  }
}
