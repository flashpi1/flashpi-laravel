<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Events;

/**
 * `document.email.delivered`: the buyer's mail server accepted the email of a document. `data` is
 * the Document.
 */
final class DocumentEmailDelivered extends DocumentWebhookEvent
{
    public const TYPE = 'document.email.delivered';
}
