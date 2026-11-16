<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Scripts\Package;

/**
 * The checks of what Composer installs: the archive GitHub makes of a tag, which `git archive`
 * writes from the same .gitattributes (flashpi-php: scripts/Package/Archive.php).
 *
 * - It holds the package (every PHP file of src/ and config/), composer.json, the license and the
 *   READMEs, and maybe the CHANGELOG, nothing else: no tests, scripts, docs or tool
 *   configuration.
 * - Its composer.json names the package, carries no version (Packagist takes it from the tag),
 *   autoloads Flashpi\Laravel\ from src/, registers the service provider for Laravel's package
 *   discovery, and requires PHP, the SDK and Laravel's own components (illuminate/*) only.
 * - The README Packagist shows has no relative link: packagist.org cannot follow one.
 */
final class Archive
{
    public const PACKAGE = 'flashpi/flashpi-laravel';

    public const PROVIDER = 'Flashpi\\Laravel\\FlashpiServiceProvider';

    public const REQUIRED = ['LICENSE', 'README.en.md', 'README.md', 'composer.json'];

    public const OPTIONAL = ['CHANGELOG.md'];

    /** What composer.json must require, besides Laravel's components. */
    public const REQUIRE = ['php' => '^8.2', 'flashpi/flashpi-php' => '^0.1'];

    /** The Laravel majors the package supports, as each illuminate/* requirement must name them. */
    public const LARAVEL = '/\A\^12\.\d+(?:\.\d+)? \|\| \^13\.\d+(?:\.\d+)?\z/';

    private const FENCED_CODE = '/^```.*?^```/ms';

    private const INLINE_LINK = '/\]\(\s*<?([^)\s>]+)/';

    private const LINK_DEFINITION = '/^ {0,3}\[[^\]]+\]:\s*<?([^\s>]+)/m';

    private const ABSOLUTE = ['https://', 'http://', 'mailto:', '#'];

    private function __construct() {}

    /**
     * What is wrong with an archive.
     *
     * @param array<string, string> $files The files of the archive (relative to its top
     *                                     directory) and their contents.
     * @param list<string> $sources The PHP files of src/ and config/ in the repository.
     *
     * @return list<string>
     */
    public static function problems(array $files, array $sources): array
    {
        $names = array_keys($files);
        $required = [...self::REQUIRED, ...$sources];
        $problems = [];
        foreach (array_diff($required, $names) as $name) {
            $problems[] = "Missing from the archive: {$name}";
        }
        foreach (array_diff($names, $required, self::OPTIONAL) as $name) {
            $problems[] = "Unexpected in the archive: {$name}";
        }
        if (isset($files['composer.json'])) {
            $problems = [...$problems, ...self::composer($files['composer.json'])];
        }
        foreach (self::relativeLinks($files['README.md'] ?? '') as $link) {
            $problems[] = "README.md links to {$link}: use an absolute URL, since packagist.org cannot follow a relative link";
        }

        return $problems;
    }

    /**
     * The link and image targets of a Markdown text that are not absolute URLs or anchors.
     *
     * @return list<string>
     */
    public static function relativeLinks(string $markdown): array
    {
        $text = (string) preg_replace(self::FENCED_CODE, '', $markdown);
        preg_match_all(self::INLINE_LINK, $text, $inline);
        preg_match_all(self::LINK_DEFINITION, $text, $definitions);
        $links = [];
        foreach ([...$inline[1], ...$definitions[1]] as $target) {
            $absolute = array_filter(self::ABSOLUTE, static fn(string $prefix): bool => str_starts_with($target, $prefix));
            if ($absolute === []) {
                $links[] = $target;
            }
        }

        return $links;
    }

    /**
     * The files of a tar or zip archive with their contents, relative to its top directory when
     * every file sits in one (`git archive --prefix=...`).
     *
     * @return array<string, string>
     */
    public static function read(string $path): array
    {
        $files = [];
        $archive = new \PharData($path);
        foreach (new \RecursiveIteratorIterator($archive) as $file) {
            \assert($file instanceof \PharFileInfo);
            $name = substr($file->getPathname(), \strlen('phar://' . $archive->getPath() . '/'));
            if ($name !== 'pax_global_header') {
                $files[$name] = $file->getContent();
            }
        }
        $tops = array_unique(array_map(static fn(string $name): string => strtok($name, '/') ?: $name, array_keys($files)));
        $top = reset($tops);
        if (\count($tops) === 1 && $top !== false && !isset($files[$top])) {
            $prefix = "{$top}/";
            $files = array_combine(
                array_map(static fn(string $name): string => substr($name, \strlen($prefix)), array_keys($files)),
                $files,
            );
        }
        ksort($files);

        return $files;
    }

    /**
     * The PHP files of src/ and config/ in a repository, relative to it.
     *
     * @return list<string>
     */
    public static function sources(string $root): array
    {
        $sources = [];
        foreach (['config', 'src'] as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$root}/{$directory}", \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                \assert($file instanceof \SplFileInfo);
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $sources[] = "{$directory}/" . substr($file->getPathname(), \strlen("{$root}/{$directory}/"));
                }
            }
        }
        sort($sources);

        return $sources;
    }

    /**
     * @return list<string>
     */
    private static function composer(string $json): array
    {
        $composer = json_decode($json, true);
        if (!\is_array($composer)) {
            return ['composer.json is not a JSON object'];
        }
        $problems = [];
        if (($composer['name'] ?? null) !== self::PACKAGE) {
            $problems[] = 'composer.json must name ' . self::PACKAGE;
        }
        if (\array_key_exists('version', $composer)) {
            $problems[] = 'composer.json must carry no version: Packagist takes it from the tag';
        }
        $require = \is_array($composer['require'] ?? null) ? $composer['require'] : [];
        foreach (self::REQUIRE as $name => $constraint) {
            if (($require[$name] ?? null) !== $constraint) {
                $problems[] = "composer.json must require {$name} {$constraint}";
            }
        }
        foreach ($require as $name => $constraint) {
            if (isset(self::REQUIRE[$name])) {
                continue;
            }
            if (!str_starts_with((string) $name, 'illuminate/')) {
                $problems[] = "composer.json must not require {$name}: the package needs PHP, flashpi/flashpi-php and Laravel's components only";
            } elseif (!\is_string($constraint) || preg_match(self::LARAVEL, $constraint) !== 1) {
                $problems[] = "composer.json must require {$name} for Laravel 12 and 13 (^12.x || ^13.x)";
            }
        }
        if (($composer['autoload'] ?? null) !== ['psr-4' => ['Flashpi\\Laravel\\' => 'src/']]) {
            $problems[] = 'composer.json must autoload Flashpi\\Laravel\\ from src/ only';
        }
        $extra = \is_array($composer['extra'] ?? null) ? $composer['extra'] : [];
        $laravel = \is_array($extra['laravel'] ?? null) ? $extra['laravel'] : [];
        if (($laravel['providers'] ?? null) !== [self::PROVIDER]) {
            $problems[] = 'composer.json must register ' . self::PROVIDER . ' for package discovery (extra.laravel.providers)';
        }

        return $problems;
    }
}
