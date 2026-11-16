<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Events;

/**
 * The event class of each webhook type the SDK knows: its DocumentEventType,
 * CertificateEventType and AccountEventType. The class is the type in StudlyCase
 * (`document.email.bounced` is DocumentEmailBounced); a test fails when the SDK lists a type
 * without a class here.
 */
final class WebhookEvents
{
    /**
     * @var array<string, class-string<WebhookEvent<array<mixed>>>>
     */
    public const CLASSES = [
        'invoice.authorized' => InvoiceAuthorized::class,
        'invoice.rejected' => InvoiceRejected::class,
        'purchase_settlement.authorized' => PurchaseSettlementAuthorized::class,
        'purchase_settlement.rejected' => PurchaseSettlementRejected::class,
        'credit_note.authorized' => CreditNoteAuthorized::class,
        'credit_note.rejected' => CreditNoteRejected::class,
        'debit_note.authorized' => DebitNoteAuthorized::class,
        'debit_note.rejected' => DebitNoteRejected::class,
        'waybill.authorized' => WaybillAuthorized::class,
        'waybill.rejected' => WaybillRejected::class,
        'withholding.authorized' => WithholdingAuthorized::class,
        'withholding.rejected' => WithholdingRejected::class,
        'document.ride.generated' => DocumentRideGenerated::class,
        'document.ride.failed' => DocumentRideFailed::class,
        'document.email.sent' => DocumentEmailSent::class,
        'document.email.delivered' => DocumentEmailDelivered::class,
        'document.email.bounced' => DocumentEmailBounced::class,
        'document.email.complained' => DocumentEmailComplained::class,
        'document.email.failed' => DocumentEmailFailed::class,
        'certificate.verified' => CertificateVerified::class,
        'certificate.pending' => CertificatePending::class,
        'certificate.blocked' => CertificateBlocked::class,
        'certificate.revoked' => CertificateRevoked::class,
        'certificate.reactivated' => CertificateReactivated::class,
        'account.email_paused' => AccountEmailPaused::class,
        'account.email_resumed' => AccountEmailResumed::class,
    ];

    private function __construct() {}

    /**
     * The event class of a webhook type, or null for a type this version has no class for.
     *
     * @return class-string<WebhookEvent<array<mixed>>>|null
     */
    public static function classFor(string $type): ?string
    {
        return self::CLASSES[$type] ?? null;
    }
}
