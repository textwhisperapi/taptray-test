<?php
$ch = curl_init("https://d472035e0d93d58506601c81da008f0.r2.cloudflarestorage.com");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_NOBODY => true,
    CURLOPT_VERBOSE => true,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2, // try also TLSv1_3
]);
$data = curl_exec($ch);
if ($data === false) {
    echo "❌ TLS test failed: " . curl_error($ch);
} else {
    echo "✅ TLS connection OK\n\n";
    echo htmlentities($data);
}
curl_close($ch);
