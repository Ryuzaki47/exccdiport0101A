<?php

use App\Http\Controllers\Api\WorkflowApiController;
use App\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

// ── Webhook (NO auth — PayMongo calls this directly) ──────────────────────
Route::post('webhooks/paymongo', [PaymentController::class, 'webhook'])
    ->name('paymongo.webhook');

Route::middleware('auth:sanctum')->group(function () {
    // ── Payment routes ─────────────────────────────────────────────────────
    Route::post('/payments/checkout', [PaymentController::class, 'createCheckout']);
});

Route::middleware('auth')->group(function () {

    // ── Existing Workflow routes ───────────────────────────────────────────
    Route::get('workflows', [WorkflowApiController::class, 'index']);
    Route::get('workflows/{workflow}', [WorkflowApiController::class, 'show']);
    Route::get('workflows/{workflow}/instances', [WorkflowApiController::class, 'instances']);
    Route::get('workflow-instances/{instance}', [WorkflowApiController::class, 'instanceDetail']);
    Route::post('workflow-instances/{instance}/advance', [WorkflowApiController::class, 'advanceInstance']);
    Route::get('my-approvals', [WorkflowApiController::class, 'myApprovals']);
    Route::post('approvals/{approval}/approve', [WorkflowApiController::class, 'approve']);
    Route::post('approvals/{approval}/reject', [WorkflowApiController::class, 'reject']);

    // ── Payment routes ─────────────────────────────────────────────────────
    Route::prefix('payments')->group(function () {
        Route::post('gcash-maya', [PaymentController::class, 'createSource']);
        Route::post('bank-transfer', [PaymentController::class, 'submitBankTransfer']);
        Route::get('check-status', [PaymentController::class, 'checkStatus']);

        // Accounting only
        Route::post('{payment}/verify', [PaymentController::class, 'verifyBankTransfer']);
    });
});

// TEMPORARY SEED ROUTE — REMOVE IMMEDIATELY AFTER USE
Route::get('/run-setup-now-xk29', function () {
    try {
        $output = [];

        \Artisan::call('route:clear');
        $output[] = 'route:clear: done';

        \Artisan::call('config:clear');
        $output[] = 'config:clear: done';

        \DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        \Artisan::call('migrate:fresh', ['--force' => true]);
        $output[] = 'migrate:fresh: ' . \Artisan::output();

        \DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        \Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
        $output[] = 'DatabaseSeeder: ' . \Artisan::output();

        return response()->json([
            'status' => 'done',
            'output' => $output,
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'status'  => 'error',
            'message' => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
            'trace'   => collect(explode("\n", $e->getTraceAsString()))
                           ->take(15)
                           ->values(),
        ], 500);
    }
});