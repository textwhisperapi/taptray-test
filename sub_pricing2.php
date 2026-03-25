<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/sub_plans.php';
require_once __DIR__ . "/includes/sub_functions.php";

header('Content-Type: text/html; charset=utf-8');
sec_session_start();

// Hardcode gateway: stripe | paypal | worldline
define('PAYMENT_GATEWAY', 'worldline');

$isLoggedIn = isset($_SESSION['user_id']);
$userId     = $_SESSION['user_id'] ?? null;

$currentPlanKey    = 'free';
$currentStorageAdd = 0;
$currentUserAdd    = 0;

// Fetch measures (logged-in only; keep anonymous page usable)
$storageStats = ['gb' => 0, 'files' => 0];
$userCount    = 0;
$listCount    = 0;
$itemCount    = 0;
if ($isLoggedIn && $userId) {
    $storageStats = getUserStorageStats($mysqli, (int)$userId);
    $userCount    = getTeamMemberCount($mysqli, (int)$userId);
    $listCount    = getUserListsCount($mysqli, (int)$userId);
    $itemCount    = getUserItemsCount($mysqli, (int)$userId);
}


if ($isLoggedIn && $userId) {
    $stmt = $mysqli->prepare("SELECT plan, storage_addon, user_addon FROM members WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->bind_result($planFromDb, $currentStorageAdd, $currentUserAdd);
    if ($stmt->fetch()) {
        $currentPlanKey = $planFromDb ?: 'free';
    }
    $stmt->close();
}

$pricingCards = [
    'free' => [
        'label' => 'Free',
        'gb' => 0.1,
        'user' => 1,
        'price' => 0,
        'mode' => 'basic',
    ],
    'team_standard' => [
        'label' => 'Team Standard',
        'gb' => 2,
        'user' => 20,
        'price' => 0,
        'mode' => 'team',
    ],
    'team_plus' => [
        'label' => 'Team Plus',
        'gb' => 5,
        'user' => 50,
        'price' => 0,
        'mode' => 'team',
    ],
];

$pricingUserOptions = [20, 30, 50, 70, 150, 300];
$pricingStorageOptions = [1, 2, 3, 5, 7, 10, 15, 30];
?>
<!DOCTYPE html>
<html lang="en">
<meta name="viewport" content="width=device-width, initial-scale=1">
<head>
  <meta charset="UTF-8">
  <title>Plans & Pricing – TextWhisper</title>

  <style>
  
    .back-link {
      /*position: absolute;*/
      /*top: 20px;*/
      /*left: 20px;*/
      color: #374151;
      font-size: 15px;
      text-decoration: none;
      font-weight: 500;
    }
    .back-link:hover {
      color: #111827;
      text-decoration: underline;
    }
  
    body { font-family: system-ui, sans-serif; background: #f4f4f8; margin: 0; padding: 2rem; color: #222; }
    .plan-container { max-width: 1000px; margin: auto; background: #fff; padding: 2rem; border-radius: 10px; box-shadow: 0 0 15px rgba(0,0,0,0.1); }
    .plan-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.5rem; margin-top: 2rem; }
    .plan-card { display: flex; flex-direction: column; justify-content: space-between; border: 1px solid #ddd; border-radius: 8px; padding: 1rem; text-align: center; }
    .plan-card.current { border: 2px solid #0077cc; background: #f0f8ff; }
    .plan-card h3 { margin-top: 0; }
    .plan-card ul { list-style: none; padding: 0; font-size: 0.95rem; }
    .plan-card li { margin: 4px 0; }
    .plan-card button, .plan-card select { margin-top: 0.5rem; padding: 8px 14px; font-size: 14px; border-radius: 6px; border: 1px solid #ccc; width: 100%; box-sizing: border-box; }
    .plan-card button { background: #4CAF50; color: #fff; border: none; cursor: pointer; }
    .plan-card button:hover { background: #3e8e41; }
    .price-display { font-weight: bold; margin: 10px 0; }
    .billing-note {
      font-size: 0.9em;
      font-weight: 500;
      color: #475569;
      margin-top: 4px;
    }
    .price-main {
      font-size: 1.9rem;
      font-weight: 800;
      letter-spacing: -0.02em;
      line-height: 1.1;
      color: #0f172a;
    }
    .price-sub {
      margin-top: 6px;
      font-size: 0.98rem;
      font-weight: 600;
      color: #475569;
    }
    .feature-note {
      margin-top: 10px;
      font-size: 0.88rem;
      color: #64748b;
      min-height: 2.5em;
    }
    
    /*less padding for mobile*/
    @media (max-width: 600px) {
      body { padding: 1rem; }                /* shrink outer padding */
      .plan-container { padding: 1rem; }     /* shrink card wrapper padding */
      .plan-card { padding: 0.75rem; }       /* tighten inside each plan */
      .plan-card button, .plan-card select { font-size: 15px; padding: 10px; }
    }
    
  </style>
  
  
  
  <script>
    const IS_LOGGED_IN = <?= $isLoggedIn ? 'true' : 'false' ?>;

    // function handleSubmit(e, plan) {
    //   if (!IS_LOGGED_IN) {
    //     e.preventDefault();
    //     window.location.href = '/login.php?plan=' + encodeURIComponent(plan);
    //     return false;
    //   }
    //   return true;
    // }

function updatePrice(planKey) {
  const base   = parseFloat(document.getElementById("price_" + planKey).dataset.base || "0");
  const storEl = document.getElementById("storage_" + planKey);
  const userEl = document.getElementById("users_" + planKey);

  const selectedStorage = storEl ? parseFloat(storEl.value) : 0;
  const selectedUsers = userEl ? parseFloat(userEl.value) : 0;

  // ✅ Update price
  let total = base;
  if (planKey === "team_standard" || planKey === "team_plus") {
    const unitMemberPrice = 9;
    const unitGbPrice = 2;
    const discountBaseMembers = 20;
    const discountLogFactor = 0.06;
    const discountFloor = 0.82;

    const totalMemberPrice = selectedUsers * unitMemberPrice;
    const totalGbPrice = selectedStorage * unitGbPrice;
    const totalTeamPrice = totalMemberPrice + totalGbPrice;
    const rawFactor = 1 - Math.log((selectedUsers / discountBaseMembers) + 1) * discountLogFactor;
    const sizeFactor = Math.max(discountFloor, rawFactor);
    total = totalTeamPrice * sizeFactor;
  }
  const priceEl = document.getElementById("price_" + planKey);
  if (total > 0) {
    const annual = total;
    const monthly = annual / 12;
    priceEl.innerHTML = "<div class='price-main'>€" + monthly.toFixed(2) + " / month</div><div class='price-sub'>Billed annually: €" + annual.toFixed(2) + "</div><div class='billing-note'>Save one month</div>";
  } else {
    priceEl.textContent = "Free";
  }

  // ✅ Update storage label
  if (storEl) {
    const storageLabel = document.getElementById("storageLabel_" + planKey);
        if (storageLabel) {
          storageLabel.textContent = "📦 " + selectedStorage + " GB";
        }
  }

  // ✅ Update user label for team-style plans
  if ((planKey === "team_standard" || planKey === "team_plus" || planKey === "enterprise") && userEl) {
    document.getElementById("userLabel_" + planKey).textContent =
      "🧑‍🤝‍🧑 Up to " + selectedUsers + " users";
  }
}


const usage = <?= json_encode([
  'gb'    => $storageStats['gb'],
  'files' => $storageStats['files'],
  'users' => $userCount,
  'lists' => $listCount,
  'items' => $itemCount
]); ?>;


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

  // 🚫 Temporarily disable all subscription submits
  function handleSubmit(event, planKey) {
    event.preventDefault();
    showFlashMessage("⚠️ Subscription services are temporarily unavailable.");
    return false;
  }



function handleSubmitXXXXPaused(e, planKey) {
    const form   = e.target;
    const limits = JSON.parse(form.dataset.limits);
    const key    = planKey.toLowerCase(); // normalize

    console.log("🔎 handleSubmit:", { planKey, key, usage, limits });

    let warnings = [];

    // --- Storage ---
    if (usage.gb > limits.storage) {
        warnings.push(`Storage too high (${usage.gb} GB / limit ${limits.storage} GB)`);
    }
    if (limits.file_limit && usage.files > limits.file_limit) {
        warnings.push(`Too many files (${usage.files} / limit ${limits.file_limit})`);
    }

    // --- Users ---
    if (key === "free" || key === "composer") {
        if (usage.users > 5) {
            warnings.push(`Too many members (${usage.users} / max 5)`);
        }
    } else if (usage.users > limits.users) {
        warnings.push(`Too many members (${usage.users} / limit ${limits.users})`);
    }

    // --- Lists ---
    if (usage.lists > limits.lists) {
        warnings.push(`Too many lists (${usage.lists} / limit ${limits.lists})`);
    }

    // --- Items ---
    if (usage.items > limits.items) {
        warnings.push(`Too many items (${usage.items} / limit ${limits.items})`);
    }

    // --- Show modal if blocked ---
    if (warnings.length > 0) {
        e.preventDefault();
        const ul = document.getElementById("downgradeWarnings");
        ul.innerHTML = "";
        warnings.forEach(w => {
            const li = document.createElement("li");
            li.textContent = w;
            ul.appendChild(li);
        });
        document.getElementById("downgradeModal").style.display = "block";
        return false; // stop submit
    }

    return true; // allow form submit
}




  </script>
</head>
<body>
    
<div class="plan-container">

    <a href="javascript:history.back()" class="back-link">← Back</a>
    
    <h3>📊 Current Usage</h3>
    
    <table class="contract-summary">
      <tr>
        <td><strong>Storage Used</strong></td>
        <td><?= $storageStats['gb'] ?> GB (<?= $storageStats['files'] ?> files)</td>
      </tr>
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
    </table>    

  <h2>Select Your Plan</h2>
  <p>All plans include the full feature set. Choose a plan and optionally add extra storage.</p>

    <?php if ($isLoggedIn && isset($PLANS[$currentPlanKey])): ?>
      <div style="background:#e6f3ff; padding:10px 16px; border-radius:6px; margin:1rem 0; 
                  display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap;">
        
        <div>
          <?php
            $currentPlanLabel = $pricingCards[$currentPlanKey]['label']
              ?? ($PLANS[$currentPlanKey]['label'] ?? ucfirst($currentPlanKey));
          ?>
          <strong>Current Plan:</strong> <?= htmlspecialchars($currentPlanLabel) ?>
          <?php if ($currentStorageAdd > 0 || $currentUserAdd > 0): ?>
            <br><span style="font-size:0.95em;">
              <?php if ($currentStorageAdd > 0): ?>📦 +<?= $currentStorageAdd ?> GB<?php endif; ?>
              <?php if ($currentUserAdd > 0): ?> &nbsp; 👥 +<?= $currentUserAdd ?> users<?php endif; ?>
            </span>
          <?php endif; ?>
        </div>
    
        <!-- 🔗 Button aligned right -->
        <div>
          <a href="/sub_payments.php"
             style="display:inline-block; padding:6px 12px; background:#007bff; color:#fff; 
                    border-radius:6px; text-decoration:none; font-size:0.9rem;">
            💳 View Payments & History
          </a>
        </div>
      </div>
    <?php endif; ?>



  <div class="plan-grid">
    <?php foreach ($pricingCards as $key => $defaults): 
      $isCurrent = $key === $currentPlanKey;
      $price     = $defaults['price'];
      $baseGB    = $defaults['gb'];
      $baseUsers = $defaults['user'];
      $displayGB = $isCurrent && $currentStorageAdd > 0 ? $currentStorageAdd : $baseGB;
    ?>
      <div class="plan-card <?= $isCurrent ? 'current' : '' ?>">
        <h3><?= htmlspecialchars($defaults['label']) ?></h3>

        <?php if ($defaults['mode'] === 'conductor'): ?>
          <ul>
            <li>📦 <?= $baseGB ?> GB</li>
            <li>🧑‍🤝‍🧑 Up to 3 users</li>
            <li>✅ Selling & Tracking of items</li>
            <li>✅ Marketplace listing & promotion</li>
            <li>✅ Fee per item sold: 5%</li>
            <li>✅ All features included</li>
          </ul>
        <?php else: ?>
          <ul>
            <li id="storageLabel_<?= $key ?>">
              📦 <?= $displayGB ?> GB<?= $isCurrent && $currentStorageAdd > 0 ? ' (includes add-on)' : '' ?>
            </li>

            <?php if ($defaults['mode'] === 'team'): ?>
              <li id="userLabel_<?= $key ?>">🧑‍🤝‍🧑 Up to <?= $baseUsers ?> users</li>
            <?php else: ?>
              <li>🧑‍🤝‍🧑 Up to <?= $baseUsers ?> users</li>
            <?php endif; ?>

            <li>✅ All features included</li>
          </ul>
        <?php endif; ?>

        <div id="price_<?= $key ?>" class="price-display" data-base="<?= $price ?>">
          <?php if ($price > 0): ?>
            <div class="price-main">€<?= number_format(((float)$price / 12), 2) ?> / month</div>
            <div class="price-sub">Billed annually: €<?= number_format((float)$price, 2) ?></div>
            <div class="billing-note">Save one month</div>
          <?php else: ?>
            <?php if ($defaults['mode'] === 'team'): ?>
              <?php
                $calcMemberPrice = $baseUsers * 9;
                $calcGbPrice = $baseGB * 2;
                $calcTeamPrice = $calcMemberPrice + $calcGbPrice;
                $calcRawFactor = 1 - log(($baseUsers / 20) + 1) * 0.06;
                $calcSizeFactor = max(0.82, $calcRawFactor);
                $calcAnnual = $calcTeamPrice * $calcSizeFactor;
                $calcMonthly = $calcAnnual / 12;
              ?>
              <div class="price-main">€<?= number_format($calcMonthly, 2) ?> / month</div>
              <div class="price-sub">Billed annually: €<?= number_format($calcAnnual, 2) ?></div>
              <div class="billing-note">Save one month</div>
            <?php else: ?>
              Free
            <?php endif; ?>
        <?php endif; ?>
        </div>

        <?php if ($key === 'team_standard'): ?>
          <div class="feature-note">Standard includes the core team workflow. Differentiated Standard features can be added here.</div>
        <?php elseif ($key === 'team_plus'): ?>
          <div class="feature-note">Plus can highlight advanced collaboration and premium team features here.</div>
        <?php else: ?>
          <div class="feature-note"></div>
        <?php endif; ?>

        <form method="POST" 
              action="sub_subscribe_<?= PAYMENT_GATEWAY ?>.php" 
              data-limits='<?= json_encode($defaults) ?>'
              onsubmit="return handleSubmit(event,'<?= $key ?>')">
        
          <input type="hidden" name="plan" value="<?= htmlspecialchars($key) ?>">
        
          <?php if ($defaults['mode'] === 'team'): ?>
            <label>Users:</label>
            <select name="user_addon" id="users_<?= $key ?>" onchange="updatePrice('<?= $key ?>')">
              <?php foreach ($pricingUserOptions as $users): ?>
                <?php $isSelected = $isCurrent ? ($currentUserAdd == $users) : ($users == $baseUsers); ?>
                <option value="<?= (int)$users ?>"
                  <?= $isSelected ? 'selected' : '' ?>>
                  Up to <?= (int)$users ?> users
                </option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>
        
          <?php if ($key !== 'free'): ?>
            <label>Storage:</label>
            <select name="storage_addon"
                    id="storage_<?= $key ?>"
                    onchange="updatePrice('<?= $key ?>')">
              <?php foreach ($pricingStorageOptions as $storageGb): ?>
                <?php $isStorageSelected = $isCurrent ? ($currentStorageAdd == $storageGb) : ((float)$storageGb === (float)$baseGB); ?>
                <option value="<?= (float)$storageGb ?>"
                  <?= $isStorageSelected ? 'selected' : '' ?>>
                  <?= rtrim(rtrim(number_format((float)$storageGb, 2, '.', ''), '0'), '.') ?> GB
                </option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>
        
          <button type="submit">Select Plan</button>
        </form>

  
        
      </div>
    <?php endforeach; ?>
  </div>
</div>


<script>
  window.addEventListener('DOMContentLoaded', () => {
    <?php if ($isLoggedIn && isset($pricingCards[$currentPlanKey])): ?>
      updatePrice(<?= json_encode($currentPlanKey) ?>);
    <?php endif; ?>
  });
</script>







  <!-- 📌 Highlighted Features section stays at the bottom -->
  <hr class="my-4">
  <div style="margin-top: 40px;">
    <h3>📌 Highlighted Features</h3>
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(300px,1fr)); gap:2rem;">
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

<!-- Info modal -->
<div id="subInfoModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%;
  background:rgba(0,0,0,0.5); z-index:1000;">
  <div style="background:#fff; max-width:600px; margin:5% auto; padding:2rem; border-radius:8px; position:relative;">
    <button onclick="document.getElementById('subInfoModal').style.display='none'"
            style="position:absolute; top:10px; right:12px; font-size:1.2rem; border:none; background:none; cursor:pointer;">✖</button>
            
            
    <h3>How Subscriptions Work</h3>
    <ul style="line-height:1.6; padding-left:1.2em;">
      <li>✅ Upgrade or downgrade your plan at any time.</li>
      <li>📦 Extra storage adjusts your yearly subscription price.</li>
      <li>🔁 New plans automatically replace your current subscription.</li>
      <li>💳 Payments are processed securely through our payment partner.</li>
      <li>🧾 Subscriptions renew yearly unless you cancel before renewal.</li>
      <li>📬 After signup, you’ll get a confirmation email with all details.</li>
    </ul>
    <p style="margin-top:1em;">
      Need help? <a href="mailto:customersupport@textwhisper.com">customersupport@textwhisper.com</a>
    </p>
  </div>
</div>

<!-- Downgrade Warning Modal -->
<!-- Downgrade Warning Modal -->
<div id="downgradeModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%;
  background:rgba(0,0,0,0.5); z-index:1001;">
  <div style="background:#fff; max-width:600px; margin:8% auto; padding:2rem; border-radius:8px; position:relative;">
    <button onclick="document.getElementById('downgradeModal').style.display='none'"
            style="position:absolute; top:10px; right:12px; font-size:1.2rem; border:none; background:none; cursor:pointer;">✖</button>
    <h3>⚠️ Cannot Downgrade</h3>
    <p>Your current usage exceeds the limits of the selected plan:</p>
    <ul id="downgradeWarnings" style="line-height:1.6; padding-left:1.2em; color:#b33;"></ul>
    <p style="margin-top:1em; text-align:center;">
      <button onclick="document.getElementById('downgradeModal').style.display='none'" 
              style="padding:0.6em 1.2em; background:#007bff; border:none; border-radius:6px; color:#fff; cursor:pointer;">
        OK, Got It
      </button>
    </p>
  </div>
</div>



</body>
</html>
