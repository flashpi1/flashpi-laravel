<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Events;

use Flashpi\Types\ApiTypes;

/**
 * An event about an issued document (the SDK's DocumentEventType): its tax outcome, its RIDE or
 * its email. `data` is the Document as the API returned it when the event happened.
 *
 * @phpstan-import-type Document from ApiTypes
 *
 * @extends WebhookEvent<Document>
 */
abstract class DocumentWebhookEvent extends WebhookEvent {}
