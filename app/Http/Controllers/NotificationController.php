<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\StudentPaymentTerm;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            // Admin sees ALL notifications for management — no active/date filtering.
            // Dates are mapped to plain "YYYY-MM-DD" strings so the frontend
            // isActive() and date display helpers don't suffer UTC timezone drift.
            $notifications = Notification::orderByDesc('created_at')
                ->get()
                ->map(fn ($n) => [
                    'id'                      => $n->id,
                    'title'                   => $n->title,
                    'message'                 => $n->message,
                    'type'                    => $n->type,
                    'target_role'             => $n->target_role,
                    'start_date'              => $n->start_date?->toDateString(),
                    'end_date'                => $n->end_date?->toDateString(),
                    'due_date'                => $n->due_date?->toDateString(),
                    'payment_term_id'         => $n->payment_term_id,
                    'is_active'               => $n->is_active,
                    'is_complete'             => $n->is_complete,
                    'target_term_name'        => $n->target_term_name,
                    'term_ids'                => $n->term_ids,
                    'trigger_days_before_due' => $n->trigger_days_before_due,
                    'user_id'                 => $n->user_id,
                    'dismissed_at'            => $n->dismissed_at?->toDateTimeString(),
                    'created_at'              => $n->created_at->toDateString(),
                    'updated_at'              => $n->updated_at->toDateString(),
                ]);

            return Inertia::render('Admin/Notifications/Index', [
                'notifications' => $notifications,
                'role'          => $user->role,
            ]);
        }

        // Non-admin users (students, accounting) land on the student-facing page
        return $this->studentIndex($request);
    }

    /**
     * Student-facing notifications page.
     *
     * Scoped to the authenticated user: active + within date range + due-date trigger.
     * Dates are mapped to plain "YYYY-MM-DD" strings — never raw Carbon ISO datetimes —
     * so the frontend can display and compare them without timezone drift.
     *
     * Also marks all matching notifications as read so the bell badge resets.
     */
    public function studentIndex(Request $request): \Inertia\Response
    {
        $user = $request->user();

        $baseQuery = fn () => Notification::active()
            ->forUser($user->id)
            ->withinDateRange()
            ->forDueDateTrigger($user);

        $notifications = $baseQuery()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($n) => [
                'id'              => $n->id,
                'title'           => $n->title,
                'message'         => $n->message,
                'type'            => $n->type,
                'start_date'      => $n->start_date?->toDateString(),
                'end_date'        => $n->end_date?->toDateString(),
                'due_date'        => $n->due_date?->toDateString(),
                'payment_term_id' => $n->payment_term_id,
                'target_role'     => $n->target_role,
                'is_active'       => $n->is_active,
                'is_complete'     => $n->is_complete,
                'dismissed_at'    => $n->dismissed_at?->toDateTimeString(),
                'created_at'      => $n->created_at->toDateTimeString(),
            ]);

        // Mark all visible notifications as read when the student opens this page.
        // Run as a direct UPDATE — avoids loading each model individually.
        Notification::active()
            ->forUser($user->id)
            ->withinDateRange()
            ->forDueDateTrigger($user)
            ->unread()
            ->update(['read_at' => now()]);

        // Bust the badge cache so the bell resets immediately on next page load.
        Cache::forget("unread_notifications_count:{$user->id}");

        return Inertia::render('Notifications/Index', [
            'notifications' => $notifications,
        ]);
    }

    /**
     * Mark all active notifications as read for the authenticated user.
     * Called by the "Mark all read" button; returns back() for Inertia.
     */
    public function markAllRead(Request $request)
    {
        $user = $request->user();

        Notification::active()
            ->forUser($user->id)
            ->withinDateRange()
            ->forDueDateTrigger($user)
            ->unread()
            ->update(['read_at' => now()]);

        Cache::forget("unread_notifications_count:{$user->id}");

        return back();
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
        $validated = $this->normalizeNotificationData($validated);

        if (! empty($validated['user_id'])) {
            $validated['target_role'] = 'student';
        }

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
        $validated = $this->normalizeNotificationData($validated);

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

    public function dismiss(Request $request, Notification $notification)
    {
        $user = $request->user();

        if (! $user->isAdmin()) {
            if ($notification->user_id !== null && $notification->user_id !== $user->id) {
                abort(403, 'You are not authorised to dismiss this notification.');
            }

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

        // Bust unread cache so bell badge updates after dismiss
        Cache::forget("unread_notifications_count:{$user->id}");

        return back()->with('success', 'Notification dismissed.');
    }

    // -------------------------------------------------------------------------
    // Private Helpers
    // -------------------------------------------------------------------------

    private function normalizeNotificationData(array $data): array
    {
        // Convert empty string to null for target_term_name
        // Empty string occurs when admin submits the form with "No filter" selected.
        // We store null instead so scopeForUser's whereNull check works correctly.
        if (isset($data['target_term_name']) && $data['target_term_name'] === '') {
            $data['target_term_name'] = null;
        }

        // Convert empty array to null for term_ids
        // Similarly, when no term_ids are selected, we store null instead of []
        // so scopeForUser's whereNull check catches it.
        if (isset($data['term_ids']) && (is_array($data['term_ids']) && count($data['term_ids']) === 0)) {
            $data['term_ids'] = null;
        }

        return $data;
    }

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

            if (! empty($data['payment_term_id'])) {
                StudentPaymentTerm::where('id', $data['payment_term_id'])
                    ->update(['due_date' => $dueDate]);

                Log::info('NotificationController: synced due_date to payment_term_id', [
                    'payment_term_id' => $data['payment_term_id'],
                    'due_date'        => $dueDate,
                ]);

                return;
            }

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