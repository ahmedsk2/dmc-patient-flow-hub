# Database backup & restore — runbook (DATA-02 / DATA-03)

> **What this closes.** Until this shipped there was **no automated database backup**: six ad-hoc,
> *plaintext* `mysqldump` gzips in `/home/ubuntu` on the same host as the database. A disk failure,
> ransomware, a bad migration or a crashed `legacy:import` would have permanently destroyed
> ~37,662 admissions and ~17,435 patient records. This runbook stands up a nightly **encrypted,
> off-box** backup, a daily **in-app freshness check**, and a **restore drill** that proves the backup
> can actually be used and measures how long that takes.

**Components (all under `laravel/`):**

| Piece | Where it runs | What it does |
|---|---|---|
| `scripts/backup/db-backup.py` | Host cron, as root, nightly 02:15 | `mysqldump` inside the MySQL container → gzip → AES-256-CBC (PBKDF2) → local copy → SigV4 PUT to the backup bucket → heartbeat `LATEST.json` |
| `php artisan backup:verify` | App scheduler, daily 06:30 | Reads `LATEST.json` from the backup bucket, HEAD-checks the object, alerts every active admin in-app (`backup.stale`) if it is older than 26 h, missing, or unverifiable |
| `scripts/backup/binlog-ship.py` | Host cron, as root, **hourly at :40** | `FLUSH BINARY LOGS` inside the MySQL container, then for every **closed** binary log not yet archived: stream it out → gzip → AES-256-CBC (PBKDF2 — the same key and the same format as the nightly dump) → SigV4 PUT under `…/binlogs/YYYY/MM/` → heartbeat `binlogs/LATEST.json`. Idempotent, and it **never deletes a binary log**. This is what makes point-in-time recovery possible (§10). |
| `scripts/backup/db-restore-drill.sh` | Host, as root, **monthly by hand** | Downloads the latest backup, restores it into a scratch DB `dmc_restore_drill`, prints row counts and timings, drops the scratch DB. Never touches `dmc_demo`. |
| `scripts/backup/test_db_backup.py` | Anywhere (`python3 -m unittest`) | Proves the Python SigV4 port against the same published AWS vectors as the PHP class, plus config/naming/pruning logic |
| `scripts/backup/test_binlog_ship.py` | Anywhere (`python3 -m unittest`), and **blocking in CI** | Proves the shipper's idempotency (state file), that the active log is never shipped, the name/size/magic-byte guards, per-file failure isolation, expired-window detection, that a dying encrypt child cannot deadlock the run, and that its ciphertext opens with the **exact** decrypt command the drill uses |

The pipeline streams: **no plaintext SQL is ever written to disk** — not during backup, not during
`--restore-check`, not during binlog shipping, and not during the drill (decrypt → gunzip → mysql all
happen in a pipe). The one deliberate exception is a point-in-time **replay** (§10.5): `mysqlbinlog`
needs real files, so decrypted binary logs land in a mode-700 work directory, are read from there
through a read-only mount (never copied into a container), and are shredded after.

---

## 1. RPO / RTO — what this actually gives you (plain English)

- **RPO (how much you can lose) — depends on whether the hourly binlog shipper is running:**
  - **≤ 1 hour — the current production state.** `binlog-ship.py` has run from cron since
    2026-09-03 (§10) and every change MySQL records in its binary log is copied off the host at
    minute 40 of every hour, so at worst you lose the changes made since the last hourly ship.
  - **≤ 24 hours from the nightly dump alone** — the fallback for anything the binary log does not
    cover (§10.6), and what a *newly built* host has until an operator repeats the install in §10.2
    (§5.1 is that exact scenario). In the worst case — the host dies at 02:14 with no binlog
    shipping reaching it — everything entered since the previous night's 02:15 backup is gone.

  Two backups, two jobs: the nightly dump is the **base**, the shipped binary logs are the
  **increment**. Neither is useful for point-in-time recovery without the other.
- **RTO (how long to get back up): the number the drill prints.** `db-restore-drill.sh` reports
  `download=…s restore=…s total=…s`. Until the first drill has been run on production, RTO is
  **unmeasured** — do not quote a number you have not measured. Add the human steps (find a host,
  install Docker/MySQL, redeploy the app via Coolify, DNS) on top of the printed figure; that sum is
  the honest RTO. Record every drill in §8 so the number is defensible.
- **What is protected:** the whole `dmc_demo` schema and data — patients, admissions, consultations,
  users (incl. MFA secrets, which are encrypted with `APP_KEY`), settings, audit log, notifications,
  routines and triggers.
- **What is NOT in the backup and must be kept elsewhere:** the app's `.env` (`APP_KEY` — **without
  it MFA secrets and the encrypted SMTP password in a restored DB are unreadable**), the backup key
  file itself, `storage/app` uploads (none in use today), and the Coolify/OCI configuration.

---

## 2. One-time install on the database host (Ubuntu 24.04)

Everything below runs as **root** on the host that runs the MySQL container (`u8ha9zwdgekz9djnjt1ndisf`).

### 2.1 Put the scripts in place

```bash
sudo mkdir -p /opt/dmc/backup
sudo cp laravel/scripts/backup/db-backup.py laravel/scripts/backup/binlog-ship.py \
        laravel/scripts/backup/db-restore-drill.sh /opt/dmc/backup/
sudo chmod 750 /opt/dmc/backup /opt/dmc/backup/db-backup.py /opt/dmc/backup/binlog-ship.py \
        /opt/dmc/backup/db-restore-drill.sh
sudo chown -R root:root /opt/dmc/backup
python3 --version && openssl version && docker --version      # all three must exist
```

`binlog-ship.py` loads `db-backup.py` from the same directory (it reuses its SigV4 client, config
loader and encryption commands), so the two files must stay **side by side**. Its own cron and first
run are in §10.2.

> **Reinstall after merging this change.** `db-backup.py`'s `mysqldump` command now carries
> `--source-data=2` (§10.5, §10.6) — the host copy at `/opt/dmc/backup/db-backup.py` is a plain file
> copy, not a symlink or a checkout, so it does **not** pick this up on its own. An operator must
> `sudo cp laravel/scripts/backup/db-backup.py /opt/dmc/backup/` (same `chmod 750` / `chown root:root`
> as below) after this merge reaches `main`, or every dump keeps being taken the old way — harmless
> (the dump itself is unaffected), but a replay against one of those dumps still needs the
> `--start-datetime` fallback in §10.5 step 3.

Rotate the logs these jobs write — they grow forever otherwise, and a full `/var` stops both the
backup and the shipper. They contain object names, byte counts and hashes only: **no PHI**, no
secrets. `/etc/logrotate.d/dmc-backup`:

```
/var/log/dmc-backup.log /var/log/dmc-backup.cron.log
/var/log/dmc-binlog-ship.log /var/log/dmc-binlog-ship.cron.log {
    weekly
    rotate 12
    compress
    delaycompress
    missingok
    notifempty
    create 0640 root adm
}
```

```bash
sudo chmod 644 /etc/logrotate.d/dmc-backup
sudo logrotate --debug /etc/logrotate.d/dmc-backup     # dry run; prints what it would do
```

### 2.2 Generate the encryption key — and escrow it

```bash
sudo sh -c 'umask 077; openssl rand -base64 48 > /root/.dmc-backup.key'
sudo chmod 600 /root/.dmc-backup.key
```

**The key is the backup.** A backup you cannot decrypt is not a backup. Immediately copy the
*contents* of `/root/.dmc-backup.key` into the hospital's password vault / sealed envelope process
(**two** custodians), labelled `DMC DB backup key — created <date>`. If the host is lost, that copy
is the only way to read the off-box backups. Never commit it, never email it, never put it in
Coolify env vars.

### 2.3 Create the config file (root-only, mode 600)

`/root/.dmc-backup.env` — `KEY=value` lines, no shell expansion:

```ini
# S3-compatible target: OCI Object Storage (in-Kingdom), the SAME endpoint/region/credentials the
# app uses for AUDIT_S3_* — but a SEPARATE bucket with its own lifecycle rule (§6).
S3_ENDPOINT=https://<namespace>.compat.objectstorage.me-riyadh-1.oraclecloud.com
S3_REGION=me-riyadh-1
S3_BUCKET=dmc-db-backups
S3_ACCESS_KEY=<customer secret key id>
S3_SECRET=<customer secret key>

# The MySQL 8 container (docker ps) — the root password is read INSIDE the container from its own
# MYSQL_ROOT_PASSWORD env var; it is never written here.
MYSQL_CONTAINER=u8ha9zwdgekz9djnjt1ndisf
DB_NAME=dmc_demo

KEYFILE=/root/.dmc-backup.key
LOCAL_KEEP_DAYS=2                 # encrypted local copies kept in LOCAL_DIR (older ones deleted)
# LOCAL_DIR=/var/backups/dmc      # default
# LOG_FILE=/var/log/dmc-backup.log
# S3_PREFIX=db-backups/dmc_demo   # default = db-backups/<DB_NAME>; must match DB_BACKUP_S3_PREFIX in the app
```

```bash
sudo chmod 600 /root/.dmc-backup.env
```

The script refuses to run if the env file or key file is readable by group/other.

### 2.4 First run, by hand

```bash
sudo /usr/bin/python3 /opt/dmc/backup/db-backup.py --dry-run     # dump + encrypt + local copy, NO upload
sudo /usr/bin/python3 /opt/dmc/backup/db-backup.py               # the real thing
sudo tail -n 3 /var/log/dmc-backup.log
```

Expected log line: `… OK object=db-backups/dmc_demo/2026/09/dmc_demo-2026-09-03T021507Z.sql.gz.enc bytes=… sha256=… plaintext_bytes=… tables=… pruned=0 duration_s=…`

Then prove the object is readable end-to-end (downloads, decrypts in a pipe, checks it is a complete
mysqldump — nothing is loaded into MySQL):

```bash
sudo /usr/bin/python3 /opt/dmc/backup/db-backup.py --restore-check db-backups/dmc_demo/2026/09/dmc_demo-2026-09-03T021507Z.sql.gz.enc
```

### 2.5 Cron

`/etc/cron.d/dmc-db-backup`:

```cron
# DMC nightly encrypted off-box DB backup (docs/BACKUP-AND-RESTORE.md). Host time (UTC on this box).
15 2 * * * root /usr/bin/python3 /opt/dmc/backup/db-backup.py >>/var/log/dmc-backup.cron.log 2>&1
```

```bash
sudo chmod 644 /etc/cron.d/dmc-db-backup
```

The script takes an exclusive lock, so an over-running backup can never overlap the next one. Exit
codes: `0` success, `1` failure (one clear `FAIL step=… error=…` line on stderr and in the log), `2`
configuration error.

### 2.6 Tell the app where the bucket is

In the app's environment (Coolify → the Laravel app → Environment):

```
DB_BACKUP_S3_BUCKET=dmc-db-backups
# DB_BACKUP_S3_PREFIX=db-backups/dmc_demo          (only if you changed S3_PREFIX above)
# DB_BACKUP_BINLOG_S3_PREFIX=…/binlogs             (defaults to DB_BACKUP_S3_PREFIX + /binlogs)
# DB_BACKUP_BINLOG_MAX_AGE_HOURS=2                 (staleness window for the hourly shipper, §10.4)
```

`AUDIT_S3_ENDPOINT / AUDIT_S3_REGION / AUDIT_S3_ACCESS_KEY / AUDIT_S3_SECRET` are reused as-is. Then:

```bash
php artisan backup:verify          # exit 0 + "Backup OK — … is N h old"
```

### 2.7 Shred the old plaintext dumps

Once §2.4 has succeeded **and** a restore drill (§4) has passed, delete the unencrypted PHI copies:

```bash
ls -la /home/ubuntu/*.sql.gz
sudo shred -u /home/ubuntu/*.sql.gz        # or rm if the filesystem does not support shred
```

---

## 3. What lands in the bucket

```
dmc-db-backups/
└── db-backups/dmc_demo/
    ├── LATEST.json                                   ← heartbeat, overwritten after every success
    └── 2026/09/dmc_demo-2026-09-03T021507Z.sql.gz.enc
```

`LATEST.json`:

```json
{
  "object": "db-backups/dmc_demo/2026/09/dmc_demo-2026-09-03T021507Z.sql.gz.enc",
  "bytes": 123456789,
  "sha256_of_ciphertext": "…64 hex…",
  "md5_of_ciphertext": "…32 hex…",
  "created_at": "2026-09-03T02:15:07Z",
  "db": "dmc_demo", "plaintext_bytes": 987654321, "cipher": "aes-256-cbc/pbkdf2-200000",
  "host": "dmc-db-host", "producer": "scripts/backup/db-backup.py"
}
```

Upload integrity: the script sends a signed `Content-MD5` (the server rejects a corrupted body) **and**
compares the returned ETag with the local MD5 (single-part upload). The heartbeat's
`sha256_of_ciphertext` is re-checked by `--restore-check` and is what a future verifier can use to
detect silent bit-rot without downloading.

Encryption: `openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt -pass file:<key>`. To decrypt by hand
(only ever into a pipe on a trusted machine):

```bash
openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass file:/root/.dmc-backup.key -in X.sql.gz.enc | gunzip -c | head
```

---

## 4. Restore DRILL (monthly, safe on production)

```bash
sudo /opt/dmc/backup/db-restore-drill.sh --check-only     # download + decrypt; shows the CREATE DATABASE/USE lines that WOULD run
sudo /opt/dmc/backup/db-restore-drill.sh                  # restore LATEST into dmc_restore_drill, count, drop
COMPARE_LIVE=1 sudo /opt/dmc/backup/db-restore-drill.sh   # additionally prints the live DB's counts (read-only SELECTs)
```

What it does, in order: read `LATEST.json` → SigV4-download the object (still encrypted, into a
700 work dir) → `DROP/CREATE DATABASE dmc_restore_drill` → `openssl -d | gunzip | sed | mysql
--database=dmc_restore_drill` (every `` `dmc_demo` `` reference in the dump is rewritten to the
scratch name, and mysql's default database is the scratch one, so nothing can land in the live DB)
→ `SELECT COUNT(*)` for `patients / admissions / users / consultations / audit_log` → `DROP DATABASE
dmc_restore_drill` (also on failure, via `trap`) → prints `DRILL OK … download=Ns restore=Ns total=Ns`.

Sanity: the restored counts should be *slightly below* the live counts (the unit kept working after
02:15) and never above. Record the run in §8. The `total=` figure is your measured RTO (plus the
human steps in §1).

---

## 5. FULL restore (real incident)

Only an admin who has read this whole section should do this. Take your time; a wrong step here is
worse than an extra hour of downtime.

> **Restoring to a *moment* rather than to last night's backup?** That is point-in-time recovery —
> read **§10** first and work through it in a scratch database. Typical trigger: a bad migration, a
> wrong bulk edit or a crashed `legacy:import` at 14:00, where restoring the 02:15 dump alone would
> throw away a whole morning of clinical work. §10 replays the shipped binary logs on top of this
> restore and stops just before the mistake.

1. **Freeze.** Put the app in maintenance mode (`php artisan down` in the app container) or stop the
   app container in Coolify, so nothing writes to the DB you are about to replace.
2. **Snapshot what is there now**, even if it looks broken — `sudo /usr/bin/python3
   /opt/dmc/backup/db-backup.py` (it will upload a fresh encrypted copy if the DB still answers).
   If the DB is unreachable, skip.
3. **Pick the object.** Usually `LATEST.json`'s `object`. For "we need the data as of Tuesday", list
   the bucket (OCI console) and pick `…/YYYY/MM/dmc_demo-<Tuesday 02:15>.sql.gz.enc`.
4. **Prove it first:** `db-backup.py --restore-check <object>` — must print `RESTORE-CHECK ok`.
5. **Restore into the live database name** (this is the one command that overwrites `dmc_demo`):

   ```bash
   set -a; . /root/.dmc-backup.env; set +a
   W=$(mktemp -d -p /var/backups/dmc); chmod 700 "$W"
   python3 /opt/dmc/backup/db-backup.py --download "<object>" "$W/b.enc"
   openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass file:$KEYFILE -in "$W/b.enc" \
     | gunzip -c \
     | docker exec -i "$MYSQL_CONTAINER" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql'
   rm -rf "$W"
   ```

   The dump was taken with `--databases dmc_demo`, so it carries `CREATE DATABASE IF NOT EXISTS
   dmc_demo` + `USE dmc_demo` and recreates every table (`DROP TABLE IF EXISTS` first). On a brand-new
   host: start a MySQL 8 container with the same `MYSQL_ROOT_PASSWORD`/`MYSQL_DATABASE=dmc_demo` and
   the app's DB user, then run the same pipe.
6. **Reconcile:**
   - `php artisan migrate` (should say *Nothing to migrate* unless the backup predates a deploy —
     then it applies the missing migrations).
   - `php artisan audit:verify` — the hash chain must be intact up to the restore point. (As an
     aside: every row `audit:verify` has ever walked against `dmc_demo` has been hashed —
     `2026_06_14_000004_add_hash_chain_to_audit_log` added `prev_hash`/`row_hash` well before
     production existed, `App\Support\Audit::log()` is the table's only writer and has populated
     both columns since that migration landed, and `legacy:import` never touches `audit_log` — so
     there should be **no** pre-chain row to find. `audit:verify`'s own `$unhashed` counter is the
     live check: it warns "N pre-chain row(s) without a hash" only if that count is non-zero. It has
     read zero on every drill and rehearsal logged in §8. If a restore ever shows a non-zero count,
     that is new information worth investigating — **never backfill or otherwise write to
     `row_hash`/`prev_hash` to make the warning go away**; the column being NULL on a genuinely
     pre-chain row is the honest state, not a defect.)
   - **Audit archive reconciliation — do this BEFORE any new write can reach `audit_log`,** because
     the restored table's own row ids can collide with ids the write-once archive already holds for
     rows the restore lost (below):
     1. `php artisan audit:ship --status` — read-only; prints
        `audit_shipped_through_id=<mark> max_audit_log_id=<local max> pending=<n>` without shipping
        anything or touching the mark. If `<mark>` is **greater than** `<local max>`, the restore
        lost rows that had *already* been shipped off-box before the incident — expected whenever the
        restore point is older than the last successful `audit:ship` run, and not itself a problem;
        the archive is authoritative for those rows, as it is meant to be.
     2. Find the archive's own newest shipped id without downloading anything: `AuditShip::handle()`
        names every object `audit/<Y>/<m>/<d>/<firstId>-<lastId>-<runTimestamp>.ndjson`, so the id
        range is in the **key name** — list the bucket's `audit/` prefix (OCI console, or any
        S3-compatible `list-objects` call against the `AUDIT_S3_*` endpoint) for the most recent
        date, and read `<lastId>` off the newest key. (The NDJSON bodies carry patient identifiers in
        `details` for some actions — Secret per `DATA-CLASSIFICATION.md` — so there is no need to
        open one for this check, only to list keys.)
     3. **If the archive's newest id is greater than the restored table's local max id**, new local
        writes must not be allowed to reuse those ids — the table's own `AUTO_INCREMENT` counter came
        back from the dump set to whatever it was *at dump time*, which is behind what was really
        issued before the incident. Move it past the archive's true newest id, once, before
        unfreezing the app:
        ```sql
        ALTER TABLE audit_log AUTO_INCREMENT = <archive's newest id + 1>;
        ```
        This touches only the counter — it inserts, edits and deletes no row, so it does not disturb
        the hash chain. Then set the bookmark to match, so `audit:ship` does not waste a run
        re-checking a gap that can never be filled locally again — key the `UPDATE` off "first row by
        id", the same way `App\Models\Setting::current()` identifies the singleton row (its own code
        comment explains why: `id` is guarded, so the row is never assumed to be `id = 1`):
        ```sql
        UPDATE settings SET audit_shipped_through_id = <archive's newest id> ORDER BY id LIMIT 1;
        ```
        Check `ROW_COUNT()` afterwards — it must be `1`. A `WHERE id = 1` shortcut here would silently
        match zero rows if the settings row's id is ever not `1`, leaving the bookmark stale with no
        error while the `AUTO_INCREMENT` bump above still took effect, which is worse than doing
        neither. (There is no `artisan` command for this write — it is a deliberate, logged, one-time
        DBA action on the bookmark column, not on any audit row.) Skip both statements if the archive's
        newest id was **not** greater than the local max — nothing to reconcile, `audit:ship` resumes
        normally.
     4. **Record the lost window** (which ids/dates exist in the archive but no longer locally) in the
        incident record, same spirit as step 8 below for clinical data — the archive still has those
        rows for audit purposes even though `dmc_demo` does not.
   - Spot-check today's census on the dashboard against the ward.
7. **Reconnect and unfreeze:** restore the app's `.env` (`APP_KEY` **must** be the one in use when
   the backup was taken — otherwise MFA secrets will not decrypt and every user is locked out of
   MFA; see the MFA reset procedure in the auth runbook), `php artisan up` / start the container.
8. **Tell people** what window of data was lost (from the backup's `created_at` to the incident) —
   clinicians will need to re-enter admissions/discharges/consultations from that window.
9. Run `php artisan backup:verify` and a fresh `db-backup.py` so the next night starts clean.

### 5.1 Whole-server loss (RES-09) — **data half rehearsed 2026-09-22, application half 2026-09-23**

> **Status.** The **data** half of this procedure was rehearsed end to end on 2026-09-22 against a
> throwaway OCI instance (§8's drill log has the row). Measured, on a 2-OCPU/12 GB Ampere instance in
> the same region, from "launch the replacement" to "the database is back and provably identical":
> instance reachable over SSH **75 s**, Docker + tooling **33 s**, MySQL 8.4 up **45 s** (including the
> image pull), latest dump downloaded and restored **8 s**, PITR tools image built **11 s**, shipped
> binary logs replayed **4 s** — about **3 minutes of machine time**, plus whatever the operator takes
> between steps. Proof: the recovered `audit_log` was byte-identical to production's over the
> recovered range (same SHA-256 over `id:row_hash`), with 17,435 patients / 37,662 admissions / 331
> users restored.
>
> **The application half was rehearsed on 2026-09-23** on another throwaway instance (§8's drill log):
> launch → SSH **64 s**, Docker + Coolify 4.1.2 installed **130 s**, the app recreated through
> Coolify's API and built from source **214 s**, `/health` ok once the scheduler ran. With the data
> half that is roughly **12 minutes of machine time** from nothing to a serving app, plus operator
> time. It found two defects in this procedure, fixed below (step 2): the first deploy onto an empty
> database can never pass its health check, and production depends on an undocumented Nixpacks
> setting without which the container never starts. **Still not exercised: the DNS repoint** (the
> production record was deliberately left alone); a proxied Cloudflare record normally takes effect
> in seconds.
>
> Three defects in this very procedure were found by rehearsing it, and are fixed below: the PITR
> tools image could not be built at all (its pinned MySQL version had gone stale), the replay had no
> way to find the archived binary logs once the shipper's state file died with the host, and the
> listing the fix depends on was truncated at 64 KB. A procedure nobody has run is a hypothesis.

Scenario: the OCI instance itself is gone — destroyed, unrecoverable, or the tenancy is
inaccessible — not merely "the database container crashed" (that is §5 above) or "the app deploy is
bad" (`DEPLOY-LARAVEL.md` §4). Everything on that host's local disk is gone: the MySQL data volume,
`/opt/dmc/backup/`, `/root/.dmc-backup.env` and `/root/.dmc-backup.key`, host crons, Coolify itself.
**What survives, because it was deliberately placed off that host:** the encrypted dumps and binlogs
in OCI Object Storage bucket `dmc-db-backups` (in-Kingdom, a separate OCI resource from the compute
instance), the audit archive bucket `dmc-audit-log`, the GitHub repository, and whatever the owner
holds outside the host (below).

1. **Stand up a replacement host.** A new OCI Ubuntu compute instance in `me-riyadh-1` (the app's
   region — Saudi PDPL/SDAIA data residency, `CLAUDE.md` §1), Docker + Coolify v4 installed fresh
   (`DEPLOY-LARAVEL.md` §9 step 1). Firewall 80/443 to **Cloudflare's published ranges only** — never
   `0.0.0.0/0` — and SSH key-only (§0's topology note: the origin talks to nothing else).
2. **Recreate the Coolify application.** Source = the GitHub repo, branch `main`, Nixpacks, base
   directory `laravel/` (`DEPLOY-LARAVEL.md` §9 step 2). Match production exactly (read off the live
   application 2026-09-23): exposed port **8000**, health check **`/login`**, post-deployment command
   `php artisan migrate --force`, and three Nixpacks variables set for build **and** runtime —
   `NIXPACKS_NODE_VERSION=22`, `NIXPACKS_PHP_ROOT_DIR=/app/public` and
   **`NIXPACKS_PHP_FALLBACK_PATH=` (present, empty)**. Without the empty fallback path Nixpacks writes
   a second `location /` into nginx's config (`duplicate location "/"`), nginx refuses to start and
   every deploy rolls back (found by the 2026-09-23 rehearsal). **Restore the database (step 5)
   before the first deploy**: `/login` needs the `sessions` table, and migrations only run after a
   deploy's health check has passed, so a first deploy onto an empty database can never succeed.
   On a genuinely empty database (a new environment), run `php artisan migrate --force` once from a
   one-off container of the built image first. This is a **new** Coolify application with a
   **new** uuid — every runbook line that names `v5d8vrnp418stpcwnup3yhta` (host-lookup-by-label in
   `dmc-schedule.sh`, the rollback script, the API examples) needs the new uuid substituted in. As
   soon as the container exists, **freeze it** (`php artisan down` inside it, same as §5 step 1) and
   leave it frozen through step 9 below — unlike §5, this procedure installs the host scheduler cron
   (step 7) *before* the audit reconciliation it must not race, and maintenance mode is what keeps
   `audit:ship` from running in between (Laravel's scheduler skips every command that is not
   `evenInMaintenanceMode()` while frozen, so the hourly `audit:ship` — not one of those — simply
   does not fire until `php artisan up` in step 9). **The freeze does not survive a container swap:**
   the file driver keeps its flag in the container's own `storage/framework`, and every redeploy —
   including the one step 3's environment variables need — starts a fresh container, unfrozen (ADR
   0002). Re-run `php artisan down` after **every** redeploy or restart in steps 3–8, and before step 7
   installs the crons confirm it holds:
   `docker exec <app container> test -f storage/framework/down && echo frozen`.
3. **Environment variables** (`DEPLOY-LARAVEL.md` §5 and §9 step 3), the load-bearing one being
   **`APP_KEY` from the owner's escrow copy — never `php artisan key:generate` on a server meant to
   hold real data.** A regenerated key makes every encrypted narrative column, `users.mfa_secret` and
   `settings.mail_password` in the restored database permanently unreadable (§1, §9 of
   `ENCRYPTION-AT-REST.md`). The rest of `DB_*` / `AUDIT_S3_*` / `SESSION_ENCRYPT` etc. as documented
   there; the backup bucket's own `S3_ACCESS_KEY`/`S3_SECRET` (below) are separate from `AUDIT_S3_*`.
4. **MySQL 8.4 container** on the new host, same shape as today's (§0's topology: `mysql:8`, utf8mb4,
   InnoDB, a dedicated `dmc_demo` app user — never root — `DEPLOY-LARAVEL.md` §9 step 1). Two things
   the rehearsal showed are easy to miss here:
   - **`/root/.dmc-backup.env` still names the OLD container.** Every backup/PITR command finds MySQL
     through `MYSQL_CONTAINER=`; point it at the new container's name before running any of them.
   - **The dump carries the database, not the accounts.** `mysqldump --databases dmc_demo` contains no
     `CREATE USER`, so after the restore recreate the app's own login and grant it — the app never
     connects as root:

     ```sql
     CREATE USER 'dmc_demo'@'%' IDENTIFIED BY '<new password>';
     GRANT ALL PRIVILEGES ON `dmc_demo`.* TO 'dmc_demo'@'%';
     ```
     then set the same password in the app's `DB_PASSWORD` (step 3).
5. **Restore the data — base dump, then replay as far as the archive allows.** This is §5 above (the
   latest dump the bucket holds) followed by §10.5 (every binlog shipped after that dump, replayed up
   to the moment the old host was lost) **into `dmc_demo` directly** — there is no old database to
   protect from a stray write any more, so skip the `dmc_restore_drill` detour and the
   `--rewrite-db` flag (§10.5's third bullet under step 3: only drop `--rewrite-db` when replaying
   onto a real `dmc_demo`, which this is). Needs the backup key from escrow (next step) to decrypt
   anything. Rehearsed specifics:
   - **Build the PITR tools image first** (§10.2) — `mysqlbinlog` is not in `mysql:8`, and the image
     takes its version from the base image, so build it on the new host rather than expecting a copy.
   - **The shipper's state file is gone with the old host, so the bucket is the inventory.**
     `python3 /opt/dmc/backup/db-backup.py --list-objects db-backups/dmc_demo/binlogs/` prints every
     archived binary log (`key<TAB>bytes`); replay from the one the dump's own coordinate names
     (§10.5 step 3) through the newest. Do not go looking for `/var/backups/dmc/binlog-shipped.json`
     — it died with the host, and the listing is what replaces it.
   - **The recovery point is the last binary log that was SHIPPED**, not the last one MySQL had: the
     active file was still on the lost host. That gap is the ≤ 1 h RPO in §1, made concrete.
   - `mysqlbinlog` with an empty file list prints its usage to stdout, which the `mysql` client then
     tries to execute — if the listing yields nothing at or after the coordinate, stop and find out
     why rather than "replaying" nothing.
6. **Reinstall the backup/shipping scripts and their secrets.** `/opt/dmc/backup/*.py` and
   `*.sh` are just files in this repo — §2.1 and §10.2 install them fresh. `/root/.dmc-backup.env`
   is rebuilt from the private ops note (bucket name, region, endpoint, the backup `S3_ACCESS_KEY` /
   `S3_SECRET` — **these must be held somewhere other than the lost host**, e.g. the owner's password
   vault alongside the backup key). `/root/.dmc-backup.key` is **only** recoverable from the escrowed
   copy §2.2 says to make at creation — there is no other copy anywhere, by design; without it every
   existing off-box backup is permanently unreadable ciphertext.
7. **Host crons**, all of them (`DEPLOY-LARAVEL.md` §6's table): `/usr/local/bin/dmc-schedule.sh`
   (Laravel scheduler — update the container label it greps for to the new Coolify uuid),
   `/etc/cron.d/dmc-db-backup` (§2.5), `/etc/cron.d/dmc-binlog-ship` (§10.2), and
   `/etc/logrotate.d/dmc-backup` (§2). None of these come back on their own — Coolify does not manage
   host crontab. `dmc-schedule.sh` starts firing every minute as soon as it is installed — the app
   is still frozen from step 2, which is what stops the hourly `audit:ship` inside it from running
   against a bookmark that has not been reconciled yet (step 9).
8. **The binlog shipper starts a fresh chain on this host, by design.** `binlog-ship.py`'s state file
   (`/var/backups/dmc/binlog-shipped.json`) is gone with the old host, and the restored MySQL
   instance's `@@server_uuid` is new (a fresh data directory), so §10.7's identity check has nothing
   to compare against — the first run on the new host simply starts shipping from whatever binary log
   MySQL opens after the restore, with no error. That is correct: the old host's archived binlogs are
   still in the bucket and were already used in step 5's replay; the new chain only needs to cover
   *from here forward*. Do not attempt to make the new server's binlog numbering continue the old
   one's — it cannot, and §10.7's guard exists precisely to stop that being tried by mistake.
9. **Audit reconciliation** — before anything else writes to `audit_log`, follow §5's step 6 (the
   full procedure, including the archive comparison and the `AUTO_INCREMENT` guard) exactly as if
   this were the plain §5 restore in step 5 above, because it is one. Only then, `php artisan up` to
   lift the freeze from step 2 — until this line the app has been frozen the whole time, including
   while the crons in step 7 were live, precisely so the hourly `audit:ship` could not run against
   the stale bookmark first.
10. **DNS.** Repoint the Cloudflare A/AAAA record for `dmc-new.towardpcc.com` at the new host's IP,
    **keeping it proxied** (orange-cloud) — an unproxied record gets no answer at all, because the
    origin firewall (step 1) only admits Cloudflare's ranges.
11. **Verify**, in this order: `scripts/smoke.sh` against the public hostname, `/health`, `php artisan
    audit:verify` on the restored `dmc_demo`, then the human check in `DEPLOY-LARAVEL.md` §3.3.
12. **Tell people which window was lost** — same as §5 step 8, but the window is now bounded by
    whatever the last binlog shipped before the host died, not by the nightly dump alone (that is the
    entire point of having binlog shipping at ≤ 1 h RPO instead of the 24 h dump-only figure).

**What only the owner holds, and this procedure cannot proceed without:** the escrowed
`/root/.dmc-backup.key` copy (§2.2 — no other copy exists anywhere by design), `APP_KEY` (escrowed
per `HANDOFF.md` — regenerating it is not a recovery option, it is a second, permanent data-loss
event on top of the first), OCI tenancy/console access to create the replacement instance and read
the two buckets' credentials, Cloudflare account access to repoint DNS, and GitHub access to the
private ops note carrying the Coolify API token and the exact `S3_ACCESS_KEY`/`S3_SECRET` pairs
(these are placeholders in §2.3's example env file, deliberately never committed). **This list is
itself a gap**: nothing in this repo currently proves those credentials are recoverable *without* the
lost host — verifying that (and rehearsing the whole section) is the follow-up this runbook update
does not close.

---

## 6. Retention

| Copy | Where | Kept for | Enforced by |
|---|---|---|---|
| Off-box, encrypted | OCI bucket `dmc-db-backups` (in-Kingdom, separate from the audit archive) | **90 days** [NEEDS LEGAL CONFIRMATION] | Bucket lifecycle rule — configured on the bucket by the orchestrator, NOT by these scripts |
| Local, encrypted | `/var/backups/dmc/` on the DB host (mode 700, files 600) | **2 days** (`LOCAL_KEEP_DAYS`) [NEEDS LEGAL CONFIRMATION] | `db-backup.py` prunes after each successful run |
| Local, plaintext | — | **never** | The pipeline never writes plaintext; §2.7 shreds the legacy dumps |

Every off-box object is patient data. The lifecycle period must be agreed with the hospital's
records-retention / privacy officer (PDPL + MOH health-record retention rules apply); until that is
minuted, treat 90 days as a placeholder, not a decision. Bucket versioning, if enabled, also needs a
retention decision.

**Writer key cannot delete (since 2026-09-23).** The key the scripts and the app use can **create,
overwrite, read and list — but not delete** (IAM policy `dmc-audit-writers-policy`, tightened
2026-09-23 and proven with a refused DELETE); expiry is the bucket's own 90-day lifecycle rule. A
stolen key can add junk but cannot wipe the backups.

**No second cloud region — owner decision, 2026-09-24.** Backups stay in **`me-riyadh-1` only**; the
owner keeps an additional local copy (below). History: a copy to **`me-jeddah-1`** (the other Saudi
OCI region) was attempted on 2026-09-23 and refused with `StorageQuotaExceeded` by the tenancy quota
policy **`ksa-data-residency`** (2026-08-08), which zeroes every data-bearing service `where
request.region != me-riyadh-1`. The quota stays. The empty bucket `dmc-db-backups-jed` was deleted on
2026-09-24; the `me-jeddah-1` subscription remains (OCI cannot remove a region subscription) and holds
nothing. Verified the same day, read-only: no replication policy on `dmc-db-backups` or
`dmc-audit-log`, no cross-region replica or cross-region backup of the host's boot volume.

**The owner's local copy.** Since 2026-09-24 the owner's daily workstation sync also pulls **this
pipeline's encrypted objects** (`*.sql.gz.enc`, `binlogs/…*.gz.enc`), copied exactly as stored and never
decrypted there. It fetches only new objects, skips the `LATEST.json` heartbeats, keeps each file for
90 days by the date in its **name** (the bucket's lifecycle period — so the local copy stays
independent: a wiped bucket deletes nothing locally), and refuses to run while the backup key is on the
same machine. Seeded 2026-09-24: 510 objects, 264 MB, all identical in name and size to the bucket.
(The same sync also mirrors `coolify-backups`, below, which holds Coolify's past **unencrypted** DMC
dumps up to 2026-09-23.) The core of it, with the owner's own OCI CLI profile:

```bash
oci os object bulk-download -bn dmc-db-backups --prefix db-backups/dmc_demo/ --exclude '*LATEST.json' --download-dir <local folder> --no-overwrite
```

A copy is restorable only together with the backup key and `APP_KEY` (§3), so keep those apart from
it — a copy stored next to its key is plaintext to whoever takes the disk — and keep the workstation's
disk encrypted (Windows device encryption / BitLocker). The bucket's 90-day expiry does not reach the
local copy: prune it by hand to the same retention (a placeholder pending legal, above).

**Coolify's own database backups — `dmc_demo` removed 2026-09-24.** Coolify's scheduled backup of the
shared MySQL container (daily 03:00 UTC, to the private in-Kingdom bucket `coolify-backups`) used to
include `dmc_demo` as a daily **plain-SQL** dump. Found on 2026-09-24 and, by owner decision the same
day, taken out of the job: it now dumps only `default`, so **this runbook's encrypted pipeline is the
only backup of the DMC database** (rollback: set the job's databases back to `default,dmc_demo` in
Coolify → the shared MySQL → Backups). What the job left behind, all unencrypted (OCI at-rest
encryption only): **66 dumps in the bucket** (2026-07-19 → 2026-09-23, ≈ 1.3 GB), which Coolify's
"keep 14 on S3" setting has not been pruning and the bucket (no lifecycle expiry; a 14-day retention
rule only blocks early deletion) will keep until someone deletes them — an owner step; **7 on the host**
under `/data/coolify/backups/databases/…/shared-mysql-…/`, which Coolify's local "keep 14" rule ages out
within about two weeks; and the owner's workstation mirror of the bucket.

**OCI boot-volume backups (found 2026-09-24).** Separately from everything above, OCI backs up the
host's **entire boot volume** — the whole disk, so the MySQL data of DMC *and of every other app on the
host* — under the volume backup policy `weekly-4` (weekly incremental, kept 4 weeks, no destination
region, so Riyadh only), and a manual full backup `manual-full-20260719-1911` (2026-07-19) has **no
expiry**. They are a coarse extra recovery path (bring back the whole server as it was on that day;
nothing in this runbook depends on it) and a copy of patient data that counts in the inventory; the
manual one's retention is an owner decision (REMAINING-WORK).

**Measured bucket volume (2026-09-22, `db-backup.py`/`binlog-ship.py`'s own log lines on the
host — hourly rotation has been running since 2026-09-04, §10.2).** Nightly dumps run about
**2.3 MB each**; hourly binlog shipping produces 24 objects/day totalling about **4.8 MB/day**
(≈ 200 KB per hour of ordinary clinical activity). At the 90-day placeholder above, that is
roughly **90 dumps ≈ 210 MB** plus **~2,160 binlog objects ≈ 430 MB** — well under a gigabyte, so
sizing is not the reason to reconsider the retention period. This replaces the earlier "measure
after the first week" placeholder; re-measure if activity volume changes materially (a much busier
unit, or a schema change that inflates row size). **The lifecycle rule itself is still an
OCI-console task for the owner, and 90 days is still a placeholder pending the records-retention
decision** — this section only answers "how big", not "how long".

---

## 7. Monitoring — how you find out it broke

- **In-app:** `backup:verify` runs daily at 06:30 (app time) and checks **both** heartbeats — the
  nightly dump's and the hourly binlog shipper's (§10.4 for the binlog rules). If `LATEST.json` is
  missing, older than
  26 h, points at an object that is gone, cannot be read (storage/credential error), or the bucket is
  not configured, **every active admin** gets ONE `backup.stale` bell notification saying why. It is
  not repeated daily while the same incident is open; it is auto-resolved when a fresh backup is
  seen again, so the *next* lapse alerts afresh. Observers and inactive accounts never get one.
- **Logs:** app `storage/logs` → `backup.verify_ok` (info) / `backup.stale` (warning) /
  `backup.verify_failed` (error). Host → `/var/log/dmc-backup.log`, one line per run, plus cron's
  `/var/log/dmc-backup.cron.log`.
- **Manually:** `php artisan backup:verify --max-age-hours=26` (exit 0 = fresh), or on the host
  `python3 /opt/dmc/backup/db-backup.py --print-latest`.

Common failures and what they mean:

| Log / alert | Meaning | Do |
|---|---|---|
| `FAIL step=dump … mysqldump exited 2` | container name wrong, MySQL down, or root password env missing | `docker ps`, `docker exec <c> env \| grep MYSQL_ROOT` |
| `dump is truncated` / `dump looks empty` | MySQL died mid-dump or the DB is empty | never uploaded — investigate MySQL, rerun |
| `FAIL step=upload … HTTP 403` | credentials or bucket policy | check `S3_ACCESS_KEY/S3_SECRET`, bucket exists, key has write |
| `upload integrity check failed: ETag …` | corruption in transit | rerun; if it persists, raise with OCI |
| `backup.stale reason=unconfigured` | `DB_BACKUP_S3_BUCKET`/`AUDIT_S3_*` missing in the app env | §2.6 |
| `backup.stale reason=binlog_stale` / `binlog_failed` | the hourly shipper stopped, or is running but cannot archive some files | §10.4, §10.7 |
| console says `Point-in-time recovery: NOT INSTALLED` | expected until §10.2 is done; never alerts | install the hourly cron (§10.2) |
| `backup.stale reason=missing` | no heartbeat / object gone | check cron ran (`grep CRON /var/log/syslog`), lifecycle rule not too aggressive |
| `RESTORE-CHECK FAIL … openssl decrypt exited 1 (wrong key?)` | key file changed / wrong host | restore the escrowed key (§2.2) |

---

## 8. Drill log

Add one row per drill (monthly) and per real restore. This table *is* the evidence for DATA-03.

| Date (UTC) | Run by | Object | download s | restore s | total s | patients / admissions / users / consultations | Result / notes |
|---|---|---|---|---|---|---|---|
| 2026-09-03 03:07 | Claude Code (on the owner's instruction) | db-backups/dmc_demo/2026/09/dmc_demo-2026-09-03T030653Z.sql.gz.enc | 1 | 6 | 8 | 17435 / 37662 / 331 / 0 | DRILL OK — first production drill; scratch DB counts matched the live DB (COMPARE_LIVE=1); RTO for a 20 MB dump ≈ 8 s plus operator time |
| 2026-09-03 18:08 | Claude Code (on the owner's instruction) | db-backups/dmc_demo/2026/09/dmc_demo-2026-09-03T165232Z.sql.gz.enc (the pre-deploy dump for `a4dd4bd`, taken by running the nightly script by hand) | 1 | 6 | 7 | 17435 / 37662 / 331 / 0 | DRILL OK — counts matched live except `audit_log` 421 vs 423 (two rows written since the dump, as expected). `audit:verify` was not run against the scratch DB because the drill drops it on exit; add a keep-scratch option before claiming that check |
| 2026-09-22 11:02 | Claude Code (on the owner's instruction) | db-backups/dmc_demo/2026/09/dmc_demo-2026-09-22T105019Z.sql.gz.enc (the pre-deploy dump for `fc44a0b`) | 1 | 6 | 7 | 17435 / 37662 / 331 / 0 | DRILL OK — monthly drill. Run twice (11:02, then 11:11 to capture the full count table; 0/7/7 s and 1/6/7 s). `audit_log` 861 restored vs 862 live: one row written after the dump, as expected |
| 2026-09-22 11:09 | Claude Code (on the owner's instruction) | **PITR rehearsal** (§10.5): base db-backups/dmc_demo/2026/09/dmc_demo-2026-09-22T021501Z.sql.gz.enc + binlog.000430–000439, replayed 02:15:01 → 10:30:00 into a throwaway server (`scripts/backup/pitr-rehearsal.sh`) | — | 6 base (incl. download) + 26 replay | 50 | 17435 / 37662 / — / 0 | **PASS — but only after fixing two defects in §10.5 the rehearsal found.** (1) the stock `mysql:8` image has **no `mysqlbinlog`** (the runbook said it did), so step 3 could not run at all → `pitr-tools.Dockerfile` (§10.2). (2) step 4's `audit:verify --env=restore-drill` would have checked the **live** chain — the app container takes `DB_DATABASE` from its process env, which a `.env` file never overrides → replaced by the one-off container. Then: `audit_log` 853 → 861, **exactly** the 861 live rows created before STOP; newest recovered row 10:00:02; `Chain intact: 861 hashed row(s)`. Admissions/patients did not move because nothing clinical was written in the window (the Laravel app is not yet the daily system) — `audit_log` is the proof |
| 2026-09-22 11:14 | Claude Code (on the owner's instruction) | PITR rehearsal, same inputs, re-run with the script switched to the **exact** documented step-3 command (tools container, work dir mounted read-only) | — | 7 base (incl. download) + 27 replay | 50 | 17435 / 37662 / — / 0 | PASS — identical result (853 → 861 = 861 live, chain intact). Throwaway server, its volume, the network and the work dir all gone afterwards (volumes 21 → 21, containers 30 → 30) |
| 2026-09-22 19:26 | Claude Code (on the owner's instruction) | PITR rehearsal on the **exact recorded position**: base db-backups/dmc_demo/2026/09/dmc_demo-2026-09-22T192313Z.sql.gz.enc (the first dump taken with `--source-data=2`) + binlog.000448, replayed from `--start-position=524989` to 19:26:00 | — | 7 base + 1 replay | 21 | 17435 / 37662 / — / 0 | PASS — the dump's own coordinate was read out of the encrypted copy in a pipe and used instead of a timestamp guess; 107 events applied; recovered `audit_log` 870 = the 870 live rows before the stop time; chain intact. The audit trail itself did not move (a quiet evening window, legitimate while this app is not the daily system) — which is why the script now also counts the events the replay carried |
| 2026-09-22 20:04–20:19 | Claude Code (owner-approved) | **Whole-server-loss rehearsal (RES-09, §5.1)** — a throwaway 2-OCPU/12 GB instance in me-riyadh-1, recovered from the off-box archive alone (base dump dmc_demo-2026-09-22T192313Z + shipped binlogs from the dump's own coordinate) | — | 8 restore + 4 replay (75 s to SSH, 33 s Docker/tooling, 45 s MySQL, 11 s tools image) | ≈180 s of machine time | 17435 / 37662 / 331 / 0 | **PASS for the data half.** Recovered `audit_log` digest over rows 1–870 identical to production's (`e2cbaeaa…b31a6a`); production had one newer row — the active binary log that a real loss takes with the host, i.e. the ≤ 1 h RPO. **Found and fixed three defects in the procedure**: the PITR tools image could not build (stale version pin), the replay had no inventory once the shipper's state file died with the host (added `db-backup.py --list-objects`), and that listing was truncated by the client's 64 KB body cap. **Not rehearsed:** Coolify install, app image rebuild, DNS repoint. Instance terminated with its boot volume; production untouched throughout |
| 2026-09-23 19:58 | Claude Code (on the owner's instruction) | db-backups/dmc_demo/2026/09/dmc_demo-2026-09-23T193634Z.sql.gz.enc (the pre-deploy dump for `4bd5bba`) | 0 | 6 | 6 | 17435 / 37662 / 331 / 0 | DRILL OK — monthly drill; the restored copy was identical to live, `audit_log` 895 = 895 |
| 2026-09-23 20:00 | Claude Code (on the owner's instruction) | **PITR rehearsal (quarterly)**: base db-backups/dmc_demo/2026/09/dmc_demo-2026-09-23T021501Z.sql.gz.enc + binlog.000456–000473, replayed 02:15:01 → 19:30:00 into a throwaway server (`pitr-rehearsal.sh`) | — | 8 base + 59 replay | 87 | 17435 / 37662 / — / 0 | PASS — 82,869 binlog events carried (a full working day, incl. three deploys); `audit_log` 878 → 895 = exactly the 895 live rows before the stop; chain intact; throwaway server, volume and work dir removed. The PITR tools image was **not** on the host (it had only been built on the 2026-09-22 throwaway instance) — rebuilt from `pitr-tools.Dockerfile` first; §10.2 already says to build it where you replay |
| 2026-09-23 20:11 | Claude Code (on the owner's instruction) | **Cutover reload rehearsal** (DEPLOY-LARAVEL §8, keep-MFA path): the latest dump restored into a throwaway database on the production server, `legacy:import` run from a one-off container of the live image under a temporary login limited to that database + read-only `dmc_prod` | — | 7 restore + 18 import | 29 | 17435 / 37662 / 331 / 0 | PASS — counts equal the legacy source; every authenticator enrolment and verified email carried over (3 / 4); settings byte-identical; audit chain intact (896); 52/52 figures reconcile; `notifications` cleared by the import (by design). Found: DEPLOY-LARAVEL §8 still cleared **file** sessions — sessions are in the database since 2026-09-03 and must be truncated there (fixed). Throwaway database and login removed |
| 2026-09-23 20:24–20:47 | Claude Code (on the owner's instruction) | **Whole-server-loss rehearsal, APPLICATION half (RES-09, §5.1)** — throwaway 2-OCPU/12 GB instance in me-riyadh-1: fresh Docker + Coolify 4.1.2, the app recreated through Coolify's API from GitHub `main` with production's settings, built from source | — | — | ≈ 7 min on a clean run (64 s to SSH, 130 s Docker + Coolify, 214 s build + start) | fresh database, no production data or secrets | **PASS after two procedure defects, both fixed in §5.1 step 2**: the first deploy onto an empty database can never pass its `/login` health check, and production relies on an undocumented `NIXPACKS_PHP_FALLBACK_PATH=` (empty) without which nginx has a duplicate `location /` and never starts. `/login`, `/up`, `/privacy`, `security.txt`, the 404 page and the security headers all served through the new proxy; `/health` ok once the scheduler ran. DNS not exercised. Instance terminated with its disk |

---

## 9. Testing the pieces without production

- `cd laravel/scripts/backup && python3 -m unittest -v test_db_backup test_binlog_ship` — SigV4
  vectors (the same AWS ones as `tests/Unit/S3SigV4Test.php`), config parsing, naming, pruning, ETag
  logic, and — when `openssl` is on PATH — end-to-end runs of both streaming pipelines against a fake
  `mysqldump`, a fake `docker exec mysql` / `cat`, and a fake bucket. The binlog suite also proves
  the hour-to-hour idempotency, that the active log is never shipped, that one unshippable file does
  not stop the others, that an expired window is detected and remembered, that a dying encrypt child
  cannot deadlock the run, and that its ciphertext opens with the **literal** `openssl enc -d …` line
  `db-restore-drill.sh` runs. **This runs in CI** (the `backend` job's "Backup tooling unit tests
  (Python)" step) and blocks the build.
- `php artisan test --filter="BackupVerifyTest|S3SigV4GetTest|S3SigV4Test"` — the in-app verifier
  (fresh / stale / missing / storage error / malformed / unconfigured / dedupe / auto-resolve /
  scheduled) against a faked HTTP transport.
- `npx vitest run resources/js/__tests__/notifText.backupStale.test.js` — the bell wording.
- **Sandbox run of the real scripts** (how this was verified before it ever touched production): point
  `S3_ENDPOINT` at a local S3 stand-in that verifies SigV4 signatures, `DB_NAME` at a local MySQL,
  substitute `mysqldump_cmd()` / a `docker` shim on `PATH` for the container hop, and run
  `db-backup.py --dry-run`, a full run, `--restore-check`, then `db-restore-drill.sh --check-only` and
  the full drill with `DMC_DRILL_SKIP_ROOT_CHECK=1 DMC_BACKUP_ENV=… DMC_BACKUP_PY=…`. That override
  only skips the "must be root" guard (root is needed on production solely to read the root-owned
  env/key files and to reach docker) — never set it on the real host.

---

## 10. Point-in-time recovery (binlogs)

> **Status.** **Installed and running in production** — `/etc/cron.d/dmc-binlog-ship` went live
> 2026-09-03 19:21 UTC (PR #19), and the point-in-time recovery it enables has itself been rehearsed
> end to end on a throwaway server (§8, 2026-09-22). RPO for anything the binary log covers is ≤ 1 h
> (§1). The procedure below (§10.2 on) is the install steps as run, kept as the reference for a
> reinstall — see §5.1 for the one scenario that needs them followed again from scratch.

### 10.1 What is already true on the server, and what the gap was

Verified on the live host on **2026-09-03** (`SHOW VARIABLES` on the MySQL 8.4.10 container
`u8ha9zwdgekz9djnjt1ndisf`, and `ls` in its data volume):

| Setting | Value | Why it matters |
|---|---|---|
| `log_bin` | `ON` | MySQL is **already** recording every change. Nothing has to be enabled. |
| `log_bin_basename` | `/var/lib/mysql/binlog` | The files are `binlog.000001`, `binlog.000002`, … in the data volume |
| `binlog_format` | `ROW` | Row images, not statements — replay is deterministic (no `NOW()` drift) |
| `sync_binlog` | `1` | Every commit is flushed to the binary log before it is acknowledged: no committed transaction is missing from the log |
| `binlog_expire_logs_seconds` | `2592000` (30 days) | **MySQL deletes its own old logs.** The shipper must never do it, and must archive a file before this window closes |
| `server_id` | `1` | Single server, no replication topology to reason about |
| Current files | `binlog.000002` ≈ 348 MB, `binlog.000003` ≈ 16 MB | Two files, so rotation has happened at least once |

So the recovery data existed — it just **lived in the same MySQL volume as the database it
protects, on the same single host**. A lost host, a lost volume or ransomware took the binary log
with the database, and there was no written procedure for using it. That is finding **DATA-02
"no point-in-time recovery"**. `binlog-ship.py` closes the copy half; this section closes the
procedure half.

### 10.2 Design and install (the operator does this)

Once an hour the shipper rotates the log MySQL is writing to, so the previous hour's changes are in
a **closed** file, and archives every closed file that is not off-box yet:

```
every hour at :40
             FLUSH BINARY LOGS            → the active file is closed, a new one opened
             SHOW BINARY LOGS             → [(name, size), …]; the LAST one is the new active file
             for each closed file not in the state file at the same size:
               docker exec <container> cat /var/lib/mysql/<name>
                 | gzip | openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt
                 → PUT db-backups/dmc_demo/binlogs/YYYY/MM/<name>.gz.enc
               state file updated (name, bytes, sha256 of the plaintext, object key)
             PUT db-backups/dmc_demo/binlogs/LATEST.json      ← heartbeat
```

Properties worth knowing before you rely on it:

- **The active file is never shipped.** It is still being written, so a copy would be torn. It goes
  off-box on the next hour's run, after the next `FLUSH` closes it. That is where the ≤ 1 h RPO
  number comes from.
- **Idempotent.** A file already recorded in the state file at the same size is skipped, so the job
  is safe to run twice, by hand, or after a partial failure. Rotated binary logs are immutable, so
  "same name + same size" is a sound identity. A file whose size *changed* is re-shipped and the run
  says so.
- **It never deletes a binary log.** MySQL's own `binlog_expire_logs_seconds` does that. Deleting
  here could destroy the only copy of a window that has not been archived yet.
- **Same key, same format, same bucket** as the nightly dump — one key to escrow (§2.2), one
  lifecycle rule, and the decrypt line in §3 works on these objects unchanged.
- **One bad file does not block the rest.** Each file is archived independently: a permanently
  unreadable one is reported and skipped while everything else still goes off-box, and the run exits
  1 naming what failed. (Stopping at the first failure would quietly stall every *later* file until
  MySQL expired it — losing data while looking like a single stuck file.)
- **It detects a hole in the chain.** If MySQL has expired binary logs that were never archived, the
  run fails and names them, `--list` flags them, and the fact is written into the state file's
  `gaps` permanently — because once the boundary moves the chain looks contiguous again even though
  a window of changes is gone for good. A hole fails the run once, not every hour forever.
- **It detects a server identity change** (`@@server_uuid`): a new data directory restarts binary-log
  numbering, so archived and live files with the same name are different logs and must never be
  mixed in a replay. The run stops and tells the operator what to do.
- **`--restore-check` proves an archived object is usable** — it decrypts and walks the event chain,
  so a truncated copy is caught before a recovery depends on it (§10.4).

Install, as root on the database host:

```bash
sudo cp laravel/scripts/backup/binlog-ship.py /opt/dmc/backup/
sudo chmod 750 /opt/dmc/backup/binlog-ship.py
sudo chown root:root /opt/dmc/backup/binlog-ship.py

# It reads the SAME /root/.dmc-backup.env db-backup.py uses. Every binlog key has a working
# default, so an existing config file needs NO edit. Override only if something is unusual:
#   BINLOG_DIR=/var/lib/mysql                       datadir inside the container
#   BINLOG_PREFIX=db-backups/dmc_demo/binlogs       object prefix
#   BINLOG_STATE_FILE=/var/backups/dmc/binlog-shipped.json
#   BINLOG_STATE_KEEP=2000                          expired-file records kept in the state file
#   BINLOG_LOG_FILE=/var/log/dmc-binlog-ship.log
#   BINLOG_DOCKER_USER=root                         only if the default container user cannot read
#                                                   the datadir (`docker exec … cat` gets EACCES)

sudo /usr/bin/python3 /opt/dmc/backup/binlog-ship.py --dry-run   # read-only: no FLUSH, no upload
sudo /usr/bin/python3 /opt/dmc/backup/binlog-ship.py             # the real first run
sudo tail -n 3 /var/log/dmc-binlog-ship.log
```

Expected: `… OK shipped=2 files=binlog.000002,binlog.000003 bytes=… active=binlog.000004 known=3 already=0 state_pruned=0 duration_s=…`

**The cron line the operator installs** — `/etc/cron.d/dmc-binlog-ship`:

```cron
# DMC hourly encrypted off-box MySQL binary-log shipping — point-in-time recovery.
# docs/BACKUP-AND-RESTORE.md §10. Host time (UTC on this box). Minute 40 keeps it clear of the
# 02:15 nightly dump and of the :00 audit shipping.
40 * * * * root /usr/bin/python3 /opt/dmc/backup/binlog-ship.py >>/var/log/dmc-binlog-ship.cron.log 2>&1
```

```bash
sudo chmod 644 /etc/cron.d/dmc-binlog-ship
```

The script takes its own exclusive lock (separate from the nightly backup's), so a long run can
never overlap the next hour. The MySQL calls and every child wait run under a timeout, so a wedged
docker daemon usually ends the run instead of holding the lock; the one unbounded step is the
streaming read of a binlog out of the container (a `cat` that hangs producing no bytes would hold
the lock), which is mitigated rather than prevented: each later hourly run exits 1 on the held lock,
and `backup:verify` raises the stale-heartbeat alert within its window. Exit codes: `0` success, `1` failure (one clear
`FAIL step=…` line on stderr and in the log), `2` configuration error.

**Disk sizing for `/var/backups/dmc`.** The shipper encrypts one binary log at a time into a work
directory there and deletes it as soon as the upload is confirmed, so its steady-state footprint is
**one** compressed binary log — not the whole archive. The peak is therefore set by the largest file
MySQL will produce; check it with `SHOW VARIABLES LIKE 'max_binlog_size'` and leave that much
headroom **on top of** the two nightly dumps `LOCAL_KEEP_DAYS` already keeps there. A crashed run's
work directory is swept at the start of the next run, so a failure cannot accumulate.

**The replay tool — build it now, not during an incident.** Shipping needs nothing but `cat`, but
*replaying* needs `mysqlbinlog`, and **the official `mysql:8` image does not contain it** (it ships
`mysql`, `mysqldump`, `mysqladmin` and `mysqlsh`; the 2026-09-22 rehearsal found this when step 3
died with `mysqlbinlog: command not found`). `scripts/backup/pitr-tools.Dockerfile` builds the
production server image plus exactly that one binary, taken from MySQL's signed client package of the
**same** version (the build checks the package signature and refuses a version mismatch). It needs
outbound HTTPS to `repo.mysql.com` once (~3.3 MB):

```bash
sudo cp laravel/scripts/backup/pitr-tools.Dockerfile laravel/scripts/backup/pitr-rehearsal.sh /opt/dmc/backup/
sudo chmod 644 /opt/dmc/backup/pitr-tools.Dockerfile && sudo chmod 750 /opt/dmc/backup/pitr-rehearsal.sh
# the tag is the SERVER's version — the build reads it out of the base image and fetches the
# matching client package, so the tool can never drift from the server that wrote the logs
V=$(sudo docker run --rm mysql:8 mysqld --version | sed -n 's/.* Ver \([0-9.]*\).*/\1/p')
sudo sh -c "docker build -t dmc/mysql-pitr:$V - < /opt/dmc/backup/pitr-tools.Dockerfile"
docker run --rm --entrypoint mysqlbinlog "dmc/mysql-pitr:$V" --version   # must match $V
```

(`sudo sh -c` because `/opt/dmc/backup` is root-only and the `<` redirect is opened by the calling
shell.) The version is no longer pinned in the Dockerfile: a pin went stale between 2026-09-03 and the
2026-09-22 whole-server-loss rehearsal, which then could not build the image **at all** — the failure mode
you would meet mid-recovery. **Rebuild it whenever the production MySQL image changes version** — pass
`--build-arg MYSQL_VERSION=<new>` and tag it `dmc/mysql-pitr:<new>` (the rehearsal script takes `IMG=`); a stale version fails the build rather than producing a mismatched
tool. The server's version is `docker exec <mysql container> mysqld --version`.

### 10.3 What lands in the bucket

```
dmc-db-backups/
└── db-backups/dmc_demo/
    ├── LATEST.json                                   ← nightly dump heartbeat (§3)
    ├── 2026/09/dmc_demo-2026-09-03T021507Z.sql.gz.enc
    └── binlogs/
        ├── LATEST.json                               ← shipper heartbeat, overwritten every hour
        └── 2026/09/binlog.000002-2026-09-03T034007Z.gz.enc
```

The object name carries the **shipping stamp** as well as the binary log's name. Binary-log numbering
restarts at `.000001` whenever MySQL gets a new data directory (a rebuild, a restore onto fresh
storage), so the name alone is not unique over the life of the archive: without the stamp, the second
generation's `binlog.000001` would overwrite the first generation's with a successful-looking PUT and
destroy archived recovery data. The shipper also HEAD-checks every key before writing it and refuses
to replace an object whose content differs.

`binlogs/LATEST.json` deliberately reuses the nightly heartbeat's field names (`object`, `bytes`,
`sha256_of_ciphertext`, `created_at`), so `backup:verify` reads it with the same code:

```json
{
  "object": "db-backups/dmc_demo/binlogs/2026/09/binlog.000003-2026-09-03T144007Z.gz.enc",
  "bytes": 4210688, "sha256_of_ciphertext": "…64 hex…", "md5_of_ciphertext": "…32 hex…",
  "created_at": "2026-09-03T14:40:07Z",        ← when the SHIPPER last completed  (age this)
  "last_shipped_at": "2026-09-03T14:40:07Z",   ← when the object above was uploaded
  "binlog": "binlog.000003", "plaintext_bytes": 16777216, "sha256_of_plaintext": "…64 hex…",
  "db": "dmc_demo", "shipped_this_run": 1,
  "failed_this_run": 0, "failed_binlogs": [],  ← alive but not archiving everything, if non-zero
  "expired_unshipped": null,                   ← a hole detected on THIS run
  "known_gaps": [],                            ← every hole ever detected — permanent facts
  "active_binlog": "binlog.000004", "known_binlogs": 3,
  "server_uuid": "…", "cipher": "aes-256-cbc/pbkdf2-200000", "host": "dmc-db-host",
  "producer": "scripts/backup/binlog-ship.py"
}
```

`created_at` is refreshed on **every** successful run, including a run that had nothing new to ship —
because for an hourly job the failure that matters is "the shipper stopped running". Age that field,
not `last_shipped_at`, and use a window of about **2 hours** (not the nightly job's 26). `object` is
`null` only before the very first file has ever been archived.

`failed_this_run` exists because age alone is not enough: a shipper that runs every hour but cannot
read one particular file keeps `created_at` fresh while binary logs quietly never reach the bucket.
**`backup:verify` alerts on a non-zero `failed_this_run` as well as on a stale `created_at`.**

### 10.4 Verify it is working

```bash
B=/opt/dmc/backup/binlog-ship.py
sudo /usr/bin/python3 $B --list          # every binary log + shipped/pending/ACTIVE, and any GAP
sudo /usr/bin/python3 $B --print-latest  # the binlogs/LATEST.json heartbeat
sudo jq '{files: (.files|length), gaps, server_uuid}' /var/backups/dmc/binlog-shipped.json
sudo tail -n 5 /var/log/dmc-binlog-ship.log
grep dmc-binlog /var/log/syslog | tail   # cron actually fired

# and prove the ARCHIVE is usable, not just present: download one object, decrypt + gunzip it in a
# pipe and walk its event chain. Prints the events and the time window the file covers, which is
# also how you pick the right files for a replay in §10.5.
sudo /usr/bin/python3 $B --restore-check db-backups/dmc_demo/binlogs/2026/09/binlog.000003-2026-09-03T144007Z.gz.enc
```

Expected from `--restore-check`: `RESTORE-CHECK ok object=… bytes=… events=… covers=2026-09-03T13:40:11Z..2026-09-03T14:39:58Z …`.
It reads the event chain directly (no MySQL needed) and fails if the archived copy is truncated,
which a plain byte count cannot detect. Run it after the first shipping run, and again whenever you
are about to rely on the archive.

Healthy signs: `--list` shows exactly one `ACTIVE` file, no `pending` ones and no `*** GAP ***`; the
heartbeat's `created_at` is under an hour old and `failed_this_run` is 0; the log has one `OK …` line
per hour. If `--list` shows files sitting in `pending`, the shipper is failing — read
`/var/log/dmc-binlog-ship.log`.

**In-app:** the daily `backup:verify` (06:30) reads `binlogs/LATEST.json` as well as the nightly one
and raises the same `backup.stale` bell notification for every active admin when the shipper's
heartbeat is older than `DB_BACKUP_BINLOG_MAX_AGE_HOURS` (default 2) or reports failed files. A
**missing** binlog heartbeat is reported as "NOT INSTALLED" and deliberately does **not** alert —
that is the state until an operator does §10.2, and the nightly-dump check already covers the 24-hour
RPO it implies. Because that check is daily, it is the backstop, not the fast signal: the fast signal
is the shipper's own non-zero exit in `/var/log/dmc-binlog-ship.cron.log`.

### 10.5 The recovery procedure

Scenario this is for: something destroyed or corrupted data at a known time — a bad migration, a
wrong bulk edit, a crashed `legacy:import` — and the 02:15 dump alone would discard everything the
unit did in between. **Do all of this in the scratch database first. Decide about promoting only
after the counts look right.**

**Step 1 — restore the base.** Restore the latest nightly dump into the scratch database exactly as
the drill does (§4), and note the dump's moment from its heartbeat:

```bash
sudo /opt/dmc/backup/db-restore-drill.sh          # → dmc_restore_drill, prints counts and timings
sudo /usr/bin/python3 /opt/dmc/backup/db-backup.py --print-latest | jq -r .created_at
#   e.g. 2026-09-03T02:15:07Z   ← this is <dump time> below, the --start-datetime FALLBACK
```

> The drill **drops** `dmc_restore_drill` when it exits. For a real recovery, restore it the same way
> but keep it: run the drill's own pipeline by hand (§4's description) or re-create the scratch DB
> and pipe the decrypted dump into `mysql --database=dmc_restore_drill` with the same
> `` sed 's/`dmc_demo`/`dmc_restore_drill`/g' `` rewrite. Nothing about the base restore changes.

**Read the dump's exact binlog coordinate, if it has one.** Dumps taken with `--source-data=2`
(every dump since this shipped, once an operator has reinstalled the host copy — §2.1) carry the
exact binlog file and position as a *commented* line near the top: `-- CHANGE REPLICATION SOURCE TO
SOURCE_LOG_FILE='binlog.000002', SOURCE_LOG_POS=1256;`. It is never executed — `mysqldump` writes it
purely for a human or a script to read. Find it the same way the dump itself was read: decrypt and
gunzip **in a pipe**, never to a file:

```bash
sudo /usr/bin/python3 /opt/dmc/backup/db-backup.py --download "<object>" "$W/base.sql.gz.enc"
openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass "file:$KEYFILE" -in "$W/base.sql.gz.enc" \
  | gunzip -c | head -n 40 | grep 'CHANGE REPLICATION SOURCE TO'
shred -u "$W/base.sql.gz.enc"
#   -- CHANGE REPLICATION SOURCE TO SOURCE_LOG_FILE='binlog.000002', SOURCE_LOG_POS=1256;
```

If that prints a line, use `SOURCE_LOG_FILE`/`SOURCE_LOG_POS` as `--start-position` in step 3 below —
exact, no boundary caveat. **If it prints nothing, the dump predates `--source-data=2`** (or the host
copy has not been reinstalled yet): fall back to `--start-datetime="<dump time>"` from the heartbeat
above, with the boundary caveat step 3 still documents for that path.

**Step 2 — fetch and decrypt the binary logs that cover the window.** You need every archived binlog
whose contents span *<dump time>* → *<the mistake>*, **in ascending sequence order**, including the
file that was active when the dump was taken. `binlog-ship.py --list`, the state file
(`/var/backups/dmc/binlog-shipped.json`, which records each object key) or a bucket listing will
tell you which ones those are.

```bash
W=$(mktemp -d -p /var/backups/dmc pitr-XXXXXX); chmod 700 "$W"

# Read ONLY the two values needed. `set -a; . /root/.dmc-backup.env` would export S3_SECRET into
# this shell (and into every child, and into the operator's history) — never do that.
read_cfg() { sed -n "s/^${1}=//p" /root/.dmc-backup.env | tail -n1 | tr -d "\"'"; }
KEYFILE=$(read_cfg KEYFILE); KEYFILE=${KEYFILE:-/root/.dmc-backup.key}
MYSQL_CONTAINER=$(read_cfg MYSQL_CONTAINER)

# The object keys carry the shipping stamp, so copy them from `--list` / the state file rather than
# guessing them; the file NAME inside the key is what mysqlbinlog needs on disk.
for OBJ in \
  db-backups/dmc_demo/binlogs/2026/09/binlog.000002-2026-09-03T034007Z.gz.enc \
  db-backups/dmc_demo/binlogs/2026/09/binlog.000003-2026-09-03T044007Z.gz.enc \
  db-backups/dmc_demo/binlogs/2026/09/binlog.000004-2026-09-03T054007Z.gz.enc ; do
  # strip exactly the "-YYYY-MM-DDTHHMMSSZ" ship stamp: `${N%-*}` would only remove "-03T034007Z"
  N=$(basename "$OBJ" .gz.enc); N=${N%-????-??-??T??????Z}     # binlog.000002
  python3 /opt/dmc/backup/db-backup.py --download "$OBJ" "$W/$N.gz.enc"
  openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass "file:$KEYFILE" -in "$W/$N.gz.enc" \
    | gunzip -c > "$W/$N"
  rm -f "$W/$N.gz.enc"
done
ls -la "$W"        # binlog.000002 binlog.000003 binlog.000004 — plaintext, mode 600, in a 700 dir
```

This is the one place plaintext touches the disk: `mysqlbinlog` needs real, seekable files. Keep the
work directory mode 700, do this on the database host (or another in-Kingdom host), and `shred` it
when you are done (step 5).

**Step 3 — replay into the scratch database, stopping before the mistake.**

Count the rows **before** the replay so step 4 can prove the replay did something:

`mysqlbinlog` is **not** in the production server image — it comes from the tools image built in
§10.2 (`dmc/mysql-pitr:8.4.10`). It runs in a one-off container with **no network** and the work
directory mounted **read-only**, and its output is piped into the production server's own `mysql`
client. So the decrypted files are read in place and never copied into a container's writable layer.

```bash
PITR_IMG=dmc/mysql-pitr:8.4.10
docker run --rm --entrypoint mysqlbinlog "$PITR_IMG" --version   # must be the server's version (mysqld --version)

# Preferred, when the dump carried --source-data=2 (see the extraction in step 1 above):
# --start-position applies to the FIRST file named below (SOURCE_LOG_FILE) — list files from
# there forward, in ascending order, exactly like the --start-datetime form.
docker run --rm --network none -v "$W":/pitr:ro --entrypoint mysqlbinlog "$PITR_IMG" \
    --rewrite-db="dmc_demo->dmc_restore_drill" \
    --database=dmc_restore_drill \
    --start-position=1256 \
    --stop-datetime="2026-09-03 13:59:00" \
    /pitr/binlog.000002 /pitr/binlog.000003 /pitr/binlog.000004 \
  | docker exec -i "$MYSQL_CONTAINER" sh -c \
      'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --database=dmc_restore_drill'

# Fallback, for a dump taken before --source-data=2 (no coordinate to read in step 1):
docker run --rm --network none -v "$W":/pitr:ro --entrypoint mysqlbinlog "$PITR_IMG" \
    --rewrite-db="dmc_demo->dmc_restore_drill" \
    --database=dmc_restore_drill \
    --start-datetime="2026-09-03 02:15:07" \
    --stop-datetime="2026-09-03 13:59:00" \
    /pitr/binlog.000002 /pitr/binlog.000003 /pitr/binlog.000004 \
  | docker exec -i "$MYSQL_CONTAINER" sh -c \
      'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --database=dmc_restore_drill'
```

Run **one** of the two, not both — `--start-position` and `--start-datetime` are alternatives, and
mixing them is meaningless (`mysqlbinlog` applies whichever is more restrictive per file). The
2026-09-22 rehearsal (`scripts/backup/pitr-rehearsal.sh`, §8) used the `--start-datetime` form
because its dumps predated this change; re-run it once a `--source-data=2` dump exists to exercise
the `--start-position` path for real, and record that as its own row in §8.

It prints `WARNING: The option --database has been used. It may filter parts of transactions, but
will include the GTIDs in any case` once per file. That is expected and harmless here: `gtid_mode` is
OFF on this server, so there are no GTIDs to include. The 2026-09-22 rehearsal ran **exactly** this
command (`scripts/backup/pitr-rehearsal.sh`, §8).

Five things about that command are load-bearing:

- **`--rewrite-db="dmc_demo->dmc_restore_drill"` is mandatory when replaying into a scratch
  database.** The binary log's events carry the database name (`USE dmc_demo`, and with `ROW` format
  the row events are bound to it), so *without* this flag the replay writes straight into the LIVE
  `dmc_demo` no matter what `--database=` you pass to the `mysql` client. Only drop `--rewrite-db`
  when you are deliberately replaying onto a full restore of the real `dmc_demo` (§5).
- **`--database` names the database AFTER the rewrite**, so it must be `dmc_restore_drill`, not
  `dmc_demo`. Get this wrong and `mysqlbinlog` filters out every event it just rewrote: the pipe
  succeeds, `mysql` reports nothing, and you get a **silent no-op** that looks exactly like a
  successful recovery. That is why step 4 checks for a non-zero row delta and not merely for the
  absence of errors.
- **`--rewrite-db` does NOT rewrite database-qualified names inside a statement.** It rewrites the
  event's database, but a statement that spells out `dmc_demo.patients` is replayed verbatim and
  lands in the live database. The app itself does not write qualified statements, but `legacy:import`
  and hand-run admin SQL can, and **this same server also holds `dmc_prod`** (the legacy import
  source), so a stray qualified statement has somewhere else to land too. Rehearse against a
  **throwaway MySQL container** — not the production one — and only bring the procedure to the
  production container once you have seen it behave there.
- **All the files go to ONE `mysqlbinlog` invocation, in ascending order.** A transaction can span a
  rotation; separate invocations piped separately would tear it.
- **Timestamps are the server's own** — this host runs UTC, and the heartbeat's `created_at` is UTC.
  `--stop-datetime` is exclusive of the moment you name, so pick the second *before* the mistake.
  `binlog-ship.py --restore-check <object>` prints each archived file's `covers=` window, which is
  the quickest way to confirm you have the right files before you start.
- **Two ways to anchor the start, depending on the dump.** A dump taken with `--source-data=2`
  records its exact binlog file+position (step 1 above) — use `--start-position` and there is no
  boundary ambiguity. A dump taken before that (or before the host copy is reinstalled, §2.1) has
  only `created_at`, second-precision, and `--single-transaction` means the snapshot is taken at the
  dump's *start* — so with `--start-datetime` expect a handful of events at the boundary to be
  re-applied; with `ROW` format those either produce identical rows or fail loudly on a duplicate key
  rather than silently duplicating data. If a boundary error stops a `--start-datetime` replay, note
  the position `mysqlbinlog` reports and resume with `--start-position` from there.

**Step 4 — verify before you promote anything.**

```bash
# counts in the recovered copy — compare with the same query run BEFORE the replay
docker exec -i "$MYSQL_CONTAINER" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql --database=dmc_restore_drill -t' <<'SQL'
SELECT 'patients' tbl, COUNT(*) n FROM patients
UNION ALL SELECT 'admissions', COUNT(*) FROM admissions
UNION ALL SELECT 'consultations', COUNT(*) FROM consultations
UNION ALL SELECT 'audit_log', COUNT(*) FROM audit_log;
SELECT MAX(created_at) AS newest_audit_row FROM audit_log;
SQL
```

**The row counts MUST have moved.** A replay that changes nothing is the failure this procedure is
most likely to hit — a wrong `--database`, a window that missed, files in the wrong order — and it
looks identical to success from the exit codes alone. If `audit_log` has not grown, stop: the replay
did not apply, and promoting the scratch database would throw away exactly the hours you were trying
to save. Treat "counts moved as expected" as the gate, not "no errors printed".

`newest_audit_row` must sit just under your `--stop-datetime`: that is the proof the replay reached
where you intended and no further. For an exact figure rather than "it grew", compare with the live
table: `audit_log.created_at` is written by the database in UTC, the same clock as
`--stop-datetime`, so the recovered count must **equal** `SELECT COUNT(*) FROM dmc_demo.audit_log
WHERE created_at < '<stop-datetime>'` (true while the live table is intact; a recovery *from* a
destroyed `audit_log` has only the growth check).

Then check the tamper-evident chain over the recovered rows with the app's own `audit:verify`, run
**once, in a one-off container** from the running app's image, pointed at the scratch database:

```bash
APP_C=$(docker ps --filter name=v5d8vrnp418stpcwnup3yhta --format '{{.Names}}' | head -n1)
APP_IMG=$(docker inspect --format '{{.Config.Image}}' "$APP_C")
NET=$(docker inspect --format '{{range $k, $v := .NetworkSettings.Networks}}{{$k}} {{end}}' "$MYSQL_CONTAINER" | awk '{print $1}')
APP_KEY=$(docker exec "$APP_C" printenv APP_KEY); export APP_KEY   # never echo it
DB_PASSWORD=$(docker exec "$MYSQL_CONTAINER" printenv MYSQL_ROOT_PASSWORD); export DB_PASSWORD
docker run --rm --network "$NET" --entrypoint sh \
    -e APP_KEY -e DB_PASSWORD -e APP_ENV=production -e APP_DEBUG=false \
    -e DB_CONNECTION=mysql -e DB_HOST="$MYSQL_CONTAINER" -e DB_PORT=3306 \
    -e DB_DATABASE=dmc_restore_drill -e DB_USERNAME=root \
    -e CACHE_STORE=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync \
    -e LOG_CHANNEL=stderr -e MAIL_MAILER=log \
    "$APP_IMG" -c 'php artisan audit:verify'
unset APP_KEY DB_PASSWORD
```

**Do not** do this with `php artisan audit:verify --env=restore-drill` inside the live app container
(this runbook said so until 2026-09-22). The container gets `DB_DATABASE=dmc_demo` from its
**process environment**, and Laravel never lets a `.env` file override a variable that is already
set — so that command silently verifies the **live** chain and prints "Chain intact" about the wrong
database. The one-off container has no inherited settings, so what it checks is what you passed it.
`audit:verify` only reads; the array cache/session drivers keep it from writing anything to the
scratch database either. Both halves are proven: the rehearsal ran it against the throwaway server, and
on 2026-09-22 this exact block (with `DB_DATABASE=dmc_demo`, read-only) reached the production server
over the `coolify` network and printed `Chain intact: 862 hashed row(s)`.

The chain must be intact end-to-end. A break means the replay landed rows out of order or mixed two
sources — do not promote it; go back to step 3 with a different window.

**Step 5 — decide, then clean up.** Promotion is a separate, deliberate act: it is the §5 FULL
restore with the recovered scratch database as the source instead of a dump (freeze the app, back up
what is there now, `RENAME`/reload into `dmc_demo`, `php artisan migrate`, `audit:verify`, unfreeze,
and tell people exactly which window was rolled back). **Run §5 step 6's audit archive
reconciliation** (the `audit:ship --status` check, the archive key-name comparison, and the
`AUTO_INCREMENT`/bookmark fix if the archive's newest id is ahead of what was promoted) **before**
unfreezing the app — a replay that stopped short of "now" is exactly the case where the local table
and the archive can disagree about which ids are already spoken for. Whatever you decide:

```bash
# the decrypted binary logs are patient data — shred them, do not just unlink them. Step 3 read them
# through a read-only mount, so this directory is the ONLY plaintext copy.
shred -u "$W"/* 2>/dev/null; rm -rf "$W"
docker exec -i "$MYSQL_CONTAINER" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -e "DROP DATABASE IF EXISTS \`dmc_restore_drill\`"'
```

Record the exercise in §8's drill log — a PITR rehearsal is the strongest evidence this control
works. **Rehearse it before you need it**, on a quiet day, against a **throwaway MySQL container**
(see the third bullet in step 3), with a `--stop-datetime` a few minutes in the past. The rehearsal
only counts if step 4 showed the row counts move.

`scripts/backup/pitr-rehearsal.sh` reads the dump's recorded coordinate itself and replays with `--start-position` when it is there, falling back to `--start-datetime` for a dump taken before `--source-data=2`. It is the rehearsal, end to end: a throwaway server from the tools
image on a `docker network create --internal` network with no published port, the base dump,
steps 2–4 with the commands above, a PASS/FAIL verdict on all four gates (the rows moved, they equal
the live rows before STOP, the newest is before STOP, the chain is intact), and cleanup on any exit
(`docker rm -f -v`, so the restored copy's volume goes too). Its header carries the 2026-09-22
invocation as the worked example; each run takes about a minute. Suggested cadence: quarterly, and
after any change to MySQL's version, the backup scripts or this procedure.

### 10.6 Limits — what binlog shipping does *not* give you

- **Not a substitute for the nightly dump.** Binary logs are an increment on a base. Without the
  02:15 dump they are unusable, and MySQL expires them after 30 days.
- **Up to one hour is still at risk** — whatever is in the active file when the host dies. Tightening
  that means shipping more often (the cron is the only thing to change) or semi-synchronous
  replication to a second host, which this architecture does not have.
- **DDL and `legacy:import` are logged too**, so a replay faithfully re-applies a bad migration or a
  truncate. That is exactly why `--stop-datetime` exists — and why step 4 is not optional.
- **`APP_KEY` is still the root of trust** (§1): replayed rows include encrypted narratives and MFA
  secrets, which are unreadable without the key that was in use when they were written.
- **The dump now records its exact binlog coordinate.** `db-backup.py`'s `mysqldump` call carries
  `--source-data=2` (added in this change), so every dump taken from now on turns step 3's
  `--start-datetime` guess into an exact `--start-position` and removes the boundary caveat below —
  see step 3. Dumps taken **before** this shipped, and the host copy at `/opt/dmc/backup/db-backup.py`
  until an operator reinstalls it (§2.1), still need the `--start-datetime` fallback.
- **Retention.** These objects are patient data under the same 90-day placeholder as §6 and need the
  same records-retention decision. **The measured bucket volume is in §6** (updated 2026-09-22, once
  hourly rotation had real data to measure from) — do not size the bucket from an old, unrotated
  binlog file that accumulated over a long period; each hourly-rotated file holds roughly one hour of
  changes.

### 10.7 Failure modes

| Log / symptom | Meaning | Do |
|---|---|---|
| `FAIL step=flush … exited 1` (the FLUSH statement) | container name wrong, MySQL down, or the root password env var missing | `docker ps`; `docker exec <c> env \| grep MYSQL_ROOT` |
| `SHOW BINARY LOGS returned nothing — is log_bin ON?` | binary logging was turned off on the server | nothing can be shipped until it is back on; the RPO is 24 h meanwhile |
| `FAIL step=ship … exited 1 … Permission denied` | the container's default user cannot read the datadir | set `BINLOG_DOCKER_USER=root` in `/root/.dmc-backup.env` |
| `does not start with the MySQL binary-log magic bytes` | the file is not a binary log (wrong `BINLOG_DIR`?) | check `log_bin_basename` and `BINLOG_DIR` |
| `read N bytes but SHOW BINARY LOGS reported M` | a supposedly-immutable rotated file changed size mid-read | investigate MySQL before trusting the archive; re-run |
| `--list` shows files stuck in `pending` | those files failed; the rest still shipped | read `/var/log/dmc-binlog-ship.log`; each failed file is retried next hour |
| heartbeat `created_at` older than ~2 h | the cron is not firing, or every run is failing | `grep dmc-binlog /var/log/syslog`; run it by hand |
| `FAIL step=ship … could not ship <file>: …` | one file is unreadable; everything else went off-box | fix that file's cause; the run exits 1 until it succeeds, and `failed_this_run` alerts admins in-app |
| `binary logs X..Y expired before they were shipped` | MySQL's own 30-day expiry deleted logs the shipper never archived — **the recovery chain has a permanent hole** | the window between X and Y is unrecoverable; note it, fix why the shipper was down, and record the fact — it stays in the state file's `gaps` and in `--list` forever |
| `FAIL step=identity … @@server_uuid was … is now …` | MySQL has a new data directory, so binary-log numbering restarted | do **not** mix the two generations in a replay; keep the old objects, then move the state file aside to start a fresh chain |
| `refusing to overwrite <key>` | an object already exists at that key with different content | never force it — work out which generation is which before touching the archive |
| `RESTORE-CHECK FAIL … is not a usable binary log` | the archived copy is truncated or corrupt | the file cannot be replayed; re-ship it if the server still has it, and treat its window as at risk |
| `state file … is not valid JSON` | the ledger was corrupted (disk full mid-write?) | move it aside; the next run re-ships every binary log MySQL still has (safe, just slower) |
| `mysql \`…\` did not answer within 60s` | MySQL or the docker daemon is wedged | the run exits rather than hanging with the lock held; check `docker ps` and the container's health |
