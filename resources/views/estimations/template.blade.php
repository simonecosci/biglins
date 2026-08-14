@php
    $logoPath = $estimation->company->logo ? public_path($estimation->company->logo) : null;
    $logoData = $logoPath && file_exists($logoPath)
        ? 'data:' . mime_content_type($logoPath) . ';base64,' . base64_encode(file_get_contents($logoPath))
        : null;

    $statusLabel = match ($estimation->status) {
        \App\Enums\EstimationStatus::Accepted => __('estimation.status_accepted'),
        \App\Enums\EstimationStatus::Rejected => __('estimation.status_rejected'),
        \App\Enums\EstimationStatus::Pending => __('estimation.status_pending'),
    };
@endphp
<!DOCTYPE html>
<html lang="{{ $estimation->language }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('estimation.title') }} {{ $estimation->number }}</title>
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
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-accepted { background: #dcfce7; color: #166534; }
        .badge-rejected { background: #fee2e2; color: #991b1b; }
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
        .proposal { margin-top: 40px; padding-top: 12px; border-top: 1px solid #e5e7eb; }
        .proposal h2 { font-size: 11px; text-transform: uppercase; color: #6b7280; margin: 0 0 4px; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td>
                @if($logoData)
                    <img class="logo" src="{{ $logoData }}" alt="{{ $estimation->company->name }}">
                @endif
                <div class="company">
                    <strong>{{ $estimation->company->name }}</strong><br>
                    {{ $estimation->company->address }}<br>
                    {{ $estimation->company->zip }} {{ $estimation->company->city }}, {{ $estimation->company->country?->name }}<br>
                    {{ __('estimation.tax_id') }}: {{ $estimation->company->tax_id }}<br>
                    {{ $estimation->company->email }} &mdash; {{ $estimation->company->phone }}
                </div>
            </td>
            <td class="customer">
                <h2>{{ __('estimation.customer') }}</h2>
                <div>
                    <strong>{{ $estimation->customer->name }}</strong><br>
                    @if($estimation->customer->address)
                        {{ $estimation->customer->address }}<br>
                    @endif
                    @if($estimation->customer->zip || $estimation->customer->city)
                        {{ $estimation->customer->zip }} {{ $estimation->customer->city }}<br>
                    @endif
                    @if($estimation->customer->country)
                        {{ $estimation->customer->country->name }}<br>
                    @endif
                    @if($estimation->customer->nif)
                        {{ __('estimation.tax_id') }}: {{ $estimation->customer->nif }}<br>
                    @endif
                </div>
            </td>
            <td class="meta">
                <h1>{{ __('estimation.title') }}</h1>
                <div>{{ __('estimation.number') }}: {{ $estimation->number }}</div>
                <div>{{ __('estimation.date') }}: {{ $estimation->estimation_date->format('d/m/Y') }}</div>
                <div>{{ __('estimation.expiration_date') }}: {{ $estimation->expiration_date->format('d/m/Y') }}</div>
                <div class="badge badge-{{ $estimation->status->value }}">{{ $statusLabel }}</div>
            </td>
        </tr>
    </table>

    <table class="rows">
        <thead>
            <tr>
                <th>{{ __('estimation.description') }}</th>
                <th class="num">{{ __('estimation.quantity') }}</th>
                <th class="num">{{ __('estimation.price') }}</th>
                <th class="num">{{ __('estimation.vat') }}</th>
                <th class="num">{{ __('estimation.total') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($estimation->rows as $row)
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
            <td>{{ __('estimation.subtotal') }}</td>
            <td class="num">{{ number_format($estimation->subtotal, 2) }}</td>
        </tr>
        <tr>
            <td>{{ __('estimation.vat') }}</td>
            <td class="num">{{ number_format($estimation->vat_total, 2) }}</td>
        </tr>
        <tr class="total">
            <td>{{ __('estimation.total') }}</td>
            <td class="num">{{ number_format($estimation->total, 2) }}</td>
        </tr>
    </table>

    @if($bodyHtml !== '')
        <div id="proposal" class="proposal">
            <h2>{{ __('estimation.proposal') }}</h2>
            <div>{!! $bodyHtml !!}</div>
        </div>
    @endif
</body>
</html>
