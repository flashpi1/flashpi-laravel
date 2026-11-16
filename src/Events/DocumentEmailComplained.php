<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Events;

/**
 * `document.email.complained`: the recipient marked the email of a document as spam. `data` is the
 * Document.
 */
final class DocumentEmailComplained extends DocumentWebhookEvent
{
    public const TYPE = 'document.email.complained';
}
