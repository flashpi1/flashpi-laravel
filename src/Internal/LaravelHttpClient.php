<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Internal;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\TransferStats;
use GuzzleHttp\Utils;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory;
use Psr\Http\Message\RequestInterface;

/**
 * The Guzzle client the SDK sends its requests with: Laravel's HTTP client underneath, so
 * Http::fake(), Http::assertSent(), Http::preventStrayRequests(), the RequestSending event and
 * the global middleware of the Http facade see every call of the SDK.
 *
 * Each request goes through the handler stack of a new pending request of the HTTP client
 * factory the container holds at that moment, as Http::get() would: a pending request copies
 * the fakes of its factory when it is created, so a stack built once, when the client is first
 * resolved, would miss an Http::fake() that comes after it and send the request for real. The
 * connections are the client's own: one Guzzle handler (curl when available) is shared by its
 * requests, so they are reused.
 *
 * The SDK sets its own options on every request (no redirects, no exceptions for error
 * statuses, its timeouts), and the Guzzle client passes them through the stack. The options of
 * a pending request (Http::globalOptions(), Laravel's default 30 s timeout) do not apply: they
 * belong to PendingRequest::send(), which the SDK does not use.
 *
 * @internal
 */
final class LaravelHttpClient
{
    private function __construct() {}

    /**
     * @param (callable(RequestInterface, array<string, mixed>): PromiseInterface)|null $handler
     *        The handler that sends the requests (default: Guzzle's choice, curl when
     *        available). Tests pass Guzzle's MockHandler.
     */
    public static function create(Container $container, ?callable $handler = null): ClientInterface
    {
        $transport = $handler ?? Utils::chooseHandler();

        return new Client([
            'handler' => static function (RequestInterface $request, array $options) use ($container, $transport): PromiseInterface {
                $stack = $container->make(Factory::class)
                    ->createPendingRequest()
                    ->setHandler($transport)
                    ->buildHandlerStack();

                // Options that only PendingRequest::send() sets and Laravel's handlers read (Laravel
                // 12 without a default): the request's form data, which the SDK never sends
                // (Http::assertSent() reads its JSON bodies from the request itself), and the
                // callback of the transfer's statistics, which Http::fake() calls.
                return Create::promiseFor($stack($request, $options + [
                    'laravel_data' => [],
                    'on_stats' => static function (TransferStats $stats): void {},
                ]));
            },
            'allow_redirects' => false,
            'http_errors' => false,
        ]);
    }
}
