<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Tests;

use Flashpi\Exception\ApiException;
use Flashpi\Exception\ConnectionException;
use Flashpi\Exception\FlashpiException;
use Flashpi\Flashpi;
use Flashpi\Laravel\Facades\Flashpi as FlashpiFacade;
use Flashpi\Laravel\Internal\LaravelHttpClient;
use Flashpi\Laravel\Tests\Support\Api;
use Flashpi\Version;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;

/**
 * The SDK sends through Laravel's HTTP client: Http::fake(), Http::assertSent(),
 * Http::preventStrayRequests(), the global middleware and the HTTP client's events see its
 * calls, and the SDK's own options (no redirects, its timeouts, its retries) still apply.
 */
final class HttpClientTest extends TestCase
{
    public function testFakesTheCallsOfTheFacade(): void
    {
        Http::fake([Api::url('/v1/invoices') => Api::json(['id' => Api::ID, 'status' => 'processing'], 202)]);

        $invoice = FlashpiFacade::invoices()->create(self::invoice());

        self::assertSame(Api::ID, $invoice['id']);
        Http::assertSent(fn(Request $request): bool => $request->method() === 'POST'
            && $request->url() === Api::url('/v1/invoices')
            && $request['issuerId'] === Api::ID
            && data_get($request->data(), 'items.0.unitPrice') === '1.00'
            && $request->hasHeader('Idempotency-Key'));
        Http::assertSentCount(1);
    }

    public function testFakesTheCallsOfAnInjectedClient(): void
    {
        Http::fake([Api::url('/v1/documents/*') => Api::json(['id' => Api::ID, 'status' => 'authorized'])]);

        $document = $this->app?->call(static fn(Flashpi $flashpi): array => $flashpi->documents->get(Api::ID));

        self::assertSame(['id' => Api::ID, 'status' => 'authorized'], $document);
        Http::assertSent(fn(Request $request): bool => $request->method() === 'GET'
            && $request->url() === Api::url('/v1/documents/' . Api::ID)
            && $request->header('User-Agent') === ['flashpi-php/' . Version::VERSION]);
    }

    /**
     * A pending request copies the fakes of its factory when it is created: the client must use
     * a new one for every request, or a fake made after the client was resolved (a test whose
     * app resolved it while booting) would let the request go out for real.
     */
    public function testSeesFakesMadeAfterTheClientWasResolved(): void
    {
        $flashpi = $this->client();
        Http::fake([Api::url('/v1/status') => Api::json(['status' => 'operational'])]);

        self::assertSame(['status' => 'operational'], $flashpi->status->get());
        Http::assertSentCount(1);

        Http::fake([Api::url('/v1/documents/*') => Api::problem(404, 'not_found')]);
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('404 not_found: Detail of not_found');
        $flashpi->documents->get(Api::ID);
    }

    public function testRefusesStrayRequests(): void
    {
        Http::preventStrayRequests();

        try {
            FlashpiFacade::status()->get();
            self::fail('The request went out');
        } catch (FlashpiException $error) {
            self::assertStringContainsString('StrayRequestException', $error->getMessage());
            self::assertStringContainsString(Api::url('/v1/status'), $error->getMessage());
            self::assertStringNotContainsString($this->apiKey, $error->getMessage());
        }
    }

    public function testRunsTheGlobalMiddlewareAndTheEventsOfTheHttpClient(): void
    {
        Event::fake([RequestSending::class]);
        Http::globalRequestMiddleware(static fn(RequestInterface $request): RequestInterface => $request->withHeader('X-App', 'shop'));
        Http::fake(['*' => Api::json(['status' => 'operational'])]);

        FlashpiFacade::status()->get();

        Http::assertSent(fn(Request $request): bool => $request->header('X-App') === ['shop']);
        Event::assertDispatched(RequestSending::class, fn(RequestSending $event): bool => $event->request->url() === Api::url('/v1/status'));
    }

    /**
     * What PendingRequest::send() dispatches after the stack: Telescope and the tools that pair
     * RequestSending with an end listen to ResponseReceived and ConnectionFailed.
     */
    public function testDispatchesResponseReceivedForAnAnswer(): void
    {
        Event::fake([RequestSending::class, ResponseReceived::class, ConnectionFailed::class]);
        Http::fake([Api::url('/v1/invoices') => Api::problem(422, 'total_mismatch')]);

        try {
            FlashpiFacade::invoices()->create(self::invoice());
            self::fail('The 422 was not thrown');
        } catch (ApiException) {
        }

        Event::assertDispatchedTimes(RequestSending::class, 1);
        Event::assertDispatchedTimes(ResponseReceived::class, 1);
        Event::assertDispatched(ResponseReceived::class, fn(ResponseReceived $event): bool => $event->request->method() === 'POST'
            && $event->request->url() === Api::url('/v1/invoices')
            && $event->request->hasHeader('Idempotency-Key')
            && $event->response->status() === 422
            && $event->response->json('code') === 'total_mismatch');
        Event::assertNotDispatched(ConnectionFailed::class);
    }

    public function testRecordsAndReportsAFailedConnectionOfEveryAttempt(): void
    {
        Event::fake([ResponseReceived::class, ConnectionFailed::class]);
        $this->config()->set('flashpi.max_retries', 1);
        Http::fake(['*' => Http::failedConnection('Could not resolve host')]);

        try {
            FlashpiFacade::status()->get();
            self::fail('The failed connection was not thrown');
        } catch (ConnectionException $error) {
            self::assertStringContainsString('Could not resolve host', $error->getMessage());
        }

        Http::assertSentCount(2);
        Http::assertSent(fn(Request $request, ?ClientResponse $response): bool => $request->url() === Api::url('/v1/status') && $response === null);
        Event::assertDispatchedTimes(ConnectionFailed::class, 2);
        Event::assertDispatched(ConnectionFailed::class, fn(ConnectionFailed $event): bool => $event->request->url() === Api::url('/v1/status')
            && $event->exception->getMessage() === 'Could not resolve host'
            && $event->exception->getPrevious() instanceof ConnectException);
        Event::assertNotDispatched(ResponseReceived::class);
    }

    public function testRecordsAndReportsAConnectionFailureOfTheHandler(): void
    {
        Event::fake([ResponseReceived::class, ConnectionFailed::class]);
        $transport = new MockHandler([
            new ConnectException('Connection refused', new PsrRequest('GET', Api::url('/v1/status'))),
        ]);
        Http::record();

        try {
            $this->clientOver($transport, 0)->status->get();
            self::fail('The failed connection was not thrown');
        } catch (ConnectionException) {
        }

        Http::assertSentCount(1);
        Http::assertSent(fn(Request $request, ?ClientResponse $response): bool => $request->url() === Api::url('/v1/status') && $response === null);
        Event::assertDispatchedTimes(ConnectionFailed::class, 1);
        Event::assertDispatched(ConnectionFailed::class, fn(ConnectionFailed $event): bool => $event->exception->getMessage() === 'Connection refused');
        Event::assertNotDispatched(ResponseReceived::class);
    }

    /**
     * A handler that rejects with an answer (Guzzle's BadResponseException, which a client of the
     * caller's may throw): send() records the answer and dispatches no ConnectionFailed, and the
     * SDK reads the answer as the API's.
     */
    public function testRecordsTheAnswerOfARejectionThatCarriesOne(): void
    {
        Event::fake([ResponseReceived::class, ConnectionFailed::class]);
        $request = new PsrRequest('GET', Api::url('/v1/status'));
        $transport = new MockHandler([
            new ServerException('Server error', $request, new Response(503, ['Content-Type' => 'application/problem+json'], '{"code":"unavailable"}')),
        ]);
        Http::record();

        try {
            $this->clientOver($transport, 0)->status->get();
            self::fail('The 503 was not thrown');
        } catch (ApiException $error) {
            self::assertSame(503, $error->status);
        }

        Http::assertSentCount(1);
        Http::assertSent(fn(Request $request, ?ClientResponse $response): bool => $response?->status() === 503);
        Event::assertNotDispatched(ConnectionFailed::class);
        Event::assertNotDispatched(ResponseReceived::class);
    }

    /**
     * The README's advice for certificate uploads: the SDK builds the multipart body itself, so
     * Laravel's form helpers see none of its parts, and tests assert on the body.
     */
    public function testShowsACertificateUploadAsAMultipartBody(): void
    {
        Http::fake([Api::url('/v1/issuers/iss_1/certificates') => Api::json(['id' => 'cert_1', 'status' => 'accepted'], 201)]);

        FlashpiFacade::issuers()->certificates->upload('iss_1', ['file' => 'the bytes of the p12', 'password' => 'p12-password']);

        Http::assertSent(fn(Request $request): bool => $request->isMultipart()
            && $request->url() === Api::url('/v1/issuers/iss_1/certificates')
            && str_contains($request->body(), 'the bytes of the p12'));
        Http::assertSent(fn(Request $request): bool => !$request->hasFile('file')
            && $request->data() === []
            && !isset($request['password']));
    }

    public function testRetriesWithTheSameIdempotencyKey(): void
    {
        Http::fake([
            Api::url('/v1/invoices') => Http::sequence()
                ->push((string) json_encode(['code' => 'internal_error']), 503, ['Retry-After' => '0', 'Content-Type' => 'application/problem+json'])
                ->push(['id' => Api::ID, 'status' => 'processing'], 202),
        ]);

        $invoice = FlashpiFacade::invoices()->create(self::invoice(), ['idempotencyKey' => 'order-42']);

        self::assertSame(Api::ID, $invoice['id']);
        Http::assertSentCount(2);
        Http::assertNotSent(fn(Request $request): bool => $request->header('Idempotency-Key') !== ['order-42']);
    }

    public function testThrowsTheApiErrorsOfFakedAnswers(): void
    {
        Http::fake(['*' => Api::problem(422, 'total_mismatch')]);

        try {
            FlashpiFacade::invoices()->create(self::invoice());
            self::fail('The 422 was not thrown');
        } catch (ApiException $error) {
            self::assertSame(422, $error->status);
            self::assertSame('total_mismatch', $error->errorCode);
            self::assertSame('req_problem', $error->requestId);
        }
    }

    public function testSetsTheSdkOptionsOnEveryRequest(): void
    {
        $this->config()->set('flashpi.timeout', '20');
        $sent = [];
        Http::fake(function (Request $request, array $options) use (&$sent) {
            $sent[] = $options;

            return Api::json(['status' => 'operational']);
        });

        FlashpiFacade::status()->get();
        FlashpiFacade::status()->get(['timeout' => 5]);

        self::assertCount(2, $sent);
        foreach ([20.0, 5.0] as $index => $timeout) {
            self::assertFalse($sent[$index]['allow_redirects'] ?? null);
            self::assertFalse($sent[$index]['http_errors'] ?? null);
            self::assertFalse($sent[$index]['stream'] ?? null);
            self::assertSame($timeout, $sent[$index]['timeout'] ?? null);
            self::assertSame($timeout, $sent[$index]['connect_timeout'] ?? null);
            self::assertSame($timeout, $sent[$index]['read_timeout'] ?? null);
        }
    }

    /**
     * With a real handler underneath (Guzzle's MockHandler in place of curl), Laravel's stack
     * runs Guzzle's middleware with the SDK's options: a redirect is not followed.
     */
    public function testFollowsNoRedirect(): void
    {
        $transport = new MockHandler([
            new Response(302, ['Location' => 'https://elsewhere.example.com/v1/status']),
            new Response(200, ['Content-Type' => 'application/json'], '{"status":"operational"}'),
        ]);

        try {
            $this->clientOver($transport)->status->get();
            self::fail('The redirect was followed');
        } catch (ApiException $error) {
            self::assertSame(302, $error->status);
        }

        self::assertCount(1, $transport);
        self::assertSame(1.0, $transport->getLastOptions()['timeout'] ?? null);
        self::assertFalse($transport->getLastOptions()['allow_redirects'] ?? null);
    }

    public function testRetriesNetworkFailuresOfTheHandler(): void
    {
        $transport = new MockHandler([
            new ConnectException('Connection refused', new PsrRequest('GET', Api::url('/v1/status'))),
            new Response(200, ['Content-Type' => 'application/json'], '{"status":"operational"}'),
        ]);
        Http::record();

        self::assertSame(['status' => 'operational'], $this->clientOver($transport, 1)->status->get());
        self::assertCount(0, $transport);
        Http::assertSentCount(2);
        Http::assertSent(fn(Request $request, ?ClientResponse $response): bool => $response === null);
        Http::assertSent(fn(Request $request, ?ClientResponse $response): bool => $response?->status() === 200);
    }

    public function testReportsANetworkFailureAfterTheRetries(): void
    {
        $transport = new MockHandler([
            new ConnectException('Connection refused', new PsrRequest('GET', Api::url('/v1/status'))),
        ]);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('GET ' . Api::url('/v1/status') . ' failed: Connection refused');
        $this->clientOver($transport, 0)->status->get();
    }

    public function testDownloadsTheRideAndTheXml(): void
    {
        $pdf = '%PDF-1.7' . random_bytes(300_000) . '%%EOF';
        $xml = '<?xml version="1.0" encoding="UTF-8"?><autorizacion><estado>AUTORIZADO</estado></autorizacion>';
        Http::fake([
            Api::url('/v1/documents/' . Api::ID . '/pdf') => Http::response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Length' => (string) \strlen($pdf)]),
            Api::url('/v1/documents/' . Api::ID . '/xml') => Http::response($xml, 200, ['Content-Type' => 'application/xml', 'Flashpi-Xml-Kind' => 'authorized']),
        ]);

        self::assertSame($pdf, FlashpiFacade::documents()->pdf(Api::ID));
        self::assertSame(['xml' => $xml, 'kind' => 'authorized'], FlashpiFacade::documents()->xml(Api::ID));
        Http::assertSent(fn(Request $request): bool => str_ends_with($request->url(), '/pdf') && $request->header('Accept') === ['application/pdf, application/problem+json']);
    }

    /**
     * A listener of ResponseReceived that reads the body (Telescope does) leaves the stream at its
     * end: the SDK rewinds it before reading it.
     */
    public function testDownloadsALargeRideThroughTheHandler(): void
    {
        $pdf = '%PDF-1.7' . random_bytes(2_000_000) . '%%EOF';
        $transport = new MockHandler([
            new Response(200, ['Content-Type' => 'application/pdf', 'Content-Length' => (string) \strlen($pdf)], $pdf),
        ]);
        $read = [];
        $this->dispatcher()->listen(ResponseReceived::class, static function (ResponseReceived $event) use (&$read): void {
            $read[] = $event->response->body();
        });

        self::assertSame($pdf, $this->clientOver($transport)->documents->pdf(Api::ID));
        self::assertSame([$pdf], $read);
    }

    public function testRefusesAnAnswerCutShort(): void
    {
        $transport = new MockHandler([
            new Response(200, ['Content-Type' => 'application/pdf', 'Content-Length' => '1000'], '%PDF-1.7 cut'),
        ]);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('the answer ended after 12 of its 1000 bytes');
        $this->clientOver($transport, 0)->documents->pdf(Api::ID);
    }

    public function testLeavesNoKeyInTheErrorsOfTheHandler(): void
    {
        $transport = new MockHandler([new \RuntimeException('handler bug')]);

        try {
            $this->clientOver($transport)->status->get();
            self::fail('The error was not thrown');
        } catch (FlashpiException $error) {
            self::assertSame('GET ' . Api::url('/v1/status') . ' was not sent: RuntimeException: handler bug', $error->getMessage());
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString($this->apiKey, $error->getTraceAsString());
        }
    }

    private function dispatcher(): Dispatcher
    {
        return $this->app?->make(Dispatcher::class) ?? throw new \LogicException('The app is not running');
    }

    /**
     * A client built as the container builds it, over another handler than curl.
     */
    private function clientOver(MockHandler $transport, int $maxRetries = 2): Flashpi
    {
        $container = $this->app ?? throw new \LogicException('The app is not running');

        return new Flashpi(
            apiKey: $this->apiKey,
            timeout: 1.0,
            maxRetries: $maxRetries,
            httpClient: LaravelHttpClient::create($container, $transport),
        );
    }

    /**
     * @return array{
     *     issuerId: string,
     *     establishment: string,
     *     emissionPoint: string,
     *     customer: array{idType: 'ruc', id: string, name: string},
     *     items: list<array{code: string, description: string, quantity: string, unitPrice: string, taxes: list<array{type: 'vat', rate: '15'}>}>,
     *     payments: list<array{method: 'cash', amount: string}>,
     * }
     */
    private static function invoice(): array
    {
        return [
            'issuerId' => Api::ID,
            'establishment' => '001',
            'emissionPoint' => '001',
            'customer' => ['idType' => 'ruc', 'id' => '1760013210001', 'name' => 'PRUEBAS SERVICIO DE RENTAS INTERNAS'],
            'items' => [[
                'code' => 'P-001',
                'description' => 'Test service',
                'quantity' => '1',
                'unitPrice' => '1.00',
                'taxes' => [['type' => 'vat', 'rate' => '15']],
            ]],
            'payments' => [['method' => 'cash', 'amount' => '1.15']],
        ];
    }
}
