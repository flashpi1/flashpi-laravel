<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Events;

/**
 * Every webhook the package's route accepts, of any type, including types this version of the
 * package has no class for: dispatched before the event of its type (InvoiceAuthorized, ...).
 *
 * Its `data` is the event's object as the API sent it; WebhookEvents::classFor() gives the class
 * of a known type.
 *
 * @extends WebhookEvent<array<string, mixed>>
 */
final class WebhookReceived extends WebhookEvent {}
