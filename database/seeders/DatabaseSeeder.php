<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('🚀 Starting comprehensive database seeding...');
        $this->command->newLine();

        // ── Step 1: Users ──────────────────────────────────────────────────────
        $this->command->info('👥 Step 1: Seeding Users (Admin, Accounting, 100 Students)...');
        $this->call(ComprehensiveUserSeeder::class);
        $this->command->newLine();

        // ── Step 2: Subjects ───────────────────────────────────────────────────
        $this->command->info('📚 Step 2: Seeding Subject Curriculum...');
        $this->call(EnhancedSubjectSeeder::class);
        $this->command->newLine();

        // ── Step 3: Fee Settings ───────────────────────────────────────────────
        $this->command->info('💰 Step 3: Seeding Fee Settings (from config/fees.php)...');
        $this->call(FeeSettingsSeeder::class);
        $this->command->newLine();

        // ── Step 4: Workflow Templates ─────────────────────────────────────────
        $this->command->info('⚙️  Step 4: Seeding Workflow Templates...');
        $this->call(DemoWorkflowSeeder::class);
        $this->call(PaymentApprovalWorkflowSeeder::class);
        $this->command->newLine();

        // ── Step 5: Assessments for 100 students ──────────────────────────────
        $this->command->info('📋 Step 5: Creating Student Assessments & Payment Terms...');
        $this->call(ComprehensiveAssessmentSeeder::class);
        $this->command->newLine();

        // ── Step 6: Realistic enrollments and payments ─────────────────────────
        $this->command->info('🎓 Step 6: Seeding Realistic Student Enrollments & Payments...');
        $this->call(RealisticStudentDataSeeder::class);
        $this->command->newLine();

        // ── Step 7: Notifications ──────────────────────────────────────────────
        $this->command->info('🔔 Step 7: Seeding Notifications...');
        $this->call(NotificationSeeder::class);
        $this->command->newLine();

        // ── Step 8: Workflow Instances ─────────────────────────────────────────
        $this->command->info('🔄 Step 8: Creating Sample Workflow Instances...');
        $this->call(WorkflowInstanceSeeder::class);
        $this->command->newLine();

        // ── Step 9: First payment test scenario ───────────────────────────────
        $this->command->info('💳 Step 9: Creating First Payment (Test Scenario)...');
        $this->call(StudentFirstPaymentSeeder::class);
        $this->command->newLine();

        // ── Step 10: 4 named test students with full transaction histories ──────
        $this->command->info('🧪 Step 10: Creating 4 Named Test Students with Transaction Histories...');
        $this->call(AdditionalStudentSeeder::class);
        $this->command->newLine();

        $this->command->info('✅ Database seeding completed successfully!');
        $this->command->newLine();

        $this->displaySummary();
    }

    private function displaySummary(): void
    {
        $this->command->info('📊 SEEDING SUMMARY');
        $this->command->info('═══════════════════════════════════════════════════════');

        $userCount       = \App\Models\User::count();
        $adminCount      = \App\Models\User::where('role', 'admin')->count();
        $accountingCount = \App\Models\User::where('role', 'accounting')->count();
        $studentCount    = \App\Models\User::where('role', 'student')->count();

        $activeStudents    = \App\Models\User::where('role', 'student')->where('status', \App\Models\User::STATUS_ACTIVE)->count();
        $droppedStudents   = \App\Models\User::where('role', 'student')->where('status', \App\Models\User::STATUS_DROPPED)->count();
        $graduatedStudents = \App\Models\User::where('role', 'student')->where('status', \App\Models\User::STATUS_GRADUATED)->count();

        $assessmentCount  = \App\Models\StudentAssessment::count();
        $paymentTermCount = \App\Models\StudentPaymentTerm::count();
        $transactionCount = \App\Models\Transaction::count();

        $workflowCount         = \App\Models\Workflow::count();
        $workflowInstanceCount = \App\Models\WorkflowInstance::count();
        $activeWorkflows       = \App\Models\WorkflowInstance::whereIn('status', ['pending', 'in_progress'])->count();
        $completedWorkflows    = \App\Models\WorkflowInstance::where('status', 'completed')->count();
        $pendingApprovals      = \App\Models\WorkflowApproval::where('status', 'pending')->count();

        $tuitionRate = (float) config('fees.tuition_per_lec_unit', 364.00);
        $labRate     = (float) config('fees.lab.per_unit', 1656.00);
        $entrepFee   = (float) config('fees.lab.entrepreneurship_fee', 600.00);
        $miscFee     = (float) config('fees.misc_fee_fixed', 4700.00);

        $this->command->table(
            ['Category', 'Count'],
            [
                ['Total Users', $userCount],
                ['├─ Admins', $adminCount],
                ['├─ Accounting Staff', $accountingCount],
                ['└─ Students', $studentCount],
                ['', ''],
                ['Student Status', ''],
                ['├─ Active', $activeStudents],
                ['├─ Dropped', $droppedStudents],
                ['└─ Graduated', $graduatedStudents],
                ['', ''],
                ['Academic Data', ''],
                ['├─ Student Assessments', $assessmentCount],
                ['├─ Payment Terms', $paymentTermCount],
                ['└─ Transactions (payments only)', $transactionCount],
                ['', ''],
                ['Workflow System', ''],
                ['├─ Workflow Templates', $workflowCount],
                ['├─ Total Instances', $workflowInstanceCount],
                ['├─ Active', $activeWorkflows],
                ['├─ Completed', $completedWorkflows],
                ['└─ Pending Approvals', $pendingApprovals],
            ]
        );

        $this->command->newLine();
        $this->command->info('💡 FEE FORMULA (config/fees.php)');
        $this->command->info('═══════════════════════════════════════════════════════');
        $this->command->info("  Tuition:  lec_units × ₱{$tuitionRate} per unit");
        $this->command->info("  Lab Fee:  (lab_units × ₱{$labRate}) + ₱{$entrepFee} entrep fee");
        $this->command->info("  Misc Fee: ₱{$miscFee} (fixed per semester)");
        $this->command->info('  ─────────────────────────────────────────────────────');
        $exampleTuition = 18 * $tuitionRate;
        $exampleLab     = (3 * $labRate) + $entrepFee;
        $exampleTotal   = $exampleTuition + $exampleLab + $miscFee;
        $this->command->info("  Example (1st Year, 18 lec + 3 lab, no discount):  ₱" . number_format($exampleTotal, 2));

        $this->command->newLine();
        $this->command->info('🔐 DEFAULT CREDENTIALS');
        $this->command->info('═══════════════════════════════════════════════════════');
        $this->command->table(
            ['Role', 'Email', 'Password'],
            [
                ['Admin',           'admin@ccdi.edu.ph',                              'password'],
                ['Accounting',      'accounting@ccdi.edu.ph',                         'password'],
                ['Students',        'student1@ccdi.edu.ph – student100@ccdi.edu.ph',  'password'],
                ['Test: Maria',     'maria.santos@test.com',                          'password'],
                ['Test: Juan',      'juan.dela.cruz@test.com',                        'password'],
                ['Test: Ana',       'ana.garcia@test.com',                            'password'],
                ['Test: TxHistory', 'transaction.history@ccdi.edu.ph',               'password'],
            ]
        );

        $this->command->newLine();
        $this->command->info('💡 TIPS');
        $this->command->info('═══════════════════════════════════════════════════════');
        $this->command->info('• All assessments use the formula from config/fees.php — no hardcoded totals');
        $this->command->info('• lab_units drives the lab fee count; entrep fee (₱600) auto-applied when lab_units > 0');
        $this->command->info('• No charge Transactions are seeded — charges come only from admin UI');
        $this->command->info('• Payment Transactions exist only for the 4 named test students');
        $this->command->info('• transaction.history@ student has 6 accordion sections (5 paid + 1 current)');
        $this->command->info('• To reset from scratch locally: php artisan migrate:fresh --seed');
        $this->command->info('• To reset in Docker: set FORCE_RESEED=true for one deploy, then remove it');
        $this->command->newLine();
    }
}