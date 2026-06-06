# Database migrations

Run these SQL files **once each, in numeric order**, against the DMC database
(e.g. `mysql dbname < migrations/01-audit-log.sql`) as part of deploying the
`renovation` branch. They are additive and safe to run on the live schema, but
**take a database backup first**.

| # | File | Purpose | Required by |
|---|------|---------|-------------|
| 01 | `01-audit-log.sql` | `audit_log` table — who changed/deleted what, when | Batch 8 (audit trail, REL-02) |
| 02 | `02-password-resets.sql` | `password_resets` table + widen `members.member_password` to varchar(255) | Batch 11 (secure reset tokens, SEC-14/30) |
| 03 | `03-innodb-and-indexes.sql` | convert clinical tables MyISAM→InnoDB (enables transactions) + add MRN/consultant/location/signoff indexes | Batch 14 (data integrity & performance, D1/D3/REL-01) |

> Until a migration is applied, the related feature degrades gracefully:
> `audit_log()` is fail-safe (it logs to the PHP error log and never breaks the
> action if the table is missing).
