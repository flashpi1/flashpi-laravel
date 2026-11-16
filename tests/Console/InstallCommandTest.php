<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Tests\Console;

use Flashpi\Laravel\FlashpiServiceProvider;
use Flashpi\Laravel\Tests\TestCase;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

/**
 * `php artisan flashpi:install`, publishing into a config directory of its own.
 */
final class InstallCommandTest extends TestCase
{
    private string $configPath = '';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $this->configPath = sys_get_temp_dir() . '/flashpi-laravel-config-' . bin2hex(random_bytes(6));
        mkdir($this->configPath);
        \assert($app instanceof Application);
        $app->useConfigPath($this->configPath);
    }

    protected function tearDown(): void
    {
        foreach (glob("{$this->configPath}/*") ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->configPath)) {
            rmdir($this->configPath);
        }
        parent::tearDown();
    }

    public function testPublishesTheConfigurationAndListsTheVariables(): void
    {
        $this->config()->set('flashpi.api_key', null);

        $this->command('flashpi:install')
            ->expectsOutputToContain('config/flashpi.php published.')
            ->expectsOutputToContain('Add to your .env file:')
            ->expectsOutput('FLASHPI_API_KEY=fp_test_...')
            ->expectsOutput('FLASHPI_WEBHOOK_SECRET=whsec_...')
            ->expectsOutputToContain('https://flashpi.com.ec/app/')
            ->expectsOutputToContain('php artisan flashpi:check')
            ->assertSuccessful();

        self::assertFileEquals(FlashpiServiceProvider::CONFIG, "{$this->configPath}/flashpi.php");
    }

    public function testListsOnlyTheMissingVariables(): void
    {
        $this->command('flashpi:install')
            ->expectsOutput('FLASHPI_WEBHOOK_SECRET=whsec_...')
            ->doesntExpectOutput('FLASHPI_API_KEY=fp_test_...')
            ->assertSuccessful();
    }

    public function testSaysWhenEveryVariableIsSet(): void
    {
        $this->config()->set('flashpi.webhook.secret', self::fakeSecret());

        $this->command('flashpi:install')
            ->expectsOutputToContain('FLASHPI_API_KEY and FLASHPI_WEBHOOK_SECRET are already set.')
            ->doesntExpectOutputToContain('Add to your .env file:')
            ->assertSuccessful();
    }

    public function testKeepsAnExistingConfiguration(): void
    {
        file_put_contents("{$this->configPath}/flashpi.php", "<?php\n\nreturn ['api_key' => 'mine'];\n");

        $this->command('flashpi:install')
            ->expectsOutputToContain('config/flashpi.php already exists: kept as it is (--force overwrites it).')
            ->assertSuccessful();

        self::assertStringContainsString("'mine'", (string) file_get_contents("{$this->configPath}/flashpi.php"));
    }

    public function testOverwritesAnExistingConfigurationWithForce(): void
    {
        file_put_contents("{$this->configPath}/flashpi.php", "<?php\n\nreturn ['api_key' => 'mine'];\n");

        $this->command('flashpi:install', ['--force' => true])
            ->expectsOutputToContain('config/flashpi.php overwritten.')
            ->assertSuccessful();

        self::assertFileEquals(FlashpiServiceProvider::CONFIG, "{$this->configPath}/flashpi.php");
    }

    public function testFailsWhenTheConfigurationCannotBePublished(): void
    {
        $kernel = $this->app?->make(Kernel::class);
        self::assertInstanceOf(ConsoleKernel::class, $kernel);
        $kernel->registerCommand(new class extends Command {
            /** @var string */
            protected $signature = 'vendor:publish {--tag=*} {--force}';

            public function handle(): int
            {
                return self::FAILURE;
            }
        });

        $this->command('flashpi:install')
            ->expectsOutputToContain('Could not publish config/flashpi.php.')
            ->assertFailed();
    }
}
