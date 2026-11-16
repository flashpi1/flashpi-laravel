<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Tests;

use Flashpi\Flashpi;
use Flashpi\Laravel\FlashpiServiceProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Testing\PendingCommand;
use Orchestra\Testbench\TestCase as Testbench;

/**
 * A Laravel app with the package, as package discovery would register it, and a fake test key.
 */
abstract class TestCase extends Testbench
{
    /** A fake test key, new for every test. */
    protected string $apiKey = '';

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [FlashpiServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->apiKey = self::fakeKey('test');
        $config = $app->make(Repository::class);
        $config->set('app.locale', 'en');
        $config->set('flashpi.api_key', $this->apiKey);
    }

    /**
     * A fake API key: the prefix of its environment and random text.
     *
     * @param 'test'|'live' $environment
     */
    protected static function fakeKey(string $environment): string
    {
        return "fp_{$environment}_" . bin2hex(random_bytes(24));
    }

    /**
     * A fake webhook signing secret: whsec_ and 32 random bytes in base64.
     */
    protected static function fakeSecret(): string
    {
        return 'whsec_' . base64_encode(random_bytes(32));
    }

    protected function config(): Repository
    {
        return $this->app?->make(Repository::class) ?? throw new \LogicException('The app is not running');
    }

    /**
     * The client of the container.
     */
    protected function client(): Flashpi
    {
        $client = $this->app?->make(Flashpi::class);
        self::assertInstanceOf(Flashpi::class, $client);

        return $client;
    }

    /**
     * An Artisan command to run, with its expectations.
     *
     * @param array<string, mixed> $parameters
     */
    protected function command(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);
        self::assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }
}
