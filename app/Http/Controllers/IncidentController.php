<?php

namespace App\Http\Controllers;

use App\Exceptions\AnalysisUnavailableException;
use App\Http\Requests\AnalyzeRequest;
use App\Services\IncidentAnalysisService;
use Illuminate\Http\JsonResponse;

class IncidentController extends Controller
{
    public function __construct(
        private IncidentAnalysisService $analysisService
    ) {}

    /**
     * POST /analyze — Log intake + metrics, AI analysis (automatic), decision layer, final response.
     * Always returns JSON. Requires OPENAI_API_KEY for analysis.
     */
    public function analyze(AnalyzeRequest $request): JsonResponse
    {
        try {
            $valid = $request->validated();
            $logs = $valid['logs'];
            $metrics = $valid['metrics'] ?? [];

            $result = $this->analysisService->analyze($logs, $metrics);

            $provider = strtolower(trim((string) (config('ai.provider') ?? 'unknown')));

            return response()->json([
                'success' => true,
                'data' => array_merge($result, ['provider' => $provider ?: 'unknown']),
            ], 200);
        } catch (AnalysisUnavailableException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
            ], 503);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Analysis failed. Please try again.',
                'data' => null,
            ], 500);
        }
    }
}
