<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Tests\Console;

use Flashpi\Laravel\Tests\Support\Api;
use Flashpi\Laravel\Tests\TestCase;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * `php artisan flashpi:check`, against a fake API.
 */
final class CheckCommandTest extends TestCase
{
    public function testReportsATestKeyThatWorks(): void
    {
        $this->config()->set('flashpi.webhook.secret', self::fakeSecret());
        Http::fake([
            Api::url('/v1/status') => Api::json(['status' => 'operational']),
            Api::url('/v1/events*') => Api::page([]),
        ]);

        $this->command('flashpi:check')
            ->expectsOutputToContain('test (fp_test_ key: the SRI\'s test environment)')
            ->expectsOutputToContain(Api::BASE_URL)
            ->expectsOutputToContain('operational')
            ->expectsOutputToContain('accepted')
            ->doesntExpectOutputToContain('FLASHPI_WEBHOOK_SECRET is not set')
            ->assertSuccessful();

        Http::assertSent(fn(Request $request): bool => $request->url() === Api::url('/v1/events?limit=1')
            && $request->header('Authorization') === ["Bearer {$this->apiKey}"]);
    }

    public function testReportsALiveKeyAndItsBaseUrl(): void
    {
        $this->config()->set('flashpi.api_key', self::fakeKey('live'));
        $this->config()->set('flashpi.base_url', 'https://api.staging.flashpi.com.ec');
        Http::fake([
            'https://api.staging.flashpi.com.ec/v1/status' => Api::json(['status' => 'operational']),
            'https://api.staging.flashpi.com.ec/v1/events*' => Api::page([]),
        ]);

        $this->command('flashpi:check')
            ->expectsOutputToContain('live (fp_live_ key: real documents in production)')
            ->expectsOutputToContain('https://api.staging.flashpi.com.ec')
            ->assertSuccessful();
    }

    public function testWarnsWhenTheWebhookSecretIsNotSet(): void
    {
        Http::fake([
            Api::url('/v1/status') => Api::json(['status' => 'operational']),
            Api::url('/v1/events*') => Api::page([]),
        ]);

        $this->command('flashpi:check')
            // A line that holds the text of two expectations meets the first one only.
            ->expectsOutputToContain('FLASHPI_WEBHOOK_SECRET is not set: run php artisan flashpi:webhook')
            ->expectsOutputToContain('not set')
            ->assertSuccessful();
    }

    public function testFailsWithoutAKey(): void
    {
        $this->config()->set('flashpi.api_key', null);
        Http::preventStrayRequests();

        $this->command('flashpi:check')
            ->expectsOutputToContain('Missing Flashpi API key: set FLASHPI_API_KEY in your .env file')
            ->assertFailed();
    }

    public function testFailsWithAMalformedKey(): void
    {
        $this->config()->set('flashpi.api_key', 'fp_prod_123');
        Http::preventStrayRequests();

        $this->command('flashpi:check')
            ->expectsOutputToContain('The API key must start with fp_test_ or fp_live_')
            ->assertFailed();
    }

    public function testFailsWhenTheApiDoesNotAnswer(): void
    {
        $this->config()->set('flashpi.max_retries', 0);
        Http::fake(static fn() => throw new ConnectException('Could not resolve host', new PsrRequest('GET', Api::url('/v1/status'))));

        $this->command('flashpi:check')
            ->expectsOutputToContain('The Flashpi API did not answer: GET ' . Api::url('/v1/status') . ' failed: Could not resolve host')
            ->assertFailed();
    }

    public function testFailsWhenTheApiRefusesTheKey(): void
    {
        Http::fake([
            Api::url('/v1/status') => Api::json(['status' => 'operational']),
            Api::url('/v1/events*') => Api::problem(401, 'invalid_api_key'),
        ]);

        $this->command('flashpi:check')
            ->expectsOutputToContain('The API refused the key: 401 invalid_api_key: Detail of invalid_api_key (request req_problem)')
            ->expectsOutputToContain('refused')
            ->assertFailed();
    }

    public function testFailsWhenTheKeyIsNotAllowedHere(): void
    {
        Http::fake([
            Api::url('/v1/status') => Api::json(['status' => 'operational']),
            Api::url('/v1/events*') => Api::problem(403, 'ip_not_allowed'),
        ]);

        $this->command('flashpi:check')
            ->expectsOutputToContain('The API refused the key: 403 ip_not_allowed')
            ->assertFailed();
    }

    public function testFailsWhenTheKeyCannotBeChecked(): void
    {
        $this->config()->set('flashpi.max_retries', 0);
        Http::fake([
            Api::url('/v1/status') => Api::json(['status' => 'operational']),
            Api::url('/v1/events*') => Api::problem(500, 'internal_error'),
        ]);

        $this->command('flashpi:check')
            ->expectsOutputToContain('Could not check the key: 500 internal_error')
            ->assertFailed();
    }
}
