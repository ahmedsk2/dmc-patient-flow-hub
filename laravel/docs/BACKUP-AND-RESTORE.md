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
| `scripts/backup/binlog-ship.py` | Host cron, as root, **hourly at :20** | `FLUSH BINARY LOGS` inside the MySQL container, then for every **closed** binary log not yet archived: stream it out → gzip → AES-256-CBC (PBKDF2 — the same key and the same format as the nightly dump) → SigV4 PUT under `…/binlogs/YYYY/MM/` → heartbeat `binlogs/LATEST.json`. Idempotent, and it **never deletes a binary log**. This is what makes point-in-time recovery possible (§10). |
| `scripts/backup/db-restore-drill.sh` | Host, as root, **monthly by hand** | Downloads the latest backup, restores it into a scratch DB `dmc_restore_drill`, prints row counts and timings, drops the scratch DB. Never touches `dmc_demo`. |
| `scripts/backup/test_db_backup.py` | Anywhere (`python3 -m unittest`) | Proves the Python SigV4 port against the same published AWS vectors as the PHP class, plus config/naming/pruning logic |
| `scripts/backup/test_binlog_ship.py` | Anywhere (`python3 -m unittest`) | Proves the shipper's idempotency (state file), that the active log is never shipped, the name/size/magic-byte guards, and that its ciphertext opens with the **exact** decrypt command the drill uses |

The pipeline streams: **no plaintext SQL is ever written to disk** — not during backup, not during
`--restore-check`, not during binlog shipping, and not during the drill (decrypt → gunzip → mysql all
happen in a pipe). The one deliberate exception is a point-in-time **replay** (§10.5): `mysqlbinlog`
needs real files, so decrypted binary logs land in a mode-700 work directory and are shredded after.

---

## 1. RPO / RTO — what this actually gives you (plain English)

- **RPO (how much you can lose) — it depends on whether the hourly binlog shipper is installed:**
  - **≤ 1 hour, once `binlog-ship.py` is running from cron (§10).** Every change MySQL records in
    its binary log is copied off the host at minute 20 of every hour, so at worst you lose the
    changes made since the last hourly ship. This is the state to be in.
  - **≤ 24 hours from the nightly dump alone** — which is what you have *until* an operator installs
    the hourly cron line in §10.2, and what you fall back to for anything the binary log does not
    cover (see §10.6). In the worst case — the host dies at 02:14 with no binlog shipping —
    everything entered since the previous night's 02:15 backup is gone.

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
# DB_BACKUP_S3_PREFIX=db-backups/dmc_demo   (only if you changed S3_PREFIX above)
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
6. **Reconcile:** `php artisan migrate` (should say *Nothing to migrate* unless the backup predates a
   deploy — then it applies the missing migrations), `php artisan audit:verify` (the hash chain must
   be intact up to the backup moment), spot-check today's census on the dashboard against the ward.
7. **Reconnect and unfreeze:** restore the app's `.env` (`APP_KEY` **must** be the one in use when
   the backup was taken — otherwise MFA secrets will not decrypt and every user is locked out of
   MFA; see the MFA reset procedure in the auth runbook), `php artisan up` / start the container.
8. **Tell people** what window of data was lost (from the backup's `created_at` to the incident) —
   clinicians will need to re-enter admissions/discharges/consultations from that window.
9. Run `php artisan backup:verify` and a fresh `db-backup.py` so the next night starts clean.

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

---

## 7. Monitoring — how you find out it broke

- **In-app:** `backup:verify` runs daily at 06:30 (app time). If `LATEST.json` is missing, older than
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
| `backup.stale reason=missing` | no heartbeat / object gone | check cron ran (`grep CRON /var/log/syslog`), lifecycle rule not too aggressive |
| `RESTORE-CHECK FAIL … openssl decrypt exited 1 (wrong key?)` | key file changed / wrong host | restore the escrowed key (§2.2) |

---

## 8. Drill log

Add one row per drill (monthly) and per real restore. This table *is* the evidence for DATA-03.

| Date (UTC) | Run by | Object | download s | restore s | total s | patients / admissions / users / consultations | Result / notes |
|---|---|---|---|---|---|---|---|
| 2026-09-03 03:07 | Claude Code (on the owner's instruction) | db-backups/dmc_demo/2026/09/dmc_demo-2026-09-03T030653Z.sql.gz.enc | 1 | 6 | 8 | 17435 / 37662 / 331 / 0 | DRILL OK — first production drill; scratch DB counts matched the live DB (COMPARE_LIVE=1); RTO for a 20 MB dump ≈ 8 s plus operator time |

---

## 9. Testing the pieces without production

- `cd laravel/scripts/backup && python3 -m unittest -v test_db_backup test_binlog_ship` — SigV4
  vectors (the same AWS ones as `tests/Unit/S3SigV4Test.php`), config parsing, naming, pruning, ETag
  logic, and — when `openssl` is on PATH — end-to-end runs of both streaming pipelines against a fake
  `mysqldump`, a fake `docker exec mysql` / `cat`, and a fake bucket. The binlog suite also proves
  the hour-to-hour idempotency, that the active log is never shipped, and that its ciphertext opens
  with the **literal** `openssl enc -d …` line `db-restore-drill.sh` runs.
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

> **Status.** The script and its tests are in the repository and unit-tested. **Nothing in this
> section has been installed or run on the production host.** Until an operator does §10.2, the RPO
> is still the nightly 24 hours (§1) and there is no off-box binary log.

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
every hour at :20
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
- **A failed file aborts the run (exit 1)** but everything already uploaded stays recorded; the next
  hour continues from there.

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
# docs/BACKUP-AND-RESTORE.md §10. Host time (UTC on this box). Minute 20 keeps it clear of the
# 02:15 nightly dump and of the :00 audit shipping.
20 * * * * root /usr/bin/python3 /opt/dmc/backup/binlog-ship.py >>/var/log/dmc-binlog-ship.cron.log 2>&1
```

```bash
sudo chmod 644 /etc/cron.d/dmc-binlog-ship
```

The script takes its own exclusive lock (separate from the nightly backup's), so a long run can
never overlap the next hour. Exit codes: `0` success, `1` failure (one clear `FAIL step=… error=…`
line on stderr and in the log), `2` configuration error.

### 10.3 What lands in the bucket

```
dmc-db-backups/
└── db-backups/dmc_demo/
    ├── LATEST.json                                   ← nightly dump heartbeat (§3)
    ├── 2026/09/dmc_demo-2026-09-03T021507Z.sql.gz.enc
    └── binlogs/
        ├── LATEST.json                               ← shipper heartbeat, overwritten every hour
        └── 2026/09/binlog.000002.gz.enc
```

`binlogs/LATEST.json` deliberately reuses the nightly heartbeat's field names (`object`, `bytes`,
`sha256_of_ciphertext`, `created_at`), so `backup:verify`'s existing reader could be pointed at this
key with no code change:

```json
{
  "object": "db-backups/dmc_demo/binlogs/2026/09/binlog.000003.gz.enc",
  "bytes": 4210688, "sha256_of_ciphertext": "…64 hex…", "md5_of_ciphertext": "…32 hex…",
  "created_at": "2026-09-03T14:20:07Z",        ← when the SHIPPER last completed  (age this)
  "last_shipped_at": "2026-09-03T14:20:07Z",   ← when the object above was uploaded
  "binlog": "binlog.000003", "plaintext_bytes": 16777216, "sha256_of_plaintext": "…64 hex…",
  "db": "dmc_demo", "shipped_this_run": 1, "active_binlog": "binlog.000004", "known_binlogs": 3,
  "cipher": "aes-256-cbc/pbkdf2-200000", "host": "dmc-db-host",
  "producer": "scripts/backup/binlog-ship.py"
}
```

`created_at` is refreshed on **every** successful run, including a run that had nothing new to ship —
because for an hourly job the failure that matters is "the shipper stopped running". Age that field,
not `last_shipped_at`, and use a window of about **2 hours** (not the nightly job's 26). `object` is
`null` only before the very first file has ever been archived.

### 10.4 Verify it is working

```bash
sudo /usr/bin/python3 /opt/dmc/backup/binlog-ship.py --list          # every binary log + shipped/pending/ACTIVE
sudo /usr/bin/python3 /opt/dmc/backup/binlog-ship.py --print-latest  # the binlogs/LATEST.json heartbeat
sudo jq '.files | length' /var/backups/dmc/binlog-shipped.json       # records in the state ledger
sudo tail -n 5 /var/log/dmc-binlog-ship.log
grep dmc-binlog /var/log/syslog | tail                               # cron actually fired
```

Healthy signs: `--list` shows exactly one `ACTIVE` file and no `pending` ones; the heartbeat's
`created_at` is under an hour old; the log has one `OK …` line per hour. If `--list` shows files
sitting in `pending`, the shipper is failing — read `/var/log/dmc-binlog-ship.log`.

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
#   e.g. 2026-09-03T02:15:07Z   ← this is <dump time> below
```

> The drill **drops** `dmc_restore_drill` when it exits. For a real recovery, restore it the same way
> but keep it: run the drill's own pipeline by hand (§4's description) or re-create the scratch DB
> and pipe the decrypted dump into `mysql --database=dmc_restore_drill` with the same
> `` sed 's/`dmc_demo`/`dmc_restore_drill`/g' `` rewrite. Nothing about the base restore changes.

**Step 2 — fetch and decrypt the binary logs that cover the window.** You need every archived binlog
whose contents span *<dump time>* → *<the mistake>*, **in ascending sequence order**, including the
file that was active when the dump was taken. `binlog-ship.py --list`, the state file
(`/var/backups/dmc/binlog-shipped.json`, which records each object key) or a bucket listing will
tell you which ones those are.

```bash
W=$(mktemp -d -p /var/backups/dmc pitr-XXXXXX); chmod 700 "$W"
set -a; . /root/.dmc-backup.env; set +a

for OBJ in db-backups/dmc_demo/binlogs/2026/09/binlog.00000{2,3,4}.gz.enc; do
  N=$(basename "$OBJ" .gz.enc)
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

```bash
docker exec "$MYSQL_CONTAINER" mysqlbinlog --version   # the server image ships it; --rewrite-db needs 8.0.26+
docker cp "$W" "$MYSQL_CONTAINER":/tmp/pitr        # mysqlbinlog + mysql live inside the container

docker exec -i "$MYSQL_CONTAINER" sh -c '
  MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqlbinlog \
    --database=dmc_demo \
    --rewrite-db="dmc_demo->dmc_restore_drill" \
    --start-datetime="2026-09-03 02:15:07" \
    --stop-datetime="2026-09-03 13:59:00" \
    /tmp/pitr/binlog.000002 /tmp/pitr/binlog.000003 /tmp/pitr/binlog.000004 \
  | MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --database=dmc_restore_drill'
```

Four things about that command are load-bearing:

- **`--rewrite-db="dmc_demo->dmc_restore_drill"` is mandatory when replaying into a scratch
  database.** The binary log's events carry the database name (`USE dmc_demo`, and with `ROW` format
  the row events are bound to it), so *without* this flag the replay writes straight into the LIVE
  `dmc_demo` no matter what `--database=` you pass to the `mysql` client. Only drop `--rewrite-db`
  when you are deliberately replaying onto a full restore of the real `dmc_demo` (§5).
- **All the files go to ONE `mysqlbinlog` invocation, in ascending order.** A transaction can span a
  rotation; separate invocations piped separately would tear it.
- **Timestamps are the server's own** — this host runs UTC, and the heartbeat's `created_at` is UTC.
  `--stop-datetime` is exclusive of the moment you name, so pick the second *before* the mistake.
- **The dump does not record its exact binlog coordinate** (`db-backup.py` does not pass
  `--source-data`), so `--start-datetime` = the dump's `created_at` is the anchor. It is
  second-precision and `--single-transaction` means the snapshot is taken at the dump's *start*, so
  expect a handful of events at the boundary to be re-applied; with `ROW` format those either
  produce identical rows or fail loudly on a duplicate key rather than silently duplicating data.
  If a boundary error stops the replay, note the position it reports and resume with
  `--start-position`. (Recording the coordinate properly is the follow-up noted in §10.6.)

**Step 4 — verify before you promote anything.**

```bash
# counts in the recovered copy — compare with what the unit says should be there
docker exec -i "$MYSQL_CONTAINER" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql --database=dmc_restore_drill -t' <<'SQL'
SELECT 'patients' tbl, COUNT(*) n FROM patients
UNION ALL SELECT 'admissions', COUNT(*) FROM admissions
UNION ALL SELECT 'consultations', COUNT(*) FROM consultations
UNION ALL SELECT 'audit_log', COUNT(*) FROM audit_log;
SELECT MAX(created_at) AS newest_audit_row FROM audit_log;
SQL
```

`newest_audit_row` must sit just under your `--stop-datetime`: that is the proof the replay reached
where you intended and no further. Then check the tamper-evident chain over the recovered rows —
point the app at the scratch database for one command only (a copy of the app `.env` with
`DB_DATABASE=dmc_restore_drill`, **never** by editing the live one):

```bash
docker exec <app container> php artisan audit:verify --env=restore-drill
```

The chain must be intact end-to-end. A break means the replay landed rows out of order or mixed two
sources — do not promote it; go back to step 3 with a different window.

**Step 5 — decide, then clean up.** Promotion is a separate, deliberate act: it is the §5 FULL
restore with the recovered scratch database as the source instead of a dump (freeze the app, back up
what is there now, `RENAME`/reload into `dmc_demo`, `php artisan migrate`, `audit:verify`, unfreeze,
and tell people exactly which window was rolled back). Whatever you decide:

```bash
docker exec "$MYSQL_CONTAINER" rm -rf /tmp/pitr
shred -u "$W"/* 2>/dev/null; rm -rf "$W"
docker exec -i "$MYSQL_CONTAINER" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -e "DROP DATABASE IF EXISTS \`dmc_restore_drill\`"'
```

Record the exercise in §8's drill log — a PITR rehearsal is the strongest evidence this control
works. **Rehearse it before you need it**, on a quiet day, with a `--stop-datetime` a few minutes in
the past: everything above is safe as long as `--rewrite-db` is present and you never skip step 4.

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
- **Follow-up worth doing (not done here):** add `--source-data=2` to `db-backup.py`'s `mysqldump`
  so every dump records the exact binlog file and position it corresponds to. That turns step 3's
  `--start-datetime` guess into an exact `--start-position` and removes the boundary caveat.
- **Retention.** These objects are patient data under the same 90-day placeholder as §6 and need the
  same records-retention decision. They are also *bulkier*: ~350 MB per rotated file today.

### 10.7 Failure modes

| Log / symptom | Meaning | Do |
|---|---|---|
| `FAIL step=flush … exited 1` (the FLUSH statement) | container name wrong, MySQL down, or the root password env var missing | `docker ps`; `docker exec <c> env \| grep MYSQL_ROOT` |
| `SHOW BINARY LOGS returned nothing — is log_bin ON?` | binary logging was turned off on the server | nothing can be shipped until it is back on; the RPO is 24 h meanwhile |
| `FAIL step=ship … exited 1 … Permission denied` | the container's default user cannot read the datadir | set `BINLOG_DOCKER_USER=root` in `/root/.dmc-backup.env` |
| `does not start with the MySQL binary-log magic bytes` | the file is not a binary log (wrong `BINLOG_DIR`?) | check `log_bin_basename` and `BINLOG_DIR` |
| `read N bytes but SHOW BINARY LOGS reported M` | a supposedly-immutable rotated file changed size mid-read | investigate MySQL before trusting the archive; re-run |
| `--list` shows files stuck in `pending` | the last runs failed | read `/var/log/dmc-binlog-ship.log`; each failed file is retried next hour |
| heartbeat `created_at` older than ~2 h | the cron is not firing, or every run is failing | `grep dmc-binlog /var/log/syslog`; run it by hand |
| `state file … is not valid JSON` | the ledger was corrupted (disk full mid-write?) | move it aside; the next run re-ships every binary log MySQL still has (safe, just slower) |
