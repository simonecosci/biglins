# Fatturazione elettronica (Italia SDI + Spagna VERI*FACTU) — Design

Data: 2026-10-02

## Obiettivo

Permettere a ogni company di emettere le proprie fatture attive verso il sistema fiscale del proprio paese tramite un intermediario accreditato, scelto e configurato dalla company stessa. L'integrazione segue il pattern **Driver**: un contratto unico, più implementazioni intercambiabili per provider diversi.

Paesi coperti:

- **Italia** — invio a SDI (Sistema di Interscambio) in formato FatturaPA. Destinatari B2B, B2C ed esteri. **Esclusa la PA** (FPA12, firma digitale, CIG/CUP, split payment, esiti NE/DT).
- **Spagna** — registrazione VERI\*FACTU presso AEAT (registro de facturación con hash concatenato e QR sulla fattura). Obbligatorio dal 2027-01-01 per le società (contribuenti IS) e dal 2027-07-01 per autonomi/persone fisiche (Real Decreto-ley 15/2025).

Fuori scope (ma previsti dal contratto tramite `capabilities()`): ricezione fatture passive, conservazione sostitutiva, PA italiana, fattura elettronica B2B spagnola (Ley Crea y Crece, prevista per ottobre 2027), TicketBAI.

Tutti i nomi di classi, enum, colonne e tool sono in inglese.

## 1. Provider

Primo driver: **B2Brouter**, che copre entrambi i paesi con un'unica API REST:

- Italia: payload JSON → B2Brouter genera FatturaPA e trasmette a SDI, restituisce `IdentificativoSdI` e gli esiti.
- Spagna: payload JSON (o import XML VERI\*FACTU) → B2Brouter calcola l'hash, gestisce la catena, trasmette ad AEAT come *colaborador social* (nessun certificato richiesto alla company) e restituisce il **QR in base64**.
- Ambienti: `sandbox` (validazione payload), `staging` (SDI/AEAT di test), `production`.
- Webhook sugli stati finali, polling come alternativa.

Driver successivi (fuori dalla prima iterazione): `OpenapiProvider` e `InvoicetronicProvider` per l'Italia, che accettano FatturaPA XML generato da noi tramite `FatturaPaBuilder` (§ 5).

Valutati e scartati come primo driver: Invopop (formato GOBL obbligatorio, piano Pro da €500/mese), A-Cube (per l'Italia la documentazione mostra solo input JSON; prezzi non pubblici), Fatture in Cloud (gestionale completo, duplicherebbe le fatture nel loro modello).

## 2. Modello dati

### Country

- Nuova colonna `iso_code` (`char(2)`, unique), popolata per i paesi esistenti. Richiesta sia da FatturaPA (`IdPaese`) sia da VERI\*FACTU.

### Company e Customer — dati fiscali

Colonne comuni, stessi nomi su entrambi i modelli:

- `vat_number` — partita IVA / NIF-IVA. **Rinomina** di `companies.tax_id` e `customers.nif` (migration con `renameColumn`; aggiornare form, request, resource, PDF, tool MCP e test che li usano).
- `tax_code` — codice fiscale / NIF quando diverso dalla partita IVA. Nullable.
- `province` su `Company`; `Customer` usa la colonna `state` già esistente.
- `fiscal_details` — JSON (cast `array`), contenuto specifico per paese, validato da `CountryComplianceRules` (§ 4):
  - **IT, Company**: `tax_regime` (RF01…RF19), `rea_office`, `rea_number`, `share_capital` (opzionale), `liquidation_status`.
  - **IT, Customer**: `recipient_code` (7 caratteri) oppure `pec`. Per i clienti esteri il builder usa `XXXXXXX`, per i privati senza codice `0000000`.
  - **ES, Company/Customer**: `id_type` (tipo identificativo per controparti non spagnole: 02 NIF-IVA, 03 passaporte, 04 documento ufficiale del paese, 06 altro), eventuali chiavi di regime speciale.

### InvoiceRow

- Nuova colonna `vat_exemption_code` (nullable). Obbligatoria quando `vat_rate = 0`. Valori ammessi dipendenti dal paese della company: Natura `N1`–`N7` (con sottocodici, es. `N2.2`, `N3.1`) per l'Italia; causa di esenzione `E1`–`E6` / non soggetta per la Spagna.
- La stessa colonna va aggiunta a `EstimationRow` per non perdere il dato nella conversione preventivo → fattura.

### Invoice — emissione

- Nuova colonna `status` con enum `InvoiceStatus`: `Draft`, `Issued`.
- `number` diventa **nullable** e viene assegnato **all'emissione**, non più nel `creating`. Le bozze non hanno numero, così l'eliminazione di una bozza non crea buchi nella numerazione.
- Nuova colonna `issued_at` (datetime, nullable).
- Migration: tutte le fatture esistenti diventano `Issued` con `issued_at = created_at`, mantenendo il numero.
- `nextNumber()` resta invariato nella logica; è chiamato da `IssueInvoice` (§ 3) dentro una transazione con lock per evitare numeri duplicati.
- Una fattura `Issued` non è modificabile né eliminabile, salvo le eccezioni definite dalle regole del paese (§ 4). Il flag `paid` e il campo `note` restano modificabili.

### EInvoicingIntegration (nuovo, una per company)

| Colonna | Tipo | Note |
|---|---|---|
| `id` | uuid | |
| `company_id` | uuid, unique | una sola integrazione attiva per company |
| `driver` | string | enum `EInvoicingDriver` |
| `environment` | string | enum `EInvoicingEnvironment`: `Sandbox`, `Staging`, `Production` |
| `credentials` | text | cast `encrypted:array` |
| `webhook_secret` | string | generato casualmente, usato quando il provider non firma |
| `is_active` | boolean | |
| timestamps | | |

Ogni company porta **il proprio account** presso il provider e inserisce le proprie credenziali (vedi punto aperto § 9.1).

### InvoiceSubmission (nuovo, storico degli invii)

| Colonna | Tipo | Note |
|---|---|---|
| `id` | uuid | |
| `invoice_id` | uuid | una fattura può avere più invii (reinvio dopo scarto) |
| `e_invoicing_integration_id` | uuid | |
| `driver` | string | copia, per storicità |
| `status` | string | enum `SubmissionStatus` |
| `provider_status` | string, nullable | codice originale (`NS`, `MC`, `registered_with_errors`…) |
| `external_id` | string, nullable | id del documento presso il provider |
| `authority_id` | string, nullable | `IdentificativoSdI` o CSV AEAT |
| `qr_code` | text, nullable | base64, solo Spagna |
| `error_message` | text, nullable | |
| `payload_path` | string, nullable | documento inviato/restituito, su disco privato |
| `submitted_at`, `completed_at` | datetime, nullable | |
| timestamps | | |

Tabella figlia `invoice_submission_events` (`submission_id`, `type`, `provider_event_id` unique, `payload` JSON, `received_at`) per lo storico delle notifiche e l'idempotenza dei webhook.

### SubmissionStatus

| Stato | Significato | Fattura modificabile? |
|---|---|---|
| `Pending` | in coda, non ancora accettata dal provider | no |
| `Failed` | errore prima dell'autorità: validazione, 4xx del provider, credenziali | **sì**, torna `Draft` |
| `Submitted` | accettata dal provider, in attesa dell'autorità | no |
| `Rejected` | scartata (IT: NS; ES: rifiutata) | **IT: sì**, torna `Draft` mantenendo numero e data (reinvio entro 5 giorni); **ES: no**, serve rettifica |
| `Accepted` | ES: registrata (anche "con errori", dettaglio in `provider_status`) | no |
| `Delivered` | IT: RC, consegnata | no |
| `NotDelivered` | IT: MC, emessa ma non consegnata; disponibile nel cassetto fiscale del cliente | no |

`Failed` e `Rejected` sono gli unici stati che consentono un nuovo invio. Al massimo un `InvoiceSubmission` in stato `Pending` o `Submitted` per fattura (verificato in transazione).

## 3. Componenti (namespace `App\EInvoicing`)

```
app/EInvoicing/
  Contracts/EInvoicingProvider.php
  Contracts/CountryComplianceRules.php
  Data/SubmissionResult.php          (readonly DTO)
  Data/ProviderNotification.php      (readonly DTO)
  Enums/EInvoicingDriver.php
  Enums/EInvoicingEnvironment.php
  Enums/SubmissionStatus.php
  Enums/Capability.php
  Providers/B2BrouterProvider.php
  Providers/B2Brouter/InvoicePayloadMapper.php
  Providers/FakeProvider.php
  Compliance/ItalyComplianceRules.php
  Compliance/SpainComplianceRules.php
  Compliance/DefaultComplianceRules.php
  EInvoicingProviderFactory.php
  CountryComplianceResolver.php
```

### Contratto del provider

```php
interface EInvoicingProvider
{
    public function send(Invoice $invoice): SubmissionResult;

    public function fetchStatus(InvoiceSubmission $submission): SubmissionResult;

    /** Verifica la firma e normalizza la notifica; null se non pertinente. */
    public function parseWebhook(Request $request, EInvoicingIntegration $integration): ?ProviderNotification;

    public function testConnection(): bool;

    /** @return list<Capability> */
    public function capabilities(): array;
}
```

Il contratto riceve **`Invoice`**, non un documento già generato: ogni driver decide se mappare su JSON (B2Brouter) o usare `FatturaPaBuilder` (driver XML futuri).

`Capability`: `ItalySdi`, `SpainVerifactu`, più i valori futuri (`ReceivePassiveInvoices`, `LegalArchiving`, `PublicAdministration`).

### Enum e factory

`EInvoicingDriver` (`B2Brouter`, `Fake`) espone per ogni caso:

- `providerClass(): string`
- `credentialRules(): array` — regole di validazione delle credenziali (B2Brouter: `api_key`, `account_id`)
- `credentialFields(): array` — descrizione dei campi per costruire il form lato Vue
- `supportedCountries(): array`

`EInvoicingProviderFactory::forIntegration(EInvoicingIntegration $integration): EInvoicingProvider` istanzia il driver con credenziali e ambiente della company. `Fake` è disponibile solo con `app()->environment(['local', 'testing'])`.

### Regole per paese

```php
interface CountryComplianceRules
{
    /** Regole di validazione di fiscal_details per company e customer. */
    public function companyFiscalRules(): array;
    public function customerFiscalRules(): array;

    /** @return list<string> codici di esenzione IVA ammessi */
    public function vatExemptionCodes(): array;

    /** Verifica che la fattura sia emettibile; restituisce gli errori leggibili. */
    public function validateForIssue(Invoice $invoice): array;

    /** Se l'emissione richiede un invio all'autorità. */
    public function requiresSubmission(): bool;

    /** Se una fattura con l'ultimo invio in questo stato può tornare in bozza. */
    public function allowsRevisionAfter(SubmissionStatus $status): bool;
}
```

`CountryComplianceResolver::forCompany(Company $company)` sceglie l'implementazione in base a `country.iso_code`: `IT` → `ItalyComplianceRules`, `ES` → `SpainComplianceRules`, altrimenti `DefaultComplianceRules` (nessun invio, solo blocco).

Documento correttivo: in entrambi i paesi si usa il tipo `InvoiceType::CreditNote` già esistente, mappato a TD04 per l'Italia e a fattura rettificativa (R1/R4, per differenze) per la Spagna.

### Emissione: `App\Actions\IssueInvoice`

1. Verifica che la fattura sia `Draft` e appartenga alla company corrente.
2. `CountryComplianceRules::validateForIssue()`: dati fiscali di company e customer, `vat_exemption_code` sulle righe a 0%, almeno una riga. Errori restituiti come `ValidationException` (nessuna chiamata al provider).
3. Se `requiresSubmission()` e la company non ha un'integrazione attiva che supporta il paese → errore "configura la fatturazione elettronica".
4. In transazione con lock: assegna `number` (se assente), `status = Issued`, `issued_at = now()`. Se richiesto, crea `InvoiceSubmission` in `Pending`.
5. Dopo il commit, dispatch di `SubmitInvoice`.

Un'unica azione **"Emetti"** per tutti i paesi: in Italia emette e invia a SDI, in Spagna emette e registra su VERI\*FACTU, negli altri paesi emette e blocca.

### Job `App\Jobs\SubmitInvoice`

- `ShouldQueue`, `ShouldBeUnique` sull'id della submission.
- Chiama `provider->send()`, aggiorna la submission con `SubmissionResult`.
- Errori transitori (timeout, 5xx, 429): `tries = 3`, `backoff = [60, 300, 900]`. Esauriti i tentativi → `Failed`.
- Errori definitivi (4xx di validazione, 401/403): `Failed` subito con il messaggio del provider.
- Su `Failed`: la fattura torna `Draft` **mantenendo il numero** (già assegnato, non deve generare buchi).

### Webhook

- Rotta `POST /webhooks/einvoicing/{driver}/{integration}` fuori dai middleware di sessione/CSRF, `WebhookController`.
- Risolve l'integrazione, chiama `parseWebhook()` (verifica firma o `webhook_secret`), salva l'evento in `invoice_submission_events` (idempotente su `provider_event_id`), aggiorna la submission.
- Notifiche sconosciute o non pertinenti: log e risposta 200.

### Polling

Comando `einvoicing:refresh-statuses`, pianificato ogni 30 minuti: chiama `fetchStatus()` per le submission in `Submitted` da più di 1 ora. Azione manuale "Aggiorna stato" sulla fattura che fa lo stesso per una singola submission.

### Modalità desktop (NativePHP)

Il build desktop gira con `QUEUE_CONNECTION=sync` e non è raggiungibile da internet:

- `SubmitInvoice` viene eseguito in modo sincrono durante "Emetti"; l'utente vede subito l'esito dell'invio al provider. I nuovi tentativi automatici non si applicano; in caso di errore transitorio la submission va in `Failed` e si può ritentare.
- I webhook non arrivano: lo stato finale si ottiene con "Aggiorna stato" e con un refresh automatico all'apertura della fattura se la submission è `Submitted` (eseguito al massimo ogni 5 minuti per submission).
- La sezione impostazioni non mostra l'URL del webhook quando l'app gira sotto NativePHP.

## 4. Regole per paese — dettaglio

### Italia

- Destinatario: `recipient_code` o `pec` obbligatori per clienti italiani con partita IVA; per i privati `tax_code` obbligatorio e codice `0000000`; per i clienti esteri codice `XXXXXXX`, `vat_number` con prefisso paese, CAP `00000` se assente.
- Company: `vat_number`, `tax_regime`, indirizzo completo con `province`.
- Righe a 0%: Natura obbligatoria (operazioni con l'estero tipicamente `N2.1`, `N3.1`–`N3.6`, `N7`).
- `allowsRevisionAfter`: `Failed`, `Rejected`.

### Spagna

- Company: `vat_number` (NIF) obbligatorio.
- Customer: NIF per controparti spagnole; per controparti estere `vat_number` + `fiscal_details.id_type`. Fatture semplificate (F2, senza destinatario identificato) **fuori scope** della prima iterazione.
- Righe a 0%: causa di esenzione obbligatoria.
- `allowsRevisionAfter`: solo `Failed` (nessun dato è arrivato ad AEAT). Una fattura `Rejected` o `Accepted` si corregge con una nota di credito/rettificativa.
- Il QR (`InvoiceSubmission.qr_code`) e la dicitura "VERI\*FACTU" vengono stampati sul PDF della fattura quando presenti.

## 5. FatturaPaBuilder (iterazione successiva)

Non è un prerequisito del primo driver. Viene sviluppato insieme al secondo driver italiano (Openapi o Invoicetronic):

- `App\EInvoicing\Formats\FatturaPaBuilder::build(Invoice): string` — XML FPR12.
- `FatturaPaValidator` — validazione contro lo XSD ufficiale incluso nel repository.
- Abilita anche "Scarica XML FatturaPA" sulla fattura indipendentemente dal driver.

Per la Spagna non si implementa un builder VERI\*FACTU: hash, catena e firma restano responsabilità del provider.

## 6. Interfaccia (Inertia + Vue)

- **Impostazioni company → tab "Electronic invoicing"**: selezione driver (dai casi di `EInvoicingDriver` che supportano il paese della company), campi credenziali generati da `credentialFields()`, ambiente, attivo/disattivo, pulsante "Test connection", URL del webhook con copia (solo build web).
  - Le credenziali **non vengono mai inviate al frontend**: il form mostra solo se sono impostate. Un campo lasciato vuoto in modifica significa "non cambiare".
- **Form company e customer**: campi `vat_number`, `tax_code`, `province`/`state` e sezione dati fiscali che cambia in base al paese selezionato.
- **Righe fattura e preventivo**: select `vat_exemption_code` visibile quando l'aliquota è 0, con i valori del paese della company.
- **Fattura**:
  - badge `Draft`/`Issued` e badge dello stato dell'ultima submission;
  - pulsante "Issue" sulle bozze, con errori di validazione mostrati inline;
  - pannello cronologia submission/eventi, "Refresh status", "Retry" quando l'ultima submission è `Failed`;
  - modifica ed eliminazione disabilitate per le fatture bloccate.
- **Lista fatture**: filtro per stato e colonna stato submission.
- Notifiche in-app (flash/toast) su `Rejected`, `NotDelivered`, `Failed`. Per `NotDelivered` l'interfaccia suggerisce l'invio email della copia di cortesia, già esistente.

Tutte le stringhe passano dal sistema i18n esistente.

## 7. MCP

- Nuovo tool `issue_invoice` (`company_id`, `invoice_id`) che chiama `IssueInvoice`.
- `create_invoice` crea fatture in `Draft`.
- `list_invoices` espone `status`, `issued_at` e lo stato dell'ultima submission.
- I tool esistenti che modificano fatture rispettano il blocco.

## 8. Test (Pest)

- **Migration/dati**: rinomina `tax_id`/`nif` → `vat_number` senza perdita di dati; fatture esistenti → `Issued`.
- **Numerazione**: bozze senza numero; numero assegnato all'emissione; nessun duplicato in emissioni concorrenti; nessun buco eliminando bozze.
- **Regole per paese**: dataset per IT (B2B, B2C, estero) ed ES (nazionale, estero) con dati mancanti → errori attesi; codici di esenzione ammessi per paese.
- **IssueInvoice**: con `FakeProvider` → transizioni `Pending` → `Submitted` → stati finali; nessuna integrazione → errore; paese senza regole → solo blocco.
- **SubmitInvoice**: errore transitorio con nuovo tentativo, errore definitivo → `Failed` e ritorno in bozza con numero mantenuto; unicità.
- **B2BrouterProvider**: `Http::fake()` con risposte registrate da sandbox/staging per invio IT, invio ES (con QR), 4xx, 5xx, 401, `fetchStatus`, parsing webhook. Test del `InvoicePayloadMapper` su fatture IT/ES/estero e note di credito.
- **Webhook**: firma valida/non valida, evento duplicato ignorato, integrazione inesistente → 404.
- **Blocchi**: update/destroy di fatture `Issued` rifiutati da controller e tool MCP; consentiti dopo `Failed` (IT ed ES) e `Rejected` (solo IT).
- **Impostazioni**: salvataggio credenziali cifrate, credenziali mai presenti nelle props Inertia, campo vuoto non sovrascrive.
- **Comando** `einvoicing:refresh-statuses`: aggiorna solo le submission in attesa oltre la soglia.

## 9. Punti aperti (da chiudere fuori dal codice)

1. **B2Brouter — commerciale**: prezzi; modello account per una piattaforma SaaS (ogni company con il proprio account, come previsto in § 2, oppure sotto-account creati da Biglins); disponibilità dell'import XML FatturaPA. Se il modello prevede sotto-account gestiti da Biglins, `EInvoicingIntegration` resta valido ma le credenziali diventano in parte globali (config) e in parte per company.
2. **Spagna — consulenza fiscale**: se Biglins, in quanto software di fatturazione, deve presentare la *declaración responsable* anche usando B2Brouter come *colaborador social*.
3. **Spagna — B2B**: confermare la data di obbligo della fattura elettronica B2B (Ley Crea y Crece, ottobre 2027 secondo le fonti consultate); andrà in uno spec separato.

## 10. Ordine di implementazione

1. `Country.iso_code`, rinomina e nuovi campi fiscali, `fiscal_details`, `vat_exemption_code` (con form).
2. `InvoiceStatus` `Draft`/`Issued`, numerazione all'emissione, blocchi, `IssueInvoice` con `DefaultComplianceRules`.
3. Contratto, enum, factory, `EInvoicingIntegration`, `InvoiceSubmission`, `SubmitInvoice`, webhook, polling, `FakeProvider`.
4. `ItalyComplianceRules`, `SpainComplianceRules`, `B2BrouterProvider` (priorità Spagna per la scadenza del 2027-01-01).
5. Interfaccia (impostazioni, fattura, lista), QR sul PDF, tool MCP.
6. Iterazione successiva: `FatturaPaBuilder`, validazione XSD, secondo driver italiano.
