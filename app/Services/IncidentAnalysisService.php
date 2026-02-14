<?php

namespace App\Services;

use App\Exceptions\AnalysisUnavailableException;
use App\Models\Incident;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates the full analyze flow: store → preprocess → AI → decision layer → persist & return.
 * Responses are generated automatically by AI. Use GEMINI_API_KEY (free) or OPENAI_API_KEY.
 */
class IncidentAnalysisService
{
    public function __construct(
        private LogPreprocessor $preprocessor,
        private AiAnalysisService $aiAnalysis,
        private DecisionLayer $decisionLayer
    ) {}

    /**
     * Run analysis on submitted logs and metrics; store in DB and return final response.
     * Requires GEMINI_API_KEY (free at aistudio.google.com) or OPENAI_API_KEY.
     *
     * @param  array<int, string>  $logs
     * @param  array{cpu?: int, db_latency?: int, requests_per_sec?: string}  $metrics
     * @return array{likely_cause: string, confidence: float, next_steps: string, incident_id: int}
     *
     * @throws AnalysisUnavailableException When no AI API key is set
     */
    public function analyze(array $logs, array $metrics): array
    {
        $hasGemini = (bool) config('services.gemini.key');
        $hasGroq = (bool) config('services.groq.key');
        $hasGrok = (bool) config('services.grok.key');
        $hasOpenAi = (bool) config('services.openai.key');
        if (! $hasGemini && ! $hasGroq && ! $hasGrok && ! $hasOpenAi) {
            throw new AnalysisUnavailableException(
                'Set GEMINI_API_KEY (free), GROQ_API_KEY (console.groq.com), GROK_API_KEY, or OPENAI_API_KEY in .env for AI analysis.'
            );
        }

        $preprocessed = $this->preprocessor->preprocess($logs);

        $incident = null;
        $metricModel = null;

        DB::transaction(function () use ($logs, $preprocessed, $metrics, &$incident, &$metricModel) {
            $incident = Incident::create([
                'logs' => $logs,
                'preprocessed' => $preprocessed,
            ]);

            $metricModel = $incident->metric()->create([
                'cpu' => $metrics['cpu'] ?? null,
                'db_latency' => $metrics['db_latency'] ?? null,
                'requests_per_sec' => $metrics['requests_per_sec'] ?? null,
            ]);
        });

        $metricsSummary = [
            'cpu' => $metricModel->cpu,
            'db_latency' => $metricModel->db_latency,
            'requests_per_sec' => $metricModel->requests_per_sec,
        ];

        $aiResponse = $this->aiAnalysis->suggestCauses($preprocessed, $metricsSummary);
        $final = $this->decisionLayer->decide($aiResponse, $preprocessed, $metricModel);

        $incident->update([
            'likely_cause' => $final['likely_cause'],
            'confidence' => $final['confidence'],
            'next_steps' => $final['next_steps'],
        ]);

        return [
            'likely_cause' => $final['likely_cause'],
            'confidence' => $final['confidence'],
            'next_steps' => $final['next_steps'],
            'incident_id' => $incident->id,
        ];
    }
}
