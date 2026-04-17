<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentReminder extends Model
{
    protected $fillable = [
        'user_id',
        'student_assessment_id',
        'student_payment_term_id',
        'type',
        'message',
        'outstanding_balance',
        'status',
        'read_at',
        'dismissed_at',
        'in_app_sent',
        'email_sent',
        'email_sent_at',
        'scheduled_for',
        'sent_at',
        'trigger_reason',
        'triggered_by',
        'metadata',
    ];

    protected $casts = [
        'outstanding_balance' => 'decimal:2',
        'in_app_sent'         => 'boolean',
        'email_sent'          => 'boolean',
        'read_at'             => 'datetime',
        'dismissed_at'        => 'datetime',
        'email_sent_at'       => 'datetime',
        'scheduled_for'       => 'datetime',
        'sent_at'             => 'datetime',
        'metadata'            => 'array',
    ];

    const TYPE_PAYMENT_DUE      = 'payment_due';
    const TYPE_APPROACHING_DUE  = 'approaching_due';
    const TYPE_OVERDUE          = 'overdue';
    const TYPE_PARTIAL_PAYMENT  = 'partial_payment';
    const TYPE_PAYMENT_RECEIVED = 'payment_received';

    const STATUS_SENT      = 'sent';
    const STATUS_READ      = 'read';
    const STATUS_DISMISSED = 'dismissed';

    const TRIGGER_ADMIN_UPDATE       = 'admin_update';
    const TRIGGER_SCHEDULED_JOB      = 'scheduled_job';
    const TRIGGER_DUE_DATE_CHANGE    = 'due_date_change';
    const TRIGGER_THRESHOLD_REACHED  = 'threshold_reached';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(StudentAssessment::class, 'student_assessment_id');
    }

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(StudentPaymentTerm::class, 'student_payment_term_id');
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function markAsRead(): void
    {
        if ($this->status !== self::STATUS_READ) {
            $this->update([
                'status'  => self::STATUS_READ,
                'read_at' => now(),
            ]);
        }
    }

    public function markAsDismissed(): void
    {
        $this->update([
            'status'       => self::STATUS_DISMISSED,
            'dismissed_at' => now(),
        ]);
    }

    /**
     * FIX Bug #6: "Unread" = status is 'sent' only.
     * Previous version returned both 'sent' AND 'read' (not dismissed).
     * That inflated badge counts and was semantically wrong.
     */
    public static function unreadForUser(int $userId)
    {
        return self::where('user_id', $userId)
            ->where('status', self::STATUS_SENT)
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * All active (non-dismissed) reminders for a user — for notification panel.
     */
    public static function activeForUser(int $userId)
    {
        return self::where('user_id', $userId)
            ->where('status', '!=', self::STATUS_DISMISSED)
            ->orderByDesc('created_at')
            ->get();
    }

    public function isOverdueReminder(): bool
    {
        return $this->type === self::TYPE_OVERDUE;
    }

    public function getDaysInfo(): ?array
    {
        if (! $this->paymentTerm) {
            return null;
        }

        $daysUntilDue = now()->diffInDays($this->paymentTerm->due_date, false);

        return [
            'days'          => abs($daysUntilDue),
            'is_overdue'    => $daysUntilDue < 0,
            'is_approaching' => $daysUntilDue >= 0 && $daysUntilDue <= 3,
        ];
    }
}