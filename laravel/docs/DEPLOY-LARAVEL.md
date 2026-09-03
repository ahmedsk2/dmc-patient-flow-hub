# Laravel app — production deployment runbook (Coolify)

> How the **Laravel re-platform** (`laravel/` on branch `main`) is deployed, verified and rolled back
> in production. Production is **not** "git pull on the box" any more: it is a **Coolify v4**
> application that builds an immutable image from `main`, migrates, and swaps containers. This
> document describes that reality. The legacy PHP app has its own runbook at the repo root
> ([`DEPLOY.md`](../../DEPLOY.md)).
>
> Companions: [`RELEASE-CHECKLIST.md`](RELEASE-CHECKLIST.md) (the tick-list a human follows for every
> release), [`BACKUP-AND-RESTORE.md`](BACKUP-AND-RESTORE.md) (the backup/restore procedure — being written
> in a parallel workstream; the dump/restore commands quoted here defer to it), and
> [`../scripts/smoke.sh`](../scripts/smoke.sh) (the post-deploy check).
>
> ⚠️ This system holds real patient data. **Every deploy that carries a migration is preceded by a
> database dump.** No exceptions — §4.3 explains why the dump is the only rollback for the database.

---

## 0. The production topology

| Piece | What / where |
|---|---|
| Public URL | `https://dmc-new.towardpcc.com` — a **proxied** (orange-cloud) Cloudflare record. The origin's ports 80/443 accept **only Cloudflare's IP ranges**, so any additional hostname must also be proxied or it will not connect at all. |
| Host | One OCI Ubuntu instance (region `me-riyadh-1`), reached as `ubuntu@<origin>` with the OCI SSH key. The origin IP is deliberately not written here — it is what the Cloudflare proxy hides; it is in the private ops note. `ubuntu` has passwordless `sudo`; Docker commands need `sudo`. |
| Orchestrator | **Coolify v4** on the same host. Dashboard `coolify.towardpcc.com` is internal-only (no public DNS). REST API: `http://localhost:8000/api/v1/` **on the host**. Project *migrated-sites*, application **dmc-new**, uuid **`v5d8vrnp418stpcwnup3yhta`**. |
| Build | **Nixpacks**, build root `/app` = the `laravel/` directory, from branch **`main`**. Every build produces an image tagged with the **commit SHA**. |
| App container | Runs that image behind Coolify's Traefik. Locate it by label (the id changes on every deploy): `sudo docker ps -q -f "label=coolify.name=v5d8vrnp418stpcwnup3yhta"` |
| Database | A `mysql:8` container on the same host. App database **`dmc_demo`**, app user **`dmc_demo`** (not root). The legacy source database `dmc_prod` lives in the same container and is the read-only input of `legacy:import` (§8). |
| Scheduler | A **host root cron**, every minute: `/usr/local/bin/dmc-schedule.sh` → `docker exec <app container> php artisan schedule:run`. See §6. |
| Audit off-box copy | `audit:ship` (hourly) streams `audit_log` rows as write-once NDJSON to the OCI Object Storage bucket `dmc-audit-log` (me-riyadh-1), configured by the `AUDIT_S3_*` env vars. |
| CI | `.github/workflows/laravel-ci.yml` (workflow name **Laravel CI**): four jobs — Frontend (Vitest + coverage thresholds, axe, build, style gates), Backend (PHPUnit two-pass on PHP 8.3 / MySQL 8.4, blocking composer audit, Pint gate), Secret scan, SAST. **Since 2026-09-03 they are required, admin-enforced status checks on `main`**, so `main` accepts pull requests only and a merge is by definition green (§11). |

There is **one** environment. There is no staging. Treat every deploy as a production change.
Backups: nightly encrypted off-box dumps, `backup:verify`, and the restore drill are in [`BACKUP-AND-RESTORE.md`](BACKUP-AND-RESTORE.md).

---

## 1. What Coolify does on a deploy (automatically)

When a deploy is triggered (§3), Coolify:

1. **Fetches the target commit** of `main` (HEAD, unless a rollback deploys an older one — §4).
2. **Builds the image with Nixpacks** in `laravel/`: PHP 8.3 + `composer install` (production dependencies). `public/build/` is **committed** to git, so the app never depends on a Node build on the host — what gets served is exactly the asset set in the commit, and `scripts/smoke.sh` verifies that against `public/build/manifest.json` after every deploy.
3. **Tags the image with the commit SHA.** Images are immutable: the same SHA always yields the same code, which is what makes "redeploy the previous image" a real rollback (§4.1).
4. **Runs `php artisan migrate --force`** as part of the deploy, before the new container takes traffic. The deploy log prints every migration applied — read it. Migrations are **cumulative**: a deploy after several un-deployed releases applies all of them, in one batch.
5. **Starts the new container, waits for it to come up, points Traefik at it, stops the old one.** There is a brief moment where the old code runs against the freshly-migrated schema; every migration in this repo so far is additive at the schema level (new tables/columns/indexes/constraints), so the old code tolerates it. Keep it that way — see §4.3.

What Coolify does **not** do: take a database backup (you do — §2), run the test suite (you do, before pushing), run the smoke test (you do — §3.3), or rebuild when you change a *build-time* env var without triggering a deploy (§5).

Once live: `/up` returns 200 (Laravel's built-in liveness route, registered in `bootstrap/app.php`), and `/health` (a JSON check of `db`, `storage` and the scheduler heartbeat — being added in a parallel workstream) returns `{"status":"ok"}`. `scripts/smoke.sh` checks both (and warns rather than fails while `/health` is still 404).

---

## 2. Before every deploy

- [ ] **Gates green on the pull request and the release recorded** — follow [`RELEASE-CHECKLIST.md`](RELEASE-CHECKLIST.md). The four Laravel CI checks are required on `main` (§0/§11), so a merged commit is green by construction; run the local gates too when you changed something the CI runtime cannot exercise.
- [ ] **Know which migrations this deploy carries.** The currently-deployed SHA is on Coolify's *Deployments* tab; then:
  ```bash
  git log --oneline <deployed-sha>..main -- laravel/database/migrations
  ```
  If any of them is a data-fix/backfill migration (§4.3 lists the ones whose `down()` is a no-op or destructive), the pre-deploy dump is the **only** way back for the data.
- [ ] **Take the pre-deploy dump.** Mandatory when the deploy carries migrations; strongly recommended for every deploy. The canonical, automated form (and the container id) lives in [`BACKUP-AND-RESTORE.md`](BACKUP-AND-RESTORE.md); the manual form, run on the host, is:
  ```bash
  MYSQL=$(sudo docker ps -q -f "name=<mysql-container>")   # the mysql:8 container — id in BACKUP-AND-RESTORE.md
  sudo docker exec "$MYSQL" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump --single-transaction --routines --triggers --databases dmc_demo' \
    | gzip > ~/dmc_demo_pre-deploy_$(date +%F-%H%M%S).sql.gz
  gzip -t ~/dmc_demo_pre-deploy_*.sql.gz && ls -lh ~/dmc_demo_pre-deploy_*.sql.gz
  ```
  Write the filename into the release checklist. **Copy it off the host** per the backup doc — a dump that exists only on the box it protects is not a backup.
- [ ] **Env-var changes?** Set them first (§5) and note whether they need a rebuild or a restart.
- [ ] **Window + notice.** Deploy in the low-activity window agreed with the unit and tell the clinical owner it is starting (message template in the checklist). The swap itself is seconds, but any user mid-form at that instant loses the form, and file-backed sessions do not survive a new container (§10) — everyone signs in again.

---

## 3. Deploy

### 3.1 Via the Coolify UI
1. Merge/push the release to `main` on GitHub.
2. Coolify → *migrated-sites* → **dmc-new** → **Deploy**. If *Auto Deploy* on push is enabled for the app, the push itself starts the build — look at the *Deployments* tab first so you do not queue a second, identical build.
3. Watch the deployment log to the end. Confirm three things: the build succeeded, the list of migrations applied is the list you expected from §2, and the new container is reported healthy.

### 3.2 Via the API (from the host)
```bash
# One-time: create an API token in Coolify (Keys & Tokens → API tokens) and keep it in the private
# ops note, NOT in the repo. The token stored there went dead once — verify before relying on it:
curl -sS -H "Authorization: Bearer $COOLIFY_TOKEN" http://localhost:8000/api/v1/version

# Deploy main HEAD (returns the deployment uuid):
curl -sS -H "Authorization: Bearer $COOLIFY_TOKEN" \
  "http://localhost:8000/api/v1/deploy?uuid=v5d8vrnp418stpcwnup3yhta"
#   → {"deployments":[{"message":"...","resource_uuid":"v5d8...","deployment_uuid":"<D>"}]}

# Poll until "status" is finished (or failed):
curl -sS -H "Authorization: Bearer $COOLIFY_TOKEN" http://localhost:8000/api/v1/deployments/<D>
```
Append `&force=true` to the deploy call to bypass the build cache when a build misbehaves. The API always deploys the **HEAD of the configured branch**; to deploy an older commit, use the UI's Rollback (§4.1) or make `main` point at the code you want via a revert commit.

### 3.3 After the swap — verify (do all four, in this order)
1. **Smoke test**, from your workstation, with the deployed commit checked out (so the bundle-hash comparison is meaningful):
   ```bash
   BASE_URL=https://dmc-new.towardpcc.com bash laravel/scripts/smoke.sh
   ```
   Every line must be `PASS`. `WARN` is acceptable only for the two endpoints the script names as not shipped yet (`/health`, `security.txt`). It exits non-zero on any `FAIL`; it never logs in and touches no PHI.
2. **Audit chain**, on the host:
   ```bash
   sudo docker exec $(sudo docker ps -q -f "label=coolify.name=v5d8vrnp418stpcwnup3yhta") php artisan audit:verify
   ```
   Must report the chain intact (exit 0). A break here right after a deploy means the deploy touched `audit_log` or `AuditLog::canonical()` — stop and investigate before anything else.
3. **Scheduler**: `/health` reports the heartbeat once shipped; until then run `sudo /usr/local/bin/dmc-schedule.sh` by hand and confirm it exits 0 (it locates the *new* container by label, so a redeploy does not break it — §6).
4. **Human check** (an admin, with MFA): dashboard census is plausible; Consultations → list, handover sheet and physician dashboard all open; Reports → PDF downloads. **Do not create test patients in production.**

If anything fails and cannot be explained within ~10 minutes → §4. The checklist's rollback trigger criteria are the decision rule; do not debug a live clinical system for an hour.

---

## 4. Rollback (target: under 5 minutes)

First decide **which** rollback you need:

| Situation | Do |
|---|---|
| Bad code; the release carried **no migration**, or only additive ones (new tables/columns/indexes — the previous code ignores them) | **App-only** (§4.1). Start-and-swap of an already-built image: ~2–3 minutes. |
| The release's migration **changed or rewrote data** (a data-fix or backfill), **or** the previous code cannot run on the new schema, **or** the migration failed part-way | **App + DB** (§4.2). Restore time depends on dump size, plus the app rollback. **Everything written since the dump is lost.** |

### 4.1 App-only: redeploy the previous image

**UI (fastest):** Coolify → **dmc-new** → **Rollback**. Coolify lists the images it still holds locally, by commit SHA. Pick the previously-deployed SHA → **Rollback**. It starts that image and swaps Traefik to it exactly like a deploy, but there is no build (the image already exists). The `migrate --force` step is a no-op: the old code carries no migration the database has not already recorded.

**Git (auditable; needs a rebuild, so slower):** revert the release on `main` and deploy it:
```bash
git revert --no-edit <bad-sha>                 # several commits: git revert --no-edit <good-sha>..<bad-sha>
git push origin main
curl -sS -H "Authorization: Bearer $COOLIFY_TOKEN" "http://localhost:8000/api/v1/deploy?uuid=v5d8vrnp418stpcwnup3yhta"
```
Never force-push `main` backwards: the deployment history and the record of *what was live when* depend on it. The revert commit **is** the record.

Then run `scripts/smoke.sh` (its bundle-hash line will `FAIL` if your checkout is still at the bad commit — that is the check working; check out the rolled-back SHA) and `audit:verify`.

### 4.2 App + DB: restore the pre-deploy dump, then roll the app back

**Order matters: database first, then application.** Roll the app back first and the still-running new code keeps writing into the schema you are about to replace; restore the database but leave the new code running and the next deploy simply re-applies the migrations you just undid.

```bash
APP=$(sudo docker ps -q -f "label=coolify.name=v5d8vrnp418stpcwnup3yhta")
MYSQL=$(sudo docker ps -q -f "name=<mysql-container>")

# 1. Stop writes: maintenance mode in the running container (file driver — holds until `up`).
sudo docker exec "$APP" php artisan down --secret="<long-random-string>"    # preview via https://host/<secret>

# 2. Restore. The dump was taken with --databases, so it carries its own `USE dmc_demo`.
gunzip -c ~/dmc_demo_pre-deploy_<timestamp>.sql.gz \
  | sudo docker exec -i "$MYSQL" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot'

# 3. Roll the application back — §4.1 (UI Rollback, or revert + deploy). The old code's migrate
#    step finds the restored `migrations` table already at its own level → nothing to migrate.

# 4. Leave maintenance mode in the NEW container after the swap, then verify.
APP=$(sudo docker ps -q -f "label=coolify.name=v5d8vrnp418stpcwnup3yhta")
sudo docker exec "$APP" php artisan up
sudo docker exec "$APP" php artisan audit:verify
```
Then `scripts/smoke.sh`, and tell the unit **the exact time the dump was taken**: every admission, consultation, handover, sign-off and audit row written after that moment is gone and must be re-entered from the paper / HIS record.

Two side-effects of a restore to check afterwards:
- **`settings` is restored too.** Confirm the consultations cutover flag is still ON in Control → Settings (§7). If the dump predates cutover the flag comes back OFF — and the next `legacy:import` would then destroy the ledger.
- **`settings.audit_shipped_through_id` moves backwards**, so the next hourly `audit:ship` re-ships rows that are already in the bucket, as a new write-once file. Harmless duplicates — note them in the incident record so whoever reads the bucket later is not surprised.

### 4.3 Why "DB rollback" means *restore* — never `migrate:rollback`

`php artisan migrate:rollback` is **not** a rollback in this system and must not be run in production:

- Several migrations are data fixes whose `down()` is deliberately empty — they cannot undo themselves:
  `2026_06_11_000002_resolve_numeric_consultation_to_service`, `2026_06_11_000003_normalize_delay_reason_case`,
  `2026_06_14_010002_fix_analytics_trashed_rows`, `2026_08_21_000300_backfill_consultation_ledger_state`.
- Some `down()`s that *do* run are destructive: `2026_08_21_000100_add_ledger_columns_to_consultations` **drops the ledger columns** (every consultation status, sign-off and response written since is gone); `2026_08_21_000500_grant_consultation_coordinator_to_unaffiliated_users` revokes the coordinator capability from everyone in its population, including users an admin granted by hand afterwards.
- Rollback works per **batch**, and a deploy that applied several releases' migrations put them all in one batch.

Restoring the pre-deploy dump is the only operation that returns the data to a known state. That is why §2 makes the dump mandatory, and why new migrations should stay additive (expand, never contract) so §4.1 remains the default.

### 4.4 Rehearsal — the 5-minute target is only real once measured

| Drill | Where | Last done | Measured time |
|---|---|---|---|
| App-only rollback via Coolify **Rollback**, then `smoke.sh` | production (safe — no data change) | ☐ not yet | — |
| Restore a current-size dump into a **scratch** database, then `audit:verify` against it | scratch DB, never `dmc_demo` | ☐ not yet | — |

Fill the table in after each drill. Do the restore drill on a scratch database — the restore path in §4.2 is exercised for real only during an incident.

---

## 5. Environment variables: which need a rebuild, which a restart

Every env var in Coolify (*dmc-new* → *Environment Variables*) has a **Build Variable** toggle — `is_buildtime` in the API. The distinction decides what you must do after changing a value:

| Kind | Where it takes effect | After changing it |
|---|---|---|
| **Build Variable** (`is_buildtime: true`) | Baked into the image during the Nixpacks build | **Redeploy** (a full build). A restart is *not* enough — the container would start with the old baked value. |
| Runtime variable (`is_buildtime: false`) | Injected when the container starts | **Restart** the application (Coolify → *Restart*). No build needed. |

As configured today, `APP_URL` and the `AUDIT_S3_*` set are marked Build Variables (they were created that way); everything else is runtime. When unsure, **Redeploy** — it is always correct, only slower.

API forms (the field is `is_buildtime`, **not** `is_build_time` — the wrong name fails validation):
```bash
# update an existing variable
curl -sS -X PATCH -H "Authorization: Bearer $COOLIFY_TOKEN" -H "Content-Type: application/json" \
  http://localhost:8000/api/v1/applications/v5d8vrnp418stpcwnup3yhta/envs \
  -d '{"key":"LOG_LEVEL","value":"warning","is_buildtime":false}'
# create a new one
curl -sS -X POST -H "Authorization: Bearer $COOLIFY_TOKEN" -H "Content-Type: application/json" \
  http://localhost:8000/api/v1/applications/v5d8vrnp418stpcwnup3yhta/envs \
  -d '{"key":"NEW_KEY","value":"...","is_preview":false,"is_buildtime":false,"is_literal":true}'
```

**What lives where:**

- **Bootstrap-only in env (Coolify):** `APP_KEY`, `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, `DB_*`, `LOG_CHANNEL=daily` / `LOG_LEVEL=warning`, `SESSION_SECURE_COOKIE=true`, `CSP_MODE=enforce`, `AUDIT_S3_*`, `SENTRY_DSN` (optional; the SDK is inert while empty). `APP_TIMEZONE=Asia/Riyadh` is the fallback for the in-app value below — date columns are timezone-naive and every "today" rule drifts by 3 h if the app runs in UTC.
- **Runtime configuration in the app (Control → System):** timezone and the `MAIL_*` set are editable in-app and take effect on the next request — no restart, no rebuild. `.env` values are the fallback for any field left blank. The SMTP password is stored encrypted (AES-256 under `APP_KEY`) and is write-only in the UI.
- **Never in the repo:** no `.env`, no tokens, no dump files. Coolify's env store on the host is the secret store.

---

## 6. Scheduler and background work

Nothing inside the container runs the Laravel scheduler. It is driven by a **host root cron**, every minute:

```
* * * * * /usr/local/bin/dmc-schedule.sh
#   → docker exec $(docker ps -q -f "label=coolify.name=v5d8vrnp418stpcwnup3yhta") php artisan schedule:run
```

Because the script looks the container up **by label**, it survives every redeploy. It does **not** survive the application being deleted and recreated in Coolify (new uuid) — update the script if that ever happens. If the scheduler is ever moved to a Coolify *Scheduled Task*, remove this cron so tasks do not run twice.

**All host root crons** (these run on the host, outside every container — nothing in Coolify shows them):

| File / entry | When | What | Runbook |
|---|---|---|---|
| `* * * * * /usr/local/bin/dmc-schedule.sh` | every minute | drives the Laravel scheduler (the table below) | §6 |
| `/etc/cron.d/dmc-db-backup` | 02:15 daily | `db-backup.py` — nightly encrypted off-box `mysqldump` | BACKUP-AND-RESTORE.md §2.5 |
| `/etc/cron.d/dmc-binlog-ship` | minute 40 of every hour | `binlog-ship.py` — encrypted off-box MySQL binary logs; this is what makes point-in-time recovery possible and takes the RPO from 24 h to ≤ 1 h. **Not installed yet** — the operator installs it. | BACKUP-AND-RESTORE.md §10.2 |

**Deploy-on-green (prepared, opt-in).** Deploys are operator-triggered today (Auto Deploy is off on purpose). `scripts/deploy-on-green.sh` is a host-side alternative that deploys `main` only when the Laravel CI run for that exact commit is green and the commit is not already the live image, then runs the smoke test and reports a red smoke as the rollback trigger. To enable: place a Coolify API token in a root-only file (`/root/.coolify-deploy-token`, mode 600 — never in the repo), copy the script to `/usr/local/bin/`, and add a root cron such as `*/5 * * * * /usr/local/bin/deploy-on-green.sh`. Because `main` only accepts green pull requests, "green" here is redundant protection, not the only gate. The nightly database backup is scheduled separately in `/etc/cron.d/dmc-db-backup` (see BACKUP-AND-RESTORE.md §2.5).

Scheduled (`routes/console.php`):

| Task | When | Notes |
|---|---|---|
| `audit:ship` | hourly | Ships new `audit_log` rows off-box. No-ops with a warning when `AUDIT_S3_*` is unset. |
| `audit:verify-daily` | 02:30 | Walks the hash chain; on a break it logs critical, notifies every active admin in-app and emails report recipients. Silent on success. |
| `dq:notify` | 07:00 | Data-quality digest for admins. Read-only. |
| `monthly-report-email` | 1st, 06:00 | Emails the prior month's booklet to active recipients. |

`audit:prune` is **never** scheduled — it deletes rows and is operator-run only, behind `--confirm`.

`QUEUE_CONNECTION=sync`: there is no queue worker. Anything marked `ShouldQueue` (the monthly PDF job, the report page's `?async=1`) runs inline in the request that triggered it.

---

## 7. The consultations cutover flag — `settings.consultations_source_of_truth`

Since the consultation ledger went live, **this application owns the consultation data**; the legacy database does not. The flag records that fact, and `legacy:import` reads it before it touches anything:

| Flag | `legacy:import` behaviour |
|---|---|
| **ON** (cutover done — the production state) | `consultations` (and `consultation_followups`) are **preserved**. After the patient/user rebuild each consultation is re-pointed at the new rows by natural key (`patients.mrn`, `users.legacy_id`). `--wipe-consultations` is **refused**. |
| OFF (pre-cutover default) | `consultations` is **truncated and rebuilt from the legacy dump** — every consultation, status change, sign-off and follow-up entered in this app is destroyed. |

Set and inspected in **Control → Settings** (the checkbox confirms before it un-ticks, and the change is written to the settings history). The flag is an app-owned settings column, so `legacy:import` itself carries it across a rebuild — the first import cannot silently reset it.

**Never flip it back casually.** Turning it OFF is not a harmless toggle: it re-arms the next `legacy:import` to destroy the ledger. The only legitimate reason is a deliberate decision to abandon the ledger and re-adopt the legacy data, taken with the consulting services, with a fresh dump in hand. Two more rules follow from the flag:

- **Read the import output.** Any preserved consultation whose patient or user cannot be resolved after the rebuild is reported as `preserved consultations that lost their <column>` with its ids, and that link is set to NULL. This is expected for patients admitted inside this app (they are not in the legacy dump). Those rows keep their `mrn` / `patient_name` and can be re-linked; ledger reports that join on the column skip them until you do.
- **A FAILED import while the flag is ON requires a database restore before retrying.** The command cannot run in one transaction (TRUNCATE forces a commit) and the re-link mapping is held only in memory — a crash after the user rebuild leaves preserved consultations pointing at the previous generation's ids, and a retry cannot recover the mapping. Always dump first (§2).
- **After any database restore, check the flag** (§4.2): a dump taken before cutover restores it to OFF.

---

## 8. Reloading patient data — `php artisan legacy:import`

Not part of a deploy (code deploys and data reloads are independent — a deploy's `migrate --force` is a no-op for a reload, and deploying after a reload is safe), but it is the most destructive operator command in the system, so its rules live here.

- **The app's MySQL user is `dmc_demo`, not root**, and the `legacy` connection reuses `DB_USERNAME`/`DB_PASSWORD`. The import needs `GRANT SELECT ON dmc_prod.* TO 'dmc_demo'@'%'` (already in place; re-grant after any MySQL rebuild).
- **It truncates the target tables BEFORE its first read of the legacy database.** If that read fails (missing grant, wrong `LEGACY_DB_DATABASE`), `dmc_demo` is left **empty**. Dump first (§2); recover with the restore line in §4.2. This has happened once.
- **It is a full rebuild that resets identity state:** users are deleted and re-seeded with **new ids and NULL `mfa_secret` / `mfa_enrolled_at` / `email_verified_at`** (everyone re-enrols MFA); `handovers`, `handover_*`, `notifications` are truncated; `settings` is rebuilt but **every app-owned column is carried through** (SMTP credentials, timezone, thresholds, the cutover flag, `audit_shipped_through_id`, retention). `audit_log` is never touched. Consultations follow §7.
- **To reload without disrupting sign-in ("keep MFA")**, snapshot → import → **verify counts** → restore, never in one step: (1) `CREATE TABLE _mfa_bak AS SELECT legacy_id, mfa_secret, mfa_recovery_codes, mfa_enrolled_at, email_verified_at, tour_completed_at FROM users WHERE legacy_id IS NOT NULL AND (mfa_enrolled_at IS NOT NULL OR email_verified_at IS NOT NULL)`; (2) run the import and compare its printed counts with the source; (3) `UPDATE users u JOIN _mfa_bak b ON u.legacy_id = b.legacy_id SET u.mfa_secret = b.mfa_secret, u.mfa_recovery_codes = b.mfa_recovery_codes, u.mfa_enrolled_at = b.mfa_enrolled_at, u.email_verified_at = b.email_verified_at, u.tour_completed_at = b.tour_completed_at;` then `TRUNCATE trusted_devices;` (stale device cookies would map onto re-seeded ids) and drop `_mfa_bak`; (4) clear file sessions in the app container: `sudo docker exec "$APP" sh -c 'rm -f storage/framework/sessions/*'` — re-seeded ids orphan live sessions.
- Loading a newer legacy dump into `dmc_prod`: `DROP`/`CREATE DATABASE dmc_prod`, pipe the `.sql` into `mysql -uroot dmc_prod` inside the container, **delete the loose `.sql` from the host afterwards** (it is PHI).
- The importer self-heals to satisfy the schema: out-of-range ages and inverted admission dates are NULLed, duplicate member emails are de-duplicated (lowest id keeps the address), `memory_limit` is raised to 1 G. It is idempotent on the target and read-only on the source.

---

## 9. First-time setup (a new environment, or rebuilding from zero)

Nothing here is needed for a routine release; it is what it takes to recreate production.

1. **Host:** Ubuntu with Docker + Coolify v4; a `mysql:8` service (utf8mb4, InnoDB) with the app database and a **dedicated app user with a fresh password** — the legacy credentials were exposed in history and must never be reused. Firewall 80/443 to Cloudflare ranges only; SSH key-only.
2. **Coolify application:** source = the GitHub repo, branch `main`, build pack Nixpacks, base directory `/laravel`, domain `https://dmc-new.towardpcc.com` (proxied in Cloudflare, SSL mode *strict*). Enable a health check on `/up` so a broken image is never routed to. Add the deploy-time `php artisan migrate --force` step (that is where §1 step 4 comes from).
3. **Environment variables** per §5; generate `APP_KEY` with `php artisan key:generate --show` locally and paste it in.
4. **Database contents** — pick one: import a ready-made `dmc_laravel_export.sql` (then `migrate --force` should print *Nothing to migrate* — a sanity check that code and schema agree), **or** `migrate` + `legacy:import` from `dmc_prod` (§8).
5. **Scheduler cron** per §6. **Audit shipping** bucket + least-privilege S3 key per the private ops note (`AUDIT_S3_*`).
6. **First login as an admin:** Control → Settings — set the real **licensed ward / ICU beds** (occupancy is wrong until you do), confirm the cutover flag (§7), review users/capabilities and deactivate accounts that should not exist. MFA (authenticator app) is mandatory for every user since 2026-07-11; each user enrols at their next login; the *MFA enforcement* setting is inert.
7. Run `scripts/smoke.sh` and `audit:verify`; then follow the release checklist's post-deploy section as if it were a release.

---

## 10. Known gotchas

- **Migrations are cumulative and batched.** One deploy can apply several releases' migrations in one batch. Read the list the deploy log prints and compare it with §2's expectation.
- **`migrate:rollback` is not a rollback here** — §4.3. DB rollback = restore the dump.
- **No drain step on the container swap (RES-12, open).** Coolify starts the new container, repoints Traefik and stops the old one; a request in flight on the old container at that instant is cut. Two settings close most of it and are not yet applied: give the app a stop grace period (Coolify → application → *Advanced* → stop timeout, e.g. 30 s, which becomes `docker stop -t`) and make PHP-FPM finish in-flight work inside it (`process_control_timeout = 20s` in the image's `php-fpm.conf`). Until then, deploy in the agreed low-activity window and warn the clinical owner (§2).
- **Deploy-on-green is prepared, not enabled.** `scripts/deploy-on-green.sh` (host-side) deploys `main` only when the Laravel CI run for that exact commit is green and it is not already live, then runs the smoke test; wire it as a host cron only after the operator has read §6 and decided to move from operator-triggered deploys (Auto Deploy is off by design).
- **Logs live in the container; sessions no longer do.** Since 2026-09-03 `SESSION_DRIVER=database` and `CACHE_STORE=database` (runtime env vars): sessions and the dashboard cache survive a redeploy and the session-revocation paths (password reset, MFA reset, self password change) actually work — with `file` they deleted `sessions` rows no session lived in. `LOG_CHANNEL` still writes under `storage/`, so a redeploy discards the previous container's log files unless `storage/` is mounted as a persistent volume in Coolify (check the app's *Storages* tab). Ship logs elsewhere before relying on them for an investigation.
- **A code change that "did not take"** is not stale OPcache any more (new container = fresh process). It is either a deploy that did not actually finish (check *Deployments*), or a build-time env var changed without a rebuild (§5). Hashed asset filenames make Cloudflare caching a non-issue for `/build/*`.
- **Never build assets on the host.** `public/build/` is committed; CI's build-reproducibility check (§11) fails if a `.vue`/CSS/JS change was pushed without rebuilding. Fix = `npm run build` locally, commit the diff.
- **Timezone.** Date columns are timezone-naive; if the app runs in UTC every same-day rule (readmission windows, "today" counts, session timeouts shown to users) is off by 3 h for a UTC+3 hospital. Set the timezone in Control → System (or `APP_TIMEZONE`) and verify on the dashboard.
- **The origin only talks to Cloudflare.** A DNS record that is not proxied — or a `curl` straight at the origin IP — gets no answer. Run `smoke.sh` against the public hostname.
- **Header oddities the smoke test will surface:** an `x-powered-by: PHP/x.y` leak means `expose_php` is on in the image's php.ini; duplicated `x-frame-options` / `x-content-type-options` means a proxy layer is adding its own copy (the app's `DENY` / `nosniff` must remain).
- **The Coolify API token** in the private ops note has gone stale once. Check `GET /api/v1/version` before an incident, not during one.
- **`legacy:import` needs the SELECT grant on `dmc_prod`** and **wipes MFA enrolment** unless you follow the keep-MFA path — §8.
- **Do not flip `consultations_source_of_truth`** without reading §7.
- **`QUEUE_CONNECTION=sync`** — `?async=1` report generation is not actually asynchronous; a long monthly PDF holds the request open.
- **`.prod-ready/` is a local audit workspace**, not part of the app; keep it out of commits.

---

## 11. CI

`.github/workflows/laravel-ci.yml` (workflow name **CI**) gates every push/PR to `main`. It is deliberately a separate file from the legacy app's `.github/workflows/ci.yml` at the repo root — the two apps have independent pipelines; do not merge them. Two independent jobs:

- **frontend** — `npm ci` → `npm audit --omit=dev` → Vitest → `npm run build` → the class allow-list drift guard (`scripts/check-source-allowlist.mjs`) → the contrast / perceptual-distance gate (`scripts/contrast.mjs`) → a **build-reproducibility check** (`git status --porcelain -- public/build` must be empty after a rebuild).
- **backend** — Composer install against a real MySQL 8 service container (mirrors `phpunit.xml`, which runs `RefreshDatabase` against MySQL so the actual SQL dialect is exercised), then the project's established **two-pass** `php artisan test`: everything except the `pdf` group, then the `pdf` group in its own process (dompdf's native font-compression state segfaults PHP if it shares a long-lived process with the rest of the suite). The `slow-import` group runs in pass one — it is what proves a data reload cannot destroy or misattribute the consultation ledger.

**Status (2026-09-03): CI runs and gates.** Actions billing is active (keep it so — when it lapses, jobs are created with zero steps and prove nothing), `composer audit` is a blocking gate arbitrated by `scripts/composer-audit-gate.php`, and the four jobs are **required, admin-enforced status checks on `main`** — the branch accepts pull requests only, so the code that reaches production has passed them. The workflow has no path filter (a required check that does not run blocks a merge as *Expected — waiting*). A red build means a genuine test regression, a style drift (Pint), a coverage drop below the Vitest thresholds, a contrast regression, allow-list drift, or an un-rebuilt `public/build` — none of these is neutral logging; the gates are the assertion. Run the [`RELEASE-CHECKLIST.md`](RELEASE-CHECKLIST.md) gates locally when you touch something the CI runtime cannot exercise.
