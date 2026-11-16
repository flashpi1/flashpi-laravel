# Changelog

## 0.1.0 (2026-11-16)


### Features

* add a Laravel event per webhook type ([d17a1ff](https://github.com/flashpi1/flashpi-laravel/commit/d17a1ffd1d3467e9f624c1268d6551d04186f516))
* add the Flashpi facade ([eee41dd](https://github.com/flashpi1/flashpi-laravel/commit/eee41dda37f0f8c7a1376903f6f0483f15e6d1c2))
* add the flashpi:check command ([4d96953](https://github.com/flashpi1/flashpi-laravel/commit/4d969532c2a075f0c02011d1438fd81d8a70e93b))
* add the flashpi:install command ([475bf89](https://github.com/flashpi1/flashpi-laravel/commit/475bf892c91eba684f303c8137e469e883d7110e))
* add the flashpi:webhook command ([2a9568b](https://github.com/flashpi1/flashpi-laravel/commit/2a9568b61b24e31b16128246c762f236479c0b91))
* build the Flashpi client from config/flashpi.php ([e85824a](https://github.com/flashpi1/flashpi-laravel/commit/e85824ae511f1f867e30a5acce5e9e6c3e3f2f2b))
* follow the app's locale on every request ([6776c54](https://github.com/flashpi1/flashpi-laravel/commit/6776c544d180afbef1ec0d9211fe039edd86ca4d))
* let flashpi:webhook --force skip the public host check ([7dacf8a](https://github.com/flashpi1/flashpi-laravel/commit/7dacf8a836e922a1560c752f0f400cfa5cdd4a6c))
* read the webhook settings in one place ([c0629f0](https://github.com/flashpi1/flashpi-laravel/commit/c0629f022536509adafcd1d54a7e40f37b3e0e39))
* receive Flashpi's webhooks on a ready route ([bdf9318](https://github.com/flashpi1/flashpi-laravel/commit/bdf93180107c1f11ef69a7c4fa4135c7cb766c12))


### Bug fixes

* answer 400 to a webhook request without the webhook headers ([1febe18](https://github.com/flashpi1/flashpi-laravel/commit/1febe18967705c1bdd6f97f9223bb6e7de4e3a9c))
* choose Spanish when app.locale starts with es ([51220e9](https://github.com/flashpi1/flashpi-laravel/commit/51220e93de1cb1f09865e899ddb73898f4e5dc05))
* dispatch ResponseReceived and ConnectionFailed for the SDK's calls ([2808966](https://github.com/flashpi1/flashpi-laravel/commit/28089662c9a1bac17a6f03fb6cd69c60332a3b2a))
* fail flashpi:webhook when an existing endpoint has other settings ([41be66a](https://github.com/flashpi1/flashpi-laravel/commit/41be66a218deb6224f3e75a7e0268c3faaa543cd))
* hold a webhook delivery being handled for 5 minutes only ([9e03c2d](https://github.com/flashpi1/flashpi-laravel/commit/9e03c2d1e8f50ad22ce411a6f2351b986132b0f8))
* keep the listener's error when forgetting the delivery fails ([ef2e48e](https://github.com/flashpi1/flashpi-laravel/commit/ef2e48e823159245f268a8f1d2e4464c5b35e73d))
* refuse the null cache store for the webhook deliveries ([9e3285b](https://github.com/flashpi1/flashpi-laravel/commit/9e3285b99d6822f830c22d2448f56fa8492beda9))
* say where to read a webhook endpoint's secret again ([46c9db4](https://github.com/flashpi1/flashpi-laravel/commit/46c9db40ffd008441ab374b1f4651a550cee5a4f))
* write a webhook event's data once when it is queued ([d990975](https://github.com/flashpi1/flashpi-laravel/commit/d990975ba4f200579e661e28b93d64efd30334a6))
