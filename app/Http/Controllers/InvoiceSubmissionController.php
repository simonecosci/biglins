<?php

namespace App\Http\Controllers;

use App\Actions\IssueInvoice;
use App\Http\Controllers\Concerns\ScopesToCurrentCompany;
use App\Models\Invoice;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class InvoiceSubmissionController extends Controller
{
    use ScopesToCurrentCompany;

    public function issue(Invoice $invoice, IssueInvoice $issueInvoice): RedirectResponse
    {
        $this->authorizeCurrentCompany($invoice);

        $issueInvoice->handle($invoice);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invoice issued.')]);

        return to_route('invoices.edit', $invoice);
    }
}
