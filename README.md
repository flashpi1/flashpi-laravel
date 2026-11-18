# Flashpi para Laravel

Paquete oficial de Laravel para [Flashpi](https://flashpi.com.ec), la API de facturación
electrónica del SRI de Ecuador.

[English](https://github.com/flashpi1/flashpi-laravel/blob/main/README.en.md)

- El cliente del [SDK de PHP](https://github.com/flashpi1/flashpi-php) listo para usar: inyecta
  `Flashpi\Flashpi` o usa la facade, `Flashpi::invoices()->create([...])`, configurado desde
  `config/flashpi.php` y tu `.env`.
- Una ruta lista para los webhooks: verifica la firma, responde el desafío de verificación,
  descarta las entregas repetidas y lanza un evento de Laravel por cada tipo
  (`InvoiceAuthorized`, `DocumentEmailBounced`, ...), que tus listeners pueden encolar.
- Pruebas con `Http::fake()`: el cliente envía sus peticiones por el cliente HTTP de Laravel.
- Comandos de Artisan: `flashpi:install`, `flashpi:check` y `flashpi:webhook`.

## Requisitos

- Laravel 12.71 o superior, o 13.40 o superior: todas las versiones anteriores tienen avisos de
  seguridad publicados. Si tu app usa una 12.x o 13.x más antigua, `composer require` falla con
  un conflicto en `illuminate/*`; actualiza Laravel primero
  (`composer update laravel/framework -W`) o agrega `-W` a la instalación
  (`composer require flashpi/flashpi-laravel -W`).
- PHP 8.2 o superior (Laravel 13 necesita PHP 8.3).
- El paquete instala [flashpi/flashpi-php](https://github.com/flashpi1/flashpi-php), el SDK
  oficial de PHP, que hace las llamadas a la API. Todo lo que explica su README (los tipos, los
  reintentos, las listas) vale aquí. Composer elige su versión según tu versión de PHP: la 0.3 en
  PHP 8.3 o superior, y la 0.1 en PHP 8.2. Desde su 0.3, el último pago de un comprobante puede ir
  sin `amount`, y Flashpi le pone lo que falta del total
  ([reglas](https://flashpi.com.ec/docs/documents/#totales)).

## Instalación

```bash
composer require flashpi/flashpi-laravel
php artisan flashpi:install
```

Laravel registra el paquete solo. `flashpi:install` publica `config/flashpi.php` y te muestra
las variables que faltan en tu `.env`; nunca escribe el `.env`.

## Configuración

Crea una clave de API en la [consola](https://flashpi.com.ec/app/) y agrégala a tu `.env`:

```dotenv
FLASHPI_API_KEY=fp_test_...
FLASHPI_WEBHOOK_SECRET=whsec_...
```

Una clave `fp_test_` emite en el ambiente de pruebas del SRI; una `fp_live_` emite comprobantes
reales en producción. El secreto `whsec_` te lo da `php artisan flashpi:webhook` (ver
[Webhooks](#webhooks)). Luego comprueba la clave:

```bash
php artisan flashpi:check
```

| Variable                      | Uso                                                                        |
| ----------------------------- | -------------------------------------------------------------------------- |
| `FLASHPI_API_KEY`             | La clave de API (`fp_test_...` o `fp_live_...`).                           |
| `FLASHPI_WEBHOOK_SECRET`      | El secreto `whsec_` del endpoint de webhooks.                              |
| `FLASHPI_LANGUAGE`            | Idioma de los mensajes de la API: `es` o `en` (ver abajo).                 |
| `FLASHPI_TIMEOUT`             | Segundos por intento de cada petición (60 por defecto).                    |
| `FLASHPI_MAX_RETRIES`         | Reintentos ante errores de red, 408, 429 y 5xx (2 por defecto; 0 los apaga). |
| `FLASHPI_BASE_URL`            | Otra dirección de la API (por defecto `https://api.flashpi.com.ec`).        |
| `FLASHPI_WEBHOOK_ENABLED`     | `false` quita la ruta de webhooks del paquete.                             |
| `FLASHPI_WEBHOOK_PATH`        | La ruta de webhooks (`/flashpi/webhook` por defecto).                      |
| `FLASHPI_WEBHOOK_CACHE_STORE` | El store de caché que recuerda las entregas (el de por defecto si falta).  |

El paquete solo lee `config('flashpi...')`, nunca el entorno: con `php artisan config:cache`
Laravel deja de leer el `.env`, así que después de cambiarlo vuelve a ejecutar
`php artisan config:cache`.

**Idioma.** Con `FLASHPI_LANGUAGE`, los mensajes de error de la API llegan en ese idioma. Sin
ella, cada llamada sigue el locale de la app en ese momento: en español si el idioma de
`config('app.locale')` es `es` (`es`, `es_EC`, `es-EC`, `es_EC.UTF-8`, ...) y en inglés en otro
caso. Así, un `App::setLocale()` en un request, en un job o bajo Octane cambia el idioma de las
llamadas que vienen después. La opción `language` de una llamada (`['language' => 'es']`) gana
sobre ambas. Los `errorCode` no cambian con el idioma.

## Primeros pasos

```php
use Flashpi\Laravel\Facades\Flashpi;
use Illuminate\Support\Facades\Log;

$invoice = Flashpi::invoices()->create([
    'issuerId' => '<id del emisor demo o del tuyo>',
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
            'description' => 'Servicio de prueba',
            'quantity' => '1',
            'unitPrice' => '1.00',
            'taxes' => [['type' => 'vat', 'rate' => '15']],
        ],
    ],
    'payments' => [['method' => 'cash', 'amount' => '1.15']],
    'options' => ['waitForAuthorization' => true],
]);

Log::info("Factura {$invoice['status']}", ['accessKey' => $invoice['accessKey']]);
```

Las peticiones y las respuestas son arrays con las claves de la API, en camelCase, tipados con
las formas de arrays de PHPStan del SDK: tu editor y PHPStan conocen cada campo. Los montos y
cantidades son **textos** (`'1.15'`), nunca números, para no perder decimales.

Con el **emisor demo** puedes emitir en pruebas sin subir un certificado: su id está en el
Resumen de la consola (hasta 50 comprobantes al día). Más en
[Sandbox y emisor demo](https://flashpi.com.ec/docs/sandbox/).

## El cliente: facade o inyección

`Flashpi\Flashpi` está registrado como singleton, creado desde `config/flashpi.php` la primera
vez que lo pides. Inyéctalo donde Laravel resuelve dependencias (controladores, jobs, listeners,
comandos) o usa la facade `Flashpi\Laravel\Facades\Flashpi`: los recursos del SDK son
propiedades (`$flashpi->invoices`) y en la facade son métodos (`Flashpi::invoices()`).

En un job, por ejemplo:

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
        // toFlashpiInvoice() es tu código: arma la factura del pedido.
        $invoice = $flashpi->invoices->create($this->order->toFlashpiInvoice(), [
            // La misma clave en cada intento del job: un reintento nunca emite dos facturas.
            'idempotencyKey' => "pedido-{$this->order->id}",
        ]);
        $this->order->update(['flashpi_document_id' => $invoice['id']]);
    }
}
```

`IssueInvoice::dispatch($order)` lo encola. `Flashpi::environment()` (o
`$flashpi->environment`) dice si la clave es de pruebas (`test`) o de producción (`live`).

## Emitir comprobantes

| Comprobante           | Facade                                        | Cliente inyectado                              |
| --------------------- | --------------------------------------------- | ---------------------------------------------- |
| Factura               | `Flashpi::invoices()->create($body)`            | `$flashpi->invoices->create($body)`            |
| Nota de crédito       | `Flashpi::creditNotes()->create($body)`         | `$flashpi->creditNotes->create($body)`         |
| Nota de débito        | `Flashpi::debitNotes()->create($body)`          | `$flashpi->debitNotes->create($body)`          |
| Liquidación de compra | `Flashpi::purchaseSettlements()->create($body)` | `$flashpi->purchaseSettlements->create($body)` |
| Guía de remisión      | `Flashpi::waybills()->create($body)`            | `$flashpi->waybills->create($body)`            |
| Retención             | `Flashpi::withholdings()->create($body)`        | `$flashpi->withholdings->create($body)`        |

Todos siguen el ejemplo de la factura y devuelven el documento. Por defecto la respuesta llega
enseguida con el estado `processing` y el resultado del SRI llega por webhook. Con
`'options' => ['waitForAuthorization' => true]` la API espera la respuesta del SRI (15 s por
defecto, hasta 30 s con `waitTimeoutSeconds`) y devuelve `authorized`, `rejected` o `failed`;
si el SRI tarda más, devuelve `processing` o `pending_sri`, el documento sigue su curso y el
resultado llega por webhook.

Cada método acepta, como último argumento, las opciones de esa petición: `idempotencyKey`,
`timeout`, `maxRetries`, `language`, `correlationId` y `extraHeaders`. El resto de la API
(emisores, certificados, endpoints de webhooks, eventos) está en `Flashpi::issuers()`,
`Flashpi::issuerOnboardings()`, `Flashpi::webhookEndpoints()` y `Flashpi::events()`, como en el
[README del SDK](https://github.com/flashpi1/flashpi-php/blob/main/README.md).

## Documentos

```php
use Flashpi\Laravel\Facades\Flashpi;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

$pdf = Flashpi::documents()->pdf($documentId); // el RIDE, en bytes
Storage::put("facturas/{$documentId}.pdf", $pdf);

$xml = Flashpi::documents()->xml($documentId); // $xml['kind']: 'authorized' cuando ya lo autorizó el SRI
Storage::put("facturas/{$documentId}.xml", $xml['xml']);

// foreach recorre todas las páginas, que se piden a medida que avanzas.
foreach (Flashpi::documents()->list(['status' => 'authorized', 'issueDateFrom' => '2026-11-01']) as $document) {
    Log::info($document['accessKey']);
}
```

`Flashpi::documents()->get($id)` lee un documento y `Flashpi::documents()->sendEmail($id)`
reenvía el RIDE y el XML al correo del comprador.

## Webhooks

Flashpi te avisa por webhook cuando el SRI autoriza o rechaza un comprobante, cuando se genera
su RIDE, cuando se entrega o rebota su correo, y cuando cambia un certificado. El paquete los
recibe por ti.

### La ruta

El paquete registra `POST /flashpi/webhook` (la ruta `flashpi.webhook`) fuera del grupo `web`:
sin sesión y sin token CSRF, porque cada entrega trae su firma. Con cada entrega:

1. Verifica la firma (Standard Webhooks) con el cuerpo tal cual llegó, los encabezados y
   `FLASHPI_WEBHOOK_SECRET`, a través del SDK. Una entrega sin firma válida recibe un 400.
2. Responde por sí misma el desafío de verificación del endpoint
   (`webhook_endpoint.verification`).
3. Descarta las entregas repetidas (ver [Entregas repetidas](#entregas-repetidas)).
4. Lanza `WebhookReceived` y luego el evento de su tipo (`InvoiceAuthorized`, ...), y responde
   204.

Si un listener lanza una excepción, Laravel la reporta, la respuesta es un 500 y Flashpi
reintenta la entrega. Flashpi espera la respuesta 10 segundos: lo que tarde más va en la cola.

Cambia la ruta con `FLASHPI_WEBHOOK_PATH`, o agrégale middleware (por ejemplo `throttle`) en
`webhook.middleware` de `config/flashpi.php`. Si usas `php artisan route:cache`, vuelve a
ejecutarlo después de cambiarla.

### Registrar el endpoint

Con la app desplegada en una dirección https pública (`APP_URL`), registra su ruta en Flashpi:

```bash
php artisan flashpi:webhook
```

El comando registra `APP_URL` más la ruta (o la URL de `--url`) para todos los eventos del
ambiente de tu clave, y muestra **una sola vez** la línea `FLASHPI_WEBHOOK_SECRET=whsec_...`
para tu `.env`. Si lo pierdes, la página del endpoint en Webhooks de la
[consola](https://flashpi.com.ec/app/) lo muestra otra vez, igual que
`Flashpi::webhookEndpoints()->getSecret('<id del endpoint>')` (el comando, ejecutado otra vez,
muestra el id). Flashpi activa el endpoint cuando responde su desafío, firmado con ese secreto:
despliega la app con el secreto y ejecuta `php artisan flashpi:webhook --verify` para enviarlo
de nuevo. Ejecutado otra vez, el comando muestra el estado del endpoint.

Flashpi tiene que llegar a la URL desde internet: el comando rechaza una URL que no es https o
que es local (`localhost`, `*.test`, `10.0.0.1`, ...). En desarrollo, usa un túnel (ngrok,
Expose, Cloudflare Tunnel) y pasa su URL:
`php artisan flashpi:webhook --url=https://abc123.ngrok.app/flashpi/webhook`. El comando revisa
el host con una lista de nombres y rangos, sin consultar el DNS: si rechaza un host que sí es
público, pasa `--force`. La URL igual tiene que ser https, y la API de Flashpi la vuelve a
revisar.

Opciones: `--event=invoice.authorized` (repetible) para recibir solo esos eventos, y `--thin`
para recibir payloads thin. `--event` y `--thin` se aplican solo al crear el endpoint: si la URL
ya está registrada con otros valores, el comando falla sin cambiarla; cámbiala con
`Flashpi::webhookEndpoints()->update()` o en la consola de Flashpi.

### Eventos

Escucha el evento del tipo que te interesa. Laravel descubre los listeners de `app/Listeners`
por el tipo del parámetro de `handle()`; también puedes registrarlos con
`Event::listen(InvoiceAuthorized::class, MarkOrderInvoiced::class)`.

Laravel elige los listeners por la clase del evento (y sus interfaces), no por su clase padre:
un listener de `DocumentWebhookEvent`, `CertificateWebhookEvent`, `AccountWebhookEvent` o
`WebhookEvent` nunca corre. Para varios tipos, pasa sus clases a
`Event::listen([InvoiceAuthorized::class, InvoiceRejected::class], ...)` (o un tipo unión en
`handle()`), o escucha `WebhookReceived` y revisa `$event->type`.

```php
use App\Models\Order;
use Flashpi\Laravel\Events\InvoiceAuthorized;

final class MarkOrderInvoiced
{
    public function handle(InvoiceAuthorized $event): void
    {
        $invoice = $event->fetch()['data']; // el documento autorizado
        Order::query()
            ->where('flashpi_document_id', $invoice['id'])
            ->update(['access_key' => $invoice['accessKey']]);
    }
}
```

Cada evento trae `id`, `type`, `environment` (`test` o `live`), `createdAt`, `data` (el
documento, el certificado o el envío de correo, como lo describió la API en ese momento),
`payload` (el cuerpo completo) y `webhookId`. Todas las clases están en
`Flashpi\Laravel\Events`:

| Tipo de evento                                                     | Clase                                                              | `data`                |
| ------------------------------------------------------------------ | ------------------------------------------------------------------ | --------------------- |
| `invoice.authorized`, `invoice.rejected`                           | `InvoiceAuthorized`, `InvoiceRejected`                             | El documento          |
| `credit_note.authorized`, `credit_note.rejected`                   | `CreditNoteAuthorized`, `CreditNoteRejected`                       | El documento          |
| `debit_note.authorized`, `debit_note.rejected`                     | `DebitNoteAuthorized`, `DebitNoteRejected`                         | El documento          |
| `purchase_settlement.authorized`, `purchase_settlement.rejected`   | `PurchaseSettlementAuthorized`, `PurchaseSettlementRejected`       | El documento          |
| `waybill.authorized`, `waybill.rejected`                           | `WaybillAuthorized`, `WaybillRejected`                             | El documento          |
| `withholding.authorized`, `withholding.rejected`                   | `WithholdingAuthorized`, `WithholdingRejected`                     | El documento          |
| `document.ride.generated`, `document.ride.failed`                  | `DocumentRideGenerated`, `DocumentRideFailed`                      | El documento          |
| `document.email.sent`, `.delivered`, `.bounced`, `.complained`, `.failed` | `DocumentEmailSent`, `DocumentEmailDelivered`, `DocumentEmailBounced`, `DocumentEmailComplained`, `DocumentEmailFailed` | El documento |
| `certificate.verified`, `.pending`, `.blocked`, `.revoked`, `.reactivated` | `CertificateVerified`, `CertificatePending`, `CertificateBlocked`, `CertificateRevoked`, `CertificateReactivated` | El certificado |
| `account.email_paused`, `account.email_resumed`                    | `AccountEmailPaused`, `AccountEmailResumed`                        | El envío de correo    |
| Cualquiera, también los que esta versión no conoce                 | `WebhookReceived`                                                  | Lo que envió la API   |

`WebhookReceived` llega con cada entrega, antes del evento de su tipo: sirve para registrar
todas, o para tipos nuevos que esta versión aún no tiene. La entrega llega al menos una vez:
haz que tus listeners se puedan repetir sin efectos dobles.

### Listeners en cola

Un listener que implementa `ShouldQueue` corre en tu cola: los eventos solo guardan textos y
arrays, así que se serializan completos.

```php
use App\Models\Order;
use Flashpi\Laravel\Events\DocumentEmailBounced;
use Illuminate\Contracts\Queue\ShouldQueue;

final class FlagBouncedEmail implements ShouldQueue
{
    public function handle(DocumentEmailBounced $event): void
    {
        // Con un payload thin, fetch() pide el evento a la API aquí, en el worker.
        $document = $event->fetch()['data'];
        Order::query()
            ->where('flashpi_document_id', $document['id'])
            ->update(['email_bounced' => true]);
    }
}
```

La ruta responde 204 apenas encola el listener: con una cola real (no `sync`), si el job falla
después, lo reintenta tu cola (`$tries`, `failed_jobs`), no Flashpi.

### Entregas repetidas

Una entrega puede llegar más de una vez. La ruta recuerda el encabezado `webhook-id` de cada una
en la caché de Laravel durante 24 horas (con `Cache::add()`, atómico), así que una entrega
repetida no lanza los eventos otra vez: recibe un 204, o un 409 si la primera aún se está
procesando. Si un listener lanzó una excepción, la entrega se olvida para que el reintento de
Flashpi la procese.

Con varios servidores, usa un store de caché que compartan (database, redis, memcached o
dynamodb) en `FLASHPI_WEBHOOK_CACHE_STORE`. El store `null` no se acepta (cada entrega falla con
un 500 que nombra la variable); el store `array` olvida las entregas al terminar cada petición,
así que fuera de las pruebas no descarta nada; y el `add()` del store `apc` no es atómico. Las
entregas que reenvías desde Flashpi (`replay`) conservan su `webhook-id`: dentro de las 24 horas
también se descartan. Para que se procese otra vez (por ejemplo, después de corregir un listener
que hizo algo mal sin lanzar una excepción), olvídala antes de reenviarla:
`Cache::store(config('flashpi.webhook.deduplication.store'))->forget(WebhookController::cacheKey($webhookId))`,
con `Flashpi\Laravel\Http\Controllers\WebhookController` y el `webhook-id` de la entrega, que es el
id del evento (`$event->webhookId`).

### Payloads thin

Un endpoint en modo `thin` (registrado con `php artisan flashpi:webhook --thin`) envía solo el
`id`, el `type`, el `environment` y la fecha: `$event->data` es `null` y `$event->isThin()` es
`true`. `$event->fetch()` pide el evento completo a la API (`GET /v1/events/{id}`) solo cuando
lo llamas, y una sola vez por objeto de evento: `WebhookReceived` y la clase del tipo, y cada
listener encolado (que recibe su propia copia), lo piden por separado; con un payload completo,
devuelve el payload sin hacer ninguna petición, así que tus listeners funcionan igual en ambos
modos. Necesita una `FLASHPI_API_KEY` del mismo ambiente que el evento.

### Tu propia ruta

Con `FLASHPI_WEBHOOK_ENABLED=false` el paquete no registra la ruta. Para recibir los webhooks
en una ruta tuya, verifica cada uno con `Flashpi::unwrapWebhook($request->getContent(),
$request->headers, config('flashpi.webhook.secret'))`, como en el
[README del SDK](https://github.com/flashpi1/flashpi-php/blob/main/README.md#webhooks).

## Pruebas

El cliente envía sus peticiones por el cliente HTTP de Laravel, así que `Http::fake()`,
`Http::assertSent()` y `Http::preventStrayRequests()` funcionan con las llamadas a Flashpi:

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
        Http::preventStrayRequests(); // una petición sin respuesta falsa falla: nada llega a la API
        Http::fake([
            'api.flashpi.com.ec/v1/invoices' => Http::response(['id' => 'doc_1', 'status' => 'processing'], 202),
        ]);

        $order = (new Order())->forceFill(['id' => 42]);
        $invoice = Flashpi::invoices()->create($order->toFlashpiInvoice()); // o tu código, como IssueInvoice

        $this->assertSame('processing', $invoice['status']);
        Http::assertSent(fn(Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.flashpi.com.ec/v1/invoices'
            && data_get($request->data(), 'customer.id') === '1760013210001');
    }
}
```

- Pon `FLASHPI_API_KEY` con una clave falsa `fp_test_...` en el `phpunit.xml` (o en
  `.env.testing`), para que el cliente se cree.
- El SDK reintenta los 5xx con esperas crecientes: para probar errores sin esperar, agrega
  `<env name="FLASHPI_MAX_RETRIES" value="0"/>` al `phpunit.xml`.
- Las subidas de certificados (`Flashpi::issuers()->certificates->upload()`,
  `Flashpi::issuerOnboardings()->create()`) envían un cuerpo multipart que arma el propio SDK,
  así que `$request->hasFile()`, `$request->data()` y `$request['password']` no ven ninguna de
  sus partes: verifica con `$request->isMultipart()`, `$request->url()` y
  `str_contains($request->body(), ...)`.
- No hay `Flashpi::fake()`, y `Flashpi::shouldReceive()` no sirve (el cliente del SDK es una
  clase final): simula la API con `Http::fake()`.

Para probar tus listeners, crea el evento con el cuerpo de un webhook, sin firmas:

```php
use Flashpi\Laravel\Events\InvoiceAuthorized;
use Illuminate\Support\Facades\Event;

Event::dispatch(new InvoiceAuthorized([
    'id' => '019a1b2c-3d4e-7f60-8a1b-2c3d4e5f6a7b',
    'type' => 'invoice.authorized',
    'environment' => 'test',
    'createdAt' => '2026-11-16T15:20:00.000Z',
    'data' => $document, // un documento como lo devuelve la API
]));
```

## Comandos

| Comando                   | Qué hace                                                                                     |
| ------------------------- | -------------------------------------------------------------------------------------------- |
| `php artisan flashpi:install` | Publica `config/flashpi.php` (`--force` lo reemplaza) y muestra las variables que faltan en el `.env`. |
| `php artisan flashpi:check`   | Prueba la clave: su ambiente (pruebas o producción), el estado de la API y si la API la acepta. |
| `php artisan flashpi:webhook` | Registra el endpoint de webhooks de la app y muestra su secreto una vez. Opciones: `--url`, `--event`, `--thin`, `--verify`, `--force`. |

## Errores

Los errores son las excepciones del SDK, en `Flashpi\Exception`. Cada error de la API es una
subclase de `ApiException` según su estado HTTP; decide según `errorCode`, que es estable y en
inglés.

```php
use Flashpi\Exception\ApiException;
use Flashpi\Exception\UnprocessableEntityException;
use Flashpi\Laravel\Facades\Flashpi;
use Illuminate\Support\Facades\Log;

try {
    $invoice = Flashpi::invoices()->create($body);
} catch (UnprocessableEntityException $error) {
    // 'validation_failed', 'total_mismatch', ... con un error por campo
    Log::warning('Flashpi rechazó la factura', ['errorCode' => $error->errorCode, 'errors' => $error->errors]);
    throw $error;
} catch (ApiException $error) {
    // comparte el requestId con soporte
    Log::error('Error de Flashpi', ['status' => $error->status, 'errorCode' => $error->errorCode, 'requestId' => $error->requestId]);
    throw $error;
}
```

| Clase (`Flashpi\Exception`)    | Estado | Ejemplos de `errorCode`                                      |
| ------------------------------ | ------ | ------------------------------------------------------------ |
| `BadRequestException`          | 400    | `malformed_json`, `invalid_idempotency_key`                  |
| `AuthenticationException`      | 401    | `authentication_required`, `invalid_api_key`                 |
| `PermissionDeniedException`    | 403    | `key_read_only`, `key_scope_mismatch`, `ip_not_allowed`      |
| `NotFoundException`            | 404    | `not_found`                                                  |
| `ConflictException`            | 409    | `sequential_already_used`, `ride_not_available`              |
| `UnprocessableEntityException` | 422    | `validation_failed`, `total_mismatch`                        |
| `RateLimitException`           | 429    | `demo_issuer_quota_exceeded`, `account_email_limit_reached`  |
| `InternalServerException`      | 5xx    | `internal_error`                                             |
| `ConnectionException`          | —      | sin respuesta (red); `TimeoutException` si se agotó el plazo |

Todas derivan de `FlashpiException`, que también recibes cuando falta la configuración: sin
`FLASHPI_API_KEY`, la primera vez que pides el cliente; con un valor que no se puede convertir
(por ejemplo `FLASHPI_TIMEOUT=rápido` o `FLASHPI_LANGUAGE=fr`), con el nombre de la variable y
nunca su valor. Los rangos, la URL y el prefijo de la clave los revisa el SDK, que nombra su
propio parámetro (`timeout`, `maxRetries`, `baseUrl`) y nunca muestra la clave. Los reintentos
y la idempotencia funcionan como en el
[README del SDK](https://github.com/flashpi1/flashpi-php/blob/main/README.md#reintentos-e-idempotencia).

## Seguridad

- Tu clave de API es secreta: déjala en el `.env` de tus servidores, nunca en el repositorio ni
  en código que corre en los dispositivos de tus usuarios. El SDK no la muestra en `dump()`,
  excepciones ni trazas.
- Las peticiones a Flashpi pasan por el cliente HTTP de Laravel. Si registras las peticiones
  salientes (Telescope, Pulse, Nightwatch, un log propio), oculta su encabezado `Authorization`:
  lleva tu clave.
- La ruta de webhooks acepta solo entregas firmadas con tu `FLASHPI_WEBHOOK_SECRET` y recientes
  (5 minutos). Sin el secreto responde 500 a las peticiones que traen los encabezados de webhook
  (`webhook-id`, `webhook-timestamp` y `webhook-signature`), para que lo notes y Flashpi
  reintente; las que no los traen reciben un 400. Si tu app solo llama a la API, quita la ruta
  con `FLASHPI_WEBHOOK_ENABLED=false`.
- Reporta los problemas de seguridad en privado: ver
  [SECURITY.md](https://github.com/flashpi1/flashpi-laravel/blob/main/SECURITY.md).

## Más documentación

- [Documentación de los SDKs](https://flashpi.com.ec/docs/sdks/) y de la
  [API](https://flashpi.com.ec/docs/).
- El [README del SDK de PHP](https://github.com/flashpi1/flashpi-php/blob/main/README.md): los
  tipos, los reintentos, la paginación, los emisores y los certificados.
- [Las decisiones de este paquete](https://github.com/flashpi1/flashpi-laravel/blob/main/docs/decisions.md).

## Licencia

[MIT](https://github.com/flashpi1/flashpi-laravel/blob/main/LICENSE) © 2026 SIGNIADIGITAL S.A.
