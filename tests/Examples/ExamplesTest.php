<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Tests\Examples;

use App\Models\Order;
use Flashpi\Laravel\Events\WebhookEvent;
use Flashpi\Laravel\Scripts\Readme\Examples;
use Flashpi\Laravel\Tests\TestCase;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The README examples, tests/Examples/Readme/ (`composer readme:generate` writes them from the
 * READMEs; tests/Scripts/ReadmeTest.php checks they match), checked as flashpi-php checks its own,
 * and run:
 *
 * - each one is valid PHP (`php -l`);
 * - PHPStan accepts them with a consumer's configuration (tests/Examples/phpstan.neon);
 * - each one runs in a Laravel app with the package, the API faked with Http::fake(), the default
 *   disk faked with Storage::fake() and the reader's orders (tests/Examples/App/Models/Order.php)
 *   in SQLite in memory. Its top-level code runs as it is. Its listeners get their event through
 *   Laravel's dispatcher and the sync queue, once with a full payload and once with a thin one.
 *   Its jobs go through the bus, which serializes them, and its other classes with a handle() or
 *   __invoke() are called through the container. Its test class runs with PHPUnit in a process of
 *   its own, on the app's Tests\TestCase (tests/Examples/App/TestCase.php).
 *
 * A request the fake API does not know fails the example, and so does a class this test cannot
 * run or a run that does nothing this test can see (no request, query or webhook event).
 */
#[Group('readme')]
final class ExamplesTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private const NAMESPACE = 'Flashpi\\Laravel\\Tests\\Examples\\Readme\\';

    /** The reader's order the examples work on. */
    private const ORDER_ID = 42;

    private const EVENT_ID = '019a1b2c-3d4e-7f60-8a1b-2c3d4e5f6a7b';

    /**
     * The events the fake API holds, by id.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $events = [];

    /**
     * The webhook events dispatched while an example ran.
     *
     * @var list<WebhookEvent<array<mixed>>>
     */
    private array $dispatched = [];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $config = $app->make(Repository::class);
        $config->set('logging.default', 'null');
        $config->set('queue.default', 'sync');
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $config->set('flashpi.webhook.secret', self::fakeSecret());
    }

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/App/Models/Order.php';
        $this->database()->getSchemaBuilder()->create('orders', static function (Blueprint $table): void {
            $table->id();
            $table->string('flashpi_document_id')->nullable();
            $table->string('access_key')->nullable();
            $table->boolean('email_bounced')->default(false);
        });
        Order::query()->create(['id' => self::ORDER_ID, 'flashpi_document_id' => Reader::DOCUMENT_ID]);
        $this->database()->enableQueryLog();
        $this->database()->flushQueryLog();
        $this->events = [];
        $this->dispatched = [];
        Storage::fake();
        Http::preventStrayRequests();
        Http::fake(fn(Request $request): PromiseInterface => $this->answer($request));
        $this->dispatcher()->listen('*', function (string $name, array $payload): void {
            $event = $payload[0] ?? null;
            if ($event instanceof WebhookEvent) {
                $this->dispatched[] = $event;
            }
        });
    }

    /**
     * Every example file, by name.
     *
     * @return iterable<string, array{string}>
     */
    public static function examples(): iterable
    {
        $paths = glob(self::ROOT . '/' . Examples::DIRECTORY . '/e[ns]_*.php') ?: [];
        sort($paths);
        foreach ($paths as $path) {
            yield basename($path) => [$path];
        }
    }

    public function testBothReadmesHaveTheSameNumberOfExamples(): void
    {
        $names = array_keys(iterator_to_array(self::examples()));
        $spanish = array_filter($names, static fn(string $name): bool => str_starts_with($name, 'es_'));
        self::assertNotEmpty($spanish);
        self::assertCount(\count($spanish) * 2, $names, 'README.md and README.en.md have different examples');
    }

    #[DataProvider('examples')]
    public function testIsValidPhp(string $path): void
    {
        exec(escapeshellarg(\PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $status);

        self::assertSame(0, $status, implode("\n", $output));
    }

    public function testPhpstanAcceptsThemWithAConsumersConfiguration(): void
    {
        $command = escapeshellarg(\PHP_BINARY) . ' ' . escapeshellarg(self::ROOT . '/vendor/bin/phpstan')
            . ' analyse --error-format=json --no-progress --memory-limit=1G -c '
            . escapeshellarg(__DIR__ . '/phpstan.neon') . ' 2>/dev/null';
        $output = shell_exec($command);
        self::assertIsString($output);
        $result = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        self::assertIsArray($result['totals'] ?? null);
        $errors = [];
        foreach (\is_array($result['files'] ?? null) ? $result['files'] : [] as $file => $found) {
            foreach (\is_array($found) && \is_array($found['messages'] ?? null) ? $found['messages'] : [] as $message) {
                $errors[] = basename((string) $file) . ':' . print_r(\is_array($message) ? ($message['line'] ?? '') : '', true)
                    . ' ' . print_r(\is_array($message) ? ($message['message'] ?? '') : '', true);
            }
        }
        foreach (\is_array($result['errors'] ?? null) ? $result['errors'] : [] as $error) {
            $errors[] = print_r($error, true);
        }

        self::assertSame([], $errors);
        self::assertSame(0, $result['totals']['errors'] ?? null);
        self::assertSame(0, $result['totals']['file_errors'] ?? null);
    }

    #[DataProvider('examples')]
    public function testRunsInALaravelApp(string $path): void
    {
        $code = (string) file_get_contents($path);
        if (preg_match('/^final class (\w+) extends TestCase\b/m', $code, $test) === 1) {
            $this->runTestClass($path, $test[1]);

            return;
        }
        // es_01.php declares its classes in the namespace ...\Readme\Es01.
        $namespace = self::NAMESPACE . ucfirst(str_replace('_', '', basename($path, '.php'))) . '\\';
        $before = get_declared_classes();
        ob_start();
        try {
            (static function () use ($path): void {
                require $path;
            })();
        } finally {
            ob_end_clean();
        }
        $classes = array_filter(
            array_diff(get_declared_classes(), $before),
            static fn(string $class): bool => str_starts_with($class, $namespace),
        );
        foreach ($classes as $class) {
            $this->runClass($class);
        }

        self::assertGreaterThan(0, $this->traces(), basename($path) . ' ran, but did nothing this test can see');
    }

    /**
     * Runs a class of an example: a listener with its event, a job through the bus, anything
     * else through the container.
     *
     * @param class-string $class
     */
    private function runClass(string $class): void
    {
        $reflection = new \ReflectionClass($class);
        $method = match (true) {
            $reflection->hasMethod('handle') => 'handle',
            $reflection->hasMethod('__invoke') => '__invoke',
            default => null,
        };
        self::assertNotNull($method, "{$class} has neither handle() nor __invoke(): ExamplesTest cannot run it");
        $event = self::eventOf($reflection->getMethod($method));
        if ($event !== null) {
            $this->dispatcher()->listen($event, $class);
            foreach ([false, true] as $thin) {
                $traces = $this->traces();
                $this->dispatcher()->dispatch($this->event($event, $thin));
                // The dispatch itself is one trace: the listener must leave another.
                self::assertGreaterThan($traces + 1, $this->traces(), "{$class} did nothing with a " . ($thin ? 'thin' : 'full') . ' payload');
            }

            return;
        }
        $arguments = ['order' => Order::query()->findOrFail(self::ORDER_ID)];
        $instance = $this->app?->make($class, $arguments);
        self::assertIsObject($instance);
        if ($instance instanceof ShouldQueue) {
            $this->app?->make(Bus::class)->dispatchSync($instance);

            return;
        }
        $callable = [$instance, $method];
        self::assertIsCallable($callable);
        $this->app?->call($callable, $arguments);
    }

    /**
     * What the examples did so far that this test can see: the requests to the API, the queries
     * and the webhook events dispatched.
     */
    private function traces(): int
    {
        return \count(Http::recorded()) + \count($this->database()->getQueryLog()) + \count($this->dispatched);
    }

    /**
     * Runs the README's test class with PHPUnit, in a process of its own, from a copy named after
     * the class (PHPUnit loads a test file by its class's name).
     */
    private function runTestClass(string $path, string $class): void
    {
        $directory = sys_get_temp_dir() . '/flashpi-laravel-example-' . bin2hex(random_bytes(6));
        mkdir($directory);
        $file = "{$directory}/{$class}.php";
        copy($path, $file);
        try {
            $command = [
                \PHP_BINARY,
                self::ROOT . '/vendor/bin/phpunit',
                '--no-configuration',
                '--bootstrap',
                __DIR__ . '/bootstrap.php',
                '--fail-on-warning',
                '--fail-on-risky',
                '--fail-on-deprecation',
                '--fail-on-notice',
                '--colors=never',
                $file,
            ];
            exec(implode(' ', array_map('escapeshellarg', $command)) . ' 2>&1', $output, $status);
        } finally {
            unlink($file);
            rmdir($directory);
        }
        $output = implode("\n", $output);

        self::assertSame(0, $status, $output);
        self::assertMatchesRegularExpression('/^OK \(1 test, [1-9]\d* assertions?\)$/m', $output);
    }

    /**
     * The webhook event class a listener's method takes, or null when it takes none.
     *
     * @return class-string<WebhookEvent<array<mixed>>>|null
     */
    private static function eventOf(\ReflectionMethod $method): ?string
    {
        $type = ($method->getParameters()[0] ?? null)?->getType();
        if (!$type instanceof \ReflectionNamedType) {
            return null;
        }
        $class = $type->getName();

        return is_subclass_of($class, WebhookEvent::class) ? $class : null;
    }

    /**
     * An event of a class, as the route builds it from a delivery, whose whole event the fake API
     * holds.
     *
     * @param class-string<WebhookEvent<array<mixed>>> $class
     *
     * @return WebhookEvent<array<mixed>>
     */
    private function event(string $class, bool $thin): WebhookEvent
    {
        $id = $thin ? substr_replace(self::EVENT_ID, 'ffff', 9, 4) : self::EVENT_ID;
        $full = [
            'id' => $id,
            'type' => $class::TYPE ?? 'invoice.authorized',
            'environment' => 'test',
            'createdAt' => '2026-11-16T15:20:00.000Z',
            'data' => Reader::document(),
        ];
        $this->events[$id] = $full;

        return new $class($thin ? array_diff_key($full, ['data' => true]) : $full);
    }

    /**
     * The fake API's answer to a request.
     */
    private function answer(Request $request): PromiseInterface
    {
        $method = $request->method();
        $path = (string) parse_url($request->url(), \PHP_URL_PATH);
        $document = '/v1/documents/' . Reader::DOCUMENT_ID;
        if ($method === 'POST' && $path === '/v1/invoices') {
            return Http::response(Reader::document('processing'), 201);
        }
        if ($method === 'GET' && $path === '/v1/documents') {
            return Http::response(['data' => [Reader::document()], 'pagination' => ['nextCursor' => null, 'hasMore' => false]]);
        }
        if ($method === 'GET' && $path === $document) {
            return Http::response(Reader::document());
        }
        if ($method === 'GET' && $path === "{$document}/pdf") {
            return Http::response('%PDF-1.7 a RIDE %%EOF', 200, ['Content-Type' => 'application/pdf']);
        }
        if ($method === 'GET' && $path === "{$document}/xml") {
            $xml = '<?xml version="1.0" encoding="UTF-8"?><autorizacion><estado>AUTORIZADO</estado></autorizacion>';

            return Http::response($xml, 200, ['Content-Type' => 'application/xml', 'Flashpi-Xml-Kind' => 'authorized']);
        }
        if ($method === 'GET' && preg_match('#^/v1/events/([^/]+)$#', $path, $event) === 1 && isset($this->events[$event[1]])) {
            return Http::response($this->events[$event[1]]);
        }
        $problem = [
            'type' => 'https://flashpi.com.ec/docs/en/errors/not_found',
            'title' => 'Not found',
            'status' => 404,
            'detail' => "The fake API of the README examples does not know {$method} {$path}",
            'code' => 'not_found',
            'errors' => [],
        ];

        return Http::response((string) json_encode($problem), 404, ['Content-Type' => 'application/problem+json']);
    }

    private function database(): Connection
    {
        $database = $this->app?->make('db.connection');
        self::assertInstanceOf(Connection::class, $database);

        return $database;
    }

    private function dispatcher(): Dispatcher
    {
        return $this->app?->make(Dispatcher::class) ?? throw new \LogicException('The app is not running');
    }
}
