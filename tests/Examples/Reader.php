<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Tests\Examples;

use Flashpi\Types\ApiTypes;

/**
 * The reader's own data in the README examples: the invoice of an order and the documents the
 * fake API answers with.
 *
 * @phpstan-import-type CreateInvoice from ApiTypes
 * @phpstan-import-type Document from ApiTypes
 */
final class Reader
{
    public const DOCUMENT_ID = '019a1b2c-0000-7f60-8a1b-2c3d4e5f6a7b';

    public const ACCESS_KEY = '1611202601179000000000120010010000000011234567813';

    private function __construct() {}

    /**
     * The invoice of an order: the one of "Getting started", to the SRI's test RUC.
     *
     * @return CreateInvoice
     */
    public static function invoiceFor(string $orderId): array
    {
        return [
            'issuerId' => '019a0000-0000-7000-8000-00000000d3e0',
            'establishment' => '001',
            'emissionPoint' => '001',
            'customer' => [
                'idType' => 'ruc',
                'id' => '1760013210001',
                'name' => 'PRUEBAS SERVICIO DE RENTAS INTERNAS',
                'address' => 'Av. Amazonas y Villalengua, Quito',
            ],
            'items' => [
                [
                    'code' => 'P-001',
                    'description' => "Order {$orderId}",
                    'quantity' => '1',
                    'unitPrice' => '1.00',
                    'taxes' => [['type' => 'vat', 'rate' => '15']],
                ],
            ],
            'payments' => [['method' => 'cash', 'amount' => '1.15']],
        ];
    }

    /**
     * An invoice as the API returns it.
     *
     * @param 'processing'|'authorized' $status
     *
     * @return Document
     */
    public static function document(string $status = 'authorized'): array
    {
        $authorized = $status === 'authorized';

        return [
            'id' => self::DOCUMENT_ID,
            'type' => 'invoice',
            'environment' => 'test',
            'status' => $status,
            'issuerId' => '019a0000-0000-7000-8000-00000000d3e0',
            'establishment' => '001',
            'emissionPoint' => '001',
            'sequential' => 1,
            'number' => '001-001-000000001',
            'accessKey' => self::ACCESS_KEY,
            'issueDate' => '2026-11-16',
            'xmlVersion' => '2.1.0',
            'counterparty' => ['id' => '1760013210001', 'name' => 'PRUEBAS SERVICIO DE RENTAS INTERNAS'],
            'totals' => [
                'subtotal' => '1.00',
                'taxes' => [['type' => 'vat', 'rate' => '15', 'taxableBase' => '1.00', 'amount' => '0.15']],
                'total' => '1.15',
            ],
            'authorization' => [
                'number' => $authorized ? self::ACCESS_KEY : null,
                'authorizedAt' => $authorized ? '2026-11-16T15:20:00.000Z' : null,
            ],
            'ride' => [
                'status' => $authorized ? 'generated' : 'pending',
                'template' => 'modern',
                'generatedAt' => $authorized ? '2026-11-16T15:20:01.000Z' : null,
            ],
            'email' => ['requested' => false, 'status' => 'not_requested', 'deliveries' => [], 'warnings' => []],
            'sriMessages' => [],
            'failure' => null,
            'voidedAt' => null,
            'createdAt' => '2026-11-16T15:19:58.000Z',
            'updatedAt' => '2026-11-16T15:20:01.000Z',
        ];
    }
}
