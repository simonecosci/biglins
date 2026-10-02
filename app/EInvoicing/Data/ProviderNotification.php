<?php

namespace App\EInvoicing\Data;

final readonly class ProviderNotification
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $eventId,
        public string $externalId,
        public string $type,
        public array $payload,
        public ?SubmissionResult $result = null,
    ) {}
}
