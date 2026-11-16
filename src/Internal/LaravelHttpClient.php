<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Internal;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\TransferStats;
use GuzzleHttp\Utils;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The Guzzle client the SDK sends its requests with: Laravel's HTTP client underneath, so
 * Http::fake(), Http::assertSent(), Http::preventStrayRequests(), the HTTP client's events
 * (RequestSending, ResponseReceived, ConnectionFailed) and the global middleware of the Http
 * facade see every call of the SDK.
 *
 * Each request goes through the handler stack of a new pending request of the HTTP client
 * factory the container holds at that moment, as Http::get() would: a pending request copies
 * the fakes of its factory when it is created, so a stack built once, when the client is first
 * resolved, would miss an Http::fake() that comes after it and send the request for real. The
 * connections are the client's own: one Guzzle handler (curl when available) is shared by its
 * requests, so they are reused.
 *
 * The stack records the answers and dispatches RequestSending; what PendingRequest::send() does
 * after it is done here too: ResponseReceived for an answer, and for a request that failed, the
 * record Http::assertSent() reads and ConnectionFailed when no answer came, so Telescope and the
 * tools that pair RequestSending with an end see the SDK's calls, retries included.
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
                $factory = $container->make(Factory::class);
                $sent = null;
                $stack = $factory->createPendingRequest()
                    ->beforeSending(static function (Request $sending) use (&$sent): void {
                        $sent = $sending;
                    })
                    ->setHandler($transport)
                    ->buildHandlerStack();

                try {
                    // Options that only PendingRequest::send() sets and Laravel's handlers read
                    // (Laravel 12 without a default): the request's form data, which the SDK never
                    // sends (Http::assertSent() reads its JSON bodies from the request itself), and
                    // the callback of the transfer's statistics, which Http::fake() calls.
                    $promise = Create::promiseFor($stack($request, $options + [
                        'laravel_data' => [],
                        'on_stats' => static function (TransferStats $stats): void {},
                    ]));
                } catch (\Throwable $error) {
                    // Http::fake() waits for the answer it fakes, so Http::failedConnection() throws here.
                    $promise = Create::rejectionFor($error);
                }

                return $promise->then(
                    // mixed, narrowed here: Guzzle 8's promises are generic and Guzzle 7's are not,
                    // so a PHPDoc type for the promise would fail PHPStan with one of them.
                    static function (mixed $response) use ($factory, &$sent): ResponseInterface {
                        \assert($response instanceof ResponseInterface);
                        if ($sent instanceof Request) {
                            $factory->getDispatcher()?->dispatch(new ResponseReceived($sent, new Response($response)));
                        }

                        return $response;
                    },
                    // What PendingRequest::send() does with a request that failed in the transport (a
                    // StrayRequestException is not one, and send() does not record it either).
                    static function (mixed $reason) use ($factory): RejectedPromise {
                        if ($reason instanceof NetworkExceptionInterface || $reason instanceof RequestExceptionInterface) {
                            $failed = new Request($reason->getRequest());
                            // Guzzle 8's ResponseException, Guzzle 7's RequestException with an answer.
                            $response = method_exists($reason, 'getResponse') ? $reason->getResponse() : null;
                            if ($response instanceof ResponseInterface) {
                                $factory->recordRequestResponsePair($failed, new Response($response));
                            } else {
                                $factory->recordRequestResponsePair($failed, null);
                                $factory->getDispatcher()?->dispatch(new ConnectionFailed($failed, new ConnectionException($reason->getMessage(), 0, $reason)));
                            }
                        }

                        return new RejectedPromise($reason);
                    },
                );
            },
            'allow_redirects' => false,
            'http_errors' => false,
        ]);
    }
}
