<?php

namespace App\EInvoicing\Contracts;

use App\EInvoicing\Enums\SubmissionStatus;
use App\Models\Invoice;

interface CountryComplianceRules
{
    /**
     * Validation rules for the keys of companies.fiscal_details, without the "fiscal_details." prefix.
     *
     * @return array<string, array<mixed>>
     */
    public function companyFiscalRules(): array;

    /**
     * Validation rules for the keys of customers.fiscal_details, without the "fiscal_details." prefix.
     *
     * @return array<string, array<mixed>>
     */
    public function customerFiscalRules(): array;

    /**
     * @return list<string>
     */
    public function vatExemptionCodes(): array;

    /**
     * Check that the invoice (with company.country, customer.country and rows loaded) can be issued.
     *
     * @return array<string, string> field path => translated message
     */
    public function validateForIssue(Invoice $invoice): array;

    public function requiresSubmission(): bool;

    public function allowsRevisionAfter(SubmissionStatus $status): bool;
}
