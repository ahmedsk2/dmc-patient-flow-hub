# 0004 — Clinical narratives encrypted in the application under `APP_KEY`, via a hand-rolled cast

- **Status:** Accepted
- **Date:** 2026-09-03 (commits `88aeb34`, `184557d`; PRR finding DATA-06)

## Context

PDPL "security measures" and PRR finding DATA-06 called for encryption at rest of the free-text
clinical content. The realistic exposure is a leaked dump or backup, not a stolen disk. Two
alternatives were considered and rejected in `ENCRYPTION-AT-REST.md`:

- **MySQL InnoDB tablespace encryption** needs a keyring plugin whose key manifest, on the
  Coolify-managed stock MySQL container, is not on a persistent volume: a container recreate would
  lose it and MySQL would refuse to open the tablespaces — an availability risk to a live clinical
  system, and no protection for a dump.
- **Laravel's stock `encrypted` cast** throws on any value that is not ciphertext. One unmigrated
  row — a deploy-window write, a raw insert, a restored pre-encryption dump — would be a 500 on the
  handover sheet for every patient.

## Decision

Encrypt exactly four columns at the application layer through `App\Casts\EncryptedNarrative`:
`handovers.body`, `handover_revisions.body`, `consultations.response_note`,
`consultation_followups.note`. Writes use `Crypt::encryptString` (AES-256-CBC, random IV per value,
HMAC-SHA256, keyed by `APP_KEY`) — the mechanism already protecting `users.mfa_secret` and
`settings.mail_password`. Reads tolerate a plaintext value: serve it as-is, log one `warning` naming
table / id / column, encrypt it on the row's next save.

These four are the only **pure narrative** columns — never used in a `WHERE`, `ORDER BY`, join,
export or statistic. Everything else stays plaintext because the app filters, sorts, joins or
aggregates on it in SQL.

## Consequences

- **`APP_KEY` is now the root of trust.** A backup not paired with the key current at the time is an
  incomplete backup of the narratives; there is no recovery without it. Never run `key:generate` on
  a live environment; any environment loading a copy of production must carry that key or list it
  in `APP_PREVIOUS_KEYS`.
- A **wrong** key no longer errors — narratives render as base64 and the log fills with
  `EncryptedNarrative:` warnings, which is the alarm.
- Developer rules: read through the model; a raw read returns ciphertext and must decrypt explicitly,
  with a test; never filter/sort/group on these columns; `toArray()` decrypts, so keep them out of
  exports, notifications, logs, dashboards and e-mail.
- Rotation is manual (§4): Laravel has no `key:rotate`.
- Encryption at rest stays **partial**: everything else relies on OCI's default volume encryption.

## References

- `laravel/docs/ENCRYPTION-AT-REST.md` (all sections; §2 rejects tablespace encryption, §8 the stock cast)
- `laravel/app/Casts/EncryptedNarrative.php`
- `CLAUDE.md` §9, §13
- `laravel/tests/Feature/ClinicalNarrativeEncryptionTest.php`
- `laravel/docs/compliance/CONFIRMED-FACTS.md` C5
- `laravel/docs/BACKUP-AND-RESTORE.md` §1, §5 step 7
