<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/sub_plans.php';
require_once __DIR__ . '/includes/translate.php';
require_once __DIR__ . "/includes/sub_functions.php";



sec_session_start();


$version = $version ?? time();

if (!isset($_SESSION['user_id'])) {
    header("Location: /login.php");
    exit;
}

$userId = $_SESSION['user_id'];
$avatarOnboarding = isset($_GET['avatar_onboarding']) && $_GET['avatar_onboarding'] === '1';
$nextAfterOnboarding = trim((string)($_GET['next'] ?? '/'));
if (!preg_match('#^/[\w\-\/\?\=\&%\.]*$#', $nextAfterOnboarding)) {
    $nextAfterOnboarding = '/';
}
$planKey = $_SESSION['plan'] ?? 'free';

//Fetch measures
$storageStats = getUserStorageStats($mysqli, $userId);
$userCount    = getTeamMemberCount($mysqli, $userId);
$listCount    = getUserListsCount($mysqli, $userId);
$itemCount    = getUserItemsCount($mysqli, $userId);

$hasMemberGroupType = false;
$colRes = $mysqli->query("SHOW COLUMNS FROM members LIKE 'group_type'");
if ($colRes && $colRes->num_rows > 0) {
    $hasMemberGroupType = true;
}
if ($colRes) {
    $colRes->free();
}




// 🔍 Fetch user info from DB
// 🔍 Fetch user info + plan from DB
$selectGroupType = $hasMemberGroupType ? ", group_type" : "";
$stmt = $mysqli->prepare("
    SELECT username, email, display_name, avatar_url, locale, plan, storage_addon, fileserver, home_mode, home_page,
           profile_type{$selectGroupType}
    FROM members
    WHERE id = ?
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$groupType = '';
if ($hasMemberGroupType) {
    $stmt->bind_result(
        $username,
        $email,
        $displayName,
        $avatar,
        $locale,
        $planFromDb,
        $storageAddonFromDb,
        $fileserverFromDb,
        $homeMode,
        $homePage,
        $profileType,
        $groupType
    );
} else {
    $stmt->bind_result(
        $username,
        $email,
        $displayName,
        $avatar,
        $locale,
        $planFromDb,
        $storageAddonFromDb,
        $fileserverFromDb,
        $homeMode,
        $homePage,
        $profileType
    );
}
$stmt->fetch();
$stmt->close();


// Defaults
$username    = $username ?? 'unknown';
$email       = $email ?? 'not set';
$displayName = $displayName ?? $username;
$avatar      = $avatar ?: '/default-avatar.png';
$needsAvatarOnboarding = empty($avatar) || stripos((string)$avatar, 'default-avatar.png') !== false;
$locale      = $locale ?: 'en';
$fileserverFromDb = $fileserverFromDb ?: 'cloudflare';
// $homeMode = $homeMode ?: 'page';
$homeMode = $homeMode ?: 'default';
$homePage = $homePage ?: '';
$profileType = $profileType ?: 'person';
$groupType = $groupType ?: '';

// Always use plan directly from DB for display
$currentPlanLabel = $planFromDb ?: 'free';


// Update session with latest plan
$planKey = $planFromDb ?: 'free';
$_SESSION['plan'] = $planKey;

// Get plan details using DB values
$currentPlan = getUserPlan($userId, $planKey, $mysqli, $PLANS);
$currentPlan['storage_limit'] = $storageAddonFromDb ?: $currentPlan['storage_limit'];



// 🔐 Stripe Billing Portal
$stripeCustomerId = $_SESSION['stripe_customer_id'] ?? null;
$billingPortalUrl = null;

if ($stripeCustomerId && defined('STRIPE_SECRET_KEY')) {
    \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
    $session = \Stripe\BillingPortal\Session::create([
        'customer' => $stripeCustomerId,
        'return_url' => 'https://' . $_SERVER['HTTP_HOST'] . '/sub_settings.php',
    ]);
    $billingPortalUrl = $session->url;
}

$nextBillingDate = null;

if (!empty($_SESSION['stripe_customer_id'])) {
    try {
        \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
        $customer = \Stripe\Customer::retrieve($_SESSION['stripe_customer_id']);

        $subscriptions = $customer->subscriptions->data ?? [];
        if (!empty($subscriptions)) {
            $sub = $subscriptions[0];
            $nextBillingDate = date("F j, Y", $sub->current_period_end);
        }
    } catch (Exception $e) {
        $nextBillingDate = null;
    }
}


// ✅ Load language file
$langFile = __DIR__ . "/lang/{$locale}.php";
$lang = file_exists($langFile) ? include $langFile : [];

if ($avatarOnboarding && !$needsAvatarOnboarding) {
    header("Location: " . $nextAfterOnboarding);
    exit;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>My Settings</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="/sub_settings.css?v=<?= htmlspecialchars($version) ?>">
  <link rel="stylesheet" href="/login.css?v=<?= $version ?>">

  <script>
  document.addEventListener("DOMContentLoaded", () => {
    // 🔔 Notifications
    const notify = document.getElementById("notifyEnabled");
    const sound = document.getElementById("soundEnabled");
    const settings = JSON.parse(localStorage.getItem("notifSettings") || "{}");
    notify.checked = settings.enabled ?? true;
    sound.checked = settings.sound ?? true;
    notify.addEventListener("change", () => {
      settings.enabled = notify.checked;
      localStorage.setItem("notifSettings", JSON.stringify(settings));
    });
    sound.addEventListener("change", () => {
      settings.sound = sound.checked;
      localStorage.setItem("notifSettings", JSON.stringify(settings));
    });

    // 🌐 Language (saved to DB)
    const lang = document.getElementById("languageSelect");
    const tz = document.getElementById("timezoneSelect");

    lang.value = "<?= htmlspecialchars($locale) ?>";
    tz.value = localStorage.getItem("twTimezone")
      || Intl.DateTimeFormat().resolvedOptions().timeZone
      || "UTC";
    tz.addEventListener("change", () => {
      localStorage.setItem("twTimezone", tz.value);
    });

    lang.addEventListener("change", async () => {
      const selected = lang.value;
      await fetch("/sub_update_user_language.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: "locale=" + encodeURIComponent(selected)
      });
      location.reload();
    });

    // 🪪 Profile
    const profileForm = document.getElementById("profileForm");
    const displayNameInput = document.getElementById("displayNameInput");
    const avatarUrlInput = document.getElementById("avatarUrlInput");
    const profileStatus = document.getElementById("profileStatus");
    const profileSaveBtn = document.getElementById("profileSaveBtn");
    const profileAvatar = document.getElementById("profileAvatar");
    const profileDisplayName = document.getElementById("profileDisplayName");
    const avatarEditBtn = document.getElementById("avatarEditBtn");
    const avatarDialog = document.getElementById("avatarDialog");
    const avatarCloseBtn = document.getElementById("avatarCloseBtn");
    const avatarFileInput = document.getElementById("avatarFileInput");
    const avatarUploadBtn = document.getElementById("avatarUploadBtn");
    const avatarRemoveBtn = document.getElementById("avatarRemoveBtn");
    const avatarDialogStatus = document.getElementById("avatarDialogStatus");
    const avatarCropper = document.getElementById("avatarCropper");
    const avatarCropCanvas = document.getElementById("avatarCropCanvas");
    const avatarZoom = document.getElementById("avatarZoom");
    const cropCtx = avatarCropCanvas ? avatarCropCanvas.getContext("2d") : null;

    const avatarOnboarding = <?= $avatarOnboarding ? 'true' : 'false' ?>;
    const nextAfterOnboarding = <?= json_encode($nextAfterOnboarding, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const onboardingStatus = document.getElementById("avatarOnboardingStatus");
    const onboardingContinueBtn = document.getElementById("avatarOnboardingContinue");
    const onboardingSkipBtn = document.getElementById("avatarOnboardingSkip");

    const cropState = {
      img: null,
      baseScale: 1,
      zoom: 1,
      offsetX: 0,
      offsetY: 0,
      dragging: false,
      lastX: 0,
      lastY: 0
    };

    if (profileForm) {
      profileForm.addEventListener("submit", async (event) => {
        event.preventDefault();
        profileStatus.textContent = "Saving...";
        profileStatus.style.color = "inherit";
        profileSaveBtn.disabled = true;

        try {
          const res = await fetch("/sub_update_user_profile.php", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body:
              "display_name=" + encodeURIComponent(displayNameInput.value.trim()) +
              "&avatar_url=" + encodeURIComponent(avatarUrlInput.value.trim()) +
              "&profile_type=" + encodeURIComponent(document.getElementById("profileTypeSelect")?.value || "person") +
              "&group_type=" + encodeURIComponent(document.getElementById("groupTypeSelect")?.value || "")
          });

          const data = await res.json().catch(() => ({}));
          if (!res.ok) {
            throw new Error(data.error || "Update failed.");
          }

          profileDisplayName.textContent = data.display_name || displayNameInput.value.trim();
          if (data.avatar_url && profileAvatar) {
            profileAvatar.src = data.avatar_url;
          }
          const typeSelect = document.getElementById("profileTypeSelect");
          if (typeSelect && typeof data.profile_type !== "undefined") {
            typeSelect.value = data.profile_type || "person";
          }
          const groupTypeSelect = document.getElementById("groupTypeSelect");
          if (groupTypeSelect && typeof data.group_type !== "undefined") {
            groupTypeSelect.value = data.group_type || "";
          }
          profileStatus.textContent = "Saved.";
          profileStatus.style.color = "green";
        } catch (err) {
          profileStatus.textContent = err.message || "Update failed.";
          profileStatus.style.color = "darkred";
        } finally {
          profileSaveBtn.disabled = false;
        }
      });
    }

    // 🎶 Group role edits (All Members)
    const roleSelects = Array.from(document.querySelectorAll(".role-edit"));
    const roleCache = new Map();

    const loadDistinctRoles = async (ownerId) => {
      if (roleCache.has(ownerId)) return roleCache.get(ownerId);
      const res = await fetch(`/getMemberRolesDistinct.php?owner_id=${encodeURIComponent(ownerId)}`);
      const data = await res.json().catch(() => ({}));
      const roles = Array.isArray(data.roles) ? data.roles : [];
      roleCache.set(ownerId, roles);
      return roles;
    };

    roleSelects.forEach((select) => {
      const groupId = parseInt(select.dataset.groupId || "0", 10);
      const owner = select.dataset.owner || "";
      const ownerId = parseInt(select.dataset.ownerId || "0", 10);
      const current = select.dataset.current || "";
      if (!groupId || !owner || !ownerId) return;

      loadDistinctRoles(ownerId).then((roles) => {
        const options = roles.filter(r => r && r.trim()).map(r => r.trim());
        select.innerHTML = "";
        select.appendChild(new Option("-", "", current === ""));
        options.forEach((role) => {
          const opt = new Option(role, role, role === current, role === current);
          select.appendChild(opt);
        });
      });

      select.addEventListener("change", async () => {
        await fetch(`/ep_group_members.php?owner=${encodeURIComponent(owner)}`, {
          method: "POST",
          headers: { "Content-Type": "application/x-www-form-urlencoded" },
          body:
            "action=add" +
            "&group_id=" + encodeURIComponent(groupId) +
            "&member_id=" + encodeURIComponent(<?= (int)$userId ?>) +
            "&role=" + encodeURIComponent(select.value.trim())
        });
      });
    });

    const openAvatarDialog = () => {
      if (!avatarDialog) return;
      avatarDialog.classList.add("is-open");
      avatarDialog.setAttribute("aria-hidden", "false");
      if (avatarCropper) {
        avatarCropper.classList.remove("is-visible");
        avatarCropper.setAttribute("aria-hidden", "true");
      }
      if (avatarDialogStatus) {
        avatarDialogStatus.textContent = "";
      }
    };

    const closeAvatarDialog = () => {
      if (!avatarDialog) return;
      avatarDialog.classList.remove("is-open");
      avatarDialog.setAttribute("aria-hidden", "true");
      if (avatarFileInput) avatarFileInput.value = "";
      if (avatarCropper) {
        avatarCropper.classList.remove("is-visible");
        avatarCropper.setAttribute("aria-hidden", "true");
      }
      cropState.img = null;
      cropState.offsetX = 0;
      cropState.offsetY = 0;
    };

    if (avatarEditBtn && avatarDialog) {
      avatarEditBtn.addEventListener("click", openAvatarDialog);
      avatarDialog.addEventListener("click", (event) => {
        if (event.target === avatarDialog) closeAvatarDialog();
      });
    }

    if (avatarCloseBtn) {
      avatarCloseBtn.addEventListener("click", closeAvatarDialog);
    }

    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape" && avatarDialog && avatarDialog.classList.contains("is-open")) {
        closeAvatarDialog();
      }
    });

    const drawCropPreview = () => {
      if (!cropCtx || !avatarCropCanvas || !cropState.img) return;

      const canvasW = avatarCropCanvas.width;
      const canvasH = avatarCropCanvas.height;
      const scale = cropState.baseScale * cropState.zoom;
      const imgW = cropState.img.width * scale;
      const imgH = cropState.img.height * scale;

      const maxOffsetX = Math.max(0, (imgW - canvasW) / 2);
      const maxOffsetY = Math.max(0, (imgH - canvasH) / 2);
      cropState.offsetX = Math.max(-maxOffsetX, Math.min(maxOffsetX, cropState.offsetX));
      cropState.offsetY = Math.max(-maxOffsetY, Math.min(maxOffsetY, cropState.offsetY));

      cropCtx.clearRect(0, 0, canvasW, canvasH);
      cropCtx.drawImage(
        cropState.img,
        (canvasW - imgW) / 2 + cropState.offsetX,
        (canvasH - imgH) / 2 + cropState.offsetY,
        imgW,
        imgH
      );
    };

    const buildCropBlob = async (maxBytes) => {
      if (!cropState.img || !avatarCropCanvas) return null;
      const outputSize = 512;
      const outputCanvas = document.createElement("canvas");
      outputCanvas.width = outputSize;
      outputCanvas.height = outputSize;
      const outCtx = outputCanvas.getContext("2d");

      const baseScale = Math.max(outputSize / cropState.img.width, outputSize / cropState.img.height);
      const scale = baseScale * cropState.zoom;
      const imgW = cropState.img.width * scale;
      const imgH = cropState.img.height * scale;
      const offsetScale = outputSize / avatarCropCanvas.width;
      const offsetX = cropState.offsetX * offsetScale;
      const offsetY = cropState.offsetY * offsetScale;

      outCtx.drawImage(
        cropState.img,
        (outputSize - imgW) / 2 + offsetX,
        (outputSize - imgH) / 2 + offsetY,
        imgW,
        imgH
      );

      const toBlob = (canvas, quality) =>
        new Promise((resolve) => canvas.toBlob(resolve, "image/jpeg", quality));

      let quality = 0.9;
      let blob = await toBlob(outputCanvas, quality);
      while (blob && blob.size > maxBytes && quality > 0.5) {
        quality -= 0.1;
        blob = await toBlob(outputCanvas, quality);
      }
      return blob;
    };

    if (avatarFileInput && avatarCropCanvas && avatarCropper) {
      avatarFileInput.addEventListener("change", () => {
        const file = avatarFileInput.files && avatarFileInput.files[0];
        if (!file) {
          avatarCropper.classList.remove("is-visible");
          avatarCropper.setAttribute("aria-hidden", "true");
          return;
        }

        const reader = new FileReader();
        reader.onload = () => {
          const img = new Image();
          img.onload = () => {
            cropState.img = img;
            cropState.offsetX = 0;
            cropState.offsetY = 0;
            cropState.zoom = 1;
            if (avatarZoom) avatarZoom.value = "1";
            cropState.baseScale = Math.max(
              avatarCropCanvas.width / img.width,
              avatarCropCanvas.height / img.height
            );
            avatarCropper.classList.add("is-visible");
            avatarCropper.setAttribute("aria-hidden", "false");
            drawCropPreview();
          };
          img.src = reader.result;
        };
        reader.readAsDataURL(file);
      });
    }

    if (avatarZoom) {
      avatarZoom.addEventListener("input", () => {
        cropState.zoom = parseFloat(avatarZoom.value) || 1;
        drawCropPreview();
      });
    }

    if (avatarCropCanvas) {
      avatarCropCanvas.addEventListener("pointerdown", (event) => {
        if (!cropState.img) return;
        cropState.dragging = true;
        cropState.lastX = event.clientX;
        cropState.lastY = event.clientY;
        avatarCropCanvas.setPointerCapture(event.pointerId);
      });

      avatarCropCanvas.addEventListener("pointermove", (event) => {
        if (!cropState.dragging) return;
        const dx = event.clientX - cropState.lastX;
        const dy = event.clientY - cropState.lastY;
        cropState.lastX = event.clientX;
        cropState.lastY = event.clientY;
        cropState.offsetX += dx;
        cropState.offsetY += dy;
        drawCropPreview();
      });

      const endDrag = (event) => {
        if (!cropState.dragging) return;
        cropState.dragging = false;
        avatarCropCanvas.releasePointerCapture(event.pointerId);
      };

      avatarCropCanvas.addEventListener("pointerup", endDrag);
      avatarCropCanvas.addEventListener("pointerleave", () => {
        cropState.dragging = false;
      });
    }

    if (avatarUploadBtn) {
      avatarUploadBtn.addEventListener("click", async () => {
        if (!avatarFileInput || !avatarFileInput.files || !avatarFileInput.files[0]) {
          avatarDialogStatus.textContent = "Choose an image first.";
          avatarDialogStatus.style.color = "darkred";
          return;
        }

        const maxBytes = 10 * 1024 * 1024;
        const sourceFile = avatarFileInput.files[0];

        avatarUploadBtn.disabled = true;
        avatarDialogStatus.textContent = "Processing...";
        avatarDialogStatus.style.color = "inherit";

        try {
          let preparedFile = sourceFile;
          if (cropState.img) {
            const blob = await buildCropBlob(maxBytes);
            if (!blob) {
              throw new Error("Could not process image.");
            }
            if (blob.size > maxBytes) {
              throw new Error("Image exceeds 10MB after compression.");
            }
            preparedFile = new File([blob], "avatar.jpg", { type: blob.type });
          } else if (sourceFile.size > maxBytes) {
            throw new Error("Image exceeds 10MB.");
          }

          const formData = new FormData();
          formData.append("avatar", preparedFile);

          avatarDialogStatus.textContent = "Uploading...";
          const res = await fetch("/sub_upload_avatar.php", {
            method: "POST",
            body: formData
          });

          const data = await res.json().catch(() => ({}));
          if (!res.ok) {
            throw new Error(data.error || "Upload failed.");
          }

          avatarUrlInput.value = data.avatar_url || "";
          if (profileAvatar) {
            profileAvatar.src = data.avatar_url;
          }
          avatarDialogStatus.textContent = "Uploaded.";
          avatarDialogStatus.style.color = "green";
          closeAvatarDialog();
        } catch (err) {
          avatarDialogStatus.textContent = err.message || "Upload failed.";
          avatarDialogStatus.style.color = "darkred";
        } finally {
          avatarUploadBtn.disabled = false;
        }
      });
    }

    if (avatarRemoveBtn) {
      avatarRemoveBtn.addEventListener("click", () => {
        avatarUrlInput.value = "";
        if (profileForm) profileForm.requestSubmit();
        closeAvatarDialog();
      });
    }

    if (avatarOnboarding && onboardingContinueBtn) {
      onboardingContinueBtn.addEventListener("click", () => {
        if (window.location.pathname === "/sub_settings.php") {
          window.location.href = nextAfterOnboarding || "/";
        } else {
          window.location.href = "/";
        }
      });
    }

    if (avatarOnboarding && onboardingSkipBtn) {
      onboardingSkipBtn.addEventListener("click", () => {
        window.location.href = nextAfterOnboarding || "/";
      });
    }

    if (avatarOnboarding) {
      if (onboardingStatus) {
        onboardingStatus.textContent = "Add a profile image now, or skip for now.";
      }
      setTimeout(() => {
        openAvatarDialog();
      }, 100);
    }

  });
  </script>
  

  
    <!-- <script>
    document.addEventListener("DOMContentLoaded", () => {
      const fs = document.getElementById("fileServerSelect");
      fs.addEventListener("change", async () => {
        const selected = fs.value;
        await fetch("/sub_update_fileserver.php", {
          method: "POST",
          headers: { "Content-Type": "application/x-www-form-urlencoded" },
          body: "fileserver=" + encodeURIComponent(selected)
        });
        location.reload();
      });
    });
    </script>   -->
  
  
</head>

<body>
  <div class="settings-wrapper">

  <a href="javascript:history.back()" class="back-link">← Back</a>

    <h2>👤 <?= $lang['my_settings'] ?? 'My Settings' ?></h2>

    <?php if ($avatarOnboarding): ?>
      <div class="settings-section" style="border:2px solid #d7e7ff;background:#f7fbff;">
        <h3>Complete your profile</h3>
        <p id="avatarOnboardingStatus" class="setting-hint">Choose an avatar to finish onboarding.</p>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
          <button type="button" id="avatarOnboardingContinue">Continue</button>
          <button type="button" id="avatarOnboardingSkip">Skip for now</button>
        </div>
      </div>
    <?php endif; ?>

    <div class="settings-group">
      <div class="settings-group-head">
        <h3>Account</h3>
        <p class="settings-group-sub">Your profile and identity in TextWhisper.</p>
      </div>

      <div class="settings-section user-info">
        <button type="button" class="avatar-button" id="avatarEditBtn" aria-label="Change avatar">
          <img id="profileAvatar" src="<?= htmlspecialchars($avatar) ?>" alt="Avatar">
          <span class="avatar-edit-label"><?= $lang['change_avatar'] ?? 'Change' ?></span>
        </button>
        <div class="user-meta">
          <h3 id="profileDisplayName"><?= htmlspecialchars($displayName) ?></h3>
          <p><?= htmlspecialchars($email) ?></p>
          <small style="opacity: 0.6;"><?= $lang['user_id'] ?? 'User ID' ?>: <?= htmlspecialchars($username) ?></small>
        </div>
      </div>

      <div class="settings-section">
        <h3>🪪 <?= $lang['profile'] ?? 'Profile' ?></h3>
        <form id="profileForm" class="profile-form">
        <label for="displayNameInput"><?= $lang['display_name'] ?? 'Display name' ?>:</label>
          <input
            type="text"
            id="displayNameInput"
            name="display_name"
            value="<?= htmlspecialchars($displayName) ?>"
            autocomplete="name"
            required
          />

        <input
          type="hidden"
          id="avatarUrlInput"
          name="avatar_url"
          value="<?= htmlspecialchars($avatar) ?>"
        />

        <label for="profileTypeSelect">Profile type:</label>
        <select id="profileTypeSelect" name="profile_type">
          <option value="person" <?= $profileType === 'person' ? 'selected' : '' ?>>Person</option>
          <option value="group" <?= $profileType === 'group' ? 'selected' : '' ?>>Group</option>
        </select>

        <label for="groupTypeSelect">Group type:</label>
        <select id="groupTypeSelect" name="group_type">
          <option value="" <?= $groupType === '' ? 'selected' : '' ?>>None</option>
          <option value="mixed" <?= $groupType === 'mixed' ? 'selected' : '' ?>>Mixed</option>
          <option value="men" <?= $groupType === 'men' ? 'selected' : '' ?>>Men</option>
          <option value="women" <?= $groupType === 'women' ? 'selected' : '' ?>>Women</option>
        </select>

          <div class="profile-actions">
            <button type="submit" id="profileSaveBtn"><?= $lang['save_changes'] ?? 'Save changes' ?></button>
            <span id="profileStatus" class="profile-status" aria-live="polite"></span>
          </div>
        </form>
      </div>

      <div class="settings-section">
        <h3>🎶 Group Roles</h3>
        <p class="setting-hint">Roles in each group that you are a member of.</p>
        <?php
        $stmt = $mysqli->prepare("
            SELECT g.id AS group_id, gm.role, owner.id AS owner_id, owner.username AS owner_username,
                   COALESCE(owner.display_name, owner.username) AS owner_display
            FROM ep_group_members gm
            JOIN ep_groups g ON g.id = gm.group_id
            JOIN members owner ON owner.id = g.created_by_member_id
            WHERE gm.member_id = ? AND g.is_all_members = 1 AND owner.profile_type = 'group'
            ORDER BY owner_display ASC
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res->num_rows > 0) {
            echo "<div class='role-lines'>";
            while ($row = $res->fetch_assoc()) {
                $ownerDisplay = $row['owner_display'] ?: 'All Members';
                $roleValue = trim((string)($row['role'] ?? ''));
                $ownerUser = $row['owner_username'] ?? '';
                $ownerId = (int)($row['owner_id'] ?? 0);
                $roleShown = $roleValue !== '' ? $roleValue : '-';
                echo "<div class='role-line'>
                        <span class='role-group'>" . htmlspecialchars($ownerDisplay) . "</span>
                        <select class='role-edit'
                                data-group-id='" . (int)$row['group_id'] . "'
                                data-owner='" . htmlspecialchars($ownerUser) . "'
                                data-owner-id='" . $ownerId . "'
                                data-current='" . htmlspecialchars($roleValue) . "'>
                          <option value=''>" . htmlspecialchars($roleShown) . "</option>
                        </select>
                      </div>";
            }
            echo "</div>";
        } else {
            echo "<p class='setting-hint'>No group roles found.</p>";
        }
        $stmt->close();
        ?>
      </div>
    </div>

    <div id="avatarDialog" class="avatar-dialog" aria-hidden="true">
      <div class="avatar-dialog-card" role="dialog" aria-modal="true" aria-labelledby="avatarDialogTitle">
        <h4 id="avatarDialogTitle"><?= $lang['avatar'] ?? 'Avatar' ?></h4>
        <p class="setting-hint">
          <?= $lang['avatar_hint'] ?? 'Upload an image from your device.' ?>
        </p>

        <div class="avatar-dialog-section">
          <label for="avatarFileInput"><?= $lang['upload_from_device'] ?? 'Upload from device' ?>:</label>
          <input type="file" id="avatarFileInput" accept="image/*">
          <div id="avatarCropper" class="avatar-cropper" aria-hidden="true">
            <canvas id="avatarCropCanvas" width="180" height="180"></canvas>
            <label for="avatarZoom"><?= $lang['zoom'] ?? 'Zoom' ?>:</label>
            <input type="range" id="avatarZoom" min="1" max="3" step="0.01" value="1">
          </div>
          <button type="button" id="avatarUploadBtn">Save</button>
          <div class="avatar-dialog-actions">
            <button type="button" id="avatarRemoveBtn"><?= $lang['remove'] ?? 'Remove' ?></button>
            <button type="button" id="avatarCloseBtn"><?= $lang['close'] ?? 'Close' ?></button>
          </div>
          <span id="avatarDialogStatus" class="profile-status" aria-live="polite"></span>
        </div>
      </div>
    </div>

    <div class="settings-group">
      <div class="settings-group-head">
        <h3>Preferences</h3>
        <p class="settings-group-sub">Local settings and day-to-day behavior.</p>
      </div>

      <div class="settings-section">
        <div class="section-head">
          <h3>🌐 <?= $lang['preferences'] ?? 'Preferences' ?></h3>
          <span class="section-chip">Auto-saves</span>
        </div>
        <div class="section-body">
          <label>
            <?= $lang['language'] ?? 'Language' ?>:
            <select name="language" id="languageSelect">
                <option value="en">🇬🇧 English (English)</option>
                <option value="is">🇮🇸 Íslenska (Icelandic)</option>
                <option value="da">🇩🇰 Dansk (Danish)</option>
                <option value="no">🇳🇴 Norsk (Norwegian)</option>
                <option value="sv">🇸🇪 Svenska (Swedish)</option>
                <option value="de">🇩🇪 Deutsch (German)</option>
                <option value="fr">🇫🇷 Français (French)</option>
                <option value="pl">🇵🇱 Polski (Polish)</option>
                <option value="es">🇪🇸 Español (Spanish)</option>
                <option value="it">🇮🇹 Italiano (Italian)</option>
                <option value="zh">🇨🇳 中文 (Chinese)</option>
            </select>
          </label>

          <label>
            <?= $lang['timezone'] ?? 'Timezone' ?>:
            <select name="timezone" id="timezoneSelect">
              <option value="UTC">UTC</option>
              <option value="Atlantic/Reykjavik">Reykjavík</option>
              <option value="Europe/Oslo">Oslo</option>
              <option value="America/New_York">New York</option>
            </select>
          </label>
          <p class="setting-hint">Language saves instantly. Timezone is stored on this device.</p>
        </div>
      </div>
      
      <div class="settings-section">
        <div class="section-head">
          <h3>🔔 <?= $lang['notifications'] ?? 'Notifications' ?></h3>
          <span class="section-chip">Auto-saves</span>
        </div>
        <div class="section-body">
          <label>
            <input type="checkbox" id="notifyEnabled">
            <?= $lang['enable_notifications'] ?? 'Enable push notifications' ?>
          </label>
          
          <label>
            <input type="checkbox" id="soundEnabled">
            <?= $lang['play_sound'] ?? 'Play sound for alerts' ?>
          </label>
        </div>
      </div>    
    </div>
        
    <div class="settings-group">
      <div class="settings-group-head">
        <h3>Storage & Home</h3>
        <p class="settings-group-sub">File handling and your default landing page.</p>
      </div>

      <div class="settings-section">
        <div class="section-head">
          <h3>📂 File Server</h3>
          <span class="section-chip">Auto-saves</span>
        </div>
        <div class="section-body">
          <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            <select id="fileServerSelect" disabled>
              <option value="cloudflare" selected>Cloudflare R2</option>
            </select>
        
            <a href="/sub_migrate_files.php"
               id="openMigrationUiBtn"
               class="button-link small-inline"
               style="display:inline-block;">
              ☁️ <?= $lang['migrate_files_cloudflare'] ?? 'Migrate' ?>
            </a>
          </div>
          <p class="setting-hint">File server is fixed to Cloudflare R2.</p>
        </div>
      </div>

      <div class="settings-section">
        <div class="section-head">
          <h3>🏠 Home view</h3>
          <span class="section-chip">Auto-saves</span>
        </div>
        <div class="section-body">
          <label>
            Mode:
            <select id="homeModeSelect">
              <option value="default" <?= $homeMode === 'default' ? 'selected' : '' ?>>
                Default (TextWhisper Home)
              </option>
              <option value="page" <?= $homeMode === 'page' ? 'selected' : '' ?>>
                Custom page
              </option>
              <option value="pdf" <?= $homeMode === 'pdf' ? 'selected' : '' ?>>
                PDF document
              </option>
            </select>
          </label>

          <label>
            Home target:
            <input
              type="text"
              id="homePageInput"
              placeholder="URL or PDF ID (only for custom modes)"
              value="<?= htmlspecialchars($homePage) ?>"
              style="width:100%;"
            />
          </label>

          <p class="setting-hint">
            The default home view shows the standard TextWhisper Home.
            Custom modes allow you to open a specific page or PDF instead.
          </p>
        </div>
      </div>
    </div>




    
    <script>
    document.addEventListener("DOMContentLoaded", () => {

      // 📂 File server
      const migrateBtn = document.getElementById("openMigrationUiBtn");

      if (migrateBtn) {
        migrateBtn.addEventListener("click", () => {
          window.location.href = "/sub_migrate_files.php";
        });
      }

      // 🏠 Home view
      const homeMode = document.getElementById("homeModeSelect");
      const homePage = document.getElementById("homePageInput");

      if (homeMode && homePage) {

        const saveHome = async () => {
          let trimmedPage = homePage.value.trim();
          if (homeMode.value === "page" && trimmedPage) {
            if (!/^https?:\/\//i.test(trimmedPage)) {
              trimmedPage = `https://${trimmedPage}`;
            }
            if (!/^https:\/\//i.test(trimmedPage)) {
              alert("Custom page must be an https URL.");
              return;
            }
            homePage.value = trimmedPage;
          }

          await fetch("/sub_update_home.php", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            credentials: "same-origin",
            body:
              "home_mode=" + encodeURIComponent(homeMode.value) +
              "&home_page=" + encodeURIComponent(trimmedPage)
          });
        };

        homeMode.addEventListener("change", saveHome);
        homePage.addEventListener("blur", saveHome);
        homePage.addEventListener("keydown", (event) => {
          if (event.key === "Enter") {
            event.preventDefault();
            saveHome();
          }
        });
      }

    });
    </script>






  

    <div class="settings-group">
      <div class="settings-group-head">
        <h3>Billing & Usage</h3>
        <p class="settings-group-sub">Plan details and current usage.</p>
      </div>

      <div class="settings-section plan-details">
        <h3>🧾 <?= $lang['current_plan'] ?? 'Current Plan' ?></h3>
        <p><strong><?= $lang['plan'] ?? 'Plan' ?>:</strong> <?= htmlspecialchars($currentPlan['label'] ?? ucfirst($planKey)) ?></p>
        <p><strong><?= $lang['storage'] ?? 'Storage' ?>:</strong> <?= htmlspecialchars($currentPlan['storage_limit']) ?></p>
        <p><strong><?= $lang['offline_access'] ?? 'Offline access' ?>:</strong> <?= $currentPlan['offline'] ? '✅ ' . ($lang['yes'] ?? 'Yes') : '❌ ' . ($lang['no'] ?? 'No') ?></p>
        <p><strong><?= $lang['price'] ?? 'Price' ?>:</strong> €<?= number_format($currentPlan['price'], 2) ?>/year</p>
        <?php if ($nextBillingDate): ?>
          <p><strong><?= $lang['next_billing_date'] ?? 'Next billing date' ?>:</strong> <?= $nextBillingDate ?></p>
        <?php endif; ?>
      </div>
      
      <div class="settings-section">
        <h3>📊 Current Usage</h3>
        <table class="contract-summary">
          <tr>
            <td><strong>Storage Used</strong></td>
            <td><?= $storageStats['gb'] ?> GB</td>
          </tr>
          <tr>
            <td><strong>Total Files</strong></td>
            <td><?= (int)$storageStats['files'] ?></td>
          </tr>
          <?php
            $usageTypeLabels = [
              'pdf' => 'PDF',
              'audio' => 'Audio',
              'xml' => 'XML',
              'image' => 'Image',
              'other' => 'Other',
            ];
            $usageByType = is_array($storageStats['by_type'] ?? null) ? $storageStats['by_type'] : [];
            foreach ($usageTypeLabels as $typeKey => $typeLabel):
              $typeStats = $usageByType[$typeKey] ?? null;
              $typeFiles = is_array($typeStats) ? (int)($typeStats['files'] ?? 0) : 0;
              $typeGb = is_array($typeStats) ? (float)($typeStats['gb'] ?? 0) : 0.0;
              if ($typeFiles <= 0 && $typeGb <= 0) {
                  continue;
              }
          ?>
          <tr>
            <td><strong><?= htmlspecialchars($typeLabel) ?></strong></td>
            <td><?= $typeFiles ?> files (<?= rtrim(rtrim(number_format($typeGb, 3, '.', ''), '0'), '.') ?> GB)</td>
          </tr>
          <?php endforeach; ?>
          <?php if (!empty($storageStats['sql_text_items']) || !empty($storageStats['sql_text_gb'])): ?>
          <tr>
            <td><strong>TW Text</strong></td>
            <td><?= (int)$storageStats['sql_text_items'] ?> items (<?= rtrim(rtrim(number_format((float)$storageStats['sql_text_gb'], 3, '.', ''), '0'), '.') ?> GB)</td>
          </tr>
          <?php endif; ?>
          <?php if (!empty($storageStats['orphan_files']) || !empty($storageStats['orphan_gb'])): ?>
          <tr>
            <td><strong>Orphan CF Files</strong></td>
            <td><?= (int)$storageStats['orphan_files'] ?> files (<?= rtrim(rtrim(number_format((float)$storageStats['orphan_gb'], 3, '.', ''), '0'), '.') ?> GB)</td>
          </tr>
          <?php endif; ?>
          <tr>
            <td><strong>Team Members</strong></td>
            <td><?= $userCount ?></td>
          </tr>
          <tr>
            <td><strong>Lists</strong></td>
            <td><?= $listCount ?></td>
          </tr>
          <tr>
            <td><strong>Items</strong></td>
            <td><?= $itemCount ?></td>
          </tr>
          <?php if (!empty($storageStats['scanned_at'])): ?>
          <tr>
            <td><strong>Usage Scan</strong></td>
            <td><?= htmlspecialchars(date('d.m.Y H:i', strtotime((string)$storageStats['scanned_at']))) ?></td>
          </tr>
          <?php endif; ?>
        </table>
      </div>
      
      <div class="settings-section">
        <a href="/sub_pricing.php" class="button-link">🔄 <?= $lang['manage_subscription'] ?? 'Manage Subscription' ?></a>
      </div>

      <?php if (!empty($billingPortalUrl)): ?>
        <div class="settings-section">
          <a href="<?= htmlspecialchars($billingPortalUrl) ?>" class="button-link">💳 <?= $lang['open_billing_portal'] ?? 'Open Stripe Billing Portal' ?></a>
        </div>
      <?php endif; ?>
    </div>

    <div class="settings-group">
      <div class="settings-group-head">
        <h3>History & Links</h3>
        <p class="settings-group-sub">Plan history and navigation shortcuts.</p>
      </div>

      <div class="settings-section">
        <a href="/" class="button-link">← <?= $lang['back_to_dashboard'] ?? 'Back to Dashboard' ?></a>
      </div>    

      <!-- 📜 Plan Change History -->
      <div class="settings-section">
        <h3>📜 <?= $lang['plan_change_history'] ?? 'Plan Change History' ?></h3>
        <?php
        $stmt = $mysqli->prepare("
            SELECT old_plan, new_plan, old_storage, new_storage, change_type, changed_at
            FROM sub_plan_changes
            WHERE user_id=?
            ORDER BY changed_at DESC
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            echo "<table border='1' cellpadding='6' style='border-collapse:collapse; width:100%;'>";
            echo "<tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>From</th>
                    <th>To</th>
                  </tr>";
            while ($row = $result->fetch_assoc()) {
                $from = ucfirst($row['old_plan'] ?? '-') . 
                        (!empty($row['old_storage']) && $row['old_storage'] !== 'none' ? " ({$row['old_storage']})" : '');
                $to = ucfirst($row['new_plan'] ?? '-') . 
                      (!empty($row['new_storage']) && $row['new_storage'] !== 'none' ? " ({$row['new_storage']})" : '');
                echo "<tr>
                        <td>" . htmlspecialchars($row['changed_at']) . "</td>
                        <td>" . ucfirst(htmlspecialchars($row['change_type'])) . "</td>
                        <td>" . htmlspecialchars($from) . "</td>
                        <td>" . htmlspecialchars($to) . "</td>
                      </tr>";
            }
            echo "</table>";
        } else {
            echo "<p>" . ($lang['no_plan_changes'] ?? 'No plan changes recorded.') . "</p>";
        }
        $stmt->close();
        ?>
      </div>
    </div>
    
    



  </div>
</body>



</html>



