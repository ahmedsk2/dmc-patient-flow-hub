<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RuntimeConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_mail_password_is_stored_encrypted_and_round_trips(): void
    {
        $s = Setting::current();
        $s->update(['mail_password' => 's3cret-smtp-pw']);

        $raw = $s->getRawOriginal('mail_password');
        $this->assertNotSame('s3cret-smtp-pw', $raw);
        $this->assertNotEmpty($raw);

        $this->assertSame('s3cret-smtp-pw', Setting::query()->orderBy('id')->first()->mail_password);
    }

    public function test_non_null_db_values_override_config_env_defaults_are_kept(): void
    {
        config(['mail.mailers.smtp.host' => 'env-host', 'app.timezone' => 'UTC', 'app.name' => 'EnvName']);
        Setting::current()->update([
            'mail_host' => 'db-host', 'mail_port' => 2525, 'mail_encryption' => 'ssl',
            'mail_password' => 'pw', 'app_timezone' => 'Asia/Riyadh',
        ]);

        (new \App\Providers\RuntimeConfigServiceProvider($this->app))->boot();

        $this->assertSame('db-host', config('mail.mailers.smtp.host'));
        $this->assertSame(2525, config('mail.mailers.smtp.port'));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->assertSame('pw', config('mail.mailers.smtp.password'));
        $this->assertSame('Asia/Riyadh', config('app.timezone'));
        $this->assertSame('Asia/Riyadh', date_default_timezone_get());
        $this->assertSame('EnvName', config('app.name'));
    }

    public function test_boot_is_crash_proof_when_settings_row_is_empty(): void
    {
        config(['mail.mailers.smtp.host' => 'env-host']);
        (new \App\Providers\RuntimeConfigServiceProvider($this->app))->boot();
        $this->assertSame('env-host', config('mail.mailers.smtp.host'));
    }
}
