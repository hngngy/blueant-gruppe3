<?php

$config = require __DIR__ . '/../config/config.php';

/*
|--------------------------------------------------------------------------
| TEST-ENDPUNKT
|--------------------------------------------------------------------------
| Hier kannst du verschiedene Blue-Ant-Endpunkte testen.
|
| Beispiele:
| $endpoint = '/v1/projects';
| $endpoint = '/v1/projects/778393700';
| $endpoint = '/v1/projects/778393700/kpis';
| $endpoint = '/v1/projects/778393700/planningentries';
| $endpoint = '/v1/projects/778393700/individualrisks';
*/

$endpoint = '/v1/projects/419344634?includeMemoFields=true&includeOverallRisk=true';

$url = rtrim($config['blueant_base_url'], '/') . '/' . ltrim($endpoint, '/');

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'Authorization: Bearer ' . $config['blueant_token'],
    ],
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);

curl_close($ch);

$decodedResponse = json_decode($response, true);

header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'endpoint' => $endpoint,
    'url' => $url,
    'httpCode' => $httpCode,
    'curlError' => $error,
    'response' => $decodedResponse,
    'rawResponse' => $response,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);