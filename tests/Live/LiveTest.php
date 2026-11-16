<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Tests\Live;

use Flashpi\Laravel\Events\InvoiceAuthorized;
use Flashpi\Laravel\Facades\Flashpi;
use Flashpi\Laravel\Tests\TestCase;
use Flashpi\Types\ApiTypes;
use Illuminate\Contracts\Config\Repository;
use PHPUnit\Framework\Attributes\Group;

/**
 * The package against the real Flashpi API in test mode (SRI pruebas), as flashpi-php's
 * tests/Live/LiveTest.php checks the SDK:
 *
 *     composer test:live
 *
 * A Laravel app with the package boots with FLASHPI_TEST_API_KEY (an fp_test_ key with
 * read_write access) in its configuration; FLASHPI_DEMO_ISSUER_ID is the demo issuer's id (on
 * the console's Overview), and FLASHPI_BASE_URL may point to another API. Without the key and the
 * issuer every test is skipped, and the normal run leaves the `live` group out. It issues ONE
 * real test invoice on the shared demo issuer, which counts toward its daily quota of 50, so CI
 * only runs it while a release is being prepared. It never accepts an fp_live_ key.
 *
 * @phpstan-import-type CreateInvoice from ApiTypes
 */
#[Group('live')]
final class LiveTest extends TestCase
{
    /** Checks of a slow SRI step, 5 seconds apart: up to 2 minutes. */
    private const POLLS = 24;

    private const POLL_SECONDS = 5;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $config = $app->make(Repository::class);
        $config->set('flashpi.api_key', self::apiKey());
        $baseUrl = trim((string) getenv('FLASHPI_BASE_URL'));
        $config->set('flashpi.base_url', $baseUrl !== '' ? $baseUrl : null);
    }

    protected function setUp(): void
    {
        if (self::apiKey() === '' || self::issuerId() === '') {
            self::markTestSkipped('needs FLASHPI_TEST_API_KEY and FLASHPI_DEMO_ISSUER_ID');
        }
        if (!str_starts_with(self::apiKey(), 'fp_test_')) {
            throw new \RuntimeException('FLASHPI_TEST_API_KEY must be an fp_test_ key: this suite never runs live');
        }
        parent::setUp();
    }

    public function testFlashpiCheckAcceptsTheKey(): void
    {
        $this->command('flashpi:check')
            ->expectsOutputToContain('test (fp_test_ key: the SRI\'s test environment)')
            ->expectsOutputToContain('operational')
            ->expectsOutputToContain('accepted')
            ->assertSuccessful();
    }

    public function testIssuesATestInvoiceThroughTheFacadeAndFetchesItsEvent(): void
    {
        $invoice = Flashpi::invoices()->create(self::invoice(), [
            'idempotencyKey' => \sprintf('laravel-live-%x-%s', time(), bin2hex(random_bytes(16))),
            'correlationId' => 'flashpi-laravel-live',
        ]);
        self::assertSame('invoice', $invoice['type']);
        self::assertSame('test', $invoice['environment']);
        self::assertSame('test', Flashpi::environment());

        // The SRI's test environment can be slow: follow the document for up to 2 minutes.
        for ($poll = 0; $poll < self::POLLS && \in_array($invoice['status'], ['processing', 'pending_sri'], true); $poll++) {
            sleep(self::POLL_SECONDS);
            $invoice = Flashpi::documents()->get($invoice['id']);
        }
        self::assertSame('authorized', $invoice['status']);
        self::assertMatchesRegularExpression('/^\d{49}$/', $invoice['accessKey']);

        // The invoice.authorized event, as a thin webhook would carry it: fetch() reads it from
        // the API through the container's client.
        $eventId = null;
        for ($poll = 0; $poll < 12 && $eventId === null; $poll++) {
            if ($poll > 0) {
                sleep(self::POLL_SECONDS);
            }
            foreach (Flashpi::events()->list(['documentId' => $invoice['id']]) as $event) {
                if ($event['type'] === 'invoice.authorized') {
                    $eventId = $event['id'];
                }
            }
        }
        self::assertIsString($eventId, 'The API listed no invoice.authorized event for the invoice');
        $thin = new InvoiceAuthorized([
            'id' => $eventId,
            'type' => 'invoice.authorized',
            'environment' => 'test',
            'createdAt' => '2026-11-16T00:00:00.000Z',
        ]);
        self::assertTrue($thin->isThin());
        $fetched = $thin->fetch();
        self::assertSame($eventId, $fetched['id']);
        self::assertSame($invoice['id'], $fetched['data']['id']);
        self::assertSame($invoice['accessKey'], $fetched['data']['accessKey']);
    }

    /**
     * The same test invoice as the docs' quickstart: USD 1.00 + 15 % VAT to the SRI's test RUC.
     *
     * @return CreateInvoice
     */
    private static function invoice(): array
    {
        return [
            'issuerId' => self::issuerId(),
            'establishment' => '001',
            'emissionPoint' => '001',
            'customer' => [
                'idType' => 'ruc',
                'id' => '1760013210001',
                'name' => 'PRUEBAS SERVICIO DE RENTAS INTERNAS',
                'address' => 'AMAZONAS AVENUE AND VILLALENGUA, QUITO',
            ],
            'items' => [
                [
                    'code' => 'SDK-LIVE-001',
                    'description' => 'flashpi (Laravel) live test',
                    'quantity' => '1',
                    'unitPrice' => '1.00',
                    'taxes' => [['type' => 'vat', 'rate' => '15']],
                ],
            ],
            'payments' => [['method' => 'cash', 'amount' => '1.15']],
            'options' => ['waitForAuthorization' => true, 'waitTimeoutSeconds' => 30, 'sendEmail' => false],
        ];
    }

    private static function apiKey(): string
    {
        return trim((string) getenv('FLASHPI_TEST_API_KEY'));
    }

    private static function issuerId(): string
    {
        return trim((string) getenv('FLASHPI_DEMO_ISSUER_ID'));
    }
}
