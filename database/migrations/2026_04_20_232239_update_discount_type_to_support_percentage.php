<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UPDATE DISCOUNT SCHEMA — Percentage-Based Discounts
 *
 * Previous model: discount_type ENUM('none','full','nstp')
 * New model:
 *   - discount_type ENUM('none','full','nstp','percentage')
 *     'none'       → no discount
 *     'full'       → 100% tuition waived (NSTP floors still apply)
 *     'nstp'       → tuition fixed at NSTP minimum (1.5 units × rate)
 *     'percentage' → partial discount; actual % stored in discount_percentage
 *
 *   - discount_percentage DECIMAL(5,2)
 *     For 'percentage' type: 0.00–100.00 (e.g. 10.00 = 10% off tuition)
 *     For other types: 0.00 (unused)
 *
 * Policy reminder:
 *   - Only TUITION is discountable. Lab and misc are NEVER discounted.
 *   - NSTP subjects are excluded from discount entirely (always full price).
 *   - PATHFIT/PE subjects are excluded from tuition billing entirely.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL requires dropping and re-adding ENUM to extend it.
        // We modify the column to use a string type first, then re-constrain.
        DB::statement("
            ALTER TABLE student_assessments
            MODIFY COLUMN discount_type
                ENUM('none','full','nstp','percentage')
                NOT NULL DEFAULT 'none'
                COMMENT 'Discount policy: none | full (100% waived) | nstp (NSTP waiver) | percentage (partial %)'
        ");

        // Ensure discount_percentage exists (it was added in a previous migration,
        // but we now enforce the range constraint via application logic).
        if (! Schema::hasColumn('student_assessments', 'discount_percentage')) {
            Schema::table('student_assessments', function (Blueprint $table) {
                $table->decimal('discount_percentage', 5, 2)
                    ->default(0.00)
                    ->after('discount_type')
                    ->comment('Percentage discount on tuition (0-100). Only used when discount_type=percentage.');
            });
        }
    }

    public function down(): void
    {
        // Revert any 'percentage' rows to 'none' before shrinking the enum
        DB::statement("UPDATE student_assessments SET discount_type = 'none' WHERE discount_type = 'percentage'");

        DB::statement("
            ALTER TABLE student_assessments
            MODIFY COLUMN discount_type
                ENUM('none','full','nstp')
                NOT NULL DEFAULT 'none'
        ");
    }
};