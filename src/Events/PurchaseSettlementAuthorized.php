<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Events;

/**
 * `purchase_settlement.authorized`: the SRI authorized a purchase settlement (liquidación de
 * compra). `data` is the Document.
 */
final class PurchaseSettlementAuthorized extends DocumentWebhookEvent
{
    public const TYPE = 'purchase_settlement.authorized';
}
