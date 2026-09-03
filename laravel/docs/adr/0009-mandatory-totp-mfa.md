# 0009 — Mandatory TOTP MFA for every user, remember-me disabled, no self-disable

- **Status:** Accepted
- **Date:** 2026-07-11 (commit `abf36c4`, "mandatory MFA + email verification"); the remember-me and
  self-disable decisions were taken earlier — commits `e67e888` (2026-06-11) and `136a0b7` (2026-06-12)

## Context

Every authenticated page reaches PHI for roughly 17k patients, across ~330 staff accounts on shared
hospital workstations. A password alone is not an adequate authenticator for that, and an optional
second factor protects only the accounts that opt in.

Making MFA mandatory also exposed an interaction that had to be settled: Laravel's "remember me"
issues a recaller cookie that auto-authenticates a returning browser, carrying the user straight past
the MFA challenge — a persistent second-factor bypass for the life of the cookie (recorded as 30
days). The 2026-09-03 readiness runs describe remember-me as "deliberately disabled to close an
MFA-bypass path".

## Decision

- **TOTP MFA is mandatory for every account, including admins.** `EnsureMfaEnrolled` sits in the
  authenticated stack (`auth → session.timeout → email.verify → mfa.enroll → pwd`), so no clinical
  page renders for a user who has not enrolled. `settings.mfa_enforcement` is inert.
- **Remember-me is disabled**, always: `Auth::login(..., false)`, no checkbox on the login page, and
  `remember_token` rotated on MFA enrolment, password reset and self password change, so any stale
  recaller dies too.
- **Self-disable of MFA is removed.** Only an admin, through Control → Reset MFA, can clear it.
- The challenge expires after 5 minutes, allows 8 attempts, and is replay-guarded by
  `users.mfa_last_counter`. `mfa_secret` is encrypted at rest, recovery codes are hashed, and a
  recovery code cannot mint a trusted device. The trusted-device skip is a fixed, non-extending,
  admin-tunable window (default 24 h), revocable and audited.
- Registration is phased: the e-mail OTP and the authenticator are confirmed **before** a user row
  exists; the account is then `active=0` pending admin activation, never Admin.

## Consequences

- Every user re-authenticates each session — accepted friction on shared workstations, and why UAT
  explicitly checks that no "Remember me" control exists (AUTH-06).
- Locked-out users depend on an admin reset; that path is documented and audited.
- **`APP_KEY` and MFA are coupled to backups:** a restore with the wrong key leaves `mfa_secret`
  undecryptable and every user locked out of MFA.
- `legacy:import` nulls `mfa_secret` / `mfa_enrolled_at` / `email_verified_at`, so a reload forces
  everyone to re-enrol unless the keep-MFA snapshot path is followed.
- Test fixtures need `mfa_secret`, `mfa_enrolled_at`, `email_verified_at` and a recent
  `pass_exp_date` or the middleware redirects the test.

## References

- `CLAUDE.md` §7
- `laravel/app/Http/Controllers/Auth/AuthController.php` (comments dated 2026-07-11),
  `laravel/app/Http/Controllers/MfaController.php`, `laravel/app/Http/Middleware/EnsureMfaEnrolled.php`,
  `laravel/app/Support/Totp.php`, `laravel/resources/js/Pages/Auth/Login.vue`
- `laravel/tests/Feature/MfaTest.php`, `laravel/tests/Feature/Round4H2Test.php` (C1)
- `laravel/docs/DATABASE-AND-BEHAVIOR.md` (Login row, K1/5), `laravel/docs/UAT-TEST-PLAN.md` AUTH-06
- `laravel/SECURITY.md` (scope), `laravel/docs/compliance/DPIA.md`, `ROPA.md` (security measures),
  `INCIDENT-RESPONSE.md`, `EVIDENCE-PACK.md` row P7
- `laravel/docs/DEPLOY-LARAVEL.md` §8 (keep-MFA reload path)
