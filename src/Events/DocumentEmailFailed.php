<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Events;

/**
 * `document.email.failed`: the email of a document could not be sent. `data` is the Document.
 */
final class DocumentEmailFailed extends DocumentWebhookEvent
{
    public const TYPE = 'document.email.failed';
}
