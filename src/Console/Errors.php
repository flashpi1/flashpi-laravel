<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Console;

use Flashpi\Exception\ApiException;
use Flashpi\Exception\FlashpiException;

/**
 * What the commands print of an SDK error.
 *
 * @internal
 */
final class Errors
{
    private function __construct() {}

    /**
     * The error's message, with the API's request id to share with support.
     */
    public static function describe(FlashpiException $error): string
    {
        $message = $error->getMessage();
        if ($error instanceof ApiException && $error->requestId !== null) {
            $message .= " (request {$error->requestId})";
        }

        return $message;
    }
}
