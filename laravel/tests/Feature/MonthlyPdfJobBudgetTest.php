<?php

namespace Tests\Feature;

use App\Http\Controllers\ReportsController;
use App\Jobs\GenerateMonthlyPdf;
use App\Support\RenderBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * With QUEUE_CONNECTION=sync the "background" monthly booklet runs inline in the admin's web
 * request, so it must take the same render budget as the direct PDF routes — otherwise the one path
 * built for the heaviest render is the one left on the tighter 60 s web SELECT cap.
 */
#[Group('pdf')]
class MonthlyPdfJobBudgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_monthly_booklet_job_applies_the_render_budget(): void
    {
        Storage::fake('local');
        $spy = new class extends RenderBudget
        {
            public int $calls = 0;

            public function apply(): void
            {
                $this->calls++;
            }
        };
        $this->app->instance(RenderBudget::class, $spy);

        (new GenerateMonthlyPdf(2024, 0))->handle(app(ReportsController::class));

        $this->assertSame(1, $spy->calls);
        Storage::disk('local')->assertExists('reports/monthly-2024-0.pdf');
    }
}
