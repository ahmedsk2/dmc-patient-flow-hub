# infra/ — DMC infrastructure as code (documentation-grade, CFG-11)

> Written 2026-09-22. Captures the production infrastructure of the DMC Internal Medicine
> patient-flow hub **as it exists on that date**, so a rebuild is repeatable and drift is visible.

## Status — read this before touching anything here

- **This code has never been run.** It has not been `terraform init`'d, `plan`'d, `validate`'d or
  `apply`'d. Terraform is not installed in the environment that wrote it. Treat every resource
  block as a first draft that needs a real `terraform plan` against a real provider before it is
  trusted.
- **It is not applied against production and must not be, as-is.** The compute instance, VCN,
  buckets, IAM principals and DNS record it describes **already exist and are live**, holding real
  PHI (`CLAUDE.md` §1). Running `terraform apply` with this configuration and no prior import step
  would try to create duplicates of resources that are already running the clinical system, at
  best failing loudly (name collisions) and at worst succeeding and leaving two of something
  (two VCNs, a second bucket, a conflicting DNS record) — see "Adopting this for real" below.
- **Every fact in here is sourced from the repo's own docs**, named inline next to the value. Where
  a value is not documented anywhere in the repo (an OCID, an instance shape, a VCN CIDR, an IAM
  policy's exact OCI policy-language wording), it is a `<PLACEHOLDER>` or a clearly-labelled
  convention, never an invented real-looking value. See "Placeholders and TODOs" below for the
  complete list.
- **Unvalidated HCL.** Nothing here has been checked against a live provider schema. Resource and
  argument names are written from documented knowledge of the `oracle/oci` and
  `cloudflare/cloudflare` providers as of this writing, but provider versions drift. Every place a
  specific argument name is uncertain carries an inline `# TODO: verify argument names against the
  provider docs before first plan` comment — do not silently "fix" these without checking the
  provider's current schema first.

## What is deliberately out of scope

- **Coolify's own installation and application configuration.** Coolify v4 runs on the host and
  owns the build (Nixpacks), the deploy trigger, the app's environment variables and the
  application record itself (`laravel/docs/DEPLOY-LARAVEL.md` §0, §9). None of that is
  Terraform-managed here — Coolify is installed by `host-bootstrap.sh`'s Docker step only insofar
  as Docker is a prerequisite; the Coolify installer itself is not invoked. Deploys stay
  operator-triggered by owner decision (`laravel/docs/adr/0005-operator-triggered-deploys.md`);
  nothing in `infra/` changes that.
- **`APP_KEY` and every other secret.** No secret value appears anywhere in this directory:
  not the database password, not `APP_KEY`, not the OCI customer secret keys, not the Cloudflare
  API token, not the backup encryption key (`/root/.dmc-backup.key`). Where Terraform must produce
  a secret (an `oci_identity_customer_secret_key`), the resource is written so the value is a
  Terraform **output marked `sensitive = true`**, never printed to a log, never committed to state
  checked into git (Terraform state itself is not committed — see "State" below), and the owner
  retrieves it once and escrows it exactly as `laravel/docs/BACKUP-AND-RESTORE.md` §2.2 already
  requires for the existing key. This is the same "owner handles all secrets" rule as `CLAUDE.md`
  §2.
- **The legacy `dmc-im.com` site.** It runs on SiteGround shared hosting in the United States, is
  not OCI/Cloudflare infrastructure, and is out of scope for this Terraform (`CLAUDE.md` §1,
  `laravel/docs/compliance/CONFIRMED-FACTS.md` B1–B3). It is mentioned only where a fact about it
  (e.g. the plan to retire it at cutover) explains why the Laravel side looks the way it does.
- **Anything inside the running containers.** The MySQL 8 container's own configuration, the app
  container's image contents, and Coolify's internal Traefik configuration are not
  infrastructure-as-code targets here — they are owned by Coolify and the Nixpacks build.

## File tree

```
infra/
├── README.md                    this file
├── versions.tf                  Terraform + provider version pins
├── variables.tf                 every input, with safe defaults or <PLACEHOLDER> markers
├── oci.tf                       OCI network, compute, object storage, IAM (backup/audit writers)
├── cloudflare.tf                the proxied DNS record + zone security settings
├── outputs.tf                   non-sensitive identifiers + the one sensitive secret-key output
├── terraform.tfvars.example     placeholder values only — copy to terraform.tfvars and fill in
└── host-bootstrap.sh            idempotent script for what Terraform does not own (§ below)
```

## Facts, and exactly where each one came from

| Fact | Value used | Source |
|---|---|---|
| OCI region | `me-riyadh-1` | `laravel/docs/DEPLOY-LARAVEL.md` §0; `CLAUDE.md` §1; ADR 0007 |
| Compute + DB share one host | one OCI Ubuntu instance running Coolify v4 + a `mysql:8` container | `DEPLOY-LARAVEL.md` §0, §9 |
| Origin firewall | ports 80/443 accept **only** Cloudflare's published IP ranges; SSH key-only | `DEPLOY-LARAVEL.md` §0, §9; ADR 0007; `CLAUDE.md` §9 |
| SSH is not yet IP-restricted | "SSH IP allow-list deferred by the owner" | `CLAUDE.md` §14 |
| Hosting processor | Oracle Systems Limited, company-held pay-as-you-go tenancy, Basic support, Oracle-managed AES-256 volume encryption (default) | `laravel/docs/compliance/CONFIRMED-FACTS.md` B4 |
| Shared host | the same OCI host also runs unrelated databases (`endorsement`, `qch`) | `CONFIRMED-FACTS.md` B7 |
| Backup bucket | `dmc-db-backups`, in-Kingdom, holds the nightly dump + hourly binlog shipments | `BACKUP-AND-RESTORE.md` §2–§3, §10.3; ADR 0010 |
| Audit bucket | `dmc-audit-log`, in-Kingdom, hourly write-once NDJSON audit archive | `DEPLOY-LARAVEL.md` §0; ADR 0003; `EVIDENCE-PACK.md` P17 |
| Backup bucket retention | 90 days off-box **[NEEDS LEGAL CONFIRMATION]**, 2 days local — "the bucket lifecycle rule itself is still an OCI-console task for the owner" | `BACKUP-AND-RESTORE.md` §6; ADR 0010 |
| Audit bucket retention | **not documented anywhere in the repo** — not modelled as a lifecycle rule here; see Placeholders | (absence confirmed by search — no source) |
| S3-compatible endpoint shape | `https://<namespace>.compat.objectstorage.me-riyadh-1.oraclecloud.com` | `BACKUP-AND-RESTORE.md` §2.3 |
| Credential shape | an OCI "customer secret key" (access key id + secret), one pair per script (`S3_ACCESS_KEY`/`S3_SECRET` for the backup shipper, `AUDIT_S3_ACCESS_KEY`/`AUDIT_S3_SECRET` for the audit shipper) — reused, not a shared key | `BACKUP-AND-RESTORE.md` §2.3; `laravel/.env.example`; `DEPLOY-LARAVEL.md` §0 |
| Scheduler cron | host root cron, every minute, drives `php artisan schedule:run` via `docker exec` on the container found **by label** `coolify.name=<app uuid>` | `DEPLOY-LARAVEL.md` §6 |
| Nightly dump cron | `/etc/cron.d/dmc-db-backup`, `15 2 * * * root …db-backup.py` | `BACKUP-AND-RESTORE.md` §2.5 |
| Binlog shipping cron | `/etc/cron.d/dmc-binlog-ship`, `40 * * * * root …binlog-ship.py` | `BACKUP-AND-RESTORE.md` §10.2 |
| Logrotate | weekly, rotate 12, compress, delaycompress, `create 0640 root adm`, covering the four backup/binlog log files | `BACKUP-AND-RESTORE.md` §2.1 |
| PITR tools image | `dmc/mysql-pitr:<version>` built from `pitr-tools.Dockerfile`, needed because the stock `mysql:8` image has no `mysqlbinlog` | `BACKUP-AND-RESTORE.md` §10.2 |
| Branch/deploy model | `main` protected, PR-only, 4 required CI checks, admin-enforced; deploys are operator-triggered, Auto Deploy off by owner decision | ADR 0005, ADR 0006; `CLAUDE.md` §2 |
| Public hostname | `dmc-new.towardpcc.com`, proxied (orange-cloud) Cloudflare record | `DEPLOY-LARAVEL.md` §0 |
| Cloudflare plan / TLS | Free plan, minimum TLS 1.2, HSTS; Regional Services (in-Kingdom TLS termination) is Enterprise-only and NOT in use | `CONFIRMED-FACTS.md` B5; ADR 0007; `CLAUDE.md` §9 |
| Origin IP | **deliberately not written anywhere in the repo** — "it is what the Cloudflare proxy hides; it is in the private ops note" | `DEPLOY-LARAVEL.md` §0 |

## Placeholders and TODOs — the complete list

Everything below is either a `<PLACEHOLDER>` value in `variables.tf` / `terraform.tfvars.example`,
or an inline `# TODO` in the `.tf` files. Nothing real and sensitive is typed anywhere in this
directory.

1. **Every OCID** (tenancy, compartment, subnet, image, existing VCN if importing) — the docs never
   state one; these come from the owner's OCI console / the private ops note, never invented here.
2. **Compute shape and boot volume size** — no shape is named anywhere in the repo (`DEPLOY-LARAVEL.md`
   never states it; searched and not found). `variables.tf` defaults `instance_shape` to a clearly
   marked placeholder and leaves boot volume size as an OCI default; the operator must fill in the
   real shape from the console before any plan.
3. **VCN / subnet CIDR blocks** — not documented anywhere (the repo describes the firewall *policy*,
   never the network's address plan). `oci.tf` uses conventional example ranges
   (`10.0.0.0/16` / `10.0.1.0/24`) for a from-scratch build and says so in a comment; if importing the
   live VCN, replace them with its real CIDRs read from the console first, or `terraform plan` will
   show a spurious diff.
4. **Cloudflare IP ranges for the security list** — never hand-copied here (the brief explicitly
   forbids presenting a stale copy as current truth). `oci.tf` fetches
   `https://www.cloudflare.com/ips-v4` and `ips-v6` at plan/apply time via the `hashicorp/http`
   provider's `data "http"` source, split into a CIDR list. `variables.tf` also exposes
   `cloudflare_ipv4_cidrs_override` / `_ipv6_` so the operator can pin a reviewed, dated snapshot
   instead of trusting a live fetch inside a `plan` against production, if that is preferred.
5. **SSH source CIDR** — SSH is documented as **not yet IP-restricted** (`CLAUDE.md` §14: "SSH IP
   allow-list deferred by the owner"). `variables.tf`'s `ssh_allowed_cidrs` therefore has **no safe
   default** — it must be supplied, and a comment states the honest current state (open) rather than
   pretending a restriction already exists.
6. **IAM policy statement wording** — OCI policy language is exact-syntax and none of it is quoted in
   the repo (only the credential *shape*, "customer secret key", is documented). `oci.tf` writes a
   conventional least-privilege pattern (one group + one user + one customer secret key per bucket
   writer, scoped `where target.bucket.name = '<bucket>'`) with a `# TODO: verify argument names and
   policy statement syntax against the provider docs before first plan` — do not trust the exact
   wording without checking `oci_identity_policy` current syntax.
7. **Cloudflare zone/account IDs and API token** — never in the repo; `variables.tf` placeholders,
   supplied via `terraform.tfvars` (git-ignored, never committed) or environment variables at apply
   time, never hardcoded.
8. **Audit bucket (`dmc-audit-log`) retention/lifecycle** — genuinely undocumented (checked; no
   number appears anywhere in the repo, unlike the backup bucket's 90-day placeholder). `oci.tf`
   creates the bucket with **no lifecycle rule** rather than inventing a retention period, and says so
   in a comment. This is itself worth flagging to the owner as an open item, not silently decided
   here.
9. **Provider resource names for Cloudflare v4 vs v5** — the Cloudflare Terraform provider renamed
   several resources between major versions (e.g. `cloudflare_record` → `cloudflare_dns_record`,
   `cloudflare_zone_settings_override` → per-setting resources) after this file was written from
   memory of the v4 shape. `versions.tf` pins `~> 4.0` and `cloudflare.tf` carries a `# TODO: verify
   against the provider version actually available before first plan` — if the owner is on v5, the
   resource names in `cloudflare.tf` need updating first.

## Adopting this for real (sketch only — do not run without doing this)

The live resources already exist. Applying this configuration blind would try to create new,
colliding infrastructure next to a running clinical system. The only safe path:

1. **Add a `*.tfvars` / `*.tfstate*` rule to the root `.gitignore` first**, in its own reviewed
   change (out of scope for this `infra/`-only task — the root `.gitignore` does not yet have one).
   Then fill in every placeholder in `terraform.tfvars` (copied from the `.example`) with the real
   OCIDs, shape, region, bucket names (already documented — see the facts table) and Cloudflare
   zone/account IDs, from the owner's OCI console and Cloudflare dashboard. Never put a real OCID
   or token in a file this repo tracks.
2. **`terraform init`** with the two providers pinned in `versions.tf`.
3. **Import every existing resource** before ever running `plan` without `-refresh-only` intent —
   `terraform import` the VCN, subnet, security list, compute instance, both buckets, the DNS record
   and the zone settings override by their real OCIDs/IDs. Skipping this step and running `apply`
   is exactly the failure mode this README warns about above.
4. **`terraform plan`** and read every line. Expect drift on the VCN/subnet CIDRs (placeholder #3
   above) and possibly on the security list rules (Cloudflare's ranges may have changed since this
   was written, or the live list may have been hand-edited) — reconcile by updating the `.tf` to match
   reality, not by forcing a change onto production.
5. **Only once `plan` shows zero unexpected changes** does this configuration become a safe
   source of truth for future changes — from that point, infrastructure changes go through a PR that
   touches `infra/`, reviewed like any other change to a live clinical system (`CLAUDE.md` §2:
   "Confirm before anything destructive or outward-facing").
6. For the **disaster-recovery use case** instead (the host is gone, not merely being reorganised —
   `laravel/docs/BACKUP-AND-RESTORE.md` §5.1, "unrehearsed"), this configuration can instead be
   `apply`'d fresh against an empty compartment to stand up the network/compute/bucket skeleton, after
   which the operator still follows §5.1's manual steps by hand: Coolify install, `APP_KEY` from
   escrow, the data restore, the host crons (`host-bootstrap.sh` here), and the Cloudflare DNS
   repoint. This Terraform does not automate §5.1 end-to-end — it only removes the network/compute/
   bucket part of that runbook from being typed by hand into the console under incident pressure.

## What `host-bootstrap.sh` does that Terraform does not

Terraform provisions cloud resources; it does not configure the inside of the host. Everything
Terraform does not own — Docker, the backup script install, the cron entries, logrotate, the PITR
tools image — is `host-bootstrap.sh`, described in its own header comment. It is idempotent (safe
to re-run), refuses to overwrite `/root/.dmc-backup.env` or the running crons if they already exist
with real content, and contains no secrets — `/root/.dmc-backup.env` is written as a **template**
with `<PLACEHOLDER>` values the operator must edit by hand, exactly like `terraform.tfvars.example`.

## State

No `.tfstate` file is part of this commit, and none should ever be. A real adoption needs a remote
backend (an OCI Object Storage bucket with SSE, or Terraform Cloud) decided by the owner — not
specified here because no such backend is documented anywhere in the repo, and local state for a
live clinical system's infrastructure is not an acceptable long-term choice. `versions.tf` leaves
the `backend` block absent (local state) with a comment marking this as the first thing to fix
before real adoption.

## Live state observed 2026-09-23 (read off OCI, for whoever adopts this code)

The Terraform above was written from the docs and has never been planned. Reading the live tenancy on
2026-09-23 showed where it differs — reconcile these before the first `plan`, or it will try to
"fix" production:

- **IAM:** one group, `dmc-audit-writers`, with one service user and one policy
  (`dmc-audit-writers-policy`) covering **both** buckets — not two separate writer groups. Its object
  statements are the create/overwrite/inspect/read form above (no delete, since 2026-09-23), plus
  `Allow service objectstorage-me-riyadh-1 to manage object-family in tenancy` (lifecycle needs it;
  replication would too).
- **`dmc-db-backups`:** expiry is an **object lifecycle policy** (`expire-db-backups-after-90-days`,
  DELETE after 90 days), not a bucket retention rule — the retention-rule block here would make objects
  undeletable. Versioning disabled. No replication (see the quota below).
- **Tenancy quota policy `ksa-data-residency`** (2026-08-08, not modelled here): zero quotas for every
  data-bearing service (compute, block and object storage, databases, …) `where request.region !=
  me-riyadh-1`. It is the reason a second-region copy failed on 2026-09-23 (`StorageQuotaExceeded`);
  on 2026-09-24 the owner decided to **stay Riyadh-only**, and the empty test bucket was deleted. The
  tenancy remains **subscribed to `me-jeddah-1`** (OCI cannot remove a subscription) with nothing in it.
  The quota is tenancy-wide — it covers every project on this tenancy — and its description cites an
  "ADR-0004" that is not this repository's ADR 0004. Model it before any `plan`, and do not loosen it
  without the owner's decision.
- **`coolify-backups` (not modelled here):** Coolify's own backup destination for every app on the
  host. Its scheduled backup of the shared MySQL writes plain-SQL daily dumps of selected databases —
  `dmc_demo` was among them until 2026-09-24, when it was taken out (53 of its 66 old dumps deleted the same day; 13 locked until 2026-10-07) — with **no
  lifecycle policy** (a 14-day retention rule only), versioning disabled, private.
- **Compute backups (not modelled here):** the host's boot volume is under the volume backup policy
  `weekly-4` (weekly incremental, 4-week retention, no destination region) and has a manual full
  backup from 2026-07-19 with no expiry — whole-disk copies of every app on the host.
- **`dmc-audit-log`:** has a **7-year retention rule** (`audit-worm-7y`, write-once) and suspended
  versioning — the code here says "no retention rule".
- **Network:** SSH on the security list is limited to the owner's workstation address (/32, since
  2026-09-23 — supply it through `ssh_allowed_cidrs`, never commit it); 80/443 from Cloudflare's ranges
  only; the VNIC also carries a network security group that admits 443 only from another app's load
  balancer on the same host.
