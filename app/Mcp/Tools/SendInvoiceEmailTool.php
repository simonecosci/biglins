<?php

namespace App\Mcp\Tools;

use App\Http\Requests\SendInvoiceRequest;
use App\Mail\InvoiceMail;
use App\Models\Invoice;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Name('send_invoice_email')]
#[Description('Email an existing invoice as a PDF attachment to a recipient.')]
#[IsDestructive]
#[IsOpenWorld]
class SendInvoiceEmailTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        try {
            $data = $request->validate([
                'company_id' => ['required', 'uuid', Rule::exists('companies', 'id')],
                'invoice_id' => ['required', 'uuid'],
            ]);
        } catch (ValidationException $e) {
            return Response::error($e->validator->errors()->first());
        }

        $invoice = Invoice::query()->where('company_id', (string) $data['company_id'])->find((string) $data['invoice_id']);

        if ($invoice === null) {
            return Response::error('No invoice with that id was found for the given company.');
        }

        try {
            $mailData = $request->validate((new SendInvoiceRequest)->rules());
        } catch (ValidationException $e) {
            return Response::error($e->validator->errors()->first());
        }

        Mail::to($mailData['to'])->send(new InvoiceMail($invoice, $mailData['subject'], $mailData['message']));

        $invoice->sent_at = Carbon::now();
        $invoice->sent_to = $mailData['to'];
        $invoice->save();

        return Response::structured(['invoice' => $invoice->fresh()->toArray()]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'company_id' => $schema->string()->description('UUID of the company the invoice belongs to.')->required(),
            'invoice_id' => $schema->string()->description('UUID of the invoice to send.')->required(),
            'to' => $schema->string()->format('email')->required(),
            'subject' => $schema->string()->required(),
            'message' => $schema->string()->description('Email body.')->required(),
        ];
    }
}
