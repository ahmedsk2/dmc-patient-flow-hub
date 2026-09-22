# infra/oci.tf — OCI network, compute, object storage and IAM for the DMC Laravel host.
#
# UNVALIDATED HCL — see infra/README.md. Every fact below is sourced inline; every uncertain
# argument name carries a `# TODO: verify ... before first plan` comment per the task brief.

provider "oci" {
  region = var.oci_region
  # Auth (user_ocid / fingerprint / private_key_path, or an instance-principal / security-token
  # flow) is deliberately NOT set here — supply it via the standard OCI config file
  # (~/.oci/config) or OCI_CLI_*/TF_VAR_* environment variables at apply time. See variables.tf's
  # comment under "OCI authentication and scope".
  # TODO: verify current provider auth block shape (config_file_profile vs explicit args) against
  # the oracle/oci provider docs before first plan.
}

# -------------------------------------------------------------------------------------------
# Cloudflare's published IP ranges — fetched live, never hand-copied (infra/README.md item 4).
# -------------------------------------------------------------------------------------------

data "http" "cloudflare_ipv4" {
  url = var.cloudflare_ipv4_url
}

data "http" "cloudflare_ipv6" {
  url = var.cloudflare_ipv6_url
}

locals {
  # Both files are newline-separated CIDR lists with a trailing blank line; compact() drops empties.
  cloudflare_ipv4_live = compact(split("\n", data.http.cloudflare_ipv4.response_body))
  cloudflare_ipv6_live = compact(split("\n", data.http.cloudflare_ipv6.response_body))

  cloudflare_ipv4_cidrs = length(var.cloudflare_ipv4_cidrs_override) > 0 ? var.cloudflare_ipv4_cidrs_override : local.cloudflare_ipv4_live
  cloudflare_ipv6_cidrs = length(var.cloudflare_ipv6_cidrs_override) > 0 ? var.cloudflare_ipv6_cidrs_override : local.cloudflare_ipv6_live
}

# -------------------------------------------------------------------------------------------
# Networking — DEPLOY-LARAVEL.md §0: "one OCI Ubuntu instance"; §9 step 1: "Firewall 80/443 to
# Cloudflare ranges only; SSH key-only." ADR 0007 restates the same. CIDR blocks below are a
# from-scratch-build convention, NOT a confirmed live value — infra/README.md item 3.
# -------------------------------------------------------------------------------------------

resource "oci_core_vcn" "dmc" {
  compartment_id = var.oci_compartment_id
  cidr_blocks    = [var.vcn_cidr]
  display_name   = "dmc-vcn"
  dns_label      = "dmcvcn"
  # TODO: verify argument names (cidr_blocks vs cidr_block, singular/plural changed across
  # provider versions) against the provider docs before first plan.
}

resource "oci_core_internet_gateway" "dmc" {
  compartment_id = var.oci_compartment_id
  vcn_id         = oci_core_vcn.dmc.id
  display_name   = "dmc-igw"
  enabled        = true
}

resource "oci_core_route_table" "dmc_public" {
  compartment_id = var.oci_compartment_id
  vcn_id         = oci_core_vcn.dmc.id
  display_name   = "dmc-public-rt"

  route_rules {
    destination       = "0.0.0.0/0"
    destination_type  = "CIDR_BLOCK"
    network_entity_id = oci_core_internet_gateway.dmc.id
  }
}

# Security list: origin ports 80/443 admit ONLY Cloudflare's published ranges (never 0.0.0.0/0 —
# DEPLOY-LARAVEL.md §0: "an unproxied DNS record or a direct curl gets nothing"). SSH is scoped to
# var.ssh_allowed_cidrs, which has no default (see variables.tf) because the real, documented
# state today is that no IP allow-list is applied yet (CLAUDE.md §14).
resource "oci_core_security_list" "dmc_public" {
  compartment_id = var.oci_compartment_id
  vcn_id         = oci_core_vcn.dmc.id
  display_name   = "dmc-public-sl"

  egress_security_rules {
    destination = "0.0.0.0/0"
    protocol    = "all"
  }

  dynamic "ingress_security_rules" {
    for_each = local.cloudflare_ipv4_cidrs
    content {
      source   = ingress_security_rules.value
      protocol = "6" # TCP
      tcp_options {
        min = 443
        max = 443
      }
    }
  }

  dynamic "ingress_security_rules" {
    for_each = local.cloudflare_ipv4_cidrs
    content {
      source   = ingress_security_rules.value
      protocol = "6"
      tcp_options {
        min = 80
        max = 80
      }
    }
  }

  # IPv6 Cloudflare ranges, same two ports. Kept as a separate dynamic block for clarity since the
  # `source` values are a different family; OCI security lists accept both IPv4 and IPv6 CIDRs in
  # `source` as of recent provider versions.
  # TODO: verify IPv6 ingress rule support / argument shape against the provider docs before first
  # plan — some OCI provider versions require source_type = "CIDR_BLOCK" set explicitly for IPv6.
  dynamic "ingress_security_rules" {
    for_each = local.cloudflare_ipv6_cidrs
    content {
      source   = ingress_security_rules.value
      protocol = "6"
      tcp_options {
        min = 443
        max = 443
      }
    }
  }

  dynamic "ingress_security_rules" {
    for_each = local.cloudflare_ipv6_cidrs
    content {
      source   = ingress_security_rules.value
      protocol = "6"
      tcp_options {
        min = 80
        max = 80
      }
    }
  }

  dynamic "ingress_security_rules" {
    for_each = var.ssh_allowed_cidrs
    content {
      source   = ingress_security_rules.value
      protocol = "6"
      tcp_options {
        min = 22
        max = 22
      }
    }
  }
}

resource "oci_core_subnet" "dmc_public" {
  compartment_id             = var.oci_compartment_id
  vcn_id                     = oci_core_vcn.dmc.id
  cidr_block                 = var.public_subnet_cidr
  display_name               = "dmc-public-subnet"
  dns_label                  = "dmcpublic"
  route_table_id             = oci_core_route_table.dmc_public.id
  security_list_ids          = [oci_core_security_list.dmc_public.id]
  prohibit_public_ip_on_vnic = false
}

# -------------------------------------------------------------------------------------------
# Compute — the single host running Coolify v4 + the mysql:8 container (DEPLOY-LARAVEL.md §0).
# Shape and image are undocumented placeholders (infra/README.md items 2) — this resource will
# not plan cleanly until they are filled in.
# -------------------------------------------------------------------------------------------

resource "oci_core_instance" "app_host" {
  compartment_id      = var.oci_compartment_id
  availability_domain = "" # TODO: an OCI AD name (e.g. "xXXX:ME-RIYADH-1-AD-1") is not documented anywhere in the repo — fill in from the console.
  shape                = var.instance_shape
  display_name         = var.app_host_display_name

  # Only meaningful for a *.Flex shape; harmless when shape is fixed and these are null.
  dynamic "shape_config" {
    for_each = var.instance_ocpus == null ? [] : [1]
    content {
      ocpus         = var.instance_ocpus
      memory_in_gbs = var.instance_memory_gbs
    }
  }

  create_vnic_details {
    subnet_id        = oci_core_subnet.dmc_public.id
    assign_public_ip = true
  }

  source_details {
    source_type = "image"
    image_id    = var.instance_image_id
    boot_volume_size_in_gbs = var.boot_volume_size_gb
  }

  metadata = {
    ssh_authorized_keys = var.ssh_public_key
  }

  freeform_tags = {
    "coolify-app-uuid" = var.coolify_app_uuid
    "managed-by"       = "terraform-infra-as-code-CFG-11"
  }

  # TODO: verify argument names (availability_domain requirement, shape_config nesting,
  # source_details vs source_details block shape) against the oci_core_instance schema for the
  # pinned provider version before first plan — this resource is the least certain in the file.
}

# -------------------------------------------------------------------------------------------
# Object storage — the two in-Kingdom buckets (ADR 0007; DEPLOY-LARAVEL.md §0; BACKUP-AND-RESTORE.md).
# -------------------------------------------------------------------------------------------

data "oci_objectstorage_namespace" "ns" {
  compartment_id = var.oci_compartment_id
}

resource "oci_objectstorage_bucket" "db_backups" {
  compartment_id = var.oci_compartment_id
  namespace      = data.oci_objectstorage_namespace.ns.namespace
  name           = var.backup_bucket_name
  access_type    = "NoPublicAccess"
  # Oracle-managed AES-256 at rest by default (CONFIRMED-FACTS.md B4) — no kms_key_id set, matching
  # the documented "default" encryption, not a customer-managed key.

  retention_rules {
    display_name = "backup-retention-placeholder"
    duration {
      time_amount = var.backup_bucket_retention_days
      time_unit   = "DAYS"
    }
    # BACKUP-AND-RESTORE.md §6: "90 days [NEEDS LEGAL CONFIRMATION]" and "the bucket lifecycle
    # rule itself is still an OCI-console task for the owner" — this rule mirrors that documented
    # placeholder in code; it is not a settled retention decision. See infra/README.md item 8 for
    # the audit bucket's contrasting *absence* of a rule.
  }

  # TODO: verify retention_rules block shape (OCI distinguishes bucket-level "retention rules",
  # which are immutable/legal-hold-style, from object lifecycle "policy" for deletion after N
  # days via a separate oci_objectstorage_object_lifecycle_policy resource — confirm which one
  # the operator actually wants before first plan; a legal-hold-style retention rule would make
  # the objects UNDELETABLE for the duration, which may not be intended for a rolling 90-day
  # window). This is flagged deliberately rather than guessed silently.
}

resource "oci_objectstorage_bucket" "audit_log" {
  compartment_id = var.oci_compartment_id
  namespace      = data.oci_objectstorage_namespace.ns.namespace
  name           = var.audit_bucket_name
  access_type    = "NoPublicAccess"

  # No lifecycle/retention rule: no retention period for dmc-audit-log is documented anywhere in
  # the repo (checked — only the backup bucket's 90-day placeholder is). Inventing one here would
  # violate the "never invent a retention period" guardrail (CLAUDE.md §14). This bucket's
  # write-once NDJSON archive is the tamper-evident audit trail (ADR 0003) — an operator adding a
  # retention rule later should confirm it does not contradict any records-retention obligation
  # first. infra/README.md item 8.
}

# -------------------------------------------------------------------------------------------
# IAM — least-privilege writer per bucket, mirroring the two separate documented credentials
# (S3_ACCESS_KEY/S3_SECRET for the backup shipper, AUDIT_S3_ACCESS_KEY/AUDIT_S3_SECRET for the
# app's audit shipper — BACKUP-AND-RESTORE.md §2.3, laravel/.env.example, DEPLOY-LARAVEL.md §0).
# No IAM group/policy names or exact policy-language wording are documented anywhere in the repo;
# this is a conventional least-privilege pattern, not a captured fact. infra/README.md item 6.
# -------------------------------------------------------------------------------------------

resource "oci_identity_group" "backup_writers" {
  compartment_id = var.oci_tenancy_ocid # IAM groups live in the tenancy (root compartment), per OCI convention
  name           = var.backup_writer_group_name
  description    = "Least-privilege group: write-only access to the ${var.backup_bucket_name} bucket for the nightly dump + hourly binlog shipper (BACKUP-AND-RESTORE.md §2.3, §10.2)."
}

resource "oci_identity_group" "audit_writers" {
  compartment_id = var.oci_tenancy_ocid
  name           = var.audit_writer_group_name
  description    = "Least-privilege group: write-only access to the ${var.audit_bucket_name} bucket for the app's hourly audit:ship (DEPLOY-LARAVEL.md §0)."
}

resource "oci_identity_user" "backup_shipper" {
  compartment_id = var.oci_tenancy_ocid
  name           = "dmc-backup-shipper"
  description    = "Service identity for scripts/backup/db-backup.py and binlog-ship.py. No console password; a customer secret key only."
}

resource "oci_identity_user" "audit_shipper" {
  compartment_id = var.oci_tenancy_ocid
  name           = "dmc-audit-shipper"
  description    = "Service identity for the app's AUDIT_S3_* audit:ship credential."
}

resource "oci_identity_user_group_membership" "backup_shipper_in_group" {
  user_id  = oci_identity_user.backup_shipper.id
  group_id = oci_identity_group.backup_writers.id
}

resource "oci_identity_user_group_membership" "audit_shipper_in_group" {
  user_id  = oci_identity_user.audit_shipper.id
  group_id = oci_identity_group.audit_writers.id
}

# Customer secret keys are the S3-compatible credential the backup/audit shippers actually use
# (BACKUP-AND-RESTORE.md §2.3: "S3_ACCESS_KEY=<customer secret key id>"). The secret half is
# generated by OCI at creation and retrievable from Terraform state exactly once in practice
# (OCI itself never shows it again) — treat the corresponding output as the single handoff point
# to the owner's escrow process (BACKUP-AND-RESTORE.md §2.2 already requires escrow for the
# backup encryption key; the same discipline applies here), never logged or printed elsewhere.
resource "oci_identity_customer_secret_key" "backup_shipper_key" {
  user_id     = oci_identity_user.backup_shipper.id
  display_name = "dmc-backup-shipper-key"
  # TODO: verify this resource name/schema (oci_identity_customer_secret_key) against the
  # provider docs before first plan — confirm the secret is genuinely retrievable via `key` /
  # similar attribute and how it is marked sensitive in the provider's own schema.
}

resource "oci_identity_customer_secret_key" "audit_shipper_key" {
  user_id     = oci_identity_user.audit_shipper.id
  display_name = "dmc-audit-shipper-key"
}

resource "oci_identity_policy" "backup_writers_policy" {
  compartment_id = var.oci_tenancy_ocid
  name           = "dmc-backup-writers-policy"
  description    = "Least-privilege: manage objects only in ${var.backup_bucket_name}, only for the backup-writers group."
  statements = [
    "Allow group ${oci_identity_group.backup_writers.name} to manage objects in compartment id ${var.oci_compartment_id} where target.bucket.name = '${var.backup_bucket_name}'",
    "Allow group ${oci_identity_group.backup_writers.name} to read buckets in compartment id ${var.oci_compartment_id} where target.bucket.name = '${var.backup_bucket_name}'",
  ]
  # TODO: verify OCI policy-language syntax (the `where target.bucket.name = '...'` variable
  # form, and whether "manage objects" is the right verb+resource-type pairing for PUT+HEAD+GET
  # without also granting delete) against current OCI IAM policy reference docs before first
  # plan — no policy statement text is quoted anywhere in the repo (infra/README.md item 6); this
  # is a best-effort least-privilege pattern, not a captured fact.
}

resource "oci_identity_policy" "audit_writers_policy" {
  compartment_id = var.oci_tenancy_ocid
  name           = "dmc-audit-writers-policy"
  description    = "Least-privilege: manage objects only in ${var.audit_bucket_name}, only for the audit-writers group."
  statements = [
    "Allow group ${oci_identity_group.audit_writers.name} to manage objects in compartment id ${var.oci_compartment_id} where target.bucket.name = '${var.audit_bucket_name}'",
    "Allow group ${oci_identity_group.audit_writers.name} to read buckets in compartment id ${var.oci_compartment_id} where target.bucket.name = '${var.audit_bucket_name}'",
  ]
}
