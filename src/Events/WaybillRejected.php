<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Events;

/**
 * `waybill.rejected`: the SRI rejected a waybill (see the document's sriMessages). `data` is the
 * Document.
 */
final class WaybillRejected extends DocumentWebhookEvent
{
    public const TYPE = 'waybill.rejected';
}
