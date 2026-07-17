<?php

final class PortfolioAnalysis
{
    public static function summarizeText(string $text, int $maxLength = 320): string
    {
        $clean = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');

        if ($clean === '') {
            return 'Keine Angabe.';
        }

        if (self::length($clean) <= $maxLength) {
            return $clean;
        }

        $excerpt = self::substring($clean, 0, $maxLength);
        $sentenceEnd = max(
            self::lastPosition($excerpt, '.'),
            self::lastPosition($excerpt, '!'),
            self::lastPosition($excerpt, '?')
        );

        if ($sentenceEnd >= (int)($maxLength * 0.55)) {
            return trim(self::substring($excerpt, 0, $sentenceEnd + 1));
        }

        $wordEnd = self::lastPosition($excerpt, ' ');
        $excerpt = $wordEnd < 0 ? $excerpt : self::substring($excerpt, 0, $wordEnd);

        return rtrim($excerpt, " \t\n\r\0\x0B,;:") . ' …';
    }

    public static function analyzeMilestones(array $entries, DateTimeImmutable $reportDate): array
    {
        $result = ['total' => 0, 'completed' => 0, 'open' => 0, 'overdue' => 0];

        foreach ($entries as $entry) {
            if (!is_array($entry) || strtolower((string)($entry['entryType'] ?? '')) !== 'milestone') {
                continue;
            }

            $result['total']++;
            $progress = (float)($entry['progressActual'] ?? 0);
            $isCompleted = $progress >= 100 || !empty($entry['finished']);

            if ($isCompleted) {
                $result['completed']++;
                continue;
            }

            $result['open']++;
            $endDateRaw = $entry['end'] ?? $entry['endWished'] ?? null;

            if ($endDateRaw === null || $endDateRaw === '') {
                continue;
            }

            try {
                if (new DateTimeImmutable((string)$endDateRaw) < $reportDate) {
                    $result['overdue']++;
                }
            } catch (Throwable $e) {
                // Ein unlesbares Datum wird nicht als überfällig bewertet.
            }
        }

        return $result;
    }

    public static function analyzeCriticalProject(array $project, float $progressThreshold = -20.0): array
    {
        $reasons = [];

        if (($project['gesamtstatus'] ?? '') === 'Rot') {
            $reasons[] = 'Statusampel ist Rot';
        }

        $progressDeviation = (float)($project['abweichungFortschritt'] ?? 0);
        if ($progressDeviation <= $progressThreshold) {
            $reasons[] = sprintf(
                'Fortschritt liegt mindestens %.1f Prozentpunkte hinter Plan',
                abs($progressThreshold)
            );
        }

        $overdueMilestones = (int)($project['milestonesOverdue'] ?? 0);
        if ($overdueMilestones > 0) {
            $reasons[] = $overdueMilestones . ' überfällige Meilensteine';
        }

        $statusText = self::lower((string)($project['statusMemo'] ?? ''));
        $keywords = [
            'kritisch', 'gefährdet', 'eskalation', 'eskaliert', 'problem',
            'verzögerung', 'verzögert', 'verzug', 'terminverzug', 'rückstand',
            'blockiert', 'blockade', 'hohes risiko', 'budgetüberschreitung',
            'kostenüberschreitung', 'mehraufwand', 'ressourcenengpass',
            'fehlende ressourcen', 'lieferverzug', 'nicht im plan',
            'handlungsbedarf', 'entscheidung erforderlich', 'freigabe fehlt',
        ];

        foreach ($keywords as $keyword) {
            if ($statusText !== '' && str_contains($statusText, $keyword)) {
                $reasons[] = 'Kritischer Hinweis im Statustext: „' . $keyword . '“';
                break;
            }
        }

        return ['isCritical' => $reasons !== [], 'reasons' => $reasons];
    }

    public static function forecast(array $project, DateTimeImmutable $reportDate): array
    {
        $result = [
            'available' => false,
            'estimatedCompletion' => null,
            'scheduleStatus' => 'Keine belastbare Prognose möglich',
            'delayDays' => null,
            'estimatedTotalEffort' => null,
            'estimatedEffortDeviation' => null,
            'explanation' => 'Für eine Prognose fehlen ausreichende Projekt- oder Fortschrittsdaten.',
        ];

        $startRaw = $project['start'] ?? null;
        $endRaw = $project['end'] ?? null;
        $actualProgress = (float)($project['istFortschritt'] ?? 0);

        if (!$startRaw || !$endRaw || $actualProgress <= 0 || $actualProgress > 100) {
            return $result;
        }

        try {
            $start = new DateTimeImmutable((string)$startRaw);
            $plannedEnd = new DateTimeImmutable((string)$endRaw);
        } catch (Throwable $e) {
            return $result;
        }

        if ($plannedEnd <= $start || $reportDate <= $start) {
            return $result;
        }

        $elapsedDays = max(1, (int)$start->diff($reportDate)->days);
        $estimatedDurationDays = (int)ceil($elapsedDays * (100 / $actualProgress));
        $estimatedCompletion = $start->modify('+' . $estimatedDurationDays . ' days');
        $delayDays = (int)$plannedEnd->diff($estimatedCompletion)->format('%r%a');

        $result['available'] = true;
        $result['estimatedCompletion'] = $estimatedCompletion->format('Y-m-d');
        $result['delayDays'] = $delayDays;
        $result['scheduleStatus'] = $delayDays > 0 ? 'Voraussichtlich verspätet' : 'Voraussichtlich im Plan';

        $planEffort = (float)($project['planAufwand'] ?? 0);
        $actualEffort = (float)($project['istAufwand'] ?? 0);

        if ($planEffort > 0 && $actualEffort >= 0) {
            $estimatedTotalEffort = $actualEffort / ($actualProgress / 100);
            $result['estimatedTotalEffort'] = round($estimatedTotalEffort, 1);
            $result['estimatedEffortDeviation'] = round($estimatedTotalEffort - $planEffort, 1);
        }

        $result['explanation'] = 'Lineare Schätzung aus bisheriger Laufzeit und aktuellem Ist-Fortschritt; keine zugesicherte Termin- oder Aufwandsangabe.';

        return $result;
    }

    public static function formatForecast(array $forecast): string
    {
        if (empty($forecast['available'])) {
            return 'Keine belastbare Prognose möglich.';
        }

        $parts = [
            (string)$forecast['scheduleStatus'],
            'geschätzte Fertigstellung: ' . (string)$forecast['estimatedCompletion'],
        ];

        if ($forecast['estimatedTotalEffort'] !== null) {
            $parts[] = 'geschätzter Gesamtaufwand: ' . number_format((float)$forecast['estimatedTotalEffort'], 1, ',', '.');
            $parts[] = 'geschätzte Aufwandsabweichung: ' . number_format((float)$forecast['estimatedEffortDeviation'], 1, ',', '.');
        }

        return implode('; ', $parts) . '. Schätzung auf Basis des aktuellen Datenstands.';
    }

    private static function length(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
    }

    private static function substring(string $text, int $start, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($text, $start, $length) : substr($text, $start, $length);
    }

    private static function lastPosition(string $text, string $needle): int
    {
        $position = function_exists('mb_strrpos') ? mb_strrpos($text, $needle) : strrpos($text, $needle);
        return $position === false ? -1 : (int)$position;
    }

    private static function lower(string $text): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
    }
}
