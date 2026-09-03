# Release checklist — Laravel app (production)

> One page, ticked by a human, for **every** production release. Copy it into the release's notes
> (or print it), fill the header, tick as you go. The *how* of each step is in
> [`DEPLOY-LARAVEL.md`](DEPLOY-LARAVEL.md) (section numbers referenced as §n); the backup commands
> are in [`BACKUP-AND-RESTORE.md`](BACKUP-AND-RESTORE.md).
> There is one environment and it holds real patient data. If a box cannot be ticked, the release does not proceed.

| Release | Date / window | Deployer | Second person aware | Commit SHA(s) `main` |
|---|---|---|---|---|
| v | | | | |

| Migrations in this release? | Any data-fix / backfill migration (DB rollback = restore)? | Env-var changes (rebuild or restart)? | Pre-deploy dump filename |
|---|---|---|---|
| ☐ no ☐ yes: | ☐ no ☐ yes: | ☐ none ☐ restart ☐ rebuild: | |

## 1. Gates — all local, all green (CI is billing-blocked and proves nothing, §11)

Run from `laravel/` on the exact commit you will deploy.

- [ ] `php artisan test --exclude-group pdf` — green. This pass **includes** the `slow-import` group; if your edit loop used `--exclude-group slow-import`, run `php artisan test --group slow-import` explicitly now. Never ship without it: it proves a data reload cannot destroy the consultation ledger.
- [ ] `php artisan test --group pdf` — green (own process; dompdf segfaults if shared).
- [ ] `npx vitest run` — green.
- [ ] `npm run build` then `git status --porcelain -- public/build` prints **nothing** (committed assets match source).
- [ ] `npm run check-allowlist` — green (Tailwind `@source` allow-list snapshot unchanged, or the snapshot was updated on purpose).
- [ ] `node scripts/contrast.mjs` — green (no WCAG / perceptual-distance regression).
- [ ] `composer audit` and `npm audit --omit=dev` reviewed — new advisories either fixed or recorded here with the reason they are unreachable: ______
- [ ] `bash -n scripts/smoke.sh` still parses (only if you touched it).

## 2. Record the release

- [ ] Commit messages follow `type(scope): summary` (`feat`, `fix`, `harden`, `docs`, …) and contain no PHI, credentials or dump filenames.
- [ ] Annotated tag on the deploy commit — there is no CHANGELOG file; **the tag message is the release note**:
  `git tag -a v<YYYY.MM.N> -m "<what changed, migrations included, rollback type>"` then `git push origin main --tags`.
- [ ] `git log --oneline <deployed-sha>..main -- laravel/database/migrations` reviewed; the list matches the header table.

## 3. Backup (§2) — mandatory when there are migrations, expected always

- [ ] Pre-deploy dump taken **now** (not this morning): `dmc_demo_pre-deploy_<timestamp>.sql.gz`; `gzip -t` passed; size is plausible (compare with the previous one).
- [ ] Copied **off the host** per `BACKUP-AND-RESTORE.md`.
- [ ] Filename written into the header table.

## 4. Announce start

- [ ] Inside the agreed low-activity window; the clinical owner / on-shift lead told **before** clicking Deploy:
  > *"DMC patient-flow app: release v… deploying at HH:MM, expect ~1 minute where the site reloads and everyone signs in again. Rollback ready. Will confirm when done."*

## 5. Deploy (§3)

- [ ] Env-var changes applied first; rebuild-vs-restart noted (§5).
- [ ] Deploy triggered (Coolify UI **Deploy**, or `GET /api/v1/deploy?uuid=v5d8vrnp418stpcwnup3yhta`). Deployment uuid: ______
- [ ] Deploy log watched to the end: build OK; **migrations printed = migrations expected**; container healthy.

## 6. Smoke (§3.3)

- [ ] `BASE_URL=https://dmc-new.towardpcc.com bash laravel/scripts/smoke.sh` at the deployed commit → **0 FAIL** (WARN only for `/health` / `security.txt` if not yet shipped). Paste the summary line: ______
- [ ] Admin login with MFA works; dashboard census plausible; Consultations list, handover sheet and physician dashboard open; a PDF downloads. No test patients created.

## 7. Verify the audit chain and the scheduler

- [ ] `sudo docker exec $(sudo docker ps -q -f "label=coolify.name=v5d8vrnp418stpcwnup3yhta") php artisan audit:verify` → chain intact (exit 0).
- [ ] `/health` reports `"status":"ok"` (once shipped) — or `sudo /usr/local/bin/dmc-schedule.sh` exits 0 by hand (§6).
- [ ] Control → Settings: `consultations_source_of_truth` still **ON** (§7).

## 8. Announce done and log it

- [ ] Clinical owner told: *"Release v… live at HH:MM, smoke passed, sign in again if you were logged in."*
- [ ] Deploy log entry (date, tag/SHA, deployer, deployment uuid, dump filename, smoke summary, anything odd) added to the ops record.
- [ ] Next morning: confirm `audit:verify-daily` (02:30) and `dq:notify` (07:00) ran without alerts; skim `storage/logs` (or the shipped copy) for new errors.

## 9. Rollback triggers — decide in minutes, not hours (§4)

Roll back **immediately** (do not fix forward) if **any** of these is true after the deploy:

- `smoke.sh` reports a `FAIL` that is not explained within **10 minutes**.
- Real users cannot sign in, or MFA challenge / enrolment fails.
- A 5xx or blank page on the dashboard, the patient board, New Admissions, or any Consultations page.
- `audit:verify` reports a broken chain.
- Data looks wrong: census / bed counts changed unexpectedly, consultations or handovers missing, the cutover flag is OFF.
- A fix-forward is not verified green within **15 minutes** of starting it.

Then choose (§4 table): **app-only** if the release had no migration or only additive ones → Coolify **Rollback** to the previous SHA (~2–3 min); **app + DB** if a migration changed data or failed part-way → restore the pre-deploy dump **first**, then roll the app back, then `up`, `smoke.sh`, `audit:verify`, and tell the unit the dump time — everything after it must be re-entered.

- [ ] Rollback owner for this release: ______ Rollback type if needed: ☐ app-only ☐ app + DB
- [ ] Last rollback drill date / measured time (§4.4): ______ — if blank, schedule one.
