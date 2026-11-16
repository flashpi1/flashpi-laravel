<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Console;

use Flashpi\Exception\AuthenticationException;
use Flashpi\Exception\FlashpiException;
use Flashpi\Exception\PermissionDeniedException;
use Flashpi\Flashpi;
use Flashpi\Laravel\Internal\ClientBuilder;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `php artisan flashpi:check`: builds the client from the configuration, asks the API for its
 * status, and makes one call that needs the key (the newest event), so a key the API refuses
 * fails here rather than on the first invoice.
 */
#[AsCommand(name: 'flashpi:check')]
final class CheckCommand extends Command
{
    /** @var string */
    protected $signature = 'flashpi:check';

    /** @var string */
    protected $description = 'Check the Flashpi API key: its environment (test or live) and the API status';

    public function handle(Repository $config): int
    {
        try {
            $flashpi = ClientBuilder::resolve($this->laravel);
        } catch (FlashpiException $error) {
            $this->components->error($error->getMessage());

            return self::FAILURE;
        }

        $baseUrl = $config->get('flashpi.base_url');
        $this->components->twoColumnDetail('Environment', $flashpi->environment === 'test'
            ? 'test (fp_test_ key: the SRI\'s test environment)'
            : 'live (fp_live_ key: real documents in production)');
        $this->components->twoColumnDetail('API', \is_string($baseUrl) && trim($baseUrl) !== '' ? trim($baseUrl) : Flashpi::DEFAULT_BASE_URL);

        try {
            $status = $flashpi->status->get();
        } catch (FlashpiException $error) {
            $this->components->error('The Flashpi API did not answer: ' . Errors::describe($error));

            return self::FAILURE;
        }
        $this->components->twoColumnDetail('Status', $status['status']);

        try {
            $flashpi->events->list(['limit' => 1]);
        } catch (AuthenticationException|PermissionDeniedException $error) {
            $this->components->twoColumnDetail('API key', '<fg=red>refused</>');
            $this->components->error('The API refused the key: ' . Errors::describe($error));

            return self::FAILURE;
        } catch (FlashpiException $error) {
            $this->components->error('Could not check the key: ' . Errors::describe($error));

            return self::FAILURE;
        }
        $this->components->twoColumnDetail('API key', '<fg=green>accepted</>');

        $secret = $config->get('flashpi.webhook.secret');
        if (\is_string($secret) && trim($secret) !== '') {
            $this->components->twoColumnDetail('Webhook secret', 'set');
        } else {
            $this->components->twoColumnDetail('Webhook secret', '<fg=yellow>not set</>');
            $this->components->warn('FLASHPI_WEBHOOK_SECRET is not set: run php artisan flashpi:webhook to register the app\'s webhook endpoint.');
        }

        return self::SUCCESS;
    }
}
