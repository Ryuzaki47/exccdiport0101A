<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('paymongo_source_id')->nullable();
            $table->string('paymongo_payment_id')->nullable();
            $table->string('proof_of_payment')->nullable();
            $table->text('notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn([
                'paymongo_source_id',
                'paymongo_payment_id',
                'proof_of_payment',
                'notes',
            ]);
        });
    }
};