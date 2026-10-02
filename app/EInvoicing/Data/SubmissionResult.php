<?php

namespace App\EInvoicing\Data;

use App\EInvoicing\Enums\SubmissionStatus;

final readonly class SubmissionResult
{
    public function __construct(
        public SubmissionStatus $status,
        public ?string $providerStatus = null,
        public ?string $externalId = null,
        public ?string $authorityId = null,
        public ?string $qrCode = null,
        public ?string $errorMessage = null,
        public ?string $document = null,
    ) {}

    public static function failed(string $message, ?string $document = null): self
    {
        return new self(SubmissionStatus::Failed, errorMessage: $message, document: $document);
    }
}
