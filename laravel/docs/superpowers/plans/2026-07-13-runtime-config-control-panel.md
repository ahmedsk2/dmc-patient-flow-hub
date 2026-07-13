# Runtime Configuration in the Control Panel — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an admin change SMTP/email, timezone, and app basics (display name, app URL) from the Control Panel at runtime, with `.env` as the fallback — no redeploy/`config:cache` needed.

**Architecture:** Extend the single-row `settings` table with nullable columns (`.env` used when null). A boot-time `RuntimeConfigServiceProvider` reads the row and overrides `config()` for each non-null value. A new Control → **System** tab edits the values, sends a test email, and resets fields to default. The SMTP password is stored `encrypted`, is write-only in the UI, and is redacted in the audit trail. The update + test-email routes are admin-only and step-up gated.

**Tech Stack:** Laravel 13 (PHP 8.5 via `C:/wamp64/bin/php/php8.5.0/php.exe`; no Composer), Inertia 2, Vue 3 `<script setup>`, Tailwind v4. Tests: PHPUnit (two-pass `--exclude-group pdf` then `--group pdf`, isolate with `DB_DATABASE=dmc_test2`) + Vitest (`npx vitest run`). `public/build` is committed — rebuild with `npx vite build` after any `resources/` change; then refresh `node scripts/check-source-allowlist.mjs --write` and pass `node scripts/contrast.mjs`.

**Spec:** `docs/superpowers/specs/2026-07-13-runtime-config-control-panel-design.md`

---

## File Structure

| File | Responsibility | Create/Modify |
|---|---|---|
| `database/migrations/2026_07_13_000000_add_runtime_config_columns_to_settings_table.php` | Add nullable config columns to `settings` | Create |
| `app/Models/Setting.php` | Add `mail_password => encrypted` cast | Modify |
| `app/Providers/RuntimeConfigServiceProvider.php` | Boot-time DB→config override | Create |
| `bootstrap/providers.php` | Register the provider | Modify |
| `app/Http/Controllers/ControlController.php` | `index` `system`/`timezones` props; `updateSystem`; `testEmail` | Modify |
| `routes/web.php` | `PUT /control/system`, `POST /control/system/test-email` (both `stepup`) | Modify |
| `resources/js/Pages/Control/Index.vue` | System tab (Email / Localization / Application cards) | Modify |
| `tests/Feature/RuntimeConfigTest.php` | Provider override behavior | Create |
| `tests/Feature/ControlSystemTest.php` | `updateSystem` / `testEmail` / props / step-up / audit | Create |
| `resources/js/Pages/__tests__/ControlSystem.spec.js` | System tab renders + write-only password | Create |
| `docs/DEPLOY-LARAVEL.md` | Note: timezone/MAIL_* now in-app once set | Modify |

Each task ends with a commit. Run PHPUnit with the isolated DB, e.g.:
`DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe artisan test tests/Feature/RuntimeConfigTest.php`

---

## Task 1: Migration + encrypted cast

**Files:**
- Create: `database/migrations/2026_07_13_000000_add_runtime_config_columns_to_settings_table.php`
- Modify: `app/Models/Setting.php:11`
- Test: `tests/Feature/RuntimeConfigTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/RuntimeConfigTest.php`:

```php
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

        // the raw column is ciphertext, not the plaintext
        $raw = $s->getRawOriginal('mail_password');
        $this->assertNotSame('s3cret-smtp-pw', $raw);
        $this->assertNotEmpty($raw);

        // the cast decrypts on read
        $this->assertSame('s3cret-smtp-pw', Setting::query()->orderBy('id')->first()->mail_password);
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe artisan test tests/Feature/RuntimeConfigTest.php`
Expected: FAIL — `mail_password` column does not exist.

- [ ] **Step 3: Create the migration**

`database/migrations/2026_07_13_000000_add_runtime_config_columns_to_settings_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $t) {
            $t->string('mail_mailer', 20)->nullable();
            $t->string('mail_host', 255)->nullable();
            $t->unsignedSmallInteger('mail_port')->nullable();
            $t->string('mail_encryption', 10)->nullable();
            $t->string('mail_username', 255)->nullable();
            $t->text('mail_password')->nullable();          // encrypted (see Setting::$casts)
            $t->string('mail_from_address', 255)->nullable();
            $t->string('mail_from_name', 255)->nullable();
            $t->string('app_timezone', 64)->nullable();
            $t->string('app_name', 120)->nullable();
            $t->string('app_url', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $t) {
            $t->dropColumn([
                'mail_mailer', 'mail_host', 'mail_port', 'mail_encryption', 'mail_username',
                'mail_password', 'mail_from_address', 'mail_from_name',
                'app_timezone', 'app_name', 'app_url',
            ]);
        });
    }
};
```

- [ ] **Step 4: Add the `encrypted` cast**

In `app/Models/Setting.php`, change the `$casts` line (currently `protected $casts = ['log_record_opens' => 'boolean'];`) to:

```php
    protected $casts = ['log_record_opens' => 'boolean', 'mail_password' => 'encrypted'];
```

- [ ] **Step 5: Run the test — it passes**

Run: `DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe artisan test tests/Feature/RuntimeConfigTest.php`
Expected: PASS (1 test).

- [ ] **Step 6: Commit**

```bash
git add laravel/database/migrations/2026_07_13_000000_add_runtime_config_columns_to_settings_table.php laravel/app/Models/Setting.php laravel/tests/Feature/RuntimeConfigTest.php
git commit -m "feat(config): add runtime-config columns to settings (encrypted mail_password)"
```

---

## Task 2: RuntimeConfigServiceProvider (DB → config override)

**Files:**
- Create: `app/Providers/RuntimeConfigServiceProvider.php`
- Modify: `bootstrap/providers.php`
- Test: `tests/Feature/RuntimeConfigTest.php` (extend)

- [ ] **Step 1: Add failing tests**

Append to `tests/Feature/RuntimeConfigTest.php` (inside the class):

```php
    public function test_non_null_db_values_override_config_env_defaults_are_kept(): void
    {
        config(['mail.mailers.smtp.host' => 'env-host', 'app.timezone' => 'UTC', 'app.name' => 'EnvName']);
        Setting::current()->update([
            'mail_host' => 'db-host', 'mail_port' => 2525, 'mail_encryption' => 'ssl',
            'mail_password' => 'pw', 'app_timezone' => 'Asia/Riyadh',
            // app_name left null -> env kept
        ]);

        (new \App\Providers\RuntimeConfigServiceProvider($this->app))->boot();

        $this->assertSame('db-host', config('mail.mailers.smtp.host'));
        $this->assertSame(2525, config('mail.mailers.smtp.port'));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));   // ssl -> smtps
        $this->assertSame('pw', config('mail.mailers.smtp.password'));    // decrypted
        $this->assertSame('Asia/Riyadh', config('app.timezone'));
        $this->assertSame('Asia/Riyadh', date_default_timezone_get());
        $this->assertSame('EnvName', config('app.name'));                 // null column -> env kept
    }

    public function test_boot_is_crash_proof_when_settings_row_is_empty(): void
    {
        // no columns set — provider must not throw and must leave config untouched
        config(['mail.mailers.smtp.host' => 'env-host']);
        (new \App\Providers\RuntimeConfigServiceProvider($this->app))->boot();
        $this->assertSame('env-host', config('mail.mailers.smtp.host'));
    }
```

- [ ] **Step 2: Run and confirm failure**

Run: `DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe artisan test tests/Feature/RuntimeConfigTest.php`
Expected: FAIL — `Class "App\Providers\RuntimeConfigServiceProvider" not found`.

- [ ] **Step 3: Create the provider**

`app/Providers/RuntimeConfigServiceProvider.php`:

```php
<?php

namespace App\Providers;

use App\Models\Setting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/**
 * Overrides Laravel config from the single-row `settings` table at boot, so an admin can change
 * SMTP / timezone / app basics from the Control Panel without a redeploy. `.env` remains the
 * fallback: only NON-NULL columns override. Fail-safe — any read/decrypt error leaves `.env` in
 * force (never bricks a request). Runs on every request, so it works with or without config:cache.
 */
class RuntimeConfigServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Fresh DB / mid-migration: the table may not exist yet.
        if (! Schema::hasTable('settings')) {
            return;
        }

        try {
            $s = Setting::current();
        } catch (\Throwable) {
            return;   // unreadable settings row -> keep .env
        }

        $set = function (string $key, $val): void {
            if (filled($val)) {
                config([$key => $val]);
            }
        };

        if (filled($s->mail_mailer)) {
            config(['mail.default' => $s->mail_mailer]);
        }
        $set('mail.mailers.smtp.host', $s->mail_host);
        if ($s->mail_port !== null) {
            config(['mail.mailers.smtp.port' => (int) $s->mail_port]);
        }
        if (filled($s->mail_encryption)) {
            config(['mail.mailers.smtp.scheme' => $s->mail_encryption === 'ssl' ? 'smtps' : 'smtp']);
        }
        $set('mail.mailers.smtp.username', $s->mail_username);

        // password access can throw if the ciphertext is corrupt — isolate so a bad password never
        // disables the OTHER overrides (host/timezone still apply; mail falls back to .env password).
        try {
            $pw = $s->mail_password;
        } catch (\Throwable) {
            $pw = null;
        }
        if (filled($pw)) {
            config(['mail.mailers.smtp.password' => $pw]);
        }

        $set('mail.from.address', $s->mail_from_address);
        $set('mail.from.name', $s->mail_from_name);

        if (filled($s->app_timezone)) {
            config(['app.timezone' => $s->app_timezone]);
            date_default_timezone_set($s->app_timezone);
        }
        $set('app.name', $s->app_name);
        $set('app.url', $s->app_url);
    }
}
```

- [ ] **Step 4: Register the provider**

In `bootstrap/providers.php`, add the provider so the array reads:

```php
<?php

use App\Providers\AppServiceProvider;
use App\Providers\RuntimeConfigServiceProvider;

return [
    AppServiceProvider::class,
    RuntimeConfigServiceProvider::class,
];
```

- [ ] **Step 5: Run tests — pass**

Run: `DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe artisan test tests/Feature/RuntimeConfigTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add laravel/app/Providers/RuntimeConfigServiceProvider.php laravel/bootstrap/providers.php laravel/tests/Feature/RuntimeConfigTest.php
git commit -m "feat(config): boot-time RuntimeConfigServiceProvider overrides config from DB (.env fallback)"
```

---

## Task 3: Controller — `updateSystem`, `testEmail`, index props + routes

**Files:**
- Modify: `app/Http/Controllers/ControlController.php` (index props ~line 38-67; add two methods)
- Modify: `routes/web.php:217` (add two routes near `control.settings`)
- Test: `tests/Feature/ControlSystemTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ControlSystemTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ]);
    }

    /** a fresh step-up window so step-up-gated routes proceed */
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
            ->missing('system.mail_password'));   // the value is NEVER shipped
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
        $this->assertSame('original-pw', $s->mail_password);   // blank submit kept the old password
        $this->assertSame('Asia/Riyadh', $s->app_timezone);
    }

    public function test_update_stores_a_new_password_and_redacts_it_in_the_audit_trail(): void
    {
        $this->actingAs($this->admin())->withSession($this->stepup())
            ->put('/control/system', ['mail_password' => 'brand-new-pw', 'app_timezone' => 'UTC'])
            ->assertRedirect();

        $this->assertSame('brand-new-pw', Setting::query()->orderBy('id')->first()->mail_password);
        // the setting_changes history never stores the secret
        $row = \DB::table('setting_changes')->where('field', 'mail_password')->latest('id')->first();
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
        // no step-up in session -> the middleware bounces to the step-up screen
        $this->actingAs($this->admin())
            ->put('/control/system', ['app_timezone' => 'UTC'])
            ->assertRedirect(route('stepup.show'));
    }

    public function test_test_email_sends_to_the_admin(): void
    {
        Mail::fake();
        $this->actingAs($this->admin())->withSession($this->stepup())
            ->post('/control/system/test-email')->assertRedirect();
        Mail::assertSent(fn (\Illuminate\Mail\Mailable $m = null) => true);   // see note below
    }
}
```

> Note on the test-email assertion: `Mail::raw` is asserted with `Mail::assertSent`? `Mail::raw` uses a `Illuminate\Mail\Message`, not a Mailable, so with `Mail::fake()` assert via `Mail::assertSent(\Illuminate\Mail\Mailable::class)` is wrong. Use the raw-safe form: replace that last line with
> `\Illuminate\Support\Facades\Mail::assertSent(\Illuminate\Mail\Mailable::class);` **only if** `testEmail` uses a Mailable. This plan uses `Mail::raw`, which is recorded by `Mail::fake()` — assert with:
> `$this->assertTrue(true);` is NOT acceptable. Instead assert the flash: `->assertSessionHas('flash.type', 'success')` on the response. Rewrite the last test to:

```php
    public function test_test_email_reports_success(): void
    {
        Mail::fake();
        $resp = $this->actingAs($this->admin())->withSession($this->stepup())->post('/control/system/test-email');
        $resp->assertRedirect();
        $resp->assertSessionHas('flash', fn ($f) => ($f['type'] ?? null) === 'success');
    }
```

(Delete the `test_test_email_sends_to_the_admin` version; keep `test_test_email_reports_success`.)

- [ ] **Step 2: Run and confirm failure**

Run: `DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe artisan test tests/Feature/ControlSystemTest.php`
Expected: FAIL — routes/methods absent (404 / missing `system` prop).

- [ ] **Step 3: Add the two routes**

In `routes/web.php`, immediately after the `control.settings` route (line 217), add:

```php
        // Runtime configuration (SMTP / timezone / app basics) — sensitive, so step-up gated like
        // destroyUser. The RuntimeConfigServiceProvider applies these over .env on the next request.
        Route::put('/control/system', [ControlController::class, 'updateSystem'])->name('control.system')->middleware('stepup');
        Route::post('/control/system/test-email', [ControlController::class, 'testEmail'])->name('control.system.testEmail')->middleware('stepup');
```

- [ ] **Step 4: Add the `system` + `timezones` props to `index`**

In `ControlController::index`, capture the settings row once and add two render keys. After the
`$users = ...` block, add `$s = Setting::current();`. Then inside the `Inertia::render('Control/Index', [...])`
array (next to `'settings' => Setting::current(),`) add:

```php
            'system' => [
                'mail_mailer' => $s->mail_mailer, 'mail_host' => $s->mail_host, 'mail_port' => $s->mail_port,
                'mail_encryption' => $s->mail_encryption, 'mail_username' => $s->mail_username,
                // NEVER ship the value — only whether one is set (getRawOriginal avoids a decrypt)
                'mail_password_set' => filled($s->getRawOriginal('mail_password')),
                'mail_from_address' => $s->mail_from_address, 'mail_from_name' => $s->mail_from_name,
                'app_timezone' => $s->app_timezone, 'app_name' => $s->app_name, 'app_url' => $s->app_url,
            ],
            'timezones' => timezone_identifiers_list(),
```

(You may reuse `$s` for `'settings' => $s` too, but leaving `Setting::current()` is fine — it is memoized per request.)

- [ ] **Step 5: Add `updateSystem` + `testEmail` methods**

Add these methods to `ControlController` (after `updateSettings`). Ensure the file imports
`use Illuminate\Support\Facades\Mail;` (add it to the top `use` block).

```php
    public function updateSystem(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'mail_mailer' => ['nullable', 'in:smtp,log'],
            'mail_host' => ['nullable', 'required_if:mail_mailer,smtp', 'string', 'max:255'],
            'mail_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'mail_encryption' => ['nullable', 'in:tls,ssl,none'],
            'mail_username' => ['nullable', 'string', 'max:255'],
            'mail_password' => ['nullable', 'string', 'max:255'],
            'mail_from_address' => ['nullable', 'email', 'max:255'],
            'mail_from_name' => ['nullable', 'string', 'max:120'],
            'app_timezone' => ['nullable', Rule::in(timezone_identifiers_list())],
            'app_name' => ['nullable', 'string', 'max:120'],
            'app_url' => ['nullable', 'url', 'max:255'],
        ]);

        // write-only password: a blank/absent submit KEEPS the current value.
        if (! filled($data['mail_password'] ?? null)) {
            unset($data['mail_password']);
        }

        $settings = Setting::current();

        // append-only history, mirroring updateSettings — but the password value is REDACTED.
        foreach ($data as $field => $new) {
            $old = $settings->{$field};
            if ((string) $old === (string) $new) {
                continue;
            }
            $redact = $field === 'mail_password';
            DB::table('setting_changes')->insert([
                'field' => $field,
                'old_value' => $redact ? '••••' : ($old === null ? null : (string) $old),
                'new_value' => $redact ? '••••' : (string) $new,
                'changed_by' => Auth::id(),
                'created_at' => now(),
            ]);
        }

        $settings->update($data);

        // audit detail never carries the secret
        $detail = collect($data)->except('mail_password')->all();
        if (array_key_exists('mail_password', $data)) {
            $detail['mail_password'] = 'changed';
        }
        Audit::log('settings.system.update', 'settings', '1', $detail);

        return back()->with('flash', ['type' => 'success', 'message' => 'System configuration saved.']);
    }

    public function testEmail(Request $request): RedirectResponse
    {
        $to = $request->user()->email;
        if (! $to) {
            return back()->with('flash', ['type' => 'error', 'message' => 'Your account has no email address to send a test to.']);
        }

        // The RuntimeConfigServiceProvider already applied the saved SMTP config for this request.
        try {
            Mail::raw('This is a test email from the DMC Internal Medicine Control Panel. If you received it, your mail settings are working.',
                fn ($m) => $m->to($to)->subject('DMC — test email'));
        } catch (\Throwable $e) {
            return back()->with('flash', ['type' => 'error', 'message' => 'Test email failed: '.$e->getMessage()]);
        }

        return back()->with('flash', ['type' => 'success', 'message' => "Test email sent to {$to}."]);
    }
```

- [ ] **Step 6: Run the tests — pass**

Run: `DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe artisan test tests/Feature/ControlSystemTest.php`
Expected: PASS (6 tests).

- [ ] **Step 7: Commit**

```bash
git add laravel/app/Http/Controllers/ControlController.php laravel/routes/web.php laravel/tests/Feature/ControlSystemTest.php
git commit -m "feat(config): Control updateSystem + testEmail (step-up gated, password write-only + redacted)"
```

---

## Task 4: Control → System tab (Vue)

**Files:**
- Modify: `resources/js/Pages/Control/Index.vue` (props, `controlTabs`, `sysForm`, the panel)
- Test: `resources/js/Pages/__tests__/ControlSystem.spec.js`

- [ ] **Step 1: Write the failing Vitest spec**

Create `resources/js/Pages/__tests__/ControlSystem.spec.js`:

```js
import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', () => ({
    router: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn(), visit: vi.fn() },
    useForm: (obj) => ({ ...obj, put: vi.fn(), post: vi.fn(), delete: vi.fn(), reset: vi.fn(), clearErrors: vi.fn(), defaults: vi.fn(), errors: {}, processing: false, isDirty: false }),
}));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { name: 'AppLayout', props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' } }));
vi.mock('@/Components/Tabs.vue', () => ({ default: { name: 'Tabs', props: ['tabs', 'modelValue'], template: "<div><slot :active=\"'system'\" /></div>" } }));
vi.mock('@/Components/BaseModal.vue', () => ({ default: { name: 'BaseModal', props: ['open', 'title', 'subtitle', 'size', 'closable', 'dirty'], template: '<div v-if="open"><slot /></div>' } }));

import Control from '@/Pages/Control/Index.vue';

const settings = { min_hospitalist: 6, max_hospitalist: 30, min_subs: 7, max_subs: 7, short_los: 5, long_los: 11 };
const base = {
    settings, users: [], roles: { 0: 'Admin' }, counts: { users: 0, active_users: 0, patients: 0, admissions: 0, consultations: 0, icd10: 0, specialties: 0 },
    specialties: [], reasons: [], settingHistory: [], reportRecipients: [],
    timezones: ['UTC', 'Asia/Riyadh'],
};
const mountControl = (system) => mount(Control, { props: { ...base, system } });

describe('Control — System tab', () => {
    it('renders the three cards + a write-only password showing "Set" when one exists', () => {
        const w = mountControl({ mail_mailer: 'smtp', mail_host: 'smtp.x.com', mail_port: 587, mail_encryption: 'tls', mail_username: 'u', mail_password_set: true, mail_from_address: 'a@b.com', mail_from_name: 'DMC', app_timezone: 'UTC', app_name: 'DMC', app_url: 'https://x' });
        expect(w.text()).toContain('Email');
        expect(w.text()).toContain('Localization');
        expect(w.text()).toContain('Application');
        // password field is present, write-only (a placeholder, not the value), and flags "Set"
        const pw = w.find('input[aria-label="SMTP password"]');
        expect(pw.exists()).toBe(true);
        expect(pw.attributes('type')).toBe('password');
        expect(pw.element.value).toBe('');           // never pre-filled with the stored secret
        expect(w.text()).toContain('Set');
        // timezone select carries the server list
        expect(w.findAll('option').some((o) => o.text() === 'Asia/Riyadh')).toBe(true);
        // a Send test email action exists
        expect(w.findAll('button').some((b) => b.text().includes('Send test email'))).toBe(true);
    });
});
```

- [ ] **Step 2: Run and confirm failure**

Run: `cd laravel && npx vitest run resources/js/Pages/__tests__/ControlSystem.spec.js`
Expected: FAIL — no System tab / no such elements.

- [ ] **Step 3: Wire the tab + form in `Control/Index.vue`**

1. Add `system` + `timezones` to `defineProps` (line 13). The declaration becomes:

```js
const props = defineProps({ settings: Object, users: { type: Array, default: () => [] }, roles: Object, counts: Object, specialties: Array, reasons: Array, settingHistory: Array, reportRecipients: { type: Array, default: () => [] }, system: { type: Object, default: () => ({}) }, timezones: { type: Array, default: () => [] } });
```

2. Add `System` to `controlTabs` (after the `users` entry):

```js
    { id: 'users', label: 'Users' },
    { id: 'system', label: 'System' },
    { id: 'reference', label: 'Reference' },
```

3. Add the form + save/test handlers (near the other `useForm`s):

```js
// System (runtime config). Password is write-only: '' means "keep the current one".
const sysForm = useForm({
    mail_mailer: props.system.mail_mailer ?? 'log',
    mail_host: props.system.mail_host ?? '', mail_port: props.system.mail_port ?? '',
    mail_encryption: props.system.mail_encryption ?? 'tls', mail_username: props.system.mail_username ?? '',
    mail_password: '',
    mail_from_address: props.system.mail_from_address ?? '', mail_from_name: props.system.mail_from_name ?? '',
    app_timezone: props.system.app_timezone ?? '', app_name: props.system.app_name ?? '', app_url: props.system.app_url ?? '',
});
const saveSystem = guardSubmit(sysForm, () => sysForm.put('/control/system', { preserveScroll: true }));
const sendTestEmail = () => router.post('/control/system/test-email', {}, { preserveScroll: true });
```

- [ ] **Step 4: Add the System panel markup**

Add a `<div v-show="active === 'system'">` panel (place it just before the `<!-- Reference data -->`
block, inside the `#default` slot). Use `:class="field"` for inputs to match the page. Complete markup:

```html
        <!-- System (runtime config) -->
        <div v-show="active === 'system'" class="grid gap-5 lg:grid-cols-2">
            <!-- Email -->
            <form @submit.prevent="saveSystem" class="rounded-2xl bg-card p-6 shadow-card ring-1 ring-line lg:col-span-2">
                <h3 class="mb-1 font-bold text-ink-800">Email</h3>
                <p class="mb-4 text-sm text-ink-400">SMTP delivery. Set <strong>Log</strong> to capture mail without sending. Blank the password to keep the current one.</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">Mailer</span>
                        <select v-model="sysForm.mail_mailer" aria-label="Mailer" :class="field"><option value="smtp">SMTP (send)</option><option value="log">Log (don't send)</option></select></label>
                    <label class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">Host</span><input v-model="sysForm.mail_host" aria-label="SMTP host" :class="field" placeholder="smtp.example.com" /></label>
                    <label class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">Port</span><input v-model="sysForm.mail_port" type="number" min="1" max="65535" aria-label="SMTP port" :class="field" placeholder="587" /></label>
                    <label class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">Encryption</span>
                        <select v-model="sysForm.mail_encryption" aria-label="SMTP encryption" :class="field"><option value="tls">TLS</option><option value="ssl">SSL</option><option value="none">None</option></select></label>
                    <label class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">Username</span><input v-model="sysForm.mail_username" aria-label="SMTP username" :class="field" /></label>
                    <label class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">Password <span v-if="system.mail_password_set" class="ml-1 rounded-full bg-tint-success px-1.5 py-0.5 text-[10px] font-semibold text-on-success">Set</span></span>
                        <input v-model="sysForm.mail_password" type="password" autocomplete="new-password" aria-label="SMTP password" :class="field" :placeholder="system.mail_password_set ? 'Leave blank to keep current' : 'Not set'" /></label>
                    <label class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">From address</span><input v-model="sysForm.mail_from_address" type="email" aria-label="From address" :class="field" placeholder="noreply@dmc-im.com" /></label>
                    <label class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">From name</span><input v-model="sysForm.mail_from_name" aria-label="From name" :class="field" placeholder="DMC Internal Medicine" /></label>
                </div>
                <p v-if="sysForm.errors.mail_host || sysForm.errors.mail_from_address" class="mt-2 text-xs text-on-danger">{{ sysForm.errors.mail_host || sysForm.errors.mail_from_address }}</p>
                <div class="mt-4 flex items-center gap-2">
                    <button type="submit" :disabled="sysForm.processing" class="rounded-xl bg-brand-solid px-5 py-2 text-sm font-semibold text-white hover:bg-brand-solid-hover disabled:opacity-50">Save system config</button>
                    <button type="button" @click="sendTestEmail" class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-600 ring-1 ring-line transition hover:bg-ink-50">Send test email</button>
                </div>
            </form>

            <!-- Localization -->
            <div class="rounded-2xl bg-card p-6 shadow-card ring-1 ring-line">
                <h3 class="mb-1 font-bold text-ink-800">Localization</h3>
                <p class="mb-4 text-sm text-ink-400">The application timezone (dates + times across the app).</p>
                <label class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">Timezone</span>
                    <select v-model="sysForm.app_timezone" aria-label="Timezone" :class="field"><option value="">Use server default (.env)</option><option v-for="tz in timezones" :key="tz" :value="tz">{{ tz }}</option></select></label>
                <p v-if="sysForm.errors.app_timezone" class="mt-1 text-xs text-on-danger">{{ sysForm.errors.app_timezone }}</p>
            </div>

            <!-- Application -->
            <div class="rounded-2xl bg-card p-6 shadow-card ring-1 ring-line">
                <h3 class="mb-1 font-bold text-ink-800">Application</h3>
                <p class="mb-4 text-sm text-ink-400">Display name + the URL used in emails.</p>
                <label class="mb-3 block"><span class="mb-1 block text-sm font-semibold text-ink-700">Display name</span><input v-model="sysForm.app_name" aria-label="Display name" :class="field" placeholder="DMC Internal Medicine" /></label>
                <label class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">App URL</span><input v-model="sysForm.app_url" aria-label="App URL" :class="field" placeholder="https://dmc-im.com" /></label>
                <p v-if="sysForm.errors.app_url" class="mt-1 text-xs text-on-danger">{{ sysForm.errors.app_url }}</p>
            </div>
        </div>
```

> Reset-to-default: an empty field submitted for `app_timezone`/`app_name`/`app_url` (and blank mail
> fields) already means "use `.env`" server-side (validation is `nullable`; blanks store `null` via the
> model when the value is `''`). No separate control is needed — the "Use server default (.env)" option
> and clearing a field ARE the reset. (Note in the UI copy above.)

- [ ] **Step 5: Run the Vitest spec — pass**

Run: `cd laravel && npx vitest run resources/js/Pages/__tests__/ControlSystem.spec.js`
Expected: PASS (1 test).

- [ ] **Step 6: Confirm the existing Control tabs spec still passes**

Run: `cd laravel && npx vitest run resources/js/Pages/__tests__/ControlIndex.tabs.test.js`
Expected: PASS — the four-tab assertion becomes five; **update that assertion** in `ControlIndex.tabs.test.js`:
change `['Overview', 'Settings', 'Users', 'Reference']` to `['Overview', 'Settings', 'Users', 'System', 'Reference']` (the `system` prop defaults to `{}` so no extra fixture is needed).

- [ ] **Step 7: Commit**

```bash
git add laravel/resources/js/Pages/Control/Index.vue laravel/resources/js/Pages/__tests__/ControlSystem.spec.js laravel/resources/js/Pages/__tests__/ControlIndex.tabs.test.js
git commit -m "feat(config): Control System tab — Email / Localization / Application cards + test email"
```

---

## Task 5: Full verification, build, gates, docs

**Files:**
- Modify: `docs/DEPLOY-LARAVEL.md`
- Rebuild: `public/build/**`, refresh `scripts/` allowlist snapshot

- [ ] **Step 1: Rebuild committed assets**

Run: `cd laravel && npx vite build`
Expected: `✓ built`.

- [ ] **Step 2: Refresh + verify the Tailwind allowlist**

Run: `cd laravel && node scripts/check-source-allowlist.mjs --write && node scripts/check-source-allowlist.mjs`
Expected: `PASS (no drift)`.

- [ ] **Step 3: Contrast gate**

Run: `cd laravel && node scripts/contrast.mjs`
Expected: `contrast gate: PASS (exit 0)`.

- [ ] **Step 4: Full Vitest**

Run: `cd laravel && npx vitest run`
Expected: all files pass (existing + the new `ControlSystem.spec.js`).

- [ ] **Step 5: Two-pass PHPUnit (isolated DB)**

Run:
```
cd laravel
DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe artisan test --exclude-group pdf
DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe artisan test --group pdf
```
Expected: green both passes (the one known capnography red does not apply here; investigate any NEW red).

- [ ] **Step 6: Update the deploy doc**

In `docs/DEPLOY-LARAVEL.md`, add under the config/ops section:

```markdown
### Runtime configuration (Control Panel → System)
Timezone and mail (`MAIL_*`) can now be set in-app (Control Panel → **System**) and take effect on
the next request — no `.env` edit or `config:cache` needed once a value is set. `.env` remains the
fallback for any field left blank. The SMTP password is stored encrypted (AES-256 via `APP_KEY`).
Bootstrap config still lives ONLY in `.env`: `APP_KEY`, the DB credentials, `APP_ENV`/`APP_DEBUG`.
```

- [ ] **Step 7: Commit the build + docs**

```bash
git add laravel/public/build laravel/scripts laravel/docs/DEPLOY-LARAVEL.md
git commit -m "chore(config): rebuild assets + allowlist snapshot + deploy note for runtime config"
```

---

## Self-review notes (for the executor)

- **Spec coverage:** storage (T1), runtime override (T2), controller+routes+test-email+props (T3), UI (T4), security/validation are enforced in T3 (encrypted cast T1, write-only + redaction + step-up + validation in T3), backward-compat (nullable migration T1), testing (T1–T4), deploy note (T5). All spec sections map to a task.
- **Encryption mapping:** `mail_encryption` `ssl → scheme smtps`, otherwise `smtp` (STARTTLS-capable). Consistent in T2 provider + T4 UI options (tls/ssl/none).
- **Password contract:** stored encrypted (T1 cast) → provider decrypts (T2) → controller keeps-on-blank + redacts (T3) → prop is `mail_password_set` bool via `getRawOriginal` (T3) → UI write-only (T4). One contract, threaded through every layer.
- **Step-up:** route middleware `stepup` (T3), tested via `withSession(['stepup.verified_at' => …])` (mirrors `WaveBTest` reverse-discharge) and the no-session redirect-to-`stepup.show` case.
```
