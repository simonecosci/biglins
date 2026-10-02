<?php

namespace App\Mcp\Tools;

use App\Actions\IssueInvoice;
use App\Models\Company;
use App\Models\Invoice;
use App\Support\CurrentCompany;
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

#[Name('issue_invoice')]
#[Description('Issue a draft invoice: assigns its number, locks it and, for companies in Italy or Spain, submits it to the tax authority through the configured e-invoicing provider.')]
class IssueInvoiceTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        try {
            $data = $request->validate([
                'company_id' => ['required', 'uuid', Rule::exists('companies', 'id')],
                'invoice_id' => ['required', 'uuid', Rule::exists('invoices', 'id')->where('company_id', $request->get('company_id'))],
            ]);
        } catch (ValidationException $e) {
            return Response::error($e->validator->errors()->first());
        }

        $company = Company::query()->findOrFail((string) $data['company_id']);
        $invoice = Invoice::query()->findOrFail((string) $data['invoice_id']);

        return CurrentCompany::runningAs($company, function () use ($invoice): Response|ResponseFactory {
            try {
                $invoice = app(IssueInvoice::class)->handle($invoice);
            } catch (ValidationException $e) {
                return Response::error(collect($e->errors())->flatten()->implode(' '));
            }

            $submission = $invoice->latestSubmission;

            return Response::structured([
                'invoice' => [
                    'id' => $invoice->id,
                    'number' => $invoice->number,
                    'status' => $invoice->status->value,
                    'issued_at' => $invoice->issued_at?->toIso8601String(),
                ],
                'submission' => $submission ? [
                    'status' => $submission->status->value,
                    'error_message' => $submission->error_message,
                ] : null,
            ]);
        });
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'company_id' => $schema->string()->description('UUID of the company owning the invoice.')->required(),
            'invoice_id' => $schema->string()->description('UUID of the draft invoice to issue.')->required(),
        ];
    }
}
