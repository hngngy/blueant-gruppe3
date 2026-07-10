<?php

require_once __DIR__ . '/../src/BlueAntClient.php';
require_once __DIR__ . '/../src/AiJsonClient.php';
require_once __DIR__ . '/../src/GeminiClient.php';
require_once __DIR__ . '/../src/PortfolioAiAnalyzer.php';
require_once __DIR__ . '/../src/ForecastAnalyzer.php';

$config = require __DIR__ . '/../config/config.php';

try {
    $client = new BlueAntClient(
        $config['blueant_base_url'],
        $config['blueant_token']
    );

    $portfolios = $client->getPortfolios();
    $allProjects = $client->getProjects();
    $projectStatuses = $client->getProjectStatuses();
    $error = null;
} catch (Throwable $e) {
    $portfolios = [];
    $allProjects = [];
    $projectStatuses = [];
    $error = $e->getMessage();
}

$statusMap = [];

foreach ($projectStatuses as $status) {
    $statusId = (int)($status['id'] ?? 0);

    if ($statusId > 0) {
        $statusName = $status['text']
            ?? $status['name']
            ?? ('Unbekannter Status');

        $statusMap[$statusId] = $statusName;
    }
}

$selectedPortfolioId = isset($_REQUEST['portfolioId']) ? (int) $_REQUEST['portfolioId'] : null;
$runAi = isset($_REQUEST['runAi']) && (string)$_REQUEST['runAi'] === '1';
$exportFormat = isset($_GET['export']) ? strtolower((string)$_GET['export']) : null;
$exportFormat = in_array($exportFormat, ['csv', 'txt'], true) ? $exportFormat : null;
$analysisStarted = isset($_REQUEST['analysisStarted']) && (string)$_REQUEST['analysisStarted'] === '1';
$selectedProjectIdsRaw = $_REQUEST['projectIds'] ?? [];
$selectedProjectIdsRaw = is_array($selectedProjectIdsRaw) ? $selectedProjectIdsRaw : [$selectedProjectIdsRaw];
$reportDateInput = trim((string)($_REQUEST['reportDate'] ?? date('Y-m-d')));
$reportDateError = null;
$defaultAiPrompt = (string)($config['ai']['prompts']['portfolio_management_summary'] ?? '');
$customAiPrompt = trim((string)($_REQUEST['aiPrompt'] ?? $defaultAiPrompt));
$activeAiPrompt = $customAiPrompt !== '' ? $customAiPrompt : $defaultAiPrompt;

try {
    $reportDate = DateTimeImmutable::createFromFormat('!Y-m-d', $reportDateInput);

    if (!$reportDate || $reportDate->format('Y-m-d') !== $reportDateInput) {
        throw new InvalidArgumentException('Ungültiger Stichtag');
    }
} catch (Throwable $e) {
    $reportDate = new DateTimeImmutable('today');
    $reportDateInput = $reportDate->format('Y-m-d');
    $reportDateError = 'Der Stichtag war ungültig und wurde auf heute gesetzt.';
}

$selectedPortfolio = null;
$portfolioProjectIds = [];
$portfolioProjects = [];
$selectedProjectIds = [];
$selectedPortfolioProjects = [];

foreach ($portfolios as $portfolio) {
    if ((int)($portfolio['id'] ?? 0) === $selectedPortfolioId) {
        $selectedPortfolio = $portfolio;
        $portfolioProjectIds = $portfolio['projectIds'] ?? [];
        break;
    }
}

if ($selectedPortfolio) {
    foreach ($allProjects as $project) {
        if (in_array($project['id'] ?? null, $portfolioProjectIds, true)) {
            $portfolioProjects[] = $project;
        }
    }

    $availableProjectIds = array_values(array_filter(array_map(
        static fn (array $project): int => (int)($project['id'] ?? 0),
        $portfolioProjects
    )));

    if ($analysisStarted) {
        $selectedProjectIds = array_map('intval', $selectedProjectIdsRaw);
        $selectedProjectIds = array_values(array_unique(array_intersect($selectedProjectIds, $availableProjectIds)));
    }

    foreach ($portfolioProjects as $project) {
        if (in_array((int)($project['id'] ?? 0), $selectedProjectIds, true)) {
            $selectedPortfolioProjects[] = $project;
        }
    }
}

$portfolioProjectAnalyses = [];

if ($analysisStarted && count($selectedPortfolioProjects) > 0) {
    foreach ($selectedPortfolioProjects as $project) {
        $projectId = (int)($project['id'] ?? 0);

        if ($projectId <= 0) {
            continue;
        }

    $kpis = $client->getProjectKpis($projectId);
    $projectDetails = $client->getProject($projectId);

    $planAufwand = getKpiValue($kpis, 'WorkTotalPlan');
    $istAufwand = getKpiValue($kpis, 'WorkTotalActual');
    $abweichung = $istAufwand - $planAufwand;
    $project['planAufwand'] = $planAufwand;
    $project['istAufwand'] = $istAufwand;
    $project['abweichungAufwand'] = $abweichung;

    $planFortschritt = getKpiValue($kpis, 'DevelopmentPlanProgress');
    $istFortschritt = getKpiValue($kpis, 'SubjectiveProgress');
    $abweichungFortschritt = $istFortschritt - $planFortschritt;
    $project['planFortschritt'] = $planFortschritt;
    $project['istFortschritt'] = $istFortschritt;
    $project['abweichungFortschritt'] = $abweichungFortschritt;

    $prognoseMehraufwand = getKpiValue($kpis, 'PrognosisOvertime');
    $project['prognoseMehraufwand'] = $prognoseMehraufwand;

    $customFields = $projectDetails['customFields'] ?? $project['customFields'] ?? [];
    $blueAntTrafficLight = extractBlueAntTrafficLight($projectDetails, $customFields);

    $project['hasBlueAntTrafficLight'] = $blueAntTrafficLight['available'];
    $project['blueAntTrafficLight'] = $blueAntTrafficLight['color'];
    $project['blueAntTrafficLightReason'] = $blueAntTrafficLight['reason'];

    $gesamtstatusRaw = $customFields['832814142'] ?? null;
    $project['gesamtstatus'] = translateTrafficLight($gesamtstatusRaw);
    $statusId = (int)($projectDetails['statusId'] ?? $project['statusId'] ?? 0);
    $statusName = $statusMap[$statusId] ?? 'Unbekannter Status';
    $project['statusName'] = $statusName;
    $project['statusLabel'] = $statusId . ' - ' . $statusName;

    $project['statusMemo'] = trim(strip_tags((string)($projectDetails['statusMemo'] ?? '')));
    $project['noteMemo'] = trim(strip_tags((string)($projectDetails['noteMemo'] ?? '')));

    $planningEntries = $client->getPlanningEntries($projectId);
    $milestones = analyzeMilestones($planningEntries, $reportDate);

    $project['milestonesTotal'] = $milestones['total'];
    $project['milestonesCompleted'] = $milestones['completed'];
    $project['milestonesOpen'] = $milestones['open'];
    $project['milestonesOverdue'] = $milestones['overdue'];
    $project['todosTotal'] = $milestones['todosTotal'];
    $project['todosOpen'] = $milestones['todosOpen'];
    $project['todosOverdue'] = $milestones['todosOverdue'];
    $project['planningHasData'] = $milestones['hasPlanningData'];

    $overallRisk = $projectDetails['overallRisk'] ?? [];
    $project['overallRiskId'] = $overallRisk['overallRiskId'] ?? null;
    $project['riskAssessment'] = trim(strip_tags((string)($overallRisk['riskAssessment'] ?? '')));

    $criticalAnalysis = analyzeCriticalProject($project);
    $project['isCritical'] = $criticalAnalysis['isCritical'];
    $project['criticalReasons'] = $criticalAnalysis['reasons'];

    // Prognose berechnen
    $forecast = ForecastAnalyzer::analyzeProjectForecast($project, $reportDate->format('Y-m-d'));
    $project['forecast'] = $forecast;
    $project['forecastText'] = ForecastAnalyzer::formatForecast($forecast);

    $healthAnalysis = analyzeOwnProjectHealth($project);
    $project['eigeneAmpel'] = $healthAnalysis['color'];
    $project['eigeneAmpelBegruendung'] = $healthAnalysis['reason'];

    $portfolioProjectAnalyses[] = $project;
    }
}

$blueAntTrafficLightCounts = [
    'Rot' => 0,
    'Gelb' => 0,
    'Grün' => 0,
    'Nicht verfügbar' => 0,
];

$eigeneTrafficLightCounts = [
    'Rot' => 0,
    'Gelb' => 0,
    'Grün' => 0,
    'Grau' => 0,
];

foreach ($portfolioProjectAnalyses as $project) {
    $blueAntColor = (string)($project['blueAntTrafficLight'] ?? 'Keine Angabe');

    if (!empty($project['hasBlueAntTrafficLight']) && isset($blueAntTrafficLightCounts[$blueAntColor])) {
        $blueAntTrafficLightCounts[$blueAntColor]++;
    } else {
        $blueAntTrafficLightCounts['Nicht verfügbar']++;
    }

    $eigeneColor = (string)($project['eigeneAmpel'] ?? 'Grau');

    if (!isset($eigeneTrafficLightCounts[$eigeneColor])) {
        $eigeneTrafficLightCounts[$eigeneColor] = 0;
    }

    $eigeneTrafficLightCounts[$eigeneColor]++;
}

$projectStatusCounts = [];

foreach ($portfolioProjectAnalyses as $project) {
    $statusId = (int)($project['statusId'] ?? 0);
    $statusName = $statusMap[$statusId] ?? 'Unbekannter Status';

    $label = $statusId . ' - ' . $statusName;

    if (!isset($projectStatusCounts[$label])) {
        $projectStatusCounts[$label] = 0;
    }

    $projectStatusCounts[$label]++;
}


ksort($projectStatusCounts);

$trafficLightCounts = [
    'Rot' => 0,
    'Gelb' => 0,
    'Grün' => 0,
    'Keine Angabe' => 0,
];

foreach ($portfolioProjectAnalyses as $project) {
    $status = $project['gesamtstatus'] ?? '-';

    if ($status === '-') {
        $status = 'Keine Angabe';
    }

    $trafficLightCounts[$status]++;
}

$milestoneSummary = [
    'total' => 0,
    'open' => 0,
    'completed' => 0,
    'overdue' => 0,
];

foreach ($portfolioProjectAnalyses as $project) {
    $milestoneSummary['total'] += (int)($project['milestonesTotal'] ?? 0);
    $milestoneSummary['open'] += (int)($project['milestonesOpen'] ?? 0);
    $milestoneSummary['completed'] += (int)($project['milestonesCompleted'] ?? 0);
    $milestoneSummary['overdue'] += (int)($project['milestonesOverdue'] ?? 0);
}

$criticalProjects = array_filter($portfolioProjectAnalyses, function ($project) {
    return !empty($project['isCritical']);
});

$selectedProjectCount = count($portfolioProjectAnalyses);
$criticalProjectCount = count($criticalProjects);
$maxEffortValue = 0.0;
$maxOverdueMilestones = 0;

foreach ($portfolioProjectAnalyses as $project) {
    $maxEffortValue = max(
        $maxEffortValue,
        (float)($project['planAufwand'] ?? 0),
        (float)($project['istAufwand'] ?? 0)
    );
    $maxOverdueMilestones = max($maxOverdueMilestones, (int)($project['milestonesOverdue'] ?? 0));
}

if ($analysisStarted && $selectedPortfolio && count($selectedPortfolioProjects) > 0 && $exportFormat) {
    exportPortfolioReport(
        $exportFormat,
        $selectedPortfolio,
        $reportDate,
        $portfolioProjectAnalyses,
        $trafficLightCounts,
        $projectStatusCounts,
        $milestoneSummary,
        $criticalProjects
    );
}

$aiReport = null;
$aiError = null;

if ($analysisStarted && $runAi && $selectedPortfolio && count($selectedPortfolioProjects) > 0 && ($config['ai']['enabled'] ?? false)) {
    try {
        $aiClient = new GeminiClient(
            $config['ai']['base_url'],
            $config['ai']['api_key'],
            $config['ai']['model'],
            (float)$config['ai']['temperature'],
            (int)$config['ai']['timeout_seconds']
        );

        $aiPrompts = $config['ai']['prompts'];
        $aiPrompts['portfolio_management_summary'] = $activeAiPrompt;
        $aiAnalyzer = new PortfolioAiAnalyzer($aiClient, $aiPrompts);
        $portfolioForAi = $selectedPortfolio;
        $portfolioForAi['reportDate'] = $reportDate->format('Y-m-d');

        $aiReport = $aiAnalyzer->createManagementSummary(
            $portfolioForAi,
            $portfolioProjectAnalyses,
            $trafficLightCounts,
            $projectStatusCounts,
            $milestoneSummary,
            $criticalProjects
        );
    } catch (Throwable $e) {
        $aiError = $e->getMessage();
    }
}

function getKpiValue(array $kpis, string $id, string $period = 'TOTAL'): float
{
    foreach ($kpis as $kpi) {
        if (($kpi['id'] ?? '') === $id && ($kpi['period'] ?? '') === $period) {
            return (float) ($kpi['value'] ?? 0);
        }
    }

    return 0;
}

function translateTrafficLight($value): string
{
    return match ((string)$value) {
        '1' => 'Rot',
        '2' => 'Gelb',
        '3' => 'Grün',
        default => 'Keine Angabe',
    };
}

function getTrafficLightClass(string $status): string
{
    return match ($status) {
        'Rot' => 'status-red',
        'Gelb' => 'status-yellow',
        'Grün' => 'status-green',
        'Grau' => 'status-unknown',
        default => 'status-unknown',
    };
}

function analyzeMilestones(array $entries, DateTimeImmutable $reportDate): array
{
    $total = 0;
    $completed = 0;
    $open = 0;
    $overdue = 0;
    $todosTotal = 0;
    $todosOpen = 0;
    $todosOverdue = 0;

    foreach ($entries as $entry) {
        $entryType = strtolower((string)($entry['entryType'] ?? ''));
        $isMilestone = str_contains($entryType, 'milestone') || str_contains($entryType, 'meilenstein');
        $progress = (float)($entry['progressActual'] ?? $entry['percentComplete'] ?? $entry['progress'] ?? 0);
        $endDateRaw = $entry['end'] ?? $entry['endWished'] ?? $entry['endDate'] ?? $entry['targetDate'] ?? null;
        $isCompleted = $progress >= 100;

        if ($isMilestone) {
            $total++;

            if ($isCompleted) {
                $completed++;
                continue;
            }

            $open++;

            if ($endDateRaw) {
                try {
                    $endDate = new DateTimeImmutable((string)$endDateRaw);

                    if ($endDate < $reportDate) {
                        $overdue++;
                    }
                } catch (Throwable $e) {
                    // Ungültiges Datum ignorieren
                }
            }

            continue;
        }

        $todosTotal++;

        if ($isCompleted) {
            continue;
        }

        $todosOpen++;

        if ($endDateRaw) {
            try {
                $endDate = new DateTimeImmutable((string)$endDateRaw);

                if ($endDate < $reportDate) {
                    $todosOverdue++;
                }
            } catch (Throwable $e) {
                // Ungültiges Datum ignorieren
            }
        }
    }

    return [
        'total' => $total,
        'completed' => $completed,
        'open' => $open,
        'overdue' => $overdue,
        'todosTotal' => $todosTotal,
        'todosOpen' => $todosOpen,
        'todosOverdue' => $todosOverdue,
        'hasPlanningData' => ($total + $todosTotal) > 0,
    ];
}

function extractBlueAntTrafficLight(array $projectDetails, array $customFields): array
{
    $candidates = [];

    foreach (['trafficLight', 'trafficlight', 'statusTrafficLight', 'statusAmpel', 'ampel', 'statusColor', 'trafficLightValue', 'stateColor'] as $fieldName) {
        if (array_key_exists($fieldName, $projectDetails)) {
            $candidates[] = $projectDetails[$fieldName];
        }
    }

    foreach ($customFields as $fieldName => $value) {
        $fieldNameLower = strtolower((string)$fieldName);

        if (str_contains($fieldNameLower, 'traffic') || str_contains($fieldNameLower, 'ampel') || str_contains($fieldNameLower, 'status')) {
            $candidates[] = $value;
        }
    }

    foreach ($candidates as $candidate) {
        $normalized = normalizeTrafficLightValue($candidate);

        if ($normalized !== null) {
            return [
                'available' => true,
                'color' => $normalized['color'],
                'reason' => $normalized['reason'],
            ];
        }
    }

    return [
        'available' => false,
        'color' => 'Keine Angabe',
        'reason' => 'Nicht verfügbar',
    ];
}

function normalizeTrafficLightValue(mixed $value): ?array
{
    if ($value === null) {
        return null;
    }

    if (is_array($value)) {
        if (array_key_exists('value', $value)) {
            return normalizeTrafficLightValue($value['value']);
        }

        if (array_key_exists('text', $value)) {
            return normalizeTrafficLightValue($value['text']);
        }

        return null;
    }

    if (is_numeric($value)) {
        return match ((int)$value) {
            1 => ['color' => 'Rot', 'reason' => ''],
            2 => ['color' => 'Gelb', 'reason' => ''],
            3 => ['color' => 'Grün', 'reason' => ''],
            default => null,
        };
    }

    $normalized = strtolower(trim((string)$value));

    return match ($normalized) {
        '1', 'rot', 'red', 'r' => ['color' => 'Rot', 'reason' => ''],
        '2', 'gelb', 'yellow', 'y' => ['color' => 'Gelb', 'reason' => ''],
        '3', 'grün', 'grun', 'green', 'g' => ['color' => 'Grün', 'reason' => ''],
        default => null,
    };
}

function analyzeOwnProjectHealth(array $project): array
{
    if (empty($project['planningHasData'])) {
        return [
            'color' => 'Grau',
            'reason' => 'Keine Planungsdaten verfügbar.',
        ];
    }

    $milestonesOpen = (int)($project['milestonesOpen'] ?? 0);
    $milestonesOverdue = (int)($project['milestonesOverdue'] ?? 0);
    $todosOpen = (int)($project['todosOpen'] ?? 0);
    $todosOverdue = (int)($project['todosOverdue'] ?? 0);
    $progressDeviation = (float)($project['abweichungFortschritt'] ?? 0);

    if ($milestonesOverdue > 0) {
        return [
            'color' => 'Rot',
            'reason' => $milestonesOverdue . ' überfällige Meilensteine.',
        ];
    }

    if ($todosOpen > 0) {
        $overdueRate = $todosOpen > 0 ? ($todosOverdue / $todosOpen) * 100 : 0.0;

        if ($overdueRate > 20) {
            return [
                'color' => 'Rot',
                'reason' => $todosOverdue . ' offene To-dos sind überfällig (>20 %).',
            ];
        }

        if ($todosOverdue > 0) {
            return [
                'color' => 'Gelb',
                'reason' => $todosOverdue . ' offene To-dos sind überfällig.',
            ];
        }
    }

    if ($progressDeviation <= -20) {
        return [
            'color' => 'Gelb',
            'reason' => 'Fortschritt liegt ' . formatDecimal(abs($progressDeviation)) . ' Prozentpunkte hinter dem Plan.',
        ];
    }

    if (($milestonesOpen > 0 || $todosOpen > 0) && $progressDeviation <= -10) {
        return [
            'color' => 'Gelb',
            'reason' => 'Es gibt offene Planungen und der Fortschritt ist leicht hinter dem Plan zurück.',
        ];
    }

    return [
        'color' => 'Grün',
        'reason' => 'Keine offenen Verzögerungen bei Meilensteinen oder To-dos.',
    ];
}

function analyzeCriticalProject(array $project): array
{
    $reasons = [];

    $gesamtstatus = (string)($project['gesamtstatus'] ?? 'Keine Angabe');
    $abweichungFortschritt = (float)($project['abweichungFortschritt'] ?? 0);
    $milestonesOverdue = (int)($project['milestonesOverdue'] ?? 0);
    $statusMemo = strtolower((string)($project['statusMemo'] ?? ''));
    $noteMemo = strtolower((string)($project['noteMemo'] ?? ''));
    $riskAssessment = strtolower((string)($project['riskAssessment'] ?? ''));

    if ($gesamtstatus === 'Rot') {
        $reasons[] = 'Statusampel ist Rot';
    }

    if ($abweichungFortschritt <= -50) {
        $reasons[] = 'Fortschritt liegt mindestens 50 Prozentpunkte hinter Plan';
    }

    if ($milestonesOverdue > 0) {
        $reasons[] = $milestonesOverdue . ' überfällige Meilensteine';
    }

    $criticalRiskWords = [
    'kritisch',
    'gefährdet',
    'eskalation',
    'eskaliert',
    'problem',
    'probleme',
    'verzögerung',
    'verzögert',
    'verzug',
    'terminverzug',
    'rückstand',
    'blockiert',
    'blockade',
    'hohes risiko',
    'hoher gefährdungsbereich',
    'budgetüberschreitung',
    'kostenüberschreitung',
    'mehraufwand',
    'ressourcenengpass',
    'fehlende ressourcen',
    'abhängigkeit',
    'abhängigkeiten',
    'lieferverzug',
    'nicht im plan',
    'abweichung',
    'handlungsbedarf',
    'gegenmaßnahmen erforderlich',
    'entscheidung erforderlich',
    'freigabe fehlt',
    'kunde blockiert',
    'scope creep',
];

$textForRiskCheck = $statusMemo . ' ' . $noteMemo . ' ' . $riskAssessment;

foreach ($criticalRiskWords as $keyword) {
    $keyword = strtolower($keyword);

    if ($textForRiskCheck !== '' && str_contains($textForRiskCheck, $keyword)) {
        $reasons[] = 'kritischer Hinweis im Status-/Risikotext: "' . $keyword . '"';
        break;
    }
}

    return [
        'isCritical' => count($reasons) > 0,
        'reasons' => $reasons,
    ];
}

function exportPortfolioReport(
    string $format,
    array $portfolio,
    DateTimeImmutable $reportDate,
    array $projects,
    array $trafficLightCounts,
    array $projectStatusCounts,
    array $milestoneSummary,
    array $criticalProjects
): void {
    $portfolioId = (string)($portfolio['id'] ?? 'portfolio');
    $date = $reportDate->format('Y-m-d');
    $baseFileName = 'portfolio-dashboard-' . safeFileName($portfolioId) . '-' . $date;

    if ($format === 'csv') {
        exportPortfolioCsv($baseFileName, $portfolio, $reportDate, $projects);
        exit;
    }

    exportPortfolioText(
        $baseFileName,
        $portfolio,
        $reportDate,
        $projects,
        $trafficLightCounts,
        $projectStatusCounts,
        $milestoneSummary,
        $criticalProjects
    );
    exit;
}

function exportPortfolioCsv(
    string $baseFileName,
    array $portfolio,
    DateTimeImmutable $reportDate,
    array $projects
): void {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $baseFileName . '.csv"');

    $output = fopen('php://output', 'wb');
    fwrite($output, "\xEF\xBB\xBF");

    fputcsv($output, ['Portfolio', (string)($portfolio['name'] ?? '-')], ';');
    fputcsv($output, ['Portfolio-ID', (string)($portfolio['id'] ?? '-')], ';');
    fputcsv($output, ['Stichtag', $reportDate->format('Y-m-d')], ';');
    fputcsv($output, [], ';');
    fputcsv($output, [
        'Projekt-ID',
        'Projektnummer',
        'Projektname',
        'Projektstatus',
        'Plan-Aufwand',
        'Ist-Aufwand',
        'Aufwand-Abweichung',
        'Plan-Fortschritt',
        'Ist-Fortschritt',
        'Fortschritt-Abweichung',
        'Prognose Mehraufwand',
        'Meilensteine gesamt',
        'Meilensteine offen',
        'Meilensteine erledigt',
        'Meilensteine überfällig',
        'Statusampel',
        'Kritisch',
        'Kritische Gründe',
    ], ';');

    foreach ($projects as $project) {
        fputcsv($output, [
            (string)($project['id'] ?? '-'),
            (string)($project['number'] ?? '-'),
            (string)($project['name'] ?? '-'),
            (string)($project['statusLabel'] ?? '-'),
            (string)($project['planAufwand'] ?? 0),
            (string)($project['istAufwand'] ?? 0),
            (string)($project['abweichungAufwand'] ?? 0),
            (string)($project['planFortschritt'] ?? 0),
            (string)($project['istFortschritt'] ?? 0),
            (string)($project['abweichungFortschritt'] ?? 0),
            (string)($project['prognoseMehraufwand'] ?? 0),
            (string)($project['milestonesTotal'] ?? 0),
            (string)($project['milestonesOpen'] ?? 0),
            (string)($project['milestonesCompleted'] ?? 0),
            (string)($project['milestonesOverdue'] ?? 0),
            (string)($project['gesamtstatus'] ?? '-'),
            !empty($project['isCritical']) ? 'Ja' : 'Nein',
            implode(' | ', $project['criticalReasons'] ?? []),
        ], ';');
    }

    fclose($output);
}

function exportPortfolioText(
    string $baseFileName,
    array $portfolio,
    DateTimeImmutable $reportDate,
    array $projects,
    array $trafficLightCounts,
    array $projectStatusCounts,
    array $milestoneSummary,
    array $criticalProjects
): void {
    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $baseFileName . '.txt"');

    echo "Portfolio-Dashboard\n";
    echo "===================\n\n";
    echo 'Portfolio: ' . (string)($portfolio['name'] ?? '-') . "\n";
    echo 'Portfolio-ID: ' . (string)($portfolio['id'] ?? '-') . "\n";
    echo 'Stichtag: ' . $reportDate->format('Y-m-d') . "\n";
    echo 'Anzahl Projekte: ' . count($projects) . "\n\n";

    echo "Statusampel-Zusammenfassung\n";
    foreach ($trafficLightCounts as $status => $count) {
        echo '- ' . $status . ': ' . $count . "\n";
    }

    echo "\nProjektstatus-Zusammenfassung\n";
    foreach ($projectStatusCounts as $status => $count) {
        echo '- ' . $status . ': ' . $count . "\n";
    }

    echo "\nMeilenstein-Zusammenfassung\n";
    echo '- Gesamt: ' . $milestoneSummary['total'] . "\n";
    echo '- Offen: ' . $milestoneSummary['open'] . "\n";
    echo '- Erledigt: ' . $milestoneSummary['completed'] . "\n";
    echo '- Überfällig: ' . $milestoneSummary['overdue'] . "\n";

    echo "\nKritische Projekte\n";
    if (count($criticalProjects) === 0) {
        echo "Keine kritischen Projekte nach den aktuellen Kriterien gefunden.\n";
    } else {
        foreach ($criticalProjects as $project) {
            echo '- ' . (string)($project['number'] ?? '-') . ' - ' . (string)($project['name'] ?? '-') . "\n";
            echo '  Gründe: ' . implode(' | ', $project['criticalReasons'] ?? []) . "\n";
        }
    }

    echo "\nProjekte\n";
    foreach ($projects as $project) {
        echo '- ' . (string)($project['number'] ?? '-') . ' - ' . (string)($project['name'] ?? '-') . "\n";
        echo '  Status: ' . (string)($project['statusLabel'] ?? '-') . "\n";
        echo '  Aufwand Plan/Ist/Abweichung: '
            . (string)($project['planAufwand'] ?? 0) . ' / '
            . (string)($project['istAufwand'] ?? 0) . ' / '
            . (string)($project['abweichungAufwand'] ?? 0) . "\n";
        echo '  Fortschritt Plan/Ist/Abweichung: '
            . (string)($project['planFortschritt'] ?? 0) . '% / '
            . (string)($project['istFortschritt'] ?? 0) . '% / '
            . (string)($project['abweichungFortschritt'] ?? 0) . "%\n";
        echo '  Meilensteine offen/überfällig: '
            . (string)($project['milestonesOpen'] ?? 0) . ' / '
            . (string)($project['milestonesOverdue'] ?? 0) . "\n";
    }
}

function safeFileName(string $value): string
{
    $safe = preg_replace('/[^A-Za-z0-9_-]+/', '-', $value);
    return trim((string)$safe, '-') ?: 'export';
}

function percentage(float $value, float $total): float
{
    if ($total <= 0) {
        return 0.0;
    }

    return round(($value / $total) * 100, 1);
}

function barWidth(float $value, float $max): string
{
    return number_format(percentage($value, $max), 1, '.', '');
}

function formatDecimal(float $value): string
{
    return number_format($value, 1, ',', '.');
}

function renderSelectedProjectHiddenInputs(array $selectedProjectIds): void
{
    echo '<input type="hidden" name="analysisStarted" value="1">';

    foreach ($selectedProjectIds as $projectId) {
        echo '<input type="hidden" name="projectIds[]" value="' . htmlspecialchars((string)$projectId) . '">';
    }
}

function renderList(array $items): void
{
    if (count($items) === 0) {
        echo '<p>Keine Angabe.</p>';
        return;
    }

    echo '<ul>';

    foreach ($items as $item) {
        echo '<li>' . htmlspecialchars((string)$item) . '</li>';
    }

    echo '</ul>';
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Portfolio-Dashboard</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>

<main class="page-shell">
<header class="hero">
    <h1>Portfolio-Dashboard</h1>
    <p>Wähle Portfolio, Stichtag und Projekte aus, um eine reproduzierbare Management-Auswertung zu erstellen.</p>
</header>

<?php if ($error): ?>
    <p class="message message-danger">Fehler: <?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<form method="get" action="portfolio-dashboard.php" class="control-card">
    <div class="field">
        <label for="portfolioId">Portfolio auswählen</label>

        <select name="portfolioId" id="portfolioId">
            <option value="">-- Portfolio wählen --</option>

            <?php foreach ($portfolios as $portfolio): ?>
                <option
                    value="<?= htmlspecialchars((string)$portfolio['id']) ?>"
                    <?= ((int)($portfolio['id'] ?? 0) === $selectedPortfolioId) ? 'selected' : '' ?>
                >
                    <?= htmlspecialchars($portfolio['name'] ?? 'Unbenanntes Portfolio') ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="field">
        <label for="reportDate">Stichtag</label>
        <input
            type="date"
            id="reportDate"
            name="reportDate"
            value="<?= htmlspecialchars($reportDateInput) ?>"
        >
    </div>

    <button type="submit">Projekte anzeigen</button>
</form>

<?php if ($reportDateError): ?>
    <p class="message message-warning"><?= htmlspecialchars($reportDateError) ?></p>
<?php endif; ?>

<?php if ($selectedPortfolio): ?>

<section class="card">
    <h2>Ausgewähltes Portfolio: <?= htmlspecialchars($selectedPortfolio['name'] ?? '-') ?></h2>

    <div class="portfolio-meta">
        <div class="meta-item">
            <span class="meta-label">Portfolio-ID</span>
            <span class="meta-value"><?= htmlspecialchars((string)($selectedPortfolio['id'] ?? '-')) ?></span>
        </div>
        <div class="meta-item">
            <span class="meta-label">Zeitraum</span>
            <span class="meta-value">
                <?= htmlspecialchars($selectedPortfolio['dateFrom'] ?? '-') ?>
                bis
                <?= htmlspecialchars($selectedPortfolio['dateTo'] ?? '-') ?>
            </span>
        </div>
        <div class="meta-item">
            <span class="meta-label">Stichtag</span>
            <span class="meta-value"><?= htmlspecialchars($reportDate->format('Y-m-d')) ?></span>
        </div>
        <div class="meta-item">
            <span class="meta-label">Projekte</span>
            <span class="meta-value"><?= count($selectedPortfolioProjects) ?> / <?= count($portfolioProjects) ?> ausgewählt</span>
        </div>
    </div>
</section>

<section class="project-card">
    <h3>Projekte auswählen</h3>

    <form method="get" action="portfolio-dashboard.php">
        <input type="hidden" name="portfolioId" value="<?= htmlspecialchars((string)($selectedPortfolio['id'] ?? '')) ?>">
        <input type="hidden" name="reportDate" value="<?= htmlspecialchars($reportDate->format('Y-m-d')) ?>">
        <input type="hidden" name="analysisStarted" value="1">

        <?php if (count($portfolioProjects) === 0): ?>
            <p class="message message-info">Dieses Portfolio enthält keine Projekte.</p>
        <?php else: ?>
            <div class="actions-card actions-inline">
                <button type="button" id="selectAllBtn" class="button-secondary">Alle auswählen</button>
                <button type="button" id="selectNoneBtn" class="button-secondary">Alle abwählen</button>
            </div>

            <div class="project-list">
                <?php foreach ($portfolioProjects as $project): ?>
                    <?php $projectId = (int)($project['id'] ?? 0); ?>
                    <label class="project-option">
                        <input
                            type="checkbox"
                            name="projectIds[]"
                            value="<?= htmlspecialchars((string)$projectId) ?>"
                            <?= in_array($projectId, $selectedProjectIds, true) ? 'checked' : '' ?>
                        >
                        <span>
                            <span class="project-title"><?= htmlspecialchars((string)($project['name'] ?? 'Unbenanntes Projekt')) ?></span>
                            <span class="project-number"><?= htmlspecialchars((string)($project['number'] ?? '-')) ?></span>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>

            <button type="submit">Auswertung starten</button>
        <?php endif; ?>
    </form>
</section>

    <?php if ($analysisStarted && count($selectedPortfolioProjects) === 0): ?>
        <p class="message message-warning">Bitte wähle mindestens ein Projekt aus.</p>
    <?php endif; ?>

<?php if ($analysisStarted && count($selectedPortfolioProjects) > 0): ?>

    <form method="get" action="portfolio-dashboard.php" class="actions-card">
        <input type="hidden" name="portfolioId" value="<?= htmlspecialchars((string)($selectedPortfolio['id'] ?? '')) ?>">
        <input type="hidden" name="reportDate" value="<?= htmlspecialchars($reportDate->format('Y-m-d')) ?>">
        <?php renderSelectedProjectHiddenInputs($selectedProjectIds); ?>
        <button type="submit" name="export" value="csv" class="button-secondary">CSV exportieren</button>
        <button type="submit" name="export" value="txt" class="button-secondary">Text exportieren</button>
    </form>

<section class="ai-card">
<h3>KI-gestützte Management Summary</h3>

<?php if (!($config['ai']['enabled'] ?? false)): ?>
    <p class="message message-info">KI-Auswertung ist deaktiviert. Setze <code>AI_ENABLED=true</code> in der <code>.env</code>, um Gemini zu verwenden.</p>
<?php elseif (!$runAi): ?>
    <p class="message message-info">Prüfe den Prompt vor dem KI-Aufruf. Du kannst ihn für diese Auswertung anpassen; die gespeicherte Prompt-Datei wird dadurch nicht verändert.</p>
    <form method="post" action="portfolio-dashboard.php" class="prompt-form">
        <input type="hidden" name="portfolioId" value="<?= htmlspecialchars((string)($selectedPortfolio['id'] ?? '')) ?>">
        <input type="hidden" name="reportDate" value="<?= htmlspecialchars($reportDate->format('Y-m-d')) ?>">
        <?php renderSelectedProjectHiddenInputs($selectedProjectIds); ?>
        <input type="hidden" name="runAi" value="1">
        <label for="aiPrompt">Prompt für die KI-Auswertung</label>
        <textarea id="aiPrompt" name="aiPrompt" rows="18"><?= htmlspecialchars($activeAiPrompt) ?></textarea>
        <div class="actions-card actions-inline">
            <button type="submit" class="button-ai">KI-Auswertung starten</button>
        </div>
    </form>
    <p class="message message-info">Die Auswertung unten funktioniert unabhängig von der KI. Die KI wird erst nach Klick auf den Button gestartet.</p>
<?php elseif ($aiError): ?>
    <p class="message message-danger">KI-Fehler: <?= htmlspecialchars($aiError) ?></p>
<?php elseif ($aiReport): ?>
    <details class="prompt-preview">
        <summary>Verwendeten Prompt anzeigen</summary>
        <pre><?= htmlspecialchars($activeAiPrompt) ?></pre>
    </details>

    <h4>Management Summary</h4>
    <p><?= nl2br(htmlspecialchars((string)($aiReport['management_summary'] ?? 'Keine KI-Zusammenfassung geliefert.'))) ?></p>

    <h4>Portfolio-Status</h4>
    <p><?= nl2br(htmlspecialchars((string)($aiReport['portfolio_status'] ?? 'Keine Einordnung geliefert.'))) ?></p>

    <h4>Kritische Auffälligkeiten</h4>
    <?php renderList($aiReport['critical_findings'] ?? []); ?>

    <h4>Empfohlene Maßnahmen</h4>
    <?php renderList($aiReport['recommended_actions'] ?? []); ?>

    <h4>Projekt-Zusammenfassungen</h4>
    <?php if (!empty($aiReport['project_summaries']) && is_array($aiReport['project_summaries'])): ?>
        <table>
            <thead>
                <tr>
                    <th>Projekt</th>
                    <th>Zusammenfassung</th>
                    <th>Risikohinweis</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($aiReport['project_summaries'] as $summary): ?>
                    <tr>
                        <td>
                            <?= htmlspecialchars((string)($summary['project_id'] ?? '-')) ?>
                            -
                            <?= htmlspecialchars((string)($summary['project_name'] ?? '-')) ?>
                        </td>
                        <td><?= nl2br(htmlspecialchars((string)($summary['summary'] ?? '-'))) ?></td>
                        <td><?= nl2br(htmlspecialchars((string)($summary['risk_note'] ?? '-'))) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>Keine Projekt-Zusammenfassungen geliefert.</p>
    <?php endif; ?>
<?php endif; ?>
</section>

<section class="visualization-section">
    <h3>Visualisierte Auswertung</h3>

    <div class="chart-grid">
        <article class="chart-card">
            <h4>Statusampel-Verteilung</h4>
            <div class="traffic-grid">
                <?php foreach ($trafficLightCounts as $status => $count): ?>
                    <?php $share = percentage((float)$count, (float)$selectedProjectCount); ?>
                    <div class="traffic-tile traffic-<?= htmlspecialchars(strtolower(str_replace(' ', '-', $status))) ?>">
                        <span class="traffic-label"><?= htmlspecialchars($status) ?></span>
                        <strong><?= htmlspecialchars((string)$count) ?></strong>
                        <span><?= htmlspecialchars(formatDecimal($share)) ?> %</span>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="chart-note">Basis: <?= htmlspecialchars((string)$selectedProjectCount) ?> ausgewertete Projekte.</p>
        </article>

        <article class="chart-card">
            <h4>Meilenstein-Verteilung</h4>
            <div class="stacked-bar" aria-label="Meilenstein-Verteilung">
                <?php $milestoneTotal = max(1, (int)$milestoneSummary['total']); ?>
                <?php $openNotOverdue = max(0, (int)$milestoneSummary['open'] - (int)$milestoneSummary['overdue']); ?>
                <span class="segment segment-completed" style="width: <?= barWidth((float)$milestoneSummary['completed'], (float)$milestoneTotal) ?>%"></span>
                <span class="segment segment-open" style="width: <?= barWidth((float)$openNotOverdue, (float)$milestoneTotal) ?>%"></span>
                <span class="segment segment-overdue" style="width: <?= barWidth((float)$milestoneSummary['overdue'], (float)$milestoneTotal) ?>%"></span>
            </div>
            <div class="legend">
                <span><i class="legend-dot segment-completed"></i>Erledigt: <?= htmlspecialchars((string)$milestoneSummary['completed']) ?></span>
                <span><i class="legend-dot segment-open"></i>Offen, nicht überfällig: <?= htmlspecialchars((string)$openNotOverdue) ?></span>
                <span><i class="legend-dot segment-overdue"></i>Überfällig: <?= htmlspecialchars((string)$milestoneSummary['overdue']) ?></span>
            </div>
            <p class="chart-note">Gesamt: <?= htmlspecialchars((string)$milestoneSummary['total']) ?> Meilensteine.</p>
        </article>

        <article class="chart-card">
            <h4>Kritische Projekte</h4>
            <div class="stacked-bar" aria-label="Kritische Projekte">
                <span class="segment segment-critical" style="width: <?= barWidth((float)$criticalProjectCount, (float)max(1, $selectedProjectCount)) ?>%"></span>
                <span class="segment segment-normal" style="width: <?= barWidth((float)($selectedProjectCount - $criticalProjectCount), (float)max(1, $selectedProjectCount)) ?>%"></span>
            </div>
            <div class="legend">
                <span><i class="legend-dot segment-critical"></i>Kritisch: <?= htmlspecialchars((string)$criticalProjectCount) ?></span>
                <span><i class="legend-dot segment-normal"></i>Nicht kritisch: <?= htmlspecialchars((string)($selectedProjectCount - $criticalProjectCount)) ?></span>
            </div>
            <p class="chart-note"><?= htmlspecialchars(formatDecimal(percentage((float)$criticalProjectCount, (float)$selectedProjectCount))) ?> % der ausgewerteten Projekte sind kritisch.</p>
        </article>
    </div>

    <div class="chart-card">
        <h4>Plan-/Ist-Fortschritt je Projekt</h4>
        <div class="bar-list">
            <?php foreach ($portfolioProjectAnalyses as $project): ?>
                <?php
                    $planProgress = max(0.0, min(100.0, (float)($project['planFortschritt'] ?? 0)));
                    $actualProgress = max(0.0, min(100.0, (float)($project['istFortschritt'] ?? 0)));
                ?>
                <div class="bar-row">
                    <div class="bar-label">
                        <strong><?= htmlspecialchars((string)($project['number'] ?? '-')) ?></strong>
                        <span><?= htmlspecialchars((string)($project['name'] ?? '-')) ?></span>
                    </div>
                    <div class="bar-pair">
                        <div class="bar-track">
                            <span class="bar-fill bar-plan" style="width: <?= htmlspecialchars(number_format($planProgress, 1, '.', '')) ?>%"></span>
                        </div>
                        <span class="bar-value">Plan <?= htmlspecialchars(formatDecimal((float)($project['planFortschritt'] ?? 0))) ?> %</span>
                        <div class="bar-track">
                            <span class="bar-fill bar-actual" style="width: <?= htmlspecialchars(number_format($actualProgress, 1, '.', '')) ?>%"></span>
                        </div>
                        <span class="bar-value">Ist <?= htmlspecialchars(formatDecimal((float)($project['istFortschritt'] ?? 0))) ?> %</span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="chart-grid chart-grid-wide">
        <article class="chart-card">
            <h4>Plan-/Ist-Aufwand je Projekt</h4>
            <div class="bar-list">
                <?php foreach ($portfolioProjectAnalyses as $project): ?>
                    <div class="bar-row">
                        <div class="bar-label">
                            <strong><?= htmlspecialchars((string)($project['number'] ?? '-')) ?></strong>
                            <span><?= htmlspecialchars((string)($project['name'] ?? '-')) ?></span>
                        </div>
                        <div class="bar-pair">
                            <div class="bar-track">
                                <span class="bar-fill bar-plan" style="width: <?= barWidth((float)($project['planAufwand'] ?? 0), $maxEffortValue) ?>%"></span>
                            </div>
                            <span class="bar-value">Plan <?= htmlspecialchars(formatDecimal((float)($project['planAufwand'] ?? 0))) ?></span>
                            <div class="bar-track">
                                <span class="bar-fill bar-actual" style="width: <?= barWidth((float)($project['istAufwand'] ?? 0), $maxEffortValue) ?>%"></span>
                            </div>
                            <span class="bar-value">Ist <?= htmlspecialchars(formatDecimal((float)($project['istAufwand'] ?? 0))) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>

        <article class="chart-card">
            <h4>Überfällige Meilensteine je Projekt</h4>
            <div class="bar-list">
                <?php foreach ($portfolioProjectAnalyses as $project): ?>
                    <?php $overdueMilestones = (int)($project['milestonesOverdue'] ?? 0); ?>
                    <div class="bar-row bar-row-compact">
                        <div class="bar-label">
                            <strong><?= htmlspecialchars((string)($project['number'] ?? '-')) ?></strong>
                            <span><?= htmlspecialchars((string)($project['name'] ?? '-')) ?></span>
                        </div>
                        <div class="single-bar">
                            <div class="bar-track">
                                <span class="bar-fill bar-risk" style="width: <?= barWidth((float)$overdueMilestones, (float)$maxOverdueMilestones) ?>%"></span>
                            </div>
                            <span class="bar-value"><?= htmlspecialchars((string)$overdueMilestones) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>
    </div>
</section>

<h3>Statusampel-Zusammenfassung</h3>
<p class="message message-info">Die eigene Ampel wird aus Meilensteinen, offenen To-dos und der Fortschrittsabweichung berechnet. Die BlueAnt-Ampel wird nur dann angezeigt, wenn die API für das Projekt einen echten Ampelwert liefert; in den aktuellen Live-Daten ist das nicht zuverlässig der Fall.</p>

<table>
    <thead>
        <tr>
            <th>Statusampel</th>
            <th>Anzahl (Blue Ant, wenn verfügbar)</th>
            <th>Anzahl (Unsere Berechnung)</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($blueAntTrafficLightCounts as $status => $count): ?>
            <tr>
                <td><?= htmlspecialchars($status) ?></td>
                <td><?= htmlspecialchars((string)$count) ?></td>
                <td>
                    <?= htmlspecialchars((string)($eigeneTrafficLightCounts[$status] ?? 0)) ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<h3>Projektstatus-Zusammenfassung</h3>

<table>
    <thead>
        <tr>
            <th>Status-ID und Name</th>
            <th>Anzahl Projekte</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($projectStatusCounts as $statusLabel => $count): ?>
            <tr>
                <td><?= htmlspecialchars($statusLabel) ?></td>
                <td><?= htmlspecialchars((string)$count) ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<h3>Meilenstein-Zusammenfassung</h3>

<table>
    <tbody>
        <tr>
            <th>Meilensteine gesamt</th>
            <td><?= htmlspecialchars((string)$milestoneSummary['total']) ?></td>
        </tr>
        <tr>
            <th>Offene Meilensteine</th>
            <td><?= htmlspecialchars((string)$milestoneSummary['open']) ?></td>
        </tr>
        <tr>
            <th>Erledigte Meilensteine</th>
            <td><?= htmlspecialchars((string)$milestoneSummary['completed']) ?></td>
        </tr>
        <tr>
            <th>Überfällige Meilensteine</th>
            <td><?= htmlspecialchars((string)$milestoneSummary['overdue']) ?></td>
        </tr>
    </tbody>
</table>

<h3>Kritische Projekte</h3>

<?php if (count($criticalProjects) === 0): ?>
    <p>Keine kritischen Projekte nach den aktuellen Kriterien gefunden.</p>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Projekt</th>
                <th>Projektstatus</th>
                <th>Statusampel</th>
                <th>EIGENE AMPEL</th> 
                <th>Fortschritt-Abweichung</th>
                <th>Überfällige Meilensteine</th>
                <th>Gesamtrisiko</th>
                <th>Gründe</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($criticalProjects as $project): ?>
                <tr>
                    <td>
                        <?= htmlspecialchars((string)($project['number'] ?? '-')) ?>
                        -
                        <?= htmlspecialchars((string)($project['name'] ?? '-')) ?>
                    </td>
                    <td><?= htmlspecialchars((string)($project['statusLabel'] ?? '-')) ?></td>
                    <td><?= htmlspecialchars((string)($project['gesamtstatus'] ?? 'Keine Angabe')) ?></td>

                    <?php
                        $eigeneFarbe = $project['eigeneAmpel'] ?? 'GRAU';
                        $cssKlasse = ($eigeneFarbe === 'GRAU') ? 'status-unknown' : getTrafficLightClass($eigeneFarbe);
                    ?>
                    <td class="<?= $cssKlasse ?>">
                        <strong>
                            <?= $eigeneFarbe === 'Rot' ? '🔴 ' : '' ?>
                            <?= $eigeneFarbe === 'Gelb' ? '🟡 ' : '' ?>
                            <?= $eigeneFarbe === 'Grün' ? '🟢 ' : '' ?>
                            <?= htmlspecialchars($eigeneFarbe) ?>
                        </strong>
                        <span style="display: block; font-size: 10px; color: #555; font-weight: normal; margin-top: 3px;">
                            <?= htmlspecialchars((string)($project['eigeneAmpelBegruendung'] ?? '')) ?>
                        </span>
                    </td>

                    <td><?= htmlspecialchars((string)($project['abweichungFortschritt'] ?? 0)) ?> %</td>
                    <td><?= htmlspecialchars((string)($project['milestonesOverdue'] ?? 0)) ?></td>
                    <td><?= htmlspecialchars((string)($project['riskAssessment'] ?: 'Keine Angabe')) ?></td>
                    <td>
                        <?php if (!empty($project['criticalReasons'])): ?>
                            <ul>
                                <?php foreach ($project['criticalReasons'] as $reason): ?>
                                    <li><?= htmlspecialchars($reason) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
    <h3>Projekte dieses Portfolios</h3>

    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Projektnummer</th>
                <th>Projektname</th>
                <th>Projektstatus</th>
                <th>Plan-Aufwand</th>
                <th>Ist-Aufwand</th>
                <th>Abweichung</th>
                <th>Plan-Fortschritt</th>
                <th>Ist-Fortschritt</th>
                <th>Fortschritt-Abweichung</th>
                <th>Prognose Mehraufwand</th>
                <th>Prognose (Aufwand/Zeitplan)</th>
                <th>Meilensteine gesamt</th>
                <th>Meilensteine offen</th>
                <th>Meilensteine erledigt</th>
                <th>Meilensteine überfällig</th>
                <th>BlueAnt-Ampel</th>
                <th>Eigene Ampel</th>
                <th class="col-status-text">Status-Text</th>
                <th>Kritisch?</th>
                <th>Kritische Gründe</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($portfolioProjectAnalyses as $project): ?>
              <tr>
                  <td><?= htmlspecialchars((string)($project['id'] ?? '-')) ?></td>
                  <td><?= htmlspecialchars((string)($project['number'] ?? '-')) ?></td>
                  <td><?= htmlspecialchars((string)($project['name'] ?? '-')) ?></td>
                  <td><?= htmlspecialchars((string)($project['statusLabel'] ?? '-')) ?></td>
                  <td><?= htmlspecialchars((string)($project['planAufwand'] ?? 0)) ?></td>
                  <td><?= htmlspecialchars((string)($project['istAufwand'] ?? 0)) ?></td>
                  <td><?= htmlspecialchars((string)($project['abweichungAufwand'] ?? 0)) ?></td>
                  <td><?= htmlspecialchars((string)($project['planFortschritt'] ?? 0)) ?> %</td>
                  <td><?= htmlspecialchars((string)($project['istFortschritt'] ?? 0)) ?> %</td>
                  <td><?= htmlspecialchars((string)($project['abweichungFortschritt'] ?? 0)) ?> %</td>
                  <td><?= htmlspecialchars((string)($project['prognoseMehraufwand'] ?? 0)) ?></td>
                  <td><?= htmlspecialchars((string)($project['forecastText'] ?? '-')) ?></td>
                  <td><?= htmlspecialchars((string)($project['milestonesTotal'] ?? 0)) ?></td>
                  <td><?= htmlspecialchars((string)($project['milestonesOpen'] ?? 0)) ?></td>
                  <td><?= htmlspecialchars((string)($project['milestonesCompleted'] ?? 0)) ?></td>
                  <td><?= htmlspecialchars((string)($project['milestonesOverdue'] ?? 0)) ?></td>
                  
                  <td>
                      <?php if (!empty($project['hasBlueAntTrafficLight'])): ?>
                          <strong><?= htmlspecialchars((string)($project['blueAntTrafficLight'] ?? 'Keine Angabe')) ?></strong>
                          <?php if (($project['blueAntTrafficLightReason'] ?? '') !== ''): ?>
                              <span style="display: block; font-size: 10px; color: #555; font-weight: normal; margin-top: 3px;">
                                  <?= htmlspecialchars((string)$project['blueAntTrafficLightReason']) ?>
                              </span>
                          <?php endif; ?>
                      <?php else: ?>
                          <span class="status-unknown">Nicht verfügbar</span>
                      <?php endif; ?>
                  </td>
                  
                  <?php 
                      $eigeneFarbe = $project['eigeneAmpel'] ?? 'GRAU'; 
                      $cssKlasse = ($eigeneFarbe === 'GRAU') ? 'status-unknown' : getTrafficLightClass($eigeneFarbe);
                  ?>
                  <td class="<?= $cssKlasse ?>">
                      <strong>
                          <?= $eigeneFarbe === 'Rot' ? '🔴 ' : '' ?>
                          <?= $eigeneFarbe === 'Gelb' ? '🟡 ' : '' ?>
                          <?= $eigeneFarbe === 'Grün' ? '🟢 ' : '' ?>
                          <?= htmlspecialchars($eigeneFarbe) ?>
                      </strong>
                      <span style="display: block; font-size: 10px; color: #555; font-weight: normal; margin-top: 3px;">
                          <?= htmlspecialchars((string)($project['eigeneAmpelBegruendung'] ?? '')) ?>
                      </span>
                  </td>

                  <td class="col-status-text"><?= nl2br(htmlspecialchars((string)($project['statusMemo'] ?: 'Keine Angabe'))) ?></td>
                  <td><?= !empty($project['isCritical']) ? 'Ja' : 'Nein' ?></td>
                <td>
                    <?php if (!empty($project['criticalReasons'])): ?>
                     <ul>
                        <?php foreach ($project['criticalReasons'] as $reason): ?>
                         <li><?= htmlspecialchars($reason) ?></li>
                        <?php endforeach; ?>
                     </ul>
                     <?php else: ?>
                        -
                     <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

<?php endif; ?>

<?php elseif ($selectedPortfolioId): ?>

    <p class="message message-danger">Das ausgewählte Portfolio wurde nicht gefunden.</p>

<?php else: ?>

    <p class="message message-info">Bitte wähle ein Portfolio aus.</p>

<?php endif; ?>

</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const selectAllBtn = document.getElementById('selectAllBtn');
    const selectNoneBtn = document.getElementById('selectNoneBtn');
    
    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', function(e) {
            e.preventDefault();
            document.querySelectorAll('input[name="projectIds[]"]').forEach(checkbox => {
                checkbox.checked = true;
            });
        });
    }
    
    if (selectNoneBtn) {
        selectNoneBtn.addEventListener('click', function(e) {
            e.preventDefault();
            document.querySelectorAll('input[name="projectIds[]"]').forEach(checkbox => {
                checkbox.checked = false;
            });
        });
    }
});
</script>

</body>
</html>
