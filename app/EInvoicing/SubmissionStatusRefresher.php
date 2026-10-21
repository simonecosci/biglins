<?php

namespace App\EInvoicing;

use App\Models\InvoiceSubmission;

class SubmissionStatusRefresher
{
    public function __construct(
        private EInvoicingProviderFactory $factory,
        private SubmissionResultRecorder $recorder,
    ) {}

    public function refresh(InvoiceSubmission $submission): InvoiceSubmission
    {
        $submission->refresh();

        if (! $submission->status->isAwaitingAuthority() || $submission->external_id === null) {
            return $submission;
        }

        $result = $this->factory->forIntegration($submission->integration)->fetchStatus($submission);

        return $this->recorder->record($submission, $result);
    }
}
