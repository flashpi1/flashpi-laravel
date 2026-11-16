<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Events;

/**
 * `certificate.blocked`: the certificate stopped signing: still unverified at revocation.blockAt,
 * or its chain is no longer trusted. `data` is the Certificate.
 */
final class CertificateBlocked extends CertificateWebhookEvent
{
    public const TYPE = 'certificate.blocked';
}
