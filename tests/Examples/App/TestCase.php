<?php

declare(strict_types=1);

namespace Tests;

use Flashpi\Laravel\FlashpiServiceProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Orchestra\Testbench\TestCase as Testbench;

/**
 * An app's own base test case, `Tests\TestCase`, which the README's test example extends: a
 * Laravel app with the package as package discovery registers it, and a fake fp_test_ key, as an
 * app's phpunit.xml sets FLASHPI_API_KEY. tests/Examples/bootstrap.php loads it when
 * tests/Examples/ExamplesTest.php runs that example with PHPUnit.
 */
abstract class TestCase extends Testbench
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [FlashpiServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app->make(Repository::class)->set('flashpi.api_key', 'fp_test_' . bin2hex(random_bytes(24)));
        // An attribute the model does not accept throws instead of being dropped, so an example
        // that fills one fails here rather than passing without it.
        Model::preventSilentlyDiscardingAttributes();
    }
}
