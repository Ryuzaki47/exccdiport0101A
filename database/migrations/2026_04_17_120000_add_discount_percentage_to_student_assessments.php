<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADD DISCOUNT FEATURE TO STUDENT ASSESSMENTS
 *
 * Adds tuition discount functionality:
 *   - discount_percentage: 0-100 percent discount applied to tuition only
 *   - Minimum floor: 1.5 units × ₱364 = ₱546
 *
 * Formula:
 *   finalTuition = min + (fullTuition - min) × (1 - discount / 100)
 *
 * Where:
 *   - fullTuition = lec_units × ₱364
 *   - min = 1.5 × ₱364 = ₱546
 *
 * Examples:
 *   - 0% discount: ₱7,826 (full tuition)
 *   - 50% discount: ₱4,186
 *   - 100% discount: ₱546 (minimum)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_assessments', function (Blueprint $table) {
            if (!Schema::hasColumn('student_assessments', 'discount_percentage')) {
                $table->decimal('discount_percentage', 5, 2)
                    ->default(0)
                    ->after('lab_units')
                    ->comment('Tuition discount as percentage (0-100). Stored for auditability.');
            }
        });
    }

    public function down(): void
    {
        Schema::table('student_assessments', function (Blueprint $table) {
            $table->dropColumn('discount_percentage');
        });
    }
};
