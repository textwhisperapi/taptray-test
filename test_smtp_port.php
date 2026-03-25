<?php
echo fsockopen("mail.textwhisper.com", 587, $errno, $errstr, 10)
    ? "✔ Port 587 reachable"
    : "❌ Port 587 NOT reachable — $errno: $errstr";