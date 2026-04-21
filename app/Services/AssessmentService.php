<?php

namespace App\Services;

use App\Models\FeeSetting;
use App\Models\Subject;

/**
 * AssessmentService
 *
 * Single source of truth for fee computation, curriculum lookup, and
 * discount application for CCDI student assessments (AY 2025-2026).
 *
 * ── BILLING RULES ─────────────────────────────────────────────────────────────
 *   Tuition   = billable_lec_units × tuition_per_unit
 *               + nstp_lec_units   × tuition_per_unit  (always full price)
 *   Lab Fee   = (count of subjects with lab_units > 0) × lab_fee_per_subject
 *               + ₱600 entrepreneurship_fee (flat, once, if any lab subjects)
 *   Misc Fee  = ₱4,700 fixed
 *   Total     = tuition + lab_fee + misc_fee
 *
 * ── NSTP / PATHFIT CHED EXCLUSION RULES ──────────────────────────────────────
 *   NSTP subjects:
 *     - Excluded from BILLABLE lec_units (tracked separately as nstp_lec_units)
 *     - NSTP tuition is billed at FULL PRICE regardless of any discount
 *     - Discount percentage NEVER applies to the NSTP portion
 *   PATHFIT / PE subjects:
 *     - Excluded from tuition billing entirely (CHED non-tuition subjects)
 *     - No discount concept — they were never billed to begin with
 *
 * ── DISCOUNT POLICY ───────────────────────────────────────────────────────────
 *   A single discount_percentage (0–100) is the only discount input.
 *   0   → no discount; full billing
 *   >0  → discount applies ONLY to billable (non-NSTP) tuition
 *
 *   Formula:
 *     discounted_billable = billable_tuition × (1 - pct/100)
 *     final_tuition       = discounted_billable + nstp_tuition
 *     lab_fee             = unchanged  (never discounted)
 *     misc_fee            = unchanged  (never discounted)
 *
 *   Example — student with 18 billable lec units + 3 NSTP units, 25% discount:
 *     billable_tuition    = 18 × ₱364 = ₱6,552
 *     nstp_tuition        = 3  × ₱364 = ₱1,092  ← full price always
 *     discounted_billable = ₱6,552 × 0.75 = ₱4,914
 *     final_tuition       = ₱4,914 + ₱1,092 = ₱6,006
 */
class AssessmentService
{
    // ─── Constants ────────────────────────────────────────────────────────────

    /** NSTP minimum tuition per CHED: 1.5 units × rate (kept for legacy compat) */
    const NSTP_MINIMUM_UNITS = 1.5;

    // ─── Fee Rates ────────────────────────────────────────────────────────────

    /**
     * Load all active fee rates from fee_settings table.
     * Falls back to config values if the table is not seeded.
     *
     * @return array{
     *   tuition_per_unit: float,
     *   lab_fee_per_subject: float,
     *   entrepreneurship_fee: float,
     *   misc_total: float,
     *   misc_items: array,
     *   payment_terms: array
     * }
     */
    public static function loadRates(): array
    {
        $settings = FeeSetting::allActive();

        $tuitionPerUnit   = (float) ($settings['tuition_per_unit']?->amount     ?? config('fees.tuition_per_lec_unit', 364.00));
        $labFeePerSubject = (float) ($settings['lab_fee_per_subject']?->amount   ?? config('fees.lab.per_subject',      1656.00));
        $entrepreneurFee  = (float) ($settings['entrepreneurship_fee']?->amount  ?? config('fees.lab.entrepreneurship_fee', 600.00));

        $miscItems = $settings
            ->whereIn('category', ['miscellaneous', 'other'])
            ->sortBy('sort_order')
            ->values()
            ->map(fn ($s) => [
                'id'       => $s->id,
                'key'      => $s->key,
                'label'    => $s->label,
                'amount'   => (float) $s->amount,
                'category' => $s->category,
            ])
            ->all();

        $miscTotal = collect($miscItems)->sum('amount');

        if ($miscTotal === 0.0) {
            $miscTotal = (float) config('fees.misc_fee_fixed', 4700.00);
        }

        $paymentTerms = [];
        for ($i = 1; $i <= 5; $i++) {
            $key = "term_{$i}_pct";
            if (isset($settings[$key])) {
                $paymentTerms[] = [
                    'term_name'  => $settings[$key]->label,
                    'term_order' => $i,
                    'percentage' => (float) $settings[$key]->amount,
                ];
            }
        }

        if (empty($paymentTerms)) {
            $paymentTerms = config('fees.payment_terms', []);
        }

        return [
            'tuition_per_unit'     => $tuitionPerUnit,
            'lab_fee_per_subject'  => $labFeePerSubject,
            'entrepreneurship_fee' => $entrepreneurFee,
            'misc_total'           => $miscTotal,
            'misc_items'           => $miscItems,
            'payment_terms'        => $paymentTerms,
        ];
    }

    // ─── Curriculum Lookup ────────────────────────────────────────────────────

    /**
     * Get curriculum subjects for a regular student and compute billable units.
     *
     * Returns has_nstp = true when the curriculum includes NSTP subjects.
     * The caller (Vue form) should display a notice when has_nstp is true,
     * informing accounting that the NSTP tuition portion will NOT be discounted
     * regardless of the discount percentage entered.
     *
     * @return array{
     *   subjects: array,
     *   billable_lec_units: int,
     *   nstp_lec_units: int,
     *   has_nstp: bool,
     *   lab_subject_count: int,
     *   pathfit_units: int,
     *   total_units: int
     * }
     */
    public static function getCurriculumUnits(string $course, string $yearLevel, string $semester): array
    {
        $semesterDb = self::normalizeSemester($semester);

        $subjects = Subject::where('course', $course)
            ->where('year_level', $yearLevel)
            ->where('semester', $semesterDb)
            ->where('is_active', true)
            ->get();

        $billableLecUnits = 0;
        $nstpLecUnits     = 0;
        $labSubjectCount  = 0;
        $pathfitUnits     = 0;
        $subjectList      = [];

        foreach ($subjects as $subj) {
            $isNstp    = self::isNstpSubject($subj->code, $subj->name);
            $isPathfit = self::isPathfitSubject($subj->code, $subj->name);

            if ($isNstp) {
                // NSTP: tracked separately, billed at full price, excluded from discount
                $nstpLecUnits += (int) ($subj->lec_units ?? 0);
            } elseif ($isPathfit) {
                // PATHFIT/PE: excluded from billing entirely (CHED non-tuition)
                $pathfitUnits += (int) ($subj->lec_units ?? 0);
            } else {
                // Normal billable subject
                $billableLecUnits += (int) ($subj->lec_units ?? 0);
                if ((int) ($subj->lab_units ?? 0) > 0) {
                    $labSubjectCount++;
                }
            }

            $subjectList[] = [
                'id'          => $subj->id,
                'code'        => $subj->code,
                'name'        => $subj->name,
                'lec_units'   => (int) ($subj->lec_units ?? 0),
                'lab_units'   => (int) ($subj->lab_units ?? 0),
                'total_units' => ((int) ($subj->lec_units ?? 0)) + ((int) ($subj->lab_units ?? 0)),
                'is_nstp'     => $isNstp,
                'is_pathfit'  => $isPathfit,
                'is_billable' => ! $isNstp && ! $isPathfit,
            ];
        }

        return [
            'subjects'           => $subjectList,
            'billable_lec_units' => $billableLecUnits,
            'nstp_lec_units'     => $nstpLecUnits,
            'has_nstp'           => $nstpLecUnits > 0,
            'lab_subject_count'  => $labSubjectCount,
            'pathfit_units'      => $pathfitUnits,
            'total_units'        => $billableLecUnits + $nstpLecUnits + $pathfitUnits,
        ];
    }

    // ─── Fee Computation ──────────────────────────────────────────────────────

    /**
     * Compute the full assessment fee breakdown.
     *
     * DISCOUNT RULE:
     *   discount_percentage applies ONLY to billable (non-NSTP) tuition.
     *   NSTP tuition is always billed at full price.
     *   Lab and miscellaneous fees are NEVER discounted.
     *
     * @param  int        $lecUnits            Billable lecture units (NSTP/PATHFIT excluded)
     * @param  int        $labSubjects          Number of subjects with lab_units > 0
     * @param  int        $nstpLecUnits         NSTP lecture units (billed full, never discounted)
     * @param  float      $discountPercentage   0–100. 0 means no discount.
     * @param  array|null $rates                Output of loadRates(). Loaded fresh if null.
     * @return array{
     *   tuition_fee: float,
     *   billable_tuition: float,
     *   nstp_tuition: float,
     *   lab_fee: float,
     *   entrepreneurship_fee: float,
     *   misc_fee: float,
     *   total: float,
     *   discount_saving: float,
     *   discount_applied: string,
     *   raw_billable_tuition: float
     * }
     */
    public static function compute(
        int    $lecUnits,
        int    $labSubjects,
        int    $nstpLecUnits       = 0,
        float  $discountPercentage = 0.0,
        ?array $rates              = null
    ): array {
        $rates ??= self::loadRates();

        $tuitionPerUnit   = $rates['tuition_per_unit'];
        $labFeePerSubject = $rates['lab_fee_per_subject'];
        $entrepreneurFee  = $labSubjects > 0 ? $rates['entrepreneurship_fee'] : 0.0;

        // Lab and misc are NEVER discounted
        $labFee  = round($labSubjects * $labFeePerSubject, 2);
        $miscFee = round($rates['misc_total'], 2);

        // Compute tuition components
        $rawBillableTuition = round($lecUnits * $tuitionPerUnit, 2);
        $nstpTuition        = round($nstpLecUnits * $tuitionPerUnit, 2); // always full price

        // Apply discount ONLY to billable (non-NSTP) tuition
        if ($discountPercentage > 0 && $discountPercentage <= 100) {
            $discountSaving     = round($rawBillableTuition * ($discountPercentage / 100), 2);
            $discountedBillable = round($rawBillableTuition - $discountSaving, 2);
            $discountApplied    = "percentage_{$discountPercentage}pct";
        } else {
            $discountSaving     = 0.0;
            $discountedBillable = $rawBillableTuition;
            $discountApplied    = 'none';
        }

        $finalTuition = $discountedBillable + $nstpTuition;
        $total        = round($finalTuition + $labFee + $entrepreneurFee + $miscFee, 2);

        return [
            'tuition_fee'          => round($finalTuition, 2),
            'billable_tuition'     => round($discountedBillable, 2),
            'nstp_tuition'         => round($nstpTuition, 2),
            'lab_fee'              => round($labFee, 2),
            'entrepreneurship_fee' => round($entrepreneurFee, 2),
            'misc_fee'             => round($miscFee, 2),
            'total'                => $total,
            'discount_saving'      => round($discountSaving, 2),
            'discount_applied'     => $discountApplied,
            'raw_billable_tuition' => $rawBillableTuition,
        ];
    }

    /**
     * Convenience wrapper: compute() accepts the legacy $isTakingNstp bool
     * for backward compatibility with callers that haven't been migrated yet.
     *
     * @deprecated Pass nstpLecUnits directly to compute() instead.
     */
    public static function computeWithNstpFlag(
        int    $lecUnits,
        int    $labSubjects,
        bool   $isTakingNstp       = false,
        float  $discountPercentage = 0.0,
        ?array $rates              = null
    ): array {
        $rates          ??= self::loadRates();
        $nstpLecUnits    = $isTakingNstp ? (int) round(self::NSTP_MINIMUM_UNITS) : 0;

        return self::compute($lecUnits, $labSubjects, $nstpLecUnits, $discountPercentage, $rates);
    }

    /**
     * Build payment term records from a total assessment amount.
     */
    public static function buildPaymentTerms(float $total, array $rates): array
    {
        $terms = [];

        foreach ($rates['payment_terms'] as $config) {
            $amount = round($total * ($config['percentage'] / 100), 2);

            $terms[] = [
                'term_name'  => $config['term_name'],
                'term_order' => $config['term_order'],
                'percentage' => $config['percentage'],
                'amount'     => $amount,
                'balance'    => $amount,
                'status'     => 'unpaid',
                'due_date'   => null,
                'paid_date'  => null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        return $terms;
    }

    // ─── Subject Classification Helpers ───────────────────────────────────────

    /**
     * NSTP subjects: excluded from billable lec_units AND from discount.
     * Their tuition is always charged at full price.
     */
    public static function isNstpSubject(string $code, string $name): bool
    {
        $code = strtoupper(trim($code));
        $name = strtoupper(trim($name));

        return str_starts_with($code, 'NSTP')
            || str_contains($name, 'NATIONAL SERVICE TRAINING');
    }

    /**
     * PATHFIT/PE subjects: excluded from tuition billing per CHED.
     * They have no discount concept since they are not billed.
     */
    public static function isPathfitSubject(string $code, string $name): bool
    {
        $code = strtoupper(trim($code));
        $name = strtoupper(trim($name));

        return str_starts_with($code, 'PATHFIT')
            || str_starts_with($code, 'PE ')
            || in_array($code, ['PE1', 'PE2', 'PE3', 'PE4', 'PE 1', 'PE 2', 'PE 3', 'PE 4'])
            || str_contains($name, 'MOVEMENT COMPETENCY')
            || str_contains($name, 'EXERCISE-BASED FITNESS')
            || str_contains($name, 'OUTDOOR AND ADVENTURE')
            || str_contains($name, 'DANCE')
            || str_contains($name, 'RHYTHMIC')
            || str_contains($name, 'RECREATIONAL SPORTS')
            || str_contains($name, 'INDIVIDUAL AND TEAM SPORTS')
            || str_contains($name, 'PHYSICAL FITNESS')
            || str_contains($name, 'PATHFIT');
    }

    /**
     * Normalize semester from form value ("1st") to DB format ("1st Sem").
     */
    public static function normalizeSemester(string $semester): string
    {
        return match ($semester) {
            '1st'    => '1st Sem',
            '2nd'    => '2nd Sem',
            'Summer' => 'Summer',
            default  => $semester,
        };
    }

    /**
     * Denormalize DB semester ("1st Sem") to form value ("1st").
     */
    public static function denormalizeSemester(string $semester): string
    {
        return match ($semester) {
            '1st Sem' => '1st',
            '2nd Sem' => '2nd',
            default   => $semester,
        };
    }

    /**
     * Build the fee rates payload for the Vue Create/Edit form.
     */
    public static function feeRatesForForm(): array
    {
        $rates = self::loadRates();

        return [
            'tuition_per_unit'     => $rates['tuition_per_unit'],
            'lab_fee_per_subject'  => $rates['lab_fee_per_subject'],
            'entrepreneurship_fee' => $rates['entrepreneurship_fee'],
            'misc_total'           => $rates['misc_total'],
            'misc_items'           => $rates['misc_items'],
            'payment_terms'        => $rates['payment_terms'],
        ];
    }
}