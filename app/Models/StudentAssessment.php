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
        'course',           // added by 2026_03_17 migration
        'year_level',
        'semester',
        'school_year',
        'lec_units',        // added by 2026_04_11 refactor migration
        'lab_units',
        'lab_subjects',
        'discount_type',    // added by 2026_04_17 migration
        'discount_percentage',
        'is_taking_nstp',
        'tuition_fee',
        'lab_fee',
        'misc_fee',
        'total_assessment',
        'status',
    ];

    public const MINIMUM_UNITS = 1.5;

    protected $casts = [
        'lec_units'            => 'integer',
        'lab_units'            => 'integer',
        'lab_subjects'         => 'integer',
        'discount_percentage'  => 'decimal:2',
        'discount_type'        => 'string',
        'is_taking_nstp'       => 'boolean',
        'tuition_fee'          => 'decimal:2',
        'lab_fee'              => 'decimal:2',
        'misc_fee'             => 'decimal:2',
        'total_assessment'     => 'decimal:2',
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
        return (float) ($this->attributes['tuition_fee'] ?? 0);
    }

    public function getLabFeeAttribute(): float
    {
        return (float) ($this->attributes['lab_fee'] ?? 0);
    }

    public function getMiscFeeAttribute(): float
    {
        return (float) ($this->attributes['misc_fee'] ?? 0);
    }

    public function getOutstandingBalanceAttribute(): float
    {
        return (float) $this->paymentTerms->sum('balance');
    }

    // ─── Static Methods ───────────────────────────────────────────────────────

    public static function generateAssessmentNumber(): string
    {
        $year = date('Y');

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