<?php

$PLANS = [
  'free' => [
    'label' => 'Free',
    'price' => 0,
    'storage_limit' => '0.1 GB',
    'offline' => 'Limited',
    'stripe_price_id' => null
  ],
  'composer' => [
    'label' => 'Composer',
    'price' => 49,
    'storage_limit' => '2 GB',
    'offline' => 'Full',
    'stripe_price_id' => 'price_1Rsp1wBFbFoGOrx4GbKVPcvZ'
  ],
  'team_lite' => [
    'label' => 'Team Lite',
    'price' => 149,
    'storage_limit' => '5 GB',
    'offline' => 'Full',
    'stripe_price_id' => 'price_1Rsp2nBFbFoGOrx49Rk4A9ds'
  ],
  'team_standard' => [
    'label' => 'Team Standard',
    'price' => 399,
    'storage_limit' => '15 GB',
    'offline' => 'Full',
    'stripe_price_id' => 'price_1Rsp3UBFbFoGOrx4VZRRcVgw'
  ],
  'team_plus' => [
    'label' => 'Team Plus',
    'price' => 899,
    'storage_limit' => '30 GB',
    'offline' => 'Full',
    'stripe_price_id' => 'price_1Rsp42BFbFoGOrx4wl0oiGi1'
  ],
  'enterprise' => [
    'label' => 'Enterprise',
    'price' => 1499,
    'storage_limit' => '100 GB',
    'offline' => 'Full',
    'stripe_price_id' => 'price_1RtSNaBFbFoGOrx4dQUEFshD'
  ],
];

// $STORAGE_UPGRADES = [
//   'shared' => [
//     '0' => ['label' => 'Default', 'price' => 0],
//     '10' => ['label' => '+10 GB', 'price' => 30],
//     '50' => ['label' => '+50 GB', 'price' => 100],
//     '100' => ['label' => '+100 GB', 'price' => 150],
//     '1000' => ['label' => '+1 TB', 'price' => 1000],
//   ],
//   'enterprise' => [
//     '0' => ['label' => 'Default', 'price' => 0],
//     '500' => ['label' => '+500 GB', 'price' => 750],
//     '1000' => ['label' => '+1 TB', 'price' => 1300],
//     '2000' => ['label' => '+2 TB', 'price' => 2600],
//   ]
// ];

// $USER_UPGRADES = [
//   '0' => ['label' => 'Default (Up to 300)', 'price' => 0],
//   '500' => ['label' => '+200 users (500 total)', 'price' => 1200],
//   '1000' => ['label' => '+700 users (1000 total)', 'price' => 2000],
// ];




$PLANS_DEFAULT = [
    'free'          => ['label' => 'Free',          'gb' => 0.1,   'user' => 1,   'price' => 0],
    'team_lite'     => ['label' => 'Team Lite',     'gb' => 5,   'user' => 20,  'price' => 149],
    'team_standard' => ['label' => 'Team Standard', 'gb' => 15,  'user' => 70,  'price' => 399],
    'team_plus'     => ['label' => 'Team Plus',     'gb' => 30,  'user' => 150, 'price' => 799],
    'composer'      => ['label' => 'Composer',      'gb' => 2,   'user' => 3,   'price' => 49],
    'enterprise'    => ['label' => 'Enterprise',    'gb' => 100, 'user' => 300, 'price' => 1499],
];



$STORAGE_ADDON = [
    0    => ['label' => 'Default',   'gb' => 0,    'price' => 0],
    15   => ['label' => '+10 GB',    'gb' => 15,   'price' => 30],
    50   => ['label' => '+50 GB',    'gb' => 50,   'price' => 90],
    100  => ['label' => '+100 GB',   'gb' => 100,  'price' => 150],
    300  => ['label' => '+300 GB',   'gb' => 300,  'price' => 350],
    1000 => ['label' => '+1 TB',     'gb' => 1000, 'price' => 1200],
    2000 => ['label' => '+2 TB',     'gb' => 2000, 'price' => 2000],
];

$USER_ADDON = [
    300  => ['label' => 'Default (Up to 300)',      'price' => 0],
    500  => ['label' => '+200 users (500 total)',   'price' => 800],
    1000 => ['label' => '+700 users (1000 total)',  'price' => 2200],
];




function getUserPlan($userId, $planKey, $con, $PLANS) {
  $basePlan = $PLANS[$planKey] ?? $PLANS['free'];

  $stmt = $con->prepare("SELECT price_override, storage_override FROM custom_pricing WHERE user_id = ? LIMIT 1");
  $stmt->bind_param("i", $userId);
  $stmt->execute();
  $stmt->bind_result($price, $storage);
  if ($stmt->fetch()) {
    if (!is_null($price)) $basePlan['price'] = $price;
    if (!is_null($storage)) $basePlan['storage_limit'] = $storage;
  }
  $stmt->close();

  return $basePlan;
}



function calculateAddonPrice($planKey, $selectedStorageKey, $selectedUserKey) {
    global $STORAGE_ADDON, $USER_ADDON;

    $storagePrice = $STORAGE_ADDON[$selectedStorageKey]['price'] ?? 0;
    $userPrice    = $USER_ADDON[$selectedUserKey]['price'] ?? 0;

    if ($planKey === 'enterprise') {
        return $storagePrice + $userPrice;
    }

    return $storagePrice;
}




