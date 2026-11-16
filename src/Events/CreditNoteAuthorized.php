<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Events;

/**
 * `credit_note.authorized`: the SRI authorized a credit note. `data` is the Document.
 */
final class CreditNoteAuthorized extends DocumentWebhookEvent
{
    public const TYPE = 'credit_note.authorized';
}
