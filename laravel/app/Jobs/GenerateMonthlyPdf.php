<?php

namespace App\Jobs;

use App\Http\Controllers\ReportsController;
use App\Models\Notification;
use App\Support\RenderBudget;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 3 — §3.6: queued heavy monthly-booklet generation. Renders the PDF off the web request
 * (which would otherwise risk a server timeout / memory exhaustion for a full 12-month booklet),
 * stores it on the PRIVATE local disk under storage/app/reports, and drops an in-app notification
 * for the requesting admin so they can download it via the authenticated downloadGenerated route.
 */
class GenerateMonthlyPdf implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $year, public int $userId) {}

    public function handle(ReportsController $reports): void
    {
        // QUEUE_CONNECTION=sync: this runs inline in the admin's web request, so it needs the same
        // render budget as the direct PDF routes (a no-op under a real, CLI queue worker).
        app(RenderBudget::class)->apply();

        $pdf = Pdf::loadView('reports.monthly-pdf', $reports->gatherBooklet($this->year))->setPaper('a4', 'landscape');

        $filename = "monthly-{$this->year}-{$this->userId}.pdf";
        Storage::disk('local')->put("reports/{$filename}", $pdf->output());

        if ($this->userId) {
            Notification::create([
                'user_id' => $this->userId,
                'type' => 'report.ready',
                'payload' => [
                    'message' => "Your {$this->year} monthly report is ready to download.",
                    'url' => "/reports/pdf-download/{$filename}",
                    'year' => $this->year,
                ],
            ]);
        }
    }
}
