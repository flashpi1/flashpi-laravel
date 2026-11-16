<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Events;

/**
 * `credit_note.rejected`: the SRI rejected a credit note (see the document's sriMessages). `data`
 * is the Document.
 */
final class CreditNoteRejected extends DocumentWebhookEvent
{
    public const TYPE = 'credit_note.rejected';
}
