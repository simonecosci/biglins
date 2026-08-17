# Server MCP — Design

Data: 2026-08-17

Issue: [#24](https://github.com/simonecosci/biglins/issues/24), sub-issue: [#34](https://github.com/simonecosci/biglins/issues/34) [#35](https://github.com/simonecosci/biglins/issues/35) [#36](https://github.com/simonecosci/biglins/issues/36) [#37](https://github.com/simonecosci/biglins/issues/37) [#38](https://github.com/simonecosci/biglins/issues/38)

## Obiettivo

Esporre un server MCP (protocollo, tramite il package ufficiale `laravel/mcp`, non un server Node.js separato) che permetta ad agenti AI di operare su Biglins: creare clienti, creare preventivi, creare fatture, inviarli via email. Biglins è un'app desktop NativePHP (singolo utente, singola macchina, sqlite locale), quindi il server gira come processo locale invocato dall'agente, senza esposizione di rete né autenticazione.

## 1. Trasporto e registrazione

- `laravel/mcp` è già presente in `composer.lock` come dipendenza di sviluppo (transitiva, tramite `laravel/boost`). Va promosso a dipendenza reale in `composer.json` (`require`, non `require-dev`), perché il server deve funzionare anche in produzione (build distribuita dell'app desktop).
- Nuovo `App\Mcp\Servers\BiglinsServer` (namespace di default del package: `App\Mcp\Servers`), registrato in `routes/ai.php` con `Mcp::local('biglins', BiglinsServer::class)`.
- L'agente si collega spawnando `php artisan mcp:start biglins` come sottoprocesso (stdio). Nessuna rotta HTTP, nessun token: chi può eseguire l'artisan locale ha già accesso pieno al DB sqlite dell'app.

## 2. Scoping per company fuori dal contesto HTTP

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

## 3. Tool

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

## 4. Testing (Pest, feature)

- Un test per tool: creazione con dati validi verifica lo stato DB; dati invalidi verificano `Response::error`; risorse (`customer_id`, `invoice_id`, ...) di un'altra company vengono rifiutate.
- `send_invoice_email`/`send_estimation_email`: `Mail::fake()` + assert `Mail::assertSent(...)`, verifica aggiornamento `sent_at`/`sent_to`.
- `CurrentCompanyTest` (esteso): `runningAs()` sovrascrive `resolve()` per la durata della closure e ripristina il valore precedente al termine, anche in caso di eccezione.

## Note

Nessuna migrazione necessaria: tutti i tool operano sulle tabelle esistenti. Nessuna gestione di autenticazione/autorizzazione oltre allo scoping per company: coerentemente con il resto dell'app (vedi `docs/superpowers/specs/2026-08-13-company-context-design.md`), non esiste un confine di tenancy per utente, quindi il server MCP locale ha accesso a tutte le company presenti nel DB — lo scoping serve a evitare scritture cross-company accidentali, non a limitare la visibilità.
