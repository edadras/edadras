<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Reports\ReportParams;
use App\Reports\ReportRegistry;
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
        private readonly ReportRegistry $registry,
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

    /** Everything the reports screen can offer, grouped for the sidebar. */
    public function catalogue(): JsonResponse
    {
        $this->authorize('reports.view');

        $reports = collect($this->registry->catalogue())
            ->map(fn (array $report) => $report + ['label' => __("reports.{$report['key']}")]);

        return response()->json([
            'reports' => $reports->values()->all(),
            'groups' => collect($this->registry->groups())
                ->map(fn (string $group) => ['key' => $group, 'label' => __("reports.group_{$group}")])
                ->all(),
            'total' => $reports->count(),
        ]);
    }

    /** Runs one report by key, with the period the screen selected. */
    public function show(Request $request, string $key): JsonResponse
    {
        $this->authorize('reports.view');

        $params = ReportParams::fromRequest($request);

        return response()->json([
            'key' => $key,
            'label' => __("reports.{$key}"),
            'period' => ['from' => $params->from->toDateString(), 'to' => $params->to->toDateString()],
            'data' => $this->registry->run($key, $params),
        ]);
    }

    /** Same reports, as a CSV the accountant can open in a spreadsheet. */
    public function export(Request $request, string $key): StreamedResponse|Response
    {
        $this->authorize('reports.export');

        $rows = $this->flatten($this->registry->run($key, ReportParams::fromRequest($request)));

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
        if ($payload instanceof \Illuminate\Support\Collection) {
            $payload = $payload->all();
        }

        if (! is_array($payload)) {
            return [];
        }

        // A list of rows exports as is; a single map exports as one row.
        if (array_is_list($payload)) {
            return collect($payload)
                ->map(fn ($row) => is_array($row) ? $row : ['value' => $row])
                ->all();
        }

        $firstList = collect($payload)->first(fn ($value) => is_array($value) && array_is_list($value) && $value !== []);

        if ($firstList) {
            return collect($firstList)->map(fn ($row) => is_array($row) ? $row : ['value' => $row])->all();
        }

        return [collect($payload)->map(fn ($value) => is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value)->all()];
    }
}
