<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Events;

/**
 * `withholding.rejected`: the SRI rejected a withholding receipt (see the document's sriMessages).
 * `data` is the Document.
 */
final class WithholdingRejected extends DocumentWebhookEvent
{
    public const TYPE = 'withholding.rejected';
}
