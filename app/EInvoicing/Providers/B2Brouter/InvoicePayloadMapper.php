<?php

namespace App\EInvoicing\Providers\B2Brouter;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceRow;

/**
 * Maps an Invoice (with company.country, customer.country and rows loaded) to the B2Brouter "invoice" object.
 */
class InvoicePayloadMapper
{
    /**
     * @return array<string, mixed>
     */
    public function map(Invoice $invoice): array
    {
        $companyIso = $invoice->company->country?->iso_code;
        $isItalianCreditNote = $invoice->isCreditNote() && $companyIso === 'IT';

        return array_filter([
            'type' => 'IssuedInvoice',
            'number' => $invoice->number,
            'date' => $invoice->invoice_date->format('Y-m-d'),
            'currency' => 'EUR',
            'language' => $invoice->language,
            'is_credit_note' => $isItalianCreditNote ? true : null,
            'extra_info' => $invoice->note,
            'contact' => $this->contact($invoice->customer, $companyIso),
            'invoice_lines_attributes' => $invoice->rows->map(fn (InvoiceRow $row): array => [
                'description' => $row->description,
                'quantity' => (float) $row->quantity,
                'price' => $isItalianCreditNote ? abs((float) $row->price) : (float) $row->price,
                'taxes_attributes' => [$this->tax($row, $companyIso)],
            ])->all(),
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function contact(Customer $customer, ?string $companyIso): array
    {
        $customerIso = $customer->country?->iso_code;
        $isForeign = $customerIso !== $companyIso;
        $vatNumber = $customer->vat_number;

        $contact = [
            'name' => $customer->name,
            'address' => $customer->address,
            'postalcode' => $customer->zip ?? ($companyIso === 'IT' && $isForeign ? '00000' : null),
            'city' => $customer->city,
            'province' => $customer->state,
            'country' => $customerIso ? strtolower($customerIso) : null,
            'email' => $customer->email,
        ];

        if (filled($vatNumber)) {
            $vatPrefix = $customerIso === 'GR' ? 'EL' : $customerIso;
            $hasCountryPrefix = $vatPrefix !== null && str_starts_with(strtoupper($vatNumber), $vatPrefix);

            $contact['tin_value'] = match (true) {
                $customerIso === 'IT', $customerIso === null, $hasCountryPrefix => $vatNumber,
                default => $vatPrefix.$vatNumber,
            };
            $contact['tin_scheme'] = match ($customerIso) {
                'IT' => '9906',
                'ES' => '9920',
                default => null,
            };
        }

        if ($companyIso === 'IT') {
            if (filled($customer->tax_code) && blank($vatNumber)) {
                $contact['cin_value'] = $customer->tax_code;
                $contact['cin_scheme'] = '9907';
            }

            $recipientCode = $customer->fiscal_details['recipient_code'] ?? null;

            $contact['transport_type_code'] = 'it.sdi';
            $contact['document_type_code'] = 'xml.fatturapa.1.2';
            $contact['recipient_code'] = match (true) {
                $isForeign => 'XXXXXXX',
                filled($recipientCode) => strtoupper($recipientCode),
                default => '0000000',
            };
            $contact['certified_email'] = $customer->fiscal_details['pec'] ?? null;
        }

        return array_filter($contact, fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, mixed>
     */
    private function tax(InvoiceRow $row, ?string $companyIso): array
    {
        $rate = (float) $row->vat_rate;

        if ($rate > 0 || $row->vat_exemption_code === null) {
            return ['name' => 'IVA', 'category' => 'S', 'percent' => $rate];
        }

        if ($companyIso === 'IT') {
            return ['name' => 'IVA', 'category' => $row->vat_exemption_code, 'percent' => 0.0];
        }

        return [
            'name' => 'IVA',
            'category' => str_starts_with($row->vat_exemption_code, 'E') ? 'E' : 'NS',
            'percent' => 0.0,
            'comment' => $row->vat_exemption_code,
        ];
    }
}
