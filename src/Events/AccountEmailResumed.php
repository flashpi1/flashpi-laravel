<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Events;

/**
 * `account.email_resumed`: Flashpi's staff resumed the account's emails to buyers in the event's
 * environment. `data` is the AccountEmailSending.
 */
final class AccountEmailResumed extends AccountWebhookEvent
{
    public const TYPE = 'account.email_resumed';
}
