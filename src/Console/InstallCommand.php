<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `php artisan flashpi:install`: publishes config/flashpi.php and lists the variables to add to
 * .env. It writes nothing else: .env is the app's.
 */
#[AsCommand(name: 'flashpi:install')]
final class InstallCommand extends Command
{
    /** @var string */
    protected $signature = 'flashpi:install
        {--force : Overwrite config/flashpi.php if it exists}';

    /** @var string */
    protected $description = 'Publish config/flashpi.php and show the .env variables Flashpi needs';

    public function handle(Repository $config): int
    {
        $path = $this->laravel->configPath('flashpi.php');
        $existed = is_file($path);
        if ($existed && $this->option('force') !== true) {
            $this->components->info('config/flashpi.php already exists: kept as it is (--force overwrites it).');
        } else {
            $this->callSilently('vendor:publish', ['--tag' => 'flashpi-config', '--force' => true]);
            if (!is_file($path)) {
                $this->components->error('Could not publish config/flashpi.php.');

                return self::FAILURE;
            }
            $this->components->info($existed ? 'config/flashpi.php overwritten.' : 'config/flashpi.php published.');
        }

        $missing = [];
        if (self::blank($config->get('flashpi.api_key'))) {
            $missing[] = 'FLASHPI_API_KEY=fp_test_...';
        }
        if (self::blank($config->get('flashpi.webhook.secret'))) {
            $missing[] = 'FLASHPI_WEBHOOK_SECRET=whsec_...';
        }
        if ($missing === []) {
            $this->components->info('FLASHPI_API_KEY and FLASHPI_WEBHOOK_SECRET are already set.');
        } else {
            $this->components->info('Add to your .env file:');
            foreach ($missing as $line) {
                $this->line($line);
            }
            $this->newLine();
            $this->components->bulletList([
                'FLASHPI_API_KEY: create an API key in the console, https://flashpi.com.ec/app/ (an fp_test_ key issues in the SRI\'s test environment).',
                'FLASHPI_WEBHOOK_SECRET: php artisan flashpi:webhook registers the app\'s webhook endpoint and shows its secret.',
                'Optional: FLASHPI_LANGUAGE=es or en (default: from the app\'s locale).',
            ]);
        }
        $this->components->info('Then check the key with php artisan flashpi:check.');

        return self::SUCCESS;
    }

    private static function blank(mixed $value): bool
    {
        return !\is_string($value) || trim($value) === '';
    }
}
