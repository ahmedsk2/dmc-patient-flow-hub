<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Patient;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Wave-A hardening guards: login rate-limiting (brute-force), per-request settings memoization,
 * and the length-of-stay integer cast.
 */
class HardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_is_rate_limited(): void
    {
        $statuses = [];
        for ($i = 0; $i < 6; $i++) {
            $statuses[] = $this->post('/login', ['username' => 'nobody', 'password' => 'wrong'])->getStatusCode();
        }
        // throttle:5,1 → the 6th attempt within the minute is blocked
        $this->assertSame(429, $statuses[5], 'login should be throttled after 5 attempts');
    }

    public function test_setting_current_is_memoized_per_request(): void
    {
        Setting::current(); // prime
        DB::enableQueryLog();
        $a = Setting::current();
        $b = Setting::current();
        $c = Setting::current();
        $settingSelects = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'settings'))->count();

        $this->assertSame(0, $settingSelects, 'repeat Setting::current() must not re-query within a request');
        $this->assertTrue($a === $b && $b === $c, 'Setting::current() must return the same memoized instance');
    }

    public function test_length_of_stay_is_a_whole_integer(): void
    {
        $p = Patient::create(['mrn' => '90000001', 'name' => 'LOS Patient']);
        $a = Admission::create([
            'patient_id' => $p->id, 'admit_date' => '2024-01-01', 'discharge_date' => '2024-01-06',
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0,
        ]);
        $los = $a->lengthOfStay();
        $this->assertIsInt($los);
        $this->assertSame(5, $los);
    }
}
