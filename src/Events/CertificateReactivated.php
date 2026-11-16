<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Events;

/**
 * `certificate.reactivated`: a blocked certificate was verified good and signs again. `data` is
 * the Certificate.
 */
final class CertificateReactivated extends CertificateWebhookEvent
{
    public const TYPE = 'certificate.reactivated';
}
