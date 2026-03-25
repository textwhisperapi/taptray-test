<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/sub_plans.php';

header('Content-Type: text/html; charset=utf-8');
sec_session_start();

$isLoggedIn = isset($_SESSION['user_id']);
$userId = $_SESSION['user_id'] ?? null;
$currentPlanKey = $_SESSION['plan'] ?? 'free';

$currentStorageAddon = 0;
$currentUserAddon = 0;

if ($isLoggedIn && $userId) {
    $stmt = $mysqli->prepare("SELECT storage_addon, user_addon FROM members WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->bind_result($currentStorageAddon, $currentUserAddon);
    $stmt->fetch();
    $stmt->close();
}


$sharedStorageOptions = [
  '0' => ['label' => 'Default', 'price' => 0],
  '10' => ['label' => '+10 GB', 'price' => 30],
  '50' => ['label' => '+50 GB', 'price' => 100],
  '100' => ['label' => '+100 GB', 'price' => 150],
  '1000' => ['label' => '+1 TB', 'price' => 1000],
];

$enterpriseStorageOptions = [
  '0' => ['label' => 'Default', 'price' => 0],
  '500' => ['label' => '+500 GB', 'price' => 750],
  '1000' => ['label' => '+1 TB', 'price' => 1300],
  '2000' => ['label' => '+2 TB', 'price' => 2600],
];

$userOptions = [
  '300' => ['label' => 'Up to 300 users (default)', 'price' => 0],
  '500' => ['label' => 'Up to 500 users', 'price' => 1200],
  '1000' => ['label' => 'Up to 1000 users', 'price' => 2000],
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Plans & Pricing – TextWhisper</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body {
      font-family: system-ui, sans-serif;
      background: #f4f4f8;
      margin: 0;
      padding: 2rem;
      color: #222;
    }
    .plan-container {
      max-width: 1000px;
      margin: auto;
      background: #fff;
      padding: 2rem;
      border-radius: 10px;
      box-shadow: 0 0 15px rgba(0,0,0,0.1);
    }
    .plan-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 1.5rem;
      margin-top: 2rem;
    }
.plan-card {
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  background: #fdfdfd;
  border: 1px solid #ddd;
  border-radius: 8px;
  padding: 1rem;
  text-align: center;
}

    .plan-card h3 {
      margin-top: 0;
    }
    .plan-card ul {
      list-style: none;
      padding: 0;
      font-size: 0.95rem;
    }
    .plan-card li {
      margin: 4px 0;
    }
    .plan-card button, .plan-card select {
      margin-top: 0.5rem;
      padding: 8px 14px;
      font-size: 14px;
      border-radius: 6px;
      border: 1px solid #ccc;
      width: 100%;
      box-sizing: border-box;
    }
    .plan-card button {
      background: #4CAF50;
      color: white;
      border: none;
      cursor: pointer;
    }
    .plan-card button:hover {
      background: #3e8e41;
    }
    .plan-card label.label-top {
      text-align: left;
      display: block;
      font-size: 0.8rem;
      font-weight: 500;
      margin-top: 6px;
      margin-bottom: -3px;
      color: #444;
    }

    .price-display {
      font-weight: bold;
      margin: 10px 0;
    }
    .plan-card.current-plan {
      border: 2px solid #0077cc;
      background: #f0f8ff;
      box-shadow: 0 0 8px rgba(0, 119, 204, 0.2);
    }
    
    .plan-card.current-plan h3::after {
      content: " (Current)";
      font-size: 0.9em;
      color: #0077cc;
    }

    
    
  </style>
  

  

  <script>
  
  
function showFlashMessage(msg) {
  const flash = document.createElement("div");
  flash.innerText = msg;
  flash.style.position = "fixed";
  flash.style.top = "20px";
  flash.style.left = "50%";
  flash.style.transform = "translateX(-50%)";
  flash.style.background = "#cc0000";
  flash.style.color = "#fff";
  flash.style.padding = "14px 24px";
  flash.style.borderRadius = "8px";
  flash.style.fontWeight = "bold";
  flash.style.zIndex = 9999;
  flash.style.boxShadow = "0 4px 12px rgba(0,0,0,0.15)";
  document.body.appendChild(flash);
  setTimeout(() => flash.remove(), 3000);
}


//Temporarelly dissabled with this fake function
function handlePlanSubmit(event, planKey) {
  event.preventDefault();
  showFlashMessage("⚠️ Subscription services are temporarily unavailable.");
  return false;
}
  
  
      const isLoggedIn = <?= $isLoggedIn ? 'true' : 'false' ?>;
      function handlePlanSubmitXXX(event, planKey) {
        

        
        
        if (!isLoggedIn) {
          event.preventDefault();
          window.location.href = '/login.php?plan=' + encodeURIComponent(planKey);
          return false;
        }
        return true;
      }

      const STORAGE_UPGRADES = <?= json_encode($STORAGE_UPGRADES, JSON_UNESCAPED_UNICODE) ?>;
      const USER_UPGRADES = <?= json_encode($USER_UPGRADES, JSON_UNESCAPED_UNICODE) ?>;

    
    function updatePriceAndStorage(planKey, basePrice) {
        
  
        
      let newPrice = basePrice;
    
      const planType = planKey === 'enterprise' ? 'enterprise' : 'shared';
      const storageSelect = document.getElementById('storage_' + planKey);
      const userSelect = document.getElementById('users_' + planKey);
    
      const storageValue = parseInt(storageSelect?.value || 0);
      const userValue = parseInt(userSelect?.value || 0);
    
      newPrice += STORAGE_UPGRADES[planType]?.[storageValue]?.price || 0;
    
      if (planKey === 'enterprise') {
        newPrice += USER_UPGRADES?.[userValue]?.price || 0;
      }
    
      // ✅ Update price
      document.getElementById('price_' + planKey).innerText = '€' + newPrice + ' / year';
    
      // ✅ Update storage label
      const baseStorage = parseInt(storageSelect.getAttribute('data-base-gb')) || 0;
      const totalStorage = baseStorage + storageValue;
      document.getElementById('storageLabel_' + planKey).innerText = '📦 ' + totalStorage + ' GB';
    
      // ✅ Update user count (only for enterprise)
      if (planKey === 'enterprise') {
        const baseUsers = 300;
        const extraUsers = USER_UPGRADES?.[userValue]?.label?.match(/(\d+)/)?.[0] || 0;
        const totalUsers = baseUsers + parseInt(extraUsers);
        document.getElementById('userLabel_' + planKey).innerText = '🧑‍🤝‍🧑 Up to ' + totalUsers + ' users';
      }
    }

    
  </script>
</head>
<body>

<div class="plan-container">

<div style="display: flex; justify-content: space-between; align-items: center;">
  <h2>Select Your Plan</h2>
  <button onclick="document.getElementById('subInfoModal').style.display='block'" 
          style="font-size: 1.2rem; padding: 6px 12px; cursor: pointer;">
    ℹ️ Info
  </button>
</div>

  
  <p>All plans include the full feature set. Upgrade storage or users if needed.</p>

<?php if ($isLoggedIn && isset($PLANS[$currentPlanKey])): ?>
  <div style="background: #e6f3ff; padding: 10px 16px; border-radius: 6px; margin-top: 1rem; margin-bottom: 1rem;">
    <strong>Current Plan:</strong> <?= htmlspecialchars($PLANS[$currentPlanKey]['label']) ?>
    <?php if ($currentStorageAddon > 0 || $currentUserAddon > 0): ?>
      <br>
      <span style="font-size: 0.95em;">
        <?php if ($currentStorageAddon > 0): ?>📦 +<?= $currentStorageAddon ?> GB<?php endif; ?>
        <?php if ($currentUserAddon > 0): ?> &nbsp; 👥 +<?= $currentUserAddon ?> users<?php endif; ?>
      </span>
    <?php endif; ?>
  </div>
<?php endif; ?>



    <div class="plan-grid">
      <?php foreach (['free', 'team_lite', 'team_standard', 'team_plus', 'composer', 'enterprise'] as $key): ?>
        <?php
          $planData = getUserPlan($userId, $key, $mysqli, $PLANS);
          $basePrice = $planData['price'];
          $priceDisplay = $basePrice > 0 ? "€" . number_format($basePrice, 0) . "/year" : "Free";
        ?>
        <!--<div class="plan-card">-->
        <?php $isCurrent = ($key === $currentPlanKey); ?>
        <div class="plan-card<?= $isCurrent ? ' current-plan' : '' ?>">

          <h3><?= htmlspecialchars($planData['label']) ?></h3>
          <ul>
            <?php
              $baseStorage = (int) preg_replace('/[^0-9]/', '', $planData['storage_limit']);
              $totalStorage = ($isCurrent ? $baseStorage + $currentStorageAddon : $baseStorage);
            ?>
            
            <li id="storageLabel_<?= $key ?>">
              📦 <?= $totalStorage ?> GB<?= $isCurrent && $currentStorageAddon > 0 ? ' (includes add-on)' : '' ?>
            </li>
            
            <!--<li id="storageLabel_<?= $key ?>">📦 <?= $planData['storage_limit'] ?></li>-->
            <?php if ($key === 'free'): ?>
              <li>🧾 3 lists / 15 items</li>
              <li>🧍 1 user</li>
            <?php elseif ($key === 'team_lite'): ?>
              <li>🧑‍🤝‍🧑 Up to 20 users</li>
            <?php elseif ($key === 'team_standard'): ?>
              <li>🧑‍🤝‍🧑 Up to 70 users</li>
            <?php elseif ($key === 'team_plus'): ?>
              <li>🧑‍🤝‍🧑 Up to 150 users</li>
            <?php elseif ($key === 'composer'): ?>
              <li>🧑‍🤝‍🧑 Up to 3 users</li>
              <li>✅ Selling & Tracking of items</li>
              <li>✅ Marketplace listing & promotion</li>
              <li>✅ Fee per item sold: 5%</li>
            <?php elseif ($key === 'enterprise'): ?>
              <!--<li>🧑‍🤝‍🧑 Up to 300 users (expandable)</li>-->
              <li id="userLabel_<?= $key ?>">
                  🧑‍🤝‍🧑 Up to <?= ($key === 'enterprise') ? 300 + $currentUserAddon : 'X' ?> users
              </li>

            <?php endif; ?>
            <li>✅ All features included</li>
          </ul>
          <div id="price_<?= $key ?>" class="price-display"><?= $priceDisplay ?></div>
          <!--<form method="POST" action="sub_subscribe.php">-->
          <form method="POST" action="sub_subscribe.php" onsubmit="return handlePlanSubmit(event, '<?= $key ?>')">

            <input type="hidden" name="plan" value="<?= htmlspecialchars($key) ?>">
            <?php if (!in_array($key, ['free'])): ?>
                <?php if ($key === 'enterprise'): ?>
                  <label for="users_<?= $key ?>" class="label-top">Users:</label>
                  <select name="user_upgrade" id="users_<?= $key ?>" onchange="updatePriceAndStorage('<?= $key ?>', <?= $basePrice ?>)">
                    <?php foreach ($userOptions as $u => $opt): ?>
                      <?php
                        $isSelected = ($isCurrent && (int)$u === $currentUserAddon) ? 'selected' : '';
                      ?>
                      <option value="<?= $u ?>" <?= $isSelected ?>>
                        <?= $opt['label'] ?><?= $opt['price'] > 0 ? " + €{$opt['price']}" : "" ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                <?php endif; ?>            
              <label for="storage_<?= $key ?>" class="label-top">Storage:</label>
              <select name="storage_upgrade"
                      id="storage_<?= $key ?>"
                      data-base-gb="<?= preg_replace('/[^0-9]/', '', $planData['storage_limit']) ?>"
                      onchange="updatePriceAndStorage('<?= $key ?>', <?= $basePrice ?>)">
                <?php
                  $optionsToUse = ($key === 'enterprise') ? $enterpriseStorageOptions : $sharedStorageOptions;
                  foreach ($optionsToUse as $gb => $opt):
                ?>
                  <!--<option value="<?= $gb ?>"><?= $opt['label'] ?><?= $opt['price'] > 0 ? " + €{$opt['price']}" : "" ?></option>-->
                <?php
                  $isSelected = ($isCurrent && (int)$gb === $currentStorageAddon) ? 'selected' : '';
                ?>
                <option value="<?= $gb ?>" <?= $isSelected ?>><?= $opt['label'] ?><?= $opt['price'] > 0 ? " + €{$opt['price']}" : "" ?></option>

                <?php endforeach; ?>
              </select>
            <?php endif; ?>




            <button type="submit">Select Plan</button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- 🔽 Feature Highlights Section -->
    <hr class="my-4">
    <div style="margin-top: 40px;">
      <h3>📌 Highlighted Features</h3>
      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 2rem;">
        <div>
          <h4>📘 Text Recall & Practice</h4>
          <ul>
            <li>Smart text trimming with adjustable hint levels</li>
            <li>Dual view: full text vs. trimmed</li>
            <li>Scroll navigation (swipe, double-tap, pinch-to-zoom)</li>
            <li>Offline access for saved lists and items</li>
          </ul>

          <h4>📴 Offline Mode</h4>
          <ul>
            <li>Works in browser or the TextWhisper app</li>
            <li>Preserves list order and annotations</li>
            <li>Ideal for planes, trains, or no signal areas</li>
          </ul>

          <h4>📝 PDF Integration & Annotation</h4>
          <ul>
            <li>Drag & drop or link-upload for PDFs</li>
            <li>Pen, eraser, undo, color picker tools</li>
            <li>Per-user annotations (private)</li>
            <li>Supports IMSLP search & Google Drive</li>
          </ul>

          <h4>🎵 Music & Audio</h4>
          <ul>
            <li>Upload MIDI, MP3, FLAC, WAV, M4A</li>
            <li>Paste from YouTube, Spotify, Soundslice</li>
            <li>Interactive MIDI player (mute, solo, volume)</li>
            <li>Floating players for focused listening</li>
          </ul>
        </div>

        <div>
          <h4>💬 List Chat & Collaboration</h4>
          <ul>
            <li>Real-time chat per list (encrypted)</li>
            <li>Viewer → Admin roles</li>
            <li>Invite-only with notification control</li>
            <li>Push alerts for key messages</li>
          </ul>

          <h4>🔐 Privacy & Sharing</h4>
          <ul>
            <li>Public / Private / Secret list modes</li>
            <li>Sharable links with preview</li>
            <li>Session-auth and secure cookies</li>
            <li>Spam protection, strong password rules</li>
          </ul>

          <h4>🖥️ Interface & Usability</h4>
          <ul>
            <li>Mobile-first responsive UI</li>
            <li>Progressive Web App (PWA)</li>
            <li>Sidebar/tab memory and deep links</li>
            <li>Fast, modern, distraction-free design</li>
          </ul>
        </div>
      </div>
    </div>

</div>

<div id="subInfoModal" style="display: none; position: fixed; top: 0; left: 0; 
  width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
  <div style="background: white; max-width: 600px; margin: 5% auto; padding: 2rem; border-radius: 8px; position: relative;">
    <button onclick="document.getElementById('subInfoModal').style.display='none'" 
            style="position: absolute; top: 10px; right: 12px; font-size: 1.2rem; border: none; background: none; cursor: pointer;">✖</button>
    <h3>How Subscriptions Work</h3>
    <ul style="line-height: 1.6; padding-left: 1.2em;">
      <li>✅ You can change plans at any time.</li>
      <li>📦 If you add extra storage or users, your price updates accordingly.</li>
      <li>🔁 Switching plans triggers a fair prorated charge or credit.</li>
      <li>💳 Stripe securely handles payments and billing.</li>
      <li>🧾 All subscriptions are yearly and renew automatically unless cancelled.</li>
      <li>📬 After subscribing, you’ll receive a receipt and confirmation by email.</li>
    </ul>
    <p style="margin-top: 1em;">Need help? <a href="/contact.php">Contact us</a>.</p>
  </div>
</div>


<script>
  window.addEventListener('DOMContentLoaded', () => {
    <?php if ($isLoggedIn && isset($PLANS[$currentPlanKey])): ?>
      const currentPlanKey = <?= json_encode($currentPlanKey) ?>;
      const basePrice = <?= (int)($PLANS[$currentPlanKey]['price'] ?? 0) ?>;
      updatePriceAndStorage(currentPlanKey, basePrice);
    <?php endif; ?>
  });
</script>


</body>
</html>
