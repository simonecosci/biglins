<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCurrentCompany;
use App\Http\Requests\StoreEstimationAttachmentRequest;
use App\Models\Attachment;
use App\Models\Estimation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class EstimationAttachmentController extends Controller
{
    use ScopesToCurrentCompany;

    public function store(StoreEstimationAttachmentRequest $request, Estimation $estimation): RedirectResponse
    {
        $this->authorizeCurrentCompany($estimation);

        $file = $request->file('file');
        $path = $file->store("estimations/{$estimation->id}/attachments", 'local');

        $estimation->attachments()->create([
            'disk' => 'local',
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Attachment uploaded.')]);

        return to_route('estimations.edit', $estimation);
    }

    public function destroy(Estimation $estimation, Attachment $attachment): RedirectResponse
    {
        $this->authorizeCurrentCompany($estimation);

        abort_unless(
            $attachment->attachable_type === Estimation::class && $attachment->attachable_id === $estimation->id,
            404
        );

        Storage::disk($attachment->disk)->delete($attachment->path);
        $attachment->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Attachment deleted.')]);

        return to_route('estimations.edit', $estimation);
    }
}
