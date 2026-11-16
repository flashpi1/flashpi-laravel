<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Events;

/**
 * `waybill.authorized`: the SRI authorized a waybill (guía de remisión). `data` is the Document.
 */
final class WaybillAuthorized extends DocumentWebhookEvent
{
    public const TYPE = 'waybill.authorized';
}
