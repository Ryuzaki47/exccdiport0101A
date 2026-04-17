<?php

namespace App\Services;

/**
 * DiscountService
 *
 * Implements the fee discount flowchart logic:
 *
 *  START
 *   └─ Input subject details (tuition, lab, misc)
 *       └─ Apply 100% discount (zero everything out)
 *           └─ Is taking NSTP?
 *               ├─ YES → tuition_fee = 546, lab = as-is, misc = as-is
 *               └─ NO  → tuition = as-is, lab = as-is, misc = as-is
 *   └─ Compute total
 *  END
 */
class DiscountService
{
    /** Fixed NSTP tuition override per CHED rules (AY 2025-2026) */
    const NSTP_TUITION_FEE = 546.00;

    /**
     * Apply discount logic to a fee breakdown.
     *
     * @param  float  $tuitionFee   Raw tuition (units × rate)
     * @param  float  $labFee       Raw lab fees total
     * @param  float  $miscFee      Raw miscellaneous fees total
     * @param  string $discountType 'none' | 'full' | 'nstp'
     * @param  bool   $isTakingNstp Whether the student is enrolled in NSTP
     * @return array{tuition: float, lab: float, misc: float, total: float, applied: string}
     */
    public function apply(
        float $tuitionFee,
        float $labFee,
        float $miscFee,
        string $discountType,
        bool $isTakingNstp = false
    ): array {
        // Step 1: Apply 100% discount (zero out everything) when discount_type = 'full'
        if ($discountType === 'full') {
            $tuition = 0.00;
            $lab     = 0.00;
            $misc    = 0.00;
            $applied = 'full';

            // Step 2: If also taking NSTP, restore tuition to fixed ₱546
            if ($isTakingNstp) {
                $tuition = self::NSTP_TUITION_FEE;
                $lab     = $labFee;   // as-is
                $misc    = $miscFee;  // as-is
                $applied = 'full_with_nstp';
            }
        } elseif ($discountType === 'nstp' || $isTakingNstp) {
            // NSTP only (no full discount) — tuition = 546, rest as-is
            $tuition = self::NSTP_TUITION_FEE;
            $lab     = $labFee;
            $misc    = $miscFee;
            $applied = 'nstp';
        } else {
            // No discount — all as-is
            $tuition = $tuitionFee;
            $lab     = $labFee;
            $misc    = $miscFee;
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
     * Convenience: compute from a StudentAssessment-like data array.
     *
     * @param  array  $data  Keys: tuition_fee, lab_fee, misc_fee, discount_type, is_taking_nstp
     * @return array Same shape as apply()
     */
    public function applyFromAssessment(array $data): array
    {
        return $this->apply(
            tuitionFee:   (float) ($data['tuition_fee'] ?? 0),
            labFee:       (float) ($data['lab_fee'] ?? 0),
            miscFee:      (float) ($data['misc_fee'] ?? 0),
            discountType: $data['discount_type'] ?? 'none',
            isTakingNstp: (bool)  ($data['is_taking_nstp'] ?? false),
        );
    }
}