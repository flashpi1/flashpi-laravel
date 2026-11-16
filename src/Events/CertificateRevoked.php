<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Events;

/**
 * `certificate.revoked`: the certification authority revoked the certificate: it never signs
 * again. `data` is the Certificate.
 */
final class CertificateRevoked extends CertificateWebhookEvent
{
    public const TYPE = 'certificate.revoked';
}
