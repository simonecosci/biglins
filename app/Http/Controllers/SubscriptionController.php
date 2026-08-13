<?php

namespace App\Http\Controllers;

use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Concerns\ScopesToCurrentCompany;
use App\Models\Invoice;
use App\Models\InvoiceRow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class SubscriptionController extends Controller
{
    use ScopesToCurrentCompany;

    public function renew(Invoice $invoice): RedirectResponse
    {
        $this->authorizeCurrentCompany($invoice);

        $rows = $invoice->rows()->subscriptions()->get();

        abort_if($rows->isEmpty(), 404);

        $newInvoice = DB::transaction(function () use ($invoice, $rows) {
            $newInvoice = Invoice::query()->create([
                'company_id' => $invoice->company_id,
                'customer_id' => $invoice->customer_id,
                'invoice_date' => now()->format('Y-m-d'),
                'paid' => false,
                'language' => $invoice->language,
            ]);

            foreach ($rows as $row) {
                $newInvoice->rows()->create([
                    'description' => $row->description,
                    'quantity' => $row->quantity,
                    'price' => $row->price,
                    'vat_rate' => $row->vat_rate,
                    'expiration_date' => $row->expiration_date->copy()->addYear(),
                    'subscription_status' => SubscriptionStatus::Active,
                ]);
            }

            InvoiceRow::query()->whereIn('id', $rows->pluck('id'))->update([
                'subscription_status' => SubscriptionStatus::Cancelled,
            ]);

            return $newInvoice;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Renewal invoice created.')]);

        return to_route('invoices.edit', $newInvoice);
    }

    public function cancelRow(InvoiceRow $invoiceRow): RedirectResponse
    {
        $this->authorizeCurrentCompany($invoiceRow->invoice);

        $invoiceRow->update(['subscription_status' => SubscriptionStatus::Cancelled]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Service marked as not renewing.')]);

        return to_route('dashboard');
    }

    public function cancelGroup(Invoice $invoice): RedirectResponse
    {
        $this->authorizeCurrentCompany($invoice);

        $invoice->rows()->subscriptions()->update(['subscription_status' => SubscriptionStatus::Cancelled]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('All services marked as not renewing.')]);

        return to_route('dashboard');
    }
}
