<?php

namespace App\Services;

use App\Enums\UserRoleEnum;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowApproval;
use App\Models\WorkflowInstance;
use App\Events\WorkflowStepAdvanced;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WorkflowService
{
    /**
     * Start a workflow instance for the given entity.
     *
     * ✅ FIX: Notifications are sent AFTER the transaction commits, so if the mail
     * server is down, the workflow is already safely stored in the database.
     */
    public function startWorkflow(Workflow $workflow, Model $entity, int $userId): WorkflowInstance
    {
        $instance = DB::transaction(function () use ($workflow, $entity, $userId) {
            $firstStep = $workflow->steps[0] ?? null;

            if (! $firstStep) {
                throw new \Exception('Workflow has no steps defined');
            }

            // ── Build metadata from the entity for use in Approvals listing ──
            $metadata = [];
            if ($entity instanceof Transaction) {
                $metadata = [
                    'transaction_id'  => $entity->id,
                    'amount'          => (float) $entity->amount,
                    'payment_method'  => $entity->payment_channel,
                    'term_name'       => $entity->meta['term_name'] ?? $entity->type,
                    'year'            => $entity->year,
                    'semester'        => $entity->semester,
                    'student_user_id' => $entity->user_id,
                    'submitted_at'    => now()->toIso8601String(),
                ];
            }

            $instance = WorkflowInstance::create([
                'workflow_id'       => $workflow->id,
                'workflowable_type' => get_class($entity),
                'workflowable_id'   => $entity->id,
                'current_step'      => $firstStep['name'],
                'status'            => 'in_progress',
                'step_history'      => [],
                'metadata'          => $metadata,
                'initiated_by'      => $userId,
            ]);

            $instance->addStepToHistory($firstStep['name'], [
                'action'  => 'started',
                'user_id' => $userId,
            ]);

            if (! ($firstStep['requires_approval'] ?? false)) {
                $this->advanceWorkflow($instance, $userId);
            } else {
                $this->createApprovalRequest($instance, $firstStep);
            }

            return $instance->fresh();
        });

        $instance->refresh();
        try {
            $this->notifyApproversForStep($instance, $workflow->steps[0]);
        } catch (\Exception $e) {
            Log::warning('Failed to send approval notifications', [
                'workflow_instance_id' => $instance->id,
                'error'                => $e->getMessage(),
            ]);
        }

        return $instance;
    }

    public function advanceWorkflow(WorkflowInstance $instance, int $userId): void
    {
        $nextStepData = DB::transaction(function () use ($instance, $userId) {
            $workflow         = $instance->workflow;
            $currentStepIndex = $this->getStepIndex($workflow, $instance->current_step);
            $previousStep     = $instance->current_step;

            if ($currentStepIndex === null) {
                throw new \Exception('Current step not found in workflow');
            }

            $nextStepIndex = $currentStepIndex + 1;

            if ($nextStepIndex >= count($workflow->steps)) {
                $instance->update([
                    'status'       => 'completed',
                    'completed_at' => now(),
                ]);

                $instance->addStepToHistory('completed', [
                    'action'  => 'completed',
                    'user_id' => $userId,
                ]);

                Log::info('Workflow advanced to completed', [
                    'workflow_instance_id' => $instance->id,
                    'final_step'           => $previousStep,
                ]);

                return null;
            }

            $nextStep = $workflow->steps[$nextStepIndex];

            $instance->update([
                'current_step' => $nextStep['name'],
            ]);

            $instance->addStepToHistory($nextStep['name'], [
                'action'  => 'advanced',
                'user_id' => $userId,
            ]);

            if ($nextStep['requires_approval'] ?? false) {
                $this->createApprovalRequest($instance, $nextStep);
            }

            // Dispatch event after successful advancement
            WorkflowStepAdvanced::dispatch($instance, $previousStep, $nextStep['name']);

            // Return the next step if approvals are needed (for notification after transaction)
            return ($nextStep['requires_approval'] ?? false) ? $nextStep : null;
        });

        // Send notifications AFTER the transaction is committed
        if ($nextStepData !== null) {
            $instance->refresh();
            try {
                $this->notifyApproversForStep($instance, $nextStepData);
            } catch (\Exception $e) {
                Log::warning('Failed to send approval notifications after step advance', [
                    'workflow_instance_id' => $instance->id,
                    'error'                => $e->getMessage(),
                ]);
                // Don't re-throw; approvals are already in the database
            }
        } else {
            // If the step we just advanced to doesn't require approval,
            // continue advancing until we hit an approval-required step or complete.
            $instance->refresh();
            if (! $instance->isCompleted()) {
                $this->advanceWorkflow($instance, $userId);
            }
        }
    }

    public function approveStep(WorkflowApproval $approval, int $userId, ?string $comments = null): void
    {
        // Step 1: approve the record and advance the workflow inside a transaction.
        // onWorkflowCompleted() is intentionally called OUTSIDE this transaction
        // so that a finalization failure does NOT roll back the approval record.
        // This means: approval is always committed, and finalization is retryable.
        $instance = DB::transaction(function () use ($approval, $userId, $comments) {
            $approval->approve($comments);

            // Check if all approvals for this step are now approved
            $pendingApprovals = WorkflowApproval::where('workflow_instance_id', $approval->workflow_instance_id)
                ->where('step_name', $approval->step_name)
                ->where('status', 'pending')
                ->count();

            if ($pendingApprovals === 0) {
                $this->advanceWorkflow($approval->workflowInstance, $userId);
            }

            return $approval->workflowInstance->fresh();
        });

        // Step 2: if the workflow reached 'completed', finalize the payment OUTSIDE
        // the approval transaction. If finalization fails, the approval stays committed
        // (accounting doesn't have to re-approve) and the error is surfaced cleanly.
        if ($instance->isCompleted()) {
            $this->onWorkflowCompleted($instance);
        }
    }

    public function rejectStep(WorkflowApproval $approval, int $userId, string $comments): void
    {
        DB::transaction(function () use ($approval, $userId, $comments) {
            $approval->reject($comments);

            $instance = $approval->workflowInstance;
            $instance->update([
                'status' => 'rejected',
            ]);

            $instance->addStepToHistory($approval->step_name, [
                'action'   => 'rejected',
                'user_id'  => $userId,
                'comments' => $comments,
            ]);

            // Handle workflow-specific rejection logic
            $this->onWorkflowRejected($instance, $comments);
        });
    }

    protected function createApprovalRequest(WorkflowInstance $instance, array $step): void
    {
        $approverIds = $step['approvers'] ?? [];

        // Support dynamic role-based approvers via explicit step config
        if (isset($step['approver_role'])) {
            // Use the appropriate scope based on the role name to properly handle Enum casting
            $roleApprovers = match ($step['approver_role']) {
                'accounting' => User::accounting()->where('is_active', true)->pluck('id')->toArray(),
                'admin' => User::admins()->where('is_active', true)->pluck('id')->toArray(),
                default => [],
            };
            $approverIds = array_unique(array_merge($approverIds, $roleApprovers));
        }

        // Fallback 1: assign to all active accounting users
        if (empty($approverIds)) {
            $approverIds = User::accounting()
                ->where('is_active', true)
                ->pluck('id')
                ->toArray();
        }

        // Fallback 2: assign to active admin users if no accounting users exist
        if (empty($approverIds)) {
            $approverIds = User::admins()
                ->where('is_active', true)
                ->pluck('id')
                ->toArray();
        }

        // Last resort: fail hard if no approvers can be found at all
        if (empty($approverIds)) {
            throw new \Exception(
                'No approvers found for workflow step "' . $step['name'] . '". ' .
                'Ensure at least one active accounting or admin user exists in the system.'
            );
        }

        // ✅ FIX: Create only ONE approval record per step (for the first available approver).
        // All accounting/admin users can already view ALL payment_approval workflows
        // per WorkflowApprovalController::index(), regardless of who the approver is.
        // Creating one record per approver caused duplicate payment entries in the approvals list.
        // This now correctly shows "1 payment = 1 approx approval record" instead of "1 payment = N records".
        $primaryApproverId = reset($approverIds);

        WorkflowApproval::create([
            'workflow_instance_id' => $instance->id,
            'step_name'            => $step['name'],
            'approver_id'          => $primaryApproverId,
            'status'               => 'pending',
        ]);
        // NOTE: Approval notifications are sent AFTER the transaction commits
        // by the notifyApproversForStep() method. This ensures that even if
        // the mail server is unavailable, the approval records exist in the database.
    }

    /**
     * Send notifications to approvers for a specific workflow step.
     * Called AFTER the database transaction completes to prevent rollback on notification errors.
     */
    protected function notifyApproversForStep(WorkflowInstance $instance, array $step): void
    {
        $pendingApprovals = WorkflowApproval::where('workflow_instance_id', $instance->id)
            ->where('step_name', $step['name'])
            ->where('status', 'pending')
            ->get();

        foreach ($pendingApprovals as $approval) {
            $approver = User::find($approval->approver_id);
            if ($approver && ! app()->environment('testing')) {
                try {
                    // NOTIFICATION: LARAVEL DATABASE CHANNEL
                    // ApprovalRequired is a transactional notification tied to a specific user.
                    // Uses: $user->notify() → writes to `notifications` table via database channel
                    // See: docs/NOTIFICATION_ARCHITECTURE.md for system overview
                    $approver->notify(new \App\Notifications\ApprovalRequired($approval));
                } catch (\Exception $e) {
                    Log::warning('Failed to send approval notification', [
                        'approval_id' => $approval->id,
                        'approver_id' => $approver->id,
                        'error'       => $e->getMessage(),
                    ]);
                    // Continue sending to other approvers even if one fails
                }
            }
        }
    }

    protected function getStepIndex(Workflow $workflow, string $stepName): ?int
    {
        foreach ($workflow->steps as $index => $step) {
            if ($step['name'] === $stepName) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Called when workflow reaches 'completed' status.
     * Finalizes payment if this was a payment_approval workflow.
     */
    protected function onWorkflowCompleted(WorkflowInstance $instance): void
    {
        Log::info('WorkflowService::onWorkflowCompleted called', [
            'workflow_instance_id' => $instance->id,
            'workflow_type'        => $instance->workflow->type,
        ]);

        if ($instance->workflow->type !== 'payment_approval') {
            Log::info('Not a payment_approval workflow, skipping');
            return;
        }

        try {
            $transaction = $instance->workflowable;
            Log::info('Workflowable retrieved', [
                'transaction_id'    => $transaction?->id,
                'transaction_class' => get_class($transaction),
            ]);

            if ($transaction instanceof Transaction) {
                Log::info('Finalizing approved payment', [
                    'transaction_id' => $transaction->id,
                    'amount'         => $transaction->amount,
                    'current_status' => $transaction->status,
                ]);

                app(StudentPaymentService::class)->finalizeApprovedPayment($transaction);

                $transaction->refresh();
                Log::info('Payment finalized', [
                    'transaction_id' => $transaction->id,
                    'new_status'     => $transaction->status,
                ]);
                
                event(new \App\Events\PaymentRecorded(
                    $transaction->user,
                    $transaction->id,
                    (float) $transaction->amount,
                    $transaction->reference,
                ));

                // NOTIFICATION: CUSTOM ADMIN_NOTIFICATIONS
                // Payment approval is a system event that needs role-based targeting.
                // Uses: Notification::create() → writes to `admin_notifications` table
                // Why: Support user_id + target_role combo; provides audit trail
                // See: docs/NOTIFICATION_ARCHITECTURE.md for system overview
                $student = $transaction->user;
                \App\Models\Notification::create([
                    'title'       => 'Payment Approved',
                    'message'     => 'Your payment of ₱' . number_format($transaction->amount, 2) .
                                     " (Ref: {$transaction->reference}) has been verified by accounting.",
                    'target_role' => 'student',
                    'user_id'     => $student->id,
                    'is_active'   => true,
                    'start_date'  => now()->toDateString(),
                    'end_date'    => now()->addDays(7)->toDateString(),
                ]);

                Log::info('Notification created for student', ['user_id' => $student->id]);
            } else {
                Log::warning('Workflowable is not a Transaction', [
                    'workflowable_class' => get_class($transaction),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Error in onWorkflowCompleted', [
                'workflow_instance_id' => $instance->id,
                'error'                => $e->getMessage(),
                'trace'                => $e->getTraceAsString(),
            ]);
            // Re-throw with a user-readable prefix so WorkflowApprovalController
            // can surface a meaningful error instead of a generic 500 page.
            // The outer DB::transaction in approveStep() will roll back the
            // approval record, keeping the system in a consistent state.
            throw new \Exception(
                'Payment approved but finalization failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Called when workflow is rejected.
     * Cancels payment and notifies student.
     */
    protected function onWorkflowRejected(WorkflowInstance $instance, string $reason): void
    {
        Log::info('WorkflowService::onWorkflowRejected called', [
            'workflow_instance_id' => $instance->id,
            'workflow_type'        => $instance->workflow->type,
            'reason'               => $reason,
        ]);

        if ($instance->workflow->type !== 'payment_approval') {
            Log::info('Not a payment_approval workflow, skipping');
            return;
        }

        try {
            $transaction = $instance->workflowable;
            Log::info('Workflowable retrieved for rejection', [
                'transaction_id'    => $transaction?->id,
                'transaction_class' => get_class($transaction),
            ]);

            if ($transaction instanceof Transaction) {
                Log::info('Cancelling rejected payment', [
                    'transaction_id' => $transaction->id,
                    'amount'         => $transaction->amount,
                ]);

                app(StudentPaymentService::class)->cancelRejectedPayment($transaction);

                $transaction->refresh();
                Log::info('Payment cancelled', [
                    'transaction_id' => $transaction->id,
                    'new_status'     => $transaction->status,
                ]);

                // NOTIFICATION: CUSTOM ADMIN_NOTIFICATIONS
                // Payment rejection is a system event that needs role-based targeting.
                // Uses: Notification::create() → writes to `admin_notifications` table
                // Why: Support user_id + target_role combo + reason context
                // See: docs/NOTIFICATION_ARCHITECTURE.md for system overview
                $student = $transaction->user;
                \App\Models\Notification::create([
                    'title'       => 'Payment Rejected',
                    'message'     => 'Your payment of ₱' . number_format($transaction->amount, 2) .
                                     " (Ref: {$transaction->reference}) was not verified. Reason: {$reason}",
                    'target_role' => 'student',
                    'user_id'     => $student->id,
                    'is_active'   => true,
                    'start_date'  => now()->toDateString(),
                    'end_date'    => now()->addDays(14)->toDateString(),
                ]);

                Log::info('Rejection notification created for student', ['user_id' => $student->id]);
            } else {
                Log::warning('Workflowable is not a Transaction for rejection', [
                    'workflowable_class' => get_class($transaction),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Error in onWorkflowRejected', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}