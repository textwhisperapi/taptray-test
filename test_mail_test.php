<?php
$to = "geirigrimmi@gmail.com";
$subject = "Test Email";
$message = "This is a test email.";
$headers = "From: noreply@textwhisper.com\r\n";


if (mail($to, $subject, $message, $headers)) {
    echo "Email sent successfully.";
} else {
    echo "Failed to send email.";
}
?>
