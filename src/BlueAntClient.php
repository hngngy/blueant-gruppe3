<?php

class BlueAntClient
{
    private string $baseUrl;
    private string $token;

    public function __construct(string $baseUrl, string $token)
    {
        if (trim($baseUrl) === '') {
            throw new InvalidArgumentException('BLUEANT_BASE_URL ist nicht konfiguriert.');
        }

        if (trim($token) === '') {
            throw new InvalidArgumentException('BLUEANT_TOKEN ist nicht konfiguriert.');
        }

        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token = $token;
    }

    public function get(string $endpoint): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Die PHP-curl-Erweiterung ist nicht aktiviert.');
        }

        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer ' . $this->token,
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException("Blue Ant API Fehler: HTTP $httpCode $error");
        }

        return json_decode($response, true) ?? [];
    }

public function getProjects(): array
{
    $data = $this->get('/v1/projects?includeCustomFields=true');

    return $data['projects']
        ?? $data['response']['projects']
        ?? [];
}

public function getProject(int $projectId): array
{
    $data = $this->get(
        '/v1/projects/' . $projectId . '?includeMemoFields=true&includeOverallRisk=true'
    );

    return $data['project']
        ?? $data['response']['project']
        ?? $data;
}

public function getPortfolios(): array
{
    $data = $this->get('/v1/portfolios');
    return $data['portfolios']
        ?? $data['response']['portfolios']
        ?? [];
}

public function getProjectKpis(int $projectId): array
{
    $data = $this->get('/v1/projects/' . $projectId . '/kpis');
    return $data['kpis'] ?? [];
}

public function getProjectStatuses(): array
{
    $data = $this->get('/v1/masterdata/projects/statuses');

    return $data['projectStatus']
        ?? $data['projectStatuses']
        ?? [];
}

public function getPlanningEntries(int $projectId): array
{
    $data = $this->get('/v1/projects/' . $projectId . '/planningentries');

    return $data['entries']
        ?? $data['planningEntries']
        ?? [];
}
}
