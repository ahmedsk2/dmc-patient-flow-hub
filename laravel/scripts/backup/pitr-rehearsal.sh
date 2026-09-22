#!/usr/bin/env bash
# Point-in-time recovery REHEARSAL — docs/BACKUP-AND-RESTORE.md §10.5 end to end, against a
# THROWAWAY MySQL server on an --internal Docker network, so nothing it does can reach the live
# dmc_demo. The only touch on production is one read-only COUNT(*) on the live audit_log for the
# cross-check. Every decrypted byte is shredded and the throwaway server is removed WITH its volume
# on exit, success or failure.
#
# What PASS proves (and the rehearsal only counts if all four hold):
#   1. the replay applied something  — audit_log grew beyond the base dump, or (on a quiet window,
#      legitimate while this app is not yet the daily system) the replay carried binlog events at all;
#   2. the replay applied everything — the recovered audit_log equals the live rows created before
#      STOP (audit_log.created_at is DB-written in UTC, the same clock the binlog stamps events with);
#   3. it stopped where asked        — the newest recovered audit row is before STOP;
#   4. the chain survived            — the app's own `audit:verify`, run from the live image in a
#      one-off container on the internal network, reports the chain intact.
#
# Needs the tools image (the stock mysql:8 image has no mysqlbinlog):
#   sudo sh -c 'docker build -t dmc/mysql-pitr:8.4.10 - < /opt/dmc/backup/pitr-tools.Dockerfile'
#
# Usage (root, on the database host). The worked example is the first rehearsal, 2026-09-22:
#   sudo BASE_OBJ=db-backups/dmc_demo/2026/09/dmc_demo-2026-09-22T021501Z.sql.gz.enc \
#        START='2026-09-22 02:15:01' STOP='2026-09-22 10:30:00' FIRST=430 LAST=439 \
#        bash /opt/dmc/backup/pitr-rehearsal.sh
#   BASE_OBJ  a nightly dump object;  START  its moment (UTC, from the object name);
#   STOP      a recent moment still inside the LAST shipped binlog's window (exclusive);
#   FIRST..LAST  the binlog sequence numbers spanning START..STOP: `binlog-ship.py --list` shows what
#                is shipped, `--restore-check <object>` prints the window one file covers. One extra
#                file before START is a harmless margin — --start-datetime filters it.
set -euo pipefail

: "${BASE_OBJ:?set BASE_OBJ to a nightly dump object}"
: "${START:?set START (UTC, the base dump moment)}"
: "${STOP:?set STOP (UTC, exclusive, inside the last shipped binlog)}"
: "${FIRST:?set FIRST (first binlog sequence number)}"
: "${LAST:?set LAST (last binlog sequence number)}"
IMG=${IMG:-dmc/mysql-pitr:8.4.10}   # the production server image + mysqlbinlog (pitr-tools.Dockerfile)

PROD_DB=u8ha9zwdgekz9djnjt1ndisf
APP_UUID=v5d8vrnp418stpcwnup3yhta
NET=dmc-pitr-rehearsal-net
CT=dmc-pitr-rehearsal-db
STATE=/var/backups/dmc/binlog-shipped.json
BACKUP_PY=/opt/dmc/backup/db-backup.py

say() { echo "[$(date -u +%H:%M:%S)] $*"; }
T0=$(date +%s)

W=$(mktemp -d -p /var/backups/dmc pitr-rehearsal-XXXXXX); chmod 700 "$W"
cleanup() {
    set +e
    docker rm -f -v "$CT" >/dev/null 2>&1        # -v: the anonymous /var/lib/mysql volume = the restored copy
    docker network rm "$NET" >/dev/null 2>&1
    find "$W" -type f -exec shred -u {} + 2>/dev/null; rm -rf "$W"
    say "cleanup: throwaway server + its volume + network removed, work dir shredded"
}
trap cleanup EXIT

read_cfg() { sed -n "s/^${1}=//p" /root/.dmc-backup.env | tail -n1 | tr -d "\"'"; }
KEYFILE=$(read_cfg KEYFILE); KEYFILE=${KEYFILE:-/root/.dmc-backup.key}
decrypt() { openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass "file:$KEYFILE" -in "$1"; }

# ---- 0. the throwaway server: internal network (no route in or out), no published port --------
docker image inspect "$IMG" >/dev/null 2>&1 || { say "tools image $IMG not found — build it from pitr-tools.Dockerfile"; exit 2; }
docker network create --internal "$NET" >/dev/null
MYSQL_ROOT_PASSWORD=$(openssl rand -hex 24); export MYSQL_ROOT_PASSWORD
docker run -d --name "$CT" --network "$NET" -e MYSQL_ROOT_PASSWORD "$IMG" >/dev/null
ready() { docker logs "$CT" 2>&1 | grep -c 'ready for connections.*port: 3306' || true; }
for _ in $(seq 1 90); do [ "$(ready)" -ge 1 ] && break; sleep 2; done
[ "$(ready)" -ge 1 ] || { say "throwaway server never became ready"; exit 1; }
say "throwaway $IMG ready as $CT on internal network $NET"
q() { docker exec -i "$CT" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot "$@"' sh "$@"; }
COUNTS_SQL="SELECT (SELECT COUNT(*) FROM audit_log), (SELECT COUNT(*) FROM admissions), (SELECT COUNT(*) FROM patients), (SELECT COUNT(*) FROM handover_revisions), (SELECT COALESCE(MAX(created_at),'-') FROM audit_log)"

# ---- 1. base: the 02:15 nightly dump into dmc_restore_drill — the drill's own pipeline ---------
T1=$(date +%s)
python3 "$BACKUP_PY" --download "$BASE_OBJ" "$W/base.sql.gz.enc" >/dev/null
q -e 'CREATE DATABASE `dmc_restore_drill`' </dev/null
decrypt "$W/base.sql.gz.enc" | gunzip -c | sed -e 's/`dmc_demo`/`dmc_restore_drill`/g' | q --database=dmc_restore_drill

# A dump taken with --source-data=2 names the exact binlog file and position it corresponds to, in a
# comment near the top. Use it: --start-position is exact, where --start-datetime is a second-precision
# guess that re-applies a handful of boundary events. Read it from the same encrypted copy, in a pipe.
COORD=$( { decrypt "$W/base.sql.gz.enc" 2>/dev/null | gunzip -c 2>/dev/null | head -c 262144; } \
        | grep -m1 -oE "SOURCE_LOG_FILE='[^']+', SOURCE_LOG_POS=[0-9]+" || true)   # head closing the pipe is expected
shred -u "$W/base.sql.gz.enc"
START_POS=""
if [ -n "$COORD" ]; then
    CFILE=${COORD#*SOURCE_LOG_FILE=\'}; CFILE=${CFILE%%\'*}
    START_POS=${COORD##*SOURCE_LOG_POS=}
    FIRST=$((10#$(printf '%s' "$CFILE" | tr -dc '0-9')))   # the position belongs to THIS file, so start here
    say "dump records its own coordinate ($CFILE pos $START_POS) — replaying with --start-position"
else
    say "dump carries no coordinate (taken before --source-data=2) — falling back to --start-datetime=$START"
fi
BEFORE=$(echo "$COUNTS_SQL" | q -N dmc_restore_drill)
say "base restored in $(( $(date +%s) - T1 ))s   audit_log admissions patients handover_revisions newest_audit: $BEFORE"

# ---- 2. the archived binlogs covering START..STOP, decrypted into the 700 work dir -------------
FILES=""
for n in $(seq "$FIRST" "$LAST"); do
    N=$(printf 'binlog.%06d' "$n")
    OBJ=$(python3 -c "import json; r=json.load(open('$STATE'))['files']['$N']; print(r.get('object') or r.get('key'))")
    python3 "$BACKUP_PY" --download "$OBJ" "$W/$N.gz.enc" >/dev/null
    decrypt "$W/$N.gz.enc" | gunzip -c > "$W/$N"
    shred -u "$W/$N.gz.enc"
    FILES="$FILES /pitr/$N"
done
say "binlog.$(printf %06d "$FIRST")..binlog.$(printf %06d "$LAST") fetched and decrypted into the work dir"

# ---- 3. replay — the EXACT command of §10.5 step 3: mysqlbinlog in a one-off tools container
#          (no network, the work dir mounted read-only, so no second plaintext copy anywhere),
#          --rewrite-db, --database AFTER the rewrite, piped into the target server's own client --
T3=$(date +%s)
if [ -n "$START_POS" ]; then START_FLAG="--start-position=$START_POS"; else START_FLAG="--start-datetime=$START"; fi
# shellcheck disable=SC2086  # FILES is a deliberate word list of paths without spaces; START_FLAG is one token
docker run --rm --network none -v "$W":/pitr:ro --entrypoint mysqlbinlog "$IMG" \
    --rewrite-db="dmc_demo->dmc_restore_drill" \
    --database=dmc_restore_drill \
    $START_FLAG --stop-datetime="$STOP" $FILES \
  | q --database=dmc_restore_drill

# How much the replay actually carried. On a quiet window (this app is not yet the daily system) the
# audit trail can legitimately not move, and then "applied everything" and "applied nothing" look the
# same from row counts alone — so count the events too. A second pass over the same files, decoded but
# never stored: grep counts the event headers and throws the rest, including any PHI, away.
# shellcheck disable=SC2086
EVENTS=$(docker run --rm --network none -v "$W":/pitr:ro --entrypoint mysqlbinlog "$IMG" \
    --rewrite-db="dmc_demo->dmc_restore_drill" \
    --database=dmc_restore_drill --base64-output=DECODE-ROWS \
    $START_FLAG --stop-datetime="$STOP" $FILES 2>/dev/null \
  | grep -c '^# at ' || true)
AFTER=$(echo "$COUNTS_SQL" | q -N dmc_restore_drill)
say "replayed $START -> $STOP in $(( $(date +%s) - T3 ))s   audit_log admissions patients handover_revisions newest_audit: $AFTER"

# ---- 4a. cross-check against LIVE (read-only). audit_log.created_at is DB-written in UTC, so the
#          rows the live table holds before STOP must be exactly the rows the replay rebuilt -----
LIVE=$(docker exec -i "$PROD_DB" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot -N dmc_demo' \
       <<< "SELECT COUNT(*) FROM audit_log WHERE created_at < '$STOP'")
B_AUD=$(echo "$BEFORE" | cut -f1); A_AUD=$(echo "$AFTER" | cut -f1); NEWEST=$(echo "$AFTER" | cut -f5)
say "live audit_log rows before $STOP: $LIVE   replayed copy: $A_AUD   base: $B_AUD"

# ---- 4b. the tamper-evident chain over the recovered rows: the app's OWN audit:verify, in a
#          one-off container from the running image, attached to the internal network ONLY -------
APP_C=$(docker ps --filter "name=$APP_UUID" --format '{{.Names}}'); APP_C=${APP_C%%$'\n'*}
APP_IMG=$(docker inspect --format '{{.Config.Image}}' "$APP_C")
APP_KEY=$(docker exec "$APP_C" printenv APP_KEY); export APP_KEY
DB_PASSWORD="$MYSQL_ROOT_PASSWORD"; export DB_PASSWORD
CHAIN=$(docker run --rm --network "$NET" --entrypoint sh \
    -e APP_KEY -e DB_PASSWORD -e APP_ENV=production -e APP_DEBUG=false \
    -e DB_CONNECTION=mysql -e DB_HOST="$CT" -e DB_PORT=3306 -e DB_DATABASE=dmc_restore_drill -e DB_USERNAME=root \
    -e CACHE_STORE=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync -e LOG_CHANNEL=stderr -e MAIL_MAILER=log \
    "$APP_IMG" -c 'php artisan audit:verify' 2>&1 | tail -2)
unset APP_KEY DB_PASSWORD
say "audit:verify on the recovered copy: $CHAIN"

# ---- 5. verdict — the rehearsal only counts if the replay actually carried the window ---------
PASS=1
if [ "$A_AUD" -gt "$B_AUD" ]; then
    say "applied: audit_log grew $B_AUD -> $A_AUD over the window ($EVENTS binlog events)"
elif [ "$EVENTS" -gt 0 ]; then
    say "applied: $EVENTS binlog event(s); audit_log did not move — a quiet window, legitimate while this app is not yet the daily system"
else
    say "FAIL: the replay applied nothing at all — no rows, no events"; PASS=0
fi
[ "$A_AUD" -eq "$LIVE" ]  || { say "FAIL: replayed audit_log ($A_AUD) != live rows before STOP ($LIVE)"; PASS=0; }
[[ "$NEWEST" < "$STOP" ]] || { say "FAIL: newest recovered audit row $NEWEST is not before $STOP"; PASS=0; }
echo "$CHAIN" | grep -q 'Chain intact' || { say "FAIL: hash chain not intact on the recovered copy"; PASS=0; }
[ "$PASS" -eq 1 ] && say "PITR REHEARSAL PASS in $(( $(date +%s) - T0 ))s total" || { say "PITR REHEARSAL FAILED"; exit 1; }
