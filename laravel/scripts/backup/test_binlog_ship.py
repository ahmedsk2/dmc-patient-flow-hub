#!/usr/bin/env python3
"""
Unit tests for binlog-ship.py — run anywhere with `python3 -m unittest` from this directory (no
docker, no MySQL, no network needed):

    cd laravel/scripts/backup && python3 -m unittest -v test_db_backup test_binlog_ship

Same shape as test_db_backup.py: the pure logic is tested directly, and the streaming pipeline is
tested end-to-end with a fake `docker`/`mysql` (python children driven by a small control file), a
fake container filesystem (real files on disk), the same in-memory FakeS3 bucket the nightly backup
tests use, and — when `openssl` is on PATH — REAL openssl.

The load-bearing assertion is `EncryptionCompatibility`: an object this script uploads is decrypted
with the LITERAL command line db-restore-drill.sh runs, proving the archive format did not fork.
"""

import datetime as dt
import gzip
import hashlib
import importlib.util
import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest

from test_db_backup import FakeS3  # the same fake bucket the nightly-backup tests use

HERE = os.path.dirname(os.path.abspath(__file__))
_spec = importlib.util.spec_from_file_location("binlog_ship", os.path.join(HERE, "binlog-ship.py"))
binlog_ship = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(binlog_ship)

db_backup = binlog_ship.db_backup
MAGIC = binlog_ship.BINLOG_MAGIC

# The exact DECRYPT=(...) array in db-restore-drill.sh, and the exact line documented in
# BACKUP-AND-RESTORE.md §3. If binlog-ship.py ever writes something this cannot open, the archive
# has forked from the nightly backups and the runbook is wrong.
DRILL_DECRYPT = ["openssl", "enc", "-d", "-aes-256-cbc", "-pbkdf2", "-iter", "200000"]


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
        # the nightly keys survive untouched
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

    def test_ordering_is_numeric_so_it_survives_the_lexical_break_at_999999(self):
        entries = [("binlog.1000000", 1), ("binlog.999999", 2), ("binlog.999998", 3)]
        to_ship, active, _, _ = binlog_ship.select_files_to_ship(entries, {})
        self.assertEqual(active, "binlog.1000000")
        self.assertEqual([n for n, _ in to_ship], ["binlog.999998", "binlog.999999"])

    def test_a_server_with_only_an_active_log_ships_nothing(self):
        to_ship, active, _, _ = binlog_ship.select_files_to_ship([("binlog.000001", 4)], {})
        self.assertEqual((to_ship, active), ([], "binlog.000001"))


class Naming(unittest.TestCase):
    WHEN = dt.datetime(2026, 9, 3, 14, 20, 7, tzinfo=dt.timezone.utc)

    def test_object_and_heartbeat_keys_sit_under_the_nightly_prefix(self):
        self.assertEqual(binlog_ship.binlog_object_key("db-backups/dmc_demo/binlogs", "binlog.000002", self.WHEN),
                         "db-backups/dmc_demo/binlogs/2026/09/binlog.000002.gz.enc")
        self.assertEqual(binlog_ship.binlog_heartbeat_key("db-backups/dmc_demo/binlogs"),
                         "db-backups/dmc_demo/binlogs/LATEST.json")

    def test_object_key_refuses_a_name_that_could_escape_the_prefix(self):
        with self.assertRaises(db_backup.BackupError):
            binlog_ship.binlog_object_key("p", "../LATEST.json", self.WHEN)

    def test_mysql_command_keeps_the_password_inside_the_container(self):
        cmd = binlog_ship.mysql_cmd("u8ha9zwdgekz9djnjt1ndisf", "SHOW BINARY LOGS")
        self.assertEqual(cmd[:5], ["docker", "exec", "u8ha9zwdgekz9djnjt1ndisf", "sh", "-c"])
        self.assertIn('MYSQL_PWD="$MYSQL_ROOT_PASSWORD"', cmd[5])
        self.assertIn("--batch --skip-column-names", cmd[5])
        self.assertTrue(cmd[5].endswith("'SHOW BINARY LOGS'"))
        self.assertNotIn("-p", " ".join(cmd[5].split("exec mysql")[1:]).replace("--skip", ""))

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


class StateFile(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.mkdtemp(prefix="dmc-binlog-state-")
        self.path = os.path.join(self.tmp, "binlog-shipped.json")
        self.when = dt.datetime(2026, 9, 3, 14, 20, 7, tzinfo=dt.timezone.utc)

    def tearDown(self):
        shutil.rmtree(self.tmp, ignore_errors=True)

    def test_a_missing_state_file_is_an_empty_ledger_not_an_error(self):
        state = binlog_ship.load_state(self.path, "dmc_demo")
        self.assertEqual(state["files"], {})
        self.assertEqual(state["db"], "dmc_demo")

    def test_round_trip(self):
        state = binlog_ship.empty_state("dmc_demo")
        state["files"]["binlog.000002"] = binlog_ship.binlog_record(
            "binlog.000002", "p/2026/09/binlog.000002.gz.enc", 348, "ab" * 32, 120, "cd" * 32, "ef" * 16, self.when)
        binlog_ship.save_state(self.path, state, self.when)
        again = binlog_ship.load_state(self.path, "dmc_demo")
        self.assertEqual(again["files"], state["files"])
        self.assertEqual(again["updated_at"], "2026-09-03T14:20:07Z")
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
        present = ["binlog.000009", "binlog.000010"]
        kept, dropped = binlog_ship.prune_state(files, present, keep=3)
        self.assertEqual(dropped, ["binlog.000001", "binlog.000002", "binlog.000003", "binlog.000004", "binlog.000005"])
        self.assertEqual(sorted(kept), ["binlog.000006", "binlog.000007", "binlog.000008",
                                        "binlog.000009", "binlog.000010"])

    def test_prune_is_a_no_op_while_the_ledger_is_small(self):
        files = {"binlog.000001": {}, "binlog.000002": {}}
        kept, dropped = binlog_ship.prune_state(files, [], keep=2000)
        self.assertEqual((kept, dropped), (files, []))


class Heartbeat(unittest.TestCase):
    WHEN = dt.datetime(2026, 9, 3, 14, 20, 7, tzinfo=dt.timezone.utc)

    def test_field_names_match_the_nightly_heartbeat_so_backup_verify_could_read_it(self):
        record = binlog_ship.binlog_record("binlog.000002", "k", 348, "ab" * 32, 120, "cd" * 32, "ef" * 16, self.WHEN)
        doc = binlog_ship.binlog_heartbeat_document(record, self.WHEN, "dmc_demo", 2, "binlog.000004", 3, "host1")
        nightly = db_backup.heartbeat_document("k", 120, "cd" * 32, "ef" * 16, self.WHEN, "dmc_demo", 348, "host1")
        for field in ("object", "bytes", "sha256_of_ciphertext", "created_at", "db", "cipher", "host", "producer"):
            self.assertIn(field, doc, field)
            self.assertIn(field, nightly, field)
        self.assertEqual(doc["object"], "k")
        self.assertEqual(doc["created_at"], "2026-09-03T14:20:07Z")
        self.assertEqual(doc["last_shipped_at"], "2026-09-03T14:20:07Z")
        self.assertEqual(doc["binlog"], "binlog.000002")
        self.assertEqual(doc["active_binlog"], "binlog.000004")
        self.assertEqual(doc["shipped_this_run"], 2)
        self.assertEqual(doc["cipher"], nightly["cipher"])
        self.assertEqual(doc["producer"], "scripts/backup/binlog-ship.py")

    def test_object_is_null_only_before_anything_has_ever_been_shipped(self):
        doc = binlog_ship.binlog_heartbeat_document(None, self.WHEN, "dmc_demo", 0, "binlog.000001", 1, "h")
        self.assertIsNone(doc["object"])
        self.assertEqual(doc["created_at"], "2026-09-03T14:20:07Z")

    def test_last_shipped_picks_the_newest_by_sequence_not_by_insertion_order(self):
        files = {"binlog.000003": {"binlog": "binlog.000003"}, "binlog.000001": {"binlog": "binlog.000001"}}
        self.assertEqual(binlog_ship.last_shipped(files)["binlog"], "binlog.000003")
        self.assertIsNone(binlog_ship.last_shipped({}))


class NeverDeletes(unittest.TestCase):
    def test_the_script_contains_no_statement_that_could_destroy_a_binary_log(self):
        with open(os.path.join(HERE, "binlog-ship.py"), encoding="utf-8") as fh:
            source = fh.read()
        for forbidden in ("PURGE BINARY", "PURGE MASTER", "RESET MASTER", "RESET BINARY"):
            self.assertNotIn(forbidden, source, f"{forbidden} must never appear — MySQL expires binlogs itself")


class ShippingHarness:
    """The real streaming pipeline: fake `docker exec mysql` and `docker exec cat` (python children),
    a fake container datadir of real files, an in-memory bucket, REAL gzip + REAL openssl.

    A plain mixin, not a TestCase, so the two suites below share it without re-running each other."""

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
            json.dump({"logs": [], "dir": self.datadir}, fh)

        self.cfg = base_config(KEYFILE=self.keyfile, LOCAL_DIR=self.local,
                               BINLOG_LOG_FILE=os.path.join(self.tmp, "binlog-ship.log"),
                               BINLOG_DIR="/var/lib/mysql")
        self.fake = FakeS3()
        self._orig = (binlog_ship.make_client, binlog_ship.mysql_cmd, binlog_ship.read_binlog_cmd)
        binlog_ship.make_client = lambda cfg: self.fake
        binlog_ship.mysql_cmd = lambda container, sql, user=None: [sys.executable, self._fake_mysql(), self.control, sql]
        binlog_ship.read_binlog_cmd = lambda container, d, name, user=None: [
            sys.executable, self._fake_cat(), os.path.join(self.datadir, name)]

        self.add_binlog("binlog.000001", 1024)
        self.add_binlog("binlog.000002", 2048)
        self.add_binlog("binlog.000003", 512)     # the active one until the first FLUSH

    def tearDown(self):
        binlog_ship.make_client, binlog_ship.mysql_cmd, binlog_ship.read_binlog_cmd = self._orig
        shutil.rmtree(self.tmp, ignore_errors=True)

    # -- fakes -------------------------------------------------------------------------------------

    def _fake_mysql(self):
        """`docker exec … mysql -e <sql>`: FLUSH BINARY LOGS rotates (closing the active file and
        opening the next one, on disk and in the listing); SHOW BINARY LOGS prints the batch table."""
        path = os.path.join(self.tmp, "fake_mysql.py")
        if not os.path.exists(path):
            with open(path, "w", encoding="utf-8") as fh:
                fh.write(
                    "import json, os, sys\n"
                    "control, sql = sys.argv[1], sys.argv[2]\n"
                    "state = json.load(open(control))\n"
                    "up = sql.upper()\n"
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

    def add_binlog(self, name, size, magic=MAGIC):
        """Create (or replace) one file in the fake datadir and in the SHOW BINARY LOGS listing."""
        body = magic + bytes((i * 7 + len(name)) % 251 for i in range(size - len(magic)))
        with open(os.path.join(self.datadir, name), "wb") as fh:
            fh.write(body)
        with open(self.control, encoding="utf-8") as fh:
            state = json.load(fh)
        state["logs"] = [row for row in state["logs"] if row[0] != name] + [[name, size]]
        state["logs"].sort(key=lambda row: binlog_ship.binlog_sequence(row[0]))
        with open(self.control, "w", encoding="utf-8") as fh:
            json.dump(state, fh)
        return body

    def set_listed_size(self, name, size):
        with open(self.control, encoding="utf-8") as fh:
            state = json.load(fh)
        state["logs"] = [[n, size if n == name else s] for n, s in state["logs"]]
        with open(self.control, "w", encoding="utf-8") as fh:
            json.dump(state, fh)

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

        self.assertEqual(self.objects(), [
            "db-backups/dmc_demo/binlogs/%s/binlog.00000%d.gz.enc" % (dt.datetime.now(dt.timezone.utc).strftime("%Y/%m"), i)
            for i in (1, 2, 3)
        ])
        # binlog.000004 was opened by the FLUSH and is the active file — never shipped
        self.assertTrue(os.path.exists(os.path.join(self.datadir, "binlog.000004")))
        self.assertNotIn("binlog.000004", " ".join(self.objects()))

        state = self.state()
        self.assertEqual(sorted(state["files"]), ["binlog.000001", "binlog.000002", "binlog.000003"])
        self.assertEqual(state["files"]["binlog.000002"]["plaintext_bytes"], 2048)
        self.assertEqual(state["files"]["binlog.000002"]["sha256_of_plaintext"],
                         hashlib.sha256(self.plaintext_of("binlog.000002")).hexdigest())

        hb = self.heartbeat()
        self.assertEqual(hb["binlog"], "binlog.000003")
        self.assertEqual(hb["active_binlog"], "binlog.000004")
        self.assertEqual(hb["shipped_this_run"], 3)
        self.assertEqual(hb["known_binlogs"], 4)
        self.assertEqual(hb["bytes"], len(self.fake.objects[hb["object"]]))
        self.assertEqual(hb["sha256_of_ciphertext"], hashlib.sha256(self.fake.objects[hb["object"]]).hexdigest())
        self.assertRegex(hb["created_at"], r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$")

        log = self.log()
        self.assertIn(" OK shipped=3 ", log)
        self.assertIn("active=binlog.000004", log)
        self.assertNotIn("s3cr3t", log)
        self.assertEqual([p for p in os.listdir(self.local) if p.startswith("binlog-ship-")], [],
                         "the work directory must be removed")

    def test_hourly_reruns_are_idempotent_and_pick_up_only_the_newly_closed_log(self):
        self.assertEqual(binlog_ship.run_ship(self.cfg), 0)
        first = dict(self.fake.objects)

        self.assertEqual(binlog_ship.run_ship(self.cfg), 0)      # the next hour
        new = [k for k in self.objects() if k not in first]
        self.assertEqual([k.rsplit("/", 1)[1] for k in new], ["binlog.000004.gz.enc"])
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
        self.assertEqual(binlog_ship.run_ship(self.cfg, flush=False), 0)
        after = self.heartbeat()
        self.assertEqual(len(self.objects()), 3, "no FLUSH, nothing new closed → nothing shipped")
        self.assertEqual(after["shipped_this_run"], 0)
        self.assertEqual(after["object"], before["object"], "the heartbeat still names the last object shipped")
        self.assertEqual(after["last_shipped_at"], before["last_shipped_at"])
        self.assertIn(" OK shipped=0 files=- ", self.log())

    def test_a_run_before_anything_has_ever_been_flushed_still_writes_a_usable_heartbeat(self):
        cfg = base_config(KEYFILE=self.keyfile, LOCAL_DIR=os.path.join(self.tmp, "l2"),
                          BINLOG_LOG_FILE=os.path.join(self.tmp, "l2.log"),
                          BINLOG_STATE_FILE=os.path.join(self.tmp, "s2.json"))
        with open(self.control, "w", encoding="utf-8") as fh:
            json.dump({"logs": [["binlog.000001", 4]], "dir": self.datadir}, fh)
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
        self.assertEqual(len(self.objects()), 2, "--no-flush ships the two already-closed logs only")

        self.assertEqual(binlog_ship.main(["--env-file", os.path.join(self.tmp, "nope.env")]), 2,
                         "a missing config file is exit 2, not 1")

    def test_binary_logs_are_never_deleted_from_the_server(self):
        before = sorted(os.listdir(self.datadir))
        self.assertEqual(binlog_ship.run_ship(self.cfg), 0)
        after = sorted(os.listdir(self.datadir))
        self.assertEqual(after, sorted(before + ["binlog.000004"]),
                         "shipping must only ADD the rotated file; MySQL expires binlogs itself")

    def test_a_file_that_is_not_a_binary_log_is_refused(self):
        self.add_binlog("binlog.000001", 1024, magic=b"NOPE")   # a CLOSED file with the wrong header
        self.assertEqual(binlog_ship.run_ship(self.cfg, flush=False), 1)
        self.assertIn("magic bytes", self.log())
        self.assertIn("FAIL step=ship", self.log())
        self.assertEqual(self.objects(), [], "nothing may be archived once a file looks wrong")

    def test_a_size_that_disagrees_with_show_binary_logs_is_refused(self):
        self.set_listed_size("binlog.000001", 999)
        self.assertEqual(binlog_ship.run_ship(self.cfg, flush=False), 1)
        self.assertIn("read 1024 bytes but SHOW BINARY LOGS reported 999", self.log())
        self.assertNotIn("db-backups/dmc_demo/binlogs/LATEST.json", self.fake.objects,
                         "a failed run must not advertise a heartbeat")

    def test_a_failure_midway_keeps_what_already_landed_and_the_next_run_resumes(self):
        os.unlink(os.path.join(self.datadir, "binlog.000002"))
        self.assertEqual(binlog_ship.run_ship(self.cfg, flush=False), 1)
        self.assertEqual([k.rsplit("/", 1)[1] for k in self.objects()], ["binlog.000001.gz.enc"])
        self.assertEqual(sorted(self.state()["files"]), ["binlog.000001"])
        self.assertIn("FAIL step=ship", self.log())

        self.add_binlog("binlog.000002", 2048)                 # the file comes back (bad mount, say)
        self.set_listed_size("binlog.000002", 2048)
        self.assertEqual(binlog_ship.run_ship(self.cfg, flush=False), 0)
        self.assertEqual([k.rsplit("/", 1)[1] for k in self.objects()],
                         ["binlog.000001.gz.enc", "binlog.000002.gz.enc"])

    def test_an_etag_mismatch_fails_the_run_without_a_heartbeat(self):
        self.fake.etag_override = '"00000000000000000000000000000000"'
        self.assertEqual(binlog_ship.run_ship(self.cfg), 1)
        self.assertNotIn("db-backups/dmc_demo/binlogs/LATEST.json", self.fake.objects)
        self.assertIn("upload integrity check failed", self.log())

    def test_child_stderr_is_scrubbed_of_the_bucket_secret(self):
        leak = os.path.join(self.tmp, "leaky.py")
        with open(leak, "w", encoding="utf-8") as fh:
            fh.write("import sys\nsys.stderr.write('auth failed for %s\\n' % sys.argv[1])\nsys.exit(3)\n")
        binlog_ship.read_binlog_cmd = lambda container, d, name, user=None: [sys.executable, leak, "s3cr3t"]
        self.assertEqual(binlog_ship.run_ship(self.cfg, flush=False), 1)
        log = self.log()
        self.assertIn("[redacted]", log)
        self.assertNotIn("s3cr3t", log)


@unittest.skipUnless(shutil.which("openssl"), "openssl not on PATH")
class EncryptionCompatibility(ShippingHarness, unittest.TestCase):
    """The quality bar: what this script uploads must be readable by the EXISTING decrypt path."""

    def test_the_uploaded_object_decrypts_with_the_exact_command_the_restore_drill_runs(self):
        self.assertEqual(binlog_ship.run_ship(self.cfg), 0)
        for name in ("binlog.000001", "binlog.000002", "binlog.000003"):
            key = [k for k in self.objects() if k.endswith(name + ".gz.enc")][0]
            blob = self.fake.objects[key]
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
        """Encrypt a binlog with binlog-ship.py's pipeline and a dump with db-backup.py's; both come
        back through one decrypt routine."""
        self.assertEqual(binlog_ship.run_ship(self.cfg), 0)
        key = [k for k in self.objects() if k.endswith("binlog.000001.gz.enc")][0]
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
