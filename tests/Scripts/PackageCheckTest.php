<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Tests\Scripts;

use Flashpi\Laravel\Scripts\Package\Archive;
use PHPUnit\Framework\TestCase;

/**
 * The check of what Composer installs from a tag (scripts/package_check.php), on hand-made
 * archives (flashpi-php: tests/Scripts/PackageCheckTest.php).
 */
final class PackageCheckTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private const SOURCES = ['config/flashpi.php', 'src/FlashpiServiceProvider.php', 'src/Internal/ClientBuilder.php'];

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/flashpi-laravel-package-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob("{$this->directory}/*") ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testAcceptsThePackageWithItsLicenseAndReadmes(): void
    {
        self::assertSame([], Archive::problems(self::files(), self::SOURCES));
        self::assertSame([], Archive::problems([...self::files(), 'CHANGELOG.md' => '# Changelog'], self::SOURCES));
    }

    public function testReportsWhatIsMissingAndWhatIsExtra(): void
    {
        $files = self::files();
        unset($files['config/flashpi.php'], $files['LICENSE']);
        $files['tests/TestCase.php'] = '<?php';
        $files['phpunit.xml.dist'] = '<phpunit/>';
        self::assertSame([
            'Missing from the archive: LICENSE',
            'Missing from the archive: config/flashpi.php',
            'Unexpected in the archive: tests/TestCase.php',
            'Unexpected in the archive: phpunit.xml.dist',
        ], Archive::problems($files, self::SOURCES));
    }

    public function testChecksComposerJson(): void
    {
        $composer = self::composer();
        $composer['version'] = '0.1.0';
        $composer['name'] = 'flashpi/other';
        $composer['require']['guzzlehttp/guzzle'] = '^7.15.2';
        $composer['require']['illuminate/routing'] = '^12.0';
        $composer['require']['flashpi/flashpi-php'] = '^0.2';
        $composer['autoload']['files'] = ['src/helpers.php'];
        $composer['extra'] = [];
        self::assertSame([
            'composer.json must name flashpi/flashpi-laravel',
            'composer.json must carry no version: Packagist takes it from the tag',
            'composer.json must require flashpi/flashpi-php ^0.1 || ^0.2',
            'composer.json must not require guzzlehttp/guzzle: the package needs PHP, flashpi/flashpi-php and Laravel\'s components only',
            'composer.json must require illuminate/routing for Laravel 12 and 13 (^12.x || ^13.x)',
            'composer.json must autoload Flashpi\\Laravel\\ from src/ only',
            'composer.json must register Flashpi\\Laravel\\FlashpiServiceProvider for package discovery (extra.laravel.providers)',
        ], Archive::problems([...self::files(), 'composer.json' => (string) json_encode($composer)], self::SOURCES));
        self::assertSame(['composer.json is not a JSON object'], Archive::problems([...self::files(), 'composer.json' => 'null'], self::SOURCES));
    }

    public function testChecksTheComposerJsonOfTheRepository(): void
    {
        $files = [...self::files(), 'composer.json' => (string) file_get_contents(self::ROOT . '/composer.json')];

        self::assertSame([], Archive::problems($files, self::SOURCES));
    }

    public function testRefusesARelativeLinkInTheReadmePackagistShows(): void
    {
        $readme = "# Flashpi\n\nSee [the docs](docs/decisions.md) and ![logo](./logo.png).\n";
        self::assertSame([
            'README.md links to docs/decisions.md: use an absolute URL, since packagist.org cannot follow a relative link',
            'README.md links to ./logo.png: use an absolute URL, since packagist.org cannot follow a relative link',
        ], Archive::problems([...self::files(), 'README.md' => $readme], self::SOURCES));
    }

    public function testFindsRelativeLinksOutsideCode(): void
    {
        $markdown = "[a](https://x.ec) [b](#errors) [c](mailto:a@x.ec) [d]( <LICENSE> )\n"
            . "```php\n\$a = [\$b](c);\n```\n[e]: CONTRIBUTING.md\n   [f]: http://x.ec\n";
        self::assertSame(['LICENSE', 'CONTRIBUTING.md'], Archive::relativeLinks($markdown));
    }

    public function testReadsATarWithItsTopDirectoryOrAZipWithout(): void
    {
        $tar = new \PharData("{$this->directory}/package.tar");
        $zip = new \PharData("{$this->directory}/package.zip");
        foreach (self::files() as $name => $contents) {
            $tar->addFromString("flashpi-laravel/{$name}", $contents);
            $zip->addFromString($name, $contents);
        }
        $expected = self::files();
        ksort($expected);
        self::assertSame($expected, Archive::read("{$this->directory}/package.tar"));
        self::assertSame($expected, Archive::read("{$this->directory}/package.zip"));
    }

    public function testListsThePhpFilesOfThePackage(): void
    {
        $sources = Archive::sources(self::ROOT);
        self::assertContains('config/flashpi.php', $sources);
        self::assertContains('src/FlashpiServiceProvider.php', $sources);
        self::assertContains('src/Facades/Flashpi.php', $sources);
        foreach ($sources as $source) {
            self::assertMatchesRegularExpression('/^(config|src)\/[\w\/]+\.php$/', $source);
        }
    }

    /**
     * A complete archive.
     *
     * @return array<string, string>
     */
    private static function files(): array
    {
        $files = [
            'LICENSE' => 'MIT',
            'README.md' => '# Flashpi',
            'README.en.md' => '# Flashpi',
            'composer.json' => (string) json_encode(self::composer()),
        ];
        foreach (self::SOURCES as $source) {
            $files[$source] = '<?php';
        }

        return $files;
    }

    /**
     * @return array{
     *     name: string,
     *     require: array<string, string>,
     *     autoload: array<string, mixed>,
     *     extra: array<string, mixed>,
     * }
     */
    private static function composer(): array
    {
        return [
            'name' => 'flashpi/flashpi-laravel',
            'require' => [
                'php' => '^8.2',
                'flashpi/flashpi-php' => '^0.1 || ^0.2',
                'illuminate/support' => '^12.0 || ^13.0',
                'illuminate/http' => '^12.40.1 || ^13.2',
            ],
            'autoload' => ['psr-4' => ['Flashpi\\Laravel\\' => 'src/']],
            'extra' => ['laravel' => ['providers' => ['Flashpi\\Laravel\\FlashpiServiceProvider']]],
        ];
    }
}
