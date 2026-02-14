<?php

namespace App\Services;

/**
 * Preprocesses raw logs before AI analysis: dedupe, group by time window, mark severity.
 */
class LogPreprocessor
{
    /** Time window in seconds for grouping log lines */
    private const TIME_WINDOW_SECONDS = 300;

    /** Severity keywords (case-insensitive) */
    private const SEVERITY_PATTERNS = [
        'critical' => ['fatal', 'panic', 'critical', 'outage'],
        'high' => ['error', 'exception', 'timeout', 'reset', 'failed', 'refused'],
        'medium' => ['warn', 'warning', 'degraded', 'slow'],
        'low' => ['info', 'debug'],
    ];

    /**
     * Preprocess logs: remove duplicates, group by time window, assign severity.
     *
     * @param  array<int, string>  $logs  Raw log lines (e.g. "12:00 DB timeout")
     * @return array{entries: array<int, array{raw: string, time: string|null, severity: string, window: string}>}
     */
    public function preprocess(array $logs): array
    {
        $normalized = $this->normalizeAndDedupe($logs);
        $withTime = $this->extractTime($normalized);
        $grouped = $this->groupByTimeWindow($withTime);
        $withSeverity = $this->markSeverity($grouped);

        return [
            'entries' => $withSeverity,
            'summary' => [
                'total_original' => count($logs),
                'after_dedup' => count($normalized),
                'windows' => count(array_unique(array_column($withSeverity, 'window'))),
            ],
        ];
    }

    /**
     * Normalize and remove duplicate log lines (by trimmed content).
     *
     * @param  array<int, string>  $logs
     * @return array<int, string>
     */
    private function normalizeAndDedupe(array $logs): array
    {
        $seen = [];
        $result = [];

        foreach ($logs as $line) {
            $trimmed = trim((string) $line);
            if ($trimmed === '') {
                continue;
            }
            if (isset($seen[$trimmed])) {
                continue;
            }
            $seen[$trimmed] = true;
            $result[] = $trimmed;
        }

        return array_values($result);
    }

    /**
     * Extract time from each line (e.g. "12:00" or "12:00:01") for grouping.
     *
     * @param  array<int, string>  $lines
     * @return array<int, array{raw: string, time: string|null}>
     */
    private function extractTime(array $lines): array
    {
        $result = [];

        foreach ($lines as $raw) {
            $time = null;
            if (preg_match('/\b(\d{1,2}:\d{2}(?::\d{2})?)\b/', $raw, $m)) {
                $time = $m[1];
            }
            $result[] = ['raw' => $raw, 'time' => $time];
        }

        return $result;
    }

    /**
     * Group entries by time window (bucket label).
     *
     * @param  array<int, array{raw: string, time: string|null}>  $entries
     * @return array<int, array{raw: string, time: string|null, window: string}>
     */
    private function groupByTimeWindow(array $entries): array
    {
        $result = [];

        foreach ($entries as $entry) {
            $window = 'unknown';
            if ($entry['time'] !== null) {
                $parts = array_map('intval', explode(':', $entry['time']));
                $minutes = ($parts[0] ?? 0) * 60 + ($parts[1] ?? 0);
                $bucket = (int) floor($minutes / (self::TIME_WINDOW_SECONDS / 60));
                $window = 'window_'.$bucket;
            }
            $result[] = [
                'raw' => $entry['raw'],
                'time' => $entry['time'],
                'window' => $window,
            ];
        }

        return $result;
    }

    /**
     * Mark severity per entry based on keywords.
     *
     * @param  array<int, array{raw: string, time: string|null, window: string}>  $entries
     * @return array<int, array{raw: string, time: string|null, window: string, severity: string}>
     */
    private function markSeverity(array $entries): array
    {
        $result = [];

        foreach ($entries as $entry) {
            $severity = 'low';
            $lower = strtolower($entry['raw']);

            foreach (self::SEVERITY_PATTERNS as $level => $keywords) {
                foreach ($keywords as $keyword) {
                    if (str_contains($lower, $keyword)) {
                        if ($this->severityRank($level) > $this->severityRank($severity)) {
                            $severity = $level;
                        }
                        break;
                    }
                }
            }

            $result[] = [
                'raw' => $entry['raw'],
                'time' => $entry['time'],
                'window' => $entry['window'],
                'severity' => $severity,
            ];
        }

        return $result;
    }

    private function severityRank(string $severity): int
    {
        return match ($severity) {
            'critical' => 4,
            'high' => 3,
            'medium' => 2,
            'low' => 1,
            default => 0,
        };
    }
}
