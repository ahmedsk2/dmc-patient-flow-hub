# tools/e2e — end-to-end test harness (LOCAL ONLY)

A black-box, real-HTTP test suite that drives the running app exactly as a browser would
(through `guard.php` / `csrf.php` / `validate.php`) and asserts the resulting DB state. Built
during the A-to-Z verification pass; keep it as a regression net.

> **Local only.** It creates throwaway `e2e_*` accounts and `999000*`-prefixed test patients in
> whatever database `config.local.php` points at, and cleans the patients up at the end. `lib.php`
> refuses to run against a non-localhost DB host. **Never point `config.local.php` at production.**

## Prerequisites
- Dev server running: `php -S 127.0.0.1:8765 dev-router.php` (or the `dmc-local` launch config).
- `config.local.php` present (DB creds + DB_NAME). The harness uses the *same* DB as the app.

## Run
```
php tools/e2e/setup_accounts.php     # create/refresh the 5 role accounts, smoke-test login
php tools/e2e/test_functional.php    # full patient + consultation lifecycle (admit→discharge→…)
php tools/e2e/test_authz.php         # role × endpoint authorization matrix + CSRF + IDOR
php tools/e2e/test_stats.php         # every stats/dashboard data endpoint: clean output + chart data
```
Each script exits 0 when all checks pass.

## What it covers
- **Roles:** admin(0), registrar(2), consultant(3), resident(4), observer(5) — created with known
  passwords and capability flags by `setup_accounts.php`.
- **Functional:** admission variants + server-side validation, all 3 assignment paths, consultation
  add/modify/sign-off/delete, two-phase + one-shot + ICU discharge, the three transfer types,
  72-hour readmission detection, long-term flag, mortality, hard delete, attribution from session.
- **Authorization:** every action endpoint × every role (403 when denied, through-the-guard when
  allowed), CSRF enforcement (419 without a token), object-level ownership (consultant can act on
  own patient, 403 on another's), Observer read-only board.
- **Statistics/graphs:** dashboard/1+3, kpis, charts, charts1, time1, a4, a4-monthly across the
  param matrix — asserts no PHP error output (which would corrupt the eval'd chart scripts) and that
  chart fragments carry Chart.js data. The render half (charts actually draw) is verified in a
  browser via the Preview MCP.

## Notes
- Cookie jars live in the system temp dir (`%TEMP%/dmc_e2e`), not the repo.
- `setup_accounts.php` is idempotent. The `e2e_*` accounts persist between runs (drop them manually
  if you want them gone: `DELETE FROM members WHERE member_name LIKE 'e2e\_%'`).
