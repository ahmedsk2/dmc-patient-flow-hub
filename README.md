# DMC Patient-Flow Hub

Hospital patient-flow system for an Internal Medicine unit (admissions, consultant assignment,
ward/ICU transfers, two-phase discharge, consultations, registry search, dashboards, statistics,
printable/PDF reports).

**This repository contains two implementations of the same system:**

| | Where | Branch | Status |
|---|---|---|---|
| **Laravel re-platform** (go-forward) | [`laravel/`](laravel/) | `main` | Full feature parity, tested (22 tests + CI), reconciled row-for-row against the live data |
| **Renovated legacy PHP app** | repo root (`*.php`) | `renovation` (also present here) | The original procedural app, security-hardened; kept guarded and deployable as fallback |

Start here:

- **Project overview for reviewers:** [`PROJECT-OVERVIEW.md`](PROJECT-OVERVIEW.md)
- **Laravel local setup:** [`laravel/README.md`](laravel/README.md)
- **Laravel production deploy:** [`laravel/docs/DEPLOY-LARAVEL.md`](laravel/docs/DEPLOY-LARAVEL.md)
- **Legacy app deploy:** [`DEPLOY.md`](DEPLOY.md)
- **Database & per-action behavior:** [`laravel/docs/DATABASE-AND-BEHAVIOR.md`](laravel/docs/DATABASE-AND-BEHAVIOR.md)
- **Every metric's exact formula:** [`laravel/docs/DASHBOARD-AND-STATISTICS-METRICS.md`](laravel/docs/DASHBOARD-AND-STATISTICS-METRICS.md)
- **Legacy↔Laravel data reconciliation:** [`laravel/docs/RECONCILIATION.md`](laravel/docs/RECONCILIATION.md)

## Quick start (Laravel app)

```bash
git clone -b main https://github.com/ahmedsk2/dmc-patient-flow-hub.git
cd dmc-patient-flow-hub/laravel
composer install && npm install
cp .env.example .env && php artisan key:generate    # set DB_* in .env
php artisan migrate                                  # build the schema
# data: import the dmc_laravel export, or: php artisan legacy:import (from the original DB)
npm run build && php artisan serve
```

> ⚠️ This system holds real patient data (PHI) in production. Database dumps, `.env`, and
> `config.local.php` are git-ignored on purpose — never commit them, and deploy only behind TLS.
