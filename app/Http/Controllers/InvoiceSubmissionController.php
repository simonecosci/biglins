<?php

namespace App\Http\Controllers;

use App\Actions\IssueInvoice;
use App\Actions\SubmitElectronicInvoice;
use App\EInvoicing\Enums\SubmissionStatus;
use App\EInvoicing\Exceptions\TransientProviderException;
use App\EInvoicing\SubmissionStatusRefresher;
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

    public function submit(Invoice $invoice, SubmitElectronicInvoice $submitElectronicInvoice): RedirectResponse
    {
        $this->authorizeCurrentCompany($invoice);

        $invoice = $submitElectronicInvoice->handle($invoice);
        $submission = $invoice->latestSubmission;

        Inertia::flash('toast', match ($submission?->status) {
            SubmissionStatus::Failed => ['type' => 'error', 'message' => __('The invoice could not be submitted: :error', ['error' => $submission->error_message])],
            SubmissionStatus::Pending => ['type' => 'success', 'message' => __('Invoice queued for electronic submission.')],
            default => ['type' => 'success', 'message' => __('Invoice sent electronically.')],
        });

        return to_route('invoices.edit', $invoice);
    }

    public function refresh(Invoice $invoice, SubmissionStatusRefresher $refresher): RedirectResponse
    {
        $this->authorizeCurrentCompany($invoice);

        $submission = $invoice->latestSubmission;

        if ($submission !== null) {
            try {
                $refresher->refresh($submission);
                Inertia::flash('toast', ['type' => 'success', 'message' => __('Submission status updated.')]);
            } catch (TransientProviderException) {
                Inertia::flash('toast', ['type' => 'error', 'message' => __('The provider is not reachable, try again later.')]);
            }
        }

        return to_route('invoices.edit', $invoice);
    }
}
