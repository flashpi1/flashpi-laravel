<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Console;

use Flashpi\Exception\FlashpiException;
use Flashpi\Flashpi;
use Flashpi\Laravel\Internal\ClientBuilder;
use Flashpi\Types\ApiTypes;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `php artisan flashpi:webhook`: registers the app's webhook endpoint in Flashpi (APP_URL plus
 * the package's route, or --url) and shows its signing secret once, for FLASHPI_WEBHOOK_SECRET.
 *
 * Flashpi must reach the URL from the internet, over HTTPS: a URL that is not https, or whose
 * host is local or private (localhost, *.test, 10.0.0.1, ...), is refused before anything is
 * sent. Endpoints belong to the key's environment: an fp_test_ key registers the endpoint of the
 * test events.
 *
 * A new endpoint stays `pending_verification` until it answers Flashpi's challenge, which needs
 * the secret in the app's configuration: once it is there, `--verify` sends the challenge again.
 * Run again for a URL that is already registered, the command reports the endpoint's status and
 * creates nothing.
 *
 * @phpstan-import-type CreateWebhookEndpoint from ApiTypes
 * @phpstan-import-type WebhookEndpoint from ApiTypes
 */
#[AsCommand(name: 'flashpi:webhook')]
final class WebhookCommand extends Command
{
    /**
     * Host names that never reach the internet: RFC 2606 and RFC 6761 names, mDNS, ICANN's
     * private-use .internal, home networks, and the usual names of private networks.
     */
    private const LOCAL_SUFFIXES = [
        'localhost',
        'test',
        'example',
        'invalid',
        'local',
        'internal',
        'home.arpa',
        'lan',
        'localdomain',
        'corp',
        'intranet',
        'private',
    ];

    /** @var string */
    protected $signature = 'flashpi:webhook
        {--url= : The public HTTPS URL Flashpi sends the webhooks to (default: APP_URL plus the webhook route\'s path)}
        {--event=* : An event type to receive, such as invoice.authorized (repeat it for several; default: every event)}
        {--thin : Send thin payloads (the event\'s id, type and environment only)}
        {--verify : Send the verification challenge again to the endpoint already registered for the URL}';

    /** @var string */
    protected $description = 'Register the app\'s webhook endpoint in Flashpi and show its signing secret';

    public function handle(Repository $config): int
    {
        $url = $this->url($config);
        if ($url === null) {
            return self::FAILURE;
        }
        $problem = self::problem($url);
        if ($problem !== null) {
            $this->components->error("{$url} {$problem}");
            $this->line('  Flashpi must reach the URL from the internet: set APP_URL to the app\'s public https address, or pass --url.');
            $this->line('  For local development, a tunnel (ngrok, Expose, Cloudflare Tunnel) gives you one.');

            return self::FAILURE;
        }

        try {
            $flashpi = ClientBuilder::resolve($this->laravel);
            $endpoint = $this->find($flashpi, $url);
            if ($endpoint !== null) {
                return $this->existing($flashpi, $endpoint, $config);
            }
            if ($this->option('verify') === true) {
                $this->components->error("No webhook endpoint is registered for {$url} in the {$flashpi->environment} environment: run php artisan flashpi:webhook without --verify first.");

                return self::FAILURE;
            }

            return $this->register($flashpi, $url);
        } catch (FlashpiException $error) {
            $this->components->error(Errors::describe($error));

            return self::FAILURE;
        }
    }

    /**
     * What keeps Flashpi from delivering to a URL, or null when nothing does.
     */
    private static function problem(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return 'is not a URL';
        }
        if (strtolower($parts['scheme']) !== 'https') {
            return 'is not an https URL: Flashpi delivers webhooks over HTTPS only';
        }
        $host = strtolower(rtrim(trim($parts['host'], '[]'), '.'));
        if (filter_var($host, \FILTER_VALIDATE_IP) !== false) {
            // RFC 6890's global ranges: no private, loopback, link-local, shared or reserved one.
            $global = filter_var($host, \FILTER_VALIDATE_IP, \FILTER_FLAG_GLOBAL_RANGE) !== false;

            return $global ? null : 'points to a private or local address';
        }
        if (!str_contains($host, '.')) {
            return 'is not a public address';
        }
        foreach (self::LOCAL_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, ".{$suffix}")) {
                return 'is not a public address';
            }
        }

        return null;
    }

    /**
     * --url, or APP_URL plus the path of the package's route.
     */
    private function url(Repository $config): ?string
    {
        $option = $this->option('url');
        if (\is_string($option) && trim($option) !== '') {
            return trim($option);
        }
        if ($config->get('flashpi.webhook.enabled') === false) {
            $this->components->error('The package\'s webhook route is turned off (FLASHPI_WEBHOOK_ENABLED): pass the URL of your own route with --url.');

            return null;
        }
        $appUrl = $config->get('app.url');
        $path = $config->get('flashpi.webhook.path');
        if (!\is_string($appUrl) || trim($appUrl) === '') {
            $this->components->error('APP_URL is not set: set it to the app\'s public https address, or pass --url.');

            return null;
        }
        if (!\is_string($path) || trim($path, " \t\n\r\0\x0B/") === '') {
            $this->components->error('config flashpi.webhook.path (FLASHPI_WEBHOOK_PATH) is empty: set it, or pass --url.');

            return null;
        }

        return rtrim(trim($appUrl), '/') . '/' . ltrim(trim($path), '/');
    }

    /**
     * The endpoint already registered for the URL in the key's environment.
     *
     * @return WebhookEndpoint|null
     *
     * @throws FlashpiException
     */
    private function find(Flashpi $flashpi, string $url): ?array
    {
        foreach ($flashpi->webhookEndpoints->list() as $endpoint) {
            if ($endpoint['url'] === $url) {
                return $endpoint;
            }
        }

        return null;
    }

    /**
     * Registers the endpoint and shows its secret.
     *
     * @throws FlashpiException
     */
    private function register(Flashpi $flashpi, string $url): int
    {
        $events = $this->option('event');
        /** @var CreateWebhookEndpoint $body The API checks the event types: new ones need no new version of the package. */
        $body = [
            'url' => $url,
            'enabledEvents' => \is_array($events) && $events !== [] ? array_values($events) : ['*'],
            'payloadMode' => $this->option('thin') === true ? 'thin' : 'full',
        ];
        $endpoint = $flashpi->webhookEndpoints->create($body);
        $secret = $flashpi->webhookEndpoints->getSecret($endpoint['id']);

        $this->components->info("Registered {$endpoint['url']} for the {$endpoint['environment']} events ({$endpoint['id']}, {$endpoint['payloadMode']} payloads).");
        $this->line('  Add this line to the app\'s .env file. The secret is shown only now:');
        $this->newLine();
        $this->line("FLASHPI_WEBHOOK_SECRET={$secret['secret']}");
        $this->newLine();
        $this->line('  Flashpi activates the endpoint once it answers the verification challenge, signed with that secret.');
        $this->line('  Deploy the app with the secret (run php artisan config:cache again if you cache the configuration),');
        $this->line('  then run php artisan flashpi:webhook --verify to send the challenge again.');

        return self::SUCCESS;
    }

    /**
     * Reports an endpoint already registered for the URL, and sends its challenge again with
     * --verify.
     *
     * @param WebhookEndpoint $endpoint
     *
     * @throws FlashpiException
     */
    private function existing(Flashpi $flashpi, array $endpoint, Repository $config): int
    {
        $name = "{$endpoint['url']} ({$endpoint['id']}, {$endpoint['environment']} events)";
        if ($endpoint['status'] === 'active') {
            $this->components->info("{$name} is registered and active.");
            if ($this->option('verify') === true) {
                $this->line('  It needs no verification.');
            }

            return self::SUCCESS;
        }
        if ($endpoint['status'] === 'disabled') {
            $this->components->warn("{$name} is registered but disabled: enable it again in the Flashpi console.");

            return self::SUCCESS;
        }
        if ($this->option('verify') !== true) {
            $this->components->warn("{$name} is registered and waiting for verification.");
            $this->line('  Once the app\'s FLASHPI_WEBHOOK_SECRET holds its secret, run php artisan flashpi:webhook --verify.');

            return self::SUCCESS;
        }
        $secret = $config->get('flashpi.webhook.secret');
        if (!\is_string($secret) || trim($secret) === '') {
            $this->components->warn('FLASHPI_WEBHOOK_SECRET is not set here: the deployed app needs it to answer the challenge.');
        }
        $flashpi->webhookEndpoints->verify($endpoint['id']);
        $this->components->info("Flashpi sent the verification challenge to {$endpoint['url']} again.");
        $this->line('  Run php artisan flashpi:webhook in a few seconds to see whether the endpoint is active.');

        return self::SUCCESS;
    }
}
