<?php

namespace App\Reports\Concerns;

use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The handful of shapes every report is built from: a breakdown, a daily
 * series and a monthly series. Keeping them here means a new report is a
 * query plus one call, not another twenty lines of grouping.
 */
trait AggregatesRows
{
    /**
     * Counts or sums rows per value of one column.
     *
     * @return array<int, array{label:string, total:float|int}>
     */
    protected function breakdown(Builder|QueryBuilder $query, string $column, ?string $sum = null): array
    {
        $aggregate = $sum ? "sum({$sum})" : 'count(*)';

        return $query
            ->select($column, DB::raw("{$aggregate} as report_total"))
            ->groupBy($column)
            ->orderByDesc('report_total')
            ->get()
            ->map(fn ($row) => [
                'label' => (string) ($row->{$this->columnAlias($column)} ?? '—'),
                'total' => $sum ? round((float) $row->report_total, 2) : (int) $row->report_total,
            ])
            ->all();
    }

    /**
     * A dense day-by-day series — every day in the window appears, including
     * the ones with nothing in them, so a chart never draws a false gap.
     *
     * @return array<int, array{date:string, total:float|int}>
     */
    protected function dailySeries(Builder|QueryBuilder $query, string $dateColumn, int $days, ?string $sum = null): array
    {
        $aggregate = $sum ? "sum({$sum})" : 'count(*)';

        $rows = $query
            ->where($dateColumn, '>=', today()->subDays($days - 1)->startOfDay())
            ->select(DB::raw("date({$dateColumn}) as report_day"), DB::raw("{$aggregate} as report_total"))
            ->groupBy('report_day')
            ->pluck('report_total', 'report_day');

        return collect(range($days - 1, 0))
            ->map(function (int $offset) use ($rows, $sum) {
                $day = today()->subDays($offset)->toDateString();
                $value = $rows[$day] ?? 0;

                return ['date' => $day, 'total' => $sum ? round((float) $value, 2) : (int) $value];
            })
            ->all();
    }

    /**
     * The same idea by calendar month, for year-on-year style reports.
     *
     * @return array<int, array{month:string, total:float|int}>
     */
    protected function monthlySeries(Builder|QueryBuilder $query, string $dateColumn, int $months = 12, ?string $sum = null): array
    {
        $aggregate = $sum ? "sum({$sum})" : 'count(*)';
        $start = today()->startOfMonth()->subMonths($months - 1);

        $rows = $query
            ->where($dateColumn, '>=', $start)
            ->select(DB::raw($this->monthExpression($dateColumn).' as report_month'), DB::raw("{$aggregate} as report_total"))
            ->groupBy('report_month')
            ->pluck('report_total', 'report_month');

        return collect(range($months - 1, 0))
            ->map(function (int $offset) use ($rows, $sum) {
                $month = today()->startOfMonth()->subMonths($offset)->format('Y-m');
                $value = $rows[$month] ?? 0;

                return ['month' => $month, 'total' => $sum ? round((float) $value, 2) : (int) $value];
            })
            ->all();
    }

    /** SQLite and MySQL spell "the month of this timestamp" differently. */
    protected function monthExpression(string $column): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', {$column})"
            : "date_format({$column}, '%Y-%m')";
    }

    /** Turns a grouped collection into a plain list of labelled rows. */
    protected function labelled(Collection $groups, callable $shape): array
    {
        return $groups->map($shape)->values()->all();
    }

    protected function columnAlias(string $column): string
    {
        return str_contains($column, '.') ? substr($column, strrpos($column, '.') + 1) : $column;
    }
}
