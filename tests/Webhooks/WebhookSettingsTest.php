<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Tests\Webhooks;

use Flashpi\Exception\FlashpiException;
use Flashpi\Laravel\Internal\WebhookSettings;
use Illuminate\Config\Repository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * How the webhook settings of config/flashpi.php are read, from .env's strings too.
 */
final class WebhookSettingsTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function switches(): iterable
    {
        yield 'unset' => [null, true];
        yield 'true' => [true, true];
        yield 'false' => [false, false];
        yield '1' => [1, true];
        yield '0' => [0, false];
        yield 'empty' => ['', true];
        foreach (['false', 'FALSE', '(false)', '0', 'off', ' Off ', 'no'] as $text) {
            yield "text {$text}" => [$text, false];
        }
        foreach (['true', '1', 'on', 'yes', 'whatever'] as $text) {
            yield "text {$text}" => [$text, true];
        }
        yield 'a list' => [[false], true];
    }

    #[DataProvider('switches')]
    public function testTurnsTheRouteOffForFalseValuesOnly(mixed $value, bool $enabled): void
    {
        self::assertSame($enabled, WebhookSettings::enabled(self::config(['enabled' => $value])));
    }

    /**
     * @return iterable<string, array{mixed, string|null}>
     */
    public static function paths(): iterable
    {
        yield 'unset' => [null, '/flashpi/webhook'];
        yield 'empty' => [' ', '/flashpi/webhook'];
        yield 'the default' => ['/flashpi/webhook', '/flashpi/webhook'];
        yield 'without slashes' => ['hooks/flashpi', '/hooks/flashpi'];
        yield 'with spaces and slashes' => [" //hooks/flashpi// \n", '/hooks/flashpi'];
        yield 'slashes only' => ['/', null];
        yield 'not a string' => [['/hooks'], null];
    }

    #[DataProvider('paths')]
    public function testNormalizesThePath(mixed $value, ?string $path): void
    {
        self::assertSame($path, WebhookSettings::path(self::config(['path' => $value])));
        self::assertSame('/flashpi/webhook', WebhookSettings::path(new Repository(['flashpi' => ['webhook' => []]])));
    }

    /**
     * @return iterable<string, array{mixed, list<string>}>
     */
    public static function middleware(): iterable
    {
        yield 'unset' => [null, []];
        yield 'none' => [[], []];
        yield 'a name' => ['throttle:60,1', ['throttle:60,1']];
        yield 'names' => [['throttle:60,1', 'log'], ['throttle:60,1', 'log']];
        yield 'blanks and other values' => [['', 'log', 3, null], ['log']];
        yield 'a number' => [3, []];
    }

    /**
     * @param list<string> $names
     */
    #[DataProvider('middleware')]
    public function testReadsTheMiddleware(mixed $value, array $names): void
    {
        self::assertSame($names, WebhookSettings::middleware(self::config(['middleware' => $value])));
    }

    public function testTrimsTheSecretAndTheStore(): void
    {
        $config = self::config(['secret' => " whsec_abc\n", 'deduplication' => ['store' => ' redis ']]);

        self::assertSame('whsec_abc', WebhookSettings::secret($config));
        self::assertSame('redis', WebhookSettings::store($config));
        self::assertNull(WebhookSettings::store(self::config(['deduplication' => ['store' => '']])));
        self::assertNull(WebhookSettings::store(self::config([])));
    }

    /**
     * @return iterable<string, array{mixed, int|null}>
     */
    public static function ttls(): iterable
    {
        yield 'unset' => [null, 86400];
        yield 'empty' => [' ', 86400];
        yield 'a number' => [3600, 3600];
        yield 'digits' => [' 60 ', 60];
        yield 'zero' => [0, null];
        yield 'negative' => [-1, null];
        yield 'a fraction' => [1.5, null];
        yield 'a negative text' => ['-60', null];
        yield 'text' => ['1 day', null];
    }

    #[DataProvider('ttls')]
    public function testReadsTheTimeToLive(mixed $value, ?int $ttl): void
    {
        $config = self::config(['deduplication' => ['ttl' => $value]]);
        if ($ttl === null) {
            $this->expectException(FlashpiException::class);
            $this->expectExceptionMessage('config flashpi.webhook.deduplication.ttl must be a whole number of seconds, 1 or more');
        }

        self::assertSame($ttl, WebhookSettings::ttl($config));
    }

    /**
     * @param array<string, mixed> $webhook
     */
    private static function config(array $webhook): Repository
    {
        return new Repository(['flashpi' => ['webhook' => $webhook]]);
    }
}
