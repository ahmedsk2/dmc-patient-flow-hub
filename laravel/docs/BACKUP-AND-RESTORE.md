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
| `scripts/backup/db-restore-drill.sh` | Host, as root, **monthly by hand** | Downloads the latest backup, restores it into a scratch DB `dmc_restore_drill`, prints row counts and timings, drops the scratch DB. Never touches `dmc_demo`. |
| `scripts/backup/test_db_backup.py` | Anywhere (`python3 -m unittest`) | Proves the Python SigV4 port against the same published AWS vectors as the PHP class, plus config/naming/pruning logic |

The pipeline streams: **no plaintext SQL is ever written to disk** — not during backup, not during
`--restore-check`, and not during the drill (decrypt → gunzip → mysql all happen in a pipe).

---

## 1. RPO / RTO — what this actually gives you (plain English)

- **RPO (how much you can lose): ≤ 24 hours.** One full backup per night. In the worst case — the
  host dies at 02:14 — everything entered since the previous night's 02:15 backup is gone. That is
  the trade-off of a nightly logical dump; a tighter RPO needs binlog shipping (DATA-04), which is
  **not** part of this change.
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
sudo cp laravel/scripts/backup/db-backup.py laravel/scripts/backup/db-restore-drill.sh /opt/dmc/backup/
sudo chmod 750 /opt/dmc/backup /opt/dmc/backup/db-backup.py /opt/dmc/backup/db-restore-drill.sh
sudo chown -R root:root /opt/dmc/backup
python3 --version && openssl version && docker --version      # all three must exist
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
| _not yet run_ | | | | | | | first production drill pending |

---

## 9. Testing the pieces without production

- `cd laravel/scripts/backup && python3 -m unittest -v test_db_backup` — SigV4 vectors (the same AWS
  ones as `tests/Unit/S3SigV4Test.php`), config parsing, naming, pruning, ETag logic, and — when
  `openssl` is on PATH — an end-to-end run of the streaming pipeline with a fake `mysqldump` and a
  fake bucket.
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
