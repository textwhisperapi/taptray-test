

<?php if (isset($_SESSION['username'], $listOwnerUsername) && $_SESSION['username'] === $listOwnerUsername): ?>
<script>
  window.currentUserRole = "owner";
</script>
<?php endif; ?>



<!-- 📧 Invite Modal -->
<!-- 📧 Invite Modal -->
<div id="inviteModal" class="modal" style="display:none;">
  <div class="modal-content">
    <h3>📧 <?= $lang['invite_user_to_list'] ?? 'Invite User to List' ?></h3>

    <label><?= $lang['email'] ?? 'Email:' ?></label>
    <input type="email" id="inviteEmail" placeholder="user@example.com" required />

    <label><?= $lang['role'] ?? 'Role:' ?></label>
    <select id="inviteRole">
      <option value="viewer">Viewer</option>
      <option value="commenter">Commenter</option>
      <option value="editor">Editor</option>
    </select>

    <button onclick="sendInvite()"><?= $lang['send_invite'] ?? 'Send Invite' ?></button>
    <button onclick="closeModal('inviteModal')"><?= $lang['cancel'] ?? 'Cancel' ?></button>
  </div>
</div>

<!-- 🙋‍♂️ Request Access Modal -->
<div id="requestModal" class="modal" style="display:none;">
  <div class="modal-content">
    <h3>🙋‍♂️ <?= $lang['request_access_to_list'] ?? 'Request Access to List' ?></h3>
    <p><?= $lang['list_owned_by_other'] ?? 'This list is owned by another user. You can request access below.' ?></p>

    <label><?= $lang['message_optional'] ?? 'Message (optional):' ?></label>
    <textarea id="requestMessage" rows="3" placeholder="<?= $lang['collaborate_placeholder'] ?? 'I\'d like to collaborate...' ?>"></textarea>

    <button onclick="submitAccessRequest()"><?= $lang['submit_request'] ?? 'Submit Request' ?></button>
    <button onclick="closeModal('requestModal')"><?= $lang['cancel'] ?? 'Cancel' ?></button>
  </div>
</div>

<!-- 👥 Manage Access Modal -->
<div id="manageAccessModal" class="modal" style="display:none;">
  <div class="modal-content">
    <h3>👥 <?= $lang['manage_list_access'] ?? 'Manage List Access' ?></h3>
    <div id="accessList"><?= $lang['loading'] ?? 'Loading...' ?></div>
    <button onclick="closeModal('manageAccessModal')"><?= $lang['close'] ?? 'Close' ?></button>
  </div>
</div>


<!-- 💬 Chat Trigger Button -->
<!--<button id="chatIconBtn" onclick="openChatFromCurrentList()" title="Open Chat" style="position:fixed; bottom:20px; right:20px; background:#007bff; color:white; border:none; border-radius:50%; width:50px; height:50px; font-size:24px; box-shadow:0 2px 6px rgba(0,0,0,0.3); z-index:1000; touch-action:manipulation;">💬</button>-->


<!-- 💬 Modern Chat Container -->
<div id="chatContainer" class="chat-container" style="display: none;">

  <!-- 🔵 Header -->
  <div class="chat-header">
    <div class="chat-header-left" id="chatHeaderMenuTrigger" title="Chat settings">
      <span id="chatBackArrow" style="display: none; cursor: pointer;" onclick="backToChatView()" title="Back">←</span>
      <i data-lucide="message-circle" class="chat-header-chat-icon"></i>
      <span id="chatHeaderTitle">List Chat</span>
      <button id="chatHeaderMenuBtn" type="button" class="chat-header-icon" onclick="toggleChatHeaderMenu(event)" title="Chat settings">
        <i data-lucide="sliders-horizontal" class="chat-header-settings-icon"></i>
      </button>
      <span class="chat-header-caret">▾</span>
    </div>

    <button class="chat-close-button" onclick="toggleChat(); event.stopPropagation();" title="Close chat">✖</button>
  </div>

  <div id="chatHeaderMenu" class="chat-header-menu" style="display:none;">
    <button type="button" class="chat-header-menu-item" data-action="members" onclick="chatHeaderMenuAction('members'); event.stopPropagation();">
      <i data-lucide="users"></i><span>Members</span>
    </button>
    <button type="button" class="chat-header-menu-item" data-action="rename" onclick="chatHeaderMenuAction('rename'); event.stopPropagation();">
      <i data-lucide="square-pen"></i><span>Conversation name</span>
    </button>
    <button type="button" class="chat-header-menu-item" data-action="add" onclick="chatHeaderMenuAction('add'); event.stopPropagation();">
      <i data-lucide="user-plus"></i><span>Add people</span>
    </button>
    <button type="button" class="chat-header-menu-item" data-action="pause" onclick="chatHeaderMenuAction('pause'); event.stopPropagation();">
      <i data-lucide="pause"></i><span>Pause chat</span>
    </button>
    <button type="button" class="chat-header-menu-item danger" data-action="leave" onclick="chatHeaderMenuAction('leave'); event.stopPropagation();">
      <i data-lucide="log-out"></i><span>Leave chat</span>
    </button>
    <button type="button" class="chat-header-menu-item danger" data-action="delete" onclick="chatHeaderMenuAction('delete'); event.stopPropagation();">
      <i data-lucide="trash-2"></i><span>Delete chat</span>
    </button>
  </div>

  <!-- 🔒 Encryption Notice -->
    <!-- 🔒 Encryption Notice -->
    <div
      class="chat-encryption-banner"
      title="<?= $lang['chat_encrypted_tooltip']
                ?? 'Only people in this chat can read, listen to, or share these messages.' ?>"
    >
      <?= $lang['chat_encrypted_notice']
            ?? 'Messages are end-to-end encrypted.' ?>
    </div>



  <!-- 👥 Invite Manager Panel -->
  <div id="chatInviteWrapper" class="chat-invite-wrapper">
    <?php include_once __DIR__ . '/chatManageInvite.php'; ?>
  </div>

  <!-- 💬 Body -->
  <div id="chatBodyWrapper" class="chat-body">
    <div id="chatMessages"></div>

    <div id="chatInputWrapper" class="chat-input-wrapper">
      <div class="chat-input-actions" aria-label="Chat actions">
        <button id="chatPollSelectBtn" class="chat-bell-button chat-input-action chat-poll-select-btn"
                onclick="chatSelectPoll(event)"
                title="Select poll to share">
          <i data-lucide="bar-chart-3" style="width:16px;height:16px;"></i>
        </button>
        <button id="emojiToggleBtn" class="chat-bell-button chat-input-action"
                onclick="inputAllertBellToMessage()"
                title="Use the bell or ! to alert...">
          <i data-lucide="bell-ring" style="width:16px;height:16px;"></i>
        </button>
      </div>

      <textarea id="chatInput" rows="1" placeholder="Type a message..."></textarea>

      <button onclick="chatSendMessage()" class="send-button" title="Send">
        <img src="/icons/sendBluePlane.svg" alt="Send" width="26" height="26">
      </button>
    </div>
  </div>
</div>




<script>
  const chatInput = document.getElementById("chatInput");

  function autoResizeTextarea(el) {
    el.style.height = "auto"; // reset
    el.style.height = Math.min(el.scrollHeight, 120) + "px"; // up to max
  }

  chatInput.addEventListener("input", () => autoResizeTextarea(chatInput));
</script>

    <script>
      window.translations = <?= json_encode($lang) ?>;
    </script>



<!-- Minimal CSS -->
<link rel="stylesheet" href="/chatStyles.css?v=<?= $version ?>">




<!-- JS Logic -->
<script src="/chatFunctions.js?v=<?= $version ?>"></script>
<script>
  if (window.lucide && typeof window.lucide.createIcons === "function") {
    window.lucide.createIcons();
  }
</script>
