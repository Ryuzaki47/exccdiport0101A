<?php

/**
 * CCDI Fee Configuration — AY 2025-2026
 *
 * Source: Rate of Conduct of Consultation, March 4, 2025
 * Approved increase of 15% from AY 2024-2025 rates.
 *
 * To update rates for a new school year:
 *   1. Change the values below
 *   2. Run: php artisan config:clear
 *   No other code changes required.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Tuition Rate
    |--------------------------------------------------------------------------
    | Charged per lecture unit enrolled.
    | AY 2024-2025: ₱317.00  →  AY 2025-2026: ₱364.00 (+15%)
    */
    'tuition_per_lec_unit' => env('CCDI_TUITION_PER_UNIT', 364.00),

    /*
    |--------------------------------------------------------------------------
    | Laboratory Fee
    |--------------------------------------------------------------------------
    | Charged per laboratory unit enrolled.
    | AY 2024-2025: ₱1,440.00  →  AY 2025-2026: ₱1,656.00 (+15%)
    */
    'lab_fee_per_unit' => env('CCDI_LAB_FEE_PER_UNIT', 1656.00),

    /*
    |--------------------------------------------------------------------------
    | Miscellaneous Fees (Fixed Per Semester)
    |--------------------------------------------------------------------------
    | Charged once per semester regardless of subject load.
    | This is the sum of all line items in the misc fee schedule.
    |
    | Breakdown:
    |   Entrepreneurship Fee ₱600   ← was mislabeled "Registration Fee" before
    |   LMS                  ₱450
    |   Library Fee          ₱450
    |   Athletic Fee         ₱550
    |   PRISAA               ₱300
    |   Publication Fee      ₱200
    |   Audio-Visual Fee     ₱250
    |   ID                   ₱300
    |   Faculty Development  ₱250
    |   Guidance Services    ₱225
    |   Medical              ₱300
    |   Insurance Fee        ₱100
    |   Cultural Arts Fee    ₱175
    |   Maintenance Fee      ₱400
    |   ──────────────────────────
    |   TOTAL                ₱4,700
    |
    | NOTE: Registration Fee = ₱0 (Free). It is displayed in the fee breakdown
    | on the assessment form and PDF but adds nothing to the total.
    |
    | NOTE: Laboratory fee (₱1,656 per lab unit) is billed separately
    | via lab_fee_per_unit above and is NOT included in this total.
    */
    'misc_fee_fixed' => env('CCDI_MISC_FEE', 4700.00),

    /*
    |--------------------------------------------------------------------------
    | Miscellaneous Fee Line Items (for display only)
    |--------------------------------------------------------------------------
    | Used by the assessment form and PDF to render the itemized misc breakdown.
    | These do NOT affect the total — misc_fee_fixed above is authoritative.
    | Amounts here are informational only. Registration Fee is listed as 0
    | because it is free (no charge to the student).
    */
    'misc_items' => [
        ['label' => 'Registration Fee',    'amount' => 0.00],
        ['label' => 'Entrepreneurship Fee','amount' => 600.00],
        ['label' => 'LMS',                 'amount' => 450.00],
        ['label' => 'Library Fee',         'amount' => 450.00],
        ['label' => 'Athletic Fee',        'amount' => 550.00],
        ['label' => 'PRISAA',              'amount' => 300.00],
        ['label' => 'Publication Fee',     'amount' => 200.00],
        ['label' => 'Audio-Visual Fee',    'amount' => 250.00],
        ['label' => 'ID',                  'amount' => 300.00],
        ['label' => 'Faculty Development', 'amount' => 250.00],
        ['label' => 'Guidance Services',   'amount' => 225.00],
        ['label' => 'Medical',             'amount' => 300.00],
        ['label' => 'Insurance Fee',       'amount' => 100.00],
        ['label' => 'Cultural Arts Fee',   'amount' => 175.00],
        ['label' => 'Maintenance Fee',     'amount' => 400.00],
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Terms
    |--------------------------------------------------------------------------
    | How the total assessment is split into payment terms.
    | Percentages must sum to 100.
    |
    | term_name   → label shown to student/accounting
    | percentage  → portion of total due at that term (0–100)
    */
    'payment_terms' => [
        ['term_name' => 'Upon Registration', 'term_order' => 1, 'percentage' => 25],
        ['term_name' => 'Prelim',            'term_order' => 2, 'percentage' => 25],
        ['term_name' => 'Midterm',           'term_order' => 3, 'percentage' => 25],
        ['term_name' => 'Semi-Final',        'term_order' => 4, 'percentage' => 12.5],
        ['term_name' => 'Final',             'term_order' => 5, 'percentage' => 12.5],
    ],

];