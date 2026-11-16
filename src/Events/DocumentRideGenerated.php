<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Events;

/**
 * `document.ride.generated`: the RIDE (PDF) of an authorized document was generated. `data` is the
 * Document.
 */
final class DocumentRideGenerated extends DocumentWebhookEvent
{
    public const TYPE = 'document.ride.generated';
}
