# Processor register, DPA checklist and cross-border transfers

> **DRAFT — for review by the hospital's legal / data-protection officer; not legal advice.**
>
> Scope: every third party that touches data on behalf of the DMC Internal Medicine Patient-Flow Hub
> (“the hub”). Version 0.1-draft, 2026-09-03. Facts about the system are as built on the draft date
> (hosting, edge network, email, source control) and must be re-verified on any infrastructure change.
> Square-bracketed markers are open items; `[VERIFY ARTICLE]` / `[VERIFY]` mean the obligation is
> stated in words and the exact provision of the Personal Data Protection Law (“PDPL”), its
> Implementing Regulations or the Data Transfer Regulations must be cited by counsel.

---

## 1. Processor register (record of processing — processors and transfers)

| # | Processor | Legal entity / HQ | Service to the hub | Data it can see | Data subjects | Where processed | Leaves the Kingdom? | Contract in place? | Safeguard for any transfer | TRA needed? | Owner | Status |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| P1 | **Oracle Cloud Infrastructure (OCI)** | Oracle [ENTITY — PLACEHOLDER]; HQ United States | Compute, block storage, database host, backups, audit-archive object storage | Everything: patient identifiers, admission episodes, consultations, handover notes, staff accounts, audit log, backups | Patients, staff | Riyadh region, Saudi Arabia (in-Kingdom) | **No** by design — confirm no cross-region replication and how remote support access works [VERIFY] | [YES / NO — PLACEHOLDER] Oracle cloud services agreement + Oracle data-processing terms [VERIFY current documents] | Not a transfer if processing stays in-Kingdom; document support-access position | Only if support access from abroad is confirmed | IT lead | OPEN |
| P2 | **Cloudflare** | Cloudflare, Inc.; HQ United States | DNS, reverse proxy / WAF, TLS termination at the edge | Decrypted HTTP traffic **in transit**: page payloads that can contain patient identifiers and clinical fields, session cookies, staff identities. Request metadata (IP, URL, headers, timing) in Cloudflare logs/analytics. Patient identifiers are never in URLs by design, so metadata carries no PHI. | Patients (via page content), staff | Cloudflare edge; PoP observed serving the hub is in-Kingdom, **not contractually guaranteed** | **Potentially yes** — a non-Saudi processor decrypts the traffic; edge location is not fixed by contract | [YES / NO — PLACEHOLDER] Cloudflare self-serve terms + Cloudflare Data Processing Addendum [VERIFY current version and whether the plan tier allows negotiation] | Requires a decision — see §5.2 | **Yes** — completed in §5.2 (template) | DPO + IT lead | OPEN |
| P3 | **Transactional SMTP relay** | [PROVIDER NAME / ENTITY — PLACEHOLDER]; hosted in the United States | Outbound email: one-time codes, password-reset links, username reminders, monthly statistics PDF | Staff email addresses and names, OTP codes, signed reset links, **aggregate** monthly figures (no patient-level data by design). Provider may retain message logs / bodies per its own policy [VERIFY]. | Staff only | United States | **Yes** (staff personal data) | [YES / NO — PLACEHOLDER] provider terms + DPA [VERIFY] | Standard contractual clauses / appropriate safeguards under the Data Transfer Regulations [VERIFY] — or eliminate the transfer (§5.3) | Yes (low volume, staff data only) | IT lead | OPEN |
| P4 | **GitHub** | GitHub, Inc. (Microsoft); HQ United States | Source-code hosting, CI (GitHub Actions) | Source code, CI logs, developer identities (commit names / emails). **No patient data** by policy; CI uses a synthetic test database. | Developers | United States / global | Yes (developer identities only) | GitHub terms + GitHub DPA [VERIFY] | Minimal — confirm no personal data beyond developer identities; see §5.4 | Low — confirm | Dev lead | OPEN |

No other third party receives data from the hub. The hub has no analytics, tracking, AI, payment or
messaging integrations.

## 2. What the PDPL expects of a controller that uses processors (in words)

The following is the obligation set as generally described in the PDPL and its Implementing Regulations;
every row must be tied to its provision by counsel [VERIFY ARTICLE].

| # | Obligation | How the hub meets it |
|---|---|---|
| C1 | Choose processors that give **sufficient guarantees** of protecting personal data, and verify them. | Security posture evidence (certifications, DPA, region controls) filed per processor in §5. |
| C2 | Have a **written contract** with each processor that fixes the purpose, scope, duration, categories of data and data subjects, the processor's obligations, and the controller's rights. | DPA checklist in §4. |
| C3 | The processor must act **only on the controller's documented instructions** and must not use the data for its own purposes. | DPA clause; configuration review (e.g. Cloudflare analytics, SMTP provider logging). |
| C4 | The controller stays **responsible** for the processing and must be able to **audit / verify** the processor's compliance. | Audit / assurance-report clause; annual review in DPO duties. |
| C5 | **Sub-processors** only with the controller's knowledge / approval; the same obligations flow down. | Sub-processor list clause; notification of changes. |
| C6 | **Security** measures appropriate to sensitive data; **breach notification** to the controller without undue delay. | DPA clause; the hospital's own notification duty to the authority runs from its awareness [VERIFY period]. |
| C7 | **Assistance** with data-subject requests, impact assessments and authority enquiries. | DPA clause. |
| C8 | **Return / destruction** of data at the end of the service, with certification. | DPA clause + exit plan (OCI: export + verified deletion; SMTP: purge logs). |
| C9 | Where data is transferred **outside the Kingdom**, the transfer must satisfy the Data Transfer Regulations (see §3). | §5 per processor. |

## 3. Transfers outside the Kingdom — what the rules require (in words)

The PDPL restricts transferring or disclosing personal data outside the Kingdom. The Data Transfer
Regulations, as generally described, allow a transfer only where **all** of the following hold
[VERIFY ARTICLE / VERIFY the current text of the regulations]:

1. **A permitted purpose** — for example performing an agreement to which the data subject is party,
   fulfilling an obligation the controller is subject to, or another purpose the regulations list.
2. **A lawful mechanism** — either the destination is on the competent authority's list of countries or
   sectors offering an **adequate** level of protection, or **appropriate safeguards** are in place:
   standard contractual clauses issued or approved by the authority, binding common rules within a
   corporate group, or a recognised certification / code of conduct. Narrow exceptions exist for
   specific situations (e.g. protecting a person's vital interests) [VERIFY].
3. **Minimisation** — only the minimum data necessary for the purpose is transferred.
4. **A transfer risk assessment** in the cases the regulations specify (commonly described as
   transfers under appropriate safeguards / where sensitive data is involved / where the transfer is
   continuous or large-scale) [VERIFY when a TRA is mandatory], kept on file and reviewed.
5. The transfer must not **prejudice national security or vital interests** of the Kingdom.

**Health-sector overlay.** Separately from the PDPL, health facilities may be subject to Ministry of
Health / national health-data rules on where health data may be stored and processed
[NEEDS LEGAL CONFIRMATION — confirm whether any sector-specific localisation requirement applies to
this hospital and, if so, whether edge-only decryption by a foreign processor is compatible with it].

**Working rule for the hub:** patient data is stored and processed only in the Kingdom (OCI Riyadh).
The Cloudflare edge is the single point where a non-Saudi processor decrypts traffic containing patient
data; it is therefore the transfer to decide on (§5.2). Staff-only email leaves the Kingdom today
(§5.3). GitHub holds code only (§5.4).

## 4. DPA checklist (apply to every processor)

Mark each cell **Y** (present and acceptable), **N** (absent), **?** (needs reading), or **n/a**.

| # | Clause the DPA / contract must contain | OCI | Cloudflare | SMTP relay | GitHub |
|---|---|---|---|---|---|
| D1 | Parties, roles (controller / processor), governing law, and reference to the PDPL. | ? | ? | ? | ? |
| D2 | Subject matter, duration, nature and purpose of the processing; categories of data and of data subjects — matching §1. | ? | ? | ? | ? |
| D3 | Processing only on documented instructions; no use for the processor's own purposes (incl. product analytics / model training). | ? | ? | ? | ? |
| D4 | Confidentiality of personnel with access. | ? | ? | ? | ? |
| D5 | Security measures appropriate to **sensitive** data (encryption in transit and at rest, access control, logging). | ? | ? | ? | n/a |
| D6 | Personal-data breach notification to the hospital — timeline, content, contact. | ? | ? | ? | ? |
| D7 | Sub-processor list, prior notice of changes, flow-down of obligations. | ? | ? | ? | ? |
| D8 | Assistance with data-subject requests, DPIAs and authority enquiries. | ? | ? | ? | ? |
| D9 | Audit / assurance rights (reports, certifications, right to verify). | ? | ? | ? | ? |
| D10 | **Location of processing** — named region(s); prohibition on moving data elsewhere without consent. | ? | ? | ? | n/a |
| D11 | **Cross-border transfer mechanism** — SCCs / approved clauses under the Data Transfer Regulations [VERIFY], plus a completed TRA on file. | n/a if in-Kingdom | ? | ? | ? |
| D12 | Log / metadata retention periods and where logs are stored. | ? | ? | ? | ? |
| D13 | Return / deletion at termination, with certification; backup expiry. | ? | ? | ? | ? |
| D14 | Liability, indemnity, insurance appropriate to health data. | ? | ? | ? | ? |
| D15 | Signed by an authorised signatory of the hospital; filed with the processor register. | ? | ? | ? | ? |

## 5. Per-processor sheets

### 5.1 Oracle Cloud Infrastructure (OCI) — hosting

| Item | Position |
|---|---|
| What runs there | Application server, MySQL database, file storage, scheduled jobs, backups, the S3-compatible object-storage bucket that receives the shipped audit-log archive. |
| Data | All hub data (patients + staff), full backups, audit archive. |
| Location | Riyadh region (in-Kingdom). Confirm the tenancy's **home region** and that **no cross-region replication / backup copies** are configured for the compute volumes, database backups or the audit bucket [VERIFY]. |
| Encryption | In transit: TLS 1.2+. At rest: platform-level volume encryption is a feature of the provider [VERIFY what is enabled on this tenancy and who holds the keys]; application/database-level encryption of stored PHI is **being introduced** — track the roll-out date [PLACEHOLDER]. |
| What to sign | Oracle cloud services agreement / ordering document + Oracle's data-processing terms for cloud services [VERIFY current titles and that they cover the PDPL]. |
| Transfer analysis | Processing is in-Kingdom, so no transfer for storage/processing. **Residual question:** Oracle support and operations personnel may access customer environments from outside the Kingdom under Oracle's policies — obtain Oracle's written statement and decide whether that constitutes a disclosure requiring safeguards [VERIFY] [NEEDS LEGAL CONFIRMATION]. |
| Required safeguards (in words) | Written DPA (§4); named region; access-control and logging commitments; breach notification; sub-processor transparency; deletion at exit. |
| Owner | IT lead |
| Status | OPEN — collect the signed agreement, region confirmation, support-access statement, key-management confirmation. |

### 5.2 Cloudflare — DNS, WAF, edge TLS termination

**Transfer-risk assessment (template — pre-filled with the position as built)**

| Field | Assessment |
|---|---|
| Transfer description | The hub's hostname is proxied through Cloudflare (“orange-cloud”). Browser TLS sessions terminate at a Cloudflare edge data centre, which decrypts each request/response, applies security rules, and re-encrypts to the origin in OCI Riyadh. |
| Exporter / importer | Exporter: [HOSPITAL LEGAL NAME] (controller). Importer: Cloudflare, Inc. (processor), United States, operating a global network. |
| Data categories in scope | Page and API payloads while decrypted at the edge: patient identifiers (MRN, name, age, gender, nationality), admission and consultation fields, handover notes, staff identities and session cookies. Metadata retained in Cloudflare logs/analytics: client IP, URL path, headers, timing, security-rule verdicts — **no PHI**, because patient identifiers never appear in URLs and authenticated responses carry `Cache-Control: no-store`, so the edge does not cache them. |
| Data subjects | Patients of the Internal Medicine unit; hospital staff. |
| Volume / frequency | Continuous; every request to the hub. Sensitive data on most authenticated pages. |
| Duration of processing at the edge | Momentary (in memory for the life of the request). No storage of payloads for the hub's purpose [VERIFY against Cloudflare's DPA and logging documentation for the plan in use]. |
| Purpose and necessity | DDoS / WAF protection, TLS, origin IP concealment. Necessary for security; **not** necessary that decryption happen outside the Kingdom. |
| Destination and law | Cloudflare's edge nearest the user — observed in-Kingdom, but selection is automatic and can fail over abroad. Importer is a US company subject to US law, including lawful-access requests [VERIFY Cloudflare's transparency and government-request commitments]. |
| Existing contractual safeguards | Cloudflare's standard terms and Data Processing Addendum (self-serve plans) [VERIFY current version; whether it is negotiable at the plan tier; whether it contains clauses acceptable under the Saudi Data Transfer Regulations or must be supplemented with authority-approved standard contractual clauses]. |
| Existing technical safeguards | TLS 1.2+ end-to-end; origin firewall admits Cloudflare ranges only [VERIFY]; PHI never in URLs; no caching of authenticated responses; Cloudflare account protected by [MFA / SSO — VERIFY]. |
| Risks identified | (1) Decryption by a foreign processor of health data, potentially outside the Kingdom on failover — likelihood: medium; impact: high. (2) Lawful-access request to the importer — likelihood: low; impact: high. (3) Metadata (IPs, paths) stored outside the Kingdom — likelihood: high; impact: low (no PHI). (4) Plan-level DPA not aligned with PDPL transfer mechanism — likelihood: medium; impact: medium (compliance). |
| Mitigation options | **A. Regional Services (Data Localization Suite).** Cloudflare's feature that pins TLS termination — the point at which traffic is decrypted — to a chosen region; requests arriving elsewhere are forwarded encrypted to that region. Available on Enterprise / Data Localization Suite plans; regions are Cloudflare-managed keys (e.g. `eu`, `us`) or account-specific custom regions arranged through the account team. **Confirm whether a managed or custom region covering Saudi Arabia is offered for this account** [VERIFY with Cloudflare]. Note that Cloudflare documents its *Customer Metadata Boundary* (log/analytics residency) as selectable only for the United States or the European Union, so metadata residency in-Kingdom cannot be achieved with that feature as documented [VERIFY]. **B. DNS-only (“grey-cloud”) mode.** Remove Cloudflare from the traffic path; TLS terminates at the origin in OCI Riyadh. Eliminates the transfer entirely but removes WAF/DDoS protection and exposes the origin IP — requires an in-Kingdom WAF/DDoS alternative and an origin firewall redesign before switching. **C. In-Kingdom edge provider.** Replace Cloudflare with a Saudi-hosted CDN/WAF (e.g. a provider with in-Kingdom presence — [CANDIDATES — PLACEHOLDER]). **D. Accept with safeguards.** Keep the current setup; execute a PDPL-aligned DPA + standard contractual clauses [VERIFY availability], keep this TRA on file, monitor the serving PoP, and re-assess annually. Only defensible if counsel agrees the momentary, encrypted-in/encrypted-out edge processing meets the regulations [NEEDS LEGAL CONFIRMATION]. |
| Recommended decision | [DECISION — PLACEHOLDER]. Engineering note: A is the least disruptive if a Saudi region is available; otherwise B or C are the only ways to remove the transfer. |
| Residual risk after mitigation | [LOW / MEDIUM — PLACEHOLDER after decision]. |
| Conditions | Signed DPA on file; chosen mitigation implemented and verified (e.g. `cf-ray` / trace headers show the expected PoP); annual review; re-assess on any Cloudflare plan change. |
| Approvals | DPO: __________ date ______ · IT lead: __________ date ______ · Legal: __________ date ______ |
| Review date | [DATE — 12 months from approval] |

**What to sign:** Cloudflare DPA (plan-appropriate) [VERIFY]; if option A, the Enterprise / DLS order
form; standard contractual clauses under the Data Transfer Regulations if counsel requires them
[VERIFY]. **Owner:** DPO + IT lead. **Status:** OPEN — decision pending.

### 5.3 Transactional SMTP relay — staff email (US-hosted)

| Item | Position |
|---|---|
| What it carries | Email-verification and MFA-enrolment codes, password-reset links, username reminders, MFA-related notices, and the monthly aggregate statistics PDF to the configured report recipients. All recipients are hospital staff. **No patient-level data by design** — the monthly PDF is aggregate counts only [VERIFY by inspecting a current monthly PDF and the mail templates before signing this off]. |
| Personal data | Staff name and email address, time-limited codes and signed links (credential material in transit), IP/user-agent where included in security notices [VERIFY templates]. |
| Location | Relay hosted in the United States → transfer of **staff** personal data outside the Kingdom. |
| Risks | Provider log retention of message bodies (OTP codes / reset links) [VERIFY]; provider breach; lawful access; mis-configuration that could one day route a patient-level export by email (there is no such feature today — keep it that way). |
| Options | **1. Move to an in-Kingdom relay** (hospital mail system or a Saudi-hosted provider): removes the transfer; requires SMTP credentials to be rotated in Control → System and a deliverability check. **2. Keep, with safeguards:** signed DPA + standard contractual clauses [VERIFY]; enforce TLS on the SMTP connection; confirm log retention is short and bodies are not retained; minimise content (no names in OTP mails); document in the staff privacy section; complete a short TRA. |
| Required safeguards (in words) | Written DPA; transfer mechanism acceptable under the Data Transfer Regulations; minimisation; security; breach notification; log-retention limits. |
| Owner | IT lead |
| Status | OPEN — recommendation: option 1 (eliminate the transfer) unless the hospital mail system cannot deliver reliably; interim: confirm TLS + log retention. |

### 5.4 GitHub — source code

| Item | Position |
|---|---|
| What it holds | The hub's source code, CI workflows and CI logs. CI runs against a synthetic test database. |
| Personal data | Developer identities in commits and accounts. **Must never hold patient data.** Action items: (a) confirm no production database dump or export has ever been committed — the legacy code tree that preceded this application contained a database dump with real patient data (`Demo.sql`, see the repository's `CLAUDE.md` §7); verify it is absent from the current repository **and its history**, and purge / rewrite if found [VERIFY]; (b) confirm no credentials remain in history (rotate any that ever did) [VERIFY]; (c) enable secret scanning / push protection and branch protection [VERIFY]. |
| Location | United States / global. |
| Transfer analysis | Developer identities only → minimal. If (a) is verified, GitHub is not a processor of patient data and no health-data transfer occurs. |
| What to sign | GitHub terms + DPA as applicable [VERIFY]; internal policy: no data files in the repository. |
| Owner | Dev lead |
| Status | OPEN — run the history check and record the result here. |

## 6. Action plan

| # | Action | Owner | Due | Status |
|---|---|---|---|---|
| A1 | Legal review of §2–§3 wording; cite provisions for every `[VERIFY ARTICLE]`. | Legal | [DATE] | OPEN |
| A2 | Collect and file signed agreements / DPAs for P1–P4; complete §4 grid. | IT lead + Legal | [DATE] | OPEN |
| A3 | OCI: confirm home region, no cross-region copies, key management, support-access statement. | IT lead | [DATE] | OPEN |
| A4 | Cloudflare: ask the account team whether a Saudi-Arabia region is available for Regional Services; price Enterprise/DLS; decide A/B/C/D; approve the TRA. | DPO + IT lead | [DATE] | OPEN |
| A5 | SMTP: decide in-Kingdom relay vs. safeguards; rotate credentials in Control → System if moving. | IT lead | [DATE] | OPEN |
| A6 | GitHub: history check for data/secrets; enable protections; record result. | Dev lead | [DATE] | OPEN |
| A7 | Update privacy notice §7 (both languages + `resources/lang`) once A4–A5 are decided. | DPO + Dev | [DATE] | OPEN |
| A8 | Add the hub to the hospital-wide record of processing; register / update with the competent authority [VERIFY]. | DPO | [DATE] | OPEN |
| A9 | Confirm sector-specific health-data localisation requirements (§3 overlay). | Legal | [DATE] | OPEN |

## 7. Review log

| Version | Date | Change | By |
|---|---|---|---|
| 0.1-draft | 2026-09-03 | First draft: register, obligations in words, DPA checklist, four processor sheets, Cloudflare TRA template. | Hub engineering |
