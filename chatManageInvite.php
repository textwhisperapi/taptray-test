<div id="chatInviteManager" style="
  display: flex;
  flex-direction: column;
  height: 100%;
  box-sizing: border-box;
  font-size: 12px;
">

  <!-- 🔒 Only shown to owner/editor -->
  <div id="inviteInputWrapper" style="padding: 0 8px;">
    <textarea id="bulkInviteEmails"
      rows="4"
      placeholder="<?= $lang['bulk_invite_placeholder'] ?? 'Invite members to the list. Write or paste email(s) here (comma or newline separated)...' ?>"
      style="width: 100%; resize: vertical; font-size: 12px; margin-bottom: 6px;"></textarea>


    <div style="display: flex; justify-content: flex-end;">
      <button onclick="sendBulkInvites()" style="font-size: 12px;">📨 Invite</button>
    </div>
  </div>

  <!-- List below -->
  <div id="chatInviteList"
       style="flex: 1; overflow-y: auto; padding: 0 4px 4px;">
    <p>Loading invited users...</p>
  </div>

</div>
