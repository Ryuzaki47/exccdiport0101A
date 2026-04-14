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
    | Tuition Rate (Lecture Only)
    |--------------------------------------------------------------------------
    | Charged per lecture unit enrolled.
    | AY 2024-2025: ₱317.00  →  AY 2025-2026: ₱364.00 (+15%)
    */
    'tuition_per_lec_unit' => env('CCDI_TUITION_PER_UNIT', 364.00),

    /*
    |--------------------------------------------------------------------------
    | Laboratory Fees
    |--------------------------------------------------------------------------
    | Charged per laboratory unit enrolled.
    | AY 2024-2025: ₱1,440.00  →  AY 2025-2026: ₱1,656.00 (+15%)
    |
    | NOTE:
    | - Entrepreneurship Fee (₱600) is ALWAYS applied when
    |   there is at least 1 laboratory unit.
    */
    'lab' => [
        'per_unit' => env('CCDI_LAB_FEE_PER_UNIT', 1656.00),
        'entrepreneurship_fee' => 600.00,
    ],

    /*
    |--------------------------------------------------------------------------
    | Miscellaneous Fees (Fixed Per Semester)
    |--------------------------------------------------------------------------
    | Total is fixed at ₱4,700 per semester.
    |
    | NOTE:
    | - Miscellaneous fees are NOT unit-based
    | - Always applied regardless of lecture/lab units
    | - Do NOT recompute total from items
    */
    'misc_fee_fixed' => env('CCDI_MISC_FEE', 4700.00),

    /*
    |--------------------------------------------------------------------------
    | Miscellaneous Fee Breakdown (Display Only)
    |--------------------------------------------------------------------------
    | This is used for UI/PDF display only.
    | Must match the fixed total above (₱4,700).
    */
    'misc_items' => [
        ['label' => 'Registration Fee',    'amount' => 600.00],
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
    */
    'payment_terms' => [
        ['term_name' => 'Upon Registration', 'term_order' => 1, 'percentage' => 25],
        ['term_name' => 'Prelim',            'term_order' => 2, 'percentage' => 25],
        ['term_name' => 'Midterm',           'term_order' => 3, 'percentage' => 25],
        ['term_name' => 'Semi-Final',        'term_order' => 4, 'percentage' => 12.5],
        ['term_name' => 'Final',             'term_order' => 5, 'percentage' => 12.5],
    ],

];