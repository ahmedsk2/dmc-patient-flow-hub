# infra/variables.tf — every input this configuration needs.
#
# Convention used throughout: a variable has a real default ONLY when the value is documented
# somewhere in the repo (the source is named in the comment). Anything not documented is either
# left with NO default (Terraform then refuses to plan until it is supplied — the safest way to
# force a real value) or given an obviously-fake `<PLACEHOLDER...>` string default that will
# error out loudly if used unmodified against a real provider. Nothing here is a real OCID, key,
# token or IP. See infra/README.md "Placeholders and TODOs" for the indexed list.

# ---------------------------------------------------------------------------------------------
# OCI authentication and scope
# ---------------------------------------------------------------------------------------------

variable "oci_region" {
  description = "OCI region. me-riyadh-1 per laravel/docs/DEPLOY-LARAVEL.md §0 and CLAUDE.md §1 (Saudi PDPL/SDAIA in-Kingdom residency requirement, ADR 0007)."
  type        = string
  default     = "me-riyadh-1"
}

variable "oci_tenancy_ocid" {
  description = "Tenancy OCID. Not documented anywhere in the repo (it is operational secret-adjacent data) — supply via terraform.tfvars (git-ignored) or the TF_VAR_oci_tenancy_ocid env var, never committed."
  type        = string
  default     = "<PLACEHOLDER-OCI-TENANCY-OCID>"
}

variable "oci_compartment_id" {
  description = "Compartment OCID that will hold the VCN, compute instance, buckets and IAM group/policy resources. Not documented in the repo; the owner's OCI console is the source."
  type        = string
  default     = "<PLACEHOLDER-OCI-COMPARTMENT-OCID>"
}

# Deliberately no variables for user_ocid / fingerprint / private_key_path / api key auth: the OCI
# provider reads these from the standard ~/.oci/config file or OCI_CLI_* / TF_VAR_* environment
# variables by convention. Putting API-signing-key material in a .tf variable (even one with no
# default) invites it ending up in a tfvars file or shell history. Configure the provider block in
# oci.tf via env vars / the OCI config file at apply time instead.

# ---------------------------------------------------------------------------------------------
# Compute
# ---------------------------------------------------------------------------------------------

variable "instance_shape" {
  description = <<-EOT
    Compute shape for the single OCI Ubuntu host running Coolify v4 + the mysql:8 container.
    NOT documented anywhere in the repo — laravel/docs/DEPLOY-LARAVEL.md §0/§9 says "one OCI
    Ubuntu instance" but never names a shape, and no other doc does either (searched). The
    operator must read the real shape from the OCI console (Compute -> Instances -> the live
    host) and set it here before any plan; using this placeholder unmodified will fail cleanly
    against a real provider rather than silently picking something wrong.
  EOT
  type        = string
  default     = "<PLACEHOLDER-VERIFY-INSTANCE-SHAPE>"
}

variable "instance_ocpus" {
  description = "OCPU count, only meaningful for a flexible shape (VM.Standard.*.Flex). Not documented; leave null for a fixed shape."
  type        = number
  default     = null
}

variable "instance_memory_gbs" {
  description = "Memory in GB, only meaningful for a flexible shape. Not documented; leave null for a fixed shape."
  type        = number
  default     = null
}

variable "instance_image_id" {
  description = "Boot image OCID (an Ubuntu platform image, per DEPLOY-LARAVEL.md §9 step 1: \"Ubuntu with Docker + Coolify v4\"). Not documented as a specific OCID anywhere in the repo — image OCIDs are also region- and OCI-catalog-version-specific, so hand-copying one here would go stale. Look up the current Ubuntu (22.04/24.04 LTS — the repo does not pin a minor version either) platform image OCID for me-riyadh-1 at apply time."
  type        = string
  default     = "<PLACEHOLDER-VERIFY-UBUNTU-IMAGE-OCID-FOR-ME-RIYADH-1>"
}

variable "boot_volume_size_gb" {
  description = "Boot volume size in GB. Not documented in the repo; left at the OCI default (50) unless the operator knows otherwise."
  type        = number
  default     = 50
}

variable "app_host_display_name" {
  description = "Display name / hostname tag for the compute instance."
  type        = string
  default     = "dmc-app-host"
}

variable "ssh_public_key" {
  description = "SSH public key installed for the ubuntu user (the docs describe access as `ubuntu@<origin>` with the OCI SSH key and passwordless sudo — laravel session memory 'dmc-new deployment infra'; the key material itself is never in this repo). Supply via terraform.tfvars or TF_VAR_ssh_public_key, never commit a real key here."
  type        = string
  default     = "<PLACEHOLDER-SSH-PUBLIC-KEY>"
}

variable "coolify_app_uuid" {
  description = "The Coolify application uuid for dmc-new, used only as an instance freeform tag for operator reference (the scheduler cron and rollback tooling look the app container up by this same label — DEPLOY-LARAVEL.md §0, §6). Documented value below; treat it as informational, not something Terraform manages."
  type        = string
  default     = "v5d8vrnp418stpcwnup3yhta" # DEPLOY-LARAVEL.md §0
}

# ---------------------------------------------------------------------------------------------
# Networking
# ---------------------------------------------------------------------------------------------

variable "vcn_cidr" {
  description = <<-EOT
    VCN CIDR block. NOT confirmed against the live network — the repo documents the firewall
    POLICY (80/443 to Cloudflare ranges only, SSH key-only) but never the address plan itself.
    This is a conventional default for a from-scratch build (infra/README.md item 3). If
    importing the existing VCN, replace with its real CIDR (read from the OCI console) before
    plan, or `terraform plan` will show a spurious diff.
  EOT
  type        = string
  default     = "10.0.0.0/16"
}

variable "public_subnet_cidr" {
  description = "Public subnet CIDR for the app/DB host. Same caveat as vcn_cidr — a from-scratch-build convention, not a confirmed live value."
  type        = string
  default     = "10.0.1.0/24"
}

variable "ssh_allowed_cidrs" {
  description = <<-EOT
    Source CIDRs allowed to reach port 22. Deliberately has NO default, so the operator makes an
    explicit choice. Passing ["0.0.0.0/0"] reproduces the live state since 2026-09-25 (owner
    decision: the workstation address changes; login is key-only). Passing a real allow-list
    restores the 2026-09-23 restriction.
  EOT
  type        = list(string)
}

variable "cloudflare_ipv4_url" {
  description = "Where Cloudflare publishes its current IPv4 ranges. Fetched live via the http provider so the security list is never a hand-copied, potentially stale list (infra/README.md item 4)."
  type        = string
  default     = "https://www.cloudflare.com/ips-v4"
}

variable "cloudflare_ipv6_url" {
  description = "Where Cloudflare publishes its current IPv6 ranges."
  type        = string
  default     = "https://www.cloudflare.com/ips-v6"
}

variable "cloudflare_ipv4_cidrs_override" {
  description = "Optional pinned, dated snapshot of Cloudflare IPv4 ranges, for operators who would rather review-and-pin than have `terraform plan` fetch a live list against production. Empty = use the live fetch."
  type        = list(string)
  default     = []
}

variable "cloudflare_ipv6_cidrs_override" {
  description = "Optional pinned, dated snapshot of Cloudflare IPv6 ranges. Empty = use the live fetch."
  type        = list(string)
  default     = []
}

# ---------------------------------------------------------------------------------------------
# Object storage (backups + audit archive)
# ---------------------------------------------------------------------------------------------

variable "backup_bucket_name" {
  description = "The nightly-dump + hourly-binlog off-box backup bucket. BACKUP-AND-RESTORE.md §2-§3, §10.3; ADR 0010."
  type        = string
  default     = "dmc-db-backups"
}

variable "audit_bucket_name" {
  description = "The hourly write-once NDJSON audit archive bucket. DEPLOY-LARAVEL.md §0; ADR 0003; EVIDENCE-PACK.md P17."
  type        = string
  default     = "dmc-audit-log"
}

variable "backup_bucket_retention_days" {
  description = "Off-box backup retention. 90 days is the documented placeholder, explicitly marked [NEEDS LEGAL CONFIRMATION] in BACKUP-AND-RESTORE.md §6 and ADR 0010 — treat this default as a placeholder pending the hospital's records-retention decision, not a settled figure."
  type        = number
  default     = 90
}

# No audit_bucket_retention_days variable: no retention period for dmc-audit-log is documented
# anywhere in the repo (checked). oci.tf creates the bucket with no lifecycle rule rather than
# inventing one — see infra/README.md item 8.

# ---------------------------------------------------------------------------------------------
# IAM (least-privilege writers for the two buckets)
# ---------------------------------------------------------------------------------------------

variable "backup_writer_group_name" {
  description = "OCI IAM group whose members' customer secret keys are used by scripts/backup/db-backup.py and binlog-ship.py (S3_ACCESS_KEY/S3_SECRET in /root/.dmc-backup.env, BACKUP-AND-RESTORE.md §2.3). Scoped to the backup bucket only."
  type        = string
  default     = "dmc-backup-writers"
}

variable "audit_writer_group_name" {
  description = "OCI IAM group whose member's customer secret key is used by the app's AUDIT_S3_* env vars (audit:ship, DEPLOY-LARAVEL.md §0). Scoped to the audit bucket only — a separate credential from the backup writer's, least-privilege per bucket."
  type        = string
  default     = "dmc-audit-writers"
}

# ---------------------------------------------------------------------------------------------
# Cloudflare
# ---------------------------------------------------------------------------------------------

# No cloudflare_api_token variable: the Cloudflare provider reads CLOUDFLARE_API_TOKEN from the
# environment by convention. Not defining it here at all is deliberate — it cannot end up in a
# tfvars file or plan output by accident.

variable "cloudflare_zone_id" {
  description = "Cloudflare zone ID for towardpcc.com. Not documented in the repo; from the owner's Cloudflare dashboard."
  type        = string
  default     = "<PLACEHOLDER-CLOUDFLARE-ZONE-ID>"
}

variable "app_hostname" {
  description = "The public hostname, proxied through Cloudflare. DEPLOY-LARAVEL.md §0."
  type        = string
  default     = "dmc-new.towardpcc.com"
}

variable "app_origin_ip" {
  description = <<-EOT
    The DNS record's target IP. Deliberately has NO default and is never hand-typed as a real
    value anywhere in this repo: DEPLOY-LARAVEL.md §0 states "The origin IP is deliberately not
    written here — it is what the Cloudflare proxy hides; it is in the private ops note." When
    this configuration provisions its own instance (oci_core_instance.app_host), wire the DNS
    record to that resource's own computed public_ip attribute instead of this variable, so the
    IP is never typed by a human into version control. This variable exists only for the
    import-against-an-existing-instance path, where it must be supplied out-of-band (env var /
    untracked tfvars), never committed.
  EOT
  type        = string
  default     = null
}

variable "min_tls_version" {
  description = "Cloudflare zone minimum TLS version. CONFIRMED-FACTS.md B5 / C8: 'Cloudflare min TLS 1.2 + HSTS'."
  type        = string
  default     = "1.2"
}
