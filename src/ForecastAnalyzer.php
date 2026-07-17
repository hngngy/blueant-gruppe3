<?php

class ForecastAnalyzer
{
    /**
     * Berechne Prognose für Aufwand und Fortschritt
     */
    public static function analyzeProjectForecast(
        array $project,
        string $reportDate
    ): array {
        $forecast = [
            'schedule_forecast' => null,
            'effort_forecast' => null,
            'schedule_status' => 'on_track',
            'effort_status' => 'on_track',
            'estimated_completion' => null,
            'estimated_budget_overrun' => 0,
        ];

        $planStart = $project['planStart'] ?? null;
        $planEnd = $project['planEnd'] ?? null;
        $reportDateObj = new DateTime($reportDate);

        if (!$planStart || !$planEnd) {
            return $forecast;
        }

        $planStartObj = new DateTime($planStart);
        $planEndObj = new DateTime($planEnd);

        // Berechne zeitlichen Fortschritt
        $totalDays = $planStartObj->diff($planEndObj)->days;
        $elapsedDays = $planStartObj->diff($reportDateObj)->days;
        $remainingDays = $reportDateObj->diff($planEndObj)->days;

        if ($totalDays <= 0) {
            return $forecast;
        }

        $timeProgress = min(100, ($elapsedDays / $totalDays) * 100);

        // Berechne inhaltlichen Fortschritt
        $actualProgress = (float)($project['progress'] ?? 0);

        // Prognose: Wenn weniger als erhofft erledigt, wird das Projekt verzögert
        if ($timeProgress > 0 && $actualProgress < $timeProgress) {
            $schedule_forecast = ($actualProgress / $timeProgress) * 100;
            $forecast['schedule_forecast'] = round($schedule_forecast, 1);
            $forecast['schedule_status'] = 'delayed';

            // Geschätzte Fertigstellung
            if ($schedule_forecast > 0 && $schedule_forecast < 100) {
                $additionalDays = round(($totalDays - $elapsedDays) / ($schedule_forecast / 100), 0);
                $estimatedEnd = new DateTime($reportDate);
                $estimatedEnd->modify("+$additionalDays days");
                $forecast['estimated_completion'] = $estimatedEnd->format('Y-m-d');
            }
        } elseif ($timeProgress > 0 && $actualProgress > $timeProgress * 1.1) {
            $forecast['schedule_forecast'] = round(95, 1);  // Max 95% prognose
            $forecast['schedule_status'] = 'ahead';
        } else {
            $forecast['schedule_status'] = 'on_track';
        }

        // Berechne Aufwands-Prognose
        $planEffort = (float)($project['planEffort'] ?? 0);
        $actualEffort = (float)($project['actualEffort'] ?? 0);

        if ($planEffort > 0 && $timeProgress > 5) {
            $effortBurnRate = $actualEffort / ($timeProgress / 100);
            $effort_forecast = ($effortBurnRate / $planEffort) * 100;
            $forecast['effort_forecast'] = round($effort_forecast, 1);

            if ($effort_forecast > 105) {
                $forecast['effort_status'] = 'budget_risk';
                $forecast['estimated_budget_overrun'] = round($effort_forecast - 100, 1);
            } elseif ($effort_forecast > 100) {
                $forecast['effort_status'] = 'at_risk';
                $forecast['estimated_budget_overrun'] = round($effort_forecast - 100, 1);
            } else {
                $forecast['effort_status'] = 'on_track';
            }
        }

        return $forecast;
    }

    /**
     * Formatiere Forecast als Text
     */
    public static function formatForecast(array $forecast): string
    {
        $lines = [];

        if ($forecast['schedule_forecast'] !== null) {
            $lines[] = sprintf(
                'Zeitplan-Prognose: %s%% (%s)',
                $forecast['schedule_forecast'],
                self::getStatusText($forecast['schedule_status'])
            );
        }

        if ($forecast['estimated_completion']) {
            $lines[] = sprintf(
                'Geschätzte Fertigstellung: %s',
                $forecast['estimated_completion']
            );
        }

        if ($forecast['effort_forecast'] !== null) {
            $lines[] = sprintf(
                'Aufwands-Prognose: %s%% (%s)',
                $forecast['effort_forecast'],
                self::getStatusText($forecast['effort_status'])
            );
        }

        if ($forecast['estimated_budget_overrun'] > 0) {
            $lines[] = sprintf(
                'Geschätztes Budget-Überrun: +%s%%',
                $forecast['estimated_budget_overrun']
            );
        }

        return implode(' | ', $lines);
    }

    private static function getStatusText(string $status): string
    {
        return match ($status) {
            'on_track' => 'Im Plan',
            'ahead' => 'Voraus',
            'delayed' => 'Verzögert',
            'at_risk' => 'Gefährdet',
            'budget_risk' => 'Budget-Risiko',
            default => $status,
        };
    }
}
