<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Tests\Webhooks;

use Flashpi\Laravel\Events\InvoiceAuthorized;
use Flashpi\Laravel\Tests\Support\Api;
use Flashpi\Laravel\Tests\Support\QueuedInvoiceListener;
use Flashpi\Laravel\Tests\Support\Recorder;
use Flashpi\Laravel\Tests\Support\Webhook;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\Attributes\DefineEnvironment;

/**
 * Queued listeners (ShouldQueue) get the webhook events through the queue's serialization: on the
 * sync queue, which serializes the job too, and on the database queue with a worker.
 */
final class QueuedListenerTest extends WebhookTestCase
{
    private Recorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recorder = new Recorder();
        $app = $this->app ?? throw new \LogicException('The app is not running');
        $app->instance(Recorder::class, $this->recorder);
        $app->make(Dispatcher::class)->listen(InvoiceAuthorized::class, QueuedInvoiceListener::class);
    }

    public function testGivesTheSyncQueueAnEventThatWentThroughSerialization(): void
    {
        $dispatched = $this->record([InvoiceAuthorized::class]);

        $this->deliver(Webhook::event('invoice.authorized'))->assertNoContent();

        $copy = self::only($this->recorder->events);
        self::assertNotSame($dispatched[0], $copy);
        self::assertEquals($dispatched[0], $copy);
        self::assertSame([Webhook::document()], $this->recorder->data);
    }

    #[DefineEnvironment('usesTheDatabaseQueue')]
    public function testGivesAWorkerOfTheDatabaseQueueTheEventAndItsData(): void
    {
        Http::fake([Api::url('/v1/events/' . Webhook::ID) => Api::json(Webhook::event('invoice.authorized'))]);
        $dispatched = $this->record([InvoiceAuthorized::class]);

        $this->deliver(Webhook::thin('invoice.authorized'))->assertNoContent();

        // Queued, not handled yet; the job holds neither the API key nor the webhook secret.
        self::assertCount(0, $this->recorder->events);
        $job = self::only($this->database()->table('jobs')->pluck('payload')->all());
        self::assertIsString($job);
        $command = data_get(json_decode($job, true, 512, \JSON_THROW_ON_ERROR), 'data.command');
        self::assertIsString($command);
        self::assertStringContainsString(sprintf('O:%d:"%s"', \strlen(InvoiceAuthorized::class), InvoiceAuthorized::class), $command);
        self::assertStringNotContainsString($this->apiKey, $job);
        self::assertStringNotContainsString(substr($this->secret, 6), $job);
        Http::assertNothingSent();

        $this->command('queue:work', ['--once' => true])->assertSuccessful();

        [$original, $copy] = [$dispatched[0], self::only($this->recorder->events)];
        self::assertInstanceOf(InvoiceAuthorized::class, $original);
        self::assertInstanceOf(InvoiceAuthorized::class, $copy);
        self::assertSame($original->payload, $copy->payload);
        self::assertSame($original->webhookId, $copy->webhookId);
        self::assertTrue($copy->isThin());
        // The listener fetched the thin event's data, with the app's key, in the worker.
        self::assertSame([Webhook::document()], $this->recorder->data);
        Http::assertSentCount(1);
        self::assertSame(0, $this->database()->table('jobs')->count());
        self::assertSame(0, $this->database()->table('failed_jobs')->count());
    }

    protected function usesTheDatabaseQueue(Application $app): void
    {
        $config = $app->make(Repository::class);
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $config->set('queue.default', 'database');
        $config->set('queue.connections.database', [
            'driver' => 'database',
            'connection' => 'testing',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations(['--database' => 'testing']);
    }

    private function database(): \Illuminate\Database\ConnectionInterface
    {
        $resolver = $this->app?->make(ConnectionResolverInterface::class) ?? throw new \LogicException('The app is not running');

        return $resolver->connection('testing');
    }
}
