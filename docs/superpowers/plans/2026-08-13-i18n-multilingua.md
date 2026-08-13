# Multilingua (EN / IT / ES) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the entire biglins application (frontend + backend) multilingual in English (default), Italian, and Spanish, with the language chosen per-user in Settings and persisted on the account.

**Architecture:** A `locale` column on `users` drives a `SetLocale` middleware that calls `App::setLocale()` per request (falling back to browser `Accept-Language` for guests, then `en`). The resolved locale is shared to Inertia as `locale`/`locales`. The Vue frontend uses `vue-i18n` (Composition API, global injection) seeded from that shared prop, with translation dictionaries in `resources/js/lang/{en,it,es}.ts`. Laravel's own translation files (`lang/{it,es}/validation.php` etc. and `lang/{it,es}.json` for literal-string `__()` calls) cover backend validation messages and Fortify's built-in auth emails.

**Tech Stack:** Laravel 13, Inertia Laravel v3, Fortify v1, Vue 3.5, `vue-i18n` (new dependency), Pest 5, vue-tsc.

**Spec:** `docs/superpowers/specs/2026-08-13-i18n-multilingua-design.md`

## Global Constraints

- Three supported locales everywhere: `en` (default/fallback), `it`, `es`.
- The per-invoice document language (`invoices.language`, already `it`/`en`/`es`) is a separate concept from the UI locale and is never touched by this plan.
- Frontend "test cycle" for any task that only touches Vue/TS files: `npm run types:check` (must pass — this also structurally validates that `it.ts`/`es.ts` match the `en.ts` key shape, since they're typed as `typeof en`) and `npm run lint:check`.
- Backend "test cycle": `php artisan test --compact --filter=<Name>` for the relevant test file, run after every backend task.
- Every PHP file touched must be run through `vendor/bin/pint --dirty --format agent` before committing (per project convention).
- Commit after every task with a `feat:`/`chore:` prefix as appropriate.

---

## Task 1: `locale` column on `users`

**Files:**
- Create: `database/migrations/2026_08_13_000001_add_locale_to_users_table.php`
- Modify: `app/Models/User.php`
- Test: `tests/Feature/Settings/LanguageTest.php` (created fully in Task 4; this task only needs the column to exist, verified via existing `UserFactory`)

**Interfaces:**
- Produces: `users.locale` column, string, default `'en'`, not nullable. `User::$locale` property (via `#[Fillable]` set that already includes it from Task 4 on — this task does NOT add `locale` to `$fillable` yet, since mass-assignment only happens through the dedicated `LanguageController` in Task 4, which will assign it explicitly with `$user->locale = ...`).

- [ ] **Step 1: Create the migration**

Run: `php artisan make:migration add_locale_to_users_table --table=users --no-interaction`

- [ ] **Step 2: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('locale')->default('en')->after('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('locale');
        });
    }
};
```

- [ ] **Step 3: Update the `User` model PHPDoc**

In `app/Models/User.php`, add the property to the existing PHPDoc block (do not add `locale` to `#[Fillable]` — see Task 4):

```php
/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $locale
 * @property string $password
 ...
```

- [ ] **Step 4: Run the migration**

Run: `php artisan migrate --no-interaction`
Expected: `add_locale_to_users_table` migrated successfully.

- [ ] **Step 5: Run pint and the existing test suite smoke check**

Run: `vendor/bin/pint --dirty --format agent`
Run: `php artisan test --compact --filter=ProfileUpdateTest`
Expected: PASS (confirms the new column doesn't break existing user factories/tests).

- [ ] **Step 6: Commit**

```bash
git add database/migrations app/Models/User.php
git commit -m "feat: add locale column to users table"
```

---

## Task 2: Backend locale resolution (`SetLocale` middleware + shared Inertia props)

**Files:**
- Create: `app/Http/Middleware/SetLocale.php`
- Modify: `bootstrap/app.php`
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Modify: `resources/js/types/global.d.ts`
- Test: `tests/Feature/SetLocaleTest.php`

**Interfaces:**
- Consumes: `User::$locale` (Task 1).
- Produces: `App::getLocale()` correctly resolved before controllers run; Inertia shared props `locale: string` and `locales: string[]` (`['en', 'it', 'es']`), consumed by the frontend from Task 3 onward.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\User;

test('authenticated user locale is applied to the app', function () {
    $user = User::factory()->create(['locale' => 'it']);

    $this->actingAs($user)->get('/dashboard');

    expect(app()->getLocale())->toBe('it');
});

test('guest locale falls back to Accept-Language header', function () {
    $this->withHeaders(['Accept-Language' => 'es-ES,es;q=0.9'])
        ->get('/login');

    expect(app()->getLocale())->toBe('es');
});

test('guest locale defaults to en when Accept-Language is unsupported or missing', function () {
    $this->withHeaders(['Accept-Language' => 'fr-FR,fr;q=0.9'])
        ->get('/login');

    expect(app()->getLocale())->toBe('en');
});

test('locale and locales are shared with every Inertia response', function () {
    $user = User::factory()->create(['locale' => 'it']);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertInertia(fn ($page) => $page
        ->where('locale', 'it')
        ->where('locales', ['en', 'it', 'es'])
    );
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=SetLocaleTest`
Expected: FAIL (`SetLocale` middleware doesn't exist yet, `locale`/`locales` not shared).

- [ ] **Step 3: Write the middleware**

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * @var list<string>
     */
    public const SUPPORTED_LOCALES = ['en', 'it', 'es'];

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale($this->resolveLocale($request));

        return $next($request);
    }

    private function resolveLocale(Request $request): string
    {
        if ($request->user() !== null) {
            return $request->user()->locale;
        }

        $preferred = $request->getPreferredLanguage(self::SUPPORTED_LOCALES);

        return $preferred ?? config('app.locale');
    }
}
```

- [ ] **Step 4: Register the middleware in the `web` group**

In `bootstrap/app.php`, add the import and register it before `HandleInertiaRequests`:

```php
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocale;
```

```php
        $middleware->web(append: [
            SetLocale::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
```

- [ ] **Step 5: Share `locale`/`locales` with Inertia**

In `app/Http/Middleware/HandleInertiaRequests.php`:

```php
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'version' => config('app.version'),
            'auth' => [
                'user' => $request->user(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'locale' => app()->getLocale(),
            'locales' => SetLocale::SUPPORTED_LOCALES,
        ];
    }
```

Add `use App\Http\Middleware\SetLocale;` to the top of the file.

- [ ] **Step 6: Add the frontend types**

In `resources/js/types/global.d.ts`, extend `sharedPageProps`:

```ts
declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            version: string | null;
            auth: Auth;
            sidebarOpen: boolean;
            locale: string;
            locales: string[];
            [key: string]: unknown;
        };
    }
}
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --compact --filter=SetLocaleTest`
Expected: PASS

- [ ] **Step 8: Pint, types check, commit**

```bash
vendor/bin/pint --dirty --format agent
npm run types:check
git add app/Http/Middleware/SetLocale.php bootstrap/app.php app/Http/Middleware/HandleInertiaRequests.php resources/js/types/global.d.ts tests/Feature/SetLocaleTest.php
git commit -m "feat: resolve and share the active locale per request"
```

---

## Task 3: Install vue-i18n, translation file skeleton, wire `app.ts`

**Files:**
- Modify: `package.json` (new dependency)
- Create: `resources/js/lang/en.ts`
- Create: `resources/js/lang/it.ts`
- Create: `resources/js/lang/es.ts`
- Modify: `resources/js/app.ts`
- Modify: `resources/js/components/AppearanceTabs.vue`

**Interfaces:**
- Consumes: Inertia shared prop `locale` (Task 2, typed via `global.d.ts`).
- Produces: `resources/js/lang/en.ts` default-exports the canonical `messages` object and the `MessageSchema` type; `it.ts`/`es.ts` default-export objects typed as `MessageSchema` (so `npm run types:check` fails if any later task lets the three files drift out of structural sync). Global `$t()` available in every `<template>` from this task onward (via `globalInjection: true`).

- [ ] **Step 1: Install the dependency**

Run: `npm install vue-i18n`
Expected: `vue-i18n` (v10+) added to `package.json` `dependencies`.

- [ ] **Step 2: Create the English messages file**

`resources/js/lang/en.ts`:

```ts
const messages = {
    common: {
        actions: {
            save: 'Save',
            cancel: 'Cancel',
            edit: 'Edit',
            addRow: 'Add row',
            previous: 'Previous',
            next: 'Next',
        },
        fields: {
            name: 'Name',
            email: 'Email address',
            address: 'Address',
            city: 'City',
            zip: 'ZIP code',
            country: 'Country',
            selectCountry: 'Select a country',
            phone: 'Phone number',
        },
    },
    appearance: {
        light: 'Light',
        dark: 'Dark',
        system: 'System',
    },
} as const;

export default messages;
export type MessageSchema = typeof messages;
```

- [ ] **Step 3: Create the Italian messages file**

`resources/js/lang/it.ts`:

```ts
import type { MessageSchema } from '@/lang/en';

const messages: MessageSchema = {
    common: {
        actions: {
            save: 'Salva',
            cancel: 'Annulla',
            edit: 'Modifica',
            addRow: 'Aggiungi riga',
            previous: 'Precedente',
            next: 'Successivo',
        },
        fields: {
            name: 'Nome',
            email: 'Indirizzo email',
            address: 'Indirizzo',
            city: 'Città',
            zip: 'CAP',
            country: 'Paese',
            selectCountry: 'Seleziona un paese',
            phone: 'Numero di telefono',
        },
    },
    appearance: {
        light: 'Chiaro',
        dark: 'Scuro',
        system: 'Sistema',
    },
};

export default messages;
```

- [ ] **Step 4: Create the Spanish messages file**

`resources/js/lang/es.ts`:

```ts
import type { MessageSchema } from '@/lang/en';

const messages: MessageSchema = {
    common: {
        actions: {
            save: 'Guardar',
            cancel: 'Cancelar',
            edit: 'Editar',
            addRow: 'Añadir fila',
            previous: 'Anterior',
            next: 'Siguiente',
        },
        fields: {
            name: 'Nombre',
            email: 'Correo electrónico',
            address: 'Dirección',
            city: 'Ciudad',
            zip: 'Código postal',
            country: 'País',
            selectCountry: 'Selecciona un país',
            phone: 'Número de teléfono',
        },
    },
    appearance: {
        light: 'Claro',
        dark: 'Oscuro',
        system: 'Sistema',
    },
};

export default messages;
```

- [ ] **Step 5: Wire vue-i18n into `app.ts` via `withApp`**

`resources/js/app.ts` — add the imports and the `withApp` option. `withApp` is the Inertia v3 extension point for adding Vue plugins without overriding the framework's own default `resolve`/`setup` (which the `@inertiajs/vite` plugin injects automatically):

```ts
import { createInertiaApp } from '@inertiajs/vue3';
import { createI18n } from 'vue-i18n';
import { initializeTheme } from '@/composables/useAppearance';
import en from '@/lang/en';
import es from '@/lang/es';
import it from '@/lang/it';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
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
    withApp(app, { page }) {
        app.use(
            createI18n({
                legacy: false,
                globalInjection: true,
                locale: page.props.locale,
                fallbackLocale: 'en',
                messages: { en, it, es },
            }),
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
```

- [ ] **Step 6: First real usage — translate `AppearanceTabs.vue`**

Read the file first to find the `tabs` array — it currently has an entry per option with a literal `label: 'Light' | 'Dark' | 'System'` field (plus whatever `value`/icon fields it already has, which you leave untouched). Rename that field so it stores the translation *key* instead of the literal label (e.g. `labelKey: 'appearance.light'` / `'appearance.dark'` / `'appearance.system'`, matching the keys added to `en.ts`/`it.ts`/`es.ts` in Steps 2–4), keeping every other field in each entry exactly as it already is. In the template, wherever the label is currently interpolated (e.g. `{{ label }}` or `{{ tab.label }}` inside the `v-for`), call `$t(...)` on the renamed key field instead (e.g. `{{ $t(tab.labelKey) }}`) — match whatever the actual loop variable is named in the existing template.

- [ ] **Step 7: Verify**

Run: `npm run types:check`
Expected: PASS — this also confirms `it.ts`/`es.ts` structurally match `en.ts`.
Run: `npm run lint:check`
Expected: PASS
Run: `npm run build`
Expected: builds successfully.

Manually start `npm run dev` (or `composer run dev`) and confirm the Appearance tab labels still render correctly at `/settings/appearance` (they should — same English text as before, now sourced from `en.ts`).

- [ ] **Step 8: Commit**

```bash
git add package.json package-lock.json resources/js/lang resources/js/app.ts resources/js/components/AppearanceTabs.vue
git commit -m "feat: install vue-i18n and wire up locale-aware messages"
```

---

## Task 4: `LanguageController` — backend for the Settings language preference

**Files:**
- Create: `app/Http/Controllers/Settings/LanguageController.php`
- Create: `app/Http/Requests/Settings/LanguageUpdateRequest.php`
- Modify: `routes/settings.php`
- Test: `tests/Feature/Settings/LanguageTest.php`

**Interfaces:**
- Consumes: `SetLocale::SUPPORTED_LOCALES` (Task 2).
- Produces: `PUT /settings/language` (route name `language.update`) and `GET /settings/language` (route name `language.edit`, renders `settings/Language` — the Inertia page created in Task 5). Sets `$user->locale` directly.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\User;

test('language settings page is displayed', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('language.edit'));

    $response->assertOk();
});

test('user can update their locale', function () {
    $user = User::factory()->create(['locale' => 'en']);

    $response = $this->actingAs($user)->put(route('language.update'), [
        'locale' => 'it',
    ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('language.edit'));

    expect($user->refresh()->locale)->toBe('it');
});

test('locale must be one of the supported languages', function () {
    $user = User::factory()->create(['locale' => 'en']);

    $response = $this->actingAs($user)->put(route('language.update'), [
        'locale' => 'fr',
    ]);

    $response->assertSessionHasErrors('locale');
    expect($user->refresh()->locale)->toBe('en');
});

test('guests cannot update locale', function () {
    $this->put(route('language.update'), ['locale' => 'it'])
        ->assertRedirect(route('login'));
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=LanguageTest`
Expected: FAIL (route `language.edit`/`language.update` not defined).

- [ ] **Step 3: Write the form request**

```php
<?php

namespace App\Http\Requests\Settings;

use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LanguageUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'locale' => ['required', 'string', Rule::in(SetLocale::SUPPORTED_LOCALES)],
        ];
    }
}
```

- [ ] **Step 4: Write the controller**

```php
<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\LanguageUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LanguageController extends Controller
{
    /**
     * Show the user's language settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Language');
    }

    /**
     * Update the user's language preference.
     */
    public function update(LanguageUpdateRequest $request): RedirectResponse
    {
        $request->user()->update(['locale' => $request->validated('locale')]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Language updated.')]);

        return to_route('language.edit');
    }
}
```

- [ ] **Step 5: Register the routes**

In `routes/settings.php`, add the import and a new route pair inside the existing `['auth']` group (locale should be changeable without requiring `verified`, consistent with `appearance.edit`):

```php
use App\Http\Controllers\Settings\LanguageController;
```

```php
    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::get('settings/language', [LanguageController::class, 'edit'])->name('language.edit');
    Route::put('settings/language', [LanguageController::class, 'update'])->name('language.update');
});
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --compact --filter=LanguageTest`
Expected: FAIL still at this point on `assertOk()` for the edit page (the `settings/Language` Inertia component doesn't exist yet — that's Task 5). The three non-rendering tests (`user can update their locale`, `locale must be one of the supported languages`, `guests cannot update locale`) should PASS already; confirm that with:

Run: `php artisan test --compact --filter="user can update their locale"`
Run: `php artisan test --compact --filter="locale must be one of the supported languages"`
Run: `php artisan test --compact --filter="guests cannot update locale"`
Expected: all PASS.

- [ ] **Step 7: Pint, commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Settings/LanguageController.php app/Http/Requests/Settings/LanguageUpdateRequest.php routes/settings.php tests/Feature/Settings/LanguageTest.php
git commit -m "feat: add backend endpoint for updating the user's language preference"
```

---

## Task 5: `Language.vue` settings page + nav entry + instant locale switch

**Files:**
- Create: `resources/js/pages/settings/Language.vue`
- Modify: `resources/js/layouts/settings/Layout.vue`
- Modify: `app/Http/Controllers/Settings/LanguageController.php`
- Modify: `resources/js/lang/en.ts`, `resources/js/lang/it.ts`, `resources/js/lang/es.ts`

**Interfaces:**
- Consumes: `PUT /settings/language` (Task 4, via Wayfinder-generated `resources/js/actions/App/Http/Controllers/Settings/LanguageController.ts` / `resources/js/routes/language.ts` — generated automatically by the Wayfinder Vite plugin on next `npm run dev`/`build` once the route exists).
- Produces: working Settings → Language tab; on successful save, the running app's vue-i18n `locale` flips immediately without a full reload.

- [ ] **Step 1: Add the `settings.language` and `settings.nav` translation keys**

Extend `resources/js/lang/en.ts` (inside the existing `as const` object, alongside `common`/`appearance`):

```ts
    settings: {
        nav: {
            profile: 'Profile',
            security: 'Security',
            appearance: 'Appearance',
            language: 'Language',
        },
        language: {
            title: 'Language settings',
            description: 'Choose the language used across the application',
            label: 'Language',
            options: {
                en: 'English',
                it: 'Italiano',
                es: 'Español',
            },
        },
    },
```

Extend `resources/js/lang/it.ts`:

```ts
    settings: {
        nav: {
            profile: 'Profilo',
            security: 'Sicurezza',
            appearance: 'Aspetto',
            language: 'Lingua',
        },
        language: {
            title: 'Impostazioni lingua',
            description: "Scegli la lingua utilizzata in tutta l'applicazione",
            label: 'Lingua',
            options: {
                en: 'English',
                it: 'Italiano',
                es: 'Español',
            },
        },
    },
```

Extend `resources/js/lang/es.ts`:

```ts
    settings: {
        nav: {
            profile: 'Perfil',
            security: 'Seguridad',
            appearance: 'Apariencia',
            language: 'Idioma',
        },
        language: {
            title: 'Configuración de idioma',
            description: 'Elige el idioma utilizado en toda la aplicación',
            label: 'Idioma',
            options: {
                en: 'English',
                it: 'Italiano',
                es: 'Español',
            },
        },
    },
```

(The `options` values are the language names in their own native form — this is a deliberate content decision, not a translation gap: "Italiano" is written the same way regardless of which locale is currently active, same convention every language switcher uses.)

- [ ] **Step 2: Create the Language settings page**

`resources/js/pages/settings/Language.vue` — mirrors the structure of `resources/js/pages/settings/Appearance.vue`, but is a real form (not just a display component) since it posts to the backend and also flips the live vue-i18n locale:

```vue
<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import LanguageController from '@/actions/App/Http/Controllers/Settings/LanguageController';
import Heading from '@/components/Heading.vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { edit } from '@/routes/language';
import type { BreadcrumbItem } from '@/types';

const props = defineProps<{
    locale: string;
    locales: string[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Language settings', href: edit() },
        ] satisfies BreadcrumbItem[],
    },
});

const { t, locale: activeLocale } = useI18n();

function updateLocale(value: string): void {
    router.put(
        LanguageController.update.url(),
        { locale: value },
        {
            preserveScroll: true,
            onSuccess: () => {
                activeLocale.value = value;
            },
        },
    );
}
</script>

<template>
    <Head :title="t('settings.language.title')" />

    <h1 class="sr-only">{{ t('settings.language.title') }}</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            :title="t('settings.language.title')"
            :description="t('settings.language.description')"
        />

        <div class="grid gap-2">
            <Select :model-value="props.locale" @update:model-value="updateLocale">
                <SelectTrigger id="locale" class="w-full max-w-xs">
                    <SelectValue :placeholder="t('settings.language.label')" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="option in props.locales"
                        :key="option"
                        :value="option"
                    >
                        {{ t(`settings.language.options.${option}`) }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>
    </div>
</template>
```

Note: `props.locale`/`props.locales` here come from the *page* props, not the shared Inertia props — update `LanguageController::edit()` (Task 4, Step 4) to pass them explicitly since `Inertia::render('settings/Language')` currently sends no page-specific props:

```php
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Language', [
            'locale' => $request->user()->locale,
            'locales' => \App\Http\Middleware\SetLocale::SUPPORTED_LOCALES,
        ]);
    }
```

(Add `use App\Http\Middleware\SetLocale;` to the top of `LanguageController.php` instead of the fully-qualified inline reference, for consistency with the rest of the codebase.)

- [ ] **Step 3: Add the "Language" tab to the settings nav**

In `resources/js/layouts/settings/Layout.vue`:

```ts
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editLanguage } from '@/routes/language';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
```

```ts
const { t } = useI18n();

const sidebarNavItems = computed<NavItem[]>(() => [
    { title: t('settings.nav.profile'), href: editProfile() },
    { title: t('settings.nav.security'), href: editSecurity() },
    { title: t('settings.nav.appearance'), href: editAppearance() },
    { title: t('settings.nav.language'), href: editLanguage() },
]);
```

Add `import { computed } from 'vue';` and `import { useI18n } from 'vue-i18n';` to the imports, replace the existing static `const sidebarNavItems: NavItem[] = [...]` block with the computed version above (so the labels react to locale changes), and update the template's `v-for="item in sidebarNavItems"` — no change needed there since `sidebarNavItems` is still consumed the same way, just now a computed ref.

Also translate the page heading in the same file: replace `title="Settings"` / `description="Manage your profile and account settings"` with `:title="t('settings.title')"` / `:description="t('settings.description')"`, and add those two keys to `settings.*` in all three lang files:

```ts
// en.ts, inside settings:
        title: 'Settings',
        description: 'Manage your profile and account settings',
```
```ts
// it.ts, inside settings:
        title: 'Impostazioni',
        description: 'Gestisci il profilo e le impostazioni dell\'account',
```
```ts
// es.ts, inside settings:
        title: 'Configuración',
        description: 'Gestiona tu perfil y la configuración de la cuenta',
```

- [ ] **Step 4: Run the Language tests end-to-end**

Run: `php artisan test --compact --filter=LanguageTest`
Expected: PASS (all four tests, including `language settings page is displayed` which now works since the Inertia component exists).

- [ ] **Step 5: Verify frontend**

Run: `npm run types:check && npm run lint:check && npm run build`
Expected: all PASS.

Manually run `composer run dev`, log in, go to `/settings/language`, switch to Italiano, and confirm: (a) the page immediately re-renders the settings nav and heading in Italian without a reload, (b) reloading the page keeps Italian (locale persisted), (c) switching back to English works the same way.

- [ ] **Step 6: Commit**

```bash
git add resources/js/pages/settings/Language.vue resources/js/layouts/settings/Layout.vue resources/js/lang app/Http/Controllers/Settings/LanguageController.php
git commit -m "feat: add Language tab to settings with live locale switching"
```

---

## Task 6: Backend validation messages (`lang:publish` + it/es translations)

**Files:**
- Create: `lang/en/*.php` (via artisan)
- Create: `lang/it/validation.php`, `lang/it/auth.php`, `lang/it/passwords.php`
- Create: `lang/es/validation.php`, `lang/es/auth.php`, `lang/es/passwords.php`
- Test: `tests/Feature/LocalizedValidationTest.php`

**Interfaces:**
- Consumes: `SetLocale` middleware (Task 2) — locale must already be `App::setLocale()`'d before the FormRequest validates, which it is (middleware runs before controller/FormRequest resolution).
- Produces: validation error messages, auth throttling messages, and password-reset broker messages rendered in the user's locale.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\User;

test('validation errors are translated for an Italian-locale user', function () {
    $user = User::factory()->create(['locale' => 'it']);

    $response = $this->actingAs($user)->post(route('countries.store'), []);

    $response->assertSessionHasErrors([
        'name' => 'Il campo name è obbligatorio.',
    ]);
});

test('validation errors are translated for a Spanish-locale user', function () {
    $user = User::factory()->create(['locale' => 'es']);

    $response = $this->actingAs($user)->post(route('countries.store'), []);

    $response->assertSessionHasErrors([
        'name' => 'El campo name es obligatorio.',
    ]);
});

test('validation errors stay in English by default', function () {
    $user = User::factory()->create(['locale' => 'en']);

    $response = $this->actingAs($user)->post(route('countries.store'), []);

    $response->assertSessionHasErrors([
        'name' => 'The name field is required.',
    ]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=LocalizedValidationTest`
Expected: FAIL — `lang/en` doesn't exist yet (Laravel 13 ships without published lang files), so `validation.required` resolves to Laravel's compiled-in English default already ("The name field is required.") for the `en` case (that one might already pass), but `it`/`es` cases FAIL since there's no `lang/it/validation.php` / `lang/es/validation.php` yet (Laravel falls back to the English default text regardless of locale).

- [ ] **Step 3: Publish the base English language files**

Run: `php artisan lang:publish --no-interaction`
Expected: creates `lang/en/auth.php`, `lang/en/pagination.php`, `lang/en/passwords.php`, `lang/en/validation.php`.

- [ ] **Step 4: Create `lang/it/validation.php`**

```php
<?php

return [
    'accepted' => 'Il campo :attribute deve essere accettato.',
    'active_url' => 'Il campo :attribute non è un URL valido.',
    'after' => 'Il campo :attribute deve essere una data successiva a :date.',
    'after_or_equal' => 'Il campo :attribute deve essere una data successiva o uguale a :date.',
    'before' => 'Il campo :attribute deve essere una data precedente a :date.',
    'before_or_equal' => 'Il campo :attribute deve essere una data precedente o uguale a :date.',
    'between' => [
        'numeric' => 'Il campo :attribute deve essere compreso tra :min e :max.',
        'string' => 'Il campo :attribute deve essere compreso tra :min e :max caratteri.',
    ],
    'boolean' => 'Il campo :attribute deve essere vero o falso.',
    'confirmed' => 'La conferma del campo :attribute non corrisponde.',
    'current_password' => 'La password non è corretta.',
    'date' => 'Il campo :attribute non è una data valida.',
    'digits' => 'Il campo :attribute deve essere di :digits cifre.',
    'email' => 'Il campo :attribute deve essere un indirizzo email valido.',
    'exists' => 'Il valore selezionato per :attribute non è valido.',
    'image' => 'Il campo :attribute deve essere un\'immagine.',
    'in' => 'Il valore selezionato per :attribute non è valido.',
    'integer' => 'Il campo :attribute deve essere un numero intero.',
    'max' => [
        'numeric' => 'Il campo :attribute non può essere maggiore di :max.',
        'string' => 'Il campo :attribute non può superare :max caratteri.',
        'file' => 'Il campo :attribute non può superare :max kilobyte.',
    ],
    'mimes' => 'Il campo :attribute deve essere un file di tipo: :values.',
    'min' => [
        'numeric' => 'Il campo :attribute deve essere almeno :min.',
        'string' => 'Il campo :attribute deve contenere almeno :min caratteri.',
        'file' => 'Il campo :attribute deve essere almeno :min kilobyte.',
    ],
    'numeric' => 'Il campo :attribute deve essere un numero.',
    'required' => 'Il campo :attribute è obbligatorio.',
    'string' => 'Il campo :attribute deve essere una stringa di testo.',
    'unique' => 'Il valore del campo :attribute è già in uso.',
    'url' => 'Il campo :attribute deve essere un URL valido.',
    'uuid' => 'Il campo :attribute deve essere un UUID valido.',

    'attributes' => [],
];
```

- [ ] **Step 5: Create `lang/es/validation.php`**

```php
<?php

return [
    'accepted' => 'El campo :attribute debe ser aceptado.',
    'active_url' => 'El campo :attribute no es una URL válida.',
    'after' => 'El campo :attribute debe ser una fecha posterior a :date.',
    'after_or_equal' => 'El campo :attribute debe ser una fecha posterior o igual a :date.',
    'before' => 'El campo :attribute debe ser una fecha anterior a :date.',
    'before_or_equal' => 'El campo :attribute debe ser una fecha anterior o igual a :date.',
    'between' => [
        'numeric' => 'El campo :attribute debe estar entre :min y :max.',
        'string' => 'El campo :attribute debe tener entre :min y :max caracteres.',
    ],
    'boolean' => 'El campo :attribute debe ser verdadero o falso.',
    'confirmed' => 'La confirmación del campo :attribute no coincide.',
    'current_password' => 'La contraseña es incorrecta.',
    'date' => 'El campo :attribute no es una fecha válida.',
    'digits' => 'El campo :attribute debe tener :digits dígitos.',
    'email' => 'El campo :attribute debe ser una dirección de correo válida.',
    'exists' => 'El valor seleccionado para :attribute no es válido.',
    'image' => 'El campo :attribute debe ser una imagen.',
    'in' => 'El valor seleccionado para :attribute no es válido.',
    'integer' => 'El campo :attribute debe ser un número entero.',
    'max' => [
        'numeric' => 'El campo :attribute no puede ser mayor que :max.',
        'string' => 'El campo :attribute no puede tener más de :max caracteres.',
        'file' => 'El campo :attribute no puede ser mayor que :max kilobytes.',
    ],
    'mimes' => 'El campo :attribute debe ser un archivo de tipo: :values.',
    'min' => [
        'numeric' => 'El campo :attribute debe ser al menos :min.',
        'string' => 'El campo :attribute debe tener al menos :min caracteres.',
        'file' => 'El campo :attribute debe ser al menos :min kilobytes.',
    ],
    'numeric' => 'El campo :attribute debe ser un número.',
    'required' => 'El campo :attribute es obligatorio.',
    'string' => 'El campo :attribute debe ser una cadena de texto.',
    'unique' => 'El valor del campo :attribute ya está en uso.',
    'url' => 'El campo :attribute debe ser una URL válida.',
    'uuid' => 'El campo :attribute debe ser un UUID válido.',

    'attributes' => [],
];
```

- [ ] **Step 6: Create `lang/it/auth.php` and `lang/it/passwords.php`**

`lang/it/auth.php`:

```php
<?php

return [
    'failed' => 'Queste credenziali non corrispondono ai nostri archivi.',
    'password' => 'La password inserita non è corretta.',
    'throttle' => 'Troppi tentativi di accesso. Riprova tra :seconds secondi.',
];
```

`lang/it/passwords.php`:

```php
<?php

return [
    'reset' => 'La password è stata reimpostata.',
    'sent' => 'Ti abbiamo inviato via email il link per reimpostare la password.',
    'throttled' => 'Attendi prima di riprovare.',
    'token' => 'Il token di reimpostazione della password non è valido.',
    'user' => 'Non troviamo nessun utente con questo indirizzo email.',
];
```

- [ ] **Step 7: Create `lang/es/auth.php` and `lang/es/passwords.php`**

`lang/es/auth.php`:

```php
<?php

return [
    'failed' => 'Estas credenciales no coinciden con nuestros registros.',
    'password' => 'La contraseña introducida es incorrecta.',
    'throttle' => 'Demasiados intentos de acceso. Inténtalo de nuevo en :seconds segundos.',
];
```

`lang/es/passwords.php`:

```php
<?php

return [
    'reset' => 'Tu contraseña ha sido restablecida.',
    'sent' => 'Te hemos enviado por correo electrónico el enlace para restablecer la contraseña.',
    'throttled' => 'Por favor espera antes de volver a intentarlo.',
    'token' => 'El token de restablecimiento de contraseña no es válido.',
    'user' => 'No encontramos ningún usuario con esa dirección de correo electrónico.',
];
```

- [ ] **Step 8: Run test to verify it passes**

Run: `php artisan test --compact --filter=LocalizedValidationTest`
Expected: PASS (all three).

- [ ] **Step 9: Run the full test suite to check for regressions**

Run: `php artisan test --compact`
Expected: PASS — publishing `lang/en/*.php` must not change any existing English-locale assertions (Laravel's compiled-in defaults and the published `lang/en/*.php` content are identical by design of `lang:publish`).

- [ ] **Step 10: Commit**

```bash
git add lang tests/Feature/LocalizedValidationTest.php
git commit -m "feat: translate validation and auth messages into Italian and Spanish"
```

---

## Task 7: Backend literal-string translations (flash messages + Fortify auth emails)

**Files:**
- Create: `lang/it.json`
- Create: `lang/es.json`
- Test: `tests/Feature/LocalizedFlashMessageTest.php`

**Interfaces:**
- Consumes: the 19 exact `__('...')` calls already present in `app/Http/Controllers/**` (18 pre-existing + `'Language updated.'` from Task 4) and the exact strings used by `Illuminate\Auth\Notifications\ResetPassword`/`VerifyEmail` (Laravel core, via `Lang::get()`, which resolves through the same `lang/{locale}.json` mechanism).
- Produces: flash toasts and Fortify's password-reset/email-verification emails rendered in the user's locale.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Country;
use App\Models\User;

test('flash messages are translated for an Italian-locale user', function () {
    $user = User::factory()->create(['locale' => 'it']);

    $this->actingAs($user)->post(route('countries.store'), ['name' => 'Test Country']);

    expect(session('toast')['message'])->toBe('Paese creato.');
});

test('flash messages are translated for a Spanish-locale user', function () {
    $user = User::factory()->create(['locale' => 'es']);

    $this->actingAs($user)->post(route('countries.store'), ['name' => 'Test Country']);

    expect(session('toast')['message'])->toBe('País creado.');
});

test('flash messages stay in English by default', function () {
    $user = User::factory()->create(['locale' => 'en']);

    $this->actingAs($user)->post(route('countries.store'), ['name' => 'Test Country']);

    expect(session('toast')['message'])->toBe('Country created.');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=LocalizedFlashMessageTest`
Expected: FAIL on the `it`/`es` cases (message stays in English — no `lang/{locale}.json` yet).

- [ ] **Step 3: Create `lang/it.json`**

```json
{
    "Invoice created.": "Fattura creata.",
    "Invoice updated.": "Fattura aggiornata.",
    "Invoice deleted.": "Fattura eliminata.",
    "Customer created.": "Cliente creato.",
    "Customer updated.": "Cliente aggiornato.",
    "Customer deleted.": "Cliente eliminato.",
    "Company created.": "Azienda creata.",
    "Company updated.": "Azienda aggiornata.",
    "Company deleted.": "Azienda eliminata.",
    "This company has invoices and cannot be deleted.": "Questa azienda ha fatture associate e non può essere eliminata.",
    "Product created.": "Prodotto creato.",
    "Product updated.": "Prodotto aggiornato.",
    "Product deleted.": "Prodotto eliminato.",
    "Country created.": "Paese creato.",
    "Country updated.": "Paese aggiornato.",
    "Country deleted.": "Paese eliminato.",
    "Password updated.": "Password aggiornata.",
    "Profile updated.": "Profilo aggiornato.",
    "Language updated.": "Lingua aggiornata.",
    "Reset your password": "Reimposta la tua password",
    "You are receiving this email because we received a password reset request for your account.": "Hai ricevuto questa email perché abbiamo ricevuto una richiesta di reimpostazione della password per il tuo account.",
    "Reset Password": "Reimposta password",
    "This password reset link will expire in :count minutes.": "Questo link per la reimpostazione della password scadrà tra :count minuti.",
    "If you did not request a password reset, no further action is required.": "Se non hai richiesto la reimpostazione della password, non è necessaria alcuna ulteriore azione.",
    "Verify your email address": "Verifica il tuo indirizzo email",
    "Please click the button below to verify your email address.": "Fai clic sul pulsante qui sotto per verificare il tuo indirizzo email.",
    "Verify Email Address": "Verifica indirizzo email",
    "If you did not create an account, no further action is required.": "Se non hai creato un account, non è necessaria alcuna ulteriore azione.",
    "All rights reserved.": "Tutti i diritti riservati."
}
```

- [ ] **Step 4: Create `lang/es.json`**

```json
{
    "Invoice created.": "Factura creada.",
    "Invoice updated.": "Factura actualizada.",
    "Invoice deleted.": "Factura eliminada.",
    "Customer created.": "Cliente creado.",
    "Customer updated.": "Cliente actualizado.",
    "Customer deleted.": "Cliente eliminado.",
    "Company created.": "Empresa creada.",
    "Company updated.": "Empresa actualizada.",
    "Company deleted.": "Empresa eliminada.",
    "This company has invoices and cannot be deleted.": "Esta empresa tiene facturas asociadas y no se puede eliminar.",
    "Product created.": "Producto creado.",
    "Product updated.": "Producto actualizado.",
    "Product deleted.": "Producto eliminado.",
    "Country created.": "País creado.",
    "Country updated.": "País actualizado.",
    "Country deleted.": "País eliminado.",
    "Password updated.": "Contraseña actualizada.",
    "Profile updated.": "Perfil actualizado.",
    "Language updated.": "Idioma actualizado.",
    "Reset your password": "Restablece tu contraseña",
    "You are receiving this email because we received a password reset request for your account.": "Recibes este correo porque recibimos una solicitud de restablecimiento de contraseña para tu cuenta.",
    "Reset Password": "Restablecer contraseña",
    "This password reset link will expire in :count minutes.": "Este enlace para restablecer la contraseña caducará en :count minutos.",
    "If you did not request a password reset, no further action is required.": "Si no solicitaste un restablecimiento de contraseña, no es necesaria ninguna otra acción.",
    "Verify your email address": "Verifica tu dirección de correo electrónico",
    "Please click the button below to verify your email address.": "Haz clic en el botón de abajo para verificar tu dirección de correo electrónico.",
    "Verify Email Address": "Verificar dirección de correo",
    "If you did not create an account, no further action is required.": "Si no creaste una cuenta, no es necesaria ninguna otra acción.",
    "All rights reserved.": "Todos los derechos reservados."
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter=LocalizedFlashMessageTest`
Expected: PASS (all three).

- [ ] **Step 6: Run the full backend test suite**

Run: `php artisan test --compact`
Expected: PASS — every existing test that asserts an English flash/error message (e.g. `CountryTest`, `CustomerTest`, `ProfileUpdateTest`, `SecurityTest`) must still see English text, since those tests create users without an explicit `locale` (defaulting to `'en'`).

- [ ] **Step 7: Commit**

```bash
git add lang/it.json lang/es.json tests/Feature/LocalizedFlashMessageTest.php
git commit -m "feat: translate flash messages and Fortify auth emails into Italian and Spanish"
```

---

## Task 8: Translate the auth pages

**Files:**
- Modify: `resources/js/pages/auth/Login.vue`
- Modify: `resources/js/pages/auth/Register.vue`
- Modify: `resources/js/pages/auth/ForgotPassword.vue`
- Modify: `resources/js/pages/auth/ResetPassword.vue`
- Modify: `resources/js/pages/auth/ConfirmPassword.vue`
- Modify: `resources/js/pages/auth/TwoFactorChallenge.vue`
- Modify: `resources/js/pages/auth/VerifyEmail.vue`
- Modify: `resources/js/components/PasswordInput.vue`
- Modify: `resources/js/components/PasskeyVerify.vue`
- Modify: `resources/js/lang/en.ts`, `resources/js/lang/it.ts`, `resources/js/lang/es.ts`

**Interfaces:**
- Consumes: `common.actions.*` (Task 3), global `$t()` injection (Task 3).
- Produces: `auth.*` and `passwordInput.*` translation namespaces.

- [ ] **Step 1: Add the `auth` and `passwordInput` keys to `en.ts`**

```ts
    passwordInput: {
        show: 'Show password',
        hide: 'Hide password',
    },
    auth: {
        login: {
            layoutTitle: 'Log in to your account',
            layoutDescription: 'Enter your email and password below to log in',
            headTitle: 'Log in',
            email: 'Email address',
            password: 'Password',
            forgotPassword: 'Forgot password?',
            remember: 'Remember me',
            submit: 'Log in',
            noAccount: "Don't have an account?",
            signUp: 'Sign up',
            passkey: 'Sign in with a passkey',
            passkeyLoading: 'Authenticating...',
            passkeySeparator: 'Or continue with email',
        },
        register: {
            layoutTitle: 'Create an account',
            layoutDescription: 'Enter your details below to create your account',
            headTitle: 'Register',
            name: 'Name',
            namePlaceholder: 'Full name',
            email: 'Email address',
            password: 'Password',
            confirmPassword: 'Confirm password',
            submit: 'Create account',
            haveAccount: 'Already have an account?',
            logIn: 'Log in',
        },
        forgotPassword: {
            layoutTitle: 'Forgot password',
            layoutDescription: 'Enter your email to receive a password reset link',
            headTitle: 'Forgot password',
            email: 'Email address',
            submit: 'Email password reset link',
            returnPrefix: 'Or, return to',
            logIn: 'log in',
        },
        resetPassword: {
            layoutTitle: 'Reset password',
            layoutDescription: 'Please enter your new password below',
            headTitle: 'Reset password',
            email: 'Email',
            password: 'Password',
            confirmPassword: 'Confirm password',
            submit: 'Reset password',
        },
        confirmPassword: {
            layoutTitle: 'Confirm password',
            layoutDescription: 'This is a secure area of the application. Please confirm your password before continuing.',
            headTitle: 'Confirm password',
            passkeyLabel: 'Confirm with passkey',
            passkeyLoading: 'Confirming...',
            passkeySeparator: 'Or confirm with password',
            password: 'Password',
            submit: 'Confirm password',
        },
        twoFactorChallenge: {
            headTitle: 'Two-factor authentication',
            recoveryTitle: 'Recovery code',
            recoveryDescription: 'Please confirm access to your account by entering one of your emergency recovery codes.',
            recoveryButton: 'login using an authentication code',
            authTitle: 'Authentication code',
            authDescription: 'Enter the authentication code provided by your authenticator application.',
            authButton: 'login using a recovery code',
            recoveryPlaceholder: 'Enter recovery code',
            continue: 'Continue',
            orYouCan: 'or you can',
        },
        verifyEmail: {
            layoutTitle: 'Email verification',
            layoutDescription: 'Please verify your email address by clicking on the link we just emailed to you.',
            headTitle: 'Email verification',
            sent: 'A new verification link has been sent to the email address you provided during registration.',
            resend: 'Resend verification email',
            logout: 'Log out',
        },
    },
```

Extend `resources/js/lang/it.ts` with the Italian equivalents:

```ts
    passwordInput: {
        show: 'Mostra password',
        hide: 'Nascondi password',
    },
    auth: {
        login: {
            layoutTitle: 'Accedi al tuo account',
            layoutDescription: 'Inserisci email e password per accedere',
            headTitle: 'Accedi',
            email: 'Indirizzo email',
            password: 'Password',
            forgotPassword: 'Password dimenticata?',
            remember: 'Ricordami',
            submit: 'Accedi',
            noAccount: 'Non hai un account?',
            signUp: 'Registrati',
            passkey: 'Accedi con una passkey',
            passkeyLoading: 'Autenticazione...',
            passkeySeparator: "Oppure continua con l'email",
        },
        register: {
            layoutTitle: 'Crea un account',
            layoutDescription: 'Inserisci i tuoi dati per creare un account',
            headTitle: 'Registrati',
            name: 'Nome',
            namePlaceholder: 'Nome completo',
            email: 'Indirizzo email',
            password: 'Password',
            confirmPassword: 'Conferma password',
            submit: 'Crea account',
            haveAccount: 'Hai già un account?',
            logIn: 'Accedi',
        },
        forgotPassword: {
            layoutTitle: 'Password dimenticata',
            layoutDescription: 'Inserisci la tua email per ricevere il link di reimpostazione',
            headTitle: 'Password dimenticata',
            email: 'Indirizzo email',
            submit: 'Invia link di reimpostazione password',
            returnPrefix: 'Oppure, torna al',
            logIn: 'accesso',
        },
        resetPassword: {
            layoutTitle: 'Reimposta password',
            layoutDescription: 'Inserisci di seguito la tua nuova password',
            headTitle: 'Reimposta password',
            email: 'Email',
            password: 'Password',
            confirmPassword: 'Conferma password',
            submit: 'Reimposta password',
        },
        confirmPassword: {
            layoutTitle: 'Conferma password',
            layoutDescription: "Questa è un'area protetta dell'applicazione. Conferma la tua password prima di continuare.",
            headTitle: 'Conferma password',
            passkeyLabel: 'Conferma con passkey',
            passkeyLoading: 'Conferma in corso...',
            passkeySeparator: 'Oppure conferma con la password',
            password: 'Password',
            submit: 'Conferma password',
        },
        twoFactorChallenge: {
            headTitle: 'Autenticazione a due fattori',
            recoveryTitle: 'Codice di recupero',
            recoveryDescription: "Conferma l'accesso al tuo account inserendo uno dei tuoi codici di recupero di emergenza.",
            recoveryButton: 'accedi con un codice di autenticazione',
            authTitle: 'Codice di autenticazione',
            authDescription: "Inserisci il codice di autenticazione fornito dall'applicazione del tuo autenticatore.",
            authButton: 'accedi con un codice di recupero',
            recoveryPlaceholder: 'Inserisci il codice di recupero',
            continue: 'Continua',
            orYouCan: 'oppure puoi',
        },
        verifyEmail: {
            layoutTitle: 'Verifica email',
            layoutDescription: "Verifica il tuo indirizzo email cliccando sul link che ti abbiamo appena inviato.",
            headTitle: 'Verifica email',
            sent: 'Un nuovo link di verifica è stato inviato allindirizzo email fornito in fase di registrazione.',
            resend: 'Invia di nuovo email di verifica',
            logout: 'Esci',
        },
    },
```

Extend `resources/js/lang/es.ts` with the Spanish equivalents:

```ts
    passwordInput: {
        show: 'Mostrar contraseña',
        hide: 'Ocultar contraseña',
    },
    auth: {
        login: {
            layoutTitle: 'Inicia sesión en tu cuenta',
            layoutDescription: 'Introduce tu correo y contraseña para iniciar sesión',
            headTitle: 'Iniciar sesión',
            email: 'Correo electrónico',
            password: 'Contraseña',
            forgotPassword: '¿Olvidaste tu contraseña?',
            remember: 'Recuérdame',
            submit: 'Iniciar sesión',
            noAccount: '¿No tienes una cuenta?',
            signUp: 'Regístrate',
            passkey: 'Iniciar sesión con una passkey',
            passkeyLoading: 'Autenticando...',
            passkeySeparator: 'O continúa con el correo electrónico',
        },
        register: {
            layoutTitle: 'Crea una cuenta',
            layoutDescription: 'Introduce tus datos para crear tu cuenta',
            headTitle: 'Registrarse',
            name: 'Nombre',
            namePlaceholder: 'Nombre completo',
            email: 'Correo electrónico',
            password: 'Contraseña',
            confirmPassword: 'Confirmar contraseña',
            submit: 'Crear cuenta',
            haveAccount: '¿Ya tienes una cuenta?',
            logIn: 'Iniciar sesión',
        },
        forgotPassword: {
            layoutTitle: 'Contraseña olvidada',
            layoutDescription: 'Introduce tu correo para recibir el enlace de restablecimiento',
            headTitle: 'Contraseña olvidada',
            email: 'Correo electrónico',
            submit: 'Enviar enlace de restablecimiento',
            returnPrefix: 'O, vuelve a',
            logIn: 'iniciar sesión',
        },
        resetPassword: {
            layoutTitle: 'Restablecer contraseña',
            layoutDescription: 'Introduce tu nueva contraseña a continuación',
            headTitle: 'Restablecer contraseña',
            email: 'Correo electrónico',
            password: 'Contraseña',
            confirmPassword: 'Confirmar contraseña',
            submit: 'Restablecer contraseña',
        },
        confirmPassword: {
            layoutTitle: 'Confirmar contraseña',
            layoutDescription: 'Esta es un área segura de la aplicación. Confirma tu contraseña antes de continuar.',
            headTitle: 'Confirmar contraseña',
            passkeyLabel: 'Confirmar con passkey',
            passkeyLoading: 'Confirmando...',
            passkeySeparator: 'O confirma con la contraseña',
            password: 'Contraseña',
            submit: 'Confirmar contraseña',
        },
        twoFactorChallenge: {
            headTitle: 'Autenticación de dos factores',
            recoveryTitle: 'Código de recuperación',
            recoveryDescription: 'Confirma el acceso a tu cuenta introduciendo uno de tus códigos de recuperación de emergencia.',
            recoveryButton: 'iniciar sesión con un código de autenticación',
            authTitle: 'Código de autenticación',
            authDescription: 'Introduce el código de autenticación proporcionado por tu aplicación autenticadora.',
            authButton: 'iniciar sesión con un código de recuperación',
            recoveryPlaceholder: 'Introduce el código de recuperación',
            continue: 'Continuar',
            orYouCan: 'o puedes',
        },
        verifyEmail: {
            layoutTitle: 'Verificación de correo',
            layoutDescription: 'Verifica tu dirección de correo haciendo clic en el enlace que te acabamos de enviar.',
            headTitle: 'Verificación de correo',
            sent: 'Se ha enviado un nuevo enlace de verificación a la dirección de correo que proporcionaste durante el registro.',
            resend: 'Reenviar correo de verificación',
            logout: 'Cerrar sesión',
        },
    },
```

- [ ] **Step 2: `Login.vue`**

Replace:
- `defineOptions({ layout: { title: 'Log in to your account', description: 'Enter your email and password below to log in' } })` → use `t('auth.login.layoutTitle')` / `t('auth.login.layoutDescription')`. Since `defineOptions` runs outside the render scope, import `useI18n` directly: `const { t } = useI18n();` and reference `t(...)` inside the `layout` object — this works because `defineOptions`'s object is evaluated at module setup time same as any other `<script setup>` code, after `useI18n()` has run.
- `<Head title="Log in" />` → `<Head :title="t('auth.login.headTitle')" />`
- `<Label for="email">Email address</Label>` → `<Label for="email">{{ t('auth.login.email') }}</Label>`
- `Forgot password?` → `{{ t('auth.login.forgotPassword') }}`
- `<Label for="password">Password</Label>` → `{{ t('auth.login.password') }}`
- `<span>Remember me</span>` → `<span>{{ t('auth.login.remember') }}</span>`
- `Log in` (button text) → `{{ t('auth.login.submit') }}`
- `Don't have an account?` → `{{ t('auth.login.noAccount') }}`
- `Sign up` → `{{ t('auth.login.signUp') }}`

Add `import { useI18n } from 'vue-i18n';` to the imports.

- [ ] **Step 3: `Register.vue`**

Same pattern: `layout.title`/`layout.description` → `auth.register.layoutTitle`/`layoutDescription`; `Register` head title → `auth.register.headTitle`; `Name`/`Full name` → `auth.register.name`/`namePlaceholder`; `Email address` → `auth.register.email`; `Password` → `auth.register.password`; `Confirm password` → `auth.register.confirmPassword`; `Create account` → `auth.register.submit`; `Already have an account?` → `auth.register.haveAccount`; `Log in` → `auth.register.logIn`.

- [ ] **Step 4: `ForgotPassword.vue`**

`layout.title`/`description` → `auth.forgotPassword.layoutTitle`/`layoutDescription`; head title → `auth.forgotPassword.headTitle`; `Email address` → `auth.forgotPassword.email`; `Email password reset link` → `auth.forgotPassword.submit`; `Or, return to` → `auth.forgotPassword.returnPrefix`; `log in` → `auth.forgotPassword.logIn`.

- [ ] **Step 5: `ResetPassword.vue`**

`layout.title`/`description` → `auth.resetPassword.layoutTitle`/`layoutDescription`; head title → `auth.resetPassword.headTitle`; `Email` → `auth.resetPassword.email`; `Password` → `auth.resetPassword.password`; `Confirm password` → `auth.resetPassword.confirmPassword`; `Reset password` (button) → `auth.resetPassword.submit`.

- [ ] **Step 6: `ConfirmPassword.vue`**

`layout.title`/`description` → `auth.confirmPassword.layoutTitle`/`layoutDescription`; head title → `auth.confirmPassword.headTitle`; the `<PasskeyVerify>` props become `:label="t('auth.confirmPassword.passkeyLabel')"`, `:loading-label="t('auth.confirmPassword.passkeyLoading')"`, `:separator="t('auth.confirmPassword.passkeySeparator')"`; `Password` label → `auth.confirmPassword.password`; `Confirm password` (button) → `auth.confirmPassword.submit`.

- [ ] **Step 7: `TwoFactorChallenge.vue`**

The `authConfigContent` computed's literal strings become `$t()` calls (note: `computed` already has access to `t` from `useI18n()` called at the top of `<script setup>`):

```ts
const authConfigContent = computed<TwoFactorConfigContent>(() => {
    if (showRecoveryInput.value) {
        return {
            title: t('auth.twoFactorChallenge.recoveryTitle'),
            description: t('auth.twoFactorChallenge.recoveryDescription'),
            buttonText: t('auth.twoFactorChallenge.recoveryButton'),
        };
    }

    return {
        title: t('auth.twoFactorChallenge.authTitle'),
        description: t('auth.twoFactorChallenge.authDescription'),
        buttonText: t('auth.twoFactorChallenge.authButton'),
    };
});
```

`<Head title="Two-factor authentication" />` → `<Head :title="t('auth.twoFactorChallenge.headTitle')" />`; `Enter recovery code` placeholder → `t('auth.twoFactorChallenge.recoveryPlaceholder')`; both `Continue` buttons → `{{ t('auth.twoFactorChallenge.continue') }}`; both `or you can` → `{{ t('auth.twoFactorChallenge.orYouCan') }}`.

- [ ] **Step 8: `VerifyEmail.vue`**

`layout.title`/`description` → `auth.verifyEmail.layoutTitle`/`layoutDescription`; head title → `auth.verifyEmail.headTitle`; the "A new verification link has been sent..." paragraph → `{{ t('auth.verifyEmail.sent') }}`; `Resend verification email` → `{{ t('auth.verifyEmail.resend') }}`; `Log out` → `{{ t('auth.verifyEmail.logout') }}`.

- [ ] **Step 9: `PasswordInput.vue`**

Add `import { useI18n } from 'vue-i18n';` and `const { t } = useI18n();`. Replace `:aria-label="showPassword ? 'Hide password' : 'Show password'"` with `:aria-label="showPassword ? t('passwordInput.hide') : t('passwordInput.show')"`.

- [ ] **Step 10: `PasskeyVerify.vue`**

Add `import { useI18n } from 'vue-i18n';` and `const { t } = useI18n();`. Replace the fallback defaults so the component is correctly localized even when the caller (`Login.vue`) doesn't pass explicit `label`/`loading-label`/`separator` props:

```ts
{{
    isLoading
        ? (props.loadingLabel ?? t('auth.login.passkeyLoading'))
        : (props.label ?? t('auth.login.passkey'))
}}
```

```ts
{{ props.separator ?? t('auth.login.passkeySeparator') }}
```

(These defaults intentionally reuse the `auth.login.*` keys rather than inventing generic ones — `Login.vue` is the only caller that relies on the defaults; `ConfirmPassword.vue` always passes its own explicit props from Step 6.)

- [ ] **Step 11: Verify**

Run: `npm run types:check && npm run lint:check && npm run build`
Expected: all PASS.

Run: `php artisan test --compact --filter=AuthenticationTest`
Run: `php artisan test --compact --filter=RegistrationTest`
Run: `php artisan test --compact --filter=PasswordResetTest`
Run: `php artisan test --compact --filter=PasswordConfirmationTest`
Run: `php artisan test --compact --filter=TwoFactorChallengeTest`
Run: `php artisan test --compact --filter=EmailVerificationTest`
Expected: all PASS (these tests assert on redirects/session state, not rendered template text, so translating the templates shouldn't break them — this run just confirms that's still true).

Manually run `composer run dev` and click through `/login`, `/register`, `/forgot-password`, `/two-factor-challenge` (if 2FA is set up) with the browser's language set to Italian/Spanish to eyeball the translations render correctly.

- [ ] **Step 12: Commit**

```bash
git add resources/js/pages/auth resources/js/components/PasswordInput.vue resources/js/components/PasskeyVerify.vue resources/js/lang
git commit -m "feat: translate the auth pages"
```

---

## Task 9: Translate `Dashboard.vue`, `Welcome.vue`, and the sidebar nav

**Files:**
- Modify: `resources/js/pages/Dashboard.vue`
- Modify: `resources/js/pages/Welcome.vue`
- Modify: `resources/js/components/AppSidebar.vue`
- Modify: `resources/js/lang/en.ts`, `resources/js/lang/it.ts`, `resources/js/lang/es.ts`

**Interfaces:**
- Produces: `nav.*`, `dashboard.*`, `welcome.*` translation namespaces.

- [ ] **Step 1: Add the keys to `en.ts`**

```ts
    nav: {
        dashboard: 'Dashboard',
        customers: 'Customers',
        companies: 'Companies',
        invoices: 'Invoices',
        products: 'Products',
        countries: 'Countries',
        repository: 'Repository',
        documentation: 'Documentation',
    },
    dashboard: {
        title: 'Dashboard',
    },
    welcome: {
        description:
            'Invoicing application for freelancers: customer registry, invoice issuing with rows/VAT, automatic sequential numbering, PDF preview and generation, invoice duplication.',
        readDocsPrefix: 'Read the',
        documentation: 'Documentation',
        dashboard: 'Dashboard',
        logIn: 'Log in',
        register: 'Register',
    },
```

Add to `it.ts`:

```ts
    nav: {
        dashboard: 'Dashboard',
        customers: 'Clienti',
        companies: 'Aziende',
        invoices: 'Fatture',
        products: 'Prodotti',
        countries: 'Paesi',
        repository: 'Repository',
        documentation: 'Documentazione',
    },
    dashboard: {
        title: 'Dashboard',
    },
    welcome: {
        description:
            'Applicazione di fatturazione per liberi professionisti: anagrafica clienti, emissione fatture con righe/IVA, numerazione progressiva automatica, anteprima e generazione PDF, duplicazione fattura.',
        readDocsPrefix: 'Leggi la',
        documentation: 'Documentazione',
        dashboard: 'Dashboard',
        logIn: 'Accedi',
        register: 'Registrati',
    },
```

Add to `es.ts`:

```ts
    nav: {
        dashboard: 'Panel',
        customers: 'Clientes',
        companies: 'Empresas',
        invoices: 'Facturas',
        products: 'Productos',
        countries: 'Países',
        repository: 'Repositorio',
        documentation: 'Documentación',
    },
    dashboard: {
        title: 'Panel',
    },
    welcome: {
        description:
            'Aplicación de facturación para autónomos: registro de clientes, emisión de facturas con líneas/IVA, numeración secuencial automática, vista previa y generación de PDF, duplicación de facturas.',
        readDocsPrefix: 'Lee la',
        documentation: 'Documentación',
        dashboard: 'Panel',
        logIn: 'Iniciar sesión',
        register: 'Registrarse',
    },
```

- [ ] **Step 2: `Dashboard.vue`**

Add `import { useI18n } from 'vue-i18n';` and `const { t } = useI18n();`. Replace the breadcrumb `title: 'Dashboard'` with `title: t('dashboard.title')`, and `<Head title="Dashboard" />` with `<Head :title="t('dashboard.title')" />`.

- [ ] **Step 3: `AppSidebar.vue`**

Read the file to find `mainNavItems`/`footerNavItems`. Add `import { useI18n } from 'vue-i18n';` and `const { t } = useI18n();`. If these arrays are plain `const` (not reactive), convert them to a `computed()` so the labels react to a live locale switch (consistent with the `Layout.vue` pattern from Task 5):

```ts
const mainNavItems = computed<NavItem[]>(() => [
    { title: t('nav.dashboard'), href: dashboard(), icon: LayoutGrid },
    { title: t('nav.customers'), href: customersIndex(), icon: Users },
    { title: t('nav.companies'), href: companiesIndex(), icon: Building2 },
    { title: t('nav.invoices'), href: invoicesIndex(), icon: FileText },
    { title: t('nav.products'), href: productsIndex(), icon: Package },
    { title: t('nav.countries'), href: countriesIndex(), icon: Globe },
]);

const footerNavItems = computed<NavItem[]>(() => [
    { title: t('nav.repository'), href: '...', icon: Folder },
    { title: t('nav.documentation'), href: '...', icon: BookOpen },
]);
```

(keep the exact existing `href`/`icon` values for each entry — only wrap the arrays in `computed()` and swap the `title` strings for `t(...)` calls; import `computed` from `'vue'` if not already imported.)

- [ ] **Step 4: `Welcome.vue`**

Add `import { useI18n } from 'vue-i18n';` and `const { t } = useI18n();`. Replace:
- `Dashboard` link text → `{{ t('welcome.dashboard') }}`
- `Log in` link text → `{{ t('welcome.logIn') }}`
- `Register` link text → `{{ t('welcome.register') }}`
- The `<p>` marketing paragraph ("Applicazione di fatturazione...") → `{{ t('welcome.description') }}`
- `Read the` → `{{ t('welcome.readDocsPrefix') }}`
- `<span>Documentation</span>` → `<span>{{ t('welcome.documentation') }}</span>`

Leave `$page.props.name`, `$page.props.version`, the GitHub URL, and the logo `alt="Logo"` untouched (product name / version / external link, not translatable UI copy — `alt="Logo"` is a generic accessibility label acceptable to leave in English, consistent with how icon-only buttons elsewhere in the app aren't fully localized either... actually translate it too for consistency: add `welcome.logoAlt: 'Logo'` / `'Logo'` / `'Logo'` — same word in all three languages, so this is a no-op translation-wise; skip it to avoid a pointless key).

- [ ] **Step 5: Verify**

Run: `npm run types:check && npm run lint:check && npm run build`
Expected: all PASS.
Run: `php artisan test --compact --filter=DashboardTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add resources/js/pages/Dashboard.vue resources/js/pages/Welcome.vue resources/js/components/AppSidebar.vue resources/js/lang
git commit -m "feat: translate the dashboard, welcome page, and sidebar navigation"
```

---

## Task 10: Translate the Settings pages (`Profile`, `Security`, `DeleteUser`, `ManageTwoFactor`, `ManagePasskeys`)

**Files:**
- Modify: `resources/js/pages/settings/Profile.vue`
- Modify: `resources/js/pages/settings/Security.vue`
- Modify: `resources/js/components/DeleteUser.vue`
- Modify: `resources/js/components/ManageTwoFactor.vue`
- Modify: `resources/js/components/ManagePasskeys.vue`
- Modify: `resources/js/lang/en.ts`, `resources/js/lang/it.ts`, `resources/js/lang/es.ts`

**Interfaces:**
- Consumes: `common.fields.*` (Task 3) for the shared `Name`/`Email address` labels.
- Produces: `settings.profile.*`, `settings.security.*`, `settings.deleteAccount.*`, `settings.twoFactor.*`, `settings.passkeys.*`.

- [ ] **Step 1: Add the keys to `en.ts`**

```ts
        profile: {
            title: 'Profile',
            description: 'Update your name and email address',
            namePlaceholder: 'Full name',
            emailPlaceholder: 'Email address',
            unverified: 'Your email address is unverified.',
            resendLink: 'Click here to re-send the verification email.',
            verificationSent: 'A new verification link has been sent to your email address.',
        },
        security: {
            title: 'Update password',
            description: 'Ensure your account is using a long, random password to stay secure',
            currentPassword: 'Current password',
            currentPasswordPlaceholder: 'Current password',
            newPassword: 'New password',
            newPasswordPlaceholder: 'New password',
            confirmPassword: 'Confirm password',
            confirmPasswordPlaceholder: 'Confirm password',
        },
        deleteAccount: {
            title: 'Delete account',
            description: 'Delete your account and all of its resources',
            warningTitle: 'Warning',
            warningBody: 'Please proceed with caution, this cannot be undone.',
            deleteButton: 'Delete account',
            confirmTitle: 'Are you sure you want to delete your account?',
            confirmDescription:
                'Once your account is deleted, all of its resources and data will also be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.',
            passwordLabel: 'Password',
            passwordPlaceholder: 'Password',
        },
        twoFactor: {
            title: 'Two-factor authentication',
            description: 'Manage your two-factor authentication settings',
            disabledIntro:
                'When you enable two-factor authentication, you will be prompted for a secure pin during login. This pin can be retrieved from a TOTP-supported application on your phone.',
            continueSetup: 'Continue setup',
            enable: 'Enable 2FA',
            enabledIntro:
                'You will be prompted for a secure, random pin during login, which you can retrieve from the TOTP-supported application on your phone.',
            disable: 'Disable 2FA',
        },
        passkeys: {
            title: 'Passkeys',
            description: 'Manage your passkeys for passwordless sign-in',
            emptyTitle: 'No passkeys yet',
            emptyDescription: 'Add a passkey to sign in without a password',
        },
```

(insert these as siblings of the existing `nav`/`language`/`title`/`description` keys already under `settings` from Task 5.)

Add to `it.ts`:

```ts
        profile: {
            title: 'Profilo',
            description: 'Aggiorna il tuo nome e indirizzo email',
            namePlaceholder: 'Nome completo',
            emailPlaceholder: 'Indirizzo email',
            unverified: 'Il tuo indirizzo email non è verificato.',
            resendLink: "Clicca qui per inviare di nuovo l'email di verifica.",
            verificationSent: 'Un nuovo link di verifica è stato inviato al tuo indirizzo email.',
        },
        security: {
            title: 'Aggiorna password',
            description: "Assicurati che il tuo account utilizzi una password lunga e casuale per restare sicuro",
            currentPassword: 'Password attuale',
            currentPasswordPlaceholder: 'Password attuale',
            newPassword: 'Nuova password',
            newPasswordPlaceholder: 'Nuova password',
            confirmPassword: 'Conferma password',
            confirmPasswordPlaceholder: 'Conferma password',
        },
        deleteAccount: {
            title: 'Elimina account',
            description: 'Elimina il tuo account e tutte le sue risorse',
            warningTitle: 'Attenzione',
            warningBody: "Procedi con cautela, questa azione non può essere annullata.",
            deleteButton: 'Elimina account',
            confirmTitle: 'Sei sicuro di voler eliminare il tuo account?',
            confirmDescription:
                'Una volta eliminato il tuo account, anche tutte le sue risorse e i suoi dati saranno eliminati permanentemente. Inserisci la tua password per confermare che vuoi eliminare definitivamente il tuo account.',
            passwordLabel: 'Password',
            passwordPlaceholder: 'Password',
        },
        twoFactor: {
            title: 'Autenticazione a due fattori',
            description: "Gestisci le impostazioni dell'autenticazione a due fattori",
            disabledIntro:
                "Quando attivi l'autenticazione a due fattori, ti verrà richiesto un pin sicuro durante l'accesso. Questo pin può essere recuperato da un'applicazione TOTP sul tuo telefono.",
            continueSetup: 'Continua configurazione',
            enable: 'Attiva 2FA',
            enabledIntro:
                "Ti verrà richiesto un pin sicuro e casuale durante l'accesso, che puoi recuperare dall'applicazione TOTP sul tuo telefono.",
            disable: 'Disattiva 2FA',
        },
        passkeys: {
            title: 'Passkey',
            description: 'Gestisci le tue passkey per accedere senza password',
            emptyTitle: 'Nessuna passkey ancora',
            emptyDescription: 'Aggiungi una passkey per accedere senza password',
        },
```

Add to `es.ts`:

```ts
        profile: {
            title: 'Perfil',
            description: 'Actualiza tu nombre y dirección de correo electrónico',
            namePlaceholder: 'Nombre completo',
            emailPlaceholder: 'Correo electrónico',
            unverified: 'Tu dirección de correo electrónico no está verificada.',
            resendLink: 'Haz clic aquí para reenviar el correo de verificación.',
            verificationSent: 'Se ha enviado un nuevo enlace de verificación a tu dirección de correo electrónico.',
        },
        security: {
            title: 'Actualizar contraseña',
            description: 'Asegúrate de que tu cuenta use una contraseña larga y aleatoria para mantenerse segura',
            currentPassword: 'Contraseña actual',
            currentPasswordPlaceholder: 'Contraseña actual',
            newPassword: 'Nueva contraseña',
            newPasswordPlaceholder: 'Nueva contraseña',
            confirmPassword: 'Confirmar contraseña',
            confirmPasswordPlaceholder: 'Confirmar contraseña',
        },
        deleteAccount: {
            title: 'Eliminar cuenta',
            description: 'Elimina tu cuenta y todos sus recursos',
            warningTitle: 'Advertencia',
            warningBody: 'Procede con precaución, esta acción no se puede deshacer.',
            deleteButton: 'Eliminar cuenta',
            confirmTitle: '¿Seguro que quieres eliminar tu cuenta?',
            confirmDescription:
                'Una vez eliminada tu cuenta, todos sus recursos y datos también se eliminarán de forma permanente. Introduce tu contraseña para confirmar que deseas eliminar tu cuenta de forma permanente.',
            passwordLabel: 'Contraseña',
            passwordPlaceholder: 'Contraseña',
        },
        twoFactor: {
            title: 'Autenticación de dos factores',
            description: 'Gestiona la configuración de tu autenticación de dos factores',
            disabledIntro:
                'Cuando actives la autenticación de dos factores, se te pedirá un PIN seguro durante el inicio de sesión. Este PIN se puede obtener desde una aplicación TOTP en tu teléfono.',
            continueSetup: 'Continuar configuración',
            enable: 'Activar 2FA',
            enabledIntro:
                'Se te pedirá un PIN seguro y aleatorio durante el inicio de sesión, que puedes obtener desde la aplicación TOTP en tu teléfono.',
            disable: 'Desactivar 2FA',
        },
        passkeys: {
            title: 'Passkeys',
            description: 'Gestiona tus passkeys para iniciar sesión sin contraseña',
            emptyTitle: 'Aún no hay passkeys',
            emptyDescription: 'Añade una passkey para iniciar sesión sin contraseña',
        },
```

- [ ] **Step 2: `Profile.vue`**

Add `import { useI18n } from 'vue-i18n';` and `const { t } = useI18n();`. Replace: breadcrumb `title: 'Profile settings'` and `<Head title="Profile settings" />` and `<h1 class="sr-only">Profile settings</h1>` → keep these as `'Profile settings'`-equivalent, add `settings.profileSettingsTitle` — actually reuse `t('settings.profile.title')` is just "Profile", not "Profile settings"; add one extra key `pageTitle: 'Profile settings'` / `'Impostazioni profilo'` / `'Configuración de perfil'` under `settings.profile` for this specific head/breadcrumb/sr-only usage, keeping `title`/`description` for the on-page `<Heading>` component as already defined in Step 1. Use `t('settings.profile.pageTitle')` for breadcrumb/head/sr-only, and `t('settings.profile.title')`/`t('settings.profile.description')` for the `<Heading>`. Label "Name" → `t('common.fields.name')`; placeholder → `t('settings.profile.namePlaceholder')`; Label "Email address" → `t('common.fields.email')`; placeholder → `t('settings.profile.emailPlaceholder')`; "Your email address is unverified." → `t('settings.profile.unverified')`; "Click here to re-send the verification email." → `t('settings.profile.resendLink')`; "A new verification link has been sent to your email address." → `t('settings.profile.verificationSent')`; Save button → `t('common.actions.save')`.

Add the `pageTitle` key to all three `settings.profile` blocks from Step 1 (`'Profile settings'` / `'Impostazioni profilo'` / `'Configuración de perfil'`).

- [ ] **Step 3: `Security.vue`**

Same `pageTitle` pattern: add `settings.security.pageTitle` (`'Security settings'` / `'Impostazioni sicurezza'` / `'Configuración de seguridad'`) for the breadcrumb/head/sr-only, and use `settings.security.title`/`description` for the `<Heading>`. Label "Current password" → `t('settings.security.currentPassword')`, placeholder → `currentPasswordPlaceholder`; "New password" → `newPassword`/`newPasswordPlaceholder`; "Confirm password" → `confirmPassword`/`confirmPasswordPlaceholder`; Save button → `t('common.actions.save')`.

- [ ] **Step 4: `DeleteUser.vue`**

Add `import { useI18n } from 'vue-i18n';` and `const { t } = useI18n();`. `<Heading title="Delete account" description="Delete your account and all of its resources" />` → `:title="t('settings.deleteAccount.title')"` / `:description="t('settings.deleteAccount.description')"`; "Warning" → `t('settings.deleteAccount.warningTitle')`; "Please proceed with caution, this cannot be undone." → `t('settings.deleteAccount.warningBody')`; both "Delete account" buttons → `t('settings.deleteAccount.deleteButton')`; "Are you sure you want to delete your account?" → `t('settings.deleteAccount.confirmTitle')`; the `DialogDescription` paragraph → `t('settings.deleteAccount.confirmDescription')`; `sr-only` "Password" label → `t('settings.deleteAccount.passwordLabel')`; placeholder "Password" → `t('settings.deleteAccount.passwordPlaceholder')`; "Cancel" → `t('common.actions.cancel')`.

- [ ] **Step 5: `ManageTwoFactor.vue`**

Add `import { useI18n } from 'vue-i18n';` and `const { t } = useI18n();`. `<Heading title="Two-factor authentication" description="Manage your two-factor authentication settings" />` → `settings.twoFactor.title`/`description`; the "When you enable..." paragraph → `settings.twoFactor.disabledIntro`; "Continue setup" → `settings.twoFactor.continueSetup`; "Enable 2FA" → `settings.twoFactor.enable`; the "You will be prompted..." paragraph → `settings.twoFactor.enabledIntro`; "Disable 2FA" → `settings.twoFactor.disable`.

- [ ] **Step 6: `ManagePasskeys.vue`**

Add `import { useI18n } from 'vue-i18n';` and `const { t } = useI18n();`. `<Heading title="Passkeys" description="Manage your passkeys for passwordless sign-in" />` → `settings.passkeys.title`/`description`; "No passkeys yet" → `settings.passkeys.emptyTitle`; "Add a passkey to sign in without a password" → `settings.passkeys.emptyDescription`.

- [ ] **Step 7: Verify**

Run: `npm run types:check && npm run lint:check && npm run build`
Expected: all PASS.
Run: `php artisan test --compact --filter=ProfileUpdateTest`
Run: `php artisan test --compact --filter=SecurityTest`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add resources/js/pages/settings/Profile.vue resources/js/pages/settings/Security.vue resources/js/components/DeleteUser.vue resources/js/components/ManageTwoFactor.vue resources/js/components/ManagePasskeys.vue resources/js/lang
git commit -m "feat: translate the profile, security, and account-management settings pages"
```

---

## Task 11: Translate the Companies pages

**Files:**
- Modify: `resources/js/pages/companies/Index.vue`
- Modify: `resources/js/pages/companies/Create.vue`
- Modify: `resources/js/pages/companies/Edit.vue`
- Modify: `resources/js/lang/en.ts`, `resources/js/lang/it.ts`, `resources/js/lang/es.ts`

**Interfaces:**
- Consumes: `common.actions.*`, `common.fields.*` (Task 3).
- Produces: `companies.index.*`, `companies.create.*`, `companies.edit.*`.

- [ ] **Step 1: Add the keys to `en.ts`**

```ts
    companies: {
        index: {
            title: 'Companies',
            description: 'Manage the companies that can issue invoices',
            newButton: 'New company',
            searchPlaceholder: 'Search by name...',
            columns: { name: 'Name', city: 'City', country: 'Country', email: 'Email', default: 'Default' },
            yes: 'Yes',
            empty: 'No companies found.',
        },
        create: {
            title: 'New company',
            description: 'Add an issuing company to the registry',
            namePlaceholder: 'Company name',
            taxId: 'Tax ID',
            taxIdPlaceholder: 'Tax identification number',
            addressPlaceholder: 'Address',
            zipPlaceholder: 'ZIP code',
            cityPlaceholder: 'City',
            emailPlaceholder: 'Email address',
            phonePlaceholder: 'Phone number',
            iban: 'IBAN',
            ibanPlaceholder: 'Bank account IBAN',
            logo: 'Logo',
            defaultCompany: 'Default company for new invoices',
        },
        edit: {
            title: 'Edit company',
            description: 'Update {name}',
            currentLogoAlt: 'Current logo',
            removeLogo: 'Remove current logo',
            confirmDelete: 'Delete this company? This cannot be undone.',
            deleteButton: 'Delete company',
        },
    },
```

Add to `it.ts`:

```ts
    companies: {
        index: {
            title: 'Aziende',
            description: 'Gestisci le aziende che possono emettere fatture',
            newButton: 'Nuova azienda',
            searchPlaceholder: 'Cerca per nome...',
            columns: { name: 'Nome', city: 'Città', country: 'Paese', email: 'Email', default: 'Predefinita' },
            yes: 'Sì',
            empty: 'Nessuna azienda trovata.',
        },
        create: {
            title: 'Nuova azienda',
            description: "Aggiungi un'azienda emittente all'anagrafica",
            namePlaceholder: 'Nome azienda',
            taxId: 'Partita IVA',
            taxIdPlaceholder: 'Numero identificativo fiscale',
            addressPlaceholder: 'Indirizzo',
            zipPlaceholder: 'CAP',
            cityPlaceholder: 'Città',
            emailPlaceholder: 'Indirizzo email',
            phonePlaceholder: 'Numero di telefono',
            iban: 'IBAN',
            ibanPlaceholder: 'IBAN del conto bancario',
            logo: 'Logo',
            defaultCompany: 'Azienda predefinita per le nuove fatture',
        },
        edit: {
            title: 'Modifica azienda',
            description: 'Aggiorna {name}',
            currentLogoAlt: 'Logo attuale',
            removeLogo: 'Rimuovi logo attuale',
            confirmDelete: "Eliminare questa azienda? L'azione non può essere annullata.",
            deleteButton: 'Elimina azienda',
        },
    },
```

Add to `es.ts`:

```ts
    companies: {
        index: {
            title: 'Empresas',
            description: 'Gestiona las empresas que pueden emitir facturas',
            newButton: 'Nueva empresa',
            searchPlaceholder: 'Buscar por nombre...',
            columns: { name: 'Nombre', city: 'Ciudad', country: 'País', email: 'Correo electrónico', default: 'Predeterminada' },
            yes: 'Sí',
            empty: 'No se encontraron empresas.',
        },
        create: {
            title: 'Nueva empresa',
            description: 'Añade una empresa emisora al registro',
            namePlaceholder: 'Nombre de la empresa',
            taxId: 'NIF',
            taxIdPlaceholder: 'Número de identificación fiscal',
            addressPlaceholder: 'Dirección',
            zipPlaceholder: 'Código postal',
            cityPlaceholder: 'Ciudad',
            emailPlaceholder: 'Correo electrónico',
            phonePlaceholder: 'Número de teléfono',
            iban: 'IBAN',
            ibanPlaceholder: 'IBAN de la cuenta bancaria',
            logo: 'Logotipo',
            defaultCompany: 'Empresa predeterminada para nuevas facturas',
        },
        edit: {
            title: 'Editar empresa',
            description: 'Actualizar {name}',
            currentLogoAlt: 'Logotipo actual',
            removeLogo: 'Eliminar logotipo actual',
            confirmDelete: 'żEliminar esta empresa? Esta acción no se puede deshacer.',
            deleteButton: 'Eliminar empresa',
        },
    },
```

Fix the mis-encoded `¿` in the Spanish `confirmDelete` line above — write it literally as `¿Eliminar esta empresa? Esta acción no se puede deshacer.` in the actual file (the escaping above is a transcription artifact of this plan document, not something to type literally).

- [ ] **Step 2: `Index.vue`**

Add `import { useI18n } from 'vue-i18n';` and `const { t } = useI18n();`. Replace: breadcrumb `title: 'Companies'` and `<Head title="Companies" />` → `t('companies.index.title')`; `<Heading title="Companies" description="Manage the companies that can issue invoices" />` → `:title="t('companies.index.title')"` `:description="t('companies.index.description')"`; "New company" → `t('companies.index.newButton')`; search placeholder → `t('companies.index.searchPlaceholder')`; table headers "Name"/"City"/"Country"/"Email"/"Default" → `t('companies.index.columns.name')` etc.; `company.is_default ? 'Yes' : '—'` → `company.is_default ? t('companies.index.yes') : '—'`; icon button `title="Edit"` → `:title="t('common.actions.edit')"`; empty state "No companies found." → `t('companies.index.empty')`.

- [ ] **Step 3: `Create.vue`**

Add `import { useI18n } from 'vue-i18n';` and `const { t } = useI18n();`. Breadcrumb/head/`<Heading>` → `companies.create.title`/`description`. Field labels use `common.fields.*` where the label text is identical to the shared ones (Name, Address, ZIP, City, Country/Select a country, Email, Phone) and `companies.create.*` for the company-specific placeholders/labels (Tax ID, IBAN, Logo, Default company checkbox): `Label "Name"` → `t('common.fields.name')`, placeholder → `t('companies.create.namePlaceholder')`; `Label "Tax ID"` → `t('companies.create.taxId')`, placeholder → `t('companies.create.taxIdPlaceholder')`; `Label "Address"` → `t('common.fields.address')`, placeholder → `t('companies.create.addressPlaceholder')`; `Label "ZIP"` → `t('common.fields.zip')`, placeholder → `t('companies.create.zipPlaceholder')`; `Label "City"` → `t('common.fields.city')`, placeholder → `t('companies.create.cityPlaceholder')`; `Label "Country"` → `t('common.fields.country')`, `SelectValue placeholder="Select a country"` → `:placeholder="t('common.fields.selectCountry')"`; `Label "Email"` → `t('common.fields.email')`, placeholder → `t('companies.create.emailPlaceholder')`; `Label "Phone"` → `t('common.fields.phone')`, placeholder → `t('companies.create.phonePlaceholder')`; `Label "IBAN"` → `t('companies.create.iban')`, placeholder → `t('companies.create.ibanPlaceholder')`; `Label "Logo"` → `t('companies.create.logo')`; `Label "Default company for new invoices"` → `t('companies.create.defaultCompany')`; `Button "Save"` → `t('common.actions.save')`; `Link "Cancel"` → `t('common.actions.cancel')`.

- [ ] **Step 4: `Edit.vue`**

Add `import { useI18n } from 'vue-i18n';` and `const { t } = useI18n();`. Same field labels/placeholders as `Create.vue` (Step 3). Breadcrumb/head → `companies.edit.title`. `<Heading :description="`Update ${company.name}`" />` → `:description="t('companies.edit.description', { name: company.name })"` (vue-i18n named interpolation — the `{name}` placeholder in the `en`/`it`/`es` strings from Step 1 is filled automatically). `alt="Current logo"` → `:alt="t('companies.edit.currentLogoAlt')"`. `Label "Remove current logo"` → `t('companies.edit.removeLogo')`. `onDelete()`'s `confirm('Delete this company? This cannot be undone.')` → `confirm(t('companies.edit.confirmDelete'))`. `Button "Delete company"` → `t('companies.edit.deleteButton')`. `Button "Save"` → `t('common.actions.save')`. `Link "Cancel"` → `t('common.actions.cancel')`.

- [ ] **Step 5: Verify**

Run: `npm run types:check && npm run lint:check && npm run build`
Expected: all PASS.
Run: `php artisan test --compact --filter=CompanyTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add resources/js/pages/companies resources/js/lang
git commit -m "feat: translate the companies pages"
```

---

## Task 12: Translate the Countries pages

**Files:**
- Modify: `resources/js/pages/countries/Index.vue`
- Modify: `resources/js/pages/countries/Create.vue`
- Modify: `resources/js/pages/countries/Edit.vue`
- Modify: `resources/js/lang/en.ts`, `resources/js/lang/it.ts`, `resources/js/lang/es.ts`

**Interfaces:**
- Consumes: `common.actions.*`, `common.fields.name` (Task 3).
- Produces: `countries.index.*`, `countries.create.*`, `countries.edit.*`.

- [ ] **Step 1: Add the keys to `en.ts`**

```ts
    countries: {
        index: {
            title: 'Countries',
            description: 'Manage the countries available to customers',
            newButton: 'New country',
            searchPlaceholder: 'Search countries...',
            column: 'Name',
            empty: 'No countries found.',
        },
        create: {
            title: 'New country',
            description: 'Add a country to the list available to customers',
            namePlaceholder: 'Country name',
        },
        edit: {
            title: 'Edit country',
            description: 'Update {name}',
            confirmDelete: 'Delete this country? This cannot be undone.',
            deleteButton: 'Delete country',
        },
    },
```

Add to `it.ts`:

```ts
    countries: {
        index: {
            title: 'Paesi',
            description: 'Gestisci i paesi disponibili per i clienti',
            newButton: 'Nuovo paese',
            searchPlaceholder: 'Cerca paesi...',
            column: 'Nome',
            empty: 'Nessun paese trovato.',
        },
        create: {
            title: 'Nuovo paese',
            description: 'Aggiungi un paese alla lista disponibile per i clienti',
            namePlaceholder: 'Nome del paese',
        },
        edit: {
            title: 'Modifica paese',
            description: 'Aggiorna {name}',
            confirmDelete: "Eliminare questo paese? L'azione non può essere annullata.",
            deleteButton: 'Elimina paese',
        },
    },
```

Add to `es.ts`:

```ts
    countries: {
        index: {
            title: 'Países',
            description: 'Gestiona los países disponibles para los clientes',
            newButton: 'Nuevo país',
            searchPlaceholder: 'Buscar países...',
            column: 'Nombre',
            empty: 'No se encontraron países.',
        },
        create: {
            title: 'Nuevo país',
            description: 'Añade un país a la lista disponible para los clientes',
            namePlaceholder: 'Nombre del país',
        },
        edit: {
            title: 'Editar país',
            description: 'Actualizar {name}',
            confirmDelete: '¿Eliminar este país? Esta acción no se puede deshacer.',
            deleteButton: 'Eliminar país',
        },
    },
```

- [ ] **Step 2: `Index.vue`**

Add `useI18n`. Breadcrumb/head/`<Heading>` → `countries.index.title`/`description`; "New country" → `countries.index.newButton`; search placeholder → `countries.index.searchPlaceholder`; table header "Name" → `countries.index.column`; icon button `title="Edit"` → `t('common.actions.edit')`; empty state → `countries.index.empty`.

- [ ] **Step 3: `Create.vue`**

Add `useI18n`. Breadcrumb/head/`<Heading>` → `countries.create.title`/`description`; `Label "Name"` → `t('common.fields.name')`, placeholder → `t('countries.create.namePlaceholder')`; `Button "Save"` → `t('common.actions.save')`; `Link "Cancel"` → `t('common.actions.cancel')`.

- [ ] **Step 4: `Edit.vue`**

Add `useI18n`. Breadcrumb/head → `countries.edit.title`; `<Heading :description="`Update ${country.name}`" />` → `t('countries.edit.description', { name: country.name })`; `Label "Name"` → `t('common.fields.name')`, placeholder → `t('countries.create.namePlaceholder')` (reuse the same placeholder key as Create, since it's identical text); `confirm(...)` → `confirm(t('countries.edit.confirmDelete'))`; `Button "Delete country"` → `t('countries.edit.deleteButton')`; `Button "Save"` → `t('common.actions.save')`; `Link "Cancel"` → `t('common.actions.cancel')`.

- [ ] **Step 5: Verify**

Run: `npm run types:check && npm run lint:check && npm run build`
Expected: all PASS.
Run: `php artisan test --compact --filter=CountryTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add resources/js/pages/countries resources/js/lang
git commit -m "feat: translate the countries pages"
```

---

## Task 13: Translate the Customers pages

**Files:**
- Modify: `resources/js/pages/customers/Index.vue`
- Modify: `resources/js/pages/customers/Create.vue`
- Modify: `resources/js/pages/customers/Edit.vue`
- Modify: `resources/js/lang/en.ts`, `resources/js/lang/it.ts`, `resources/js/lang/es.ts`

**Interfaces:**
- Consumes: `common.actions.*`, `common.fields.*` (Task 3).
- Produces: `customers.index.*`, `customers.create.*`, `customers.edit.*`.

- [ ] **Step 1: Add the keys to `en.ts`**

```ts
    customers: {
        index: {
            title: 'Customers',
            description: 'Manage your customer registry',
            newButton: 'New customer',
            searchPlaceholder: 'Search by name or email...',
            columns: { name: 'Name', city: 'City', country: 'Country', email: 'Email' },
            empty: 'No customers found.',
        },
        create: {
            title: 'New customer',
            description: 'Add a customer to the registry',
            namePlaceholder: 'Customer name',
            addressPlaceholder: 'Address',
            zipPlaceholder: 'ZIP code',
            cityPlaceholder: 'City',
            stateProvince: 'State / Province',
            stateProvincePlaceholder: 'State or province',
            emailPlaceholder: 'Email address',
            website: 'Website',
            phonePlaceholder: 'Phone number',
            taxId: 'NIF',
            taxIdPlaceholder: 'Tax identification number',
        },
        edit: {
            title: 'Edit customer',
            description: 'Update {name}',
            confirmDelete: 'Delete this customer? This cannot be undone.',
            deleteButton: 'Delete customer',
        },
    },
```

Add to `it.ts`:

```ts
    customers: {
        index: {
            title: 'Clienti',
            description: 'Gestisci la tua anagrafica clienti',
            newButton: 'Nuovo cliente',
            searchPlaceholder: 'Cerca per nome o email...',
            columns: { name: 'Nome', city: 'Città', country: 'Paese', email: 'Email' },
            empty: 'Nessun cliente trovato.',
        },
        create: {
            title: 'Nuovo cliente',
            description: "Aggiungi un cliente all'anagrafica",
            namePlaceholder: 'Nome cliente',
            addressPlaceholder: 'Indirizzo',
            zipPlaceholder: 'CAP',
            cityPlaceholder: 'Città',
            stateProvince: 'Stato / Provincia',
            stateProvincePlaceholder: 'Stato o provincia',
            emailPlaceholder: 'Indirizzo email',
            website: 'Sito web',
            phonePlaceholder: 'Numero di telefono',
            taxId: 'Codice fiscale',
            taxIdPlaceholder: 'Numero identificativo fiscale',
        },
        edit: {
            title: 'Modifica cliente',
            description: 'Aggiorna {name}',
            confirmDelete: "Eliminare questo cliente? L'azione non può essere annullata.",
            deleteButton: 'Elimina cliente',
        },
    },
```

Add to `es.ts`:

```ts
    customers: {
        index: {
            title: 'Clientes',
            description: 'Gestiona tu registro de clientes',
            newButton: 'Nuevo cliente',
            searchPlaceholder: 'Buscar por nombre o correo...',
            columns: { name: 'Nombre', city: 'Ciudad', country: 'País', email: 'Correo electrónico' },
            empty: 'No se encontraron clientes.',
        },
        create: {
            title: 'Nuevo cliente',
            description: 'Añade un cliente al registro',
            namePlaceholder: 'Nombre del cliente',
            addressPlaceholder: 'Dirección',
            zipPlaceholder: 'Código postal',
            cityPlaceholder: 'Ciudad',
            stateProvince: 'Estado / Provincia',
            stateProvincePlaceholder: 'Estado o provincia',
            emailPlaceholder: 'Correo electrónico',
            website: 'Sitio web',
            phonePlaceholder: 'Número de teléfono',
            taxId: 'NIF',
            taxIdPlaceholder: 'Número de identificación fiscal',
        },
        edit: {
            title: 'Editar cliente',
            description: 'Actualizar {name}',
            confirmDelete: '¿Eliminar este cliente? Esta acción no se puede deshacer.',
            deleteButton: 'Eliminar cliente',
        },
    },
```

- [ ] **Step 2: `Index.vue`**

Add `useI18n`. Breadcrumb/head/`<Heading>` → `customers.index.title`/`description`; "New customer" → `customers.index.newButton`; search placeholder → `customers.index.searchPlaceholder`; table headers "Name"/"City"/"Country"/"Email" → `customers.index.columns.*`; icon button `title="Edit"` → `t('common.actions.edit')`; empty state → `customers.index.empty`.

- [ ] **Step 3: `Create.vue`**

Add `useI18n`. Breadcrumb/head/`<Heading>` → `customers.create.title`/`description`. Shared field labels use `common.fields.*` (Name, Address, ZIP, City, Country/Select a country, Email, Phone) with `customers.create.*` placeholders; customer-specific: `Label "State / Province"` → `t('customers.create.stateProvince')`, placeholder → `stateProvincePlaceholder`; `Label "Website"` → `t('customers.create.website')` (placeholder `https://example.com` stays literal — it's an example value, not translatable copy); `Label "NIF"` → `t('customers.create.taxId')`, placeholder → `taxIdPlaceholder`. `Button "Save"` → `t('common.actions.save')`; `Link "Cancel"` → `t('common.actions.cancel')`.

- [ ] **Step 4: `Edit.vue`**

Add `useI18n`. Same fields as `Create.vue` (Step 3). Breadcrumb/head → `customers.edit.title`; `<Heading :description>` → `t('customers.edit.description', { name: customer.name })`; `confirm(...)` → `confirm(t('customers.edit.confirmDelete'))`; `Button "Delete customer"` → `t('customers.edit.deleteButton')`; `Button "Save"` → `t('common.actions.save')`; `Link "Cancel"` → `t('common.actions.cancel')`.

- [ ] **Step 5: Verify**

Run: `npm run types:check && npm run lint:check && npm run build`
Expected: all PASS.
Run: `php artisan test --compact --filter=CustomerTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add resources/js/pages/customers resources/js/lang
git commit -m "feat: translate the customers pages"
```

---

## Task 14: Translate the Products pages

**Files:**
- Modify: `resources/js/pages/products/Index.vue`
- Modify: `resources/js/pages/products/Create.vue`
- Modify: `resources/js/pages/products/Edit.vue`
- Modify: `resources/js/lang/en.ts`, `resources/js/lang/it.ts`, `resources/js/lang/es.ts`

**Interfaces:**
- Consumes: `common.actions.*` (Task 3).
- Produces: `products.index.*`, `products.create.*`, `products.edit.*`, `products.type.*` (shared between the index badge/label map and both form `Select` components).

- [ ] **Step 1: Add the keys to `en.ts`**

```ts
    products: {
        type: { product: 'Product', service: 'Service' },
        index: {
            title: 'Products',
            description: 'Manage your product and service catalog',
            newButton: 'New product',
            searchPlaceholder: 'Search by code or description...',
            columns: { code: 'Code', type: 'Type', description: 'Description', price: 'Price' },
            empty: 'No products found.',
        },
        create: {
            title: 'New product',
            description: 'Add a product or service to the catalog',
            code: 'Code',
            codePlaceholder: 'Optional code',
            type: 'Type',
            selectType: 'Select a type',
            descriptionLabel: 'Description',
            descriptionPlaceholder: 'Description',
            price: 'Price',
            pricePlaceholder: 'Price',
        },
        edit: {
            title: 'Edit product',
            description: 'Update {name}',
            confirmDelete: 'Delete this product? This cannot be undone.',
            deleteButton: 'Delete product',
        },
    },
```

Add to `it.ts`:

```ts
    products: {
        type: { product: 'Prodotto', service: 'Servizio' },
        index: {
            title: 'Prodotti',
            description: 'Gestisci il tuo catalogo di prodotti e servizi',
            newButton: 'Nuovo prodotto',
            searchPlaceholder: 'Cerca per codice o descrizione...',
            columns: { code: 'Codice', type: 'Tipo', description: 'Descrizione', price: 'Prezzo' },
            empty: 'Nessun prodotto trovato.',
        },
        create: {
            title: 'Nuovo prodotto',
            description: 'Aggiungi un prodotto o servizio al catalogo',
            code: 'Codice',
            codePlaceholder: 'Codice opzionale',
            type: 'Tipo',
            selectType: 'Seleziona un tipo',
            descriptionLabel: 'Descrizione',
            descriptionPlaceholder: 'Descrizione',
            price: 'Prezzo',
            pricePlaceholder: 'Prezzo',
        },
        edit: {
            title: 'Modifica prodotto',
            description: 'Aggiorna {name}',
            confirmDelete: "Eliminare questo prodotto? L'azione non può essere annullata.",
            deleteButton: 'Elimina prodotto',
        },
    },
```

Add to `es.ts`:

```ts
    products: {
        type: { product: 'Producto', service: 'Servicio' },
        index: {
            title: 'Productos',
            description: 'Gestiona tu catálogo de productos y servicios',
            newButton: 'Nuevo producto',
            searchPlaceholder: 'Buscar por código o descripción...',
            columns: { code: 'Código', type: 'Tipo', description: 'Descripción', price: 'Precio' },
            empty: 'No se encontraron productos.',
        },
        create: {
            title: 'Nuevo producto',
            description: 'Añade un producto o servicio al catálogo',
            code: 'Código',
            codePlaceholder: 'Código opcional',
            type: 'Tipo',
            selectType: 'Selecciona un tipo',
            descriptionLabel: 'Descripción',
            descriptionPlaceholder: 'Descripción',
            price: 'Precio',
            pricePlaceholder: 'Precio',
        },
        edit: {
            title: 'Editar producto',
            description: 'Actualizar {name}',
            confirmDelete: '¿Eliminar este producto? Esta acción no se puede deshacer.',
            deleteButton: 'Eliminar producto',
        },
    },
```

- [ ] **Step 2: `Index.vue`**

Add `useI18n`. Breadcrumb/head/`<Heading>` → `products.index.title`/`description`; the `typeLabels` map becomes computed from `t()` instead of a static object:

```ts
const typeLabels = computed<Record<'product' | 'service', string>>(() => ({
    product: t('products.type.product'),
    service: t('products.type.service'),
}));
```

(import `computed` from `'vue'` if not already; template usage `typeLabels[product.type]` becomes `typeLabels.value[product.type]`.) "New product" → `products.index.newButton`; search placeholder → `products.index.searchPlaceholder`; table headers → `products.index.columns.*`; icon button `title="Edit"` → `t('common.actions.edit')`; empty state → `products.index.empty`.

- [ ] **Step 3: `Create.vue`**

Add `useI18n`. Breadcrumb/head/`<Heading>` → `products.create.title`/`description`; `Label "Code"` → `t('products.create.code')`, placeholder → `codePlaceholder`; `Label "Type"` → `t('products.create.type')`, `SelectValue placeholder="Select a type"` → `t('products.create.selectType')`, `SelectItem "Product"`/`"Service"` → `t('products.type.product')`/`t('products.type.service')`; `Label "Description"` → `t('products.create.descriptionLabel')`, placeholder → `descriptionPlaceholder`; `Label "Price"` → `t('products.create.price')`, placeholder → `pricePlaceholder`; `Button "Save"` → `t('common.actions.save')`; `Link "Cancel"` → `t('common.actions.cancel')`.

- [ ] **Step 4: `Edit.vue`**

Add `useI18n`. Same fields as `Create.vue` (Step 3). Breadcrumb/head → `products.edit.title`; `<Heading :description>` → `t('products.edit.description', { name: product.description })`; `confirm(...)` → `confirm(t('products.edit.confirmDelete'))`; `Button "Delete product"` → `t('products.edit.deleteButton')`; `Button "Save"` → `t('common.actions.save')`; `Link "Cancel"` → `t('common.actions.cancel')`.

- [ ] **Step 5: Verify**

Run: `npm run types:check && npm run lint:check && npm run build`
Expected: all PASS.
Run: `php artisan test --compact --filter=ProductTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add resources/js/pages/products resources/js/lang
git commit -m "feat: translate the products pages"
```

---

## Task 15: Translate the Invoices pages and `ProductPicker.vue`

**Files:**
- Modify: `resources/js/pages/invoices/Index.vue`
- Modify: `resources/js/pages/invoices/Create.vue`
- Modify: `resources/js/pages/invoices/Edit.vue`
- Modify: `resources/js/components/ProductPicker.vue`
- Modify: `resources/js/lang/en.ts`, `resources/js/lang/it.ts`, `resources/js/lang/es.ts`

**Interfaces:**
- Consumes: `common.actions.*` (Task 3).
- Produces: `invoices.index.*`, `invoices.create.*`, `invoices.edit.*`, `productPicker.*`. Also fixes the pre-existing `title="Duplica"` mixed-language bug found during string extraction (Italian text hardcoded regardless of locale) by giving it a real, locale-aware key.

- [ ] **Step 1: Add the keys to `en.ts`**

```ts
    invoices: {
        index: {
            title: 'Invoices',
            description: 'Manage your invoices',
            newButton: 'New invoice',
            searchPlaceholder: 'Search by number or customer...',
            columns: { number: 'Number', date: 'Date', customer: 'Customer', paid: 'Paid', total: 'Total' },
            paid: 'Paid',
            unpaid: 'Unpaid',
            preview: 'Preview',
            pdf: 'PDF',
            duplicate: 'Duplicate',
            empty: 'No invoices found.',
        },
        create: {
            title: 'New invoice',
            description: 'Create a new invoice',
            number: 'Number',
            date: 'Date',
            customer: 'Customer',
            selectCustomer: 'Select a customer',
            company: 'Issuing company',
            selectCompany: 'Select a company',
            language: 'Language',
            selectLanguage: 'Select a language',
            paid: 'Paid',
            note: 'Note',
            notePlaceholder: 'Optional note',
            rows: 'Rows',
            addRow: 'Add row',
            rowDescription: 'Description',
            rowQuantity: 'Quantity',
            rowPrice: 'Price',
            rowVat: 'VAT (%)',
            rowVatPlaceholder: 'VAT %',
            total: 'Total: {amount}',
        },
        edit: {
            title: 'Edit invoice',
            description: 'Update invoice {number}',
            confirmDelete: 'Delete this invoice? This cannot be undone.',
            deleteButton: 'Delete invoice',
        },
    },
    productPicker: {
        title: 'Choose a product',
        searchPlaceholder: 'Search by code or description...',
        empty: 'No products found.',
        fallbackLabel: 'From catalog',
        page: 'Page {current} of {last}',
    },
```

Add to `it.ts`:

```ts
    invoices: {
        index: {
            title: 'Fatture',
            description: 'Gestisci le tue fatture',
            newButton: 'Nuova fattura',
            searchPlaceholder: 'Cerca per numero o cliente...',
            columns: { number: 'Numero', date: 'Data', customer: 'Cliente', paid: 'Pagata', total: 'Totale' },
            paid: 'Pagata',
            unpaid: 'Non pagata',
            preview: 'Anteprima',
            pdf: 'PDF',
            duplicate: 'Duplica',
            empty: 'Nessuna fattura trovata.',
        },
        create: {
            title: 'Nuova fattura',
            description: 'Crea una nuova fattura',
            number: 'Numero',
            date: 'Data',
            customer: 'Cliente',
            selectCustomer: 'Seleziona un cliente',
            company: 'Azienda emittente',
            selectCompany: "Seleziona un'azienda",
            language: 'Lingua',
            selectLanguage: 'Seleziona una lingua',
            paid: 'Pagata',
            note: 'Nota',
            notePlaceholder: 'Nota opzionale',
            rows: 'Righe',
            addRow: 'Aggiungi riga',
            rowDescription: 'Descrizione',
            rowQuantity: 'Quantità',
            rowPrice: 'Prezzo',
            rowVat: 'IVA (%)',
            rowVatPlaceholder: 'IVA %',
            total: 'Totale: {amount}',
        },
        edit: {
            title: 'Modifica fattura',
            description: 'Aggiorna la fattura {number}',
            confirmDelete: "Eliminare questa fattura? L'azione non può essere annullata.",
            deleteButton: 'Elimina fattura',
        },
    },
    productPicker: {
        title: 'Scegli un prodotto',
        searchPlaceholder: 'Cerca per codice o descrizione...',
        empty: 'Nessun prodotto trovato.',
        fallbackLabel: 'Dal catalogo',
        page: 'Pagina {current} di {last}',
    },
```

Add to `es.ts`:

```ts
    invoices: {
        index: {
            title: 'Facturas',
            description: 'Gestiona tus facturas',
            newButton: 'Nueva factura',
            searchPlaceholder: 'Buscar por número o cliente...',
            columns: { number: 'Número', date: 'Fecha', customer: 'Cliente', paid: 'Pagada', total: 'Total' },
            paid: 'Pagada',
            unpaid: 'Sin pagar',
            preview: 'Vista previa',
            pdf: 'PDF',
            duplicate: 'Duplicar',
            empty: 'No se encontraron facturas.',
        },
        create: {
            title: 'Nueva factura',
            description: 'Crea una nueva factura',
            number: 'Número',
            date: 'Fecha',
            customer: 'Cliente',
            selectCustomer: 'Selecciona un cliente',
            company: 'Empresa emisora',
            selectCompany: 'Selecciona una empresa',
            language: 'Idioma',
            selectLanguage: 'Selecciona un idioma',
            paid: 'Pagada',
            note: 'Nota',
            notePlaceholder: 'Nota opcional',
            rows: 'Líneas',
            addRow: 'Añadir línea',
            rowDescription: 'Descripción',
            rowQuantity: 'Cantidad',
            rowPrice: 'Precio',
            rowVat: 'IVA (%)',
            rowVatPlaceholder: 'IVA %',
            total: 'Total: {amount}',
        },
        edit: {
            title: 'Editar factura',
            description: 'Actualizar factura {number}',
            confirmDelete: '¿Eliminar esta factura? Esta acción no se puede deshacer.',
            deleteButton: 'Eliminar factura',
        },
    },
    productPicker: {
        title: 'Elige un producto',
        searchPlaceholder: 'Buscar por código o descripción...',
        empty: 'No se encontraron productos.',
        fallbackLabel: 'Del catálogo',
        page: 'Página {current} de {last}',
    },
```

- [ ] **Step 2: `Index.vue`**

Add `useI18n`. Breadcrumb/head/`<Heading>` → `invoices.index.title`/`description`; "New invoice" → `invoices.index.newButton`; search placeholder → `invoices.index.searchPlaceholder`; table headers → `invoices.index.columns.*`; `invoice.paid ? 'Paid' : 'Unpaid'` → `invoice.paid ? t('invoices.index.paid') : t('invoices.index.unpaid')`; icon button titles: `title="Preview"` → `t('invoices.index.preview')`, `title="PDF"` → `t('invoices.index.pdf')`, `title="Edit"` → `t('common.actions.edit')`, `title="Duplica"` → `t('invoices.index.duplicate')` (this is the mixed-language fix — the button now correctly reads "Duplicate"/"Duplica"/"Duplicar" depending on locale instead of always being hardcoded Italian); empty state → `invoices.index.empty`.

- [ ] **Step 3: `Create.vue`**

Add `import { useI18n } from 'vue-i18n';` and `const { t } = useI18n();` (this file already imports `computed`/`ref` from Vue — add to the existing Vue import). Breadcrumb `{ title: 'Invoices', href: index() }` → `{ title: t('invoices.index.title'), href: index() }`; `<Head title="New invoice" />` → `:title="t('invoices.create.title')"`; `<Heading title="New invoice" description="Create a new invoice" />` → `:title="t('invoices.create.title')"` `:description="t('invoices.create.description')"`; `Label "Number"` → `t('invoices.create.number')`; the `placeholder="2026-0001"` attribute stays exactly as the literal string it already is — it's an example format, not translatable copy, so no key was added for it in Step 1 and no `:placeholder` binding is introduced here; `Label "Date"` → `t('invoices.create.date')`; `Label "Customer"` → `t('invoices.create.customer')`, `SelectValue placeholder="Select a customer"` → `:placeholder="t('invoices.create.selectCustomer')"`; `Label "Issuing company"` → `t('invoices.create.company')`, `SelectValue placeholder="Select a company"` → `:placeholder="t('invoices.create.selectCompany')"`; `Label "Language"` → `t('invoices.create.language')`, `SelectValue placeholder="Select a language"` → `:placeholder="t('invoices.create.selectLanguage')"` (the `SelectItem` values "Italiano"/"English"/"Español" stay as literal native-form language names — same reasoning as the Settings language switcher in Task 5, do not translate them); `Label "Paid"` → `t('invoices.create.paid')`; `Label "Note"` → `t('invoices.create.note')`, placeholder → `t('invoices.create.notePlaceholder')`; `Label "Rows"` → `t('invoices.create.rows')`; `Button "Add row"` → `t('common.actions.addRow')`; column header spans "Description"/"Quantity"/"Price"/"VAT (%)" → `t('invoices.create.rowDescription')`/`rowQuantity`/`rowPrice`/`rowVat`; row `Input placeholder="Description"` → `:placeholder="t('invoices.create.rowDescription')"`, `"Quantity"` → `t('invoices.create.rowQuantity')`, `"Price"` → `t('invoices.create.rowPrice')`, `"VAT %"` → `t('invoices.create.rowVatPlaceholder')`; `<p>Total: {{ total.toFixed(2) }}</p>` → `<p>{{ t('invoices.create.total', { amount: total.toFixed(2) }) }}</p>`; `Button "Save"` → `t('common.actions.save')`; `Link "Cancel"` → `t('common.actions.cancel')`.

Remove the now-unused `numberPlaceholder` key from `invoices.create` in all three lang files written in Step 1 (it was scaffolded but the placeholder stays a literal example value per this step's decision — keeping an unused key would fail nothing technically, but leaves dead content; delete it for cleanliness).

- [ ] **Step 4: `Edit.vue`**

Add `useI18n`. Same field translations as `Create.vue` (Step 3) for the shared parts of the form. Breadcrumb/head → `invoices.edit.title`; `title="Preview"`/`title="PDF"` → same as Step 2; `<Heading :description="`Update invoice ${invoice.number}`" />` → `:description="t('invoices.edit.description', { number: invoice.number })"`; `confirm(...)` → `confirm(t('invoices.edit.confirmDelete'))`; `Button "Delete invoice"` → `t('invoices.edit.deleteButton')`.

- [ ] **Step 5: `ProductPicker.vue`**

Add `useI18n`. `selectedLabel || 'From catalog'` (both occurrences — `sr-only` span and `TooltipContent`) → `selectedLabel || t('productPicker.fallbackLabel')`; `<DialogTitle>Choose a product</DialogTitle>` → `<DialogTitle>{{ t('productPicker.title') }}</DialogTitle>`; search placeholder → `t('productPicker.searchPlaceholder')`; empty state → `t('productPicker.empty')`; `Button "Previous"` → `t('common.actions.previous')`; `<span>Page {{ currentPage }} of {{ lastPage }}</span>` → `<span>{{ t('productPicker.page', { current: currentPage, last: lastPage }) }}</span>`; `Button "Next"` → `t('common.actions.next')`.

- [ ] **Step 6: Verify**

Run: `npm run types:check && npm run lint:check && npm run build`
Expected: all PASS.
Run: `php artisan test --compact --filter=InvoiceTest`
Run: `php artisan test --compact --filter=InvoiceTemplateTest`
Expected: PASS (invoice PDF template rendering is unrelated to the UI locale — the invoice's own `language` field still drives the PDF, untouched by this task).

- [ ] **Step 7: Commit**

```bash
git add resources/js/pages/invoices resources/js/components/ProductPicker.vue resources/js/lang
git commit -m "feat: translate the invoices pages and product picker, fix hardcoded Italian duplicate button"
```

---

## Task 16: Sweep remaining components for leftover hardcoded strings

**Files:**
- Modify (as needed, based on what the sweep finds): `resources/js/components/TwoFactorRecoveryCodes.vue`, `resources/js/components/TwoFactorSetupModal.vue`, `resources/js/components/PasskeyItem.vue`, `resources/js/components/PasskeyRegister.vue`, and any other file the grep in Step 1 surfaces.
- Modify: `resources/js/lang/en.ts`, `resources/js/lang/it.ts`, `resources/js/lang/es.ts`

**Interfaces:**
- Consumes: `common.actions.*` and any namespace from earlier tasks that fits (e.g. `settings.twoFactor.*`, `settings.passkeys.*`).
- Produces: a namespace per remaining component (e.g. `settings.twoFactor.recoveryCodes.*`, `settings.twoFactor.setupModal.*`, `settings.passkeys.item.*`, `settings.passkeys.register.*`) — exact key names depend on what Step 1 finds, follow the same nesting convention as every prior task.

This task closes out the long tail: Tasks 8–15 covered every page and every component that page directly imports, but four components rendered deeper in the Settings → Security tree (`TwoFactorRecoveryCodes.vue`, `TwoFactorSetupModal.vue`, `PasskeyItem.vue`, `PasskeyRegister.vue`) were flagged during string extraction as not yet audited. Rather than guess their exact content up front, this task defines a precise, machine-checkable completion condition instead of hand-listing strings that may be stale by execution time.

- [ ] **Step 1: Find every remaining hardcoded string**

Run this grep across the whole `resources/js` tree to surface literal English text still sitting in templates (adjust/re-run iteratively — this is intentionally broad and will have some false positives like CSS class names or route strings, which you skip):

```bash
grep -rnE ">[A-Z][a-zA-Z ]{3,}<|placeholder=\"[A-Z]|title=\"[A-Z][a-zA-Z ]+\"" resources/js/components/TwoFactorRecoveryCodes.vue resources/js/components/TwoFactorSetupModal.vue resources/js/components/PasskeyItem.vue resources/js/components/PasskeyRegister.vue
```

For each real hit (a literal user-facing string — skip icon names, class names, attribute names that aren't `title=`/`placeholder=`/text nodes), read the full file to get complete context (surrounding props/computed values, since some strings — like `TwoFactorChallenge.vue`'s `authConfigContent` pattern in Task 8 — are built dynamically rather than sitting directly in the template).

- [ ] **Step 2: Add translation keys and replace, file by file**

For each file with real hits: add `import { useI18n } from 'vue-i18n';` and `const { t } = useI18n();`; add a new nested namespace under the relevant existing `settings.twoFactor.*` / `settings.passkeys.*` block in `en.ts`/`it.ts`/`es.ts` (matching the naming convention established in Task 10 — e.g. `settings.twoFactor.recoveryCodes.title`, `settings.twoFactor.setupModal.qrCodeInstructions`, `settings.passkeys.item.removeButton`, `settings.passkeys.register.button`); replace every hardcoded string found in Step 1 with the matching `t('...')` call, translating the English source text into natural Italian and Spanish consistent with the tone already established elsewhere in `settings.*` (formal-but-friendly, matching e.g. `settings.deleteAccount.confirmDescription`'s register).

- [ ] **Step 3: Re-run the grep to confirm the sweep is complete**

Run the same command from Step 1 again.
Expected: no more real hits (ignore any remaining false positives you already triaged as non-translatable in Step 1 — e.g. a literal `"2026-0001"`-style example value, a CSS class, or a route name).

- [ ] **Step 4: Full verification pass**

Run: `npm run types:check && npm run lint:check && npm run build`
Expected: all PASS.
Run: `php artisan test --compact --filter=SecurityTest`
Expected: PASS.

Manually run `composer run dev`, go to `/settings/security`, and walk through the 2FA setup flow (or at least open the setup modal) and the passkey registration flow with the browser set to Italian/Spanish, confirming no leftover English text where translations were expected.

- [ ] **Step 5: Commit**

```bash
git add resources/js/components resources/js/lang
git commit -m "feat: translate remaining two-factor and passkey management components"
```

---

## Final check

After Task 16, run the complete verification suite one more time to confirm nothing regressed across the whole plan:

```bash
php artisan test --compact
npm run types:check
npm run lint:check
npm run format:check
npm run build
```

All must pass. At this point every page in `resources/js/pages/**`, every shared component with user-facing text, Laravel's validation/auth/password-broker messages, Fortify's built-in auth emails, and every backend flash toast are locale-aware, driven by the `locale` column on `users` and switchable live from Settings → Language.
