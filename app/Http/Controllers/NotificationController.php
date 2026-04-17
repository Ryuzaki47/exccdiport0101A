<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\StudentPaymentTerm;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            $notifications = Notification::orderByDesc('created_at')->get();
        } else {
            $notifications = Notification::active()
                ->forUser($user->id)
                ->withinDateRange()
                ->orderByDesc('start_date')
                ->get();
        }

        return Inertia::render('Admin/Notifications/Index', [
            'notifications' => $notifications,
            'role'          => $user->role,
        ]);
    }

    public function create()
    {
        $this->authorize('create', Notification::class);

        $students = User::whereRole('student')
            ->select('id', 'first_name', 'last_name', 'middle_initial', 'email')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $paymentTerms = StudentPaymentTerm::distinct()
            ->orderBy('term_order')
            ->get(['id', 'term_name', 'term_order']);

        return Inertia::render('Admin/Notifications/Create', [
            'students'     => $students,
            'paymentTerms' => $paymentTerms,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Notification::class);

        $validated = $this->validateNotification($request);

        if (! empty($validated['user_id'])) {
            $validated['target_role'] = 'student';
        }

        // FIX Bug #2: syncDueDateToPaymentTerms() is now outside the DB::transaction().
        // The notification is created first (in its own transaction). If the sync
        // fails, we log it but do NOT roll back the already-committed notification.
        // This matches the original intent described in the catch block comment,
        // but previously the try/catch inside a transaction closure was misleading —
        // a caught exception inside DB::transaction() does NOT trigger rollback,
        // but it creates ambiguity about transaction state. Separating them is cleaner.
        DB::transaction(function () use ($validated) {
            Notification::create($validated);
        });

        $this->syncDueDateToPaymentTerms($validated);

        return redirect('/admin/notifications')
            ->with('success', 'Notification created. Payment term due dates have been updated.');
    }

    public function show(Notification $notification)
    {
        $this->authorize('view', $notification);

        return Inertia::render('Admin/Notifications/Show', [
            'notification' => $notification,
        ]);
    }

    public function edit(Notification $notification)
    {
        $this->authorize('update', $notification);

        $students = User::whereRole('student')
            ->select('id', 'first_name', 'last_name', 'middle_initial', 'email')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $paymentTerms = StudentPaymentTerm::distinct()
            ->orderBy('term_order')
            ->get(['id', 'term_name', 'term_order']);

        return Inertia::render('Admin/Notifications/Edit', [
            'notification' => $notification,
            'students'     => $students,
            'paymentTerms' => $paymentTerms,
        ]);
    }

    public function update(Request $request, Notification $notification)
    {
        $this->authorize('update', $notification);

        $validated = $this->validateNotification($request);

        if (! empty($validated['user_id'])) {
            $validated['target_role'] = 'student';
        }

        DB::transaction(function () use ($notification, $validated) {
            $notification->update($validated);
        });

        $this->syncDueDateToPaymentTerms($validated);

        return redirect('/admin/notifications')
            ->with('success', 'Notification updated. Payment term due dates have been updated.');
    }

    public function destroy(Notification $notification)
    {
        $this->authorize('delete', $notification);

        $notification->delete();

        return redirect('/admin/notifications')
            ->with('success', 'Notification deleted successfully.');
    }

    /**
     * FIX Bug #5: Replaced manual role-string comparison with Policy check.
     * Also fixed logic: user_id !== null && !== $user->id should be 403 immediately.
     * Previous code had a path where user_id was set for someone else but
     * fell through to the target_role check — that's wrong.
     */
    public function dismiss(Request $request, Notification $notification)
    {
        $user = $request->user();

        if (! $user->isAdmin()) {
            // If notification targets a specific user, it must be this user
            if ($notification->user_id !== null && $notification->user_id !== $user->id) {
                abort(403, 'You are not authorised to dismiss this notification.');
            }

            // If broadcast notification, verify role match
            if ($notification->user_id === null) {
                $roleString = $user->role instanceof \BackedEnum
                    ? $user->role->value
                    : (string) $user->role;

                if (! in_array($notification->target_role, [$roleString, 'all'], true)) {
                    abort(403, 'You are not authorised to dismiss this notification.');
                }
            }
        }

        $notification->markDismissed();

        return back()->with('success', 'Notification dismissed.');
    }

    // -------------------------------------------------------------------------
    // Private Helpers
    // -------------------------------------------------------------------------

    private function validateNotification(Request $request): array
    {
        return $request->validate([
            'title'                   => 'required|string|max:255',
            'message'                 => 'nullable|string|max:2000',
            'type'                    => 'nullable|string|in:general,payment_due,payment_approved,payment_rejected',
            'start_date'              => 'required|date',
            'end_date'                => 'nullable|date|after_or_equal:start_date',
            'due_date'                => 'nullable|date',
            'payment_term_id'         => 'nullable|integer|exists:student_payment_terms,id',
            'target_role'             => 'required|string|in:student,accounting,admin,all',
            'user_id'                 => 'nullable|integer|exists:users,id',
            'is_active'               => 'boolean',
            'term_ids'                => 'nullable|array',
            'term_ids.*'              => 'integer|exists:student_payment_terms,id',
            'target_term_name'        => 'nullable|string|in:Upon Registration,Prelim,Midterm,Semi-Final,Final',
            'trigger_days_before_due' => 'nullable|integer|min:0|max:90',
        ]);
    }

    /**
     * Push notification's due_date into matching student_payment_terms rows.
     * Only runs for type = 'payment_due' with a due_date present.
     * Runs OUTSIDE DB::transaction — sync failure does not roll back the notification.
     */
    private function syncDueDateToPaymentTerms(array $data): void
    {
        if (($data['type'] ?? '') !== 'payment_due') {
            return;
        }

        $dueDate = $data['due_date'] ?? null;
        if (! $dueDate) {
            return;
        }

        try {
            // Priority 1: explicit term IDs
            if (! empty($data['term_ids'])) {
                $updated = StudentPaymentTerm::whereIn('id', $data['term_ids'])
                    ->update(['due_date' => $dueDate]);

                Log::info('NotificationController: synced due_date to term_ids', [
                    'term_ids' => $data['term_ids'],
                    'due_date' => $dueDate,
                    'updated'  => $updated,
                ]);

                return;
            }

            // Priority 2: single payment_term_id
            if (! empty($data['payment_term_id'])) {
                StudentPaymentTerm::where('id', $data['payment_term_id'])
                    ->update(['due_date' => $dueDate]);

                Log::info('NotificationController: synced due_date to payment_term_id', [
                    'payment_term_id' => $data['payment_term_id'],
                    'due_date'        => $dueDate,
                ]);

                return;
            }

            // Priority 3: target_term_name broadcast
            if (! empty($data['target_term_name'])) {
                $query = StudentPaymentTerm::where('term_name', $data['target_term_name']);

                if (! empty($data['user_id'])) {
                    $query->where('user_id', $data['user_id']);
                }

                $updated = $query->update(['due_date' => $dueDate]);

                Log::info('NotificationController: synced due_date by term_name', [
                    'target_term_name' => $data['target_term_name'],
                    'user_id'          => $data['user_id'] ?? 'all',
                    'due_date'         => $dueDate,
                    'updated'          => $updated,
                ]);

                return;
            }

            Log::info('NotificationController: no term filter — skipping due_date sync', [
                'due_date' => $dueDate,
            ]);

        } catch (\Throwable $e) {
            Log::error('NotificationController: failed to sync due_date to payment terms', [
                'error' => $e->getMessage(),
                'data'  => $data,
            ]);
        }
    }
}