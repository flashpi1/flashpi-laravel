# Security

Please do not report security problems in public issues.

Report them privately from this repository's **Security** tab with **Report a vulnerability**
(GitHub private vulnerability reporting). Only the Flashpi maintainers see the report. Include
the versions of `flashpi/flashpi-laravel` and `flashpi/flashpi-php`, your PHP and Laravel
versions and the steps to reproduce.

For problems with the PHP SDK or the Flashpi API itself, use the same channel: we route them to
the right team.

## Keeping your integration safe

- Your API key (`fp_live_...`) is a secret: keep it in your servers' `.env` as `FLASHPI_API_KEY`,
  never in the repository or in code that runs on your users' devices. The SDK never shows it:
  not in `dump()`, `var_dump()`, exceptions or stack traces.
- Every request to Flashpi goes through Laravel's HTTP client. Tools that record outgoing
  requests (Telescope, Pulse, Nightwatch, a log of your own) see its `Authorization` header,
  which holds the key: hide that header in them.
- Keep the webhook route's signature check: the package's route verifies every delivery with
  `FLASHPI_WEBHOOK_SECRET` before it dispatches anything. If you receive webhooks on a route of
  your own, verify each one with `Flashpi::unwrapWebhook()` on the raw body first, and drop
  repeated deliveries by their `webhook-id`.
- With several servers, keep the webhook deliveries in a cache store they share (database,
  redis, memcached or dynamodb, whose `add()` is atomic), so a delivery is handled once.
- Install the curl extension in production: it enforces each attempt's timeout over the whole
  answer.
