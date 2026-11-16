<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Tests\Examples\Readme;

use Flashpi\Laravel\Tests\Examples\Reader;
use Flashpi\Types\ApiTypes;

/**
 * What the README examples take from their surroundings: stand-ins for the reader's own data.
 *
 * scripts/readme.php sets each variable an example reads before setting it from the method of the
 * same name.
 *
 * @phpstan-import-type CreateInvoice from ApiTypes
 * @phpstan-import-type Document from ApiTypes
 */
final class Context
{
    private function __construct() {}

    /**
     * An invoice to issue.
     *
     * @return CreateInvoice
     */
    public static function body(): array
    {
        return Reader::invoiceFor('42');
    }

    /**
     * The id of a document.
     */
    public static function documentId(): string
    {
        return Reader::DOCUMENT_ID;
    }

    /**
     * A document as the API returns it.
     *
     * @return Document
     */
    public static function document(): array
    {
        return Reader::document();
    }
}
