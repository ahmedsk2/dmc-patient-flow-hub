# Confirmed facts — compliance drafts

> **The single source of truth for filling the nine PDPL drafts.** Every row here was **confirmed by
> the system owner** in session on **2026-09-03**, or **verified against the live system** on that date
> (read-only). Fill the drafts only from this file. Three tags are used:
> **CONFIRMED** (owner-stated or system-verified fact), **PLACEHOLDER** (a real value still owed, kept
> bracketed in the drafts), **DECISION** (a call reserved for counsel/DPO/governance). Legal citations
> live in [`PROPOSED-CITATIONS.md`](PROPOSED-CITATIONS.md) and stay `[PROPOSED]` until counsel signs off.

## A. Parties

| # | Fact | Value | Tag |
|---|---|---|---|
| A1 | Controller | **Dammam Medical Complex** (مجمع الدمام الطبي), Dammam, Eastern Province — under the **Eastern Health Cluster** (تجمع الشرقية الصحي), a subsidiary of the **Saudi Health Holding Company** (شركة الصحة القابضة). Formerly Ministry of Health. | CONFIRMED |
| A2 | Which entity registers with SDAIA / signs as controller | Eastern Health Cluster acting for DMC, per owner; the exact registering entity and whether it is a "Public Entity" under the PDPL | DECISION — counsel |
| A3 | Processor (primary) | **The developer's company** — owns the code, the OCI server and the domain; hosts, operates, develops and supports the hub, and can access the data. DMC staff use the site. | CONFIRMED |
| A4 | Processor company legal identity | [COMPANY LEGAL NAME, Commercial Registration No., city] | PLACEHOLDER |
| A5 | Controller–processor contract | **None exists yet.** Signing one (with Implementing Regulation Art. 17 minimum content [PROPOSED]) is the **top action item**. | CONFIRMED (gap) |
| A6 | Data Protection Officer | **Not appointed yet.** Appointment is treated as mandatory (core activity = sensitive health data; IR Art. 32(1)(c) [PROPOSED]). | CONFIRMED (gap) |
| A7 | Information-security lead & system owner | The developer/owner (processor side) | CONFIRMED |
| A8 | Clinical data owner | Head of Internal Medicine, DMC — [NAME] | PLACEHOLDER |
| A9 | Staff-data owner | DMC hospital administration — [NAME/OFFICE] | PLACEHOLDER |
| A10 | Approving body | DMC clinical governance / quality committee — [NAME] | PLACEHOLDER |

## B. Systems and hosting

| # | Fact | Value | Tag |
|---|---|---|---|
| B1 | **Daily production system** | The **legacy PHP app at `https://www.dmc-im.com`** — hosted on **SiteGround shared hosting (United States)**. This is what staff use today. | CONFIRMED |
| B2 | Live legacy build state (verified 2026-09-03, read-only) | The live site is the **ORIGINAL un-hardened build**, not the `renovation`-branch hardened one: no CSRF token, no `css/app.css`, innovia branding, no CSP/HSTS/X-Frame headers, session cookie without Secure/HttpOnly, apex redirects to plain HTTP. All original-review systemic defects (unauthenticated patient read/write/delete, SQL injection, admin self-registration, plaintext secrets) are **live over real PHI on a US host**. | VERIFIED |
| B3 | Go-forward system | The **Laravel app at `https://dmc-new.towardpcc.com`** — live, holding a parallel copy of the data (~17k patients), on OCI Riyadh. Runs **in parallel until cutover**; the plan is to replace the dmc-im.com code with it. | CONFIRMED |
| B4 | Hosting processor — OCI | **Oracle Systems Limited** (Saudi contracting entity; Cloud Services Agreement v062223 [PROPOSED]). Company-held pay-as-you-go tenancy, Basic support, **Riyadh region `me-riyadh-1` only**, Oracle-managed AES-256 volume encryption (default). Oracle's **global** support staff may access the tenancy under Oracle's policies. | CONFIRMED |
| B5 | Edge processor — Cloudflare | **Cloudflare, Inc.** (US). **Free plan.** Standard Customer DPA v6.4 applies by reference [PROPOSED]; security-event logs kept ~24 h, analytics ~7 days. **Regional Services (in-Kingdom TLS termination) is NOT available on Free** (Enterprise-only). Saudi points of presence: Dammam, Jeddah, Riyadh. | CONFIRMED |
| B6 | Mail processor — SMTP relay (verified 2026-09-03) | Production sends via `mail.dmc-im.com:587` STARTTLS, from `info@dmc-im.com`. Host resolves into a Google-Cloud range; the domain's MX is SiteGround's SpamExperts → the relay is the **dmc-im.com mailbox on SiteGround (United States)**, company-held. Carries staff e-mail, OTP codes, reset links and the **aggregate** monthly PDF (no patient-level data). | VERIFIED |
| B7 | Shared host observation | The OCI host also runs unrelated databases (`endorsement`, `qch`) → it is a **shared multi-project host**, relevant to isolation in the classification scheme. | VERIFIED |
| B8 | Source control | GitHub `ahmedsk2/dmc-patient-flow-hub` is **PUBLIC** (owner decision, time-boxed — see D3). Secret scanning + push protection on; sole collaborator; 2FA enabled (owner statement). History holds **no** dumps, `.env`, logs or origin IP; infrastructure identifiers (Coolify uuid, bucket names) are in tracked docs. | CONFIRMED / VERIFIED |

## C. Data, retention, security posture (verified from prod, read-only, 2026-09-03)

| # | Fact | Value | Tag |
|---|---|---|---|
| C1 | Hub = part of the medical record? | **Yes — a supplementary part of the medical record.** Clinical rows follow the medical-record retention period once counsel obtains it; clinical governance to confirm in writing. | CONFIRMED (governance to minute) |
| C2 | Medical-record retention period | Governing instrument: Private Health Institutions Law Executive Regulations (Min. Resolution 1019377, 28/5/1439H) Art. 3/3 → Ministry Annex No. 5 [PROPOSED]; the actual period (commonly cited as ten years) is **unverified**. | DECISION — counsel / HIM office |
| C3 | Queue driver | `sync` — no queued PDF bytes persist in the `jobs` table. | VERIFIED |
| C4 | Backups | Live: nightly encrypted off-box dump to in-Kingdom OCI bucket `dmc-db-backups`; encrypted copies present in `/var/backups/dmc/`. Retention 90 days is a legal placeholder. | VERIFIED |
| C5 | Encryption at rest | **Partial.** Four narrative columns encrypted under `APP_KEY`; all other columns rely on OCI's default AES-256 volume encryption. | VERIFIED |
| C6 | Monthly report PDF | **Aggregate-only** (template carries no MRN or name); the governance PDF does carry identifiers. | VERIFIED |
| C7 | Authenticated-page caching | `no-store` on authenticated pages. | VERIFIED |
| C8 | Transport (Laravel app) | Cloudflare min TLS 1.2 + HSTS; SMTP STARTTLS on 587. | VERIFIED |
| C9 | Break-glass logging | `log_record_opens` = **0 (OFF)** in production; enabling it is a governance decision. | VERIFIED |
| C10 | Audit retention | `audit_retention_years` = 6 (internal choice; no sector rule found requiring six — ECC baseline is ≥12 months [PROPOSED]). | VERIFIED |
| C11 | Session timeout | Idle 30 min; absolute off; trusted-device 24 h. | VERIFIED |
| C12 | Export auditing gap | **Registry exports are audited; statistics exports, audit-log exports and report PDFs are NOT.** Confirmed engineering gap. | VERIFIED |
| C13 | Export labelling gap | No classification label on exports or printed sheets. | VERIFIED |

## D. PHI-copy inventory and standing decisions

| # | Fact | Value | Tag |
|---|---|---|---|
| D1 | Legacy PHI copies | (a) **Live legacy DB on SiteGround (US)** behind dmc-im.com; (b) owner workstation `Downloads/`: 7× `dbqeqbacgfvmhk*.sql` legacy dumps + `dmc_laravel_export.sql(.gz)` + `_recon.sql` (Jun–Aug 2026); (c) OCI host `/home/ubuntu/migrate/dmc/`: plaintext `dmc_demo.sql.gz` + a pre-refresh dump + leftover key files (`dbpw.env`, `deploykey`, `env.orig`); (d) encrypted backups in `/var/backups/dmc/`. Inventory + destruction schedule owed. | VERIFIED (gap) |
| D2 | Legacy site plan | Replace the dmc-im.com code with the Laravel app at cutover (same domain). Legacy stays live until then. | CONFIRMED |
| D3 | GitHub repo visibility | **Keep PUBLIC until development finishes** so CI runs free; **make private before go-live / compliance sign-off**. Accepted, time-boxed risk (no secrets/PHI/origin-IP exposed; gitleaks + push protection in place). | DECISION — owner |
| D4 | Classification tier for patient data | Draft assumes **Secret**; NDMO-derived scheme suggests ordinary patient-identifying data maps to **Restricted** [PROPOSED, MEDIUM]. Kept at Secret as a deliberate stricter stance pending NDMO's own policy. | DECISION — security lead / counsel |
| D5 | Dates / review | Drafts v0.1 dated 2026-09-03; approval date blank until sign-off; **annual** review or on material change → next review 2027-09-03; review owner = DPO once appointed, owner interim. | CONFIRMED |
| D6 | Data-subject request channel | Patients: DMC Medical Records / Health Information Management office (verifies identity, holds full record); staff: hospital administration; escalation + complaints: the DPO once appointed. Contact details = PLACEHOLDER. | CONFIRMED |

## E. Legal-position corrections (all [PROPOSED] — see PROPOSED-CITATIONS.md)

- **Legal basis for health data:** PDPL Art. 6 has **no** stand-alone health-service-provider consent exception; the basis is likely Art. 6(2) (another law / prior agreement) plus the IR Art. 26 health-data controls. The privacy-notice legal-basis sentence must be reworded.
- **Breach notification:** IR Art. 24 — notify SDAIA within **72 hours of becoming aware**; phased/supplemented notice allowed with justification; notify affected subjects **without undue delay**; NCA duties preserved.
- **Breach definition:** reconcile the draft's GDPR-style wording with PDPL Art. 20 / IR Art. 1(3).
- **DPIA, DPO, National Register:** all effectively mandatory here (sensitive-data processing).
- **ROPA:** kept five years after processing ends (IR Art. 33).
- **Transfers:** no SDAIA adequacy list → SCCs/BCR + an Art. 7 risk assessment for the US relay; in-Kingdom foreign-edge TLS termination is unaddressed by any SDAIA text (open question).
- **NCA ECC/DCC/CCC:** bind government + CNI; a private controller is only encouraged unless designated — but DMC's public-sector ownership (A1) may pull it into scope. Counsel to confirm.
