<?php

namespace App\Providers;

use App\Models\User;
use App\Models\Notification;
use App\Models\StudentPaymentTerm;
use App\Models\Student;
use App\Models\WorkflowApproval;
use App\Models\Payment;

use App\Policies\UserPolicy;
use App\Policies\NotificationPolicy;
use App\Policies\StudentPaymentTermPolicy;
use App\Policies\WorkflowApprovalPolicy;
use App\Policies\PaymentPolicy;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        User::class => UserPolicy::class,
        Notification::class => NotificationPolicy::class,
        WorkflowApproval::class => WorkflowApprovalPolicy::class,
        StudentPaymentTerm::class => StudentPaymentTermPolicy::class,
        Payment::class => PaymentPolicy::class, // ✅ ADDED
    ];

    public function boot(): void
    {
        $this->registerPolicies();

        // ============================================================
        // ROUTE MODEL BINDING — Include Soft-Deleted Students
        // ============================================================
        Route::bind('student', function ($value) {
            return Student::withTrashed()->findOrFail($value);
        });
    }
}