<?php

namespace App\Services;

/**
 * DiscountService
 *
 * Implements CCDI fee discount policy (AY 2025-2026).
 *
 * POLICY:
 *   NSTP units are EXCLUDED from discount (non-discountable).
 *   Discounts affect ONLY billable (non-NSTP) lecture units.
 *   Lab fee and miscellaneous fee are ALWAYS charged in full,
 *   regardless of discount type — they cover actual consumables,
 *   equipment use, insurance, library, and institutional funds
 *   that cannot be waived.
 *
 * ── DISCOUNT MATRIX ──────────────────────────────────────────────────────────
 *
 *   With NSTP units + discount_type = 'full'
 *     billable_tuition → ₱0.00  (100% discounted)
 *     nstp_tuition     → discountableUnits × rate  (full price, never discounted)
 *     lab              → as-is
 *     misc             → as-is
 *     total_tuition    = nstp_tuition + lab + misc
 *
 *   With NSTP units + discount_type = 'none'
 *     billable_tuition → discountableUnits × ₱364  (full rate)
 *     nstp_tuition     → nstpUnits × ₱364
 *     lab              → as-is
 *     misc             → as-is
 *     total_tuition    = billable_tuition + nstp_tuition
 *
 *   Without NSTP + discount_type = 'full'
 *     tuition → ₱0.00  (100% waived)
 *     lab     → as-is
 *     misc    → as-is
 *
 *   Without NSTP + discount_type = 'none'
 *     tuition → lec_units × ₱364  (full rate)
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
     * NSTP units (non-discountable) are excluded from discount calculation.
     * Only billable (non-NSTP) lecture units can be discounted.
     * Lab and misc are never discounted.
     *
     * @param  float  $tuitionFee       Raw tuition for billable lec_units (lec_units × ₱364)
     * @param  float  $nstpUnits        Total NSTP lecture units (always full price)
     * @param  float  $labFee           Raw lab fees (lab_units × ₱1,656 + ₱600 entrep)
     * @param  float  $miscFee          Fixed misc   (₱4,700)
     * @param  string $discountType     'none' | 'full'
     * @param  float  $rate             Per-unit tuition rate (₱364)
     * @return array{tuition: float, lab: float, misc: float, total: float, applied: string}
     */
    public function apply(
        float  $tuitionFee,
        float  $nstpUnits,
        float  $labFee,
        float  $miscFee,
        string $discountType,
        float  $rate = 364.00
    ): array {
        // Lab and misc are NEVER discounted — always passed through unchanged.
        $lab  = $labFee;
        $misc = $miscFee;

        // Calculate NSTP tuition at full price
        $nstpTuition = round($nstpUnits * $rate, 2);

        // Apply discount only to billable (non-NSTP) units
        if ($discountType === 'full') {
            $billableTuition = 0.00;
            $applied = $nstpUnits > 0 ? 'full_discount_nstp_full_price' : 'full';
        } else {
            // No discount: billable units at full price
            $billableTuition = $tuitionFee;
            $applied = 'none';
        }

        $finalTuition = $billableTuition + $nstpTuition;

        return [
            'tuition' => round($finalTuition, 2),
            'lab'     => round($lab, 2),
            'misc'    => round($misc, 2),
            'total'   => round($finalTuition + $lab + $misc, 2),
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