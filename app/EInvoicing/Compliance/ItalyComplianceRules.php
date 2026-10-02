<?php

namespace App\EInvoicing\Compliance;

use App\EInvoicing\Enums\SubmissionStatus;
use App\Models\Invoice;
use App\Models\InvoiceRow;
use Illuminate\Validation\Rule;

class ItalyComplianceRules extends DefaultComplianceRules
{
    /**
     * @var list<string>
     */
    public const TAX_REGIMES = [
        'RF01', 'RF02', 'RF04', 'RF05', 'RF06', 'RF07', 'RF08', 'RF09', 'RF10',
        'RF11', 'RF12', 'RF13', 'RF14', 'RF15', 'RF16', 'RF17', 'RF18', 'RF19',
    ];

    public function companyFiscalRules(): array
    {
        return [
            'tax_regime' => ['nullable', 'string', Rule::in(self::TAX_REGIMES)],
            'rea_office' => ['nullable', 'string', 'size:2', 'alpha'],
            'rea_number' => ['nullable', 'string', 'max:20'],
            'share_capital' => ['nullable', 'numeric', 'min:0'],
            'liquidation_status' => ['nullable', 'string', Rule::in(['LS', 'LN'])],
        ];
    }

    public function customerFiscalRules(): array
    {
        return [
            'recipient_code' => ['nullable', 'string', 'size:7', 'alpha_num'],
            'pec' => ['nullable', 'email', 'max:255'],
        ];
    }

    public function vatExemptionCodes(): array
    {
        return [
            'N1', 'N2.1', 'N2.2', 'N3.1', 'N3.2', 'N3.3', 'N3.4', 'N3.5', 'N3.6', 'N4', 'N5',
            'N6.1', 'N6.2', 'N6.3', 'N6.4', 'N6.5', 'N6.6', 'N6.7', 'N6.8', 'N6.9', 'N7',
        ];
    }

    public function validateForIssue(Invoice $invoice): array
    {
        $errors = parent::validateForIssue($invoice);
        $company = $invoice->company;
        $customer = $invoice->customer;

        if (blank($company->vat_number)) {
            $errors['company.vat_number'] = __('The company VAT number is required.');
        }

        if (blank($company->fiscal_details['tax_regime'] ?? null)) {
            $errors['company.fiscal_details.tax_regime'] = __('The company tax regime is required.');
        }

        foreach (['address', 'zip', 'city', 'province'] as $field) {
            if (blank($company->{$field})) {
                $errors["company.{$field}"] = __('The company address is incomplete.');
            }
        }

        $isDomesticCustomer = $customer->country?->iso_code === 'IT';

        if (! $isDomesticCustomer) {
            if (blank($customer->vat_number)) {
                $errors['customer.vat_number'] = __('A foreign customer needs a VAT number.');
            }
        } elseif (filled($customer->vat_number)) {
            if (blank($customer->fiscal_details['recipient_code'] ?? null) && blank($customer->fiscal_details['pec'] ?? null)) {
                $errors['customer.fiscal_details.recipient_code'] = __('A business customer needs a recipient code or a PEC address.');
            }
        } elseif (blank($customer->tax_code)) {
            $errors['customer.tax_code'] = __('A private customer needs a tax code.');
        }

        return [...$errors, ...$this->exemptionCodeErrors($invoice)];
    }

    public function requiresSubmission(): bool
    {
        return true;
    }

    public function allowsRevisionAfter(SubmissionStatus $status): bool
    {
        return in_array($status, [SubmissionStatus::Failed, SubmissionStatus::Rejected], true);
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
