<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\ReportRecipient;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Task #231 — scheduled wrapper around `audit:verify` that turns a broken tamper-evident hash
 * chain into an ALERT instead of a line only someone reading cron/CI output would ever see.
 *
 * A broken chain means a row in `audit_log` was edited or deleted out from under the hash chain —
 * i.e. a *possible tampering incident*, not a routine data-quality blip. Because whatever caused
 * the break might involve the very same database an alert would otherwise rely on, the failure
 * path fans out across channels that don't all depend on that database staying trustworthy:
 *
 *   1. Log::critical — the always-works, dependency-free channel. This is exactly what off-box
 *      log shipping (task #230) will eventually carry off this box, so the alert survives even a
 *      fully compromised DB.
 *   2. An in-app `audit.integrity_failure` notification for every ACTIVE admin (mirrors
 *      DataQualityNotify's admin-fan-out), de-duplicated so the same still-open incident doesn't
 *      mint a fresh row every single day the chain remains broken.
 *   3. A best-effort plain-text email to active report recipients — wrapped in try/catch so a
 *      mail outage never turns a real integrity incident into an uncaught exception.
 *
 * On an intact chain this command is silent apart from an info-level log line — no admin noise on
 * the happy path. Scheduled nightly, ahead of the 07:00 data-quality digest, in routes/console.php.
 *
 *   php artisan audit:verify-daily
 */
class AuditVerifyDaily extends Command
{
    protected $signature = 'audit:verify-daily';

    protected $description = 'Run audit:verify and alert admins (log + in-app + email) if the hash chain is broken';

    public function handle(): int
    {
        // Capture the nested command's output with an explicit OutputStyle wrapping our own
        // BufferedOutput, rather than relying on Artisan::output() (shared state on the console
        // Application singleton) or passing a raw BufferedOutput. Illuminate\Console\Command::run()
        // only honours a passed OutputInterface AS-IS when it's already an OutputStyle instance;
        // otherwise it resolves `OutputStyle::class` through the container — which, when THIS
        // command was itself invoked via PHPUnit's $this->artisan() test helper, is bound to that
        // helper's own output mock. Passing a real OutputStyle here bypasses that override so the
        // captured text is reliable in both production and tests.
        $buffer = new BufferedOutput;
        $exit = Artisan::call('audit:verify', [], new OutputStyle(new ArrayInput([]), $buffer));
        $output = trim($buffer->fetch());

        if ($exit === self::SUCCESS) {
            $this->info('Audit chain intact — no alert needed.');
            Log::info('audit.integrity_ok', ['output' => $output]);

            return self::SUCCESS;
        }

        $this->error('Audit chain integrity check FAILED — alerting admins.');
        Log::critical('audit.integrity_failure', ['output' => $output]);

        $this->notifyAdmins($output);
        $this->emailReportRecipients($output);

        return self::FAILURE;
    }

    /**
     * One notification per active admin, skipping anyone who already has an unresolved
     * audit.integrity_failure open — otherwise a chain that stays broken for a week mints seven
     * identical unread rows per admin instead of one they can act on and resolve.
     */
    private function notifyAdmins(string $output): void
    {
        $adminIds = User::where('role', User::ROLE_ADMIN)->where('active', 1)->pluck('id');

        foreach ($adminIds as $adminId) {
            $alreadyOpen = Notification::where('user_id', $adminId)
                ->where('type', 'audit.integrity_failure')
                ->whereNull('resolved_at')
                ->exists();

            if ($alreadyOpen) {
                continue;
            }

            Notification::create([
                'user_id' => $adminId,
                'type' => 'audit.integrity_failure',
                'created_at' => now(),
                'payload' => ['detail' => $output],
            ]);
        }

        $this->info("Notified {$adminIds->count()} active admin(s) (new incidents only).");
    }

    /** Best-effort — a mail outage must never crash the incident path itself. */
    private function emailReportRecipients(string $output): void
    {
        $emails = ReportRecipient::where('active', true)->pluck('email');
        if ($emails->isEmpty()) {
            return;
        }

        try {
            Mail::raw($output, function ($message) use ($emails) {
                $message->to($emails->all())->subject('DMC audit-log integrity check FAILED');
            });
        } catch (\Throwable $e) {
            Log::warning('audit.integrity_failure_email_failed', ['error' => $e->getMessage()]);
        }
    }
}
