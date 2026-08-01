<?php

namespace App\Reports;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** The window and the limits a report was asked for. */
final class ReportParams
{
    public function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly int $days = 30,
        public readonly int $months = 12,
        public readonly ?string $date = null,
        public readonly int $limit = 25,
    ) {}

    /** Reads the period straight off the request, with sane defaults. */
    public static function fromRequest(Request $request): self
    {
        return new self(
            from: $request->date('from') ?? today()->startOfMonth(),
            to: $request->date('to') ?? today()->endOfDay(),
            days: max(1, min(730, $request->integer('days', 30))),
            months: max(1, min(60, $request->integer('months', 12))),
            date: $request->query('date'),
            limit: max(1, min(500, $request->integer('limit', 25))),
        );
    }
}
