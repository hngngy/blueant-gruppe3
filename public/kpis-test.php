<?php

require_once __DIR__ . '/../src/BlueAntClient.php';

$config = require __DIR__ . '/../config/config.php';

$endpoint = '/v1/projects/kpi_descriptions';

// Optionaler Suchfilter:
// http://localhost:8000/kpi-descriptions-test.php?search=arbeit
$search = isset($_GET['search']) ? strtolower(trim($_GET['search'])) : '';

try {
    $client = new BlueAntClient(
        $config['blueant_base_url'],
        $config['blueant_token']
    );

    $data = $client->get($endpoint);

    /*
     * Je nach API-Antwort kann der Key unterschiedlich heißen.
     * Deshalb prüfen wir mehrere mögliche Stellen.
     */
    $descriptions =
        $data['kpiDescriptions']
        ?? $data['kpis']
        ?? $data['descriptions']
        ?? $data['response']['kpiDescriptions']
        ?? $data['response']['kpis']
        ?? $data['response']['descriptions']
        ?? [];

    if ($search !== '') {
        $descriptions = array_filter($descriptions, function ($item) use ($search) {
            $id = strtolower((string)($item['id'] ?? ''));
            $name = strtolower((string)($item['name'] ?? $item['Name'] ?? ''));
            $description = strtolower((string)($item['description'] ?? $item['Description'] ?? $item['Beschreibung'] ?? ''));
            $dataType = strtolower((string)($item['dataType'] ?? $item['Datentyp'] ?? $item['type'] ?? ''));

            return str_contains($id, $search)
                || str_contains($name, $search)
                || str_contains($description, $search)
                || str_contains($dataType, $search);
        });
    }

    $result = [
        'status' => [
            'name' => 'OK',
            'code' => 200,
        ],
        'endpoint' => $endpoint,
        'search' => $search,
        'totalDescriptions' => count($descriptions),
        'descriptions' => array_values($descriptions),
        'rawResponse' => $data,
    ];
} catch (Throwable $e) {
    $result = [
        'status' => [
            'name' => 'ERROR',
            'code' => 500,
        ],
        'endpoint' => $endpoint,
        'search' => $search,
        'error' => $e->getMessage(),
    ];
}

header('Content-Type: application/json; charset=utf-8');

echo json_encode(
    $result,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
);
