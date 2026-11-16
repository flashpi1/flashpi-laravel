<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Facades;

use Flashpi\Exception\WebhookVerificationException;
use Flashpi\Flashpi as Client;
use Flashpi\Resources\CreditNotes;
use Flashpi\Resources\DebitNotes;
use Flashpi\Resources\Documents;
use Flashpi\Resources\Events;
use Flashpi\Resources\Invoices;
use Flashpi\Resources\IssuerOnboardings;
use Flashpi\Resources\Issuers;
use Flashpi\Resources\PurchaseSettlements;
use Flashpi\Resources\StatusResource;
use Flashpi\Resources\Waybills;
use Flashpi\Resources\WebhookEndpoints;
use Flashpi\Resources\Withholdings;
use Flashpi\Types\SdkTypes;
use Flashpi\Webhooks;
use Illuminate\Support\Facades\Facade;

/**
 * The Flashpi client of the container (Flashpi\Flashpi, a singleton built from
 * config/flashpi.php).
 *
 * ```php
 * use Flashpi\Laravel\Facades\Flashpi;
 *
 * $invoice = Flashpi::invoices()->create([...]);
 * ```
 *
 * The client's resources are read-only properties (`$flashpi->invoices`): each one is a method
 * of the facade that returns it. In tests, fake the API with Http::fake(): the client sends its
 * requests through Laravel's HTTP client.
 *
 * @method static Invoices invoices() Invoices (facturas).
 * @method static CreditNotes creditNotes() Credit notes (notas de crédito).
 * @method static DebitNotes debitNotes() Debit notes (notas de débito).
 * @method static PurchaseSettlements purchaseSettlements() Purchase settlements (liquidaciones de compra).
 * @method static Waybills waybills() Waybills (guías de remisión).
 * @method static Withholdings withholdings() Withholding receipts (comprobantes de retención).
 * @method static Documents documents() Every issued document, of any type: search, XML, RIDE (PDF) and email.
 * @method static Issuers issuers() The issuers (RUCs) of the account, with their establishments, emission points and certificates.
 * @method static IssuerOnboardings issuerOnboardings() Creates an issuer from its .p12 certificate.
 * @method static WebhookEndpoints webhookEndpoints() The URLs Flashpi sends events to, with their deliveries.
 * @method static Events events() Events: what happened to documents, certificates and email sending.
 * @method static StatusResource status() The API's own status.
 * @method static Webhooks webhooks() Verify the signature of the webhooks you receive.
 * @method static 'test'|'live' environment() `test` for fp_test_ keys (SRI pruebas), `live` for fp_live_ keys.
 *
 * @phpstan-import-type WebhookEvent from SdkTypes
 * @phpstan-import-type WebhookHeaders from SdkTypes
 *
 * @see Client
 */
final class Flashpi extends Facade
{
    /**
     * Verifies a webhook and returns its decoded body: see Flashpi\Webhooks::unwrap(). It needs
     * no API key, so it does not build the client.
     *
     * @param string|\Stringable $payload The body exactly as received.
     * @param WebhookHeaders $headers
     * @param string $secret The endpoint's whsec_ secret.
     *
     * @return WebhookEvent
     *
     * @throws WebhookVerificationException
     */
    public static function unwrapWebhook(
        mixed $payload,
        iterable $headers,
        #[\SensitiveParameter]
        mixed $secret,
        int|float|null $tolerance = null,
        \DateTimeInterface|int|float|null $now = null,
    ): array {
        return Client::unwrapWebhook($payload, $headers, $secret, $tolerance, $now);
    }

    /**
     * Checks that a webhook comes from Flashpi and is recent: see
     * Flashpi\Webhooks::verifySignature(). It needs no API key, so it does not build the client.
     *
     * @param string|\Stringable $payload The body exactly as received.
     * @param WebhookHeaders $headers
     * @param string $secret The endpoint's whsec_ secret.
     *
     * @throws WebhookVerificationException
     */
    public static function verifyWebhookSignature(
        mixed $payload,
        iterable $headers,
        #[\SensitiveParameter]
        mixed $secret,
        int|float|null $tolerance = null,
        \DateTimeInterface|int|float|null $now = null,
    ): void {
        Client::verifyWebhookSignature($payload, $headers, $secret, $tolerance, $now);
    }

    /**
     * A resource of the client (or its environment) for the method of the same name; any other
     * public method of the client is called as it is.
     *
     * @param string $method
     * @param array<mixed> $args
     *
     * @throws \BadMethodCallException
     */
    public static function __callStatic($method, $args): mixed
    {
        $client = static::getFacadeRoot();
        if (!\is_object($client)) {
            throw new \RuntimeException('A facade root has not been set.');
        }
        // From here, only the client's public properties are visible.
        $properties = get_object_vars($client);
        if (\array_key_exists($method, $properties)) {
            if ($args !== []) {
                throw new \BadMethodCallException("Flashpi::{$method}() takes no arguments: it returns the client's {$method}");
            }

            return $properties[$method];
        }
        if (!\is_callable([$client, $method])) {
            throw new \BadMethodCallException("Flashpi has no resource or method named {$method}()");
        }

        return $client->{$method}(...$args);
    }

    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
