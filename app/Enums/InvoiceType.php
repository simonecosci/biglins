<?php

namespace App\Enums;

enum InvoiceType: string
{
    case Invoice = 'invoice';
    case CreditNote = 'credit_note';
}
