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
}
