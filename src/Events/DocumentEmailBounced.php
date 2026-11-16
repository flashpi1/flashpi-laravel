<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Events;

/**
 * `document.email.bounced`: the email of a document bounced. `data` is the Document.
 */
final class DocumentEmailBounced extends DocumentWebhookEvent
{
    public const TYPE = 'document.email.bounced';
}
