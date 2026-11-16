<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Events;

/**
 * `invoice.authorized`: the SRI authorized an invoice. `data` is the Document.
 */
final class InvoiceAuthorized extends DocumentWebhookEvent
{
    public const TYPE = 'invoice.authorized';
}
