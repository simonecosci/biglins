<?php

namespace App\Actions;

use App\EInvoicing\CountryComplianceResolver;
use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Numbers and locks a draft. Sending it to the tax authority is a separate step, see SubmitElectronicInvoice.
 */
class IssueInvoice
{
    public function handle(Invoice $invoice): Invoice
    {
        $invoice->load(['company.country', 'customer.country', 'rows']);

        $this->ensureCanBeIssued($invoice);

        $issued = DB::transaction(function () use ($invoice): Invoice {
            Company::query()->whereKey($invoice->company_id)->lockForUpdate()->first();

            $locked = Invoice::query()
                ->with(['company.country', 'customer.country', 'rows'])
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureCanBeIssued($locked);

            $locked->number ??= Invoice::nextNumber($locked->company_id);
            $locked->status = InvoiceStatus::Issued;
            $locked->issued_at = now();
            $locked->save();

            return $locked;
        });

        return $issued->refresh();
    }

    protected function ensureCanBeIssued(Invoice $invoice): void
    {
        if ($invoice->status !== InvoiceStatus::Draft) {
            throw ValidationException::withMessages(['invoice' => __('Only draft invoices can be issued.')]);
        }

        $errors = CountryComplianceResolver::forCompany($invoice->company)->validateForIssue($invoice);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
