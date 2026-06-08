# DMC Internal Medicine — Laravel re-platform

A modern, parallel rebuild of the DMC patient-flow hub on **Laravel 13 + Inertia 2 + Vue 3 +
Tailwind v4 + ApexCharts**, themed for the Eastern Health Cluster (EHC). It lives alongside the
existing renovated PHP app (on the `renovation` branch) without touching it; this app is on the
`laravel-replatform` branch under `laravel/`.

The exact EHC brand colors and logo are placeholders for now (teal/navy/gold healthcare palette in
`resources/css/app.css` `@theme`) — drop the final tokens there and rebuild.

## Stack & architecture

- **Backend:** Laravel 13 (PHP 8.3), Eloquent, server-side validation, transactions, audit log.
- **Frontend:** Inertia SPA (Vue 3 `<script setup>`), Tailwind v4, ApexCharts (`vue3-apexcharts`).
- **DB:** clean normalized schema in `dmc_laravel` (InnoDB). A read-only `legacy` connection points at
  the original database (`dmc_prod`) and is used **only** by the import command.

### Clean schema (vs the legacy `picupatients` model)
- `patients` — canonical, one row per MRN (the identity the legacy schema never had).
- `admissions` — one row per episode (re-admission / ward↔ICU transfer = new row), FK to `patients`,
  consultant/admitted_by/discharged_by FKs to `users`, indexed date columns.
- `admission_diagnoses` — ICD-10 codes normalized out of the legacy JSON array.
- `consultations` — MRN as VARCHAR (legacy was INT, which truncated), JSON indication, FKs.
- `users` — clinical roles (0 Admin, 2 Registrar, 3 Consultant, 4 Resident, 5 Observer), capability
  flags (assign/add/manage/modify), MFA columns, `legacy_id`.
- `specialties`, `icd10` (covering-indexed), `consultation_reasons`, `tb_diagnoses`, `countries`,
  `settings`, `audit_log`.

## Setup (local, WAMP/MySQL)

```bash
cd laravel
composer install
npm install

# configure .env: DB_DATABASE=dmc_laravel (app), LEGACY_DB_DATABASE=dmc_prod (read-only source)
php artisan key:generate

php artisan migrate:fresh          # build the clean schema
php artisan legacy:import          # transform the original DB -> clean schema (idempotent)

npm run build                      # or: npm run dev
php artisan serve --port=8001
```

Then open http://127.0.0.1:8001 and sign in **by username**.

### `php artisan legacy:import`
Truncates the new tables and re-derives everything from the `legacy` connection — **faithful and
idempotent**. It:
- imports reference data, users (member_name de-duped on collision, bcrypt password kept as-is),
- builds one canonical `patient` per trimmed MRN, then every admission episode (MRN-less episodes are
  preserved under `NOMRN-<id>` placeholders so **no record is dropped**),
- normalizes the diagnosis JSON into `admission_diagnoses`, imports consultations + settings.

Verified row-for-row against the source (total admissions and active census match exactly).

## Features (parity)

| Area | Status |
|---|---|
| Login (username + bcrypt, active-only), logout, session | ✅ |
| Dashboard — KPIs + 5 ApexCharts on live data | ✅ |
| Patients board — search/filter/paginate, LOS bands, flags | ✅ |
| Flow actions — assign / transfer (ward↔ICU) / discharge (+ admin reverse) | ✅ |
| New Admission — transactional, ICD-10 typeahead, session-attributed | ✅ |
| Consultations — list, create, sign-off | ✅ |
| Registry — historical search + streamed CSV export | ✅ |
| Statistics — date-range KPIs + charts (sargable SQL) | ✅ |
| Reports — printable A4 annual report (print / save-as-PDF) | ✅ |
| Control panel (admin) — settings, user role/capability management | ✅ |
| Profile — edit details, change password | ✅ |

### Security improvements baked in (vs legacy)
- Authorization enforced **server-side** on every write (capability + ownership checks), not UI-only.
- Audit attribution (`admitted_by`, `discharged_by`, `entered_by`) comes from the **session**, never
  the client. Every write records an `audit_log` row.
- Prepared statements throughout (Eloquent / query builder), CSRF on by default, validation on all input,
  transactions on multi-row changes (transfer), bcrypt passwords.

## Deferred / next
- MFA (TOTP) enrollment + login challenge (columns exist; UI not yet built).
- Server-side PDF rendering (currently browser print for A4).
- The exact EHC palette/logo (placeholder theme until provided).
- Fine-grained per-role read access on Statistics/Registry/Reports (currently any authenticated user;
  Control is admin-only).
