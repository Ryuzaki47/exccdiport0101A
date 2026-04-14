<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * StudentTransactionHistorySeeder — DEPRECATED
 *
 * This seeder has been merged into AdditionalStudentSeeder (student 4/4).
 * The "transaction.history@ccdi.edu.ph" student is now seeded there with:
 *   - Correct fee formula (lec_units × ₱364 + lab_subjects × ₱1,656 + ₱4,700)
 *   - Proper lab_subjects field populated (was always 0 here — bug fixed)
 *   - Consistent payment Transaction records per term
 *
 * Running this seeder now delegates to AdditionalStudentSeeder.
 * You can safely remove this file once DatabaseSeeder is updated.
 *
 * USAGE (preferred):
 *   php artisan db:seed --class=AdditionalStudentSeeder
 *
 * USAGE (legacy, still works):
 *   php artisan db:seed --class=StudentTransactionHistorySeeder
 */
class StudentTransactionHistorySeeder extends Seeder
{
    public function run(): void
    {
        $this->command->warn(
            '⚠  StudentTransactionHistorySeeder is deprecated. ' .
            'Delegating to AdditionalStudentSeeder (student 4/4).'
        );

        $this->call(AdditionalStudentSeeder::class);
    }
}