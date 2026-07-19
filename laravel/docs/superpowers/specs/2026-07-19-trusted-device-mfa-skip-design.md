# Trusted device — opt-in MFA skip — design

**Date:** 2026-07-19
**Status:** approved (owner decisions recorded below)

## Goal

Let a user skip the **TOTP code** — never the password — for a configurable window (default 24 h)
on a browser they explicitly opted in on.

## Why this is not "remember me"

Remember-me was **deliberately disabled** on 2026-07-11 when MFA became mandatory
([`AuthController.php:67`](../../../app/Http/Controllers/Auth/AuthController.php)): a recaller cookie
auto-authenticates through Laravel's `SessionGuard` **without ever reaching the login controller**,
so it skipped *both* factors for 30 days. That stays disabled.

This feature is materially weaker in blast radius:

| | remember-me (disabled) | trusted device (this) |
|---|---|---|
| Skips password | **yes** | no — always required |
| Skips TOTP | yes | yes, for the window |
| Authenticates without hitting `AuthController` | yes | no |
| Granted | implicitly | only by ticking a box |

A stolen trust cookie alone authenticates nobody. It is a *second-factor waiver for one browser*,
useless without the password.

## Owner decisions

| Decision | Choice |
|---|---|
| How trust is granted | **Opt-in checkbox** on the MFA challenge screen, unticked by default |
| Admins | **Included** — same rules as everyone |
| Duration | **Admin-configurable**, default 24 h, `0` disables the feature outright |
| Renewal | **Fixed window from when trust was granted** — using the device does *not* extend it |

The renewal choice follows from picking the opt-in option over the self-renewing one. After the
window the user gets one challenge and may tick again. If a sliding window is wanted instead, that
is a one-line change to `TrustedDevice::touch()` — flagged, not assumed.

## Clinical-setting risk (accepted, mitigated by design)

Ward workstations are shared. Automatic trust would silently waive the second factor for every
person who later sits at that terminal. The opt-in checkbox is the mitigation: staff tick it on a
personal phone and leave it alone on a shared PC. The checkbox label states the consequence in
plain words rather than saying "trust this device".

## Data model

New table `trusted_devices` — one row per trusted browser per user:

| Column | Notes |
|---|---|
| `id` | PK |
| `user_id` | FK → `users`, `cascadeOnDelete` |
| `selector` | 16-byte random hex, **unique index** — the lookup key |
| `validator_hash` | `hash('sha256', $validator)` of a 32-byte random secret |
| `label` | short device description derived from the User-Agent (e.g. "Chrome on Windows") |
| `ip` | issuing IP |
| `expires_at` | issue time + configured hours — **never extended** |
| `revoked_at` | nullable; set instead of deleting, so the trail survives |
| `last_used_at` | nullable; for the user-facing list |
| timestamps | |

**Split selector/validator, not a single token.** The selector is indexed and looked up; the
validator is compared with `hash_equals` against its SHA-256. This gives a constant-time comparison
and a single indexed query, and never stores anything usable at rest. SHA-256 (not bcrypt) is
correct here because the validator is 32 bytes of CSPRNG output — there is nothing to brute-force,
and bcrypt on every login is a needless cost.

**Cookie:** name `dmc_trusted_device`, value `selector:validator`. `HttpOnly`, `Secure`,
`SameSite=Lax`, `Max-Age` = the window. Laravel's `EncryptCookies` is active with an empty `$except`,
so the value is AES-encrypted in transit as a free extra layer — we do **not** add it to `$except`.

## Flow

**Granting** — `MfaController::verifyChallenge`, after a code verifies and only when
`trust_device=1` was posted and the setting is non-zero: issue the row, queue the cookie.

**Using** — `AuthController::login`, after the password verifies and **before** the pending identity
is parked:

```
password ok
  └─ mfaEnabled() && trusted cookie resolves to THIS user, unexpired, unrevoked
       ├─ yes → log in directly (same success block as the MFA path), stamp last_used_at
       └─ no  → park mfa.pending.*, redirect to the challenge (unchanged)
```

The trusted path reuses the *identical* post-login sequence as `verifyChallenge`
(`Auth::login($user, false)` → `session()->regenerate()` → stamp `session_started_at` /
`last_activity_at`) so session-timeout behaviour is unchanged.

A cookie is honoured **only** for the user whose password just succeeded. It is never an identity
hint: presenting someone else's cookie changes nothing about which account is being logged into.

**Audit:** `login.success` with `details => ['mfa' => false, 'trusted_device' => true]`. `mfa` is
recorded **false** because no second factor was presented — an audit that claimed `mfa: true` here
would be a lie in the record. `trusted_device` explains why it was skipped legitimately.

## Revocation — every path that must kill trust

The exploration found the existing invalidation sites are incomplete for this feature. All of these
must revoke a user's trusted devices:

| Site | Today | Change |
|---|---|---|
| `ProfileController::updatePassword` | purges sessions, rotates `remember_token` | **+ revoke all** |
| `PasswordResetController::update` | purges sessions, rotates `remember_token` | **+ revoke all** |
| `ControlController::resetMfa` | **touches nothing** | **+ revoke all** (pre-existing gap) |
| `MfaController::confirm` (re-enrolment) | rotates `remember_token` | **+ revoke all** |
| User self-revoke | — | new: Profile → revoke one, or all |

Deactivation (`active = 0`) needs no change: `AuthController::login` already filters
`where('active', 1)`, so a deactivated account cannot complete the password step at all — the trust
cookie is inert without it. Soft-delete is likewise covered by the `SoftDeletingScope` on
`retrieveById`.

## Surfaces

1. **MFA challenge page** — a checkbox, unticked, only rendered when the feature is on:
   "Don't ask for a code on this device for the next 24 hours. Leave this unticked on a shared
   or ward computer." (The hour count interpolates from the setting.)
2. **Control → System** — `Trusted-device window (hours)`, numeric, `0` = off, help text stating
   that 0 disables it and that changing it does not shorten windows already granted.
3. **Profile → Security** — the user's own trusted devices (label, granted, expires, last used) with
   **Revoke** per row and **Revoke all**. Without this a user who ticks the box on the wrong machine
   has no remedy short of changing their password.

## Testing

- password is still required with a valid trust cookie (the core promise)
- valid cookie → straight to dashboard, no challenge, audit says `trusted_device: true, mfa: false`
- expired / revoked / unknown-selector / tampered-validator cookie → falls back to the challenge
- **cookie issued for user A does not skip MFA for user B** (cross-account rejection)
- setting `0` → checkbox absent and a previously-issued cookie is ignored
- window is **not** extended by use
- each of the four revocation sites actually revokes
- checkbox unticked → no row, no cookie

## Out of scope

Sliding-window renewal; per-device push approval; remembering the device across a password change;
fixing the pre-existing "`active=0` does not evict live sessions" gap (noted, separate).
