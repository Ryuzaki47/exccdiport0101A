<?php

namespace App\Enums;

/**
 * PaymentStatus — single source of truth for all payment/transaction statuses.
 *
 * USAGE
 * -----
 * Write:   PaymentStatus::PAID->value          → 'paid'
 * Check:   $model->status === PaymentStatus::PAID->value
 * In:      whereIn('status', PaymentStatus::unpaidValues())
 *
 * SCOPE OF USE
 * ------------
 * This enum replaces raw status string literals in:
 *   - App\Models\Transaction        (status column)
 *   - App\Models\StudentPaymentTerm (status column)
 *   - App\Models\Payment            (status column — maps to STATUS_COMPLETED)
 *   - App\Services\StudentPaymentService
 *   - App\Http\Controllers\StudentFeeController
 *   - App\Http\Controllers\TransactionController
 *   - App\Http\Controllers\WorkflowApprovalController
 */
enum PaymentStatus: string
{
    // ── Transaction / StudentPaymentTerm statuses ─────────────────────────────

    /** Bank transfer submitted — waiting for student to upload proof. */
    case AWAITING_PROOF = 'awaiting_proof';

    /** Payment submitted and fully confirmed. Term balance has been deducted. */
    case PAID = 'paid';

    /** Charge created or term not yet paid. No payment received. */
    case PENDING = 'pending';

    /** Student submitted a payment; waiting for accounting to approve. */
    case AWAITING_APPROVAL = 'awaiting_approval';

    /** Payment was rejected by accounting and will not be applied. */
    case CANCELLED = 'cancelled';

    /** A partial payment has been applied; some balance still remains on the term. */
    case PARTIAL = 'partial';

    /** Payment gateway returned an error and payment was not processed. */
    case FAILED = 'failed';

    // ── Payment model status (maps to Payment::STATUS_COMPLETED) ─────────────

    /** Payment record has been created and reconciled (used in payments table). */
    case COMPLETED = 'completed';

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Human-readable label for display in the UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::PAID             => 'Paid',
            self::PENDING          => 'Pending',
            self::AWAITING_APPROVAL => 'Awaiting Approval',
            self::CANCELLED        => 'Cancelled',
            self::PARTIAL          => 'Partial',
            self::FAILED           => 'Failed',
            self::COMPLETED        => 'Completed',
        };
    }

    /**
     * Tailwind CSS color class hint for badge rendering in Vue components.
     */
    public function colorClass(): string
    {
        return match ($this) {
            self::PAID, self::COMPLETED => 'text-green-600 bg-green-50',
            self::PENDING               => 'text-yellow-600 bg-yellow-50',
            self::AWAITING_APPROVAL     => 'text-blue-600 bg-blue-50',
            self::CANCELLED, self::FAILED => 'text-red-600 bg-red-50',
            self::PARTIAL               => 'text-orange-600 bg-orange-50',
        };
    }

    /**
     * Returns the values that represent "still owes money" for StudentPaymentTerm.
     * Used in whereIn() queries for outstanding balance calculations.
     *
     * @return string[]
     */
    public static function unpaidValues(): array
    {
        return [
            self::PENDING->value,
            self::PARTIAL->value,
        ];
    }

    /**
     * Returns all raw string values (useful for validation rule `in:` lists).
     *
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}