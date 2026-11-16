<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Internal;

use Flashpi\Exception\FlashpiException;
use Flashpi\Flashpi;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;

/**
 * Builds the Flashpi client from config/flashpi.php.
 *
 * It reads config() only, never env(): with `php artisan config:cache` Laravel does not load
 * .env, and the SDK's own fallback (FLASHPI_API_KEY from the process environment) is not used
 * either, so the cached configuration is the only source. The SDK checks every value (the key's
 * prefix and characters, the URL, the ranges); this class turns the strings .env holds into the
 * types the SDK takes.
 *
 * @internal
 */
final class ClientBuilder
{
    private function __construct() {}

    /**
     * The client of the container: built from the configuration the first time.
     *
     * @throws FlashpiException A missing or malformed setting.
     */
    public static function resolve(Container $container): Flashpi
    {
        return $container->make(Flashpi::class);
    }

    /**
     * @throws FlashpiException A missing or malformed setting.
     */
    public static function build(Container $container): Flashpi
    {
        $config = $container->make(Repository::class);

        return new Flashpi(
            apiKey: self::apiKey($config),
            baseUrl: self::text($config, 'base_url', 'FLASHPI_BASE_URL'),
            timeout: self::seconds($config),
            maxRetries: self::retries($config),
            language: self::language($config),
            httpClient: LaravelHttpClient::create($container),
        );
    }

    /**
     * The language of the API's messages: FLASHPI_LANGUAGE when set; otherwise 'es' when
     * config('app.locale') starts with 'es' (es, es_EC, es-EC, ...), 'en' for any other.
     *
     * The locale is read when the client is built (the first time the container resolves it).
     * A request's own `language` option still wins.
     *
     * @return 'en'|'es'
     *
     * @throws FlashpiException
     */
    private static function language(Repository $config): string
    {
        $language = self::text($config, 'language', 'FLASHPI_LANGUAGE');
        if ($language !== null) {
            return match (strtolower($language)) {
                'es' => 'es',
                'en' => 'en',
                default => throw new FlashpiException('config flashpi.language (FLASHPI_LANGUAGE) must be es or en'),
            };
        }
        $locale = $config->get('app.locale');

        return \is_string($locale) && str_starts_with(strtolower(trim($locale)), 'es') ? 'es' : 'en';
    }

    /**
     * @throws FlashpiException
     */
    private static function apiKey(Repository $config): string
    {
        $key = self::text($config, 'api_key', 'FLASHPI_API_KEY');
        if ($key === null) {
            throw new FlashpiException(
                'Missing Flashpi API key: set FLASHPI_API_KEY in your .env file (config flashpi.api_key). '
                . 'If you cache the configuration, run php artisan config:cache again after changing .env',
            );
        }

        return $key;
    }

    /**
     * A string setting, trimmed; null when it is unset or empty.
     *
     * @throws FlashpiException
     */
    private static function text(Repository $config, string $name, string $variable): ?string
    {
        $value = $config->get("flashpi.{$name}");
        if ($value === null) {
            return null;
        }
        if (!\is_string($value)) {
            throw new FlashpiException("config flashpi.{$name} ({$variable}) must be a string");
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @throws FlashpiException
     */
    private static function seconds(Repository $config): ?float
    {
        $value = $config->get('flashpi.timeout');
        if (\is_int($value) || \is_float($value)) {
            return (float) $value;
        }
        if ($value === null || (\is_string($value) && trim($value) === '')) {
            return null;
        }
        if (\is_string($value) && is_numeric(trim($value))) {
            return (float) trim($value);
        }

        throw new FlashpiException('config flashpi.timeout (FLASHPI_TIMEOUT) must be a number of seconds');
    }

    /**
     * @throws FlashpiException
     */
    private static function retries(Repository $config): ?int
    {
        $value = $config->get('flashpi.max_retries');
        if (\is_int($value)) {
            return $value;
        }
        if ($value === null || (\is_string($value) && trim($value) === '')) {
            return null;
        }
        if (\is_string($value) && preg_match('/\A\d+\z/', trim($value)) === 1) {
            return (int) trim($value);
        }

        throw new FlashpiException('config flashpi.max_retries (FLASHPI_MAX_RETRIES) must be a whole number, 0 or more');
    }
}
