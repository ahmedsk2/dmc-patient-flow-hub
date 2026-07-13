# Runtime configuration in the Control Panel — design

**Date:** 2026-07-13
**Status:** Approved (design) — ready for an implementation plan
**App:** DMC Internal Medicine (Laravel 13 + Inertia 2 + Vue 3 + Tailwind v4, `laravel/`)

## Goal

Let an admin change a curated set of configuration values **from the Control Panel at runtime**,
instead of editing `.env` and redeploying (`config:cache`). First targets: **SMTP/email**,
**timezone**, and a few **application basics** (display name, app URL).

## Decisions (locked with the owner)

1. **Scope = SMTP + Timezone + app basics.** Not a generic "edit any env value" editor.
2. **The SMTP password is stored encrypted in the database** (`encrypted` cast, AES‑256 via
   `APP_KEY`). It is **write‑only** in the UI and **redacted** in the audit trail.
3. **Bootstrap/infra config stays in `.env`** and is explicitly out of scope: `APP_KEY`, the
   database credentials, `APP_ENV`/`APP_DEBUG`, and the session/cache/queue drivers. These bootstrap
   the app or decrypt everything else — they cannot safely live in the database they protect.

## Approach (chosen)

**Extend the existing single‑row `settings` table + a boot‑time config override.** Reuses the
proven `Setting` model + Control → Settings pattern. Rejected alternatives: a generic `app_configs`
key/value table (more machinery, invites scope‑creep) and rewriting the real `.env` file from a web
request (fragile, unsafe, can't encrypt, breaks under `config:cache`).

**Layering model:** `.env` is the default/fallback. A saved (non‑null) Control‑Panel value **overrides**
it at runtime. Clearing a field ("Reset to default") nulls the column and returns to the `.env` value.

## Architecture

Three units, each independently understandable:

1. **Storage** — nullable columns on `settings` (the existing single row). `null` = "use `.env`".
2. **`RuntimeConfig` service provider** — on boot, reads the settings row and, for each non‑null
   value, overrides the live Laravel config. Pure read → `config()->set()`; no side effects beyond
   config + `date_default_timezone_set()`.
3. **Control → System tab (UI + controller)** — edit the values, send a test email, reset to default.

### 1. Storage — migration (additive, reversible)

New nullable columns on `settings` (all default `NULL` ⇒ behavior unchanged until an admin sets one):

| Column | Type | Notes |
|---|---|---|
| `mail_mailer` | string(20) nullable | `smtp` or `log` (`log` = safe "don't actually send") |
| `mail_host` | string(255) nullable | |
| `mail_port` | unsignedSmallInteger nullable | 1–65535 |
| `mail_encryption` | string(10) nullable | `tls` \| `ssl` \| `none` (mapped to the Symfony scheme) |
| `mail_username` | string(255) nullable | |
| `mail_password` | text nullable | **`encrypted` cast** |
| `mail_from_address` | string(255) nullable | |
| `mail_from_name` | string(255) nullable | |
| `app_timezone` | string(64) nullable | validated against `timezone_identifiers_list()` |
| `app_name` | string(120) nullable | |
| `app_url` | string(255) nullable | used in email links |

`Setting` model: add `'mail_password' => 'encrypted'` to `$casts`. (`$guarded = ['id']` already allows
mass assignment of the rest.)

### 2. Runtime override — `App\Providers\RuntimeConfigServiceProvider`

`boot()` reads `Setting::current()` once and applies **only non‑null** values:

| Setting column | Config key(s) set |
|---|---|
| `mail_mailer` | `mail.default` |
| `mail_host` | `mail.mailers.smtp.host` |
| `mail_port` | `mail.mailers.smtp.port` |
| `mail_encryption` | `mail.mailers.smtp.scheme` (`ssl`→`smtps`, else `smtp`) |
| `mail_username` | `mail.mailers.smtp.username` |
| `mail_password` | `mail.mailers.smtp.password` (decrypted by the cast) |
| `mail_from_address` | `mail.from.address` |
| `mail_from_name` | `mail.from.name` |
| `app_timezone` | `app.timezone` **and** `date_default_timezone_set()` |
| `app_name` | `app.name` |
| `app_url` | `app.url` |

Key properties:
- Runs on **every request after config load**, so it works whether or not `config:cache` has run —
  removing the need to re‑cache config after a change.
- **Crash‑proof:** wrapped so a missing `settings` table (fresh DB, pre‑migrate) or any read error is
  swallowed and the app falls back to `.env`. Guard with `Schema::hasTable('settings')` +
  `try/catch`. Skips entirely when running migrations/before the table exists.
- Timezone override in `boot()` runs after Laravel's own early timezone bootstrap, but
  `date_default_timezone_set()` + `config(app.timezone)` take effect for all request‑time
  `now()`/Carbon usage (the only place it matters here).

### 3. Control → System tab (UI + controller)

New **System** tab in `Control/Index.vue` (sibling of Overview / Settings / Users / Reference),
three cards. Server data comes from `ControlController::index` (add a `system` prop built from the
settings row, with the password **never included** — only a boolean `mail_password_set`).

- **Email** card: `mail_mailer` (SMTP vs Log toggle), host, port, encryption (tls/ssl/none),
  username, **password** (write‑only: input placeholder "Leave blank to keep the current password",
  shows "Set" when one exists), from‑address, from‑name, and a **"Send test email"** button.
- **Localization** card: timezone `<select>` (options from the server's `timezone_identifiers_list()`).
- **Application** card: display name, app URL.

Each field group has a **"Reset to default"** that nulls the column(s). Save posts to
`POST /control/system` (new controller method `updateSystem`).

**Write‑only password rule:** on save, if `mail_password` submitted is blank/absent → keep the stored
value unchanged; if non‑blank → re‑encrypt and store. The stored password is never sent to the client.

## Security

- **Encryption:** `mail_password` uses the `encrypted` cast (AES‑256‑CBC via `APP_KEY`).
- **Never echoed:** the Inertia `system` prop exposes `mail_password_set: bool` only, never the value.
- **Audit:** reuse the existing append‑only `setting_changes` history / `Audit`. `mail_password`
  changes log a **redacted marker** ("mail_password: changed"), never old/new values.
- **Access:** Control is already admin‑only. The `/control/system` update **and** the test‑email
  route are additionally wrapped in the existing **step‑up re‑auth** (`stepup` middleware), matching
  how other sensitive admin actions are gated.
- **Safe default:** `mail_mailer = log` means "capture, don't send" — a foot‑gun‑free off switch.

## Validation (server‑side, `updateSystem`)

- `mail_mailer` ∈ {`smtp`,`log`}; `mail_host` required when mailer is `smtp`.
- `mail_port` integer 1–65535; `mail_encryption` ∈ {`tls`,`ssl`,`none`}.
- `mail_username` nullable string; `mail_password` nullable string (blank = keep current).
- `mail_from_address` a valid email; `mail_from_name` string ≤ 120.
- `app_timezone` ∈ `timezone_identifiers_list()`; `app_name` string ≤ 120; `app_url` a valid URL.
- Any invalid field → validation errors back to the form (no partial apply).

## Test email — `POST /control/system/test-email`

Applies the **currently saved** SMTP config and sends a minimal Mailable ("DMC test email") to the
acting admin's own email. Returns a flash: success, or failure **with the transport exception
message** so misconfig is diagnosable. Lets an admin verify before relying on it. Step‑up gated.

## Data flow

1. Admin edits System card → `POST /control/system` (validated) → settings row updated (password
   re‑encrypted only if provided) → audit row (password redacted) → redirect back with flash.
2. Next request → `RuntimeConfigServiceProvider::boot()` reads the row → overrides `config()` →
   mail/timezone/app now use the new values. No `config:cache` step required.
3. "Send test email" → applies config, sends, reports success/exception.

## Error handling / edge cases

- Settings table absent (fresh DB) → provider no‑ops, `.env` used.
- Corrupt/undecryptable `mail_password` → caught; mail falls back to `.env` password; surfaced on the
  next test‑email attempt rather than crashing requests.
- `app_url` change does not rewrite `APP_URL` in `.env` (Inertia/asset URLs still come from the built
  manifest + request host); it only affects generated links (e.g. email URLs) via `config('app.url')`.
- Invalid timezone can never be saved (validated), so the `date_default_timezone_set()` call is safe.

## Backward compatibility / migration / deploy

- **Additive & inert by default:** new columns are nullable with no defaults; until an admin sets a
  value, everything reads from `.env` exactly as today.
- **Deploy:** run the migration. **No `.env` changes required**; the existing `.env` keeps working and
  remains the fallback. Update `DEPLOY.md`: timezone and `MAIL_*` can now be managed in‑app once set,
  so those no longer require an SSH edit + `config:cache`.

## Testing

**PHPUnit (Feature):**
- `RuntimeConfig` applies non‑null DB values over `config()`; null values leave `.env` untouched.
- Encrypted `mail_password` round‑trips and decrypts for the mail transport; the raw value never
  appears in the Inertia `system` prop nor in the `setting_changes` audit row.
- `app_timezone` override changes `now()`'s timezone within a request.
- `POST /control/system` validation: rejects a bad timezone / port / email / URL; blank password keeps
  the current one; a provided password replaces it.
- Test‑email endpoint sends on good config and reports the exception on bad config.
- `/control/system` + test‑email are admin‑only **and** step‑up gated.

**Vitest (component):**
- The System tab renders the three cards; the password field is write‑only (placeholder + "Set"
  state); the timezone select is populated; "Send test email" and "Reset to default" are present.

## Out of scope (now)

- `APP_KEY`, database credentials, `APP_ENV`/`APP_DEBUG`, session/cache/queue drivers (stay in `.env`).
- Multiple mailers / non‑SMTP transports (SES, Mailgun…).
- Per‑user or per‑environment config; a generic env editor.

## Future (not built now)

- Additional curated fields can be added the same way (one column + one override line + one form field).
- If the set grows large, revisit the generic `app_configs` table (approach B) as a refactor.
