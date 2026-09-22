# infra/outputs.tf — identifiers useful to an operator adopting this configuration.
#
# Deliberately excludes the origin IP as plain output text (DEPLOY-LARAVEL.md §0 treats it as
# something the Cloudflare proxy deliberately hides) even though Terraform itself knows it as a
# resource attribute — an operator who needs it can read it from `terraform show` deliberately,
# but it is not surfaced by default the way a "helpful" output list normally would.

output "vcn_id" {
  description = "OCID of the DMC VCN."
  value       = oci_core_vcn.dmc.id
}

output "public_subnet_id" {
  description = "OCID of the public subnet holding the app/DB host."
  value       = oci_core_subnet.dmc_public.id
}

output "backup_bucket_name" {
  description = "The off-box backup bucket name (BACKUP-AND-RESTORE.md)."
  value       = oci_objectstorage_bucket.db_backups.name
}

output "audit_bucket_name" {
  description = "The audit archive bucket name (ADR 0003)."
  value       = oci_objectstorage_bucket.audit_log.name
}

output "objectstorage_namespace" {
  description = "The OCI Object Storage namespace, needed to build the S3-compatible endpoint URL documented in BACKUP-AND-RESTORE.md §2.3 (https://<namespace>.compat.objectstorage.me-riyadh-1.oraclecloud.com)."
  value       = data.oci_objectstorage_namespace.ns.namespace
}

output "app_hostname" {
  description = "The public hostname."
  value       = var.app_hostname
}

# --- Sensitive: handle exactly like every other secret in this system (CLAUDE.md §2) -----------
# These are the customer secret keys the backup and audit shippers use as S3_ACCESS_KEY/S3_SECRET
# and AUDIT_S3_ACCESS_KEY/AUDIT_S3_SECRET respectively. Terraform marks them sensitive so they are
# redacted from ordinary plan/apply console output, but `terraform output` can still print them on
# request — the operator must escrow them the same way BACKUP-AND-RESTORE.md §2.2 already requires
# for the backup encryption key, and must never paste them into chat, a commit, or an issue.

output "backup_shipper_access_key_id" {
  description = "Customer secret key ID for the backup-writers service user. Not itself secret (it is an identifier), but pair it only with its secret below."
  value       = oci_identity_customer_secret_key.backup_shipper_key.id
}

output "backup_shipper_secret" {
  description = "Customer secret key SECRET for the backup shipper. Retrieve once, put directly into /root/.dmc-backup.env's S3_SECRET (BACKUP-AND-RESTORE.md §2.3), then escrow and treat as compromised if it is ever displayed anywhere else."
  value       = oci_identity_customer_secret_key.backup_shipper_key.key
  sensitive   = true
}

output "audit_shipper_access_key_id" {
  description = "Customer secret key ID for the audit-writers service user."
  value       = oci_identity_customer_secret_key.audit_shipper_key.id
}

output "audit_shipper_secret" {
  description = "Customer secret key SECRET for the audit shipper. Goes into the app's AUDIT_S3_SECRET runtime env var in Coolify (DEPLOY-LARAVEL.md §5), never into a file this repo tracks."
  value       = oci_identity_customer_secret_key.audit_shipper_key.key
  sensitive   = true
}
