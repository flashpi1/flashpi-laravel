<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Events;

use Flashpi\Types\ApiTypes;

/**
 * An event about an issuer's .p12 certificate (the SDK's CertificateEventType). `data` is the
 * Certificate as the API returned it when the event happened.
 *
 * A base class for typing, never dispatched itself: Laravel does not run its listeners (see
 * WebhookEvent).
 *
 * @phpstan-import-type Certificate from ApiTypes
 *
 * @extends WebhookEvent<Certificate>
 */
abstract class CertificateWebhookEvent extends WebhookEvent {}
