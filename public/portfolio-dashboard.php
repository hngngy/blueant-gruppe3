<?php

require_once __DIR__ . '/../src/BlueAntClient.php';

$config = require __DIR__ . '/../config/config.php';

$client = new BlueAntClient(
    $config['blueant_base_url'],
    $config['blueant_token']
);

try {
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

$selectedPortfolioId = isset($_GET['portfolioId']) ? (int) $_GET['portfolioId'] : null;

$selectedPortfolio = null;
$portfolioProjectIds = [];
$portfolioProjects = [];

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
}
$portfolioProjectAnalyses = [];

foreach ($portfolioProjects as $project) {
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

    $customFields = $project['customFields'] ?? [];

    $gesamtstatusRaw = $customFields['832814142'] ?? null;
    $project['gesamtstatus'] = translateTrafficLight($gesamtstatusRaw);
    $statusId = (int)($project['statusId'] ?? 0);
    $statusName = $statusMap[$statusId] ?? 'Unbekannter Status';
    $project['statusName'] = $statusName;
    $project['statusLabel'] = $statusId . ' - ' . $statusName;

    $project['statusMemo'] = trim(strip_tags((string)($projectDetails['statusMemo'] ?? '')));
    $project['noteMemo'] = trim(strip_tags((string)($projectDetails['noteMemo'] ?? '')));

    $planningEntries = $client->getPlanningEntries($projectId);
    $milestones = analyzeMilestones($planningEntries);

    $project['milestonesTotal'] = $milestones['total'];
    $project['milestonesCompleted'] = $milestones['completed'];
    $project['milestonesOpen'] = $milestones['open'];
    $project['milestonesOverdue'] = $milestones['overdue'];

    $overallRisk = $projectDetails['overallRisk'] ?? [];
    $project['overallRiskId'] = $overallRisk['overallRiskId'] ?? null;
    $project['riskAssessment'] = trim(strip_tags((string)($overallRisk['riskAssessment'] ?? '')));

    $criticalAnalysis = analyzeCriticalProject($project);
    $project['isCritical'] = $criticalAnalysis['isCritical'];
    $project['criticalReasons'] = $criticalAnalysis['reasons'];

    $portfolioProjectAnalyses[] = $project;
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

function analyzeMilestones(array $entries): array
{
    $total = 0;
    $completed = 0;
    $open = 0;
    $overdue = 0;

    $today = new DateTimeImmutable('today');

    foreach ($entries as $entry) {
        $entryType = strtolower((string)($entry['entryType'] ?? ''));

        if ($entryType !== 'milestone') {
            continue;
        }

        $total++;

        $progress = (float)($entry['progressActual'] ?? 0);
        $endDateRaw = $entry['end'] ?? $entry['endWished'] ?? null;

        $isCompleted = $progress >= 100;

        if ($isCompleted) {
            $completed++;
            continue;
        }

        $open++;

        if ($endDateRaw) {
            try {
                $endDate = new DateTimeImmutable((string)$endDateRaw);

                if ($endDate < $today) {
                    $overdue++;
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

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Portfolio-Dashboard</title>
</head>
<body>

<h1>Portfolio-Dashboard</h1>

<?php if ($error): ?>
    <p style="color:red;">Fehler: <?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<form method="get" action="portfolio-dashboard.php">
    <label for="portfolioId">Portfolio auswählen:</label>

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

    <button type="submit">Auswertung starten</button>
</form>

<?php if ($selectedPortfolio): ?>

    <h2>Ausgewähltes Portfolio: <?= htmlspecialchars($selectedPortfolio['name'] ?? '-') ?></h2>

    <p>
        Portfolio-ID: <?= htmlspecialchars((string)($selectedPortfolio['id'] ?? '-')) ?><br>
        Zeitraum:
        <?= htmlspecialchars($selectedPortfolio['dateFrom'] ?? '-') ?>
        bis
        <?= htmlspecialchars($selectedPortfolio['dateTo'] ?? '-') ?><br>
        Gefundene Projekte aus dem Portfolio: <?= count($portfolioProjects) ?>
    </p>
<h3>Statusampel-Zusammenfassung</h3>

<table border="1" cellpadding="8" cellspacing="0">
    <thead>
        <tr>
            <th>Statusampel</th>
            <th>Anzahl Projekte</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($trafficLightCounts as $status => $count): ?>
            <tr>
                <td><?= htmlspecialchars($status) ?></td>
                <td><?= htmlspecialchars((string)$count) ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<h3>Projektstatus-Zusammenfassung</h3>

<table border="1" cellpadding="8" cellspacing="0">
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

<table border="1" cellpadding="8" cellspacing="0">
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
    <table border="1" cellpadding="8" cellspacing="0">
        <thead>
            <tr>
                <th>Projekt</th>
                <th>Projektstatus</th>
                <th>Statusampel</th>
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

    <table border="1" cellpadding="8" cellspacing="0">
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
                <th>Meilensteine gesamt</th>
                <th>Meilensteine offen</th>
                <th>Meilensteine erledigt</th>
                <th>Meilensteine überfällig</th>
                <th>Status-Ampel</th>
                <th>Status-Text</th>
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
                  <td><?= htmlspecialchars((string)($project['milestonesTotal'] ?? 0)) ?></td>
                  <td><?= htmlspecialchars((string)($project['milestonesOpen'] ?? 0)) ?></td>
                  <td><?= htmlspecialchars((string)($project['milestonesCompleted'] ?? 0)) ?></td>
                  <td><?= htmlspecialchars((string)($project['milestonesOverdue'] ?? 0)) ?></td>
                  <td><?= htmlspecialchars((string)($project['gesamtstatus'] ?? '-')) ?></td>
                  <td><?= nl2br(htmlspecialchars((string)($project['statusMemo'] ?: 'Keine Angabe'))) ?></td>
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

<?php elseif ($selectedPortfolioId): ?>

    <p style="color:red;">Das ausgewählte Portfolio wurde nicht gefunden.</p>

<?php else: ?>

    <p>Bitte wähle ein Portfolio aus.</p>

<?php endif; ?>

</body>
</html>