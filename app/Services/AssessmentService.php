<?php

namespace App\Services;

use App\Models\FeeSetting;
use App\Models\Subject;
use App\Models\User;

/**
 * AssessmentService
 *
 * Single source of truth for:
 *   1. Computing fee totals from fee_settings table (NOT config)
 *   2. Auto-populating curriculum units for regular students
 *   3. Enforcing NSTP/PATHFIT billing exclusion rules
 *   4. Applying discount policy
 *
 * BILLING RULES (AY 2025-2026):
 *   Tuition   = billable_lec_units × tuition_per_unit
 *   Lab Fee   = count(subjects with lab_units > 0) × lab_fee_per_subject
 *   Misc Fee  = sum of all active fee_settings where category IN ('miscellaneous','other')
 *   ─────────────────────────────────────────────────────────────────────
 *   Total     = tuition + lab_fee + misc_fee
 *
 * DISCOUNT POLICY (canonical — this file is the only source):
 *   'none'     → Full billing, no changes
 *   'nstp'     → Tuition floors at NSTP minimum (1.5 units × rate)
 *                Lab and misc charged in full
 *   'full'     → Tuition = 0 (100% waived)
 *                If student is also taking NSTP, tuition floors at NSTP minimum instead
 *                Lab and misc are ALWAYS charged — they cover consumables and institutional funds
 *   percentage → Additional partial discount: tuition = max(nstp_min, raw × (1 - pct/100))
 *                Lab and misc always charged in full
 *
 * NSTP / PATHFIT RULES:
 *   - NSTP subjects (code like 'NSTP%') are NEVER billed — excluded from lec_units
 *   - PATHFIT / PE subjects are non-tuition per CHED — excluded from lec_units
 *   - These subjects still appear in the curriculum list but contribute 0 to billing
 */
class AssessmentService
{
    // ─── Constants ────────────────────────────────────────────────────────────

    /**
     * NSTP minimum tuition per CHED rules: 1.5 units × rate.
     * Recalculated dynamically from fee_settings so it updates if the rate changes.
     */
    const NSTP_MINIMUM_UNITS = 1.5;

    /**
     * Subject codes / name patterns that are non-tuition-bearing.
     */
    const NON_TUITION_CODES = ['NSTP', 'PATHFIT', 'PE ', 'PE1', 'PE2', 'PE3', 'PE4'];

    // ─── Fee Rates ────────────────────────────────────────────────────────────

    /**
     * Load all active fee rates from fee_settings table.
     * Falls back to config values if the table isn't seeded yet.
     *
     * @return array{
     *   tuition_per_unit: float,
     *   lab_fee_per_subject: float,
     *   misc_total: float,
     *   misc_items: array,
     *   payment_terms: array
     * }
     */
    public static function loadRates(): array
    {
        $settings = FeeSetting::allActive();

        $tuitionPerUnit   = (float) ($settings['tuition_per_unit']?->amount   ?? config('fees.tuition_per_lec_unit', 364.00));
        $labFeePerSubject = (float) ($settings['lab_fee_per_subject']?->amount ?? config('fees.lab_fee_per_unit', 1656.00));

        // Collect all misc items (miscellaneous + other categories)
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

        // Payment terms from fee_settings
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

        // Fall back to config if no terms in fee_settings
        if (empty($paymentTerms)) {
            $paymentTerms = config('fees.payment_terms', []);
        }

        return [
            'tuition_per_unit'    => $tuitionPerUnit,
            'lab_fee_per_subject' => $labFeePerSubject,
            'misc_total'          => $miscTotal,
            'misc_items'          => $miscItems,
            'payment_terms'       => $paymentTerms,
        ];
    }

    // ─── Curriculum Lookup ────────────────────────────────────────────────────

    /**
     * Get curriculum subjects for a regular student and compute their billable units.
     *
     * @return array{
     *   subjects: array,
     *   billable_lec_units: int,
     *   lab_subject_count: int,
     *   nstp_units: int,
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
        $labSubjectCount  = 0;
        $nstpUnits        = 0;
        $pathfitUnits     = 0;
        $subjectList      = [];

        foreach ($subjects as $subj) {
            $isNstp     = self::isNstpSubject($subj->code, $subj->name);
            $isPathfit  = self::isPathfitSubject($subj->code, $subj->name);
            $isBillable = ! $isNstp && ! $isPathfit;

            if ($isNstp) {
                $nstpUnits += $subj->lec_units ?? 0;
            } elseif ($isPathfit) {
                $pathfitUnits += $subj->lec_units ?? 0;
            } else {
                $billableLecUnits += $subj->lec_units ?? 0;
                if (($subj->lab_units ?? 0) > 0) {
                    $labSubjectCount++;
                }
            }

            $subjectList[] = [
                'id'          => $subj->id,
                'code'        => $subj->code,
                'name'        => $subj->name,
                'lec_units'   => $subj->lec_units ?? 0,
                'lab_units'   => $subj->lab_units ?? 0,
                'total_units' => ($subj->lec_units ?? 0) + ($subj->lab_units ?? 0),
                'is_nstp'     => $isNstp,
                'is_pathfit'  => $isPathfit,
                'is_billable' => $isBillable,
            ];
        }

        return [
            'subjects'           => $subjectList,
            'billable_lec_units' => $billableLecUnits,
            'lab_subject_count'  => $labSubjectCount,
            'nstp_units'         => $nstpUnits,
            'pathfit_units'      => $pathfitUnits,
            'total_units'        => $billableLecUnits + $nstpUnits + $pathfitUnits,
        ];
    }

    // ─── Fee Computation ──────────────────────────────────────────────────────

    /**
     * Compute the full assessment fee breakdown.
     *
     * CANONICAL DISCOUNT RULES:
     *   Lab fee and misc fee are NEVER discounted — they cover consumables,
     *   equipment, insurance, library, and institutional funds.
     *   Only tuition is subject to discount.
     *
     * @param  int    $lecUnits           Billable lecture units (NSTP/PATHFIT already excluded)
     * @param  int    $labSubjects        Number of subjects with lab_units > 0
     * @param  string $discountType       'none' | 'full' | 'nstp'
     * @param  bool   $isTakingNstp       Whether student is enrolled in NSTP this semester
     * @param  float  $discountPercentage Optional partial discount percentage (0-100). Applied
     *                                    AFTER discount_type logic. Floors at NSTP minimum.
     * @param  array  $rates              Output of loadRates() — if null, loads fresh
     * @return array{
     *   tuition_fee: float,
     *   lab_fee: float,
     *   misc_fee: float,
     *   total: float,
     *   nstp_min_tuition: float,
     *   discount_applied: string,
     *   raw_tuition: float
     * }
     */
    public static function compute(
        int    $lecUnits,
        int    $labSubjects,
        string $discountType        = 'none',
        bool   $isTakingNstp        = false,
        float  $discountPercentage  = 0.0,
        ?array $rates               = null
    ): array {
        $rates ??= self::loadRates();

        $tuitionPerUnit   = $rates['tuition_per_unit'];
        $labFeePerSubject = $rates['lab_fee_per_subject'];
        // Lab and misc are NEVER discounted per CCDI policy
        $labFee  = round($labSubjects * $labFeePerSubject, 2);
        $miscFee = round($rates['misc_total'], 2);

        // NSTP minimum tuition (1.5 units × rate)
        $nstpMinTuition = round(self::NSTP_MINIMUM_UNITS * $tuitionPerUnit, 2);
        $rawTuition     = round($lecUnits * $tuitionPerUnit, 2);

        // ── Step 1: Apply discount_type ───────────────────────────────────────
        $discountApplied = 'none';

        if ($discountType === 'full') {
            if ($isTakingNstp) {
                // Full scholarship + NSTP: tuition floors at NSTP minimum
                $tuitionFee      = $nstpMinTuition;
                $discountApplied = 'full_with_nstp';
            } else {
                // Full scholarship: tuition = 0
                // Lab and misc are still charged — per CCDI policy
                $tuitionFee      = 0.00;
                $discountApplied = 'full';
            }
        } elseif ($discountType === 'nstp' || $isTakingNstp) {
            // NSTP waiver: tuition fixed at minimum
            $tuitionFee      = $nstpMinTuition;
            $discountApplied = 'nstp';
        } else {
            $tuitionFee      = $rawTuition;
            $discountApplied = 'none';
        }

        // ── Step 2: Apply partial discount_percentage on top ──────────────────
        // Only applies when discount_type = 'none' (percentage-based partial scholarship).
        // Floors at NSTP minimum. Ignored when discount_type = 'full' or 'nstp'.
        if ($discountPercentage > 0 && $discountType === 'none') {
            $discounted      = round($rawTuition * (1 - $discountPercentage / 100), 2);
            $tuitionFee      = max($nstpMinTuition, $discounted);
            $discountApplied = "partial_{$discountPercentage}pct";
        }

        $total = round($tuitionFee + $labFee + $miscFee, 2);

        return [
            'tuition_fee'      => $tuitionFee,
            'lab_fee'          => $labFee,
            'misc_fee'         => $miscFee,
            'total'            => $total,
            'nstp_min_tuition' => $nstpMinTuition,
            'discount_applied' => $discountApplied,
            'raw_tuition'      => $rawTuition,
        ];
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

    // ─── Helpers ──────────────────────────────────────────────────────────────

    public static function isNstpSubject(string $code, string $name): bool
    {
        $code = strtoupper($code);
        $name = strtoupper($name);

        return str_starts_with($code, 'NSTP')
            || str_contains($name, 'NATIONAL SERVICE TRAINING');
    }

    public static function isPathfitSubject(string $code, string $name): bool
    {
        $code = strtoupper($code);
        $name = strtoupper($name);

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
     * Normalize semester value from form ("1st","2nd","Summer")
     * to DB format ("1st Sem","2nd Sem","Summer").
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
     * Always reads from fee_settings — never from config().
     */
    public static function feeRatesForForm(): array
    {
        $rates = self::loadRates();

        return [
            'tuition_per_unit'    => $rates['tuition_per_unit'],
            'lab_fee_per_subject' => $rates['lab_fee_per_subject'],
            'misc_total'          => $rates['misc_total'],
            'misc_items'          => $rates['misc_items'],
            'payment_terms'       => $rates['payment_terms'],
            'nstp_min_tuition'    => round(self::NSTP_MINIMUM_UNITS * $rates['tuition_per_unit'], 2),
        ];
    }
}