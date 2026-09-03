# CI — what gates what, and how to change a gate

Two GitHub Actions workflows live at the repo root:

| Workflow | File | Runs when | Guards |
|---|---|---|---|
| **Laravel CI** | `.github/workflows/laravel-ci.yml` | push / PR to `main` touching `laravel/**` or the workflow file | the Laravel re-platform (this directory) |
| **Legacy CI** | `.github/workflows/ci.yml` | push / PR touching anything *except* `laravel/**`, the Laravel workflow, `docs/superpowers/**`, `README.md`, `.gitignore` | the legacy PHP app at the repo root |

They were both called `CI` until 2026-09; the run list conflated them. Do not merge them — the
legacy app's canonical lineage is the `renovation` branch and its pipeline has its own fixtures.

> **Branch protection (updated 2026-09-03).** GitHub Actions runs and gates: the workflow has been
> green on every push since billing was restored. The four Laravel CI checks — `Frontend (Vitest,
> axe, build, style gates)`, `Backend (PHPUnit, MySQL, composer audit)`, `Secret scan (gitleaks)`,
> `SAST (Semgrep, ERROR severity blocks)` — are **required status checks on `main`, enforced for
> admins**, alongside no-force-push and no-delete. Consequence: **every change to `main` goes
> through a pull request and merges only on green** (`gh pr create`, then `gh pr merge` once the
> checks pass); direct pushes are rejected. Reviews are not required (single maintainer).
>
> The workflow deliberately has **no path filter**: a required check that does not run because a
> commit touched no matching path shows as *Expected — waiting* and blocks the merge forever, so
> the four jobs run on every push and PR to `main`, docs-only changes included.

## Laravel CI jobs

All jobs run under a **read-only** `GITHUB_TOKEN` (`permissions: contents: read` at workflow level).
Two jobs widen that for themselves only: `secrets` adds `pull-requests: read` (the gitleaks action
lists a PR's commits), `sast` adds `security-events: write` (SARIF upload to the Security tab).
Nothing here can push, tag, comment, or publish.

### `frontend` — Frontend (Vitest, axe, build, style gates)

| Step | Gate | Fails when |
|---|---|---|
| `npm audit --omit=dev --audit-level=moderate` | production npm advisories | any moderate+ advisory in a production dependency |
| **Accessibility gate (vitest-axe)** | `resources/js/__tests__/a11y.axe.spec.js` | axe-core reports **any** violation on the consultation pages |
| Vitest | the whole unit suite (includes the axe spec) | any test fails |
| `npm run build` | production build | build error |
| `npm run check-allowlist` | class allow-list drift | a Tailwind utility appears that is not in the allow-list snapshot |
| `node scripts/contrast.mjs` | AA contrast + perceptual distance of the palette tokens | a token pair falls below the threshold |
| build reproducibility | committed `public/build` == `npm run build` output | any diff or untracked file under `public/build` |

**The accessibility gate.** `a11y.axe.spec.js` mounts `Pages/Consultations/Index.vue` (empty, populated
in every state, registrar's read-only shape, consultant with the follow-up worklist, the
new/edit/sign-off forms, and a form with a validation error summary), `Pages/Consultations/Dashboard.vue`
(consultant, admin with the specialty picker, every empty state) and `Pages/Consultations/Handover.vue`
(one service, several services with the picker, an empty service, an empty sheet) with representative
props, attached to the document, and runs axe-core over each. **No rule is disabled or filtered** —
`toHaveNoViolations()` is asserted against axe's full default ruleset. The layout stub mirrors the real
`AppLayout` skeleton (`<header><h1>` + `<main id="main-content">`) so landmark/heading rules run
against a page shaped like production, and the `ChartCanvas` stub renders the same `<div>` root the real
component does, with the caller's attributes fallen through.

What it cannot see: axe-core in jsdom has no layout engine, so **colour contrast** and anything needing
a paint is out of its reach — that is `scripts/contrast.mjs`'s job. A green run means *no structural
WCAG defect in the rendered DOM*, not *accessible*.

When it fails: fix the page. A finding is real unless the fixture shape is wrong (the page renders
what the controller ships — check the controller's prop shape before blaming the page). Do not add
`rules: { … disabled }` or `runOnly` to the spec; if a rule genuinely cannot apply, the reason belongs
in a comment on a targeted `expect` with the offending node excluded, never a global switch.

To cover another page: add a `describe` block in the same spec with that page's fixture idiom
(copy from its existing unit test), mount via `mountAttached`, and call `audit(w)`.

**Baseline on 2026-09-03.** The first run found one real violation: both chart elements in
`Pages/Consultations/Dashboard.vue` carried an `aria-label` with no role (`aria-prohibited-attr`,
serious — an `aria-label` on a plain `<div>` is ignored or misread by assistive tech). Fixed by adding
`role="img"`, the idiom `Pages/Statistics/Index.vue` already uses and the one `ChartFigure` documents.
The six charts in the main `Pages/Dashboard.vue` were given the same `role="img"` fix during the
Chart.js migration. Charts render on Chart.js (`ChartCanvas`); the wrapper is jsdom-safe (no 2D
context there), so the axe fixtures need no chart stub — the `ChartCanvas` stub above is only for a
stable DOM root.

### `backend` — Backend (PHPUnit, MySQL, composer audit)

| Step | Gate | Fails when |
|---|---|---|
| **composer audit → `scripts/composer-audit-gate.php`** | Composer advisories | a **high / critical** (or severity-less) advisory is not listed in `.composer-audit-ignore.json`; an ignore entry has expired; the report is missing/invalid |
| PHPUnit pass 1 (`--exclude-group pdf`) | the suite against real MySQL 8 | any test fails |
| PHPUnit pass 2 (`--group pdf`) | dompdf tests in an isolated process | any test fails |

**The composer audit gate.** `composer audit` exits non-zero for *any* advisory (medium and low
included), which is why the old pipeline ran it with `|| true` — and thereby swallowed the high ones
too. Now the composer exit code is captured but *not* acted on; `scripts/composer-audit-gate.php`
reads the `--format=json` report and decides:

| Advisory | Verdict |
|---|---|
| high / critical, not ignored | **BLOCK** (`::error::`, exit 1) |
| no severity from the advisory source | **BLOCK** — unknown is not "low"; review it, then ignore it explicitly if it is unreachable |
| medium / low | `::warning::` only — visible in the run, does not fail |
| listed in `.composer-audit-ignore.json` | `::notice::` with the recorded reason |
| listed, but its `review_by` date has passed | the entry is **expired** — it suppresses nothing until re-reviewed |
| abandoned package | `::warning::` only |
| composer produced no JSON / no `advisories` key | exit 2 — composer itself failed; never a pass |

A summary also lands in the job's step summary.

**Adding an ignore** (`laravel/.composer-audit-ignore.json`) — only for an advisory you have verified
is *unreachable in this application*, never because it is inconvenient:

```json
{
    "package": "vendor/name",
    "id": "CVE-2026-12345",
    "severity_at_review": "high",
    "reason": "Which code path the advisory needs, and why this app never reaches it (name the file). Remove when vendor/name >= X.Y.Z.",
    "reviewed_at": "2026-09-03",
    "review_by": "2026-12-03"
}
```

- `id` may be the CVE, the Packagist `PKSA-…` id, or the GitHub `GHSA-…` id (all three are matched,
  case-insensitively, together with `package`).
- `package`, `id`, `reason`, `reviewed_at` are **mandatory**; a malformed entry fails the job (exit 2)
  so an allow-list cannot be widened by a half-written line.
- Put a `review_by` on every entry (90 days is the convention). When it passes, the advisory blocks
  again until someone re-reads the reason and moves the date — that is the point.
- Prefer upgrading the package. An ignore is a debt with a due date, not a fix.

**Baseline on 2026-09-03** (`composer audit --locked`, Composer 2.10.2): 27 advisories.
Ignored with reasons: `guzzlehttp/guzzle` CVE-2026-69246 (high — the only outbound HTTP call is the
backup upload to a fixed S3 endpoint, `app/Support/S3SigV4.php`) and the six `dompdf/dompdf`
advisories (medium/low — application-authored templates only, `enable_remote=false`, chroot set).
**Still blocking: `league/commonmark` 2.8.2 carries eight HIGH advisories** (DoS via crafted
Markdown, and an `on*` event-handler filter bypass in the Attributes extension), all fixed in
≥ 2.9.1 / 2.10.0. It is a transitive dependency of `laravel/framework` and nothing under `app/`
calls Markdown; it was **not** added to the ignore list because it was not part of the reviewed
set. Resolve with `composer update league/commonmark` (or a full `composer update`) and commit the
lock file — until then the backend job is red, by design.

### `secrets` — Secret scan (gitleaks)

Runs `gitleaks/gitleaks-action` over the **commit range** of the push (or the PR's commits) with
`laravel/.gitleaks.toml`, which extends the built-in ruleset unchanged. Fails on any finding; PR
commenting is off (it would need a write token). The action needs no licence for a personal-account
repository; `GITLEAKS_LICENSE` becomes mandatory the day the repo moves under an organisation.

The config's **only** allow-list is the four AWS-published SigV4 test vectors that
`tests/Unit/S3SigV4Test.php` signs against (documentation example access key / secret, the
empty-payload SHA-256, and the documented expected signature). It uses `condition = "AND"`: a finding
is ignored only when the file path is that test (or the config itself) **and** the line carries one of
those exact strings. Nothing else in that file, and nothing anywhere else, is exempt.

Adding an allow-list entry needs the same bar: a *published, non-secret* value, matched by exact
string, in a named path, with a comment saying where it is published. Never allow-list a directory,
never `disabledRules`, never a bare regex without a path. If gitleaks flags something real: rotate the
credential first (it is already in history), then remove it from the tree; rewriting history is a
separate, deliberate operation.

Known: the legacy app's history contains the old hard-coded DB/SMTP credentials (documented in the
production-readiness audit). A *full-history* scan (`gitleaks git .`) surfaces them; the CI job scans
only the pushed range, so it stays green while still refusing any *new* secret. Rotation is the fix,
not an allow-list.

### `sast` — SAST (Semgrep, ERROR severity blocks)

Runs in the official `semgrep/semgrep` container (pinned by **digest**, 1.175.0) over `laravel/app`,
`laravel/routes` and `laravel/resources/js` with the registry rulesets `p/php`, `p/owasp-top-ten` and
`p/javascript`; front-end test files (`__tests__`, `*.test.js`, `*.spec.js`) are excluded because
jsdom fixtures legitimately build DOM from strings.

- `--severity ERROR` restricts the run to ERROR-level rules and `--error` turns any finding into exit 1
  — **an ERROR finding fails the job.** WARNING/INFO rules are not run. To surface them without gating,
  drop `--severity` and gate on the JSON output separately (`--json-output` + a severity filter).
- The SARIF is uploaded to the repository's **Security → Code scanning** tab (category `semgrep`),
  `if: always()` so failures are visible, and `continue-on-error` so an upload problem (a fork PR's
  read-only token, say) can never mask or fake the scan's own verdict.
- Metrics are off (`--metrics=off`); no code leaves the runner except the rule download.

**Baseline on 2026-09-03** (semgrep 1.176.0, same command, run locally): 175 files scanned, 171
rules loaded of which 35 are ERROR-level and ran, **0 findings** — the job is expected green on its
first run.

When it fails: fix the code. If a finding is a true false-positive, Semgrep's inline
`// nosemgrep: <rule-id>` (PHP: `# nosemgrep: <rule-id>`) on the offending line is acceptable **with a
comment saying why** — the rule id keeps the exemption narrow and greppable. Never drop a ruleset or
widen `--exclude` to make a finding go away.

## Pinned actions

Every `uses:` in both workflows is pinned to a full commit SHA with the tag it was resolved from as a
trailing comment; the Semgrep image is pinned to its manifest digest. A re-tagged upstream release
therefore cannot change what runs here.

| Action | Pinned | Resolved from | Resolved on |
|---|---|---|---|
| `actions/checkout` | `11d5960a326750d5838078e36cf38b85af677262` | `v4` → `v4.4.0` | 2026-09-03 |
| `actions/setup-node` | `49933ea5288caeca8642d1e84afbd3f7d6820020` | `v4` → `v4.4.0` | 2026-09-03 |
| `shivammathur/setup-php` | `f3e473d116dcccaddc5834248c87452386958240` | `v2` → `2.37.2` (annotated tag dereferenced) | 2026-09-03 |
| `gitleaks/gitleaks-action` | `ff98106e4c7b2bc287b24eaf42907196329070c7` | `v2` → `v2.3.9` (annotated tag dereferenced) | 2026-09-03 |
| `github/codeql-action/upload-sarif` | `cdf488f595d80d6e07e03d4674febd5ab45fa938` | `v4` → `v4.37.9` (annotated tag dereferenced) | 2026-09-03 |
| `semgrep/semgrep` (image) | `sha256:b94b53d02fd4a022f9eac4e2af1380f5c3c4c21400e79d3336bdff1d1db5e796` | Docker Hub tag `1.175.0` (= `latest`) | 2026-09-03 |

**Bumping a pin:**

```sh
gh api repos/<owner>/<repo>/git/ref/tags/<tag> --jq '.object | "\(.type) \(.sha)"'
# "commit <sha>"  → use <sha>
# "tag <sha>"     → annotated tag: dereference it
gh api repos/<owner>/<repo>/git/tags/<sha> --jq '.object.sha'
# Semgrep image digest for a version tag:
curl -s https://hub.docker.com/v2/repositories/semgrep/semgrep/tags/<version> | jq -r .digest
```

Update the SHA *and* the trailing comment together; never paste a SHA you did not resolve yourself.

## Local reproduction

```sh
cd laravel
npx vitest run resources/js/__tests__/a11y.axe.spec.js         # accessibility gate
composer audit --locked --format=json > /tmp/audit.json; php scripts/composer-audit-gate.php /tmp/audit.json .composer-audit-ignore.json
gitleaks dir . --config .gitleaks.toml                          # or: gitleaks git .. --config laravel/.gitleaks.toml
cd .. && semgrep scan --config p/php --config p/owasp-top-ten --config p/javascript \
  --severity ERROR --error --exclude __tests__ --exclude '*.test.js' --exclude '*.spec.js' \
  --metrics=off laravel/app laravel/routes laravel/resources/js
```
