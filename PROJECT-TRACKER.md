# DMC Renovation — Project Tracker

> **Living project-management doc.** Notes + to-dos for the DMC patient-flow renovation,
> updated as we go until we reach the final product. Companion to
> [`RENOVATION-PLAN.md`](RENOVATION-PLAN.md) (the roadmap & rationale),
> [`REVIEW-FINDINGS.md`](REVIEW-FINDINGS.md) (finding IDs), [`CLAUDE.md`](CLAUDE.md) (architecture).

**Status key:** ⬜ to-do · 🔄 in progress · ✅ done · ⏸️ blocked / awaiting decision
**Convention:** each to-do is one line — `status — action — (plan item / finding IDs) — priority`.

---

## Progress snapshot
_Last updated: 2026-06-06_

| Phase | Done / Total | State |
|---|---|---|
| Review & planning (read-only) | 4 / 4 | ✅ complete |
| Phase 0 — Containment | 0 / 7 | not started |
| Phase 1 — Critical security & data integrity | 0 / 15 | not started |
| Phase 2 — Stabilize | 0 / 8 | not started |
| Phase 3 — Refactor + UI/UX | 0 / 6 | not started |
| Phase 4 — Re-platform (decision-gated) | 0 / 2 | not started |
| Phase 5 — Nice-to-haves | 0 / 3 | not started |

---

## Notes & decisions  _(newest first)_

- **2026-06-06 — `permissions.docx` is DATED (per maintainer).** Do **not** treat its
  permission matrix as ground truth. The intended role/capability model must be
  **re-confirmed** with the team/clinicians before relying on the intended-vs-actual gap
  analysis. ⚠️ This does **not** soften the Critical findings: endpoints with *no* auth at all,
  SQL injection, and admin takeover are wrong under **any** policy. → added to-do **P1: re-confirm
  permission model**; flagged in `CLAUDE.md` §5.
- **2026-06-06 — Review & planning phase complete (read-only).** Deliverables in repo:
  `CLAUDE.md`, `REVIEW-FINDINGS.md`, `RENOVATION-PLAN.md`, `RENOVATION-PLAN.docx`. Depth =
  full/exhaustive; audience = leadership + technical; SQL dump (`Demo.sql`) provided.
- **2026-06-06 — Owed:** a follow-up cited-research pass for the session/password/MySQL/
  audit-HIPAA/framework sections (RENOVATION-PLAN.md §8) was not yet run through 3-vote
  verification.

_(Add new notes/decisions above this line as we go.)_

---

## To-dos

### Phase 0 — Emergency containment (P0 — do now, mostly ops)
- ⬜ Rotate the **DB password** (treat as compromised) — (S7 / SEC-16) — P0
- ⬜ Rotate the **SMTP password** — (S7 / SEC-17) — P0
- ⬜ **Delete `reset-testcount.php`** from prod — (S9 / SEC-05) — P0
- ⬜ **Delete `test-trans.php`** from prod — (S9 / SEC-06) — P0
- ⬜ **Force HTTPS** (redirect 80→443) and/or restrict app to intranet/VPN — (S8 / SEC-15) — P0
- ⬜ Take a **verified, restorable DB backup** — (R5 / REL-04) — P0
- ⬜ Delete committed `php_errorlog` files (~37 MB) + set `display_errors = Off` — (X1 / SIMP-01, SEC-24) — P0

### Phase 1 — Critical security & data integrity (P1 — ~4-6 wks)
- ⬜ **Central server-side auth guard** included by every endpoint (deny-by-default + ownership) — (S1 / SEC-01,04,07-11,20,21) — P1
- ⬜ **Re-confirm the real role/capability permission model** with team/clinicians (permissions.docx is dated) — (NEW) — P1
- ⬜ Convert **all queries to prepared statements** via one DB helper — (S2,C2 / SEC-07-13) — P1
- ⬜ **Encode all output** + remove `eval` + baseline CSP — (S3,S10 / SEC-18,26) — P1
- ⬜ Block **privilege escalation** (never accept client-supplied position/flags) — (S4 / SEC-02,03) — P1
- ⬜ Fix **password reset** (random, single-use, hashed, expiring token) + widen hash column — (S5 / SEC-13,14,30) — P1
- ⬜ **Session/cookie hardening** + **CSRF tokens** — (S6 / SEC-19,23) — P1
- ⬜ **Externalize secrets** from source (env/secret store) — (S7 / SEC-16,17) — P1
- ⬜ Add **security headers** (HSTS/CSP/XFO/nosniff/Referrer-Policy) — (S8 / SEC-27) — P1
- ⬜ Remove debug/`var_dump`; replace **PHPExcel → PhpSpreadsheet** — (S9 / SEC-24,25) — P1
- ⬜ **Audit-log foundation** (actor from session, before/after) — (R1 / REL-02, SEC-22) — P1
- ⬜ Fix broken **assign-to-primary quote** (corrupts patient ID) — (W2 / UX-02) — P1
- ⬜ **Confirmations** on discharge/transfer/delete/reverse (show name+MRN) — (W4 / UX-04) — P1
- ⬜ **Trustworthy inline save** (read response; confirm identity-field edits) — (W1 / UX-01) — P1
- ⬜ **Server-side + typed input validation** (age/MRN/date) — (W3 / UX-03) — P1

### Phase 2 — Stabilize & quick wins (P1/P2)
- ⬜ Migrate **MyISAM → InnoDB** (utf8mb4) — (D1 / DB-01) — P1
- ⬜ **Transactions** on multi-step clinical writes — (R2 / REL-01,08,09) — P2
- ⬜ Add **indexes** + make stat queries **sargable** (no MONTH()/YEAR() on indexed cols) — (D3,P2 / DB-03,PERF-02) — P1
- ⬜ **Soft-delete** + audit; restrict delete to Admin — (R4 / REL-05) — P2
- ⬜ Backups + **schema-migrations** tooling — (R5 / REL-04) — P1
- ⬜ Delete remaining **dead code/weight** — (X4 / SIMP-04,05) — P2
- ⬜ **Central error handling** (no raw `die()`/error echo) — (C4 / ARCH-05) — P2
- ⬜ Status **color+icon+contrast** a11y safety fix — (U1 / UI-01,02) — P1/P2

### Phase 3 — Refactor + UI/UX overhaul (P2/P3)
- ⬜ Introduce **layering + PHPUnit**; extract helpers/partials — (C1,C3 / ARCH-01,03,04) — P2
- ⬜ **De-duplicate** lists/census/ICD-10 on ONE "active" definition — (X2 / SIMP-02, CLIN-08) — P2
- ⬜ **Statistics engine**: grouped SQL + caching; merge A4 twins — (X3,P1,P4 / SIMP-03,PERF-01) — P2
- ⬜ **UI/UX overhaul**: responsive/tablet, a11y, data-entry, status pipeline — (U2,U4,W5) — P2
- ⬜ **Server-side PDF** reports; un-hide KPI page; vendor CDNs locally — (U3 / UI-05,06,07,08) — P2
- ⬜ Normalize **diagnoses/MRN/specialty** schema; add FKs — (D2,D4,D5) — P2/P3

### Phase 4 — Re-platform (P3 — decision-gated, after Phase 1)
- ⏸️ **Decide framework** (Laravel / Symfony / Slim) — P3
- ⬜ **Strangler-fig** incremental migration; add CI — (Phase 4) — P3

### Phase 5 — Nice-to-haves (P3)
- ⬜ Branding/typo cleanup, semantic icons — (U5 / UI-14) — P3
- ⬜ Real-time dashboard refresh (polling/websocket) — (PERF-06) — P3
- ⬜ MFA, richer reporting — P3

---

## Decisions needed  ⏸️ _(awaiting your input)_
- ⏸️ **Is the app internet-facing or intranet/VPN-only?** (sets breach blast-radius & urgency)
- ⏸️ **Compliance regime + breach-notification posture** given current exposure
- ⏸️ **Re-confirm the permission model** (permissions.docx is dated) — who owns the source of truth?
- ⏸️ **Framework choice** for Phase 4
- ⏸️ **Backup/retention policy**; who maintains the app (hospital vs white-label vendor)
- ⏸️ **Clinical sign-offs (CLIN-01…09):** readmission window · LOS/DST calc · `settings` thresholds ·
  shuffle assignment policy · `MORTALITY` vocabulary · two-phase discharge semantics ·
  canonical "active patient" definition · TB-list maintenance · MRN format

---

## Backlog / parking lot  _(add anything new here as we go)_
- _(empty)_
