@php
    // DomPDF does no Arabic shaping of its own, so every Persian string on
    // this page is joined and reordered on the way in.
    $rtl = fn (?string $value) => \App\Support\PersianText::shape($value);
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->number }}</title>
    <style>
        /* DejaVu Sans covers the Persian and Arabic blocks, so the glyphs
           are there. What DomPDF will not do is join them or run them right
           to left — that is what the rtl() helper below is for, and every
           Persian string on this page goes through it.

           A club that prefers its own typeface drops a TTF into
           storage/fonts and registers it in config/dompdf.php; the shaping
           still applies. */
        @page { margin: 24px 28px; }
        body {
            font-family: 'vazirmatn', 'DejaVu Sans', sans-serif;
            font-size: 12px;
            color: #16181d;
            direction: {{ $direction }};
        }
        .header { border-bottom: 2px solid #5EF38C; padding-bottom: 12px; margin-bottom: 18px; }
        .club-name { font-size: 20px; font-weight: bold; }
        .muted { color: #6b7280; font-size: 11px; }
        .row { width: 100%; }
        .col { display: inline-block; vertical-align: top; width: 48%; }
        .align-end { text-align: {{ $direction === 'rtl' ? 'left' : 'right' }}; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { padding: 8px 6px; border-bottom: 1px solid #e5e7eb; text-align: {{ $direction === 'rtl' ? 'right' : 'left' }}; }
        th { background: #f3f4f6; font-weight: bold; font-size: 11px; text-transform: uppercase; }
        tfoot td { border-bottom: none; }
        .total-row td { font-size: 14px; font-weight: bold; border-top: 2px solid #16181d; }
        .badge {
            display: inline-block; padding: 3px 10px; border-radius: 999px;
            font-size: 11px; background: #5EF38C; color: #0d0f12;
        }
        .badge.unpaid { background: #fca5a5; }
        .badge.partial { background: #fcd34d; }
        .footer { margin-top: 28px; font-size: 10px; color: #9ca3af; text-align: center; }
    </style>
</head>
<body>

<div class="header">
    <div class="row">
        <div class="col">
            <div class="club-name">{{ $rtl($club->name) }}</div>
            <div class="muted">
                {{ $rtl($club->address) }}<br>
                {{ $club->phone }} @if($club->email) · {{ $club->email }} @endif
            </div>
        </div>
        <div class="col align-end">
            <div style="font-size:16px; font-weight:bold;">{{ $invoice->number }}</div>
            <div class="muted">{{ $invoice->issued_at->format('Y-m-d H:i') }}</div>
            <div style="margin-top:6px;">
                <span class="badge {{ $invoice->status }}">{{ $rtl(__('invoice.status_'.$invoice->status)) }}</span>
            </div>
        </div>
    </div>
</div>

@if($invoice->member)
    <div>
        <strong>{{ $rtl($invoice->member->full_name) }}</strong>
        <div class="muted">
            #{{ $invoice->member->code }} · {{ $invoice->member->phone }}
        </div>
    </div>
@endif

<table>
    <thead>
        <tr>
            <th style="width:50%">{{ $rtl(__('invoice.item')) }}</th>
            <th>{{ $rtl(__('invoice.qty')) }}</th>
            <th>{{ $rtl(__('invoice.unit_price')) }}</th>
            <th>{{ $rtl(__('invoice.discount')) }}</th>
            <th class="align-end">{{ $rtl(__('invoice.total')) }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach($invoice->items as $item)
            <tr>
                <td>{{ $rtl($item->description) }}</td>
                <td>{{ (float) $item->quantity }}</td>
                <td>{{ number_format((float) $item->unit_price) }}</td>
                <td>{{ number_format((float) $item->discount) }}</td>
                <td class="align-end">{{ number_format((float) $item->total) }}</td>
            </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr>
            <td colspan="4" class="align-end">{{ $rtl(__('invoice.subtotal')) }}</td>
            <td class="align-end">{{ number_format((float) $invoice->subtotal) }}</td>
        </tr>
        @if((float) $invoice->discount > 0)
            <tr>
                <td colspan="4" class="align-end">{{ $rtl(__('invoice.discount')) }}</td>
                <td class="align-end">-{{ number_format((float) $invoice->discount) }}</td>
            </tr>
        @endif
        @if((float) $invoice->tax > 0)
            <tr>
                <td colspan="4" class="align-end">{{ $rtl(__('invoice.tax')) }}</td>
                <td class="align-end">{{ number_format((float) $invoice->tax) }}</td>
            </tr>
        @endif
        <tr class="total-row">
            <td colspan="4" class="align-end">{{ $rtl(__('invoice.total')) }} ({{ $club->currency }})</td>
            <td class="align-end">{{ number_format((float) $invoice->total) }}</td>
        </tr>
        <tr>
            <td colspan="4" class="align-end">{{ $rtl(__('invoice.paid')) }}</td>
            <td class="align-end">{{ number_format((float) $invoice->paid_amount) }}</td>
        </tr>
        @if($invoice->balance() > 0)
            <tr>
                <td colspan="4" class="align-end">{{ $rtl(__('invoice.balance_due')) }}</td>
                <td class="align-end">{{ number_format($invoice->balance()) }}</td>
            </tr>
        @endif
    </tfoot>
</table>

@if($invoice->payments->isNotEmpty())
    <table>
        <thead>
            <tr><th>{{ $rtl(__('invoice.paid_at')) }}</th><th>{{ $rtl(__('invoice.method')) }}</th><th class="align-end">{{ $rtl(__('invoice.amount')) }}</th></tr>
        </thead>
        <tbody>
            @foreach($invoice->payments as $payment)
                <tr>
                    <td>{{ $payment->paid_at?->format('Y-m-d H:i') }}</td>
                    <td>{{ $rtl(__('invoice.method_'.$payment->method)) }}</td>
                    <td class="align-end">{{ number_format((float) $payment->amount) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

@if($invoice->notes)
    <p class="muted" style="margin-top:16px;">{{ $rtl($invoice->notes) }}</p>
@endif

<div class="footer">GymFlow AI · {{ $rtl($club->name) }}</div>

</body>
</html>
