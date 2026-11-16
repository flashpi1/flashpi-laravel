<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Events;

/**
 * `withholding.authorized`: the SRI authorized a withholding receipt (comprobante de retención).
 * `data` is the Document.
 */
final class WithholdingAuthorized extends DocumentWebhookEvent
{
    public const TYPE = 'withholding.authorized';
}
