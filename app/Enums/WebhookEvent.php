<?php

namespace App\Enums;

/**
 * Gateway events by their Xendit wire name. Some also accept the alias from
 * the brief's examples (payment.success and so on), so both keep working.
 */
enum WebhookEvent: string
{
    case PaymentAuthorization = 'payment.authorization';
    case PaymentCapture = 'payment.capture';
    case PaymentFailure = 'payment.failure';
    case PaymentRequestExpiry = 'payment_request.expiry';
    case RefundSucceeded = 'refund.succeeded';
    case RefundFailed = 'refund.failed';

    /**
     * Accepted alias => wire name.
     */
    private const array ALIASES = [
        'payment.pending' => 'payment.authorization',
        'payment.success' => 'payment.capture',
        'payment.expired' => 'payment_request.expiry',
        'payment.refunded' => 'refund.succeeded',
    ];

    /**
     * Resolve a wire name or its alias; null for an unknown name.
     */
    public static function fromWireName(string $name): ?self
    {
        return self::tryFrom(self::ALIASES[$name] ?? $name);
    }

    /**
     * The wire name followed by its aliases.
     *
     * @return list<string>
     */
    public function wireNames(): array
    {
        return [$this->value, ...array_keys(self::ALIASES, $this->value, true)];
    }

    /**
     * The ledger kind this event records.
     */
    public function kind(): TransactionKind
    {
        return match ($this) {
            self::PaymentAuthorization => TransactionKind::Pending,
            self::PaymentCapture => TransactionKind::Payment,
            self::PaymentFailure => TransactionKind::FailedPayment,
            self::PaymentRequestExpiry => TransactionKind::Expiry,
            self::RefundSucceeded => TransactionKind::Refund,
            self::RefundFailed => TransactionKind::FailedRefund,
        };
    }
}
