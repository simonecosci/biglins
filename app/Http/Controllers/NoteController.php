<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCurrentCompany;
use App\Http\Requests\StoreNoteRequest;
use App\Http\Requests\UpdateNoteRequest;
use App\Models\Note;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NoteController extends Controller
{
    use ScopesToCurrentCompany;

    public function index(Request $request): Response|JsonResponse
    {
        $search = $request->string('search')->trim()->toString();
        $currentCompanyId = CurrentCompany::resolve()?->id;

        $notes = Note::query()
            ->where('company_id', $currentCompanyId)
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('title', 'like', "%{$search}%")
                    ->orWhere('content', 'like', "%{$search}%");
            }))
            ->orderBy('title')
            ->paginate(15)
            ->withQueryString();

        if ($request->wantsJson()) {
            return response()->json($notes);
        }

        return Inertia::render('notes/Index', [
            'notes' => $notes,
            'filters' => ['search' => $search],
        ]);
    }

    public function create(): Response|RedirectResponse
    {
        if (CurrentCompany::resolve() === null) {
            return $this->redirectToCreateCompany();
        }

        return Inertia::render('notes/Create');
    }

    public function store(StoreNoteRequest $request): RedirectResponse
    {
        $currentCompany = CurrentCompany::resolve();

        if ($currentCompany === null) {
            return $this->redirectToCreateCompany();
        }

        Note::query()->create([
            ...$request->validated(),
            'company_id' => $currentCompany->id,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Note created.')]);

        return to_route('notes.index');
    }

    public function edit(Note $note): Response
    {
        $this->authorizeCurrentCompany($note);

        return Inertia::render('notes/Edit', [
            'note' => $note,
        ]);
    }

    public function update(UpdateNoteRequest $request, Note $note): RedirectResponse
    {
        $this->authorizeCurrentCompany($note);

        $note->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Note updated.')]);

        return to_route('notes.index');
    }

    public function destroy(Note $note): RedirectResponse
    {
        $this->authorizeCurrentCompany($note);

        $note->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Note deleted.')]);

        return to_route('notes.index');
    }
}
