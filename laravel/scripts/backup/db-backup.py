#!/usr/bin/env python3
"""
db-backup.py — nightly encrypted, off-box MySQL backup for the DMC patient-flow hub (finding DATA-02).

Runs as ROOT from host cron on the database host — NOT inside a container. Dependencies are
deliberately minimal so it survives any host rebuild: python3 stdlib + `openssl` + `docker`.

Pipeline (one streaming pass — no plaintext ever touches the disk):

    docker exec <mysql container> mysqldump --single-transaction --routines --triggers --databases <db>
      │  (root password is read INSIDE the container from its MYSQL_ROOT_PASSWORD env var — it never
      │   appears in a host process's argv/env)
      ▼
    gzip (in-process, stdlib)
      ▼
    openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt -pass file:<KEYFILE>
      ▼
    <LOCAL_DIR>/<db>-<UTC stamp>.sql.gz.enc            (dir 700, file 600, last LOCAL_KEEP_DAYS kept)
      ▼
    SigV4 PUT  →  s3://<S3_BUCKET>/<S3_PREFIX>/YYYY/MM/<db>-YYYY-MM-DDTHHMMSSZ.sql.gz.enc
      ▼         (Content-MD5 sent AND the returned ETag compared with the local MD5 — single-part upload)
    SigV4 PUT  →  s3://<S3_BUCKET>/<S3_PREFIX>/LATEST.json    (heartbeat; `php artisan backup:verify` reads it)

The SigV4 signing is a line-for-line port of laravel/app/Support/S3SigV4.php (`signature()`), proven
against the same published AWS test vectors in test_db_backup.py. Path-style URLs, three signed
headers (host, x-amz-content-sha256, x-amz-date) plus content-md5 on uploads.

Modes
    (default)                     full run: dump → encrypt → local copy → upload → heartbeat → prune
    --dry-run                     dump → encrypt → local copy only (no upload, no heartbeat, no prune)
    --restore-check OBJECT        download OBJECT, decrypt + gunzip IN A PIPE (decrypted bytes never
                                  hit the disk), confirm it is a complete mysqldump (CREATE TABLE …
                                  "Dump completed") without loading it anywhere
    --print-latest                print the LATEST.json heartbeat (used by db-restore-drill.sh)
    --download OBJECT DEST        SigV4 GET OBJECT → DEST as-is, still encrypted (used by the drill)

Config: /root/.dmc-backup.env (KEY=value, root-only, mode 600) — override with --env-file. Keys:
    S3_ENDPOINT      e.g. https://<namespace>.compat.objectstorage.me-riyadh-1.oraclecloud.com
    S3_REGION        e.g. me-riyadh-1
    S3_BUCKET        default dmc-db-backups
    S3_ACCESS_KEY / S3_SECRET
    MYSQL_CONTAINER  docker container name/id of the MySQL 8 server
    DB_NAME          default dmc_demo
    KEYFILE          default /root/.dmc-backup.key   (openssl rand -base64 48 > file; chmod 600)
    LOCAL_KEEP_DAYS  default 2
    LOCAL_DIR        default /var/backups/dmc
    LOG_FILE         default /var/log/dmc-backup.log
    S3_PREFIX        default db-backups/<DB_NAME>

Exit codes: 0 success · 1 failure (one clear line on stderr) · 2 configuration error.
Never prints secrets: stderr from child processes is truncated and scrubbed before it is logged.

This is the BASE backup (RPO 24 h). The INCREMENT that closes the gap between two nightly runs is
the sibling binlog-ship.py, which archives MySQL's binary logs hourly using this file's SigV4
client, config loader and encryption commands — keep the two side by side. See
laravel/docs/BACKUP-AND-RESTORE.md (§10 for point-in-time recovery).
"""

import argparse
import base64
import datetime as _dt
import gzip
import hashlib
import hmac
import http.client
import json
import os
import re
import shlex
import socket
import ssl
import subprocess
import sys
import tempfile
import threading
import time
import urllib.parse

CHUNK = 1024 * 1024
EMPTY_SHA256 = hashlib.sha256(b"").hexdigest()
OPENSSL_CIPHER_ARGS = ["-aes-256-cbc", "-pbkdf2", "-iter", "200000"]
CIPHER_LABEL = "aes-256-cbc/pbkdf2-200000"

DEFAULTS = {
    "S3_BUCKET": "dmc-db-backups",
    "DB_NAME": "dmc_demo",
    "KEYFILE": "/root/.dmc-backup.key",
    "LOCAL_KEEP_DAYS": "2",
    "LOCAL_DIR": "/var/backups/dmc",
    "LOG_FILE": "/var/log/dmc-backup.log",
}
REQUIRED = ("S3_ENDPOINT", "S3_REGION", "S3_BUCKET", "S3_ACCESS_KEY", "S3_SECRET", "MYSQL_CONTAINER", "DB_NAME", "KEYFILE")


class BackupError(Exception):
    """A failure with a message that is safe to print and log (no secrets)."""


# --------------------------------------------------------------------------------------------------
# SigV4 — port of App\Support\S3SigV4::signature() (pure; unit-tested against the AWS vectors)
# --------------------------------------------------------------------------------------------------

def sha256_hex(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def _hmac(key: bytes, msg: str) -> bytes:
    return hmac.new(key, msg.encode("utf-8"), hashlib.sha256).digest()


def sigv4_signature(method, canonical_uri, canonical_query, headers, secret, region, service,
                    date_stamp, amz_date, payload_hash=None):
    """canonical request -> string to sign -> derived signing key -> HMAC-SHA256 (lowercase hex).

    `headers` = {lowercase name: raw value} containing EVERY header that must be signed; canonical
    headers and the signed-headers list are both derived from it, sorted by name, exactly as the
    PHP original does (ksort + strtolower + trim).
    """
    items = sorted((name.lower(), str(value).strip()) for name, value in headers.items())
    canonical_headers = "".join(f"{name}:{value}\n" for name, value in items)
    signed_header_names = ";".join(name for name, _ in items)

    if payload_hash is None:
        payload_hash = dict(items).get("x-amz-content-sha256", EMPTY_SHA256)

    canonical_request = "\n".join([
        method, canonical_uri, canonical_query, canonical_headers, signed_header_names, payload_hash,
    ])

    credential_scope = f"{date_stamp}/{region}/{service}/aws4_request"
    string_to_sign = "\n".join([
        "AWS4-HMAC-SHA256", amz_date, credential_scope, sha256_hex(canonical_request.encode("utf-8")),
    ])

    k_date = _hmac(("AWS4" + secret).encode("utf-8"), date_stamp)
    k_region = _hmac(k_date, region)
    k_service = _hmac(k_region, service)
    k_signing = _hmac(k_service, "aws4_request")

    return hmac.new(k_signing, string_to_sign.encode("utf-8"), hashlib.sha256).hexdigest()


def rawurlencode(segment: str) -> str:
    """PHP rawurlencode(): RFC 3986 — everything except A-Za-z0-9-_.~ is percent-encoded."""
    return urllib.parse.quote(segment, safe="-_.~")


def canonical_uri(bucket: str, key: str) -> str:
    """Path-style: /{bucket}/{key}, each path segment rawurlencoded — identical to the PHP class."""
    return "/" + rawurlencode(bucket) + "/" + "/".join(rawurlencode(s) for s in key.lstrip("/").split("/"))


class S3Client:
    """Minimal SigV4 S3 client over http.client: PUT (file or bytes), GET (to file or bytes)."""

    def __init__(self, endpoint, region, bucket, access_key, secret, timeout=120):
        parsed = urllib.parse.urlsplit(endpoint)
        if parsed.scheme not in ("http", "https") or not parsed.hostname:
            raise BackupError("S3_ENDPOINT must be an http(s) URL with a host")
        self.scheme = parsed.scheme
        self.host = parsed.hostname
        self.port = parsed.port or (443 if parsed.scheme == "https" else 80)
        default_port = (self.scheme == "https" and self.port == 443) or (self.scheme == "http" and self.port == 80)
        self.host_header = self.host if default_port else f"{self.host}:{self.port}"
        self.region = region
        self.bucket = bucket
        self.access_key = access_key
        self.secret = secret
        self.timeout = timeout

    def _connection(self):
        if self.scheme == "https":
            return http.client.HTTPSConnection(self.host, self.port, timeout=self.timeout,
                                               context=ssl.create_default_context())
        return http.client.HTTPConnection(self.host, self.port, timeout=self.timeout)

    def _signed(self, method, key, payload_hash, extra_signed=None):
        """(path, headers) for one request. Signs host + x-amz-content-sha256 + x-amz-date and any
        extra headers passed (content-md5 on uploads), exactly like the PHP signedRequestHeaders()."""
        path = canonical_uri(self.bucket, key)
        amz_date = _dt.datetime.now(_dt.timezone.utc).strftime("%Y%m%dT%H%M%SZ")
        date_stamp = amz_date[:8]

        signed = {"host": self.host_header, "x-amz-content-sha256": payload_hash, "x-amz-date": amz_date}
        signed.update({k.lower(): v for k, v in (extra_signed or {}).items()})

        signature = sigv4_signature(method, path, "", signed, self.secret, self.region, "s3",
                                    date_stamp, amz_date, payload_hash)
        authorization = (
            "AWS4-HMAC-SHA256 Credential=%s/%s/%s/s3/aws4_request, SignedHeaders=%s, Signature=%s"
            % (self.access_key, date_stamp, self.region, ";".join(sorted(signed)), signature)
        )
        headers = {
            "Host": self.host_header,
            "x-amz-content-sha256": payload_hash,
            "x-amz-date": amz_date,
            "Authorization": authorization,
        }
        headers.update(extra_signed or {})
        return path, headers

    def _request(self, method, key, payload_hash, body=None, extra_signed=None, extra_headers=None, sink=None):
        """One attempt. Returns (status, response headers, body bytes or None when sink is given)."""
        path, headers = self._signed(method, key, payload_hash, extra_signed)
        headers.update(extra_headers or {})
        conn = self._connection()
        try:
            conn.request(method, path, body=body, headers=headers)
            resp = conn.getresponse()
            if sink is not None and 200 <= resp.status < 300:
                while True:
                    chunk = resp.read(CHUNK)
                    if not chunk:
                        break
                    sink.write(chunk)
                return resp.status, resp.getheaders(), None
            return resp.status, resp.getheaders(), resp.read(64 * 1024)
        finally:
            conn.close()

    def _with_retries(self, what, attempt):
        """Retry transient failures (connection errors, 5xx) up to 3 times with backoff; each attempt
        re-signs with a fresh x-amz-date. 4xx is never retried — it is a caller/config error."""
        last = None
        for i in range(3):
            try:
                status, headers, body = attempt()
            except (OSError, http.client.HTTPException) as exc:  # socket/TLS/protocol errors
                last = f"{type(exc).__name__}: {exc}"
            else:
                if status < 500:
                    return status, headers, body
                last = f"HTTP {status} {_short(body)}"
            if i < 2:
                time.sleep(2 ** i * 3)
        raise BackupError(f"{what}: giving up after 3 attempts ({last})")

    def put_file(self, key, path, size, sha256_hexdigest, md5_digest, content_type="application/octet-stream"):
        md5_b64 = base64.b64encode(md5_digest).decode("ascii")

        def attempt():
            with open(path, "rb") as fh:
                return self._request(
                    "PUT", key, sha256_hexdigest, body=fh,
                    extra_signed={"content-md5": md5_b64},
                    extra_headers={"Content-Type": content_type, "Content-Length": str(size)},
                )

        return self._with_retries(f"PUT {key}", attempt)

    def put_bytes(self, key, data: bytes, content_type="application/json"):
        md5_b64 = base64.b64encode(hashlib.md5(data).digest()).decode("ascii")

        def attempt():
            return self._request(
                "PUT", key, sha256_hex(data), body=data,
                extra_signed={"content-md5": md5_b64},
                extra_headers={"Content-Type": content_type, "Content-Length": str(len(data))},
            )

        return self._with_retries(f"PUT {key}", attempt)

    def get_bytes(self, key):
        return self._with_retries(f"GET {key}", lambda: self._request("GET", key, EMPTY_SHA256))

    def get_to_file(self, key, dest_path):
        def attempt():
            with open(dest_path, "wb") as fh:
                status, headers, body = self._request("GET", key, EMPTY_SHA256, sink=fh)
            return status, headers, body

        return self._with_retries(f"GET {key}", attempt)


# --------------------------------------------------------------------------------------------------
# Pure helpers (unit-tested)
# --------------------------------------------------------------------------------------------------

def parse_env_file(text: str) -> dict:
    """KEY=value lines; '#' comments and blanks ignored; optional single/double quotes stripped;
    a leading `export ` tolerated. Values are NOT shell-expanded."""
    out = {}
    for raw in text.splitlines():
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        if line.startswith("export "):
            line = line[7:].strip()
        if "=" not in line:
            raise BackupError(f"config: cannot parse line {raw!r}")
        key, value = line.split("=", 1)
        key = key.strip()
        value = value.strip()
        if len(value) >= 2 and value[0] == value[-1] and value[0] in "\"'":
            value = value[1:-1]
        if not re.fullmatch(r"[A-Z][A-Z0-9_]*", key):
            raise BackupError(f"config: bad key {key!r}")
        out[key] = value
    return out


def build_config(values: dict) -> dict:
    cfg = dict(DEFAULTS)
    cfg.update({k: v for k, v in values.items() if v != ""})
    cfg.setdefault("S3_PREFIX", f"db-backups/{cfg['DB_NAME']}")
    cfg["S3_PREFIX"] = cfg["S3_PREFIX"].strip("/")
    missing = [k for k in REQUIRED if not cfg.get(k)]
    if missing:
        raise BackupError("config: missing " + ", ".join(missing))
    if not re.fullmatch(r"[A-Za-z0-9_]+", cfg["DB_NAME"]):
        raise BackupError("config: DB_NAME must be [A-Za-z0-9_]+")
    try:
        cfg["LOCAL_KEEP_DAYS"] = int(cfg["LOCAL_KEEP_DAYS"])
    except ValueError:
        raise BackupError("config: LOCAL_KEEP_DAYS must be an integer") from None
    if cfg["LOCAL_KEEP_DAYS"] < 0:
        raise BackupError("config: LOCAL_KEEP_DAYS must be >= 0")
    return cfg


def backup_file_name(db_name: str, when: _dt.datetime) -> str:
    """<db>-YYYY-MM-DDTHHMMSSZ.sql.gz.enc (UTC)."""
    return f"{db_name}-{when.strftime('%Y-%m-%dT%H%M%SZ')}.sql.gz.enc"


def object_key(prefix: str, db_name: str, when: _dt.datetime) -> str:
    """<prefix>/YYYY/MM/<file name> — month folders keep listings and lifecycle rules simple."""
    return f"{prefix}/{when.strftime('%Y')}/{when.strftime('%m')}/{backup_file_name(db_name, when)}"


def heartbeat_key(prefix: str) -> str:
    return f"{prefix}/LATEST.json"


def heartbeat_document(key, size, sha256_hexdigest, md5_hexdigest, when, db_name, plaintext_bytes, host) -> dict:
    return {
        "object": key,
        "bytes": size,
        "sha256_of_ciphertext": sha256_hexdigest,
        "md5_of_ciphertext": md5_hexdigest,
        "created_at": when.strftime("%Y-%m-%dT%H:%M:%SZ"),
        "db": db_name,
        "plaintext_bytes": plaintext_bytes,
        "cipher": CIPHER_LABEL,
        "host": host,
        "producer": "scripts/backup/db-backup.py",
    }


def etag_matches_md5(etag, md5_hexdigest):
    """True/False when the ETag is an MD5 (single-part upload); None when it is not MD5-shaped
    (some S3-compatible stores return opaque ETags) so the caller can warn instead of fail."""
    if etag is None:
        return None
    value = etag.strip().strip('"').lower()
    if not re.fullmatch(r"[0-9a-f]{32}", value):
        return None
    return value == md5_hexdigest.lower()


def files_to_prune(entries, db_name, keep_days, now_ts):
    """entries = [(name, mtime)] from LOCAL_DIR; returns the names older than keep_days that match
    our own naming pattern (never anything else that happens to live in the directory)."""
    pattern = re.compile(rf"^{re.escape(db_name)}-\d{{4}}-\d{{2}}-\d{{2}}T\d{{6}}Z\.sql\.gz\.enc$")
    cutoff = now_ts - keep_days * 86400
    return sorted(name for name, mtime in entries if pattern.match(name) and mtime < cutoff)


def scrub(text, secrets):
    """Replace every secret value that might have leaked into child stderr with a placeholder."""
    for value in secrets:
        if value:
            text = text.replace(value, "[redacted]")
    return text


def _short(data, limit=300):
    if data is None:
        return ""
    if isinstance(data, bytes):
        data = data.decode("utf-8", "replace")
    data = " ".join(data.split())
    return data[:limit]


# --------------------------------------------------------------------------------------------------
# Host-side plumbing
# --------------------------------------------------------------------------------------------------

def load_config(path: str) -> dict:
    try:
        st = os.stat(path)
    except FileNotFoundError:
        raise BackupError(f"config: {path} not found") from None
    if os.name == "posix" and (st.st_mode & 0o077):
        raise BackupError(f"config: {path} must not be group/world readable (chmod 600)")
    with open(path, "r", encoding="utf-8") as fh:
        cfg = build_config(parse_env_file(fh.read()))
    key = cfg["KEYFILE"]
    try:
        kst = os.stat(key)
    except FileNotFoundError:
        raise BackupError(f"config: KEYFILE {key} not found (openssl rand -base64 48 > {key}; chmod 600 {key})") from None
    if os.name == "posix" and (kst.st_mode & 0o077):
        raise BackupError(f"config: KEYFILE {key} must be mode 600")
    if kst.st_size < 32:
        raise BackupError(f"config: KEYFILE {key} is too short to be a real key")
    return cfg


def ensure_local_dir(path: str):
    os.makedirs(path, mode=0o700, exist_ok=True)
    if os.name == "posix":
        os.chmod(path, 0o700)


def acquire_lock(path: str):
    """Non-blocking exclusive lock so an over-running backup never overlaps the next one."""
    try:
        import fcntl
    except ImportError:  # non-POSIX (local development only)
        return None
    fh = open(path, "w")
    try:
        fcntl.flock(fh, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except OSError:
        raise BackupError("another db-backup.py run is still in progress (lock held)") from None
    return fh


def log_line(cfg, line):
    stamp = _dt.datetime.now(_dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
    entry = f"{stamp} {line}\n"
    try:
        with open(cfg.get("LOG_FILE", DEFAULTS["LOG_FILE"]), "a", encoding="utf-8") as fh:
            fh.write(entry)
    except OSError as exc:
        sys.stderr.write(f"warning: could not write log file: {exc}\n")
    return entry.rstrip("\n")


def _drain(stream, sink):
    """Read a child's stderr on a thread so a chatty child can never deadlock the pipeline."""
    def run():
        sink.append(stream.read())
    t = threading.Thread(target=run, daemon=True)
    t.start()
    return t


def openssl_encrypt_cmd(keyfile, out_path):
    return ["openssl", "enc", *OPENSSL_CIPHER_ARGS, "-salt", "-pass", f"file:{keyfile}", "-out", out_path]


def openssl_decrypt_cmd(keyfile, in_path):
    return ["openssl", "enc", "-d", *OPENSSL_CIPHER_ARGS, "-pass", f"file:{keyfile}", "-in", in_path]


def mysqldump_cmd(container, db_name):
    # The password is expanded by `sh` INSIDE the container from its own environment; nothing
    # secret is in this argv. --set-gtid-purged=OFF keeps the dump restorable into a scratch
    # database on the same server (the restore drill) even if GTIDs are ever switched on.
    inner = (
        'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump --single-transaction --routines --triggers '
        "--set-gtid-purged=OFF --databases " + shlex.quote(db_name)
    )
    return ["docker", "exec", container, "sh", "-c", inner]


def dump_and_encrypt(cfg, out_path):
    """mysqldump | gzip | openssl → out_path. Returns (plaintext_bytes, table_count).
    Fails unless mysqldump exited 0, at least one CREATE TABLE was seen, and the stream ended with
    mysqldump's own '-- Dump completed' trailer (a truncated dump must never be uploaded)."""
    dump = subprocess.Popen(mysqldump_cmd(cfg["MYSQL_CONTAINER"], cfg["DB_NAME"]),
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    enc = subprocess.Popen(openssl_encrypt_cmd(cfg["KEYFILE"], out_path),
                           stdin=subprocess.PIPE, stderr=subprocess.PIPE)
    dump_err, enc_err = [], []
    t1, t2 = _drain(dump.stderr, dump_err), _drain(enc.stderr, enc_err)

    plaintext = 0
    tables = 0
    tail = b""
    stream_error = None
    try:
        gz = gzip.GzipFile(filename="", mode="wb", fileobj=enc.stdin, mtime=0)
        try:
            while True:
                chunk = dump.stdout.read(CHUNK)
                if not chunk:
                    break
                gz.write(chunk)
                plaintext += len(chunk)
                tables += chunk.count(b"CREATE TABLE ")
                tail = (tail + chunk)[-512:]
        finally:
            gz.close()
    except OSError as exc:  # e.g. BrokenPipe when openssl died early — reported below, after rc/stderr
        stream_error = exc
    finally:
        try:
            enc.stdin.close()
        except OSError:
            pass
        dump_rc = dump.wait()
        enc_rc = enc.wait()
        t1.join(timeout=5)
        t2.join(timeout=5)
        for stream in (dump.stdout, dump.stderr, enc.stderr):
            stream.close()

    secrets = [cfg.get("S3_SECRET")]
    if dump_rc != 0:
        raise BackupError(f"mysqldump exited {dump_rc}: {scrub(_short(b''.join(dump_err)), secrets)}")
    if enc_rc != 0:
        raise BackupError(f"openssl enc exited {enc_rc}: {scrub(_short(b''.join(enc_err)), secrets)}")
    if stream_error is not None:
        raise BackupError(f"pipeline I/O error: {type(stream_error).__name__}: {stream_error}")
    if plaintext < 1024 or tables == 0:
        raise BackupError(f"dump looks empty ({plaintext} bytes, {tables} CREATE TABLE statements)")
    if b"Dump completed" not in tail:
        raise BackupError("dump is truncated: mysqldump's '-- Dump completed' trailer is missing")
    warnings = scrub(_short(b"".join(dump_err)), secrets)
    if warnings:
        sys.stderr.write(f"mysqldump stderr: {warnings}\n")
    return plaintext, tables


def hash_file(path):
    sha, md5, size = hashlib.sha256(), hashlib.md5(), 0
    with open(path, "rb") as fh:
        while True:
            chunk = fh.read(CHUNK)
            if not chunk:
                break
            sha.update(chunk)
            md5.update(chunk)
            size += len(chunk)
    return size, sha.hexdigest(), md5.digest()


def prune_local(cfg, dry_run):
    entries = []
    for name in os.listdir(cfg["LOCAL_DIR"]):
        full = os.path.join(cfg["LOCAL_DIR"], name)
        if os.path.isfile(full):
            entries.append((name, os.path.getmtime(full)))
    doomed = files_to_prune(entries, cfg["DB_NAME"], cfg["LOCAL_KEEP_DAYS"], time.time())
    for name in doomed:
        if dry_run:
            print(f"dry-run: would delete {name}")
        else:
            os.unlink(os.path.join(cfg["LOCAL_DIR"], name))
    # leftover partials from a crashed run
    for name in os.listdir(cfg["LOCAL_DIR"]):
        full = os.path.join(cfg["LOCAL_DIR"], name)
        if name.endswith(".tmp") and os.path.isfile(full) and os.path.getmtime(full) < time.time() - 86400 and not dry_run:
            os.unlink(full)
    return doomed


def make_client(cfg):
    return S3Client(cfg["S3_ENDPOINT"], cfg["S3_REGION"], cfg["S3_BUCKET"], cfg["S3_ACCESS_KEY"], cfg["S3_SECRET"])


# --------------------------------------------------------------------------------------------------
# Modes
# --------------------------------------------------------------------------------------------------

def run_backup(cfg, dry_run):
    started = time.monotonic()
    now = _dt.datetime.now(_dt.timezone.utc)
    ensure_local_dir(cfg["LOCAL_DIR"])
    lock = acquire_lock(os.path.join(cfg["LOCAL_DIR"], ".lock"))

    name = backup_file_name(cfg["DB_NAME"], now)
    key = object_key(cfg["S3_PREFIX"], cfg["DB_NAME"], now)
    final_path = os.path.join(cfg["LOCAL_DIR"], name)
    tmp_path = final_path + ".tmp"

    step = "dump"
    try:
        plaintext, tables = dump_and_encrypt(cfg, tmp_path)
        if os.name == "posix":
            os.chmod(tmp_path, 0o600)
        os.replace(tmp_path, final_path)

        step = "hash"
        size, sha256_hexdigest, md5_digest = hash_file(final_path)
        md5_hexdigest = md5_digest.hex()

        if dry_run:
            prune_local(cfg, dry_run=True)
            line = (f"DRY-RUN ok local={final_path} bytes={size} sha256={sha256_hexdigest} "
                    f"plaintext_bytes={plaintext} tables={tables} would_upload={key} "
                    f"duration_s={time.monotonic() - started:.1f}")
            print(log_line(cfg, line))
            return 0

        step = "upload"
        client = make_client(cfg)
        status, headers, body = client.put_file(key, final_path, size, sha256_hexdigest, md5_digest)
        if not 200 <= status < 300:
            raise BackupError(f"upload rejected: HTTP {status} {_short(body)}")
        etag = dict((k.lower(), v) for k, v in headers).get("etag")
        verdict = etag_matches_md5(etag, md5_hexdigest)
        if verdict is False:
            raise BackupError(f"upload integrity check failed: ETag {etag} != local MD5 {md5_hexdigest}")
        if verdict is None:
            sys.stderr.write("note: ETag is not MD5-shaped; relying on the signed Content-MD5 the server accepted\n")

        step = "heartbeat"
        doc = heartbeat_document(key, size, sha256_hexdigest, md5_hexdigest, now, cfg["DB_NAME"], plaintext,
                                 socket.gethostname())
        status, _, body = client.put_bytes(heartbeat_key(cfg["S3_PREFIX"]), json.dumps(doc, indent=2).encode("utf-8"))
        if not 200 <= status < 300:
            raise BackupError(f"heartbeat upload rejected: HTTP {status} {_short(body)}")

        step = "prune"
        pruned = prune_local(cfg, dry_run=False)

        line = (f"OK object={key} bytes={size} sha256={sha256_hexdigest} plaintext_bytes={plaintext} "
                f"tables={tables} local={final_path} pruned={len(pruned)} duration_s={time.monotonic() - started:.1f}")
        print(log_line(cfg, line))
        return 0
    except BackupError as exc:
        for p in (tmp_path,):
            if os.path.exists(p):
                os.unlink(p)
        line = log_line(cfg, f"FAIL step={step} error={exc}")
        sys.stderr.write(line + "\n")
        return 1
    finally:
        if lock is not None:
            lock.close()


def run_restore_check(cfg, key):
    """Download → openssl -d → gunzip, all in a pipe; the only bytes on disk are still encrypted."""
    started = time.monotonic()
    ensure_local_dir(cfg["LOCAL_DIR"])
    client = make_client(cfg)
    workdir = tempfile.mkdtemp(prefix="restore-check-", dir=cfg["LOCAL_DIR"])
    cipher_path = os.path.join(workdir, "object.enc")
    try:
        status, _, body = client.get_to_file(key, cipher_path)
        if status != 200:
            raise BackupError(f"download failed: HTTP {status} {_short(body)}")
        size, sha256_hexdigest, _ = hash_file(cipher_path)

        # If this is the object the heartbeat advertises, the ciphertext digest must match it.
        hb_status, _, hb_body = client.get_bytes(heartbeat_key(cfg["S3_PREFIX"]))
        if hb_status == 200:
            try:
                hb = json.loads(hb_body.decode("utf-8"))
            except ValueError:
                hb = {}
            if hb.get("object") == key and hb.get("sha256_of_ciphertext") not in (None, sha256_hexdigest):
                raise BackupError("ciphertext sha256 does not match the heartbeat's sha256_of_ciphertext")

        dec = subprocess.Popen(openssl_decrypt_cmd(cfg["KEYFILE"], cipher_path),
                               stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        dec_err = []
        t = _drain(dec.stderr, dec_err)
        plaintext, tables, tail = 0, 0, b""
        stream_error = None
        try:
            gz = gzip.GzipFile(fileobj=dec.stdout, mode="rb")
            while True:
                chunk = gz.read(CHUNK)
                if not chunk:
                    break
                plaintext += len(chunk)
                tables += chunk.count(b"CREATE TABLE ")
                tail = (tail + chunk)[-512:]
        except (OSError, EOFError) as exc:  # BadGzipFile is an OSError subclass
            stream_error = exc
        finally:
            dec.stdout.close()
            rc = dec.wait()
            t.join(timeout=5)
            dec.stderr.close()
        # openssl's own verdict first: a wrong key shows up as "bad decrypt" + non-zero exit, and the
        # garbage it emits before noticing is what makes gzip complain — report the cause, not the symptom.
        if rc != 0:
            raise BackupError(f"openssl decrypt exited {rc} (wrong key?): {scrub(_short(b''.join(dec_err)), [cfg['S3_SECRET']])}")
        if stream_error is not None:
            raise BackupError(f"decrypted stream is not a valid gzip: {stream_error}")
        if tables == 0:
            raise BackupError("no CREATE TABLE statement found — not a mysqldump")
        if b"Dump completed" not in tail:
            raise BackupError("dump trailer '-- Dump completed' missing — truncated backup")

        line = (f"RESTORE-CHECK ok object={key} bytes={size} sha256={sha256_hexdigest} "
                f"plaintext_bytes={plaintext} tables={tables} duration_s={time.monotonic() - started:.1f}")
        print(log_line(cfg, line))
        return 0
    except BackupError as exc:
        line = log_line(cfg, f"RESTORE-CHECK FAIL object={key} error={exc}")
        sys.stderr.write(line + "\n")
        return 1
    finally:
        for name in os.listdir(workdir):
            os.unlink(os.path.join(workdir, name))
        os.rmdir(workdir)


def run_print_latest(cfg):
    status, _, body = make_client(cfg).get_bytes(heartbeat_key(cfg["S3_PREFIX"]))
    if status == 404:
        sys.stderr.write("no heartbeat (LATEST.json) in the bucket — no backup has completed yet\n")
        return 1
    if status != 200:
        sys.stderr.write(f"heartbeat fetch failed: HTTP {status} {_short(body)}\n")
        return 1
    sys.stdout.write(body.decode("utf-8"))
    if not body.endswith(b"\n"):
        sys.stdout.write("\n")
    return 0


def run_download(cfg, key, dest):
    status, _, body = make_client(cfg).get_to_file(key, dest)
    if status != 200:
        if os.path.exists(dest):
            os.unlink(dest)
        sys.stderr.write(f"download failed: HTTP {status} {_short(body)}\n")
        return 1
    if os.name == "posix":
        os.chmod(dest, 0o600)
    size, sha256_hexdigest, _ = hash_file(dest)
    print(f"downloaded {key} -> {dest} bytes={size} sha256={sha256_hexdigest}")
    return 0


def main(argv=None):
    parser = argparse.ArgumentParser(description="DMC encrypted off-box MySQL backup (DATA-02)")
    parser.add_argument("--env-file", default="/root/.dmc-backup.env")
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument("--dry-run", action="store_true", help="dump + encrypt + local copy, no upload")
    mode.add_argument("--restore-check", metavar="OBJECT", help="download, decrypt and validate OBJECT")
    mode.add_argument("--print-latest", action="store_true", help="print the LATEST.json heartbeat")
    mode.add_argument("--download", nargs=2, metavar=("OBJECT", "DEST"), help="download OBJECT (still encrypted) to DEST")
    args = parser.parse_args(argv)

    if os.name == "posix":
        os.umask(0o077)

    try:
        cfg = load_config(args.env_file)
    except BackupError as exc:
        sys.stderr.write(f"FAIL {exc}\n")
        return 2

    try:
        if args.restore_check:
            return run_restore_check(cfg, args.restore_check)
        if args.print_latest:
            return run_print_latest(cfg)
        if args.download:
            return run_download(cfg, args.download[0], args.download[1])
        return run_backup(cfg, dry_run=args.dry_run)
    except BackupError as exc:
        sys.stderr.write(f"FAIL {exc}\n")
        return 1
    except Exception as exc:  # anything unforeseen still exits non-zero with ONE clear line (and a log entry)
        line = log_line(cfg, f"FAIL unexpected {type(exc).__name__}: {scrub(str(exc), [cfg.get('S3_SECRET')])}")
        sys.stderr.write(line + "\n")
        return 1


if __name__ == "__main__":
    sys.exit(main())
