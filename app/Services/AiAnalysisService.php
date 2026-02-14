<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MCP-style AI analysis: send cleaned logs + metric summary to LLM with smart context selection.
 * Avoids sending all logs blindly; mitigates hallucinations via structured output and grounding.
 */
class AiAnalysisService
{
    /** Max log lines to send (smart context: prefer high severity, then recent) */
    private const MAX_LOG_LINES = 50;

    /** Max chars for log block to avoid token overflow */
    private const MAX_LOG_CHARS = 4000;

    public function __construct() {}

    /**
     * Ask AI for probable root causes and reasoning. Returns structured array for decision layer.
     *
     * @param  array{entries: array, summary: array}  $preprocessed
     * @param  array{cpu?: int, db_latency?: int, requests_per_sec?: string}  $metricsSummary
     * @return array{likely_cause: string, reasoning: string, next_steps: string, confidence: float}
     */
    public function suggestCauses(array $preprocessed, array $metricsSummary): array
    {
        $context = $this->buildSmartContext($preprocessed, $metricsSummary);
        $prompt = $this->buildPrompt($context);
        $systemPrompt = $this->systemPrompt();

        $provider = strtolower(trim((string) (config('ai.provider') ?? 'gemini')));

        if ($provider === 'gemini' && config('services.gemini.key')) {
            return $this->callGemini($systemPrompt, $prompt, $preprocessed);
        }
        if ($provider === 'grok' && config('services.grok.key')) {
            return $this->callGrok($systemPrompt, $prompt, $preprocessed);
        }
        if ($provider === 'groq' && config('services.groq.key')) {
            return $this->callGroq($systemPrompt, $prompt, $preprocessed);
        }
        if ($provider === 'openai' && config('services.openai.key')) {
            return $this->callOpenAi($systemPrompt, $prompt, $preprocessed);
        }

        return $this->serviceUnavailableResponse(0, 'No API key for provider: '.$provider, $provider);
    }

    /**
     * Call Google Gemini API (free tier). Response is fully from AI.
     *
     * @param  array{entries: array}  $preprocessed
     * @return array{likely_cause: string, reasoning: string, next_steps: string, confidence: float}
     */
    private function callGemini(string $systemPrompt, string $userPrompt, array $preprocessed): array
    {
        $key = config('services.gemini.key');
        $model = config('services.gemini.model');
        $urlTemplate = config('services.gemini.url');
        $url = sprintf($urlTemplate, $model).'?key='.$key;

        $fullText = $systemPrompt."\n\n".$userPrompt;
        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $fullText],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.2,
            ],
        ];

        try {
            $response = Http::timeout(30)->post($url, $payload);

            if ($response->status() === 429) {
                sleep(3);
                $response = Http::timeout(30)->post($url, $payload);
            }

            if (! $response->successful()) {
                $status = $response->status();
                $body = $response->body();
                Log::warning('Gemini API error', ['status' => $status, 'body' => $body]);

                return $this->serviceUnavailableResponse($status, $body, 'gemini');
            }

            $data = $response->json();
            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
            $parsed = $this->parseJsonFromText($text);
            if (! is_array($parsed)) {
                return $this->serviceUnavailableResponse(0, 'Invalid Gemini response', 'gemini');
            }

            return $this->groundAndSanitize($parsed, $preprocessed);
        } catch (\Throwable $e) {
            Log::error('Gemini exception', ['message' => $e->getMessage()]);

            return $this->serviceUnavailableResponse(0, $e->getMessage(), 'gemini');
        }
    }

    /**
     * Call xAI Grok API (OpenAI-compatible). Response is fully from AI.
     *
     * @param  array{entries: array}  $preprocessed
     * @return array{likely_cause: string, reasoning: string, next_steps: string, confidence: float}
     */
    private function callGrok(string $systemPrompt, string $userPrompt, array $preprocessed): array
    {
        try {
            $payload = [
                'model' => config('services.grok.model'),
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => 0.2,
            ];
            $response = Http::withToken(config('services.grok.key'))
                ->timeout(30)
                ->post(config('services.grok.url'), $payload);

            if ($response->status() === 429) {
                sleep(3);
                $response = Http::withToken(config('services.grok.key'))
                    ->timeout(30)
                    ->post(config('services.grok.url'), $payload);
            }

            if (! $response->successful()) {
                $status = $response->status();
                $body = $response->body();
                Log::warning('Grok API error', ['status' => $status, 'body' => $body]);

                return $this->serviceUnavailableResponse($status, $body, 'grok');
            }

            $body = $response->json();
            $content = $body['choices'][0]['message']['content'] ?? '{}';
            $parsed = $this->parseJsonFromText($content);
            if (! is_array($parsed)) {
                return $this->serviceUnavailableResponse(0, 'Invalid Grok response', 'grok');
            }

            return $this->groundAndSanitize($parsed, $preprocessed);
        } catch (\Throwable $e) {
            Log::error('Grok exception', ['message' => $e->getMessage()]);

            return $this->serviceUnavailableResponse(0, $e->getMessage(), 'grok');
        }
    }

    /**
     * Call Groq API (OpenAI-compatible, fast inference). Response is fully from AI.
     *
     * @param  array{entries: array}  $preprocessed
     * @return array{likely_cause: string, reasoning: string, next_steps: string, confidence: float}
     */
    private function callGroq(string $systemPrompt, string $userPrompt, array $preprocessed): array
    {
        $payload = [
            'model' => config('services.groq.model'),
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'temperature' => 0.2,
        ];
        try {
            $response = Http::withToken(config('services.groq.key'))
                ->timeout(30)
                ->post(config('services.groq.url'), $payload);

            if ($response->status() === 429) {
                sleep(3);
                $response = Http::withToken(config('services.groq.key'))
                    ->timeout(30)
                    ->post(config('services.groq.url'), $payload);
            }

            if (! $response->successful()) {
                $status = $response->status();
                $body = $response->body();
                Log::warning('Groq API error', ['status' => $status, 'body' => $body]);

                return $this->serviceUnavailableResponse($status, $body, 'groq');
            }

            $body = $response->json();
            $content = $body['choices'][0]['message']['content'] ?? '{}';
            $parsed = $this->parseJsonFromText($content);
            if (! is_array($parsed)) {
                return $this->serviceUnavailableResponse(0, 'Invalid Groq response', 'groq');
            }

            return $this->groundAndSanitize($parsed, $preprocessed);
        } catch (\Throwable $e) {
            Log::error('Groq exception', ['message' => $e->getMessage()]);

            return $this->serviceUnavailableResponse(0, $e->getMessage(), 'groq');
        }
    }

    /**
     * Call OpenAI API. Response is fully from AI.
     *
     * @param  array{entries: array}  $preprocessed
     * @return array{likely_cause: string, reasoning: string, next_steps: string, confidence: float}
     */
    private function callOpenAi(string $systemPrompt, string $userPrompt, array $preprocessed): array
    {
        $payload = [
            'model' => config('services.openai.model'),
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0.2,
        ];
        try {
            $response = Http::withToken(config('services.openai.key'))
                ->timeout(30)
                ->post(config('services.openai.url'), $payload);

            if ($response->status() === 429) {
                sleep(3);
                $response = Http::withToken(config('services.openai.key'))
                    ->timeout(30)
                    ->post(config('services.openai.url'), $payload);
            }

            if (! $response->successful()) {
                $status = $response->status();
                $body = $response->body();
                Log::warning('OpenAI API error', ['status' => $status, 'body' => $body]);

                return $this->serviceUnavailableResponse($status, $body, 'openai');
            }

            $body = $response->json();
            $content = $body['choices'][0]['message']['content'] ?? '{}';
            $parsed = json_decode($content, true);
            if (! is_array($parsed)) {
                return $this->serviceUnavailableResponse(0, 'Invalid AI response', 'openai');
            }

            return $this->groundAndSanitize($parsed, $preprocessed);
        } catch (\Throwable $e) {
            Log::error('OpenAI exception', ['message' => $e->getMessage()]);

            return $this->serviceUnavailableResponse(0, $e->getMessage(), 'openai');
        }
    }

    /**
     * Smart context: prioritize high/critical severity entries, cap total size.
     *
     * @param  array{entries: array, summary: array}  $preprocessed
     * @param  array{cpu?: int, db_latency?: int, requests_per_sec?: string}  $metricsSummary
     */
    private function buildSmartContext(array $preprocessed, array $metricsSummary): array
    {
        $entries = $preprocessed['entries'] ?? [];
        $bySeverity = ['critical' => [], 'high' => [], 'medium' => [], 'low' => []];

        foreach ($entries as $entry) {
            $sev = $entry['severity'] ?? 'low';
            if (isset($bySeverity[$sev])) {
                $bySeverity[$sev][] = $entry;
            }
        }

        $selected = array_merge(
            $bySeverity['critical'],
            $bySeverity['high'],
            array_slice($bySeverity['medium'], 0, 15),
            array_slice($bySeverity['low'], 0, 10)
        );
        $selected = array_slice($selected, 0, self::MAX_LOG_LINES);

        $logBlock = '';
        foreach ($selected as $e) {
            $line = sprintf("[%s] %s: %s\n", $e['window'] ?? '', $e['severity'] ?? '', $e['raw'] ?? '');
            if (strlen($logBlock) + strlen($line) > self::MAX_LOG_CHARS) {
                break;
            }
            $logBlock .= $line;
        }

        return [
            'log_lines' => $logBlock,
            'metrics' => $metricsSummary,
            'summary' => $preprocessed['summary'] ?? [],
        ];
    }

    private function systemPrompt(): string
    {
        return 'You are an incident analyst. Given cleaned log entries and system metrics, suggest ONE most likely root cause, brief reasoning, and one concrete next step. '
            .'Respond only with valid JSON: {"likely_cause": "...", "reasoning": "...", "next_steps": "...", "confidence": 0.0-1.0}. '
            .'Base your answer only on evidence in the logs and metrics. Do not invent causes not supported by the data.';
    }

    /**
     * @param  array{log_lines: string, metrics: array, summary: array}  $context
     */
    private function buildPrompt(array $context): string
    {
        $metrics = $context['metrics'];
        $metricsText = sprintf(
            'CPU: %s%%, DB latency: %s ms, Requests/sec: %s',
            $metrics['cpu'] ?? 'N/A',
            $metrics['db_latency'] ?? 'N/A',
            $metrics['requests_per_sec'] ?? 'N/A'
        );

        return "Cleaned logs (deduplicated, grouped by time window, severity marked):\n\n"
            .$context['log_lines']
            ."\n\nMetrics: ".$metricsText
            ."\n\nSuggest probable root cause and reasoning. Respond with JSON only: likely_cause, reasoning, next_steps, confidence (0-1).";
    }

    /**
     * When AI cannot be called: return minimal response with a clear reason (provider-specific).
     *
     * @param  int  $status  HTTP status from AI API (0 if not from HTTP)
     * @param  string  $detail  Response body or error message
     * @param  string  $provider  Provider that was hit: gemini, groq, grok, openai
     * @return array{likely_cause: string, reasoning: string, next_steps: string, confidence: float}
     */
    private function serviceUnavailableResponse(int $status = 0, string $detail = '', string $provider = ''): array
    {
        $name = match ($provider) {
            'gemini' => 'Gemini',
            'groq' => 'Groq',
            'grok' => 'Grok',
            'openai' => 'OpenAI',
            default => 'AI',
        };

        $cause = 'Analysis temporarily unavailable';
        $nextSteps = 'Check API key and network; retry later.';

        if ($status === 429) {
            $cause = $name.' rate limit (429) – '.config('ai.provider').' hit';
            $nextSteps = match ($provider) {
                'groq' => 'Groq: wait ~1 min (RPM limit). Then retry. No billing needed.',
                'gemini' => 'Gemini: wait ~1 min. Then retry. Free tier RPM limit.',
                'grok' => 'Grok: wait ~1 min. Then retry. Check rate limits at console.x.ai.',
                'openai' => 'OpenAI: check usage at platform.openai.com. Wait or add billing.',
                default => 'Wait 1 minute, then retry. Avoid many requests in a short time.',
            };
        } elseif ($status === 401 || $status === 400) {
            $cause = $name.' API key invalid or bad request ('.$status.')';
            $nextSteps = match ($provider) {
                'gemini' => 'Get key: https://aistudio.google.com/app/apikey. Set GEMINI_API_KEY.',
                'groq' => 'Get key: https://console.groq.com/keys. Set GROQ_API_KEY.',
                'grok' => 'Get key: https://console.x.ai/team/default/api-keys. Set GROK_API_KEY.',
                'openai' => 'Verify OPENAI_API_KEY in .env.',
                default => 'Verify API key in .env for '.config('ai.provider'),
            };
        } elseif ($status === 403) {
            $cause = $name.' access forbidden (403)';
            $nextSteps = 'Check API key and account permissions for '.$provider;
        } elseif ($status === 404) {
            $cause = $name.' model not found (404)';
            $nextSteps = $provider === 'gemini'
                ? 'Set GEMINI_MODEL=gemini-2.0-flash, GEMINI_URL with v1beta. Run: php artisan config:clear'
                : 'Check '.$provider.' model name in .env (GROQ_MODEL, GROK_MODEL, or OPENAI_MODEL).';
        } elseif (str_contains(strtolower($detail), 'quota') || str_contains(strtolower($detail), 'insufficient_quota')) {
            $cause = $name.' quota exceeded';
            $nextSteps = $provider === 'openai' ? 'Check platform.openai.com billing.' : 'Wait and retry, or try another provider.';
        } elseif (str_contains(strtolower($detail), 'api key not valid') || str_contains(strtolower($detail), 'invalid_argument')) {
            $cause = $name.' API key invalid';
            $nextSteps = match ($provider) {
                'gemini' => 'New key: https://aistudio.google.com/app/apikey. Set GEMINI_API_KEY. php artisan config:clear',
                'groq' => 'New key: https://console.groq.com/keys. Set GROQ_API_KEY.',
                'grok' => 'New key: https://console.x.ai. Set GROK_API_KEY.',
                default => 'Set valid API key for '.$provider.' in .env.',
            };
        }

        return [
            'likely_cause' => $cause,
            'reasoning' => 'AI service could not be reached (provider: '.($provider ?: config('ai.provider')).').',
            'next_steps' => $nextSteps,
            'confidence' => 0.0,
        ];
    }

    /**
     * Extract JSON from Gemini text (v1 has no responseMimeType; model may return raw JSON or markdown-wrapped).
     *
     * @return array<string, mixed>|null
     */
    private function parseJsonFromText(string $text): ?array
    {
        $text = trim($text);
        if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/', $text, $m)) {
            $text = trim($m[1]);
        } elseif (preg_match('/\{[\s\S]*\}/', $text, $m)) {
            $text = $m[0];
        }
        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Mitigate hallucinations: ensure cause/reasoning are grounded in log content.
     *
     * @param  array{likely_cause?: string, reasoning?: string, next_steps?: string, confidence?: float}  $parsed
     * @param  array{entries: array}  $preprocessed
     */
    private function groundAndSanitize(array $parsed, array $preprocessed): array
    {
        $cause = trim((string) ($parsed['likely_cause'] ?? 'Unknown'));
        $reasoning = trim((string) ($parsed['reasoning'] ?? ''));
        $nextSteps = trim((string) ($parsed['next_steps'] ?? 'Review logs and metrics.'));
        $confidence = (float) ($parsed['confidence'] ?? 0.5);
        $confidence = max(0, min(1, $confidence));

        $allLogText = '';
        foreach ($preprocessed['entries'] ?? [] as $e) {
            $allLogText .= ' '.($e['raw'] ?? '');
        }
        $allLogText = strtolower($allLogText);

        if ($cause !== 'Unknown' && $cause !== '') {
            $words = array_filter(explode(' ', strtolower($cause)), fn ($w) => strlen($w) > 2);
            $grounded = 0;
            foreach ($words as $w) {
                if (str_contains($allLogText, $w) || str_contains($allLogText, 'db') && in_array($w, ['database', 'connection', 'timeout'], true)) {
                    $grounded++;
                }
            }
            if ($grounded === 0 && count($words) > 2) {
                $confidence = max(0.3, $confidence - 0.2);
            }
        }

        return [
            'likely_cause' => $cause !== '' ? $cause : 'Unknown',
            'reasoning' => $reasoning,
            'next_steps' => $nextSteps !== '' ? $nextSteps : 'Review logs and metrics.',
            'confidence' => $confidence,
        ];
    }
}
