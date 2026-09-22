#!/usr/bin/env bash
#
# infra/host-bootstrap.sh — the parts of the DMC host that Terraform does not own.
#
# STATUS: documentation-grade, like the rest of infra/. Has NEVER been run against the live host.
# Written from laravel/docs/DEPLOY-LARAVEL.md §6/§9 and laravel/docs/BACKUP-AND-RESTORE.md
# §2/§2.1/§2.5/§10.2, so a rebuild is repeatable instead of re-typed from the runbooks by hand.
#
# WHAT THIS OWNS (Terraform provisions the cloud resources; this configures the inside of the
# host, on top of them — infra/README.md "What host-bootstrap.sh does that Terraform does not"):
#   - Docker (a prerequisite for Coolify and the backup scripts' `docker exec`)
#   - /opt/dmc/backup/* — the backup/binlog/PITR scripts, copied from this checkout
#   - /root/.dmc-backup.env — written ONLY as a placeholder TEMPLATE if absent; never overwritten
#   - the three documented host root crons (scheduler every minute, nightly dump 02:15, binlog
#     shipping at :40)
#   - /etc/logrotate.d/dmc-backup
#   - the PITR tools image (scripts/backup/pitr-tools.Dockerfile)
#
# WHAT THIS DELIBERATELY DOES NOT OWN (infra/README.md "What is deliberately out of scope"):
#   - installing or configuring Coolify itself, or the Laravel application record inside it
#   - generating /root/.dmc-backup.key (BACKUP-AND-RESTORE.md §2.2 — a one-time, deliberately
#     manual action so the immediate two-custodian escrow step is never skipped by automation)
#   - putting any real credential, key or secret into any file this script writes
#   - the app's own environment variables (Coolify env store) or the GRANT SELECT the app's DB
#     user needs for legacy:import (DEPLOY-LARAVEL.md §8 — an application/DB-level step, not host
#     infrastructure)
#
# SAFETY:
#   - Idempotent: safe to re-run. Re-running upgrades the /opt/dmc/backup/*.py|*.sh files (that is
#     the documented "reinstall after merging" step, BACKUP-AND-RESTORE.md §2.1) but never
#     regenerates or overwrites /root/.dmc-backup.env or /root/.dmc-backup.key if either already
#     exists with content.
#   - No secrets: every value this script writes into a config file is a `<PLACEHOLDER>`. Real
#     values are filled in by the operator by hand, by editing the file after this script exits —
#     exactly like terraform.tfvars.example.
#   - Must run as root (every path and cron it manages is root-owned, matching the runbooks).
#
# USAGE: run from a checkout of this repository on the target host:
#   sudo ./infra/host-bootstrap.sh
#
set -euo pipefail

# ---------------------------------------------------------------------------------------------
# 0. Preconditions
# ---------------------------------------------------------------------------------------------

if [ "$(id -u)" -ne 0 ]; then
  echo "host-bootstrap.sh must run as root (it writes to /opt, /root, /etc/cron.d) — try sudo." >&2
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
BACKUP_SRC="$REPO_ROOT/laravel/scripts/backup"

if [ ! -f "$BACKUP_SRC/db-backup.py" ]; then
  echo "Expected $BACKUP_SRC/db-backup.py — run this script from a checkout of the DMC repo" \
       "(infra/host-bootstrap.sh at the repo root, laravel/scripts/backup/ alongside it)." >&2
  exit 1
fi

# The Coolify application uuid the scheduler cron looks the app container up by (label-based —
# survives every redeploy, per DEPLOY-LARAVEL.md §6). Update this if the app is ever deleted and
# recreated in Coolify (new uuid) — DEPLOY-LARAVEL.md §6 calls this out explicitly.
COOLIFY_APP_UUID="${COOLIFY_APP_UUID:-v5d8vrnp418stpcwnup3yhta}"

# MySQL version the production container runs, for the PITR tools image tag (BACKUP-AND-RESTORE.md
# §10.2). Rebuild with a new value whenever the production MySQL image changes version — verify
# with `docker exec <mysql container> mysqld --version` on the real host before relying on this.
MYSQL_VERSION="${MYSQL_VERSION:-8.4.10}"

echo "== DMC host bootstrap =="
echo "Repo root:     $REPO_ROOT"
echo "Backup source: $BACKUP_SRC"
echo "Coolify uuid:  $COOLIFY_APP_UUID"
echo "MySQL version: $MYSQL_VERSION"
echo

# ---------------------------------------------------------------------------------------------
# 1. Docker (prerequisite for Coolify and for every `docker exec` the backup scripts and the
#    scheduler cron do). Coolify's own install is explicitly NOT run here — see header.
# ---------------------------------------------------------------------------------------------

if command -v docker >/dev/null 2>&1; then
  echo "[1/7] Docker already installed ($(docker --version)) — skipping install."
else
  echo "[1/7] Installing Docker Engine from Docker's official apt repository..."
  apt-get update -y
  apt-get install -y ca-certificates curl gnupg
  install -m 0755 -d /etc/apt/keyrings
  if [ ! -f /etc/apt/keyrings/docker.gpg ]; then
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
    chmod a+r /etc/apt/keyrings/docker.gpg
  fi
  ARCH="$(dpkg --print-architecture)"
  CODENAME="$(. /etc/os-release && echo "$VERSION_CODENAME")"
  echo "deb [arch=$ARCH signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $CODENAME stable" \
    > /etc/apt/sources.list.d/docker.list
  apt-get update -y
  apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
  echo "Docker installed: $(docker --version)"
fi
echo

# ---------------------------------------------------------------------------------------------
# 2. /opt/dmc/backup — the backup/binlog/PITR scripts (BACKUP-AND-RESTORE.md §2.1, §10.2).
#    Safe to re-run: this is a code deploy, not secret material, so it always syncs to the
#    checkout's current version (this IS the documented "reinstall after merging" step).
# ---------------------------------------------------------------------------------------------

echo "[2/7] Installing /opt/dmc/backup scripts..."
mkdir -p /opt/dmc/backup
cp "$BACKUP_SRC/db-backup.py" "$BACKUP_SRC/binlog-ship.py" "$BACKUP_SRC/db-restore-drill.sh" \
   "$BACKUP_SRC/pitr-tools.Dockerfile" "$BACKUP_SRC/pitr-rehearsal.sh" \
   /opt/dmc/backup/
chmod 750 /opt/dmc/backup /opt/dmc/backup/db-backup.py /opt/dmc/backup/binlog-ship.py \
  /opt/dmc/backup/db-restore-drill.sh /opt/dmc/backup/pitr-rehearsal.sh
chmod 644 /opt/dmc/backup/pitr-tools.Dockerfile
chown -R root:root /opt/dmc/backup
echo "  done."
echo

# ---------------------------------------------------------------------------------------------
# 3. /root/.dmc-backup.env — TEMPLATE ONLY. Never overwritten if it already exists (that would
#    either destroy a working config or silently wipe real credentials back to placeholders).
#    BACKUP-AND-RESTORE.md §2.3.
# ---------------------------------------------------------------------------------------------

echo "[3/7] Backup config template (/root/.dmc-backup.env)..."
if [ -f /root/.dmc-backup.env ]; then
  echo "  /root/.dmc-backup.env already exists — leaving it untouched (refusing to overwrite config)."
else
  umask 077
  cat > /root/.dmc-backup.env <<'EOF'
# /root/.dmc-backup.env — root-only, mode 600. Written as a TEMPLATE by infra/host-bootstrap.sh;
# every value below is a placeholder. Fill in the real values from the owner's OCI console /
# private ops note, then re-check permissions (chmod 600) — the scripts refuse to run if this
# file is group/other readable. See laravel/docs/BACKUP-AND-RESTORE.md §2.3 for the authoritative
# description of every key.

# S3-compatible target: OCI Object Storage (in-Kingdom), the SAME endpoint/region/credentials
# shape the app uses for AUDIT_S3_* — but a SEPARATE bucket with its own lifecycle rule.
S3_ENDPOINT=https://<PLACEHOLDER-NAMESPACE>.compat.objectstorage.me-riyadh-1.oraclecloud.com
S3_REGION=me-riyadh-1
S3_BUCKET=dmc-db-backups
S3_ACCESS_KEY=<PLACEHOLDER-CUSTOMER-SECRET-KEY-ID>
S3_SECRET=<PLACEHOLDER-CUSTOMER-SECRET-KEY-SECRET>

# The MySQL 8 container (docker ps) — the root password is read INSIDE the container from its
# own MYSQL_ROOT_PASSWORD env var; it is never written here.
MYSQL_CONTAINER=<PLACEHOLDER-MYSQL-CONTAINER-NAME-OR-ID>
DB_NAME=dmc_demo

KEYFILE=/root/.dmc-backup.key
LOCAL_KEEP_DAYS=2
# LOCAL_DIR=/var/backups/dmc
# LOG_FILE=/var/log/dmc-backup.log
# S3_PREFIX=db-backups/dmc_demo
EOF
  chmod 600 /root/.dmc-backup.env
  echo "  wrote a PLACEHOLDER template — edit /root/.dmc-backup.env by hand before the first real run."
fi
echo

# ---------------------------------------------------------------------------------------------
# 3b. /root/.dmc-backup.key — NOT generated here, on purpose. BACKUP-AND-RESTORE.md §2.2 makes
#     key generation a deliberate, manual, one-time act specifically so the immediate two-
#     custodian escrow step is never skipped by unattended automation. This script only checks.
# ---------------------------------------------------------------------------------------------

echo "[3b/7] Backup encryption key..."
if [ -f /root/.dmc-backup.key ]; then
  echo "  /root/.dmc-backup.key already exists — leaving it untouched."
else
  cat <<'EOF'
  /root/.dmc-backup.key does NOT exist and this script will NOT create it.
  Key generation is a deliberate manual step (laravel/docs/BACKUP-AND-RESTORE.md §2.2) because the
  key must be escrowed (two custodians, hospital password vault) the moment it is created — an
  unattended bootstrap script is the wrong place for that. To generate it:

    sudo sh -c 'umask 077; openssl rand -base64 48 > /root/.dmc-backup.key'
    sudo chmod 600 /root/.dmc-backup.key
    # then immediately copy its contents into escrow — see §2.2 for the exact procedure.
EOF
fi
echo

# ---------------------------------------------------------------------------------------------
# 4. Logrotate — deterministic, no secrets, safe to overwrite every run. BACKUP-AND-RESTORE.md §2.1.
# ---------------------------------------------------------------------------------------------

echo "[4/7] /etc/logrotate.d/dmc-backup..."
cat > /etc/logrotate.d/dmc-backup <<'EOF'
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
EOF
chmod 644 /etc/logrotate.d/dmc-backup
logrotate --debug /etc/logrotate.d/dmc-backup >/dev/null
echo "  installed and dry-run validated."
echo

# ---------------------------------------------------------------------------------------------
# 5. The three documented host root crons — deterministic content, safe to overwrite every run.
#    DEPLOY-LARAVEL.md §6 (scheduler), BACKUP-AND-RESTORE.md §2.5 (nightly dump) and §10.2
#    (binlog shipping).
# ---------------------------------------------------------------------------------------------

echo "[5/7] Host root crons..."

cat > /usr/local/bin/dmc-schedule.sh <<EOF
#!/bin/sh
# Drives the Laravel scheduler. Finds the app container BY LABEL so a redeploy never breaks this
# (DEPLOY-LARAVEL.md §6). Does NOT survive the Coolify application being deleted and recreated
# (new uuid) — update COOLIFY_APP_UUID at the top of infra/host-bootstrap.sh and re-run it if
# that ever happens.
CONTAINER="\$(docker ps -q -f "label=coolify.name=${COOLIFY_APP_UUID}")"
if [ -z "\$CONTAINER" ]; then
  echo "dmc-schedule.sh: no running container labelled coolify.name=${COOLIFY_APP_UUID}" >&2
  exit 1
fi
exec docker exec "\$CONTAINER" php artisan schedule:run
EOF
chmod 755 /usr/local/bin/dmc-schedule.sh
chown root:root /usr/local/bin/dmc-schedule.sh

# NOTE: DEPLOY-LARAVEL.md §6 documents this only as "a host root cron, every minute" without
# stating whether it lives in root's own crontab or /etc/cron.d. Using /etc/cron.d here for
# consistency with the other two documented crons below, which ARE explicitly /etc/cron.d files.
cat > /etc/cron.d/dmc-schedule <<EOF
# DMC Laravel scheduler driver — every minute, by container label (survives redeploys).
# docs/DEPLOY-LARAVEL.md §6. Host time.
* * * * * root /usr/local/bin/dmc-schedule.sh >>/var/log/dmc-schedule.cron.log 2>&1
EOF
chmod 644 /etc/cron.d/dmc-schedule

cat > /etc/cron.d/dmc-db-backup <<'EOF'
# DMC nightly encrypted off-box DB backup (docs/BACKUP-AND-RESTORE.md §2.5). Host time (UTC on
# the documented production box).
15 2 * * * root /usr/bin/python3 /opt/dmc/backup/db-backup.py >>/var/log/dmc-backup.cron.log 2>&1
EOF
chmod 644 /etc/cron.d/dmc-db-backup

cat > /etc/cron.d/dmc-binlog-ship <<'EOF'
# DMC hourly encrypted off-box MySQL binary-log shipping — point-in-time recovery.
# docs/BACKUP-AND-RESTORE.md §10.2. Host time. Minute 40 keeps it clear of the 02:15 nightly dump
# and of the :00 audit shipping.
40 * * * * root /usr/bin/python3 /opt/dmc/backup/binlog-ship.py >>/var/log/dmc-binlog-ship.cron.log 2>&1
EOF
chmod 644 /etc/cron.d/dmc-binlog-ship

echo "  installed: /etc/cron.d/{dmc-schedule,dmc-db-backup,dmc-binlog-ship}, /usr/local/bin/dmc-schedule.sh"
echo

# ---------------------------------------------------------------------------------------------
# 6. PITR tools image — the stock mysql:8 image has no mysqlbinlog (BACKUP-AND-RESTORE.md §10.2,
#    found by the 2026-09-22 rehearsal). Skipped if the tag already exists, unless FORCE=1.
# ---------------------------------------------------------------------------------------------

echo "[6/7] PITR tools image dmc/mysql-pitr:${MYSQL_VERSION}..."
if docker image inspect "dmc/mysql-pitr:${MYSQL_VERSION}" >/dev/null 2>&1 && [ "${FORCE:-0}" != "1" ]; then
  echo "  dmc/mysql-pitr:${MYSQL_VERSION} already built — skipping (set FORCE=1 to rebuild)."
else
  docker build --build-arg "MYSQL_VERSION=${MYSQL_VERSION}" \
    -t "dmc/mysql-pitr:${MYSQL_VERSION}" - < /opt/dmc/backup/pitr-tools.Dockerfile
  docker run --rm --entrypoint mysqlbinlog "dmc/mysql-pitr:${MYSQL_VERSION}" --version
fi
echo

# ---------------------------------------------------------------------------------------------
# 7. Summary — what remains for a human (this script deliberately stops short of these).
# ---------------------------------------------------------------------------------------------

cat <<EOF
[7/7] host-bootstrap.sh done. Still required by hand, per infra/README.md's scope boundary:

  1. Install and configure Coolify v4 itself (not run by this script) — DEPLOY-LARAVEL.md §9.
  2. Generate + escrow /root/.dmc-backup.key if it does not exist yet (§2.2 above).
  3. Edit /root/.dmc-backup.env with real values (namespace, customer secret key, container name).
  4. sudo /usr/bin/python3 /opt/dmc/backup/db-backup.py --dry-run   # first proof run
  5. GRANT SELECT ON dmc_prod.* TO the app's DB user, for legacy:import (DEPLOY-LARAVEL.md §8) —
     an application/DB step, not a host-infrastructure one, so it is not done here.
  6. Confirm /etc/cron.d/dmc-schedule finds a real container once Coolify is actually running
     (this cron will fail loudly and log to dmc-schedule.cron.log until then — expected on a
     fresh host).
EOF
