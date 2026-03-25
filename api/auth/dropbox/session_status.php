<?php
session_start();

header("Content-Type: application/json");

echo json_encode([
  "hasToken" => isset($_SESSION["DROPBOX_ACCESS_TOKEN"])
]);
