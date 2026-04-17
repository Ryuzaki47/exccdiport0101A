<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_assessments', function (Blueprint $table) {
            if (!Schema::hasColumn('student_assessments', 'discount_type')) {
                $table->string('discount_type')->default('none')->after('status');
            }
            if (!Schema::hasColumn('student_assessments', 'is_taking_nstp')) {
                $table->boolean('is_taking_nstp')->default(false)->after('discount_type');
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
        });
    }
};