<?php

namespace App\Console\Commands;

use App\Models\PaymentReminder;
use App\Models\StudentPaymentTerm;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class CheckOverduePayments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payments:check-overdue';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check for overdue payment terms and generate reminders for students';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Checking for overdue payments...');
        
        // Get all overdue payment terms
        $overdueTerms = StudentPaymentTerm::where('status', '!=', StudentPaymentTerm::STATUS_PAID)
            ->where('balance', '>', 0)
            ->where('due_date', '<', now()->toDateString())
            ->with('assessment.user')
            ->get();
        
        $this->info("Found " . $overdueTerms->count() . " overdue payment terms");
        
        $remindersCreated = 0;
        
        foreach ($overdueTerms as $term) {
            // Check if reminder already exists for this term
            $existingReminder = PaymentReminder::where('student_payment_term_id', $term->id)
                ->where('type', PaymentReminder::TYPE_OVERDUE)
                ->where('status', '!=', PaymentReminder::STATUS_DISMISSED)
                ->latest('created_at')
                ->first();
            
            // Only create reminder if none exists or if the last one is older than 7 days
            if (!$existingReminder || $existingReminder->created_at->diffInDays(now()) >= 7) {
                $daysOverdue = now()->diffInDays($term->due_date);
                
                $reminder = PaymentReminder::create([
                    'user_id' => $term->assessment->user_id,
                    'student_assessment_id' => $term->student_assessment_id,
                    'student_payment_term_id' => $term->id,
                    'type' => PaymentReminder::TYPE_OVERDUE,
                    'message' => "{$term->term_name} is {$daysOverdue} day(s) overdue. Outstanding balance: ₱" . number_format($term->balance, 2) . ". Please settle your account immediately.",
                    'outstanding_balance' => $term->balance,
                    'status' => PaymentReminder::STATUS_SENT,
                    'in_app_sent' => true,
                    'sent_at' => now(),
                    'trigger_reason' => PaymentReminder::TRIGGER_SCHEDULED_JOB,
                    'triggered_by' => null,
                    'metadata' => [
                        'days_overdue' => $daysOverdue,
                        'due_date' => $term->due_date,
                        'term_order' => $term->term_order,
                    ],
                ]);
                
                $term->assessment->user->notify(new \App\Notifications\PaymentDueNotification(
                    $term->term_name,
                    (float) $term->balance,
                    $term->due_date,
                ));
                
                $remindersCreated++;
                $this->info("Created overdue reminder for user {$term->user_id} (Term: {$term->term_name})");
            }
        }
        
        $this->info("✓ Overdue payment check complete. Created {$remindersCreated} reminders");
        
        // Also check for approaching due dates (3 days before)
        $this->generateApproachingDueReminders();
        
        return self::SUCCESS;
    }
    
    /**
     * Generate reminders for payments approaching their due date
     */
    private function generateApproachingDueReminders(): void
    {
        $this->info('Checking for approaching due dates...');
        
        $approachingTerms = StudentPaymentTerm::where('status', '!=', StudentPaymentTerm::STATUS_PAID)
            ->where('balance', '>', 0)
            ->whereBetween('due_date', [
                now()->toDateString(),
                now()->addDays(3)->toDateString(),
            ])
            ->with('assessment.user')
            ->get();
        
        $this->info("Found " . $approachingTerms->count() . " terms with approaching due dates");
        
        $remindersCreated = 0;
        
        foreach ($approachingTerms as $term) {
            // Check if reminder already exists for this term
            $existingReminder = PaymentReminder::where('student_payment_term_id', $term->id)
                ->where('type', PaymentReminder::TYPE_APPROACHING_DUE)
                ->where('status', '!=', PaymentReminder::STATUS_DISMISSED)
                ->where('created_at', '>', now()->subDay())
                ->first();
            
            // Only create if no reminder was sent today
            if (!$existingReminder) {
                $daysUntilDue = now()->diffInDays($term->due_date);
                
                PaymentReminder::create([
                    'user_id' => $term->assessment->user_id,
                    'student_assessment_id' => $term->student_assessment_id,
                    'student_payment_term_id' => $term->id,
                    'type' => PaymentReminder::TYPE_APPROACHING_DUE,
                    'message' => "{$term->term_name} payment is due in {$daysUntilDue} day(s). Amount due: ₱" . number_format($term->balance, 2),
                    'outstanding_balance' => $term->balance,
                    'status' => PaymentReminder::STATUS_SENT,
                    'in_app_sent' => true,
                    'sent_at' => now(),
                    'trigger_reason' => PaymentReminder::TRIGGER_SCHEDULED_JOB,
                    'triggered_by' => null,
                    'metadata' => [
                        'days_until_due' => $daysUntilDue,
                        'due_date' => $term->due_date,
                        'term_order' => $term->term_order,
                    ],
                ]);
                
                $term->assessment->user->notify(new \App\Notifications\PaymentDueNotification(
                    $term->term_name,
                    (float) $term->balance,
                    $term->due_date,
                ));
                
                $remindersCreated++;
                $this->info("Created approaching due reminder for user {$term->user_id} (Term: {$term->term_name})");
            }
        }
        
        $this->info("✓ Approaching due date check complete. Created {$remindersCreated} reminders");
    }
}
