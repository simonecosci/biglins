<?php

namespace App\Mcp\Tools;

use App\Models\Invoice;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_invoices')]
#[Description('List invoices for a given company.')]
#[IsReadOnly]
class ListInvoicesTool extends Tool
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

        $invoices = Invoice::query()
            ->with('customer')
            ->where('company_id', $companyId)
            ->orderByDesc('number')
            ->limit(50)
            ->get(['id', 'number', 'type', 'customer_id', 'invoice_date', 'paid'])
            ->map(fn (Invoice $invoice): array => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'type' => $invoice->type->value,
                'customer_id' => $invoice->customer_id,
                'customer_name' => $invoice->customer->name,
                'invoice_date' => $invoice->invoice_date->format('Y-m-d'),
                'paid' => $invoice->paid,
            ]);

        return Response::structured([
            'invoices' => $invoices->toArray(),
            'truncated' => $invoices->count() === 50,
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'company_id' => $schema->string()->description('UUID of the company to list invoices for.')->required(),
        ];
    }
}
