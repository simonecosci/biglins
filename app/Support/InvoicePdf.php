<?php

namespace App\Support;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Illuminate\Support\Facades\App;

class InvoicePdf
{
    public static function render(Invoice $invoice): PdfDocument
    {
        App::setLocale($invoice->language);

        return Pdf::loadView('invoices.template', [
            'invoice' => $invoice->load(['customer.country', 'company.country', 'rows']),
        ]);
    }

    public static function filename(Invoice $invoice): string
    {
        return str_replace(['/', '\\'], '-', $invoice->number).'.pdf';
    }
}
