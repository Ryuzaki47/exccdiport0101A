<?php

namespace App\Services;

/**
 * DiscountService
 *
 * Implements CCDI fee discount policy (AY 2025-2026).
 *
 * POLICY — Option A (tuition-only discount):
 *   Discounts affect ONLY the tuition fee.
 *   Lab fee and miscellaneous fee are ALWAYS charged in full,
 *   regardless of discount type — they cover actual consumables,
 *   equipment use, insurance, library, and institutional funds
 *   that cannot be waived.
 *
 * ── DISCOUNT MATRIX ──────────────────────────────────────────────────────────
 *
 *   discount_type = 'none'
 *     tuition → lec_units × ₱364  (full rate)
 *     lab     → as-is
 *     misc    → as-is
 *
 *   discount_type = 'full'
 *     tuition → ₱0.00  (100% waived)
 *     lab     → as-is  (NOT waived — student still pays lab + entrep + misc)
 *     misc    → as-is
 *
 *   discount_type = 'nstp'  (or is_taking_nstp = true)
 *     tuition → ₱546.00 fixed  (CHED minimum — 1.5 units × ₱364)
 *     lab     → as-is
 *     misc    → as-is
 *
 *   discount_type = 'full' + is_taking_nstp = true
 *     tuition → ₱546.00  (NSTP minimum restored even under full discount)
 *     lab     → as-is
 *     misc    → as-is
 *
 * ─────────────────────────────────────────────────────────────────────────────
 */
class DiscountService
{
    /**
     * Fixed NSTP tuition per CHED rules.
     * Equivalent to the minimum 1.5 units × ₱364 = ₱546.
     */
    const NSTP_TUITION_FEE = 546.00;

    /**
     * Apply the CCDI discount policy to a raw fee breakdown.
     *
     * Only tuition is affected. Lab and misc are always passed through as-is.
     *
     * @param  float  $tuitionFee   Raw tuition  (lec_units × ₱364)
     * @param  float  $labFee       Raw lab fees (lab_units × ₱1,656 + ₱600 entrep)
     * @param  float  $miscFee      Fixed misc   (₱4,700)
     * @param  string $discountType 'none' | 'full' | 'nstp'
     * @param  bool   $isTakingNstp Whether the student is enrolled in NSTP
     * @return array{tuition: float, lab: float, misc: float, total: float, applied: string}
     */
    public function apply(
        float  $tuitionFee,
        float  $labFee,
        float  $miscFee,
        string $discountType,
        bool   $isTakingNstp = false
    ): array {
        // Lab and misc are NEVER discounted — always passed through unchanged.
        $lab  = $labFee;
        $misc = $miscFee;

        if ($discountType === 'full') {
            // Full scholarship: tuition is fully waived.
            // If student is also taking NSTP, the CHED minimum of ₱546 is restored.
            $tuition = $isTakingNstp ? self::NSTP_TUITION_FEE : 0.00;
            $applied = $isTakingNstp ? 'full_with_nstp' : 'full';

        } elseif ($discountType === 'nstp' || $isTakingNstp) {
            // NSTP waiver only: tuition is fixed at ₱546 regardless of unit load.
            $tuition = self::NSTP_TUITION_FEE;
            $applied = 'nstp';

        } else {
            // No discount: everything is full rate.
            $tuition = $tuitionFee;
            $applied = 'none';
        }

        return [
            'tuition' => round($tuition, 2),
            'lab'     => round($lab, 2),
            'misc'    => round($misc, 2),
            'total'   => round($tuition + $lab + $misc, 2),
            'applied' => $applied,
        ];
    }

    /**
     * Convenience wrapper — compute from a StudentAssessment-like data array.
     *
     * @param  array  $data  Keys: tuition_fee, lab_fee, misc_fee, discount_type, is_taking_nstp
     * @return array  Same shape as apply()
     */
    public function applyFromAssessment(array $data): array
    {
        return $this->apply(
            tuitionFee:   (float) ($data['tuition_fee']   ?? 0),
            labFee:       (float) ($data['lab_fee']        ?? 0),
            miscFee:      (float) ($data['misc_fee']       ?? 0),
            discountType:          $data['discount_type']  ?? 'none',
            isTakingNstp: (bool)  ($data['is_taking_nstp'] ?? false),
        );
    }
}