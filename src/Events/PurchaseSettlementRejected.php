<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Events;

/**
 * `purchase_settlement.rejected`: the SRI rejected a purchase settlement (see the document's
 * sriMessages). `data` is the Document.
 */
final class PurchaseSettlementRejected extends DocumentWebhookEvent
{
    public const TYPE = 'purchase_settlement.rejected';
}
