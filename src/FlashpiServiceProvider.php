<?php

declare(strict_types=1);

namespace Flashpi\Laravel;

use Flashpi\Flashpi;
use Flashpi\Laravel\Console\CheckCommand;
use Flashpi\Laravel\Console\InstallCommand;
use Flashpi\Laravel\Console\WebhookCommand;
use Flashpi\Laravel\Http\Controllers\WebhookController;
use Flashpi\Laravel\Internal\ClientBuilder;
use Flashpi\Laravel\Internal\WebhookSettings;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Support\ServiceProvider;

/**
 * Registers Flashpi in a Laravel app (auto-discovered through composer.json).
 *
 * - The configuration, config/flashpi.php, merged with the app's own copy and published with
 *   `php artisan vendor:publish --tag=flashpi-config` (or `php artisan flashpi:install`).
 * - Flashpi\Flashpi as a singleton built from that configuration, with Laravel's HTTP client
 *   underneath (see Internal\LaravelHttpClient): inject it, or use the Flashpi facade.
 * - The webhook route, `POST /flashpi/webhook` named `flashpi.webhook` (see
 *   Http\Controllers\WebhookController), unless FLASHPI_WEBHOOK_ENABLED is false. It sits
 *   outside the `web` middleware group: no session and no CSRF token, since the signature
 *   authenticates each delivery. It is a controller, so `php artisan route:cache` caches it; with
 *   cached routes the provider adds nothing, and a change to FLASHPI_WEBHOOK_PATH needs
 *   `php artisan route:cache` again.
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
        $this->registerWebhookRoute();
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

    private function registerWebhookRoute(): void
    {
        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }
        $config = $this->app->make(Repository::class);
        $path = WebhookSettings::path($config);
        if (!WebhookSettings::enabled($config) || $path === null) {
            return;
        }
        $this->app->make(Registrar::class)
            ->post($path, WebhookController::class)
            ->middleware(WebhookSettings::middleware($config))
            ->name(WebhookSettings::ROUTE);
    }
}
