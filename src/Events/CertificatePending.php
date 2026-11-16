<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Events;

/**
 * `certificate.pending`: the revocation status of the certificate could not be verified: it signs
 * until revocation.blockAt. Raised again with each reminder. `data` is the Certificate.
 */
final class CertificatePending extends CertificateWebhookEvent
{
    public const TYPE = 'certificate.pending';
}
