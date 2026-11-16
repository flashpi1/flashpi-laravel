<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Tests\Console;

use Flashpi\Laravel\Tests\Support\Api;
use Flashpi\Laravel\Tests\TestCase;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `php artisan flashpi:webhook`, against a fake API.
 */
final class WebhookCommandTest extends TestCase
{
    private const URL = 'https://shop.example.com/flashpi/webhook';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app->make(Repository::class)->set('app.url', 'https://shop.example.com');
    }

    public function testRegistersTheEndpointAndShowsItsSecret(): void
    {
        $secret = self::fakeSecret();
        $endpoint = Api::endpoint();
        Http::fake([
            Api::url('/v1/webhook-endpoints/*/secret') => Api::json(['secret' => $secret, 'previousSecretExpiresAt' => null]),
            Api::url('/v1/webhook-endpoints') => static fn(Request $request) => $request->method() === 'GET' ? Api::page([]) : Api::json($endpoint, 201),
        ]);

        $this->command('flashpi:webhook')
            ->expectsOutputToContain("Registered {$endpoint['url']} for the test events ({$endpoint['id']}, full payloads).")
            ->expectsOutputToContain('This command shows the secret only now; the endpoint\'s page under Webhooks in the Flashpi console (https://flashpi.com.ec/app/) shows it again:')
            ->expectsOutput("FLASHPI_WEBHOOK_SECRET={$secret}")
            ->expectsOutputToContain('then run php artisan flashpi:webhook --verify')
            ->assertSuccessful();

        Http::assertSent(fn(Request $request): bool => $request->method() === 'POST'
            && $request->url() === Api::url('/v1/webhook-endpoints')
            && $request->data() === ['url' => self::URL, 'enabledEvents' => ['*'], 'payloadMode' => 'full']);
        Http::assertSent(fn(Request $request): bool => $request->url() === Api::url("/v1/webhook-endpoints/{$endpoint['id']}/secret"));
    }

    public function testRegistersTheChosenEventsWithThinPayloads(): void
    {
        $this->config()->set('app.url', 'https://shop.example.com/');
        $this->config()->set('flashpi.webhook.path', 'hooks/flashpi');
        $endpoint = Api::endpoint(url: 'https://shop.example.com/hooks/flashpi', payloadMode: 'thin');
        Http::fake([
            Api::url('/v1/webhook-endpoints/*/secret') => Api::json(['secret' => self::fakeSecret(), 'previousSecretExpiresAt' => null]),
            Api::url('/v1/webhook-endpoints') => static fn(Request $request) => $request->method() === 'GET' ? Api::page([]) : Api::json($endpoint, 201),
        ]);

        $this->command('flashpi:webhook', ['--event' => ['invoice.authorized', 'invoice.rejected'], '--thin' => true])
            ->expectsOutputToContain('thin payloads')
            ->assertSuccessful();

        Http::assertSent(fn(Request $request): bool => $request->method() === 'POST'
            && $request->data() === [
                'url' => 'https://shop.example.com/hooks/flashpi',
                'enabledEvents' => ['invoice.authorized', 'invoice.rejected'],
                'payloadMode' => 'thin',
            ]);
    }

    public function testRegistersTheUrlOfTheOption(): void
    {
        $this->config()->set('app.url', 'http://localhost');
        $this->config()->set('flashpi.webhook.enabled', false);
        $endpoint = Api::endpoint(url: 'https://abc123.ngrok-free.app/webhooks/flashpi');
        Http::fake([
            Api::url('/v1/webhook-endpoints/*/secret') => Api::json(['secret' => self::fakeSecret(), 'previousSecretExpiresAt' => null]),
            Api::url('/v1/webhook-endpoints') => static fn(Request $request) => $request->method() === 'GET' ? Api::page([]) : Api::json($endpoint, 201),
        ]);

        $this->command('flashpi:webhook', ['--url' => ' https://abc123.ngrok-free.app/webhooks/flashpi '])->assertSuccessful();

        Http::assertSent(fn(Request $request): bool => $request->method() === 'POST'
            && $request['url'] === 'https://abc123.ngrok-free.app/webhooks/flashpi');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unreachableUrls(): iterable
    {
        yield 'http' => ['http://shop.example.com/flashpi/webhook', 'is not an https URL: Flashpi delivers webhooks over HTTPS only'];
        yield 'not a URL' => ['shop.example.com/flashpi/webhook', 'is not a URL'];
        yield 'localhost' => ['https://localhost/flashpi/webhook', 'is not a public address'];
        yield 'a subdomain of localhost' => ['https://shop.localhost/flashpi/webhook', 'is not a public address'];
        yield 'Herd or Valet' => ['https://shop.test/flashpi/webhook', 'is not a public address'];
        yield 'mDNS' => ['https://my-mac.local/flashpi/webhook', 'is not a public address'];
        yield 'Docker' => ['https://host.docker.internal/flashpi/webhook', 'is not a public address'];
        yield 'one label' => ['https://shop/flashpi/webhook', 'is not a public address'];
        yield 'a trailing dot' => ['https://shop.test./flashpi/webhook', 'is not a public address'];
        yield 'loopback' => ['https://127.0.0.1/flashpi/webhook', 'points to a private or local address'];
        yield 'private' => ['https://192.168.1.20/flashpi/webhook', 'points to a private or local address'];
        yield 'shared' => ['https://100.64.0.1/flashpi/webhook', 'points to a private or local address'];
        yield 'IPv6 loopback' => ['https://[::1]/flashpi/webhook', 'points to a private or local address'];
    }

    #[DataProvider('unreachableUrls')]
    public function testRefusesAUrlFlashpiCannotReach(string $url, string $problem): void
    {
        Http::preventStrayRequests();

        $this->command('flashpi:webhook', ['--url' => $url])
            ->expectsOutputToContain($problem)
            ->expectsOutputToContain('Flashpi must reach the URL from the internet')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function testRefusesALocalAppUrl(): void
    {
        $this->config()->set('app.url', 'http://localhost');
        Http::preventStrayRequests();

        $this->command('flashpi:webhook')
            ->expectsOutputToContain('http://localhost/flashpi/webhook is not an https URL')
            ->assertFailed();
    }

    public function testAcceptsAPublicAddress(): void
    {
        $this->config()->set('app.url', 'https://203.0.113.10');
        Http::fake([Api::url('/v1/webhook-endpoints') => Api::page([Api::endpoint(url: 'https://8.8.8.8/flashpi/webhook', status: 'active')])]);

        $this->command('flashpi:webhook', ['--url' => 'https://8.8.8.8/flashpi/webhook'])
            ->expectsOutputToContain('https://8.8.8.8/flashpi/webhook (we_019a1b2c, test events, full payloads) is registered and active.')
            ->assertSuccessful();
    }

    public function testNeedsTheUrlOfYourOwnRouteWhenThePackageRouteIsOff(): void
    {
        $this->config()->set('flashpi.webhook.enabled', false);

        $this->command('flashpi:webhook')
            ->expectsOutputToContain('The package\'s webhook route is turned off (FLASHPI_WEBHOOK_ENABLED): pass the URL of your own route with --url.')
            ->assertFailed();
    }

    public function testNeedsTheAppUrl(): void
    {
        $this->config()->set('app.url', '');

        $this->command('flashpi:webhook')
            ->expectsOutputToContain('APP_URL is not set')
            ->assertFailed();
    }

    public function testNeedsTheRoutePath(): void
    {
        $this->config()->set('flashpi.webhook.path', '/');

        $this->command('flashpi:webhook')
            ->expectsOutputToContain('config flashpi.webhook.path (FLASHPI_WEBHOOK_PATH) is empty')
            ->assertFailed();
    }

    public function testReportsAnActiveEndpointWithoutCreatingAnother(): void
    {
        Http::fake([
            Api::url('/v1/webhook-endpoints*') => Http::sequence()
                ->push(['data' => [Api::endpoint(url: 'https://shop.example.com/other')], 'pagination' => ['nextCursor' => 'c2', 'hasMore' => true]])
                ->push(['data' => [Api::endpoint(status: 'active')], 'pagination' => ['nextCursor' => null, 'hasMore' => false]]),
        ]);

        $this->command('flashpi:webhook', ['--verify' => true])
            ->expectsOutputToContain(self::URL . ' (we_019a1b2c, test events, full payloads) is registered and active.')
            ->expectsOutputToContain('It needs no verification.')
            ->doesntExpectOutputToContain('FLASHPI_WEBHOOK_SECRET=')
            ->assertSuccessful();

        Http::assertSentCount(2);
        Http::assertNotSent(fn(Request $request): bool => $request->method() === 'POST');
    }

    /**
     * @return iterable<string, array{array{enabledEvents?: list<string>, payloadMode?: string, status?: string}, array<string, mixed>, string}>
     */
    public static function otherSettings(): iterable
    {
        yield 'thin for full payloads' => [[], ['--thin' => true], 'it sends full payloads, not thin'];
        yield 'events for every event' => [[], ['--event' => ['invoice.authorized']], 'it receives *, not invoice.authorized'];
        yield 'other events' => [
            ['enabledEvents' => ['invoice.authorized'], 'payloadMode' => 'thin'],
            ['--event' => ['invoice.authorized', 'invoice.rejected'], '--thin' => true],
            'it receives invoice.authorized, not invoice.authorized, invoice.rejected',
        ];
        yield 'both' => [
            [],
            ['--event' => ['invoice.authorized'], '--thin' => true],
            'it sends full payloads, not thin; it receives *, not invoice.authorized',
        ];
        yield 'a pending endpoint, with --verify' => [['status' => 'pending_verification'], ['--thin' => true, '--verify' => true], 'it sends full payloads, not thin'];
    }

    /**
     * The command changes no existing endpoint: options that ask for other settings fail it, so
     * a deploy script sees that they are not in place.
     *
     * @param array{enabledEvents?: list<string>, payloadMode?: string, status?: string} $changes
     * @param array<string, mixed> $options
     */
    #[DataProvider('otherSettings')]
    public function testFailsWhenAnExistingEndpointHasOtherSettings(array $changes, array $options, string $differences): void
    {
        $this->config()->set('flashpi.webhook.secret', self::fakeSecret());
        $mode = $changes['payloadMode'] ?? 'full';
        Http::fake([Api::url('/v1/webhook-endpoints') => Api::page([[...Api::endpoint(status: 'active'), ...$changes]])]);

        $this->command('flashpi:webhook', $options)
            ->expectsOutputToContain(self::URL . " (we_019a1b2c, test events, {$mode} payloads) is already registered with other settings: {$differences}.")
            ->expectsOutputToContain("The command changes no existing endpoint: change it in the Flashpi console, or with Flashpi::webhookEndpoints()->update('we_019a1b2c', [...]).")
            ->doesntExpectOutputToContain('is registered and')
            ->assertFailed();

        // The list only: nothing created, updated or verified.
        Http::assertSentCount(1);
    }

    /**
     * @return iterable<string, array{array{enabledEvents?: list<string>, payloadMode?: string}, array<string, mixed>}>
     */
    public static function sameSettings(): iterable
    {
        yield 'no options' => [[], []];
        yield 'every event' => [[], ['--event' => ['*']]];
        yield 'thin for thin payloads' => [['payloadMode' => 'thin'], ['--thin' => true]];
        yield 'the same events in another order, repeated' => [
            ['enabledEvents' => ['invoice.rejected', 'invoice.authorized'], 'payloadMode' => 'thin'],
            ['--event' => ['invoice.authorized', 'invoice.rejected', 'invoice.authorized'], '--thin' => true],
        ];
        // --thin is a flag: without it, nothing is asked of the payload mode.
        yield 'no --thin for thin payloads' => [['payloadMode' => 'thin'], ['--event' => ['*']]];
    }

    /**
     * @param array{enabledEvents?: list<string>, payloadMode?: string} $changes
     * @param array<string, mixed> $options
     */
    #[DataProvider('sameSettings')]
    public function testReportsAnExistingEndpointWithTheSameSettings(array $changes, array $options): void
    {
        $mode = $changes['payloadMode'] ?? 'full';
        Http::fake([Api::url('/v1/webhook-endpoints') => Api::page([[...Api::endpoint(status: 'active'), ...$changes]])]);

        $this->command('flashpi:webhook', $options)
            ->expectsOutputToContain(self::URL . " (we_019a1b2c, test events, {$mode} payloads) is registered and active.")
            ->assertSuccessful();

        Http::assertSentCount(1);
    }

    public function testReportsADisabledEndpoint(): void
    {
        Http::fake([Api::url('/v1/webhook-endpoints') => Api::page([Api::endpoint(status: 'disabled')])]);

        $this->command('flashpi:webhook')
            ->expectsOutputToContain('is registered but disabled: enable it again in the Flashpi console.')
            ->assertSuccessful();
    }

    public function testReportsAnEndpointWaitingForVerification(): void
    {
        Http::fake([Api::url('/v1/webhook-endpoints') => Api::page([Api::endpoint()])]);

        $this->command('flashpi:webhook')
            ->expectsOutputToContain('is registered and waiting for verification.')
            ->expectsOutputToContain('run php artisan flashpi:webhook --verify')
            ->expectsOutputToContain("Its secret is on the endpoint's page under Webhooks in the Flashpi console (https://flashpi.com.ec/app/), or in Flashpi::webhookEndpoints()->getSecret('we_019a1b2c').")
            ->doesntExpectOutputToContain('FLASHPI_WEBHOOK_SECRET=')
            ->assertSuccessful();

        Http::assertNotSent(fn(Request $request): bool => $request->method() === 'POST');
    }

    public function testSendsTheChallengeAgain(): void
    {
        $this->config()->set('flashpi.webhook.secret', self::fakeSecret());
        $endpoint = Api::endpoint();
        Http::fake([
            Api::url('/v1/webhook-endpoints') => Api::page([$endpoint]),
            Api::url("/v1/webhook-endpoints/{$endpoint['id']}/verify") => Api::json($endpoint),
        ]);

        $this->command('flashpi:webhook', ['--verify' => true])
            ->doesntExpectOutputToContain('FLASHPI_WEBHOOK_SECRET is not set here')
            ->expectsOutputToContain('Flashpi sent the verification challenge to ' . self::URL . ' again.')
            ->assertSuccessful();

        Http::assertSent(fn(Request $request): bool => $request->method() === 'POST'
            && $request->url() === Api::url("/v1/webhook-endpoints/{$endpoint['id']}/verify"));
    }

    public function testWarnsWhenTheSecretIsNotSetHere(): void
    {
        $endpoint = Api::endpoint();
        Http::fake([
            Api::url('/v1/webhook-endpoints') => Api::page([$endpoint]),
            Api::url("/v1/webhook-endpoints/{$endpoint['id']}/verify") => Api::json($endpoint),
        ]);

        $this->command('flashpi:webhook', ['--verify' => true])
            ->expectsOutputToContain('FLASHPI_WEBHOOK_SECRET is not set here')
            ->expectsOutputToContain("Flashpi::webhookEndpoints()->getSecret('we_019a1b2c')")
            ->assertSuccessful();
    }

    public function testSaysWhereTheSecretOfAnActiveEndpointIsWhenItIsNotSetHere(): void
    {
        Http::fake([Api::url('/v1/webhook-endpoints') => Api::page([Api::endpoint(status: 'active')])]);

        $this->command('flashpi:webhook')
            ->expectsOutputToContain('is registered and active.')
            ->expectsOutputToContain("FLASHPI_WEBHOOK_SECRET is not set here. Its secret is on the endpoint's page under Webhooks in the Flashpi console (https://flashpi.com.ec/app/), or in Flashpi::webhookEndpoints()->getSecret('we_019a1b2c').")
            ->assertSuccessful();

        $this->config()->set('flashpi.webhook.secret', self::fakeSecret());
        $this->command('flashpi:webhook')
            ->expectsOutputToContain('is registered and active.')
            ->doesntExpectOutputToContain('getSecret(')
            ->assertSuccessful();
    }

    public function testCannotVerifyAnEndpointThatDoesNotExist(): void
    {
        Http::fake([Api::url('/v1/webhook-endpoints') => Api::page([])]);

        $this->command('flashpi:webhook', ['--verify' => true])
            ->expectsOutputToContain('No webhook endpoint is registered for ' . self::URL . ' in the test environment')
            ->assertFailed();

        Http::assertNotSent(fn(Request $request): bool => $request->method() === 'POST');
    }

    public function testReportsTheErrorsOfTheApi(): void
    {
        Http::fake([
            Api::url('/v1/webhook-endpoints') => static fn(Request $request) => $request->method() === 'GET'
                ? Api::page([])
                : Api::problem(403, 'key_scope_mismatch'),
        ]);

        $this->command('flashpi:webhook')
            ->expectsOutputToContain('403 key_scope_mismatch: Detail of key_scope_mismatch (request req_problem)')
            ->assertFailed();
    }

    public function testFailsWithoutAKey(): void
    {
        $this->config()->set('flashpi.api_key', null);
        Http::preventStrayRequests();

        $this->command('flashpi:webhook')
            ->expectsOutputToContain('Missing Flashpi API key')
            ->assertFailed();
    }
}
