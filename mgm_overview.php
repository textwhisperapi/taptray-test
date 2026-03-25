<?php
require_once __DIR__ . '/includes/mgm_ui.php';

$ctx = mgm_bootstrap('overview', 'Management Overview');

mgm_render_shell_start(
    $ctx,
    'General Overview',
    'This is the starting management shell. Traffic is the first live section; the remaining areas are staged so pricing, contracts, payments, users, and logs can grow into the same structure.'
);

$cards = [
    [
        'label' => 'Traffic Overview',
        'href' => '/mgm_traffic_overview.php',
        'tag' => 'Live',
        'copy' => 'Session footprint, recent app activity, login signals, error log pressure, and basic growth indicators.',
    ],
    [
        'label' => 'Price Settings',
        'href' => '/mgm_price_settings.php',
        'tag' => 'Preview',
        'copy' => 'Current pricing prototype. Still formula-only, not yet connected to billing source-of-truth.',
    ],
    [
        'label' => 'Payments',
        'href' => '/mgm_payment_settings.php',
        'tag' => 'Setup',
        'copy' => 'Merchant-of-record settings, wallet mode, and payment context source-of-truth.',
    ],
    [
        'label' => 'Contracts & Renewals',
        'href' => '#',
        'tag' => 'Queued',
        'copy' => 'Contract status, renewal timing, downgrade windows, and customer follow-up.',
    ],
    [
        'label' => 'Users',
        'href' => '/mgm_users.php',
        'tag' => 'Live',
        'copy' => 'Account state, verification, active sessions, and support-oriented account inspection.',
    ],
    [
        'label' => 'Insights',
        'href' => '#',
        'tag' => 'Queued',
        'copy' => 'Adoption, feature usage, conversions, and retention summaries.',
    ],
];
?>
      <section class="mgm-grid cols-3">
        <?php foreach ($cards as $card): ?>
          <a class="mgm-link-card" href="<?= mgm_h($card['href']) ?>">
            <article class="mgm-panel">
              <p class="mgm-stat-label"><?= mgm_h($card['label']) ?></p>
              <h2><?= mgm_h($card['tag']) ?></h2>
              <p class="mgm-panel-intro"><?= mgm_h($card['copy']) ?></p>
            </article>
          </a>
        <?php endforeach; ?>
      </section>

      <section class="mgm-grid cols-2" style="margin-top:18px;">
        <article class="mgm-panel">
          <h2>Current Direction</h2>
          <p class="mgm-panel-intro">Keep the management view simple: strong overview pages first, then drill-down pages for records and actions.</p>
          <ul class="mgm-list">
            <li>Overview pages should answer “what needs attention now?” before showing raw tables.</li>
            <li>Each section should expose one summary page and one detail table before deeper tools are added.</li>
            <li>Auditability matters more than visual density for payment and account operations.</li>
          </ul>
        </article>
      </section>

      <section class="mgm-grid cols-2" style="margin-top:18px;">
        <article class="mgm-panel">
          <h2>Next Suggested Pages</h2>
          <p class="mgm-panel-intro">After traffic, the next two high-value additions are pricing source-of-truth and payment exceptions.</p>
          <dl class="mgm-kv">
            <dt>Pricing</dt>
            <dd>Move plan definitions out of static PHP arrays into editable records with versioning.</dd>
            <dt>Payments</dt>
            <dd>Expose unresolved Worldline/PayPal/Stripe states and retry or follow-up actions.</dd>
            <dt>Users</dt>
            <dd>Show membership growth, verification state, and risky session patterns in one place.</dd>
          </dl>
        </article>
      </section>
<?php
mgm_render_shell_end();
