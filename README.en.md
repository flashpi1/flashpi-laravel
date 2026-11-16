# Flashpi for Laravel

The official Laravel package for [Flashpi](https://flashpi.com.ec), the electronic invoicing API
for Ecuador's SRI.

[Español](https://github.com/flashpi1/flashpi-laravel/blob/main/README.md)

- The client of the [PHP SDK](https://github.com/flashpi1/flashpi-php), ready to use: inject
  `Flashpi\Flashpi` or use the facade, `Flashpi::invoices()->create([...])`, configured from
  `config/flashpi.php` and your `.env`.
- A ready route for webhooks: it verifies the signature, answers the verification challenge,
  drops repeated deliveries and dispatches one Laravel event per type (`InvoiceAuthorized`,
  `DocumentEmailBounced`, ...), which your listeners can queue.
- Tests with `Http::fake()`: the client sends its requests through Laravel's HTTP client.
- Artisan commands: `flashpi:install`, `flashpi:check` and `flashpi:webhook`.

## Requirements

- Laravel 12.71 or later, or 13.40 or later: every earlier release has a published security
  advisory. If your app is on an older 12.x or 13.x, `composer require` fails with a conflict on
  `illuminate/*`; update Laravel first (`composer update laravel/framework -W`), or add `-W` to the
  install (`composer require flashpi/flashpi-laravel -W`).
- PHP 8.2 or later (Laravel 13 needs PHP 8.3).
- It installs [flashpi/flashpi-php](https://github.com/flashpi1/flashpi-php), the official PHP
  SDK, which makes the API calls. Everything its README explains (the types, the retries, the
  lists) applies here.

## Install

```bash
composer require flashpi/flashpi-laravel
php artisan flashpi:install
```

Laravel registers the package by itself. `flashpi:install` publishes `config/flashpi.php` and
shows the variables your `.env` lacks; it never writes the `.env`.

## Configuration

Create an API key in the [console](https://flashpi.com.ec/app/) and add it to your `.env`:

```dotenv
FLASHPI_API_KEY=fp_test_...
FLASHPI_WEBHOOK_SECRET=whsec_...
```

An `fp_test_` key issues in the SRI's test environment; an `fp_live_` key issues real documents
in production. `php artisan flashpi:webhook` gives you the `whsec_` secret (see
[Webhooks](#webhooks)). Then check the key:

```bash
php artisan flashpi:check
```

| Variable                      | Use                                                                         |
| ----------------------------- | --------------------------------------------------------------------------- |
| `FLASHPI_API_KEY`             | The API key (`fp_test_...` or `fp_live_...`).                               |
| `FLASHPI_WEBHOOK_SECRET`      | The `whsec_` secret of the webhook endpoint.                                |
| `FLASHPI_LANGUAGE`            | Language of the API's messages: `es` or `en` (see below).                   |
| `FLASHPI_TIMEOUT`             | Seconds per attempt of each request (60 by default).                        |
| `FLASHPI_MAX_RETRIES`         | Retries after network errors, 408, 429 and 5xx (2 by default; 0 turns them off). |
| `FLASHPI_BASE_URL`            | Another address of the API (`https://api.flashpi.com.ec` by default).       |
| `FLASHPI_WEBHOOK_ENABLED`     | `false` removes the package's webhook route.                                |
| `FLASHPI_WEBHOOK_PATH`        | The webhook route (`/flashpi/webhook` by default).                          |
| `FLASHPI_WEBHOOK_CACHE_STORE` | The cache store that remembers deliveries (the default one when unset).     |

The package reads `config('flashpi...')` only, never the environment: with
`php artisan config:cache` Laravel stops reading the `.env`, so after changing it run
`php artisan config:cache` again.

**Language.** With `FLASHPI_LANGUAGE`, the API's error messages come in that language. Without
it, they come in Spanish when `config('app.locale')` starts with `es` (`es`, `es_EC`, `es-EC`,
...) and in English otherwise. The locale is read once, when the client is first resolved, and
then kept for the rest of the process (a queue worker keeps it across jobs, even if a job calls
`App::setLocale()`). For a language per user, pass the call's `language` option
(`['language' => 'es']`). The `errorCode`s do not change with the language.

## Getting started

```php
use Flashpi\Laravel\Facades\Flashpi;
use Illuminate\Support\Facades\Log;

$invoice = Flashpi::invoices()->create([
    'issuerId' => '<the demo issuer id or yours>',
    'establishment' => '001',
    'emissionPoint' => '001',
    'customer' => [
        'idType' => 'ruc',
        'id' => '1760013210001',
        'name' => 'PRUEBAS SERVICIO DE RENTAS INTERNAS',
        'address' => 'Av. Amazonas y Villalengua, Quito',
    ],
    'items' => [
        [
            'code' => 'P-001',
            'description' => 'Test service',
            'quantity' => '1',
            'unitPrice' => '1.00',
            'taxes' => [['type' => 'vat', 'rate' => '15']],
        ],
    ],
    'payments' => [['method' => 'cash', 'amount' => '1.15']],
    'options' => ['waitForAuthorization' => true],
]);

Log::info("Invoice {$invoice['status']}", ['accessKey' => $invoice['accessKey']]);
```

Requests and answers are arrays with the API's keys, in camelCase, typed with the SDK's PHPStan
array shapes: your editor and PHPStan know every field. Amounts and quantities are **strings**
(`'1.15'`), never numbers, so no decimals are lost.

With the **demo issuer** you can issue test documents without uploading a certificate: its id
is on the console's Overview (up to 50 documents a day). More in
[Sandbox and demo issuer](https://flashpi.com.ec/docs/en/sandbox/).

## The client: facade or injection

`Flashpi\Flashpi` is bound as a singleton, built from `config/flashpi.php` the first time you
ask for it. Inject it wherever Laravel resolves dependencies (controllers, jobs, listeners,
commands) or use the `Flashpi\Laravel\Facades\Flashpi` facade: the SDK's resources are
properties (`$flashpi->invoices`), and methods on the facade (`Flashpi::invoices()`).

In a job, for example:

```php
use App\Models\Order;
use Flashpi\Flashpi;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class IssueInvoice implements ShouldQueue
{
    use Queueable;

    public function __construct(public Order $order) {}

    public function handle(Flashpi $flashpi): void
    {
        // toFlashpiInvoice() is your code: it builds the order's invoice.
        $invoice = $flashpi->invoices->create($this->order->toFlashpiInvoice(), [
            // The same key on every attempt of the job: a retry never issues two invoices.
            'idempotencyKey' => "order-{$this->order->id}",
        ]);
        $this->order->update(['flashpi_document_id' => $invoice['id']]);
    }
}
```

`IssueInvoice::dispatch($order)` queues it. `Flashpi::environment()` (or
`$flashpi->environment`) tells whether the key is a test (`test`) or production (`live`) key.

## Issuing documents

| Document              | Facade                                          | Injected client                                |
| --------------------- | ----------------------------------------------- | ---------------------------------------------- |
| Invoice               | `Flashpi::invoices()->create($body)`            | `$flashpi->invoices->create($body)`            |
| Credit note           | `Flashpi::creditNotes()->create($body)`         | `$flashpi->creditNotes->create($body)`         |
| Debit note            | `Flashpi::debitNotes()->create($body)`          | `$flashpi->debitNotes->create($body)`          |
| Purchase settlement   | `Flashpi::purchaseSettlements()->create($body)` | `$flashpi->purchaseSettlements->create($body)` |
| Waybill               | `Flashpi::waybills()->create($body)`            | `$flashpi->waybills->create($body)`            |
| Withholding           | `Flashpi::withholdings()->create($body)`        | `$flashpi->withholdings->create($body)`        |

They all follow the invoice's example and return the document. By default the answer comes at
once with the status `processing`, and the SRI's outcome arrives by webhook. With
`'options' => ['waitForAuthorization' => true]` the API waits for the SRI's answer (15 s by
default, up to 30 s with `waitTimeoutSeconds`) and returns `authorized`, `rejected` or `failed`;
if the SRI takes longer, it returns `processing` or `pending_sri`, the document carries on and
its outcome arrives by webhook.

Every method also takes, as its last argument, the options of that request: `idempotencyKey`,
`timeout`, `maxRetries`, `language`, `correlationId` and `extraHeaders`. The rest of the API
(issuers, certificates, webhook endpoints, events) is in `Flashpi::issuers()`,
`Flashpi::issuerOnboardings()`, `Flashpi::webhookEndpoints()` and `Flashpi::events()`, as in
the [SDK's README](https://github.com/flashpi1/flashpi-php/blob/main/README.en.md).

## Documents

```php
use Flashpi\Laravel\Facades\Flashpi;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

$pdf = Flashpi::documents()->pdf($documentId); // the RIDE, as bytes
Storage::put("invoices/{$documentId}.pdf", $pdf);

$xml = Flashpi::documents()->xml($documentId); // $xml['kind']: 'authorized' once the SRI authorized it
Storage::put("invoices/{$documentId}.xml", $xml['xml']);

// foreach walks every page, each requested as you reach it.
foreach (Flashpi::documents()->list(['status' => 'authorized', 'issueDateFrom' => '2026-11-01']) as $document) {
    Log::info($document['accessKey']);
}
```

`Flashpi::documents()->get($id)` reads a document and `Flashpi::documents()->sendEmail($id)`
sends the RIDE and the XML to the buyer's email again.

## Webhooks

Flashpi tells you by webhook when the SRI authorizes or rejects a document, when its RIDE is
generated, when its email is delivered or bounces, and when a certificate changes. The package
receives them for you.

### The route

The package registers `POST /flashpi/webhook` (the `flashpi.webhook` route) outside the `web`
group: no session and no CSRF token, since every delivery carries its signature. With each
delivery it:

1. Verifies the signature (Standard Webhooks) with the body exactly as received, the headers
   and `FLASHPI_WEBHOOK_SECRET`, through the SDK. A delivery without a valid signature gets a
   400.
2. Answers the endpoint's verification challenge (`webhook_endpoint.verification`) by itself.
3. Drops repeated deliveries (see [Repeated deliveries](#repeated-deliveries)).
4. Dispatches `WebhookReceived`, then the event of its type (`InvoiceAuthorized`, ...), and
   answers 204.

If a listener throws, Laravel reports it, the answer is a 500 and Flashpi retries the delivery.
Flashpi waits 10 seconds for the answer: whatever takes longer belongs on the queue.

Change the route with `FLASHPI_WEBHOOK_PATH`, or add middleware to it (`throttle`, for example)
in `webhook.middleware` of `config/flashpi.php`. If you use `php artisan route:cache`, run it
again after changing them.

### Registering the endpoint

With the app deployed at a public https address (`APP_URL`), register its route in Flashpi:

```bash
php artisan flashpi:webhook
```

The command registers `APP_URL` plus the route (or the `--url` URL) for every event of your
key's environment, and shows the line `FLASHPI_WEBHOOK_SECRET=whsec_...` for your `.env`
**only once**. If you lose it, the endpoint's page under Webhooks in the
[console](https://flashpi.com.ec/app/) shows it again, as does
`Flashpi::webhookEndpoints()->getSecret('<endpoint id>')` (run again, the command shows the id).
Flashpi activates the endpoint once it answers its challenge, signed with that secret: deploy
the app with the secret and run `php artisan flashpi:webhook --verify` to send it again. Run
again, the command shows the endpoint's status.

Flashpi must reach the URL from the internet: the command refuses a URL that is not https or
that is local (`localhost`, `*.test`, `10.0.0.1`, ...). In development, use a tunnel (ngrok,
Expose, Cloudflare Tunnel) and pass its URL:
`php artisan flashpi:webhook --url=https://abc123.ngrok.app/flashpi/webhook`.

Options: `--event=invoice.authorized` (repeatable) to receive those events only, and `--thin`
for thin payloads. `--event` and `--thin` apply only when the endpoint is created: if the URL is
already registered with other settings, the command fails without changing it; change it with
`Flashpi::webhookEndpoints()->update()` or in the Flashpi console.

### Events

Listen to the event of the type you need. Laravel discovers the listeners of `app/Listeners` by
the type of `handle()`'s parameter; you can also register them with
`Event::listen(InvoiceAuthorized::class, MarkOrderInvoiced::class)`.

Laravel matches a listener by the event's own class (and its interfaces), not by a parent class:
a listener of `DocumentWebhookEvent`, `CertificateWebhookEvent`, `AccountWebhookEvent` or
`WebhookEvent` never runs. For several types, pass their classes to
`Event::listen([InvoiceAuthorized::class, InvoiceRejected::class], ...)` (or a union type in
`handle()`), or listen to `WebhookReceived` and check `$event->type`.

```php
use App\Models\Order;
use Flashpi\Laravel\Events\InvoiceAuthorized;

final class MarkOrderInvoiced
{
    public function handle(InvoiceAuthorized $event): void
    {
        $invoice = $event->fetch()['data']; // the authorized document
        Order::query()
            ->where('flashpi_document_id', $invoice['id'])
            ->update(['access_key' => $invoice['accessKey']]);
    }
}
```

Every event carries `id`, `type`, `environment` (`test` or `live`), `createdAt`, `data` (the
document, certificate or email sending, as the API described it at that moment), `payload` (the
whole body) and `webhookId`. Every class is in `Flashpi\Laravel\Events`:

| Event type                                                         | Class                                                              | `data`                |
| ------------------------------------------------------------------ | ------------------------------------------------------------------ | --------------------- |
| `invoice.authorized`, `invoice.rejected`                           | `InvoiceAuthorized`, `InvoiceRejected`                             | The document          |
| `credit_note.authorized`, `credit_note.rejected`                   | `CreditNoteAuthorized`, `CreditNoteRejected`                       | The document          |
| `debit_note.authorized`, `debit_note.rejected`                     | `DebitNoteAuthorized`, `DebitNoteRejected`                         | The document          |
| `purchase_settlement.authorized`, `purchase_settlement.rejected`   | `PurchaseSettlementAuthorized`, `PurchaseSettlementRejected`       | The document          |
| `waybill.authorized`, `waybill.rejected`                           | `WaybillAuthorized`, `WaybillRejected`                             | The document          |
| `withholding.authorized`, `withholding.rejected`                   | `WithholdingAuthorized`, `WithholdingRejected`                     | The document          |
| `document.ride.generated`, `document.ride.failed`                  | `DocumentRideGenerated`, `DocumentRideFailed`                      | The document          |
| `document.email.sent`, `.delivered`, `.bounced`, `.complained`, `.failed` | `DocumentEmailSent`, `DocumentEmailDelivered`, `DocumentEmailBounced`, `DocumentEmailComplained`, `DocumentEmailFailed` | The document |
| `certificate.verified`, `.pending`, `.blocked`, `.revoked`, `.reactivated` | `CertificateVerified`, `CertificatePending`, `CertificateBlocked`, `CertificateRevoked`, `CertificateReactivated` | The certificate |
| `account.email_paused`, `account.email_resumed`                    | `AccountEmailPaused`, `AccountEmailResumed`                        | The email sending     |
| Any, including those this version does not know                    | `WebhookReceived`                                                  | What the API sent     |

`WebhookReceived` comes with every delivery, before the event of its type: use it to record them
all, or for new types this version has no class for yet. Delivery is at least once: make your
listeners safe to run again.

### Queued listeners

A listener that implements `ShouldQueue` runs on your queue: the events hold only strings and
arrays, so they serialize whole.

```php
use App\Models\Order;
use Flashpi\Laravel\Events\DocumentEmailBounced;
use Illuminate\Contracts\Queue\ShouldQueue;

final class FlagBouncedEmail implements ShouldQueue
{
    public function handle(DocumentEmailBounced $event): void
    {
        // With a thin payload, fetch() asks the API for the event here, in the worker.
        $document = $event->fetch()['data'];
        Order::query()
            ->where('flashpi_document_id', $document['id'])
            ->update(['email_bounced' => true]);
    }
}
```

The route answers 204 as soon as the listener is queued: with a real queue (not `sync`), if the
job fails later, your queue retries it (`$tries`, `failed_jobs`), not Flashpi.

### Repeated deliveries

A delivery can arrive more than once. The route remembers the `webhook-id` header of each one in
Laravel's cache for 24 hours (with `Cache::add()`, which is atomic), so a repeated delivery does
not dispatch the events again: it gets a 204, or a 409 while the first one is still being
handled. If a listener threw, the delivery is forgotten so that Flashpi's retry handles it.

With several servers, use a cache store they share (database, redis, memcached or dynamodb) in
`FLASHPI_WEBHOOK_CACHE_STORE`. The `null` store is refused (every delivery fails with a 500 that
names the variable); the `array` store forgets the deliveries after each request, so outside
tests it drops nothing; and the `apc` store's `add()` is not atomic. Deliveries you replay from
Flashpi (`replay`) keep their `webhook-id`: within the 24 hours they are dropped too. To have one
handled again (for example after fixing a listener that did the wrong thing without throwing),
forget it before replaying it:
`Cache::store(config('flashpi.webhook.deduplication.store'))->forget(WebhookController::cacheKey($webhookId))`,
with `Flashpi\Laravel\Http\Controllers\WebhookController` and the delivery's `webhook-id`, which is
the event's id (`$event->webhookId`).

### Thin payloads

An endpoint in `thin` mode (registered with `php artisan flashpi:webhook --thin`) sends only the
`id`, `type`, `environment` and date: `$event->data` is `null` and `$event->isThin()` is `true`.
`$event->fetch()` asks the API for the whole event (`GET /v1/events/{id}`) only when you call
it, and once per event object: `WebhookReceived` and the typed event, and each queued listener
(which gets its own copy), read it separately; with a full payload it returns the payload
without any request, so your listeners work the same in both modes. It needs a `FLASHPI_API_KEY`
of the event's environment.

### Your own route

With `FLASHPI_WEBHOOK_ENABLED=false` the package registers no route. To receive the webhooks
on a route of your own, verify each one with `Flashpi::unwrapWebhook($request->getContent(),
$request->headers, config('flashpi.webhook.secret'))`, as in the
[SDK's README](https://github.com/flashpi1/flashpi-php/blob/main/README.en.md#webhooks).

## Testing

The client sends its requests through Laravel's HTTP client, so `Http::fake()`,
`Http::assertSent()` and `Http::preventStrayRequests()` work on the calls to Flashpi:

```php
use App\Models\Order;
use Flashpi\Laravel\Facades\Flashpi;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class IssueInvoiceTest extends TestCase
{
    public function test_it_issues_the_invoice_of_the_order(): void
    {
        Http::preventStrayRequests(); // a request without a fake answer fails: nothing reaches the API
        Http::fake([
            'api.flashpi.com.ec/v1/invoices' => Http::response(['id' => 'doc_1', 'status' => 'processing'], 202),
        ]);

        $order = (new Order())->forceFill(['id' => 42]);
        $invoice = Flashpi::invoices()->create($order->toFlashpiInvoice()); // or your code, like IssueInvoice

        $this->assertSame('processing', $invoice['status']);
        Http::assertSent(fn(Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.flashpi.com.ec/v1/invoices'
            && data_get($request->data(), 'customer.id') === '1760013210001');
    }
}
```

- Set `FLASHPI_API_KEY` to a fake `fp_test_...` key in `phpunit.xml` (or `.env.testing`), so
  the client can be built.
- The SDK retries 5xx answers with growing waits: to test errors without waiting, add
  `<env name="FLASHPI_MAX_RETRIES" value="0"/>` to `phpunit.xml`.
- Certificate uploads (`Flashpi::issuers()->certificates->upload()`,
  `Flashpi::issuerOnboardings()->create()`) send a multipart body that the SDK builds itself, so
  `$request->hasFile()`, `$request->data()` and `$request['password']` see none of its parts:
  assert on `$request->isMultipart()`, `$request->url()` and
  `str_contains($request->body(), ...)`.
- There is no `Flashpi::fake()`, and `Flashpi::shouldReceive()` does not work (the SDK's client
  is a final class): fake the API with `Http::fake()`.

To test your listeners, build the event from a webhook body, with no signatures:

```php
use Flashpi\Laravel\Events\InvoiceAuthorized;
use Illuminate\Support\Facades\Event;

Event::dispatch(new InvoiceAuthorized([
    'id' => '019a1b2c-3d4e-7f60-8a1b-2c3d4e5f6a7b',
    'type' => 'invoice.authorized',
    'environment' => 'test',
    'createdAt' => '2026-11-16T15:20:00.000Z',
    'data' => $document, // a document as the API returns it
]));
```

## Commands

| Command                       | What it does                                                                                 |
| ----------------------------- | -------------------------------------------------------------------------------------------- |
| `php artisan flashpi:install` | Publishes `config/flashpi.php` (`--force` replaces it) and shows the variables the `.env` lacks. |
| `php artisan flashpi:check`   | Tests the key: its environment (test or production), the API's status and whether the API accepts it. |
| `php artisan flashpi:webhook` | Registers the app's webhook endpoint and shows its secret once. Options: `--url`, `--event`, `--thin`, `--verify`. |

## Errors

Errors are the SDK's exceptions, in `Flashpi\Exception`. Every API error is a subclass of
`ApiException` by its HTTP status; decide on `errorCode`, which is stable and in English.

```php
use Flashpi\Exception\ApiException;
use Flashpi\Exception\UnprocessableEntityException;
use Flashpi\Laravel\Facades\Flashpi;
use Illuminate\Support\Facades\Log;

try {
    $invoice = Flashpi::invoices()->create($body);
} catch (UnprocessableEntityException $error) {
    // 'validation_failed', 'total_mismatch', ... with one error per field
    Log::warning('Flashpi refused the invoice', ['errorCode' => $error->errorCode, 'errors' => $error->errors]);
    throw $error;
} catch (ApiException $error) {
    // share the requestId with support
    Log::error('Flashpi error', ['status' => $error->status, 'errorCode' => $error->errorCode, 'requestId' => $error->requestId]);
    throw $error;
}
```

| Class (`Flashpi\Exception`)    | Status | Examples of `errorCode`                                      |
| ------------------------------ | ------ | ------------------------------------------------------------ |
| `BadRequestException`          | 400    | `malformed_json`, `invalid_idempotency_key`                  |
| `AuthenticationException`      | 401    | `authentication_required`, `invalid_api_key`                 |
| `PermissionDeniedException`    | 403    | `key_read_only`, `key_scope_mismatch`, `ip_not_allowed`      |
| `NotFoundException`            | 404    | `not_found`                                                  |
| `ConflictException`            | 409    | `sequential_already_used`, `ride_not_available`              |
| `UnprocessableEntityException` | 422    | `validation_failed`, `total_mismatch`                        |
| `RateLimitException`           | 429    | `demo_issuer_quota_exceeded`, `account_email_limit_reached`  |
| `InternalServerException`      | 5xx    | `internal_error`                                             |
| `ConnectionException`          | —      | no answer (network); `TimeoutException` when time ran out    |

They all extend `FlashpiException`, which you also get when the configuration is missing:
without `FLASHPI_API_KEY`, the first time you ask for the client; with a value that cannot be
converted (such as `FLASHPI_TIMEOUT=fast` or `FLASHPI_LANGUAGE=fr`), naming the variable and
never its value. The SDK checks the ranges, the URL and the key's prefix, naming its own
parameter (`timeout`, `maxRetries`, `baseUrl`), and never shows the key. Retries and idempotency
work as in the
[SDK's README](https://github.com/flashpi1/flashpi-php/blob/main/README.en.md#retries-and-idempotency).

## Security

- Your API key is a secret: keep it in the `.env` of your servers, never in the repository or in
  code that runs on your users' devices. The SDK does not show it in `dump()`, exceptions or
  stack traces.
- Requests to Flashpi go through Laravel's HTTP client. If you record outgoing requests
  (Telescope, Pulse, Nightwatch, a log of your own), hide their `Authorization` header: it holds
  your key.
- The webhook route accepts only deliveries signed with your `FLASHPI_WEBHOOK_SECRET` and recent
  (5 minutes). Without the secret it answers 500 to the requests that carry the webhook headers
  (`webhook-id`, `webhook-timestamp` and `webhook-signature`), so that you notice and Flashpi
  retries; those without them get a 400. If your app only calls the API, remove the route with
  `FLASHPI_WEBHOOK_ENABLED=false`.
- Report security problems privately: see
  [SECURITY.md](https://github.com/flashpi1/flashpi-laravel/blob/main/SECURITY.md).

## More documentation

- [The SDKs' documentation](https://flashpi.com.ec/docs/en/sdks/) and the
  [API's](https://flashpi.com.ec/docs/en/).
- The [PHP SDK's README](https://github.com/flashpi1/flashpi-php/blob/main/README.en.md): the
  types, retries, pagination, issuers and certificates.
- [This package's decisions](https://github.com/flashpi1/flashpi-laravel/blob/main/docs/decisions.md).

## License

[MIT](https://github.com/flashpi1/flashpi-laravel/blob/main/LICENSE) © 2026 SIGNIADIGITAL S.A.
