# CI — what gates what, and how to change a gate

Two GitHub Actions workflows live at the repo root:

| Workflow | File | Runs when | Guards |
|---|---|---|---|
| **Laravel CI** | `.github/workflows/laravel-ci.yml` | every push / PR to `main` (no path filter — the four jobs are required checks, and a check that never runs blocks a merge forever); a pushed `v*` tag runs the `release` job only (§ below) | the Laravel re-platform (this directory) |
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
| `npm run lint` (ESLint + eslint-plugin-vue, TST-04) | lint baseline | any error **or warning** (`--max-warnings=0`); the rules are in `eslint.config.js`, with the two deliberate exceptions documented there |
| Vitest `--coverage` | the whole unit suite (includes the axe spec) + the coverage floor in `vitest.config.js` | any test fails, or lines / statements / branches / functions fall under 71 / 65 / 60 / 46 % (vitest 5 units — re-baselined 2026-09-22; the reasoning and the v3 numbers are in `vitest.config.js`) |
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
| `python3 -m unittest test_db_backup test_binlog_ship` (in `scripts/backup`) | the host-side backup and binlog-shipping tooling — Python that runs as root on the DB host, outside PHPUnit's reach | any test fails. Stdlib only; the runner's `python3` + `openssl` are the whole dependency list |
| PHPUnit pass 1 (`--exclude-group pdf --coverage-clover`) | the suite against real MySQL 8.4 on PHP 8.3; writes `coverage/clover.xml` (pcov) | any test fails |
| **`scripts/coverage-gate.php coverage/clover.xml 83`** (TST-02) | PHP statement coverage over `app/` | below 83 % (measured 86.15 % on 2026-09-03), or no usable Clover report (exit 3 — a missing driver must not pass silently) |
| PHPUnit pass 2 (`--group pdf`) | dompdf tests in an isolated process | any test fails |
| **`scripts/clock-guard.php`** (I18N-02) | raw MySQL clock functions in `app/`, `routes/`, `database/` | a string literal uses `NOW()` / `CURDATE()` / `UTC_TIMESTAMP()` etc. and is not allow-listed with a reason in `.clock-allowlist.json` |
| **`scripts/sbom.php`** (CICD-05) | CycloneDX SBOM of both lock files, archived as the `sbom-cyclonedx` run artifact (90 days) | a lock file is missing or unreadable |
| `vendor/bin/pint --test` | Laravel Pint code style | any file would be rewritten |

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

**The raw-clock guard.** `config/database.php` deliberately pins no MySQL session timezone, so the
database session runs in the DB host's zone (UTC) while the app runs Asia/Riyadh. That is safe until
the two clocks are mixed: an **app-written** column (Laravel writes `admit_date`, `assigned_at`,
`handovers.updated_at` as Riyadh-local strings) compared against MySQL's `NOW()` is three hours wrong
all year and fails silently. The guard tokenises each file and inspects **string literals only**, so
prose in a comment ("never use `CURDATE()` here") cannot trip it and PHP's `now()` helper is never
mistaken for SQL's. Every hit must be listed in `.clock-allowlist.json` with a reason; the only
defensible one is that the column on the other side is **DB-written** (a `->useCurrent()` default),
which is why the three `audit_log.created_at` comparisons are allowed. An allow-list entry that
matches nothing is reported as a `::warning::` so the file cannot rot. Pinning the session timezone
is a data migration, not a config change — `docs/DEPLOY-LARAVEL.md` §10 carries the procedure, and
`AppClockDayBoundaryTest` fails if the config key ever appears.

**The SBOM.** `scripts/sbom.php` builds one CycloneDX 1.5 document from `composer.lock` and
`package-lock.json` — the files that pin what CI actually installed. It is deliberately
dependency-free (no plugin, no network, no `npm sbom`): a supply-chain artifact that pulls its own
supply chain is self-defeating, and this runs identically on Windows and the runner. Output is sorted
by purl with no timestamp or serial number, so identical lock files give a byte-identical document
and any diff between runs means the dependencies moved; `metadata.component.version` carries
`GITHUB_SHA` in CI (and nothing locally) so an archived document says which build it describes.

Licences are the part that is easy to get wrong. CycloneDX validates `licenses[].license.id`
against the SPDX enum, so ONE unknown id invalidates the whole document — which would be discovered
the day an auditor loads it, not before. `id` is therefore emitted only for identifiers present in
the vendored `scripts/spdx-license-ids.json`; anything else becomes a free-text `name` (always
valid) with a `::warning::` naming it, so drift is visible instead of fatal. A *choice* of licences
is an expression, not a list: composer writes it as an array (`BSD-3-Clause`, `GPL-2.0-only`,
`GPL-3.0-only` means pick one) and npm as `(MIT OR CC0-1.0)`; emitting either as a list of ids would
assert every licence at once, the opposite of what the package grants. De-duplication by purl lets
`required` win over `dev`, so a package pulled in both ways is never filed as a dev dependency.
Run it locally with `php scripts/sbom.php sbom/dmc-laravel.cdx.json` (git-ignored).

**Signed build provenance (CICD-05, added 2026-09-22) lives in the separate `release` job below,
gated on a pushed tag — it is not part of every `backend` run.** This job still produces no
release artifact on an ordinary push or PR: Coolify builds the image from source on the host, so
there is still nothing to attest on the commits that flow through `frontend`/`backend`/`secrets`/`sast`.
The SBOM step here stays as it is — the `release` job regenerates its own copy of the SBOM (same
generator, same deterministic output) so the signed one matches the tagged commit, not whichever
`main` push happened to build it first.

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

**Baseline on 2026-09-03 morning** (`composer audit --locked`, Composer 2.10.2): 27 advisories,
including **`league/commonmark` 2.8.2 carrying eight HIGH advisories** (DoS via crafted Markdown,
and an `on*` event-handler filter bypass in the Attributes extension) — a transitive dependency of
`laravel/framework` that nothing under `app/` calls directly (reachable only via a crafted
self-registered username, itself closed separately by the existing `alpha_dash` validation rule).

**Fixed the same day, commit `267d422` ("chore(deps): clear all 27 composer security advisories"):**
`league/commonmark` 2.8.2 → **2.10.0**, `guzzlehttp/guzzle` 7.11.1 → 7.15.5, `guzzlehttp/psr7`
2.11.0 → 2.13.1, `dompdf/dompdf` 3.1.5 → 3.1.6 — resolved inside the production PHP 8.3 container
(`composer update --no-install --with-all-dependencies`) so the lock matches what Nixpacks actually
installs. The commit's own `composer audit --locked` run against the new lock reported **zero**
advisories. The ignore-list entries for these two packages in `.composer-audit-ignore.json` now describe
advisories against *older* versions than what `composer.lock` currently pins (`guzzlehttp/guzzle`
≥ 7.15.2, `dompdf/dompdf` ≥ 3.1.6 — both satisfied) — kept because the reviewed reasoning (why each
is unreachable in this app) is still correct background if either advisory's fixed-version claim
turns out to be wrong, and removing an ignore entry that currently matches nothing is a cleanup with
no safety benefit, not a fix. **This is the state as of the commit above; `scripts/composer-audit-gate.php`
is what re-verifies it on every CI run** — a lock-file bump since then that reintroduces a
high/critical advisory would fail the gate again, which is the gate working as intended, not a
regression in this document.

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

### `release` — Release provenance (signed build attestation)

**Added 2026-09-22 (CICD-05).** Runs **only** when a tag matching `v*` is pushed
(`if: startsWith(github.ref, 'refs/tags/v')`) — never on a plain push to `main` or a PR. It is **not**
a required status check and has no `needs:` on the four jobs above: a tag is only ever pushed against
a commit that is already on `main`, which branch protection already required to pass all four checks
before merge, so re-running them here would just duplicate work already done. (If that assumption is
ever violated — a tag pushed at a non-`main` commit — this job still truthfully attests whatever it
built from that commit; it is simply not, by itself, proof that commit passed CI. The deploy step in
`RELEASE-CHECKLIST.md` is what actually checks that.)

Adding the `tags: ['v*']` trigger to the shared `on.push` block would, by itself, also re-run
`frontend`, `backend`, `secrets` and `sast` a redundant fifth time on every tag push (a tag push
satisfies the same `on.push` event). Each of those four jobs therefore carries
`if: ${{ !startsWith(github.ref, 'refs/tags/') }}`, added purely to cancel that side effect — their
real trigger conditions (push/PR to `main`) have not changed.

**Tag scheme: `vYYYY.MM.DD`** (e.g. `v2026.09.22`; a same-day second release appends a counter,
`v2026.09.22.2`). Chosen over `vN.N.N` because this repo already keys everything else to calendar
dates rather than a version counter — deploy dates, audit dates, and doc-revision dates are how every
other doc in this tree (`HANDOFF.md`, `CLAUDE.md`, the compliance evidence packs) already refers to a
point in time — so a release tag needs no separate counter file or negotiation over what the next
number is; whoever is deploying today writes today's date. This supersedes the older
`v<YYYY.MM.N>` example in `RELEASE-CHECKLIST.md`, updated alongside this change.

**What gets attested.** The job (job-scoped `permissions: { contents: read, id-token: write,
attestations: write }` — nothing else in the workflow gets those last two) builds two files at the
tagged commit and signs both with `actions/attest-build-provenance`:

- **`laravel/sbom/dmc-laravel.cdx.json`** — the same CycloneDX SBOM `scripts/sbom.php` produces in
  `backend` (deterministic from `composer.lock` + `package-lock.json`), regenerated here so the
  signed copy is built from the tagged commit, not carried over from an earlier `main` push.
- **`laravel/release/dmc-laravel-build.tar.gz`** — a tarball of the committed `public/build`
  (Nixpacks never runs Node on the host, CLAUDE.md §3, so this directory *is* the compiled frontend
  Coolify deploys, byte for byte) plus `composer.lock` and `package-lock.json` — the two files that
  pin exactly what `composer install` resolves at deploy time. Together these are the bytes that
  determine what reaches production. Application source (`app/`, `routes/`, `resources/js/*.vue`, …)
  is deliberately **not** repackaged: the git tag itself is that provenance, and every line of it
  already passed `secrets` and `sast`, plus the full PHPUnit/Vitest suites, before it could reach
  `main`.

Both files are archived on the workflow run via `actions/upload-artifact`
(`release-<tag>`, 90-day retention — the same convention as the SBOM archived in `backend`).

**What this proves.** That the named tag, at the exact commit `github.sha` records, built through
this exact GitHub Actions workflow (identity `https://github.com/ahmedsk2/dmc-patient-flow-hub/.github/workflows/laravel-ci.yml@<ref>`),
produced these exact bytes — bound cryptographically by SHA-256 digest and signed with GitHub's own
OIDC-backed Sigstore identity, not a maintainer-held key that could leak or be reused elsewhere. A
downloaded copy that fails verification was altered, or never came out of this pipeline at all.

**What this does NOT prove.** The container the app actually runs. Coolify builds a Nixpacks image
from `main` HEAD **on the host** (`docs/DEPLOY-LARAVEL.md`) at deploy time, entirely separately from
this job — this attestation covers what CI built and signed here, not that image. There is no
attestation chain from this tarball to the running container; closing that gap would mean building
the deploy image itself inside CI, which is a bigger change than CICD-05 asked for and is not what
this job does. Nothing here is pushed to any package/image registry (`push-to-registry` is unused).

**Deliberately left out: attaching the artifact to a GitHub Release.** This job's permissions are
read-only on `contents` (above); uploading a release asset needs `contents: write`, and widening a
read-only job just to save one manual download is not worth it. There is also no GitHub Release
object in this project's process today — `RELEASE-CHECKLIST.md`'s release record is the annotated git
tag itself, not a Release page. The 90-day workflow-run artifact plus the pushed tag is the release
record until the owner decides that trade-off is worth making.

**Verifying a downloaded artifact** (needs `gh` ≥ 2.60 with the `attestation` command; no extra auth
beyond a normal `gh auth login` against a repo you can read):

```sh
gh attestation verify laravel/release/dmc-laravel-build.tar.gz --repo ahmedsk2/dmc-patient-flow-hub
gh attestation verify laravel/sbom/dmc-laravel.cdx.json --repo ahmedsk2/dmc-patient-flow-hub
```

A successful verify prints `✓ Verification succeeded` together with the signing workflow's identity
and **the exact tag/commit it ran at** — matching cryptographically is not enough on its own; read
that tag/commit back and confirm it is the release you intended before trusting the file. The full
release procedure (tag → this job attests → deploy that exact `main` HEAD) is in
`RELEASE-CHECKLIST.md`.

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
| `actions/attest-build-provenance` | `4d101475d8b20a2381f78447822ac1eab6504dd8` | `v4` → `v4.2.2` (lightweight tag) | 2026-09-22 |

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
