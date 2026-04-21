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
 *               + 1.5 (NSTP fixed billing units) × tuition_per_unit  ← always 1.5
 *   Lab Fee   = (count of subjects with lab_units > 0) × lab_fee_per_subject
 *               + ₱600 entrepreneurship_fee (flat, once, if any lab subjects)
 *   Misc Fee  = ₱4,700 fixed
 *   Total     = tuition + lab_fee + misc_fee
 *
 * ── NSTP / PATHFIT CHED EXCLUSION RULES ──────────────────────────────────────
 *   NSTP subjects:
 *     - Excluded from BILLABLE lec_units (tracked separately)
 *     - ALWAYS billed at exactly 1.5 units regardless of curriculum unit count
 *       e.g. CS-NSTP1 lists 3 units in DB → billing is still 1.5 × ₱364 = ₱546
 *     - NSTP tuition is billed at FULL PRICE regardless of any discount
 *     - Discount percentage NEVER applies to the NSTP portion
 *     - Detected by str_contains($code, 'NSTP') — NOT str_starts_with —
 *       because all DB codes have a course prefix (CS-NSTP1, IT-NSTP1, etc.)
 *   PATHFIT / PE subjects:
 *     - Excluded from tuition billing entirely (CHED non-tuition subjects)
 *
 * ── COURSES WITH NSTP (from ccdi_portal.subjects table) ──────────────────────
 *   Associate in Computer Technology  → ACT-NSTP1, ACT-NSTP2  (3 lec units in DB)
 *   BS Computer Science               → CS-NSTP1,  CS-NSTP2   (3 lec units in DB)
 *   BS Eng. Technology - Electrical   → EET-NSTP1, EET-NSTP2  (3 lec units in DB)
 *   BS Eng. Technology - Electronics  → ECE-NSTP1, ECE-NSTP2  (3 lec units in DB)
 *   BS Information Systems            → IS-NSTP1,  IS-NSTP2   (3 lec units in DB)
 *   BS Information Technology         → IT-NSTP1,  IT-NSTP2   (3 lec units in DB)
 *   ALL 6 courses → billed at 1.5 units ONLY (not 3)
 *
 * ── DISCOUNT POLICY ───────────────────────────────────────────────────────────
 *   discount_percentage applies ONLY to billable (non-NSTP) tuition.
 *   NSTP tuition is always billed at full price (1.5 × rate = ₱546).
 *   Lab and miscellaneous fees are NEVER discounted.
 *
 *   Formula (example: BSCS 1st Yr 1st Sem, no discount):
 *     billable_tuition = 17 × ₱364 = ₱6,188
 *     nstp_tuition     = 1.5 × ₱364 = ₱546
 *     lab_fee          = 3 × ₱1,656 = ₱4,968
 *     entrep_fee       = ₱600
 *     misc_fee         = ₱4,700
 *     total            = ₱17,002
 */
class AssessmentService
{
    // ─── Constants ────────────────────────────────────────────────────────────

    /**
     * NSTP billing units — ALWAYS 1.5 for ALL courses, regardless of DB value.
     * DB stores 3 units for every course's NSTP subject.
     * Admin instruction: bill only 1.5 units = ₱546.00 (at ₱364/unit).
     */
    const NSTP_MINIMUM_UNITS = 1.5;

    // ─── Fee Rates ────────────────────────────────────────────────────────────

    /**
     * Load all active fee rates from fee_settings table.
     * Falls back to config values if the table is not seeded.
     */
    public static function loadRates(): array
    {
        $settings = FeeSetting::allActive();

        $tuitionPerUnit   = (float) ($settings['tuition_per_unit']?->amount    ?? config('fees.tuition_per_lec_unit', 364.00));
        $labFeePerSubject = (float) ($settings['lab_fee_per_subject']?->amount  ?? config('fees.lab.per_subject',      1656.00));
        $entrepreneurFee  = (float) ($settings['entrepreneurship_fee']?->amount ?? config('fees.lab.entrepreneurship_fee', 600.00));

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
     * Handles ALL 6 courses in ccdi_portal:
     *   - Associate in Computer Technology (ACT)
     *   - BS Computer Science (BSCS)
     *   - BS Engineering Technology - Electrical (BSEET)
     *   - BS Engineering Technology - Electronics (BSEECT)
     *   - BS Information Systems (BSIS)
     *   - BS Information Technology (BSIT)
     *
     * NSTP detection uses str_contains($code, 'NSTP') to match all course-prefixed
     * codes: CS-NSTP1, IT-NSTP1, ACT-NSTP1, EET-NSTP1, ECE-NSTP1, IS-NSTP1, etc.
     *
     * nstp_lec_units returned is ALWAYS 1.5 when NSTP is present —
     * never the DB value (which is 3 for all 6 courses).
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
        $hasNstp          = false;
        $labSubjectCount  = 0;
        $pathfitUnits     = 0;
        $subjectList      = [];

        foreach ($subjects as $subj) {
            $isNstp    = self::isNstpSubject($subj->code, $subj->name);
            $isPathfit = self::isPathfitSubject($subj->code, $subj->name);

            if ($isNstp) {
                // Mark NSTP presence only — billing units are fixed at 1.5, NOT the DB value (3)
                // Applies to: CS-NSTP1/2, IT-NSTP1/2, ACT-NSTP1/2,
                //             EET-NSTP1/2, ECE-NSTP1/2, IS-NSTP1/2
                $hasNstp = true;
            } elseif ($isPathfit) {
                // PATHFIT/PE: excluded from billing per CHED
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

        // NSTP billing is ALWAYS 1.5 units for ALL courses — never the DB value (3)
        $nstpBillingUnits = $hasNstp ? self::NSTP_MINIMUM_UNITS : 0;

        return [
            'subjects'           => $subjectList,
            'billable_lec_units' => $billableLecUnits,
            'nstp_lec_units'     => $nstpBillingUnits, // always 1.5, never 3
            'has_nstp'           => $hasNstp,
            'lab_subject_count'  => $labSubjectCount,
            'pathfit_units'      => $pathfitUnits,
            'total_units'        => $billableLecUnits + (int) $nstpBillingUnits + $pathfitUnits,
        ];
    }

    // ─── Fee Computation ──────────────────────────────────────────────────────

    /**
     * Compute the full assessment fee breakdown.
     *
     * NSTP RULE (enforced for ALL 6 courses):
     *   $nstpLecUnits is clamped to NSTP_MINIMUM_UNITS (1.5) when > 0.
     *   This is the final safety net — even if a caller accidentally passes 3,
     *   it will be clamped to 1.5 before computing.
     *
     * DISCOUNT RULE:
     *   discount_percentage applies ONLY to billable (non-NSTP) tuition.
     *   NSTP tuition (1.5 × ₱364 = ₱546) is always billed at full price.
     *   Lab and miscellaneous fees are NEVER discounted.
     *
     * @param  int        $lecUnits            Billable lec units (NSTP/PATHFIT excluded)
     * @param  int        $labSubjects         Number of subjects with lab_units > 0
     * @param  float      $nstpLecUnits        NSTP units — clamped to 1.5 if > 0
     * @param  float      $discountPercentage  0–100. 0 = no discount.
     * @param  array|null $rates               Output of loadRates(). Loaded fresh if null.
     */
    public static function compute(
        int    $lecUnits,
        int    $labSubjects,
        float  $nstpLecUnits       = 0,
        float  $discountPercentage = 0.0,
        ?array $rates              = null
    ): array {
        $rates ??= self::loadRates();

        // ── NSTP BILLING RULE — ALL 6 COURSES ────────────────────────────────
        // DB stores NSTP as 3 lec_units for every course.
        // Admin instruction: ALWAYS bill at 1.5 units only.
        // Codes: CS-NSTP1/2, IT-NSTP1/2, ACT-NSTP1/2,
        //        EET-NSTP1/2, ECE-NSTP1/2, IS-NSTP1/2
        if ($nstpLecUnits > 0) {
            $nstpLecUnits = self::NSTP_MINIMUM_UNITS; // clamp to 1.5
        }
        // ─────────────────────────────────────────────────────────────────────

        $tuitionPerUnit   = $rates['tuition_per_unit'];
        $labFeePerSubject = $rates['lab_fee_per_subject'];
        $entrepreneurFee  = $labSubjects > 0 ? $rates['entrepreneurship_fee'] : 0.0;

        // Lab and misc are NEVER discounted
        $labFee  = round($labSubjects * $labFeePerSubject, 2);
        $miscFee = round($rates['misc_total'], 2);

        // Tuition components
        $rawBillableTuition = round($lecUnits * $tuitionPerUnit, 2);
        $nstpTuition        = round($nstpLecUnits * $tuitionPerUnit, 2); // 1.5 × 364 = 546

        // Discount applies ONLY to billable (non-NSTP) tuition
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
     * Legacy wrapper — kept for backward compatibility.
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
        $rates        ??= self::loadRates();
        // Pass 1 so compute() clamps to 1.5 via NSTP_MINIMUM_UNITS
        $nstpLecUnits   = $isTakingNstp ? 1 : 0;

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
     * Detect NSTP subjects for ALL 6 courses in ccdi_portal.
     *
     * Uses str_contains($code, 'NSTP') — NOT str_starts_with — because
     * every course prefixes the code before NSTP:
     *   ACT-NSTP1, ACT-NSTP2  → Associate in Computer Technology
     *   CS-NSTP1,  CS-NSTP2   → BS Computer Science
     *   EET-NSTP1, EET-NSTP2  → BS Engineering Technology - Electrical
     *   ECE-NSTP1, ECE-NSTP2  → BS Engineering Technology - Electronics
     *   IS-NSTP1,  IS-NSTP2   → BS Information Systems
     *   IT-NSTP1,  IT-NSTP2   → BS Information Technology
     *
     * All of the above return true from this method.
     * All of them will be billed at 1.5 units (not 3) via NSTP_MINIMUM_UNITS.
     */
    public static function isNstpSubject(string $code, string $name): bool
    {
        $code = strtoupper(trim($code));
        $name = strtoupper(trim($name));

        return str_contains($code, 'NSTP')
            || str_contains($name, 'NATIONAL SERVICE TRAINING');
    }

    /**
     * PATHFIT/PE subjects: excluded from tuition billing per CHED.
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