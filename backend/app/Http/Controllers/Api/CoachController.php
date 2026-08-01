<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Coach;
use App\Models\Transaction;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CoachController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('coaches.view');

        return response()->json(
            Coach::query()
                ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
                ->withCount('sessions')
                ->orderBy('last_name')
                ->paginate($request->integer('per_page', 25))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('coaches.create');

        return response()->json(Coach::create($this->validated($request)), 201);
    }

    public function show(Coach $coach): JsonResponse
    {
        $this->authorize('coaches.view');

        return response()->json($coach->load('classes', 'user'));
    }

    public function update(Request $request, Coach $coach): JsonResponse
    {
        $this->authorize('coaches.update');

        $coach->update($this->validated($request, $coach));

        return response()->json($coach->fresh());
    }

    public function destroy(Coach $coach): JsonResponse
    {
        $this->authorize('coaches.delete');

        $coach->delete();

        return response()->json(['message' => __('general.deleted')]);
    }

    /** Sessions run, seats filled and revenue generated in a period. */
    public function performance(Request $request, ReportService $reports): JsonResponse
    {
        $this->authorize('reports.view');

        $from = $request->date('from') ?? today()->startOfMonth();
        $to = $request->date('to') ?? today()->endOfMonth();

        return response()->json($reports->coachPerformance($from, $to));
    }

    /** Records a salary or commission payment as a cash box expense. */
    public function paySalary(Request $request, Coach $coach): JsonResponse
    {
        $this->authorize('accounting.create');

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0'],
            'method' => ['nullable', Rule::in(['cash', 'card', 'transfer'])],
            'description' => ['nullable', 'string', 'max:500'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        $transaction = Transaction::create([
            'type' => Transaction::EXPENSE,
            'category' => 'salary',
            'amount' => $data['amount'],
            'method' => $data['method'] ?? 'cash',
            'description' => $data['description'] ?? __('general.salary_for', ['name' => $coach->full_name]),
            'reference_type' => Coach::class,
            'reference_id' => $coach->id,
            'user_id' => $request->user()->id,
            'occurred_at' => $data['occurred_at'] ?? now(),
        ]);

        return response()->json($transaction, 201);
    }

    protected function validated(Request $request, ?Coach $coach = null): array
    {
        return $request->validate([
            'user_id' => ['nullable', 'exists:users,id'],
            'first_name' => [$coach ? 'sometimes' : 'required', 'string', 'max:80'],
            'last_name' => [$coach ? 'sometimes' : 'required', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:190'],
            'specialties' => ['nullable', 'array'],
            'specialties.*' => ['string', 'max:60'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'contract_type' => ['nullable', Rule::in(['fixed', 'percentage', 'per_session'])],
            'salary_amount' => ['nullable', 'numeric', 'min:0'],
            'commission_percent' => ['nullable', 'numeric', 'between:0,100'],
            'hired_at' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);
    }
}
