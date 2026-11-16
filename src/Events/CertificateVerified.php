<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Events;

/**
 * `certificate.verified`: the certificate was verified not revoked: when it is accepted, or when a
 * pending status is verified. `data` is the Certificate.
 */
final class CertificateVerified extends CertificateWebhookEvent
{
    public const TYPE = 'certificate.verified';
}
