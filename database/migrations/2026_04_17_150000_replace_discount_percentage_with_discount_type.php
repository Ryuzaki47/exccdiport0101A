<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REPLACE: discount_percentage → discount_type + is_taking_nstp
 *
 * This migration removes the percentage-based discount and replaces it with:
 *   - discount_type: 'none' | 'full' | 'nstp'
 *   - is_taking_nstp: boolean (for NSTP policy enforcement)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_assessments', function (Blueprint $table) {
            // Add new discount columns if they don't already exist
            if (!Schema::hasColumn('student_assessments', 'discount_type')) {
                $table->enum('discount_type', ['none', 'full', 'nstp'])
                    ->default('none')
                    ->after('lab_units')
                    ->comment('Discount policy applied: none | full (100% waived) | nstp (NSTP course waiver)');
            }

            if (!Schema::hasColumn('student_assessments', 'is_taking_nstp')) {
                $table->boolean('is_taking_nstp')
                    ->default(false)
                    ->after('discount_type')
                    ->comment('Whether student is enrolled in NSTP course (affects tuition when discount_type=nstp or full)');
            }

            // Add fee columns if they don't exist
            if (!Schema::hasColumn('student_assessments', 'tuition_fee')) {
                $table->decimal('tuition_fee', 10, 2)
                    ->default(0)
                    ->after('is_taking_nstp');
            }

            if (!Schema::hasColumn('student_assessments', 'lab_fee')) {
                $table->decimal('lab_fee', 10, 2)
                    ->default(0)
                    ->after('tuition_fee');
            }

            if (!Schema::hasColumn('student_assessments', 'misc_fee')) {
                $table->decimal('misc_fee', 10, 2)
                    ->default(0)
                    ->after('lab_fee');
            }
        });
    }

    public function down(): void
    {
        Schema::table('student_assessments', function (Blueprint $table) {
            if (Schema::hasColumn('student_assessments', 'discount_type')) {
                $table->dropColumn('discount_type');
            }
            if (Schema::hasColumn('student_assessments', 'is_taking_nstp')) {
                $table->dropColumn('is_taking_nstp');
            }

            // Restore old column (for rollback)
            $table->decimal('discount_percentage', 5, 2)
                ->default(0)
                ->after('lab_units')
                ->comment('Tuition discount as percentage (0-100).');
        });
    }
};
