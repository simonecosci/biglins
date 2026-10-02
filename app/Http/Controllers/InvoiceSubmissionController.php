<?php

namespace App\Http\Controllers;

use App\Actions\IssueInvoice;
use App\EInvoicing\Enums\SubmissionStatus;
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

        $invoice = $issueInvoice->handle($invoice);
        $submission = $invoice->latestSubmission;

        Inertia::flash('toast', match ($submission?->status) {
            SubmissionStatus::Failed => ['type' => 'error', 'message' => __('The invoice could not be submitted: :error', ['error' => $submission->error_message])],
            SubmissionStatus::Pending => ['type' => 'success', 'message' => __('Invoice issued and queued for submission.')],
            null => ['type' => 'success', 'message' => __('Invoice issued.')],
            default => ['type' => 'success', 'message' => __('Invoice issued and submitted.')],
        });

        return to_route('invoices.edit', $invoice);
    }
}
