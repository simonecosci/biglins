<?php

namespace App\Support;

use App\Models\Estimation;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class EstimationZip
{
    /**
     * Build a zip containing the estimation PDF and its attachments.
     *
     * @return string Path to a temporary zip file. The caller owns cleanup.
     */
    public static function build(Estimation $estimation): string
    {
        App::setLocale($estimation->language);

        $estimation->load(['customer.country', 'company.country', 'rows', 'attachments']);

        $pdfContent = Pdf::loadView('estimations.template', [
            'estimation' => $estimation,
            'bodyHtml' => MarkdownRenderer::toHtml($estimation->body),
        ])->output();

        $zipPath = tempnam(sys_get_temp_dir(), 'estimation-zip-');

        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::OVERWRITE);
        $zip->addFromString(self::pdfFilename($estimation), $pdfContent);

        foreach ($estimation->attachments as $attachment) {
            $zip->addFile(Storage::disk($attachment->disk)->path($attachment->path), $attachment->original_name);
        }

        $zip->close();

        return $zipPath;
    }

    public static function filename(Estimation $estimation): string
    {
        return str_replace(['/', '\\'], '-', $estimation->number).'.zip';
    }

    private static function pdfFilename(Estimation $estimation): string
    {
        return str_replace(['/', '\\'], '-', $estimation->number).'.pdf';
    }
}
