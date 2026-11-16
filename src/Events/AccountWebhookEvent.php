<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Events;

use Flashpi\Types\ApiTypes;

/**
 * An event about the account's email sending to buyers (the SDK's AccountEventType). `data` is
 * the AccountEmailSending of the event's environment, as the API returned it when the event
 * happened.
 *
 * @phpstan-import-type AccountEmailSending from ApiTypes
 *
 * @extends WebhookEvent<AccountEmailSending>
 */
abstract class AccountWebhookEvent extends WebhookEvent {}
