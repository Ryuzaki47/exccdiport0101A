<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Add user_id if not exists
            if (!Schema::hasColumn('payments', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->after('id');
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            }

            // Add meta JSON field  
            if (!Schema::hasColumn('payments', 'meta')) {
                $table->json('meta')->nullable()->after('description');
            }

            // Add paymongo_intent_id if not exists
            if (!Schema::hasColumn('payments', 'paymongo_intent_id')) {
                $table->string('paymongo_intent_id')->nullable()->after('paymongo_payment_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'paymongo_intent_id')) {
                $table->dropColumn('paymongo_intent_id');
            }
            if (Schema::hasColumn('payments', 'meta')) {
                $table->dropColumn('meta');
            }
            if (Schema::hasColumn('payments', 'user_id')) {
                $table->dropForeignIdFor(\App\Models\User::class);
                $table->dropColumn('user_id');
            }
        });
    }
};
