<?php

namespace App\Console\Commands;

use App\EInvoicing\Enums\SubmissionStatus;
use App\EInvoicing\SubmissionStatusRefresher;
use App\Models\InvoiceSubmission;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('einvoicing:refresh-statuses')]
#[Description('Fetch the status of e-invoicing submissions still waiting for the tax authority')]
class RefreshEInvoicingStatuses extends Command
{
    public function handle(SubmissionStatusRefresher $refresher): int
    {
        InvoiceSubmission::query()
            ->with('integration')
            ->where('status', SubmissionStatus::Submitted)
            ->where('submitted_at', '<=', now()->subHour())
            ->orderBy('submitted_at')
            ->each(function (InvoiceSubmission $submission) use ($refresher): void {
                try {
                    $refresher->refresh($submission);
                } catch (Throwable $exception) {
                    report($exception);
                    $this->warn("Submission {$submission->id}: {$exception->getMessage()}");
                }
            });

        return self::SUCCESS;
    }
}
