<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Tests\Webhooks;

use Flashpi\Exception\FlashpiException;
use Flashpi\Laravel\Events\CertificateRevoked;
use Flashpi\Laravel\Events\InvoiceAuthorized;
use Flashpi\Laravel\Events\WebhookReceived;
use Flashpi\Laravel\Http\Controllers\WebhookController;
use Flashpi\Laravel\Tests\Support\Api;
use Flashpi\Laravel\Tests\Support\Webhook;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The webhook route with real signatures: verification, the challenge, the events and the
 * deduplication of the deliveries.
 */
final class WebhookControllerTest extends WebhookTestCase
{
    public function testSignsTheDeliveriesAsFlashpiDoes(): void
    {
        // The example of Flashpi's webhook docs, which flashpi-php's tests verify too.
        $body = '{"id":"019a1b2c-3d4e-7f60-8a1b-2c3d4e5f6a7b","type":"invoice.authorized","environment":"test","createdAt":"2026-10-16T17:20:00.000Z"}';
        $secret = 'whsec_Zmxhc2hwaS1kb2NzLWV4YW1wbGUtc2VjcmV0LTMyLWI=';

        self::assertSame('v1,HbWOpDPWCo3wtq41t9GVHUpg69M1zgvveoDJmSWkA8U=', Webhook::signature($body, $secret, Webhook::ID, 1792152000));
    }

    public function testDispatchesWebhookReceivedThenTheEventOfTheType(): void
    {
        $events = $this->record([WebhookReceived::class, InvoiceAuthorized::class]);
        $event = Webhook::event('invoice.authorized');

        $this->deliver($event)->assertNoContent();

        self::assertCount(2, $events);
        [$received, $typed] = [$events[0], $events[1]];
        self::assertInstanceOf(WebhookReceived::class, $received);
        self::assertInstanceOf(InvoiceAuthorized::class, $typed);
        foreach ([$received, $typed] as $dispatched) {
            self::assertSame(Webhook::ID, $dispatched->id);
            self::assertSame('invoice.authorized', $dispatched->type);
            self::assertSame('test', $dispatched->environment);
            self::assertSame('2026-11-16T15:20:00.000Z', $dispatched->createdAt);
            self::assertSame(Webhook::document(), $dispatched->data);
            self::assertSame($event, $dispatched->payload);
            self::assertSame(Webhook::ID, $dispatched->webhookId);
            self::assertFalse($dispatched->isThin());
        }
    }

    public function testDispatchesTheEventOfEveryCategory(): void
    {
        Event::fake();
        $certificate = ['id' => 'cert_1', 'status' => 'accepted', 'revocation' => ['status' => 'revoked']];

        $this->deliver(Webhook::event('certificate.revoked', $certificate, 'live'), 'b')->assertNoContent();

        Event::assertDispatchedTimes(WebhookReceived::class, 1);
        Event::assertDispatched(CertificateRevoked::class, static fn(CertificateRevoked $event): bool => $event->data === $certificate
            && $event->environment === 'live'
            && $event->webhookId === 'b');
    }

    public function testDispatchesOnlyWebhookReceivedForAnUnknownType(): void
    {
        $events = $this->record([WebhookReceived::class, InvoiceAuthorized::class]);
        $event = [...Webhook::event('invoice.archived', ['id' => 'doc_1']), 'reason' => 'new field'];

        $this->deliver($event)->assertNoContent();

        self::assertCount(1, $events);
        self::assertInstanceOf(WebhookReceived::class, $events[0]);
        self::assertSame('invoice.archived', $events[0]->type);
        self::assertSame(['id' => 'doc_1'], $events[0]->data);
        self::assertSame('new field', $events[0]->payload['reason'] ?? null);
    }

    public function testAnswersTheVerificationChallengeWithoutEventsOrMemory(): void
    {
        Event::fake([WebhookReceived::class]);
        $challenge = base64_encode(random_bytes(32));
        $verification = Webhook::verification($challenge);

        // Flashpi sends the challenge again with `flashpi:webhook --verify`: it is answered every time.
        for ($attempt = 1; $attempt <= 2; ++$attempt) {
            $this->deliver($verification, $verification['id'])
                ->assertOk()
                ->assertExactJson(['challenge' => $challenge]);
        }

        Event::assertNothingDispatched();
        self::assertFalse($this->cache()->has(WebhookController::cacheKey($verification['id'])));
    }

    public function testNeedsNoApiKey(): void
    {
        $this->config()->set('flashpi.api_key', null);
        $events = $this->record([InvoiceAuthorized::class]);

        $this->deliver(Webhook::event('invoice.authorized'))->assertNoContent();

        self::assertCount(1, $events);
    }

    public function testAcceptsEitherSecretDuringARotation(): void
    {
        $events = $this->record([InvoiceAuthorized::class]);
        $body = Webhook::body(Webhook::event('invoice.authorized'));
        $headers = Webhook::headers($body, self::fakeSecret());
        $headers['webhook-signature'] .= ' ' . Webhook::signature($body, $this->secret, Webhook::ID, $headers['webhook-timestamp']);

        $this->postRaw($body, $headers)->assertNoContent();

        self::assertCount(1, $events);
    }

    /**
     * @return iterable<string, array{\Closure(string, string): array{string, array<string, string>}}>
     */
    public static function badDeliveries(): iterable
    {
        $event = static fn(): string => Webhook::body(Webhook::event('invoice.authorized'));
        $signed = static fn(string $body, string $secret): array => [$body, Webhook::headers($body, $secret)];

        yield 'tampered body' => [static function (string $secret) use ($event): array {
            $body = $event();
            $headers = Webhook::headers($body, $secret);

            return [str_replace('"test"', '"live"', $body), $headers];
        }];
        yield 'old timestamp' => [static fn(string $secret): array => [$event(), Webhook::headers($event(), $secret, timestamp: time() - 301)]];
        yield 'future timestamp' => [static fn(string $secret): array => [$event(), Webhook::headers($event(), $secret, timestamp: time() + 301)]];
        yield 'wrong secret' => [static fn(string $secret, string $other): array => [$event(), Webhook::headers($event(), $other)]];
        foreach (['webhook-id', 'webhook-timestamp', 'webhook-signature'] as $header) {
            yield "no {$header}" => [static function (string $secret) use ($event, $header): array {
                $headers = Webhook::headers($event(), $secret);
                unset($headers[$header]);

                return [$event(), $headers];
            }];
        }
        yield 'no headers' => [static fn(string $secret): array => [$event(), []]];
        yield 'not JSON' => [static fn(string $secret): array => $signed('not json', $secret)];
        yield 'a JSON list' => [static fn(string $secret): array => $signed('[1,2]', $secret)];
        yield 'no type' => [static fn(string $secret): array => $signed('{"id":"a","environment":"test","createdAt":"2026-11-16T15:20:00Z"}', $secret)];
        yield 'no id' => [static fn(string $secret): array => $signed('{"type":"invoice.authorized","environment":"test","createdAt":"2026-11-16T15:20:00Z"}', $secret)];
        yield 'other environment' => [static fn(string $secret): array => $signed(Webhook::body(Webhook::event('invoice.authorized', environment: 'staging')), $secret)];
        yield 'data not an object' => [static fn(string $secret): array => $signed(Webhook::body([...Webhook::thin('invoice.authorized'), 'data' => 'x']), $secret)];
        yield 'no challenge' => [static fn(string $secret): array => $signed(Webhook::body([...Webhook::verification('c'), 'data' => []]), $secret)];
    }

    /**
     * @param \Closure(string, string): array{string, array<string, string>} $delivery
     */
    #[DataProvider('badDeliveries')]
    public function testRefusesADeliveryThatDoesNotVerifyWith400AndNoDetail(\Closure $delivery): void
    {
        Event::fake([WebhookReceived::class, InvoiceAuthorized::class]);
        [$body, $headers] = $delivery($this->secret, self::fakeSecret());

        $response = $this->postRaw($body, $headers);

        $response->assertStatus(400);
        self::assertSame('', $response->getContent());
        Event::assertNothingDispatched();
        self::assertFalse($this->cache()->has(WebhookController::cacheKey(Webhook::ID)));
    }

    /**
     * @return iterable<string, array{mixed, non-empty-string}>
     */
    public static function missingSecrets(): iterable
    {
        yield 'unset' => [null, 'Missing Flashpi webhook secret: set FLASHPI_WEBHOOK_SECRET'];
        yield 'empty' => ['  ', 'Missing Flashpi webhook secret: set FLASHPI_WEBHOOK_SECRET'];
        yield 'not a string' => [['whsec_'], 'config flashpi.webhook.secret (FLASHPI_WEBHOOK_SECRET) must be a string'];
        yield 'not base64' => ['whsec_not base64!', 'config flashpi.webhook.secret (FLASHPI_WEBHOOK_SECRET) is not valid: The webhook secret is not a whsec_ secret'];
        yield 'cut short' => ['whsec_' . base64_encode('0123456789'), 'config flashpi.webhook.secret (FLASHPI_WEBHOOK_SECRET) is not valid: The webhook secret is not a complete whsec_ secret'];
    }

    /**
     * @param non-empty-string $message
     */
    #[DataProvider('missingSecrets')]
    public function testThrowsWithoutAValidSecretSoThatFlashpiRetries(mixed $secret, string $message): void
    {
        $this->config()->set('flashpi.webhook.secret', $secret);
        Event::fake([WebhookReceived::class]);
        $event = Webhook::event('invoice.authorized');

        $this->deliver($event)->assertStatus(500);

        $this->withoutExceptionHandling();
        try {
            $this->deliver($event);
            self::fail('No exception');
        } catch (FlashpiException $error) {
            self::assertStringStartsWith($message, $error->getMessage());
            if (\is_string($secret) && trim($secret) !== '') {
                self::assertStringNotContainsString(substr($secret, 6), $error->getMessage());
            }
        }
        Event::assertNothingDispatched();
    }

    /**
     * @return iterable<string, array{mixed, array<string, string>}>
     */
    public static function requestsWithoutTheWebhookHeaders(): iterable
    {
        $headers = ['webhook-id' => Webhook::ID, 'webhook-timestamp' => '1792152000', 'webhook-signature' => 'v1,c2lnbmF0dXJl'];
        foreach (['unset' => null, 'malformed' => 'whsec_not base64!'] as $name => $secret) {
            yield "secret {$name}, no headers" => [$secret, []];
            foreach (array_keys($headers) as $header) {
                $without = $headers;
                unset($without[$header]);
                yield "secret {$name}, no {$header}" => [$secret, $without];
                yield "secret {$name}, an empty {$header}" => [$secret, [...$headers, $header => '']];
            }
        }
    }

    /**
     * A scanner's POST to the route, in an app that calls the API only and has no secret: it
     * cannot be a Flashpi delivery, so it is refused before the secret is read.
     *
     * @param array<string, string> $headers
     */
    #[DataProvider('requestsWithoutTheWebhookHeaders')]
    public function testRefusesARequestWithoutTheWebhookHeadersBeforeReadingTheSecret(mixed $secret, array $headers): void
    {
        Exceptions::fake();
        $this->config()->set('flashpi.webhook.secret', $secret);
        Event::fake([WebhookReceived::class]);

        $response = $this->postRaw(Webhook::body(Webhook::event('invoice.authorized')), $headers);

        $response->assertStatus(400);
        self::assertSame('', $response->getContent());
        Exceptions::assertNothingReported();
        Event::assertNothingDispatched();
    }

    public function testHandlesARepeatedDeliveryOnce(): void
    {
        $events = $this->record([WebhookReceived::class, InvoiceAuthorized::class]);
        $body = Webhook::body(Webhook::event('invoice.authorized'));
        $headers = Webhook::headers($body, $this->secret);

        $this->postRaw($body, $headers)->assertNoContent();
        // The same request again, then Flashpi's retry: the same webhook-id, a new timestamp and signature.
        $this->postRaw($body, $headers)->assertNoContent();
        $this->postRaw($body, Webhook::headers($body, $this->secret, timestamp: time() - 60))->assertNoContent();

        self::assertCount(2, $events);
        self::assertSame(WebhookController::PROCESSED, $this->cache()->get('flashpi:webhook:' . Webhook::ID));
        // Another event is handled.
        $this->deliver(Webhook::event('invoice.authorized', id: 'other'), 'other')->assertNoContent();
        self::assertCount(4, $events);
    }

    public function testForgetsADeliveryWhoseListenerThrowsSoThatTheRetryHandlesIt(): void
    {
        Exceptions::fake();
        $calls = 0;
        $this->dispatcher()->listen(InvoiceAuthorized::class, static function () use (&$calls): void {
            if (++$calls === 1) {
                throw new \RuntimeException('The database is down');
            }
        });
        $received = $this->record([WebhookReceived::class]);
        $event = Webhook::event('invoice.authorized');

        $this->deliver($event)->assertStatus(500);
        self::assertFalse($this->cache()->has(WebhookController::cacheKey(Webhook::ID)));
        Exceptions::assertReported(static fn(\RuntimeException $error): bool => $error->getMessage() === 'The database is down');

        $this->deliver($event)->assertNoContent();
        $this->deliver($event)->assertNoContent();

        self::assertSame(2, $calls);
        self::assertCount(2, $received);
    }

    public function testLetsTheListenersErrorReachLaravelsExceptionHandler(): void
    {
        $this->dispatcher()->listen(WebhookReceived::class, static function (): void {
            throw new \DomainException('Listener failed');
        });
        $this->withoutExceptionHandling();

        try {
            $this->deliver(Webhook::event('invoice.authorized'));
            self::fail('No exception');
        } catch (\DomainException $error) {
            self::assertSame('Listener failed', $error->getMessage());
        }
        self::assertFalse($this->cache()->has(WebhookController::cacheKey(Webhook::ID)));
    }

    public function testAnswers409WhileTheFirstDeliveryIsStillBeingHandled(): void
    {
        $events = $this->record([WebhookReceived::class]);
        $this->cache()->put(WebhookController::cacheKey(Webhook::ID), WebhookController::PROCESSING, 60);

        $this->deliver(Webhook::event('invoice.authorized'))->assertStatus(409);

        self::assertCount(0, $events);
    }

    public function testHandlesADeliveryAgainOnceTheLeaseOfARequestThatDiedEnds(): void
    {
        Exceptions::fake();
        // A request that dies without an exception (a fatal error, a killed worker) never
        // forgets its key: a store whose forget() does nothing leaves it as such a request does.
        $this->useStore(new class extends ArrayStore {
            public function forget($key): bool
            {
                return false;
            }
        });
        $calls = 0;
        $this->dispatcher()->listen(InvoiceAuthorized::class, static function () use (&$calls): void {
            if (++$calls === 1) {
                throw new \RuntimeException('Killed');
            }
        });
        $event = Webhook::event('invoice.authorized');

        $this->deliver($event)->assertStatus(500);
        // Flashpi's first retries, 5 seconds later.
        $this->travel(5)->seconds();
        $this->deliver($event)->assertStatus(409);
        // Its next one, 5 minutes later, is past the lease.
        $this->travel(296)->seconds();
        $this->deliver($event)->assertNoContent();
        // Processed now: remembered for the whole TTL.
        $this->travel(86399)->seconds();
        $this->deliver($event)->assertNoContent();

        self::assertSame(2, $calls);
    }

    public function testKeepsTheListenersErrorWhenForgettingTheDeliveryFailsToo(): void
    {
        Exceptions::fake();
        $this->useStore(new class extends ArrayStore {
            public function forget($key): bool
            {
                throw new \RuntimeException('The cache is down');
            }
        });
        $this->dispatcher()->listen(InvoiceAuthorized::class, static function (): void {
            throw new \DomainException('Listener failed');
        });

        $this->deliver(Webhook::event('invoice.authorized'))->assertStatus(500);

        Exceptions::assertReported(static fn(\DomainException $error): bool => $error->getMessage() === 'Listener failed');
        Exceptions::assertReported(static fn(\RuntimeException $error): bool => $error->getMessage() === 'The cache is down');
        // Not forgotten: the retries wait until its lease ends.
        self::assertSame(WebhookController::PROCESSING, $this->cache('custom')->get(WebhookController::cacheKey(Webhook::ID)));

        // The listener's error is the one thrown, for another delivery.
        $this->withoutExceptionHandling();
        try {
            $this->deliver(Webhook::event('invoice.authorized', id: 'other'), 'other');
            self::fail('No exception');
        } catch (\DomainException $error) {
            self::assertSame('Listener failed', $error->getMessage());
        }
    }

    public function testAnswers204WhenTheCacheFailsAfterTheEventsWentOut(): void
    {
        Exceptions::fake();
        $this->useStore(new class extends ArrayStore {
            public function put($key, $value, $seconds): bool
            {
                if ($value === WebhookController::PROCESSED) {
                    throw new \RuntimeException('The cache is down');
                }

                return parent::put($key, $value, $seconds);
            }
        });
        $events = $this->record([InvoiceAuthorized::class]);

        $this->deliver(Webhook::event('invoice.authorized'))->assertNoContent();

        self::assertCount(1, $events);
        Exceptions::assertReported(static fn(\RuntimeException $error): bool => $error->getMessage() === 'The cache is down');
        // Still being handled, as far as the cache knows: a retry waits until its lease ends.
        self::assertSame(WebhookController::PROCESSING, $this->cache('custom')->get(WebhookController::cacheKey(Webhook::ID)));
    }

    public function testRemembersADeliveryForTheConfiguredTime(): void
    {
        $events = $this->record([WebhookReceived::class]);
        $event = Webhook::event('invoice.authorized');

        $this->deliver($event)->assertNoContent();
        $this->travel(86399)->seconds();
        $this->deliver($event)->assertNoContent();
        self::assertCount(1, $events);
        $this->travel(2)->seconds();
        $this->deliver($event)->assertNoContent();
        self::assertCount(2, $events);

        $this->config()->set('flashpi.webhook.deduplication.ttl', ' 60 ');
        $this->deliver(Webhook::event('invoice.authorized', id: 'short'), 'short')->assertNoContent();
        $this->travel(61)->seconds();
        $this->deliver(Webhook::event('invoice.authorized', id: 'short'), 'short')->assertNoContent();
        self::assertCount(4, $events);
    }

    public function testRemembersTheDeliveriesInTheConfiguredStore(): void
    {
        $this->config()->set('cache.stores.webhooks', ['driver' => 'array']);
        $this->config()->set('flashpi.webhook.deduplication.store', 'webhooks');

        $this->deliver(Webhook::event('invoice.authorized'))->assertNoContent();

        $key = WebhookController::cacheKey(Webhook::ID);
        self::assertSame(WebhookController::PROCESSED, $this->cache('webhooks')->get($key));
        self::assertFalse($this->cache()->has($key));
    }

    /**
     * @return iterable<string, array{string, mixed, string}>
     */
    public static function badDeduplicationSettings(): iterable
    {
        yield 'ttl 0' => ['ttl', 0, 'config flashpi.webhook.deduplication.ttl must be a whole number of seconds, 1 or more'];
        yield 'ttl text' => ['ttl', '1 day', 'config flashpi.webhook.deduplication.ttl must be a whole number of seconds, 1 or more'];
        yield 'store not a name' => ['store', ['redis'], 'config flashpi.webhook.deduplication.store (FLASHPI_WEBHOOK_CACHE_STORE) must be the name of a cache store'];
    }

    #[DataProvider('badDeduplicationSettings')]
    public function testThrowsForABadDeduplicationSetting(string $key, mixed $value, string $message): void
    {
        $this->config()->set("flashpi.webhook.deduplication.{$key}", $value);
        $this->withoutExceptionHandling();

        $this->expectException(FlashpiException::class);
        $this->expectExceptionMessage($message);

        $this->deliver(Webhook::event('invoice.authorized'));
    }

    public function testUsesTheDefaultsOfAConfigurationWithoutTheNestedKeys(): void
    {
        // An app's config/flashpi.php replaces the package's `webhook` array whole.
        $this->config()->set('flashpi.webhook', ['secret' => $this->secret]);
        $events = $this->record([WebhookReceived::class]);

        $this->deliver(Webhook::event('invoice.authorized'))->assertNoContent();
        $this->travel(86399)->seconds();
        $this->deliver(Webhook::event('invoice.authorized'))->assertNoContent();

        self::assertCount(1, $events);
    }

    public function testKeysTheCacheByTheWebhookId(): void
    {
        self::assertSame('flashpi:webhook:019a1b2c-3d4e-7f60-8a1b-2c3d4e5f6a7b', WebhookController::cacheKey(Webhook::ID));
        self::assertSame('flashpi:webhook:msg_p5jXN8AQM9LWM0D4loKWxJek', WebhookController::cacheKey('msg_p5jXN8AQM9LWM0D4loKWxJek'));
        self::assertSame('flashpi:webhook:' . hash('sha256', 'an id with spaces'), WebhookController::cacheKey('an id with spaces'));
        self::assertSame('flashpi:webhook:' . hash('sha256', str_repeat('a', 129)), WebhookController::cacheKey(str_repeat('a', 129)));

        $this->deliver(Webhook::event('invoice.authorized'), 'an id with spaces')->assertNoContent();
        self::assertTrue($this->cache()->has('flashpi:webhook:' . hash('sha256', 'an id with spaces')));
    }

    public function testGivesAThinPayloadThatFetchesTheEventOnlyWhenAsked(): void
    {
        Http::preventStrayRequests();
        $full = Webhook::event('invoice.authorized');
        Http::fake([Api::url('/v1/events/' . Webhook::ID) => Api::json($full)]);
        $events = $this->record([InvoiceAuthorized::class]);

        $this->deliver(Webhook::thin('invoice.authorized'))->assertNoContent();

        Http::assertNothingSent();
        $event = $events[0];
        self::assertInstanceOf(InvoiceAuthorized::class, $event);
        self::assertTrue($event->isThin());
        self::assertNull($event->data);
        self::assertSame($full, $event->fetch());
        self::assertSame(Webhook::document(), $event->fetch()['data']);
        Http::assertSentCount(1);
    }

    public function testGivesAFullPayloadWithoutAnyRequest(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $events = $this->record([InvoiceAuthorized::class]);
        $event = Webhook::event('invoice.authorized');

        $this->deliver($event)->assertNoContent();

        self::assertInstanceOf(InvoiceAuthorized::class, $events[0]);
        self::assertSame($event, $events[0]->fetch());
        Http::assertNothingSent();
    }

    private function cache(?string $store = null): Cache
    {
        return $this->app?->make(CacheFactory::class)->store($store) ?? throw new \LogicException('The app is not running');
    }

    /**
     * Makes a store of the test's own the one that remembers the deliveries.
     */
    private function useStore(Store $store): void
    {
        $manager = $this->app?->make('cache');
        self::assertInstanceOf(CacheManager::class, $manager);
        $manager->extend('custom', fn(): CacheRepository => new CacheRepository($store));
        $this->config()->set('cache.stores.custom', ['driver' => 'custom']);
        $this->config()->set('flashpi.webhook.deduplication.store', 'custom');
    }

    private function dispatcher(): Dispatcher
    {
        return $this->app?->make(Dispatcher::class) ?? throw new \LogicException('The app is not running');
    }
}
