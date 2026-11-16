<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Events;

/**
 * `debit_note.rejected`: the SRI rejected a debit note (see the document's sriMessages). `data` is
 * the Document.
 */
final class DebitNoteRejected extends DocumentWebhookEvent
{
    public const TYPE = 'debit_note.rejected';
}
