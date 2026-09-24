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

**Action:** moved to the Windows Recycle Bin on 2026-09-24 (≈ 01:22 Riyadh) by Claude Code, then
destroyed the same day when the owner emptied the bin (below), on the
owner's instruction ("you may delete the files you want to delete"). Every file was hashed before the
move; afterwards all 38 were confirmed present in the Recycle Bin and absent from their folders.

**Destroyed 2026-09-24 — the owner emptied the Recycle Bin** (permanent deletion was the owner's
step; the Recycle Bin is reversible by design). The same step removed an older copy of that export,
`dmc_laravel_export.sql` (18,626,097 bytes, deleted 2026-06-10). **Verified the same day** by Claude
Code, metadata only: the Recycle Bin holds no items; none of the 38 files, nor the older copy, is in
it; none is back in its original folder. (Emptying the bin frees the blocks; on an SSD the drive
discards them via TRIM, which leaves no recoverable copy in normal use. Full-disk encryption is what
makes any remnant unreadable — REMAINING-WORK.)

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
   uses the Docker test database with demo data. **Dropped by the owner on 2026-09-24** (follow-up
   below).
2. **The owner's daily local mirror of the OCI bucket `coolify-backups`**, which includes Coolify's
   own **unencrypted** daily dumps of the production `dmc_demo` database (66 dumps from 2026-07-19,
   ≈ 1.3 GB) alongside other apps' backups. (Later the same night `dmc_demo` was taken out of that
   Coolify job, so no new DMC dumps reach the mirror. The owner then deleted 53 of the 66 from the
   bucket and their laptop copies were recycled — follow-up below.) This is the owner's local backup; it should sit on an
   encrypted disk or be replaced by the encrypted DMC backups (BACKUP-AND-RESTORE §6).
3. Local WAMP and Docker **test** databases (`dmc_test*`, `dmc_test_legacy`, the `dmc-test-mysql`
   container) — demo/test data only.

Also found and left with the owner: a handful of patient spreadsheets whose origin could not be
confirmed as DMC from metadata alone, in other folders and applications on the workstation; they
were reported to the owner directly, not listed in this public repository.

## Not checked

Volume shadow copies (need administrator rights); the contents of Docker Desktop's virtual disks and
of mail-client stores; Claude Code's own session transcripts (not examined for fragments).

## Follow-up, same day (2026-09-24)

- **Local WAMP databases dropped by the owner.** `dmc_laravel`, `dmc_prod` and `dmc` were dropped by
  the owner in phpMyAdmin; verified by Claude Code the same day: their folders no longer exist in
  WAMP's MySQL data directory, and only the `dmc_test*` / `dmc_test_legacy` demo databases remain.
- **Coolify's old DMC dumps.** The owner deleted **53 of the 66** unencrypted `dmc_demo` dumps from
  the bucket `coolify-backups`; the 13 newest (2026-09-10 → 2026-09-23) were refused by the bucket's
  locked 14-day retention rule (`RetentionRuleViolation`) and become deletable on 2026-10-07 03:00 UTC.
  Verified afterwards: 13 remain in the bucket.
- **The laptop's copies of those 53** (in the owner's `coolify-backups` mirror) were hashed and moved
  to the Recycle Bin by Claude Code — 53 files, 1,042,441,366 bytes. They were
  **destroyed 2026-09-24** when the owner emptied the bin (a first attempt through Explorer left them in place; `Clear-RecycleBin -DriveLetter C` then cleared it). Verified the same day by Claude Code, metadata only: the Recycle Bin holds no items and no data files on disk, and none of the 53 is back in the mirror. The mirror's other 13 stay while their bucket originals do
  (the sync re-downloads whatever the bucket holds).

| # | File | Bytes | Last modified (laptop) | SHA-256 |
|---|---|---:|---|---|
| 1 | `mysql-dump-dmc_demo-1784488352.dmp` | 19,543,493 | 2026-09-23 21:51 | `ebff75fa79c65ba8a7143f4a282e39e8e4d634a3e154e5fc1ed458f25ef87f37` |
| 2 | `mysql-dump-dmc_demo-1784516406.dmp` | 19,543,493 | 2026-09-23 21:52 | `580c3a03b5f11189cefe8500722732e38377404e5efdb8acef3b4be1f78c694c` |
| 3 | `mysql-dump-dmc_demo-1784602806.dmp` | 19,543,493 | 2026-09-23 21:52 | `813e17805e14f75de2e28088c0c685f57cc4b9b5ce8a1f1b21088424ed2c2644` |
| 4 | `mysql-dump-dmc_demo-1784689207.dmp` | 19,543,493 | 2026-09-23 21:52 | `09b2e57cc1e24b7165177d5a77b406c08cc95a4ae77e5929ed1f6986e076ca33` |
| 5 | `mysql-dump-dmc_demo-1784775607.dmp` | 19,543,493 | 2026-09-23 21:52 | `716a70a014b868887456db7c7332dace40ec019d2ab2320a86995081aec27120` |
| 6 | `mysql-dump-dmc_demo-1784862006.dmp` | 19,543,493 | 2026-09-23 21:51 | `29da13413c792e6e17bd16a47087c59d357bbda07b4ab8a402fe49ab681512d0` |
| 7 | `mysql-dump-dmc_demo-1784948405.dmp` | 19,543,493 | 2026-09-23 21:51 | `ccaa981c12aea82f96a127e11b4b76da52fb1fc7dfdf99c6eaa5d1dfeb0e0cbe` |
| 8 | `mysql-dump-dmc_demo-1785034806.dmp` | 19,543,493 | 2026-09-23 21:52 | `0ffcf29238e5b706668cff6207cf0129bccfe0e0d8c9fb77caabcfa013ee3eec` |
| 9 | `mysql-dump-dmc_demo-1785121206.dmp` | 19,543,493 | 2026-09-23 21:52 | `214a9e0d5cc5384f98529d4ec7b530c00384945234fa40eafee05e0a3b8144d3` |
| 10 | `mysql-dump-dmc_demo-1785207606.dmp` | 19,543,742 | 2026-09-23 21:51 | `9744f46472390a4ce01e6fbfbd183ffaff707e1ec2dbfadfedef4bb3d4938f2d` |
| 11 | `mysql-dump-dmc_demo-1785294007.dmp` | 19,543,742 | 2026-09-23 21:51 | `85cc6432a0f5768dbbdcee7d8c628a4a2c7b2d24dc6342e6882f4e6fe4a43c2e` |
| 12 | `mysql-dump-dmc_demo-1785380407.dmp` | 19,543,742 | 2026-09-23 21:52 | `11e7ab5de7bc72018235de37e418e3ec24749a83b9537d48cbeebb027973b69b` |
| 13 | `mysql-dump-dmc_demo-1785466806.dmp` | 19,543,742 | 2026-09-23 21:52 | `1e9bcef1eb17e1502e1bd41e4ad2fb6b7649d8f38b0bcee8f8057adceb8eec94` |
| 14 | `mysql-dump-dmc_demo-1785553207.dmp` | 19,543,742 | 2026-09-23 21:51 | `88e0d5fb8fe493fb783323bb3d292691973bf9aeaf3586af1b68bab2ea453ab3` |
| 15 | `mysql-dump-dmc_demo-1785639607.dmp` | 19,543,742 | 2026-09-23 21:52 | `3737baf7a76b31d5252bcc2990600331b80a9bcb05f010a08d115fbbb663d32d` |
| 16 | `mysql-dump-dmc_demo-1785726007.dmp` | 19,543,742 | 2026-09-23 21:52 | `70088c5dda287fa747e1ce559181458453c0b3e2c063c9be8eca390cbc1fc8b9` |
| 17 | `mysql-dump-dmc_demo-1785812406.dmp` | 19,543,742 | 2026-09-23 21:52 | `9f1506cb57dee0f3cd96206373f273aa5135ed44ffacca237b74e99346ab99ac` |
| 18 | `mysql-dump-dmc_demo-1785898808.dmp` | 19,543,742 | 2026-09-23 21:52 | `492b0503e1cc4a60af83f4d677a9e090d43288e428d5e196ffa95837c4a4e067` |
| 19 | `mysql-dump-dmc_demo-1785985207.dmp` | 19,543,742 | 2026-09-23 21:52 | `34289f9354dedf1cff5f8f3e6a49e41786d4de629539b0748bb5ae85df1f1234` |
| 20 | `mysql-dump-dmc_demo-1786071607.dmp` | 19,543,742 | 2026-09-23 21:52 | `8a4c490d37e643a34c052ada85ea316fc763f6d7ba95ca54ceca5873cea91712` |
| 21 | `mysql-dump-dmc_demo-1786158007.dmp` | 19,543,742 | 2026-09-23 21:52 | `1efa25c8b26d6684f4fdefbfa1ba4171e1cc991f53b92c6e74e440a8bd2d569a` |
| 22 | `mysql-dump-dmc_demo-1786244407.dmp` | 19,543,742 | 2026-09-23 21:52 | `934516295eb4238027e6995a15e43eff212959fd974fc78f587249ffb6746ba3` |
| 23 | `mysql-dump-dmc_demo-1786330806.dmp` | 19,543,742 | 2026-09-23 21:52 | `0bf3cbd1ce6eb509b677bd81978af6b2c0a38153c966bd0d7065c6e4bdd35faf` |
| 24 | `mysql-dump-dmc_demo-1786417207.dmp` | 19,543,742 | 2026-09-23 21:52 | `a48cd6c28b342372fe5fa15e44c735e283a1867985001b70f3fb6d74c3033f0a` |
| 25 | `mysql-dump-dmc_demo-1786503607.dmp` | 19,543,742 | 2026-09-23 21:52 | `b360759a863e758feee2d680ecd0ecd359f1606be55540d055354eb4cc5d3fe3` |
| 26 | `mysql-dump-dmc_demo-1786590006.dmp` | 19,543,742 | 2026-09-23 21:52 | `174d76d1b96124bc20bd91fd973b3f3a90e6674fc418ec72dca05802976e283f` |
| 27 | `mysql-dump-dmc_demo-1786676406.dmp` | 19,543,742 | 2026-09-23 21:52 | `06013aa819ce929a5a099232190784fba7047c881b20b7ccaea1881affe244cc` |
| 28 | `mysql-dump-dmc_demo-1786762807.dmp` | 19,543,742 | 2026-09-23 21:52 | `c9448cd525668cbb610bc8a6eeac6647d73c2d9da07571269907a41fbef29180` |
| 29 | `mysql-dump-dmc_demo-1786849206.dmp` | 19,543,742 | 2026-09-23 21:52 | `c20d1e0a5e43fe599df854f58ab382fce4c0b4518042ff6467bdec49117b9f42` |
| 30 | `mysql-dump-dmc_demo-1786935606.dmp` | 19,543,742 | 2026-09-23 21:52 | `8f5d66ef5df025cc02a8324ff3ec2264ecf8b09baa828e3ef80d7d18eda6f1bd` |
| 31 | `mysql-dump-dmc_demo-1787022006.dmp` | 19,543,742 | 2026-09-23 21:52 | `b98c42c070f9fdc69b172580aba72315c100ed28f6341291edea0f43c3601890` |
| 32 | `mysql-dump-dmc_demo-1787108407.dmp` | 19,543,742 | 2026-09-23 21:52 | `52bc4e17fa5c2624752f0f4f8f4422e1e9c2f60869f667e1b3be3c6164d6e5ed` |
| 33 | `mysql-dump-dmc_demo-1787194806.dmp` | 19,551,453 | 2026-09-23 21:52 | `af80423f41b37e41b606036ea35a25f7549a2e982cc8bf1d9235da974e1e9cc0` |
| 34 | `mysql-dump-dmc_demo-1787281207.dmp` | 19,897,460 | 2026-09-23 21:52 | `b710dd31dc41e5cf788d89d1e48bc13f9383a83e3a6291c6d4b56ae23dad99de` |
| 35 | `mysql-dump-dmc_demo-1787367606.dmp` | 19,905,417 | 2026-09-23 21:53 | `34aa715aad25748d8118916fe480c47b163e9365996ed91b0e7905a30bd7c98e` |
| 36 | `mysql-dump-dmc_demo-1787454006.dmp` | 19,653,896 | 2026-09-23 21:52 | `06e965e8e7272b371801059abba59ef9f96fc87634577b3871af1bbd2a2075a2` |
| 37 | `mysql-dump-dmc_demo-1787540406.dmp` | 19,662,351 | 2026-09-23 21:52 | `d22759e3aa12a003697eb3a1b2b64bcfc5f01609b4202401cfeb148a0d87f5de` |
| 38 | `mysql-dump-dmc_demo-1787626806.dmp` | 19,670,297 | 2026-09-23 21:52 | `9d43ac47eaaf718ba07a15d0e14bfb4d6ec65df0672614aa487ad95b09c4da00` |
| 39 | `mysql-dump-dmc_demo-1787713207.dmp` | 19,678,245 | 2026-09-23 21:53 | `3e4db8d66e38872222e6c491a38aa4eb8b67b517a1d16d41ec73cf399ab38ee9` |
| 40 | `mysql-dump-dmc_demo-1787799606.dmp` | 19,686,193 | 2026-09-23 21:52 | `c599a6c6c4eb535fbcb323edc3aafb3eff16ef95135bacf26e3e15e9f74f04c8` |
| 41 | `mysql-dump-dmc_demo-1787886006.dmp` | 19,694,141 | 2026-09-23 21:53 | `932f297b022294574ae8087a178a13c1d7b3d06056d317b109e70433c71e0091` |
| 42 | `mysql-dump-dmc_demo-1787972407.dmp` | 19,702,091 | 2026-09-23 21:53 | `2440b9cd0cb6da8a025af17dcdc0934040d4c22e6a19348998b4f05002ed7eec` |
| 43 | `mysql-dump-dmc_demo-1788058807.dmp` | 19,710,041 | 2026-09-23 21:52 | `c068e7f10b0640e38bbc1238c059ef895f54f56ca49f89f4db5092e8ffc3150e` |
| 44 | `mysql-dump-dmc_demo-1788145207.dmp` | 19,717,991 | 2026-09-23 21:53 | `4cb6b5626b1b2352e96be3537dd9ae5217db0701039981380c96c8ee35876014` |
| 45 | `mysql-dump-dmc_demo-1788231606.dmp` | 19,725,941 | 2026-09-23 21:53 | `cbcb4ab41da54f68964ad2148aa08fd78348041c6418854cf6f9e2887b33e23f` |
| 46 | `mysql-dump-dmc_demo-1788318006.dmp` | 19,733,891 | 2026-09-23 21:53 | `1b23b0f4517a929e2f036cb20061bd79e42772e0ad7aed35c2a38e41150d0f2d` |
| 47 | `mysql-dump-dmc_demo-1788404407.dmp` | 19,742,303 | 2026-09-23 21:53 | `45fe5b44f2d56bea32c3267f0654c6543c6e1ba46594159a7a0d485b0950fb4f` |
| 48 | `mysql-dump-dmc_demo-1788490806.dmp` | 20,196,008 | 2026-09-23 21:53 | `694ed4bde571a54b00539b98ea80b93e1178cdc959e16c38f341514c0c405483` |
| 49 | `mysql-dump-dmc_demo-1788577206.dmp` | 20,214,281 | 2026-09-23 21:53 | `c90488314c2a566119f3537f56661d02647fe2d61b1e2ebe6588aefa9a2355c9` |
| 50 | `mysql-dump-dmc_demo-1788663606.dmp` | 20,199,144 | 2026-09-23 21:53 | `19274dd909beb98ebdf922ef4965853ab41bf7449bea57260a47a3311ac23f44` |
| 51 | `mysql-dump-dmc_demo-1788750007.dmp` | 20,241,721 | 2026-09-23 21:53 | `190cbfc404468c56d06cdfe1f1026480bc2e03e1a014dd51b962a6e38b4086b0` |
| 52 | `mysql-dump-dmc_demo-1788836406.dmp` | 20,217,652 | 2026-09-23 21:53 | `2583351a4aa5af2246b08d4982b5d5b928f23db19d2cd1a46f4121550d4b7f46` |
| 53 | `mysql-dump-dmc_demo-1788922806.dmp` | 20,243,346 | 2026-09-23 21:53 | `1a124ada10d54d76b22792bf70e47f2d5efda6cdc26d3db8247ffa1505365906` |
