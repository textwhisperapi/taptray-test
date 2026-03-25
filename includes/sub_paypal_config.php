<?php
/**
 * PayPal config + tiny SDK (curl only)
 * - Sandbox on geirigrimmi.com
 * - Live on textwhisper.com
 */
 



#Live
#API key: AdxtPQEucOzNuOm5iERXm5m4umEZBOwPa5yusJpx6YL9-UDRBk7LWhuA8INlzLJV44RavMKFPgA-OxrW
#secret:  EPaIB4UhNkaUz_L8vHgvSRgPvs6uPRBjsrZCey0dIvugddnV3a32yVUNUWK6i_f9IHPvZLq5rbn8H-z6

#Sandbox
#API key: ATosDdIXFR-LBLSmBxPJrfWmreYaGxLVOXyeCgXd9vn2ciQ5WjgyDD-_r4l6CymEal7JlUt1xS-D9iCl
#secret:  EITKfqCLHSIbOlntJMTjNDRChi838hjxbmGLNDgfBOD5Hs4lf5vGmfYli2RE9u6L7EFoRk_Oqz8yCbDH
#Sandbox credentials
# username sb-xe2cl44873816@business.example.com 
# pass: 63$pd*3G

#sb-vsery45222404@personal.example.com
# pass: x-Yrue8%
#Tester.123

// Name
// John Doe

// Phone
// 3548661555
// Country
// IS

// Account type
// Personal
// Account ID
// UNNJG87D5Z54C


#https://developer.paypal.com/docs/multiparty/checkout/standard/integrate/

#https://developer.paypal.com/tools/sandbox/card-testing/

// Generated credit card details
// Card number
// 4032031671235090
// Expiry date
// 06/2028
// CVC code
// 615


declare(strict_types=1);

$host = $_SERVER['HTTP_HOST'] ?? '';
$isLive = (stripos($host, 'textwhisper.com') !== false);

// 🔐 FILL THESE:
if ($isLive) {
  // LIVE
  define('PAYPAL_CLIENT_ID', 'AdxtPQEucOzNuOm5iERXm5m4umEZBOwPa5yusJpx6YL9-UDRBk7LWhuA8INlzLJV44RavMKFPgA-OxrW');
  define('PAYPAL_SECRET', 'EPaIB4UhNkaUz_L8vHgvSRgPvs6uPRBjsrZCey0dIvugddnV3a32yVUNUWK6i_f9IHPvZLq5rbn8H-z6');
  define('PAYPAL_API_BASE',  'https://api-m.paypal.com');
  define('PAYPAL_ENV',       'live');
} else {
  // SANDBOX
  define('PAYPAL_CLIENT_ID', 'ATosDdIXFR-LBLSmBxPJrfWmreYaGxLVOXyeCgXd9vn2ciQ5WjgyDD-_r4l6CymEal7JlUt1xS-D9iCl');
  define('PAYPAL_SECRET', 'EITKfqCLHSIbOlntJMTjNDRChi838hjxbmGLNDgfBOD5Hs4lf5vGmfYli2RE9u6L7EFoRk_Oqz8yCbDH');
  define('PAYPAL_API_BASE',  'https://api-m.sandbox.paypal.com');
  define('PAYPAL_ENV',       'sandbox');
}

/**
 * Get OAuth token (cached for the request)
 */
function paypal_get_access_token(): string {
  static $token = null;
  if ($token) return $token;

  $ch = curl_init(PAYPAL_API_BASE . '/v1/oauth2/token');
  curl_setopt_array($ch, [
    CURLOPT_POST            => true,
    CURLOPT_USERPWD         => PAYPAL_CLIENT_ID . ':' . PAYPAL_SECRET,
    CURLOPT_POSTFIELDS      => 'grant_type=client_credentials',
    CURLOPT_HTTPHEADER      => ['Accept: application/json'],
    CURLOPT_RETURNTRANSFER  => true,
    CURLOPT_TIMEOUT         => 20,
  ]);
  $resp = curl_exec($ch);
  if ($resp === false) throw new Exception('PayPal auth failed: ' . curl_error($ch));
  $data = json_decode($resp, true);
  if (empty($data['access_token'])) throw new Exception('PayPal auth: no token in response');
  return $token = $data['access_token'];
}

/**
 * Minimal API helper
 */
function paypal_api(string $method, string $path, ?array $body = null): array {
  $url = PAYPAL_API_BASE . $path;
  $hdr = [
    'Content-Type: application/json',
    'Authorization: Bearer ' . paypal_get_access_token(),
  ];
  $ch = curl_init($url);
  $opts = [
    CURLOPT_CUSTOMREQUEST   => $method,
    CURLOPT_HTTPHEADER      => $hdr,
    CURLOPT_RETURNTRANSFER  => true,
    CURLOPT_TIMEOUT         => 30,
  ];
  if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body);
  curl_setopt_array($ch, $opts);
  $resp = curl_exec($ch);
  if ($resp === false) throw new Exception("PayPal $method $path failed: " . curl_error($ch));
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $json = json_decode($resp, true);
  if ($code >= 400) {
    $msg = $json['message'] ?? $json['name'] ?? 'PayPal error';
    throw new Exception("PayPal $method $path HTTP $code: $msg");
  }
  return $json ?? [];
}

/**
 * Ensure we have (or create) a single reusable Product to host all plans.
 * We look up by name to avoid creating duplicates.
 */
function paypal_get_or_create_product(string $name = 'TextWhisper Subscription'): string {
  // Try to find an existing product with that name
  try {
    $list = paypal_api('GET', '/v1/catalogs/products?page_size=20');
    if (!empty($list['products'])) {
      foreach ($list['products'] as $p) {
        if (strcasecmp($p['name'] ?? '', $name) === 0) {
          return $p['id'];
        }
      }
    }
  } catch (Exception $e) {
    // Not fatal; we can just create it
  }

  // Create a new product
  $created = paypal_api('POST', '/v1/catalogs/products', [
    'name'        => $name,
    'description' => 'TextWhisper bundled subscription (plan + storage)',
    'type'        => 'SERVICE',
    'category'    => 'SOFTWARE',
  ]);
  return $created['id'];
}

/**
 * Create a brand-new ACTIVE plan for a given annual price (EUR).
 * We put your selection into name/description/custom_id so you can trace it later.
 */
function paypal_create_annual_plan(string $productId, string $planName, string $planDesc, string $priceEUR, array $meta = []): string {
  $payload = [
    'product_id'   => $productId,
    'name'         => $planName,
    'description'  => $planDesc,
    'status'       => 'ACTIVE',
    'billing_cycles' => [[
      'frequency'      => [ 'interval_unit' => 'YEAR', 'interval_count' => 1 ],
      'tenure_type'    => 'REGULAR',
      'sequence'       => 1,
      'total_cycles'   => 0, // infinite
      'pricing_scheme' => [ 'fixed_price' => [ 'value' => $priceEUR, 'currency_code' => 'EUR' ] ],
    ]],
    'payment_preferences' => [
      'auto_bill_outstanding'     => true,
      'setup_fee_failure_action'  => 'CANCEL',
      'payment_failure_threshold' => 3,
    ],
    // Store a compact signature of the selection (not required, handy for audit)
    'taxes' => [ 'percentage' => '0', 'inclusive' => false ],
    'quantity_supported' => false,
  ];

  // Allow some metadata via "custom_id" (kept on Plan)
  if ($meta) {
    $payload['custom_id'] = substr(base64_encode(json_encode($meta)), 0, 127);
  }

  $res = paypal_api('POST', '/v1/billing/plans', $payload);
  return $res['id'];
}

/**
 * Create a subscription to a plan and return the approval URL.
 */
function paypal_create_subscription(string $planId, string $returnUrl, string $cancelUrl, string $email = null, array $meta = []): array {
  $body = [
    'plan_id' => $planId,
    'application_context' => [
      'brand_name' => 'TextWhisper',
      'locale'     => 'en-US',
      'return_url' => $returnUrl,
      'cancel_url' => $cancelUrl,
      // Optional UX flags:
      'shipping_preference' => 'NO_SHIPPING',
      'user_action'         => 'SUBSCRIBE_NOW'
    ],
  ];
  if ($email) {
    $body['subscriber'] = ['email_address' => $email];
  }
  if ($meta) {
    $body['custom_id'] = substr(base64_encode(json_encode($meta)), 0, 127);
  }
  $res = paypal_api('POST', '/v1/billing/subscriptions', $body);

  $approve = null;
  foreach (($res['links'] ?? []) as $l) {
    if (($l['rel'] ?? '') === 'approve') { $approve = $l['href']; break; }
  }
  if (!$approve) throw new Exception('No approval link returned by PayPal.');
  return ['id' => $res['id'] ?? null, 'approve' => $approve];
}
