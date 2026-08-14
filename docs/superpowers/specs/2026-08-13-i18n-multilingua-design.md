# Multilingua (EN / IT / ES) — Design

Data: 2026-08-13

## Obiettivo

Rendere l'intera applicazione multilingua con tre lingue supportate: inglese (default), italiano, spagnolo. La lingua è una preferenza legata all'account utente, modificabile in Settings, e copre sia il frontend (pagine Vue) sia il backend (messaggi di validazione, email di autenticazione inviate da Fortify).

Nota: esiste già un campo `language` (it/en/es) sulle righe di fattura (`invoices.language`), usato per la lingua del **documento PDF** generato. È un concetto indipendente dalla lingua dell'interfaccia utente e non viene toccato da questo lavoro.

## 1. Livello dati

**Migration additiva su `users`**: nuova colonna `locale` — string, default `'en'`, non nullable.

`App\Models\User`: `locale` aggiunto a `#[Fillable]`, PHPDoc `@property string $locale`.

## 2. Backend

### Risoluzione della lingua per request

Nuovo middleware `App\Http\Middleware\SetLocale`, registrato nel gruppo `web` **prima** di `HandleInertiaRequests`. Risolve la lingua con questa priorità:

1. `locale` dell'utente autenticato, se presente;
2. negoziazione dell'header `Accept-Language` del browser (solo tra `en`/`it`/`es`, altrimenti si scarta), per gli utenti non autenticati;
3. default `en` (`config('app.locale')`).

Chiama `App::setLocale($locale)`.

### Prop condivisa Inertia

`HandleInertiaRequests::share()` espone:
- `locale`: la lingua attiva per la request corrente;
- `locales`: `['en', 'it', 'es']`, lista delle lingue disponibili (usata dal selettore in Settings).

### File di lingua Laravel

- `php artisan lang:publish` per pubblicare i file base (`lang/en/*`), non ancora presenti nel progetto (Laravel 13 non li include di default).
- Traduzione in `lang/it` e `lang/es` di `validation.php`, `auth.php`, `passwords.php`.
- Le email di Fortify (verifica email, reset password) usano le stringhe standard di Laravel (`__()`), quindi seguono automaticamente `App::setLocale()` impostato dal middleware — nessun lavoro aggiuntivo lato Notification.

## 3. Frontend (Vue + Inertia)

### Libreria

Nuova dipendenza: `vue-i18n` (v10, compatibile Vue 3).

### Struttura traduzioni

`resources/js/lang/{en,it,es}.ts`, oggetti annidati organizzati per namespace:
- `common.*` — stringhe condivise tra pagine (Save, Cancel, Edit, Delete, Actions, ecc.), per evitare duplicazione;
- un namespace per dominio/pagina, es. `invoices.create.*`, `invoices.index.*`, `settings.language.*`, `auth.login.*`.

`resources/js/app.ts`: inizializza `vue-i18n` (modalità Composition API, `legacy: false`) con `locale` iniziale preso dalla prop Inertia condivisa `locale`.

### Sostituzione stringhe

Tutte le stringhe hardcoded in UI (titoli, label, placeholder, testo dei bottoni, breadcrumb, messaggi) nelle 27 pagine sotto `resources/js/pages/**` e nei componenti condivisi (`Heading`, `AppSidebar`, `AppearanceTabs`, ecc.) vengono sostituite con `$t('namespace.key')`. Le traduzioni IT/ES vengono scritte contestualmente (nessun servizio di traduzione esterno).

## 4. Settings: tab Lingua

- Nuova pagina `resources/js/pages/settings/Language.vue`, stesso pattern di `Appearance.vue` (usa `Heading`, layout settings).
- Nuova voce "Language" nella tab-bar del layout settings, accanto a Profile / Security / Appearance.
- Nuovo `App\Http\Controllers\Settings\LanguageController` con singola azione `update`: valida `locale` come `required|in:en,it,es` e lo salva sull'utente autenticato.
- Rotta dedicata via Wayfinder, coerente con `ProfileController`/`SecurityController`.
- Al salvataggio riuscito, il frontend imposta immediatamente `locale.value` di vue-i18n (aggiornamento UI istantaneo, senza reload); le richieste successive al server usano già la nuova lingua per validazione/email grazie a `SetLocale`.

## 5. Testing

- Aggiornamento `UserFactory`/test esistenti se necessario per il default `locale`.
- Nuovo test Pest (feature) `LanguageTest`: utente autenticato aggiorna la propria `locale`, verifica persistenza su DB e presenza del valore aggiornato nella prop condivisa `locale` sulla request successiva; validazione rifiuta valori fuori da `en/it/es`; rotta protetta da autenticazione.
- Spot-check: un test verifica che un messaggio di validazione (es. campo required) torni tradotto quando l'utente ha `locale = it`.

## Note

- Lavoro ampio in termini di file toccati (~60: pagine, componenti, file di lingua) ma concettualmente un solo sotto-sistema coerente: non viene decomposto in spec separate.
- Nessuna migrazione dati necessaria: `locale` ha default `'en'`, gli utenti esistenti restano in inglese finché non cambiano preferenza.
