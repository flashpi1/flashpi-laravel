<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Events;

/**
 * `document.ride.failed`: the RIDE (PDF) of a document could not be generated. `data` is the
 * Document.
 */
final class DocumentRideFailed extends DocumentWebhookEvent
{
    public const TYPE = 'document.ride.failed';
}
