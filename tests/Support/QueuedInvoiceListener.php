<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Tests\Support;

use Flashpi\Laravel\Events\InvoiceAuthorized;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A queued listener, as an app writes one: it reads the invoice of the event, fetching it when
 * the payload is thin.
 */
final class QueuedInvoiceListener implements ShouldQueue
{
    public function __construct(private readonly Recorder $recorder) {}

    public function handle(InvoiceAuthorized $event): void
    {
        $this->recorder->events[] = $event;
        $this->recorder->data[] = $event->fetch()['data'];
    }
}
