<?php

declare(strict_types=1);

namespace App\Models;

use Flashpi\Laravel\Tests\Examples\Reader;
use Flashpi\Types\ApiTypes;
use Illuminate\Database\Eloquent\Model;

/**
 * The reader's order, an Eloquent model of the README examples: tests/Examples/ExamplesTest.php
 * creates its table in the app's SQLite database in memory.
 *
 * @property int $id
 * @property string|null $flashpi_document_id
 * @property string|null $access_key
 * @property bool $email_bounced
 *
 * @phpstan-import-type CreateInvoice from ApiTypes
 */
final class Order extends Model
{
    /**
     * Guarded as an app's model is: the README examples that fill an attribute the model does not
     * list fail, as they would in the reader's app.
     *
     * @var list<string>
     */
    protected $fillable = ['flashpi_document_id', 'access_key', 'email_bounced'];

    /** @var bool */
    public $timestamps = false;

    /**
     * The order's invoice, as Flashpi takes it.
     *
     * @return CreateInvoice
     */
    public function toFlashpiInvoice(): array
    {
        return Reader::invoiceFor((string) $this->id);
    }
}
