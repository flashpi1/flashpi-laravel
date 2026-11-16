<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Events;

/**
 * `invoice.rejected`: the SRI rejected an invoice (see the document's sriMessages). `data` is the
 * Document.
 */
final class InvoiceRejected extends DocumentWebhookEvent
{
    public const TYPE = 'invoice.rejected';
}
