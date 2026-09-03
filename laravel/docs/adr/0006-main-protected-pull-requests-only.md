# 0006 — `main` is protected: pull requests only, four required admin-enforced CI checks

- **Status:** Accepted
- **Date:** 2026-09-03

## Context

`main` is the single Laravel development branch and the branch Coolify builds. Until 2026-09-03 it
had no required status checks and no rulesets: a direct push could land on `main` — and become
deployable — before CI reported. The morning production-readiness run recorded this as a CI/CD
Critical (CICD-04, TST-11). CI itself had already been hardened into four jobs under a read-only
`GITHUB_TOKEN` with SHA-pinned actions; the remaining question was whether they *gated* anything.

## Decision

Protect `main`: **pull requests only**, with the four Laravel CI jobs as required status checks,
**enforced for admins**, alongside no-force-push and no-delete. The required checks are, by their
exact job names:

- `Frontend (Vitest, axe, build, style gates)`
- `Backend (PHPUnit, MySQL, composer audit)`
- `Secret scan (gitleaks)`
- `SAST (Semgrep, ERROR severity blocks)`

Reviews are **not** required — there is a single maintainer. The workflow deliberately has **no path
filter**: a required check that does not run because a commit touched no matching path shows as
*Expected — waiting* and would block the merge forever, so all four jobs run on every push and pull
request to `main`, docs-only changes included.

## Consequences

- Every change to `main` goes through `gh pr create` and merges only on green; direct pushes are
  rejected. Code that reaches production has passed the four gates by construction. CI runs on
  docs-only commits too — the cost of having no path filter.
- The gates are the assertion, not logging: a red build means a real regression, a Pint style drift,
  a coverage drop below the Vitest or PHP floors, a contrast regression, allow-list drift, or an
  un-rebuilt `public/build`.
- The whole rule depends on **GitHub Actions billing staying enabled**. When billing lapses, jobs are
  created with zero steps and prove nothing, so the same gates must be run locally per
  `RELEASE-CHECKLIST.md`.
- Branch protection does not add a second person: the re-score notes a required approving review
  count no second collaborator exists to satisfy. SEC-11 stays open (ADR 0005).
- The legacy `ci.yml` at the repo root is a separate pipeline for the legacy PHP app and must never
  be merged with `laravel-ci.yml`.

## References

- `laravel/docs/CI.md` — "Branch protection (updated 2026-09-03)" and the job table
- `CLAUDE.md` §2, §12
- `HANDOFF.md` — "The product" (branch model) and item 5 top fix (1)
- `laravel/docs/DEPLOY-LARAVEL.md` §0, §11
- `.github/workflows/laravel-ci.yml`; commits `4b75aca`, `3fbbd73`, `fa66c00`, `cb551b8` (2026-09-03)
- `laravel/docs/compliance/EVIDENCE-PACK.md` gap G16
