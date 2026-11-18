# Contributing to flashpi/flashpi-laravel

Read [docs/decisions.md](docs/decisions.md) first: it records why the package is built the way
it is. It is built on the PHP SDK, [flashpi/flashpi-php](https://github.com/flashpi1/flashpi-php),
whose [decisions](https://github.com/flashpi1/flashpi-php/blob/main/docs/decisions.md) apply here
too. Decisions about the product (names, behavior, supported versions, releases) belong to the
Flashpi team: ask before changing them.

## Setup

PHP 8.2 or later with the curl, mbstring, pdo_sqlite and phar extensions, and
[Composer](https://getcomposer.org/):

```bash
composer install
```

`composer.lock` is not committed: a library is tested against the newest and the oldest versions
`composer.json` allows. `composer install` resolves Laravel 13 and flashpi/flashpi-php 0.2 on PHP
8.3 and later, and Laravel 12 and flashpi/flashpi-php 0.1 on PHP 8.2 (the SDK's 0.2 needs PHP 8.3);
for Laravel 12 on a newer PHP, run `composer update --with "orchestra/testbench:^10.0"` (Testbench
10 is Laravel 12's, 11 is Laravel 13's), and for the SDK's 0.1,
`composer update --with "flashpi/flashpi-php:^0.1"`. Without PHP on your machine, run every command
in the official images, for example
`docker run --rm -v "$PWD":/app -w /app composer:2 composer install`, then
`docker run --rm -v "$PWD":/app -w /app php:8.5-cli vendor/bin/phpunit`.

## Checks

Run the same checks as CI before every commit:

```bash
composer validate --strict && composer cs && composer analyse && composer test
```

- `composer cs` checks the style (PHP-CS-Fixer, PER-CS 2.0; `composer cs:fix` fixes it).
- `composer analyse` runs PHPStan at level max, with bleeding edge, over `config`, `src`, `tests`
  and `scripts`. Its configuration (`phpstan.neon.dist`) adds flashpi-php's flags of its own.
  There is no Larastan (see docs/decisions.md).
- `composer test` runs PHPUnit in a Laravel app of
  [Orchestra Testbench](https://packages.tools/testbench), strict about warnings, deprecations
  and output. The API is faked with `Http::fake()`; some tests open local sockets (a `php -S`
  server), and one runs a queue worker on SQLite in memory. It also checks that
  `tests/Examples/Readme/` matches the READMEs, and lints, analyses and runs those examples
  (below).
- Coverage: install pcov and run `php -d pcov.directory=. vendor/bin/phpunit --coverage-text`.
  pcov only measures `src/` unless told otherwise; `pcov.directory=.` counts the scripts too. CI
  fails below 95 % of lines.
- CI runs the tests on PHP 8.2 to 8.5 with Laravel 12 and Guzzle 7, and on PHP 8.3 to 8.5 with
  Laravel 13 (which needs PHP 8.3) with Guzzle 7 and with Guzzle 8: `composer update` resolves
  Guzzle 8 with Laravel 13, and `composer update --with "guzzlehttp/guzzle:^7.15.2"` Guzzle 7. It
  also runs them on the oldest versions `composer.json` allows with each Laravel major:
  `composer update --prefer-lowest --prefer-stable --with "orchestra/testbench:^10.0"` installs
  Laravel 12.71.0, and `... --with "orchestra/testbench:^11.0"` Laravel 13.40.0, both with Guzzle
  7; add `--with "guzzlehttp/guzzle:^8.3"` to the second for Laravel 13 with Guzzle 8.3.0, which
  `--prefer-lowest` never picks on its own. Run `composer update` to go back to the newest. The
  newest versions bring flashpi/flashpi-php 0.1 on PHP 8.2 and 0.2 on PHP 8.3 and later, and the
  lowest its 0.1.0 on every PHP, so CI tests both with each Laravel major. With the lowest
  versions, PHPStan checks `config`, `scripts` and `src` only: the lowest `symfony/polyfill-uuid`
  installs `random_compat`, whose `random_bytes()` has no return type, and the lowest Mockery
  (1.6.10) types `shouldReceive()` too loosely for the tests.
- `composer package:check` checks what Composer would install from a tag: the archive of `HEAD`
  (so commit first), which `.gitattributes` keeps to `src/`, `config/`, `composer.json`, the
  license and the READMEs. CI then installs that archive in new Laravel 12 and 13 apps
  (`composer create-project laravel/laravel`), runs `php artisan flashpi:install`, and runs
  `scripts/installed_check.php` there, which boots the app and sends a signed challenge and a
  signed event to the webhook route.
- `composer test:live` calls the real API in test mode. It needs `FLASHPI_TEST_API_KEY` (an
  `fp_test_` key, read_write) and `FLASHPI_DEMO_ISSUER_ID` (in CI, an organization secret and
  variable shared with the SDKs' repositories), and issues one test invoice on the demo issuer,
  which has a daily quota: run it only when needed.

## The README examples

The READMEs are in Spanish (`README.md`) and English (`README.en.md`); keep both in step, with
the same examples in the same order. Every `php` block in them is checked: after changing one,
run `composer readme:generate`, which writes it to `tests/Examples/Readme/` (as flashpi-php does).
`tests/Examples/ExamplesTest.php` then:

- lints each one (`php -l`);
- analyses them with PHPStan and a consumer's configuration (`tests/Examples/phpstan.neon`:
  level max and bleeding edge, nothing else);
- runs each one in a Testbench app with the package, the API faked with `Http::fake()` and the
  default disk faked: top-level code as it is; a listener with its event, once with a full
  payload and once with a thin one, through Laravel's dispatcher and the sync queue; a job
  through the bus; a test class with PHPUnit, in a process of its own. A request the fake API
  does not know fails the example, and so does a class it cannot run.

A block is what would follow `<?php` in a file of its own (its `use` imports first, no
namespace). Variables it reads before setting them come from
`tests/Examples/Readme/Context.php`; the app's own classes it uses, `Tests\TestCase` and the
`App\Models\Order` model (whose table the test creates in SQLite), are in `tests/Examples/App/`.
Examples read the configuration with `config()`, never `env()`, and use the container's client,
never `new Flashpi()`.

## Code conventions

- English for code, identifiers, comments and commit messages.
- PHP 8.2 or later, `declare(strict_types=1)` in every file, final classes, PHPStan at level max.
- The package requires PHP, `flashpi/flashpi-php` and Laravel's own components (`illuminate/*`,
  for Laravel 12 and 13) only; `composer package:check` refuses anything else. Every call to the
  API goes through the SDK: the package never builds a request or verifies a signature itself.
- `src/` reads the configuration through `config()` (the `Repository`), never `env()`, `getenv()`,
  `$_ENV` or `$_SERVER`: a test checks it. `config/flashpi.php` is the only place that reads the
  environment.
- `Flashpi\Laravel\Internal` is not part of the public API.
- The facade's `@method` tags list every resource of the SDK's client; a test fails when the SDK
  adds one without its tag. The event classes cover every webhook type of the SDK's types; a test
  fails when the SDK adds a type without its class.

## Commits and releases

Commit with [Conventional Commits](https://www.conventionalcommits.org/) (`feat:`, `fix:`,
`docs:`, `test:`, `build:`, `ci:`, `chore:`). They drive the version and the changelog:
[release-please](https://github.com/googleapis/release-please) keeps a release pull request open,
and merging it tags the version (`v0.1.0` first) and creates the GitHub release from the
`Release` workflow. release-please writes the version to `CHANGELOG.md` and
`.release-please-manifest.json`; `composer.json` carries none, and the package has no Version
file.

While the version is 0.x, `feat:` raises the minor version and `fix:` the patch version.

Packagist reads the tags from GitHub: there is no upload or publish job, and no Packagist token
in this repository. The Flashpi owner submits the repository to Packagist once
(https://packagist.org/packages/submit, as `flashpi/flashpi-laravel`) and keeps Packagist's
GitHub integration on, so every new tag appears there within minutes. Composer installs a
version from GitHub's archive of its tag, which `.gitattributes` keeps to the package.

The release PR is opened with a token of the organization's GitHub App, so CI runs on it like on
any pull request. (One opened with `GITHUB_TOKEN` would get its CI only after someone approves
it.) The Release workflow also starts the live API test (`live.yml`) on the release branch each
time release-please updates it. A dispatched `ci.yml` run does not count for a pull request's
required checks: to run CI again, re-run the PR's own checks.

### The GitHub App

The `flashpi1` organization's app, as in flashpi-php: installed on `flashpi-laravel` too, with no
webhook and these repository permissions: Contents, Pull requests and Issues, read and write. Its
Client ID is the organization variable `FLASHPI_BOT_CLIENT_ID` and its private key the
organization secret `FLASHPI_BOT_PRIVATE_KEY`, shared with this repository. The workflows create
a one-hour token per run with only the permissions each job needs. To rotate the key, generate a
new one in the app's settings, update the organization secret (not a repository one, which would
shadow it here only), then delete the old key.
