<?php

namespace App\EInvoicing\Providers;

use App\EInvoicing\Contracts\EInvoicingProvider;
use App\EInvoicing\Data\ProviderNotification;
use App\EInvoicing\Data\SubmissionResult;
use App\EInvoicing\Enums\Capability;
use App\EInvoicing\Enums\EInvoicingEnvironment;
use App\EInvoicing\Enums\SubmissionStatus;
use App\EInvoicing\Exceptions\InvalidWebhookSignature;
use App\Models\EInvoicingIntegration;
use App\Models\Invoice;
use App\Models\InvoiceSubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * In-memory provider for local development and tests.
 */
class FakeProvider implements EInvoicingProvider
{
    /** @var list<SubmissionResult|Throwable> */
    private static array $sendResults = [];

    /** @var list<SubmissionResult|Throwable> */
    private static array $statusResults = [];

    public static bool $connectionSucceeds = true;

    /** @var list<string> */
    public static array $sentInvoiceIds = [];

    /**
     * @param  array<string, string>  $credentials
     */
    public function __construct(public array $credentials = [], public EInvoicingEnvironment $environment = EInvoicingEnvironment::Sandbox) {}

    public static function reset(): void
    {
        self::$sendResults = [];
        self::$statusResults = [];
        self::$connectionSucceeds = true;
        self::$sentInvoiceIds = [];
    }

    public static function queueSendResult(SubmissionResult|Throwable $result): void
    {
        self::$sendResults[] = $result;
    }

    public static function queueStatusResult(SubmissionResult|Throwable $result): void
    {
        self::$statusResults[] = $result;
    }

    public function send(Invoice $invoice): SubmissionResult
    {
        self::$sentInvoiceIds[] = $invoice->id;

        return $this->unwrap(array_shift(self::$sendResults))
            ?? new SubmissionResult(SubmissionStatus::Submitted, 'submitted', 'fake-'.Str::uuid());
    }

    public function fetchStatus(InvoiceSubmission $submission): SubmissionResult
    {
        return $this->unwrap(array_shift(self::$statusResults))
            ?? new SubmissionResult($submission->status, $submission->provider_status, $submission->external_id);
    }

    public function parseWebhook(Request $request, EInvoicingIntegration $integration): ?ProviderNotification
    {
        if (! hash_equals($integration->webhook_secret, (string) $request->header('X-Fake-Secret'))) {
            throw new InvalidWebhookSignature('Invalid fake webhook secret.');
        }

        if (! $request->filled(['event_id', 'external_id'])) {
            return null;
        }

        $status = null;

        if ($request->filled('status')) {
            $status = SubmissionStatus::tryFrom((string) $request->input('status'));

            if ($status === null) {
                return null;
            }
        }

        return new ProviderNotification(
            eventId: $request->string('event_id')->toString(),
            externalId: $request->string('external_id')->toString(),
            type: 'status_change',
            payload: $request->all(),
            result: $status === null ? null : new SubmissionResult($status, $request->input('provider_status'), $request->string('external_id')->toString()),
        );
    }

    public function testConnection(): bool
    {
        return self::$connectionSucceeds;
    }

    public function capabilities(): array
    {
        return [Capability::ItalySdi, Capability::SpainVerifactu];
    }

    private function unwrap(SubmissionResult|Throwable|null $result): ?SubmissionResult
    {
        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }
}
