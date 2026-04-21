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
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// ============================================
// PUBLIC ROUTES
// ============================================
Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::post('/webhook/paymongo', [PaymongoWebhookController::class, 'handle']);

Route::get('/payment/success', [PaymentController::class, 'success'])->name('payment.success');
Route::get('/payment/cancel',  [PaymentController::class, 'cancel'])->name('payment.cancel');


// ============================================
// AUTHENTICATED ROUTES
// ============================================
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/api/payments/bank-details', [PaymentController::class, 'getBankDetails'])->name('payment.bank-details');
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
    Route::post('reminders/{reminder}/read', [PaymentReminderController::class, 'markRead'])->name('reminders.read');
    Route::post('reminders/{reminder}/dismiss', [PaymentReminderController::class, 'dismiss'])->name('reminders.dismiss');
    Route::post('/account/pay-now', [TransactionController::class, 'payNow'])->name('account.pay-now');
    Route::get('/payment/{transaction}/proof', [PaymentController::class, 'showProofForm'])->name('payment.proof.show');
    Route::post('/payment/{transaction}/proof', [PaymentController::class, 'uploadProof'])->name('payment.proof.upload');
    Route::get('/notifications', [NotificationController::class, 'studentIndex'])->name('student.notifications');
    Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllRead'])->name('student.notifications.mark-all-read');

    // ✅ FIX: Named dismiss route for student notifications
    Route::post('/notifications/{notification}/dismiss', [NotificationController::class, 'dismiss'])->name('notifications.dismiss');
});

// ============================================
// STUDENT ARCHIVE ROUTES (Admin Only)
// ============================================
Route::middleware(['auth', 'verified', 'role:admin'])->group(function () {
    Route::resource('students', StudentController::class);
    Route::post('students/{student}/payments', [StudentController::class, 'storePayment'])->name('students.payments.store');
    Route::post('students/{student}/advance-workflow', [StudentController::class, 'advanceWorkflow'])->name('students.advance-workflow');
    Route::get('students/{student}/workflow-history', [StudentController::class, 'workflowHistory'])->name('students.workflow-history');
    Route::get('students-archive', [StudentController::class, 'archive'])->name('students.archive');
    Route::post('students/{student}/reinstate', [StudentController::class, 'reinstate'])->name('students.reinstate');
});

// ============================================
// STUDENT FEE MANAGEMENT ROUTES
// ============================================

// ── Shared: Admin + Accounting ───────────────────────────────────────────────
Route::middleware(['auth', 'verified', 'role:admin,accounting'])
    ->prefix('student-fees')
    ->name('student-fees.')
    ->group(function () {
        Route::get('/', [StudentFeeController::class, 'index'])->name('index');
        Route::get('/search', [StudentFeeController::class, 'search'])->name('search');

        // IMPORTANT: Static-segment routes MUST come before wildcard /{userId}
        Route::get('/latest-assessment', [StudentFeeController::class, 'getLatestAssessmentData'])->name('latest-assessment');

        // Auto-populate curriculum units for the Create form
        Route::get('/curriculum-units', [StudentFeeController::class, 'getCurriculumUnits'])->name('curriculum-units');

        // ── Create and store assessments (was accounting-only — now shared) ──
        Route::get('/create', [StudentFeeController::class, 'create'])->name('create');
        Route::post('/', [StudentFeeController::class, 'store'])->name('store');

        Route::get('/{userId}/export-pdf', [StudentFeeController::class, 'exportPdf'])->whereNumber('userId')->name('export-pdf');
        Route::post('/{userId}/payments', [StudentFeeController::class, 'storePayment'])->whereNumber('userId')->name('payments.store');
        Route::post('/{user}/drop', [StudentFeeController::class, 'drop'])->whereNumber('user')->name('drop');
        Route::get('/{userId}/edit', [StudentFeeController::class, 'edit'])->whereNumber('userId')->name('edit');

        // Catch-all last
        Route::get('/{userId}', [StudentFeeController::class, 'show'])->whereNumber('userId')->name('show');
    });

// ── Admin-only: Edit assessment, manage students ──────────────────────────────
Route::middleware(['auth', 'verified', 'role:admin'])
    ->prefix('student-fees')
    ->name('student-fees.')
    ->group(function () {
        Route::get('/create-student', [StudentFeeController::class, 'createStudent'])->name('create-student');
        Route::post('/store-student', [StudentFeeController::class, 'storeStudent'])->name('store-student');
        Route::put('/{userId}', [StudentFeeController::class, 'update'])->whereNumber('userId')->name('update');
        Route::get('/{student}/edit-student', [StudentFeeController::class, 'editStudent'])->name('edit-student');
        Route::put('/{student}/update-student', [StudentFeeController::class, 'updateStudent'])->name('update-student');
    });

// ============================================
// TRANSACTION ROUTES
// ============================================
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/transactions', [TransactionController::class, 'index'])->name('transactions.index');
    Route::get('/transactions/download', [TransactionController::class, 'download'])->name('transactions.download');
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
    Route::post('users/{user}/deactivate', [AdminController::class, 'deactivate'])->name('admin.users.deactivate');
    Route::post('users/{user}/reactivate', [AdminController::class, 'reactivate'])->name('admin.users.reactivate');
    Route::resource('notifications', NotificationController::class)->names([
        'index'   => 'admin.notifications.index',
        'create'  => 'admin.notifications.create',
        'store'   => 'admin.notifications.store',
        'show'    => 'admin.notifications.show',
        'edit'    => 'admin.notifications.edit',
        'update'  => 'admin.notifications.update',
        'destroy' => 'admin.notifications.destroy',
    ]);
    // ✅ FIX: Admin can also dismiss notifications (for their own banner management)
    Route::post('notifications/{notification}/dismiss', [NotificationController::class, 'dismiss'])->name('admin.notifications.dismiss');

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

    // Fee Settings — store and destroy included
    Route::get('/fee-settings', [FeeSettingsController::class, 'index'])->name('accounting.fee-settings.index');
    Route::patch('/fee-settings/{feeSetting}', [FeeSettingsController::class, 'update'])->name('accounting.fee-settings.update');
    // NOTE: bulk must be registered BEFORE the {feeSetting} wildcard to avoid collision
    Route::post('/fee-settings/bulk', [FeeSettingsController::class, 'bulkUpdate'])->name('accounting.fee-settings.bulk');
    Route::post('/fee-settings', [FeeSettingsController::class, 'store'])->name('accounting.fee-settings.store');
    Route::delete('/fee-settings/{feeSetting}', [FeeSettingsController::class, 'destroy'])->name('accounting.fee-settings.destroy');
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
    Route::post('/{transaction}/submit', [AccountingTransactionController::class, 'submitForApproval'])->name('accounting-workflows.submit');
});

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

require __DIR__ . '/settings.php';

if (app()->environment('local')) {
    Route::middleware('auth')->get('/debug/csrf-token', [\App\Http\Controllers\Debug\DebugController::class, 'csrfToken']);
}

require __DIR__ . '/auth.php';