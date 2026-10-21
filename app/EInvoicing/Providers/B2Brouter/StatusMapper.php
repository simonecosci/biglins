<?php

namespace App\EInvoicing\Providers\B2Brouter;

use App\EInvoicing\Enums\SubmissionStatus;

class StatusMapper
{
    public static function map(?string $invoiceState, ?string $taxReportState, ?string $isoCode): SubmissionStatus
    {
        if ($taxReportState !== null) {
            return match ($taxReportState) {
                'registered', 'acknowledged' => $isoCode === 'IT' ? SubmissionStatus::Delivered : SubmissionStatus::Accepted,
                'registered_with_errors' => SubmissionStatus::Accepted,
                'deposited' => SubmissionStatus::NotDelivered,
                'refused', 'error', 'invalid', 'annulled' => SubmissionStatus::Rejected,
                default => SubmissionStatus::Submitted,
            };
        }

        return in_array($invoiceState, ['error', 'invalid'], true)
            ? SubmissionStatus::Failed
            : SubmissionStatus::Submitted;
    }
}
