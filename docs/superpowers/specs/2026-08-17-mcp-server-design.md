# Server MCP — Design

Data: 2026-08-17

Issue: [#24](https://github.com/simonecosci/biglins/issues/24), sub-issue: [#34](https://github.com/simonecosci/biglins/issues/34) [#39](https://github.com/simonecosci/biglins/issues/39) [#35](https://github.com/simonecosci/biglins/issues/35) [#36](https://github.com/simonecosci/biglins/issues/36) [#37](https://github.com/simonecosci/biglins/issues/37) [#38](https://github.com/simonecosci/biglins/issues/38)

## Obiettivo

Esporre un server MCP (protocollo, tramite il package ufficiale `laravel/mcp`, non un server Node.js separato) che permetta ad agenti AI di operare su Biglins: creare clienti, creare preventivi, creare fatture, inviarli via email. Biglins si distribuisce in due modalità (vedi `docs/superpowers/specs/2026-08-15-nativephp-desktop-design.md` e `docs/superpowers/specs/2026-08-15-https-support-design.md`): app desktop NativePHP (singolo utente, singola macchina, sqlite locale) e immagine Docker (può essere esposta su internet, con HTTPS, autenticazione Fortify multi-utente). Il server MCP deve funzionare in entrambe, con requisiti di trasporto/sicurezza diversi.

## 1. Trasporto e registrazione (doppio transport)

`App\Mcp\Servers\BiglinsServer` e i tool sono condivisi tra le due modalità; cambia solo come ci si collega:

- **Desktop (NativePHP)**: trasporto locale/stdio. `Mcp::local('biglins', BiglinsServer::class)` in `routes/ai.php`. L'agente si collega spawnando `php artisan mcp:start biglins` come sottoprocesso sulla stessa macchina. Nessuna rotta HTTP, nessun token: chi può eseguire l'artisan locale ha già accesso pieno al DB sqlite dell'app.
- **Docker/web**: trasporto HTTP. `Mcp::web('/mcp/biglins', BiglinsServer::class)->middleware('auth:sanctum')` nello stesso file. Essendo potenzialmente esposto su internet, richiede autenticazione (§ 2).

`laravel/mcp` è già presente in `composer.lock` come dipendenza di sviluppo (transitiva, tramite `laravel/boost`). Va promosso a dipendenza reale in `composer.json` (`require`, non `require-dev`), perché il server deve funzionare anche in produzione (sia build desktop che immagine Docker).

## 2. Autenticazione del trasporto web — Sanctum

Il supporto OAuth nativo di `laravel/mcp` (`Mcp::oauthRoutes()`) richiede `laravel/passport` (server OAuth2 completo): dipendenza pesante, pensata per flussi tipo "Connect your account" da piattaforme hosted con consent screen. Sproporzionata per questa app. Si usa invece **Laravel Sanctum**, già lo standard Laravel per token API personali:

- Nuova dipendenza `laravel/sanctum` (`require`).
- Migration standard di Sanctum (`personal_access_tokens`), trait `HasApiTokens` su `App\Models\User`.
- Rotta web protetta con `->middleware('auth:sanctum')`: l'agente manda `Authorization: Bearer <token>`.
- Nessun impatto sullo scoping per company (§ 3): anche in modalità web, come nel resto dell'app, non esiste un confine di tenancy per utente — un token valido di un qualsiasi utente autenticato dà accesso a tutte le company nel DB, esattamente come già succede oggi per un utente loggato via browser.

### Gestione token — Settings > API Tokens

Nuova pagina Inertia `settings/ApiTokens.vue`, stesso pattern della gestione passkey già esistente in `settings/Security.vue`:

- `App\Http\Controllers\Settings\ApiTokenController@index`: lista i token dell'utente corrente (`name`, `created_at`, `last_used_at`), nessun campo segreto esposto dopo la creazione.
- `@store`: crea un token (`$request->user()->createToken($name)`), la pagina mostra il valore in chiaro **una sola volta** (pattern standard Sanctum), poi solo l'hash è recuperabile.
- `@destroy`: revoca (elimina) un token.
- Nuove rotte in `routes/settings.php`, gruppo `auth`/`verified`, stesso pattern delle rotte password/passkey esistenti.
- Voce di navigazione nel menu Settings esistente, accanto a Security/Language/Appearance.

## 3. Scoping per company fuori dal contesto HTTP

`CurrentCompany::resolve()` legge `session('current_company_id')`, ma il processo MCP non ha una sessione HTTP. Ogni tool riceve quindi un parametro esplicito `company_id`, validato con `Rule::exists('companies', 'id')`.

Per riusare senza duplicazioni le regole di validazione e lo scoping già scritti nei controller/FormRequest (che chiamano tutti `CurrentCompany::resolve()`), `App\Support\CurrentCompany` guadagna un override temporaneo:

```php
class CurrentCompany
{
    private static ?Company $override = null;

    public static function runningAs(Company $company, Closure $callback): mixed
    {
        $previous = self::$override;
        self::$override = $company;

        try {
            return $callback();
        } finally {
            self::$override = $previous;
        }
    }

    public static function resolve(): ?Company
    {
        if (self::$override !== null) {
            return self::$override;
        }

        // ... logica esistente basata su sessione ...
    }
}
```

Ogni tool di scrittura: risolve la `Company` dal `company_id` in input, poi esegue la propria logica dentro `CurrentCompany::runningAs($company, fn () => ...)`. Dentro la closure, le regole di validazione business (es. `customer_id` deve appartenere alla company) si ottengono istanziando la `FormRequest` esistente e chiamandone `rules()`:

```php
Validator::make($input, (new StoreInvoiceRequest())->rules())->validate();
```

Questo evita di riscrivere le regole di unicità/appartenenza già espresse nei FormRequest. Gli errori di validazione diventano `Response::error($validator->errors()->first())` nel tool (il protocollo MCP non ha un formato 422 strutturato come le request HTTP).

Il `runningAs` è annidato per singola chiamata di tool: il server MCP locale processa le richieste in sequenza all'interno dello stesso processo PHP, quindi non serve alcuna sincronizzazione — l'override è sempre ripristinato a `null` (o al valore precedente) subito dopo la `finally`.

## 4. Tool

Namespace `App\Mcp\Tools`. Ogni tool estende `Laravel\Mcp\Server\Tool`, con `schema()` per la struttura (tipi/required via `Illuminate\JsonSchema`) e `handle()` per la logica.

**Clienti**
- `list_customers(company_id, search?)` — mirror di `CustomerController@index`, senza paginazione (l'agente non naviga pagine), fino a un limite ragionevole (es. 50 risultati) con nota se troncato.
- `create_customer(company_id, name, address?, zip?, city?, country_id?, state?, email?, web?, phone?, nif?)` — valida con `StoreCustomerRequest::rules()`, `Customer::create()`.

**Preventivi**
- `list_estimations(company_id)` — mirror di `EstimationController@index`.
- `create_estimation(company_id, customer_id, estimation_date, expiration_date, language, body?, rows[])` — valida con `StoreEstimationRequest::rules()`, stessa logica di creazione (`Estimation::create()` + `rows()->createMany()`) di `EstimationController@store`.

**Fatture**
- `list_invoices(company_id)` — mirror di `InvoiceController@index`.
- `create_invoice(company_id, type?, invoice_date, customer_id, note?, language, rows[])` — valida con `StoreInvoiceRequest::rules()`, stessa logica di `InvoiceController@store` (transazione, `normalizeRowPrice` per le note di credito).

**Invio email**
- `send_invoice_email(company_id, invoice_id, to, subject, message)` — valida con `SendInvoiceRequest::rules()`, stessa logica di `InvoiceController@send` (verifica che la fattura appartenga alla company, invia `InvoiceMail`, aggiorna `sent_at`/`sent_to`).
- `send_estimation_email(company_id, estimation_id, to, subject, message)` — equivalente per preventivi (`EstimationMail`, `EstimationController@send`).

Ogni tool che riceve un id di risorsa (`invoice_id`, `estimation_id`, `customer_id` nelle rows) verifica l'appartenenza alla company risolta prima di procedere, con lo stesso pattern 403-style già usato da `ScopesToCurrentCompany` (qui: `Response::error(...)` invece di `abort(403)`).

## 5. Testing (Pest, feature)

- Un test per tool: creazione con dati validi verifica lo stato DB; dati invalidi verificano `Response::error`; risorse (`customer_id`, `invoice_id`, ...) di un'altra company vengono rifiutate. Ogni test esercita i tool direttamente (non serve avviare un vero processo `mcp:start`), seguendo l'helper di test fornito da `laravel/mcp` (es. `Server::actingAs(...)` / chiamata diretta al tool).
- `send_invoice_email`/`send_estimation_email`: `Mail::fake()` + assert `Mail::assertSent(...)`, verifica aggiornamento `sent_at`/`sent_to`.
- `CurrentCompanyTest` (esteso): `runningAs()` sovrascrive `resolve()` per la durata della closure e ripristina il valore precedente al termine, anche in caso di eccezione.
- `ApiTokenTest` (nuovo, feature HTTP standard): creazione/elenco/revoca token da Settings; il token in chiaro è presente solo nella risposta di creazione.
- Un test HTTP che chiama la rotta `Mcp::web()` senza token → 401; con token Sanctum valido → risposta MCP valida (smoke test del wiring auth, non re-testa la logica dei singoli tool già coperta sopra).

## Note

Migrazione necessaria: quella standard di Sanctum (`personal_access_tokens`), pubblicata da `php artisan install:api` o `vendor:publish --tag=sanctum-migrations`. Nessun'altra migrazione: i tool MCP operano sulle tabelle esistenti. Lo scoping per company (§ 3) evita scritture cross-company *accidentali*, non limita la visibilità: coerentemente con il resto dell'app (vedi `docs/superpowers/specs/2026-08-13-company-context-design.md`), non esiste un confine di tenancy per utente — sia un processo locale (desktop) sia un token Sanctum valido (web) danno accesso a tutte le company presenti nel DB.
