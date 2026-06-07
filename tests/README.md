# Tests

Self-contained test suite — **no Composer / no PHPUnit** (matches the app's design).

```bash
php tests/run.php          # whole suite (runs integration checks if the dev server is up)
php tests/run.php --unit   # offline unit checks only (CI-friendly; no server needed)
```

The runner (`tests/run.php`) executes each registered check as its own PHP process and
aggregates exit codes, so the existing validated check scripts *are* the suite:

| Check | Script | Type | What it proves |
|---|---|---|---|
| TOTP / MFA crypto | `tools/mfa_test.php` | unit | Matches the published RFC-6238 vectors; Base32 / AES-GCM / recovery-code round-trips |
| Stats `report_data` == `a4.php` | `tools/report_data_validate.php` | integration | The PDF data layer's figures are identical to the HTML A4 report (2023 & 2024) |

Integration checks need the local dev server (`DMC_BASE`, default `http://127.0.0.1:8765`)
and are **skipped** (not failed) when it isn't reachable.

Also available (not in the auto-runner — they're before/after tools, run manually):
`tools/stats_validate.php` (golden-master capture/compare for the stats endpoints).

**To add a test:** write a script that exits `0` on pass / non-zero on fail, then register it
in the `$suites` array in `tests/run.php`.

> Roadmap (Phase 4): a PSR-4 autoloader + a `src/` service/repository layer (strangler-fig) so
> domain logic moves behind interfaces and gets direct unit tests. `report_data.php` is the first
> candidate to become a `src/` class. Real PHPUnit can be swapped in where Composer is available.
