<?php

use App\Http\Controllers\Accounting\FinancialReportsController;
use App\Http\Controllers\Accounting\FeeSettingsController;
use App\Http\Controllers\AccountingDashboardController;
use App\Http\Controllers\AccountingTransactionController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\PaymongoWebhookController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaymentReminderController;
use App\Http\Controllers\PaymentTermsController;
use App\Http\Controllers\StudentAccountController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\StudentDashboardController;
use App\Http\Controllers\StudentFeeController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\WorkflowApprovalController;
use App\Http\Controllers\WorkflowController;
// REMOVED: FeeController (Fee Management disabled)
// REMOVED: SubjectController (Subject Management disabled)
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// ============================================
// PUBLIC ROUTES
// ============================================
Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

// PayMongo Webhook — No authentication required (called by PayMongo servers)
Route::post('/webhook/paymongo', [PaymongoWebhookController::class, 'handle']);

// PayMongo Redirect Routes — must be inside auth so auth()->user() is never null.
// PayMongo redirects the student's authenticated browser session back here,
// so the session cookie will always be present on a normal flow.
// If the session has expired, redirectToLogin() is safer than a null-user crash.
Route::middleware(['auth'])->group(function () {
    Route::get('/payment/success', [PaymentController::class, 'success'])->name('payment.success');
    Route::get('/payment/cancel',  [PaymentController::class, 'cancel'])->name('payment.cancel');
});

// ============================================
// AUTHENTICATED ROUTES
// ============================================
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('notifications/{notification}/dismiss', [NotificationController::class, 'dismiss'])->name('notifications.dismiss');
    // Bank details endpoint for payment form
    Route::get('/api/payments/bank-details', [PaymentController::class, 'getBankDetails']);
});

// ============================================
// STUDENT-SPECIFIC ROUTES
// ============================================
Route::middleware(['auth', 'verified', 'role:student'])->prefix('student')->group(function () {
    Route::get('/dashboard', [StudentDashboardController::class, 'index'])->name('student.dashboard');
    Route::get('/account', [StudentAccountController::class, 'index'])->name('student.account');
    Route::get('/payment', [PaymentController::class, 'create'])->name('payment.create');
    Route::post('/payment/checkout', [PaymentController::class, 'createCheckout'])->name('payment.checkout');
    Route::post('/payment/bank-transfer', [PaymentController::class, 'submitBankTransfer'])->name('payment.bank-transfer');
    Route::post('reminders/{reminder}/read', [PaymentReminderController::class, 'markRead'])
        ->name('reminders.read');
    Route::post('reminders/{reminder}/dismiss', [PaymentReminderController::class, 'dismiss'])
        ->name('reminders.dismiss');
    Route::post('/account/pay-now', [TransactionController::class, 'payNow'])->name('account.pay-now');
    Route::get('/payment/{transaction}/proof', [PaymentController::class, 'showProofForm'])->name('payment.proof.show');
    Route::post('/payment/{transaction}/proof', [PaymentController::class, 'uploadProof'])->name('payment.proof.upload');
});

// ============================================
// STUDENT ARCHIVE ROUTES (Admin Only)
// ============================================
Route::middleware(['auth', 'verified', 'role:admin'])->group(function () {
    Route::resource('students', StudentController::class);
    Route::post('students/{student}/payments', [StudentController::class, 'storePayment'])->name('students.payments.store');
    Route::post('students/{student}/advance-workflow', [StudentController::class, 'advanceWorkflow'])
        ->name('students.advance-workflow');
    Route::get('students/{student}/workflow-history', [StudentController::class, 'workflowHistory'])
        ->name('students.workflow-history');
    // Student Archives — graduated, dropped, and inactive students
    Route::get('students-archive', [StudentController::class, 'archive'])->name('students.archive');
    Route::post('students/{student}/reinstate', [StudentController::class, 'reinstate'])
        ->name('students.reinstate');
});

// ============================================
// STUDENT FEE MANAGEMENT ROUTES
// ============================================
// Admin + Accounting: index, show, export PDF, payments
// Admin only:         edit, update, create-student, store-student
// Accounting only:    create, search, store assessment
// ============================================

// ── Shared: Admin + Accounting ───────────────────────────────────────────────
Route::middleware(['auth', 'verified', 'role:admin,accounting'])
    ->prefix('student-fees')
    ->name('student-fees.')
    ->group(function () {
        Route::get('/', [StudentFeeController::class, 'index'])->name('index');
        Route::get('/search', [StudentFeeController::class, 'search'])->name('search');

        Route::get('/{userId}/export-pdf', [StudentFeeController::class, 'exportPdf'])
            ->whereNumber('userId')
            ->name('export-pdf');

        Route::post('/{userId}/payments', [StudentFeeController::class, 'storePayment'])
            ->whereNumber('userId')
            ->name('payments.store');

        Route::post('/{user}/drop', [StudentFeeController::class, 'drop'])
            ->whereNumber('user')
            ->name('drop');

        // IMPORTANT: Specific static-segment routes MUST be registered
        // BEFORE the wildcard /{userId} catch-all to prevent route collision.
        Route::get('/latest-assessment', [StudentFeeController::class, 'getLatestAssessmentData'])
            ->name('latest-assessment');

        Route::get('/{userId}/edit', [StudentFeeController::class, 'edit'])
            ->whereNumber('userId')
            ->name('edit');

        // Catch-all last — after all /{userId}/sub-routes above.
        Route::get('/{userId}', [StudentFeeController::class, 'show'])
            ->whereNumber('userId')
            ->name('show');
    });

// ── Admin-only: Edit assessment ───────────────────────────────────────────────
// NOTE: The edit/update actions are also guarded inside the controller.
// The route group above allows accounting to see the URL resolve (no 404),
// but the controller's edit() and update() methods enforce admin-only
// via policy or explicit role check. This prevents Inertia 404 on redirect.
Route::middleware(['auth', 'verified', 'role:admin'])
    ->prefix('student-fees')
    ->name('student-fees.')
    ->group(function () {
        Route::put('/{userId}', [StudentFeeController::class, 'update'])
            ->whereNumber('userId')
            ->name('update');

        Route::get('/create-student', [StudentFeeController::class, 'createStudent'])
            ->name('create-student');

        Route::post('/store-student', [StudentFeeController::class, 'storeStudent'])
            ->name('store-student');
    });

// ── Accounting-only: Create and store assessments ────────────────────────────
Route::middleware(['auth', 'verified', 'role:accounting'])
    ->prefix('student-fees')
    ->name('student-fees.')
    ->group(function () {
        Route::get('/create', [StudentFeeController::class, 'create'])->name('create');
        Route::post('/', [StudentFeeController::class, 'store'])->name('store');
    });

// ============================================
// TRANSACTION ROUTES
// ============================================
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/transactions', [TransactionController::class, 'index'])->name('transactions.index');
    Route::get('/transactions/download', [TransactionController::class, 'download'])->name('transactions.download');
    // Single-transaction receipt PDF — any authenticated user, but controller enforces ownership
    Route::get('/transactions/{transaction}/receipt', [TransactionController::class, 'receipt'])->name('transactions.receipt');
});

Route::middleware(['auth', 'verified', 'role:admin,accounting'])->group(function () {
    Route::get('/transactions/create', [TransactionController::class, 'create'])->name('transactions.create');
    Route::post('/transactions', [TransactionController::class, 'store'])->name('transactions.store');
    Route::get('/transactions/{transaction}', [TransactionController::class, 'show'])->name('transactions.show');
    Route::delete('/transactions/{transaction}', [TransactionController::class, 'destroy'])->name('transactions.destroy');
});

// ============================================
// ADMIN ROUTES
// ============================================
Route::middleware(['auth', 'verified', 'role:admin'])->prefix('admin')->group(function () {
    Route::get('/dashboard', [AdminDashboardController::class, 'index'])->name('admin.dashboard');
    Route::resource('users', AdminController::class)->names([
        'index'   => 'users.index',
        'create'  => 'users.create',
        'store'   => 'users.store',
        'show'    => 'users.show',
        'edit'    => 'users.edit',
        'update'  => 'users.update',
        'destroy' => 'users.destroy',
    ]);
    // NOTE: These two routes MUST use the admin.users.* name — no aliases.
    Route::post('users/{user}/deactivate', [AdminController::class, 'deactivate'])
        ->name('admin.users.deactivate');
    Route::post('users/{user}/reactivate', [AdminController::class, 'reactivate'])
        ->name('admin.users.reactivate');
    Route::resource('notifications', NotificationController::class);
    Route::get('/payment-terms', [PaymentTermsController::class, 'index'])->name('admin.payment-terms.index');
    Route::post('/payment-terms/{paymentTerm}/due-date', [PaymentTermsController::class, 'updateDueDate'])->name('admin.payment-terms.update-due-date');
    Route::post('/payment-terms/bulk-due-date', [PaymentTermsController::class, 'bulkUpdateDueDate'])->name('admin.payment-terms.bulk-due-date');
});

// ============================================
// ACCOUNTING ROUTES
// ============================================
Route::middleware(['auth', 'verified', 'role:accounting,admin'])->prefix('accounting')->group(function () {
    Route::get('/dashboard', [AccountingDashboardController::class, 'index'])->name('accounting.dashboard');
    Route::get('/transactions', [TransactionController::class, 'index'])->name('accounting.transactions.index');
    Route::get('/financial-reports', [FinancialReportsController::class, 'index'])->name('accounting.financial-reports');
    Route::get('/financial-reports/export', [FinancialReportsController::class, 'export'])->name('accounting.financial-reports.export');

    // Fee Settings
    Route::get('/fee-settings', [FeeSettingsController::class, 'index'])->name('accounting.fee-settings.index');
    Route::patch('/fee-settings/{feeSetting}', [FeeSettingsController::class, 'update'])->name('accounting.fee-settings.update');
    Route::post('/fee-settings/bulk', [FeeSettingsController::class, 'bulkUpdate'])->name('accounting.fee-settings.bulk');
});

// ============================================
// ACCOUNTING TRANSACTION WORKFLOW ROUTES
// ============================================
Route::middleware(['auth', 'verified', 'role:admin,accounting'])->prefix('accounting-workflows')->group(function () {
    Route::get('/', [AccountingTransactionController::class, 'index'])->name('accounting-workflows.index');
    Route::get('/create', [AccountingTransactionController::class, 'create'])->name('accounting-workflows.create');
    Route::post('/', [AccountingTransactionController::class, 'store'])->name('accounting-workflows.store');
    Route::get('/{transaction}', [AccountingTransactionController::class, 'show'])->name('accounting-workflows.show');
    Route::put('/{transaction}', [AccountingTransactionController::class, 'update'])->name('accounting-workflows.update');
    Route::delete('/{transaction}', [AccountingTransactionController::class, 'destroy'])->name('accounting-workflows.destroy');
    Route::post('/{transaction}/submit', [AccountingTransactionController::class, 'submitForApproval'])
        ->name('accounting-workflows.submit');
});

// ============================================
// FEE MANAGEMENT ROUTES — DISABLED
// ============================================
// Route::middleware(['auth', 'verified', 'role:admin,accounting'])->group(function () {
//     Route::resource('fees', FeeController::class);
//     Route::post('fees/{fee}/toggle-status', [FeeController::class, 'toggleStatus'])->name('fees.toggleStatus');
//     Route::post('fees/assign-to-students', [FeeController::class, 'assignToStudents'])->name('fees.assignToStudents');
// });

// ============================================
// SUBJECT MANAGEMENT ROUTES — DISABLED
// ============================================
// Route::middleware(['auth', 'verified', 'role:admin,accounting'])->group(function () {
//     Route::resource('subjects', SubjectController::class);
//     Route::post('subjects/{subject}/enroll-students', [SubjectController::class, 'enrollStudents'])->name('subjects.enrollStudents');
// });

// ============================================
// WORKFLOW MANAGEMENT ROUTES
// ============================================
Route::middleware(['auth', 'verified', 'role:admin,accounting'])->group(function () {
    Route::resource('workflows', WorkflowController::class);
    Route::get('/approvals', [WorkflowApprovalController::class, 'index'])->name('approvals.index');
    Route::get('/approvals/{approval}', [WorkflowApprovalController::class, 'show'])->name('approvals.show');
    Route::post('/approvals/{approval}/approve', [WorkflowApprovalController::class, 'approve'])->name('approvals.approve');
    Route::post('/approvals/{approval}/reject', [WorkflowApprovalController::class, 'reject'])->name('approvals.reject');
});

// ─────────────────────────────────────────────────────────────────────────────
// MAIL INTEGRATION TEST — Remove before going fully live in production
// Access: GET /test-resend
// Sends a synchronous test email via Resend to the hardcoded address.
// ─────────────────────────────────────────────────────────────────────────────
if (app()->environment(['local', 'staging'])) {
    Route::get('/test-resend', function () {
        \Illuminate\Support\Facades\Notification::route('mail', 'ryuzakikamisama@gmail.com')
            ->notify(new \App\Notifications\TestNotification());

        return response()->json([
            'status'   => 'sent',
            'mailer'   => config('mail.default'),
            'from'     => config('mail.from.address'),
            'to'       => 'ryuzakikamisama@gmail.com',
            'env'      => app()->environment(),
        ]);
    })->name('test.resend');
}

// TEMPORARY SEED ROUTE — REMOVE IMMEDIATELY AFTER USE
Route::get('/run-setup-now-xk29', function () {
    if (app()->environment('production')) {
        try {
            $output = [];

            // Disable foreign key checks before dropping tables
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');

            Artisan::call('migrate:fresh', ['--force' => true]);
            $output[] = 'migrate:fresh: ' . Artisan::output();

            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
            $output[] = 'DatabaseSeeder: ' . Artisan::output();

            return response()->json([
                'status' => 'done',
                'output' => $output,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status'   => 'error',
                'message'  => $e->getMessage(),
                'file'     => $e->getFile(),
                'line'     => $e->getLine(),
                'trace'    => collect(explode("\n", $e->getTraceAsString()))
                                ->take(15)
                                ->values(),
            ], 500);
        }
    }

    return response()->json(['status' => 'forbidden'], 403);
});

// ============================================
// SETTINGS ROUTES
// Appearance is defined in routes/settings.php — no duplicate here.
// ============================================
require __DIR__ . '/settings.php';

if (app()->environment('local')) {
    Route::middleware('auth')->get('/debug/csrf-token', [\App\Http\Controllers\Debug\DebugController::class, 'csrfToken']);
}

require __DIR__ . '/auth.php';