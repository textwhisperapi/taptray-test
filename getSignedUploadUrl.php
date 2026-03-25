<?php
require_once __DIR__ . "/includes/db_connect.php";
require_once __DIR__ . "/includes/functions.php";
sec_session_start();

if (!login_check($mysqli)) {
  http_response_code(403);
  exit(json_encode(["error" => "Not logged in"]));
}

$data = json_decode(file_get_contents("php://input"), true);

$owner     = $data["owner"]     ?? null;
$surrogate = $data["surrogate"] ?? null;
$type      = $data["type"]      ?? null;
$filename  = basename($data["filename"] ?? "");

if (!$owner || !$surrogate || !$filename) {
  http_response_code(400);
  exit(json_encode(["error" => "Bad request"]));
}

/* 1️⃣ authority: owner root list */
if (!userCanEditOwner($mysqli, $_SESSION["username"], $owner)) {
  http_response_code(403);
  exit(json_encode(["error" => "Access denied"]));
}

/* 2️⃣ build key */
$key = $type === "pdf"
  ? "$owner/pdf/temp_pdf_surrogate-$surrogate.pdf"
  : "$owner/surrogate-$surrogate/files/$filename";

/* 3️⃣ ask R2 worker to sign */
$signed = getSignedR2Url($key);

echo json_encode([
  "uploadUrl" => $signed,
  "key" => $key
]);
