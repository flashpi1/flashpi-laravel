<?php

declare(strict_types=1);

/*
 * Flashpi, the electronic invoicing API for Ecuador's SRI: https://flashpi.com.ec
 *
 * Every value is read from the environment here, and only here: with `php artisan config:cache`
 * Laravel stops reading .env, and the package reads nothing but this configuration. After
 * changing .env, run `php artisan config:cache` again (or `config:clear`).
 */

return [
    /*
     * The API key, from the console (https://flashpi.com.ec/app/). An fp_test_ key issues in the
     * SRI's test environment (pruebas); an fp_live_ key issues real documents in production. Keep
     * it secret: on your servers only.
     */
    'api_key' => env('FLASHPI_API_KEY'),

    /*
     * Language of the API's messages: 'es' or 'en'. Error codes do not change. When it is not
     * set, it follows the app's locale: 'es' when config('app.locale') is Spanish (es, es_EC,
     * es-EC, ...), 'en' otherwise.
     */
    'language' => env('FLASHPI_LANGUAGE'),

    /*
     * The API's address. Leave it unset for https://api.flashpi.com.ec.
     */
    'base_url' => env('FLASHPI_BASE_URL'),

    /*
     * Seconds each attempt of a request may take, from sending it to reading the whole answer
     * (unset: 60). Issuing with options.waitForAuthorization raises it to the wait plus 15 s.
     */
    'timeout' => env('FLASHPI_TIMEOUT'),

    /*
     * Retries after network errors, timeouts, 408, 429 and 5xx answers (unset: 2; 0 turns them
     * off). Issuing is safe to retry: every request carries an Idempotency-Key.
     */
    'max_retries' => env('FLASHPI_MAX_RETRIES'),

    /*
     * The webhooks Flashpi sends to this app.
     */
    'webhook' => [
        /*
         * Whether the package registers its POST route for the webhooks (anything but false, 0,
         * off or no keeps it on). Turn it off to receive them with a route of your own. With
         * `php artisan route:cache`, cache the routes again after changing this or the path.
         */
        'enabled' => env('FLASHPI_WEBHOOK_ENABLED', true),

        /*
         * The path of that route. `php artisan flashpi:webhook` registers APP_URL plus this path
         * in Flashpi.
         */
        'path' => env('FLASHPI_WEBHOOK_PATH', '/flashpi/webhook'),

        /*
         * The endpoint's signing secret (whsec_...), which `php artisan flashpi:webhook` shows
         * when it registers the endpoint. Deliveries without a valid signature are answered 400;
         * without the secret, every delivery fails (500) and Flashpi retries it.
         */
        'secret' => env('FLASHPI_WEBHOOK_SECRET'),

        /*
         * Middleware of the route. It runs outside the 'web' group on purpose: no session and no
         * CSRF token, since the signature authenticates each delivery.
         */
        'middleware' => [],

        /*
         * A delivery can arrive more than once: its webhook-id is remembered in the cache, for
         * `ttl` seconds (24 hours), so its events are dispatched once. A delivery whose listener
         * throws is forgotten, so that Flashpi's retry dispatches it again. A replayed delivery
         * (webhookEndpoints->deliveries->replay()) keeps its webhook-id: within the ttl it is
         * ignored too.
         * `store` is a cache store of config/cache.php (unset: the default one); with several
         * servers, use one they share (database, redis, memcached, dynamodb), whose add() is
         * atomic.
         */
        'deduplication' => [
            'store' => env('FLASHPI_WEBHOOK_CACHE_STORE'),
            'ttl' => 86400,
        ],
    ],
];
