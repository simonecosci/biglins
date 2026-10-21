<?php

namespace App\EInvoicing\Providers;

use App\EInvoicing\Contracts\EInvoicingProvider;
use App\EInvoicing\Data\ProviderNotification;
use App\EInvoicing\Data\SubmissionResult;
use App\EInvoicing\Enums\Capability;
use App\EInvoicing\Enums\EInvoicingEnvironment;
use App\EInvoicing\Exceptions\InvalidWebhookSignature;
use App\EInvoicing\Exceptions\TransientProviderException;
use App\EInvoicing\Providers\B2Brouter\InvoicePayloadMapper;
use App\EInvoicing\Providers\B2Brouter\StatusMapper;
use App\Models\EInvoicingIntegration;
use App\Models\Invoice;
use App\Models\InvoiceSubmission;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class B2BrouterProvider implements EInvoicingProvider
{
    public const API_VERSION = '2026-06-26';

    /**
     * @param  array<string, string>  $credentials
     */
    public function __construct(private array $credentials, private EInvoicingEnvironment $environment) {}

    public function send(Invoice $invoice): SubmissionResult
    {
        $response = $this->call(fn (PendingRequest $http): Response => $http->post("/accounts/{$this->accountId()}/invoices", [
            'send_after_import' => true,
            'invoice' => (new InvoicePayloadMapper)->map($invoice),
        ]));

        if ($response->failed()) {
            return SubmissionResult::failed($this->errorMessage($response), $response->body());
        }

        $invoiceData = $response->json('invoice') ?? [];

        if (! isset($invoiceData['id'])) {
            return SubmissionResult::failed('B2Brouter returned no invoice id.', $response->body());
        }

        return $this->resultFromInvoice($invoiceData, $invoice->company->country?->iso_code, $response->body(), tolerateTaxReportFailure: true);
    }

    public function fetchStatus(InvoiceSubmission $submission): SubmissionResult
    {
        $response = $this->call(fn (PendingRequest $http): Response => $http->get("/invoices/{$submission->external_id}"));

        if ($response->failed()) {
            return new SubmissionResult($submission->status, $submission->provider_status, errorMessage: $this->errorMessage($response));
        }

        return $this->resultFromInvoice($response->json('invoice') ?? [], $submission->invoice->company->country?->iso_code, $response->body());
    }

    public function parseWebhook(Request $request, EInvoicingIntegration $integration): ?ProviderNotification
    {
        $this->verifyWebhook($request, $integration);

        $payload = json_decode($request->getContent(), true) ?? [];
        $code = $payload['code'] ?? null;
        $data = $payload['data'] ?? [];

        $invoiceId = match ($code) {
            'issued_invoice.state_change' => $data['invoice_id'] ?? null,
            'tax_report.state_change' => $data['object']['invoice_id'] ?? null,
            default => null,
        };

        if ($invoiceId === null || ! isset($data['event_id'])) {
            return null;
        }

        return new ProviderNotification((string) $data['event_id'], (string) $invoiceId, (string) $code, $payload);
    }

    public function testConnection(): bool
    {
        try {
            return $this->http()->get("/accounts/{$this->accountId()}")->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    public function capabilities(): array
    {
        return [Capability::ItalySdi, Capability::SpainVerifactu];
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    private function resultFromInvoice(array $invoice, ?string $isoCode, string $document, bool $tolerateTaxReportFailure = false): SubmissionResult
    {
        $taxReport = null;
        $taxReportIds = $invoice['tax_report_ids'] ?? [];
        $taxReportId = is_array($taxReportIds) ? array_last($taxReportIds) : null;

        if ($taxReportId !== null) {
            try {
                $taxReportResponse = $this->call(fn (PendingRequest $http): Response => $http->get("/tax_reports/{$taxReportId}"));
                $taxReport = $taxReportResponse->successful() ? $taxReportResponse->json('tax_report') : null;
            } catch (TransientProviderException $exception) {
                if (! $tolerateTaxReportFailure) {
                    throw $exception;
                }
            }
        }

        $taxReportErrorList = $taxReport['errors'] ?? [];
        $taxReportErrors = implode('; ', array_map(
            fn (array $error): string => trim(($error['code'] ?? '').' '.($error['description'] ?? '')),
            is_array($taxReportErrorList) ? $taxReportErrorList : [],
        ));

        return new SubmissionResult(
            status: StatusMapper::map($invoice['state'] ?? null, $taxReport['state'] ?? null, $isoCode),
            providerStatus: $taxReport['state'] ?? $invoice['state'] ?? null,
            externalId: isset($invoice['id']) ? (string) $invoice['id'] : null,
            authorityId: $isoCode === 'IT' ? ($invoice['to_net_id'] ?? null) : ($taxReport['identifier'] ?? null),
            qrCode: $taxReport['qr'] ?? null,
            errorMessage: $taxReportErrors !== '' ? $taxReportErrors : ($invoice['error_message'] ?? $invoice['refuse_reason'] ?? null),
            document: $document,
        );
    }

    private function verifyWebhook(Request $request, EInvoicingIntegration $integration): void
    {
        $signingSecret = $this->credentials['webhook_signing_secret'] ?? null;

        if (blank($signingSecret)) {
            if (! hash_equals($integration->webhook_secret, (string) $request->query('secret'))) {
                throw new InvalidWebhookSignature('Invalid webhook secret.');
            }

            return;
        }

        parse_str(str_replace(',', '&', (string) $request->header('X-B2Brouter-Signature')), $parts);
        if (! is_string($parts['t'] ?? null) || ! is_string($parts['s'] ?? null)) {
            throw new InvalidWebhookSignature('Invalid B2Brouter signature.');
        }

        $expected = hash_hmac('sha256', $parts['t'].'.'.$request->getContent(), $signingSecret);

        if (! hash_equals($expected, $parts['s'])) {
            throw new InvalidWebhookSignature('Invalid B2Brouter signature.');
        }
    }

    /**
     * @param  callable(PendingRequest): Response  $request
     */
    private function call(callable $request): Response
    {
        try {
            $response = $request($this->http());
        } catch (ConnectionException $exception) {
            throw new TransientProviderException($exception->getMessage(), previous: $exception);
        }

        if ($response->serverError() || $response->status() === 429) {
            throw new TransientProviderException("B2Brouter responded with HTTP {$response->status()}.");
        }

        return $response;
    }

    private function http(): PendingRequest
    {
        $baseUrl = $this->environment === EInvoicingEnvironment::Staging
            ? 'https://api-staging.b2brouter.net'
            : 'https://api.b2brouter.net';

        return Http::baseUrl($baseUrl)
            ->withHeaders([
                'X-B2B-API-Key' => $this->credentials['api_key'] ?? '',
                'X-B2B-API-Version' => self::API_VERSION,
            ])
            ->acceptJson()
            ->asJson()
            ->connectTimeout(10)
            ->timeout(30);
    }

    private function accountId(): string
    {
        return $this->credentials['account_id'] ?? '';
    }

    private function errorMessage(Response $response): string
    {
        $error = $response->json('error');

        return match (true) {
            is_array($error) => (string) ($error['message'] ?? $error['code'] ?? 'Unknown error'),
            is_string($error) => $error,
            default => (string) ($response->json('message') ?? "HTTP {$response->status()}"),
        };
    }
}
