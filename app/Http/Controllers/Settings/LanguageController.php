<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetLocale;
use App\Http\Requests\Settings\LanguageUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Inertia\Inertia;
use Inertia\Response;

class LanguageController extends Controller
{
    /**
     * Show the user's language settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Language', [
            'locale' => $request->user()->locale,
            'locales' => SetLocale::SUPPORTED_LOCALES,
        ]);
    }

    /**
     * Update the user's language preference.
     */
    public function update(LanguageUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $user->locale = $request->validated('locale');
        $user->save();

        App::setLocale($user->locale);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Language updated.')]);

        return to_route('language.edit');
    }
}
