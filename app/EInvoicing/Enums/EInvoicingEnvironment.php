<?php

namespace App\EInvoicing\Enums;

enum EInvoicingEnvironment: string
{
    case Sandbox = 'sandbox';
    case Staging = 'staging';
    case Production = 'production';
}
