<?php

namespace App\Enums;

enum ExpirationUrgency: string
{
    case Expired = 'expired';
    case ExpiringSoon = 'expiring_soon';
    case Upcoming = 'upcoming';
}
