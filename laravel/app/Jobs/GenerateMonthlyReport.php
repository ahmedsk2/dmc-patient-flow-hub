<?php

namespace App\Jobs;

use App\Http\Controllers\ReportsController;
use App\Mail\MonthlyReportMail;
use App\Models\ReportRecipient;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Phase 3 — §3.3: build the prior month's booklet PDF and email it to every active recipient.
 * Dispatched on the 1st of each month by the routes/console.php schedule. The PDF is generated
 * through ReportsController::gatherBooklet — the EXACT code path of the downloadable monthlyPdf
 * endpoint — so the emailed PDF and the downloadable PDF are identical.
 */
class GenerateMonthlyReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $year, public int $month) {}

    public function handle(ReportsController $reports): void
    {
        $recipients = ReportRecipient::where('active', true)->get();
        if ($recipients->isEmpty()) {
            return; // nothing to do — skip the (expensive) PDF render entirely
        }

        $pdf = Pdf::loadView('reports.monthly-pdf', $reports->gatherBooklet($this->year))->setPaper('a4', 'landscape');
        $pdfContent = $pdf->output();

        // RES-05: one bad address or a relay hiccup must not abort the whole distribution — with
        // QUEUE_CONNECTION=sync the send runs inline, so an exception here would stop the loop and
        // silently skip every remaining recipient. Continue, remember who failed, log once.
        $failed = [];
        foreach ($recipients as $r) {
            try {
                Mail::to($r->email)->queue(new MonthlyReportMail($this->year, $this->month, $pdfContent));
            } catch (\Throwable $e) {
                $failed[] = $r->email;
                Log::warning('monthly report: send failed', [
                    'recipient' => $r->email, 'year' => $this->year, 'month' => $this->month,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        if ($failed !== [] && count($failed) === $recipients->count()) {
            Log::error('monthly report: every send failed', [
                'year' => $this->year, 'month' => $this->month, 'recipients' => count($failed),
            ]);
        }
    }
}
