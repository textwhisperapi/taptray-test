<?php
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/functions.php';

sec_session_start();
$isLoggedIn = login_check($mysqli);
$assetVersion = (@filemtime(__DIR__ . '/img/wrt.png') ?: time()) + 1;
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$referrerPath = parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_PATH) ?: '';
$ownerToken = trim($_GET['owner'] ?? '');
$pathForCheck = $ownerToken !== '' ? '' : ($referrerPath ?: $requestPath);
?>

<script>
document.addEventListener("click", () => {
  if (window.parent && window.parent !== window) {
    window.parent.postMessage({ type: "tw-close-sidebar" }, "*");
  }
});
</script>

<style>
:root {
  --tw-ink: #14110c;
  --tw-muted: #5f5a53;
  --tw-accent: #285fe6;
  --tw-accent-warm: #f15a2a;
  --tw-bg-1: #f6f1e6;
  --tw-bg-2: #eef6ff;
  --tw-card: #ffffff;
  --tw-stroke: #e6ddcf;
  --tw-shadow: 0 14px 40px rgba(19, 17, 12, 0.12);
}

.tw-home {
  max-width: 1080px;
  margin: 20px auto;
  padding: clamp(20px, 4vw, 52px);
  font-family: "Trebuchet MS", "Gill Sans", "Helvetica Neue", sans-serif;
  line-height: 1.6;
  color: var(--tw-ink);
  display: flex;
  flex-direction: column;
  gap: 18px;
  position: relative;
  overflow: hidden;
  border-radius: 26px;
  background: linear-gradient(145deg, var(--tw-bg-1), var(--tw-bg-2));
}

.tw-home::before,
.tw-home::after {
  content: "";
  position: absolute;
  width: 340px;
  height: 340px;
  border-radius: 50%;
  filter: blur(40px);
  opacity: 0.35;
  z-index: 0;
}

.tw-home::before {
  top: -120px;
  right: -80px;
  background: radial-gradient(circle, #ffd7a8, rgba(255, 215, 168, 0));
}

.tw-home::after {
  bottom: -140px;
  left: -100px;
  background: radial-gradient(circle, #b7d8ff, rgba(183, 216, 255, 0));
}

.tw-home > section {
  position: relative;
  z-index: 1;
  animation: tw-fade-up 0.7s ease both;
}

.tw-home > section:nth-of-type(1) { animation-delay: 0.05s; }
.tw-home > section:nth-of-type(2) { animation-delay: 0.12s; }
.tw-home > section:nth-of-type(3) { animation-delay: 0.18s; }
.tw-home > section:nth-of-type(4) { animation-delay: 0.24s; }
.tw-home > section:nth-of-type(5) { animation-delay: 0.3s; }

@keyframes tw-fade-up {
  from {
    opacity: 0;
    transform: translateY(14px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

/* HERO */
.tw-hero {
  padding: clamp(14px, 3vw, 26px);
  background: rgba(255, 255, 255, 0.72);
  border: 1px solid rgba(230, 221, 207, 0.7);
  border-radius: 20px;
  box-shadow: var(--tw-shadow);
}

.tw-hero-title {
  display: inline-flex;
  align-items: center;
  gap: 12px;
}

.tw-logo {
  width: clamp(48px, 8vw, 64px);
  height: auto;
  opacity: 0.96;
}

.tw-hero h1 {
  font-family: "Bodoni MT", "Didot", "Garamond", "Georgia", serif;
  font-size: clamp(30px, 4.8vw, 48px);
  font-weight: 600;
  letter-spacing: 0.5px;
  margin: 0;
}

.tw-tagline {
  font-size: clamp(14px, 2.2vw, 17px);
  color: var(--tw-muted);
  margin-top: 6px;
}

/* LOGIN CTA */
.tw-login {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 14px 18px;
  background: var(--tw-card);
  border-radius: 16px;
  border: 1px solid var(--tw-stroke);
  box-shadow: 0 10px 24px rgba(19, 17, 12, 0.08);
}

.tw-login p {
  margin: 0;
  font-size: 15px;
  color: var(--tw-muted);
}

.tw-login-button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 10px 20px;
  border-radius: 999px;
  border: none;
  background: linear-gradient(135deg, var(--tw-accent), var(--tw-accent-warm));
  color: #fff;
  font-size: 14px;
  font-weight: 600;
  letter-spacing: 0.3px;
  cursor: pointer;
  box-shadow: 0 10px 18px rgba(40, 95, 230, 0.25);
  text-decoration: none;
}

.tw-login-button:hover {
  transform: translateY(-1px);
}

/* CONTENT */
.tw-section {
  padding: clamp(14px, 3vw, 24px);
  background: rgba(255, 255, 255, 0.86);
  border: 1px solid var(--tw-stroke);
  border-radius: 20px;
}

.tw-section h2 {
  font-family: "Bodoni MT", "Didot", "Garamond", "Georgia", serif;
  font-size: clamp(18px, 2.8vw, 22px);
  margin: 0 0 10px;
}

.tw-section p {
  margin: 6px 0 0;
  font-size: 15px;
  color: var(--tw-muted);
}

.tw-scroll-link {
  color: var(--tw-accent);
  font-weight: 600;
  text-decoration: none;
}

.tw-scroll-link:hover {
  text-decoration: underline;
}

html {
  scroll-behavior: smooth;
}

.tw-card-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 14px;
  margin-top: 12px;
}

.tw-card-grid.tw-card-grid-stack {
  grid-template-columns: 1fr;
}

.tw-advantages-embed {
  margin-top: 10px;
  border: 1px solid var(--tw-stroke);
  border-radius: 12px;
  overflow: hidden;
  background: #fff;
}

.tw-advantages-embed iframe {
  display: block;
  width: 100%;
  min-height: 720px;
  border: 0;
  background: #fff;
}

.tw-pricing-embed {
  margin-top: 14px;
  border: 1px solid var(--tw-stroke);
  border-radius: 18px;
  overflow: hidden;
  background: #fff;
  box-shadow: 0 10px 24px rgba(19, 17, 12, 0.06);
}

.tw-pricing-embed iframe {
  display: block;
  width: 100%;
  min-height: 1600px;
  border: 0;
  background: #fff;
}

/* COLLAPSIBLES */
details {
  border: 1px solid var(--tw-stroke);
  border-radius: 14px;
  padding: 12px 14px;
  background: var(--tw-card);
  box-shadow: 0 8px 20px rgba(19, 17, 12, 0.06);
}

details summary {
  font-weight: 600;
  cursor: pointer;
  list-style: none;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}

details summary::-webkit-details-marker {
  display: none;
}

details summary::after {
  content: "+";
  font-size: 18px;
  color: var(--tw-accent);
}

details[open] summary::after {
  content: "-";
  color: var(--tw-accent-warm);
}

details ul {
  margin: 10px 0 0;
  padding-left: 18px;
}

details li {
  margin-bottom: 6px;
  font-size: 14px;
  color: var(--tw-muted);
}

/* VIDEO */
.tw-video {
  display: flex;
  justify-content: center;
}

.tw-video + .tw-video {
  margin-top: 24px;
}

.tw-video-content {
  width: min(380px, 100%);
}

.tw-video-title {
  font-size: 16px;
  font-weight: 600;
  letter-spacing: 0.02em;
  text-transform: uppercase;
  color: var(--tw-muted);
  margin: 0 0 12px;
  text-align: center;
}

.tw-video-frame {
  width: 100%;
  aspect-ratio: 9 / 16;
  border-radius: 18px;
  overflow: hidden;
  box-shadow: 0 16px 34px rgba(19, 17, 12, 0.18);
  border: 1px solid rgba(255, 255, 255, 0.8);
}

.tw-video-frame iframe {
  width: 100%;
  height: 100%;
  border: 0;
}

/* RESPONSIVE */
@media (max-width: 720px) {
  .tw-home {
    margin: 0;
    border-radius: 0;
    padding: 12px 10px 88px;
  }

  .tw-login {
    flex-direction: column;
    align-items: flex-start;
  }

  .tw-login-button {
    width: 100%;
  }
}




</style>

<div class="tw-home">

  <!-- HERO -->
  <section class="tw-hero">
    <div class="tw-hero-title">
      <img src="/img/wrt.png?v=<?= $assetVersion ?>" alt="TapTray" class="tw-logo">
      <h1>TapTray</h1>
    </div>
    <div class="tw-tagline">Scan. Order. Pay.</div>
  </section>



  <!-- LOGIN -->
  <?php if (!$isLoggedIn): ?>
    <section class="tw-login">
      <p>
        You’re viewing TapTray in read-only mode.
        Log in to manage menus, items, and restaurant settings.
      </p>
      <a class="tw-login-button" href="/login.php?redirect=/" target="_top">Log in</a>
    </section>
  <?php endif; ?>


  <!-- INTRO -->
  <section class="tw-section">
    <p>
      TapTray is a QR-first restaurant menu platform built for fast guest
      browsing, instant mobile checkout, and simple menu management.
    </p>

    <p>
      Restaurants can organize menus, sections, and items in a clear structure,
      update prices and availability quickly, and publish changes instantly to
      guests scanning at the table or counter.
    </p>

    <p>
      Guests get a clean mobile flow: scan a QR code, browse the live menu,
      choose items, and pay with a phone wallet without typing bank details or
      downloading an app.
    </p>
    <p>
      <a class="tw-scroll-link" href="#tutoring-videos">
        Scroll down for the current TapTray rollout notes.
      </a>
    </p>
  </section>


  <!-- WHAT'S NEW -->
  <section class="tw-section">
    <h2>What TapTray offers</h2>

    <div class="tw-card-grid tw-card-grid-stack">
      <details>
        <summary>Core capabilities</summary>
        <ul>
          <li>Publish QR menus for restaurants, cafes, bars, and food halls</li>
          <li>Organize menu sections and items in a reusable structure</li>
          <li>Update prices, descriptions, and availability quickly</li>
          <li>Support mobile-first ordering and instant wallet checkout</li>
          <li>Keep menu access fast and reliable on guest phones</li>
          <li>Manage shared access for owners, managers, and staff</li>
        </ul>
      </details>

      <details>
        <summary>Guest experience</summary>
        <ul>
          <li>Scan once and open the menu instantly in the browser</li>
          <li>No app install and no account required for basic ordering</li>
          <li>Clean item selection flow designed for tables and takeaway</li>
          <li>Phone-wallet checkout instead of manual bank-code entry</li>
        </ul>
      </details>

      <details>
        <summary>Current rollout focus</summary>
        <ul>
          <li>Restaurant profiles and menu publishing</li>
          <li>Menu sections, menu items, and option groups</li>
          <li>QR entry points for venue, table, or counter flows</li>
          <li>Wallet-first checkout and simple operator management</li>
        </ul>
      </details>
    </div>

  </section>

  <!-- FEATURES -->
  <!-- <section class="tw-section">
    <h2>Highlighted features</h2>

    <div class="tw-card-grid">
      <details><summary>📘 Text Recall & Practice</summary>
        <ul>
          <li>Smart trimming with adjustable hint levels</li>
          <li>Dual view: full vs trimmed text</li>
          <li>Scroll and touch-based navigation</li>
          <li>Offline access for saved items</li>
        </ul>
      </details>

      <details><summary>📴 Offline Mode</summary>
        <ul>
          <li>Offline access for selected lists</li>
          <li>Preserved order for rehearsal or performance</li>
          <li>Practice without internet access</li>
        </ul>
      </details>

      <details><summary>📝 PDF Integration & Annotation</summary>
        <ul>
          <li>PDF upload and drag-and-drop import</li>
          <li>Pen, eraser, undo, color picker</li>
          <li>Personal annotation layers</li>
          <li>IMSLP search and direct URL support</li>
        </ul>
      </details>

      <details><summary>✏️ Text Annotations</summary>
        <ul>
          <li>Private highlights, comments, drawings</li>
          <li>Draggable comment bubbles</li>
          <li>Unified undo system</li>
        </ul>
      </details>

      <details><summary>🎵 Music & Audio</summary>
        <ul>
          <li>Upload MIDI and audio formats</li>
          <li>YouTube, Spotify, Soundslice links</li>
          <li>Pinned and floating players</li>
        </ul>
      </details>

      <details><summary>💬 Collaboration & Privacy</summary>
        <ul>
          <li>Encrypted list chat</li>
          <li>Role-based access control</li>
          <li>Public, private, and secret lists</li>
        </ul>
      </details>
    </div>

  </section> -->

  <!-- FEATURES -->
  <section class="tw-section">
    <h2>Highlighted features</h2>

    <details>
      <summary>📋 Menu structure</summary>
      <ul>
        <li>Create menus as ordered collections of sections and items</li>
        <li>Reuse item structures across lunch, dinner, and seasonal menus</li>
        <li>Reorder sections and items without rebuilding the whole menu</li>
        <li>Support restaurant-level and location-level publishing</li>
        <li>Keep the current list engine as the first internal menu model</li>
      </ul>
    </details>

    <details>
      <summary>📱 Guest ordering flow</summary>
      <ul>
        <li>Open a menu directly from a table or counter QR code</li>
        <li>Browse categories and items on a phone without installing an app</li>
        <li>Select items quickly with a simple touch-first interface</li>
        <li>Move from menu to payment without leaving the browser</li>
        <li>Keep the flow short enough for real-world restaurant use</li>
      </ul>
    </details>    

    <details>
      <summary>💳 Payments</summary>
      <ul>
        <li>Wallet-first payment direction for Apple Pay and Google Pay</li>
        <li>Fast checkout designed for “scan, choose, pay”</li>
        <li>No bank-code entry in the guest flow</li>
        <li>Payment plumbing can evolve separately from menu publishing</li>
      </ul>
    </details>

    <details>
      <summary>🛠️ Restaurant management</summary>
      <ul>
        <li>Edit menu names, descriptions, and pricing centrally</li>
        <li>Toggle sold-out items or limited availability quickly</li>
        <li>Allow multiple staff roles with controlled access</li>
        <li>Prepare for location-specific menus and table flows</li>
      </ul>
    </details>

    <details>
      <summary>🔐 Access and operations</summary>
      <ul>
        <li>Role-based access for owner, manager, editor, and staff</li>
        <li>Shareable tokens remain useful for internal admin flows</li>
        <li>Existing permission logic can be reused during the transition</li>
        <li>Public customer-facing access stays separate from admin tools</li>
      </ul>
    </details>

    <details>
      <summary>⚡ Performance and reliability</summary>
      <ul>
        <li>Fast page loads through Cloudflare and the current PWA shell</li>
        <li>Mobile-friendly browsing for weak in-venue connections</li>
        <li>Progressive enhancement instead of mandatory app installs</li>
        <li>Room to add caching for popular menus later</li>
      </ul>
    </details>

    <details>
      <summary>🚧 Current status</summary>
      <ul>
        <li>This build is still a cleaned TextWhisper fork underneath</li>
        <li>Terminology and flows are being shifted toward restaurants</li>
        <li>Menu and order concepts will replace generic lists and items</li>
        <li>Test first on `test.taptray.com`, then promote to live</li>
      </ul>
    </details>

  </section>


  <!-- VIDEO -->
  <section class="tw-video" id="tutoring-videos">
    <div class="tw-video-content">
      <div class="tw-video-title">TapTray roadmap</div>
      <div class="tw-video-frame">
        <div style="padding:24px;background:#fff;height:100%;display:flex;align-items:center;justify-content:center;text-align:center;color:#5f5a53;">
          Early TapTray build. This area will later show product demos for guest ordering, QR flows, and restaurant setup.
        </div>
      </div>
    </div>
  </section>

</div>
