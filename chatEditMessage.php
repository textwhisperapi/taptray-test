require_once "includes/functions.php";
require_once "includes/db_connect.php";
sec_session_start();

if (!login_check($mysqli)) {
  http_response_code(403);
  echo json_encode(['error' => 'Not logged in']);
  exit;
}

$id = $_POST['id'] ?? null;
$text = trim($_POST['text'] ?? '');

if (!$id || !$text) {
  echo json_encode(['error' => 'Invalid input']);
  exit;
}

// Only allow editing own messages
$stmt = $mysqli->prepare("UPDATE chat_messages SET message = ? WHERE id = ? AND username = ?");
$stmt->bind_param("sis", $text, $id, $_SESSION['username']);
$success = $stmt->execute();
$stmt->close();

echo json_encode(['status' => $success ? 'success' : 'failed']);
