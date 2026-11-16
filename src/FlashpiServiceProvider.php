<?php

declare(strict_types=1);

namespace Flashpi\Laravel;

use Flashpi\Flashpi;
use Flashpi\Laravel\Console\CheckCommand;
use Flashpi\Laravel\Console\InstallCommand;
use Flashpi\Laravel\Console\WebhookCommand;
use Flashpi\Laravel\Internal\ClientBuilder;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;

/**
 * Registers Flashpi in a Laravel app (auto-discovered through composer.json).
 *
 * - The configuration, config/flashpi.php, merged with the app's own copy and published with
 *   `php artisan vendor:publish --tag=flashpi-config` (or `php artisan flashpi:install`).
 * - Flashpi\Flashpi as a singleton built from that configuration, with Laravel's HTTP client
 *   underneath (see Internal\LaravelHttpClient): inject it, or use the Flashpi facade.
 * - The commands flashpi:install, flashpi:check and flashpi:webhook.
 */
final class FlashpiServiceProvider extends ServiceProvider
{
    public const CONFIG = __DIR__ . '/../config/flashpi.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG, 'flashpi');

        $this->app->singleton(Flashpi::class, static fn(Container $container): Flashpi => ClientBuilder::build($container));
    }

    public function boot(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }
        $this->publishes([self::CONFIG => $this->app->configPath('flashpi.php')], 'flashpi-config');
        $this->commands([
            InstallCommand::class,
            CheckCommand::class,
            WebhookCommand::class,
        ]);
    }
}
