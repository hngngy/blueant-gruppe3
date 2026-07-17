<?php

require_once __DIR__ . '/../src/BlueAntClient.php';
require_once __DIR__ . '/../src/AiJsonClient.php';
require_once __DIR__ . '/../src/GeminiClient.php';
require_once __DIR__ . '/../src/PortfolioAiAnalyzer.php';
require_once __DIR__ . '/../src/ForecastAnalyzer.php';
require_once __DIR__ . '/../src/PortfolioAnalysis.php';

$config = require __DIR__ . '/../config/config.php';
$reportDateInput = trim((string)($_REQUEST['reportDate'] ?? date('Y-m-d')));
$reportDateError = null;
try {
    $reportDate = DateTimeImmutable::createFromFormat('!Y-m-d', $reportDateInput);
    if (!$reportDate || $reportDate->format('Y-m-d') !== $reportDateInput) {
        throw new InvalidArgumentException('Ungültiger Stichtag');
    }
} catch (Throwable $e) {
    $reportDate = new DateTimeImmutable('today');
    $reportDateInput = $reportDate->format('Y-m-d');
    $reportDateError = 'Der eingegebene Stichtag war ungültig und wurde auf heute gesetzt.';
}
$error = null;

try {
    $client = new BlueAntClient($config['blueant_base_url'], $config['blueant_token']);
    $portfolios = $client->getPortfolios();
    $allProjects = $client->getProjects();
    $projectStatuses = $client->getProjectStatuses();
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
        $statusMap[$statusId] = (string)($status['text'] ?? $status['name'] ?? 'Unbekannter Status');
    }
}

$portfolioIdsRaw = $_REQUEST['portfolioIds'] ?? ($_REQUEST['portfolioId'] ?? []);
$portfolioIdsRaw = is_array($portfolioIdsRaw) ? $portfolioIdsRaw : [$portfolioIdsRaw];
$selectedPortfolioIds = array_values(array_unique(array_filter(array_map('intval', $portfolioIdsRaw))));
$analysisStarted = (string)($_REQUEST['analysisStarted'] ?? '') === '1';
$runAi = (string)($_REQUEST['runAi'] ?? '') === '1';
$exportFormat = strtolower((string)($_GET['export'] ?? ''));
$exportFormat = in_array($exportFormat, ['csv', 'txt'], true) ? $exportFormat : null;
$selectedProjectIdsRaw = $_REQUEST['projectIds'] ?? [];
$selectedProjectIdsRaw = is_array($selectedProjectIdsRaw) ? $selectedProjectIdsRaw : [$selectedProjectIdsRaw];
$defaultAiPrompt = (string)($config['ai']['prompts']['portfolio_management_summary'] ?? '');
$customAiPrompt = trim((string)($_REQUEST['aiPrompt'] ?? $defaultAiPrompt));
$activeAiPrompt = $customAiPrompt !== '' ? $customAiPrompt : $defaultAiPrompt;

$selectedPortfolios = [];
foreach ($portfolios as $portfolio) {
    if (in_array((int)($portfolio['id'] ?? 0), $selectedPortfolioIds, true)) {
        $selectedPortfolios[] = $portfolio;
    }
}

$projectPortfolioNames = [];
$availableProjectIdMap = [];
foreach ($selectedPortfolios as $portfolio) {
    foreach ($portfolio['projectIds'] ?? [] as $projectId) {
        $id = (int)$projectId;
        if ($id <= 0) {
            continue;
        }
        $availableProjectIdMap[$id] = true;
        $projectPortfolioNames[$id][] = (string)($portfolio['name'] ?? '-');
    }
}

$portfolioProjects = [];
foreach ($allProjects as $project) {
    $id = (int)($project['id'] ?? 0);
    if (isset($availableProjectIdMap[$id])) {
        $project['portfolioNames'] = array_values(array_unique($projectPortfolioNames[$id] ?? []));
        $portfolioProjects[] = $project;
    }
}

$availableProjectIds = array_map(static fn (array $project): int => (int)($project['id'] ?? 0), $portfolioProjects);
$selectedProjectIds = $analysisStarted
    ? array_values(array_unique(array_intersect(array_map('intval', $selectedProjectIdsRaw), $availableProjectIds)))
    : [];
$selectedPortfolioProjects = array_values(array_filter(
    $portfolioProjects,
    static fn (array $project): bool => in_array((int)($project['id'] ?? 0), $selectedProjectIds, true)
));

$portfolioProjectAnalyses = [];
if ($analysisStarted && $selectedPortfolioProjects !== []) {
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

        $forecast = PortfolioAnalysis::forecast($project, $reportDate);
        $project['forecast'] = $forecast;
        $project['forecastText'] = PortfolioAnalysis::formatForecast($forecast);

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
$trafficLightCounts = ['Rot' => 0, 'Gelb' => 0, 'Grün' => 0, 'Keine Angabe' => 0];
$milestoneSummary = ['total' => 0, 'open' => 0, 'completed' => 0, 'overdue' => 0];
foreach ($portfolioProjectAnalyses as $project) {
    $projectStatusCounts[(string)$project['statusLabel']] = ($projectStatusCounts[(string)$project['statusLabel']] ?? 0) + 1;
    $light = (string)($project['gesamtstatus'] ?? 'Keine Angabe');
    $trafficLightCounts[$light] = ($trafficLightCounts[$light] ?? 0) + 1;
    foreach ($milestoneSummary as $key => $value) {
        $milestoneSummary[$key] += (int)($project['milestones' . ucfirst($key)] ?? 0);
    }
}
ksort($projectStatusCounts);
$criticalProjects = array_values(array_filter($portfolioProjectAnalyses, static fn (array $project): bool => !empty($project['isCritical'])));
$selectedProjectCount = count($portfolioProjectAnalyses);
$criticalProjectCount = count($criticalProjects);
$maxEffortValue = 0.0;
$maxOverdueMilestones = 0;
$maxForecastDelay = 0;
$maxForecastEffortDeviation = 0.0;
foreach ($portfolioProjectAnalyses as $project) {
    $maxEffortValue = max($maxEffortValue, (float)($project['planAufwand'] ?? 0), (float)($project['istAufwand'] ?? 0));
    $maxOverdueMilestones = max($maxOverdueMilestones, (int)($project['milestonesOverdue'] ?? 0));
    $maxForecastDelay = max($maxForecastDelay, max(0, (int)($project['forecast']['delayDays'] ?? 0)));
    $maxForecastEffortDeviation = max(
        $maxForecastEffortDeviation,
        max(0.0, (float)($project['forecast']['estimatedEffortDeviation'] ?? 0))
    );
}

if ($analysisStarted && $selectedPortfolios !== [] && $portfolioProjectAnalyses !== [] && $exportFormat) {
    exportPortfolioReport(
        $exportFormat,
        $selectedPortfolios,
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
if ($analysisStarted && $runAi && $selectedPortfolios !== [] && $portfolioProjectAnalyses !== []) {
    if (!($config['ai']['enabled'] ?? false)) {
        $aiError = 'Die KI-Auswertung ist deaktiviert. Die regelbasierten Zusammenfassungen bleiben verfügbar.';
    } else {
        try {
            $aiClient = new GeminiClient(
                $config['ai']['base_url'],
                $config['ai']['api_key'],
                $config['ai']['model'],
                (float)$config['ai']['temperature'],
                (int)$config['ai']['timeout_seconds']
            );
            $prompts = $config['ai']['prompts'];
            $prompts['portfolio_management_summary'] = $activeAiPrompt;
            $aiAnalyzer = new PortfolioAiAnalyzer($aiClient, $prompts);
            $aiReport = $aiAnalyzer->createManagementSummary(
                ['reportDate' => $reportDateInput, 'items' => $selectedPortfolios],
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
}

function getKpiValue(array $kpis, string $id, string $period = 'TOTAL'): float
{
    foreach ($kpis as $kpi) {
        if (($kpi['id'] ?? '') === $id && ($kpi['period'] ?? '') === $period) {
            return (float)($kpi['value'] ?? 0);
        }
    }
    return 0.0;
}

function translateTrafficLight($value): string
{
    return match ((string)$value) {
        '1' => 'Rot', '2' => 'Gelb', '3' => 'Grün', default => 'Keine Angabe',
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

function renderProjectHiddenInputs(array $projectIds): void
{
    echo '<input type="hidden" name="analysisStarted" value="1">';
    foreach ($projectIds as $projectId) {
        echo '<input type="hidden" name="projectIds[]" value="' . htmlspecialchars((string)$projectId) . '">';
    }
}

function renderReportDateHiddenInput(DateTimeImmutable $reportDate): void
{
    echo '<input type="hidden" name="reportDate" value="' . htmlspecialchars($reportDate->format('Y-m-d')) . '">';
}

function renderList(array $items): void
{
    if ($items === []) {
        echo '<p>Keine Angabe.</p>';
        return;
    }
    echo '<ul>';
    foreach ($items as $item) {
        echo '<li>' . htmlspecialchars((string)$item) . '</li>';
    }
    echo '</ul>';
}

function renderTrafficLight(string $status): void
{
    $normalized = in_array($status, ['Rot', 'Gelb', 'Grün'], true) ? $status : 'Keine Angabe';
    $class = match ($normalized) {
        'Rot' => 'red',
        'Gelb' => 'yellow',
        'Grün' => 'green',
        default => 'unknown',
    };

    echo '<span class="traffic-badge traffic-badge-' . $class . '">';
    echo '<span class="traffic-badge-dot" aria-hidden="true"></span>';
    echo '<span>' . htmlspecialchars($normalized) . '</span>';
    echo '</span>';
}

function exportPortfolioReport(
    string $format,
    array $portfolios,
    DateTimeImmutable $reportDate,
    array $projects,
    array $trafficLightCounts,
    array $projectStatusCounts,
    array $milestoneSummary,
    array $criticalProjects
): void {
    $fileName = 'portfolio-dashboard-' . $reportDate->format('Y-m-d');
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $fileName . '.csv"');
        $output = fopen('php://output', 'wb');
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, ['Portfolios', implode(' | ', array_column($portfolios, 'name'))], ';');
        fputcsv($output, ['Stichtag', $reportDate->format('Y-m-d')], ';');
        fputcsv($output, [], ';');
        fputcsv($output, [
            'Projekt-ID', 'Projektnummer', 'Projektname', 'Portfolios', 'Projektstatus', 'Statusampel',
            'Plan-Aufwand', 'Ist-Aufwand', 'Aufwand-Abweichung', 'Plan-Fortschritt', 'Ist-Fortschritt',
            'Fortschritt-Abweichung', 'Blue-Ant-Prognose Mehraufwand', 'Eigene Prognose',
            'Meilensteine gesamt', 'Meilensteine offen', 'Meilensteine erledigt', 'Meilensteine überfällig',
            'Gegenstand', 'Gegenstand-Zusammenfassung', 'Statustext', 'Status-Zusammenfassung',
            'Kritisch', 'Kritische Gründe',
        ], ';');
        foreach ($projects as $project) {
            fputcsv($output, [
                $project['id'] ?? '-', $project['number'] ?? '-', $project['name'] ?? '-',
                implode(' | ', $project['portfolioNames'] ?? []), $project['statusLabel'] ?? '-',
                $project['gesamtstatus'] ?? 'Keine Angabe', $project['planAufwand'] ?? 0,
                $project['istAufwand'] ?? 0, $project['abweichungAufwand'] ?? 0,
                $project['planFortschritt'] ?? 0, $project['istFortschritt'] ?? 0,
                $project['abweichungFortschritt'] ?? 0, $project['prognoseMehraufwand'] ?? 0,
                $project['forecastText'] ?? '', $project['milestonesTotal'] ?? 0,
                $project['milestonesOpen'] ?? 0, $project['milestonesCompleted'] ?? 0,
                $project['milestonesOverdue'] ?? 0, $project['subjectMemo'] ?? '',
                $project['subjectSummary'] ?? '', $project['statusMemo'] ?? '',
                $project['statusSummary'] ?? '', !empty($project['isCritical']) ? 'Ja' : 'Nein',
                implode(' | ', $project['criticalReasons'] ?? []),
            ], ';');
        }
        fclose($output);
        exit;
    }

    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '.txt"');
    echo "Portfolio-Auswertung\n====================\n";
    echo 'Portfolios: ' . implode(' | ', array_column($portfolios, 'name')) . "\n";
    echo 'Stichtag: ' . $reportDate->format('Y-m-d') . "\n\n";
    echo "Statusampeln\n";
    foreach ($trafficLightCounts as $label => $count) { echo '- ' . $label . ': ' . $count . "\n"; }
    echo "\nProjektstatus\n";
    foreach ($projectStatusCounts as $label => $count) { echo '- ' . $label . ': ' . $count . "\n"; }
    echo "\nMeilensteine\n";
    foreach ($milestoneSummary as $label => $count) { echo '- ' . $label . ': ' . $count . "\n"; }
    echo "\nKritische Projekte: " . count($criticalProjects) . "\n";
    foreach ($projects as $project) {
        echo "\n" . ($project['number'] ?? '-') . ' - ' . ($project['name'] ?? '-') . "\n";
        echo 'Gegenstand: ' . ($project['subjectSummary'] ?? 'Keine Angabe.') . "\n";
        echo 'Status: ' . ($project['statusSummary'] ?? 'Keine Angabe.') . "\n";
        echo 'Aufwand Plan/Ist: ' . ($project['planAufwand'] ?? 0) . ' / ' . ($project['istAufwand'] ?? 0) . "\n";
        echo 'Fortschritt Plan/Ist: ' . ($project['planFortschritt'] ?? 0) . '% / ' . ($project['istFortschritt'] ?? 0) . "%\n";
        echo 'Prognose: ' . ($project['forecastText'] ?? 'Keine belastbare Prognose möglich.') . "\n";
        echo 'Meilensteine gesamt/offen/überfällig: ' . ($project['milestonesTotal'] ?? 0) . ' / ' . ($project['milestonesOpen'] ?? 0) . ' / ' . ($project['milestonesOverdue'] ?? 0) . "\n";
        echo 'Kritisch: ' . (!empty($project['isCritical']) ? 'Ja - ' . implode(' | ', $project['criticalReasons'] ?? []) : 'Nein') . "\n";
    }
    exit;
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Portfolio-Dashboard</title>
    <link rel="stylesheet" href="styles.css?v=<?= rawurlencode((string)(filemtime(__DIR__ . '/styles.css') ?: '1')) ?>">
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
        </div>
        <div class="field compact-field">
            <label for="reportDate">Stichtag</label>
            <input type="date" id="reportDate" name="reportDate" value="<?= htmlspecialchars($reportDateInput) ?>">
        </div>
        <button type="submit">Projekte anzeigen</button>
    </form>

    <?php if ($reportDateError): ?><p class="message message-warning"><?= htmlspecialchars($reportDateError) ?></p><?php endif; ?>

    <?php if ($selectedPortfolios !== []): ?>
        <section class="card">
            <h2>Ausgewählte Portfolios</h2>
            <p><?= htmlspecialchars(implode(', ', array_column($selectedPortfolios, 'name'))) ?></p>
            <div class="portfolio-meta">
                <div class="meta-item"><span class="meta-label">Portfolios</span><span class="meta-value"><?= count($selectedPortfolios) ?></span></div>
                <div class="meta-item"><span class="meta-label">Stichtag</span><span class="meta-value"><?= htmlspecialchars($reportDateInput) ?></span></div>
                <div class="meta-item"><span class="meta-label">Projekte</span><span class="meta-value" id="selectedProjectCounter"><?= count($selectedProjectIds) ?> / <?= count($portfolioProjects) ?> ausgewählt</span></div>
            </div>
        </section>

        <section class="project-card">
            <h3>Projekte auswählen</h3>
            <form method="get" id="projectSelectionForm">
                <?php renderPortfolioHiddenInputs($selectedPortfolioIds); ?>
                <?php renderReportDateHiddenInput($reportDate); ?>
                <input type="hidden" name="analysisStarted" value="1">
                <div class="actions-inline bulk-actions">
                    <button type="button" id="selectAllBtn" class="button-secondary">Alle auswählen</button>
                    <button type="button" id="selectNoneBtn" class="button-secondary">Alle abwählen</button>
                </div>
                <?php if ($portfolioProjects === []): ?>
                    <p class="message message-info">Die ausgewählten Portfolios enthalten keine Projekte.</p>
                <?php else: ?>
                    <div class="project-list">
                        <?php foreach ($portfolioProjects as $project): $projectId = (int)($project['id'] ?? 0); ?>
                            <label class="project-option">
                                <input type="checkbox" name="projectIds[]" value="<?= $projectId ?>" <?= in_array($projectId, $selectedProjectIds, true) ? 'checked' : '' ?>>
                                <span>
                                    <span class="project-title"><?= htmlspecialchars((string)($project['name'] ?? 'Unbenanntes Projekt')) ?></span>
                                    <span class="project-number"><?= htmlspecialchars((string)($project['number'] ?? '-')) ?> · <?= htmlspecialchars(implode(', ', $project['portfolioNames'] ?? [])) ?></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit">Auswertung starten</button>
                <?php endif; ?>
            </form>
        </section>

        <?php if ($analysisStarted && $selectedProjectIds === []): ?>
            <p class="message message-warning">Bitte wähle mindestens ein Projekt aus.</p>
        <?php endif; ?>

        <?php if ($analysisStarted && $portfolioProjectAnalyses !== []): ?>
            <section class="actions-card actions-inline">
                <form method="get"><?php renderPortfolioHiddenInputs($selectedPortfolioIds); renderReportDateHiddenInput($reportDate); renderProjectHiddenInputs($selectedProjectIds); ?><button name="export" value="csv" class="button-secondary">CSV exportieren</button></form>
                <form method="get"><?php renderPortfolioHiddenInputs($selectedPortfolioIds); renderReportDateHiddenInput($reportDate); renderProjectHiddenInputs($selectedProjectIds); ?><button name="export" value="txt" class="button-secondary">Text exportieren</button></form>
            </section>

            <section class="summary-grid">
                <article class="summary-card"><span>Projekte</span><strong><?= count($portfolioProjectAnalyses) ?></strong></article>
                <article class="summary-card"><span>Kritisch</span><strong><?= count($criticalProjects) ?></strong></article>
                <article class="summary-card"><span>Meilensteine überfällig</span><strong><?= $milestoneSummary['overdue'] ?></strong></article>
                <article class="summary-card"><span>Rote Ampeln</span><strong><?= $trafficLightCounts['Rot'] ?></strong></article>
            </section>

            <section class="visualization-section">
                <h3>Visualisierte Management-Auswertung</h3>
                <div class="chart-grid">
                    <article class="chart-card">
                        <h4>Statusampel-Verteilung</h4>
                        <div class="traffic-grid">
                            <?php foreach ($trafficLightCounts as $status => $count): ?>
                                <div class="traffic-tile traffic-<?= htmlspecialchars(strtolower(str_replace(' ', '-', $status))) ?>">
                                    <span class="traffic-label"><?= htmlspecialchars($status) ?></span>
                                    <strong><?= $count ?></strong>
                                    <span><?= htmlspecialchars(formatDecimal(percentage((float)$count, (float)$selectedProjectCount))) ?> %</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <p class="chart-note">Basis: <?= $selectedProjectCount ?> ausgewertete Projekte.</p>
                    </article>

                    <article class="chart-card">
                        <h4>Meilenstein-Verteilung</h4>
                        <?php $milestoneTotalForChart = max(1, (int)$milestoneSummary['total']); ?>
                        <?php $openNotOverdue = max(0, (int)$milestoneSummary['open'] - (int)$milestoneSummary['overdue']); ?>
                        <div class="stacked-bar" aria-label="Meilenstein-Verteilung">
                            <span class="segment segment-completed" style="width: <?= barWidth((float)$milestoneSummary['completed'], (float)$milestoneTotalForChart) ?>%"></span>
                            <span class="segment segment-open" style="width: <?= barWidth((float)$openNotOverdue, (float)$milestoneTotalForChart) ?>%"></span>
                            <span class="segment segment-overdue" style="width: <?= barWidth((float)$milestoneSummary['overdue'], (float)$milestoneTotalForChart) ?>%"></span>
                        </div>
                        <div class="legend">
                            <span><i class="legend-dot segment-completed"></i>Erledigt: <?= $milestoneSummary['completed'] ?></span>
                            <span><i class="legend-dot segment-open"></i>Offen: <?= $openNotOverdue ?></span>
                            <span><i class="legend-dot segment-overdue"></i>Überfällig: <?= $milestoneSummary['overdue'] ?></span>
                        </div>
                        <p class="chart-note">Gesamt: <?= $milestoneSummary['total'] ?> Meilensteine.</p>
                    </article>

                    <article class="chart-card">
                        <h4>Kritische Projekte</h4>
                        <div class="stacked-bar" aria-label="Anteil kritischer Projekte">
                            <span class="segment segment-critical" style="width: <?= barWidth((float)$criticalProjectCount, (float)max(1, $selectedProjectCount)) ?>%"></span>
                            <span class="segment segment-normal" style="width: <?= barWidth((float)($selectedProjectCount - $criticalProjectCount), (float)max(1, $selectedProjectCount)) ?>%"></span>
                        </div>
                        <div class="legend">
                            <span><i class="legend-dot segment-critical"></i>Kritisch: <?= $criticalProjectCount ?></span>
                            <span><i class="legend-dot segment-normal"></i>Nicht kritisch: <?= $selectedProjectCount - $criticalProjectCount ?></span>
                        </div>
                        <p class="chart-note"><?= htmlspecialchars(formatDecimal(percentage((float)$criticalProjectCount, (float)$selectedProjectCount))) ?> % sind kritisch.</p>
                    </article>
                </div>

                <article class="chart-card">
                    <h4>Plan-/Ist-Fortschritt je Projekt</h4>
                    <div class="bar-list">
                        <?php foreach ($portfolioProjectAnalyses as $project): ?>
                            <?php $planProgress = max(0.0, min(100.0, (float)$project['planFortschritt'])); ?>
                            <?php $actualProgress = max(0.0, min(100.0, (float)$project['istFortschritt'])); ?>
                            <div class="bar-row">
                                <div class="bar-label"><strong><?= htmlspecialchars((string)$project['number']) ?></strong><span><?= htmlspecialchars((string)$project['name']) ?></span></div>
                                <div class="bar-pair">
                                    <div class="bar-track"><span class="bar-fill bar-plan" style="width: <?= number_format($planProgress, 1, '.', '') ?>%"></span></div><span class="bar-value">Plan <?= htmlspecialchars(formatDecimal((float)$project['planFortschritt'])) ?> %</span>
                                    <div class="bar-track"><span class="bar-fill bar-actual" style="width: <?= number_format($actualProgress, 1, '.', '') ?>%"></span></div><span class="bar-value">Ist <?= htmlspecialchars(formatDecimal((float)$project['istFortschritt'])) ?> %</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </article>

                <div class="chart-grid chart-grid-wide visualization-spacing">
                    <article class="chart-card">
                        <h4>Plan-/Ist-Aufwand je Projekt</h4>
                        <div class="bar-list">
                            <?php foreach ($portfolioProjectAnalyses as $project): ?>
                                <div class="bar-row">
                                    <div class="bar-label"><strong><?= htmlspecialchars((string)$project['number']) ?></strong><span><?= htmlspecialchars((string)$project['name']) ?></span></div>
                                    <div class="bar-pair">
                                        <div class="bar-track"><span class="bar-fill bar-plan" style="width: <?= barWidth((float)$project['planAufwand'], $maxEffortValue) ?>%"></span></div><span class="bar-value">Plan <?= htmlspecialchars(formatDecimal((float)$project['planAufwand'])) ?></span>
                                        <div class="bar-track"><span class="bar-fill bar-actual" style="width: <?= barWidth((float)$project['istAufwand'], $maxEffortValue) ?>%"></span></div><span class="bar-value">Ist <?= htmlspecialchars(formatDecimal((float)$project['istAufwand'])) ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </article>

                    <article class="chart-card">
                        <h4>Überfällige Meilensteine je Projekt</h4>
                        <div class="bar-list">
                            <?php foreach ($portfolioProjectAnalyses as $project): $overdue = (int)$project['milestonesOverdue']; ?>
                                <div class="bar-row bar-row-compact">
                                    <div class="bar-label"><strong><?= htmlspecialchars((string)$project['number']) ?></strong><span><?= htmlspecialchars((string)$project['name']) ?></span></div>
                                    <div class="single-bar"><div class="bar-track"><span class="bar-fill bar-risk" style="width: <?= barWidth((float)$overdue, (float)$maxOverdueMilestones) ?>%"></span></div><span class="bar-value"><?= $overdue ?></span></div>
                                </div>
                            <?php endforeach; ?>
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
    <details class="collapsible-table">
        <summary>Projekte dieses Portfolios</summary>

<style>
    /* 1. Wir zwingen die Tabelle dazu, die Spaltenbreiten strikt zu respektieren */
    table {
        table-layout: fixed !important;
        width: 100% !important;
        min-width: 1200px !important; /* Erhöhe diesen Wert, falls es immer noch quetscht */
    }

    /* 2. Jetzt greifen die Breiten für die Status-Zellen garantiert */
    th.col-status-text, 
    td.col-status-text {
        width: 360px !important;
        min-width: 360px !important;
        max-width: 420px !important;
        white-space: normal !important;
        word-break: break-word !important;
    }
</style>

<table style="table-layout: fixed !important; width: 100%;">
    <colgroup>
        <col span="18">
        <col class="col-status-text" width="520">
        <col span="2">
    </colgroup>
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
            <th style="width: 520px !important; min-width: 520px !important; max-width: 520px !important;">Status-Text</th>
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

              <!-- HIER: Deine TD-Zelle hat die Klasse bereits, das passt! -->
<!-- Ersetze dein altes TD mit diesem hier: -->
            <td class="col-status-text" style="width: 520px !important; min-width: 520px !important; max-width: 520px !important; white-space: normal !important; word-break: break-word !important; overflow-wrap: anywhere;">
            <?= nl2br(htmlspecialchars((string)($project['statusMemo'] ?: 'Keine Angabe'))) ?>
            </td>     
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

    const setPortfolioMenuOpen = (open) => {
        if (!portfolioMenu || !portfolioTrigger) return;
        portfolioMenu.hidden = !open;
        portfolioTrigger.setAttribute('aria-expanded', open ? 'true' : 'false');
    };

    portfolioTrigger?.addEventListener('click', () => setPortfolioMenuOpen(portfolioMenu?.hidden ?? true));
    document.getElementById('selectAllPortfoliosBtn')?.addEventListener('click', () => {
        portfolioBoxes.forEach(box => box.checked = true);
        updatePortfolioText();
    });
    document.getElementById('selectNoPortfoliosBtn')?.addEventListener('click', () => {
        portfolioBoxes.forEach(box => box.checked = false);
        updatePortfolioText();
    });
    portfolioBoxes.forEach(box => box.addEventListener('change', updatePortfolioText));
    document.addEventListener('click', event => {
        if (portfolioControl && !portfolioControl.contains(event.target)) setPortfolioMenuOpen(false);
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && portfolioMenu && !portfolioMenu.hidden) {
            setPortfolioMenuOpen(false);
            portfolioTrigger?.focus();
        }
    });
    updatePortfolioText();

    const form = document.getElementById('projectSelectionForm');
    if (form) {
        const boxes = Array.from(form.querySelectorAll('input[name="projectIds[]"]'));
        const counter = document.getElementById('selectedProjectCounter');
        const updateCounter = () => {
            const selected = boxes.filter(box => box.checked).length;
            if (counter) counter.textContent = selected + ' / ' + boxes.length + ' ausgewählt';
        };
        document.getElementById('selectAllBtn')?.addEventListener('click', () => { boxes.forEach(box => box.checked = true); updateCounter(); });
        document.getElementById('selectNoneBtn')?.addEventListener('click', () => { boxes.forEach(box => box.checked = false); updateCounter(); });
        boxes.forEach(box => box.addEventListener('change', updateCounter));
        updateCounter();
    }

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
