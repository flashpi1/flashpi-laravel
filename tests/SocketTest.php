<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Tests;

use Flashpi\Exception\ApiException;
use Flashpi\Exception\TimeoutException;
use Flashpi\Laravel\Tests\Support\Api;
use Flashpi\Laravel\Tests\Support\LocalServer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * The client of the container over real sockets (a local `php -S` server): Guzzle's own handler
 * under Laravel's HTTP client, with the SDK's timeouts and without redirects.
 */
final class SocketTest extends TestCase
{
    public static function tearDownAfterClass(): void
    {
        LocalServer::stop();
        parent::tearDownAfterClass();
    }

    public function testDownloadsALargeRide(): void
    {
        $this->config()->set('flashpi.base_url', LocalServer::url());
        Http::record();

        $pdf = $this->client()->documents->pdf(Api::ID);

        self::assertSame(LocalServer::pdf(3_000_000), $pdf);
        Http::assertSent(fn(Request $request): bool => $request->url() === LocalServer::url() . '/v1/documents/' . Api::ID . '/pdf');
    }

    public function testTimesOutASlowAnswer(): void
    {
        $this->config()->set('flashpi.base_url', LocalServer::url() . '/slow');
        $this->config()->set('flashpi.timeout', '0.5');
        $this->config()->set('flashpi.max_retries', '0');
        $started = microtime(true);

        try {
            $this->client()->status->get();
            self::fail('The request did not time out');
        } catch (TimeoutException $error) {
            self::assertSame('GET ' . LocalServer::url() . '/slow/v1/status timed out after 0.5 seconds', $error->getMessage());
        }

        self::assertLessThan(1.5, microtime(true) - $started);
    }

    public function testFollowsNoRedirect(): void
    {
        $this->config()->set('flashpi.base_url', LocalServer::url() . '/redirect');

        try {
            $this->client()->status->get();
            self::fail('The redirect was followed');
        } catch (ApiException $error) {
            self::assertSame(302, $error->status);
            self::assertSame('https://example.com/v1/status', $error->header('Location'));
        }
    }
}
