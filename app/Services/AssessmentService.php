<?php

namespace App\Services;

use App\Models\FeeSetting;
use App\Models\Subject;
use App\Models\User;

/**
 * AssessmentService
 *
 * Single source of truth for:
 *   1. Computing fee totals from fee_settings table (NOT config directly)
 *   2. Auto-populating curriculum units for regular students
 *   3. Enforcing NSTP/PATHFIT billing exclusion rules per CHED
 *   4. Applying discount policy
 *
 * ── BILLING RULES (AY 2025-2026) ─────────────────────────────────────────────
 *   Tuition   = billable_lec_units × tuition_per_unit
 *   Lab Fee   = (count of subjects with lab_units > 0) × lab_fee_per_subject
 *               + ₱600 entrepreneurship_fee (flat, once, if any lab subjects)
 *   Misc Fee  = ₱4,700 fixed (sum of all active misc fee_settings)
 *   Total     = tuition + lab_fee + misc_fee
 *
 * ── NSTP / PATHFIT CHED RULES ────────────────────────────────────────────────
 *   NSTP subjects   → EXCLUDED from lecture unit billing entirely
 *                     NSTP is also NEVER discounted — it is always full price
 *                     when the student pays for it separately
 *   PATHFIT / PE    → EXCLUDED from lecture unit billing entirely
 *                     These subjects contribute 0 to tuition
 *
 * ── DISCOUNT POLICY ──────────────────────────────────────────────────────────
 *   'none'       → Full billing, no changes
 *   'full'       → Tuition = 0 (100% waived)
 *                  If also taking NSTP: tuition floors at NSTP minimum
 *                  Lab and misc ALWAYS charged — they cover consumables and
 *                  institutional funds that cannot be waived
 *   'nstp'       → Tuition fixed at NSTP minimum (1.5 units × rate)
 *                  Lab and misc charged in full
 *   'percentage' → Partial discount: tuition = rawTuition × (1 - pct/100)
 *                  Floors at NSTP minimum when student is taking NSTP
 *                  Lab and misc ALWAYS charged in full
 */
class AssessmentService
{
    // ─── Constants ────────────────────────────────────────────────────────────

    /** NSTP minimum tuition per CHED: 1.5 units × rate */
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

        $tuitionPerUnit     = (float) ($settings['tuition_per_unit']?->amount     ?? config('fees.tuition_per_lec_unit', 364.00));
        $labFeePerSubject   = (float) ($settings['lab_fee_per_subject']?->amount   ?? config('fees.lab.per_subject',      1656.00));
        $entrepreneurFee    = (float) ($settings['entrepreneurship_fee']?->amount  ?? config('fees.lab.entrepreneurship_fee', 600.00));

        // Collect all misc items
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

        // Fall back to config misc if table not seeded
        if ($miscTotal === 0.0) {
            $miscTotal = (float) config('fees.misc_fee_fixed', 4700.00);
        }

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

        if (empty($paymentTerms)) {
            $paymentTerms = config('fees.payment_terms', []);
        }

        return [
            'tuition_per_unit'    => $tuitionPerUnit,
            'lab_fee_per_subject' => $labFeePerSubject,
            'entrepreneurship_fee' => $entrepreneurFee,
            'misc_total'          => $miscTotal,
            'misc_items'          => $miscItems,
            'payment_terms'       => $paymentTerms,
        ];
    }

    // ─── Curriculum Lookup ────────────────────────────────────────────────────

    /**
     * Get curriculum subjects for a regular student and compute billable units.
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
            $isNstp    = self::isNstpSubject($subj->code, $subj->name);
            $isPathfit = self::isPathfitSubject($subj->code, $subj->name);

            if ($isNstp) {
                $nstpUnits += (int) ($subj->lec_units ?? 0);
            } elseif ($isPathfit) {
                $pathfitUnits += (int) ($subj->lec_units ?? 0);
            } else {
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
     * CANONICAL RULES:
     *   - Lab fee    = labSubjects × lab_fee_per_subject
     *                  + entrepreneurship_fee (flat ₱600, once, if labSubjects > 0)
     *   - Misc fee   = fixed ₱4,700
     *   - Lab and misc are NEVER discounted under any policy
     *   - Only tuition is discountable
     *   - NSTP subjects are excluded from billing AND from discount
     *
     * @param  int    $lecUnits           Billable lecture units (NSTP/PATHFIT already excluded)
     * @param  int    $labSubjects        Number of subjects with lab_units > 0
     * @param  string $discountType       'none' | 'full' | 'nstp' | 'percentage'
     * @param  bool   $isTakingNstp       Whether student is enrolled in NSTP this semester
     * @param  float  $discountPercentage Percentage for 'percentage' type (0–100). Ignored for other types.
     * @param  array|null $rates          Output of loadRates(). Loaded fresh if null.
     * @return array{
     *   tuition_fee: float,
     *   lab_fee: float,
     *   entrepreneurship_fee: float,
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
        $entrepreneurFee  = $labSubjects > 0 ? $rates['entrepreneurship_fee'] : 0.0;

        // Lab and misc are NEVER discounted
        $labFee  = round($labSubjects * $labFeePerSubject, 2);
        $miscFee = round($rates['misc_total'], 2);

        // NSTP minimum tuition (1.5 units × rate)
        $nstpMinTuition = round(self::NSTP_MINIMUM_UNITS * $tuitionPerUnit, 2);
        $rawTuition     = round($lecUnits * $tuitionPerUnit, 2);

        // ── Apply discount_type ───────────────────────────────────────────────
        $discountApplied = 'none';

        switch ($discountType) {
            case 'full':
                if ($isTakingNstp) {
                    $tuitionFee      = $nstpMinTuition;
                    $discountApplied = 'full_with_nstp';
                } else {
                    $tuitionFee      = 0.00;
                    $discountApplied = 'full';
                }
                break;

            case 'nstp':
                $tuitionFee      = $nstpMinTuition;
                $discountApplied = 'nstp';
                break;

            case 'percentage':
                if ($discountPercentage <= 0) {
                    // Treated as no discount
                    $tuitionFee      = $rawTuition;
                    $discountApplied = 'none';
                } else {
                    $discounted = round($rawTuition * (1 - $discountPercentage / 100), 2);
                    // Floor at NSTP minimum when student is taking NSTP
                    $tuitionFee      = $isTakingNstp ? max($nstpMinTuition, $discounted) : $discounted;
                    $discountApplied = "percentage_{$discountPercentage}pct";
                }
                break;

            default: // 'none'
                $tuitionFee      = $rawTuition;
                $discountApplied = 'none';
                break;
        }

        $total = round($tuitionFee + $labFee + $entrepreneurFee + $miscFee, 2);

        return [
            'tuition_fee'         => $tuitionFee,
            'lab_fee'             => $labFee,
            'entrepreneurship_fee' => round($entrepreneurFee, 2),
            'misc_fee'            => $miscFee,
            'total'               => $total,
            'nstp_min_tuition'    => $nstpMinTuition,
            'discount_applied'    => $discountApplied,
            'raw_tuition'         => $rawTuition,
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

    /**
     * NSTP subjects are excluded from tuition billing AND are never discounted.
     * Pattern: code starts with NSTP, or name contains "National Service Training".
     */
    public static function isNstpSubject(string $code, string $name): bool
    {
        $code = strtoupper(trim($code));
        $name = strtoupper(trim($name));

        return str_starts_with($code, 'NSTP')
            || str_contains($name, 'NATIONAL SERVICE TRAINING');
    }

    /**
     * PATHFIT/PE subjects are excluded from tuition billing per CHED regulations.
     * They have no discount concept because they are never billed.
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
     * Always reads from fee_settings — never from config() directly.
     */
    public static function feeRatesForForm(): array
    {
        $rates = self::loadRates();

        return [
            'tuition_per_unit'    => $rates['tuition_per_unit'],
            'lab_fee_per_subject' => $rates['lab_fee_per_subject'],
            'entrepreneurship_fee' => $rates['entrepreneurship_fee'],
            'misc_total'          => $rates['misc_total'],
            'misc_items'          => $rates['misc_items'],
            'payment_terms'       => $rates['payment_terms'],
            'nstp_min_tuition'    => round(self::NSTP_MINIMUM_UNITS * $rates['tuition_per_unit'], 2),
        ];
    }
}