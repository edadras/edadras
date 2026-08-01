<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MembershipService;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly MembershipService $memberships,
    ) {}

    /** The manager app home screen. */
    public function dashboard(): JsonResponse
    {
        $this->authorize('dashboard.view');

        // Passes that ran out overnight should not be counted as active.
        $this->memberships->expireOverdue();

        return response()->json($this->reports->dashboard());
    }

    /** Everything the reports screen can offer. */
    public function catalogue(): JsonResponse
    {
        $this->authorize('reports.view');

        return response()->json(
            collect($this->reports->catalogue())
                ->map(fn (array $report) => $report + ['label' => __("reports.{$report['key']}")])
                ->all()
        );
    }

    /** Runs one report by key, with the period the screen selected. */
    public function show(Request $request, string $key): JsonResponse
    {
        $this->authorize('reports.view');

        $from = $request->date('from') ?? today()->startOfMonth();
        $to = $request->date('to') ?? today()->endOfDay();
        $days = $request->integer('days', 30);

        $data = match ($key) {
            'dashboard' => $this->reports->dashboard(),
            'daily_register' => $this->reports->dailyRegister($request->query('date')),
            'profit_and_loss' => $this->reports->profitAndLoss($from, $to),
            'revenue_by_category' => $this->reports->revenueByCategory($from, $to),
            'revenue_by_method' => $this->reports->revenueByMethod($from, $to),
            'expense_by_category' => $this->reports->expenseByCategory($from, $to),
            'revenue_series' => $this->reports->revenueSeries($days),
            'product_sales' => $this->reports->productSales($from, $to),
            'low_stock' => $this->reports->lowStockProducts(),
            'inactive_members' => $this->reports->inactiveMembers($days),
            'expiring_memberships' => $this->reports->expiringMemberships($request->integer('days', 7)),
            'top_members_by_attendance' => $this->reports->topMembersByAttendance($days),
            'membership_mix' => $this->reports->membershipMix(),
            'gender_split' => $this->reports->genderSplit(),
            'attendance_series' => $this->reports->attendanceSeries($days),
            'attendance_by_hour' => $this->reports->attendanceByHour($days),
            'coach_performance' => $this->reports->coachPerformance($from, $to),
            'class_occupancy' => $this->reports->classOccupancy($from, $to),
            default => abort(404, __('reports.unknown')),
        };

        return response()->json([
            'key' => $key,
            'label' => __("reports.{$key}"),
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'data' => $data,
        ]);
    }

    /** Same reports, as a CSV the accountant can open in a spreadsheet. */
    public function export(Request $request, string $key): StreamedResponse|Response
    {
        $this->authorize('reports.export');

        $payload = $this->show($request, $key)->getData(true)['data'];
        $rows = $this->flatten($payload);

        if ($rows === []) {
            return response('', 204);
        }

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF"); // BOM, so Excel reads Persian correctly
            fputcsv($handle, array_keys($rows[0]));

            foreach ($rows as $row) {
                fputcsv($handle, array_map(fn ($value) => is_scalar($value) || $value === null ? $value : json_encode($value, JSON_UNESCAPED_UNICODE), $row));
            }

            fclose($handle);
        }, "{$key}-".today()->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<int, array<string, mixed>> */
    protected function flatten(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        // A list of rows exports as is; a single map exports as one row.
        if (array_is_list($payload)) {
            return collect($payload)
                ->map(fn ($row) => is_array($row) ? $row : ['value' => $row])
                ->all();
        }

        $firstList = collect($payload)->first(fn ($value) => is_array($value) && array_is_list($value));

        if ($firstList) {
            return collect($firstList)->map(fn ($row) => is_array($row) ? $row : ['value' => $row])->all();
        }

        return [collect($payload)->map(fn ($value) => is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value)->all()];
    }
}
