@php
    $logoPath = $invoice->company->logo ? public_path($invoice->company->logo) : null;
    $logoData = $logoPath && file_exists($logoPath)
        ? 'data:' . mime_content_type($logoPath) . ';base64,' . base64_encode(file_get_contents($logoPath))
        : null;
    $documentTitle = $invoice->isCreditNote() ? __('invoice.credit_note_title') : __('invoice.title');
@endphp
<!DOCTYPE html>
<html lang="{{ $invoice->language }}">
<head>
    <meta charset="utf-8">
    <title>{{ $documentTitle }} {{ $invoice->number }}</title>
    <style>
        body { font-family: Helvetica, Arial, sans-serif; font-size: 12px; color: #1f2937; margin: 40px; }
        table { border-collapse: collapse; }
        .header { width: 100%; margin-bottom: 30px; }
        .header td { vertical-align: top; }
        .logo { max-width: 160px; max-height: 80px; }
        .company { font-size: 11px; line-height: 1.5; margin-top: 8px; }
        .company strong { font-size: 14px; }
        .meta { text-align: right; }
        .meta h1 { font-size: 20px; margin: 0 0 8px; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 10px; }
        .badge-paid { background: #dcfce7; color: #166534; }
        .badge-unpaid { background: #fee2e2; color: #991b1b; }
        .customer { padding: 0 16px; font-size: 11px; line-height: 1.5; }
        .customer h2 { font-size: 11px; text-transform: uppercase; color: #6b7280; margin: 0 0 4px; }
        table.rows { width: 100%; margin-bottom: 20px; }
        table.rows th { text-align: left; border-bottom: 2px solid #1f2937; padding: 6px 4px; font-size: 11px; text-transform: uppercase; }
        table.rows td { border-bottom: 1px solid #e5e7eb; padding: 6px 4px; }
        table.rows td.num, table.rows th.num { text-align: right; }
        table.totals { width: 260px; margin-left: auto; }
        table.totals td { padding: 4px; }
        table.totals td.num { text-align: right; }
        table.totals tr.total td { font-weight: bold; font-size: 14px; border-top: 2px solid #1f2937; }
        .notes { margin-top: 40px; padding-top: 12px; border-top: 1px solid #e5e7eb; }
        .notes h2 { font-size: 11px; text-transform: uppercase; color: #6b7280; margin: 0 0 4px; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td>
                @if($logoData)
                    <img class="logo" src="{{ $logoData }}" alt="{{ $invoice->company->name }}">
                @endif
                <div class="company">
                    <strong>{{ $invoice->company->name }}</strong><br>
                    {{ $invoice->company->address }}<br>
                    {{ $invoice->company->zip }} {{ $invoice->company->city }}, {{ $invoice->company->country?->name }}<br>
                    {{ __('invoice.tax_id') }}: {{ $invoice->company->tax_id }}<br>
                    {{ $invoice->company->email }} &mdash; {{ $invoice->company->phone }}
                </div>
            </td>
            <td class="customer">
                <h2>{{ __('invoice.customer') }}</h2>
                <div>
                    <strong>{{ $invoice->customer->name }}</strong><br>
                    @if($invoice->customer->address)
                        {{ $invoice->customer->address }}<br>
                    @endif
                    @if($invoice->customer->zip || $invoice->customer->city)
                        {{ $invoice->customer->zip }} {{ $invoice->customer->city }}<br>
                    @endif
                    @if($invoice->customer->country)
                        {{ $invoice->customer->country->name }}<br>
                    @endif
                    @if($invoice->customer->nif)
                        {{ __('invoice.tax_id') }}: {{ $invoice->customer->nif }}<br>
                    @endif
                </div>
            </td>
            <td class="meta">
                <h1>{{ $documentTitle }}</h1>
                <div>{{ __('invoice.number') }}: {{ $invoice->number }}</div>
                <div>{{ __('invoice.date') }}: {{ $invoice->invoice_date->format('d/m/Y') }}</div>
            </td>
        </tr>
    </table>

    <table class="rows">
        <thead>
            <tr>
                <th>{{ __('invoice.description') }}</th>
                <th class="num">{{ __('invoice.quantity') }}</th>
                <th class="num">{{ __('invoice.price') }}</th>
                <th class="num">{{ __('invoice.vat') }}</th>
                <th class="num">{{ __('invoice.total') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->rows as $row)
                <tr>
                    <td>{{ $row->description }}</td>
                    <td class="num">{{ number_format((float) $row->quantity, 2) }}</td>
                    <td class="num">{{ number_format((float) $row->price, 2) }}</td>
                    <td class="num">{{ number_format((float) $row->vat_rate, 2) }}%</td>
                    <td class="num">{{ number_format((float) $row->total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td>{{ __('invoice.subtotal') }}</td>
            <td class="num">{{ number_format($invoice->subtotal, 2) }}</td>
        </tr>
        <tr>
            <td>{{ __('invoice.vat') }}</td>
            <td class="num">{{ number_format($invoice->vat_total, 2) }}</td>
        </tr>
        <tr class="total">
            <td>{{ __('invoice.total') }}</td>
            <td class="num">{{ number_format($invoice->total, 2) }}</td>
        </tr>
    </table>

    @if($invoice->note)
        <div id="notes" class="notes">
            <h2>{{ __('invoice.note') }}</h2>
            <div>{{ $invoice->note }}</div>
        </div>
    @endif
</body>
</html>
