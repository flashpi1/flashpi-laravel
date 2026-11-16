<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Events;

/**
 * `account.email_paused`: the account's emails to buyers in the event's environment were paused:
 * too many hard bounces or complaints in the last 7 days, or by Flashpi's staff. Documents are
 * still issued; their emails fail with email_sending_paused until Flashpi's staff resume them.
 * `data` is the AccountEmailSending.
 */
final class AccountEmailPaused extends AccountWebhookEvent
{
    public const TYPE = 'account.email_paused';
}
