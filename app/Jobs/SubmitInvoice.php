<?php

namespace App\Jobs;

use App\EInvoicing\Data\SubmissionResult;
use App\EInvoicing\EInvoicingProviderFactory;
use App\EInvoicing\Enums\SubmissionStatus;
use App\EInvoicing\Exceptions\TransientProviderException;
use App\EInvoicing\SubmissionResultRecorder;
use App\Models\InvoiceSubmission;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Throwable;

class SubmitInvoice implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public InvoiceSubmission $submission) {}

    public function uniqueId(): string
    {
        return $this->submission->id;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(EInvoicingProviderFactory $factory, SubmissionResultRecorder $recorder): void
    {
        $submission = $this->submission->fresh(['integration', 'invoice.company.country', 'invoice.customer.country', 'invoice.rows']);

        if ($submission === null || $submission->status !== SubmissionStatus::Pending) {
            return;
        }

        try {
            $result = $factory->forIntegration($submission->integration)->send($submission->invoice);
        } catch (TransientProviderException $exception) {
            if ($this->job === null || $this->job instanceof SyncJob || $this->attempts() >= $this->tries) {
                $recorder->record($submission, SubmissionResult::failed($exception->getMessage()));

                return;
            }

            $this->release($this->backoff()[$this->attempts() - 1] ?? 900);

            return;
        }

        $recorder->record($submission, $result);
    }

    public function failed(?Throwable $exception): void
    {
        $submission = $this->submission->fresh();

        if ($submission?->status === SubmissionStatus::Pending) {
            app(SubmissionResultRecorder::class)->record($submission, SubmissionResult::failed($exception?->getMessage() ?? 'Submission failed.'));
        }
    }
}
