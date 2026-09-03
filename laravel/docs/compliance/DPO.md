# Data Protection Officer — designation template

> **Confirmed inputs (2026-09-03) — see [`CONFIRMED-FACTS.md`](CONFIRMED-FACTS.md).** Controller: **Dammam Medical Complex**, under the **Eastern Health Cluster** (Saudi Health Holding Company); public-sector ownership, exact registering entity for counsel to confirm. Primary processor: **the developer/operator company** (holds the code, the OCI tenancy and the domain) — **no controller–processor contract exists yet (top action)**. DPO **not yet appointed** (treated as mandatory). The daily production system is still the **legacy PHP app on `dmc-im.com` (SiteGround, United States), original un-hardened build**; the Laravel app runs in parallel until cutover. Review: annual (next 2027-09-03), owner interim until a DPO is named. Legal citations are **[PROPOSED]** — see [`PROPOSED-CITATIONS.md`](PROPOSED-CITATIONS.md).

> **DRAFT — for review by the hospital's legal / data-protection officer; not legal advice.**
>
> Scope: the DMC Internal Medicine Patient-Flow Hub (“the hub”) and, by extension, the hospital as the
> controller of the personal data processed in it. Version 0.1-draft, 2026-09-03. Every square-bracketed
> marker is an open item: `[VERIFY ARTICLE]` means the obligation is described in words and the
> article of the Personal Data Protection Law (“PDPL”) or its Implementing Regulations must be checked
> and cited by legal counsel; `[PLACEHOLDER]` means a fact only the hospital can supply.

---

## 1. Why this document exists

The PDPL makes the **controller** — the hospital — responsible for how personal data is processed. Its
Implementing Regulations require a controller to designate one or more persons responsible for personal
data protection (in practice, a Data Protection Officer, “DPO”) in the situations the regulations list;
the commonly described triggers are public entities, controllers whose core activity involves
large-scale, regular and systematic monitoring of individuals, and controllers whose core activity
involves processing **sensitive** personal data [VERIFY ARTICLE]. Health data is sensitive personal data
under the PDPL, and the hub processes it for every admitted patient of the unit, so the hospital should
assume it is in scope unless counsel concludes otherwise [NEEDS LEGAL CONFIRMATION].

Designating a DPO does not transfer liability: the hospital stays the controller. The DPO is the person
who makes the controller's obligations happen day to day and who the competent authority and data
subjects can reach.

## 2. The role

| Element | Template wording | Notes |
|---|---|---|
| Title | Data Protection Officer (مسؤول حماية البيانات) | Use the Arabic title on Arabic-facing material. |
| Appointed by | [BOARD / CEO / DIRECTOR-GENERAL — PLACEHOLDER] by written designation (section 7). | Written, dated, signed. Keep a copy with the processor register. |
| Reports to | [CEO / EXECUTIVE DIRECTOR — PLACEHOLDER] directly, with unfiltered access to the board / executive committee on data-protection matters. | Direct line to top management is the independence test. |
| Deputy | [DEPUTY NAME / ROLE — PLACEHOLDER] | Covers absence; same independence rules. |
| Basis of appointment | Internal designation (employee) **or** external service contract [CHOOSE]. | Either is acceptable in words under the regulations [VERIFY ARTICLE]; an external DPO still needs a named individual and a hospital-side liaison. |
| Qualifications | Working knowledge of the PDPL, its Implementing Regulations and the Data Transfer Regulations; understanding of hospital medical-records duties; ability to read the hub's audit and access reports. | [VERIFY whether the regulations or SDAIA guidance prescribe minimum qualifications or training]. |
| Time allocation | [PERCENTAGE / DAYS PER MONTH — PLACEHOLDER] | Must be realistic for a live clinical system. |
| Resources | Budget line for training, legal advice and tooling; read access to the hub's audit log viewer and processor register. | |
| Term & review | [TERM — PLACEHOLDER]; role reviewed annually and on any change of hosting / processors / law. | |

## 3. Responsibilities

The list below describes, in words, what the PDPL and its Implementing Regulations expect the
controller's data-protection function to do; the specific provisions must be cited by counsel
[VERIFY ARTICLE for each row].

| # | Responsibility | For the hub, concretely |
|---|---|---|
| R1 | **Monitor compliance** with the PDPL, the regulations and the hospital's own policies; advise management. | Own the annual privacy review of the hub; sign off changes that add data fields, recipients or providers. |
| R2 | **Maintain the record of processing activities** (purposes, categories, recipients, transfers, retention, security). | Keep `docs/compliance/DPA-AND-TRANSFERS.md` (processor register) current; add the hub to the hospital-wide record. |
| R3 | **Privacy notices / transparency** — ensure data subjects are informed as the law requires. | Owner of `PRIVACY-NOTICE.en.md` / `.ar.md` and the in-app `/privacy` page; approves each version; ensures the notice reaches patients through the hospital's channels. |
| R4 | **Legal basis & purpose limitation** — confirm each processing purpose has a lawful basis and stays within it. | Confirm the health-services basis for clinical use; block any proposed non-clinical use (marketing, research without a lawful pathway, analytics). |
| R5 | **Data-protection impact assessment** where the regulations require one for high-risk processing (sensitive data, new technology, large scale). | Run / commission a DPIA for the hub and re-run it on material change (new provider, new data category, new export). [VERIFY ARTICLE — when a DPIA is mandatory]. |
| R6 | **Data-subject requests** — receive, verify, coordinate and answer within the period the regulations set. | Agree the workflow with the HIM office (which verifies identity and holds the full record); ensure hub data is included in access/copy requests; document refusals grounded in medical-record retention duties. [VERIFY PERIOD]. |
| R7 | **Breach management** — assess, contain, document, and notify the competent authority and (where required) affected individuals within the period the regulations set. | Own the incident runbook for the hub (audit-log review, session revocation, processor notification). [VERIFY ARTICLE — notification period and thresholds]. |
| R8 | **Processors and transfers** — ensure written contracts with every processor, sufficient guarantees, and that any transfer outside the Kingdom meets the Data Transfer Regulations. | Sign-off on Cloudflare / OCI / SMTP / GitHub agreements and the Cloudflare transfer-risk assessment (`DPA-AND-TRANSFERS.md`). |
| R9 | **Security oversight** — confirm technical and organisational measures are appropriate to sensitive data. | Review MFA coverage, role/capability grants, export audit, encryption-at-rest roll-out, backup restore tests. |
| R10 | **Training and awareness** for staff who handle personal data. | Annual PDPL briefing for all hub users; onboarding note for new accounts. |
| R11 | **Point of contact** for the competent authority and for data subjects; keep the hospital's registration with the authority current. | Publish contact details (section 4); register / update the controller and DPO details on the authority's National Register (National Data Governance Platform) — **mandatory** for a controller that processes sensitive data [PROPOSED — Rules Governing the National Register of Controllers, Art. 2]. |
| R12 | **Reporting** — periodic report to top management on compliance status, incidents, requests and open risks. | Quarterly one-page report; annual formal review. |

## 4. Publishing the contact

The regulations expect the DPO's contact details to be **available to data subjects and communicated to
the competent authority** [VERIFY ARTICLE]. For the hub:

| Where | What to publish |
|---|---|
| Privacy notice (section 2 and 12 of `PRIVACY-NOTICE.*.md`) and the in-app `/privacy` page | Name or role, a **generic mailbox** (recommended: `[dpo@HOSPITAL-DOMAIN — PLACEHOLDER]` rather than a personal address), phone, postal address. |
| Hospital website / patient-information channel | Same details, Arabic and English. |
| Patient leaflets / admission pack (Internal Medicine unit) | Short version: “Questions about your data: [DPO CONTACT]”. |
| Staff intranet / onboarding | Same, plus the internal incident-reporting route. |
| Competent authority (SDAIA) [VERIFY] | Controller registration + DPO details, updated on any change. |

## 5. Independence and conflict of interest

- The DPO must be able to perform the role **without instruction on the outcome** of assessments,
  must not be dismissed or penalised for doing so, and must have the resources and access needed
  [VERIFY ARTICLE].
- **Avoid conflicts**: the DPO should not be the person who decides the purposes and means of the
  processing being overseen. For the hub that means the DPO should **not** be the hub's product owner,
  the head of the Internal Medicine unit (who defines what the hub does), or the head of IT who runs
  it. A compliance / legal / quality-governance officer, or an external DPO, is the usual fit. The HIM
  office is a close partner but, because it operates the medical record, doubling as DPO should be
  reviewed for conflict [NEEDS LEGAL CONFIRMATION].
- Confidentiality: the DPO is bound by confidentiality regarding the requests and incidents handled.
- Escalation: the DPO may escalate directly to the board / executive committee.

## 6. Hub-specific duties (attach to the designation)

| Duty | Cadence |
|---|---|
| Approve every version of the privacy notice (both languages) before it is deployed; confirm `resources/lang/{en,ar}/privacy.php` match the approved Markdown. | Per change |
| Review the processor register and each processor's status column. | Quarterly |
| Review the Cloudflare transfer-risk assessment; confirm the chosen mitigation is still in force. | Semi-annually and on any Cloudflare plan / DNS change |
| Sample the hub's audit log (record reads, exports, role changes) with the system administrator. | Monthly |
| Confirm audit-log retention (6 y in-app, 7 y immutable archive) and backup retention (90 d) still match policy [NEEDS LEGAL CONFIRMATION]. | Annually |
| Confirm the DSR workflow with the HIM office still works end to end (test request). | Annually |
| Table incidents and open risks to top management. | Quarterly |

## 7. Designation template

```
[HOSPITAL LETTERHEAD]

DESIGNATION OF DATA PROTECTION OFFICER
تعيين مسؤول حماية البيانات

Pursuant to the Personal Data Protection Law of the Kingdom of Saudi Arabia and its Implementing
Regulations [VERIFY ARTICLE], Dammam Medical Complex, Eastern Health Cluster (Saudi Health Holding Company) (Commercial Registration / Licence No.
[PLACEHOLDER]), as controller, hereby designates:

    Name:          [DPO NAME]
    Position:      [DPO ROLE / DEPARTMENT]
    Deputy:        [DEPUTY NAME, ROLE]
    Contact:       [dpo@HOSPITAL-DOMAIN] · [PHONE] · [POSTAL ADDRESS]
    Effective:     [DATE]
    Term:          [TERM] (renewable)

as Data Protection Officer for the processing of personal data carried out by the hospital, including
the DMC Internal Medicine Patient-Flow Hub.

The DPO shall perform the responsibilities in Schedule A, reports directly to [CEO / EXECUTIVE
DIRECTOR], acts independently in the performance of these responsibilities, shall not be instructed as
to the outcome of any assessment nor penalised for performing the role, and shall be provided with the
resources, access and training required. The hospital shall publish the DPO's contact details to data
subjects and communicate them to the competent authority [VERIFY].

Signed: ______________________  [NAME, TITLE]        Date: __________
Accepted: ____________________  [DPO NAME]           Date: __________

Schedule A — Responsibilities: sections 3 and 6 of docs/compliance/DPO.md (version [X]).
```

## 8. Filled example (placeholders retained)

```
Name:          [Eng. / Dr. FIRST LAST]
Position:      Head of Compliance & Risk, Dammam Medical Complex, Eastern Health Cluster (Saudi Health Holding Company)
Deputy:        [FIRST LAST], Information Security Officer
Contact:       dpo@[hospital-domain].sa · +966 [XX XXX XXXX] · [P.O. Box], [City] [Postcode], Saudi Arabia
Effective:     [2026-MM-DD]
Term:          Two years, renewable
Reports to:    Chief Executive Officer; direct access to the Executive Committee
Time:          [40%] of working time; external counsel retained for PDPL questions
Hub liaison:   [System administrator name] (IT) and [HIM office lead] (records / DSRs)
Registered:    Controller + DPO details filed on the competent authority's platform on [DATE] [VERIFY]
```

## 9. Review log

| Version | Date | Change | By |
|---|---|---|---|
| 0.1-draft | 2026-09-03 | First draft for legal / DPO review. | Hub engineering |
