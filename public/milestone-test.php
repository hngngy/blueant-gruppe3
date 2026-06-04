<?php

require_once __DIR__ . '/../src/BlueAntClient.php';

$config = require __DIR__ . '/../config/config.php';

$client = new BlueAntClient(
    $config['blueant_base_url'],
    $config['blueant_token']
);

$projectId = isset($_GET['id']) ? (int) $_GET['id'] : 358571979;

try {
    $data = $client->get('/v1/projects/' . $projectId . '/planningentries');

    $entries = $data['entries']
        ?? $data['response']['entries']
        ?? [];

    $milestones = array_filter($entries, function ($entry) {
        $entryType = strtolower((string)($entry['entryType'] ?? ''));

        return str_contains($entryType, 'milestone')
            || str_contains($entryType, 'meilenstein');
    });

    $result = [
        'status' => 'OK',
        'projectId' => $projectId,
        'totalEntries' => count($entries),
        'totalMilestones' => count($milestones),
        'milestones' => array_values($milestones),
    ];
} catch (Throwable $e) {
    $result = [
        'status' => 'ERROR',
        'projectId' => $projectId,
        'error' => $e->getMessage(),
    ];
}

header('Content-Type: application/json; charset=utf-8');

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);