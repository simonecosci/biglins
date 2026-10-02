<?php

namespace App\EInvoicing\Enums;

enum Capability: string
{
    case ItalySdi = 'italy_sdi';
    case SpainVerifactu = 'spain_verifactu';
    case ReceivePassiveInvoices = 'receive_passive_invoices';
    case LegalArchiving = 'legal_archiving';
    case PublicAdministration = 'public_administration';
}
