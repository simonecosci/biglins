<?php

namespace App\EInvoicing;

use App\EInvoicing\Data\SubmissionResult;
use App\EInvoicing\Enums\SubmissionStatus;
use App\Enums\InvoiceStatus;
use App\Models\InvoiceSubmission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SubmissionResultRecorder
{
    public function record(InvoiceSubmission $submission, SubmissionResult $result): InvoiceSubmission
    {
        if ($submission->status->isFinal()) {
            return $submission;
        }

        DB::transaction(function () use ($submission, $result): void {
            $submission->fill([
                'status' => $result->status,
                'provider_status' => $result->providerStatus ?? $submission->provider_status,
                'external_id' => $result->externalId ?? $submission->external_id,
                'authority_id' => $result->authorityId ?? $submission->authority_id,
                'qr_code' => $result->qrCode ?? $submission->qr_code,
                'error_message' => $result->errorMessage,
            ]);

            if ($result->document !== null) {
                $path = "einvoicing/{$submission->id}/".now()->format('YmdHisv').'.txt';
                Storage::disk('local')->put($path, $result->document);
                $submission->payload_path = $path;
            }

            if ($submission->submitted_at === null && ! in_array($result->status, [SubmissionStatus::Pending, SubmissionStatus::Failed], true)) {
                $submission->submitted_at = now();
            }

            if ($result->status->isFinal()) {
                $submission->completed_at = now();
            }

            $submission->save();

            $invoice = $submission->invoice()->with('company.country')->firstOrFail();

            if ($result->status->isFinal() && CountryComplianceResolver::forCompany($invoice->company)->allowsRevisionAfter($result->status)) {
                $invoice->forceFill(['status' => InvoiceStatus::Draft, 'issued_at' => null])->save();
            }
        });

        return $submission;
    }
}
