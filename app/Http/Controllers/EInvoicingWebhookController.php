<?php

namespace App\Http\Controllers;

use App\EInvoicing\EInvoicingProviderFactory;
use App\EInvoicing\Exceptions\InvalidWebhookSignature;
use App\EInvoicing\Exceptions\TransientProviderException;
use App\EInvoicing\SubmissionResultRecorder;
use App\Models\EInvoicingIntegration;
use App\Models\InvoiceSubmission;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EInvoicingWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $driver,
        EInvoicingIntegration $integration,
        EInvoicingProviderFactory $factory,
        SubmissionResultRecorder $recorder,
    ): Response {
        abort_unless($integration->driver->value === $driver, 404);

        $provider = $factory->forIntegration($integration);

        try {
            $notification = $provider->parseWebhook($request, $integration);
        } catch (InvalidWebhookSignature) {
            abort(403);
        }

        if ($notification === null) {
            Log::info('Ignored e-invoicing webhook.', ['integration' => $integration->id]);

            return response('', 200);
        }

        $submission = InvoiceSubmission::query()
            ->where('e_invoicing_integration_id', $integration->id)
            ->where('external_id', $notification->externalId)
            ->latest()
            ->first();

        if ($submission === null) {
            Log::info('E-invoicing webhook for an unknown submission.', ['integration' => $integration->id, 'external_id' => $notification->externalId]);

            return response('', 200);
        }

        $alreadyReceived = $submission->events()
            ->where('provider_event_id', $notification->eventId)
            ->exists();

        if ($alreadyReceived) {
            return response('', 200);
        }

        try {
            $result = $notification->result ?? $provider->fetchStatus($submission);
        } catch (TransientProviderException) {
            abort(503);
        }

        try {
            DB::transaction(function () use ($submission, $notification, $result, $recorder): void {
                $submission->events()->create([
                    'type' => $notification->type,
                    'provider_event_id' => $notification->eventId,
                    'payload' => $notification->payload,
                    'received_at' => now(),
                ]);

                $recorder->record($submission, $result);
            });
        } catch (UniqueConstraintViolationException) {
            return response('', 200);
        }

        return response('', 200);
    }
}
