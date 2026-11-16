<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Http\Controllers;

use Flashpi\Exception\FlashpiException;
use Flashpi\Exception\WebhookVerificationException;
use Flashpi\Flashpi;
use Flashpi\Laravel\Events\WebhookEvents;
use Flashpi\Laravel\Events\WebhookReceived;
use Flashpi\Laravel\Internal\WebhookSettings;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The package's webhook route (POST /flashpi/webhook by default): receives Flashpi's webhooks
 * and dispatches them as Laravel events.
 *
 * 1. It verifies the Standard Webhooks signature through the SDK (Flashpi::unwrapWebhook()), with
 *    the raw body, the headers and FLASHPI_WEBHOOK_SECRET: a delivery that does not verify (a
 *    bad or missing signature, a timestamp more than 5 minutes away, a body that is not a
 *    Flashpi event) is answered 400, with no detail. A request without the webhook-id,
 *    webhook-timestamp and webhook-signature headers cannot be a Flashpi delivery: it is answered
 *    400 before the secret is read, so an app that never set one is not flooded with reports by
 *    scanners. A request that carries them, without a valid secret in the configuration, throws a
 *    FlashpiException (a 500, reported like any error) so that Flashpi retries.
 * 2. It answers the endpoint's verification challenge (`webhook_endpoint.verification`) with
 *    `{"challenge": "..."}`, and dispatches nothing for it.
 * 3. It remembers the delivery's webhook-id in the cache (24 hours, Cache::add(), which is atomic
 *    in the stores that share it between servers: database, redis, memcached, dynamodb): a
 *    delivery seen before is answered 204 without dispatching it again, or 409 while its first
 *    delivery is still being handled, so that Flashpi tries again later.
 * 4. It dispatches WebhookReceived, then the event of the type (InvoiceAuthorized, ...) when this
 *    version has one, remembers the delivery as processed and answers 204. When a listener
 *    throws, the webhook-id is forgotten and the error goes to Laravel's exception handler (a
 *    500): Flashpi's retry handles it again.
 *
 * Flashpi waits 10 seconds for the answer: listeners that take longer belong on the queue
 * (ShouldQueue).
 */
final class WebhookController
{
    /** The cache key of a delivery is this prefix and its webhook-id. */
    public const CACHE_PREFIX = 'flashpi:webhook:';

    /** The cached value while a delivery is being handled. */
    public const PROCESSING = 'processing';

    /** The cached value once its events were dispatched. */
    public const PROCESSED = 'processed';

    private const VERIFICATION = 'webhook_endpoint.verification';

    /**
     * @throws FlashpiException A missing or malformed webhook setting.
     * @throws \Throwable What a listener throws.
     */
    public function __invoke(
        Request $request,
        Repository $config,
        Dispatcher $events,
        CacheFactory $cache,
        ExceptionHandler $exceptions,
    ): Response|JsonResponse {
        if (!self::hasWebhookHeaders($request)) {
            return new Response('', 400);
        }
        $secret = WebhookSettings::secret($config);
        try {
            $payload = Flashpi::unwrapWebhook($request->getContent(), $request->headers, $secret);
        } catch (WebhookVerificationException $error) {
            if ($error->reason === WebhookVerificationException::INVALID_SECRET) {
                throw new FlashpiException("config flashpi.webhook.secret (FLASHPI_WEBHOOK_SECRET) is not valid: {$error->getMessage()}", $error);
            }

            return new Response('', 400);
        }

        if (self::isVerification($payload)) {
            $challenge = self::challenge($payload);

            return $challenge === null ? new Response('', 400) : new JsonResponse(['challenge' => $challenge]);
        }

        // The SDK checked that the header is there.
        $webhookId = (string) $request->headers->get('webhook-id');
        try {
            $received = new WebhookReceived($payload, $webhookId);
            $class = WebhookEvents::classFor($received->type);
            $typed = $class === null ? null : new $class($payload, $webhookId);
        } catch (\InvalidArgumentException) {
            return new Response('', 400);
        }

        $store = $cache->store(WebhookSettings::store($config));
        $ttl = WebhookSettings::ttl($config);
        $key = self::cacheKey($webhookId);
        if (!$store->add($key, self::PROCESSING, $ttl)) {
            return new Response('', $store->get($key) === self::PROCESSED ? 204 : 409);
        }
        try {
            $events->dispatch($received);
            if ($typed !== null) {
                $events->dispatch($typed);
            }
        } catch (\Throwable $error) {
            $store->forget($key);

            throw $error;
        }
        try {
            $store->put($key, self::PROCESSED, $ttl);
        } catch (\Throwable $error) {
            // The events went out: a 204 keeps Flashpi from sending them again, and the
            // delivery stays `processing` until it expires.
            $exceptions->report($error);
        }

        return new Response('', 204);
    }

    /**
     * Whether the request carries the three Standard Webhooks headers, each with a value. It
     * refuses only what the SDK's `missing_headers` refuses too (the SDK joins the values of a
     * repeated webhook-signature), so no delivery the SDK would accept is turned away.
     */
    private static function hasWebhookHeaders(Request $request): bool
    {
        foreach (['webhook-id', 'webhook-timestamp', 'webhook-signature'] as $name) {
            if (implode('', $request->headers->all($name)) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a delivery is the endpoint's verification challenge.
     *
     * @param array<string, mixed> $payload
     */
    private static function isVerification(array $payload): bool
    {
        return ($payload['type'] ?? null) === self::VERIFICATION;
    }

    /**
     * The challenge to echo, or null when the verification has none.
     *
     * @param array<string, mixed> $payload
     */
    private static function challenge(array $payload): ?string
    {
        $data = $payload['data'] ?? null;
        $challenge = \is_array($data) ? $data['challenge'] ?? null : null;

        return \is_string($challenge) ? $challenge : null;
    }

    /**
     * The cache key that remembers a delivery: the prefix and its webhook-id (Flashpi sends the
     * event's id, a UUID), or the id's SHA-256 when it holds characters some stores refuse.
     */
    public static function cacheKey(string $webhookId): string
    {
        $id = preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/', $webhookId) === 1 ? $webhookId : hash('sha256', $webhookId);

        return self::CACHE_PREFIX . $id;
    }
}
