<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Tests;

use Flashpi\Exception\FlashpiException;
use Flashpi\Flashpi;
use Flashpi\Laravel\Tests\Support\Api;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The client the container builds from config/flashpi.php: config() only, never .env.
 */
final class ConfigurationTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $environment = [];

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
            unset($_ENV[$name], $_SERVER[$name]);
        }
        parent::tearDown();
    }

    public function testUsesTheConfiguredKey(): void
    {
        Http::fake(['*' => Api::json(['status' => 'operational'])]);

        $this->client()->status->get();

        Http::assertSent(fn(Request $request): bool => $request->header('Authorization') === ["Bearer {$this->apiKey}"]);
    }

    public function testTellsTheEnvironmentOfTheKey(): void
    {
        $this->config()->set('flashpi.api_key', self::fakeKey('live'));

        self::assertSame('live', $this->client()->environment);
    }

    public function testTrimsTheKey(): void
    {
        $this->config()->set('flashpi.api_key', " {$this->apiKey}\n");
        Http::fake(['*' => Api::json(['status' => 'operational'])]);

        $this->client()->status->get();

        Http::assertSent(fn(Request $request): bool => $request->header('Authorization') === ["Bearer {$this->apiKey}"]);
    }

    /**
     * With `php artisan config:cache`, .env is not loaded and config() is all there is: the SDK's
     * own fallback to FLASHPI_API_KEY in the process environment must not apply either.
     */
    public function testNeverReadsTheKeyFromTheEnvironment(): void
    {
        $this->setEnvironment('FLASHPI_API_KEY', self::fakeKey('live'));
        $this->config()->set('flashpi.api_key', null);

        $this->assertBuildFails('Missing Flashpi API key: set FLASHPI_API_KEY in your .env file (config flashpi.api_key). If you cache the configuration, run php artisan config:cache again after changing .env');
    }

    public function testPrefersTheConfigurationToTheEnvironment(): void
    {
        $this->setEnvironment('FLASHPI_API_KEY', self::fakeKey('live'));

        self::assertSame('test', $this->client()->environment);
    }

    /**
     * @return iterable<string, array{mixed, non-empty-string}>
     */
    public static function badKeys(): iterable
    {
        yield 'empty' => ['', 'Missing Flashpi API key'];
        yield 'blank' => ["  \n", 'Missing Flashpi API key'];
        yield 'not a string' => [123, 'config flashpi.api_key (FLASHPI_API_KEY) must be a string'];
        yield 'no prefix' => ['sk_test_123', 'The API key must start with fp_test_ or fp_live_'];
        yield 'webhook secret' => ['whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw', 'That is a webhook signing secret'];
    }

    /**
     * @param non-empty-string $message
     */
    #[DataProvider('badKeys')]
    public function testRefusesAKeyThatCannotBeUsed(mixed $key, string $message): void
    {
        $this->config()->set('flashpi.api_key', $key);

        $this->assertBuildFails($message);
    }

    public function testSendsToTheConfiguredBaseUrl(): void
    {
        $this->config()->set('flashpi.base_url', ' https://sandbox.flashpi.test ');
        Http::fake(['*' => Api::json(['status' => 'operational'])]);

        $this->client()->status->get();

        Http::assertSent(fn(Request $request): bool => $request->url() === 'https://sandbox.flashpi.test/v1/status');
    }

    public function testUsesTheDefaultsForEmptySettings(): void
    {
        $this->config()->set('flashpi.base_url', '');
        $this->config()->set('flashpi.timeout', '');
        $this->config()->set('flashpi.max_retries', ' ');
        $options = [];
        Http::fake(function (Request $request, array $sent) use (&$options) {
            $options = $sent;

            return Api::json(['status' => 'operational']);
        });

        $this->client()->status->get();

        Http::assertSent(fn(Request $request): bool => $request->url() === Api::url('/v1/status'));
        self::assertSame(Flashpi::DEFAULT_TIMEOUT, $options['timeout'] ?? null);
    }

    /**
     * @return iterable<string, array{mixed, float}>
     */
    public static function timeouts(): iterable
    {
        yield 'string from .env' => ['12.5', 12.5];
        yield 'whole string' => [' 30 ', 30.0];
        yield 'int' => [45, 45.0];
        yield 'float' => [7.5, 7.5];
    }

    #[DataProvider('timeouts')]
    public function testReadsTheTimeoutInSeconds(mixed $value, float $seconds): void
    {
        $this->config()->set('flashpi.timeout', $value);
        $options = [];
        Http::fake(function (Request $request, array $sent) use (&$options) {
            $options = $sent;

            return Api::json(['status' => 'operational']);
        });

        $this->client()->status->get();

        self::assertSame($seconds, $options['timeout'] ?? null);
    }

    /**
     * @return iterable<string, array{string, mixed, non-empty-string}>
     */
    public static function badSettings(): iterable
    {
        yield 'timeout text' => ['timeout', 'soon', 'config flashpi.timeout (FLASHPI_TIMEOUT) must be a number of seconds'];
        yield 'timeout array' => ['timeout', [30], 'config flashpi.timeout (FLASHPI_TIMEOUT) must be a number of seconds'];
        yield 'timeout zero' => ['timeout', '0', 'timeout must be more than 0'];
        yield 'retries text' => ['max_retries', 'two', 'config flashpi.max_retries (FLASHPI_MAX_RETRIES) must be a whole number, 0 or more'];
        yield 'retries negative text' => ['max_retries', '-1', 'config flashpi.max_retries (FLASHPI_MAX_RETRIES) must be a whole number, 0 or more'];
        yield 'retries float' => ['max_retries', 1.5, 'config flashpi.max_retries (FLASHPI_MAX_RETRIES) must be a whole number, 0 or more'];
        yield 'retries negative' => ['max_retries', -1, 'maxRetries must be a whole number, 0 or more'];
        yield 'base URL' => ['base_url', 'api.flashpi.com.ec', 'baseUrl must be an http:// or https:// URL'];
        yield 'base URL not a string' => ['base_url', true, 'config flashpi.base_url (FLASHPI_BASE_URL) must be a string'];
        yield 'language not a string' => ['language', ['es'], 'config flashpi.language (FLASHPI_LANGUAGE) must be a string'];
        yield 'language with a region' => ['language', 'es_EC', 'config flashpi.language (FLASHPI_LANGUAGE) must be es or en'];
        yield 'another language' => ['language', 'fr', 'config flashpi.language (FLASHPI_LANGUAGE) must be es or en'];
    }

    /**
     * @param non-empty-string $message
     */
    #[DataProvider('badSettings')]
    public function testRefusesASettingThatCannotBeUsed(string $name, mixed $value, string $message): void
    {
        $this->config()->set("flashpi.{$name}", $value);

        $this->assertBuildFails($message);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function retries(): iterable
    {
        yield 'string from .env' => ['0'];
        yield 'int' => [0];
    }

    #[DataProvider('retries')]
    public function testReadsTheRetries(mixed $value): void
    {
        $this->config()->set('flashpi.max_retries', $value);
        Http::fake(['*' => Api::problem(503, 'service_unavailable', ['Retry-After' => '0'])]);

        try {
            $this->client()->status->get();
            self::fail('The 503 was not thrown');
        } catch (FlashpiException) {
        }

        Http::assertSentCount(1);
    }

    /**
     * @return iterable<string, array{string|null, string, string}>
     */
    public static function languages(): iterable
    {
        yield 'FLASHPI_LANGUAGE' => ['es', 'en', 'es'];
        yield 'FLASHPI_LANGUAGE over the locale' => ['en', 'es', 'en'];
        yield 'FLASHPI_LANGUAGE trimmed' => [' es ', 'en', 'es'];
        yield 'FLASHPI_LANGUAGE in capitals' => ['ES', 'en', 'es'];
        yield 'empty FLASHPI_LANGUAGE' => ['', 'es', 'es'];
        yield 'Spanish' => [null, 'es', 'es'];
        yield 'Spanish of Ecuador' => [null, 'es_EC', 'es'];
        yield 'Spanish with a hyphen' => [null, 'es-EC', 'es'];
        yield 'Spanish in capitals' => [null, 'ES', 'es'];
        yield 'English' => [null, 'en', 'en'];
        yield 'Portuguese' => [null, 'pt_BR', 'en'];
        yield 'Estonian (ISO 639-2)' => [null, 'est', 'en'];
    }

    #[DataProvider('languages')]
    public function testChoosesTheLanguageOfTheMessages(?string $language, string $locale, string $expected): void
    {
        $this->config()->set('flashpi.language', $language);
        $this->config()->set('app.locale', $locale);
        Http::fake(['*' => Api::json(['status' => 'operational'])]);

        $this->client()->status->get();

        Http::assertSent(fn(Request $request): bool => $request->header('Accept-Language') === [$expected]);
    }

    public function testFallsBackToEnglishWithoutALocale(): void
    {
        $this->config()->set('app.locale', null);
        Http::fake(['*' => Api::json(['status' => 'operational'])]);

        $this->client()->status->get();

        Http::assertSent(fn(Request $request): bool => $request->header('Accept-Language') === ['en']);
    }

    public function testLetsARequestChooseItsLanguage(): void
    {
        $this->config()->set('app.locale', 'es');
        Http::fake(['*' => Api::json(['status' => 'operational'])]);

        $this->client()->status->get(['language' => 'en']);

        Http::assertSent(fn(Request $request): bool => $request->header('Accept-Language') === ['en']);
    }

    /**
     * @param non-empty-string $message
     */
    private function assertBuildFails(string $message): void
    {
        try {
            $this->client();
            self::fail('The client was built');
        } catch (FlashpiException $error) {
            self::assertStringStartsWith($message, $error->getMessage());
            // The message names the setting, never its value.
            self::assertStringNotContainsString($this->apiKey, $error->getMessage());
        }
    }

    private function setEnvironment(string $name, string $value): void
    {
        $this->environment[$name] = getenv($name);
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
