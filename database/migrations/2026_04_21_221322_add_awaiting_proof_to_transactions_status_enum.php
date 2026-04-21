<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE transactions MODIFY COLUMN status " .
                "ENUM('pending','paid','failed','cancelled','awaiting_approval','awaiting_proof') " .
                "DEFAULT 'pending'"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("UPDATE transactions SET status = 'pending' WHERE status = 'awaiting_proof'");
            DB::statement(
                "ALTER TABLE transactions MODIFY COLUMN status " .
                "ENUM('pending','paid','failed','cancelled','awaiting_approval') " .
                "DEFAULT 'pending'"
            );
        }
    }
};