<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . "/api/config_cloudflare.php";
require '/home1/wecanrec/textwhisper_vendor/aws_vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

$bucket = "twaudio";

try {
    $client = new S3Client([
        'region' => 'us-east-1', // ✅ use real region instead of "auto"
        'version' => 'latest',
        'endpoint' => "https://{$accountId}.r2.cloudflarestorage.com",
        'use_path_style_endpoint' => true,
        'credentials' => [
            'key'    => $accessKey,
            'secret' => $secretKey,
        ],
        'http' => [ // ✅ force TLS 1.2
            'verify' => true,
            'curl' => [
                CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            ],
        ],
    ]);

    $result = $client->listObjectsV2([
        'Bucket' => $bucket,
    ]);

    echo "<h2>Contents of bucket: {$bucket}</h2>";
    if (!empty($result['Contents'])) {
        echo "<ul>";
        foreach ($result['Contents'] as $object) {
            $name = htmlspecialchars($object['Key']);
            $size = number_format($object['Size'] / 1024, 2) . " KB";
            $date = $object['LastModified']->format('Y-m-d H:i:s');
            echo "<li>{$date} | {$size} | {$name}</li>";
        }
        echo "</ul>";
    } else {
        echo "Bucket is empty.";
    }
} catch (AwsException $e) {
    echo "❌ AWS Error: " . $e->getAwsErrorMessage() . "<br>";
    echo "❌ Full Message: " . $e->getMessage() . "<br>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
} catch (Exception $e) {
    echo "❌ PHP Exception: " . $e->getMessage();
}
