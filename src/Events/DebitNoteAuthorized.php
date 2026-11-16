<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Events;

/**
 * `debit_note.authorized`: the SRI authorized a debit note. `data` is the Document.
 */
final class DebitNoteAuthorized extends DocumentWebhookEvent
{
    public const TYPE = 'debit_note.authorized';
}
