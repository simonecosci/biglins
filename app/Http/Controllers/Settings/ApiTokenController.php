<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreApiTokenRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

class ApiTokenController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('settings/ApiTokens', [
            'tokens' => request()->user()
                ->tokens()
                ->select(['id', 'name', 'created_at', 'last_used_at'])
                ->latest()
                ->get()
                ->map(fn (PersonalAccessToken $token): array => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'created_at_diff' => $token->created_at?->diffForHumans(),
                    'last_used_at_diff' => $token->last_used_at?->diffForHumans(),
                ])
                ->values()
                ->all(),
        ]);
    }

    public function store(StoreApiTokenRequest $request): RedirectResponse
    {
        $token = $request->user()->createToken($request->validated('name'));

        Inertia::flash('newApiToken', $token->plainTextToken);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('API token created.')]);

        return to_route('api-tokens.index');
    }

    public function destroy(int $token): RedirectResponse
    {
        $accessToken = PersonalAccessToken::query()->findOrFail($token);

        abort_unless($accessToken->tokenable_id === request()->user()->id, 403);

        $accessToken->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('API token revoked.')]);

        return to_route('api-tokens.index');
    }
}
