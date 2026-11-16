<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Scripts\Readme;

/**
 * A README block that cannot become a checked example.
 */
final class ExampleError extends \RuntimeException {}
