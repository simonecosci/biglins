<?php

namespace App\EInvoicing\Enums;

enum SubmissionStatus: string
{
    case Pending = 'pending';
    case Failed = 'failed';
    case Submitted = 'submitted';
    case Rejected = 'rejected';
    case Accepted = 'accepted';
    case Delivered = 'delivered';
    case NotDelivered = 'not_delivered';

    public function isAwaitingAuthority(): bool
    {
        return in_array($this, [self::Pending, self::Submitted], true);
    }

    public function isFinal(): bool
    {
        return ! $this->isAwaitingAuthority();
    }
}
