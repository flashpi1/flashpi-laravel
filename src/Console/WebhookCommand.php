<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Console;

use Flashpi\Exception\FlashpiException;
use Flashpi\Flashpi;
use Flashpi\Laravel\Internal\ClientBuilder;
use Flashpi\Laravel\Internal\WebhookSettings;
use Flashpi\Types\ApiTypes;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `php artisan flashpi:webhook`: registers the app's webhook endpoint in Flashpi (APP_URL plus
 * the package's route, or --url) and shows its signing secret once, for FLASHPI_WEBHOOK_SECRET;
 * for an endpoint that already exists, it says where to read the secret again (the endpoint's
 * page in the Flashpi console, or webhookEndpoints->getSecret()).
 *
 * Flashpi must reach the URL from the internet, over HTTPS: a URL that is not https, or whose
 * host is local or private (localhost, *.test, 10.0.0.1, ...), is refused before anything is
 * sent. The host check is a list of names and ranges, with no DNS lookup, so --force skips it for
 * a public host the list gets wrong; the URL must still be https, and Flashpi's API refuses a URL
 * that is not public HTTPS (url_not_allowed) on its own. Endpoints belong to the key's
 * environment: an fp_test_ key registers the endpoint of the test events.
 *
 * A new endpoint stays `pending_verification` until it answers Flashpi's challenge, which needs
 * the secret in the app's configuration: once it is there, `--verify` sends the challenge again.
 * Run again for a URL that is already registered, the command reports the endpoint's status and
 * creates nothing. It changes no existing endpoint either: --thin and --event apply when the
 * endpoint is created, and when they ask for settings an existing endpoint lacks, the command
 * fails without sending anything more.
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

    /** The Flashpi console, where an endpoint's page shows its secret. */
    private const CONSOLE = 'https://flashpi.com.ec/app/';

    /** @var string */
    protected $signature = 'flashpi:webhook
        {--url= : The public HTTPS URL Flashpi sends the webhooks to (default: APP_URL plus the webhook route\'s path)}
        {--event=* : An event type the new endpoint receives, such as invoice.authorized (repeat it for several; default: every event)}
        {--thin : The new endpoint sends thin payloads (the event\'s id, type and environment only)}
        {--verify : Send the verification challenge again to the endpoint already registered for the URL}
        {--force : Skip the check that the URL\'s host is public, for a public host it gets wrong (the URL must still be https, and Flashpi\'s API checks it again)}';

    /** @var string */
    protected $description = 'Register the app\'s webhook endpoint in Flashpi and show its signing secret';

    public function handle(Repository $config): int
    {
        $url = $this->url($config);
        if ($url === null) {
            return self::FAILURE;
        }
        $problem = self::urlProblem($url);
        $hostProblem = $problem === null && $this->option('force') !== true ? self::hostProblem($url) : null;
        if ($problem !== null || $hostProblem !== null) {
            $this->components->error($url . ' ' . ($problem ?? $hostProblem));
            $this->line('  Flashpi must reach the URL from the internet: set APP_URL to the app\'s public https address, or pass --url.');
            $this->line('  For local development, a tunnel (ngrok, Expose, Cloudflare Tunnel) gives you one.');
            if ($hostProblem !== null) {
                $this->line('  If the host is public, pass --force: Flashpi\'s API checks the URL again and refuses one it cannot reach.');
            }

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
     * Why the URL is not an https URL, or null when it is.
     */
    private static function urlProblem(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return 'is not a URL';
        }
        if (strtolower($parts['scheme']) !== 'https') {
            return 'is not an https URL: Flashpi delivers webhooks over HTTPS only';
        }

        return null;
    }

    /**
     * Why the URL's host cannot be reached from the internet, or null when it can be: a
     * private or local IP, or a name of a local network. --force skips this check.
     */
    private static function hostProblem(string $url): ?string
    {
        $host = strtolower(rtrim(trim((string) parse_url($url, \PHP_URL_HOST), '[]'), '.'));
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
        if (!WebhookSettings::enabled($config)) {
            $this->components->error('The package\'s webhook route is turned off (FLASHPI_WEBHOOK_ENABLED): pass the URL of your own route with --url.');

            return null;
        }
        $appUrl = $config->get('app.url');
        $path = WebhookSettings::path($config);
        if (!\is_string($appUrl) || trim($appUrl) === '') {
            $this->components->error('APP_URL is not set: set it to the app\'s public https address, or pass --url.');

            return null;
        }
        if ($path === null) {
            $this->components->error('config flashpi.webhook.path (FLASHPI_WEBHOOK_PATH) is empty: set it, or pass --url.');

            return null;
        }

        return rtrim(trim($appUrl), '/') . $path;
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
        $this->line('  Add this line to the app\'s .env file. This command shows the secret only now; the endpoint\'s page under Webhooks in the Flashpi console (' . self::CONSOLE . ') shows it again:');
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
        $name = "{$endpoint['url']} ({$endpoint['id']}, {$endpoint['environment']} events, {$endpoint['payloadMode']} payloads)";
        $differences = $this->differences($endpoint);
        if ($differences !== []) {
            $this->components->error("{$name} is already registered with other settings: " . implode('; ', $differences) . '.');
            $this->line("  The command changes no existing endpoint: change it in the Flashpi console, or with Flashpi::webhookEndpoints()->update('{$endpoint['id']}', [...]).");

            return self::FAILURE;
        }
        $secretIsSet = self::secretIsSet($config);
        if ($endpoint['status'] === 'active') {
            $this->components->info("{$name} is registered and active.");
            if ($this->option('verify') === true) {
                $this->line('  It needs no verification.');
            }
            if (!$secretIsSet) {
                $this->line('  FLASHPI_WEBHOOK_SECRET is not set here. ' . self::whereTheSecretIs($endpoint));
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
            $this->line('  ' . self::whereTheSecretIs($endpoint));

            return self::SUCCESS;
        }
        if (!$secretIsSet) {
            $this->components->warn('FLASHPI_WEBHOOK_SECRET is not set here: the deployed app needs it to answer the challenge.');
            $this->line('  ' . self::whereTheSecretIs($endpoint));
        }
        $flashpi->webhookEndpoints->verify($endpoint['id']);
        $this->components->info("Flashpi sent the verification challenge to {$endpoint['url']} again.");
        $this->line('  Run php artisan flashpi:webhook in a few seconds to see whether the endpoint is active.');

        return self::SUCCESS;
    }

    /**
     * Where the secret of an existing endpoint can be read again: this command prints only the
     * secret of an endpoint it creates.
     *
     * @param WebhookEndpoint $endpoint
     */
    private static function whereTheSecretIs(array $endpoint): string
    {
        return 'Its secret is on the endpoint\'s page under Webhooks in the Flashpi console (' . self::CONSOLE . "), or in Flashpi::webhookEndpoints()->getSecret('{$endpoint['id']}').";
    }

    private static function secretIsSet(Repository $config): bool
    {
        $secret = $config->get('flashpi.webhook.secret');

        return \is_string($secret) && trim($secret) !== '';
    }

    /**
     * How an existing endpoint differs from what --thin and --event ask for: only the options
     * given count, and a run without --thin asks nothing of the payload mode (a flag cannot tell
     * "full" from "not given").
     *
     * @param WebhookEndpoint $endpoint
     *
     * @return list<string>
     */
    private function differences(array $endpoint): array
    {
        $differences = [];
        if ($this->option('thin') === true && $endpoint['payloadMode'] !== 'thin') {
            $differences[] = "it sends {$endpoint['payloadMode']} payloads, not thin";
        }
        $option = $this->option('event');
        $asked = \is_array($option) ? array_values(array_unique(array_filter($option, \is_string(...)))) : [];
        if ($asked !== []) {
            $enabled = $endpoint['enabledEvents'];
            sort($asked);
            sort($enabled);
            if ($asked !== $enabled) {
                $differences[] = 'it receives ' . implode(', ', $enabled) . ', not ' . implode(', ', $asked);
            }
        }

        return $differences;
    }
}
