#!/usr/bin/env python3
"""
Unit tests for the pure parts of db-backup.py — run anywhere with `python3 -m unittest` from this
directory (no docker/openssl/network needed):

    cd laravel/scripts/backup && python3 -m unittest -v test_db_backup

The SigV4 port is proven against the SAME published AWS SigV4 test vectors the PHP original uses
(tests/Unit/S3SigV4Test.php): access key AKIAIOSFODNN7EXAMPLE, secret
wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY, region us-east-1, date 20130524T000000Z — the "GET Object"
(with Range), "GET Bucket Lifecycle" (three headers, no Range — the exact shape of our GETs) and
"PUT Object" (the shape of our uploads) examples from "Examples of the Complete Version 4 Signing
Process". If any of these ever disagree with the PHP class, one of the two signers is wrong.
"""

import datetime as dt
import glob
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

HERE = os.path.dirname(os.path.abspath(__file__))
_spec = importlib.util.spec_from_file_location("db_backup", os.path.join(HERE, "db-backup.py"))
db_backup = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(db_backup)

SECRET = "wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY"
EMPTY = "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"
HOST = "examplebucket.s3.amazonaws.com"
DATE, STAMP = "20130524", "20130524T000000Z"


class SigV4Vectors(unittest.TestCase):
    def test_aws_get_object_vector_with_range(self):
        sig = db_backup.sigv4_signature(
            "GET", "/test.txt", "",
            {"host": HOST, "range": "bytes=0-9", "x-amz-content-sha256": EMPTY, "x-amz-date": STAMP},
            SECRET, "us-east-1", "s3", DATE, STAMP,
        )
        self.assertEqual(sig, "f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41")

    def test_aws_three_header_get_vector_without_range(self):
        sig = db_backup.sigv4_signature(
            "GET", "/", "lifecycle=",
            {"host": HOST, "x-amz-content-sha256": EMPTY, "x-amz-date": STAMP},
            SECRET, "us-east-1", "s3", DATE, STAMP,
        )
        self.assertEqual(sig, "fea454ca298b7da1c68078a5d1bdbfbbe0d65c699e0f91ac7a200a0136783543")

    def test_aws_put_object_vector(self):
        payload_hash = db_backup.sha256_hex(b"Welcome to Amazon S3.")
        self.assertEqual(payload_hash, "44ce7dd67c959e0d3524ffac1771dfbba87d2b6b4b4e99e42034a8b803f8b072")
        sig = db_backup.sigv4_signature(
            "PUT", "/test%24file.text", "",
            {
                "date": "Fri, 24 May 2013 00:00:00 GMT",
                "host": HOST,
                "x-amz-content-sha256": payload_hash,
                "x-amz-date": STAMP,
                "x-amz-storage-class": "REDUCED_REDUNDANCY",
            },
            SECRET, "us-east-1", "s3", DATE, STAMP, payload_hash,
        )
        self.assertEqual(sig, "98ad721746da40c64f1a55b78f14c238d841ea1380cd77a1b5971af0ece108bd")

    def test_header_order_does_not_matter(self):
        a = db_backup.sigv4_signature("GET", "/test.txt", "",
                                      {"x-amz-date": STAMP, "range": "bytes=0-9", "x-amz-content-sha256": EMPTY, "host": HOST},
                                      SECRET, "us-east-1", "s3", DATE, STAMP)
        self.assertEqual(a, "f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41")

    def test_a_different_secret_changes_the_signature(self):
        sig = db_backup.sigv4_signature("GET", "/test.txt", "",
                                        {"host": HOST, "range": "bytes=0-9", "x-amz-content-sha256": EMPTY, "x-amz-date": STAMP},
                                        "a-completely-different-secret", "us-east-1", "s3", DATE, STAMP)
        self.assertNotEqual(sig, "f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41")
        self.assertEqual(len(sig), 64)


class CanonicalUri(unittest.TestCase):
    def test_path_style_with_rawurlencoded_segments_like_the_php_class(self):
        self.assertEqual(
            db_backup.canonical_uri("dmc-db-backups", "db-backups/dmc_demo/2026/09/dmc_demo-2026-09-03T021507Z.sql.gz.enc"),
            "/dmc-db-backups/db-backups/dmc_demo/2026/09/dmc_demo-2026-09-03T021507Z.sql.gz.enc",
        )
        # PHP rawurlencode: space -> %20, $ -> %24, '~' and '-_.' untouched, '/' splits segments
        self.assertEqual(db_backup.canonical_uri("b", "a b/test$file.text~"), "/b/a%20b/test%24file.text~")
        self.assertEqual(db_backup.canonical_uri("b", "/leading/slash"), "/b/leading/slash")


class S3ClientSigning(unittest.TestCase):
    def test_signed_headers_carry_the_three_required_headers_plus_content_md5_when_uploading(self):
        client = db_backup.S3Client("https://ns.compat.objectstorage.me-riyadh-1.oraclecloud.com", "me-riyadh-1",
                                    "dmc-db-backups", "AKIATESTKEY", "test-secret")
        path, headers = client._signed("PUT", "db-backups/dmc_demo/LATEST.json", EMPTY, {"content-md5": "1B2M2Y8AsgTpgAmY7PhCfg=="})
        self.assertEqual(path, "/dmc-db-backups/db-backups/dmc_demo/LATEST.json")
        self.assertEqual(headers["Host"], "ns.compat.objectstorage.me-riyadh-1.oraclecloud.com")
        self.assertEqual(headers["x-amz-content-sha256"], EMPTY)
        self.assertRegex(headers["x-amz-date"], r"^\d{8}T\d{6}Z$")
        self.assertTrue(headers["Authorization"].startswith("AWS4-HMAC-SHA256 Credential=AKIATESTKEY/"))
        self.assertIn("/me-riyadh-1/s3/aws4_request, SignedHeaders=content-md5;host;x-amz-content-sha256;x-amz-date, Signature=",
                      headers["Authorization"])
        self.assertNotIn("test-secret", headers["Authorization"])

    def test_get_signs_exactly_the_same_three_headers_as_the_php_class(self):
        client = db_backup.S3Client("https://s3.example.test", "me-riyadh-1", "b", "AK", "SK")
        _, headers = client._signed("GET", "k", EMPTY)
        self.assertIn("SignedHeaders=host;x-amz-content-sha256;x-amz-date,", headers["Authorization"])

    def test_non_default_port_is_part_of_the_signed_host(self):
        client = db_backup.S3Client("http://minio.local:9000", "us-east-1", "b", "AK", "SK")
        _, headers = client._signed("GET", "k", EMPTY)
        self.assertEqual(headers["Host"], "minio.local:9000")

    def test_rejects_a_non_http_endpoint(self):
        with self.assertRaises(db_backup.BackupError):
            db_backup.S3Client("s3://bucket", "r", "b", "AK", "SK")


class ConfigParsing(unittest.TestCase):
    def test_parses_key_values_comments_quotes_and_export(self):
        cfg = db_backup.parse_env_file(
            "# comment\n\nS3_ENDPOINT=https://x\nexport S3_REGION='me-riyadh-1'\nS3_SECRET=\"a=b=c\"\n"
        )
        self.assertEqual(cfg, {"S3_ENDPOINT": "https://x", "S3_REGION": "me-riyadh-1", "S3_SECRET": "a=b=c"})

    def test_rejects_garbage_lines_and_bad_keys(self):
        with self.assertRaises(db_backup.BackupError):
            db_backup.parse_env_file("not a key value line\n")
        with self.assertRaises(db_backup.BackupError):
            db_backup.parse_env_file("lower=case\n")

    def test_build_config_applies_defaults_and_requires_the_essentials(self):
        base = {"S3_ENDPOINT": "https://x", "S3_REGION": "r", "S3_ACCESS_KEY": "a", "S3_SECRET": "s",
                "MYSQL_CONTAINER": "c"}
        cfg = db_backup.build_config(base)
        self.assertEqual(cfg["S3_BUCKET"], "dmc-db-backups")
        self.assertEqual(cfg["DB_NAME"], "dmc_demo")
        self.assertEqual(cfg["S3_PREFIX"], "db-backups/dmc_demo")
        self.assertEqual(cfg["LOCAL_KEEP_DAYS"], 2)
        self.assertEqual(cfg["LOCAL_DIR"], "/var/backups/dmc")
        self.assertEqual(cfg["KEYFILE"], "/root/.dmc-backup.key")

        with self.assertRaises(db_backup.BackupError) as ctx:
            db_backup.build_config({k: v for k, v in base.items() if k != "S3_SECRET"})
        self.assertIn("S3_SECRET", str(ctx.exception))

        with self.assertRaises(db_backup.BackupError):
            db_backup.build_config({**base, "DB_NAME": "dmc_demo; DROP"})
        with self.assertRaises(db_backup.BackupError):
            db_backup.build_config({**base, "LOCAL_KEEP_DAYS": "two"})


class Naming(unittest.TestCase):
    WHEN = dt.datetime(2026, 9, 3, 2, 15, 7, tzinfo=dt.timezone.utc)

    def test_file_and_object_names_follow_the_agreed_layout(self):
        self.assertEqual(db_backup.backup_file_name("dmc_demo", self.WHEN), "dmc_demo-2026-09-03T021507Z.sql.gz.enc")
        self.assertEqual(db_backup.object_key("db-backups/dmc_demo", "dmc_demo", self.WHEN),
                         "db-backups/dmc_demo/2026/09/dmc_demo-2026-09-03T021507Z.sql.gz.enc")
        self.assertEqual(db_backup.heartbeat_key("db-backups/dmc_demo"), "db-backups/dmc_demo/LATEST.json")

    def test_heartbeat_document_has_what_backup_verify_reads(self):
        doc = db_backup.heartbeat_document("k", 10, "ab" * 32, "cd" * 16, self.WHEN, "dmc_demo", 99, "host1")
        self.assertEqual(doc["object"], "k")
        self.assertEqual(doc["bytes"], 10)
        self.assertEqual(doc["sha256_of_ciphertext"], "ab" * 32)
        self.assertEqual(doc["created_at"], "2026-09-03T02:15:07Z")

    def test_mysqldump_command_keeps_the_password_inside_the_container(self):
        cmd = db_backup.mysqldump_cmd("u8ha9zwdgekz9djnjt1ndisf", "dmc_demo")
        self.assertEqual(cmd[:5], ["docker", "exec", "u8ha9zwdgekz9djnjt1ndisf", "sh", "-c"])
        self.assertIn('MYSQL_PWD="$MYSQL_ROOT_PASSWORD"', cmd[5])
        self.assertIn("--single-transaction --routines --triggers", cmd[5])
        self.assertTrue(cmd[5].endswith("--databases dmc_demo"))

    def test_openssl_commands_use_the_agreed_kdf(self):
        enc = db_backup.openssl_encrypt_cmd("/root/.dmc-backup.key", "/tmp/x.enc")
        self.assertEqual(enc, ["openssl", "enc", "-aes-256-cbc", "-pbkdf2", "-iter", "200000", "-salt",
                               "-pass", "file:/root/.dmc-backup.key", "-out", "/tmp/x.enc"])
        dec = db_backup.openssl_decrypt_cmd("/root/.dmc-backup.key", "/tmp/x.enc")
        self.assertEqual(dec[:7], ["openssl", "enc", "-d", "-aes-256-cbc", "-pbkdf2", "-iter", "200000"])


class Integrity(unittest.TestCase):
    def test_etag_comparison(self):
        md5 = "9e107d9d372bb6826bd81d3542a419d6"
        self.assertTrue(db_backup.etag_matches_md5('"9E107D9D372BB6826BD81D3542A419D6"', md5))
        self.assertFalse(db_backup.etag_matches_md5('"00000000000000000000000000000000"', md5))
        self.assertIsNone(db_backup.etag_matches_md5('"abc-2"', md5))   # multipart / opaque
        self.assertIsNone(db_backup.etag_matches_md5(None, md5))

    def test_prune_only_touches_our_own_old_files(self):
        now = 1_800_000_000
        entries = [
            ("dmc_demo-2026-09-01T021500Z.sql.gz.enc", now - 3 * 86400),   # old -> prune
            ("dmc_demo-2026-09-03T021500Z.sql.gz.enc", now - 3600),        # fresh -> keep
            ("other_db-2026-09-01T021500Z.sql.gz.enc", now - 30 * 86400),  # not ours -> keep
            ("notes.txt", now - 30 * 86400),                                # not ours -> keep
        ]
        self.assertEqual(db_backup.files_to_prune(entries, "dmc_demo", 2, now), ["dmc_demo-2026-09-01T021500Z.sql.gz.enc"])

    def test_scrub_redacts_secret_values(self):
        self.assertEqual(db_backup.scrub("token=hunter2 ok", ["hunter2", None, ""]), "token=[redacted] ok")


class FakeS3:
    """In-memory bucket with the S3Client surface run_backup/run_restore_check use. Returns an
    MD5 ETag like a single-part S3 PUT does (overridable to simulate corruption)."""

    def __init__(self):
        self.objects = {}
        self.etag_override = None

    def _etag(self, data):
        return self.etag_override or '"%s"' % hashlib.md5(data).hexdigest()

    def put_file(self, key, path, size, sha256_hexdigest, md5_digest, content_type="application/octet-stream"):
        with open(path, "rb") as fh:
            data = fh.read()
        assert len(data) == size and hashlib.sha256(data).hexdigest() == sha256_hexdigest
        assert hashlib.md5(data).digest() == md5_digest
        self.objects[key] = data
        return 200, [("ETag", self._etag(data))], b""

    def put_bytes(self, key, data, content_type="application/json"):
        self.objects[key] = data
        return 200, [("ETag", self._etag(data))], b""

    def get_bytes(self, key):
        if key not in self.objects:
            return 404, [], b"NoSuchKey"
        return 200, [], self.objects[key]

    def get_to_file(self, key, dest):
        if key not in self.objects:
            return 404, [], b"NoSuchKey"
        with open(dest, "wb") as fh:
            fh.write(self.objects[key])
        return 200, [], None


FAKE_SQL = (
    b"-- MySQL dump 10.13  Distrib 8.4.7, for Linux (x86_64)\n--\n-- Host: localhost    Database: dmc_demo\n"
    b"CREATE DATABASE /*!32312 IF NOT EXISTS*/ `dmc_demo`;\nUSE `dmc_demo`;\n"
    + b"".join(
        b"DROP TABLE IF EXISTS `%s`;\nCREATE TABLE `%s` (`id` bigint unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY (`id`));\n"
        b"INSERT INTO `%s` VALUES (1),(2),(3);\n" % (t, t, t)
        for t in (b"patients", b"admissions", b"users", b"consultations")
    )
    + b"-- filler " * 200 + b"\n"
)
FAKE_TRAILER = b"-- Dump completed on 2026-09-03  2:15:07\n"


@unittest.skipUnless(shutil.which("openssl"), "openssl not on PATH")
class EndToEndPipeline(unittest.TestCase):
    """The real streaming pipeline — in-process gzip, REAL openssl, a fake mysqldump (a python child
    that prints a dump-shaped stream) and an in-memory bucket. Proves dump→encrypt→hash→upload→
    heartbeat→prune and download→decrypt→gunzip→scan without docker, MySQL or a network."""

    def setUp(self):
        self.tmp = tempfile.mkdtemp(prefix="dmc-backup-test-")
        self.keyfile = os.path.join(self.tmp, "key")
        with open(self.keyfile, "wb") as fh:
            fh.write(b"dGhpcy1pcy1hLXRlc3Qta2V5LW9ubHktZm9yLXVuaXQtdGVzdHMtMTIzNDU2Nzg5MA==\n")
        self.local = os.path.join(self.tmp, "local")
        self.cfg = db_backup.build_config({
            "S3_ENDPOINT": "https://fake.test", "S3_REGION": "r", "S3_ACCESS_KEY": "a", "S3_SECRET": "s3cr3t",
            "MYSQL_CONTAINER": "c", "DB_NAME": "dmc_demo", "KEYFILE": self.keyfile, "LOCAL_DIR": self.local,
            "LOG_FILE": os.path.join(self.tmp, "backup.log"),
        })
        self.fake = FakeS3()
        self._orig_client = db_backup.make_client
        self._orig_dump = db_backup.mysqldump_cmd
        db_backup.make_client = lambda cfg: self.fake
        self.set_dump(FAKE_SQL + FAKE_TRAILER)

    def tearDown(self):
        db_backup.make_client = self._orig_client
        db_backup.mysqldump_cmd = self._orig_dump
        shutil.rmtree(self.tmp, ignore_errors=True)

    def set_dump(self, payload: bytes, exit_code: int = 0):
        path = os.path.join(self.tmp, "payload.bin")
        with open(path, "wb") as fh:
            fh.write(payload)
        script = os.path.join(self.tmp, "fake_mysqldump.py")
        with open(script, "w", encoding="utf-8") as fh:
            fh.write(
                "import sys\n"
                f"sys.stdout.buffer.write(open({path!r}, 'rb').read())\nsys.stdout.flush()\n"
                f"sys.exit({exit_code})\n"
            )
        db_backup.mysqldump_cmd = lambda container, db: [sys.executable, script]

    def log(self):
        with open(self.cfg["LOG_FILE"], encoding="utf-8") as fh:
            return fh.read()

    def local_files(self):
        return sorted(os.path.basename(p) for p in glob.glob(os.path.join(self.local, "*.enc")))

    def decrypt(self, data: bytes) -> bytes:
        enc = os.path.join(self.tmp, "x.enc")
        with open(enc, "wb") as fh:
            fh.write(data)
        out = subprocess.run(db_backup.openssl_decrypt_cmd(self.keyfile, enc), capture_output=True, check=True).stdout
        return gzip.decompress(out)

    def test_dry_run_encrypts_locally_and_uploads_nothing(self):
        self.assertEqual(db_backup.run_backup(self.cfg, dry_run=True), 0)
        files = self.local_files()
        self.assertEqual(len(files), 1)
        self.assertRegex(files[0], r"^dmc_demo-\d{4}-\d{2}-\d{2}T\d{6}Z\.sql\.gz\.enc$")
        with open(os.path.join(self.local, files[0]), "rb") as fh:
            blob = fh.read()
        self.assertTrue(blob.startswith(b"Salted__"), "openssl -salt header expected")
        self.assertNotIn(b"CREATE TABLE", blob, "ciphertext must not contain plaintext")
        self.assertEqual(self.decrypt(blob), FAKE_SQL + FAKE_TRAILER)
        self.assertEqual(self.fake.objects, {})
        self.assertIn("DRY-RUN ok", self.log())
        self.assertEqual(glob.glob(os.path.join(self.local, "*.tmp")), [])

    def test_full_run_uploads_object_plus_heartbeat_and_restore_check_validates_it(self):
        self.assertEqual(db_backup.run_backup(self.cfg, dry_run=False), 0)

        keys = sorted(self.fake.objects)
        self.assertEqual(len(keys), 2)
        heartbeat_key, object_key = "db-backups/dmc_demo/LATEST.json", [k for k in keys if k.endswith(".enc")][0]
        self.assertIn(heartbeat_key, keys)
        self.assertRegex(object_key, r"^db-backups/dmc_demo/\d{4}/\d{2}/dmc_demo-\d{4}-\d{2}-\d{2}T\d{6}Z\.sql\.gz\.enc$")

        hb = json.loads(self.fake.objects[heartbeat_key])
        blob = self.fake.objects[object_key]
        self.assertEqual(hb["object"], object_key)
        self.assertEqual(hb["bytes"], len(blob))
        self.assertEqual(hb["sha256_of_ciphertext"], hashlib.sha256(blob).hexdigest())
        self.assertEqual(hb["plaintext_bytes"], len(FAKE_SQL + FAKE_TRAILER))
        self.assertRegex(hb["created_at"], r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$")
        self.assertEqual(self.decrypt(blob), FAKE_SQL + FAKE_TRAILER)

        log = self.log()
        self.assertIn(" OK object=" + object_key, log)
        self.assertNotIn("s3cr3t", log)

        # --restore-check: download, decrypt+gunzip in a pipe, confirm it is a complete dump
        self.assertEqual(db_backup.run_restore_check(self.cfg, object_key), 0)
        self.assertIn("RESTORE-CHECK ok object=" + object_key, self.log())
        self.assertIn("tables=4", self.log())
        self.assertEqual(glob.glob(os.path.join(self.local, "restore-check-*")), [], "work dir removed")

    def test_truncated_dump_is_never_uploaded(self):
        self.set_dump(FAKE_SQL)  # no '-- Dump completed' trailer
        self.assertEqual(db_backup.run_backup(self.cfg, dry_run=False), 1)
        self.assertEqual(self.fake.objects, {})
        self.assertEqual(self.local_files(), [])
        self.assertIn("FAIL step=dump", self.log())
        self.assertIn("truncated", self.log())

    def test_failed_mysqldump_is_never_uploaded(self):
        self.set_dump(FAKE_SQL + FAKE_TRAILER, exit_code=2)
        self.assertEqual(db_backup.run_backup(self.cfg, dry_run=False), 1)
        self.assertEqual(self.fake.objects, {})
        self.assertIn("mysqldump exited 2", self.log())

    def test_etag_mismatch_fails_the_run_without_a_heartbeat(self):
        self.fake.etag_override = '"00000000000000000000000000000000"'
        self.assertEqual(db_backup.run_backup(self.cfg, dry_run=False), 1)
        self.assertNotIn("db-backups/dmc_demo/LATEST.json", self.fake.objects)
        self.assertIn("FAIL step=upload", self.log())

    def test_restore_check_with_the_wrong_key_fails(self):
        self.assertEqual(db_backup.run_backup(self.cfg, dry_run=False), 0)
        object_key = [k for k in self.fake.objects if k.endswith(".enc")][0]
        with open(self.keyfile, "wb") as fh:
            fh.write(b"YS1jb21wbGV0ZWx5LWRpZmZlcmVudC1rZXktdGhhdC13aWxsLW5vdC1kZWNyeXB0LWFueXRoaW5nLTAwMA==\n")
        self.assertEqual(db_backup.run_restore_check(self.cfg, object_key), 1)
        self.assertIn("RESTORE-CHECK FAIL", self.log())
        self.assertIn("wrong key?", self.log(), "the cause (openssl bad decrypt) must be reported, not the gzip symptom")

    def test_restore_check_of_a_missing_object_fails(self):
        self.assertEqual(db_backup.run_restore_check(self.cfg, "db-backups/dmc_demo/2026/01/nope.sql.gz.enc"), 1)
        self.assertIn("HTTP 404", self.log())

    def test_local_retention_prunes_only_old_copies(self):
        old = os.path.join(self.local, "dmc_demo-2020-01-01T000000Z.sql.gz.enc")
        os.makedirs(self.local, exist_ok=True)
        with open(old, "wb") as fh:
            fh.write(b"x")
        os.utime(old, (0, 0))
        stranger = os.path.join(self.local, "keep-me.txt")
        with open(stranger, "wb") as fh:
            fh.write(b"x")
        os.utime(stranger, (0, 0))

        self.assertEqual(db_backup.run_backup(self.cfg, dry_run=False), 0)
        self.assertFalse(os.path.exists(old))
        self.assertTrue(os.path.exists(stranger))
        self.assertEqual(len(self.local_files()), 1)
        self.assertIn("pruned=1", self.log())


if __name__ == "__main__":
    unittest.main()
