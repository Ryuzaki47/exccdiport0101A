<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->onDelete('cascade');
            $table->foreignId('transaction_id')->nullable()->constrained()->onDelete('set null');
            $table->string('paymongo_payment_intent_id')->nullable();
            $table->string('paymongo_checkout_session_id')->nullable();
            $table->string('payment_method')->nullable(); // gcash, maya, card
            $table->decimal('amount', 10, 2);
            $table->string('currency')->default('PHP');
            $table->string('status')->default('pending'); // pending, paid, failed
            $table->string('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};