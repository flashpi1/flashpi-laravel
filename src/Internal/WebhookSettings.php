<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Internal;

use Flashpi\Exception\FlashpiException;
use Illuminate\Contracts\Config\Repository;

/**
 * The webhook settings of config/flashpi.php (`flashpi.webhook`), read the same way by the
 * service provider, the webhook controller and `php artisan flashpi:webhook`.
 *
 * Values from .env arrive as strings: they are trimmed and turned into what the package needs.
 * The route's settings never throw, so a bad value cannot stop the app from booting; the
 * delivery's settings (the secret, the cache's time to live) throw a FlashpiException that names
 * the setting, never its value, and the delivery is answered 500 so that Flashpi retries it.
 *
 * Laravel merges the package's configuration with the app's one level deep: an app's
 * config/flashpi.php that lacks a nested key gives null here, never the package's default, so
 * every nested key has its default in this class too.
 *
 * @internal
 */
final class WebhookSettings
{
    /** The name of the package's webhook route. */
    public const ROUTE = 'flashpi.webhook';

    public const DEFAULT_PATH = '/flashpi/webhook';

    /** Seconds a delivery's webhook-id is remembered: 24 hours. */
    public const DEFAULT_TTL = 86400;

    private const FALSE = ['false', '(false)', '0', 'off', 'no'];

    private function __construct() {}

    /**
     * Whether the package registers its webhook route (FLASHPI_WEBHOOK_ENABLED): anything but
     * false, 0, off or no, so an unset or empty value keeps it on.
     */
    public static function enabled(Repository $config): bool
    {
        $value = $config->get('flashpi.webhook.enabled');

        return match (true) {
            \is_bool($value) => $value,
            \is_int($value) => $value !== 0,
            \is_string($value) => !\in_array(strtolower(trim($value)), self::FALSE, true),
            default => true,
        };
    }

    /**
     * The path of the route (FLASHPI_WEBHOOK_PATH) with one leading slash and none at the end:
     * `/flashpi/webhook` when it is unset or empty; null (no route) when it is only slashes or
     * not a string.
     */
    public static function path(Repository $config): ?string
    {
        $value = $config->get('flashpi.webhook.path');
        if ($value === null || (\is_string($value) && trim($value) === '')) {
            return self::DEFAULT_PATH;
        }
        $path = \is_string($value) ? trim(trim($value), '/') : '';

        return $path === '' ? null : "/{$path}";
    }

    /**
     * The route's middleware: a name, a list of names, or none.
     *
     * @return list<string>
     */
    public static function middleware(Repository $config): array
    {
        $value = $config->get('flashpi.webhook.middleware');
        $names = \is_string($value) ? [$value] : (\is_array($value) ? $value : []);

        return array_values(array_filter($names, static fn(mixed $name): bool => \is_string($name) && trim($name) !== ''));
    }

    /**
     * The endpoint's signing secret (FLASHPI_WEBHOOK_SECRET), trimmed.
     *
     * @throws FlashpiException When it is not set.
     */
    public static function secret(Repository $config): string
    {
        $value = $config->get('flashpi.webhook.secret');
        if ($value !== null && !\is_string($value)) {
            throw new FlashpiException('config flashpi.webhook.secret (FLASHPI_WEBHOOK_SECRET) must be a string');
        }
        $secret = trim((string) $value);
        if ($secret === '') {
            throw new FlashpiException(
                'Missing Flashpi webhook secret: set FLASHPI_WEBHOOK_SECRET in your .env file (config flashpi.webhook.secret) '
                . 'to the endpoint\'s whsec_ secret, which php artisan flashpi:webhook shows. '
                . 'If you cache the configuration, run php artisan config:cache again after changing .env',
            );
        }

        return $secret;
    }

    /**
     * The cache store that remembers the deliveries (FLASHPI_WEBHOOK_CACHE_STORE), null for the
     * app's default store.
     *
     * @throws FlashpiException
     */
    public static function store(Repository $config): ?string
    {
        $value = $config->get('flashpi.webhook.deduplication.store');
        if ($value !== null && !\is_string($value)) {
            throw new FlashpiException('config flashpi.webhook.deduplication.store (FLASHPI_WEBHOOK_CACHE_STORE) must be the name of a cache store');
        }
        $store = trim((string) $value);

        return $store === '' ? null : $store;
    }

    /**
     * Seconds a delivery's webhook-id is remembered (24 hours unless set).
     *
     * @return positive-int
     *
     * @throws FlashpiException
     */
    public static function ttl(Repository $config): int
    {
        $value = $config->get('flashpi.webhook.deduplication.ttl');
        if ($value === null || (\is_string($value) && trim($value) === '')) {
            return self::DEFAULT_TTL;
        }
        if (\is_string($value) && preg_match('/\A\d+\z/', trim($value)) === 1) {
            $value = (int) trim($value);
        }
        if (!\is_int($value) || $value < 1) {
            throw new FlashpiException('config flashpi.webhook.deduplication.ttl must be a whole number of seconds, 1 or more');
        }

        return $value;
    }
}
