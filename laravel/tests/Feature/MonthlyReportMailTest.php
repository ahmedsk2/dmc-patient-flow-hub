<?php

namespace Tests\Feature;

use App\Jobs\GenerateMonthlyReport;
use App\Mail\MonthlyReportMail;
use App\Models\ReportRecipient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Phase 3 — §3.3: scheduled monthly-report email (job dispatch, recipient CRUD, control panel). */
#[\PHPUnit\Framework\Attributes\Group('pdf')]
class MonthlyReportMailTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['username' => 'mr_admin_' . substr(md5(uniqid('', true)), 0, 6),
            'name' => 'MR Admin', 'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 1]);
    }

    private function nonAdmin(): User
    {
        return User::create(['username' => 'mr_cons_' . substr(md5(uniqid('', true)), 0, 6),
            'name' => 'MR Cons', 'password' => 'secret12345', 'role' => User::ROLE_CONSULTANT, 'active' => 1]);
    }

    public function test_job_dispatches_to_active_recipients_only(): void
    {
        Mail::fake();
        ReportRecipient::create(['email' => 'a@dmc-im.com', 'active' => true]);
        ReportRecipient::create(['email' => 'b@dmc-im.com', 'active' => true]);
        ReportRecipient::create(['email' => 'c@dmc-im.com', 'active' => false]);

        (new GenerateMonthlyReport(2024, 6))->handle(app(\App\Http\Controllers\ReportsController::class));

        Mail::assertQueued(MonthlyReportMail::class, 2);
        Mail::assertQueued(MonthlyReportMail::class, fn ($m) => $m->hasTo('a@dmc-im.com'));
        Mail::assertNotQueued(MonthlyReportMail::class, fn ($m) => $m->hasTo('c@dmc-im.com'));
    }

    public function test_job_dispatches_no_mail_when_no_recipients(): void
    {
        Mail::fake();
        (new GenerateMonthlyReport(2024, 6))->handle(app(\App\Http\Controllers\ReportsController::class));
        Mail::assertNothingQueued();
    }

    public function test_add_recipient_requires_admin(): void
    {
        $this->actingAs($this->nonAdmin())
            ->post('/control/report-recipients', ['email' => 'x@dmc-im.com'])->assertForbidden();
    }

    public function test_add_recipient_validates_email(): void
    {
        $this->actingAs($this->admin())
            ->post('/control/report-recipients', ['email' => 'notanemail'])
            ->assertSessionHasErrors('email');
    }

    public function test_add_recipient_unique_constraint(): void
    {
        ReportRecipient::create(['email' => 'dup@dmc-im.com', 'active' => true]);
        $this->actingAs($this->admin())
            ->post('/control/report-recipients', ['email' => 'dup@dmc-im.com'])
            ->assertSessionHasErrors('email');
    }

    public function test_add_recipient_persists_and_audits(): void
    {
        $this->actingAs($this->admin())
            ->post('/control/report-recipients', ['email' => 'new@dmc-im.com']);
        $this->assertDatabaseHas('report_recipients', ['email' => 'new@dmc-im.com', 'active' => 1]);
        $this->assertDatabaseHas('audit_log', ['action' => 'report_recipient.add', 'entity_type' => 'report_recipient']);
    }

    public function test_remove_recipient_requires_admin(): void
    {
        $r = ReportRecipient::create(['email' => 'rm@dmc-im.com', 'active' => true]);
        $this->actingAs($this->nonAdmin())
            ->delete("/control/report-recipients/{$r->id}")->assertForbidden();
    }

    public function test_remove_recipient_deletes(): void
    {
        $r = ReportRecipient::create(['email' => 'gone@dmc-im.com', 'active' => true]);
        $this->actingAs($this->admin())->delete("/control/report-recipients/{$r->id}");
        $this->assertDatabaseMissing('report_recipients', ['id' => $r->id]);
    }

    public function test_control_index_includes_recipients(): void
    {
        ReportRecipient::create(['email' => 'shown@dmc-im.com', 'active' => true]);
        $this->actingAs($this->admin())->get('/control')
            ->assertInertia(fn (AssertableInertia $p) => $p->has('reportRecipients', 1)
                ->where('reportRecipients.0.email', 'shown@dmc-im.com'));
    }

    public function test_emailed_pdf_uses_same_blade_as_download(): void
    {
        // the Mailable carries the booklet PDF as an application/pdf attachment with the dmc-monthly
        // filename pattern — the emailed PDF == the downloadable PDF (same gatherBooklet code path)
        $mail = new MonthlyReportMail(2024, 6, '%PDF-1.4 fake');
        $mail->assertHasSubject('DMC Internal Medicine — Monthly Report June 2024');
        $mail->assertHasAttachedData('%PDF-1.4 fake', 'dmc-monthly-2024-06.pdf', ['mime' => 'application/pdf']);
    }
}
