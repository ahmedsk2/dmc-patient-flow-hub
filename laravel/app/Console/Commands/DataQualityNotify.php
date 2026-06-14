<?php

namespace App\Console\Commands;

use App\Http\Controllers\DataQualityController;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Phase 4 — Item 6: the daily data-quality digest. Runs the SAME five canary queries the dashboard
 * uses (DataQualityController::checks), sums the totals, and — if anything needs review — raises ONE
 * notification per run for every active admin (not one per row). Scheduled in routes/console.php.
 *
 *   php artisan dq:notify
 */
class DataQualityNotify extends Command
{
    protected $signature = 'dq:notify';
    protected $description = 'Notify admins of outstanding data-quality issues (daily digest)';

    public function handle(DataQualityController $dq): int
    {
        $checks = $dq->checks();
        $payload = [
            'over_los' => $checks['overLos']->count(),
            'no_dx' => $checks['noDx']->count(),
            'bad_dates' => $checks['badDates']->count(),
            'orphan_dx' => $checks['orphanDx']->count(),
            'double_open' => $checks['doubleOpen']->count(),
        ];
        $total = array_sum($payload);

        if ($total === 0) {
            $this->info('No data-quality issues — no notifications sent.');

            return self::SUCCESS;
        }

        $admins = User::where('role', User::ROLE_ADMIN)->where('active', 1)->pluck('id');
        foreach ($admins as $adminId) {
            Notification::create([
                'user_id' => $adminId,
                'type' => 'dq.daily_report',
                'created_at' => now(),
                'payload' => $payload,
            ]);
        }
        $this->info("Notified {$admins->count()} admin(s) of {$total} data-quality item(s).");

        return self::SUCCESS;
    }
}
