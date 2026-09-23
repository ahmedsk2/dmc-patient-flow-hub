# Workstation clean-up of DMC patient-data copies — 2026-09-24

> **What this records:** the inventory of DMC patient-data copies and DMC work files removed from the
> owner's workstation on 2026-09-24, and what was deliberately left for the owner. It closes the
> "inventory of what was destroyed" half of REMAINING-WORK's workstation-exports item and supports
> EVIDENCE-PACK P19 and CONFIRMED-FACTS D1. It lists file names, sizes, dates and SHA-256 hashes only;
> no file was opened or read beyond hashing.

## How the copies were found

A read-only sweep on 2026-09-24, run by Claude Code on the owner's instruction, used five independent
methods: by file name, by content signature (file names only, no lines printed), local databases
and virtual disks, temp/caches/Recycle Bin, and the repository's untracked/ignored files. It was
followed by a two-agent classification pass and a completeness critic. **Google Drive was excluded
entirely, on the owner's instruction** ("leave everything in my Google Drive untouched") — it was
neither listed nor searched. Evidence allowed per file: path, size, date, SQL table names, CSV
column-count and header-keyword yes/no flags, spreadsheet sheet names. Real vs demo data was judged
from those, never from content.

## What was moved to the Recycle Bin (38 files, 165,744,759 bytes)

**Action:** moved to the Windows Recycle Bin on 2026-09-24 (≈ 01:22 Riyadh) by Claude Code, on the
owner's instruction ("you may delete the files you want to delete"). Every file was hashed before the
move; afterwards all 38 were confirmed present in the Recycle Bin and absent from their folders.

**Not yet destroyed:** the Recycle Bin is reversible by design. **Permanent destruction is the owner's
step — empty the Recycle Bin** — and record the date here: `[DATE EMPTIED]`. The bin also holds an
older copy of that export, `dmc_laravel_export.sql` (18,626,097 bytes, deleted 2026-06-10), which the
same step removes. (Emptying the bin frees the blocks; on an SSD the drive discards them via TRIM. Full-disk
encryption is what makes any remnant unreadable — see REMAINING-WORK.)

Real patient data: rows 1–11 (legacy production dumps and exports, 2026-06 to 2026-08), 12–13 (the
local Laravel export of 2026-07-13) and 16 and 23 (two app pages saved while the local copy held the
real import). Demo data: row 15 (the 2026-09-23 walkthrough's labelled export) and rows 34–38 (SQL
fixtures written for local tests). Report renders — rows 14 and 24–33 — could not be tied to real or
demo data from metadata alone and are **treated as patient data**. Rows 17–22 are DMC work files
(queries and their small outputs, a local test log, a local test cookie and token).

| # | Where | File | Bytes | Last modified (Riyadh) | SHA-256 |
|---|---|---|---:|---|---|
| 1 | Downloads | `dbqeqbacgfvmhk.sql` | 16,477,261 | 2026-06-08 00:51 | `ca391c024edebda447f8871ba00a41fc1dbb8482452055e157a849b4c0192d9c` |
| 2 | Downloads | `dbqeqbacgfvmhk (1).sql` | 16,488,662 | 2026-06-09 12:26 | `9b1edafb50a0c29dfc7bb3eb9228fce79855b5dcd59047ecd32dd6f47a2a7002` |
| 3 | Downloads | `dbqeqbacgfvmhk (2).sql` | 16,488,466 | 2026-06-10 02:51 | `bdda02a3ee707506d9f4faaff4fd1fd90b41a719fed8150661da0380a68a8462` |
| 4 | Downloads | `dbqeqbacgfvmhk (3).sql` | 16,682,494 | 2026-07-11 00:38 | `8ebcb5e61ea2f9bbf908a71e6917b5d700de38bcfb1a6c3cc4d8475e250f7cb9` |
| 5 | Downloads | `dbqeqbacgfvmhk (4).sql` | 16,693,722 | 2026-07-12 20:16 | `05d571a9995e072a72d929aefba77c09eef9242a0da639f8fb2fce815923836c` |
| 6 | Downloads | `dbqeqbacgfvmhk (5).sql` | 16,694,168 | 2026-07-12 23:50 | `013e82bfbd05e69d7a42af285028e1a7f471733f4fae02aae618efd9fe94afa9` |
| 7 | Downloads | `dbqeqbacgfvmhk (6).sql` | 16,953,669 | 2026-08-20 17:42 | `25efb4c7460db46c800b38c3bfcd074ffdff7925626c79edb9a7c993a8aef155` |
| 8 | Downloads | `dbqeqbacgfvmhk.csv` | 16,030,925 | 2026-08-17 11:26 | `df51a60a7fb23222b2594bc8d4acc8cf7dbf58a080bbb26fe80be8e6829ba317` |
| 9 | Downloads | `picupatients old.csv` | 166,908 | 2026-06-19 22:25 | `46936024c28ae0aa315e09c95b093a6e60b3bea64b39dcbb040d5c4fc3386367` |
| 10 | Downloads | `picupatients latest.csv` | 172,514 | 2026-06-19 22:25 | `24a9d6926b8f9984a9f5fa016a2fa7dc24c0c7f91e213ee690b2f6712f6054b8` |
| 11 | Downloads | `Patients info.csv` | 175,115 | 2026-06-20 00:01 | `2724d95fd5ad7f8474225af15b804c1841139af6cb9b59c44427f80fb2ac8b6c` |
| 12 | Downloads | `dmc_laravel_export.sql` | 19,519,144 | 2026-07-13 00:18 | `b9e9a49ba7ede862790be2cf5779183682bba066e36cf107a20c3473e3a74a5c` |
| 13 | Downloads | `dmc_laravel_export.sql.gz` | 2,264,942 | 2026-07-13 00:18 | `b36395b328d2d015446958669c87a45ac01076405d8b2cdeb1a73c9fd94295b5` |
| 14 | Downloads | `dmc-monthly-report-2026-06.pdf` | 881,260 | 2026-06-11 02:18 | `c4f86aab1d217be914b30a4a988bcf0d963c00023d42a6d29c9991fa003e298d` |
| 15 | Downloads | `SECRET-Audit-Export-23-09-2026.csv` | 21,686 | 2026-09-23 13:28 | `9b33a9c29e8e0e6b1d102fdfd988146422f53b2ec084a4be285bbcd940ecbb0d` |
| 16 | Downloads | `DMC _ Dashboard.pdf` | 331,978 | 2026-07-13 00:28 | `343c91a53852e5d87b31d61a0bd15a3b4a4f766db2ef67870162fa868747f085` |
| 17 | Downloads | `_recon.sql` | 5,754 | 2026-06-09 12:44 | `9520f48a74b3a48f8706535ae7e5bba92c4134e86ae5fcdae302d95a479552b5` |
| 18 | Downloads | `_recon_out.txt` | 2,400 | 2026-06-09 12:44 | `4729b73a3ad19bbec4534156d67c92845d0e4123a20ddebcafa47f209ba55507` |
| 19 | Downloads | `_recon_out2.txt` | 2,376 | 2026-06-10 03:05 | `573db78c18a456eb3780efe77366939560ab976ce2d3a6a3621ac508840c7f23` |
| 20 | Downloads | `dmc_test_error.log` | 226,239 | 2026-06-07 06:48 | `1c04ae6927245b045da13f5c2ff5faf56a2864c34f97bd314ab7d184b93a15c8` |
| 21 | Downloads | `dmc_test.cookies` | 209 | 2026-06-07 01:56 | `3d4676206784baf02bef1c2680b3839fcbfd7bc66567609a06e37fc389a7082c` |
| 22 | Downloads | `dmc_test.token` | 65 | 2026-06-07 01:56 | `07d929518694a0bcca20c263ef0f1a7f1b95bdbbfd36848d5d149d27d7fdabc0` |
| 23 | Downloads | `Command Center — DMC Internal Medicine · DMC Internal Medicine.pdf` | 331,427 | 2026-07-13 00:28 | `83ec4fe511d7986b15f43de5b3a839b7506a85f298ab2a239b6305f0d9551546` |
| 24 | repo `laravel/storage/app/` (git-ignored) | `after-annual.pdf` | 992,715 | 2026-06-11 04:29 | `4e8052184069e9d59d01bebe630c985433be44601e0f98479c488d6836469587` |
| 25 | repo `laravel/storage/app/` (git-ignored) | `after-monthly.pdf` | 1,130,095 | 2026-06-11 04:29 | `d94ef84c34114dcf2197e571ef9cfc04b46a60e82519b5e880890c2ac11f3fcc` |
| 26 | repo `laravel/storage/app/` (git-ignored) | `before-annual.pdf` | 884,374 | 2026-06-11 04:14 | `f338f4b536c8ff549df512ef99a19cd716fb341872dcef72ab2e511fa0a3d5b1` |
| 27 | repo `laravel/storage/app/` (git-ignored) | `before-monthly.pdf` | 881,404 | 2026-06-11 04:14 | `02efddd0644b09d7db06522920eba5bdc71e1b14272ea93e3da38bebfbba92e7` |
| 28 | Claude Code scratchpad | `real_annual.pdf` | 988,788 | 2026-09-22 17:19 | `1dc200b4c68bb108bff231e004e2509c2dbd517dbeab3558c9b6966c0df0b68c` |
| 29 | Claude Code scratchpad | `real_annual_fixed.pdf` | 988,994 | 2026-09-22 17:29 | `d3681ab73032d57372fd604b8f80fcbfe1e5558725f1444d32d8130b1c3d9165` |
| 30 | Claude Code scratchpad | `real_annual_subsetting.pdf` | 137,864 | 2026-09-22 17:23 | `a87f6db17d90fe27632a5f8c8240831f4c702ec132daffb140901c89f7bde8f0` |
| 31 | Claude Code scratchpad | `real_monthly.pdf` | 920,585 | 2026-09-22 17:32 | `3aa51d602cca669f4954b650b9e75fe8ef16e32e7d77203c7368d7a0634c5fb7` |
| 32 | Claude Code scratchpad | `real_statistics.pdf` | 881,075 | 2026-09-22 17:31 | `c370042b2e92e6420b40010c6208ab98d99aa08eb158680fe4beb0b8beb9727f` |
| 33 | Claude Code scratchpad | `gov.pdf` | 1,304,897 | 2026-09-23 21:22 | `29b0d4b2191db43e76ba9c48e3476845b87ab8124da91666ce643f1a229f0f05` |
| 34 | Claude Code scratchpad | `day_setup.sql` | 10,406 | 2026-09-23 19:46 | `4f7466f4206241400e3f22f903a73223f1ddac013cfa58d1eb02c4ec9c21da6c` |
| 35 | Claude Code scratchpad | `shf_patients.sql` | 2,553 | 2026-09-23 19:43 | `47ce7a19d53546d68da9c6c1ecf8f7c567b7d0c6ccdcf214206f177778bb430e` |
| 36 | Claude Code scratchpad | `shf_setup.sql` | 2,499 | 2026-09-23 19:43 | `98f79b0bdf2bdd2ae7244aad52c2726872b669ca8db6e0c1784d8ba58d456645` |
| 37 | Claude Code scratchpad | `gen_inserts.sql` | 3,840 | 2026-09-23 21:48 | `2ef0daf4f6d6eff59fc61ad5aeca85481b53b995014108bdf81e9db7c5360d7e` |
| 38 | Claude Code scratchpad | `gen_inserts2.sql` | 3,286 | 2026-09-23 21:55 | `e0e38adda78bb93d557c820eccd618a94b7dee38fce3f2440b6daa76022d5ca7` |

## Left for the owner (not touched)

DMC-relevant items that are databases or the owner's own backups, so not "loose files":

1. **Local WAMP MySQL databases holding real data** — `dmc_laravel` (≈ 104 MB, a legacy import of
   production data, last loaded 2026-08-20), `dmc_prod` (≈ 17 MB, the legacy source for that import)
   and `dmc` (≈ 52 MB, created 2026-06-08, most likely an early import of a legacy dump). They are
   contrary to CLAUDE.md §10 ("local dev must never point at production data"); local development now
   uses the Docker test database with demo data. Dropping them is the owner's step (permanent).
2. **The owner's daily local mirror of the OCI bucket `coolify-backups`**, which includes Coolify's
   own **unencrypted** daily dumps of the production `dmc_demo` database (66 dumps from 2026-07-19,
   ≈ 1.3 GB) alongside other apps' backups. (Later the same night `dmc_demo` was taken out of that
   Coolify job, so no new DMC dumps reach the mirror; the 66 remain.) This is the owner's local backup; it should sit on an
   encrypted disk or be replaced by the encrypted DMC backups (BACKUP-AND-RESTORE §6).
3. Local WAMP and Docker **test** databases (`dmc_test*`, `dmc_test_legacy`, the `dmc-test-mysql`
   container) — demo/test data only.

Also found and left with the owner: a handful of patient spreadsheets whose origin could not be
confirmed as DMC from metadata alone, in other folders and applications on the workstation; they
were reported to the owner directly, not listed in this public repository.

## Not checked

Volume shadow copies (need administrator rights); the contents of Docker Desktop's virtual disks and
of mail-client stores; Claude Code's own session transcripts (not examined for fragments).
