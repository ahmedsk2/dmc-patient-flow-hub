# infra/cloudflare.tf — the proxied DNS record + zone security settings for dmc-new.towardpcc.com.
#
# UNVALIDATED HCL, pinned to the Cloudflare provider v4 resource shape — see versions.tf and
# infra/README.md item 9. The v5 provider renamed cloudflare_record -> cloudflare_dns_record and
# split cloudflare_zone_settings_override into per-setting resources; confirm the provider major
# version actually in use before trusting the resource names below.

provider "cloudflare" {
  # api_token is read from the CLOUDFLARE_API_TOKEN environment variable by provider convention.
  # Deliberately not set as a .tf variable here — see variables.tf's comment on why no
  # cloudflare_api_token variable exists.
}

# The public, proxied (orange-cloud) A record. DEPLOY-LARAVEL.md §0: "a proxied (orange-cloud)
# Cloudflare record. The origin's ports 80/443 accept only Cloudflare's IP ranges, so any
# additional hostname must also be proxied or it will not connect at all."
resource "cloudflare_record" "app" {
  zone_id = var.cloudflare_zone_id
  name    = var.app_hostname
  type    = "A"

  # Wired to the instance's own computed public IP when this configuration provisions the host,
  # so the real origin IP is never hand-typed into version control (DEPLOY-LARAVEL.md §0:
  # "The origin IP is deliberately not written here"). var.app_origin_ip exists only for the
  # import-against-an-existing-instance path and must be supplied out-of-band if used.
  content = coalesce(var.app_origin_ip, oci_core_instance.app_host.public_ip)

  proxied = true # orange-cloud — mandatory: the origin firewall only admits Cloudflare's ranges (oci.tf)
  ttl     = 1    # "Auto" TTL, required by the provider when proxied = true

  # TODO: verify current argument names (cloudflare_record vs cloudflare_dns_record; `content` vs
  # `value` — this also changed between provider minor versions) against the provider docs before
  # first plan.
}

# Zone-level security settings. Facts used:
#   - minimum TLS 1.2 + HSTS: CONFIRMED-FACTS.md B5/C8, CLAUDE.md §9
#   - SSL/TLS mode "strict" (Full Strict): DEPLOY-LARAVEL.md §9 step 1
#   - Free plan, so Regional Services / in-Kingdom TLS termination is NOT available
#     (CONFIRMED-FACTS.md B5, ADR 0007) — not modelled here because it cannot be enabled on this
#     plan; noted so a future upgrade decision is not silently lost.
resource "cloudflare_zone_settings_override" "dmc" {
  zone_id = var.cloudflare_zone_id

  settings {
    ssl             = "strict"           # DEPLOY-LARAVEL.md §9 step 1: "SSL mode strict"
    min_tls_version = var.min_tls_version # "1.2" — CONFIRMED-FACTS.md B5/C8
    always_use_https = "on"
    automatic_https_rewrites = "on"

    security_header {
      enabled = true
      # HSTS is also set by the app itself (SecurityHeaders middleware, CLAUDE.md §5) — enabling
      # it at the zone level too is redundant-but-documented ("Cloudflare min TLS 1.2 + HSTS",
      # CONFIRMED-FACTS.md C8). Sub-settings (max_age, includeSubDomains, preload) are not stated
      # anywhere in the repo at the zone-settings level (only the app's own header is quoted in
      # CLAUDE.md §9) — left at provider defaults rather than invented.
      # TODO: verify the security_header sub-block's required arguments against the provider
      # docs before first plan; some provider versions require max_age to be set explicitly for
      # `enabled = true` to take effect.
    }
  }

  # TODO: verify cloudflare_zone_settings_override's exact settings{} argument names for the
  # pinned provider version before first plan — this resource in particular has had fields
  # renamed/deprecated across Cloudflare provider releases (e.g. tls_1_3, universal_ssl).
}
