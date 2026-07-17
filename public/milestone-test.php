<?php

require_once __DIR__ . '/../src/BlueAntClient.php';

$config = require __DIR__ . '/../config/config.php';

$projectId = isset($_GET['id']) ? (int) $_GET['id'] : 358571979;

try {
    $client = new BlueAntClient(
        $config['blueant_base_url'],
        $config['blueant_token']
    );

    $data = $client->get('/v1/projects/' . $projectId . '/planningentries');

    $entries = $data['entries']
        ?? $data['response']['entries']
        ?? [];

    // 1. Trennung in Meilensteine und normale Aufgaben (To-dos)
    $milestones = [];
    $todos = [];

    foreach ($entries as $entry) {
        $entryType = strtolower((string)($entry['entryType'] ?? ''));
        
        if (str_contains($entryType, 'milestone') || str_contains($entryType, 'meilenstein')) {
            $milestones[] = $entry;
        } else {
            // Alles andere (Task, Activity, etc.) wandert in die To-dos
            $todos[] = $entry;
        }
    }

    // 2. AMPEL-BERECHNUNG INITIALISIEREN
    $ampelFarbe = 'GRÜN';
    $ampelGrund = 'Projekt liegt im Zeitplan.';
    $heute = new DateTime();

    // Falls gar keine Daten geliefert werden (z. B. leeres Projekt, fehlender Basisplan oder Rechte)
    if (empty($entries)) {
        $ampelFarbe = 'GRAU';
        $ampelGrund = 'Keine Planungsdaten verfügbar (Ggf. Rechteprüfung nötig oder Projekt ist leer).';
    } else {
        
        // A) MEILENSTEINE PRÜFEN (Höchste Priorität -> kann sofort ROT auslösen)
        foreach ($milestones as $ms) {
            $fortschritt = $ms['percentComplete'] ?? $ms['progress'] ?? 0;
            $endDatumStr = $ms['end'] ?? $ms['targetDate'] ?? null;

            if ($fortschritt < 100 && $endDatumStr) {
                $zielDatum = new DateTime($endDatumStr);
                if ($zielDatum < $heute) {
                    $ampelFarbe = 'ROT';
                    $ampelGrund = 'Meilenstein "' . ($ms['name'] ?? 'Unbenannt') . '" ist überfällig!';
                    break; // Bei kritischem Meilensteinverzug brechen wir sofort ab -> ROT
                }
            }
        }

        // B) TO-DOS PRÜFEN (Nur wenn Meilensteine nicht schon ROT sind)
        if ($ampelFarbe !== 'ROT') {
            $anzahlOffenTodos = 0;
            $anzahlUeberfaelligTodos = 0;

            foreach ($todos as $todo) {
                $fortschritt = $todo['percentComplete'] ?? $todo['progress'] ?? 0;
                $endDatumStr = $todo['end'] ?? $todo['endDate'] ?? null;

                if ($fortschritt < 100) {
                    $anzahlOffenTodos++;
                    
                    if ($endDatumStr) {
                        $endDatum = new DateTime($endDatumStr);
                        if ($endDatum < $heute) {
                            $anzahlUeberfaelligTodos++;
                        }
                    }
                }
            }

            // Logik für die Aufgaben-Verzugsquote
            if ($anzahlOffenTodos > 0) {
                $verzugsQuote = ($anzahlUeberfaelligTodos / $anzahlOffenTodos) * 100;

                if ($verzugsQuote > 20) { // Mehr als 20% der offenen Aufgaben im Verzug
                    $ampelFarbe = 'ROT';
                    $ampelGrund = $anzahlUeberfaelligTodos . ' Aufgaben sind überfällig (>20% Verzug).';
                } elseif ($verzugsQuote > 0) { // Weniger als 20%, aber es brennt leicht
                    $ampelFarbe = 'GELB';
                    $ampelGrund = $anzahlUeberfaelligTodos . ' Aufgaben sind überfällig.';
                }
            }
        }
    }

    // 3. ERGEBNIS-ARRAY BAUEN
    $result = [
        'status' => 'OK',
        'projectId' => $projectId,
        'ampelStatus' => $ampelFarbe,
        'ampelBegruendung' => $ampelGrund,
        'totalEntries' => count($entries),
        'totalMilestones' => count($milestones),
        'totalTodos' => count($todos),
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
