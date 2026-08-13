<?php

namespace App\Http\Controllers;

use App\Enums\ExpirationUrgency;
use App\Models\Invoice;
use App\Models\InvoiceRow;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $currentCompanyId = CurrentCompany::resolve()?->id;

        $today = Carbon::today();

        $yearToDateRevenue = Invoice::query()
            ->where('company_id', $currentCompanyId)
            ->whereBetween('invoice_date', [$today->copy()->startOfYear(), $today])
            ->with('rows')
            ->get()
            ->sum('total');

        $rows = InvoiceRow::query()
            ->subscriptions()
            ->whereHas('invoice', fn ($query) => $query->where('company_id', $currentCompanyId))
            ->with('invoice.customer')
            ->orderBy('expiration_date')
            ->get();

        $groups = $rows
            ->groupBy('invoice_id')
            ->map(function ($rows) {
                $invoice = $rows->first()->invoice;
                $urgencies = $rows->map->expiration_urgency;

                return [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->number,
                    'customer_name' => $invoice->customer?->name,
                    'status' => match (true) {
                        $urgencies->contains(ExpirationUrgency::Expired) => ExpirationUrgency::Expired->value,
                        $urgencies->contains(ExpirationUrgency::ExpiringSoon) => ExpirationUrgency::ExpiringSoon->value,
                        default => ExpirationUrgency::Upcoming->value,
                    },
                    'total' => (float) $rows->sum(fn (InvoiceRow $row): float => $row->price * $row->quantity),
                    'rows' => $rows->map(fn (InvoiceRow $row): array => [
                        'id' => $row->id,
                        'description' => $row->description,
                        'price' => $row->price,
                        'quantity' => $row->quantity,
                        'expiration_date' => $row->expiration_date->format('Y-m-d'),
                        'urgency' => $row->expiration_urgency->value,
                    ])->values(),
                ];
            })
            ->values();

        return Inertia::render('Dashboard', [
            'revenue' => [
                'year' => $today->year,
                'yearToDate' => (float) $yearToDateRevenue,
            ],
            'subscriptions' => [
                'expiredCount' => $rows->filter(fn (InvoiceRow $row): bool => $row->expiration_urgency === ExpirationUrgency::Expired)->count(),
                'expiringSoonCount' => $rows->filter(fn (InvoiceRow $row): bool => $row->expiration_urgency === ExpirationUrgency::ExpiringSoon)->count(),
                'groups' => $groups,
            ],
        ]);
    }
}
