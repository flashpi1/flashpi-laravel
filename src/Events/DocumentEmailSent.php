<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Events;

/**
 * `document.email.sent`: the email of a document to the buyer was sent. `data` is the Document.
 */
final class DocumentEmailSent extends DocumentWebhookEvent
{
    public const TYPE = 'document.email.sent';
}
