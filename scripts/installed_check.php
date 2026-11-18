<?php

declare(strict_types=1);

/*
 * Uses the installed package the way a Laravel app would. CI runs it in a new Laravel app
 * (`composer create-project laravel/laravel`) that required flashpi/flashpi-laravel from its
 * archive (a path repository) and ran `php artisan flashpi:install`:
 *
 *     php scripts/installed_check.php /path/to/the/app
 *
 * It boots the app with a fake fp_test_ key and a fake whsec_ secret in its environment, then
 * fails when the package is not loaded from the app's vendor directory, when the archive brought
 * more than the package, when package discovery did not register it, when the client does not
 * send through Laravel's HTTP client (Http::fake()), or when the webhook route does not answer
 * a signed challenge and dispatch a signed event.
 */

use Flashpi\Laravel\Events\InvoiceAuthorized;
use Flashpi\Laravel\Facades\Flashpi;
use Flashpi\Laravel\FlashpiServiceProvider;
use Flashpi\Version;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

$arguments = [];
foreach (\is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [] as $argument) {
    if (\is_string($argument)) {
        $arguments[] = $argument;
    }
}
$project = $arguments[1] ?? null;
if ($project === null || !is_file("{$project}/vendor/autoload.php") || !is_file("{$project}/bootstrap/app.php")) {
    fwrite(\STDERR, "Usage: php scripts/installed_check.php <Laravel app with vendor/autoload.php>\n");
    exit(2);
}
$project = (string) realpath($project);

// The app's configuration reads these, as it would read them from its .env file; the array cache
// keeps the check off the app's database. (No random_bytes(): with the lowest dependencies,
// random_compat declares it without a return type.)
$random = hash('sha256', uniqid('', true), true);
$secret = 'whsec_' . base64_encode($random);
$environment = [
    'FLASHPI_API_KEY' => 'fp_test_' . bin2hex($random),
    'FLASHPI_WEBHOOK_SECRET' => $secret,
    'CACHE_STORE' => 'array',
];
foreach ($environment as $name => $value) {
    putenv("{$name}={$value}");
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

require "{$project}/vendor/autoload.php";

$problems = [];
$package = "{$project}/vendor/flashpi/flashpi-laravel";
$location = (string) (new ReflectionClass(FlashpiServiceProvider::class))->getFileName();
if (!str_starts_with($location, "{$package}/")) {
    $problems[] = "The package was loaded from {$location}, not from {$package}";
}
foreach (['.github', 'docs', 'scripts', 'tests', 'phpstan.neon.dist', 'phpunit.xml.dist'] as $extra) {
    if (file_exists("{$package}/{$extra}")) {
        $problems[] = "The archive brought {$extra}";
    }
}
if (!is_file("{$project}/config/flashpi.php")) {
    $problems[] = 'php artisan flashpi:install did not publish config/flashpi.php';
}

$app = require "{$project}/bootstrap/app.php";
if (!$app instanceof Application) {
    fwrite(\STDERR, "bootstrap/app.php did not return a Laravel application\n");
    exit(1);
}
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
if ($app->getProvider(FlashpiServiceProvider::class) === null) {
    $problems[] = 'Package discovery did not register ' . FlashpiServiceProvider::class;
}

// The client, through the facade, sends with Laravel's HTTP client.
Http::preventStrayRequests();
Http::fake(['api.flashpi.com.ec/v1/status' => Http::response(['status' => 'operational'])]);
if (Flashpi::environment() !== 'test' || Flashpi::status()->get()['status'] !== 'operational') {
    $problems[] = 'The client did not read the status through Http::fake()';
}

// The webhook route: a signed challenge, then a signed event.
$received = [];
$app->make(Dispatcher::class)->listen(InvoiceAuthorized::class, static function (InvoiceAuthorized $event) use (&$received): void {
    $received[] = $event->id;
});
/**
 * Sends a delivery of an event to the webhook route, signed as Flashpi signs it.
 *
 * @param array<string, mixed> $event
 */
$deliver = static function (array $event, string $id) use ($kernel, $secret): Response {
    $body = (string) json_encode($event, \JSON_UNESCAPED_SLASHES);
    $timestamp = (string) time();
    $key = (string) base64_decode(substr($secret, \strlen('whsec_')), true);
    $signature = 'v1,' . base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));
    $request = Request::create('/flashpi/webhook', 'POST', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_WEBHOOK_ID' => $id,
        'HTTP_WEBHOOK_TIMESTAMP' => $timestamp,
        'HTTP_WEBHOOK_SIGNATURE' => $signature,
    ], content: $body);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    return $response;
};
$challenge = $deliver([
    'id' => '019a1b2c-ffff-7f60-8a1b-2c3d4e5f6a7b',
    'type' => 'webhook_endpoint.verification',
    'environment' => 'test',
    'createdAt' => '2026-11-16T15:20:00.000Z',
    'data' => ['webhookEndpointId' => 'we_installed_check', 'challenge' => 'installed-check'],
], '019a1b2c-ffff-7f60-8a1b-2c3d4e5f6a7b');
if ($challenge->getStatusCode() !== 200 || $challenge->getContent() !== '{"challenge":"installed-check"}') {
    $problems[] = "The webhook route answered the challenge with {$challenge->getStatusCode()}: {$challenge->getContent()}";
}
$delivery = $deliver([
    'id' => '019a1b2c-3d4e-7f60-8a1b-2c3d4e5f6a7b',
    'type' => 'invoice.authorized',
    'environment' => 'test',
    'createdAt' => '2026-11-16T15:20:00.000Z',
    'data' => ['id' => '019a1b2c-0000-7f60-8a1b-2c3d4e5f6a7b', 'type' => 'invoice', 'status' => 'authorized'],
], '019a1b2c-3d4e-7f60-8a1b-2c3d4e5f6a7b');
if ($delivery->getStatusCode() !== 204 || $received !== ['019a1b2c-3d4e-7f60-8a1b-2c3d4e5f6a7b']) {
    $problems[] = "The webhook route answered an invoice.authorized delivery with {$delivery->getStatusCode()} and dispatched " . \count($received) . ' InvoiceAuthorized events';
}

foreach ($problems as $problem) {
    fwrite(\STDERR, "{$problem}\n");
}
if ($problems !== []) {
    exit(1);
}
fwrite(\STDOUT, "flashpi/flashpi-laravel works from {$package} with flashpi/flashpi-php " . Version::VERSION . " in Laravel {$app->version()} on PHP " . \PHP_VERSION . "\n");
