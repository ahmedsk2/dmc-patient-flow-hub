# infra/versions.tf — Terraform and provider version pins.
#
# UNVALIDATED: Terraform is not installed in the environment that wrote this. Nothing here has
# been through `terraform init`. Version constraints below are conservative, documented-behaviour
# pins, not the result of testing against a live provider registry — confirm current major
# versions before first init.

terraform {
  required_version = ">= 1.6, < 2.0"

  required_providers {
    # OCI: compute, networking, object storage, IAM. Region/tenancy come from variables, never
    # hardcoded here (see variables.tf).
    oci = {
      source  = "oracle/oci"
      version = "~> 5.0" # TODO: verify current major against the OCI provider's changelog before first plan
    }

    # Cloudflare: the proxied DNS record + zone security settings for dmc-new.towardpcc.com.
    # Pinned to the v4 resource shape (cloudflare_record, cloudflare_zone_settings_override).
    # The v5 provider renamed several resources (e.g. cloudflare_record -> cloudflare_dns_record).
    # infra/README.md item 9 and cloudflare.tf both flag this — verify before first plan.
    cloudflare = {
      source  = "cloudflare/cloudflare"
      version = "~> 4.0" # TODO: verify against the provider version actually available before first plan
    }

    # Used only to fetch Cloudflare's published IP ranges at plan/apply time, so the OCI security
    # list never hand-copies a list that can go stale (infra/README.md item 4).
    http = {
      source  = "hashicorp/http"
      version = "~> 3.4"
    }
  }

  # No backend block: state is local by default, which is NOT an acceptable long-term choice for
  # a live clinical system's infrastructure (see infra/README.md "State"). Before any real
  # adoption, add a remote backend here (e.g. an OCI Object Storage bucket with SSE, or Terraform
  # Cloud) — the owner has not yet decided which, so none is invented here.
  # backend "s3" { ... }  # OCI Object Storage is S3-compatible; TODO once the owner decides.
}
