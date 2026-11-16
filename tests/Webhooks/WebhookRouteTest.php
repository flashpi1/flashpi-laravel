<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Tests\Webhooks;

use Flashpi\Laravel\Events\InvoiceAuthorized;
use Flashpi\Laravel\Http\Controllers\WebhookController;
use Flashpi\Laravel\Tests\Support\Webhook;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Orchestra\Testbench\Attributes\DefineEnvironment;

/**
 * The route the service provider registers for the webhooks.
 */
final class WebhookRouteTest extends WebhookTestCase
{
    public function testRegistersAPostRouteOutsideTheWebGroup(): void
    {
        $route = $this->route();

        self::assertSame('flashpi/webhook', $route->uri());
        self::assertSame(['POST'], $route->methods());
        self::assertSame(WebhookController::class, $route->getControllerClass());
        // No `web` group: no session, no CSRF token.
        self::assertSame([], $route->gatherMiddleware());
        self::assertSame('http://localhost/flashpi/webhook', route('flashpi.webhook'));
    }

    public function testStartsNoSession(): void
    {
        $response = $this->deliver(Webhook::event('invoice.authorized'));

        $response->assertNoContent();
        self::assertSame([], $response->headers->getCookies());
    }

    public function testAcceptsPostOnly(): void
    {
        $this->get(self::PATH)->assertStatus(405);
    }

    #[DefineEnvironment('usesAnotherPathAndMiddleware')]
    public function testUsesThePathAndTheMiddlewareOfTheConfiguration(): void
    {
        $route = $this->route();
        self::assertSame('hooks/flashpi', $route->uri());
        self::assertSame(['throttle:60,1'], $route->gatherMiddleware());

        $events = $this->record([InvoiceAuthorized::class]);
        $body = Webhook::body(Webhook::event('invoice.authorized'));
        $this->postRaw($body, Webhook::headers($body, $this->secret), '/hooks/flashpi')->assertNoContent();
        $this->postRaw($body, Webhook::headers($body, $this->secret))->assertNotFound();
        self::assertCount(1, $events);
    }

    #[DefineEnvironment('turnsTheRouteOff')]
    public function testRegistersNoRouteWhenTurnedOff(): void
    {
        self::assertNull($this->router()->getRoutes()->getByName('flashpi.webhook'));
        $this->deliver(Webhook::event('invoice.authorized'))->assertNotFound();
    }

    #[DefineEnvironment('usesARootPath')]
    public function testRegistersNoRouteForAPathOfSlashesOnly(): void
    {
        self::assertNull($this->router()->getRoutes()->getByName('flashpi.webhook'));
    }

    public function testWorksFromCachedRoutes(): void
    {
        // What `php artisan route:cache` writes, then what Laravel loads from it.
        $routes = $this->router()->getRoutes();
        self::assertInstanceOf(RouteCollection::class, $routes);
        foreach ($routes->getRoutes() as $route) {
            $route->prepareForSerialization();
        }
        $file = tempnam(sys_get_temp_dir(), 'flashpi-routes-');
        self::assertIsString($file);
        try {
            file_put_contents($file, '<?php return ' . var_export($routes->compile(), true) . ';');
            $compiled = require $file;
        } finally {
            unlink($file);
        }
        self::assertIsArray($compiled);
        /** @var array{compiled: array<mixed>, attributes: array<string, array<mixed>>} $compiled */
        $this->router()->setCompiledRoutes($compiled);
        $events = $this->record([InvoiceAuthorized::class]);

        $this->deliver(Webhook::event('invoice.authorized'))->assertNoContent();

        self::assertCount(1, $events);
        self::assertSame(WebhookController::class, $this->route()->getControllerClass());
    }

    protected function usesAnotherPathAndMiddleware(Application $app): void
    {
        $config = $app->make(Repository::class);
        $config->set('flashpi.webhook.path', ' hooks/flashpi/ ');
        $config->set('flashpi.webhook.middleware', 'throttle:60,1');
    }

    protected function turnsTheRouteOff(Application $app): void
    {
        $app->make(Repository::class)->set('flashpi.webhook.enabled', false);
    }

    protected function usesARootPath(Application $app): void
    {
        $app->make(Repository::class)->set('flashpi.webhook.path', '/');
    }

    private function route(): Route
    {
        $routes = $this->router()->getRoutes();
        $routes->refreshNameLookups();
        $route = $routes->getByName('flashpi.webhook');
        self::assertInstanceOf(Route::class, $route);

        return $route;
    }

    private function router(): Router
    {
        return $this->app?->make(Router::class) ?? throw new \LogicException('The app is not running');
    }
}
