<?php
$unitPriceMember = max(0, (float)($_GET['unit_price_member'] ?? 9));
$unitPriceGb = max(0, (float)($_GET['unit_price_gb'] ?? 2));
$validFrom = trim((string)($_GET['valid_from'] ?? date('Y-m-d')));
$discountBaseMembers = max(1, (float)($_GET['discount_base_members'] ?? 20));
$discountLogFactor = max(0, (float)($_GET['discount_log_factor'] ?? 0.06));
$discountFloor = max(0, min(1, (float)($_GET['discount_floor'] ?? 0.82)));

$scenarios = [
    ['members' => 20, 'storage_gb' => 5],
    ['members' => 50, 'storage_gb' => 10],
    ['members' => 80, 'storage_gb' => 15],
    ['members' => 150, 'storage_gb' => 30],
    ['members' => 300, 'storage_gb' => 100],
];

function tw_price_row(int $members, float $storageGb, float $unitPriceMember, float $unitPriceGb, float $discountBaseMembers, float $discountLogFactor, float $discountFloor): array {
    $memberPrice = $members * $unitPriceMember;
    $gbPrice = $storageGb * $unitPriceGb;
    $teamPrice = $memberPrice + $gbPrice;
    $rawFactor = 1 - log(($members / $discountBaseMembers) + 1) * $discountLogFactor;
    $sizeFactor = max($discountFloor, $rawFactor);
    $sizeDiscount = $teamPrice * ($sizeFactor - 1);
    $afterSize = $teamPrice * $sizeFactor;
    $discountShown = 1 - $sizeFactor;

    return [
        'members' => $members,
        'storage_gb' => $storageGb,
        'member_price' => $memberPrice,
        'gb_price' => $gbPrice,
        'team_price' => $teamPrice,
        'raw_factor' => $rawFactor,
        'size_factor' => $sizeFactor,
        'discount_shown' => $discountShown,
        'size_discount' => $sizeDiscount,
        'after_size' => $afterSize,
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Price Setup Test</title>
  <style>
    body {
      margin: 0;
      font-family: system-ui, sans-serif;
      background: #f5f7fb;
      color: #1f2937;
    }
    .wrap {
      max-width: 1180px;
      margin: 0 auto;
      padding: 24px;
    }
    .panel {
      background: #fff;
      border: 1px solid #dbe3ef;
      border-radius: 12px;
      padding: 20px;
      box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
    }
    .grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 16px;
    }
    .unit-lines {
      display: grid;
      gap: 12px;
    }
    .unit-line {
      display: grid;
      grid-template-columns: minmax(140px, 1.2fr) minmax(100px, 0.8fr) minmax(140px, 1fr) minmax(160px, 1fr);
      gap: 16px;
      align-items: end;
    }
    label {
      display: block;
      font-size: 14px;
      font-weight: 600;
      margin-bottom: 6px;
    }
    input, select, button {
      width: 100%;
      box-sizing: border-box;
      padding: 10px 12px;
      border-radius: 8px;
      border: 1px solid #cbd5e1;
      font-size: 15px;
    }
    button {
      background: #2563eb;
      color: #fff;
      border: 0;
      cursor: pointer;
      font-weight: 600;
    }
    .result {
      margin-top: 20px;
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
      gap: 12px;
    }
    .stat {
      background: #eff6ff;
      border: 1px solid #bfdbfe;
      border-radius: 10px;
      padding: 14px;
    }
    .stat strong {
      display: block;
      font-size: 13px;
      color: #475569;
      margin-bottom: 4px;
    }
    .stat span {
      font-size: 24px;
      font-weight: 700;
    }
    .hint {
      margin-top: 18px;
      font-size: 14px;
      color: #475569;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 18px;
      background: #fff;
    }
    th, td {
      padding: 10px 12px;
      border-bottom: 1px solid #e5e7eb;
      text-align: left;
      font-size: 14px;
      white-space: nowrap;
    }
    th {
      color: #475569;
      font-weight: 700;
    }
    .table-wrap {
      overflow-x: auto;
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="panel">
      <h1>Price Setup Test</h1>
      <p>General price setup for TW with priced units and one shared size-discount formula.</p>

      <form method="get">
        <h2>Price Setup</h2>
        <div class="unit-lines">
          <div class="unit-line">
            <div>
              <label for="unit_name_member">Unit Name</label>
              <input id="unit_name_member" type="text" value="Members" readonly>
            </div>
            <div>
              <label for="unit_member">Unit</label>
              <input id="unit_member" type="text" value="member" readonly>
            </div>
            <div>
              <label for="unit_price_member">Price</label>
              <input id="unit_price_member" name="unit_price_member" type="number" min="0" step="0.01" value="<?= htmlspecialchars((string)$unitPriceMember) ?>">
            </div>
            <div>
              <label for="valid_from_member">Valid From</label>
              <input id="valid_from_member" name="valid_from" type="date" value="<?= htmlspecialchars($validFrom) ?>">
            </div>
          </div>
          <div class="unit-line">
            <div>
              <label for="unit_name_gb">Unit Name</label>
              <input id="unit_name_gb" type="text" value="Storage" readonly>
            </div>
            <div>
              <label for="unit_gb">Unit</label>
              <input id="unit_gb" type="text" value="GB" readonly>
            </div>
            <div>
              <label for="unit_price_gb">Price</label>
              <input id="unit_price_gb" name="unit_price_gb" type="number" min="0" step="0.01" value="<?= htmlspecialchars((string)$unitPriceGb) ?>">
            </div>
            <div>
              <label for="valid_from_gb">Valid From</label>
              <input id="valid_from_gb" type="date" value="<?= htmlspecialchars($validFrom) ?>" readonly>
            </div>
          </div>
        </div>

        <h2>Discount Formula</h2>
        <div class="grid">
          <div>
            <label for="discount_base_members">Base Members</label>
            <input id="discount_base_members" name="discount_base_members" type="number" min="1" step="0.1" value="<?= htmlspecialchars((string)$discountBaseMembers) ?>">
          </div>
          <div>
            <label for="discount_log_factor">Log Factor</label>
            <input id="discount_log_factor" name="discount_log_factor" type="number" min="0" step="0.001" value="<?= htmlspecialchars((string)$discountLogFactor) ?>">
          </div>
          <div>
            <label for="discount_floor">Discount Floor</label>
            <input id="discount_floor" name="discount_floor" type="number" min="0" max="1" step="0.01" value="<?= htmlspecialchars((string)$discountFloor) ?>">
          </div>
        </div>

        <div style="margin-top:16px;">
          <button type="submit">Recalculate</button>
        </div>
      </form>

        <div class="result">
        <div class="stat">
          <strong>Members Price</strong>
          <span>€<?= number_format($unitPriceMember, 2) ?></span>
        </div>
        <div class="stat">
          <strong>GB Price</strong>
          <span>€<?= number_format($unitPriceGb, 2) ?></span>
        </div>
        <div class="stat">
          <strong>Log Factor</strong>
          <span><?= number_format($discountLogFactor, 3) ?></span>
        </div>
        <div class="stat">
          <strong>Discount Floor</strong>
          <span><?= number_format($discountFloor, 2) ?></span>
        </div>
      </div>

      <div class="table-wrap">
        <table>
          <tr>
            <th>Setting</th>
            <th>Value</th>
          </tr>
          <tr>
            <td>members</td>
            <td>€<?= number_format($unitPriceMember, 2) ?></td>
          </tr>
          <tr>
            <td>gb</td>
            <td>€<?= number_format($unitPriceGb, 2) ?></td>
          </tr>
          <tr>
            <td>Discount formula</td>
            <td><code>MAX(floor, 1 - LN(members / base_members + 1) * log_factor)</code></td>
          </tr>
          <tr>
            <td>Current inputs</td>
            <td>base members = <?= number_format($discountBaseMembers, 0) ?>, log factor = <?= number_format($discountLogFactor, 3) ?>, floor = <?= number_format($discountFloor, 2) ?></td>
          </tr>
        </table>
      </div>

      <h2>Preview</h2>
      <div class="table-wrap">
        <table>
          <tr>
            <th>Members</th>
            <th>GB</th>
            <th>Members Price</th>
            <th>GB Price</th>
            <th>Total</th>
            <th>Discount</th>
            <th>Result</th>
          </tr>
          <?php foreach ($scenarios as $scenario): ?>
            <?php $row = tw_price_row(
                $scenario['members'],
                $scenario['storage_gb'],
                $unitPriceMember,
                $unitPriceGb,
                $discountBaseMembers,
                $discountLogFactor,
                $discountFloor
            ); ?>
            <tr>
              <td><?= (int)$row['members'] ?></td>
              <td><?= rtrim(rtrim(number_format((float)$row['storage_gb'], 2, '.', ''), '0'), '.') ?></td>
              <td>€<?= number_format((float)$row['member_price'], 2) ?></td>
              <td>€<?= number_format((float)$row['gb_price'], 2) ?></td>
              <td>€<?= number_format((float)$row['team_price'], 2) ?></td>
              <td><?= number_format((float)$row['discount_shown'], 3) ?></td>
              <td><strong>€<?= number_format((float)$row['after_size'], 2) ?></strong></td>
            </tr>
          <?php endforeach; ?>
        </table>
      </div>

      <p class="hint">
        This page is only for price setup and preview. Promo logic can be tested separately later.
      </p>
    </div>
  </div>
</body>
</html>
