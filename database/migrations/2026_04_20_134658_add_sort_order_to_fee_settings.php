<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add sort_order to fee_settings so miscellaneous items can be reordered,
 * and add is_deletable flag so system-required rates cannot be accidentally removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fee_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('fee_settings', 'sort_order')) {
                $table->unsignedSmallInteger('sort_order')
                    ->default(0)
                    ->after('is_active')
                    ->comment('Display order within category');
            }

            if (! Schema::hasColumn('fee_settings', 'is_deletable')) {
                $table->boolean('is_deletable')
                    ->default(true)
                    ->after('sort_order')
                    ->comment('System rates (tuition, lab, terms) cannot be deleted');
            }

            $table->index('sort_order');
        });

        // Mark system-critical rows as non-deletable
        \Illuminate\Support\Facades\DB::table('fee_settings')
            ->whereIn('category', ['rate', 'term'])
            ->update(['is_deletable' => false]);
    }

    public function down(): void
    {
        Schema::table('fee_settings', function (Blueprint $table) {
            $table->dropColumn(['sort_order', 'is_deletable']);
        });
    }
};