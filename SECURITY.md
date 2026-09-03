# Security Policy — DMC Internal Medicine Patient-Flow Hub

This application runs a live hospital unit's patient flow and holds protected health
information (PHI). We take reports seriously, and we ask researchers to take the data just as
seriously: **never test against the production system.** Build a local instance from this
repository instead (see `laravel/README.md`) — it needs no real patient data.

## Supported versions

| Version | Supported |
|---|---|
| `main` — the deployed Laravel application under `laravel/` | Yes. All security fixes land here first. |
| `renovation` — the hardened legacy PHP app kept as a rollback target | Security fixes only, while it remains a deployable fallback. |
| Any other branch, tag or fork | No. |

## Reporting a vulnerability

- **Email:** the monitored address published machine-readably at
  `https://dmc-new.towardpcc.com/.well-known/security.txt` (RFC 9116; a mailbox on the unit's
  `dmc-im.com` domain, sourced from the `SECURITY_CONTACT` environment variable so it can be rotated
  without a code change). That file is the single source of truth for the contact; this policy
  deliberately does not repeat the address.
- Do **not** open a public GitHub issue, discuss the finding publicly, or share it with third
  parties before a fix is deployed.
- No PGP key is published at this time. If your report contains sensitive detail, say so in a
  first plain email and we will arrange an encrypted channel.
- Please include: a description, the affected URL or component, reproduction steps or a proof of
  concept, the impact as you understand it, and how to reach you.

### What we ask of you

- Test only against your **own local instance**. The production system serves real patients;
  probing it is not authorised.
- If you encounter PHI anyway (for example in a screenshot or a response), **stop**, do not save,
  copy or forward it, and tell us in your report so we can assess the exposure.
- No denial of service, no social engineering of staff, no physical intrusion, and no automated
  scanning of the live system.

## Response targets

| Step | Target |
|---|---|
| Acknowledge receipt | 3 business days |
| Triage and severity assessment | 7 business days |
| Fix or mitigation — Critical (PHI exposure, authentication bypass, remote code execution) | 7 days |
| Fix or mitigation — High | 30 days |
| Fix or mitigation — Medium / Low | Next scheduled release (target 90 days) |

We will keep you informed of progress, tell you when the fix is deployed, and credit you in the
release notes if you wish. Coordinated disclosure after the fix (or after 90 days, whichever is
sooner) is welcome — please agree the timing with us first.

## No bug bounty

This is a hospital unit's internal system, not a commercial product. We do not run a bug bounty
programme and cannot offer monetary rewards. We are grateful for reports all the same.

## Safe harbour

Research carried out in good faith and in line with this policy — on your own instance, without
accessing, retaining or disclosing PHI, without disrupting service, and reported to us promptly
and privately — is authorised. We will not pursue or support legal action against you for it,
and if a third party raises action over research that complied with this policy, we will make
clear that it was authorised. This statement is made by the project maintainers: it cannot
override applicable law or bind third parties (hosting or network providers, or the hospital's
own governance), and it does not extend to testing of the live system.

## Scope

**In scope:** the Laravel application in `laravel/` (web routes, authentication and MFA, the
audit-log integrity chain, exports and reports), and the legacy PHP application while it remains
a supported fallback.

**Out of scope:** third-party services in front of or beside the app (CDN and TLS termination,
the hosting platform, the email relay), volumetric denial of service, findings that require a
compromised or rooted device, and reports produced solely by automated scanners without a
demonstrated impact.
