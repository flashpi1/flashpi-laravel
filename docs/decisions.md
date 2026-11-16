# Decisions

Decisions about flashpi/flashpi-laravel, the Laravel package, newest first. It is built on the PHP
SDK, [flashpi/flashpi-php](https://github.com/flashpi1/flashpi-php), whose
[decisions](https://github.com/flashpi1/flashpi-php/blob/main/docs/decisions.md) apply here unless
an entry says otherwise. Every entry is either the Flashpi product owner's or marked **chosen
while implementing**: those wait for the owner's confirmation.

## 2026-11-16: the Laravel package

### The owner's decisions

- **Laravel 12 and 13 only.** Laravel 11 lost its security support on 2026-03-12. PHP 8.2 or
  later (Laravel 13 itself needs 8.3); CI runs PHP 8.2 to 8.5 in the combinations each Laravel
  major allows.
- **Webhooks.** The package registers a ready POST route, `/flashpi/webhook` by default
  (configurable, and it can be turned off), outside the `web` middleware group: no CSRF check and
  no session. It verifies the Standard Webhooks signature with the raw body, the headers and
  `FLASHPI_WEBHOOK_SECRET` through the SDK (`Flashpi::unwrapWebhook()`, never its own crypto),
  answers the `webhook_endpoint.verification` challenge itself (`{"challenge": ...}`), and
  dispatches one Laravel event per webhook type of the SDK's `DocumentEventType`,
  `CertificateEventType` and `AccountEventType` (`invoice.authorized` → `InvoiceAuthorized`,
  `document.email.bounced` → `DocumentEmailBounced`, ...), plus `WebhookReceived` for every
  delivery, unknown types included. Listeners may be queued, so the events serialize cleanly.
- **Repeated deliveries are ignored.** The `webhook-id` header is remembered in Laravel's cache
  for 24 hours (an atomic `Cache::add()`), so a delivery is dispatched once; a delivery whose
  processing failed (a listener threw) is forgotten, so Flashpi's retry processes it.
- **Thin payloads.** For an endpoint in `thin` mode (id, type and environment only), the event
  offers a method that fetches the full event from the API (`events->get()`), called only when a
  listener uses it.
- **Usage.** Inject `Flashpi\Flashpi`, bound as a singleton, or use the facade:
  `Flashpi::invoices()->create([...])`. The SDK's resources are read-only properties, so the
  facade maps `invoices()` and the others to them, with `@method` tags for IDEs and PHPStan. The
  configuration is `config/flashpi.php`, publishable and merged by default: the API key from
  `FLASHPI_API_KEY`, the webhook secret from `FLASHPI_WEBHOOK_SECRET`, and the SDK's settings
  (base URL, timeout, retries, language). With `php artisan config:cache` Laravel does not load
  .env, so only `config()` is read at run time.
- **Testing.** The client is built with Laravel's HTTP client underneath, so `Http::fake()`,
  `Http::assertSent()` and `Http::preventStrayRequests()` work on the SDK's calls. No
  `Flashpi::fake()`. The SDK sets `allow_redirects: false`, `http_errors: false` and its timeouts
  on every request itself, so that client is supported; downloads (RIDE, XML) and retries are
  tested through it.
- **Language.** `FLASHPI_LANGUAGE` when set; otherwise `es` when `app.locale` is Spanish, `en`
  otherwise.
- **Commands.** `flashpi:install` publishes the configuration and prints the variables to add to
  .env; `flashpi:check` tests the key (its environment and the API's status); `flashpi:webhook`
  registers the app's endpoint (`APP_URL` plus the route's path, or `--url`) through the SDK's
  `webhookEndpoints` and shows its `whsec_` secret once, refusing or warning sensibly for a URL
  that is not public or not https.
- **flashpi-php's conventions.** English code and docs, READMEs in Spanish (`README.md`) and
  English (`README.en.md`), this file, PHPStan at level max (Larastan only if it pays off),
  PHP-CS-Fixer with PER-CS 2.0, PHPUnit with Orchestra Testbench (10 for Laravel 12, 11 for
  Laravel 13) and at least 95 % of lines covered, release-please from 0.1.0 with the flashpi-bot
  GitHub App, grouped Dependabot, third-party actions pinned to commit SHAs, the live test on the
  release PR, and a check of the archive Composer installs.

### The core, chosen while implementing

- **Requirements.** `illuminate/console`, `illuminate/contracts`, `illuminate/http` and
  `illuminate/support`, the components the core uses, at `^12.71 || ^13.40`, and
  `flashpi/flashpi-php` `^0.1`. As with the SDK's Guzzle floor, the lowest versions are the
  oldest Composer installs with its audit on: every `laravel/framework` before 12.71.0, and 13.x
  before 13.40.0, has a security advisory (the latest, PKSA-yxbx-3c9s-cjt9, reported 2026-11-13),
  so `--prefer-lowest` cannot install anything older, and anything older would go untested. The
  lowest versions pass the tests on PHP 8.2 and 8.5: Laravel 12.71.0 with Testbench 10.0.0, and
  Laravel 13.40.0 with Testbench 11.0.0, both with PHPUnit 11.5.50 and Guzzle 7.15.2 (13.40.0
  with Guzzle 8.3.0 too). The development requirements are Testbench `^10.0 || ^11.0` and PHPUnit
  `^11.5.50 || ^12.5.8 || ^13.0`, the versions Testbench accepts.
- **Laravel's HTTP client, per request.** The SDK gets a Guzzle client whose handler sends each
  request through the handler stack of a new pending request of the HTTP client factory the
  container holds at that moment (`createPendingRequest()->buildHandlerStack()`), not one
  `buildClient()` taken when the singleton is built: a pending request copies its factory's fakes
  when it is created, so a client resolved before `Http::fake()` (by the app while booting, or
  early in a test) would send its requests for real. The client keeps one Guzzle handler (curl
  when available) for all its requests, so connections are reused, which `buildClient()` would
  not do either; nor does it keep cookies between calls (`buildClient()`'s client does).
  `Http::fake()`, `Http::assertSent()`, `Http::preventStrayRequests()`, `Http::record()`, the
  global middleware (`Http::globalRequestMiddleware()` and the others) and the `RequestSending`
  event apply. What belongs to `PendingRequest::send()` does not: `Http::globalOptions()`,
  Laravel's own 30 s timeout and TLS 1.2 floor, and the `ResponseReceived` and `ConnectionFailed`
  events. A stray request under `Http::preventStrayRequests()` throws a `FlashpiException`
  (`... was not sent: Illuminate\Http\Client\StrayRequestException: ...`), not retried, since the
  SDK never lets an exception of its HTTP client through. Laravel 12 reads the `laravel_data` and
  `on_stats` options without a default, which only `PendingRequest::send()` sets, so the handler
  sets them (no form data, and a statistics callback that does nothing).
- **What the HTTP client's listeners see.** Every request the SDK sends now passes through
  Laravel's HTTP client, so a listener of `RequestSending` (Telescope, Pulse, Nightwatch, a log)
  gets it with its `Authorization` header, which holds the API key, as for any request an app
  sends through `Http`. Tools that record requests must hide that header.
- **The configuration.** `api_key`, `language`, `base_url`, `timeout` and `max_retries` from
  `FLASHPI_API_KEY`, `FLASHPI_LANGUAGE`, `FLASHPI_BASE_URL`, `FLASHPI_TIMEOUT` and
  `FLASHPI_MAX_RETRIES`; and, for the webhooks, `webhook.enabled` (`FLASHPI_WEBHOOK_ENABLED`,
  true), `webhook.path` (`FLASHPI_WEBHOOK_PATH`, `/flashpi/webhook`), `webhook.secret`
  (`FLASHPI_WEBHOOK_SECRET`), `webhook.middleware` (none) and `webhook.deduplication` with the
  cache `store` (`FLASHPI_WEBHOOK_CACHE_STORE`, the default store when unset) and the `ttl` in
  seconds (86400). The SDK's `defaultHeaders` and Guzzle's options (a proxy) are not settings: an
  app that needs them binds its own `Flashpi\Flashpi`. `config/flashpi.php` is published under the
  `flashpi-config` tag only. Laravel merges the package's file with the app's one level deep, so
  code that reads a nested key gives it a default of its own.
- **Reading the configuration.** The client is built from `config()` only, the first time the
  container resolves it, so an app without a key still boots, migrates and caches its
  configuration. A missing or empty key throws a `FlashpiException` that says to set
  `FLASHPI_API_KEY` and to run `config:cache` again: the builder never passes a null key, so the
  SDK's own fallback to `FLASHPI_API_KEY` in the process environment, which a cached
  configuration would not see either, is never used. Strings from .env are trimmed and turned into
  the SDK's types: a numeric `timeout` into seconds, a `max_retries` of digits into an int, and an
  empty value into the SDK's default. Anything else throws a `FlashpiException` that names the
  setting and its variable, never the value; the SDK then checks the ranges, the URL and the
  key's prefix. A test checks that `src/` calls no `env()` or `getenv()` and reads neither `$_ENV`
  nor `$_SERVER`.
- **The language.** `FLASHPI_LANGUAGE` must be `es` or `en` (in any letter case), the SDK's
  `Language` type: anything else (`es_EC`, `fr`) throws when the client is built rather than
  being sent. A locale counts as Spanish when it is `es` alone or followed by `-` or `_`, so
  `est` (Estonian, ISO 639-2) does not. The locale is read when the client is built: in a queue
  worker or under Octane, the locale of that moment stays. A request's `language` option still
  wins.
- **The facade.** `Flashpi\Laravel\Facades\Flashpi` returns any public property of the client
  for the method of its name, so a resource the SDK adds works at once (a test then requires its
  `@method` tag). A resource called with arguments, a name the client lacks and its private parts
  throw a `\BadMethodCallException`; other public methods are called as they are.
  `Flashpi::unwrapWebhook()` and `Flashpi::verifyWebhookSignature()` are methods of the facade
  that call the SDK's static ones, so they need no API key and do not build the client.
  `Flashpi::shouldReceive()` cannot mock the client (the SDK's class is final): tests fake the
  API with `Http::fake()`.
- **No global alias.** `composer.json` registers the service provider for package discovery and
  no `Flashpi` alias: `Flashpi` is already the SDK's namespace and client class
  (`Flashpi\Flashpi`), so a global `\Flashpi` would be easy to confuse with it, could clash with
  an app's own class, and Laravel 11 and later list no aliases by default. Apps import
  `Flashpi\Laravel\Facades\Flashpi`, or add the alias themselves.
- **flashpi:install.** It runs `vendor:publish --tag=flashpi-config`, keeps an existing
  `config/flashpi.php` unless `--force`, and lists the variables the configuration lacks
  (`FLASHPI_API_KEY`, `FLASHPI_WEBHOOK_SECRET`) with where to get each one. It never writes
  .env.
- **flashpi:check.** It shows the key's environment and the base URL, reads `GET /v1/status`,
  which needs no key, then `GET /v1/events?limit=1`, the cheapest call every key can make (an
  issuer-scoped or read-only key reads its events), to test the key: a 401 or 403 is a refused
  key. A missing webhook secret is a warning, not a failure. The calls keep the client's retries.
- **flashpi:webhook.** The URL is `--url`, or `APP_URL` plus `webhook.path` (an error when the
  route is turned off, `APP_URL` is unset or the path is empty). It refuses, sending nothing, a
  URL that is not https and one whose host cannot be public: an IP address outside RFC 6890's
  global ranges, a name without a dot, and names under `localhost`, `test`, `example`, `invalid`,
  `local`, `internal`, `home.arpa`, `lan`, `localdomain`, `corp`, `intranet` and `private`. There
  is no option to override it and no DNS lookup. It then looks for an endpoint with exactly that
  URL among the key's (every page): an active one is reported, a disabled one is a warning, and
  one waiting for verification gets its challenge sent again with `--verify`. Otherwise it creates
  the endpoint for every event (`*`, or the `--event` types, which the API checks so a new type
  needs no new version) with full payloads (or `--thin`), reads its secret and prints
  `FLASHPI_WEBHOOK_SECRET=whsec_...` once, with the next steps: Flashpi's first challenge fails
  until the deployed app holds the secret, so `--verify` sends it again. The secret of an
  endpoint that already exists is never printed.
- **Static analysis.** PHPStan at level max with flashpi-php's flags, over `config`, `scripts`,
  `src` and `tests`. No Larastan: the package uses little of Laravel's magic (the container's
  `make()` is typed by Laravel's own generics, `config()` values are checked at run time anyway,
  and the facade's `@method` tags need nothing more), and Larastan requires matching
  `illuminate/*` versions, so a new Laravel major would wait for it. The one report that depends
  on the Laravel major (the container declares `BindingResolutionException` in 12 and nothing in
  13) is ignored in `phpstan.neon.dist`. With the lowest dependencies, `symfony/polyfill-uuid`
  1.15 installs `paragonie/random_compat`, whose `random_bytes()` has no return type, so PHPStan
  checks `config`, `scripts` and `src` only there.
- **Tests.** Testbench with the provider registered as package discovery would, a fake `fp_test_`
  key per test and fake `whsec_` secrets. The API is faked with `Http::fake()`; Guzzle's
  `MockHandler` stands in for curl where Guzzle's own middleware must run (redirects, answers cut
  short, network failures), and a local `php -S` server for real sockets (a 3 MB RIDE, a
  timeout, a redirect). `COLUMNS=300` in `phpunit.xml.dist` keeps the commands' messages on one
  line for the assertions.
- **The archive.** `composer package:check` checks `git archive HEAD` as flashpi-php's does: only
  `src/`, `config/`, `composer.json`, the license, the READMEs and maybe the changelog; a
  `composer.json` that requires PHP, `flashpi/flashpi-php` `^0.1` and `illuminate/*` components
  for Laravel 12 and 13 only, autoloads `Flashpi\Laravel\` from `src/` and registers the service
  provider for package discovery.
