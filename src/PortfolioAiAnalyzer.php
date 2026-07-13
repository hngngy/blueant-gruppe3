<?php

class PortfolioAiAnalyzer
{
    private AiJsonClient $client;
    private array $prompts;

    public function __construct(AiJsonClient $client, array $prompts)
    {
        $this->client = $client;
        $this->prompts = $prompts;
    }

    public function createManagementSummary(
        array $portfolioContext,
        array $projectAnalyses,
        array $trafficLightCounts,
        array $projectStatusCounts,
        array $milestoneSummary,
        array $criticalProjects
    ): array {
        $prompt = trim((string)($this->prompts['portfolio_management_summary'] ?? ''));
        if ($prompt === '') {
            throw new RuntimeException('Prompt portfolio_management_summary ist nicht konfiguriert.');
        }

        $inputData = [
            'reportDate' => $portfolioContext['reportDate'] ?? null,
            'portfolios' => array_map(static fn (array $portfolio): array => [
                'id' => $portfolio['id'] ?? null,
                'name' => $portfolio['name'] ?? null,
                'dateFrom' => $portfolio['dateFrom'] ?? null,
                'dateTo' => $portfolio['dateTo'] ?? null,
            ], $portfolioContext['items'] ?? []),
            'aggregations' => [
                'projectCount' => count($projectAnalyses),
                'trafficLightCounts' => $trafficLightCounts,
                'projectStatusCounts' => $projectStatusCounts,
                'milestoneSummary' => $milestoneSummary,
                'criticalProjectCount' => count($criticalProjects),
            ],
            'projects' => array_map([$this, 'normalizeProject'], $projectAnalyses),
        ];

        return $this->normalizeReport($this->client->generateJson($prompt, $inputData), $inputData);
    }

    private function normalizeProject(array $project): array
    {
        return [
            'id' => $project['id'] ?? null,
            'number' => $project['number'] ?? null,
            'name' => $project['name'] ?? null,
            'portfolioNames' => $project['portfolioNames'] ?? [],
            'status' => $project['statusLabel'] ?? null,
            'trafficLight' => $project['gesamtstatus'] ?? null,
            'planAufwand' => $project['planAufwand'] ?? 0,
            'istAufwand' => $project['istAufwand'] ?? 0,
            'abweichungAufwand' => $project['abweichungAufwand'] ?? 0,
            'planFortschritt' => $project['planFortschritt'] ?? 0,
            'istFortschritt' => $project['istFortschritt'] ?? 0,
            'abweichungFortschritt' => $project['abweichungFortschritt'] ?? 0,
            'prognoseMehraufwand' => $project['prognoseMehraufwand'] ?? 0,
            'forecast' => $project['forecast'] ?? [],
            'forecastText' => $project['forecastText'] ?? '',
            'milestonesTotal' => $project['milestonesTotal'] ?? 0,
            'milestonesOpen' => $project['milestonesOpen'] ?? 0,
            'milestonesCompleted' => $project['milestonesCompleted'] ?? 0,
            'milestonesOverdue' => $project['milestonesOverdue'] ?? 0,
            'statusMemo' => $project['statusMemo'] ?? '',
            'subjectMemo' => $project['subjectMemo'] ?? '',
            'isCritical' => !empty($project['isCritical']),
            'criticalReasons' => $project['criticalReasons'] ?? [],
        ];
    }

    private function normalizeReport(array $report, array $inputData): array
    {
        $normalized = [
            'management_summary' => $this->firstString($report, ['management_summary', 'managementSummary', 'summary']),
            'portfolio_status' => $this->firstString($report, ['portfolio_status', 'portfolioStatus', 'einordnung']),
            'subject_overview' => $this->firstString($report, ['subject_overview', 'subjectOverview', 'gegenstand_overview']),
            'status_overview' => $this->firstString($report, ['status_overview', 'statusOverview', 'status_zusammenfassung']),
            'critical_findings' => $this->firstList($report, ['critical_findings', 'criticalFindings', 'findings']),
            'recommended_actions' => $this->firstList($report, ['recommended_actions', 'recommendedActions', 'actions']),
            'project_summaries' => $this->normalizeProjectSummaries($report),
        ];

        $normalized['management_summary'] = $normalized['management_summary'] ?: $this->buildFallbackManagementSummary($inputData);
        $normalized['portfolio_status'] = $normalized['portfolio_status'] ?: $this->buildFallbackPortfolioStatus($inputData);
        $normalized['subject_overview'] = $normalized['subject_overview'] ?: $this->buildFallbackTextOverview($inputData, 'subjectMemo');
        $normalized['status_overview'] = $normalized['status_overview'] ?: $this->buildFallbackTextOverview($inputData, 'statusMemo');
        $normalized['recommended_actions'] = $normalized['recommended_actions'] ?: $this->buildFallbackRecommendedActions($inputData);
        $normalized['project_summaries'] = $this->mergeProjectSummaries(
            $normalized['project_summaries'],
            $this->buildFallbackProjectSummaries($inputData)
        );

        return $normalized;
    }

    private function firstString(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                return trim((string)$data[$key]);
            }
        }
        return '';
    }

    private function firstList(array $data, array $keys): array
    {
        foreach ($keys as $key) {
            if (!isset($data[$key])) {
                continue;
            }
            if (is_array($data[$key])) {
                return array_values(array_filter(array_map('strval', $data[$key])));
            }
            if (is_scalar($data[$key])) {
                return [trim((string)$data[$key])];
            }
        }
        return [];
    }

    private function normalizeProjectSummaries(array $report): array
    {
        $items = $report['project_summaries'] ?? $report['projectSummaries'] ?? $report['projects'] ?? [];
        if (!is_array($items)) {
            return [];
        }

        $summaries = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $summaries[] = [
                'project_id' => (string)($item['project_id'] ?? $item['projectId'] ?? $item['id'] ?? '-'),
                'project_name' => (string)($item['project_name'] ?? $item['projectName'] ?? $item['name'] ?? '-'),
                'summary' => (string)($item['summary'] ?? 'Keine Angabe.'),
                'subject_summary' => (string)($item['subject_summary'] ?? $item['subjectSummary'] ?? 'Keine Angabe.'),
                'status_summary' => (string)($item['status_summary'] ?? $item['statusSummary'] ?? 'Keine Angabe.'),
                'forecast_summary' => (string)($item['forecast_summary'] ?? $item['forecastSummary'] ?? 'Keine belastbare Prognose möglich.'),
                'risk_note' => (string)($item['risk_note'] ?? $item['riskNote'] ?? 'Keine Angabe.'),
            ];
        }
        return $summaries;
    }

    private function buildFallbackManagementSummary(array $inputData): string
    {
        return sprintf(
            '%d ausgewählte Projekte aus %d Portfolio(s); %d kritisch und %d Meilensteine überfällig.',
            (int)($inputData['aggregations']['projectCount'] ?? 0),
            count($inputData['portfolios'] ?? []),
            (int)($inputData['aggregations']['criticalProjectCount'] ?? 0),
            (int)($inputData['aggregations']['milestoneSummary']['overdue'] ?? 0)
        );
    }

    private function buildFallbackPortfolioStatus(array $inputData): string
    {
        $counts = $inputData['aggregations']['trafficLightCounts'] ?? [];
        return sprintf(
            'Statusampel-Verteilung: %d rot, %d gelb, %d grün und %d ohne Angabe.',
            (int)($counts['Rot'] ?? 0),
            (int)($counts['Gelb'] ?? 0),
            (int)($counts['Grün'] ?? 0),
            (int)($counts['Keine Angabe'] ?? 0)
        );
    }

    private function buildFallbackTextOverview(array $inputData, string $field): string
    {
        $parts = [];
        foreach ($inputData['projects'] ?? [] as $project) {
            if (is_array($project) && trim((string)($project[$field] ?? '')) !== '') {
                $parts[] = (string)($project['name'] ?? '-') . ': ' . $this->shorten((string)$project[$field], 180);
            }
        }
        return $parts === [] ? 'Keine Angaben vorhanden.' : implode(' | ', $parts);
    }

    private function buildFallbackRecommendedActions(array $inputData): array
    {
        $actions = [];
        if ((int)($inputData['aggregations']['criticalProjectCount'] ?? 0) > 0) {
            $actions[] = 'Kritische Projekte priorisiert im PMO besprechen.';
        }
        if ((int)($inputData['aggregations']['milestoneSummary']['overdue'] ?? 0) > 0) {
            $actions[] = 'Überfällige Meilensteine prüfen und Terminplanung aktualisieren.';
        }
        return $actions ?: ['Portfolio weiter beobachten; aktuell keine unmittelbare Eskalation ableitbar.'];
    }

    private function buildFallbackProjectSummaries(array $inputData): array
    {
        $summaries = [];
        foreach ($inputData['projects'] ?? [] as $project) {
            if (!is_array($project)) {
                continue;
            }
            $summaries[] = [
                'project_id' => (string)($project['id'] ?? '-'),
                'project_name' => (string)($project['name'] ?? '-'),
                'summary' => sprintf(
                    'Statusampel: %s; Fortschrittsabweichung: %.1f Prozentpunkte; überfällige Meilensteine: %d.',
                    (string)($project['trafficLight'] ?? 'Keine Angabe'),
                    (float)($project['abweichungFortschritt'] ?? 0),
                    (int)($project['milestonesOverdue'] ?? 0)
                ),
                'subject_summary' => $this->shorten((string)($project['subjectMemo'] ?? ''), 260) ?: 'Keine Angabe.',
                'status_summary' => $this->shorten((string)($project['statusMemo'] ?? ''), 260) ?: 'Keine Angabe.',
                'forecast_summary' => (string)($project['forecastText'] ?? 'Keine belastbare Prognose möglich.'),
                'risk_note' => !empty($project['isCritical'])
                    ? 'Projekt ist nach den definierten Kriterien kritisch.'
                    : 'Keine besonderen Risiken aus den definierten Kriterien erkennbar.',
            ];
        }
        return $summaries;
    }

    private function shorten(string $text, int $maxLength): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');
        return strlen($text) <= $maxLength ? $text : rtrim(substr($text, 0, $maxLength - 3)) . '...';
    }

    private function mergeProjectSummaries(array $aiSummaries, array $fallbackSummaries): array
    {
        $byId = [];
        foreach ($aiSummaries as $summary) {
            if (is_array($summary)) {
                $byId[(string)($summary['project_id'] ?? '')] = $summary;
            }
        }

        $result = [];
        foreach ($fallbackSummaries as $fallback) {
            $id = (string)($fallback['project_id'] ?? '');
            $merged = array_merge($fallback, $byId[$id] ?? []);

            foreach (['summary', 'subject_summary', 'status_summary', 'forecast_summary', 'risk_note'] as $field) {
                if (trim((string)($merged[$field] ?? '')) === '') {
                    $merged[$field] = $fallback[$field];
                }
            }

            $result[] = $merged;
        }

        return $result;
    }
}
