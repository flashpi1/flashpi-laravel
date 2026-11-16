<?php

declare(strict_types=1);

/*
 * The bootstrap of the README's test class, which tests/Examples/ExamplesTest.php runs with
 * PHPUnit in a process of its own: Composer's autoloader and the app's classes the example uses,
 * its `Tests\TestCase` and its `App\Models\Order`.
 */

require __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/App/TestCase.php';
require_once __DIR__ . '/App/Models/Order.php';
