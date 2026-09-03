#!/usr/bin/env bash
# db-restore-drill.sh — prove the newest off-box backup actually restores (finding DATA-03), and
# measure how long it takes (that number is the RTO — see docs/BACKUP-AND-RESTORE.md).
#
#   sudo /opt/dmc/backup/db-restore-drill.sh                 # restore LATEST into dmc_restore_drill
#   sudo /opt/dmc/backup/db-restore-drill.sh <object key>    # a specific object instead of LATEST
#   sudo /opt/dmc/backup/db-restore-drill.sh --check-only    # download+decrypt, show what WOULD run
#   COMPARE_LIVE=1 sudo ...                                  # also print the live DB's counts (read-only)
#
# SAFE ON PRODUCTION — by construction it never writes to the live database:
#   * every `dmc_demo` reference in the dump (CREATE DATABASE / USE / qualified names) is rewritten
#     to the scratch name before it reaches mysql, and mysql is started with the scratch database
#     as its default, so an unqualified statement cannot land anywhere else;
#   * the scratch database name must differ from DB_NAME (hard-checked) and is dropped on exit,
#     success or failure;
#   * decrypted bytes only ever exist in the pipe (openssl → gunzip → sed → mysql); the only file
#     written to disk is the still-encrypted download, in a 700 work dir that is removed on exit.
#
# Needs: root, python3 (+ the sibling db-backup.py for the SigV4 download), openssl, gunzip, sed, docker.

set -euo pipefail

ENV_FILE="${DMC_BACKUP_ENV:-/root/.dmc-backup.env}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKUP_PY="${DMC_BACKUP_PY:-$SCRIPT_DIR/db-backup.py}"
DRILL_DB="${DRILL_DB:-dmc_restore_drill}"
CHECK_ONLY=0
OBJECT=""

for arg in "$@"; do
    case "$arg" in
        --check-only) CHECK_ONLY=1 ;;
        -h|--help) sed -n '2,20p' "$0"; exit 0 ;;
        *) OBJECT="$arg" ;;
    esac
done

die() { echo "FAIL: $*" >&2; exit 1; }
say() { echo "[$(date -u +%H:%M:%S)] $*"; }

# Root is only needed to read the root-owned env/key files and talk to docker. DMC_DRILL_SKIP_ROOT_CHECK=1
# exists for running the drill against a local MySQL in a sandbox (see docs §9) — never set it on production.
[[ $EUID -eq 0 || "${DMC_DRILL_SKIP_ROOT_CHECK:-0}" == "1" ]] || die "run as root (reads $ENV_FILE and the key file)"
[[ -r "$ENV_FILE" ]] || die "$ENV_FILE not found"
[[ -x "$(command -v python3)" ]] || die "python3 not found"
[[ -f "$BACKUP_PY" ]] || die "db-backup.py not found next to this script ($BACKUP_PY)"
for tool in openssl gunzip sed docker; do command -v "$tool" >/dev/null || die "$tool not found"; done

# ---- config (KEY=value; same file db-backup.py reads) ------------------------------------------
while IFS= read -r line || [[ -n "$line" ]]; do
    line="${line#"${line%%[![:space:]]*}"}"                 # ltrim
    [[ -z "$line" || "$line" == \#* ]] && continue
    line="${line#export }"
    key="${line%%=*}"; value="${line#*=}"
    value="${value#\"}"; value="${value%\"}"; value="${value#\'}"; value="${value%\'}"
    case "$key" in
        MYSQL_CONTAINER|DB_NAME|KEYFILE|LOCAL_DIR) printf -v "$key" '%s' "$value" ;;
    esac
done < "$ENV_FILE"

DB_NAME="${DB_NAME:-dmc_demo}"
KEYFILE="${KEYFILE:-/root/.dmc-backup.key}"
LOCAL_DIR="${LOCAL_DIR:-/var/backups/dmc}"
[[ -n "${MYSQL_CONTAINER:-}" ]] || die "MYSQL_CONTAINER missing from $ENV_FILE"
[[ -r "$KEYFILE" ]] || die "KEYFILE $KEYFILE not readable"
[[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]] || die "DB_NAME must be [A-Za-z0-9_]+"
[[ "$DRILL_DB" =~ ^[A-Za-z0-9_]+$ ]] || die "DRILL_DB must be [A-Za-z0-9_]+"
[[ "$DRILL_DB" != "$DB_NAME" ]] || die "refusing: DRILL_DB ($DRILL_DB) must not be the live database ($DB_NAME)"

# Run a mysql client INSIDE the container; the root password never leaves the container's env.
mysql_in_container() {   # usage: mysql_in_container [mysql args...]  (stdin is passed through)
    docker exec -i "$MYSQL_CONTAINER" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql "$@"' sh "$@"
}

mkdir -p "$LOCAL_DIR"; chmod 700 "$LOCAL_DIR"
WORK="$(mktemp -d -p "$LOCAL_DIR" restore-drill-XXXXXX)"
chmod 700 "$WORK"
DRILL_CREATED=0

cleanup() {
    local rc=$?
    if [[ $DRILL_CREATED -eq 1 ]]; then
        say "dropping scratch database $DRILL_DB"
        mysql_in_container -e "DROP DATABASE IF EXISTS \`$DRILL_DB\`" </dev/null || echo "warning: could not drop $DRILL_DB" >&2
    fi
    rm -rf "$WORK"
    [[ $rc -eq 0 ]] || echo "drill FAILED (exit $rc)" >&2
}
trap cleanup EXIT

T0=$(date +%s)

# ---- 1. which object -----------------------------------------------------------------------------
if [[ -z "$OBJECT" ]]; then
    say "reading the LATEST.json heartbeat"
    HEARTBEAT="$(python3 "$BACKUP_PY" --env-file "$ENV_FILE" --print-latest)" || die "could not read the heartbeat"
    OBJECT="$(printf '%s' "$HEARTBEAT" | python3 -c 'import json,sys; print(json.load(sys.stdin)["object"])')" \
        || die "heartbeat has no \"object\""
    CREATED_AT="$(printf '%s' "$HEARTBEAT" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("created_at",""))')"
    say "latest backup: $OBJECT (created_at ${CREATED_AT:-unknown})"
fi

# ---- 2. download (still encrypted) ---------------------------------------------------------------
CIPHER="$WORK/backup.sql.gz.enc"
say "downloading $OBJECT"
python3 "$BACKUP_PY" --env-file "$ENV_FILE" --download "$OBJECT" "$CIPHER" || die "download failed"
T1=$(date +%s)
say "downloaded $(stat -c %s "$CIPHER") bytes in $((T1 - T0))s"

DECRYPT=(openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass "file:$KEYFILE" -in "$CIPHER")
REWRITE=(sed -e "s/\`$DB_NAME\`/\`$DRILL_DB\`/g")

if [[ $CHECK_ONLY -eq 1 ]]; then
    say "--check-only: these are the database-level statements that WOULD run (after rewrite):"
    "${DECRYPT[@]}" | gunzip -c | "${REWRITE[@]}" | grep -E '^(CREATE DATABASE|USE |-- Dump completed)' || true
    say "check-only done; nothing was restored"
    exit 0
fi

# ---- 3. scratch database -------------------------------------------------------------------------
say "creating scratch database $DRILL_DB"
mysql_in_container -e "DROP DATABASE IF EXISTS \`$DRILL_DB\`; CREATE DATABASE \`$DRILL_DB\`" </dev/null
DRILL_CREATED=1

# ---- 4. restore: decrypt → gunzip → rewrite db name → mysql (default db = scratch) ---------------
say "restoring into $DRILL_DB (decrypt → gunzip → rewrite → mysql, streamed)"
"${DECRYPT[@]}" | gunzip -c | "${REWRITE[@]}" | mysql_in_container --database="$DRILL_DB" \
    || die "restore failed (see mysql error above)"
T2=$(date +%s)
say "restore finished in $((T2 - T1))s"

# ---- 5. row counts --------------------------------------------------------------------------------
COUNT_SQL="SELECT 'patients' AS tbl, COUNT(*) AS rows_ FROM patients
UNION ALL SELECT 'admissions', COUNT(*) FROM admissions
UNION ALL SELECT 'users', COUNT(*) FROM users
UNION ALL SELECT 'consultations', COUNT(*) FROM consultations
UNION ALL SELECT 'audit_log', COUNT(*) FROM audit_log;"

echo
echo "Row counts in the RESTORED copy ($DRILL_DB):"
mysql_in_container --database="$DRILL_DB" -t -e "$COUNT_SQL" </dev/null || die "count query failed — restore incomplete?"

if [[ "${COMPARE_LIVE:-0}" == "1" ]]; then
    echo
    echo "Row counts in the LIVE database ($DB_NAME) right now (read-only SELECTs; they will be a little"
    echo "higher than the backup because the unit has kept working since the backup was taken):"
    mysql_in_container --database="$DB_NAME" -t -e "$COUNT_SQL" </dev/null || echo "warning: live counts unavailable" >&2
fi

T3=$(date +%s)
echo
echo "DRILL OK  object=$OBJECT  download=$((T1 - T0))s  restore=$((T2 - T1))s  total=$((T3 - T0))s"
echo "Record this run (date, object, total seconds, counts) in docs/BACKUP-AND-RESTORE.md → Drill log."
