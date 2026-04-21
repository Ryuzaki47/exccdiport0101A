<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckQueueHealth extends Command
{
    protected $signature = 'queue:check-health {--threshold=100}';

    protected $description = 'Monitor queue health and alert if job backlog exceeds threshold';

    public function handle(): int
    {
        $threshold = (int) $this->option('threshold');

        // Count pending jobs in the queue table
        $pendingCount = DB::table('jobs')
            ->whereNull('reserved_at')
            ->count();

        // Count reserved jobs (currently being processed)
        $reservedCount = DB::table('jobs')
            ->whereNotNull('reserved_at')
            ->count();

        // Count failed jobs
        $failedCount = DB::table('failed_jobs')->count();

        $status = 'healthy';
        $level  = 'info';

        if ($failedCount > 0) {
            $status = 'DEGRADED — failed jobs detected';
            $level  = 'warning';
        }

        if ($pendingCount > $threshold) {
            $status = "CRITICAL — backlog exceeds threshold ($pendingCount > $threshold)";
            $level  = 'critical';
        }

        $message = "Queue health check: $status | Pending: $pendingCount, Processing: $reservedCount, Failed: $failedCount";

        Log::log($level, $message, [
            'pending'   => $pendingCount,
            'reserved'  => $reservedCount,
            'failed'    => $failedCount,
            'threshold' => $threshold,
        ]);

        $this->info($message);

        return $status === 'healthy' ? 0 : 1;
    }
}
