# Decisions

Decisions about flashpi/flashpi-laravel, the Laravel package, newest first. It is built on the PHP
SDK, [flashpi/flashpi-php](https://github.com/flashpi1/flashpi-php), whose
[decisions](https://github.com/flashpi1/flashpi-php/blob/main/docs/decisions.md) apply here unless
an entry says otherwise. Every entry is either the Flashpi product owner's or marked **chosen
while implementing**. The owner confirmed every choice of the 2026-11-16 entries on that day,
except where "The owner's answers" changes one.

## 2026-11-18: flashpi/flashpi-php 0.3

The SDK's 0.3.0, released on 2026-11-18, follows the API: the last payment of a document may leave
out `amount`, Flashpi gives it the rest of the total, and `zero_payment` is a new field error code
(flashpi-php's `DocumentPayment` and `WithholdingPayment` types, and its `FieldErrorCode`). The
owner decided to take it. It replaces `^0.1 || ^0.2` in the 2026-11-17 entry.

- **Every minor version of the SDK since 0.1.** `"flashpi/flashpi-php": "^0.1 || ^0.2 || ^0.3"`,
  with `"php": "^8.2"` kept: Composer gives an app on PHP 8.2 the SDK's 0.1 and an app on PHP 8.3
  or later its 0.3. The 0.3 adds no resource and no webhook type, only the optional amount in the
  request types, so the package's code does not change. It is committed as `fix:`, as the move to
  0.2 was, which release-please turns into 0.1.2, and `composer package:check` requires the new
  constraint.
- **The READMEs** say the SDK's 0.3 lets the last payment leave out `amount`. With the SDK's 0.1
  (PHP 8.2), PHPStan still requires it, though the API accepts the payment without it.

## 2026-11-17: flashpi/flashpi-php 0.2

The SDK raised its minimum PHP version to 8.3 in its 0.2.0, released on 2026-11-18 (its
[decisions](https://github.com/flashpi1/flashpi-php/blob/main/docs/decisions.md#2026-11-17-php-83)),
and the owner decided that the package takes it without dropping PHP 8.2. It replaces
"`flashpi/flashpi-php` `^0.1`" in the 2026-11-16 entry ("Requirements" and "The archive", under
"The core, chosen while implementing").

- **Both minor versions of the SDK.** `"flashpi/flashpi-php": "^0.1 || ^0.2"`, in place of
  `^0.1`, with `"php": "^8.2"` kept: Composer gives an app on PHP 8.2 the SDK's 0.1, and an app on
  PHP 8.3 or later its 0.2. With `^0.1` alone, an app that also required the SDK's 0.2 could not
  install the package. The SDK's code did not change in 0.2.0, so the package's does not either.
  It is committed as `fix:`, which release-please turns into 0.1.1, and `composer package:check`
  requires the new constraint.
- **CI keeps its jobs** (**chosen while implementing**). The PHP version picks the SDK's: the
  newest versions bring 0.1 on PHP 8.2 and 0.2 on PHP 8.3 and later, and the lowest bring 0.1.0 on
  every PHP. So both are tested with each Laravel major and each Guzzle major it allows, as the
  owner asked, without new jobs (the SDK's 0.2 with the newest versions only), and the required
  checks do not change. The `laravel` job's log lists the SDK's version, and
  `scripts/installed_check.php` prints it.
- **The READMEs** (**chosen while implementing**) say which version of the SDK Composer picks for
  each PHP version.

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
- **Language.** `FLASHPI_LANGUAGE` when set; otherwise `es` when `app.locale` starts with `es`,
  `en` otherwise. Refined by the owner's answers below: the locale of each request, and its
  language must be `es`.
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

### The owner's answers

On 2026-11-16, before the first pull request, the owner answered the questions the choices below
left open:

- **The language follows the locale of each request.** Without `FLASHPI_LANGUAGE`, every request
  gets the `Accept-Language` of `app.locale` when it is sent, not the locale the client was built
  with, so queue workers, Octane and apps with several languages get the locale of that moment. The
  locale's language must be `es` (`es`, `es_EC`, `es-EC`, `es_EC.UTF-8`): `et` and `est`, Estonian,
  no longer count as Spanish.
- **Replays are documented, not changed.** A replay keeps its `webhook-id`, so within 24 hours of
  the first delivery the route answers 204 and dispatches nothing. For 0.1.0 the READMEs give the
  line that forgets the delivery before a replay; there is no command option for it, and the API
  does not mark replays.
- **`flashpi:webhook --force`.** It skips the check that the URL's host is public, for a public
  host the list gets wrong. The URL must still be https, and Flashpi's API refuses a URL that is not
  public HTTPS on its own.
- **Every other choice stays as built.** That covers the Laravel floor (`^12.71 || ^13.40`),
  `FLASHPI_LANGUAGE` limited to `es` or `en`, and no global facade alias. It covers the README's
  warning about the `Authorization` header seen by the HTTP client's listeners, and `flashpi:check`
  reading `GET /v1/events?limit=1` (any key may; the API has no "whoami" endpoint). It covers how
  `flashpi:webhook` shows a secret and fails on other settings, and the route's answers (400, 409
  with a 5-minute lease, 204, 500). It covers the verification challenge without events, the order
  `WebhookReceived` then the typed event, and the event classes' names and shapes. Finally, it
  covers the configuration keys, the README's `Order` examples, Dependabot without Laravel or
  Testbench majors, the CI matrix with every job required on `main`, the live test's single
  invoice, and no version file.
- **After 0.1.0, not now.** `flashpi:webhook --update` and a secret rotation, marker interfaces per
  event category, a proxy or headers of one's own in the configuration, and a second client with
  another key (multi-tenant apps).

### The core, chosen while implementing

- **Requirements.** `illuminate/console`, `illuminate/contracts`, `illuminate/http` and
  `illuminate/support`, the components the core uses, at `^12.71 || ^13.40`, and
  `flashpi/flashpi-php` `^0.1 || ^0.2 || ^0.3` (`^0.1` before 2026-11-17 and `^0.1 || ^0.2` before
  2026-11-18, above). As with the SDK's Guzzle
  floor, the lowest versions are the oldest Composer installs with its audit on: every
  `laravel/framework` before 12.71.0, and 13.x before 13.40.0, has a security advisory (the latest,
  PKSA-yxbx-3c9s-cjt9, reported 2026-11-13), so `--prefer-lowest` cannot install anything older, and
  anything older would go untested. The READMEs give this floor (Laravel 12.71 or 13.40 and later)
  and what to do on an older release (`composer update laravel/framework -W`, or
  `composer require -W`): the newer Laravel releases need newer versions of locked dependencies
  (such as `league/commonmark` ^2.8.1 for 12.71 and `monolog/monolog` ^3.10 for 13.40), which an
  update without `-W` cannot move, and `-W` is the flag Composer's own error suggests. The lowest
  versions pass the tests on PHP 8.2 and 8.5: Laravel 12.71.0 with Testbench 10.0.0, and Laravel
  13.40.0 with Testbench 11.0.0, both with PHPUnit 11.5.50 and Guzzle 7.15.2 (13.40.0 with Guzzle
  8.3.0 too, which a job of CI keeps checking, since `--prefer-lowest` alone always picks Guzzle 7).
  The development requirements are Testbench `^10.0 || ^11.0` and PHPUnit
  `^11.5.50 || ^12.5.8 || ^13.0`, the versions Testbench accepts. Guzzle, PSR-7 and Symfony Console,
  which the code also uses (the Guzzle client under the SDK, a command's `#[AsCommand]`), come with
  the SDK and with Laravel's components, which require them: `composer.json` does not repeat their
  constraints, so they cannot drift from the SDK's and Laravel's.
- **Laravel's HTTP client, per request.** The SDK gets a Guzzle client whose handler sends each
  request through the handler stack of a new pending request of the HTTP client factory the
  container holds at that moment (`createPendingRequest()->buildHandlerStack()`), not one
  `buildClient()` taken when the singleton is built: a pending request copies its factory's fakes
  when it is created, so a client resolved before `Http::fake()` (by the app while booting, or early
  in a test) would send its requests for real. The client keeps one Guzzle handler (curl when
  available) for all its requests, so connections are reused, which `buildClient()` would not do
  either; nor does it keep cookies between calls (`buildClient()`'s client does). `Http::fake()`,
  `Http::assertSent()`, `Http::preventStrayRequests()`, `Http::record()`, the global middleware
  (`Http::globalRequestMiddleware()` and the others) and the `RequestSending` event apply. What
  `PendingRequest::send()` does once the stack is done, the handler does too: it dispatches
  `ResponseReceived` for every answer, and for a request that failed in the transport it records the
  failure (`Http::assertSent()` and `Http::recorded()` see it with a `null` response, or with the
  answer a Guzzle exception carries) and dispatches `ConnectionFailed` when no answer came, once per
  attempt, so the SDK's retries show. Telescope's HTTP client watcher listens to those two events
  only, Sentry's spans and breadcrumbs and other APM tools close on them what they open on
  `RequestSending`, and an app's test of `Http::failedConnection()` asserts on the recorded
  requests: without them the SDK's calls would be missing there, or left open. The SDK sees the same
  rejection, so its retries do not change, and a listener that reads the body leaves a stream the
  SDK rewinds before reading it (`stream` is false), so downloads stay whole. What belongs to
  `send()`'s own pending request does not apply: `Http::globalOptions()`, Laravel's own 30 s timeout
  and TLS 1.2 floor. A stray request under `Http::preventStrayRequests()` throws a
  `FlashpiException` (`... was not sent: Illuminate\Http\Client\StrayRequestException: ...`), not
  retried, since the SDK never lets an exception of its HTTP client through. Laravel 12 reads the
  `laravel_data` and `on_stats` options without a default, which only `PendingRequest::send()` sets,
  so the handler sets them (no form data, and a statistics callback that does nothing). It leaves
  `laravel_data` empty for multipart requests too, so Laravel's `hasFile()`, `data()` and array
  access see none of the parts of a certificate upload: the SDK builds the `MultipartStream` itself
  and hides its parts (`SensitiveParameterValue`), and filling `laravel_data` would mean parsing
  every multipart body and copying the certificate's password into the request's data, only for test
  helpers. Tests assert on `isMultipart()`, the URL and `body()` instead, as the READMEs say.
- **What the HTTP client's listeners see.** Every request the SDK sends now passes through
  Laravel's HTTP client, so the HTTP client's events and global middleware (Telescope, Pulse,
  Nightwatch, a log) get it with its `Authorization` header, which holds the API key, as for any
  request an app sends through `Http`. Tools that record requests must hide that header.
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
  `Language` type: anything else (`es_EC`, `fr`) throws when the client is built rather than being
  sent. Without it, the client is built with no language, and `LaravelHttpClient` gives a request
  that has no `Accept-Language` the one of `config('app.locale')` when it is sent, so
  `App::setLocale()` in a request, a queued job or under Octane counts (the owner's answer, below).
  The locale's language is the part before `_`, `-`, `.` or `@`, in any letter case: `es` for
  `es`, `es_EC`, `es-EC`, `es_EC.UTF-8` or `es@euro`, and `en` for anything else, `et` and `est`
  (Estonian) included. A request's `language` option is already in its headers, so it wins.
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
  route is turned off, `APP_URL` is unset or the path is empty). It refuses, sending nothing, a URL
  that is not https and one whose host cannot be public: an IP address outside RFC 6890's global
  ranges, a name without a dot, and names under `localhost`, `test`, `example`, `invalid`, `local`,
  `internal`, `home.arpa`, `lan`, `localdomain`, `corp`, `intranet` and `private`. There is no
  DNS lookup. `--force` skips the host check, never the https one, for a public host the list
  gets wrong (the owner's answer, below): Flashpi's API refuses a URL that is not public HTTPS
  (`url_not_allowed`) on its own. It then looks for an endpoint with exactly that URL
  among the key's (every page): an active one is reported (with its payload mode), a disabled one
  is a warning, and one waiting for verification gets its challenge sent again with `--verify`. The
  command never updates an endpoint: `--event` and `--thin` apply when it creates one, and when the
  existing endpoint's payload mode or events differ from those given (only the options given count:
  `--thin` is a flag, so a run without it asks nothing of the payload mode, and the events are
  compared as sets), the command prints the differences and fails without sending anything more,
  `--verify` included, so a deploy script sees that the settings it asked for are not in place
  (**chosen while implementing**; an option that updates the endpoint through
  `webhookEndpoints->update()` waits until after 0.1.0, the owner's answer). Otherwise it creates the
  endpoint for every event (`*`, or the `--event` types, which the API checks so a new type needs
  no new version) with full payloads (or `--thin`), reads its secret and prints
  `FLASHPI_WEBHOOK_SECRET=whsec_...` once, with the next steps: Flashpi's first challenge fails
  until the deployed app holds the secret, so `--verify` sends it again. The secret of an endpoint
  that already exists is never printed: the command points to the endpoint's page in the console
  and to `webhookEndpoints->getSecret()`, which return it at any time (as does `flashpi:check` when
  the secret is not set).
- **Static analysis.** PHPStan at level max with flashpi-php's flags, over `config`, `scripts`,
  `src` and `tests`. No Larastan: the package uses little of Laravel's magic (the container's
  `make()` is typed by Laravel's own generics, `config()` values are checked at run time anyway,
  and the facade's `@method` tags need nothing more), and Larastan requires matching `illuminate/*`
  versions, so a new Laravel major would wait for it. The one report that depends on the Laravel
  major (the container declares `BindingResolutionException` in 12 and nothing in 13) is ignored in
  `phpstan.neon.dist`. With the lowest dependencies, `symfony/polyfill-uuid` 1.15 installs
  `paragonie/random_compat`, whose `random_bytes()` has no return type, and Mockery 1.6.10, the
  lowest Laravel and Testbench allow, types `shouldReceive()` as
  `Expectation|ExpectationInterface|HigherOrderMessage` (1.6.13 narrows it), which fails
  `tests/ServiceProviderTest.php`, so PHPStan checks `config`, `scripts` and `src` only there.
- **Tests.** Testbench with the provider registered as package discovery would, a fake `fp_test_`
  key per test and fake `whsec_` secrets. The API is faked with `Http::fake()`; Guzzle's
  `MockHandler` stands in for curl where Guzzle's own middleware must run (redirects, answers cut
  short, network failures), and a local `php -S` server for real sockets (a 3 MB RIDE, a
  timeout, a redirect). `COLUMNS=300` in `phpunit.xml.dist` keeps the commands' messages on one
  line for the assertions.
- **The archive.** `composer package:check` checks `git archive HEAD` as flashpi-php's does: only
  `src/`, `config/`, `composer.json`, the license, the READMEs and maybe the changelog; a
  `composer.json` that requires PHP, `flashpi/flashpi-php` `^0.1 || ^0.2 || ^0.3` (`^0.1` before
  2026-11-17, `^0.1 || ^0.2` before 2026-11-18)
  and `illuminate/*` components for Laravel 12 and 13 only, autoloads `Flashpi\Laravel\` from `src/`
  and registers the service provider for package discovery.

### The webhooks, chosen while implementing

- **Requirements.** The webhooks add `illuminate/routing` (the route) and `illuminate/container`
  (the container a queued event reaches its client through), at the same `^12.71 || ^13.40`.
  The events, the cache and the exception handler are used through `illuminate/contracts` only.
- **The route.** The service provider registers `POST {webhook.path}` named `flashpi.webhook`,
  with `WebhookController` (an invokable class, so `php artisan route:cache` caches it) and the
  `webhook.middleware` (a name or a list; none by default), in `boot()` and outside any group: no
  `web` group, so no session and no CSRF check, which the signature makes unnecessary. Laravel's
  global middleware still runs (maintenance mode answers 503, which Flashpi retries). It is
  registered in code, not from a routes file, so the archive stays `src/` and `config/`; like
  `loadRoutesFrom()`, nothing is added when the routes are cached, since the cache holds the route
  as it was (a change to the path or the switch needs `route:cache` again). It is registered
  whether or not an API key is set: receiving needs only the secret. `webhook.enabled` is on
  unless it is false, 0, `off` or `no` (any letter case; an unset or empty value keeps it on),
  so a bad value never stops the app from booting. The path is trimmed to one leading slash and
  none at the end; unset or empty is `/flashpi/webhook`, and only slashes registers no route (and
  `flashpi:webhook` refuses it, as before). `flashpi:webhook` reads the same settings through
  `Internal\WebhookSettings`. Laravel merges config/flashpi.php one level deep, so an app's file
  without a nested key gets the package's default from that class, never null.
- **Verification.** The controller passes `$request->getContent()` (the raw body) and
  `$request->headers` to `Flashpi::unwrapWebhook()` with `webhook.secret`, with the SDK's
  5-minute tolerance (not a setting). Every `WebhookVerificationException` is a 400 with an empty
  body, and so is a signed body that is not a Flashpi event: no string `id`, `type` or
  `createdAt`, an `environment` other than `test` or `live`, or a `data` that is not an object.
  The one exception is the SDK's `invalid_secret`, which depends on the configuration only: a
  missing or malformed secret throws a `FlashpiException` that names `FLASHPI_WEBHOOK_SECRET`,
  never the value, so Laravel reports it and answers 500. A 400 would hide the app's
  misconfiguration among forged requests; with a 500 the app's error tracker shows it, and
  Flashpi retries the delivery (for 72 hours) until the secret is deployed. A request without
  the three headers (`webhook-id`, `webhook-timestamp`, `webhook-signature`, each with a value)
  is a 400 before the secret is read, because it cannot be a Flashpi delivery: the route is on by
  default, so an app that only calls the API, with no secret, would otherwise report every
  scanner's POST and fill its error tracker. The check refuses only what the SDK's
  `missing_headers` refuses too (the values of a repeated header are joined, as the SDK joins
  `webhook-signature`'s), so with a valid secret every answer stays the same. Only requests that
  carry the headers get the reported 500 for a missing or malformed secret; one with made-up
  headers still reports.
- **The challenge.** `webhook_endpoint.verification` is answered before anything else is done
  with the delivery: 200 with `{"challenge": "..."}`, every time it comes (`flashpi:webhook
  --verify` sends a new one), never remembered in the cache and with no event dispatched, not even
  `WebhookReceived` (the owner's decision dispatches events for every other type). One without a
  string `challenge` is a 400.
- **Deduplication.** The cache key is `flashpi:webhook:` and the `webhook-id` header (Flashpi sends
  the event's id, a UUID), or the SHA-256 of an id longer than 128 characters or with characters
  other than letters, digits, `.`, `_`, `:` and `-`, which some stores refuse; a known key lets an
  app forget a delivery (documented in the READMEs, before a replay) to have it handled again. The
  key goes through two states with different lifetimes:
  `Cache::add($key, 'processing', min(300, $ttl))` before dispatching (atomic in the stores with an
  add() of their own: the file store's takes an exclusive file lock, but its files are local, so
  with several servers it must be a store they share: database, redis, memcached,
  dynamodb), then `put($key, 'processed', $ttl)` once the events went out. The null store
  (`CACHE_STORE=null`, or `FLASHPI_WEBHOOK_CACHE_STORE` naming one) is refused with a
  `FlashpiException`, a reported 500 that Flashpi retries, as for a missing secret: its `add()`
  always fails (Laravel falls back to `get()` then `put()`, and its `put()` returns false), so
  every verified delivery would get 409 for 72 hours and no event would ever be dispatched, with
  nothing in the app to say why (**chosen while implementing**). It is checked with `instanceof
  Illuminate\Cache\NullStore`, which loads nothing, so the package still requires no
  `illuminate/cache`. The array store keeps its keys for one request (or one test) only, so it does
  not deduplicate outside tests, and the apc store has no `add()` of its own, so Laravel's `get()`
  then `put()` is not atomic: both are documented, not refused. A repeat of a processed delivery is
  answered 204 without dispatching; a repeat of one still being handled is answered 409, so that
  Flashpi tries it again later instead of taking a 204 for a delivery whose first attempt may still
  fail (Flashpi waits 10 seconds for an answer and retries 5 seconds later, so a slow synchronous
  listener meets its own retry). When a listener throws, the key is forgotten and the error is
  thrown again, so Laravel's exception handler reports it and renders it (a 500, or an
  `HttpException`'s status) and Flashpi's retry dispatches the delivery again. When forgetting the
  key fails too, that error is reported and the listener's error is still the one thrown; the
  delivery stays `processing` until its lease ends (below), and Flashpi's next retry handles it.
  When the cache fails before the events go out, the error is a 500 and nothing is dispatched; when
  it fails writing `processed`, the events went out, so the error is reported and the answer is
  still 204. A request that dies without an exception while dispatching (a PHP fatal error such as
  the memory or time limit, a worker killed by FPM's `request_terminate_timeout`, an OOM kill, a
  deploy) never forgets its key, so `processing` has a lease of its own, 300 seconds
  (`WebhookController::PROCESSING_LEASE`, **chosen while implementing**): with the whole TTL,
  Flashpi's retries (5 s, 5 min, 30 min, 2 h, 5 h, then every 10 h until 72 h after the first
  attempt) would get 409 for about 27.5 hours, and a TTL above about 62 hours would lose the
  delivery for good. 300 seconds is longer than Flashpi's 10-second wait, PHP's default 30-second
  `max_execution_time` and the usual proxy timeouts (60 seconds), so a request that still answers
  in time keeps its lease; after one that died, the retry 5 seconds later gets 409 and the one
  about 5 minutes later handles the delivery. A handler still running after 300 seconds would be
  dispatched a second time, which at-least-once delivery and idempotent listeners already allow. A
  TTL below 300 seconds shortens the lease too. No shutdown function forgets the key: it would
  cover fatal errors only, not killed workers, and does not run per request under Octane. The TTL
  is `webhook.deduplication.ttl`, a whole number of seconds of 1 or more (0 would make
  `Cache::add()` refuse every delivery), 86400 when unset; anything else throws a
  `FlashpiException`. Flashpi's replay of a delivery (`webhookEndpoints->deliveries->replay()`)
  keeps its `webhook-id`, so within the TTL it is answered 204 and dispatches nothing: replays
  cannot be told from retries.
- **The order of the events.** `WebhookReceived` is dispatched first, then the event of the type,
  as Cashier dispatches its `WebhookReceived` before handling: a listener that records every
  delivery runs before the ones that act on it. A listener that throws stops the ones after it,
  and the retry dispatches both events again, so listeners must be idempotent: delivery is at
  least once.
- **The event classes.** `Flashpi\Laravel\Events`: the abstract `WebhookEvent<TData>` (a covariant
  template, so `InvoiceAuthorized` is a `WebhookEvent<array<mixed>>`), the abstract
  `DocumentWebhookEvent` (`data` is the SDK's `Document`), `CertificateWebhookEvent`
  (`Certificate`) and `AccountWebhookEvent` (`AccountEmailSending`), one final class per type with
  its `TYPE` constant, and the final `WebhookReceived`, whose `data` is `array<string, mixed>`.
  `WebhookEvents::CLASSES` maps each type to its class (`classFor()`). The class name is the type
  in StudlyCase, split at `.` and `_` (`account.email_paused` is `AccountEmailPaused`). A test
  reads the SDK's `DocumentEventType`, `CertificateEventType` and `AccountEventType` (and
  `EventThinType`) from `ApiTypes`' PHPDoc and fails when a type has no class, or a class no type,
  or a class sits under the wrong base. The abstract bases exist for typing only, and their
  docblocks and the READMEs say that they cannot be listened to: Laravel's dispatcher matches a
  listener by the dispatched class and the interfaces it implements, never by a parent class, so a
  listener of `DocumentWebhookEvent` (registered with `Event::listen()`, as a closure's parameter
  type or as a discovered `handle()`) would silently never run (**chosen while implementing**).
  Category marker interfaces (for example `Events\Contracts\DocumentEvent`), which the dispatcher
  would match, as Laravel's own `MigrationEvent` contract is matched, wait until after 0.1.0 (the
  owner's answer): adding them later breaks nothing, removing them after 0.1.0 would.
- **What an event holds.** Read-only `id`, `type`, `environment` (`test` or `live`), `createdAt`
  (the API's ISO 8601 string, as the SDK keeps dates), `data` (null in a thin payload), `payload`
  (the whole body, with any field this version does not know) and `webhookId` (the header; the
  event's id by default). An event is built from a webhook body, `new InvoiceAuthorized($payload)`,
  so an app's tests build them as the route does; a body that is not an event of the class's type
  throws an `\InvalidArgumentException`. The classes hold strings and arrays only, so they need no
  `SerializesModels`. They define `__serialize()` and `__unserialize()`, so a queued job carries
  the payload once (`data` is part of it), the webhook-id and what `fetch()` read, and the
  constructor rebuilds the rest, checking it again: PHP's `serialize()` writes an array out in full
  each time, so without them a job carried the document twice (in `data` and in the payload),
  JSON-escaped inside the queue's payload, which doubles the queue's storage and brings large
  documents closer to the size limits of drivers such as SQS (256 KB). The queued format no longer
  depends on the property layout either, so jobs queued before a package upgrade still load. The
  sync queue (which serializes its jobs too) and the database queue with a worker are tested. They
  use no `Dispatchable` trait, which lives in `laravel/framework`'s foundation, a package the
  package does not require: `Event::dispatch()` and `event()` dispatch them.
- **Thin payloads.** `isThin()` is true when the body has no `data`. `fetch()` returns the whole
  event: the payload itself, without a request, when it carries `data`; otherwise
  `$flashpi->events->get($id)` with the client of the container at that moment
  (`Container::getInstance()`, so it works in a queue worker), read once per event object and
  kept through serialization. It throws a `FlashpiException` before sending anything when the
  key's environment is not the event's (an `fp_live_` key cannot read a test event), and when the
  API answers with another event or one without `data`; the API's errors pass through. It
  returns the API's object as it came, unknown fields included, typed with the class's `TData`.
- **Tests.** Deliveries are signed as Flashpi signs them (HMAC-SHA256 of
  `webhook-id.webhook-timestamp.body` with the base64 key of the `whsec_` secret), checked against
  the example of Flashpi's docs that flashpi-php verifies, with a fresh timestamp: the SDK reads
  the system clock, not Laravel's. They cover a valid delivery, a tampered body, timestamps 301
  seconds old or ahead, another secret, each missing header, bodies that are not events, a
  missing or malformed secret, the challenge, a repeated delivery and Flashpi's re-signed retry,
  a listener that throws then the retry, a delivery still being handled, the TTL with Laravel's
  clock, another cache store, an unknown type, a secret rotation, a thin payload fetched through
  `Http::fake()`, the route's settings and `route:cache` (the compiled routes, exported and loaded
  back as Laravel loads its cache).

### The repository, chosen while implementing

- **The READMEs.** `README.md` in Spanish and `README.en.md` in English, with the same sections
  and the same examples in the same order, in flashpi-php's style: what the package is, the
  requirements, install and `flashpi:install`, the configuration and its variables, the client
  (the facade first, then injection in a queued job), issuing (every document type follows the
  invoice), documents, the webhooks (the route, `flashpi:webhook`, the events and their classes,
  queued listeners, repeated deliveries, thin payloads, a route of one's own), testing with
  `Http::fake()`, the commands, the errors, security, and links to the SDKs' docs and to the
  SDK's README for everything the package does not change (types, retries, pagination, issuers).
  Every link is absolute, or an anchor, since Packagist shows the README and cannot follow a
  relative link (`composer package:check` refuses one in `README.md`).
- **What the examples show.** They read the configuration through `config()` and use the
  container's client, never `env()` or `new Flashpi()`, since a cached configuration does not see
  .env (`tests/Scripts/ReadmeTest.php` checks it). The reader's own code is an Eloquent
  `App\Models\Order` with a `toFlashpiInvoice()` method, the way a Laravel app holds its data,
  rather than global functions: PHPStan can type a method's return with the SDK's
  `CreateInvoice`, and cannot type a function's (`@phpstan-import-type` works in a class's
  PHPDoc only). The job uses the order's id as the `idempotencyKey`, so a retried job never
  issues twice. The testing section tells apps to set a fake `fp_test_` key and
  `FLASHPI_MAX_RETRIES=0` in `phpunit.xml`, since the SDK waits between retries of a faked 5xx.
  Its test builds the order with `forceFill(['id' => 42])`, which works on any model whatever its
  guarding: `new Order(['id' => 42])` throws `MassAssignmentException` on a model made by
  `make:model` (no `$fillable`) and drops the id on one whose `$fillable` lacks it. The stand-in
  model (`tests/Examples/App/Models/Order.php`) guards as an app's would, listing in `$fillable`
  the columns the examples fill and not `id`, and the stand-in `Tests\TestCase` calls
  `Model::preventSilentlyDiscardingAttributes()`, so an example that mass-assigns what a real
  model refuses fails instead of passing on a model that accepts everything.
- **The README examples are checked and run.** `scripts/readme.php` and `scripts/Readme/` are
  flashpi-php's, ported: every `php` block becomes a file of `tests/Examples/Readme/`
  (`composer readme:generate`; `composer readme:check` and a test fail when they differ), excluded
  from the repository's PHPStan configuration and marked `linguist-generated`.
  `tests/Examples/ExamplesTest.php` lints each one with `php -l`, analyses them with a consumer's
  PHPStan configuration (`tests/Examples/phpstan.neon`: level max and bleeding edge only, as the
  SDK's), and runs every one in a Testbench app with the API faked by a callback of
  `Http::fake()` under `Http::preventStrayRequests()`, the default disk faked, the null log
  channel, the sync queue and the orders in SQLite in memory:
  - top-level code runs as it is (its output discarded);
  - a class whose `handle()` takes a webhook event is registered as its listener and gets the
    event through Laravel's dispatcher twice, with a full payload and with a thin one (whose
    `fetch()` the fake API answers), so a queued listener goes through the sync queue and its
    serialization;
  - a job (`ShouldQueue`) goes through the bus's `dispatchSync()`, which serializes it, with its
    order;
  - a test class runs with PHPUnit in a process of its own, on a stand-in for the app's
    `Tests\TestCase` (`tests/Examples/App/TestCase.php`, a Testbench test case with the package
    and a fake key), from a copy of the file named after the class, since PHPUnit loads a test
    file by its class's name. No `--do-not-cache-result`: PHPUnit 13 deprecates it, and without a
    configuration file PHPUnit writes no cache.
  A request the fake API does not know (a 404 problem), a class with neither `handle()` nor
  `__invoke()`, a listener that leaves no trace (a request or a query) for either payload, and
  an example that does nothing the test can see fail the run. The owner asked to run what can
  run under Testbench; every example can.
- **The live test.** `tests/Live/LiveTest.php` (group `live`, `composer test:live`), as
  flashpi-php's: skipped without `FLASHPI_TEST_API_KEY` and `FLASHPI_DEMO_ISSUER_ID`, and it
  refuses a key that is not `fp_test_`. In a Testbench app with the key in its configuration
  (`FLASHPI_BASE_URL` may point to another API), it runs `php artisan flashpi:check`, issues ONE
  test invoice through the facade on the demo issuer, follows it until the SRI authorizes it, and
  reads its `invoice.authorized` event with `fetch()` from a thin `InvoiceAuthorized`, so the
  thin path is checked against the real API too. The SDK's live test already covers the API's
  other answers; this one covers what the package adds. `live.yml` mirrors flashpi-php's (PHP
  8.5, the newest dependencies, the organization secret and variable checked first).
- **CI.** `ci.yml` follows flashpi-php's, with the actions pinned to the same commit SHAs
  (checkout v7.0.1, setup-php 2.40.0):
  - `verify` (PHP 8.5, Laravel 13): `composer validate --strict`, `composer readme:check`,
    PHP-CS-Fixer, PHPStan, and the tests with pcov, failing below 95 % of lines.
  - `laravel`: PHP 8.2 to 8.5 with Laravel 12 and 13, without PHP 8.2 with Laravel 13, and with
    Guzzle 7 and 8 (`--with "guzzlehttp/guzzle:^7.15.2"` or `"^8.3"`), without Guzzle 8 with
    Laravel 12, which allows Guzzle 7 only: ten jobs, Laravel 12 with Guzzle 7 and every Laravel
    13 row with Guzzle 7 and with Guzzle 8. Left alone, the newest Laravel 13 always resolves
    Guzzle 8, but an app whose other dependencies hold Guzzle 7 runs Laravel 13 with Guzzle 7, and
    the package uses Guzzle's classes itself (`Client`, `Utils::chooseHandler()`, `TransferStats`,
    the promises), so both majors are tested as flashpi-php's matrix tests them. Each job runs
    PHPStan over everything, `tests/` included (it reports nothing with Guzzle 7, so the package
    needs no src-only run as flashpi-php does), and the tests. PHPStan runs in every job because
    Laravel's and Guzzle's types differ between majors (the one ignored report in
    `phpstan.neon.dist` is one of Laravel's; Guzzle 8's promises are generic and Guzzle 7's are
    not, which is why the handler of `LaravelHttpClient` narrows its answer with `assert()` instead
    of a PHPDoc type).
  - `lowest`: the oldest versions `composer.json` allows with each Laravel major, on its lowest
    PHP and on PHP 8.5 (Laravel 12 on 8.2 and 8.5, Laravel 13 on 8.3 and 8.5), with Guzzle 7
    (`--with "guzzlehttp/guzzle:^7.15.2"`), and a fifth job with Laravel 13 and Guzzle 8 on PHP
    8.5 (`--with "guzzlehttp/guzzle:^8.3"`): Laravel 13 and the SDK allow either major, the
    package uses Guzzle's classes itself (`Client`, `Utils::chooseHandler()`, `TransferStats`,
    `Promise\Create`), and left alone `--prefer-lowest` always picks Guzzle 7, which is why
    flashpi-php's lowest job pins each major too. PHPStan runs over `config`, `scripts` and `src`
    only (random_compat and Mockery 1.6.10, above), then the tests.
  - `package` (Laravel 12 on PHP 8.2, Laravel 13 on 8.5): `scripts/package_check.php` on `git
    archive HEAD`, then the archive installed from a path repository in a new app
    (`composer create-project laravel/laravel`), `php artisan flashpi:install`,
    `scripts/installed_check.php` (the package loads from the app's vendor directory with nothing
    but the package, package discovery registers it, the facade's client sends through
    `Http::fake()`, and the route answers a signed challenge and dispatches a signed event), then
    `config:cache` and `route:cache` with the route still listed. It is flashpi-php's install
    check, with a real Laravel app in place of a bare project.
  The Laravel major is chosen through Testbench (`--with "orchestra/testbench:^10.0"` for 12,
  `^11.0` for 13), whose majors follow Laravel's: `laravel/framework` is not a requirement of the
  package, only of Testbench.
- **Releases.** release-please with flashpi-php's configuration (`release-type: php`, 0.1.0
  first, minor bumps before 1.0, no component in the tag, the same changelog sections), and
  `.release-please-manifest.json` at `0.0.0`, as flashpi-php's was before its first release.
  `release.yml` is flashpi-php's: the flashpi-bot GitHub App's token (organization variable
  `FLASHPI_BOT_CLIENT_ID`, secret `FLASHPI_BOT_PRIVATE_KEY`) opens the release PR, and the live
  test is dispatched on its branch. No publish job, since Packagist reads the tags. No Version
  file: the package sends no version of its own (the requests carry the SDK's User-Agent), so
  release-please updates `CHANGELOG.md` and the manifest only, and `composer.json` carries no
  version.
- **Dependabot.** Weekly and grouped, as flashpi-php's: the GitHub Actions in one group (`ci:`),
  the Composer production and development dependencies in one group each (`chore:`). Majors of
  `illuminate/*` and `orchestra/testbench` are ignored: another Laravel major is the owner's
  decision, and Testbench's majors follow Laravel's. `flashpi/flashpi-php` is not ignored, but
  `composer package:check` requires `^0.1 || ^0.2 || ^0.3` (`^0.1` before 2026-11-17,
  `^0.1 || ^0.2` before 2026-11-18), so a new SDK minor is
  adopted on purpose (its new resources need the facade's tags, its new webhook types their event
  classes).
- **The archive.** `.gitattributes` keeps `.github`, `docs`, `scripts`, `tests`, the tool
  configuration, the release-please files, `CONTRIBUTING.md` and `SECURITY.md` out of the
  archive; `composer package:check` and the CI's `package` job prove that the archive holds
  `src/`, `config/`, `composer.json`, the license and the READMEs (and, once released, the
  changelog) only.
- **CONTRIBUTING.md and SECURITY.md.** flashpi-php's, adapted: the Laravel versions CI tests and
  how to install them, the README examples, the install check, and, for security, the key in
  .env, the `Authorization` header in tools that record outgoing requests, the webhook route's
  signature check and a shared cache store for several servers.
