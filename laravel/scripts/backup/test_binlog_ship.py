#!/usr/bin/env python3
"""
Unit tests for binlog-ship.py — run anywhere with `python3 -m unittest` from this directory (no
docker, no MySQL, no network needed):

    cd laravel/scripts/backup && python3 -m unittest -v test_db_backup test_binlog_ship

Same shape as test_db_backup.py: the pure logic is tested directly, and the streaming pipeline is
tested end-to-end with a fake `docker`/`mysql` (python children driven by a small control file), a
fake container filesystem holding REAL, structurally valid binary logs, the in-memory FakeS3 bucket
the nightly backup tests use (subclassed here to answer HEAD), and — when `openssl` is on PATH —
REAL openssl. The clock is frozen so every object key is deterministic.

The load-bearing assertions:
  * `EncryptionCompatibility` — an object this script uploads is decrypted with the LITERAL command
    line db-restore-drill.sh runs, proving the archive format did not fork.
  * `FailureIsolation` — one unshippable file does not stop the rest (the failure mode that would
    silently stall the archive until MySQL expired everything behind it).
  * `Deadlocks` — a dying encrypt side tears the reader down instead of blocking forever.
"""

import ast
import datetime as dt
import gzip
import hashlib
import importlib.util
import json
import os
import shutil
import struct
import subprocess
import sys
import tempfile
import time
import unittest

from test_db_backup import FakeS3  # the same fake bucket the nightly-backup tests use

HERE = os.path.dirname(os.path.abspath(__file__))
_spec = importlib.util.spec_from_file_location("binlog_ship", os.path.join(HERE, "binlog-ship.py"))
binlog_ship = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(binlog_ship)

db_backup = binlog_ship.db_backup
MAGIC = binlog_ship.BINLOG_MAGIC
HEADER = binlog_ship.EVENT_HEADER

# The exact DECRYPT=(...) array in db-restore-drill.sh, and the exact line documented in
# BACKUP-AND-RESTORE.md §3. If binlog-ship.py ever writes something this cannot open, the archive
# has forked from the nightly backups and the runbook is wrong.
DRILL_DECRYPT = ["openssl", "enc", "-d", "-aes-256-cbc", "-pbkdf2", "-iter", "200000"]

FROZEN = dt.datetime(2026, 9, 3, 14, 40, 7, tzinfo=dt.timezone.utc)
BASE_TS = 1788000000            # event timestamps in the synthetic logs
SERVER_UUID = "3f2a1b4c-5d6e-4f80-91a2-b3c4d5e6f708"


def make_binlog(events: int, magic: bytes = MAGIC, truncate: int = 0, start_ts: int = BASE_TS) -> bytes:
    """A structurally valid MySQL binary log: magic + a chain of v4 events, one per minute.

    `scan_binlog_stream` (and mysqlbinlog) walk this chain, so the fixtures have to be real — a blob
    of random bytes would pass the shipper's magic-byte check and then fail --restore-check, which is
    exactly the difference the `truncate` argument is here to exercise.
    """
    out = bytearray(magic)
    pos = len(magic)
    for i in range(events):
        payload = bytes((i + j) % 251 for j in range(24))
        length = HEADER + len(payload)
        pos += length
        out += struct.pack("<IBIIIH", start_ts + i * 60, 2, 1, length, pos, 0)
        out += payload
    return bytes(out[:len(out) - truncate]) if truncate else bytes(out)


def base_config(**overrides):
    values = {
        "S3_ENDPOINT": "https://fake.test", "S3_REGION": "r", "S3_ACCESS_KEY": "a", "S3_SECRET": "s3cr3t",
        "MYSQL_CONTAINER": "u8ha9zwdgekz9djnjt1ndisf", "DB_NAME": "dmc_demo", "KEYFILE": "/root/.dmc-backup.key",
    }
    values.update(overrides)
    return binlog_ship.build_binlog_config(db_backup.build_config(values))


class Config(unittest.TestCase):
    def test_defaults_are_derived_from_the_nightly_backup_config(self):
        cfg = base_config()
        self.assertEqual(cfg["BINLOG_PREFIX"], "db-backups/dmc_demo/binlogs")
        self.assertEqual(cfg["BINLOG_DIR"], "/var/lib/mysql")
        self.assertEqual(cfg["BINLOG_STATE_FILE"], os.path.join("/var/backups/dmc", "binlog-shipped.json"))
        self.assertEqual(cfg["BINLOG_LOG_FILE"], "/var/log/dmc-binlog-ship.log")
        self.assertEqual(cfg["BINLOG_STATE_KEEP"], 2000)
        self.assertEqual(cfg["BINLOG_DOCKER_USER"], "")
        self.assertEqual(cfg["S3_PREFIX"], "db-backups/dmc_demo")
        self.assertEqual(cfg["S3_BUCKET"], "dmc-db-backups")

    def test_every_binlog_key_can_be_overridden_from_the_same_env_file(self):
        cfg = base_config(BINLOG_DIR="/srv/mysql", BINLOG_PREFIX="/archive/binlogs/",
                          BINLOG_STATE_FILE="/tmp/s.json", BINLOG_STATE_KEEP="7",
                          BINLOG_DOCKER_USER="root", BINLOG_LOG_FILE="/tmp/l.log")
        self.assertEqual(cfg["BINLOG_DIR"], "/srv/mysql")
        self.assertEqual(cfg["BINLOG_PREFIX"], "archive/binlogs")
        self.assertEqual(cfg["BINLOG_STATE_FILE"], "/tmp/s.json")
        self.assertEqual(cfg["BINLOG_STATE_KEEP"], 7)
        self.assertEqual(cfg["BINLOG_DOCKER_USER"], "root")

    def test_rejects_nonsense(self):
        for bad in ({"BINLOG_STATE_KEEP": "many"}, {"BINLOG_STATE_KEEP": "0"},
                    {"BINLOG_DIR": "var/lib/mysql"}, {"BINLOG_DIR": "/var/lib/mysql/"},
                    {"BINLOG_DIR": "/var/lib/mysql; rm -rf /"}, {"BINLOG_DOCKER_USER": "root; id"}):
            with self.assertRaises(db_backup.BackupError, msg=bad):
                base_config(**bad)


class ShowBinaryLogsParsing(unittest.TestCase):
    def test_parses_the_batch_output_including_the_mysql8_encrypted_column(self):
        text = "binlog.000002\t348127232\tNo\nbinlog.000003\t16777216\tNo\n\n"
        self.assertEqual(binlog_ship.parse_show_binary_logs(text),
                         [("binlog.000002", 348127232), ("binlog.000003", 16777216)])

    def test_parses_a_two_column_server_too(self):
        self.assertEqual(binlog_ship.parse_show_binary_logs("mysql-bin.000001\t120\n"),
                         [("mysql-bin.000001", 120)])

    def test_empty_output_is_a_failure_not_an_empty_list(self):
        with self.assertRaises(db_backup.BackupError) as ctx:
            binlog_ship.parse_show_binary_logs("\n \n")
        self.assertIn("log_bin", str(ctx.exception))

    def test_refuses_a_hostile_or_unparseable_name(self):
        for text in ("../../etc/passwd\t10\n", "binlog.000002\n", "binlog.000002\tbig\n",
                     "bin log.000002\t10\n", "binlog.00002\t10\n"):
            with self.assertRaises(db_backup.BackupError, msg=text):
                binlog_ship.parse_show_binary_logs(text)

    def test_refuses_a_duplicated_name_rather_than_guessing_the_size(self):
        with self.assertRaises(db_backup.BackupError) as ctx:
            binlog_ship.parse_show_binary_logs("binlog.000002\t10\nbinlog.000002\t20\n")
        self.assertIn("twice", str(ctx.exception))

    def test_refuses_two_basenames_because_active_detection_would_be_meaningless(self):
        with self.assertRaises(db_backup.BackupError) as ctx:
            binlog_ship.parse_show_binary_logs("binlog.000009\t10\nmysql-bin.000001\t20\n")
        self.assertIn("binlog", str(ctx.exception))
        self.assertIn("mysql-bin", str(ctx.exception))
        self.assertIn("basenames", str(ctx.exception))


class Selection(unittest.TestCase):
    ENTRIES = [("binlog.000001", 100), ("binlog.000002", 200), ("binlog.000003", 300)]

    def test_the_active_file_is_never_shipped(self):
        to_ship, active, already, resized = binlog_ship.select_files_to_ship(self.ENTRIES, {})
        self.assertEqual(active, "binlog.000003")
        self.assertEqual(to_ship, [("binlog.000001", 100), ("binlog.000002", 200)])
        self.assertEqual((already, resized), ([], []))

    def test_a_file_already_shipped_at_the_same_size_is_skipped(self):
        state = {"binlog.000001": {"plaintext_bytes": 100}}
        to_ship, _, already, resized = binlog_ship.select_files_to_ship(self.ENTRIES, state)
        self.assertEqual(to_ship, [("binlog.000002", 200)])
        self.assertEqual(already, ["binlog.000001"])
        self.assertEqual(resized, [])

    def test_a_recorded_file_whose_size_changed_is_reshipped_and_reported(self):
        state = {"binlog.000001": {"plaintext_bytes": 99}}
        to_ship, _, already, resized = binlog_ship.select_files_to_ship(self.ENTRIES, state)
        self.assertIn(("binlog.000001", 100), to_ship)
        self.assertEqual(resized, ["binlog.000001"])
        self.assertEqual(already, [])

    def test_a_record_with_no_usable_size_is_reshipped_rather_than_trusted(self):
        state = {"binlog.000001": {"plaintext_bytes": None}}
        to_ship, _, _, resized = binlog_ship.select_files_to_ship(self.ENTRIES, state)
        self.assertIn(("binlog.000001", 100), to_ship)
        self.assertEqual(resized, ["binlog.000001"])

    def test_ordering_is_numeric_so_it_survives_the_lexical_break_at_999999(self):
        entries = [("binlog.1000000", 1), ("binlog.999999", 2), ("binlog.999998", 3)]
        to_ship, active, _, _ = binlog_ship.select_files_to_ship(entries, {})
        self.assertEqual(active, "binlog.1000000")
        self.assertEqual([n for n, _ in to_ship], ["binlog.999998", "binlog.999999"])

    def test_a_server_with_only_an_active_log_ships_nothing(self):
        to_ship, active, _, _ = binlog_ship.select_files_to_ship([("binlog.000001", 4)], {})
        self.assertEqual((to_ship, active), ([], "binlog.000001"))


class ExpiredGapDetection(unittest.TestCase):
    """MySQL deletes its own old logs. If the shipper was down across that window the logs are gone
    for good and a replay cannot cross the hole — the run must say so instead of looking healthy."""

    def test_a_contiguous_chain_is_not_a_gap(self):
        entries = [("binlog.000003", 1), ("binlog.000004", 1)]
        self.assertIsNone(binlog_ship.detect_expired_gap(entries, {"binlog.000002": {}, "binlog.000003": {}}))

    def test_the_oldest_present_log_may_be_exactly_one_past_the_newest_shipped(self):
        entries = [("binlog.000004", 1), ("binlog.000005", 1)]
        self.assertIsNone(binlog_ship.detect_expired_gap(entries, {"binlog.000003": {}}))

    def test_logs_that_expired_before_they_were_shipped_are_named(self):
        entries = [("binlog.000007", 1), ("binlog.000008", 1)]
        self.assertEqual(binlog_ship.detect_expired_gap(entries, {"binlog.000003": {}}),
                         ("binlog.000004", "binlog.000006"))

    def test_no_baseline_means_no_claim(self):
        self.assertIsNone(binlog_ship.detect_expired_gap([("binlog.000009", 1)], {}))

    def test_a_hole_is_remembered_so_it_stays_visible_after_the_boundary_moves(self):
        state = {}
        self.assertTrue(binlog_ship.record_gap(state, ("binlog.000004", "binlog.000006")))
        self.assertEqual(state["gaps"], [["binlog.000004", "binlog.000006"]])
        self.assertFalse(binlog_ship.record_gap(state, ("binlog.000004", "binlog.000006")),
                         "the same hole must not re-fail the run every hour and drown the next one")
        self.assertTrue(binlog_ship.record_gap(state, ("binlog.000009", "binlog.000010")))
        self.assertEqual(len(state["gaps"]), 2)


class Naming(unittest.TestCase):
    def test_object_keys_sit_under_the_nightly_prefix_and_carry_a_ship_stamp(self):
        key = binlog_ship.binlog_object_key("db-backups/dmc_demo/binlogs", "binlog.000002", FROZEN)
        self.assertEqual(key, "db-backups/dmc_demo/binlogs/2026/09/binlog.000002-2026-09-03T144007Z.gz.enc")
        self.assertEqual(binlog_ship.binlog_heartbeat_key("db-backups/dmc_demo/binlogs"),
                         "db-backups/dmc_demo/binlogs/LATEST.json")

    def test_the_stamp_keeps_a_numbering_reset_from_overwriting_the_previous_generation(self):
        later = FROZEN + dt.timedelta(days=400)
        first = binlog_ship.binlog_object_key("p", "binlog.000001", FROZEN)
        second = binlog_ship.binlog_object_key("p", "binlog.000001", later)
        self.assertNotEqual(first, second, "a rebuilt server restarts at .000001 — the keys must not collide")

    def test_object_key_refuses_a_name_that_could_escape_the_prefix(self):
        with self.assertRaises(db_backup.BackupError):
            binlog_ship.binlog_object_key("p", "../LATEST.json", FROZEN)

    def test_mysql_command_keeps_the_password_inside_the_container(self):
        cmd = binlog_ship.mysql_cmd("u8ha9zwdgekz9djnjt1ndisf", "SHOW BINARY LOGS")
        self.assertEqual(cmd[:5], ["docker", "exec", "u8ha9zwdgekz9djnjt1ndisf", "sh", "-c"])
        self.assertIn('MYSQL_PWD="$MYSQL_ROOT_PASSWORD"', cmd[5])
        self.assertIn("--batch --skip-column-names", cmd[5])
        self.assertTrue(cmd[5].endswith("'SHOW BINARY LOGS'"))

    def test_docker_user_override_is_applied_to_both_container_commands(self):
        self.assertEqual(binlog_ship.mysql_cmd("c", "FLUSH BINARY LOGS", "root")[:5],
                         ["docker", "exec", "-u", "root", "c"])
        self.assertEqual(binlog_ship.read_binlog_cmd("c", "/var/lib/mysql", "binlog.000002", "root"),
                         ["docker", "exec", "-u", "root", "c", "cat", "/var/lib/mysql/binlog.000002"])

    def test_read_command_is_a_plain_cat_with_no_shell_and_validates_the_name(self):
        self.assertEqual(binlog_ship.read_binlog_cmd("c", "/var/lib/mysql", "binlog.000002"),
                         ["docker", "exec", "c", "cat", "/var/lib/mysql/binlog.000002"])
        with self.assertRaises(db_backup.BackupError):
            binlog_ship.read_binlog_cmd("c", "/var/lib/mysql", "../../etc/shadow")


class ServerIdentity(unittest.TestCase):
    def test_a_changed_server_uuid_stops_the_run_with_an_explanation(self):
        with self.assertRaises(db_backup.BackupError) as ctx:
            binlog_ship.check_server_identity({"server_uuid": "aaa"}, "bbb")
        message = str(ctx.exception)
        self.assertIn("numbering restarts", message)
        self.assertIn("move the state file aside", message)

    def test_an_unknown_or_matching_uuid_is_fine(self):
        binlog_ship.check_server_identity({}, SERVER_UUID)
        binlog_ship.check_server_identity({"server_uuid": SERVER_UUID}, SERVER_UUID)
        binlog_ship.check_server_identity({"server_uuid": SERVER_UUID}, None)


class EventChainScanner(unittest.TestCase):
    """--restore-check walks the event chain instead of shelling out to mysqlbinlog: it needs no
    MySQL, works on a stream (no plaintext on disk) and fails on a truncated archive."""

    def scan(self, blob):
        import io
        return binlog_ship.scan_binlog_stream(io.BytesIO(blob))

    def test_a_valid_log_reports_its_events_hash_and_time_window(self):
        blob = make_binlog(5)
        result = self.scan(blob)
        self.assertEqual(result["events"], 5)
        self.assertEqual(result["bytes"], len(blob))
        self.assertEqual(result["sha256"], hashlib.sha256(blob).hexdigest())
        self.assertEqual(result["first_ts"], BASE_TS)
        self.assertEqual(result["last_ts"], BASE_TS + 4 * 60)
        self.assertEqual(binlog_ship.iso(BASE_TS), "2026-08-29T10:40:00Z")
        self.assertEqual(binlog_ship.iso(None), "-")

    def test_a_truncated_log_is_rejected(self):
        with self.assertRaises(db_backup.BackupError) as ctx:
            self.scan(make_binlog(5, truncate=10))
        self.assertIn("truncated", str(ctx.exception))

    def test_wrong_magic_and_empty_logs_are_rejected(self):
        with self.assertRaises(db_backup.BackupError):
            self.scan(make_binlog(3, magic=b"NOPE"))
        with self.assertRaises(db_backup.BackupError) as ctx:
            self.scan(MAGIC)
        self.assertIn("no events", str(ctx.exception))

    def test_an_impossible_event_length_is_rejected(self):
        blob = bytearray(make_binlog(2))
        blob[4 + 9:4 + 13] = struct.pack("<I", 3)   # event_length smaller than the header itself
        with self.assertRaises(db_backup.BackupError) as ctx:
            self.scan(bytes(blob))
        self.assertIn("impossible length", str(ctx.exception))


class StateFile(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.mkdtemp(prefix="dmc-binlog-state-")
        self.path = os.path.join(self.tmp, "binlog-shipped.json")

    def tearDown(self):
        shutil.rmtree(self.tmp, ignore_errors=True)

    def test_a_missing_state_file_is_an_empty_ledger_not_an_error(self):
        state = binlog_ship.load_state(self.path, "dmc_demo")
        self.assertEqual(state["files"], {})
        self.assertEqual(state["db"], "dmc_demo")

    def test_round_trip(self):
        state = binlog_ship.empty_state("dmc_demo")
        state["files"]["binlog.000002"] = binlog_ship.binlog_record(
            "binlog.000002", "p/2026/09/binlog.000002-x.gz.enc", 348, "ab" * 32, 120, "cd" * 32, "ef" * 16, FROZEN)
        binlog_ship.save_state(self.path, state, FROZEN)
        again = binlog_ship.load_state(self.path, "dmc_demo")
        self.assertEqual(again["files"], state["files"])
        self.assertEqual(again["updated_at"], "2026-09-03T14:40:07Z")
        self.assertEqual(again["files"]["binlog.000002"]["sha256_of_plaintext"], "ab" * 32)

    def test_a_corrupt_state_file_fails_loudly_with_the_way_out(self):
        with open(self.path, "w", encoding="utf-8") as fh:
            fh.write("{not json")
        with self.assertRaises(db_backup.BackupError) as ctx:
            binlog_ship.load_state(self.path, "dmc_demo")
        self.assertIn("move it aside", str(ctx.exception))

        with open(self.path, "w", encoding="utf-8") as fh:
            json.dump({"files": {"../evil": {}}}, fh)
        with self.assertRaises(db_backup.BackupError):
            binlog_ship.load_state(self.path, "dmc_demo")

    def test_prune_never_drops_a_file_the_server_still_has(self):
        files = {f"binlog.{i:06d}": {"plaintext_bytes": i} for i in range(1, 11)}
        kept, dropped = binlog_ship.prune_state(files, ["binlog.000009", "binlog.000010"], keep=3)
        self.assertEqual(dropped, ["binlog.000001", "binlog.000002", "binlog.000003",
                                   "binlog.000004", "binlog.000005"])
        self.assertEqual(sorted(kept), ["binlog.000006", "binlog.000007", "binlog.000008",
                                        "binlog.000009", "binlog.000010"])

    def test_prune_is_a_no_op_while_the_ledger_is_small(self):
        files = {"binlog.000001": {}, "binlog.000002": {}}
        kept, dropped = binlog_ship.prune_state(files, [], keep=2000)
        self.assertEqual((kept, dropped), (files, []))


class Heartbeat(unittest.TestCase):
    def test_field_names_match_the_nightly_heartbeat_so_backup_verify_can_read_it(self):
        record = binlog_ship.binlog_record("binlog.000002", "k", 348, "ab" * 32, 120, "cd" * 32, "ef" * 16, FROZEN)
        doc = binlog_ship.binlog_heartbeat_document(record, FROZEN, "dmc_demo", 2, "binlog.000004", 3, "host1",
                                                    server_uuid=SERVER_UUID)
        nightly = db_backup.heartbeat_document("k", 120, "cd" * 32, "ef" * 16, FROZEN, "dmc_demo", 348, "host1")
        for field in ("object", "bytes", "sha256_of_ciphertext", "created_at", "db", "cipher", "host", "producer"):
            self.assertIn(field, doc, field)
            self.assertIn(field, nightly, field)
        self.assertEqual(doc["object"], "k")
        self.assertEqual(doc["created_at"], "2026-09-03T14:40:07Z")
        self.assertEqual(doc["binlog"], "binlog.000002")
        self.assertEqual(doc["active_binlog"], "binlog.000004")
        self.assertEqual(doc["shipped_this_run"], 2)
        self.assertEqual(doc["failed_this_run"], 0)
        self.assertEqual(doc["failed_binlogs"], [])
        self.assertIsNone(doc["expired_unshipped"])
        self.assertEqual(doc["server_uuid"], SERVER_UUID)
        self.assertEqual(doc["cipher"], nightly["cipher"])
        self.assertEqual(doc["producer"], "scripts/backup/binlog-ship.py")

    def test_failures_and_gaps_are_visible_to_a_monitor_even_though_created_at_is_fresh(self):
        doc = binlog_ship.binlog_heartbeat_document(None, FROZEN, "dmc_demo", 1, "binlog.000009", 4, "h",
                                                    failed=["binlog.000007"],
                                                    gap=("binlog.000004", "binlog.000006"))
        self.assertEqual(doc["failed_this_run"], 1)
        self.assertEqual(doc["failed_binlogs"], ["binlog.000007"])
        self.assertEqual(doc["expired_unshipped"], ["binlog.000004", "binlog.000006"])

    def test_object_is_null_only_before_anything_has_ever_been_shipped(self):
        doc = binlog_ship.binlog_heartbeat_document(None, FROZEN, "dmc_demo", 0, "binlog.000001", 1, "h")
        self.assertIsNone(doc["object"])
        self.assertEqual(doc["created_at"], "2026-09-03T14:40:07Z")

    def test_last_shipped_picks_the_newest_by_sequence_not_by_insertion_order(self):
        files = {"binlog.000003": {"binlog": "binlog.000003"}, "binlog.000001": {"binlog": "binlog.000001"}}
        self.assertEqual(binlog_ship.last_shipped(files)["binlog"], "binlog.000003")
        self.assertIsNone(binlog_ship.last_shipped({}))


class NeverDeletes(unittest.TestCase):
    def test_the_only_sql_this_script_can_execute_is_read_only_or_a_rotation(self):
        """Parsed, not grepped: every run_mysql() argument must be a literal from this exact set, so
        no PURGE/RESET/DROP can be executed and no statement can be built at runtime. MySQL expires
        binary logs itself; deleting one here could destroy the only copy of an unshipped window."""
        with open(os.path.join(HERE, "binlog-ship.py"), encoding="utf-8") as fh:
            tree = ast.parse(fh.read())
        statements = set()
        for node in ast.walk(tree):
            if isinstance(node, ast.Call) and getattr(node.func, "id", None) == "run_mysql":
                argument = node.args[1]
                self.assertIsInstance(argument, ast.Constant,
                                      "SQL must be a literal, never composed at runtime")
                statements.add(argument.value)
        self.assertEqual(statements, {"FLUSH BINARY LOGS", "SHOW BINARY LOGS", "SELECT @@server_uuid"})


class HeadableFakeS3(FakeS3):
    """FakeS3 + HEAD, so the overwrite guard can be exercised."""

    def head(self, key):
        if key not in self.objects:
            return 404, []
        return 200, [("ETag", self._etag(self.objects[key]))]


class ShippingHarness:
    """The real streaming pipeline: fake `docker exec mysql` and `docker exec cat` (python children),
    a fake container datadir of REAL binary logs, an in-memory bucket, REAL gzip + REAL openssl, and
    a frozen clock so every object key is deterministic.

    A plain mixin, not a TestCase, so the suites below share it without re-running each other."""

    def setUp(self):
        self.tmp = tempfile.mkdtemp(prefix="dmc-binlog-test-")
        self.keyfile = os.path.join(self.tmp, "key")
        with open(self.keyfile, "wb") as fh:
            fh.write(b"dGhpcy1pcy1hLXRlc3Qta2V5LW9ubHktZm9yLXVuaXQtdGVzdHMtMTIzNDU2Nzg5MA==\n")
        self.local = os.path.join(self.tmp, "local")
        self.datadir = os.path.join(self.tmp, "containerfs")
        os.makedirs(self.datadir)
        self.control = os.path.join(self.tmp, "control.json")
        with open(self.control, "w", encoding="utf-8") as fh:
            json.dump({"logs": [], "dir": self.datadir, "server_uuid": SERVER_UUID}, fh)

        self.cfg = base_config(KEYFILE=self.keyfile, LOCAL_DIR=self.local,
                               BINLOG_LOG_FILE=os.path.join(self.tmp, "binlog-ship.log"),
                               BINLOG_DIR="/var/lib/mysql")
        self.fake = HeadableFakeS3()
        self.now = FROZEN
        self._orig = (binlog_ship.make_client, binlog_ship.mysql_cmd, binlog_ship.read_binlog_cmd,
                      binlog_ship.utcnow, db_backup.openssl_encrypt_cmd)
        binlog_ship.make_client = lambda cfg: self.fake
        binlog_ship.utcnow = lambda: self.now
        binlog_ship.mysql_cmd = lambda container, sql, user=None: [sys.executable, self._fake_mysql(), self.control, sql]
        binlog_ship.read_binlog_cmd = lambda container, d, name, user=None: [
            sys.executable, self._fake_cat(), os.path.join(self.datadir, name)]

        self.add_binlog("binlog.000001", 24)
        self.add_binlog("binlog.000002", 48)
        self.add_binlog("binlog.000003", 12)     # the active one until the first FLUSH

    def tearDown(self):
        (binlog_ship.make_client, binlog_ship.mysql_cmd, binlog_ship.read_binlog_cmd,
         binlog_ship.utcnow, db_backup.openssl_encrypt_cmd) = self._orig
        shutil.rmtree(self.tmp, ignore_errors=True)

    # -- fakes -------------------------------------------------------------------------------------

    def _fake_mysql(self):
        """`docker exec … mysql -e <sql>`: FLUSH BINARY LOGS rotates (closing the active file and
        opening the next one, on disk and in the listing); SHOW BINARY LOGS prints the batch table;
        SELECT @@server_uuid answers from the control file."""
        path = os.path.join(self.tmp, "fake_mysql.py")
        if not os.path.exists(path):
            with open(path, "w", encoding="utf-8") as fh:
                fh.write(
                    "import json, os, sys\n"
                    "control, sql = sys.argv[1], sys.argv[2]\n"
                    "state = json.load(open(control))\n"
                    "up = sql.upper()\n"
                    "if 'SERVER_UUID' in up:\n"
                    "    sys.stdout.write((state.get('server_uuid') or '') + '\\n')\n"
                    "    sys.exit(0)\n"
                    "if 'FLUSH BINARY LOGS' in up:\n"
                    "    base, seq = state['logs'][-1][0].rsplit('.', 1)\n"
                    "    nxt = '%s.%06d' % (base, int(seq) + 1)\n"
                    "    open(os.path.join(state['dir'], nxt), 'wb').write(b'\\xfe\\x62\\x69\\x6e')\n"
                    "    state['logs'].append([nxt, 4])\n"
                    "    json.dump(state, open(control, 'w'))\n"
                    "    sys.exit(0)\n"
                    "if 'SHOW BINARY LOGS' in up:\n"
                    "    for name, size in state['logs']:\n"
                    "        sys.stdout.write('%s\\t%d\\tNo\\n' % (name, size))\n"
                    "    sys.exit(0)\n"
                    "sys.stderr.write('unexpected sql: %s\\n' % sql)\n"
                    "sys.exit(1)\n"
                )
        return path

    def _fake_cat(self):
        path = os.path.join(self.tmp, "fake_cat.py")
        if not os.path.exists(path):
            with open(path, "w", encoding="utf-8") as fh:
                fh.write("import sys\nsys.stdout.buffer.write(open(sys.argv[1], 'rb').read())\n")
        return path

    # -- helpers -----------------------------------------------------------------------------------

    def add_binlog(self, name, events, magic=MAGIC, truncate=0):
        """Create (or replace) one REAL binary log in the fake datadir and in the listing."""
        body = make_binlog(events, magic=magic, truncate=truncate)
        with open(os.path.join(self.datadir, name), "wb") as fh:
            fh.write(body)
        self._set_log(name, len(body))
        return body

    def _set_log(self, name, size):
        with open(self.control, encoding="utf-8") as fh:
            state = json.load(fh)
        state["logs"] = [row for row in state["logs"] if row[0] != name] + [[name, size]]
        state["logs"].sort(key=lambda row: binlog_ship.binlog_sequence(row[0]))
        with open(self.control, "w", encoding="utf-8") as fh:
            json.dump(state, fh)

    def set_control(self, **fields):
        with open(self.control, encoding="utf-8") as fh:
            state = json.load(fh)
        state.update(fields)
        with open(self.control, "w", encoding="utf-8") as fh:
            json.dump(state, fh)

    def key_for(self, name, when=None):
        return binlog_ship.binlog_object_key("db-backups/dmc_demo/binlogs", name, when or self.now)

    def plaintext_of(self, name):
        with open(os.path.join(self.datadir, name), "rb") as fh:
            return fh.read()

    def log(self):
        with open(self.cfg["BINLOG_LOG_FILE"], encoding="utf-8") as fh:
            return fh.read()

    def state(self):
        return binlog_ship.load_state(self.cfg["BINLOG_STATE_FILE"], "dmc_demo")

    def objects(self):
        return sorted(k for k in self.fake.objects if k.endswith(".gz.enc"))

    def shipped_names(self):
        return sorted(k.rsplit("/", 1)[1].split("-")[0] for k in self.objects())

    def heartbeat(self):
        return json.loads(self.fake.objects["db-backups/dmc_demo/binlogs/LATEST.json"])

    def drill_decrypt(self, ciphertext: bytes) -> bytes:
        """Decrypt with the LITERAL command db-restore-drill.sh uses, then gunzip."""
        path = os.path.join(self.tmp, "one.enc")
        with open(path, "wb") as fh:
            fh.write(ciphertext)
        cmd = DRILL_DECRYPT + ["-pass", f"file:{self.keyfile}", "-in", path]
        return gzip.decompress(subprocess.run(cmd, capture_output=True, check=True).stdout)


@unittest.skipUnless(shutil.which("openssl"), "openssl not on PATH")
class EndToEndShipping(ShippingHarness, unittest.TestCase):
    def test_first_run_flushes_ships_every_closed_log_and_writes_the_heartbeat(self):
        self.assertEqual(binlog_ship.run_ship(self.cfg), 0)

        self.assertEqual(self.objects(), sorted(self.key_for(n) for n in
                                                ("binlog.000001", "binlog.000002", "binlog.000003")))
        # binlog.000004 was opened by the FLUSH and is the active file — never shipped
        self.assertTrue(os.path.exists(os.path.join(self.datadir, "binlog.000004")))
        self.assertNotIn("binlog.000004", self.shipped_names())

        state = self.state()
        self.assertEqual(sorted(state["files"]), ["binlog.000001", "binlog.000002", "binlog.000003"])
        self.assertEqual(state["server_uuid"], SERVER_UUID)
        self.assertEqual(state["files"]["binlog.000002"]["plaintext_bytes"],
                         len(self.plaintext_of("binlog.000002")))
        self.assertEqual(state["files"]["binlog.000002"]["sha256_of_plaintext"],
                         hashlib.sha256(self.plaintext_of("binlog.000002")).hexdigest())

        hb = self.heartbeat()
        self.assertEqual(hb["binlog"], "binlog.000003")
        self.assertEqual(hb["active_binlog"], "binlog.000004")
        self.assertEqual((hb["shipped_this_run"], hb["failed_this_run"]), (3, 0))
        self.assertEqual(hb["known_binlogs"], 4)
        self.assertEqual(hb["server_uuid"], SERVER_UUID)
        self.assertEqual(hb["bytes"], len(self.fake.objects[hb["object"]]))
        self.assertEqual(hb["sha256_of_ciphertext"], hashlib.sha256(self.fake.objects[hb["object"]]).hexdigest())
        self.assertEqual(hb["created_at"], "2026-09-03T14:40:07Z")

        log = self.log()
        self.assertIn(" OK shipped=3 ", log)
        self.assertIn("active=binlog.000004", log)
        self.assertIn("failed=0", log)
        self.assertNotIn("s3cr3t", log)
        self.assertEqual([p for p in os.listdir(self.local) if p.startswith("binlog-ship-")], [],
                         "the work directory must be removed")

    def test_hourly_reruns_are_idempotent_and_pick_up_only_the_newly_closed_log(self):
        self.assertEqual(binlog_ship.run_ship(self.cfg), 0)
        first = dict(self.fake.objects)

        self.now = FROZEN + dt.timedelta(hours=1)
        self.assertEqual(binlog_ship.run_ship(self.cfg), 0)      # the next hour
        new = [k for k in self.objects() if k not in first]
        self.assertEqual(new, [self.key_for("binlog.000004")])
        for key, body in first.items():
            if key.endswith(".gz.enc"):
                self.assertEqual(self.fake.objects[key], body, "an already-shipped object must not be rewritten")
        self.assertIn(" OK shipped=1 ", self.log())
        self.assertIn("already=3", self.log())
        self.assertEqual(sorted(self.state()["files"]),
                         ["binlog.000001", "binlog.000002", "binlog.000003", "binlog.000004"])

    def test_a_catch_up_run_without_a_flush_ships_nothing_but_still_refreshes_the_heartbeat(self):
        self.assertEqual(binlog_ship.run_ship(self.cfg), 0)
        before = self.heartbeat()
        self.now = FROZEN + dt.timedelta(hours=1)
        self.assertEqual(binlog_ship.run_ship(self.cfg, flush=False), 0)
        after = self.heartbeat()
        self.assertEqual(len(self.objects()), 3, "no FLUSH, nothing new closed → nothing shipped")
        self.assertEqual(after["shipped_this_run"], 0)
        self.assertEqual(after["object"], before["object"], "the heartbeat still names the last object shipped")
        self.assertEqual(after["last_shipped_at"], before["last_shipped_at"])
        self.assertNotEqual(after["created_at"], before["created_at"], "created_at proves the shipper ran")
        self.assertIn(" OK shipped=0 files=- ", self.log())

    def test_a_run_before_anything_has_ever_been_flushed_still_writes_a_usable_heartbeat(self):
        cfg = base_config(KEYFILE=self.keyfile, LOCAL_DIR=os.path.join(self.tmp, "l2"),
                          BINLOG_LOG_FILE=os.path.join(self.tmp, "l2.log"),
                          BINLOG_STATE_FILE=os.path.join(self.tmp, "s2.json"))
        self.set_control(logs=[["binlog.000001", 4]])
        self.assertEqual(binlog_ship.run_ship(cfg, flush=False), 0)
        hb = self.heartbeat()
        self.assertIsNone(hb["object"])
        self.assertEqual(hb["shipped_this_run"], 0)
        self.assertEqual(hb["active_binlog"], "binlog.000001")

    def test_dry_run_reads_nothing_out_of_the_container_and_writes_nothing_anywhere(self):
        self.assertEqual(binlog_ship.run_ship(self.cfg, dry_run=True), 0)
        self.assertEqual(self.fake.objects, {})
        self.assertFalse(os.path.exists(self.cfg["BINLOG_STATE_FILE"]))
        self.assertFalse(os.path.exists(os.path.join(self.datadir, "binlog.000004")), "dry-run must not FLUSH")
        log = self.log()
        self.assertIn("DRY-RUN ok active=binlog.000003", log)
        self.assertIn("would_ship=2 files=binlog.000001,binlog.000002", log)
        self.assertIn("gap=none", log)

    def test_list_mode_shows_what_is_off_box_without_changing_anything(self):
        self.assertEqual(binlog_ship.run_ship(self.cfg), 0)
        objects_before = dict(self.fake.objects)
        self.assertEqual(binlog_ship.run_list(self.cfg), 0)
        self.assertEqual(self.fake.objects, objects_before)

    def test_print_latest_prints_the_heartbeat_and_fails_cleanly_when_there_is_none(self):
        self.assertEqual(binlog_ship.run_print_latest(self.cfg), 1)
        self.assertEqual(binlog_ship.run_ship(self.cfg), 0)
        self.assertEqual(binlog_ship.run_print_latest(self.cfg), 0)

    def test_the_command_line_wires_argparse_config_loading_and_dispatch_together(self):
        env_file = os.path.join(self.tmp, "dmc-backup.env")
        with open(env_file, "w", encoding="utf-8") as fh:
            fh.write(
                "S3_ENDPOINT=https://fake.test\nS3_REGION=r\nS3_ACCESS_KEY=a\nS3_SECRET=s3cr3t\n"
                f"MYSQL_CONTAINER=c\nDB_NAME=dmc_demo\nKEYFILE={self.keyfile}\n"
                f"LOCAL_DIR={self.local}\nBINLOG_LOG_FILE={self.cfg['BINLOG_LOG_FILE']}\n"
            )
        self.assertEqual(binlog_ship.main(["--env-file", env_file, "--dry-run"]), 0)
        self.assertEqual(self.fake.objects, {})
        self.assertIn("DRY-RUN ok active=binlog.000003", self.log())

        self.assertEqual(binlog_ship.main(["--env-file", env_file, "--no-flush"]), 0)
        self.assertEqual(self.shipped_names(), ["binlog.000001", "binlog.000002"])

        self.assertEqual(binlog_ship.main(["--env-file", os.path.join(self.tmp, "nope.env")]), 2,
                         "a missing config file is exit 2, not 1")

    def test_binary_logs_are_never_deleted_from_the_server(self):
        before = sorted(os.listdir(self.datadir))
        self.assertEqual(binlog_ship.run_ship(self.cfg), 0)
        self.assertEqual(sorted(os.listdir(self.datadir)), sorted(before + ["binlog.000004"]),
                         "shipping must only ADD the rotated file; MySQL expires binlogs itself")

    def test_a_file_that_is_not_a_binary_log_is_refused(self):
        self.add_binlog("binlog.000001", 24, magic=b"NOPE")     # a CLOSED file with the wrong header
        self.assertEqual(binlog_ship.run_ship(self.cfg, flush=False), 1)
        self.assertIn("magic bytes", self.log())
        self.assertNotIn("binlog.000001", self.shipped_names())

    def test_a_size_that_disagrees_with_show_binary_logs_is_refused(self):
        self._set_log("binlog.000001", 999)
        self.assertEqual(binlog_ship.run_ship(self.cfg, flush=False), 1)
        self.assertIn("but SHOW BINARY LOGS reported 999", self.log())

    def test_an_etag_mismatch_fails_the_file_without_recording_it(self):
        self.fake.etag_override = '"00000000000000000000000000000000"'
        self.assertEqual(binlog_ship.run_ship(self.cfg), 1)
        self.assertEqual(self.state()["files"], {})
        self.assertIn("upload integrity check failed", self.log())

    def test_it_refuses_to_overwrite_an_existing_object_with_different_content(self):
        self.fake.objects[self.key_for("binlog.000001")] = b"something else entirely"
        self.assertEqual(binlog_ship.run_ship(self.cfg, flush=False), 1)
        self.assertIn("refusing to overwrite", self.log())
        self.assertNotIn("binlog.000001", self.state()["files"])
        self.assertEqual(self.fake.objects[self.key_for("binlog.000001")], b"something else entirely")
        # ...and the OTHER file still went off-box
        self.assertIn("binlog.000002", self.state()["files"])

    def test_child_stderr_is_scrubbed_of_the_bucket_secret(self):
        leak = os.path.join(self.tmp, "leaky.py")
        with open(leak, "w", encoding="utf-8") as fh:
            fh.write("import sys\nsys.stderr.write('auth failed for %s\\n' % sys.argv[1])\nsys.exit(3)\n")
        binlog_ship.read_binlog_cmd = lambda container, d, name, user=None: [sys.executable, leak, "s3cr3t"]
        self.assertEqual(binlog_ship.run_ship(self.cfg, flush=False), 1)
        log = self.log()
        self.assertIn("[redacted]", log)
        self.assertNotIn("s3cr3t", log)

    def test_a_changed_server_uuid_stops_the_run_before_anything_is_shipped(self):
        self.assertEqual(binlog_ship.run_ship(self.cfg), 0)
        shipped = dict(self.fake.objects)
        self.set_control(server_uuid="00000000-1111-4222-8333-444444444444")
        self.now = FROZEN + dt.timedelta(hours=1)
        self.assertEqual(binlog_ship.run_ship(self.cfg), 1)
        self.assertIn("FAIL step=identity", self.log())
        self.assertIn("numbering restarts", self.log())
        self.assertEqual(self.fake.objects, shipped, "nothing may be written after an identity change")

    def test_an_unreadable_server_uuid_does_not_stop_the_archive(self):
        self.set_control(server_uuid=None)
        self.assertEqual(binlog_ship.run_ship(self.cfg), 0)
        self.assertIsNone(self.heartbeat()["server_uuid"])

    def test_abandoned_work_directories_are_swept_at_the_start_of_every_run(self):
        os.makedirs(self.local, exist_ok=True)
        stale = os.path.join(self.local, "binlog-ship-crashed")
        os.makedirs(stale)
        with open(os.path.join(stale, "leftover.gz.enc"), "wb") as fh:
            fh.write(b"x")
        os.utime(stale, (0, 0))
        self.assertEqual(binlog_ship.run_ship(self.cfg, dry_run=True), 0, "even a dry run sweeps")
        self.assertFalse(os.path.exists(stale))


@unittest.skipUnless(shutil.which("openssl"), "openssl not on PATH")
class FailureIsolation(ShippingHarness, unittest.TestCase):
    """One permanently unshippable file used to block every later file until MySQL expired it."""

    def test_a_failing_file_does_not_stop_the_ones_after_it(self):
        os.unlink(os.path.join(self.datadir, "binlog.000002"))
        self.assertEqual(binlog_ship.run_ship(self.cfg, flush=False), 1)

        self.assertEqual(self.shipped_names(), ["binlog.000001"], "the file before the failure")
        self.assertEqual(sorted(self.state()["files"]), ["binlog.000001"])
        log = self.log()
        self.assertIn("FAIL step=ship", log)
        self.assertIn("could not ship binlog.000002", log)
        self.assertIn("shipped=1", log)
        self.assertIn("failed=1", log)

    def test_the_heartbeat_still_lands_and_reports_the_failure(self):
        os.unlink(os.path.join(self.datadir, "binlog.000002"))
        self.assertEqual(binlog_ship.run_ship(self.cfg, flush=False), 1)
        hb = self.heartbeat()
        self.assertEqual(hb["failed_this_run"], 1)
        self.assertEqual(hb["failed_binlogs"], ["binlog.000002"])
        self.assertEqual(hb["shipped_this_run"], 1)

    def test_the_next_run_resumes_the_file_that_failed(self):
        os.unlink(os.path.join(self.datadir, "binlog.000002"))
        self.assertEqual(binlog_ship.run_ship(self.cfg, flush=False), 1)
        self.add_binlog("binlog.000002", 48)               # the file comes back (a bad mount, say)
        self.now = FROZEN + dt.timedelta(hours=1)
        self.assertEqual(binlog_ship.run_ship(self.cfg, flush=False), 0)
        self.assertEqual(self.shipped_names(), ["binlog.000001", "binlog.000002"])

    def test_logs_that_expired_before_they_were_shipped_fail_the_run_and_name_the_hole(self):
        self.assertEqual(binlog_ship.run_ship(self.cfg, flush=False), 0)   # ships 000001 + 000002
        self.add_binlog("binlog.000007", 10)
        self.add_binlog("binlog.000008", 6)
        self.set_control(logs=[["binlog.000007", len(self.plaintext_of("binlog.000007"))],
                               ["binlog.000008", len(self.plaintext_of("binlog.000008"))]])

        self.now = FROZEN + dt.timedelta(hours=1)
        self.assertEqual(binlog_ship.run_ship(self.cfg, flush=False), 1)

        log = self.log()
        self.assertIn("binary logs binlog.000003..binlog.000006 expired before they were shipped", log)
        self.assertIn("a replay cannot cross it", log)
        # ...and it still archived what it could
        self.assertIn("binlog.000007", self.shipped_names())
        self.assertEqual(self.heartbeat()["expired_unshipped"], ["binlog.000003", "binlog.000006"])
        self.assertEqual(binlog_ship.run_list(self.cfg), 1, "--list must also report the hole")

        # The next hour the boundary has moved and the chain LOOKS contiguous again, but the hole is
        # a permanent fact: the run stops failing (no new hole) while --list and the heartbeat keep
        # reporting it, so a recovery is never planned across a window that was never archived.
        self.now = FROZEN + dt.timedelta(hours=2)
        self.assertEqual(binlog_ship.run_ship(self.cfg, flush=False), 0)
        self.assertEqual(self.heartbeat()["known_gaps"], [["binlog.000003", "binlog.000006"]])
        self.assertEqual(binlog_ship.run_list(self.cfg), 1, "the hole is still reported")


@unittest.skipUnless(shutil.which("openssl"), "openssl not on PATH")
class Deadlocks(ShippingHarness, unittest.TestCase):
    """If the encrypt side dies mid-stream (a full disk is the usual reason) the reader must be torn
    down: otherwise `docker exec cat` of a 348 MB file blocks on a full pipe forever, holding the
    lock, and the archive silently stops."""

    def test_a_dying_encrypt_child_ends_the_run_cleanly_instead_of_hanging(self):
        self.add_binlog("binlog.000001", 60000)     # ~2.5 MB: far more than any pipe buffer
        dying = os.path.join(self.tmp, "dying_openssl.py")
        with open(dying, "w", encoding="utf-8") as fh:
            fh.write("import sys\nsys.stdin.buffer.read(64)\nsys.exit(1)\n")
        db_backup.openssl_encrypt_cmd = lambda keyfile, out_path: [sys.executable, dying]

        started = time.monotonic()
        self.assertEqual(binlog_ship.run_ship(self.cfg, flush=False), 1)
        elapsed = time.monotonic() - started

        self.assertLess(elapsed, 60, "the run must not block on the unread reader")
        self.assertIn("openssl enc exited 1", self.log())
        self.assertEqual(self.state()["files"], {})

        # the lock was released and a later run works again
        db_backup.openssl_encrypt_cmd = self._orig[4]
        self.now = FROZEN + dt.timedelta(hours=1)
        self.assertEqual(binlog_ship.run_ship(self.cfg, flush=False), 0)
        self.assertEqual(self.shipped_names(), ["binlog.000001", "binlog.000002"])

    def test_a_wedged_container_call_times_out_instead_of_hanging(self):
        sleeper = os.path.join(self.tmp, "sleeper.py")
        with open(sleeper, "w", encoding="utf-8") as fh:
            fh.write("import time\ntime.sleep(600)\n")
        binlog_ship.mysql_cmd = lambda container, sql, user=None: [sys.executable, sleeper]
        original = binlog_ship.MYSQL_TIMEOUT
        binlog_ship.MYSQL_TIMEOUT = 2
        try:
            started = time.monotonic()
            self.assertEqual(binlog_ship.run_ship(self.cfg, flush=False), 1)
            self.assertLess(time.monotonic() - started, 30)
        finally:
            binlog_ship.MYSQL_TIMEOUT = original
        self.assertIn("did not answer within", self.log())


@unittest.skipUnless(shutil.which("openssl"), "openssl not on PATH")
class RestoreCheck(ShippingHarness, unittest.TestCase):
    """Proof that what is in the bucket is actually a usable binary log — the archive's own smoke test."""

    def test_it_validates_a_shipped_object_and_reports_the_window_it_covers(self):
        self.assertEqual(binlog_ship.run_ship(self.cfg), 0)
        key = self.key_for("binlog.000002")
        self.assertEqual(binlog_ship.run_restore_check(self.cfg, key), 0)

        log = self.log()
        self.assertIn(f"RESTORE-CHECK ok object={key}", log)
        self.assertIn("events=48", log)
        self.assertIn(f"covers={binlog_ship.iso(BASE_TS)}..{binlog_ship.iso(BASE_TS + 47 * 60)}", log)
        self.assertIn("sha256_of_plaintext=" + hashlib.sha256(self.plaintext_of("binlog.000002")).hexdigest(), log)
        self.assertEqual([p for p in os.listdir(self.local) if p.startswith("binlog-check-")], [])

    def test_a_truncated_archive_is_caught_even_though_it_shipped_cleanly(self):
        # The shipper only checks the magic bytes and the byte count, so a file that MySQL itself
        # truncated goes off-box looking fine; --restore-check is what finds it.
        self.add_binlog("binlog.000001", 24, truncate=10)
        self.assertEqual(binlog_ship.run_ship(self.cfg, flush=False), 0)
        self.assertEqual(binlog_ship.run_restore_check(self.cfg, self.key_for("binlog.000001")), 1)
        self.assertIn("is not a usable binary log", self.log())
        self.assertIn("truncated", self.log())

    def test_the_wrong_key_reports_the_cause_not_the_gzip_symptom(self):
        self.assertEqual(binlog_ship.run_ship(self.cfg), 0)
        key = self.key_for("binlog.000001")
        with open(self.keyfile, "wb") as fh:
            fh.write(b"YS1jb21wbGV0ZWx5LWRpZmZlcmVudC1rZXktdGhhdC13aWxsLW5vdC1kZWNyeXB0LWFueXRoaW5nLTAwMA==\n")
        self.assertEqual(binlog_ship.run_restore_check(self.cfg, key), 1)
        self.assertIn("wrong key?", self.log())

    def test_a_missing_object_fails_cleanly(self):
        self.assertEqual(binlog_ship.run_restore_check(self.cfg, "db-backups/dmc_demo/binlogs/2026/01/nope.gz.enc"), 1)
        self.assertIn("HTTP 404", self.log())


@unittest.skipUnless(shutil.which("openssl"), "openssl not on PATH")
class EncryptionCompatibility(ShippingHarness, unittest.TestCase):
    """The quality bar: what this script uploads must be readable by the EXISTING decrypt path."""

    def test_the_uploaded_object_decrypts_with_the_exact_command_the_restore_drill_runs(self):
        self.assertEqual(binlog_ship.run_ship(self.cfg), 0)
        for name in ("binlog.000001", "binlog.000002", "binlog.000003"):
            blob = self.fake.objects[self.key_for(name)]
            self.assertTrue(blob.startswith(b"Salted__"), "openssl -salt header expected")
            self.assertNotIn(MAGIC, blob, "ciphertext must not leak the plaintext")
            self.assertEqual(self.drill_decrypt(blob), self.plaintext_of(name))

    def test_it_uses_db_backup_pys_own_openssl_command_builders(self):
        # Not "an equivalent command" — literally the same function, so the two archives cannot fork.
        self.assertEqual(db_backup.openssl_decrypt_cmd(self.keyfile, "x.enc")[:7], DRILL_DECRYPT)
        self.assertEqual(db_backup.openssl_encrypt_cmd(self.keyfile, "o")[:7],
                         ["openssl", "enc", "-aes-256-cbc", "-pbkdf2", "-iter", "200000", "-salt"])
        self.assertIs(binlog_ship.db_backup.openssl_encrypt_cmd, db_backup.openssl_encrypt_cmd)
        self.assertEqual(binlog_ship.CIPHER_LABEL, db_backup.CIPHER_LABEL)

    def test_a_nightly_dump_and_a_binlog_are_interchangeable_to_the_decrypt_routine(self):
        self.assertEqual(binlog_ship.run_ship(self.cfg), 0)
        key = self.key_for("binlog.000001")
        self.assertEqual(self.drill_decrypt(self.fake.objects[key]), self.plaintext_of("binlog.000001"))

        out = os.path.join(self.tmp, "dump.gz.enc")
        payload = b"-- MySQL dump\nCREATE TABLE `x` (`id` int);\n-- Dump completed\n"
        enc = subprocess.Popen(db_backup.openssl_encrypt_cmd(self.keyfile, out), stdin=subprocess.PIPE)
        gz = gzip.GzipFile(filename="", mode="wb", fileobj=enc.stdin, mtime=0)
        gz.write(payload)
        gz.close()
        enc.stdin.close()
        self.assertEqual(enc.wait(), 0)
        with open(out, "rb") as fh:
            self.assertEqual(self.drill_decrypt(fh.read()), payload)


if __name__ == "__main__":
    unittest.main()
