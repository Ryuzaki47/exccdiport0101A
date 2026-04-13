<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class StudentAssessment extends Model
{
    protected $fillable = [
        'assessment_number',
        'user_id',
        'year_level',
        'semester',
        'school_year',
        'lec_units',
        'lab_units',
        'lab_subjects',
        'total_assessment',
        'status',
    ];

    protected $casts = [
        'lec_units'        => 'integer',
        'lab_units'        => 'integer',
        'lab_subjects'     => 'integer',
        'total_assessment' => 'decimal:2',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentTerms(): HasMany
    {
        return $this->hasMany(StudentPaymentTerm::class, 'student_assessment_id')
            ->orderBy('term_order');
    }

    // ─── Computed Attributes ──────────────────────────────────────────────────

    public function getTotalUnitsAttribute(): int
    {
        return $this->lec_units + $this->lab_units;
    }

    public function getTuitionFeeAttribute(): float
    {
        return $this->lec_units * (float) config('fees.tuition_per_lec_unit', 364.00);
    }

    public function getLabFeeAttribute(): float
    {
        return $this->lab_subjects * (float) config('fees.lab_fee_per_subject', 1656.00);
    }

    public function getMiscFeeAttribute(): float
    {
        return (float) config('fees.misc_fee_fixed', 4700.00);
    }

    public function getOutstandingBalanceAttribute(): float
    {
        return (float) $this->paymentTerms->sum('balance');
    }

    // ─── Static Methods ───────────────────────────────────────────────────────

    /**
     * Generate a unique, race-condition-safe assessment number.
     *
     * FIX: Uses DB-level MAX() on the extracted numeric suffix instead of
     * ORDER BY on a string column. This is immune to lexicographic sort
     * bugs and correctly handles all existing records regardless of status.
     *
     * IMPORTANT: This method MUST be called inside a DB::transaction() that
     * also holds a lockForUpdate() on the relevant rows — the controller's
     * store() method already does this. Do NOT call this outside a transaction.
     *
     * Format: ASMT-{year}-{sequential zero-padded to 4 digits}
     * Example: ASMT-2026-0001
     */
    public static function generateAssessmentNumber(): string
    {
        $year = date('Y');

        // Extract the numeric suffix from all records for this year using
        // a DB-level CAST, so we get the true numeric maximum — not a
        // lexicographic string maximum which breaks on 10, 11, etc.
        $maxNum = DB::table('student_assessments')
            ->where('assessment_number', 'like', "ASMT-{$year}-%")
            ->selectRaw("MAX(CAST(SUBSTRING_INDEX(assessment_number, '-', -1) AS UNSIGNED)) as max_num")
            ->value('max_num');

        $nextNum = (int) $maxNum + 1;

        return sprintf('ASMT-%s-%04d', $year, $nextNum);
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeArchived($query)
    {
        return $query->where('status', 'archived');
    }
}