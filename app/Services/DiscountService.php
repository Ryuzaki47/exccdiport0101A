<?php

namespace App\Services;

/**
 * DiscountService
 *
 * NOTE: This class is retained for legacy compatibility only.
 * All new assessment fee computation must use AssessmentService::compute().
 *
 * ── CHED EXCLUSION RULES (not discount — billing exclusion) ───────────────────
 *   NSTP subjects    → EXCLUDED from tuition billing entirely
 *                      NSTP is also NEVER subject to discount — it is billed
 *                      at full rate when applicable
 *   PATHFIT / PE     → EXCLUDED from tuition billing entirely
 *                      These are non-tuition per CHED — no discount concept
 *                      applies because they were never billed to begin with
 *
 * ── DISCOUNT POLICY (tuition only) ───────────────────────────────────────────
 *   Only the TUITION component is ever discounted.
 *   Lab fees and miscellaneous fees are NEVER discounted under any policy —
 *   they cover consumables, equipment use, insurance, library, and
 *   institutional funds that cannot be waived.
 *
 *   'none'       → No discount. Full tuition.
 *   'full'       → Tuition = ₱0. If also taking NSTP, floors at NSTP minimum.
 *   'nstp'       → Tuition fixed at NSTP minimum (1.5 units × rate).
 *   'percentage' → Partial: tuition = rawTuition × (1 - pct/100).
 *                  Floors at NSTP minimum when student is also taking NSTP.
 */
class DiscountService
{
    /** Fixed NSTP minimum: 1.5 units × ₱364 = ₱546 */
    const NSTP_MINIMUM_UNITS = 1.5;

    /**
     * Apply the CCDI discount policy to a raw fee breakdown.
     *
     * @param  float  $tuitionFee       Raw tuition for billable lec_units
     * @param  float  $nstpUnits        NSTP lecture units (always billed at full price, never discounted)
     * @param  float  $labFee           Raw lab fees (never discounted)
     * @param  float  $miscFee          Fixed misc (never discounted)
     * @param  string $discountType     'none' | 'full' | 'nstp' | 'percentage'
     * @param  float  $rate             Per-unit tuition rate (₱364)
     * @param  float  $discountPct      Percentage for 'percentage' type (0–100)
     * @return array{tuition: float, lab: float, misc: float, total: float, applied: string}
     */
    public function apply(
        float  $tuitionFee,
        float  $nstpUnits,
        float  $labFee,
        float  $miscFee,
        string $discountType,
        float  $rate        = 364.00,
        float  $discountPct = 0.0
    ): array {
        // Lab and misc are NEVER discounted
        $lab  = $labFee;
        $misc = $miscFee;

        // NSTP portion — always at full price, never discounted
        $nstpTuition    = round($nstpUnits * $rate, 2);
        $nstpMinTuition = round(self::NSTP_MINIMUM_UNITS * $rate, 2);

        switch ($discountType) {
            case 'full':
                $billableTuition = $nstpUnits > 0 ? $nstpMinTuition : 0.00;
                $applied = $nstpUnits > 0 ? 'full_with_nstp' : 'full';
                break;

            case 'nstp':
                $billableTuition = $nstpMinTuition;
                $applied = 'nstp';
                break;

            case 'percentage':
                if ($discountPct > 0) {
                    $discounted      = round($tuitionFee * (1 - $discountPct / 100), 2);
                    $billableTuition = $nstpUnits > 0 ? max($nstpMinTuition, $discounted) : $discounted;
                    $applied         = "percentage_{$discountPct}pct";
                } else {
                    $billableTuition = $tuitionFee;
                    $applied = 'none';
                }
                break;

            default: // 'none'
                $billableTuition = $tuitionFee;
                $applied = 'none';
                break;
        }

        $finalTuition = $billableTuition + ($discountType !== 'nstp' ? $nstpTuition : 0);

        return [
            'tuition' => round($finalTuition, 2),
            'lab'     => round($lab, 2),
            'misc'    => round($misc, 2),
            'total'   => round($finalTuition + $lab + $misc, 2),
            'applied' => $applied,
        ];
    }
}