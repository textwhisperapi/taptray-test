<?php
require_once './includes/db_connect.php'; // adjust path as needed
header("Content-Type: application/json");

$email = $_POST['email'] ?? '';
if (!$email) {
  echo json_encode(["error" => "No email provided"]);
  exit;
}

$stmt = $mysqli->prepare("SELECT nda_agreed_at, nda_version FROM members WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$stmt->bind_result($nda_agreed_at, $nda_version);
$stmt->fetch();
$stmt->close();

echo json_encode([
  "accepted" => !empty($nda_agreed_at),
  "nda_agreed_at" => $nda_agreed_at,
  "nda_version" => $nda_version
]);
