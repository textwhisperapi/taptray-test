<?php
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/functions.php';

sec_session_start();
$cssVersion = (@filemtime(__DIR__ . '/ep_event_planner.css') ?: time()) + 2;
$jsVersion = (@filemtime(__DIR__ . '/ep_event_planner.js') ?: time()) + 2;
$twDateVersion = (@filemtime(__DIR__ . '/assets/tw_flatpickr.js') ?: time()) + 2;
$memberId = (int)($_SESSION['user_id'] ?? 0);
$sessionUsername = $_SESSION['username'] ?? '';
$locale = strtolower(trim((string)($_SESSION['locale'] ?? 'en')));
$ownerToken = trim($_GET['owner'] ?? '');
$ownerId = $memberId;
if ($ownerToken !== '') {
  $normalizedOwnerToken = $ownerToken;
  if (str_starts_with($normalizedOwnerToken, 'invited-')) {
    $normalizedOwnerToken = substr($normalizedOwnerToken, strlen('invited-'));
  }
  $stmt = $mysqli->prepare("SELECT owner_id FROM content_lists WHERE token = ? LIMIT 1");
  $stmt->bind_param("s", $normalizedOwnerToken);
  $stmt->execute();
  $stmt->bind_result($resolvedOwnerId);
  $stmt->fetch();
  $stmt->close();
  if (!empty($resolvedOwnerId)) {
    $ownerId = (int)$resolvedOwnerId;
  } else {
    $stmt = $mysqli->prepare("SELECT id FROM members WHERE username = ? LIMIT 1");
    $stmt->bind_param("s", $normalizedOwnerToken);
    $stmt->execute();
    $stmt->bind_result($resolvedOwnerId);
    $stmt->fetch();
    $stmt->close();
    if (!empty($resolvedOwnerId)) {
      $ownerId = (int)$resolvedOwnerId;
    }
  }
}
$listTokenForRole = $ownerToken !== '' ? $ownerToken : $sessionUsername;
$roleRank = $sessionUsername ? get_user_list_role_rank($mysqli, $listTokenForRole, $sessionUsername) : 0;
if ($roleRank < 80 && str_starts_with($listTokenForRole, 'invited-')) {
  $fallbackToken = substr($listTokenForRole, strlen('invited-'));
  if ($fallbackToken !== '') {
    $roleRank = max($roleRank, (int)get_user_list_role_rank($mysqli, $fallbackToken, $sessionUsername));
  }
}
$canManage = ($memberId && $ownerId === $memberId) || ($roleRank >= 90);
if (!$canManage && $memberId) {
  $stmt = $mysqli->prepare("
    SELECT cl.owner_id
    FROM content_lists cl
    JOIN invitations i ON i.listToken = cl.token
    JOIN members m ON m.email = i.email
    WHERE cl.token = ? AND m.id = ? AND i.role_rank >= 90
    LIMIT 1
  ");
  $stmt->bind_param("si", $ownerToken, $memberId);
  $stmt->execute();
  $stmt->bind_result($invitedOwnerId);
  $stmt->fetch();
  $stmt->close();
  if (!empty($invitedOwnerId) && (int)$invitedOwnerId === (int)$ownerId) {
    $canManage = true;
  }
}
?>
<!doctype html>
<html lang="is">
<head>
  <meta charset="utf-8">
  <title>Event Planner</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/myStyles.css?v=<?= $cssVersion ?>">
  <link rel="stylesheet" href="/ep_event_planner.css?v=<?= $cssVersion ?>">
</head>
<body>
  <main class="ep-shell"
        data-member-id="<?= htmlspecialchars((string)$memberId, ENT_QUOTES, 'UTF-8') ?>"
        data-owner-id="<?= htmlspecialchars((string)$ownerId, ENT_QUOTES, 'UTF-8') ?>"
        data-owner="<?= htmlspecialchars($ownerToken, ENT_QUOTES, 'UTF-8') ?>"
        data-username="<?= htmlspecialchars($sessionUsername, ENT_QUOTES, 'UTF-8') ?>"
        data-locale="<?= htmlspecialchars($locale, ENT_QUOTES, 'UTF-8') ?>"
        data-can-manage="<?= $canManage ? '1' : '0' ?>">
    <details class="ep-section ep-hero-section" id="epHeroSection" open>
      <summary class="ep-section-summary ep-hero-section-summary">
        <span class="ep-hero-title-line">Event Planner</span>
        <div class="ep-hero-avatars" id="epChoirPanel"></div>
      </summary>
      <div class="ep-hero-body">
        <div class="ep-hero-callout" id="epHeroCallout">
          <div class="ep-hero-callout-layout">
            <div class="ep-hero-callout-content" id="epHeroCalloutDisplay">
              <div class="ep-hero-callout-label">Important messages</div>
              <div class="ep-hero-callout-title" id="epHeroCalloutTitle">Next events</div>
              <div class="ep-hero-callout-meta" id="epHeroCalloutMeta">No event scheduled yet.</div>
              <p class="ep-hero-callout-notes" id="epHeroCalloutNotes">Create an event note to show callouts here.</p>
              <div class="ep-hero-callout-actions">
                <button class="ep-btn warm ep-inline-checkin" type="button" id="epHeroCalloutCheckinBtn">Check in</button>
                <button class="ep-btn ghost ep-inline-checkin" type="button" id="epHeroCalloutEditBtn">Edit</button>
              </div>
            </div>
            <div class="ep-hero-callout-media ep-hidden" id="epHeroCalloutMediaWrap">
              <img id="epHeroCalloutImage" src="" alt="Event image">
            </div>
          </div>
          <div class="ep-hero-comments ep-hidden" id="epHeroCalloutComments">
            <div class="ep-hero-comments-list" data-role="comment-list"></div>
            <form class="ep-hero-comments-form" data-role="comment-form">
              <input type="text" maxlength="1000" placeholder="Add event comment..." data-role="comment-input">
              <button class="ep-btn ghost" type="submit">Send</button>
            </form>
          </div>
          <form class="ep-hero-callout-edit ep-hidden" id="epHeroCalloutEditForm">
            <div class="ep-panel-sub">Edit current/next events message</div>
            <label class="ep-field">
              <span>Message</span>
              <textarea id="epHeroEditNotes" rows="3" maxlength="2000" placeholder="Event message"></textarea>
            </label>
            <input type="hidden" id="epHeroEditImageUrl">
            <div class="ep-image-uploader" id="epHeroImageUploader">
              <input class="ep-image-file-input" type="file" id="epHeroEditImageFile" accept="image/*">
              <button class="ep-btn ghost ep-image-file-trigger" type="button" id="epHeroEditImageSelectBtn">Select image</button>
              <div class="ep-image-dropzone" id="epHeroImageDropzone">Paste, drop, or select image</div>
              <div class="ep-image-preview ep-hidden" id="epHeroImagePreview">
                <img id="epHeroImagePreviewImg" src="" alt="Event image preview">
              </div>
            </div>
            <div class="ep-inline-edit-actions">
              <button class="ep-btn" type="submit">Save message</button>
              <button class="ep-btn ghost" type="button" id="epHeroCalloutEditCancel">Cancel</button>
            </div>
          </form>
          <div class="ep-calendar-popout ep-hero-popout" id="epHeroEventPopout">
            <button class="ep-calendar-popout-close" type="button" aria-label="Close">×</button>
          </div>
        </div>
        <div class="ep-hero-callout-window ep-hidden" id="epHeroCalloutWindow"></div>
        <div class="ep-hero-next-days ep-hero-next-days-bottom">
          <span>Show next days</span>
          <select id="epTopNextDays">
            <option value="7" selected>7</option>
            <option value="14">14</option>
            <option value="30">30</option>
            <option value="60">60</option>
            <option value="90">90</option>
            <option value="180">180</option>
          </select>
        </div>
      </div>
    </details>

    <details class="ep-section ep-calendar" id="epCalendarSection">
      <summary class="ep-section-summary ep-calendar-summary">
        <span>Event Calendar</span>
        <span class="ep-calendar-actions">
          <button class="ep-btn ghost ep-calendar-nav" id="epCalendarPrev" type="button" aria-label="Previous months">‹</button>
          <button class="ep-btn ghost ep-calendar-nav" id="epCalendarNext" type="button" aria-label="Next months">›</button>
        </span>
      </summary>
      <div class="ep-calendar-panel">
        <div class="ep-calendar-track" id="epCalendarTrack"></div>
        <div class="ep-calendar-popout" id="epCalendarPopout">
          <button class="ep-calendar-popout-close" type="button" aria-label="Close">×</button>
        </div>
      </div>
    </details>

    <details class="ep-section ep-polls" id="epPollsSection">
      <summary class="ep-section-summary">
        <span>Polls</span>
        <span class="ep-panel-sub">Answer polls</span>
      </summary>
      <div class="ep-panel">
        <div class="ep-panel-head">
          <h2>Event Polls</h2>
          <button class="ep-btn" id="epTogglePollForm" type="button">Create poll</button>
        </div>
        <form class="ep-form ep-poll-form ep-hidden" id="epPollForm">
          <label class="ep-field">
            <span>Event</span>
            <select id="epPollEventId" name="event_id" required></select>
          </label>
          <input type="text" id="epPollQuestion" name="question" placeholder="Poll question" maxlength="255" required>
          <label class="ep-field ep-recurring-check">
            <input type="checkbox" id="epPollAllowMultiple" name="allow_multiple">
            <span>Allow multiple choices</span>
          </label>
          <label class="ep-field">
            <span>Options (one per line)</span>
            <textarea id="epPollOptions" name="options" rows="4" placeholder="Option A&#10;Option B&#10;Option C" required></textarea>
          </label>
          <button class="ep-btn" type="submit">Create poll</button>
        </form>
        <div class="ep-poll-list" id="epPollList"></div>
      </div>
    </details>

    <details class="ep-section" id="epGroupsSection">
      <summary class="ep-section-summary">
        <span>Event Groups</span>
        <span class="ep-panel-sub">Groups + members</span>
      </summary>
      <div class="ep-group-grid">
        <div class="ep-panel ep-groups-panel">
          <div class="ep-panel-head">
            <h2>Event Groups</h2>
            <div class="ep-panel-actions">
              <span class="ep-panel-sub">Create and select</span>
              <button class="ep-btn" id="epToggleGroupForm" type="button">Add group</button>
              <button class="ep-btn ghost ep-btn-sm" id="epToggleGroupDefaultsBtn" type="button" title="Settings panel" aria-label="Settings panel">✱</button>
            </div>
          </div>
          <div class="ep-inline-edit ep-hidden ep-group-defaults-popout" id="epGroupDefaultsPanel">
            <div class="ep-group-defaults-head">
              <div class="ep-panel-sub">Settings panel</div>
              <button class="ep-btn ghost ep-btn-sm" type="button" id="epCloseGroupDefaultsBtn" aria-label="Close default settings">✕</button>
            </div>
            <div class="ep-inline-edit" id="epOwnerProfileSettingsRow">
              <div class="ep-panel-sub">Profile settings for this planner</div>
              <div class="ep-inline-edit-actions">
                <select class="ep-input" id="epOwnerProfileTypeSelect">
                  <option value="person">Person</option>
                  <option value="group">Group</option>
                </select>
                <select class="ep-input" id="epOwnerGroupTypeSelect">
                  <option value="">None</option>
                  <option value="mixed">Mixed</option>
                  <option value="men">Men</option>
                  <option value="women">Women</option>
                </select>
                <select class="ep-input" id="epOwnerLanguageSelect">
                  <option value="en">🇬🇧 English</option>
                  <option value="is">🇮🇸 Íslenska</option>
                  <option value="da">🇩🇰 Dansk</option>
                  <option value="no">🇳🇴 Norsk</option>
                  <option value="sv">🇸🇪 Svenska</option>
                  <option value="de">🇩🇪 Deutsch</option>
                  <option value="fr">🇫🇷 Français</option>
                  <option value="pl">🇵🇱 Polski</option>
                  <option value="es">🇪🇸 Español</option>
                  <option value="it">🇮🇹 Italiano</option>
                  <option value="zh">🇨🇳 中文</option>
                </select>
                <button class="ep-btn ghost" type="button" id="epSaveOwnerProfileBtn">Save profile</button>
              </div>
            </div>
            <div class="ep-inline-edit-actions" id="epAllDefaultsActionRow">
              <span class="ep-panel-sub">0. Create all defaults</span>
              <button class="ep-btn ghost" type="button" id="epCreateAllDefaultsBtn">Create all</button>
            </div>
            <div class="ep-inline-edit ep-hidden ep-settings-confirm-popout" id="epAllDefaultsConfirmPanel">
              <div class="ep-panel-sub">Confirm all defaults</div>
              <div class="ep-inline-edit-actions">
                <button class="ep-btn ghost" type="button" id="epConfirmAllDefaultsBtn">Confirm create all</button>
                <button class="ep-btn ghost" type="button" id="epCancelAllDefaultsBtn">Cancel</button>
              </div>
              <div class="ep-panel-sub" id="epAllDefaultsPreview"></div>
            </div>
            <div class="ep-inline-edit-actions" id="epChoirRolesActionRow">
              <span class="ep-panel-sub">1. Create from roles</span>
              <button class="ep-btn ghost" type="button" id="epConvertChoirRolesBtn">Show and confirm roles</button>
            </div>
            <div class="ep-inline-edit ep-hidden ep-settings-confirm-popout" id="epChoirRolesConfirmPanel">
              <div class="ep-panel-sub">Confirm role groups from roles</div>
              <div class="ep-inline-edit-actions">
                <button class="ep-btn ghost" type="button" id="epConfirmChoirRolesBtn">Confirm create</button>
                <button class="ep-btn ghost" type="button" id="epCancelChoirRolesBtn">Cancel</button>
              </div>
              <div class="ep-panel-sub" id="epChoirRolesPreview"></div>
            </div>
            <div class="ep-inline-edit-actions" id="epChoirDefaultsActionRow">
              <span class="ep-panel-sub">2. Create default groups</span>
              <button class="ep-btn ghost" type="button" id="epCreateChoirDefaultsBtn">Create defaults</button>
            </div>
            <div class="ep-inline-edit ep-hidden ep-settings-confirm-popout" id="epChoirDefaultsConfirmPanel">
              <div class="ep-panel-sub">Confirm default choir groups</div>
              <div class="ep-inline-edit-actions">
                <select class="ep-input" id="epChoirTypeSelect">
                  <option value="mixed">Mixed choir</option>
                  <option value="men">Men's choir</option>
                  <option value="women">Women's choir</option>
                </select>
                <button class="ep-btn ghost" type="button" id="epConfirmChoirDefaultsBtn">Confirm create</button>
                <button class="ep-btn ghost" type="button" id="epCancelChoirDefaultsBtn">Cancel</button>
              </div>
              <div class="ep-panel-sub" id="epChoirTypePreview"></div>
            </div>
            <div class="ep-inline-edit-actions" id="epCategoryDefaultsActionRow">
              <span class="ep-panel-sub">3. Create default categories</span>
              <button class="ep-btn ghost" type="button" id="epCreateCategoryDefaultsBtn">Create defaults</button>
            </div>
            <div class="ep-inline-edit ep-hidden ep-settings-confirm-popout" id="epCategoryDefaultsConfirmPanel">
              <div class="ep-panel-sub">Confirm default categories</div>
              <div class="ep-inline-edit-actions">
                <select class="ep-input" id="epCategoryDefaultsLangSelect">
                  <option value="is">Icelandic (IS)</option>
                  <option value="en">English (EN)</option>
                </select>
                <button class="ep-btn ghost" type="button" id="epConfirmCategoryDefaultsBtn">Confirm create</button>
                <button class="ep-btn ghost" type="button" id="epCancelCategoryDefaultsBtn">Cancel</button>
              </div>
              <div class="ep-panel-sub" id="epCategoryDefaultsPreview"></div>
            </div>
            <div class="ep-inline-edit-actions" id="epListDefaultsActionRow">
              <span class="ep-panel-sub">4. Create default lists</span>
              <button class="ep-btn ghost" type="button" id="epCreateListDefaultsBtn">Create defaults</button>
            </div>
            <div class="ep-inline-edit ep-hidden ep-settings-confirm-popout" id="epListDefaultsConfirmPanel">
              <div class="ep-panel-sub">Confirm default lists</div>
              <div class="ep-inline-edit-actions">
                <button class="ep-btn ghost" type="button" id="epConfirmListDefaultsBtn">Confirm create</button>
                <button class="ep-btn ghost" type="button" id="epCancelListDefaultsBtn">Cancel</button>
              </div>
              <div class="ep-panel-sub" id="epListDefaultsPreview"></div>
            </div>
          </div>
          <form class="ep-inline-edit ep-hidden" id="epGroupForm">
            <div class="ep-panel-sub">Add group</div>
            <div class="ep-group-edit-topline">
              <label class="ep-category-color" title="Group color">
                <input type="color" name="color" value="#9fb7f0" aria-label="Group color">
              </label>
              <input type="text" name="name" placeholder="Group name" required>
              <label class="ep-field ep-recurring-check">
                <input type="checkbox" name="is_role_group">
                <span>Role group</span>
              </label>
            </div>
            <label class="ep-field">
              <span>Short description</span>
              <input type="text" name="description" placeholder="Short description">
            </label>
            <div class="ep-inline-edit-actions">
              <button class="ep-btn" type="submit">Save group</button>
              <button class="ep-btn ghost" type="button" id="epCancelGroupCreate">Cancel</button>
            </div>
          </form>
          <div class="ep-group-list" id="epGroupList"></div>
        </div>

        <div class="ep-panel">
          <div class="ep-panel-head">
            <h2>Members in group</h2>
            <div class="ep-panel-actions">
              <span class="ep-panel-sub" id="epMemberGroupLabel">Select a group</span>
              <button class="ep-btn ghost" id="epRefreshGroupMembersBtn" type="button" disabled>Refresh</button>
              <button class="ep-btn ghost" id="epEditGroupBtn" type="button" disabled>Edit group</button>
              <button class="ep-btn ghost" id="epDeleteGroupBtn" type="button" disabled>Delete group</button>
            </div>
          </div>
          <div class="ep-panel-sub ep-member-filter">
            <input class="ep-input ep-member-search" id="epMemberSearch" type="search" placeholder="Search members">
          </div>
          <form class="ep-inline-edit ep-hidden" id="epGroupEditForm">
            <div class="ep-panel-sub">Edit group</div>
            <div class="ep-group-edit-topline">
              <label class="ep-category-color" title="Group color">
                <input type="color" name="color" value="#9fb7f0" aria-label="Group color">
              </label>
              <input type="text" name="name" placeholder="Group name" required>
              <label class="ep-field ep-recurring-check">
                <input type="checkbox" name="is_role_group">
                <span>Role group</span>
              </label>
            </div>
            <label class="ep-field">
              <span>Short description</span>
              <input type="text" name="description" placeholder="Short description">
            </label>
            <div class="ep-inline-edit-actions">
              <button class="ep-btn" type="submit">Save group</button>
              <button class="ep-btn ghost" type="button" id="epCancelGroupEdit">Cancel</button>
            </div>
          </form>
          <div class="ep-member-list" id="epGroupMembers"></div>
          <div class="ep-invite-panel">
            <div class="ep-panel-sub">Add invited members</div>
            <div class="ep-invite-compose">
              <textarea id="epInviteEmails" rows="3" placeholder="Invite members by email (comma or newline separated)..."></textarea>
              <div class="ep-invite-actions">
                <button class="ep-btn ghost" id="epInviteSend" type="button">Invite</button>
              </div>
            </div>
            <div class="ep-invite-toolbar">
              <button class="ep-btn ghost ep-invite-refresh" id="epInviteRefresh" type="button">Reload invited members</button>
              <input class="ep-input ep-invite-search" id="epInviteSearch" type="search" placeholder="Search members">
            </div>
            <div class="ep-invite-list" id="epInviteList"></div>
          </div>
        </div>
      </div>
    </details>

    <details class="ep-section ep-category-settings" id="epCategorySettings">
      <summary class="ep-section-summary">
        <span>Category settings</span>
        <span class="ep-panel-sub">Define available categories and colors</span>
      </summary>
      <div class="ep-panel">
        <div class="ep-category-settings-list" id="epCategorySettingsList"></div>
        <form class="ep-category-settings-form" id="epCategorySettingsForm">
          <input type="text" name="category" placeholder="Category id">
          <input type="text" name="description" placeholder="Description">
          <input type="color" name="color" value="#9fb7f0" aria-label="Category color">
          <button class="ep-btn" type="submit">Add category</button>
        </form>
      </div>
    </details>

    <details class="ep-section ep-events">
      <summary class="ep-section-summary">
        <span>Events</span>
        <span class="ep-panel-sub">One line per event</span>
      </summary>
      <div class="ep-panel ep-events">
        <div class="ep-event-controls">
        <button class="ep-btn" id="epToggleEventForm" type="button">Create event</button>
        <label class="ep-filter-toggle">
          <input type="checkbox" id="epFilterMyEvents">
          My events
        </label>
        <label class="ep-filter-date">
          <span>From</span>
          <input type="date" id="epFilterFromDate">
        </label>
        <label class="ep-filter-date">
          <span>Group</span>
          <select id="epFilterGroup"></select>
        </label>
        <label class="ep-filter-date">
          <span>Category</span>
          <select id="epFilterCategory"></select>
        </label>
        </div>
        <form class="ep-form ep-form-grid ep-hidden" id="epEventForm">
          <div class="ep-form-col">
            <input type="text" name="title" placeholder="Event title" required>
            <input type="text" name="location" placeholder="Location">
            <label class="ep-field">
              <span>Starts</span>
              <input type="datetime-local" name="starts_at" step="60" required>
            </label>
            <label class="ep-field">
              <span>Ends</span>
              <input type="datetime-local" name="ends_at" step="60">
            </label>
          </div>
          <div class="ep-form-col">
            <div class="ep-category-picker" data-role="category-picker">
              <input type="hidden" name="category" value="">
              <button class="ep-category-trigger" type="button" data-role="category-trigger">
                <span class="ep-category-dot" aria-hidden="true"></span>
                <span>Category</span>
              </button>
              <div class="ep-category-dropdown" data-role="category-dropdown"></div>
            </div>
            <label class="ep-field ep-recurring-toggle ep-recurring-check">
              <input type="checkbox" name="recurring">
              <span>Recurring</span>
            </label>
            <label class="ep-field ep-recurring-field ep-hidden">
              <span>Frequency</span>
              <select name="recurring_frequency">
                <option value="weekly">Weekly</option>
                <option value="monthly">Monthly</option>
              </select>
            </label>
            <label class="ep-field ep-recurring-field ep-hidden">
              <span>Repeat until</span>
              <input type="date" name="recurring_until">
            </label>
            <label class="ep-field ep-recurring-field ep-hidden ep-recurring-check">
              <input type="checkbox" name="rotate_groups">
              <span>Rotate groups (one per event)</span>
            </label>
            <div>
              <div class="ep-panel-sub">Groups for this event</div>
              <div class="ep-group-picker" id="epEventGroupPicker"></div>
            </div>
          </div>
          <textarea class="ep-form-full" name="notes" rows="3" placeholder="Notes (optional): key points, callouts, reminders"></textarea>
          <input class="ep-form-full" type="hidden" name="image_url">
          <div class="ep-form-full ep-image-uploader" data-role="event-image-uploader">
            <input class="ep-image-file-input" type="file" name="image_file" accept="image/*">
            <button class="ep-btn ghost ep-image-file-trigger" type="button" data-role="image-select">Select image</button>
            <div class="ep-image-dropzone" data-role="image-drop">Paste, drop, or select image</div>
            <div class="ep-image-preview ep-hidden" data-role="image-preview">
              <img src="" alt="Event image preview">
            </div>
          </div>
          <button class="ep-btn ep-form-full" type="submit">Save event</button>
        </form>
        <div class="ep-event-list" id="epEventList"></div>
      </div>
    </details>

    <details class="ep-section ep-attendance">
      <summary class="ep-section-summary">
        <span>Attendant Report</span>
        <span class="ep-panel-sub">List by year or period</span>
      </summary>
      <div class="ep-panel ep-attendance-panel">
        <div class="ep-attendance-controls">
          <label class="ep-field">
            <span>Mode</span>
            <select id="epAttendanceMode">
              <option value="year">Year</option>
              <option value="period">Period</option>
            </select>
          </label>
          <label class="ep-field" id="epAttendanceYearWrap">
            <span>Year</span>
            <input type="number" id="epAttendanceYear" min="1970" max="2200" step="1">
          </label>
          <label class="ep-field ep-hidden" id="epAttendanceFromWrap">
            <span>From</span>
            <input type="date" id="epAttendanceFrom">
          </label>
          <label class="ep-field ep-hidden" id="epAttendanceToWrap">
            <span>To</span>
            <input type="date" id="epAttendanceTo">
          </label>
          <label class="ep-field">
            <span>Group</span>
            <select id="epAttendanceGroup">
              <option value="">All groups</option>
            </select>
          </label>
          <label class="ep-field">
            <span>Category</span>
            <select id="epAttendanceCategory">
              <option value="">All categories</option>
            </select>
          </label>
          <button class="ep-btn" id="epAttendanceLoad" type="button">Load</button>
        </div>
        <div class="ep-panel-sub" id="epAttendanceSummary"></div>
        <div class="ep-attendance-list" id="epAttendanceList"></div>
        <div class="ep-calendar-popout" id="epAttendancePopout">
          <button class="ep-calendar-popout-close" type="button" aria-label="Close">×</button>
        </div>
      </div>
    </details>
  </main>

  <script src="/assets/tw_flatpickr.js?v=<?= $twDateVersion ?>" defer></script>
  <script src="/ep_event_planner.js?v=<?= $jsVersion ?>"></script>
</body>
</html>
