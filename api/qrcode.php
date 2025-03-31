<?php

/** @var array $config */

use Photobooth\Utility\PathUtility;
use Photobooth\Utility\QrCodeUtility;

require_once '../lib/boot.php';

$filename = (isset($_GET['filename']) && $_GET['filename']) != '' ? $_GET['filename'] : false;

if ($filename || !$config['qr']['append_filename']) {
    if ($config['ftp']['enabled'] && $config['ftp']['useForQr'] && isset($config['ftp']['processedTemplate'])) {
        $url = $config['ftp']['processedTemplate'] . DIRECTORY_SEPARATOR . $filename;
    } else {
        // Set the FastAPI endpoint URL (update the host and port as needed)
    $fastApiEndpoint = "http://localhost:8000/upload";

    // Build the complete URL with the filename as the "filepath" query parameter
    $url = $fastApiEndpoint . "?filename=" . urlencode($filename);

    // Initialize a cURL session
    $ch = curl_init();

    // Set cURL options
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Return response as string
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);           // Set a timeout (in seconds)

    // Execute the HTTP request
    $response = curl_exec($ch);

    // Check for cURL errors
    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        curl_close($ch);
        die("cURL error: " . $error_msg);
    }

    // Get the HTTP status code of the response
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // If the HTTP response code is not 200 (OK), handle it as an error
    if ($httpCode !== 200) {
        die("Error: Received HTTP status code " . $httpCode);
    }

    // Decode the JSON response
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        die("Error decoding JSON: " . json_last_error_msg());
    }

    // Check if the 'url' key exists in the response data
    if (isset($data["url"])) {
        $url = $data["url"];
    } else {
        // If an error detail is provided by the FastAPI endpoint, output it
        if (isset($data["detail"])) {
            die("Error: " . $data["detail"]);
        } else {
            die("Error: Unexpected response format.");
        };
    }

    }
    try {
        $result = QrCodeUtility::create($url);
        header('Content-Type: ' . $result->getMimeType());
        echo $result->getString();
    } catch (\Exception $e) {
        http_response_code(500);
        echo 'Error generating QR Code.';
        if ($config['dev']['loglevel'] > 1) {
            echo $e->getMessage();
        }
    }
} else {
    http_response_code(400);
    echo 'No filename defined.';
}
exit();
