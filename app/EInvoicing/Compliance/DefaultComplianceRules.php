<?php

namespace App\EInvoicing\Compliance;

use App\EInvoicing\Contracts\CountryComplianceRules;
use App\EInvoicing\Enums\SubmissionStatus;
use App\Models\Invoice;
use App\Models\InvoiceRow;

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

    /**
     * @return array<string, string>
     */
    protected function exemptionCodeErrors(Invoice $invoice): array
    {
        $errors = [];

        $invoice->rows->values()->each(function (InvoiceRow $row, int $index) use (&$errors): void {
            if ((float) $row->vat_rate === 0.0 && ! in_array($row->vat_exemption_code, $this->vatExemptionCodes(), true)) {
                $errors["rows.{$index}.vat_exemption_code"] = __('Rows with a 0% VAT rate need a valid exemption code.');
            }
        });

        return $errors;
    }
}
