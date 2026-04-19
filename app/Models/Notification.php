<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Custom Admin Notification Model
 *
 * Stored in `admin_notifications` table — separate from Laravel's built-in
 * `notifications` table. See docs/NOTIFICATION_ARCHITECTURE.md.
 */
class Notification extends Model
{
    use HasFactory;

    protected $table = 'admin_notifications';

    protected $fillable = [
        'title', 'message', 'type', 'start_date', 'end_date', 'due_date',
        'payment_term_id', 'target_role', 'user_id', 'is_active', 'is_complete',
        'dismissed_at', 'read_at', 'term_ids', 'target_term_name', 'trigger_days_before_due',
    ];

    protected $casts = [
        'start_date'   => 'date',
        'end_date'     => 'date',
        'due_date'     => 'date',
        'is_active'    => 'boolean',
        'is_complete'  => 'boolean',
        'dismissed_at' => 'datetime',
        'read_at'      => 'datetime',
        'term_ids'     => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(StudentPaymentTerm::class, 'payment_term_id');
    }

    public function scopeActive($query)
    {
        return $query
            ->where('is_active', true)
            ->where('is_complete', false)
            ->whereNull('dismissed_at');
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    public function scopeForUser($query, int|string $userIdentifier)
    {
        if (is_string($userIdentifier) && str_contains($userIdentifier, '@')) {
            $user = User::where('email', $userIdentifier)->first();
        } else {
            $user = User::find($userIdentifier);
        }

        if (! $user) {
            return $query->whereRaw('0 = 1');
        }

        return $query->where(function ($q) use ($user) {
            $q->where('user_id', $user->id)
              ->orWhere(function ($q2) use ($user) {
                  $roleString = $user->role instanceof \BackedEnum
                      ? $user->role->value
                      : (string) $user->role;

                  $q2->whereNull('user_id')
                     ->where(function ($q3) use ($user, $roleString) {
                         $q3->where('target_role', $roleString)
                            ->orWhere('target_role', 'all');
                     })
                     ->where(function ($q4) use ($user) {
                         // Match null OR empty string.
                         // Empty string occurs when admin submits the form with "No filter" selected
                         // before the normalization fix was applied. This ensures existing bad records
                         // in the database start working immediately without needing a migration.
                         $q4->where(function ($inner) {
                                $inner->whereNull('target_term_name')
                                      ->orWhere('target_term_name', '');
                            })
                            ->orWhereExists(function ($sub) use ($user) {
                                $sub->from('student_payment_terms')
                                    ->join('student_assessments', 'student_assessments.id', '=', 'student_payment_terms.student_assessment_id')
                                    ->where('student_assessments.user_id', $user->id)
                                    ->whereColumn('student_payment_terms.term_name', 'admin_notifications.target_term_name');
                            });
                     })
                     ->where(function ($q5) use ($user) {
                         $table  = (new self())->getTable();
                         $driver = DB::getDriverName();

                         $q5->whereNull('term_ids')
                            ->orWhereRaw("JSON_LENGTH({$table}.term_ids) = 0")
                            ->orWhereExists(function ($sub) use ($user, $table, $driver) {
                                $sub->from('student_payment_terms')
                                    ->join('student_assessments', 'student_assessments.id', '=', 'student_payment_terms.student_assessment_id')
                                    ->where('student_assessments.user_id', $user->id)
                                    ->whereRaw(
                                        $driver === 'sqlite'
                                            ? "EXISTS (SELECT 1 FROM json_each({$table}.term_ids) WHERE json_each.value = student_payment_terms.id)"
                                            : "JSON_CONTAINS({$table}.term_ids, CAST(student_payment_terms.id AS JSON))"
                                    );
                            });
                     });
              });
        });
    }

    public function scopeWithinDateRange($query)
    {
        $today = now()->toDateString();

        return $query
            ->where(function ($q) use ($today) {
                $q->whereNull('start_date')->orWhere('start_date', '<=', $today);
            })
            ->where(function ($q) use ($today) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $today);
            });
    }

    public function scopeForDueDateTrigger($query, User $user)
    {
        $today        = now()->toDateString();
        $maxLookahead = now()->addDays(90)->toDateString();
        $table        = $this->getTable();

        return $query->where(function ($q) use ($user, $today, $maxLookahead, $table) {
            $q->whereNull('trigger_days_before_due')
              ->orWhere(function ($q2) use ($user, $today, $maxLookahead, $table) {
                  $q2->whereNotNull('trigger_days_before_due')
                     ->whereExists(function ($sub) use ($user, $today, $maxLookahead, $table) {
                         $sub->from('student_payment_terms')
                             ->join('student_assessments', 'student_assessments.id', '=', 'student_payment_terms.student_assessment_id')
                             ->where('student_assessments.user_id', $user->id)
                             ->where('student_payment_terms.balance', '>', 0)
                             ->whereNotNull('student_payment_terms.due_date')
                             ->where('student_payment_terms.due_date', '>=', $today)
                             ->where('student_payment_terms.due_date', '<=', $maxLookahead)
                             ->whereRaw(
                                 'student_payment_terms.due_date <= ' .
                                 self::addDaysExpression("{$table}.trigger_days_before_due")
                             );
                     });
              });
        });
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function isCurrentlyActive(): bool
    {
        $today = now()->toDateString();

        return $this->is_active
            && ! $this->is_complete
            && ! $this->dismissed_at
            && (! $this->start_date || $this->start_date->toDateString() <= $today)
            && (! $this->end_date   || $this->end_date->toDateString()   >= $today);
    }

    public function markComplete(): void { $this->update(['is_complete' => true]); }
    public function markDismissed(): void { $this->update(['dismissed_at' => now()]); }

    public function markRead(): void
    {
        if (is_null($this->read_at)) {
            $this->update(['read_at' => now()]);
        }
    }

    public static function addDaysExpression(string $columnExpression): string
    {
        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            return "DATE('now', '+' || {$columnExpression} || ' days')";
        }
        return "DATE_ADD(CURDATE(), INTERVAL {$columnExpression} DAY)";
    }
}