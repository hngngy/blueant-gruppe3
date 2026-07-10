<?php
/**
 * Helper-Script zum Generieren von Base64-kodierten Prompts für die .env-Datei
 * 
 * Verwendung:
 *   php tools/generate-prompt-env.php "Dein Prompt hier"
 * 
 * Oder interaktiv (ohne Argumente):
 *   php tools/generate-prompt-env.php
 */

$prompt = '';

if ($argc > 1) {
    $prompt = $argv[1];
} else {
    echo "=== Prompt zu Base64 konvertieren ===\n";
    echo "Gib deinen Prompt ein (Ctrl+D zum Beenden):\n\n";
    
    $lines = [];
    while ($line = fgets(STDIN)) {
        $lines[] = $line;
    }
    
    $prompt = implode('', $lines);
}

if (trim($prompt) === '') {
    echo "Fehler: Prompt ist leer!\n";
    exit(1);
}

$encoded = base64_encode($prompt);

echo "\n=== Ergebnis ===\n";
echo "Füge diese Zeile in deine .env-Datei ein:\n\n";
echo "PROMPT_PORTFOLIO_MANAGEMENT_SUMMARY=" . $encoded . "\n\n";
echo "Länge: " . strlen($encoded) . " Zeichen\n";
