
document.addEventListener("DOMContentLoaded", function () {
  const chatInput = document.getElementById("chatInput");
  const chatMessages = document.getElementById("chatMessages");
  const chatContainer = document.getElementById("chatContainer");
  
  
    document.getElementById("chatInput").addEventListener("paste", function (e) {
      const items = e.clipboardData.items;
      for (let i = 0; i < items.length; i++) {
        if (items[i].type.indexOf("image") !== -1) {
          const file = items[i].getAsFile();
          uploadChatImage(file);  // You’ll define this
        }
      }
    });
  
  

    navigator.serviceWorker?.addEventListener("message", event => {
      if (event.data?.type === "play-ding") {
        const count = Math.min(event.data.count || 0, 5); // Safe max of 5
        const delay = 800; // ms between dings
    
        for (let i = 0; i < count; i++) {
          setTimeout(() => {
            const audio = new Audio("/sounds/ding.mp3");
            audio.play().catch(err => {
              console.warn("🔇 Audio play failed:", err);
            });
          }, i * delay);
        }
        return;
      }
      if (event.data?.type === "navigate-to" && event.data?.url) {
        window.location.href = String(event.data.url);
      }
    });




  // ✨ Focus: scroll behavior on focus
  if (chatInput) {
    chatInput.addEventListener("focus", () => {
      setTimeout(() => {
        chatInput.scrollIntoView({ behavior: "smooth", block: "center" });
        if (chatMessages) {
          chatMessages.scrollTop = chatMessages.scrollHeight;
        }
      }, 300);
    });
    
    // 💬 Enter key behavior (desktop vs mobile)
    const isMobile = /iPhone|iPad|Android/i.test(navigator.userAgent);
    chatInput.addEventListener("keydown", function (e) {
      if (e.key === "ArrowLeft" || e.key === "ArrowRight" || e.key === "ArrowUp" || e.key === "ArrowDown") {
        e.stopPropagation();
      }
      if (e.key === "Enter") {
        if (isMobile) return; // newline on mobile
        if (!e.shiftKey) {
          e.preventDefault();
          chatSendMessage();
        }
      }
    });
  }

  // 🔔 Scroll listener to hide new-message badge
  if (chatMessages) {
    chatMessages.addEventListener("scroll", () => {
      const badge = document.getElementById("newMessageBadge");
      const footerChatBadge = document.getElementById("chatUnreadBadge");      
      const nearBottom = chatMessages.scrollHeight - chatMessages.scrollTop - chatMessages.clientHeight < 50;
      if (badge && nearBottom) {
        badge.style.display = "none";
      }
    });
    chatMessages.addEventListener("click", async (event) => {
      const btn = event.target.closest('[data-role="chat-poll-option"]');
      if (!btn) return;
      event.preventDefault();
      event.stopPropagation();
      const pollId = Number(btn.dataset.pollId || 0);
      const optionId = Number(btn.dataset.optionId || 0);
      if (!pollId || !optionId) return;
      await chatVotePollOption(pollId, optionId);
    });
    chatMessages.addEventListener("click", async (event) => {
      const btn = event.target.closest('[data-role="chat-delete-message"]');
      if (!btn) return;
      event.preventDefault();
      event.stopPropagation();
      const messageId = Number(btn.dataset.messageId || 0);
      if (!messageId) return;
      await chatDeleteMessage(messageId);
    });
  }

  const headerTrigger = document.getElementById("chatHeaderMenuTrigger");
  if (headerTrigger) {
    headerTrigger.addEventListener("click", (event) => {
      const backArrow = document.getElementById("chatBackArrow");
      if (backArrow && backArrow.style.display !== "none" && event.target.closest("#chatBackArrow")) return;
      toggleChatHeaderMenu(event);
    });
  }
});

if (typeof window.twResolveAvatarUrl !== "function") {
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

  const twHashString = (value) => {
    const str = String(value || "");
    let hash = 2166136261;
    for (let i = 0; i < str.length; i += 1) {
      hash ^= str.charCodeAt(i);
      hash += (hash << 1) + (hash << 4) + (hash << 7) + (hash << 8) + (hash << 24);
    }
    return hash >>> 0;
  };

  const twAvatarInitials = (name) => {
    if (!name) return "?";
    const parts = String(name).trim().split(/\s+/);
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
  };

  const twAvatarDataUrl = (seed, initials) => {
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
  };

  window.twResolveAvatarUrl = (member, fallbackName) => {
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
  };

  window.twAvatarDataUrl = twAvatarDataUrl;
  window.twAvatarInitials = twAvatarInitials;
  window.twHandleAvatarError = function (img) {
    if (!img || img.dataset.avatarFallbackDone) return;
    const name = img.getAttribute("alt") || "User";
    img.dataset.avatarFallbackDone = "1";
    img.onerror = null;
    img.src = twAvatarDataUrl(name, twAvatarInitials(name));
  };

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
}

// 🔔 Canonical unread refresh (lists + thread chats total)
setTimeout(() => {
  if (typeof updateFooterChatBadge === "function") {
    updateFooterChatBadge();
  }
  if (typeof startGlobalUnreadBadgeRefresh === "function") {
    startGlobalUnreadBadgeRefresh();
  }
}, 0);



//for links in chat
function linkify(text) {
  return text.replace(
    /(\bhttps?:\/\/[^\s]+)/gi,
    url => `<a href="${url}" target="_blank" rel="noopener noreferrer">${url}</a>`
  );
}


function editMessage(messageId, originalText) {
  const newText = prompt("Edit message:", originalText);
  if (newText === null || newText === originalText.trim()) return;

  fetch('/chatEditMessage.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `id=${encodeURIComponent(messageId)}&text=${encodeURIComponent(newText)}`
  })
  .then(res => res.json())
  .then(data => {
    if (data.status === 'success') {
      chatLoadMessages(); // refresh
    } else {
      alert("❌ Failed to update message");
    }
  });
}


function reactToMessage(messageId, emoji) {
  console.log(`Reacting to message ${messageId} with ${emoji}`);
  // Later: fetch POST to /chatReactToMessage.php
}


function showReactionPicker(messageId, targetEl) {
  const picker = document.createElement("div");
  picker.className = "emoji-picker";
  
  
  picker.innerHTML = `
  <span>👍</span>
  <span>❤️</span>
  <span>😂</span>
  <span>😮</span>
  <span>😢</span>
  <span title="Remove">❌</span>
`;

  
  
  picker.style.cssText = `
    position: absolute;
    background: white;
    border: 1px solid #ccc;
    padding: 5px 8px;
    border-radius: 6px;
    font-size: 20px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.2);
    z-index: 9999;
  `;

  picker.querySelectorAll("span, div").forEach(el => {
    el.style.cursor = "pointer";
    el.onclick = () => {
      reactToMessage(messageId, el.textContent);
      picker.remove();
    };
  });

  document.body.appendChild(picker);
  const rect = targetEl.getBoundingClientRect();
  picker.style.left = `${rect.left + window.scrollX}px`;
  picker.style.top = `${rect.top + window.scrollY - 40}px`;

  setTimeout(() => {
    document.addEventListener("click", function handler(e) {
      if (!picker.contains(e.target)) {
        picker.remove();
        document.removeEventListener("click", handler);
      }
    });
  }, 0);
}


let longPressTimer;

function handleLongPress(event, messageId, el) {
  if (event.type === "touchstart") {
    longPressTimer = setTimeout(() => {
      showReactionPicker(messageId, el);
    }, 600);
  }

  el.addEventListener("touchend", () => clearTimeout(longPressTimer), { once: true });
  el.addEventListener("touchmove", () => clearTimeout(longPressTimer), { once: true });
}




function updateViewportHeight() {
  const vh = window.innerHeight * 0.01;
  document.documentElement.style.setProperty('--vh', `${vh}px`);
}

window.addEventListener('resize', updateViewportHeight);
window.addEventListener('orientationchange', updateViewportHeight);
updateViewportHeight();



let chatFirstOpen = true;
let lastChatScrollTop = 0;        


window.chat = window.chat || {};
chat.pollingInterval = null;

// function startChatPolling() {
//   //console.log("✅ startChatPolling triggered");

//   if (chat.pollingInterval) clearInterval(chat.pollingInterval);

//   chat.pollingInterval = setInterval(() => {
//     console.log("⏱ polling...");
//     chatLoadMessages();
//     updateFooterChatBadge(); // ✅ also refresh badge status
//   }, 5000);
// }



function startChatPolling() {
  if (chat.pollingInterval) return;  // 👈 already running - do nothing

  chat.pollingInterval = setInterval(() => {
    console.log("⏱ polling...");
    chatLoadMessages();
    updateFooterChatBadge();
  }, 5000);
}



function stopChatPolling() {
  if (chat.pollingInterval) {
    clearInterval(chat.pollingInterval);
    chat.pollingInterval = null;
  }
}



function urlBase64ToUint8Array(base64String) {
  const padding = '='.repeat((4 - base64String.length % 4) % 4);
  const base64 = (base64String + padding).replace(/\-/g, '+').replace(/_/g, '/');
  const rawData = atob(base64);
  return Uint8Array.from([...rawData].map(c => c.charCodeAt(0)));
}



function setupPushSubscription(token) {
     const normalizedToken = (typeof normalizeChatToken === "function")
       ? normalizeChatToken(token || "")
       : (token || "");
     console.log("🔔 setupPushSubscription called with token:", normalizedToken);
  if (!normalizedToken) {
    console.warn("🔕 Skip push prefs: missing list token");
    return;
  }
  if (!('serviceWorker' in navigator && 'PushManager' in window)) return;
  if (Notification.permission !== "granted") {
    console.log("🔕 Skip push prefs: notification permission is not granted");
    return;
  }

  (async () => {
    try {
      const key = urlBase64ToUint8Array(window.VAPID_PUBLIC_KEY);
      const reg = await navigator.serviceWorker.ready;

      const existing = await reg.pushManager.getSubscription();
      if (existing) {
        const oldKey = existing.options?.applicationServerKey;
        const oldKeyStr = btoa(String.fromCharCode(...new Uint8Array(oldKey || [])));
        const newKeyStr = btoa(String.fromCharCode(...key));

        if (oldKeyStr !== newKeyStr) {
          console.warn("🔁 Unsubscribing from old push subscription due to VAPID key change");
          await existing.unsubscribe();
        }
      }

      const subscription = await reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: key
      });

      const { endpoint, keys } = subscription.toJSON();
      console.log("📨 Subscription to send:", { endpoint, ...keys });

      // Determine environment based on hostname
      const env = location.hostname.replace(/^www\./, '');

      const resp = await fetch('/chatNotifySetPrefs.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          endpoint,
          keys: {
            p256dh: keys.p256dh,
            auth: keys.auth
          },
          token: normalizedToken,
          enabled: true,
          sound_mode: "ding",
          show_message: true,
          env
        })
      });

      if (!resp.ok) {
        console.warn(`🔕 Push prefs rejected (${resp.status}) for token ${normalizedToken}`);
        return;
      }

      console.log('✅ Push subscription sent', normalizedToken);
    } catch (err) {
      console.warn('❌ Push subscription failed:', err);
    }
  })();
}



function openChat() {
  const chat = document.getElementById("chatContainer");
  if (!chat) return;
  const activeChatToken = getCurrentChatToken();

  const isPhoneDevice = /iPhone|Android.+Mobile|Windows Phone|webOS|BlackBerry|IEMobile|Opera Mini/i
    .test(navigator.userAgent || "");
  if (isPhoneDevice) {
    const fullscreenBtn = document.querySelector('[data-target="fullscreen"]');
    if (
      fullscreenBtn &&
      !(document.fullscreenElement || document.webkitFullscreenElement || window.__appPseudoFullscreenActive === "1")
    ) {
      fullscreenBtn.click();
      chat.dataset.fullscreenActivated = "1";
    }
  }

  const isAlreadyOpen = chat.style.display === "flex";
  if (isAlreadyOpen) return;

  chat.style.display = "flex";


  const chatBody = document.getElementById("chatBodyWrapper");
  const invite = document.getElementById("chatInviteWrapper");
  const back = document.getElementById("chatBackArrow");
  const chatBox = document.getElementById("chatMessages");
  const input = document.getElementById("chatInput");

  if (invite) invite.style.display = "none";
  if (chatBody) {
    chatBody.style.display = "flex";
    chatBody.style.flex = "1";
  }
  if (back) back.style.display = "none";

  if (chatBox) {
    chatBox.style.flex = "1";
    chatBox.style.overflowY = "auto";
    chatBox.style.minHeight = "0";
  }

  if (input) {
    input.style.height = "auto";
    input.style.height = Math.min(input.scrollHeight, 80) + "px";
  }

  // ✅ Mark first open so chatLoadMessages will scroll bottom
  chatFirstOpen = true;

  if (activeChatToken) {
    selectList(activeChatToken);
  }

  startChatPolling();
  updateFooterChatBadge();
  if (activeChatToken && !isDirectMessageToken(activeChatToken)) {
    setupPushSubscription(activeChatToken);
  }
}





function selectList(token, listName = null) {
  const isSameList = getCurrentChatToken() === token;
  window.currentChatToken = token;

  if (!listName) {
    const titleEl = document.getElementById(`list-title-${token}`);
    listName = titleEl ? titleEl.textContent.trim() : "List Chat";
  }

  const header = document.getElementById("chatHeaderTitle");
  if (header) header.textContent = listName;

  const chatMessages = document.getElementById("chatMessages");
  const inviteList   = document.getElementById("chatInviteList");

  if (!isSameList) {
    // 🔄 Only clear when switching to a different chat
    if (chatMessages) {
      chatMessages.innerHTML = `<p style='color:#999;'>${window.translations?.loading_chat || 'Loading chat...'}<\/p>`;
    }
    if (inviteList) {
      inviteList.innerHTML = `<p style='color:#999;'>${window.translations?.loading_members || 'Loading members...'}<\/p>`;
    }
    stopChatPolling(); // ensure no overlap from old list
  }

  chatFirstOpen = true;
  chatLoadMessages();
  loadInlineInviteList();
  fetchUserRole(token);

  if (!isSameList) {
    startChatPolling(); // restart fresh only on new chat
  }
  chatApplyPausedUi(token);
}





function fetchUserRoleXXX(token) {
  fetch(`/getUserRole.php?token=${encodeURIComponent(token)}`)
    .then(res => res.ok ? res.json() : null)
    .then(data => {
      window.currentUserRole = data?.role || "viewer";

      const inputBlock = document.getElementById("inviteInputWrapper");
      if (inputBlock) {
        inputBlock.style.display = ["owner", "admin", "editor"].includes(window.currentUserRole)
          ? "block" : "none";
      }
    })
    .catch(err => {
      console.warn("⚠️ fetchUserRole failed:", err);
      window.currentUserRole = "viewer";
      const inputBlock = document.getElementById("inviteInputWrapper");
      if (inputBlock) inputBlock.style.display = "none";
    });
}


function fetchUserRole(token) {
  const normalizedToken = normalizeChatToken(token);
  fetch(`/getUserRole.php?token=${encodeURIComponent(normalizedToken)}`)
    .then(res => res.ok ? res.json() : null)
    .then(data => {
      window.currentUserRole = data?.role || "viewer";
      window.currentUserListRoleRank = data?.role_rank || 0; // ✅ numeric

      // Show/hide invite UI
      const inputBlock = document.getElementById("inviteInputWrapper");
      if (inputBlock) {
        inputBlock.style.display = window.currentUserListRoleRank >= 80
          ? "block"
          : "none";
      }

      console.log("📋 List role:", window.currentUserRole,
                  "rank:", window.currentUserListRoleRank);
    })
    .catch(err => {
      console.warn("⚠️ fetchUserRole failed:", err);
      window.currentUserRole = "viewer";
      window.currentUserListRoleRank = 0;
      const inputBlock = document.getElementById("inviteInputWrapper");
      if (inputBlock) inputBlock.style.display = "none";
    });
}



 
 
  function openModal(id) {
    document.getElementById(id).style.display = 'flex';
  }

  function closeModal(id) {
    document.getElementById(id).style.display = 'none';
  }

//   function sendInviteXXXX() {
//     const email = document.getElementById("inviteEmail").value;
//     const role = document.getElementById("inviteRole").value;
//     const token = window.currentListToken;

//     fetch("/chatInviteToList.php", {
//       method: "POST",
//       headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
//       body: `token=${encodeURIComponent(token)}&email=${encodeURIComponent(email)}&role=${role}`
//     })
//     .then(res => res.json())
//     .then(data => {
//       alert(data.status === "success" ? "✅ Invite sent" : "❌ " + data.message);
//       closeModal('inviteModal');
//     });
//   }



function sendInvite() {
    
console.log("Chat sendInvite started");

return;
    
  const raw = document.getElementById("inviteEmail").value;
  const email = cleanEmail(raw);   // ✅ sanitize
  const role = document.getElementById("inviteRole").value;
  const token = getCurrentChatToken();

  if (!email) {
    alert("⚠️ Please enter a valid email address.");
    return;
  }

  fetch("/chatInviteToList.php", {
    method: "POST",
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `token=${encodeURIComponent(token)}&email=${encodeURIComponent(email)}&role=${role}`
  })
  .then(res => res.json())
  .then(data => {
    alert(data.status === "success" ? "✅ Invite sent" : "❌ " + data.message);
    closeModal('inviteModal');
  });
}


window.sendInvite = sendInvite;



function submitAccessRequest() {
  console.log("📩 submitAccessRequest called");

  const messageEl = document.getElementById("requestMessage");
  const message = messageEl ? messageEl.value.trim() : "";
  const token = getCurrentChatToken();

  fetch("/requestAccess.php", {
    method: "POST",
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `token=${encodeURIComponent(token)}&message=${encodeURIComponent(message)}`
  })
  .then(res => {
    console.log("🔎 Response status:", res.status);
    return res.json();
  })
  .then(data => {
    console.log("📬 Response data:", data);

    if (data.status === "success") {
      showFlashMessage("✅ Request sent", 3000);

      closeModal('requestModal');

      loadInlineInviteList();

    } else {
      alert("❌ " + (data.message || "Request failed"));
    }
  })
  .catch(err => {
    console.error("❌ submitAccessRequest error:", err);
    alert("⚠️ Could not send access request. Please try again.");
  });
}



    
    // 🔁 Make sure it's available globally (for onclick or inline buttons)
    window.submitAccessRequest = submitAccessRequest;

  

  function openManageAccess() {
    const token = getCurrentChatToken();
    const container = document.getElementById("accessList");
    container.innerHTML = "<p>Loading...</p>";

    fetch(`/getListAccess.php?token=${encodeURIComponent(token)}`)
      .then(res => res.json())
      .then(data => {
        if (!Array.isArray(data)) return container.innerHTML = "<p>Error loading data.</p>";
        container.innerHTML = data.map(entry => `
          <div>
            <strong>${entry.email}</strong> - ${entry.role}
            <select onchange="changeRole('${entry.email}', this.value)">
              <option value="viewer" ${entry.role === 'viewer' ? 'selected' : ''}>Viewer</option>
              <option value="commenter" ${entry.role === 'commenter' ? 'selected' : ''}>Commenter</option>
              <option value="editor" ${entry.role === 'editor' ? 'selected' : ''}>Editor</option>
            </select>
            <button onclick="removeAccess('${entry.email}')">❌ Remove</button>
          </div>
        `).join("");
      });

    openModal("manageAccessModal");
  }

  function changeRole(email, newRole) {
    const token = getCurrentChatToken();
    fetch("/changeAccessRole.php", {
      method: "POST",
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `token=${encodeURIComponent(token)}&email=${encodeURIComponent(email)}&role=${newRole}`
    }).then(res => res.json()).then(data => {
      if (data.status !== "success") alert("❌ " + data.message);
    });
  }

  function removeAccess(email) {
    const token = getCurrentChatToken();
    if (!confirm(`Remove access for ${email}?`)) return;
    fetch("/removeListAccess.php", {
      method: "POST",
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `token=${encodeURIComponent(token)}&email=${encodeURIComponent(email)}`
    }).then(res => res.json()).then(data => {
      if (data.status === "success") {
        openManageAccess();
      } else {
        alert("❌ " + data.message);
      }
    });
  }





function handleLongPress(event, messageId, el) {
  event.stopPropagation();
  let timer = setTimeout(() => {
    showReactionPicker(messageId, el);
  }, 500);

  function cancel() {
    clearTimeout(timer);
    el.removeEventListener("touchend", cancel);
    el.removeEventListener("touchmove", cancel);
    el.removeEventListener("mouseup", cancel);
    el.removeEventListener("mouseleave", cancel);
  }

  el.addEventListener("touchend", cancel);
  el.addEventListener("touchmove", cancel);
  el.addEventListener("mouseup", cancel);
  el.addEventListener("mouseleave", cancel);
}

function toggleTimestamp(el) {
  const meta = el.previousElementSibling;
  if (meta && meta.classList.contains("chat-meta")) {
    meta.style.display = meta.style.display === "none" ? "block" : "none";
  }
}
function toggleEmojiReaction(messageId, emoji, users) {
  const myName = window.SESSION_DISPLAY_NAME || "";
  const hasReacted = users.includes(myName);

  if (hasReacted) {
    if (confirm(`Remove your ${emoji}?`)) {
      reactToMessage(messageId, emoji); // assume toggle logic on server
    }
  } else {
    alert(`${emoji} by: ${users.join(", ")}`);
  }
}



window.lastTapPos = null;
document.addEventListener("click", e => {
  window.lastTapPos = { x: e.clientX + 10, y: e.clientY + 10 };
});
document.addEventListener("touchstart", e => {
  const t = e.touches[0];
  window.lastTapPos = { x: t.clientX + 10, y: t.clientY + 10 };
});





function showReactionPopup(emoji, users) {
  const popup = document.createElement("div");
  popup.className = "reaction-popup";
  popup.style.cssText = `
    position: fixed;
    background: #fff;
    color: #000;
    border: 1px solid #ccc;
    border-radius: 8px;
    padding: 4px 8px;
    font-size: 12px;
    z-index: 9999;
    box-shadow: 0 4px 10px rgba(0,0,0,0.2);
    max-width: 250px;
  `;

  popup.innerHTML = `
    <div style="margin-bottom: 4px; font-weight: bold;">${emoji} Reactions</div>
    <div>
      ${users.map(name => `<div style="padding: 2px 0;">${escapeHTML(name)}</div>`).join("")}
    </div>
  `;

  document.body.appendChild(popup);

  // Auto-close on outside click
  document.addEventListener("click", function closePopup(e) {
    if (!popup.contains(e.target)) {
      popup.remove();
      document.removeEventListener("click", closePopup);
    }
  });

  // Position near last interaction
  const pos = window.lastTapPos || { x: window.innerWidth / 2, y: window.innerHeight / 2 };
  popup.style.left = `${pos.x}px`;
  popup.style.top = `${pos.y}px`;
}






// ----------


// let lastLoadedToken = null;



// async function chatLoadMessages() {
//   const token = window.currentListToken;
//   if (!token) return;

//   const chatBox = document.getElementById("chatMessages");
//   if (!chatBox) return;

//   const isNewList = token !== lastLoadedToken;
//   if (isNewList) {
//     chatBox.innerHTML = "";
//     lastLoadedToken = token;
//   }

//   let res;
//   try {
//     res = await fetch(`/chatLoadMessage.php?token=${encodeURIComponent(token)}`);
//   } catch (err) {
//     console.error("❌ Fetch failed:", err);
//     return;
//   }

//   if (!res.ok) {
//     if (res.status === 403) {
//       console.warn("🚫 User blocked from chat. Stopping polling.");
//       stopChatPolling();
//     }
//     return;
//   }

//   const messages = await res.json();
//   if (!Array.isArray(messages)) return;

//   // ✅ wipe placeholder on first open
//   if (chatFirstOpen) {
//     chatBox.innerHTML = "";
//   }

//   const badge = document.getElementById("newMessageBadge");
//   const key = await getKeyForList(token);
//   const existingIds = new Set(
//     [...chatBox.querySelectorAll(".chat-bubble")].map(el => el.dataset.id)
//   );

//   let newMessageAdded = false;

//   for (const m of messages) {
//     const existing = chatBox.querySelector(`.chat-bubble[data-id="${m.id}"]`);
//     if (existing) {
//       // ✅ update reactions
//       const detailed = m.reactions_detailed || {};
//       const reactionsHtml = m.reactions?.length
//         ? m.reactions.map(r => `
//             <span class="reaction-count"
//                   title="${escapeHTML((detailed[r.emoji] || []).join(", "))}"
//                   onclick='event.stopPropagation(); showReactionPopup("${escapeHTML(r.emoji)}", ${JSON.stringify(detailed[r.emoji] || [])})'>
//               ${r.emoji} ${r.count}
//             </span>`
//           ).join("")
//         : "";

//       const slot = existing.closest(".chat-meta-wrapper").nextElementSibling;
//       if (slot) slot.innerHTML = reactionsHtml;
//       continue;
//     }

//     // 🔑 decrypt
//     let decryptedText = m.message;
//     try {
//       if (isProbablyEncrypted(m.message)) {
//         decryptedText = await decryptMessage(m.message, key);
//       }
//     } catch (err) {
//       console.warn("🔓 Decryption failed:", err);
//     }

//     const messageText = linkifyChatMessage(
//       escapeHTML(decryptedText).replace(/\n/g, "<br>")
//     );

//     const isMine = m.username === window.SESSION_USERNAME;
//     const createdAt = new Date(m.created_at);
//     const dateTime =
//       `${createdAt.getDate()}.${createdAt.getMonth()+1}.${createdAt.getFullYear()} ` +
//       `${createdAt.getHours().toString().padStart(2,"0")}:${createdAt.getMinutes().toString().padStart(2,"0")}`;
//     const displayName = m.display_name || m.username;

//     const detailed = m.reactions_detailed || {};
//     const reactionsHtml = m.reactions?.length
//       ? m.reactions.map(r => `
//           <span class="reaction-count"
//                 title="${escapeHTML((detailed[r.emoji] || []).join(", "))}"
//                 onclick='event.stopPropagation(); showReactionPopup("${escapeHTML(r.emoji)}", ${JSON.stringify(detailed[r.emoji] || [])})'>
//             ${r.emoji} ${r.count}
//           </span>`
//         ).join("")
//       : "";

//     const wrapper = document.createElement("div");
//     wrapper.innerHTML = `
//       <div style="display:flex;flex-direction:${isMine ? "row-reverse":"row"};align-items:flex-start;gap:6px;margin-bottom:12px;">
//         <img src="${escapeHTML(m.avatar_url || "/default-avatar.png")}" class="chat-avatar ${isMine ? "me":"user"}" />
//         <div style="display:flex;flex-direction:column;align-items:${isMine ? "flex-end":"flex-start"};">
//           <div class="chat-meta-wrapper" style="display:flex;flex-direction:column;align-items:${isMine ? "flex-end":"flex-start"};">
//             <div class="chat-timestamp ${isMine ? "me":"user"}">${escapeHTML(dateTime)}</div>
//             <div class="chat-bubble ${isMine ? "me":"user"}" data-id="${m.id}"
//                  onclick="showReactionPicker('${m.id}', this)"
//                  ontouchstart="handleLongPress(event, '${m.id}', this)">
//               <div class="chat-user">${escapeHTML(displayName)}</div>
//               <div class="chat-text">${messageText}</div>
//             </div>
//           </div>
//           <div class="chat-reactions-slot" style="justify-content:${isMine ? "flex-end" : "flex-start"};">
//             ${reactionsHtml}
//           </div>
//         </div>
//       </div>
//     `;
//     chatBox.appendChild(wrapper.firstElementChild);

//     updateFooterChatBadge();
//     newMessageAdded = true;
//   }

//   // ✅ Scroll logic
//   const nearBottom = chatBox.scrollHeight - chatBox.scrollTop - chatBox.clientHeight < 80;
//   if (isNewList || chatFirstOpen || (newMessageAdded && nearBottom)) {
//     chatBox.scrollTop = chatBox.scrollHeight;   // force bottom for new list or first load
//   }

//   if (newMessageAdded && !nearBottom && badge) {
//     badge.style.display = "block";
//     badge.onclick = () => {
//       chatBox.scrollTop = chatBox.scrollHeight;
//       badge.style.display = "none";
//     };
//   } else if (badge) {
//     badge.style.display = "none";
//   }

//   chatFirstOpen = false;
// }


function updateReactionsForMessage(m) {
  const row = document.querySelector(`.chat-message-row[data-id="${m.id}"]`);
  if (!row) return;

  const detailed = m.reactions_detailed || {};
  const reactionsHtml = m.reactions?.length
    ? m.reactions.map(r => `
        <span class="reaction-count"
              title="${escapeHTML((detailed[r.emoji] || []).join(", "))}"
              onclick='event.stopPropagation(); showReactionPopup("${escapeHTML(r.emoji)}", ${JSON.stringify(detailed[r.emoji] || [])})'>
          ${r.emoji} ${r.count}
        </span>`
      ).join("")
    : "";

  const slot = row.querySelector(".chat-reactions-slot");
  if (slot) slot.innerHTML = reactionsHtml;
}


function scrollChatToBottom(smooth = false) {
  const chatBox = document.getElementById("chatMessages");
  if (!chatBox) return;

  // Wait for DOM update before scrolling
  requestAnimationFrame(() => {
    if (smooth && "scrollTo" in chatBox) {
      chatBox.scrollTo({
        top: chatBox.scrollHeight,
        behavior: "smooth"
      });
    } else {
      chatBox.scrollTop = chatBox.scrollHeight;
    }
  });
}


function isNearBottom(chatBox, threshold = 150) {
  return chatBox.scrollHeight - chatBox.scrollTop - chatBox.clientHeight <= threshold;
}

const CHAT_POLL_MARKER_RE = /^\s*\[\[poll:(\d+)\]\](?:\s+([\s\S]*))?\s*$/i;
const chatPollCache = new Map();

async function chatFetchPollById(pollId) {
  const id = Number(pollId || 0);
  if (!id) return null;
  const cached = chatPollCache.get(id);
  if (cached && (Date.now() - cached.ts) < 8000) {
    return cached.poll;
  }
  try {
    const res = await fetch(`/ep_polls.php?poll_id=${id}`, { credentials: "same-origin", cache: "no-store" });
    const data = await res.json();
    const poll = (data && data.status === "OK" && Array.isArray(data.polls) && data.polls.length)
      ? data.polls[0]
      : null;
    if (poll) {
      chatPollCache.set(id, { ts: Date.now(), poll });
    }
    return poll;
  } catch (err) {
    console.warn("chatFetchPollById failed", err);
    return null;
  }
}

function chatRenderPollVoterAvatar(voter) {
  const displayName = voter.display_name || voter.username || "Member";
  const avatarUrl = window.twResolveAvatarUrl(voter, displayName);
  return `<span class="chat-poll-voter" title="${escapeHTML(displayName)}">
    <img src="${escapeHTML(avatarUrl)}" alt="${escapeHTML(displayName)}"
      data-avatar-seed="${escapeHTML(String(voter.member_id || voter.username || displayName))}"
      data-avatar-name="${escapeHTML(displayName)}"
      onerror="twHandleAvatarError(this)">
  </span>`;
}

function chatPollParticipantCount(poll) {
  const participants = new Set();
  (poll.options || []).forEach((option) => {
    (option.voters || []).forEach((voter) => {
      const id = Number(voter.member_id);
      if (id > 0) participants.add(id);
    });
  });
  return participants.size;
}

function chatRenderPollCard(poll, noteText = "") {
  const participantCount = chatPollParticipantCount(poll);
  const ownerLabel = String(poll?.owner_display_name || "").trim();
  const noteHtml = noteText ? `<div class="chat-poll-note">${linkifyChatMessage(escapeHTML(noteText).replace(/\n/g, "<br>"))}</div>` : "";
  const optionsHtml = (poll.options || []).map((option) => {
    const mine = (poll.my_option_ids || []).includes(Number(option.id));
    const voters = Array.isArray(option.voters) ? option.voters : [];
    const count = Number(option.vote_count || voters.length || 0);
    const pct = participantCount > 0 ? Math.round((count / participantCount) * 100) : 0;
    const preview = voters.slice(0, 5);
    const extra = Math.max(0, voters.length - preview.length);
    return `
      <button class="chat-poll-option${mine ? " active" : ""}" type="button"
              data-role="chat-poll-option"
              data-poll-id="${Number(poll.id)}"
              data-option-id="${Number(option.id)}">
        <span class="chat-poll-option-line">
          <span class="chat-poll-option-text">${escapeHTML(option.option_text || "Option")}</span>
          <span class="chat-poll-option-meta">
            <span class="chat-poll-mini-voters">
              ${preview.map((v) => chatRenderPollVoterAvatar(v)).join("")}
              ${extra > 0 ? `<span class="chat-poll-voter chat-poll-voter-count">+${extra}</span>` : ""}
            </span>
            <span class="chat-poll-option-stats">${count}</span>
          </span>
        </span>
        <span class="chat-poll-progress"><span style="width:${Math.max(0, Math.min(100, pct))}%"></span></span>
      </button>
    `;
  }).join("");
  return `
    ${noteHtml}
    <div class="chat-poll-card" data-poll-id="${Number(poll.id)}">
      <div class="chat-poll-head"><strong>Poll: ${escapeHTML(ownerLabel)}</strong></div>
      <div class="chat-poll-question">${escapeHTML(poll.question || "Poll")}</div>
      <div class="chat-poll-options">${optionsHtml}</div>
      <div class="chat-poll-sub">${participantCount > 0 ? `${participantCount} vote${participantCount === 1 ? "" : "s"}` : "No votes yet"}</div>
    </div>
  `;
}

async function chatVotePollOption(pollId, optionId) {
  const poll = await chatFetchPollById(pollId);
  if (!poll) return;
  const current = new Set((poll.my_option_ids || []).map((id) => Number(id)));
  const opt = Number(optionId || 0);
  if (!opt) return;
  let optionIds = [];
  if (poll.allow_multiple) {
    if (current.has(opt)) current.delete(opt); else current.add(opt);
    optionIds = Array.from(current);
  } else {
    optionIds = [opt];
  }
  let res;
  try {
    res = await fetch("/ep_polls.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "same-origin",
      body: JSON.stringify({
        action: "vote",
        poll_id: Number(pollId),
        option_ids: optionIds
      })
    });
  } catch (err) {
    console.warn("chatVotePollOption network error", err);
    alert("Unable to reach poll service.");
    return;
  }
  if (!res.ok) {
    alert("Unable to save vote right now.");
    return;
  }
  let data;
  try {
    data = await res.json();
  } catch (err) {
    console.warn("chatVotePollOption invalid JSON", err);
    alert("Unexpected poll response.");
    return;
  }
  if (data.status !== "OK") {
    alert(data.message || "Unable to save vote");
    return;
  }
  chatPollCache.delete(Number(pollId));
  await chatLoadMessages();
}




/**
 * Renders a single chat message as a DOM element
 */
async function renderChatMessage(m, key) {
  let decryptedText = m.message;
  try {
    if (isProbablyEncrypted(m.message)) {
      decryptedText = await decryptMessage(m.message, key); // ✅ now a string
    }
  } catch (err) {
    console.warn("🔓 Decryption failed:", err);
  }

  const pollMatch = CHAT_POLL_MARKER_RE.exec(decryptedText || "");
  let messageText = linkifyChatMessage(
    escapeHTML(decryptedText).replace(/\n/g, "<br>")
  );
  if (pollMatch) {
    const pollId = Number(pollMatch[1] || 0);
    const note = (pollMatch[2] || "").trim();
    const poll = await chatFetchPollById(pollId);
    if (poll) {
      messageText = chatRenderPollCard(poll, note);
    } else {
      messageText = `<div class="chat-poll-missing">Poll unavailable.</div>`;
    }
  }

  const isMine = m.username === window.SESSION_USERNAME;
  const canDelete = !!m.can_delete;
  const isSystem = (m.username || "").toLowerCase() === "system";
  const createdAt = new Date(m.created_at);
  const dateTime =
    `${createdAt.getDate()}.${createdAt.getMonth()+1}.${createdAt.getFullYear()} ` +
    `${createdAt.getHours().toString().padStart(2,"0")}:${createdAt.getMinutes().toString().padStart(2,"0")}`;
  const displayName = m.display_name || m.username;
  const avatarUrl = window.twResolveAvatarUrl(m, displayName);

  if (isSystem) {
    const role = (window.currentUserRole || "").toLowerCase();
    const canSeeSystem = role === "owner" || role === "admin";
    if (!canSeeSystem) return null;

    const systemWrapper = document.createElement("div");
    systemWrapper.innerHTML = `
      <div class="chat-system-line" data-id="${m.id}">
        <span class="chat-system-text">${messageText}</span>
        <span class="chat-system-time">${escapeHTML(dateTime)}</span>
      </div>
    `;
    return systemWrapper.firstElementChild;
  }

  const detailed = m.reactions_detailed || {};
  const reactionsHtml = m.reactions?.length
    ? m.reactions.map(r => `
        <span class="reaction-count"
              title="${escapeHTML((detailed[r.emoji] || []).join(", "))}"
              onclick='event.stopPropagation(); showReactionPopup("${escapeHTML(r.emoji)}", ${JSON.stringify(detailed[r.emoji] || [])})'>
          ${r.emoji} ${r.count}
        </span>`
      ).join("")
    : "";

  if (pollMatch) {
    const pollId = Number(pollMatch[1] || 0);
    const note = (pollMatch[2] || "").trim();
    const poll = await chatFetchPollById(pollId);
    const pollHtml = poll
      ? chatRenderPollCard(poll, note)
      : `<div class="chat-poll-missing">Poll unavailable.</div>`;
    const wrapper = document.createElement("div");
    wrapper.innerHTML = `
      <div class="chat-message-row ${isMine ? "me" : "user"} chat-poll-row" data-id="${m.id}">
        <img src="${escapeHTML(avatarUrl)}"
             class="chat-avatar ${isMine ? "me":"user"}"
             alt="${escapeHTML(displayName)}"
             data-avatar-seed="${escapeHTML(m.username || m.email || displayName)}"
             data-avatar-name="${escapeHTML(displayName)}"
             onerror="twHandleAvatarError(this)" />
        <div class="chat-message-col ${isMine ? "me" : "user"}">
          <div class="chat-meta-wrapper ${isMine ? "me" : "user"}">
            <div class="chat-meta-line ${isMine ? "me":"user"}">
              <div class="chat-timestamp ${isMine ? "me":"user"}">${escapeHTML(dateTime)}</div>
              ${canDelete ? `<button type="button" class="chat-delete-message-btn" data-role="chat-delete-message" data-message-id="${m.id}" title="Delete message">Delete</button>` : ""}
            </div>
            <div class="chat-poll-message-shell">
              <div class="chat-user">${escapeHTML(displayName)}</div>
              ${pollHtml}
            </div>
          </div>
          <div class="chat-reactions-slot" style="justify-content:${isMine ? "flex-end" : "flex-start"};">
            ${reactionsHtml}
          </div>
        </div>
      </div>
    `;
    return wrapper.firstElementChild;
  }

  const wrapper = document.createElement("div");
  wrapper.innerHTML = `
    <div class="chat-message-row ${isMine ? "me" : "user"}" data-id="${m.id}">
      <img src="${escapeHTML(avatarUrl)}"
           class="chat-avatar ${isMine ? "me":"user"}"
           alt="${escapeHTML(displayName)}"
           data-avatar-seed="${escapeHTML(m.username || m.email || displayName)}"
           data-avatar-name="${escapeHTML(displayName)}"
           onerror="twHandleAvatarError(this)" />
      <div class="chat-message-col ${isMine ? "me" : "user"}">
        <div class="chat-meta-wrapper ${isMine ? "me" : "user"}">
          <div class="chat-meta-line ${isMine ? "me":"user"}">
            <div class="chat-timestamp ${isMine ? "me":"user"}">${escapeHTML(dateTime)}</div>
            ${canDelete ? `<button type="button" class="chat-delete-message-btn" data-role="chat-delete-message" data-message-id="${m.id}" title="Delete message">Delete</button>` : ""}
          </div>
          <div class="chat-bubble ${isMine ? "me":"user"}" data-id="${m.id}"
               onclick="showReactionPicker('${m.id}', this)"
               ontouchstart="handleLongPress(event, '${m.id}', this)">
            <div class="chat-user">${escapeHTML(displayName)}</div>
            <div class="chat-text">${messageText}</div>
          </div>
        </div>
        <div class="chat-reactions-slot" style="justify-content:${isMine ? "flex-end" : "flex-start"};">
          ${reactionsHtml}
        </div>
      </div>
    </div>
  `;
  return wrapper.firstElementChild;
}

async function chatDeleteMessage(messageId) {
  const id = Number(messageId || 0);
  if (!id) return;
  if (!confirm("Delete this message?")) return;
  let res;
  try {
    res = await fetch("/chatDeleteMessage.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      credentials: "same-origin",
      body: `id=${encodeURIComponent(id)}`
    });
  } catch (err) {
    alert("Unable to reach chat service.");
    return;
  }
  let data = null;
  try {
    data = await res.json();
  } catch (err) {
    // ignore and show generic error below
  }
  if (!res.ok || !data || data.status !== "OK") {
    alert((data && data.message) || "Unable to delete message.");
    return;
  }
  await chatLoadMessages();
}


let lastLoadedToken = null;
window.pendingChatMessageId = window.pendingChatMessageId || null;
let lastReadTouchAt = 0;

async function chatLoadMessages() {
  const token = getCurrentChatToken();
  if (!token) return;
  console.log("Chat token", token)

  const chatBox = document.getElementById("chatMessages");
  if (!chatBox) return;

  const isNewList = token !== lastLoadedToken;
  if (isNewList) {
    chatBox.innerHTML = "";
    lastLoadedToken = token;
  }

  let res;
  try {
    if (isDirectMessageToken(token)) {
      const threadId = getDirectMessageThreadId(token);
      res = await fetch(`/chatThreadLoadMessages.php?thread_id=${encodeURIComponent(threadId)}`);
    } else {
      res = await fetch(`/chatLoadMessage.php?token=${encodeURIComponent(token)}`);
    }
  } catch (err) {
    console.error("❌ Fetch failed:", err);
    return;
  }

  if (!res.ok) {
    if (res.status === 403) stopChatPolling();
    return;
  }

//   const messages = await res.json();
//   if (!Array.isArray(messages)) return;
    
    // Now getting the chat name from the json
    const data = await res.json();
    if (!data) return;
    
    // ✅ ALWAYS set header, even if no messages
    if (data.meta?.chat_name) {
      const header = document.getElementById("chatHeaderTitle");
      if (header) header.textContent = data.meta.chat_name;
    }
    
    if (!Array.isArray(data.messages)) return;
    
    const messages = data.messages;




  if (chatFirstOpen) chatBox.innerHTML = "";

  const badge = document.getElementById("newMessageBadge");
  const key = await getKeyForList(token);

  // Keep only one rendered node per message id (latest one in DOM).
  const renderedNodes = [...chatBox.querySelectorAll(".chat-message-row[data-id], .chat-system-line[data-id]")];
  const seenRenderedIds = new Set();
  for (let i = renderedNodes.length - 1; i >= 0; i -= 1) {
    const node = renderedNodes[i];
    const id = String(node.dataset.id || "");
    if (!id) continue;
    if (seenRenderedIds.has(id)) {
      node.remove();
      continue;
    }
    seenRenderedIds.add(id);
  }

  const existingIds = new Set(
    [...chatBox.querySelectorAll(".chat-message-row[data-id], .chat-system-line[data-id]")]
      .map(el => el.dataset.id)
      .filter(Boolean)
  );
  const serverIds = new Set(messages.map((m) => String(m.id)));

  // Remove rows no longer present on server (e.g. deleted messages).
  [...chatBox.querySelectorAll(".chat-message-row[data-id], .chat-system-line[data-id]")].forEach((node) => {
    const id = String(node.dataset.id || "");
    if (!id) return;
    if (!serverIds.has(id)) node.remove();
  });

  let newMessageAdded = false;

    for (const m of messages) {
      const idStr = String(m.id);
      if (existingIds.has(idStr)) {
        const existingPollRow = chatBox.querySelector(`.chat-message-row.chat-poll-row[data-id="${idStr}"]`);
        if (existingPollRow) {
          const refreshed = await renderChatMessage(m, key);
          if (refreshed) existingPollRow.replaceWith(refreshed);
        }
        updateReactionsForMessage(m);
        continue;
      }

      const node = await renderChatMessage(m, key); // ✅ await here
      if (!node) continue;
      chatBox.appendChild(node);
      newMessageAdded = true;
    }



    
    updateFooterChatBadge();

    const pendingId = Number(window.pendingChatMessageId || 0);
    if (pendingId > 0) {
      const targetNode = chatBox.querySelector(`.chat-message-row[data-id="${pendingId}"], .chat-system-line[data-id="${pendingId}"]`);
      if (targetNode) {
        targetNode.scrollIntoView({ behavior: "smooth", block: "center" });
        targetNode.classList.add("chat-message-focus");
        setTimeout(() => targetNode.classList.remove("chat-message-focus"), 1800);
        window.pendingChatMessageId = null;
      }
    }
    
    const nearBottom = isNearBottom(chatBox);  // ✅ more forgiving
    
    if (isNewList || chatFirstOpen) {
      scrollChatToBottom();
    } else if (newMessageAdded && nearBottom) {
      scrollChatToBottom();
    } else if (newMessageAdded && !nearBottom && badge) {
      badge.style.display = "block";
      badge.onclick = () => {
        scrollChatToBottom(true);
        badge.style.display = "none";
      };
    } else if (badge) {
      badge.style.display = "none";
    }



    
    chatFirstOpen = false;

    // Keep active chat marked as read while user is viewing it.
    const now = Date.now();
    if (now - lastReadTouchAt > 5000) {
      lastReadTouchAt = now;
      if (isDirectMessageToken(token)) {
        const threadId = getDirectMessageThreadId(token);
        if (threadId > 0) {
          fetch("/chatThreadMarkRead.php", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: new URLSearchParams({ thread_id: threadId })
          }).catch(() => {});
        }
      } else if (token) {
        fetch("/updateChatTimestamp.php", {
          method: "POST",
          headers: { "Content-Type": "application/x-www-form-urlencoded" },
          body: new URLSearchParams({ listToken: token })
        }).catch(() => {});
      }
    }

}












  
// function escapeHTML(str) {
//   return str
//     .replace(/&/g, "&amp;")
//     .replace(/</g, "&lt;")
//     .replace(/>/g, "&gt;")
//     .replace(/"/g, "&quot;")
//     .replace(/'/g, "&#039;");
// }



function escapeHTML(str) {
  if (typeof str !== "string") {
    // fallback: stringify non-strings safely
    return str == null ? "" : String(str);
  }
  return str
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}






async function chatSendMessage() {
  const token = getCurrentChatToken();
  if (!token) return;
  const input = document.getElementById("chatInput");
  const msg = input.value.trim();

  if (isDirectMessageToken(token) && chatIsPaused(token)) {
    alert("Chat is paused. Resume chat to send messages.");
    return;
  }

  
  if (!msg) return;

  try {
    let payloadMessage = msg;
    const shouldAlert = payloadMessage.includes("!") || payloadMessage.includes("🔔");
    const pollCmdMatch = /^\/poll\s+(\d+)(?:\s+([\s\S]+))?$/i.exec(msg);
    if (pollCmdMatch) {
      const pollId = Number(pollCmdMatch[1] || 0);
      const note = (pollCmdMatch[2] || "").trim();
      if (!pollId) {
        alert("Usage: /poll <pollId> [optional note]");
        return;
      }
      payloadMessage = `[[poll:${pollId}]]${note ? ` ${note}` : ""}`;
    }
    const key = await getKeyForList(token);
    const encrypted = await encryptMessage(payloadMessage, key);


    const isDm = isDirectMessageToken(token);
    const endpoint = isDm ? "/chatThreadSendMessage.php" : "/chatSendMessage.php";
    const env = (location.hostname || "textwhisper.com").replace(/^www\./, "");
    const body = isDm
      ? `thread_id=${encodeURIComponent(getDirectMessageThreadId(token))}&message=${encodeURIComponent(encrypted)}&alert=${shouldAlert ? 1 : 0}&env=${encodeURIComponent(env)}`
      : `token=${encodeURIComponent(token)}&message=${encodeURIComponent(encrypted)}&alert=${shouldAlert ? 1 : 0}&env=${encodeURIComponent(env)}`;
    const res = await fetch(endpoint, {
      method: "POST",
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body
    });

    const data = await res.json();
    if (data.status === "success") {
      input.value = "";
      input.dispatchEvent(new Event("input", { bubbles: true }));
      chatLoadMessages();
    } else {
      alert("❌ " + data.message);
    }
  } catch (err) {
    console.error("❌ Failed to encrypt or send:", err);
    alert("❌ Encryption error. Message not sent.");
  }
}


  
//-------------  


function toggleChat() {
  const chat = document.getElementById("chatContainer");
  const isOpening = chat.style.display === "none";

  chat.style.display = isOpening ? "block" : "none";

  if (isOpening) {
    closeChatHeaderMenu();
    chatLoadMessages();
    startChatPolling();
  } else {
    closeChatHeaderMenu();
    stopChatPolling();
    // Optionally clear the chat header title
    document.getElementById("chatHeaderTitle").textContent = "List Chat";
    if (chat.dataset.fullscreenActivated === "1") {
      const fullscreenBtn = document.querySelector('[data-target="fullscreen"]');
      if (
        fullscreenBtn &&
        (document.fullscreenElement || document.webkitFullscreenElement || window.__appPseudoFullscreenActive === "1")
      ) fullscreenBtn.click();
      delete chat.dataset.fullscreenActivated;
    }
    window.restoreTapTrayMobileSidebar?.();
  }
}

  


function normalizeChatToken(token) {
  if (isDirectMessageToken(token)) {
    return token;
  }
  if (token && token.startsWith("invited-")) {
    // invited-threstir → threstir
    return token.substring("invited-".length);
  }
  return token;
}

function isDirectMessageToken(token) {
  return typeof token === "string" && /^dm:\d+$/.test(token);
}

function getDirectMessageThreadId(token) {
  if (!isDirectMessageToken(token)) return 0;
  return Number(token.split(":")[1] || 0);
}

function getCurrentChatToken() {
  return normalizeChatToken(window.currentChatToken || window.currentListToken || "");
}

const CHAT_PAUSED_THREADS_KEY = "tw_chat_paused_threads_v1";

function chatGetPausedThreadsMap() {
  try {
    const raw = localStorage.getItem(CHAT_PAUSED_THREADS_KEY);
    const parsed = raw ? JSON.parse(raw) : {};
    return parsed && typeof parsed === "object" ? parsed : {};
  } catch (err) {
    return {};
  }
}

function chatIsPaused(token) {
  const t = normalizeChatToken(token || getCurrentChatToken());
  if (!t) return false;
  const map = chatGetPausedThreadsMap();
  return map[t] === 1;
}

function chatSetPaused(token, paused) {
  const t = normalizeChatToken(token || getCurrentChatToken());
  if (!t) return;
  const map = chatGetPausedThreadsMap();
  if (paused) map[t] = 1;
  else delete map[t];
  try {
    localStorage.setItem(CHAT_PAUSED_THREADS_KEY, JSON.stringify(map));
  } catch (err) {}
}

function chatApplyPausedUi(token) {
  const t = normalizeChatToken(token || getCurrentChatToken());
  const paused = isDirectMessageToken(t) && chatIsPaused(t);

  const input = document.getElementById("chatInput");
  if (input) {
    if (!input.dataset.normalPlaceholder) {
      input.dataset.normalPlaceholder = input.placeholder || "Type a message...";
    }
    input.disabled = paused;
    input.placeholder = paused
      ? "Chat is paused. Resume to send messages."
      : (input.dataset.normalPlaceholder || "Type a message...");
  }

  const sendBtn = document.querySelector("#chatInputWrapper .send-button");
  const pollBtn = document.getElementById("chatPollSelectBtn");
  const bellBtn = document.getElementById("emojiToggleBtn");
  [sendBtn, pollBtn, bellBtn].forEach((btn) => {
    if (!btn) return;
    btn.disabled = paused;
    btn.style.opacity = paused ? "0.55" : "";
    btn.style.pointerEvents = paused ? "none" : "";
  });
}

function closeChatPollSelector() {
  const existing = document.getElementById("chatPollSelector");
  if (existing) existing.remove();
}

async function chatSelectPoll(eventObj) {
  if (eventObj) {
    eventObj.preventDefault();
    eventObj.stopPropagation();
  }
  const token = getCurrentChatToken();
  if (!token) {
    alert("Open a list chat first.");
    return;
  }

  closeChatPollSelector();

  const fetchJson = async (url) => {
    try {
      const res = await fetch(url, { credentials: "same-origin", cache: "no-store" });
      return await res.json();
    } catch {
      return null;
    }
  };

  const [eventData, chatData] = await Promise.all([
    fetchJson(`/ep_polls.php?owner=${encodeURIComponent(token)}`),
    fetchJson(`/ep_polls.php?source=chat&list_token=${encodeURIComponent(token)}`)
  ]);

  const merged = [];
  const seen = new Set();
  const pushPoll = (poll, label) => {
    const id = Number(poll?.id || 0);
    if (!id || seen.has(id)) return;
    seen.add(id);
    const ownerName = String(poll?.owner_display_name || "").trim();
    merged.push({
      id,
      question: String(poll.question || "Poll"),
      owner: ownerName,
      label
    });
  };

  (eventData?.polls || []).forEach((poll) => pushPoll(poll, "Event"));
  (chatData?.polls || []).forEach((poll) => pushPoll(poll, "Chat"));

  if (!merged.length) {
    alert("No polls available for this chat.");
    return;
  }

  const selector = document.createElement("div");
  selector.id = "chatPollSelector";
  selector.className = "inline-chatlist-selector chat-poll-selector";
  selector.innerHTML = `
    <div class="chat-poll-selector-head">Select poll to share</div>
    ${merged.map((poll) => `
      <button type="button" class="chat-poll-selector-item" data-poll-id="${poll.id}">
        <span class="chat-poll-selector-id">#${poll.id}</span>
        <span class="chat-poll-selector-question-wrap">
          <span class="chat-poll-selector-question">${escapeHTML(poll.question)}</span>
          <span class="chat-poll-selector-owner">Owner: ${escapeHTML(poll.owner)}</span>
        </span>
        <span class="chat-poll-selector-type">${poll.label}</span>
      </button>
    `).join("")}
  `;
  document.body.appendChild(selector);

  const btn = document.getElementById("chatPollSelectBtn");
  const rect = btn
    ? btn.getBoundingClientRect()
    : { left: window.innerWidth - 260, top: window.innerHeight - 220, bottom: window.innerHeight - 180 };
  selector.style.left = `${Math.max(8, Math.min(rect.left, window.innerWidth - selector.offsetWidth - 8))}px`;
  selector.style.top = `${Math.max(8, rect.top - selector.offsetHeight - 8)}px`;

  selector.addEventListener("click", (ev) => {
    const item = ev.target.closest(".chat-poll-selector-item");
    if (!item) return;
    const pollId = Number(item.dataset.pollId || 0);
    if (!pollId) return;
    const input = document.getElementById("chatInput");
    if (!input) return;
    input.value = `/poll ${pollId}`;
    input.focus();
    input.dispatchEvent(new Event("input", { bubbles: true }));
    closeChatPollSelector();
  });

  setTimeout(() => {
    document.addEventListener("click", function closeOnOutside(ev) {
      if (!selector.contains(ev.target) && ev.target.id !== "chatPollSelectBtn") {
        closeChatPollSelector();
        document.removeEventListener("click", closeOnOutside);
      }
    });
  }, 0);
}





function openChatFromMenu(token) {
  if (!token) {
    console.warn("❌ openChatFromMenu called with no token");
    return;
  }
  
    token = normalizeChatToken(token);

  console.log("💬 Opening chat for token:", token);
  const listNameEl = document.getElementById(`list-title-${token}`);
  const listName = listNameEl ? listNameEl.textContent.trim() : "List Chat";

  window.currentChatToken = token;
  
  //zero the badge unconditonally
const badge = document.getElementById(`chat-unread-${token}`);
if (badge) {
  badge.textContent = "0";
  badge.classList.remove("unread");
}




  const header = document.getElementById("chatHeaderTitle");
  if (header) header.textContent = listName;

  let readReq;
  if (isDirectMessageToken(token)) {
    readReq = fetch('/chatThreadMarkRead.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ thread_id: getDirectMessageThreadId(token) })
    });
  } else {
    readReq = fetch('/updateChatTimestamp.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ listToken: token })
    });
  }
  Promise.resolve(readReq).finally(() => {
    updateFooterChatBadge();
  });

  chatLoadMessages();
  loadInlineInviteList();
  fetchUserRole(token);
  chatApplyPausedUi(token);
  openChat();
}




function openChatFromCurrentList() {
  const previousToken = getCurrentChatToken();

  // Try to get the new token from expanded group
  const expanded = document.querySelector('.group-item.active-list');
  const token = expanded?.getAttribute('data-group') || null;

  // ✅ Update read timestamp for previous token (if any)
  if (previousToken) {
    fetch('/updateChatTimestamp.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ listToken: previousToken })
    });
  }

  // ❌ Abort if no new token to proceed with
  if (!token) return;

  // ✅ Update read timestamp for new token as well
  fetch('/updateChatTimestamp.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ listToken: token })
  });

  // ✅ Set current list and open chat
  window.currentChatToken = token;

  const nameEl = document.getElementById(`list-title-${token}`);
  const listName = nameEl ? nameEl.textContent.trim() : "List Chat";

  selectList(token, listName);
  chatApplyPausedUi(token);
  openChat();
}





function sendInlineInvite() {
  const raw = document.getElementById("inlineInviteEmail").value;
  const email = cleanEmail(raw);   // ✅ sanitize
  const role = document.getElementById("inlineInviteRole").value;
  const token = getCurrentChatToken();

  if (!email) {
    alert("⚠️ Please enter a valid email address.");
    return;
  }

  fetch("/chatInviteToList.php", {
    method: "POST",
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `token=${encodeURIComponent(token)}&email=${encodeURIComponent(email)}&role=${role}`
  })
  .then(res => res.json())
  .then(data => {
    alert(data.status === "success" ? "✅ Invite sent" : "❌ " + data.message);
    loadInlineInviteList();
    document.getElementById("inlineInviteEmail").value = "";
  });
}





/**
 * loadInlineInviteList()
 * -----------------------
 * Renders the member management panel based on the user's role.
 *
 * 1. Shows the invite input if role is owner/admin/editor.
 * 2. If role is "none" or "denied" → shows access request prompt.
 * 3. If role is "request" → shows only the user's entry + message.
 * 4. Otherwise → loads and displays full access list with editable roles.
 *
 * Also handles role-based visibility, message display, sorting (requests first), 
 * and safe fallbacks.
 */
function loadInlineInviteList() {

  const token = getCurrentChatToken();
  const container = document.getElementById("chatInviteList");
  const inviteBlock = document.getElementById("inviteInputWrapper");
  const inviteWrapper = document.getElementById("chatInviteWrapper");

  if (!container) return;

  container.classList.remove("thread-settings-mode");
  if (inviteWrapper) inviteWrapper.classList.remove("thread-settings-mode");

  // Hide invite input block by default
  if (inviteBlock) inviteBlock.style.display = "none";
  if (isDirectMessageToken(token)) {
    container.classList.add("thread-settings-mode");
    if (inviteWrapper) inviteWrapper.classList.add("thread-settings-mode");
    const threadId = getDirectMessageThreadId(token);
    const currentName = escapeHTML((document.getElementById("chatHeaderTitle")?.textContent || "").trim());
    const view = window.chatThreadSettingsView || "members";
    const isRenameView = view === "rename";
    container.innerHTML = `
      <div class="thread-settings-panel">
        <div class="thread-settings-tabs">
          <button type="button" class="thread-settings-tab ${isRenameView ? "active" : ""}" onclick="chatSwitchThreadSettingsView('rename')">
            <i data-lucide="square-pen"></i><span>Name</span>
          </button>
          <button type="button" class="thread-settings-tab ${!isRenameView ? "active" : ""}" onclick="chatSwitchThreadSettingsView('members')">
            <i data-lucide="users-round"></i><span>People</span>
          </button>
        </div>
        ${isRenameView ? `
          <div class="thread-settings-row">
            <label class="thread-settings-label">Conversation Name</label>
            <div class="thread-settings-inline">
              <input id="threadRenameInput" class="thread-settings-input" type="text" placeholder="Rename chat" value="${currentName}">
              <button type="button" class="thread-settings-btn thread-settings-btn-primary" onclick="chatRenameCurrentThread()">Save</button>
            </div>
          </div>
        ` : `
          <div class="thread-settings-row">
            <label class="thread-settings-label">Add People</label>
            <div class="thread-settings-inline">
              <input id="threadAddMemberUsername" class="thread-settings-input" type="text" placeholder="Search friend name or username" autocomplete="off">
              <button type="button" class="thread-settings-btn thread-settings-btn-secondary" onclick="chatAddMemberToCurrentThread()">Add</button>
            </div>
          </div>
          <div id="threadFriendSearchResults" class="thread-settings-list"></div>
        `}
      </div>
    `;
    if (window.lucide && typeof window.lucide.createIcons === "function") {
      window.lucide.createIcons();
    }
    if (!isRenameView) {
      chatInitThreadFriendPicker(threadId);
    }
    return;
  }
  container.innerHTML = "<p>Loading…</p>";

  fetch(`/getUserRole.php?token=${encodeURIComponent(token)}`)
    .then(res => res.json())
    .then(roleData => {
      const role = roleData.role || "none";
      window.currentUserRole = role;

      const canEditRoles = ["owner", "admin", "editor"].includes(role);
      if (inviteBlock) {
        inviteBlock.style.display = canEditRoles ? "block" : "none";
      }

      // Special case: request user — show only themselves
      if (role === "request" && window.SESSION_EMAIL) {
        return fetch(`/getListAccess.php?token=${encodeURIComponent(token)}`)
          .then(res => res.json())
          .then(data => {
            if (!Array.isArray(data)) {
              container.innerHTML = "<p>Could not load members.</p>";
              return;
            }

            const sortSelect = document.getElementById("memberSortSelect");
            const sortBy = sortSelect ? sortSelect.value : "alpha";

            if (sortBy === "date") {
              data.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
            } else {
              data.sort((a, b) => a.email.localeCompare(b.email));
            }

            const entry = data.find(e => e.email === window.SESSION_EMAIL);
            if (!entry) {
              showAccessRequestPrompt(container);
              return;
            }

            const messageRow = entry.message
              ? `<div style="font-size: 11px; color: #888; padding-left:8px;">💬 "${entry.message}"</div>`
              : "";

            const row = `
              <tr>
                <td style="padding:4px 8px;">${entry.email}${messageRow}</td>
                <td>
                  <select disabled style="font-size:13px; width:100%;">
                    <option value="request" selected>Request</option>
                  </select>
                </td>
              </tr>
            `;

            container.innerHTML = `
              <table style="width:100%; font-size:12px; border-collapse:collapse;">
                <thead>
                  <tr>
                    <th style="text-align:left; padding:3px 0;">Email</th>
                    <th style="text-align:left; padding:3px 0;">Role</th>
                  </tr>
                </thead>
                <tbody>${row}</tbody>
              </table>
            `;
          });
      }

      // 🚫 No access at all
      if (role === "none" || role === "denied") {
        showAccessRequestPrompt(container);
        return;
      }

      // ✅ Full access: show full list
      fetch(`/getListAccess.php?token=${encodeURIComponent(token)}`)
        .then(res => res.json())
        .then(data => {
          if (data.error) {
            console.warn("🚫 Server error:", data.error);
            showAccessRequestPrompt(container);
            return;
          }

          if (!Array.isArray(data)) {
            console.warn("⚠️ Unexpected response from getListAccess:", data);
            container.innerHTML = "<p>⚠️ Could not load members.</p>";
            return;
          }

          // Sort priority: requests first, then owner, then rest alphabetical by email
          const rolePriority = {
            request: 0,    // requests at top
            owner: 1,
            admin: 2,
            editor: 3,
            commenter: 4,
            viewer: 5,
            paused: 6,
            remove: 7
          };

          data.sort((a, b) => {
            const pa = rolePriority[a.role] ?? 99;
            const pb = rolePriority[b.role] ?? 99;
            if (pa !== pb) return pa - pb;
            return a.email.localeCompare(b.email); // then alpha
          });

          const rows = data.map(entry => {
            const messageNote =
              entry.role === "request" && entry.message
                ? `<div style="font-size: 11px; color: #888;">💬 "${entry.message}"</div>`
                : "";

            const requestBadge = entry.role === "request"
              ? `<span style="
                  background-color: red;
                  color: white;
                  border-radius: 8px;
                  padding: 2px 6px;
                  font-size: 10px;
                  margin-left: 6px;
                  vertical-align: middle;
                ">REQUEST</span>`
              : "";

            return `
              <tr>
                <td style="padding:4px 8px;">
                  ${entry.display_name ? `${entry.display_name} (${entry.email})` : entry.email}
                  ${requestBadge}
                  ${messageNote}
                </td>
                <td>
                  <select
                    ${!canEditRoles ? "disabled" : ""}
                    onchange="changeInviteRole('${entry.email}', this.value)"
                    style="font-size:13px; width:100%;">
                    <option value="viewer" ${entry.role === 'viewer' ? 'selected' : ''}>Viewer</option>
                    <option value="paused" ${entry.role === 'paused' ? 'selected' : ''}>Paused</option>
                    <option value="commenter" ${entry.role === 'commenter' ? 'selected' : ''}>Commenter</option>
                    <option value="editor" ${entry.role === 'editor' ? 'selected' : ''}>Editor</option>
                    <option value="admin" ${entry.role === 'admin' ? 'selected' : ''}>Admin</option>
                    <option value="owner" ${entry.role === 'owner' ? 'selected' : ''}>Owner</option>
                    <option value="remove" ${entry.role === 'remove' ? 'selected' : ''}>Remove</option>
                    <option value="request" ${entry.role === 'request' ? 'selected' : ''}>Request</option>
                  </select>
                </td>
              </tr>
            `;

          }).join("");

          container.innerHTML = `
            <table style="width:100%; font-size:12px; border-collapse:collapse;">
              <thead>
                <tr>
                  <th style="text-align:left; padding:3px 0;">Email</th>
                  <th style="text-align:left; padding:3px 0;">Role</th>
                </tr>
              </thead>
              <tbody>${rows}</tbody>
            </table>
          `;
        });
    });
}

window.chatThreadFriendCandidates = window.chatThreadFriendCandidates || [];
window.chatThreadMemberMeta = window.chatThreadMemberMeta || {};
window.chatThreadSettingsView = window.chatThreadSettingsView || "members";

function chatSwitchThreadSettingsView(view) {
  window.chatThreadSettingsView = view === "rename" ? "rename" : "members";
  loadInlineInviteList();
}

window.chatSwitchThreadSettingsView = chatSwitchThreadSettingsView;

async function chatRenameCurrentThread() {
  const token = getCurrentChatToken();
  if (!isDirectMessageToken(token)) return;
  const threadId = getDirectMessageThreadId(token);
  if (!threadId) return;

  const input = document.getElementById("threadRenameInput");
  const title = (input?.value || "").trim();

  let res;
  try {
    res = await fetch("/chatThreadRename.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      credentials: "same-origin",
      body: `thread_id=${encodeURIComponent(threadId)}&title=${encodeURIComponent(title)}`
    });
  } catch (err) {
    alert("Unable to reach chat service.");
    return;
  }

  let data = null;
  try {
    data = await res.json();
  } catch (err) {
    alert("Unexpected response.");
    return;
  }
  if (!res.ok || data.status !== "OK") {
    alert(data?.message || "Unable to rename chat.");
    return;
  }

  const nextName = data?.meta?.chat_name || title || "Group chat";
  const header = document.getElementById("chatHeaderTitle");
  if (header) header.textContent = nextName;
  selectList(token, nextName);
}

window.chatRenameCurrentThread = chatRenameCurrentThread;

async function chatRemoveMemberFromCurrentThread(memberId, username = "") {
  const token = getCurrentChatToken();
  if (!isDirectMessageToken(token)) return;
  const threadId = getDirectMessageThreadId(token);
  if (!threadId) return;
  const mid = Number(memberId || 0);
  if (!mid) return;

  const currentUserId = Number(document.body?.dataset?.userId || 0);
  const isSelf = currentUserId > 0 && currentUserId === mid;
  const label = String(username || "this member");
  if (isSelf) {
    const warning =
      "Leave this chat?\n\n" +
      "Warning: You will lose access to this conversation and future messages.\n" +
      "You can only rejoin if another member adds you again.";
    if (!confirm(warning)) return;
  } else {
    if (!confirm(`Remove ${label} from chat?`)) return;
  }

  let res;
  try {
    res = await fetch("/chatThreadRemoveMember.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      credentials: "same-origin",
      body: `thread_id=${encodeURIComponent(threadId)}&member_id=${encodeURIComponent(mid)}`
    });
  } catch (err) {
    alert("Unable to reach chat service.");
    return;
  }

  let data = null;
  try {
    data = await res.json();
  } catch (err) {
    alert("Unexpected response.");
    return;
  }
  if (!res.ok || data.status !== "OK") {
    alert(data?.message || "Unable to remove member.");
    return;
  }

  // If current user left the thread, close chat panel.
  if (currentUserId > 0 && currentUserId === mid) {
    toggleChat();
    return;
  }

  const nameEl = document.getElementById("chatHeaderTitle");
  if (nameEl) nameEl.textContent = "Updating…";
  await chatInitThreadFriendPicker(threadId);
  chatLoadMessages();
}

window.chatRemoveMemberFromCurrentThread = chatRemoveMemberFromCurrentThread;

async function chatInitThreadFriendPicker(threadId) {
  const input = document.getElementById("threadAddMemberUsername");
  const results = document.getElementById("threadFriendSearchResults");
  if (!input || !results) return;

  let data = null;
  let memberData = null;
  try {
    const [resCandidates, resMembers] = await Promise.all([
      fetch(`/chatThreadFriendCandidates.php?thread_id=${encodeURIComponent(threadId)}`, {
        credentials: "same-origin"
      }),
      fetch(`/chatThreadMembers.php?thread_id=${encodeURIComponent(threadId)}`, {
        credentials: "same-origin"
      })
    ]);
    data = await resCandidates.json();
    memberData = await resMembers.json();
  } catch (err) {
    results.innerHTML = "<div class='thread-settings-empty'>Unable to load members.</div>";
    return;
  }

  if (!data || data.status !== "OK" || !Array.isArray(data.friends)) {
    results.innerHTML = "<div class='thread-settings-empty'>No members available.</div>";
    return;
  }

  const memberMeta = {};
  const threadMembers = [];
  if (memberData && memberData.status === "OK" && Array.isArray(memberData.members)) {
    memberData.members.forEach((m) => {
      const key = String(m.username || "");
      if (!key) return;
      const normalized = {
        member_id: Number(m.member_id || 0),
        username: key,
        display_name: String(m.display_name || m.username || ""),
        avatar_url: String(m.avatar_url || ""),
        can_remove: !!m.can_remove,
        is_self: !!m.is_self
      };
      memberMeta[key] = normalized;
      threadMembers.push(normalized);
    });
  }
  window.chatThreadMemberMeta = memberMeta;
  window.chatThreadFriendCandidates = data.friends.map((f) => {
    const u = String(f.username || "");
    const meta = memberMeta[u] || null;
    return {
      ...f,
      in_thread: !!(f.in_thread || meta),
      member_id: Number(meta?.member_id || f.member_id || 0),
      can_remove: !!meta?.can_remove,
      is_self: !!meta?.is_self,
      avatar_url: String(meta?.avatar_url || f.avatar_url || "")
    };
  });

  const render = (query = "") => {
    const q = String(query || "").toLowerCase().trim();
    const filteredMembers = threadMembers.filter((m) => {
      const username = (m.username || "").toLowerCase();
      const display = (m.display_name || "").toLowerCase();
      return !q || username.includes(q) || display.includes(q);
    });
    const filteredCandidates = window.chatThreadFriendCandidates
      .filter((f) => {
        if (f.in_thread) return false;
        const username = (f.username || "").toLowerCase();
        const display = (f.display_name || "").toLowerCase();
        return !q || username.includes(q) || display.includes(q);
      })
      .slice(0, 80);

    if (!filteredMembers.length && !filteredCandidates.length) {
      results.innerHTML = "<div class='thread-settings-empty'>No matches.</div>";
      return;
    }

    const membersHtml = filteredMembers.map((m) => {
      const username = escapeHTML(m.username || "");
      const display = escapeHTML(m.display_name || m.username || "");
      const label = display === username ? username : `${display} [${username}]`;
      const avatarUrl = (typeof window.twResolveAvatarUrl === "function")
        ? window.twResolveAvatarUrl(m, m.display_name || m.username || "User")
        : (m.avatar_url || "/default-avatar.png");
      let actions = "";
      if (m.is_self) {
        const paused = chatIsPaused(getCurrentChatToken());
        actions = `
          <span class="thread-friend-actions">
            <button type="button" class="thread-friend-action" data-action="pause-toggle">
              ${paused ? "Resume" : "Pause"}
            </button>
            <button type="button" class="thread-friend-action" data-action="remove" data-member-id="${Number(m.member_id || 0)}" data-username="${username}">
              Leave
            </button>
          </span>
        `;
      } else if (m.can_remove) {
        actions = `
          <button type="button" class="thread-friend-action" data-action="remove" data-member-id="${Number(m.member_id || 0)}" data-username="${username}">
            Remove
          </button>
        `;
      } else {
        actions = `<span class="thread-friend-state">Member</span>`;
      }
      return `
        <div class="thread-friend-result" data-in-thread="1">
          <img src="${escapeHTML(avatarUrl)}" alt="${display}" class="thread-friend-avatar" onerror="twHandleAvatarError(this)" />
          <span class="thread-friend-label">${label}${m.is_self ? " • you" : ""}</span>
          ${actions}
        </div>
      `;
    }).join("");

    const candidatesHtml = filteredCandidates.map((f) => {
      const username = escapeHTML(f.username || "");
      const display = escapeHTML(f.display_name || f.username || "");
      const label = display === username ? username : `${display} [${username}]`;
      const avatarUrl = (typeof window.twResolveAvatarUrl === "function")
        ? window.twResolveAvatarUrl(f, f.display_name || f.username || "User")
        : (f.avatar_url || "/default-avatar.png");
      return `
        <div class="thread-friend-result"
             data-username="${username}">
          <img src="${escapeHTML(avatarUrl)}" alt="${display}"
               class="thread-friend-avatar"
               onerror="twHandleAvatarError(this)" />
          <span class="thread-friend-label">${label}</span>
          <button type="button"
                  class="thread-friend-action"
                  data-action="add"
                  data-username="${username}">Add</button>
        </div>
      `;
    }).join("");

    results.innerHTML = `
      <div class="thread-settings-subtitle">Members</div>
      ${membersHtml || "<div class='thread-settings-empty'>No members.</div>"}
      <div class="thread-settings-subtitle">Add People</div>
      ${candidatesHtml || "<div class='thread-settings-empty'>No matching friends.</div>"}
    `;
  };

  render("");
  input.addEventListener("input", () => render(input.value));
  input.addEventListener("keydown", (ev) => {
    if (ev.key !== "Enter") return;
    ev.preventDefault();
    const first = results.querySelector('.thread-friend-action[data-action="add"]');
    if (first) {
      const username = (first.dataset.username || "").trim();
      if (username) {
        input.value = username;
        chatAddMemberToCurrentThread();
      }
    }
  });
  results.addEventListener("click", (ev) => {
    const btn = ev.target.closest(".thread-friend-action");
    if (!btn) return;
    const action = btn.dataset.action || "";
    if (action === "add") {
      const username = (btn.dataset.username || "").trim();
      if (!username) return;
      input.value = username;
      chatAddMemberToCurrentThread();
      return;
    }
    if (action === "remove") {
      const memberId = Number(btn.dataset.memberId || 0);
      const username = (btn.dataset.username || "").trim();
      if (!memberId) return;
      chatRemoveMemberFromCurrentThread(memberId, username);
      return;
    }
    if (action === "pause-toggle") {
      const token = getCurrentChatToken();
      if (!isDirectMessageToken(token)) return;
      const paused = chatIsPaused(token);
      chatSetPaused(token, !paused);
      chatApplyPausedUi(token);
      render(input.value);
    }
  });
}

async function chatAddMemberToCurrentThread() {
  const token = getCurrentChatToken();
  if (!isDirectMessageToken(token)) return;
  const threadId = getDirectMessageThreadId(token);
  if (!threadId) return;

  const input = document.getElementById("threadAddMemberUsername");
  const username = (input?.value || "").trim();
  if (!username) {
    alert("Enter a username.");
    return;
  }
  const known = Array.isArray(window.chatThreadFriendCandidates)
    ? window.chatThreadFriendCandidates.some((f) => (f.username || "") === username)
    : true;
  if (!known) {
    alert("Select a friend from the list.");
    return;
  }
  const picked = Array.isArray(window.chatThreadFriendCandidates)
    ? window.chatThreadFriendCandidates.find((f) => (f.username || "") === username)
    : null;
  if (picked && picked.in_thread) {
    alert("This member is already in chat.");
    return;
  }

  let res;
  try {
    res = await fetch("/chatThreadAddMember.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      credentials: "same-origin",
      body: `thread_id=${encodeURIComponent(threadId)}&username=${encodeURIComponent(username)}`
    });
  } catch (err) {
    alert("Unable to reach chat service.");
    return;
  }

  let data = null;
  try {
    data = await res.json();
  } catch (err) {
    alert("Unexpected response.");
    return;
  }

  if (!res.ok || data.status !== "OK") {
    alert(data?.message || "Unable to add member.");
    return;
  }

  if (input) input.value = "";
  window.chatThreadFriendCandidates = [];
  const chatName = data?.meta?.chat_name || "Group chat";
  selectList(token, chatName);
  loadInlineInviteList();
  chatLoadMessages();
}

window.chatAddMemberToCurrentThread = chatAddMemberToCurrentThread;

async function openDMChatWithMember(memberId, fallbackName = "Direct Message") {
  const id = Number(memberId || 0);
  if (!id) return;
  let res;
  try {
    res = await fetch("/chatThreadStartDM.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      credentials: "same-origin",
      body: `member_id=${encodeURIComponent(id)}`
    });
  } catch (err) {
    console.error("Failed to start DM:", err);
    alert("Unable to start chat.");
    return;
  }

  let data = null;
  try {
    data = await res.json();
  } catch (err) {
    alert("Unable to start chat.");
    return;
  }

  if (!res.ok || data.status !== "OK" || !data.token) {
    alert(data?.message || "Unable to start chat.");
    return;
  }

  const chatName = data?.meta?.chat_name || fallbackName;
  window.currentChatToken = data.token;
  try {
    await fetch('/chatThreadMarkRead.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ thread_id: getDirectMessageThreadId(data.token) })
    });
  } catch (err) {}
  selectList(data.token, chatName);
  openChat();
  updateFooterChatBadge();
}

window.openDMChatWithMember = openDMChatWithMember;

function consumeChatDeepLinkParams() {
  if (window.__chatDeepLinkConsumed) return;
  const params = new URLSearchParams(window.location.search || "");
  const token = (params.get("open_chat_token") || "").trim();
  if (!token) return;

  const msgId = Number(params.get("open_chat_msg") || 0);
  window.__chatDeepLinkConsumed = true;

  const openWithRetry = (attempt = 0) => {
    if (typeof openChatFromMenu === "function") {
      window.currentChatToken = token;
      if (msgId > 0) window.pendingChatMessageId = msgId;
      openChatFromMenu(token);
      const sidebar = document.getElementById("sidebarContainer");
      if (sidebar && window.innerWidth < 1200) {
        sidebar.classList.remove("show");
      }
      return;
    }
    if (attempt < 25) {
      setTimeout(() => openWithRetry(attempt + 1), 120);
    }
  };

  openWithRetry();

  const kept = new URLSearchParams(window.location.search || "");
  kept.delete("open_chat_token");
  kept.delete("open_chat_msg");
  const query = kept.toString();
  const cleanUrl = `${window.location.pathname}${query ? `?${query}` : ""}${window.location.hash || ""}`;
  window.history.replaceState({}, "", cleanUrl);
}

setTimeout(consumeChatDeepLinkParams, 0);





function changeInviteRole(email, role) {
  const token = getCurrentChatToken();
  fetch("/chatChangeInviteRole.php", {
    method: "POST",
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `token=${encodeURIComponent(token)}&email=${encodeURIComponent(email)}&role=${encodeURIComponent(role)}`
  })
  .then(res => {
    if (!res.ok) throw new Error("Request failed");
    return res.json();
  })
  .then(data => {
    if (data.status === "success") {
      loadInlineInviteList(); // Refresh the list
    } else {
      alert("❌ " + (data.message || "Error updating role."));
    }
  })
  .catch(err => {
    console.error("Error changing invite role:", err);
    alert("❌ Unable to update role. Please try again.");
  });
}



// function sendBulkInvitesXXX() {
//   const input = document.getElementById("bulkInviteEmails");
//   const token = window.currentListToken;
//   const emails = input.value
//     .split(/[\s,]+/)
//     .map(e => e.trim())
//     .filter(e => e.includes('@'));

//   if (emails.length === 0) {
//     alert("⚠️ No valid email addresses found.");
//     return;
//   }

//   Promise.all(
//     emails.map(email => {
//       return fetch("/chatInviteToList.php", {
//         method: "POST",
//         headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
//         body: `token=${encodeURIComponent(token)}&email=${encodeURIComponent(email)}&role=viewer`
//       });
//     })
//   ).then(() => {
//     alert("✅ Bulk invites sent.");
//     loadInlineInviteList();
//     input.value = "";
//   });
// }


// function sendBulkInvitesMMMMM() {
    
    
//   const input = document.getElementById("bulkInviteEmails");
//   const token = window.currentListToken;
//   const emails = input.value
//     .split(/[\s,]+/)
//     .map(cleanEmail)   // 👈 apply cleaning
//     .filter(Boolean);  // remove blanks

//   if (emails.length === 0) {
//     alert("⚠️ No valid email addresses found.");
//     return;
//   }

//   Promise.all(
//     emails.map(email => {
//       return fetch("/chatInviteToList.php", {
//         method: "POST",
//         headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
//         body: `token=${encodeURIComponent(token)}&email=${encodeURIComponent(email)}&role=viewer`
//       });
//     })
//   ).then(() => {
//     alert("✅ Bulk invites sent.");
//     loadInlineInviteList();
//     input.value = "";
//   });
// }



async function sendBulkInvites() {


  stopChatPolling();  // ⛔ pause polling

  const input = document.getElementById("bulkInviteEmails");
  const token = getCurrentChatToken();
  const emails = Array.from(new Set(input.value
    .split(/[\s,]+/)
    .map(cleanEmail)
    .filter(Boolean)));

  if (emails.length === 0) {
    alert("⚠️ No valid email addresses found.");
    startChatPolling(); // ▶ resume
    return;
  }

  try {
    const button = document.querySelector("#inviteInputWrapper button");
    const originalLabel = button ? button.textContent : null;
    const batchSize = 5;
    let sent = 0;
    let failed = 0;

    for (let i = 0; i < emails.length; i += batchSize) {
      const batch = emails.slice(i, i + batchSize);
      const results = await Promise.all(batch.map(async (email) => {
        try {
          const res = await fetch("/chatInviteToList.php", {
          method: "POST",
          headers: { "Content-Type": "application/x-www-form-urlencoded" },
          body: `token=${encodeURIComponent(token)}&email=${encodeURIComponent(email)}&role=viewer`
          });
          if (!res.ok) throw new Error(`HTTP ${res.status}`);
          const data = await res.json().catch(() => null);
          if (!data || data.status !== "success") throw new Error("Invite failed");
          return true;
        } catch (err) {
          console.error("Invite error:", email, err);
          return false;
        }
      }));

      sent += results.filter(Boolean).length;
      failed += results.length - results.filter(Boolean).length;

      if (button) {
        button.textContent = `📨 Inviting ${sent}/${emails.length}`;
      }

      await new Promise(r => setTimeout(r, 250));
    }

    if (failed > 0) {
      alert(`✅ Sent ${sent} invite(s). ❌ Failed: ${failed}.`);
    } else {
      alert("✅ Bulk invites sent.");
    }
    loadInlineInviteList();
    input.value = "";
  } finally {
    if (originalLabel && document.querySelector("#inviteInputWrapper button")) {
      document.querySelector("#inviteInputWrapper button").textContent = originalLabel;
    }
    startChatPolling(); // ▶ always resume, even on error
  }
}



function toggleManageInviteMode() {
  const invite = document.getElementById("chatInviteWrapper");
  const chatBody = document.getElementById("chatBodyWrapper");
  const back = document.getElementById("chatBackArrow");

  const isInviteOpen = invite.style.display === "block";

  if (isInviteOpen) {
    invite.style.display = "none";
    chatBody.style.display = "flex";
    back.style.display = "none";
  } else {
    invite.style.display = "block";
    chatBody.style.display = "none";
    back.style.display = "inline";
    loadInlineInviteList();
  }
}




function backToChatView() {
toggleManageInviteMode();
}



function toggleEmojiPicker() {
  const picker = document.querySelector('.emoji-picker');
  if (picker) {
    picker.style.display = (picker.style.display === 'none' || !picker.style.display) ? 'block' : 'none';
  }
}

function closeChatHeaderMenu() {
  const menu = document.getElementById("chatHeaderMenu");
  if (!menu) return;
  menu.style.display = "none";
  menu.classList.remove("show");
}

function closeCurrentChatPanel() {
  const chat = document.getElementById("chatContainer");
  if (chat) chat.style.display = "none";
  const invite = document.getElementById("chatInviteWrapper");
  const chatBody = document.getElementById("chatBodyWrapper");
  const back = document.getElementById("chatBackArrow");
  if (invite) invite.style.display = "none";
  if (chatBody) chatBody.style.display = "flex";
  if (back) back.style.display = "none";
  stopChatPolling();
  closeChatHeaderMenu();
  const header = document.getElementById("chatHeaderTitle");
  if (header) header.textContent = "List Chat";
}

async function chatDeleteCurrentThread() {
  const token = getCurrentChatToken();
  if (!isDirectMessageToken(token)) return;
  const threadId = getDirectMessageThreadId(token);
  if (!threadId) return;

  const warning =
    "Delete chat?\n\n" +
    "If you are the chat owner, this deletes the chat for everyone.\n" +
    "Otherwise, it deletes the chat only for you.";
  if (!confirm(warning)) return;

  let res;
  try {
    res = await fetch("/chatThreadDelete.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      credentials: "same-origin",
      body: `thread_id=${encodeURIComponent(threadId)}`
    });
  } catch (err) {
    alert("Unable to reach chat service.");
    return;
  }

  let data = null;
  try {
    data = await res.json();
  } catch (err) {
    alert("Unexpected response.");
    return;
  }

  if (!res.ok || data.status !== "OK") {
    alert(data?.message || "Unable to delete chat.");
    return;
  }

  closeCurrentChatPanel();
  window.currentChatToken = "";
  updateFooterChatBadge();
  alert(data?.message || (data?.scope === "all" ? "Chat deleted for all members." : "Chat deleted for you."));
}

function toggleChatHeaderMenu(event) {
  if (event) {
    event.preventDefault();
    event.stopPropagation();
  }
  const menu = document.getElementById("chatHeaderMenu");
  const trigger = document.getElementById("chatHeaderMenuTrigger");
  const invite = document.getElementById("chatInviteWrapper");
  if (!menu || !trigger) return;

  // If settings/edit panel is open, header click acts as "back to chat".
  if (invite && invite.style.display === "block") {
    closeChatHeaderMenu();
    if (typeof toggleManageInviteMode === "function") {
      toggleManageInviteMode();
    }
    return;
  }

  const isOpen = menu.style.display === "block" || menu.classList.contains("show");
  if (isOpen) {
    closeChatHeaderMenu();
    return;
  }

  const token = getCurrentChatToken();
  const isThread = isDirectMessageToken(token);
  menu.querySelectorAll('[data-action="rename"], [data-action="add"], [data-action="pause"], [data-action="leave"], [data-action="delete"]').forEach((btn) => {
    btn.style.display = isThread ? "block" : "none";
  });
  const pauseBtn = menu.querySelector('[data-action="pause"]');
  if (pauseBtn && isThread) {
    const paused = chatIsPaused(token);
    pauseBtn.classList.toggle("paused", paused);
    const label = pauseBtn.querySelector("span");
    if (label) label.textContent = paused ? "Resume chat" : "Pause chat";
  }

  menu.style.display = "block";
  menu.classList.add("show");
}

function ensureChatMemberPanelOpen() {
  const invite = document.getElementById("chatInviteWrapper");
  if (!invite) return;
  const isInviteOpen = invite.style.display === "block";
  if (!isInviteOpen) toggleManageInviteMode();
  loadInlineInviteList();
}

function chatHeaderMenuAction(action) {
  closeChatHeaderMenu();

  const token = getCurrentChatToken();
  const isThread = isDirectMessageToken(token);

  if (action === "members") {
    window.chatThreadSettingsView = "members";
    ensureChatMemberPanelOpen();
    return;
  }

  if (action === "rename") {
    if (!isThread) {
      alert("Rename is available for thread chats.");
      return;
    }
    window.chatThreadSettingsView = "rename";
    ensureChatMemberPanelOpen();
    setTimeout(() => {
      const input = document.getElementById("threadRenameInput");
      if (!input) return;
      input.focus();
      input.select();
    }, 60);
    return;
  }

  if (action === "add") {
    if (!isThread) {
      alert("Add people is available for thread chats.");
      return;
    }
    window.chatThreadSettingsView = "members";
    ensureChatMemberPanelOpen();
    setTimeout(() => {
      const input = document.getElementById("threadAddMemberUsername");
      if (!input) return;
      input.focus();
    }, 60);
    return;
  }

  if (action === "pause") {
    if (!isThread) {
      alert("Pause is available for thread chats.");
      return;
    }
    const paused = chatIsPaused(token);
    chatSetPaused(token, !paused);
    chatApplyPausedUi(token);
    return;
  }

  if (action === "leave") {
    if (!isThread) {
      alert("Leave is available for thread chats.");
      return;
    }
    const currentUserId = Number(document.body?.dataset?.userId || 0);
    if (!currentUserId) return;
    chatRemoveMemberFromCurrentThread(currentUserId, window.SESSION_USERNAME || "you");
    return;
  }

  if (action === "delete") {
    if (!isThread) {
      alert("Delete is available for thread chats.");
      return;
    }
    chatDeleteCurrentThread();
  }
}

window.chatHeaderMenuAction = chatHeaderMenuAction;

document.addEventListener("pointerdown", (event) => {
  const menu = document.getElementById("chatHeaderMenu");
  const trigger = document.getElementById("chatHeaderMenuTrigger");
  if (!menu || !trigger) return;
  if (menu.style.display !== "block") return;
  if (menu.contains(event.target)) return;
  if (trigger.contains(event.target)) return;
  closeChatHeaderMenu();
}, true);

function insertEmoji(emoji) {
  const input = document.getElementById('chatInput');
  if (!input) return;

  const start = input.selectionStart;
  const end = input.selectionEnd;

  input.value = input.value.slice(0, start) + emoji + input.value.slice(end);
  input.focus();
  input.selectionStart = input.selectionEnd = start + emoji.length;
}

//Events



function inputAllertBellToMessage() {
  const input = document.getElementById("chatInput");
  if (!input) return;
  input.value += " 🔔";
  //input.focus();
}


function focusInputAndOpenEmoji() {
    //unused at the moment
    
  const input = document.getElementById("chatInput");
  if (!input) return;

  input.focus();

  // Show native emoji picker via key event (limited browser support)
  // Fallback: manual shortcut hint for user
  alert("💡 Press Win + . (Windows) or Cmd + Ctrl + Space (macOS) to open emoji picker.");
}





function updateFooterChatBadge() {
  if (window.__twUnreadRefreshInFlight) return;
  window.__twUnreadRefreshInFlight = true;

  const applyFooterBadge = (countRaw) => {
    const unread = Number(countRaw || 0);
    const badge = document.getElementById("chatUnreadBadge");
    if (!badge) return;
    if (unread > 0) {
      badge.textContent = unread > 9 ? "9+" : String(unread);
      badge.classList.remove("zero");
      badge.style.display = "inline-block";
    } else {
      badge.textContent = "0";
      badge.classList.add("zero");
      badge.style.display = "inline-block";
    }
  };

  const applyUnreadMapToListBadges = (map) => {
    if (!map || typeof map !== "object") return;
    Object.entries(map).forEach(([token, countRaw]) => {
      if (token === "unread" || token === "unread_lists" || token === "unread_threads") return;
      const count = Number(countRaw || 0);
      const targets = [];
      const idBadge = document.getElementById(`chat-unread-${token}`);
      if (idBadge) targets.push(idBadge);
      document.querySelectorAll(`[data-group="${token}"] .chat-inline-badge`).forEach((el) => {
        if (!targets.includes(el)) targets.push(el);
      });
      targets.forEach((badgeEl) => {
        badgeEl.textContent = String(count);
        if (count > 0) {
          badgeEl.classList.add("unread");
        } else {
          badgeEl.classList.remove("unread");
        }
      });
    });
  };

  fetch("/chatUnreadCounts.php")
    .then(res => res.json())
    .then(data => {
      window.unreadChatMap = data; // canonical latest map
      window.UNREAD_COUNTS = Object.fromEntries(
        Object.entries(data || {}).filter(([k]) => (
          k !== "unread" && k !== "unread_lists" && k !== "unread_threads"
        ))
      );
      applyUnreadMapToListBadges(data);
      if (typeof refreshInlineChatBadges === "function") {
        refreshInlineChatBadges();
      }
      const directUnread = Number(data?.unread ?? NaN);
      if (Number.isFinite(directUnread)) {
        applyFooterBadge(directUnread);
        return;
      }
      const listTotal = Number(data?.unread_lists || 0);
      const threadTotal = Number(data?.unread_threads || 0);
      applyFooterBadge(listTotal + threadTotal);
    })
    .catch(err => {
      console.warn("⚠️ Failed to refresh chat badge:", err);
    })
    .finally(() => {
      window.__twUnreadRefreshInFlight = false;
    });
}

function startGlobalUnreadBadgeRefresh() {
  if (window.__twUnreadRefreshTimer) return;
  const refreshNow = () => updateFooterChatBadge();
  window.__twUnreadRefreshTimer = setInterval(refreshNow, 15000);
  window.addEventListener("focus", refreshNow);
  document.addEventListener("visibilitychange", () => {
    if (!document.hidden) refreshNow();
  });
}




function showAccessRequestPrompt(container) {
  container.innerHTML = `
    <div style="padding:10px; font-size:13px;">
      <p>🔒 You are not a member of this list.</p>
      <textarea id="requestMessage" placeholder="(Optional) Add a short note…" style="width:100%; margin-top:5px;"></textarea>
      <button onclick="submitAccessRequest()" style="margin-top:5px;">Request Access</button>
    </div>
  `;
}





function showUnreadListSelector() {
  const existing = document.querySelector(".inline-chatlist-selector");
  if (existing) {
    existing.remove();
    return;
  }

  getAvailableChatLists().then(availableLists => {
    console.log("showUnreadListSelector", availableLists, availableLists.length);

    if (!availableLists.length) {
      openChatFromCurrentList();
      return;
    }

    // if (availableLists.length === 0) {
    //   openChatFromCurrentList();
    //   return;
    // }

    const unreadMenu = document.createElement("div");
    unreadMenu.classList.add("inline-chatlist-selector");

    const header = document.createElement("div");
    header.className = "chatlist-choice";
    header.style.cursor = "default";
    header.style.fontWeight = "600";
    header.style.borderBottom = "1px solid #ddd";
    header.innerHTML = `
      <span style="font-size: 15px;">📬</span>
      <span style="margin-left: 6px;">${window.translations?.latest_chats || 'Latest Chats'}</span>

    `;
    unreadMenu.appendChild(header);

    // ✅ Add list entries
    availableLists.forEach(({ token, name, count, kind, otherMember }) => {
      const item = document.createElement("div");
      item.className = "chatlist-choice";

      const label = document.createElement("span");
      label.style.display = "inline-flex";
      label.style.alignItems = "center";
      label.style.gap = "6px";
      const badge = document.createElement("span");
      badge.className = "chat-inline-badge";
      badge.textContent = count;
      if (count > 0) {
        badge.classList.add("unread");
      }

      if (kind === "dm" && otherMember) {
        const avatar = document.createElement("img");
        avatar.alt = name || "Direct message";
        avatar.width = 22;
        avatar.height = 22;
        avatar.style.width = "22px";
        avatar.style.height = "22px";
        avatar.style.borderRadius = "50%";
        avatar.style.marginRight = "6px";
        avatar.style.verticalAlign = "middle";
        avatar.src = window.twResolveAvatarUrl(otherMember, name || "Direct message");
        avatar.onerror = function () {
          window.twHandleAvatarError(avatar);
        };
        label.appendChild(badge);
        label.appendChild(avatar);
        const text = document.createElement("span");
        text.textContent = name || "Direct message";
        label.appendChild(text);
        item.appendChild(label);
      } else {
        const icon = document.createElement("span");
        icon.textContent = kind === "group" ? "👥" : "💬";
        const text = document.createElement("span");
        text.textContent = name || "Chat";
        label.appendChild(badge);
        label.appendChild(icon);
        label.appendChild(text);
        item.appendChild(label);
      }

      item.onclick = () => {
        openChatFromMenu(token);
        unreadMenu.remove();
      };

      unreadMenu.appendChild(item);
    });

    document.body.appendChild(unreadMenu);

    const chatBtn = document.querySelector('button[data-target="chatTab"]');
    if (chatBtn) {
      const rect = chatBtn.getBoundingClientRect();
      const menuWidth = 240;
      const offsetX = 40;

      let left = rect.left + rect.width / 2 - menuWidth / 2 + offsetX;
      left = Math.max(8, Math.min(left, window.innerWidth - menuWidth - 8));

      unreadMenu.style.position = "fixed";
      unreadMenu.style.left = `${left}px`;
      unreadMenu.style.bottom = `${window.innerHeight - rect.top + 8}px`;
      unreadMenu.style.width = `${menuWidth}px`;
    } else {
      console.warn("❌ Chat button not found — unread menu not shown");
      return;
    }

    // ✅ Outside-click auto-close
    setTimeout(() => {
      document.addEventListener("click", function handler(e) {
        if (!unreadMenu.contains(e.target)) {
          unreadMenu.remove();
          document.removeEventListener("click", handler);
        }
      });
    }, 0);
  });
}


async function getAvailableChatLists() {
  const unreadMap = (window.unreadChatMap && typeof window.unreadChatMap === "object")
    ? window.unreadChatMap
    : {};
  const [listRes, dmRes] = await Promise.allSettled([
    fetch("/chatGetAvailableLists.php", { credentials: "include" }).then((res) => res.json()),
    fetch("/chatThreadList.php", { credentials: "include" }).then((res) => res.json())
  ]);

  const merged = [];
  const seen = new Set();
  const pushEntry = (token, name, unread, last, kind = "list", otherMember = null) => {
    if (!token || seen.has(token)) return;
    seen.add(token);
    merged.push({
      token,
      name: name || `(Chat ${token})`,
      kind,
      otherMember,
      count: Number(unread || 0),
      last: new Date(last || 0).getTime()
    });
  };

  if (listRes.status === "fulfilled" && Array.isArray(listRes.value?.lists)) {
    listRes.value.lists.forEach((entry) => {
      const mapUnread = Number(unreadMap[entry.token] || 0);
      const apiUnread = Number(entry.unread || 0);
      pushEntry(entry.token, entry.name || `(List ${entry.token})`, Math.max(mapUnread, apiUnread), entry.last, "list", null);
    });
  }

  if (dmRes.status === "fulfilled" && Array.isArray(dmRes.value?.threads)) {
    dmRes.value.threads.forEach((entry) => {
      pushEntry(
        entry.token,
        entry.chat_name || "Direct Message",
        entry.unread,
        entry.last,
        entry.thread_type || "dm",
        entry.other_member || null
      );
    });
  }

  return merged.sort((a, b) => b.last - a.last);
}

function hasUnreadMessages() {
  const lists = getUnreadChatLists();
  return lists.length > 0;
}



function getUnreadChatLists() {
  const map = window.unreadChatMap || {};

  const unreadLists = Object.entries(map)
    .filter(([key, count]) => key !== "unread" && key !== "unread_lists" && key !== "unread_threads" && count > 0)
    .map(([token]) => {
      const el = document.querySelector(`[data-group="${token}"] .list-title`);
      return {
        token,
        name: el?.textContent?.trim() || `(List ${token})`
      };
    });

  // 🔍 Use currentListToken or fallback to active .group-item
  let currentToken = window.currentListToken;

  if (!currentToken) {
    const activeEl = document.querySelector('.group-item.active-list');
    currentToken = activeEl?.getAttribute('data-group') || null;
  }

  if (
    currentToken &&
    !unreadLists.some(entry => entry.token === currentToken)
  ) {
    const el = document.querySelector(`[data-group="${currentToken}"] .list-title`);
    const name = el?.textContent?.trim() || `(List ${currentToken})`;
    unreadLists.unshift({ token: currentToken, name });
  }

  return unreadLists;
}





//Added at the bottom of chatFunctions.js outside DOMContentLoaded, so it runs immediately when the script loads:

(function initializePushOnLogin() {
  const isLoggedIn = document.body.classList.contains("logged-in");
  const token = getCurrentChatToken();

  if (isLoggedIn && token && !isDirectMessageToken(token)) {
    console.log("🔔 Initializing push subscription for:", token);
    setupPushSubscription(token);
  }
})();



function reactToMessage(messageId, emoji) {
  const isRemove = emoji === "❌";

  fetch("/chatReactToMessage.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: `message_id=${messageId}&emoji=${encodeURIComponent(isRemove ? "__remove__" : emoji)}`
  })
  .then(res => res.json())
  .then(data => {
    if (data.status === "success") {
      // 🔄 Instead of chatLoadMessages(), update only that bubble
      refreshReactionsForMessage(messageId);
    } else {
      alert("❌ " + data.message);
    }
  });
}


async function refreshReactionsForMessage(messageId) {
  const res = await fetch(`/chatGetReactions.php?id=${messageId}`);
  const data = await res.json();
  if (data.status !== "success") return;

  const { reactions, detailed } = data;

  const bubble = document.querySelector(`.chat-bubble[data-id="${messageId}"]`);
  if (!bubble) return;

  // ✅ Find the slot right after bubble wrapper
  const slot = bubble.closest(".chat-meta-wrapper").nextElementSibling;
  if (!slot) return;

  if (reactions && reactions.length) {
    const isMine = bubble.classList.contains("me");
    slot.innerHTML = `
      <div class="chat-reactions"
           style="font-size:12px; display:flex; gap:4px; margin-top:4px; ${isMine ? "justify-content:flex-end;" : "justify-content:flex-start;"}">
        ${reactions.map(r => `
          <span class="reaction-count"
                title="${escapeHTML((detailed[r.emoji] || []).join(", "))}"
                style="background:#777; border-radius:12px; padding:1px 4px; cursor:pointer;"
                onclick='event.stopPropagation(); showReactionPopup("${escapeHTML(r.emoji)}", ${JSON.stringify(detailed[r.emoji] || [])})'>
            ${r.emoji} ${r.count}
          </span>`
        ).join("")}
      </div>
    `;
  } else {
    slot.innerHTML = "";
  }
}







function linkifyChatMessage(text) {
  return text.replace(/(https?:\/\/[^\s<]+)/g, url => {
    // 🎥 YouTube
    if (url.includes("youtube.com") || url.includes("youtu.be")) {
      const id = extractYouTubeId(url);
      if (id) {
        const isShort = /youtube\.com\/shorts\//.test(url);
        return `
          </div></div>
          <div class="chat-embed youtube${isShort ? " portrait" : ""}">
            <iframe src="https://www.youtube.com/embed/${id}"
                    frameborder="0" allowfullscreen></iframe>
          </div>
          <div><div class="chat-text">
        `;
      }
    }

    // 🖼 Images
    if (/\.(jpg|jpeg|png|gif)$/i.test(url)) {
      return `
        </div></div>
        <div class="chat-embed image">
          <img src="${url}" />
        </div>
        <div><div class="chat-text">
      `;
    }

    // 🎵 SoundCloud
    if (url.includes("soundcloud.com")) {
      return `
        </div></div>
        <div class="chat-embed soundcloud">
          <iframe src="https://w.soundcloud.com/player/?url=${encodeURIComponent(url)}"
                  frameborder="0"></iframe>
        </div>
        <div><div class="chat-text">
      `;
    }

    // 🎧 Spotify
    if (url.includes("open.spotify.com")) {
      // Clean URL: Spotify embed wants "https://open.spotify.com/embed/track/..."
      const embedUrl = url.replace("open.spotify.com/", "open.spotify.com/embed/");
      return `
        </div></div>
        <div class="chat-embed spotify">
          <iframe src="${embedUrl}"
                  width="100%" height="152"
                  frameborder="0"
                  allowtransparency="true"
                  allow="encrypted-media"></iframe>
        </div>
        <div><div class="chat-text">
      `;
    }

    // fallback: plain link
    return `<a href="${url}" target="_blank">${url}</a>`;
  });
}


function extractYouTubeId(url) {
  const match = url.match(/(?:youtube\.com\/.*v=|youtube\.com\/shorts\/|youtu\.be\/)([a-zA-Z0-9_-]+)/);
  return match ? match[1] : null;
}



function cleanEmail(raw) {
  if (!raw) return "";
  // strip common wrappers
  let email = raw.trim().replace(/^[<('"`]+|[>)"'`]+$/g, "");
  // extract if "Name <email@host>"
  const match = email.match(/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/);
  return match ? match[1].toLowerCase() : "";
}




window.updateFooterChatBadge = updateFooterChatBadge;
window.startGlobalUnreadBadgeRefresh = startGlobalUnreadBadgeRefresh;
