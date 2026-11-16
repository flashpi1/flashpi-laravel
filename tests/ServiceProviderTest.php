<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Tests;

use Flashpi\Flashpi;
use Flashpi\Laravel\Facades\Flashpi as FlashpiFacade;
use Flashpi\Laravel\FlashpiServiceProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Mockery\MockInterface;

/**
 * What the service provider registers: the configuration and the client.
 */
final class ServiceProviderTest extends TestCase
{
    public function testMergesTheDefaultConfiguration(): void
    {
        $config = $this->config();
        self::assertNull($config->get('flashpi.language'));
        self::assertNull($config->get('flashpi.base_url'));
        self::assertNull($config->get('flashpi.timeout'));
        self::assertNull($config->get('flashpi.max_retries'));
        self::assertTrue($config->get('flashpi.webhook.enabled'));
        self::assertSame('/flashpi/webhook', $config->get('flashpi.webhook.path'));
        self::assertNull($config->get('flashpi.webhook.secret'));
        self::assertSame([], $config->get('flashpi.webhook.middleware'));
        self::assertSame(['store' => null, 'ttl' => 86400], $config->get('flashpi.webhook.deduplication'));
        // The app's values win over the package's.
        self::assertSame($this->apiKey, $config->get('flashpi.api_key'));
    }

    public function testReadsTheEnvironmentInTheConfigurationFileOnly(): void
    {
        $file = (string) file_get_contents(FlashpiServiceProvider::CONFIG);
        preg_match_all("/env\\('([A-Z_]+)'/", $file, $matches);
        self::assertSame([
            'FLASHPI_API_KEY',
            'FLASHPI_LANGUAGE',
            'FLASHPI_BASE_URL',
            'FLASHPI_TIMEOUT',
            'FLASHPI_MAX_RETRIES',
            'FLASHPI_WEBHOOK_ENABLED',
            'FLASHPI_WEBHOOK_PATH',
            'FLASHPI_WEBHOOK_SECRET',
            'FLASHPI_WEBHOOK_CACHE_STORE',
        ], $matches[1]);

        // With `php artisan config:cache`, env() returns nothing outside config files.
        $sources = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__) . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($sources as $source) {
            \assert($source instanceof \SplFileInfo);
            $code = '';
            foreach (\PhpToken::tokenize((string) file_get_contents($source->getPathname())) as $token) {
                $code .= $token->is([\T_COMMENT, \T_DOC_COMMENT]) ? '' : $token->text;
            }
            self::assertDoesNotMatchRegularExpression('/\benv\(|getenv\(|\$_ENV|\$_SERVER/', $code, $source->getPathname());
        }
    }

    public function testPublishesTheConfigurationUnderItsTag(): void
    {
        $paths = ServiceProvider::pathsToPublish(FlashpiServiceProvider::class, 'flashpi-config');
        self::assertSame([FlashpiServiceProvider::CONFIG => $this->app?->configPath('flashpi.php')], $paths);
    }

    public function testRegistersNothingForTheConsoleOutsideIt(): void
    {
        // The mock refuses any other call (configPath() for the publishing, among them).
        /** @var Application&MockInterface $app */
        $app = \Mockery::mock(Application::class);
        $app->shouldReceive('runningInConsole')->once()->andReturn(false);

        (new FlashpiServiceProvider($app))->boot();
    }

    public function testBindsTheClientAsASingleton(): void
    {
        $first = $this->app?->make(Flashpi::class);
        self::assertInstanceOf(Flashpi::class, $first);
        self::assertSame($first, $this->app?->make(Flashpi::class));
        self::assertSame('test', $first->environment);
        self::assertSame($first, FlashpiFacade::getFacadeRoot());
    }

    public function testComposerRegistersTheProviderForPackageDiscovery(): void
    {
        $composer = json_decode((string) file_get_contents(\dirname(__DIR__) . '/composer.json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        self::assertSame(['laravel' => ['providers' => [FlashpiServiceProvider::class]]], $composer['extra'] ?? null);
    }
}
