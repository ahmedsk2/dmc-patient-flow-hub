#!/usr/bin/env python3
"""
binlog-ship.py — hourly encrypted, off-box MySQL BINARY LOG shipping: point-in-time recovery for the
DMC patient-flow hub (finding DATA-02, the "no PITR" half).

Why this exists. The nightly `db-backup.py` gives an RPO of 24 hours: lose the host at 02:14 and
everything the unit entered since the previous 02:15 dump is gone. MySQL already writes a binary log
of every change, but that log lives in the MySQL data volume ON THE SAME HOST as the database it
protects, so a host loss takes the recovery data with it. This script copies each CLOSED binary log
off the box, encrypted, once an hour — which is what turns "restore last night's dump" into "restore
last night's dump and replay up to any chosen second".

Runs as ROOT from host cron on the database host — NOT inside a container. Same dependency floor as
db-backup.py: python3 stdlib + `openssl` + `docker`. It reuses db-backup.py's own SigV4 client,
config loader, openssl command builders and log format (loaded by path, see _load_db_backup), so the
objects it writes are byte-compatible with the nightly backups and decrypt with the SAME command the
restore drill already uses. db-backup.py itself is not modified.

Pipeline, once an hour (one streaming pass per file — no plaintext ever touches the host disk):

    docker exec <container> sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -e "FLUSH BINARY LOGS"'
      │  (rotates the log MySQL is writing to, so the previous hour's changes are in a CLOSED file;
      │   the password is expanded inside the container and is never in a host argv or log line)
      ▼
    SHOW BINARY LOGS  →  [(name, size), …]   the LAST one is the new active file — never shipped
      ▼
    for every closed file not already in the state file with the same size:
        docker exec <container> cat /var/lib/mysql/<name>       (rotated binlogs are immutable, so a
          │                                                      plain `cat` is a consistent copy)
          ▼
        gzip (in-process, stdlib)  →  openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt
          ▼
        HEAD, then SigV4 PUT → s3://<bucket>/<S3_PREFIX>/binlogs/YYYY/MM/<name>-<stamp>.gz.enc
          ▼
        state file <LOCAL_DIR>/binlog-shipped.json updated after EACH file
      ▼
    SigV4 PUT → s3://<bucket>/<S3_PREFIX>/binlogs/LATEST.json   (heartbeat; same field names as the
                                                                 nightly LATEST.json)

Safety properties
    * Idempotent. A file already recorded in the state file with the same size is skipped, so the
      script is safe to run by hand, twice, or after a partial failure. Because rotated binary logs
      are immutable, "same name + same size" is a sound identity; a size that CHANGED is treated as
      suspicious and re-shipped (and the run says so).
    * It NEVER deletes a binary log. MySQL expires them itself via `binlog_expire_logs_seconds`;
      deleting them here could destroy the only copy of a window that has not been shipped yet.
    * It never ships the active file (a torn, still-growing copy is worse than no copy), and it
      refuses any file that does not begin with the MySQL binary-log magic bytes or whose byte count
      disagrees with `SHOW BINARY LOGS`.
    * ONE bad file does not block the rest. Each file is shipped independently; failures are
      collected, everything shippable still goes off-box, the heartbeat is still written, and the
      run exits 1 naming what failed. (A single permanently-unreadable file used to stall every
      later file until MySQL expired it — silent, unrecoverable data loss.)
    * It detects a HOLE in the chain: if the oldest binary log the server still has is newer than
      the newest one ever archived + 1, logs expired before they were shipped and point-in-time
      recovery cannot cross that window. The run fails loudly and names the gap.
    * It detects a SERVER IDENTITY change (`@@server_uuid`): a new data directory restarts binary-log
      numbering, so archived files and live files with the same name are different logs.
    * Every child process runs under a timeout with a terminate→kill fallback, and a failure in the
      encrypt half tears the reader down instead of blocking forever on a full pipe.

Modes
    (default)                 flush → list → ship every new closed file → heartbeat → prune state
    --no-flush                same, but do NOT rotate first (catch-up run; ships what is already closed)
    --dry-run                 read-only: list what WOULD ship. No flush, no upload, no state write.
    --list                    show every binary log MySQL has and whether it has been shipped
    --restore-check OBJECT    download OBJECT, decrypt + gunzip IN A PIPE (decrypted bytes never hit
                              the disk) and walk its event chain: proves the archived copy is a
                              complete, parseable binary log and prints the time window it covers
    --print-latest            print the binlogs/LATEST.json heartbeat
To fetch an archived binlog back for a replay, use the sibling: `db-backup.py --download <object>
<dest>` (same bucket, same signer) and decrypt it with the line in docs/BACKUP-AND-RESTORE.md.

Config: the SAME /root/.dmc-backup.env db-backup.py reads (override with --env-file). It adds these
optional keys — every one of them has a working default, so an existing config file needs no edit:
    BINLOG_DIR          datadir inside the container holding the binlogs   (default /var/lib/mysql)
    BINLOG_PREFIX       object prefix                                      (default <S3_PREFIX>/binlogs)
    BINLOG_STATE_FILE   shipped-file ledger                                (default <LOCAL_DIR>/binlog-shipped.json)
    BINLOG_STATE_KEEP   expired-file records kept in the state file        (default 2000)
    BINLOG_LOG_FILE     log file                                           (default /var/log/dmc-binlog-ship.log)
    BINLOG_DOCKER_USER  `docker exec -u <user>` when the default container
                        user cannot read the datadir                       (default: unset)

Exit codes: 0 success · 1 failure (one clear line on stderr and in the log) · 2 configuration error.
Never prints secrets: child stderr is truncated and scrubbed before it is logged.
See laravel/docs/BACKUP-AND-RESTORE.md — "Point-in-time recovery (binlogs)".
"""

import argparse
import datetime as _dt
import gzip
import hashlib
import importlib.util
import json
import os
import re
import shlex
import socket
import struct
import subprocess
import sys
import tempfile
import time

HERE = os.path.dirname(os.path.abspath(__file__))


def _load_db_backup():
    """Load the sibling db-backup.py as a module.

    `import db_backup` cannot work: the file is hyphenated (a cron-friendly script name, already
    installed as /opt/dmc/backup/db-backup.py and referenced by /etc/cron.d/dmc-db-backup and by
    db-restore-drill.sh), and renaming it would break all three. So we load it by path — exactly the
    trick test_db_backup.py has always used. Nothing in db-backup.py changes, its tests keep passing,
    and every helper below (SigV4 client, config loader, openssl commands, hashing, logging) is the
    ONE implementation shared by both scripts — which is what makes the ciphertext format identical.
    """
    path = os.path.join(HERE, "db-backup.py")
    spec = importlib.util.spec_from_file_location("db_backup", path)
    if spec is None or spec.loader is None:  # pragma: no cover - only if the file is missing
        raise RuntimeError(f"cannot load {path}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


db_backup = _load_db_backup()

BackupError = db_backup.BackupError          # one exception type across both scripts
CHUNK = db_backup.CHUNK
CIPHER_LABEL = db_backup.CIPHER_LABEL
BINLOG_MAGIC = b"\xfe\x62\x69\x6e"           # every MySQL binary log starts with these four bytes
EVENT_HEADER = 19                            # the v4 common header in front of every binlog event
STATE_VERSION = 1
PRODUCER = "scripts/backup/binlog-ship.py"

# No child ever gets to block this script forever: a wedged `docker exec` used to hold the run and
# its lock indefinitely. MYSQL_TIMEOUT covers a one-statement client call; WAIT_TIMEOUT is the grace
# period for a streaming child that should already be exiting.
MYSQL_TIMEOUT = 60
WAIT_TIMEOUT = 120

BINLOG_DEFAULTS = {
    "BINLOG_DIR": "/var/lib/mysql",
    "BINLOG_LOG_FILE": "/var/log/dmc-binlog-ship.log",
    "BINLOG_STATE_KEEP": "2000",
    "BINLOG_DOCKER_USER": "",
}

# binlog.000002 / mysql-bin.000001 — a base name plus MySQL's sequence (six digits, and more once a
# server passes 999999 rotations). Anything else is refused: the name is used as an object key AND as
# a path inside `docker exec`, so it must not be able to carry a slash, a space or a shell
# metacharacter. Always matched with fullmatch().
NAME_RE = re.compile(r"[A-Za-z0-9][A-Za-z0-9._-]{0,63}\.(\d{6,10})")
UUID_RE = re.compile(r"[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}")


# --------------------------------------------------------------------------------------------------
# Pure helpers (unit-tested)
# --------------------------------------------------------------------------------------------------

def build_binlog_config(cfg: dict) -> dict:
    """db-backup.py's validated config + the binlog-only keys, all defaulted."""
    out = dict(cfg)
    for key, value in BINLOG_DEFAULTS.items():
        if not out.get(key):
            out[key] = value
    if not out.get("BINLOG_PREFIX"):
        out["BINLOG_PREFIX"] = f"{out['S3_PREFIX']}/binlogs"
    out["BINLOG_PREFIX"] = out["BINLOG_PREFIX"].strip("/")
    if not out.get("BINLOG_STATE_FILE"):
        out["BINLOG_STATE_FILE"] = os.path.join(out["LOCAL_DIR"], "binlog-shipped.json")
    try:
        out["BINLOG_STATE_KEEP"] = int(out["BINLOG_STATE_KEEP"])
    except (TypeError, ValueError):
        raise BackupError("config: BINLOG_STATE_KEEP must be an integer") from None
    if out["BINLOG_STATE_KEEP"] < 1:
        raise BackupError("config: BINLOG_STATE_KEEP must be >= 1")
    if not re.fullmatch(r"/[A-Za-z0-9_./-]*", out["BINLOG_DIR"]) or out["BINLOG_DIR"].endswith("/"):
        raise BackupError("config: BINLOG_DIR must be an absolute path with no trailing slash")
    if out["BINLOG_DOCKER_USER"] and not re.fullmatch(r"[A-Za-z0-9_.:-]+", out["BINLOG_DOCKER_USER"]):
        raise BackupError("config: BINLOG_DOCKER_USER must be a plain user[:group]")
    return out


def binlog_sequence(name: str) -> int:
    """MySQL's numeric suffix — the only reliable ordering (lexical order breaks at .999999→.1000000)."""
    match = NAME_RE.fullmatch(name)
    if not match:
        raise BackupError(f"not a binary log name: {name!r}")
    return int(match.group(1))


def binlog_basename(name: str) -> str:
    """`binlog` for `binlog.000002` — the `log_bin_basename` part, without the sequence."""
    return name.rsplit(".", 1)[0]


def parse_show_binary_logs(text: str):
    """`SHOW BINARY LOGS` under `mysql --batch --skip-column-names`: one tab-separated row per file,
    `Log_name<TAB>File_size` (MySQL 8 adds an `Encrypted` column, which we ignore). Returns
    [(name, size)] in the order MySQL reported, which is oldest → newest."""
    rows = []
    for raw in text.splitlines():
        line = raw.strip()
        if not line:
            continue
        parts = line.split("\t")
        if len(parts) < 2:
            raise BackupError(f"cannot parse a SHOW BINARY LOGS row: {raw!r}")
        name, size = parts[0].strip(), parts[1].strip()
        if not NAME_RE.fullmatch(name):
            raise BackupError(f"refusing an unexpected binary log name: {name!r}")
        if any(name == seen for seen, _ in rows):
            raise BackupError(f"SHOW BINARY LOGS listed {name} twice — refusing to guess which size is real")
        try:
            rows.append((name, int(size)))
        except ValueError:
            raise BackupError(f"cannot parse the size of {name}: {size!r}") from None
    if not rows:
        raise BackupError("SHOW BINARY LOGS returned nothing — is log_bin ON on this server?")
    # "Which file is active" is decided by the highest sequence number, which is only meaningful
    # within ONE basename. If log_bin_basename was changed, two independent series would be
    # interleaved and we could mistake a closed file for the active one (or vice versa).
    bases = sorted({binlog_basename(name) for name, _ in rows})
    if len(bases) > 1:
        raise BackupError(
            f"SHOW BINARY LOGS mixes two binary-log basenames ({bases[0]} and {bases[1]}) — "
            "log_bin_basename appears to have changed; refusing to guess which file is active"
        )
    return rows


def select_files_to_ship(entries, shipped: dict):
    """Decide what this run archives.

    `entries` = [(name, size)] from SHOW BINARY LOGS; `shipped` = the state file's "files" map.

    The file with the HIGHEST sequence number is the one MySQL is writing to right now: it is still
    growing, so a copy of it would be torn. It is skipped every run and picked up on the next one,
    after the next FLUSH has rotated it closed. Every other file is immutable, so a name already in
    the state with the SAME byte count is a no-op — that is what makes this idempotent. A recorded
    file whose size CHANGED cannot happen on a healthy server; it is re-shipped and reported.

    Returns (to_ship, active_name, already_shipped_names, resized_names).
    """
    ordered = sorted(entries, key=lambda row: binlog_sequence(row[0]))
    active = ordered[-1][0]
    to_ship, already, resized = [], [], []
    for name, size in ordered[:-1]:
        record = shipped.get(name)
        recorded = record.get("plaintext_bytes") if isinstance(record, dict) else None
        if record is None:
            to_ship.append((name, size))
        elif isinstance(recorded, int) and recorded == size:
            already.append(name)
        else:
            resized.append(name)
            to_ship.append((name, size))
    return to_ship, active, already, resized


def detect_expired_gap(entries, shipped: dict):
    """Has MySQL expired binary logs that were never archived?

    `binlog_expire_logs_seconds` deletes old logs on the server's own schedule. If the shipper was
    broken (or never installed) across that window, the logs for those hours are gone for good and a
    point-in-time replay cannot cross the hole — which is exactly the kind of silent failure that is
    only discovered during a real recovery. Returns (first_missing, last_missing) as file names, or
    None when the chain is contiguous (or when nothing has been archived yet, so there is no
    baseline to compare against).
    """
    if not shipped or not entries:
        return None
    base = binlog_basename(entries[0][0])
    width = len(NAME_RE.fullmatch(entries[0][0]).group(1))
    oldest_present = min(binlog_sequence(name) for name, _ in entries)
    newest_shipped = max(binlog_sequence(name) for name in shipped)
    if oldest_present <= newest_shipped + 1:
        return None
    return (f"{base}.{newest_shipped + 1:0{width}d}", f"{base}.{oldest_present - 1:0{width}d}")


def record_gap(state: dict, gap) -> bool:
    """Remember a hole forever, and say whether it is NEW.

    `detect_expired_gap` only ever sees the *current* boundary, so once the run archives the newest
    file the hole stops being detectable — the chain looks contiguous again even though a window of
    changes is permanently missing. Writing it into the state file keeps it visible to `--list`, to
    the heartbeat and to whoever is choosing files during a recovery. Only a NEW hole fails the run:
    re-failing every hour forever would be noise that hides the next real problem.
    """
    gaps = state.setdefault("gaps", [])
    entry = [gap[0], gap[1]]
    if entry in gaps:
        return False
    gaps.append(entry)
    return True


def binlog_object_key(prefix: str, name: str, when: _dt.datetime) -> str:
    """<prefix>/YYYY/MM/<name>-<UTC stamp>.gz.enc — month folders, like the nightly dumps, so one
    lifecycle rule covers both.

    The stamp is not decoration. Binary-log numbering restarts from .000001 whenever MySQL gets a new
    data directory (a rebuild, a restore onto fresh storage, `RESET BINARY LOGS AND GTIDS`), so a
    name alone is NOT unique over the life of the archive — without the stamp the second generation's
    `binlog.000001` would silently overwrite the first generation's, destroying archived recovery
    data with a successful-looking PUT. The state file and the heartbeat both record the full key.
    """
    if not NAME_RE.fullmatch(name):
        raise BackupError(f"refusing an unexpected binary log name: {name!r}")
    return (f"{prefix}/{when.strftime('%Y')}/{when.strftime('%m')}/"
            f"{name}-{when.strftime('%Y-%m-%dT%H%M%SZ')}.gz.enc")


def binlog_heartbeat_key(prefix: str) -> str:
    return f"{prefix}/LATEST.json"


def binlog_record(name, key, plaintext_bytes, sha256_of_plaintext, cipher_bytes,
                  sha256_of_ciphertext, md5_of_ciphertext, when) -> dict:
    """One entry in the state file — also the source of the heartbeat's per-object fields."""
    return {
        "binlog": name,
        "object": key,
        "plaintext_bytes": plaintext_bytes,
        "sha256_of_plaintext": sha256_of_plaintext,
        "bytes": cipher_bytes,
        "sha256_of_ciphertext": sha256_of_ciphertext,
        "md5_of_ciphertext": md5_of_ciphertext,
        "shipped_at": when.strftime("%Y-%m-%dT%H:%M:%SZ"),
    }


def last_shipped(files: dict):
    """The newest record in the state file, by binlog sequence (not by insertion order)."""
    if not files:
        return None
    return files[max(files, key=binlog_sequence)]


def binlog_heartbeat_document(last, when, db_name, shipped_now, active, known, host,
                              failed=(), gap=None, server_uuid=None, known_gaps=()) -> dict:
    """`object`, `bytes`, `sha256_of_ciphertext` and `created_at` deliberately carry the SAME field
    names as db-backup.py's LATEST.json, so `backup:verify` reads this key with the same code.

    `created_at` is when the SHIPPER last ran — that is the value freshness monitoring must age,
    because a shipper that stopped running is the failure that matters (an hourly job with a 26-hour
    staleness window would hide it; ~2 hours is the right threshold here). `last_shipped_at` is when
    the object named in `object` was uploaded. `object` is null only before the very first file has
    ever been shipped. `failed_this_run` is what tells a monitor that the shipper is alive but is NOT
    archiving everything — a fresh `created_at` alone would look healthy."""
    record = last or {}
    failed = list(failed)
    return {
        "object": record.get("object"),
        "bytes": record.get("bytes"),
        "sha256_of_ciphertext": record.get("sha256_of_ciphertext"),
        "md5_of_ciphertext": record.get("md5_of_ciphertext"),
        "created_at": when.strftime("%Y-%m-%dT%H:%M:%SZ"),
        "last_shipped_at": record.get("shipped_at"),
        "binlog": record.get("binlog"),
        "plaintext_bytes": record.get("plaintext_bytes"),
        "sha256_of_plaintext": record.get("sha256_of_plaintext"),
        "db": db_name,
        "shipped_this_run": shipped_now,
        "failed_this_run": len(failed),
        "failed_binlogs": failed,
        "expired_unshipped": list(gap) if gap else None,
        "known_gaps": [list(g) for g in known_gaps],
        "active_binlog": active,
        "known_binlogs": known,
        "server_uuid": server_uuid,
        "cipher": CIPHER_LABEL,
        "host": host,
        "producer": PRODUCER,
    }


def prune_state(files: dict, present, keep: int):
    """Cap the state file so it cannot grow without bound.

    A record is only ever dropped when MySQL no longer lists that file (it has expired out of the
    server under `binlog_expire_logs_seconds`, so it can never be offered for shipping again) AND it
    is older than the newest `keep` such records. Records for files MySQL still has are never
    dropped — that is what keeps the run idempotent. Returns (kept_files, dropped_names).
    """
    present = set(present)
    absent = sorted((n for n in files if n not in present), key=binlog_sequence)
    dropped = absent[:-keep] if len(absent) > keep else []
    if not dropped:
        return files, []
    doomed = set(dropped)
    return {n: m for n, m in files.items() if n not in doomed}, dropped


def empty_state(db_name: str) -> dict:
    return {"version": STATE_VERSION, "db": db_name, "files": {}, "updated_at": None}


def check_server_identity(state: dict, server_uuid):
    """A new MySQL data directory means a new `@@server_uuid` AND binary-log numbering that restarts
    at .000001. Archived `binlog.000007` and live `binlog.000007` would then be different logs, and
    replaying a mixture of them onto a restore would corrupt it. Stop and make a human look."""
    recorded = state.get("server_uuid")
    if recorded and server_uuid and recorded != server_uuid:
        raise BackupError(
            f"this MySQL server's identity changed (@@server_uuid was {recorded}, is now "
            f"{server_uuid}). Binary-log numbering restarts with a new data directory, so the "
            "archived logs and the ones on the server now are DIFFERENT logs and must never be "
            "mixed in a replay. Confirm what happened, keep the old objects, then move the state "
            "file aside to start a fresh chain."
        )


def _read_exactly(fh, n):
    """Read exactly n bytes (a pipe/gzip read can be short); returns fewer only at EOF."""
    buf = b""
    while len(buf) < n:
        chunk = fh.read(n - len(buf))
        if not chunk:
            break
        buf += chunk
    return buf


def scan_binlog_stream(fh) -> dict:
    """Walk a decrypted binary log's event chain, without MySQL.

    After the 4-byte magic every event starts with the v4 common header, all little-endian:
        timestamp(4) type_code(1) server_id(4) event_length(4) next_position(4) flags(2) = 19 bytes
    Only `event_length` is needed to hop to the next event, so this parses any MySQL 5.6+ binary log
    whether or not CRC32 checksums are on (they are counted inside event_length).

    This is deliberately used instead of shelling out to `mysqlbinlog --short-form`: it needs no
    MySQL, it works on a stream so the decrypted bytes never touch the disk, it fails on a truncated
    archive (the chain must end exactly on EOF), and it reports the time window the file covers —
    which is what an operator actually needs when picking files for a replay.

    Returns {bytes, events, sha256, first_ts, last_ts}. Raises BackupError on anything malformed.
    """
    digest = hashlib.sha256()

    def take(n):
        buf = _read_exactly(fh, n)
        digest.update(buf)
        return buf

    if take(4) != BINLOG_MAGIC:
        raise BackupError("stream does not start with the MySQL binary-log magic bytes")

    total, events = 4, 0
    first_ts = last_ts = None
    while True:
        header = take(EVENT_HEADER)
        if not header:
            break
        if len(header) < EVENT_HEADER:
            raise BackupError(f"truncated event header at offset {total} "
                              f"({len(header)} of {EVENT_HEADER} bytes)")
        timestamp, _type, _server, length, _next, _flags = struct.unpack("<IBIIIH", header)
        if length < EVENT_HEADER:
            raise BackupError(f"event at offset {total} claims an impossible length ({length})")
        body = take(length - EVENT_HEADER)
        if len(body) != length - EVENT_HEADER:
            raise BackupError(f"binary log is truncated: the event at offset {total} needs {length} "
                              f"bytes but only {EVENT_HEADER + len(body)} are there")
        total += length
        events += 1
        if timestamp:
            first_ts = timestamp if first_ts is None else min(first_ts, timestamp)
            last_ts = timestamp if last_ts is None else max(last_ts, timestamp)
    if events == 0:
        raise BackupError("binary log contains no events")
    return {"bytes": total, "events": events, "sha256": digest.hexdigest(),
            "first_ts": first_ts, "last_ts": last_ts}


def iso(ts):
    return "-" if ts is None else _dt.datetime.fromtimestamp(ts, _dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


# --------------------------------------------------------------------------------------------------
# Commands run against the container (patched wholesale in the tests)
# --------------------------------------------------------------------------------------------------

def _docker(container, docker_user=None):
    argv = ["docker", "exec"]
    if docker_user:
        argv += ["-u", docker_user]
    return argv + [container]


def mysql_cmd(container, sql, docker_user=None):
    # Same credential mechanism as db-backup.py's mysqldump_cmd: the root password is expanded by
    # `sh` INSIDE the container from its own MYSQL_ROOT_PASSWORD; nothing secret is in this argv,
    # in this process's environment, or in anything we log. `sql` is a constant from this file.
    inner = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql --batch --skip-column-names -e ' + shlex.quote(sql)
    return _docker(container, docker_user) + ["sh", "-c", inner]


def read_binlog_cmd(container, binlog_dir, name, docker_user=None):
    """`docker exec <container> cat <datadir>/<name>` — chosen over `docker cp`.

    `docker cp` would give us a TAR-wrapped stream we would have to unwrap before hashing, and it
    cannot stream through the existing gzip→openssl pipeline unchanged. `cat` hands us the exact
    bytes of the file, which is what we want to hash and encrypt, and it keeps the pipeline shape
    identical to db-backup.py's dump. It is safe here precisely because we only ever read CLOSED
    binary logs: a rotated binlog is immutable, so there is no torn-read window. No `sh -c` is used,
    so the file name is its own argv element and cannot be interpreted by a shell (it is also
    validated against NAME_RE before it gets here).
    """
    if not NAME_RE.fullmatch(name):
        raise BackupError(f"refusing an unexpected binary log name: {name!r}")
    return _docker(container, docker_user) + ["cat", f"{binlog_dir}/{name}"]


class BinlogS3Client(db_backup.S3Client):
    """db-backup.py's SigV4 client plus HEAD, used to refuse a silent overwrite before every PUT."""

    def head(self, key):
        status, headers, _ = self._with_retries(
            f"HEAD {key}", lambda: self._request("HEAD", key, db_backup.EMPTY_SHA256))
        return status, headers


def make_client(cfg):
    return BinlogS3Client(cfg["S3_ENDPOINT"], cfg["S3_REGION"], cfg["S3_BUCKET"],
                          cfg["S3_ACCESS_KEY"], cfg["S3_SECRET"])


# --------------------------------------------------------------------------------------------------
# Host-side plumbing
# --------------------------------------------------------------------------------------------------

def utcnow():
    return _dt.datetime.now(_dt.timezone.utc)


def log_line(cfg, line):
    """db-backup.py's exact one-line-per-event format, in our own log file."""
    return db_backup.log_line({"LOG_FILE": cfg["BINLOG_LOG_FILE"]}, line)


def load_config(path: str) -> dict:
    """db-backup.py's loader (which also enforces mode 600 on the env file and the key file)."""
    return build_binlog_config(db_backup.load_config(path))


def _close_quietly(stream):
    try:
        if stream is not None:
            stream.close()
    except OSError:
        pass


def _wait_bounded(proc, what, timeout=WAIT_TIMEOUT):
    """Wait for a child, but never forever: terminate, then kill, then give up with a synthetic code.

    Every call site reaches this only once the child should already be exiting, so the timeout only
    bites when something is genuinely wrong (a wedged docker daemon, a child ignoring SIGTERM). The
    alternative — a bare wait() — is what turns a stalled container call into a run that hangs with
    the lock held until someone notices, i.e. until the archive has silently stopped.
    """
    for step, timeout_s in (("wait", timeout), ("terminate", 5), ("kill", 5)):
        try:
            return proc.wait(timeout=timeout_s)
        except subprocess.TimeoutExpired:
            pass
        if step == "wait":
            proc.terminate()
        elif step == "terminate":
            proc.kill()
    sys.stderr.write(f"warning: {what} did not exit even after SIGKILL\n")
    return -9


def load_state(path: str, db_name: str) -> dict:
    try:
        with open(path, "r", encoding="utf-8") as fh:
            raw = json.load(fh)
    except FileNotFoundError:
        return empty_state(db_name)
    except ValueError as exc:
        raise BackupError(
            f"state file {path} is not valid JSON ({exc}); move it aside to re-ship every binary "
            "log the server still has"
        ) from None
    except OSError as exc:
        raise BackupError(f"state file {path} could not be read: {exc}") from None
    if not isinstance(raw, dict) or not isinstance(raw.get("files"), dict):
        raise BackupError(f"state file {path} has an unexpected shape (expected an object with a \"files\" map)")
    for name, record in raw["files"].items():
        if not NAME_RE.fullmatch(name) or not isinstance(record, dict):
            raise BackupError(f"state file {path} has a bad entry for {name!r}")
    raw.setdefault("version", STATE_VERSION)
    raw.setdefault("db", db_name)
    return raw


def save_state(path: str, state: dict, when):
    """Written atomically AND durably: the ledger is what stops a file being shipped twice or, worse,
    being believed shipped when it is not. fsync before the rename so a host that loses power right
    after an upload cannot come back with an empty or half-written ledger."""
    state["updated_at"] = when.strftime("%Y-%m-%dT%H:%M:%SZ")
    state["producer"] = PRODUCER
    tmp = path + ".tmp"
    with open(tmp, "w", encoding="utf-8") as fh:
        json.dump(state, fh, indent=2, sort_keys=True)
        fh.write("\n")
        fh.flush()
        os.fsync(fh.fileno())
    if os.name == "posix":
        os.chmod(tmp, 0o600)
    os.replace(tmp, path)


def run_mysql(cfg, sql: str) -> str:
    try:
        proc = subprocess.run(
            mysql_cmd(cfg["MYSQL_CONTAINER"], sql, cfg.get("BINLOG_DOCKER_USER")),
            stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=MYSQL_TIMEOUT,
        )
    except subprocess.TimeoutExpired:
        raise BackupError(f"mysql `{sql}` did not answer within {MYSQL_TIMEOUT}s "
                          "(MySQL wedged, or the docker daemon is not responding)") from None
    if proc.returncode != 0:
        detail = db_backup.scrub(db_backup._short(proc.stderr), [cfg.get("S3_SECRET")])
        raise BackupError(f"mysql `{sql}` exited {proc.returncode}: {detail}")
    return proc.stdout.decode("utf-8", "replace")


def read_server_uuid(cfg):
    """Best effort: the identity check is a safety net, not a reason to stop archiving."""
    try:
        value = run_mysql(cfg, "SELECT @@server_uuid").strip()
    except BackupError as exc:
        sys.stderr.write(f"note: could not read @@server_uuid ({exc}); skipping the identity check\n")
        return None
    return value if UUID_RE.fullmatch(value) else None


def stream_and_encrypt(cfg, name, expected_size, out_path):
    """`docker exec … cat <binlog>` | gzip (in-process) | openssl enc → out_path.

    The same pipeline db-backup.py uses for the nightly dump, built from db-backup.py's OWN
    openssl_encrypt_cmd(), so the cipher, KDF, iteration count and salt header are identical and
    `openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000` — the exact line in db-restore-drill.sh and
    in the runbook — decrypts these objects unchanged.

    Returns (plaintext_bytes, sha256 of the plaintext).
    """
    reader = subprocess.Popen(
        read_binlog_cmd(cfg["MYSQL_CONTAINER"], cfg["BINLOG_DIR"], name, cfg.get("BINLOG_DOCKER_USER")),
        stdout=subprocess.PIPE, stderr=subprocess.PIPE,
    )
    enc = subprocess.Popen(db_backup.openssl_encrypt_cmd(cfg["KEYFILE"], out_path),
                           stdin=subprocess.PIPE, stderr=subprocess.PIPE)
    read_err, enc_err = [], []
    t1, t2 = db_backup._drain(reader.stderr, read_err), db_backup._drain(enc.stderr, enc_err)

    plaintext = 0
    digest = hashlib.sha256()
    head = b""
    stream_error = None
    try:
        gz = gzip.GzipFile(filename="", mode="wb", fileobj=enc.stdin, mtime=0)
        try:
            while True:
                chunk = reader.stdout.read(CHUNK)
                if not chunk:
                    break
                gz.write(chunk)
                digest.update(chunk)
                plaintext += len(chunk)
                if len(head) < 4:
                    head = (head + chunk)[:4]
        finally:
            gz.close()
    except OSError as exc:  # BrokenPipe when openssl died early (disk full) — reported below
        stream_error = exc
    finally:
        if stream_error is not None:
            # Nothing will drain the reader's stdout from here on, so `cat` of a 348 MB file would
            # block on a full pipe forever and take the whole run (and its lock) with it. Close our
            # end so the reader gets EPIPE, and make sure both children are actually gone.
            _close_quietly(reader.stdout)
            for child in (reader, enc):
                if child.poll() is None:
                    child.terminate()
        _close_quietly(enc.stdin)
        read_rc = _wait_bounded(reader, f"docker exec cat {name}")
        enc_rc = _wait_bounded(enc, "openssl enc")
        t1.join(timeout=5)
        t2.join(timeout=5)
        for stream in (reader.stdout, reader.stderr, enc.stderr):
            _close_quietly(stream)

    secrets = [cfg.get("S3_SECRET")]
    # The encrypt side is reported first: when it dies (a full disk is the usual reason) the reader
    # is killed as a consequence, and the consequence is the less useful message of the two.
    if enc_rc != 0:
        raise BackupError(f"openssl enc exited {enc_rc} for {name}: "
                          f"{db_backup.scrub(db_backup._short(b''.join(enc_err)), secrets)}")
    if read_rc != 0:
        raise BackupError(f"reading {name} out of the container exited {read_rc}: "
                          f"{db_backup.scrub(db_backup._short(b''.join(read_err)), secrets)}")
    if stream_error is not None:
        raise BackupError(f"pipeline I/O error on {name}: {type(stream_error).__name__}: {stream_error}")
    if head != BINLOG_MAGIC:
        raise BackupError(f"{name} does not start with the MySQL binary-log magic bytes — refusing to ship it")
    if plaintext != expected_size:
        raise BackupError(f"{name}: read {plaintext} bytes but SHOW BINARY LOGS reported {expected_size} "
                          "— a rotated binary log is immutable, so investigate before trusting this archive")
    return plaintext, digest.hexdigest()


def sweep_workdirs(local_dir, older_than=86400):
    """Remove work directories abandoned by a crashed run (db-backup.py's .tmp sweep, for our dirs).
    Runs at the START of every mode, so a crash cannot leave encrypted rubbish accumulating in
    LOCAL_DIR until someone happens to trigger a shipping run."""
    try:
        names = os.listdir(local_dir)
    except OSError:
        return
    for name in names:
        full = os.path.join(local_dir, name)
        if name.startswith(("binlog-ship-", "binlog-check-")) and os.path.isdir(full):
            try:
                if os.path.getmtime(full) < time.time() - older_than:
                    for leftover in os.listdir(full):
                        os.unlink(os.path.join(full, leftover))
                    os.rmdir(full)
            except OSError:
                pass


def _remove_tree(path):
    if path is None or not os.path.isdir(path):
        return
    for leftover in os.listdir(path):
        try:
            os.unlink(os.path.join(path, leftover))
        except OSError:
            pass
    try:
        os.rmdir(path)
    except OSError:
        pass


# --------------------------------------------------------------------------------------------------
# Modes
# --------------------------------------------------------------------------------------------------

def ship_one(cfg, client, state, name, size, now, workdir):
    """Archive ONE binary log. Raises BackupError for this file only — the caller keeps going."""
    out_path = os.path.join(workdir, name + ".gz.enc")
    try:
        plaintext, sha_plain = stream_and_encrypt(cfg, name, size, out_path)
        if os.name == "posix":
            os.chmod(out_path, 0o600)
        cipher_bytes, sha_cipher, md5_digest = db_backup.hash_file(out_path)
        key = binlog_object_key(cfg["BINLOG_PREFIX"], name, now)

        # Refuse to write over an object that is already there with different content. The stamped
        # key makes a collision all but impossible; this is the belt to that pair of braces, because
        # the failure it guards against (overwriting archived recovery data) is unrecoverable.
        status, headers = client.head(key)
        if status == 200:
            existing = dict((k.lower(), v) for k, v in headers).get("etag")
            verdict = db_backup.etag_matches_md5(existing, md5_digest.hex())
            if verdict is False:
                raise BackupError(f"refusing to overwrite {key}: an object is already there and its "
                                  f"ETag {existing} does not match this file's MD5 — investigate before retrying")
        elif status != 404:
            sys.stderr.write(f"note: HEAD {key} returned HTTP {status}; continuing to the upload\n")

        status, headers, body = client.put_file(key, out_path, cipher_bytes, sha_cipher, md5_digest)
        if not 200 <= status < 300:
            raise BackupError(f"upload rejected: HTTP {status} {db_backup._short(body)}")
        etag = dict((k.lower(), v) for k, v in headers).get("etag")
        verdict = db_backup.etag_matches_md5(etag, md5_digest.hex())
        if verdict is False:
            raise BackupError(f"upload integrity check failed: ETag {etag} != local MD5 {md5_digest.hex()}")
        if verdict is None:
            sys.stderr.write("note: ETag is not MD5-shaped; relying on the signed Content-MD5 the server accepted\n")

        state["files"][name] = binlog_record(name, key, plaintext, sha_plain, cipher_bytes,
                                             sha_cipher, md5_digest.hex(), now)
        # After EACH file, so a crash or a later failure never re-ships what already landed.
        save_state(cfg["BINLOG_STATE_FILE"], state, now)
        return cipher_bytes
    finally:
        if os.path.exists(out_path):
            os.unlink(out_path)


def run_ship(cfg, dry_run=False, flush=True):
    started = time.monotonic()
    now = utcnow()
    db_backup.ensure_local_dir(cfg["LOCAL_DIR"])
    sweep_workdirs(cfg["LOCAL_DIR"])

    step = "lock"
    lock = None
    workdir = None
    try:
        try:
            lock = db_backup.acquire_lock(os.path.join(cfg["LOCAL_DIR"], ".binlog-ship.lock"))
        except BackupError:
            raise BackupError("another binlog-ship.py run is still in progress (lock held)") from None

        step = "state"
        state = load_state(cfg["BINLOG_STATE_FILE"], cfg["DB_NAME"])

        step = "identity"
        server_uuid = read_server_uuid(cfg)
        check_server_identity(state, server_uuid)
        if server_uuid:
            state["server_uuid"] = server_uuid

        if flush and not dry_run:
            step = "flush"
            run_mysql(cfg, "FLUSH BINARY LOGS")

        step = "list"
        entries = parse_show_binary_logs(run_mysql(cfg, "SHOW BINARY LOGS"))
        to_ship, active, already, resized = select_files_to_ship(entries, state["files"])
        gap = detect_expired_gap(entries, state["files"])
        new_gap = record_gap(state, gap) if (gap and not dry_run) else False

        if dry_run:
            line = (f"DRY-RUN ok active={active} known={len(entries)} already_shipped={len(already)} "
                    f"would_ship={len(to_ship)} files={','.join(n for n, _ in to_ship) or '-'} "
                    f"bytes={sum(s for _, s in to_ship)} gap={'-'.join(gap) if gap else 'none'} "
                    f"prefix={cfg['BINLOG_PREFIX']} duration_s={time.monotonic() - started:.1f}")
            print(log_line(cfg, line))
            return 0

        if resized:
            sys.stderr.write(f"note: re-shipping {','.join(resized)} — the size changed since it was "
                             "archived, which should be impossible for a rotated binary log\n")

        step = "ship"
        client = make_client(cfg)
        workdir = tempfile.mkdtemp(prefix="binlog-ship-", dir=cfg["LOCAL_DIR"])
        if os.name == "posix":
            os.chmod(workdir, 0o700)

        shipped, failures, shipped_bytes = [], [], 0
        for name, size in to_ship:
            try:
                shipped_bytes += ship_one(cfg, client, state, name, size, now, workdir)
                shipped.append(name)
            except BackupError as exc:
                # One bad file must not stop the rest: everything else still goes off-box, and the
                # run reports the failure instead of quietly stalling the archive behind it.
                failures.append((name, str(exc)))
                sys.stderr.write(f"note: {name} could not be shipped: {exc}\n")

        step = "prune-state"
        state["files"], dropped = prune_state(state["files"], [n for n, _ in entries], cfg["BINLOG_STATE_KEEP"])
        save_state(cfg["BINLOG_STATE_FILE"], state, now)

        step = "heartbeat"
        doc = binlog_heartbeat_document(last_shipped(state["files"]), now, cfg["DB_NAME"], len(shipped),
                                        active, len(entries), socket.gethostname(),
                                        failed=[n for n, _ in failures], gap=gap, server_uuid=server_uuid,
                                        known_gaps=state.get("gaps", []))
        status, _, body = client.put_bytes(binlog_heartbeat_key(cfg["BINLOG_PREFIX"]),
                                           json.dumps(doc, indent=2).encode("utf-8"))
        if not 200 <= status < 300:
            raise BackupError(f"heartbeat upload rejected: HTTP {status} {db_backup._short(body)}")

        summary = (f"shipped={len(shipped)} files={','.join(shipped) or '-'} bytes={shipped_bytes} "
                   f"active={active} known={len(entries)} already={len(already)} failed={len(failures)} "
                   f"state_pruned={len(dropped)} duration_s={time.monotonic() - started:.1f}")

        if failures or new_gap:
            problems = []
            if new_gap:
                problems.append(f"binary logs {gap[0]}..{gap[1]} expired before they were shipped — "
                                "the point-in-time chain has a hole and a replay cannot cross it")
            if failures:
                problems.append("could not ship " + "; ".join(f"{n}: {e}" for n, e in failures))
            line = log_line(cfg, f"FAIL step=ship {' | '.join(problems)} {summary}")
            sys.stderr.write(line + "\n")
            return 1

        print(log_line(cfg, "OK " + summary))
        return 0
    except BackupError as exc:
        line = log_line(cfg, f"FAIL step={step} error={exc}")
        sys.stderr.write(line + "\n")
        return 1
    finally:
        _remove_tree(workdir)
        if lock is not None:
            lock.close()


def run_restore_check(cfg, key):
    """Download → openssl -d → gunzip → walk the event chain, all in a pipe; the only bytes on disk
    are still encrypted. Proves the archived copy is complete and parseable, and prints the window it
    covers so an operator can pick files for a replay (BACKUP-AND-RESTORE.md §10.5)."""
    started = time.monotonic()
    db_backup.ensure_local_dir(cfg["LOCAL_DIR"])
    sweep_workdirs(cfg["LOCAL_DIR"])
    client = make_client(cfg)
    workdir = tempfile.mkdtemp(prefix="binlog-check-", dir=cfg["LOCAL_DIR"])
    cipher_path = os.path.join(workdir, "object.enc")
    try:
        status, _, body = client.get_to_file(key, cipher_path)
        if status != 200:
            raise BackupError(f"download failed: HTTP {status} {db_backup._short(body)}")
        size, sha_cipher, _ = db_backup.hash_file(cipher_path)

        # If the heartbeat advertises this exact object, its recorded ciphertext digest must match.
        hb_status, _, hb_body = client.get_bytes(binlog_heartbeat_key(cfg["BINLOG_PREFIX"]))
        if hb_status == 200:
            try:
                hb = json.loads(hb_body.decode("utf-8"))
            except ValueError:
                hb = {}
            if hb.get("object") == key and hb.get("sha256_of_ciphertext") not in (None, sha_cipher):
                raise BackupError("ciphertext sha256 does not match the heartbeat's sha256_of_ciphertext")

        dec = subprocess.Popen(db_backup.openssl_decrypt_cmd(cfg["KEYFILE"], cipher_path),
                               stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        dec_err = []
        drain = db_backup._drain(dec.stderr, dec_err)
        scan, scan_error = None, None
        try:
            scan = scan_binlog_stream(gzip.GzipFile(fileobj=dec.stdout, mode="rb"))
        except (OSError, EOFError, BackupError) as exc:  # BadGzipFile is an OSError subclass
            scan_error = exc
        finally:
            _close_quietly(dec.stdout)
            rc = _wait_bounded(dec, "openssl -d")
            drain.join(timeout=5)
            _close_quietly(dec.stderr)

        # openssl's verdict first: a wrong key is "bad decrypt" + non-zero exit, and the garbage it
        # emits before noticing is what makes gzip complain — report the cause, not the symptom.
        if rc != 0:
            raise BackupError(f"openssl decrypt exited {rc} (wrong key?): "
                              f"{db_backup.scrub(db_backup._short(b''.join(dec_err)), [cfg.get('S3_SECRET')])}")
        if scan_error is not None:
            raise BackupError(f"{key} is not a usable binary log: {scan_error}")

        line = (f"RESTORE-CHECK ok object={key} bytes={size} sha256={sha_cipher} "
                f"plaintext_bytes={scan['bytes']} sha256_of_plaintext={scan['sha256']} "
                f"events={scan['events']} covers={iso(scan['first_ts'])}..{iso(scan['last_ts'])} "
                f"duration_s={time.monotonic() - started:.1f}")
        print(log_line(cfg, line))
        return 0
    except BackupError as exc:
        line = log_line(cfg, f"RESTORE-CHECK FAIL object={key} error={exc}")
        sys.stderr.write(line + "\n")
        return 1
    finally:
        _remove_tree(workdir)


def run_list(cfg):
    """Read-only inventory: what MySQL has, and what has been archived off-box."""
    state = load_state(cfg["BINLOG_STATE_FILE"], cfg["DB_NAME"])
    entries = parse_show_binary_logs(run_mysql(cfg, "SHOW BINARY LOGS"))
    to_ship, active, already, resized = select_files_to_ship(entries, state["files"])
    pending = {name for name, _ in to_ship}
    print(f"{'binary log':<24} {'bytes':>14}  status")
    for name, size in sorted(entries, key=lambda row: binlog_sequence(row[0])):
        if name == active:
            status = "ACTIVE (never shipped while open)"
        elif name in pending:
            status = "re-ship (size changed)" if name in resized else "pending"
        else:
            status = "shipped " + state["files"][name]["object"]
        print(f"{name:<24} {size:>14}  {status}")
    gaps = [list(g) for g in state.get("gaps", [])]
    live = detect_expired_gap(entries, state["files"])
    if live and list(live) not in gaps:
        gaps.append(list(live))
    for first, last in gaps:
        print(f"\n*** GAP: {first}..{last} expired before they were shipped — a point-in-time "
              "replay cannot cross that window ***")
    print(f"\nstate file: {cfg['BINLOG_STATE_FILE']} ({len(state['files'])} records, "
          f"{len(already)} of the {len(entries)} current files already off-box)")
    return 1 if gaps else 0


def run_print_latest(cfg):
    status, _, body = make_client(cfg).get_bytes(binlog_heartbeat_key(cfg["BINLOG_PREFIX"]))
    if status == 404:
        sys.stderr.write("no binlog heartbeat (binlogs/LATEST.json) in the bucket — "
                         "binlog-ship.py has never completed\n")
        return 1
    if status != 200:
        sys.stderr.write(f"heartbeat fetch failed: HTTP {status} {db_backup._short(body)}\n")
        return 1
    sys.stdout.write(body.decode("utf-8"))
    if not body.endswith(b"\n"):
        sys.stdout.write("\n")
    return 0


def main(argv=None):
    parser = argparse.ArgumentParser(
        description="DMC hourly encrypted off-box MySQL binary-log shipping — point-in-time recovery (DATA-02)")
    parser.add_argument("--env-file", default="/root/.dmc-backup.env")
    parser.add_argument("--no-flush", action="store_true",
                        help="do not FLUSH BINARY LOGS first (catch-up run: ship only what is already closed)")
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument("--dry-run", action="store_true",
                      help="read-only: report what WOULD ship (no flush, no upload, no state write)")
    mode.add_argument("--list", action="store_true", help="show every binary log and whether it is off-box")
    mode.add_argument("--restore-check", metavar="OBJECT",
                      help="download OBJECT, decrypt and validate it as a complete binary log")
    mode.add_argument("--print-latest", action="store_true", help="print the binlogs/LATEST.json heartbeat")
    args = parser.parse_args(argv)

    if os.name == "posix":
        os.umask(0o077)

    try:
        cfg = load_config(args.env_file)
    except BackupError as exc:
        sys.stderr.write(f"FAIL {exc}\n")
        return 2

    try:
        if args.list:
            return run_list(cfg)
        if args.restore_check:
            return run_restore_check(cfg, args.restore_check)
        if args.print_latest:
            return run_print_latest(cfg)
        return run_ship(cfg, dry_run=args.dry_run, flush=not args.no_flush)
    except BackupError as exc:
        sys.stderr.write(f"FAIL {exc}\n")
        return 1
    except Exception as exc:  # anything unforeseen still exits non-zero with ONE clear line (and a log entry)
        line = log_line(cfg, f"FAIL unexpected {type(exc).__name__}: "
                             f"{db_backup.scrub(str(exc), [cfg.get('S3_SECRET')])}")
        sys.stderr.write(line + "\n")
        return 1


if __name__ == "__main__":
    sys.exit(main())
