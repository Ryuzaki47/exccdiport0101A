<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FeeSettingsSeeder extends Seeder
{
    /**
     * Seed the fee_settings table
     */
    public function run(): void
    {
        DB::table('fee_settings')->truncate();

        $now = now();

        $settings = [
            // ── Rates ────────────────────────────────────────────────────────
            ['key' => 'tuition_per_unit', 'label' => 'Tuition per Unit', 'amount' => 364.00, 'category' => 'rate'],
            ['key' => 'lab_fee_per_subject', 'label' => 'Lab Fee per Subject', 'amount' => 1656.00, 'category' => 'rate'],

            // ── Miscellaneous ────────────────────────────────────────────────
            ['key' => 'misc_registration', 'label' => 'Registration Fee', 'amount' => 600.00, 'category' => 'miscellaneous'],
            ['key' => 'misc_lms', 'label' => 'LMS Fee', 'amount' => 450.00, 'category' => 'miscellaneous'],
            ['key' => 'misc_library', 'label' => 'Library Fee', 'amount' => 450.00, 'category' => 'miscellaneous'],
            ['key' => 'misc_athletic', 'label' => 'Athletic Fee', 'amount' => 550.00, 'category' => 'miscellaneous'],
            ['key' => 'misc_prisaa', 'label' => 'PRISAA Fee', 'amount' => 300.00, 'category' => 'miscellaneous'],
            ['key' => 'misc_publication', 'label' => 'Publication Fee', 'amount' => 200.00, 'category' => 'miscellaneous'],
            ['key' => 'misc_av', 'label' => 'Audio-Visual Fee', 'amount' => 250.00, 'category' => 'miscellaneous'],
            ['key' => 'misc_id', 'label' => 'ID Fee', 'amount' => 300.00, 'category' => 'miscellaneous'],
            ['key' => 'misc_biccs', 'label' => 'BICCS/PCCL/League Fee', 'amount' => 150.00, 'category' => 'miscellaneous'],
            ['key' => 'misc_faculty', 'label' => 'Faculty Development', 'amount' => 250.00, 'category' => 'miscellaneous'],
            ['key' => 'misc_guidance', 'label' => 'Guidance Services', 'amount' => 225.00, 'category' => 'miscellaneous'],

            // ── Other ────────────────────────────────────────────────────────
            ['key' => 'misc_medical', 'label' => 'Medical Fee', 'amount' => 300.00, 'category' => 'other'],
            ['key' => 'misc_insurance', 'label' => 'Insurance Fee', 'amount' => 100.00, 'category' => 'other'],
            ['key' => 'misc_cultural', 'label' => 'Cultural Arts Fee', 'amount' => 175.00, 'category' => 'other'],
            ['key' => 'misc_maintenance', 'label' => 'Maintenance Fee', 'amount' => 400.00, 'category' => 'other'],

            // ── Payment term percentages ──────────────────────────────────────
            ['key' => 'term_1_pct', 'label' => 'Upon Registration', 'amount' => 30.00, 'category' => 'term'],
            ['key' => 'term_2_pct', 'label' => 'Prelim', 'amount' => 21.00, 'category' => 'term'],
            ['key' => 'term_3_pct', 'label' => 'Midterm', 'amount' => 21.00, 'category' => 'term'],
            ['key' => 'term_4_pct', 'label' => 'Semi-Final', 'amount' => 18.00, 'category' => 'term'],
            ['key' => 'term_5_pct', 'label' => 'Final', 'amount' => 10.00, 'category' => 'term'],
        ];

        foreach ($settings as $s) {
            DB::table('fee_settings')->insert(array_merge($s, [
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }

        $miscTotal = collect($settings)
            ->whereIn('category', ['miscellaneous', 'other'])
            ->sum('amount');

        $this->command->info('✅ fee_settings seeded.');
        $this->command->info('   Tuition/unit : ₱364.00');
        $this->command->info('   Lab/subject  : ₱1,656.00');
        $this->command->info("   Misc total   : ₱" . number_format($miscTotal, 2));
    }
}
