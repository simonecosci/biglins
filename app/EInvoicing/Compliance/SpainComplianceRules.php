<?php

namespace App\EInvoicing\Compliance;

use App\EInvoicing\Enums\SubmissionStatus;
use App\Models\Invoice;
use Illuminate\Validation\Rule;

class SpainComplianceRules extends DefaultComplianceRules
{
    /**
     * @var list<string>
     */
    public const ID_TYPES = ['02', '03', '04', '05', '06', '07'];

    public function companyFiscalRules(): array
    {
        return [
            'special_regime' => ['nullable', 'string', 'size:2'],
        ];
    }

    public function customerFiscalRules(): array
    {
        return [
            'id_type' => ['nullable', 'string', Rule::in(self::ID_TYPES)],
        ];
    }

    public function vatExemptionCodes(): array
    {
        return ['E1', 'E2', 'E3', 'E4', 'E5', 'E6', 'N1', 'N2'];
    }

    public function validateForIssue(Invoice $invoice): array
    {
        $errors = parent::validateForIssue($invoice);

        if (blank($invoice->company->vat_number)) {
            $errors['company.vat_number'] = __('The company NIF is required.');
        }

        $customer = $invoice->customer;

        if (blank($customer->vat_number)) {
            $errors['customer.vat_number'] = __('The customer tax identifier is required.');
        }

        if ($customer->country?->iso_code !== 'ES' && blank($customer->fiscal_details['id_type'] ?? null)) {
            $errors['customer.fiscal_details.id_type'] = __('A foreign customer needs an identifier type.');
        }

        return [...$errors, ...$this->exemptionCodeErrors($invoice)];
    }

    public function requiresSubmission(): bool
    {
        return true;
    }

    public function allowsRevisionAfter(SubmissionStatus $status): bool
    {
        return $status === SubmissionStatus::Failed;
    }
}
