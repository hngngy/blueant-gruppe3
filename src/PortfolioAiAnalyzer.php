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
        array $portfolio,
        array $projectAnalyses,
        array $trafficLightCounts,
        array $projectStatusCounts,
        array $milestoneSummary,
        array $criticalProjects
    ): array {
        $prompt = (string)($this->prompts['portfolio_management_summary'] ?? '');

        if ($prompt === '') {
            throw new RuntimeException('Prompt portfolio_management_summary ist nicht konfiguriert.');
        }

        $inputData = [
            'portfolio' => [
                'id' => $portfolio['id'] ?? null,
                'name' => $portfolio['name'] ?? null,
                'dateFrom' => $portfolio['dateFrom'] ?? null,
                'dateTo' => $portfolio['dateTo'] ?? null,
            ],
            'aggregations' => [
                'projectCount' => count($projectAnalyses),
                'trafficLightCounts' => $trafficLightCounts,
                'projectStatusCounts' => $projectStatusCounts,
                'milestoneSummary' => $milestoneSummary,
                'criticalProjectCount' => count($criticalProjects),
            ],
            'projects' => array_map([$this, 'normalizeProject'], $projectAnalyses),
        ];

        $report = $this->client->generateJson($prompt, $inputData);

        return $this->normalizeReport($report, $inputData);
    }

    private function normalizeProject(array $project): array
    {
        return [
            'id' => $project['id'] ?? null,
            'number' => $project['number'] ?? null,
            'name' => $project['name'] ?? null,
            'status' => $project['statusLabel'] ?? null,
            'trafficLight' => $project['gesamtstatus'] ?? null,
            'planAufwand' => $project['planAufwand'] ?? 0,
            'istAufwand' => $project['istAufwand'] ?? 0,
            'abweichungAufwand' => $project['abweichungAufwand'] ?? 0,
            'planFortschritt' => $project['planFortschritt'] ?? 0,
            'istFortschritt' => $project['istFortschritt'] ?? 0,
            'abweichungFortschritt' => $project['abweichungFortschritt'] ?? 0,
            'prognoseMehraufwand' => $project['prognoseMehraufwand'] ?? 0,
            'milestonesTotal' => $project['milestonesTotal'] ?? 0,
            'milestonesOpen' => $project['milestonesOpen'] ?? 0,
            'milestonesCompleted' => $project['milestonesCompleted'] ?? 0,
            'milestonesOverdue' => $project['milestonesOverdue'] ?? 0,
            'statusMemo' => $project['statusMemo'] ?? '',
            'gegenstandMemo' => $project['noteMemo'] ?? '',
            'riskAssessment' => $project['riskAssessment'] ?? '',
            'isCritical' => !empty($project['isCritical']),
            'criticalReasons' => $project['criticalReasons'] ?? [],
        ];
    }

    private function normalizeReport(array $report, array $inputData): array
    {
        $normalizedReport = [
            'management_summary' => $this->firstString($report, [
                'management_summary',
                'managementSummary',
                'management_zusammenfassung',
                'zusammenfassung',
                'summary',
                'thought',
            ]),
            'portfolio_status' => $this->firstString($report, [
                'portfolio_status',
                'portfolioStatus',
                'portfolio_status_einschaetzung',
                'status',
                'einordnung',
                'thought',
            ]),
            'critical_findings' => $this->firstList($report, [
                'critical_findings',
                'criticalFindings',
                'kritische_auffaelligkeiten',
                'kritischeAuffaelligkeiten',
                'auffaelligkeiten',
                'findings',
            ]),
            'recommended_actions' => $this->firstList($report, [
                'recommended_actions',
                'recommendedActions',
                'empfohlene_massnahmen',
                'empfohleneMassnahmen',
                'massnahmen',
                'actions',
            ]),
            'project_summaries' => $this->normalizeProjectSummaries($report),
        ];

        if ($normalizedReport['management_summary'] === '') {
            $normalizedReport['management_summary'] = $this->buildFallbackManagementSummary($inputData);
        }

        if ($normalizedReport['portfolio_status'] === '') {
            $normalizedReport['portfolio_status'] = $this->buildFallbackPortfolioStatus($inputData);
        }

        if (count($normalizedReport['recommended_actions']) === 0) {
            $normalizedReport['recommended_actions'] = $this->buildFallbackRecommendedActions($inputData);
        }

        if (count($normalizedReport['project_summaries']) === 0) {
            $normalizedReport['project_summaries'] = $this->buildFallbackProjectSummaries($inputData);
        }

        return $normalizedReport;
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
        $items = $report['project_summaries']
            ?? $report['projectSummaries']
            ?? $report['projekt_zusammenfassungen']
            ?? $report['projektZusammenfassungen']
            ?? $report['projects']
            ?? [];

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
                'summary' => (string)($item['summary'] ?? $item['zusammenfassung'] ?? $item['status_summary'] ?? '-'),
                'risk_note' => (string)($item['risk_note'] ?? $item['riskNote'] ?? $item['risikohinweis'] ?? 'Keine Angabe.'),
            ];
        }

        return $summaries;
    }

    private function buildFallbackManagementSummary(array $inputData): string
    {
        $portfolioName = (string)($inputData['portfolio']['name'] ?? 'das ausgewählte Portfolio');
        $projectCount = (int)($inputData['aggregations']['projectCount'] ?? 0);
        $criticalProjectCount = (int)($inputData['aggregations']['criticalProjectCount'] ?? 0);
        $overdueMilestones = (int)($inputData['aggregations']['milestoneSummary']['overdue'] ?? 0);

        return sprintf(
            'Das Portfolio "%s" umfasst %d Projekte. Davon sind %d Projekte nach den definierten Kriterien kritisch; insgesamt sind %d Meilensteine überfällig.',
            $portfolioName,
            $projectCount,
            $criticalProjectCount,
            $overdueMilestones
        );
    }

    private function buildFallbackPortfolioStatus(array $inputData): string
    {
        $trafficLightCounts = $inputData['aggregations']['trafficLightCounts'] ?? [];
        $redCount = (int)($trafficLightCounts['Rot'] ?? 0);
        $yellowCount = (int)($trafficLightCounts['Gelb'] ?? 0);
        $greenCount = (int)($trafficLightCounts['Grün'] ?? 0);

        return sprintf(
            'Statusampel-Verteilung: %d rot, %d gelb und %d gruen. Die Portfolioeinschaetzung basiert auf Statusampel, Fortschrittsabweichungen und Meilensteinlage.',
            $redCount,
            $yellowCount,
            $greenCount
        );
    }

    private function buildFallbackRecommendedActions(array $inputData): array
    {
        $actions = [];
        $criticalProjectCount = (int)($inputData['aggregations']['criticalProjectCount'] ?? 0);
        $overdueMilestones = (int)($inputData['aggregations']['milestoneSummary']['overdue'] ?? 0);

        if ($criticalProjectCount > 0) {
            $actions[] = 'Kritische Projekte priorisiert im PMO besprechen und Verantwortlichkeiten klaeren.';
        }

        if ($overdueMilestones > 0) {
            $actions[] = 'Überfällige Meilensteine prüfen und aktualisierte Terminplanung einfordern.';
        }

        if (count($actions) === 0) {
            $actions[] = 'Portfolio regelmaessig weiter beobachten; aktuell keine unmittelbare Eskalation aus den gelieferten Kennzahlen ableitbar.';
        }

        return $actions;
    }

    private function buildFallbackProjectSummaries(array $inputData): array
    {
        $projects = $inputData['projects'] ?? [];
        $summaries = [];

        if (!is_array($projects)) {
            return [];
        }

        foreach ($projects as $project) {
            if (!is_array($project)) {
                continue;
            }

            $trafficLight = (string)($project['trafficLight'] ?? 'Keine Angabe');
            $overdueMilestones = (int)($project['milestonesOverdue'] ?? 0);
            $progressDeviation = (float)($project['abweichungFortschritt'] ?? 0);

            $summaries[] = [
                'project_id' => (string)($project['id'] ?? '-'),
                'project_name' => (string)($project['name'] ?? '-'),
                'summary' => sprintf(
                    'Statusampel: %s; Fortschrittsabweichung: %.1f Prozentpunkte; überfällige Meilensteine: %d.',
                    $trafficLight,
                    $progressDeviation,
                    $overdueMilestones
                ),
                'risk_note' => !empty($project['isCritical'])
                    ? 'Projekt ist nach den definierten Kriterien kritisch.'
                    : 'Keine besonderen Risiken aus den definierten Kriterien erkennbar.',
            ];
        }

        return $summaries;
    }
}
