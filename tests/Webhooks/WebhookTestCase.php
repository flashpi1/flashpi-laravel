<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Tests\Webhooks;

use Flashpi\Laravel\Tests\Support\Webhook;
use Flashpi\Laravel\Tests\TestCase;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Testing\TestResponse;

/**
 * An app with a fake webhook secret, which receives signed deliveries on the package's route.
 */
abstract class WebhookTestCase extends TestCase
{
    protected const PATH = '/flashpi/webhook';

    /** A fake webhook secret, new for every test. */
    protected string $secret = '';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $this->secret = self::fakeSecret();
        $app->make(Repository::class)->set('flashpi.webhook.secret', $this->secret);
    }

    /**
     * Sends a delivery of an event, signed with the app's secret now.
     *
     * @param array<string, mixed> $event
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function deliver(array $event, string $id = Webhook::ID): TestResponse
    {
        $body = Webhook::body($event);

        return $this->postRaw($body, Webhook::headers($body, $this->secret, $id));
    }

    /**
     * Sends a raw body with its headers to the webhook route, as Flashpi would.
     *
     * @param array<string, string> $headers
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function postRaw(string $body, array $headers, string $path = self::PATH): TestResponse
    {
        $server = $this->transformHeadersToServerVars([
            'Content-Type' => 'application/json',
            'User-Agent' => 'Flashpi-Webhooks/1.0 (+https://flashpi.com.ec/docs/webhooks)',
            ...$headers,
        ]);

        return $this->call('POST', $path, [], [], [], $server, $body);
    }

    /**
     * The one item of a list.
     *
     * @template T
     *
     * @param array<T> $items
     *
     * @return T
     */
    protected static function only(array $items): mixed
    {
        self::assertCount(1, $items);
        foreach ($items as $item) {
            return $item;
        }
        self::fail('No item');
    }

    /**
     * Records the events of these classes as they are dispatched, in order.
     *
     * @param list<class-string> $classes
     *
     * @return \ArrayObject<int, object>
     */
    protected function record(array $classes): \ArrayObject
    {
        /** @var \ArrayObject<int, object> $events */
        $events = new \ArrayObject();
        $dispatcher = $this->app?->make(Dispatcher::class) ?? throw new \LogicException('The app is not running');
        foreach ($classes as $class) {
            $dispatcher->listen($class, static function (object $event) use ($events): void {
                $events[] = $event;
            });
        }

        return $events;
    }
}
