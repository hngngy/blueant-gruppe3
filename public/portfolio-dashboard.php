<?php

require_once __DIR__ . '/../src/BlueAntClient.php';
require_once __DIR__ . '/../src/AiJsonClient.php';
require_once __DIR__ . '/../src/GeminiClient.php';
require_once __DIR__ . '/../src/PortfolioAiAnalyzer.php';
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
        $details = $client->getProject($projectId);
        $project = array_merge($project, $details);
        $project['portfolioNames'] = array_values(array_unique($projectPortfolioNames[$projectId] ?? []));

        $project['planAufwand'] = getKpiValue($kpis, 'WorkTotalPlan');
        $project['istAufwand'] = getKpiValue($kpis, 'WorkTotalActual');
        $project['abweichungAufwand'] = $project['istAufwand'] - $project['planAufwand'];
        $project['planFortschritt'] = getKpiValue($kpis, 'DevelopmentPlanProgress');
        $project['istFortschritt'] = getKpiValue($kpis, 'SubjectiveProgress');
        $project['abweichungFortschritt'] = $project['istFortschritt'] - $project['planFortschritt'];
        $project['prognoseMehraufwand'] = getKpiValue($kpis, 'PrognosisOvertime');

        $customFields = is_array($details['customFields'] ?? null)
            ? $details['customFields']
            : (is_array($project['customFields'] ?? null) ? $project['customFields'] : []);
        $trafficLightValue = $customFields[(string)$config['traffic_light_field_id']] ?? null;
        $project['gesamtstatus'] = translateTrafficLight($trafficLightValue);

        $statusId = (int)($details['statusId'] ?? $project['statusId'] ?? 0);
        $project['statusId'] = $statusId;
        $project['statusName'] = $statusMap[$statusId] ?? 'Unbekannter Status';
        $project['statusLabel'] = $statusId . ' - ' . $project['statusName'];
        $project['statusMemo'] = cleanMemo((string)($details['statusMemo'] ?? ''));
        $project['subjectMemo'] = cleanMemo((string)($details['subjectMemo'] ?? ''));
        $project['statusSummary'] = PortfolioAnalysis::summarizeText($project['statusMemo']);
        $project['subjectSummary'] = PortfolioAnalysis::summarizeText($project['subjectMemo']);

        $milestones = PortfolioAnalysis::analyzeMilestones($client->getPlanningEntries($projectId), $reportDate);
        foreach ($milestones as $key => $value) {
            $project['milestones' . ucfirst($key)] = $value;
        }

        $forecast = PortfolioAnalysis::forecast($project, $reportDate);
        $project['forecast'] = $forecast;
        $project['forecastText'] = PortfolioAnalysis::formatForecast($forecast);

        $critical = PortfolioAnalysis::analyzeCriticalProject(
            $project,
            (float)$config['critical_progress_deviation']
        );
        $project['isCritical'] = $critical['isCritical'];
        $project['criticalReasons'] = $critical['reasons'];
        $portfolioProjectAnalyses[] = $project;
    }
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

function cleanMemo(string $text): string
{
    return trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');
}

function percentage(float $value, float $total): float
{
    return $total <= 0 ? 0.0 : round(($value / $total) * 100, 1);
}

function barWidth(float $value, float $max): string
{
    return number_format(percentage(max(0.0, $value), $max), 1, '.', '');
}

function formatDecimal(float $value): string
{
    return number_format($value, 1, ',', '.');
}

function renderPortfolioHiddenInputs(array $portfolioIds): void
{
    foreach ($portfolioIds as $portfolioId) {
        echo '<input type="hidden" name="portfolioIds[]" value="' . htmlspecialchars((string)$portfolioId) . '">';
    }
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
        <p>Portfolios und Projekte auswählen, aktuellen Datenstand analysieren und als Management-Auswertung exportieren.</p>
    </header>

    <?php if ($error): ?><p class="message message-danger">Fehler: <?= htmlspecialchars($error) ?></p><?php endif; ?>

    <form method="get" class="control-card portfolio-selector">
        <div class="field portfolio-multiselect" id="portfolioMultiselect">
            <label id="portfolioDropdownLabel">Ein oder mehrere Portfolios auswählen</label>
            <button
                type="button"
                class="portfolio-dropdown-trigger"
                id="portfolioDropdownTrigger"
                aria-haspopup="true"
                aria-expanded="false"
                aria-labelledby="portfolioDropdownLabel portfolioDropdownText"
            >
                <span id="portfolioDropdownText">
                    <?= $selectedPortfolios === []
                        ? 'Portfolio auswählen'
                        : htmlspecialchars(count($selectedPortfolios) . ' Portfolio(s) ausgewählt') ?>
                </span>
                <span aria-hidden="true">▾</span>
            </button>
            <div class="portfolio-dropdown-menu" id="portfolioDropdownMenu" hidden>
                <div class="portfolio-dropdown-actions">
                    <button type="button" class="button-link" id="selectAllPortfoliosBtn">Alle auswählen</button>
                    <button type="button" class="button-link" id="selectNoPortfoliosBtn">Alle abwählen</button>
                </div>
                <div class="portfolio-dropdown-options" role="group" aria-labelledby="portfolioDropdownLabel">
                    <?php foreach ($portfolios as $portfolio): $portfolioId = (int)($portfolio['id'] ?? 0); ?>
                        <label class="portfolio-dropdown-option">
                            <input type="checkbox" name="portfolioIds[]" value="<?= $portfolioId ?>" <?= in_array($portfolioId, $selectedPortfolioIds, true) ? 'checked' : '' ?>>
                            <span><?= htmlspecialchars((string)($portfolio['name'] ?? 'Unbenanntes Portfolio')) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
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
                    </article>
                </div>

                <article class="chart-card visualization-spacing">
                    <h4>Prognostizierte Abweichungen</h4>
                    <p class="chart-note forecast-note">Lineare Schätzung; Projekte ohne belastbare Prognose werden mit „nicht verfügbar“ angezeigt.</p>
                    <div class="bar-list">
                        <?php foreach ($portfolioProjectAnalyses as $project): ?>
                            <?php $delay = max(0, (int)($project['forecast']['delayDays'] ?? 0)); ?>
                            <?php $effortDeviation = max(0.0, (float)($project['forecast']['estimatedEffortDeviation'] ?? 0)); ?>
                            <div class="bar-row forecast-row">
                                <div class="bar-label"><strong><?= htmlspecialchars((string)$project['number']) ?></strong><span><?= htmlspecialchars((string)$project['name']) ?></span></div>
                                <?php if (empty($project['forecast']['available'])): ?>
                                    <span class="bar-value">Nicht verfügbar</span>
                                <?php else: ?>
                                    <div class="forecast-pair">
                                        <div><span class="forecast-caption">Terminabweichung</span><div class="single-bar"><div class="bar-track"><span class="bar-fill bar-risk" style="width: <?= barWidth((float)$delay, (float)$maxForecastDelay) ?>%"></span></div><span class="bar-value"><?= $delay ?> Tage</span></div></div>
                                        <div><span class="forecast-caption">Aufwandsabweichung</span><div class="single-bar"><div class="bar-track"><span class="bar-fill bar-warning" style="width: <?= barWidth($effortDeviation, $maxForecastEffortDeviation) ?>%"></span></div><span class="bar-value"><?= htmlspecialchars(formatDecimal($effortDeviation)) ?></span></div></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </article>
            </section>

            <section class="card">
                <h3>Zusammenfassungen aus dem aktuellen Datenstand</h3>
                <p class="message message-info">Diese gekürzten Zusammenfassungen sind unabhängig von der KI verfügbar. Die KI-Auswertung darunter kann sie zusätzlich verdichten.</p>
                <div class="table-scroll"><table>
                    <thead><tr><th>Projekt</th><th>Gegenstand – Zusammenfassung</th><th>Status – Zusammenfassung</th><th>Prognose</th></tr></thead>
                    <tbody><?php foreach ($portfolioProjectAnalyses as $project): ?><tr>
                        <td><?= htmlspecialchars((string)($project['number'] ?? '-')) ?> – <?= htmlspecialchars((string)($project['name'] ?? '-')) ?></td>
                        <td><?= htmlspecialchars((string)$project['subjectSummary']) ?></td>
                        <td><?= htmlspecialchars((string)$project['statusSummary']) ?></td>
                        <td><?= htmlspecialchars((string)$project['forecastText']) ?></td>
                    </tr><?php endforeach; ?></tbody>
                </table></div>
            </section>

            <section class="ai-section">
                <h3>KI-Management-Auswertung</h3>
                <?php if (!$runAi): ?>
                    <form method="post" class="prompt-form">
                        <?php renderPortfolioHiddenInputs($selectedPortfolioIds); renderReportDateHiddenInput($reportDate); renderProjectHiddenInputs($selectedProjectIds); ?>
                        <input type="hidden" name="runAi" value="1">
                        <input type="hidden" id="defaultAiPromptValue" value="<?= htmlspecialchars($defaultAiPrompt, ENT_QUOTES) ?>">
                        <div class="prompt-editor-header">
                            <label for="aiPrompt">Verwendeter KI-Prompt</label>
                            <button type="button" class="button-secondary prompt-reset-button" id="resetAiPromptBtn">Standardprompt wiederherstellen</button>
                        </div>
                        <textarea id="aiPrompt" name="aiPrompt" rows="12"><?= htmlspecialchars($activeAiPrompt) ?></textarea>
                        <button type="submit" class="button-ai">KI-Auswertung starten</button>
                    </form>
                <?php elseif ($aiError): ?>
                    <p class="message message-danger">KI-Auswertung nicht verfügbar: <?= htmlspecialchars($aiError) ?></p>
                <?php elseif ($aiReport): ?>
                    <h4>Management Summary</h4><p><?= nl2br(htmlspecialchars((string)$aiReport['management_summary'])) ?></p>
                    <h4>Portfolio-Status</h4><p><?= nl2br(htmlspecialchars((string)$aiReport['portfolio_status'])) ?></p>
                    <h4>Gegenstandsüberblick</h4><p><?= nl2br(htmlspecialchars((string)$aiReport['subject_overview'])) ?></p>
                    <h4>Statusüberblick</h4><p><?= nl2br(htmlspecialchars((string)$aiReport['status_overview'])) ?></p>
                    <h4>Kritische Auffälligkeiten</h4><?php renderList($aiReport['critical_findings'] ?? []); ?>
                    <h4>Empfohlene Maßnahmen</h4><?php renderList($aiReport['recommended_actions'] ?? []); ?>
                    <div class="table-scroll"><table>
                        <thead><tr><th>Projekt</th><th>Gegenstand</th><th>Status</th><th>Prognose</th><th>Risiko</th></tr></thead>
                        <tbody><?php foreach ($aiReport['project_summaries'] ?? [] as $summary): ?><tr>
                            <td><?= htmlspecialchars((string)$summary['project_name']) ?></td>
                            <td><?= htmlspecialchars((string)$summary['subject_summary']) ?></td>
                            <td><?= htmlspecialchars((string)$summary['status_summary']) ?></td>
                            <td><?= htmlspecialchars((string)$summary['forecast_summary']) ?></td>
                            <td><?= htmlspecialchars((string)$summary['risk_note']) ?></td>
                        </tr><?php endforeach; ?></tbody>
                    </table></div>
                <?php endif; ?>
            </section>

            <section class="card">
                <h3>Statusampel- und Projektstatus-Überblick</h3>
                <p class="chart-note">Statusampel: <?= htmlspecialchars((string)$config['traffic_light_field_name']) ?>. Kritische Fortschrittsgrenze: <?= htmlspecialchars((string)abs((float)$config['critical_progress_deviation'])) ?> Prozentpunkte hinter Plan.</p>
                <div class="overview-columns">
                    <table><thead><tr><th>Statusampel</th><th>Projekte</th></tr></thead><tbody><?php foreach ($trafficLightCounts as $label => $count): ?><tr><td><?php renderTrafficLight((string)$label); ?></td><td><?= $count ?></td></tr><?php endforeach; ?></tbody></table>
                    <table><thead><tr><th>Projektstatus</th><th>Projekte</th></tr></thead><tbody><?php foreach ($projectStatusCounts as $label => $count): ?><tr><td><?= htmlspecialchars($label) ?></td><td><?= $count ?></td></tr><?php endforeach; ?></tbody></table>
                    <table><tbody><tr><th>Meilensteine gesamt</th><td><?= $milestoneSummary['total'] ?></td></tr><tr><th>Offen</th><td><?= $milestoneSummary['open'] ?></td></tr><tr><th>Erledigt</th><td><?= $milestoneSummary['completed'] ?></td></tr><tr><th>Überfällig</th><td><?= $milestoneSummary['overdue'] ?></td></tr></tbody></table>
                </div>
            </section>

            <section class="card">
                <h3>Kritische Projekte</h3>
                <?php if ($criticalProjects === []): ?><p>Keine kritischen Projekte nach den angezeigten Kriterien.</p><?php else: ?>
                    <div class="table-scroll"><table><thead><tr><th>Projekt</th><th>Ampel</th><th>Fortschritt-Abweichung</th><th>Überfällige Meilensteine</th><th>Gründe</th></tr></thead><tbody>
                    <?php foreach ($criticalProjects as $project): ?><tr><td><?= htmlspecialchars((string)$project['name']) ?></td><td><?php renderTrafficLight((string)$project['gesamtstatus']); ?></td><td><?= htmlspecialchars((string)$project['abweichungFortschritt']) ?> %</td><td><?= (int)$project['milestonesOverdue'] ?></td><td><?php renderList($project['criticalReasons']); ?></td></tr><?php endforeach; ?>
                    </tbody></table></div>
                <?php endif; ?>
            </section>

            <section class="card">
                <h3>Projektdetails</h3>
                <div class="table-scroll"><table><thead><tr>
                    <th>Projekt</th><th>Portfolios</th><th>Status</th><th>Ampel</th><th>Aufwand Plan/Ist</th><th>Fortschritt Plan/Ist</th><th>Meilensteine gesamt/offen/überfällig</th><th>Gegenstand</th><th>Statustext</th><th>Kritisch</th>
                </tr></thead><tbody><?php foreach ($portfolioProjectAnalyses as $project): ?><tr>
                    <td><?= htmlspecialchars((string)$project['number']) ?> – <?= htmlspecialchars((string)$project['name']) ?></td>
                    <td><?= htmlspecialchars(implode(', ', $project['portfolioNames'])) ?></td>
                    <td><?= htmlspecialchars((string)$project['statusLabel']) ?></td><td><?php renderTrafficLight((string)$project['gesamtstatus']); ?></td>
                    <td><?= htmlspecialchars((string)$project['planAufwand']) ?> / <?= htmlspecialchars((string)$project['istAufwand']) ?></td>
                    <td><?= htmlspecialchars((string)$project['planFortschritt']) ?> % / <?= htmlspecialchars((string)$project['istFortschritt']) ?> %</td>
                    <td><?= (int)$project['milestonesTotal'] ?> / <?= (int)$project['milestonesOpen'] ?> / <?= (int)$project['milestonesOverdue'] ?></td>
                    <td><?= htmlspecialchars((string)($project['subjectMemo'] ?: 'Keine Angabe')) ?></td>
                    <td><?= htmlspecialchars((string)($project['statusMemo'] ?: 'Keine Angabe')) ?></td>
                    <td><?= !empty($project['isCritical']) ? 'Ja' : 'Nein' ?></td>
                </tr><?php endforeach; ?></tbody></table></div>
            </section>
        <?php endif; ?>
    <?php elseif ($selectedPortfolioIds !== []): ?>
        <p class="message message-danger">Die ausgewählten Portfolios wurden nicht gefunden.</p>
    <?php else: ?>
        <p class="message message-info">Bitte wähle mindestens ein Portfolio aus.</p>
    <?php endif; ?>
</main>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const portfolioControl = document.getElementById('portfolioMultiselect');
    const portfolioTrigger = document.getElementById('portfolioDropdownTrigger');
    const portfolioMenu = document.getElementById('portfolioDropdownMenu');
    const portfolioText = document.getElementById('portfolioDropdownText');
    const portfolioBoxes = portfolioControl
        ? Array.from(portfolioControl.querySelectorAll('input[name="portfolioIds[]"]'))
        : [];

    const updatePortfolioText = () => {
        const selected = portfolioBoxes.filter(box => box.checked);
        if (!portfolioText) return;
        if (selected.length === 0) {
            portfolioText.textContent = 'Portfolio auswählen';
        } else if (selected.length === 1) {
            portfolioText.textContent = selected[0].nextElementSibling?.textContent?.trim() || '1 Portfolio ausgewählt';
        } else {
            portfolioText.textContent = selected.length + ' Portfolios ausgewählt';
        }
    };

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

    const promptTextarea = document.getElementById('aiPrompt');
    const defaultPromptValue = document.getElementById('defaultAiPromptValue');
    document.getElementById('resetAiPromptBtn')?.addEventListener('click', () => {
        if (!promptTextarea || !defaultPromptValue) return;
        promptTextarea.value = defaultPromptValue.value;
        promptTextarea.focus();
    });
});
</script>
</body>
</html>
