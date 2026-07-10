# Mandatory MFA + email verification (registration & existing users)

**Date:** 2026-07-11 · **Branch:** `main` · **Goal:** make authentication materially
stricter. Three user-approved requirements:

1. **New self-registration** must verify the email *mid-form* (type email → "Send code" →
   6-digit code emailed → enter code → confirm) **and** set up an authenticator app
   (TOTP), confirming a code, **before the account is created**.
2. **Existing users** without MFA are forced to enrol at next login; existing users whose
   **email is on file but unverified** must complete an email-code check at next login too.
3. **MFA is mandatory for everyone, always** — the `mfa_enforcement` admin toggle can no
   longer switch it off.

Approved decisions (AskUserQuestion, 2026-07-11): MFA mandatory-for-all; **keep admin
activation** of new accounts (`active = 0` until an admin activates); **email verification
also enforced for existing users** with an on-file-but-unverified address.

## Current state (verified)

- MFA is fully built: `Totp` support class, `MfaController` (setup/confirm/challenge),
  `EnsureMfaEnrolled` middleware (`mfa.enroll`) that redirects un-enrolled *in-scope* users
  to `/mfa/setup`. In-scope is gated by `settings.mfa_enforcement` (0/1/2).
- **No email verification exists.** `users.email_verified_at` column is present (cast) but
  never written or checked; `User` does not implement `MustVerifyEmail`.
- Registration (`RegisterController::store`) creates the account inactive, no verification.
- Login (`AuthController::login`): verifies password on an `active=1` user, then if
  `mfaEnabled()` parks identity in the session → `/mfa/challenge`; else `Auth::login`. The
  authenticated route group is `['auth','session.timeout','mfa.enroll','pwd']`.
- Mail infra works (`MonthlyReportMail` Mailable pattern). Dev `MAIL_MAILER=log` (codes land
  in the log — fine for build/test; prod needs the real SMTP relay, already a deploy item).

## Architecture

### A. Pending-registration store (new)

New table `pending_registrations` (migration), one row per in-progress signup, keyed by a
random `token` held in the session (`session('reg.token')`), so state survives the
multi-step POSTs and is server-authoritative + expirable:

| column | notes |
|---|---|
| `id` | pk |
| `token` | 40-char random, unique; matched against the session |
| `email` | the address being verified (unique among *pending* + `users`) |
| `email_code_hash` | `Hash::make(code)`; nullable until first send |
| `email_code_expires_at`, `email_sent_at`, `email_send_count`, `email_attempts` | OTP lifecycle |
| `email_verified_at` | set when the code checks out |
| `totp_secret` | **encrypted** cast; nullable until provisioned |
| `totp_recovery_codes` | array cast (plaintext held only in this short-lived row; hashed into the user on create) |
| `totp_confirmed_at` | set when a TOTP code verifies |
| `expires_at` | whole-row TTL (30 min); a scheduled/opportunistic sweep deletes stale rows |
| timestamps | |

`PendingRegistration` model: `$fillable`, casts (`totp_secret`=>`encrypted`,
`totp_recovery_codes`=>`array`, the `*_at`=>`datetime`), `$hidden`
(`email_code_hash`,`totp_secret`,`totp_recovery_codes`).

### B. RegistrationController (rewrite `Auth/RegisterController`)

All step routes are **guest-only** + throttled. Contract (JSON/Inertia):

- `GET /register` → `Auth/Register` page (unchanged props: `roles`).
- `POST /register/email/send` — validate `email` (`email`, not already a `users.email`).
  Rate-limit: reject if `email_sent_at` < 60 s ago; cap `email_send_count` (e.g. 5/row).
  Upsert the pending row for the session token, generate a **6-digit numeric** code,
  `email_code_hash` = Hash::make, `email_code_expires_at` = now()+10 min, send
  `RegistrationCodeMail`. Return `{ sent: true }` (validation errors surface on `email`).
- `POST /register/email/verify` — `code` required. Load pending row by session token;
  fail closed if missing/expired; increment `email_attempts` (max 5 → force resend);
  `Hash::check` → set `email_verified_at`. Return `{ verified: true }`.
- `POST /register/mfa/provision` — requires `email_verified_at`. Generate
  `Totp::secret()` + `Totp::recoveryCodes()`, store on the pending row (secret encrypted).
  Idempotent (re-provision returns the same secret while unconfirmed). Return
  `{ secret, otpauthUri: Totp::uri(secret, email, config('app.name')), recoveryCodes }`.
- `POST /register/mfa/confirm` — `code` required; `Totp::verifyWithCounter` against the
  pending secret; on success set `totp_confirmed_at`. Return `{ confirmed: true }`.
- `POST /register` (store) — re-validate the full form (`username` unique, `full_name`,
  `role in 2,3,4,5`, `password` confirmed + `Password::min(8)->letters()->numbers()`).
  **Require** the pending row (session token) has BOTH `email_verified_at` and
  `totp_confirmed_at`, and that its `email` equals the submitted email (defense-in-depth).
  Create the `User`: `email_verified_at`=now, `mfa_secret`=pending secret,
  `mfa_recovery_codes`=`array_map(Hash::make, pending recovery)`, `mfa_enrolled_at`=now,
  `active`=0 (admin activation kept), `pass_exp_date`=today. Delete the pending row + clear
  `reg.token`. Audit `user.self_register`. Flash the existing "await activation" message.

`RegistrationCodeMail` (Mailable, `->subject('Your DMC verification code')`, a minimal
blade rendering the 6-digit code + a 10-minute-expiry line; no PHI).

### C. Existing-user gates (new middleware, added to the authenticated group)

Group becomes `['auth','session.timeout','email.verify','mfa.enroll','pwd']` — email first,
then MFA.

- **`EnsureEmailVerified` (`email.verify`)**: if `user->email` is non-null and
  `user->email_verified_at` is null and the route isn't in the allow-list
  (`email.verify.show`,`email.verify.send`,`email.verify.confirm`,`logout`,`mfa.challenge`)
  → redirect to `email.verify.show`. Users with a **null email are exempt** (we can't send
  them a code; they proceed to the MFA gate). This is the approved "email on file but
  unverified" scope.
- **`EmailVerificationController`** (auth-only) — mirrors the registration OTP but for the
  logged-in user's own on-file address, writing `users.email_verified_at`:
  - `GET /email/verify` → `Auth/EmailVerify` page (shows the masked on-file email).
  - `POST /email/verify/send` — throttle/cooldown (store code hash + expiry in the session,
    e.g. `session('email.verify.*')`, since the user row exists but we don't want to persist
    a live code on it); send `RegistrationCodeMail` (reused) to `user->email`.
  - `POST /email/verify/confirm` — check code → `user->update(['email_verified_at'=>now()])`,
    audit `email.verified`, redirect intended.
- **`EnsureMfaEnrolled` change**: `$inScope` becomes **unconditional** — every authenticated
  user without `mfaEnabled()` is redirected to `/mfa/setup`. The `mfa_enforcement` setting no
  longer gates enrollment (kept in the schema for now to avoid a wider migration). Update the
  **Control → Settings** UI: the MFA-enforcement control is annotated/disabled with "MFA is
  mandatory for all users" so admins aren't shown a toggle that does nothing. `SecurityPanel`
  / dashboard reads that reference the level keep working (they only display).

### D. Shared Inertia prop

`HandleInertiaRequests` `auth.user` gains `email_verified => $user->email_verified_at !== null`
(next to `mfa_enrolled`) so the client can reason about state if needed. No behavior depends
on it server-side (middleware is authoritative).

### E. Frontend

- **`Auth/Register.vue`** (rewrite): reactive multi-phase, matching the requested UX.
  - Phase 1 — username, full name, role, **email** + a **"Send code"** button (enabled when
    email is a valid format). Click → `POST /register/email/send` (Inertia/axios), on success
    lock the email field + reveal a **code input + "Confirm code"** button + a "Resend"
    (60 s cooldown) + a "change email" link (unlocks + resets the pending state).
  - Phase 2 — code → `POST /register/email/verify`; on success reveal the rest.
  - Phase 3 — password + confirm, then a **"Set up authenticator"** step: on entering it,
    `POST /register/mfa/provision` → render the QR (reuse the `qrcode` lib as in `Mfa/Setup`)
    + manual secret + recovery codes + a TOTP code input → `POST /register/mfa/confirm`.
  - Final **"Create account"** enabled only when email + TOTP are both confirmed → `POST
    /register`. Every step shows inline errors + the app's a11y patterns (labels, `text-on-danger`,
    autocomplete off on the code fields, `bg-brand-solid` CTAs).
- **`Auth/EmailVerify.vue`** (new): the logged-in email-verify page — masked email, "Send
  code", code input, "Confirm", "Resend" (cooldown). Same visual language.
- `Mfa/Setup.vue` unchanged (existing-user MFA enrolment already uses it).

## Security details (non-negotiable)

- Codes: 6-digit numeric, hashed at rest, 10-min expiry, ≤5 verify attempts then force
  resend, ≥60 s resend cooldown, per-route throttle (`throttle:auth`-style keyed by
  session/email/IP). No code or secret ever returned in a URL or logged (only the dev mail
  log carries the code, by design).
- TOTP secret encrypted at rest in the pending row; recovery codes hashed into the user.
- No account exists until BOTH factors verify → a half-finished signup leaves only an
  expirable pending row, never a live login.
- Enumeration: the app already surfaces "email already registered" on the current register
  form (internal hospital tool) — keep parity; do not newly widen it.
- Everything stays behind the existing CSP/headers + CSRF; the new POSTs are normal
  session+CSRF Inertia/axios calls (NOT csp-report-style exemptions).

## Testing

- **PHPUnit:** email send/verify happy path; expired code; too many attempts; resend
  cooldown; provision-requires-verified-email; confirm-requires-provisioned; store rejects
  when either factor is unverified; store creates the user inactive with MFA+email set +
  pending row deleted; existing-user email-verify gate redirects an unverified user and lets
  a verified one through; null-email user is exempt; MFA now mandatory for all (a role-5
  observer with no MFA is redirected to setup regardless of `mfa_enforcement`); the mail is
  queued/sent with the code.
- **Vitest:** Register.vue phase gating (rest hidden until email verified; Create disabled
  until TOTP confirmed; resend cooldown); EmailVerify.vue.
- Gates: two-pass PHPUnit, Vitest, contrast, allowlist, reproducible build; then live
  end-to-end in the running app (dev mail log yields the code), and an adversarial pass.

## Pended (judgment calls, adjustable)

- Code format 6-digit/10-min/5-attempts/60-s — standard; say if you want different.
- Admin-created users (Control panel) currently would verify email + set MFA at *their*
  first login (consistent with "existing users"); flag if admins should instead create
  pre-verified accounts.
- The `mfa_enforcement` setting is now inert for "off"; left in-schema. Say if you'd rather
  remove it entirely (bigger migration + Control-UI change).
- Prod email delivery must be live (real SMTP) before this helps real users — it's already a
  deploy blocker; in dev the code is in `storage/logs`.
