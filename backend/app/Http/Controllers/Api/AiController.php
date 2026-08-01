<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiInsight;
use App\Services\Ai\AiAssistant;
use App\Services\Ai\AiInsightService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The smart module: insights, forecasts and the management chat assistant. */
class AiController extends Controller
{
    public function __construct(
        private readonly AiInsightService $insights,
        private readonly AiAssistant $assistant,
    ) {}

    public function overview(): JsonResponse
    {
        $this->authorize('ai.view');

        return response()->json([
            'assistant_available' => $this->assistant->available(),
            'forecast' => $this->insights->revenueForecast(),
            'attendance' => $this->insights->attendanceAnalysis(),
            'churn_risk' => $this->insights->churnRisk(10)->map(fn ($risk) => [
                'member' => $risk['member']->only(['id', 'code', 'first_name', 'last_name', 'phone', 'photo_path']),
                'score' => $risk['score'],
                'severity' => $risk['severity'],
                'days_since_last_visit' => $risk['days_since_last_visit'],
                'reasons' => $risk['reasons'],
            ]),
            'campaign_suggestions' => $this->insights->campaignSuggestions(),
        ]);
    }

    public function churnRisk(Request $request): JsonResponse
    {
        $this->authorize('ai.view');

        return response()->json(
            $this->insights->churnRisk($request->integer('limit', 50))
                ->map(fn ($risk) => collect($risk)->merge([
                    'member' => $risk['member']->only(['id', 'code', 'first_name', 'last_name', 'phone', 'photo_path']),
                ])->all())
        );
    }

    public function forecast(): JsonResponse
    {
        $this->authorize('ai.view');

        return response()->json($this->insights->revenueForecast());
    }

    public function insights(Request $request): JsonResponse
    {
        $this->authorize('ai.view');

        return response()->json(
            AiInsight::query()
                ->when($request->query('type'), fn ($q, $type) => $q->where('type', $type))
                ->whereNull('dismissed_at')
                ->latest('generated_at')
                ->paginate($request->integer('per_page', 25))
        );
    }

    public function refresh(): JsonResponse
    {
        $this->authorize('ai.update');

        return response()->json(['generated' => $this->insights->refresh()]);
    }

    public function dismiss(AiInsight $insight): JsonResponse
    {
        $this->authorize('ai.update');

        $insight->update(['dismissed_at' => now()]);

        return response()->json(['message' => __('general.dismissed')]);
    }

    /** Chat with the assistant about this club's numbers. */
    public function chat(Request $request): JsonResponse
    {
        $this->authorize('ai.assistant');

        $data = $request->validate([
            'question' => ['required', 'string', 'max:2000'],
            'history' => ['nullable', 'array', 'max:20'],
            'history.*.role' => ['required', 'in:user,assistant'],
            'history.*.content' => ['required', 'string', 'max:8000'],
        ]);

        return response()->json($this->assistant->ask($data['question'], $data['history'] ?? []));
    }
}
