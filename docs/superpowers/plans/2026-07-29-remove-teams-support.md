# Remove Teams Support Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Completely remove the multi-tenant Teams feature (teams, memberships, invitations, team-scoped routing) from this Laravel + Inertia/Vue application, restoring plain single-tenant auth/dashboard behavior.

**Architecture:** This is a subtractive refactor, not new feature work. Backend domain objects, HTTP layer, routes, and migrations are deleted or simplified first; then Fortify's redirect responses and the dashboard route revert to plain (non-team-scoped) URLs; then Wayfinder's generated TypeScript route/action files are regenerated from the new backend routes; finally the Vue frontend (pages, components, types) is stripped of team references. Tests are updated in lockstep with the backend changes they cover.

**Tech Stack:** Laravel 13, Laravel Fortify, Laravel Passkeys, Laravel Wayfinder, Inertia.js v3, Vue 3, Pest.

## Global Constraints

- Do not change `composer.json` / `package.json` dependencies — this task removes application code only, no packages need to change.
- Migrations: delete the team-related migration files outright (confirmed with user) rather than adding reversible down-migrations. Anyone with an existing local DB re-runs `php artisan migrate:fresh`.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes in each task before moving to the next task.
- Every backend change must be covered by `php artisan test --compact`; don't skip test updates.
- Follow existing code conventions (constructor promotion, explicit return types, curly braces always) as already used throughout this codebase.

---

## File Structure

**Deleted (backend):**
- `app/Models/Team.php`, `app/Models/Membership.php`, `app/Models/TeamInvitation.php`
- `app/Enums/TeamPermission.php`, `app/Enums/TeamRole.php`
- `app/Data/TeamPermissions.php`, `app/Data/UserTeam.php`
- `app/Concerns/HasTeams.php`, `app/Concerns/GeneratesUniqueTeamSlugs.php`
- `app/Actions/Teams/CreateTeam.php`
- `app/Http/Controllers/Teams/TeamController.php`, `TeamInvitationController.php`, `TeamMemberController.php`
- `app/Http/Requests/Teams/*.php` (5 files)
- `app/Http/Responses/Concerns/RedirectsToCurrentTeam.php`
- `app/Http/Middleware/EnsureTeamMembership.php`, `app/Http/Middleware/SetTeamUrlDefaults.php`
- `app/Policies/TeamPolicy.php`
- `app/Rules/TeamName.php`, `UniqueTeamInvitation.php`, `ValidTeamInvitation.php`
- `app/Notifications/Teams/TeamInvitation.php`
- `database/migrations/2026_01_27_000001_create_teams_table.php`
- `database/migrations/2026_01_27_000002_add_current_team_id_to_users_table.php`
- `database/factories/TeamFactory.php`, `database/factories/TeamInvitationFactory.php`
- `tests/Feature/Teams/` (whole directory: 4 test files)

**Modified (backend):**
- `app/Models/User.php` — drop `HasTeams` trait, `current_team_id` fillable/docblock
- `app/Actions/Fortify/CreateNewUser.php` — drop team creation
- `app/Http/Controllers/DashboardController.php` — drop pending-invitations query
- `app/Http/Responses/LoginResponse.php`, `PasskeyLoginResponse.php`, `RegisterResponse.php`, `TwoFactorLoginResponse.php`, `VerifyEmailResponse.php` — plain `Fortify::redirects(...)` instead of team-slug path building
- `app/Http/Middleware/HandleInertiaRequests.php` — drop `currentTeam`/`teams` shared props
- `app/Providers/FortifyServiceProvider.php` — drop `teamInvitation()` context helper and its use in login/register views
- `bootstrap/app.php` — drop `SetTeamUrlDefaults` from web middleware
- `routes/web.php`, `routes/settings.php`, `routes/console.php` — drop team routes/prefix/schedule
- `database/factories/UserFactory.php` — drop `configure()` team bootstrap
- `tests/Feature/DashboardTest.php`, `tests/Feature/Auth/AuthenticationTest.php`, `tests/Feature/Auth/RegistrationTest.php`, `tests/Feature/Auth/EmailVerificationTest.php` — drop team-invitation assertions/tests, fix redirect paths

**Deleted (frontend):**
- `resources/js/components/CancelInvitationModal.vue`, `CreateTeamModal.vue`, `DeleteTeamModal.vue`, `InviteMemberModal.vue`, `LeaveTeamModal.vue`, `PendingInvitationsModal.vue`, `RemoveMemberModal.vue`, `TeamInvitationAlert.vue`, `TeamSwitcher.vue`
- `resources/js/pages/teams/` (Index.vue, Edit.vue)
- `resources/js/types/teams.ts`
- `resources/js/actions/App/Http/Controllers/Teams/**`, `resources/js/routes/teams/**` (removed automatically by Wayfinder regeneration in Task 5)

**Modified (frontend):**
- `resources/js/components/AppHeader.vue`, `AppSidebar.vue` — drop `TeamSwitcher`, simplify `dashboardUrl`
- `resources/js/components/UserInfo.vue` — drop `team` prop
- `resources/js/components/NavUser.vue` — drop `currentTeam`/`:team` usage
- `resources/js/pages/Dashboard.vue` — drop `PendingInvitationsModal`/`pendingInvitations`, simplify breadcrumb
- `resources/js/pages/Welcome.vue` — simplify `dashboardUrl`
- `resources/js/layouts/settings/Layout.vue` — drop "Teams" nav item
- `resources/js/pages/auth/Login.vue`, `Register.vue` — drop `TeamInvitationAlert`/`teamInvitation`
- `resources/js/types/index.ts`, `resources/js/types/global.d.ts` — drop team exports/shared props
- `resources/js/app.ts` — drop `teams/` layout case

---

### Task 1: Remove team domain layer (models, enums, data objects, concerns, actions)

**Files:**
- Delete: `app/Models/Team.php`
- Delete: `app/Models/Membership.php`
- Delete: `app/Models/TeamInvitation.php`
- Delete: `app/Enums/TeamPermission.php`
- Delete: `app/Enums/TeamRole.php`
- Delete: `app/Data/TeamPermissions.php`
- Delete: `app/Data/UserTeam.php`
- Delete: `app/Concerns/HasTeams.php`
- Delete: `app/Concerns/GeneratesUniqueTeamSlugs.php`
- Delete: `app/Actions/Teams/CreateTeam.php`
- Modify: `app/Models/User.php`
- Modify: `app/Actions/Fortify/CreateNewUser.php`

**Interfaces:**
- Produces: `User` model with no team relations/fillable — every later task that touches `User` (factories, tests, DashboardController) assumes no `current_team_id`, `currentTeam`, `teams()`, `ownedTeams()`, `teamMemberships()`, `switchTeam()`, `personalTeam()`, `belongsToTeam()`.

- [ ] **Step 1: Delete the team model, enum, data-object, and concern files**

```bash
git rm app/Models/Team.php app/Models/Membership.php app/Models/TeamInvitation.php
git rm app/Enums/TeamPermission.php app/Enums/TeamRole.php
git rm app/Data/TeamPermissions.php app/Data/UserTeam.php
git rm app/Concerns/HasTeams.php app/Concerns/GeneratesUniqueTeamSlugs.php
git rm -r app/Actions/Teams
```

- [ ] **Step 2: Rewrite `app/Models/User.php` without the `HasTeams` trait**

```php
<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }
}
```

- [ ] **Step 3: Rewrite `app/Actions/Fortify/CreateNewUser.php` without team creation**

```php
<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        return User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);
    }
}
```

- [ ] **Step 4: Format with Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 5: Commit**

```bash
git add -A app/Models/User.php app/Actions/Fortify/CreateNewUser.php
git commit -m "refactor: remove team domain layer (models, enums, concerns, actions)"
```

---

### Task 2: Remove team HTTP layer and simplify Fortify redirect responses

**Files:**
- Delete: `app/Http/Controllers/Teams/TeamController.php`
- Delete: `app/Http/Controllers/Teams/TeamInvitationController.php`
- Delete: `app/Http/Controllers/Teams/TeamMemberController.php`
- Delete: `app/Http/Requests/Teams/CreateTeamInvitationRequest.php`
- Delete: `app/Http/Requests/Teams/DeleteTeamRequest.php`
- Delete: `app/Http/Requests/Teams/RespondToTeamInvitationRequest.php`
- Delete: `app/Http/Requests/Teams/SaveTeamRequest.php`
- Delete: `app/Http/Requests/Teams/UpdateTeamMemberRequest.php`
- Delete: `app/Http/Responses/Concerns/RedirectsToCurrentTeam.php`
- Delete: `app/Http/Middleware/EnsureTeamMembership.php`
- Delete: `app/Http/Middleware/SetTeamUrlDefaults.php`
- Delete: `app/Policies/TeamPolicy.php`
- Delete: `app/Rules/TeamName.php`
- Delete: `app/Rules/UniqueTeamInvitation.php`
- Delete: `app/Rules/ValidTeamInvitation.php`
- Delete: `app/Notifications/Teams/TeamInvitation.php`
- Modify: `app/Http/Controllers/DashboardController.php`
- Modify: `app/Http/Responses/LoginResponse.php`
- Modify: `app/Http/Responses/PasskeyLoginResponse.php`
- Modify: `app/Http/Responses/RegisterResponse.php`
- Modify: `app/Http/Responses/TwoFactorLoginResponse.php`
- Modify: `app/Http/Responses/VerifyEmailResponse.php`
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Modify: `app/Providers/FortifyServiceProvider.php`
- Modify: `bootstrap/app.php`

**Interfaces:**
- Consumes: `Fortify::redirects(...)` (unchanged Fortify API) and `config('fortify.home')` = `/dashboard`, which after Task 3 matches the new plain `dashboard` route exactly.
- Produces: `Inertia::render('Dashboard')` with no props — Task 6's `Dashboard.vue` must accept zero props.

- [ ] **Step 1: Delete the team controllers, requests, responses trait, middleware, policy, rules, and notification**

```bash
git rm -r app/Http/Controllers/Teams
git rm -r app/Http/Requests/Teams
git rm -r app/Http/Responses/Concerns
git rm app/Http/Middleware/EnsureTeamMembership.php app/Http/Middleware/SetTeamUrlDefaults.php
git rm app/Policies/TeamPolicy.php
git rm app/Rules/TeamName.php app/Rules/UniqueTeamInvitation.php app/Rules/ValidTeamInvitation.php
git rm -r app/Notifications/Teams
```

- [ ] **Step 2: Rewrite `app/Http/Controllers/DashboardController.php`**

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('Dashboard');
    }
}
```

- [ ] **Step 3: Rewrite `app/Http/Responses/LoginResponse.php`**

```php
<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): Response
    {
        return $request->wantsJson()
            ? new JsonResponse(['two_factor' => false], 200)
            : redirect()->intended(Fortify::redirects('login'));
    }
}
```

- [ ] **Step 4: Rewrite `app/Http/Responses/PasskeyLoginResponse.php`**

```php
<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

class PasskeyLoginResponse implements PasskeyLoginResponseContract
{
    public function toResponse($request): Response
    {
        $redirect = Fortify::redirects('login');

        return $request->wantsJson()
            ? new JsonResponse(['redirect' => redirect()->intended($redirect)->getTargetUrl()], 200)
            : redirect()->intended($redirect);
    }
}
```

- [ ] **Step 5: Rewrite `app/Http/Responses/RegisterResponse.php`**

```php
<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

class RegisterResponse implements RegisterResponseContract
{
    public function toResponse($request): Response
    {
        return $request->wantsJson()
            ? new JsonResponse(['two_factor' => false], 201)
            : redirect()->intended(Fortify::redirects('register'));
    }
}
```

- [ ] **Step 6: Rewrite `app/Http/Responses/TwoFactorLoginResponse.php`**

```php
<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

class TwoFactorLoginResponse implements TwoFactorLoginResponseContract
{
    public function toResponse($request): Response
    {
        return $request->wantsJson()
            ? new JsonResponse(['two_factor' => false], 200)
            : redirect()->intended(Fortify::redirects('login'));
    }
}
```

- [ ] **Step 7: Rewrite `app/Http/Responses/VerifyEmailResponse.php`**

```php
<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

class VerifyEmailResponse implements VerifyEmailResponseContract
{
    public function toResponse($request): Response
    {
        return $request->wantsJson()
            ? new JsonResponse('', 204)
            : redirect()->intended(Fortify::redirects('email-verification').'?verified=1');
    }
}
```

- [ ] **Step 8: Rewrite `app/Http/Middleware/HandleInertiaRequests.php`**

```php
<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
```

- [ ] **Step 9: Rewrite `app/Providers/FortifyServiceProvider.php`**

```php
<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Responses\LoginResponse;
use App\Http\Responses\PasskeyLoginResponse;
use App\Http\Responses\RegisterResponse;
use App\Http\Responses\TwoFactorLoginResponse;
use App\Http\Responses\VerifyEmailResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(PasskeyLoginResponseContract::class, PasskeyLoginResponse::class);
        $this->app->singleton(RegisterResponseContract::class, RegisterResponse::class);
        $this->app->singleton(TwoFactorLoginResponseContract::class, TwoFactorLoginResponse::class);
        $this->app->singleton(VerifyEmailResponseContract::class, VerifyEmailResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/VerifyEmail', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(fn () => Inertia::render('auth/Register'));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/TwoFactorChallenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('passkeys', function (Request $request) {
            $credentialId = $request->input('credential.id');

            return Limit::perMinute(10)->by(
                ($credentialId ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }
}
```

- [ ] **Step 10: Remove `SetTeamUrlDefaults` from `bootstrap/app.php`**

```php
<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
```

- [ ] **Step 11: Format with Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 12: Commit**

```bash
git add -A app/Http app/Policies app/Rules app/Notifications app/Providers/FortifyServiceProvider.php bootstrap/app.php
git commit -m "refactor: remove team HTTP layer, simplify Fortify redirect responses"
```

---

### Task 3: Remove team routes, migrations, and factories

**Files:**
- Modify: `routes/web.php`
- Modify: `routes/settings.php`
- Modify: `routes/console.php`
- Delete: `database/migrations/2026_01_27_000001_create_teams_table.php`
- Delete: `database/migrations/2026_01_27_000002_add_current_team_id_to_users_table.php`
- Delete: `database/factories/TeamFactory.php`
- Delete: `database/factories/TeamInvitationFactory.php`
- Modify: `database/factories/UserFactory.php`

**Interfaces:**
- Produces: `dashboard` named route with URI `/dashboard` (no `current_team` parameter) — Task 4's tests and Task 5's Wayfinder regeneration both depend on this exact route shape.
- Produces: `users` table with no `current_team_id` column; no `teams`, `team_members`, `team_invitations` tables.

- [ ] **Step 1: Rewrite `routes/web.php`**

```php
<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
});

require __DIR__.'/settings.php';
```

- [ ] **Step 2: Rewrite `routes/settings.php`**

```php
<?php

use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
```

- [ ] **Step 3: Empty `routes/console.php`** (the only content was the team-invitation pruning schedule)

```php
<?php
```

- [ ] **Step 4: Delete the team migrations and factories**

```bash
git rm database/migrations/2026_01_27_000001_create_teams_table.php
git rm database/migrations/2026_01_27_000002_add_current_team_id_to_users_table.php
git rm database/factories/TeamFactory.php database/factories/TeamInvitationFactory.php
```

- [ ] **Step 5: Rewrite `database/factories/UserFactory.php` without team bootstrap**

```php
<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
```

- [ ] **Step 6: Refresh the local database schema**

```bash
php artisan migrate:fresh
```

Expected: migrations run cleanly with no `teams`/`team_members`/`team_invitations`/`current_team_id` artifacts.

- [ ] **Step 7: Format with Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 8: Commit**

```bash
git add -A routes database/migrations database/factories
git commit -m "refactor: remove team routes, migrations, and factories"
```

---

### Task 4: Update backend tests

**Files:**
- Delete: `tests/Feature/Teams/` (whole directory: `PruneExpiredTeamInvitationsTest.php`, `TeamInvitationTest.php`, `TeamMemberTest.php`, `TeamTest.php`)
- Modify: `tests/Feature/DashboardTest.php`
- Modify: `tests/Feature/Auth/AuthenticationTest.php`
- Modify: `tests/Feature/Auth/RegistrationTest.php`
- Modify: `tests/Feature/Auth/EmailVerificationTest.php`

**Interfaces:**
- Consumes: `route('dashboard')` with no parameters (Task 3), `Inertia::render('Dashboard')` with no props (Task 2).

- [ ] **Step 1: Delete the Teams test directory**

```bash
git rm -r tests/Feature/Teams
```

- [ ] **Step 2: Rewrite `tests/Feature/DashboardTest.php`**

```php
<?php

use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertOk();
});
```

- [ ] **Step 3: Rewrite `tests/Feature/Auth/AuthenticationTest.php`**

```php
<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Features;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse;

test('login screen can be rendered', function () {
    $response = $this->get(route('login'));

    $response->assertOk();
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard'));
});

test('passkey login response redirects to the dashboard', function () {
    $user = User::factory()->create();

    $request = Request::create(route('login', absolute: false), 'GET', server: [
        'HTTP_ACCEPT' => 'application/json',
    ]);
    $request->setLaravelSession($this->app['session.store']);
    $request->setUserResolver(fn () => $user);

    $jsonResponse = app(PasskeyLoginResponse::class)->toResponse($request);

    expect($jsonResponse->getData()->redirect)->toBe(route('dashboard'));
});

test('users with two factor enabled are redirected to two factor challenge', function () {
    if (! Features::canManageTwoFactorAuthentication()) {
        $this->markTestSkipped('Two-factor authentication is not enabled.');
    }

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->withTwoFactor()->create();

    $response = $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('two-factor.login'));
    $response->assertSessionHas('login.id', $user->id);
    $this->assertGuest();
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $this->assertGuest();
    $response->assertRedirect(route('home'));
});

test('users are rate limited', function () {
    $user = User::factory()->create();

    RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 5);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertTooManyRequests();
});
```

- [ ] **Step 4: Rewrite `tests/Feature/Auth/RegistrationTest.php`**

```php
<?php

use App\Models\User;

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();

    $user = User::where('email', 'test@example.com')->first();
    $response->assertRedirect(route('dashboard'));
});
```

- [ ] **Step 5: Rewrite `tests/Feature/Auth/EmailVerificationTest.php`**

```php
<?php

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;

test('email verification screen can be rendered', function () {
    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)->get(route('verification.notice'));

    $response->assertOk();
});

test('email can be verified', function () {
    $user = User::factory()->unverified()->create();

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    $response = $this->actingAs($user)->get($verificationUrl);

    Event::assertDispatched(Verified::class);
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    $response->assertRedirect('/dashboard?verified=1');
});

test('email is not verified with invalid hash', function () {
    $user = User::factory()->unverified()->create();

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1('wrong-email')],
    );

    $this->actingAs($user)->get($verificationUrl);

    Event::assertNotDispatched(Verified::class);
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('email is not verified with invalid user id', function () {
    $user = User::factory()->unverified()->create();

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => 123, 'hash' => sha1($user->email)],
    );

    $this->actingAs($user)->get($verificationUrl);

    Event::assertNotDispatched(Verified::class);
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('verified user is redirected to dashboard from verification prompt', function () {
    $user = User::factory()->create();

    Event::fake();

    $response = $this->actingAs($user)->get(route('verification.notice'));

    Event::assertNotDispatched(Verified::class);
    $response->assertRedirect('/dashboard');
});

test('already verified user visiting verification link is redirected without firing event again', function () {
    $user = User::factory()->create();

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    $this->actingAs($user)->get($verificationUrl)
        ->assertRedirect('/dashboard?verified=1');

    Event::assertNotDispatched(Verified::class);
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});
```

- [ ] **Step 6: Run the full backend test suite**

```bash
php artisan test --compact
```

Expected: PASS, no failures, no references to removed `Team`/`TeamInvitation` classes.

- [ ] **Step 7: Commit**

```bash
git add -A tests
git commit -m "test: update backend tests for teams removal"
```

---

### Task 5: Regenerate Wayfinder-generated frontend route/action files

**Files:**
- Regenerated (by tool, not hand-edited): `resources/js/actions/**`, `resources/js/routes/**`

**Interfaces:**
- Consumes: the route table produced by Task 3 (`web.php`, `settings.php`). Wayfinder introspects registered routes to generate these files, so this task must run after Task 3.
- Produces: `dashboard()` helper in `resources/js/routes/index.ts` that takes no arguments (was `dashboard(current_team)`); no `resources/js/routes/teams/` or `resources/js/actions/App/Http/Controllers/Teams/` directories.

- [ ] **Step 1: Regenerate the Wayfinder files**

```bash
php artisan wayfinder:generate --with-form
```

Expected: command exits 0. The existing generated files use form-variant helpers (e.g. `store.form`), so `--with-form` must be passed to match the current output style (this mirrors `wayfinder({ formVariants: true })` in `vite.config.ts`).

- [ ] **Step 2: Verify the stale team files are gone**

```bash
ls resources/js/routes/teams 2>&1
ls resources/js/actions/App/Http/Controllers/Teams 2>&1
```

Expected: both report "No such file or directory" — Wayfinder deletes stale generated files for routes that no longer exist.

- [ ] **Step 3: Spot-check the regenerated dashboard route helper**

```bash
grep -n "current_team" resources/js/routes/index.ts
```

Expected: no matches (the `dashboard` export now takes no `current_team` argument).

- [ ] **Step 4: Commit**

```bash
git add -A resources/js/actions resources/js/routes
git commit -m "chore: regenerate wayfinder routes/actions after teams removal"
```

---

### Task 6: Remove team references from the Vue frontend

**Files:**
- Delete: `resources/js/components/CancelInvitationModal.vue`
- Delete: `resources/js/components/CreateTeamModal.vue`
- Delete: `resources/js/components/DeleteTeamModal.vue`
- Delete: `resources/js/components/InviteMemberModal.vue`
- Delete: `resources/js/components/LeaveTeamModal.vue`
- Delete: `resources/js/components/PendingInvitationsModal.vue`
- Delete: `resources/js/components/RemoveMemberModal.vue`
- Delete: `resources/js/components/TeamInvitationAlert.vue`
- Delete: `resources/js/components/TeamSwitcher.vue`
- Delete: `resources/js/pages/teams/` (Index.vue, Edit.vue)
- Delete: `resources/js/types/teams.ts`
- Modify: `resources/js/components/AppHeader.vue`
- Modify: `resources/js/components/AppSidebar.vue`
- Modify: `resources/js/components/UserInfo.vue`
- Modify: `resources/js/components/NavUser.vue`
- Modify: `resources/js/pages/Dashboard.vue`
- Modify: `resources/js/pages/Welcome.vue`
- Modify: `resources/js/layouts/settings/Layout.vue`
- Modify: `resources/js/pages/auth/Login.vue`
- Modify: `resources/js/pages/auth/Register.vue`
- Modify: `resources/js/types/index.ts`
- Modify: `resources/js/types/global.d.ts`
- Modify: `resources/js/app.ts`

**Interfaces:**
- Consumes: `dashboard()` (Task 5, no-arg), `Dashboard` page rendered with no props (Task 2).

- [ ] **Step 1: Delete the team-only components, pages, and types file**

```bash
git rm resources/js/components/CancelInvitationModal.vue resources/js/components/CreateTeamModal.vue resources/js/components/DeleteTeamModal.vue resources/js/components/InviteMemberModal.vue resources/js/components/LeaveTeamModal.vue resources/js/components/PendingInvitationsModal.vue resources/js/components/RemoveMemberModal.vue resources/js/components/TeamInvitationAlert.vue resources/js/components/TeamSwitcher.vue
git rm -r resources/js/pages/teams
git rm resources/js/types/teams.ts
```

- [ ] **Step 2: Rewrite `resources/js/components/AppHeader.vue`**

```vue
<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { BookOpen, Folder, LayoutGrid, Menu, Search } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    NavigationMenu,
    NavigationMenuItem,
    NavigationMenuList,
    navigationMenuTriggerStyle,
} from '@/components/ui/navigation-menu';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { getInitials } from '@/composables/useInitials';
import { toUrl } from '@/lib/utils';
import { dashboard } from '@/routes';
import type { BreadcrumbItem, NavItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

const props = withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const page = usePage();
const auth = computed(() => page.props.auth);
const { isCurrentUrl, whenCurrentUrl } = useCurrentUrl();

const dashboardUrl = computed(() => dashboard().url);

const activeItemStyles =
    'text-neutral-900 dark:bg-neutral-800 dark:text-neutral-100';

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: 'Dashboard',
        href: dashboardUrl.value,
        icon: LayoutGrid,
    },
]);

const rightNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/vue-starter-kit',
        icon: Folder,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#vue',
        icon: BookOpen,
    },
];
</script>

<template>
    <div>
        <div class="border-b border-sidebar-border/80">
            <div class="mx-auto flex h-16 items-center px-4 md:max-w-7xl">
                <!-- Mobile Menu -->
                <div class="lg:hidden">
                    <Sheet>
                        <SheetTrigger :as-child="true">
                            <Button
                                variant="ghost"
                                size="icon"
                                class="mr-2 h-9 w-9"
                            >
                                <Menu class="h-5 w-5" />
                            </Button>
                        </SheetTrigger>
                        <SheetContent side="left" class="w-[300px] p-6">
                            <SheetTitle class="sr-only"
                                >Navigation menu</SheetTitle
                            >
                            <SheetHeader class="flex justify-start text-left">
                                <AppLogoIcon
                                    class="size-6 fill-current text-black dark:text-white"
                                />
                            </SheetHeader>
                            <div
                                class="flex h-full flex-1 flex-col justify-between space-y-4 py-6"
                            >
                                <nav class="-mx-3 space-y-1">
                                    <Link
                                        v-for="item in mainNavItems"
                                        :key="item.title"
                                        :href="item.href"
                                        class="flex items-center gap-x-3 rounded-lg px-3 py-2 text-sm font-medium hover:bg-accent"
                                        :class="
                                            whenCurrentUrl(
                                                item.href,
                                                activeItemStyles,
                                            )
                                        "
                                    >
                                        <component
                                            v-if="item.icon"
                                            :is="item.icon"
                                            class="h-5 w-5"
                                        />
                                        {{ item.title }}
                                    </Link>
                                </nav>
                                <div class="flex flex-col space-y-4">
                                    <a
                                        v-for="item in rightNavItems"
                                        :key="item.title"
                                        :href="toUrl(item.href)"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="flex items-center space-x-2 text-sm font-medium"
                                    >
                                        <component
                                            v-if="item.icon"
                                            :is="item.icon"
                                            class="h-5 w-5"
                                        />
                                        <span>{{ item.title }}</span>
                                    </a>
                                </div>
                            </div>
                        </SheetContent>
                    </Sheet>
                </div>

                <Link :href="dashboardUrl" class="flex items-center gap-x-2">
                    <AppLogo />
                </Link>

                <!-- Desktop Menu -->
                <div class="hidden h-full lg:flex lg:flex-1">
                    <NavigationMenu class="ml-10 flex h-full items-stretch">
                        <NavigationMenuList
                            class="flex h-full items-stretch space-x-2"
                        >
                            <NavigationMenuItem
                                v-for="(item, index) in mainNavItems"
                                :key="index"
                                class="relative flex h-full items-center"
                            >
                                <Link
                                    :class="[
                                        navigationMenuTriggerStyle(),
                                        whenCurrentUrl(
                                            item.href,
                                            activeItemStyles,
                                        ),
                                        'h-9 cursor-pointer px-3',
                                    ]"
                                    :href="item.href"
                                >
                                    <component
                                        v-if="item.icon"
                                        :is="item.icon"
                                        class="mr-2 h-4 w-4"
                                    />
                                    {{ item.title }}
                                </Link>
                                <div
                                    v-if="isCurrentUrl(item.href)"
                                    class="absolute bottom-0 left-0 h-0.5 w-full translate-y-px bg-black dark:bg-white"
                                ></div>
                            </NavigationMenuItem>
                        </NavigationMenuList>
                    </NavigationMenu>
                </div>

                <div class="ml-auto flex items-center space-x-2">
                    <div class="relative flex items-center space-x-1">
                        <Button
                            variant="ghost"
                            size="icon"
                            class="group h-9 w-9 cursor-pointer"
                        >
                            <Search
                                class="size-5 opacity-80 group-hover:opacity-100"
                            />
                        </Button>

                        <div class="hidden space-x-1 lg:flex">
                            <template
                                v-for="item in rightNavItems"
                                :key="item.title"
                            >
                                <TooltipProvider :delay-duration="0">
                                    <Tooltip>
                                        <TooltipTrigger>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                as-child
                                                class="group h-9 w-9 cursor-pointer"
                                            >
                                                <a
                                                    :href="toUrl(item.href)"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                >
                                                    <span class="sr-only">{{
                                                        item.title
                                                    }}</span>
                                                    <component
                                                        :is="item.icon"
                                                        class="size-5 opacity-80 group-hover:opacity-100"
                                                    />
                                                </a>
                                            </Button>
                                        </TooltipTrigger>
                                        <TooltipContent>
                                            <p>{{ item.title }}</p>
                                        </TooltipContent>
                                    </Tooltip>
                                </TooltipProvider>
                            </template>
                        </div>
                    </div>

                    <DropdownMenu>
                        <DropdownMenuTrigger :as-child="true">
                            <Button
                                variant="ghost"
                                size="icon"
                                class="relative size-10 w-auto rounded-full p-1 focus-within:ring-2 focus-within:ring-primary"
                            >
                                <Avatar
                                    class="size-8 overflow-hidden rounded-full"
                                >
                                    <AvatarImage
                                        v-if="auth.user.avatar"
                                        :src="auth.user.avatar"
                                        :alt="auth.user.name"
                                    />
                                    <AvatarFallback
                                        class="rounded-lg bg-neutral-200 font-semibold text-black dark:bg-neutral-700 dark:text-white"
                                    >
                                        {{ getInitials(auth.user?.name) }}
                                    </AvatarFallback>
                                </Avatar>
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-56">
                            <UserMenuContent :user="auth.user" />
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>
        </div>

        <div
            v-if="props.breadcrumbs.length > 1"
            class="flex w-full border-b border-sidebar-border/70"
        >
            <div
                class="mx-auto flex h-12 w-full items-center justify-start px-4 text-neutral-500 md:max-w-7xl"
            >
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
            </div>
        </div>
    </div>
</template>
```

- [ ] **Step 3: Rewrite `resources/js/components/AppSidebar.vue`**

```vue
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { BookOpen, FolderGit2, LayoutGrid } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const dashboardUrl = computed(() => dashboard().url);

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: 'Dashboard',
        href: dashboardUrl.value,
        icon: LayoutGrid,
    },
]);

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/vue-starter-kit',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#vue',
        icon: BookOpen,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboardUrl">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
```

- [ ] **Step 4: Rewrite `resources/js/components/UserInfo.vue`**

```vue
<script setup lang="ts">
import { computed } from 'vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/composables/useInitials';
import type { User } from '@/types';

type Props = {
    user: User;
    showEmail?: boolean;
};

const props = withDefaults(defineProps<Props>(), {
    showEmail: false,
});

const { getInitials } = useInitials();

const showAvatar = computed(
    () => props.user.avatar && props.user.avatar !== '',
);
</script>

<template>
    <Avatar class="h-8 w-8 overflow-hidden rounded-lg">
        <AvatarImage v-if="showAvatar" :src="user.avatar!" :alt="user.name" />
        <AvatarFallback class="rounded-lg text-black dark:text-white">
            {{ getInitials(user.name) }}
        </AvatarFallback>
    </Avatar>

    <div class="grid flex-1 text-left text-sm leading-tight">
        <span class="truncate font-medium">{{ user.name }}</span>
        <span
            v-if="showEmail"
            class="truncate text-xs text-muted-foreground"
            >{{ user.email }}</span
        >
    </div>
</template>
```

- [ ] **Step 5: Rewrite `resources/js/components/NavUser.vue`**

```vue
<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { ChevronsUpDown } from '@lucide/vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import UserInfo from '@/components/UserInfo.vue';
import UserMenuContent from '@/components/UserMenuContent.vue';

const page = usePage();
const user = page.props.auth.user;
const { isMobile, state } = useSidebar();
</script>

<template>
    <SidebarMenu>
        <SidebarMenuItem>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <SidebarMenuButton
                        size="lg"
                        class="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground"
                        data-test="sidebar-menu-button"
                    >
                        <UserInfo :user="user" />
                        <ChevronsUpDown class="ml-auto size-4" />
                    </SidebarMenuButton>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="w-(--reka-dropdown-menu-trigger-width) min-w-56 rounded-lg"
                    :side="
                        isMobile
                            ? 'bottom'
                            : state === 'collapsed'
                              ? 'left'
                              : 'bottom'
                    "
                    align="end"
                    :side-offset="4"
                >
                    <UserMenuContent :user="user" />
                </DropdownMenuContent>
            </DropdownMenu>
        </SidebarMenuItem>
    </SidebarMenu>
</template>
```

- [ ] **Step 6: Rewrite `resources/js/pages/Dashboard.vue`**

```vue
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import { dashboard } from '@/routes';

defineOptions({
    layout: () => ({
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    }),
});
</script>

<template>
    <Head title="Dashboard" />

    <div
        class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4"
    >
        <div class="grid auto-rows-min gap-4 md:grid-cols-3">
            <div
                class="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
            >
                <PlaceholderPattern />
            </div>
            <div
                class="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
            >
                <PlaceholderPattern />
            </div>
            <div
                class="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
            >
                <PlaceholderPattern />
            </div>
        </div>
        <div
            class="relative min-h-[100vh] flex-1 rounded-xl border border-sidebar-border/70 md:min-h-min dark:border-sidebar-border"
        >
            <PlaceholderPattern />
        </div>
    </div>
</template>
```

- [ ] **Step 7: Update `resources/js/pages/Welcome.vue`'s script block**

Use the Edit tool to replace only the `<script setup>` block (the template's decorative SVG markup is unchanged):

Old:
```vue
<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { dashboard, login } from '@/routes';
import { register } from '@/routes';

const page = usePage();
const dashboardUrl = computed(() =>
    page.props.currentTeam ? dashboard(page.props.currentTeam.slug).url : '/',
);
</script>
```

New:
```vue
<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { dashboard, login } from '@/routes';
import { register } from '@/routes';

const page = usePage();
const dashboardUrl = computed(() => dashboard().url);
</script>
```

- [ ] **Step 8: Update `resources/js/layouts/settings/Layout.vue`'s script block**

Old:
```vue
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import { index as teams } from '@/routes/teams';
import type { NavItem } from '@/types';

const sidebarNavItems: NavItem[] = [
    {
        title: 'Profile',
        href: editProfile(),
    },
    {
        title: 'Security',
        href: editSecurity(),
    },
    {
        title: 'Teams',
        href: teams(),
    },
    {
        title: 'Appearance',
        href: editAppearance(),
    },
];

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>
```

New:
```vue
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

const sidebarNavItems: NavItem[] = [
    {
        title: 'Profile',
        href: editProfile(),
    },
    {
        title: 'Security',
        href: editSecurity(),
    },
    {
        title: 'Appearance',
        href: editAppearance(),
    },
];

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>
```

- [ ] **Step 9: Rewrite `resources/js/pages/auth/Login.vue`**

```vue
<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasskeyVerify from '@/components/PasskeyVerify.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

defineOptions({
    layout: {
        title: 'Log in to your account',
        description: 'Enter your email and password below to log in',
    },
});

defineProps<{
    status?: string;
    canResetPassword: boolean;
}>();
</script>

<template>
    <Head title="Log in" />

    <div
        v-if="status"
        class="mb-4 text-center text-sm font-medium text-green-600"
    >
        {{ status }}
    </div>

    <PasskeyVerify />

    <Form
        v-bind="store.form()"
        :reset-on-success="['password']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    required
                    autofocus
                    :tabindex="1"
                    autocomplete="email"
                    placeholder="email@example.com"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <div class="flex items-center justify-between">
                    <Label for="password">Password</Label>
                    <TextLink
                        v-if="canResetPassword"
                        :href="request()"
                        class="text-sm"
                        :tabindex="5"
                    >
                        Forgot password?
                    </TextLink>
                </div>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    :tabindex="2"
                    autocomplete="current-password"
                    placeholder="Password"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="flex items-center justify-between">
                <Label for="remember" class="flex items-center space-x-3">
                    <Checkbox id="remember" name="remember" :tabindex="3" />
                    <span>Remember me</span>
                </Label>
            </div>

            <Button
                type="submit"
                class="mt-4 w-full"
                :tabindex="4"
                :disabled="processing"
                data-test="login-button"
            >
                <Spinner v-if="processing" />
                Log in
            </Button>
        </div>

        <div class="text-center text-sm text-muted-foreground">
            Don't have an account?
            <TextLink
                :href="register()"
                :tabindex="5"
                data-test="register-link"
            >
                Sign up
            </TextLink>
        </div>
    </Form>
</template>
```

- [ ] **Step 10: Rewrite `resources/js/pages/auth/Register.vue`**

```vue
<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { store } from '@/routes/register';

defineProps<{
    passwordRules: string;
}>();

defineOptions({
    layout: {
        title: 'Create an account',
        description: 'Enter your details below to create your account',
    },
});
</script>

<template>
    <Head title="Register" />

    <Form
        v-bind="store.form()"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    type="text"
                    required
                    autofocus
                    :tabindex="1"
                    autocomplete="name"
                    name="name"
                    placeholder="Full name"
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    required
                    :tabindex="2"
                    autocomplete="email"
                    name="email"
                    placeholder="email@example.com"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="password">Password</Label>
                <PasswordInput
                    id="password"
                    required
                    :tabindex="3"
                    autocomplete="new-password"
                    name="password"
                    placeholder="Password"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Confirm password</Label>
                <PasswordInput
                    id="password_confirmation"
                    required
                    :tabindex="4"
                    autocomplete="new-password"
                    name="password_confirmation"
                    placeholder="Confirm password"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <Button
                type="submit"
                class="mt-2 w-full"
                tabindex="5"
                :disabled="processing"
                data-test="register-user-button"
            >
                <Spinner v-if="processing" />
                Create account
            </Button>
        </div>

        <div class="text-center text-sm text-muted-foreground">
            Already have an account?
            <TextLink
                :href="login()"
                class="underline underline-offset-4"
                :tabindex="6"
            >
                Log in
            </TextLink>
        </div>
    </Form>
</template>
```

- [ ] **Step 11: Rewrite `resources/js/types/index.ts`**

```ts
export * from './auth';
export * from './navigation';
export * from './ui';
```

- [ ] **Step 12: Rewrite `resources/js/types/global.d.ts`**

```ts
import type { Auth } from '@/types/auth';

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}

declare module 'vue' {
    interface ComponentCustomProperties {
        $inertia: typeof Router;
        $page: Page;
        $headManager: ReturnType<typeof createHeadManager>;
    }
}
```

- [ ] **Step 13: Update `resources/js/app.ts`'s layout switch**

Old:
```ts
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
            case name.startsWith('teams/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
```

New:
```ts
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
```

- [ ] **Step 14: Run frontend lint, type-check, and build**

```bash
npm run lint:check
npm run types:check
npm run build
```

Expected: all three exit 0 with no errors and no remaining references to `Team`, `TeamInvitation`, `TeamSwitcher`, `currentTeam`, or `pendingInvitations`.

- [ ] **Step 15: Commit**

```bash
git add -A resources/js
git commit -m "refactor: remove team references from the Vue frontend"
```

---

### Task 7: Final verification sweep

**Files:** none (verification only)

- [ ] **Step 1: Grep for any leftover team references across the whole tracked tree**

```bash
git grep -ni "team" -- ':!docs/superpowers/plans/*'
```

Expected: no matches. If any appear, fix them before proceeding (this is the final safety net after Tasks 1-6).

- [ ] **Step 2: Run the full backend check (matches `composer test`)**

```bash
php artisan config:clear --ansi
vendor/bin/pint --parallel --test
phpstan analyse
php artisan test
```

Expected: all four pass with zero errors/failures.

- [ ] **Step 3: Run the full frontend check (matches `npm run ci:check` equivalents)**

```bash
npm run lint:check
npm run format:check
npm run types:check
```

Expected: all three pass with zero errors.

- [ ] **Step 4: Manually verify the app boots (optional but recommended)**

```bash
composer run dev
```

Visit `/`, register a new account, confirm redirect to `/dashboard`, log out, log back in, confirm `/dashboard` renders with no team switcher in the sidebar/header and no "Teams" entry under Settings. Stop the dev server (Ctrl+C) when done.

- [ ] **Step 5: Commit any final fixups**

```bash
git add -A
git commit -m "chore: final cleanup pass after teams removal"
```
