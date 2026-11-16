<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Tests;

use Flashpi\Exception\WebhookVerificationException;
use Flashpi\Flashpi as Client;
use Flashpi\Laravel\Facades\Flashpi;
use Flashpi\Laravel\Tests\Support\Api;
use Flashpi\Resources\Invoices;
use Flashpi\Resources\StatusResource;
use Flashpi\Webhooks;
use Illuminate\Support\Facades\Http;

/**
 * The facade: the client's resources as methods, and the webhook helpers without an API key.
 */
final class FacadeTest extends TestCase
{
    public function testReturnsEveryResourceOfTheClient(): void
    {
        $properties = get_object_vars($this->client());
        self::assertNotEmpty($properties);
        foreach ($properties as $name => $value) {
            $name = (string) $name;
            self::assertSame($value, Flashpi::{$name}(), $name);
        }
        self::assertInstanceOf(Invoices::class, Flashpi::invoices());
        self::assertInstanceOf(StatusResource::class, Flashpi::status());
        self::assertInstanceOf(Webhooks::class, Flashpi::webhooks());
        self::assertSame('test', Flashpi::environment());
    }

    /**
     * A resource the SDK adds needs a @method tag, so that IDEs and PHPStan know it.
     */
    public function testDeclaresEveryResourceOfTheClient(): void
    {
        $docblock = (string) (new \ReflectionClass(Flashpi::class))->getDocComment();
        preg_match_all('/@method static \S+ (\w+)\(\)/', $docblock, $matches);
        $public = array_map(
            static fn(\ReflectionProperty $property): string => $property->getName(),
            (new \ReflectionClass(Client::class))->getProperties(\ReflectionProperty::IS_PUBLIC),
        );
        sort($public);
        $declared = $matches[1];
        sort($declared);
        self::assertSame($public, $declared);
    }

    public function testSendsThroughTheResource(): void
    {
        Http::fake([Api::url('/v1/status') => Api::json(['status' => 'operational'])]);

        self::assertSame(['status' => 'operational'], Flashpi::status()->get());
    }

    public function testCallsTheOtherPublicMethodsOfTheClient(): void
    {
        // @phpstan-ignore staticMethod.notFound
        self::assertSame(['environment' => 'test', 'baseUrl' => Client::DEFAULT_BASE_URL], Flashpi::__debugInfo());
    }

    public function testRefusesArgumentsForAResource(): void
    {
        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage("Flashpi::invoices() takes no arguments: it returns the client's invoices");
        // @phpstan-ignore arguments.count
        Flashpi::invoices('inv_1');
    }

    public function testRefusesWhatTheClientLacks(): void
    {
        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Flashpi has no resource or method named invoice()');
        // @phpstan-ignore staticMethod.notFound
        Flashpi::invoice();
    }

    public function testKeepsThePrivatePartsOfTheClientPrivate(): void
    {
        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Flashpi has no resource or method named engine()');
        // @phpstan-ignore staticMethod.notFound
        Flashpi::engine();
    }

    public function testNeedsAFacadeRoot(): void
    {
        $this->app?->instance(Client::class, 'not a client');
        Flashpi::clearResolvedInstances();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('A facade root has not been set.');
        Flashpi::invoices();
    }

    public function testUnwrapsAWebhookWithoutAnApiKey(): void
    {
        $this->config()->set('flashpi.api_key', null);
        $secret = self::fakeSecret();
        $body = (string) json_encode(['id' => 'evt_1', 'type' => 'webhook_endpoint.verification', 'environment' => 'test', 'createdAt' => '2026-11-16T10:00:00Z', 'data' => ['challenge' => 'abc']]);

        $event = Flashpi::unwrapWebhook($body, self::signed($body, $secret), $secret);
        Flashpi::verifyWebhookSignature($body, self::signed($body, $secret), $secret);

        self::assertSame('webhook_endpoint.verification', $event['type']);
        self::assertFalse($this->app?->resolved(Client::class));
    }

    public function testRefusesAWebhookWithAnotherSignature(): void
    {
        $body = '{"id":"evt_1"}';

        try {
            Flashpi::verifyWebhookSignature($body, self::signed($body, self::fakeSecret()), self::fakeSecret());
            self::fail('The signature was accepted');
        } catch (WebhookVerificationException $error) {
            self::assertSame(WebhookVerificationException::NO_MATCHING_SIGNATURE, $error->reason);
        }
    }

    /**
     * The Standard Webhooks headers of a delivery signed with a secret.
     *
     * @return array<string, string>
     */
    private static function signed(string $body, string $secret): array
    {
        $id = 'msg_' . bin2hex(random_bytes(8));
        $timestamp = (string) time();
        $key = base64_decode(substr($secret, \strlen('whsec_')), true);
        $signature = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", (string) $key, true));

        return ['webhook-id' => $id, 'webhook-timestamp' => $timestamp, 'webhook-signature' => "v1,{$signature}"];
    }
}
