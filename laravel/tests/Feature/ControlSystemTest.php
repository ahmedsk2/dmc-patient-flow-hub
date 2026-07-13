<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ControlSystemTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'username' => 'sysadmin', 'name' => 'Sys Admin', 'email' => 'admin@dmc-im.com',
            'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 1,
            'email_verified_at' => now(), 'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ]);
    }

    private function stepup(): array
    {
        return ['stepup.verified_at' => now()->getTimestamp()];
    }

    public function test_index_exposes_system_config_without_the_password_value(): void
    {
        Setting::current()->update(['mail_host' => 'smtp.example.com', 'mail_password' => 'topsecret', 'app_timezone' => 'Asia/Riyadh']);

        $this->actingAs($this->admin())->get('/control')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('system.mail_host', 'smtp.example.com')
            ->where('system.mail_password_set', true)
            ->where('system.app_timezone', 'Asia/Riyadh')
            ->missing('system.mail_password'));
    }

    public function test_update_saves_config_and_a_blank_password_keeps_the_current_one(): void
    {
        Setting::current()->update(['mail_password' => 'original-pw']);

        $this->actingAs($this->admin())->withSession($this->stepup())
            ->put('/control/system', [
                'mail_mailer' => 'smtp', 'mail_host' => 'smtp.new.com', 'mail_port' => 587,
                'mail_encryption' => 'tls', 'mail_username' => 'u', 'mail_password' => '',
                'mail_from_address' => 'noreply@dmc-im.com', 'mail_from_name' => 'DMC',
                'app_timezone' => 'Asia/Riyadh', 'app_name' => 'DMC IM', 'app_url' => 'https://dmc-im.com',
            ])->assertRedirect();

        $s = Setting::query()->orderBy('id')->first();
        $this->assertSame('smtp.new.com', $s->mail_host);
        $this->assertSame('original-pw', $s->mail_password);
        $this->assertSame('Asia/Riyadh', $s->app_timezone);
    }

    public function test_update_stores_a_new_password_and_redacts_it_in_the_audit_trail(): void
    {
        $this->actingAs($this->admin())->withSession($this->stepup())
            ->put('/control/system', ['mail_password' => 'brand-new-pw', 'app_timezone' => 'UTC'])
            ->assertRedirect();

        $this->assertSame('brand-new-pw', Setting::query()->orderBy('id')->first()->mail_password);
        $row = DB::table('setting_changes')->where('field', 'mail_password')->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertStringNotContainsString('brand-new-pw', (string) $row->new_value);
    }

    public function test_update_validation_rejects_a_bad_timezone(): void
    {
        $this->actingAs($this->admin())->withSession($this->stepup())
            ->put('/control/system', ['app_timezone' => 'Mars/Olympus'])
            ->assertSessionHasErrors('app_timezone');
    }

    public function test_system_routes_require_a_fresh_step_up(): void
    {
        $this->actingAs($this->admin())
            ->put('/control/system', ['app_timezone' => 'UTC'])
            ->assertRedirect(route('stepup.show'));
    }

    public function test_test_email_reports_success(): void
    {
        Mail::fake();
        $resp = $this->actingAs($this->admin())->withSession($this->stepup())->post('/control/system/test-email');
        $resp->assertRedirect();
        $resp->assertSessionHas('flash', fn ($f) => ($f['type'] ?? null) === 'success');
    }

    public function test_a_validation_error_never_flashes_the_plaintext_password(): void
    {
        $this->actingAs($this->admin())->withSession($this->stepup())
            ->put('/control/system', ['app_url' => 'not-a-url', 'mail_password' => 'leaky-pw'])
            ->assertSessionHasErrors('app_url');
        $this->assertArrayNotHasKey('mail_password', (array) session('_old_input'));
    }
}
