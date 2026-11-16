<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Tests\Support;

/**
 * What the test listeners saw, shared through the container.
 */
final class Recorder
{
    /** @var list<object> */
    public array $events = [];

    /** @var list<array<mixed>> */
    public array $data = [];
}
