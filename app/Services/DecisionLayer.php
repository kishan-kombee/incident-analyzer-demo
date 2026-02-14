<?php

namespace App\Services;

use App\Models\Metric;

/**
 * Backend decision layer: rank AI-suggested causes, add confidence, correlate with metrics.
 * "AI suggests. Backend decides."
 */
class DecisionLayer
{
    /** Correlation rules: metric signals that boost confidence for certain cause keywords */
    private const METRIC_CORRELATIONS = [
        'database' => ['db_latency_high', 'cpu_high'],
        'db' => ['db_latency_high', 'cpu_high'],
        'connection' => ['db_latency_high'],
        'timeout' => ['db_latency_high', 'cpu_high'],
        'overload' => ['cpu_high', 'requests_high'],
        'cpu' => ['cpu_high'],
        'memory' => ['cpu_high'],
        'network' => ['db_latency_high'],
    ];

    /**
     * Build final response: single likely_cause, confidence, next_steps.
     * Backend decides: ground in log evidence first; only then correlate with metrics (per PDF).
     *
     * @param  array{likely_cause?: string, reasoning?: string, next_steps?: string}  $aiResponse  Parsed AI output
     * @param  array{entries: array, summary: array}  $preprocessed  Preprocessed logs summary
     */
    public function decide(array $aiResponse, array $preprocessed, ?Metric $metric): array
    {
        $entries = $preprocessed['entries'] ?? [];
        $logGrounded = $this->getLogGroundedCause($entries);

        if (! $this->logsDescribeIncident($entries)) {
            return [
                'likely_cause' => 'No clear incident from logs',
                'confidence' => 0.25,
                'next_steps' => 'Provide error logs or incident-related messages for analysis. Current input does not describe a failure or incident.',
            ];
        }

        $cause = $this->sanitizeCause($aiResponse['likely_cause'] ?? 'Unknown');
        $baseConfidence = (float) ($aiResponse['confidence'] ?? 0.5);
        $suggestedSteps = $aiResponse['next_steps'] ?? 'Review logs and metrics.';

        $signals = $this->extractMetricSignals($metric);

        if ($logGrounded !== null && ! $this->causeMatchesLogSignal($cause, $logGrounded['logSignal'])) {
            $cause = $logGrounded['cause'];
            $nextSteps = $logGrounded['next_steps'];
            $confidence = min(0.98, 0.85 + ($signals['db_latency_high'] || $signals['cpu_high'] ? 0.05 : 0));
        } else {
            $confidence = $this->adjustConfidence($cause, $baseConfidence, $signals, $preprocessed);
            $nextSteps = $this->refineNextSteps($cause, $suggestedSteps, $signals);
        }

        return [
            'likely_cause' => $cause,
            'confidence' => round($confidence, 2),
            'next_steps' => $nextSteps,
        ];
    }

    /**
     * True if logs contain incident/error indicators. False for questions or unrelated text.
     *
     * @param  array<int, array{raw?: string}>  $entries
     */
    private function logsDescribeIncident(array $entries): bool
    {
        $text = '';
        foreach ($entries as $e) {
            $text .= ' '.($e['raw'] ?? '');
        }
        $lower = strtolower(trim($text));
        if ($lower === '') {
            return false;
        }

        $questionLike = preg_match('/\?\s*$/', $lower)
            || str_contains($lower, ' what is ')
            || str_contains($lower, ' how to ')
            || str_contains($lower, ' current version ')
            || str_contains($lower, ' which version ')
            || str_contains($lower, ' can you ')
            || str_contains($lower, ' could you ')
            || preg_match('/\b(what|how|which|why|when|where)\s+(is|are|do|does|did)\b/', $lower);
        if ($questionLike) {
            return false;
        }

        $incidentKeywords = [
            'error', 'exception', 'fail', 'failed', 'failure', 'fatal', 'critical',
            '404', '500', '502', '503', 'timeout', 'timed out', 'crash', 'refused',
            'reset', 'warning', 'stack trace', 'not found', 'unreachable', 'denied',
            'out of memory', 'oom', 'segfault', 'panic', 'deadlock',
        ];
        foreach ($incidentKeywords as $keyword) {
            if (str_contains($lower, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Strong log signals (from PDF: backend must correlate with evidence). Priority order.
     *
     * @param  array<int, array{raw?: string, severity?: string}>  $entries
     * @return array{cause: string, next_steps: string, logSignal: string}|null
     */
    private function getLogGroundedCause(array $entries): ?array
    {
        $text = '';
        foreach ($entries as $e) {
            $text .= ' '.($e['raw'] ?? '');
        }
        $lower = strtolower($text);

        if (str_contains($lower, '404') || (str_contains($lower, 'not found') && ! str_contains($lower, 'connection'))) {
            return [
                'cause' => 'Resource not found (404)',
                'next_steps' => 'Verify request URL and route; check if the resource or endpoint exists.',
                'logSignal' => '404',
            ];
        }
        if (str_contains($lower, '500') || str_contains($lower, 'internal server error')) {
            return [
                'cause' => 'Internal server error (500)',
                'next_steps' => 'Check application logs and server-side code for exceptions.',
                'logSignal' => '500',
            ];
        }
        if ((str_contains($lower, 'timeout') || str_contains($lower, 'timed out')) && (str_contains($lower, 'db') || str_contains($lower, 'database') || str_contains($lower, 'connection'))) {
            return [
                'cause' => 'Database timeout or overload',
                'next_steps' => 'Check connection pool and DB health. Review slow queries and indexes.',
                'logSignal' => 'db_timeout',
            ];
        }
        if (str_contains($lower, 'connection refused')) {
            return [
                'cause' => 'Connection refused (service unreachable)',
                'next_steps' => 'Verify target host/port is running and reachable; check firewall and network.',
                'logSignal' => 'connection_refused',
            ];
        }
        if (str_contains($lower, 'connection reset')) {
            return [
                'cause' => 'Database or network connection instability',
                'next_steps' => 'Check connection pool and DB health; verify network stability.',
                'logSignal' => 'connection_reset',
            ];
        }
        if (str_contains($lower, 'timeout') || str_contains($lower, 'timed out')) {
            return [
                'cause' => 'Service timeout',
                'next_steps' => 'Check upstream service health and timeout settings.',
                'logSignal' => 'timeout',
            ];
        }

        return null;
    }

    /**
     * True if AI cause is consistent with the dominant log signal (avoids overriding when AI is correct).
     */
    private function causeMatchesLogSignal(string $cause, string $logSignal): bool
    {
        $lower = strtolower($cause);
        return match ($logSignal) {
            '404' => str_contains($lower, '404') || str_contains($lower, 'not found') || str_contains($lower, 'resource'),
            '500' => str_contains($lower, '500') || str_contains($lower, 'internal') || str_contains($lower, 'server error'),
            'db_timeout' => str_contains($lower, 'database') || str_contains($lower, 'db') || str_contains($lower, 'timeout'),
            'connection_refused' => str_contains($lower, 'refused') || str_contains($lower, 'unreachable') || str_contains($lower, 'connection'),
            'connection_reset' => str_contains($lower, 'reset') || str_contains($lower, 'connection') || str_contains($lower, 'instability'),
            'timeout' => str_contains($lower, 'timeout'),
            default => true,
        };
    }

    private function sanitizeCause(string $cause): string
    {
        $trimmed = trim($cause);
        if ($trimmed === '') {
            return 'Unknown';
        }

        return $trimmed;
    }

    /**
     * @return array{db_latency_high: bool, cpu_high: bool, requests_high: bool}
     */
    private function extractMetricSignals(?Metric $metric): array
    {
        if ($metric === null) {
            return [
                'db_latency_high' => false,
                'cpu_high' => false,
                'requests_high' => false,
            ];
        }

        $dbHigh = $metric->db_latency !== null && $metric->db_latency > 300;
        $cpuHigh = $metric->cpu !== null && $metric->cpu >= 80;
        $reqs = strtolower((string) $metric->requests_per_sec);
        $requestsHigh = in_array($reqs, ['high', 'very high', 'spike'], true);

        return [
            'db_latency_high' => $dbHigh,
            'cpu_high' => $cpuHigh,
            'requests_high' => $requestsHigh,
        ];
    }

    /**
     * Correlate cause with metrics: e.g. DB issues + high latency → stronger signal.
     *
     * @param  array{db_latency_high: bool, cpu_high: bool, requests_high: bool}  $signals
     */
    private function adjustConfidence(string $cause, float $base, array $signals, array $preprocessed): float
    {
        $lower = strtolower($cause);
        $boost = 0.0;

        foreach (self::METRIC_CORRELATIONS as $keyword => $signalKeys) {
            if (! str_contains($lower, $keyword)) {
                continue;
            }
            foreach ($signalKeys as $key) {
                if (! empty($signals[$key])) {
                    $boost += 0.08;
                }
            }
        }

        $confidence = $base + $boost;
        $highSeverityCount = 0;
        foreach ($preprocessed['entries'] ?? [] as $entry) {
            if (in_array($entry['severity'] ?? '', ['high', 'critical'], true)) {
                $highSeverityCount++;
            }
        }
        if ($highSeverityCount > 0) {
            $confidence = min(0.98, $confidence + 0.05 * min($highSeverityCount, 3));
        }

        return max(0.0, min(1.0, $confidence));
    }

    /**
     * Refine next_steps using cause and metric signals. Deduplicated and concise.
     *
     * @param  array{db_latency_high: bool, cpu_high: bool, requests_high: bool}  $signals
     */
    private function refineNextSteps(string $cause, string $suggested, array $signals): string
    {
        $lower = strtolower($cause);
        $hints = [];

        if (str_contains($lower, 'database') || str_contains($lower, 'db') || str_contains($lower, 'connection')) {
            $hints[] = 'Check connection pool and DB health.';
            if ($signals['db_latency_high']) {
                $hints[] = 'Review slow queries and indexes.';
            }
        }
        if (str_contains($lower, 'timeout') && $signals['db_latency_high']) {
            $hints[] = 'Consider increasing timeout or scaling DB.';
        }
        if ((str_contains($lower, 'cpu') || str_contains($lower, 'overload')) && $signals['cpu_high']) {
            $hints[] = 'Profile CPU and consider scaling or optimization.';
        }

        $suggestedTrimmed = trim($suggested);
        if ($suggestedTrimmed !== '' && $suggestedTrimmed !== 'Review logs and metrics.') {
            $hints[] = $suggestedTrimmed;
        }

        $combined = $hints !== [] ? implode(' ', $this->deduplicateSentences($hints)) : $suggestedTrimmed;

        return $combined !== '' ? $combined : 'Review logs and metrics.';
    }

    /**
     * Remove duplicate or near-duplicate sentences (e.g. same meaning, different wording).
     *
     * @param  array<int, string>  $sentences
     * @return array<int, string>
     */
    private function deduplicateSentences(array $sentences): array
    {
        $seen = [];
        $result = [];

        foreach ($sentences as $s) {
            $normalized = strtolower(trim($s, " .\t"));
            $key = preg_replace('/\s+/', ' ', $normalized);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            foreach ($seen as $existing => $_) {
                if (str_contains($key, $existing) || str_contains($existing, $key)) {
                    continue 2;
                }
            }
            $seen[$key] = true;
            $result[] = trim($s);
        }

        return $result;
    }
}
