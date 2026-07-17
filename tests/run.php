<?php

require_once __DIR__ . '/../src/AiJsonClient.php';
require_once __DIR__ . '/../src/PortfolioAiAnalyzer.php';
require_once __DIR__ . '/../src/PortfolioAnalysis.php';

final class FakeAiClient implements AiJsonClient
{
    public array $input = [];

    public function generateJson(string $systemPrompt, array $inputData): array
    {
        $this->input = $inputData;
        return [
            'management_summary' => 'Testübersicht',
            'portfolio_status' => 'Im Test',
            'subject_overview' => 'Gegenstandsüberblick',
            'status_overview' => 'Statusüberblick',
            'critical_findings' => ['Keine'],
            'recommended_actions' => ['Beobachten'],
            'project_summaries' => [[
                'project_id' => '1',
                'project_name' => 'Testprojekt',
                'summary' => 'Zusammenfassung',
                'subject_summary' => 'Richtiger Gegenstand',
                'status_summary' => 'Richtiger Status',
                'forecast_summary' => 'Schätzung',
                'risk_note' => 'Kein Risiko',
            ]],
        ];
    }
}

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Erwartet: ' . var_export($expected, true) . '; erhalten: ' . var_export($actual, true));
    }
}

$milestones = PortfolioAnalysis::analyzeMilestones([
    ['entryType' => 'milestone', 'progressActual' => 100, 'end' => '2026-01-01'],
    ['entryType' => 'milestone', 'progressActual' => 20, 'end' => '2026-01-10'],
    ['entryType' => 'task', 'progressActual' => 0, 'end' => '2026-01-10'],
], new DateTimeImmutable('2026-02-01'));
assertSameValue(['total' => 2, 'completed' => 1, 'open' => 1, 'overdue' => 1], $milestones, 'Meilensteinanalyse ist falsch.');

$critical = PortfolioAnalysis::analyzeCriticalProject([
    'gesamtstatus' => 'Grün',
    'abweichungFortschritt' => -25,
    'milestonesOverdue' => 0,
    'statusMemo' => 'Das Projekt ist blockiert.',
], -20);
assertSameValue(true, $critical['isCritical'], 'Kritisches Projekt wurde nicht erkannt.');
assertSameValue(2, count($critical['reasons']), 'Kritische Gründe wurden nicht vollständig erkannt.');

$forecast = PortfolioAnalysis::forecast([
    'start' => '2026-01-01',
    'end' => '2026-04-11',
    'istFortschritt' => 25,
    'planAufwand' => 100,
    'istAufwand' => 30,
], new DateTimeImmutable('2026-02-20'));
assertSameValue(true, $forecast['available'], 'Prognose wurde trotz ausreichender Daten nicht erstellt.');
assertSameValue(120.0, $forecast['estimatedTotalEffort'], 'Aufwandsprognose ist falsch.');

$client = new FakeAiClient();
$analyzer = new PortfolioAiAnalyzer($client, ['portfolio_management_summary' => 'Testprompt']);
$report = $analyzer->createManagementSummary(
    ['reportDate' => '2026-07-13', 'items' => [['id' => 1, 'name' => 'Portfolio']]],
    [[
        'id' => 1,
        'name' => 'Testprojekt',
        'subjectMemo' => 'Der echte Gegenstand',
        'noteMemo' => 'Nur eine Notiz',
        'statusMemo' => 'Aktueller Status',
    ]],
    ['Rot' => 0, 'Gelb' => 0, 'Grün' => 1, 'Keine Angabe' => 0],
    ['Aktiv' => 1],
    ['total' => 0, 'open' => 0, 'completed' => 0, 'overdue' => 0],
    []
);
assertSameValue('Der echte Gegenstand', $client->input['projects'][0]['subjectMemo'], 'Gegenstand wird nicht aus subjectMemo übernommen.');
assertSameValue(false, array_key_exists('gegenstandMemo', $client->input['projects'][0]), 'Veraltetes Gegenstandsfeld wird noch übertragen.');
assertSameValue('Richtiger Gegenstand', $report['project_summaries'][0]['subject_summary'], 'KI-Gegenstandszusammenfassung fehlt.');

echo "Alle Tests erfolgreich.\n";
