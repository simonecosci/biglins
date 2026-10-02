<?php

namespace App\EInvoicing\Compliance;

use App\EInvoicing\Contracts\CountryComplianceRules;
use App\EInvoicing\Enums\SubmissionStatus;
use App\Models\Invoice;

class DefaultComplianceRules implements CountryComplianceRules
{
    public function companyFiscalRules(): array
    {
        return [];
    }

    public function customerFiscalRules(): array
    {
        return [];
    }

    public function vatExemptionCodes(): array
    {
        return [];
    }

    public function validateForIssue(Invoice $invoice): array
    {
        if ($invoice->rows->isEmpty()) {
            return ['rows' => __('The invoice must have at least one row.')];
        }

        return [];
    }

    public function requiresSubmission(): bool
    {
        return false;
    }

    public function allowsRevisionAfter(SubmissionStatus $status): bool
    {
        return false;
    }
}
