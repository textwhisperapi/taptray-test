<?php
// /api/auth/microsoft/microsoft-login.php

$clientId    = 'fb1a4eb8-2b8e-47ce-bc45-9493078f6002';
$tenantId    = 'bd932aa7-9328-4dff-b288-0e41ecee6c20';

// Detect domain dynamically
$currentDomain = $_SERVER['HTTP_HOST'];
$redirectUri   = "https://{$currentDomain}/api/auth/microsoft/oauth2callback.php";

// Scopes
$scopes = [
    'openid',
    'profile',
    'email'
];

// Build Microsoft OAuth URL
$authUrl = "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/authorize" .
    "?client_id=" . urlencode($clientId) .
    "&response_type=code" .
    "&redirect_uri=" . urlencode($redirectUri) .
    "&response_mode=query" .
    "&scope=" . urlencode(implode(' ', $scopes)) .
    "&state=" . urlencode(bin2hex(random_bytes(16)));

header('Location: ' . $authUrl);
exit;
