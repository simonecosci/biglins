<?php

namespace App\Mcp\Tools;

use App\Enums\InvoiceType;
use App\Http\Requests\StoreInvoiceRequest;
use App\Models\Company;
use App\Models\Invoice;
use App\Support\CurrentCompany;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('create_invoice')]
#[Description('Create a new invoice (or credit note) with line items for an existing customer.')]
class CreateInvoiceTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        try {
            $companyId = $request->validate([
                'company_id' => ['required', 'uuid', Rule::exists('companies', 'id')],
            ])['company_id'];
        } catch (ValidationException $e) {
            return Response::error($e->validator->errors()->first());
        }

        $company = Company::query()->findOrFail($companyId);

        return CurrentCompany::runningAs($company, function () use ($request, $company): Response|ResponseFactory {
            try {
                $data = $request->validate((new StoreInvoiceRequest)->rules());
            } catch (ValidationException $e) {
                return Response::error($e->validator->errors()->first());
            }

            $invoice = DB::transaction(function () use ($data, $company): Invoice {
                $invoice = Invoice::query()->create([
                    ...collect($data)->except('rows')->all(),
                    'company_id' => $company->id,
                ]);

                $type = $invoice->type;
                $rows = collect($data['rows'])->map(function (array $row) use ($type): array {
                    if ($type === InvoiceType::CreditNote) {
                        $row['price'] = -abs((float) $row['price']);
                    }

                    return $row;
                });

                $invoice->rows()->createMany($rows);

                return $invoice;
            });

            return Response::structured(['invoice' => $invoice->load('rows')->toArray()]);
        });
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'company_id' => $schema->string()->description('UUID of the company to create the invoice under.')->required(),
            'customer_id' => $schema->string()->description('UUID of an existing customer belonging to the company.')->required(),
            'type' => $schema->string()->enum(['invoice', 'credit_note'])->description('Defaults to invoice.'),
            'invoice_date' => $schema->string()->format('date')->description('YYYY-MM-DD.')->required(),
            'note' => $schema->string(),
            'language' => $schema->string()->enum(['it', 'en', 'es'])->required(),
            'rows' => $schema->array()->items(
                $schema->object([
                    'description' => $schema->string()->required(),
                    'quantity' => $schema->number()->required(),
                    'price' => $schema->number()->required(),
                    'vat_rate' => $schema->number()->required(),
                    'expiration_date' => $schema->string()->format('date'),
                ])
            )->description('Line items, at least one required.')->required(),
        ];
    }
}
