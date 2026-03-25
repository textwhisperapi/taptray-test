<?php
// smtp_test.php — standalone test page

require_once __DIR__ . "/includes/psl-config.php";
require_once __DIR__ . "/includes/functions.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// PHPMailer paths used by your system:
require_once __DIR__ . '/libs/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/libs/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/libs/PHPMailer/src/Exception.php';

$sentResult = null;
$errorInfo  = null;

if (isset($_POST['run_test'])) {

    $mail = new PHPMailer(true);

    try {
        $start = microtime(true);

        $mail->CharSet  = 'UTF-8';
        $mail->Encoding = 'base64';

        $mail->isSMTP();
        $mail->Host       = 'mail.textwhisper.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'noreply@textwhisper.com';
        $mail->Password   = 'VegaVinnur.99';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('noreply@textwhisper.com', 'SMTP TEST');
        $mail->addAddress('textwhisper.test@proton.me');
        $mail->isHTML(true);
        $mail->Subject = "SMTP Speed Test";
        $mail->Body    = "<p>This is a test email.</p>";

        $mail->send();

        $duration = round((microtime(true) - $start) * 1000);
        $sentResult = "SUCCESS — Mail sent in {$duration} ms";

    } catch (Exception $e) {
        $errorInfo = $mail->ErrorInfo;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>SMTP Test</title>
</head>
<body style="font-family:Arial; padding:40px;">

<h2>SMTP Speed Test (Port 587)</h2>

<form method="post">
    <button type="submit" name="run_test"
            style="padding:10px 20px; font-size:16px;">
        Run SMTP Test
    </button>
</form>

<?php if ($sentResult): ?>
    <p style="color:green; font-size:18px; margin-top:20px;">
        <?= htmlspecialchars($sentResult) ?>
    </p>
<?php endif; ?>

<?php if ($errorInfo): ?>
    <p style="color:red; font-size:18px; margin-top:20px;">
        ERROR: <?= htmlspecialchars($errorInfo) ?>
    </p>
<?php endif; ?>

</body>
</html>
