# Encryption at rest — clinical narratives (DATA-06)

> **One-line summary:** the four free-text clinical narratives are AES-256 encrypted at the
> application layer under `APP_KEY`. **`APP_KEY` is now the root of trust for those columns —
> lose it and they are unrecoverable.** Back it up with the same care as the database itself.

Status: shipped 2026-09-03 (PRR finding DATA-06 / Saudi PDPL "security measures").
Verification: `tests/Feature/ClinicalNarrativeEncryptionTest.php` (plus the extended
`HandoverTest::test_save_creates_handover_revision_and_audit` and
`ConsultationLedgerW2aTest::test_signoff_records_status_actor_time_and_the_structured_response`).

---

## 1. What is encrypted

| Table.column | Model cast | What it holds |
|---|---|---|
| `handovers.body` | `App\Models\Handover` | the CURRENT handover text per admission |
| `handover_revisions.body` | `App\Models\HandoverRevision` | append-only handover history |
| `consultations.response_note` | `App\Models\Consultation` | the free-text sign-off response |
| `consultation_followups.note` | `App\Models\ConsultationFollowup` | the optional daily follow-up one-liner |

Mechanism: Laravel's `'encrypted'` Eloquent cast — `Illuminate\Encryption\Encrypter`,
**AES-256-CBC with a random IV per value and an HMAC-SHA256 MAC**, keyed by `APP_KEY`
(`config('app.key')`). This is the *same* mechanism that already protects `users.mfa_secret`
and `settings.mail_password`. MySQL only ever holds the base64 JSON payload
(`{"iv","value","mac","tag"}`); the model decrypts transparently on read and encrypts on write.

Two properties follow directly from "random IV per value":

- The same sentence saved twice produces two different ciphertexts (no equality leakage).
- A re-encryption of an unchanged value is detectable by byte inequality — the data migration's
  idempotency test relies on exactly this.

### Why these four and nothing else

They are the only columns that are **pure narrative**: never used in a `WHERE`, `LIKE`,
`ORDER BY`, `GROUP BY`, join condition, export column or statistic (orchestrator-verified
across `app/` before this change). Encrypting a column makes it opaque to MySQL — it can no
longer be indexed, searched, sorted or compared — so the candidates are precisely the columns
the application only ever *displays*.

## 2. What is NOT encrypted, and why

| Data | Why it stays plaintext |
|---|---|
| MRN, patient name, age/gender/nationality | searched (`LIKE`), joined, exported, and the Registry sorts on them. Deterministic encryption would be needed to keep equality search, and that leaks equality. |
| Admission/discharge/consultation dates, LOS | every statistic, dashboard tile, ageing rule and A4 report filters and aggregates on them in SQL. |
| ICD-10 codes, `admission_diagnoses`, consultation `indication` ids | joined to reference tables and counted per code. |
| Bed, location, consultant / specialty / status columns | the board, the worklist and the ledger scopes filter on them. |
| Audit log `details` JSON | the audit trail is a hash-chained, shippable record (`audit:verify`, `audit:ship`); encrypting its payload would break external verification. **Note:** `consultation.reverse_signoff` deliberately preserves the cleared `response_note` plaintext inside the audit row (that is where an undone clinical assertion belongs). That copy is *outside* this change's scope and remains plaintext — flagged for the follow-up review. |

**Why not full-disk / tablespace encryption instead?** MySQL's InnoDB tablespace encryption
needs a keyring plugin whose key manifest lives on the DB host. On the Coolify-managed stock
MySQL container that manifest is not on a persistent volume, so a container recreate would
lose it and MySQL would refuse to open the encrypted tablespaces — an availability risk to a
live clinical system that outweighs the benefit. Application-level encryption of the
narratives is the reversible, container-agnostic step; it also protects against the more
realistic exposure (a leaked dump / backup file), which tablespace encryption does not.

## 3. `APP_KEY` is now the root of trust — operator obligations

Before this change, losing `APP_KEY` cost you sessions, `mfa_secret` (users re-enrol) and the
stored SMTP password (re-enter it). **Now it also costs every handover and consultation
narrative ever written.** There is no recovery path without the key: the ciphertext is
AES-256 and the key is never stored in the database.

Therefore:

1. **Back up `APP_KEY` with the database, every time.** A database backup that is not paired
   with the `APP_KEY` current at the time (or the full `APP_PREVIOUS_KEYS` chain, see §4) is an
   *incomplete* backup of the narratives. Store the key in the same secrets store / escrow the
   DB backup credentials live in, under the same access control — **never in git**, never in a
   ticket, never in chat.
2. **Add it to the restore runbook** (`docs/DEPLOY-LARAVEL.md` → restore section): a restore
   is "restore the dump **and** set `APP_KEY` (and `APP_PREVIOUS_KEYS`) to the values that were
   live when the dump was taken". Test the restore by opening a handover in the restored app.
3. **Any environment that loads a copy of the production database** (a staging/demo reload, a
   local reproduction of a bug) must carry production's `APP_KEY` — or list it in
   `APP_PREVIOUS_KEYS` — or every narrative read will throw
   `Illuminate\Contracts\Encryption\DecryptException` (the app is deliberately strict: it never
   silently shows ciphertext or an empty string in place of a clinical note).
4. **Never regenerate `APP_KEY` casually.** `php artisan key:generate` on a live environment
   without following §4 makes every narrative unreadable instantly.

## 4. Key rotation

Laravel has **no `key:rotate` command** and nothing that re-encrypts `encrypted` casts for you.
What it does have (verified in `vendor/laravel/framework/src/Illuminate/Encryption/`):

- `config/app.php` → `'previous_keys' => [...explode(',', env('APP_PREVIOUS_KEYS', ''))]`.
- `EncryptionServiceProvider::registerEncrypter()` passes those keys to
  `Encrypter::previousKeys()`.
- `Encrypter::decrypt()` iterates `getAllKeys()` = **current key first, then every previous
  key**, accepting the first whose MAC validates. **Encryption always uses the current key.**

So rotation is graceful for *reads*, and the only work is re-encrypting stored rows under the
new key. Procedure:

```text
1. Generate a new key WITHOUT applying it:      php artisan key:generate --show
2. Back up the DB and record the OLD key (§3).
3. In the environment (Coolify → app → Environment):
       APP_PREVIOUS_KEYS=<old key>          (comma-separated if several are already listed)
       APP_KEY=<new key>
   Redeploy / restart. Reads keep working (old key is tried), new writes use the new key.
   Side effects of changing APP_KEY: all sessions are invalidated (users log in again);
   users.mfa_secret and settings.mail_password decrypt via the previous-keys chain, so MFA
   and outbound mail keep working.
4. Re-encrypt the stored narratives under the NEW key.
   The data migration (2026_09_03_000000) canNOT be reused for this: its idempotency rule
   skips any value that decrypts under ANY configured key, which now includes the old one.
   Run the loop below in `php artisan tinker` (or wrap it in a one-off command); it is the
   migration's chunked/transactional walk with the "skip if readable" test removed:

       use Illuminate\Support\Facades\{Crypt, DB};
       foreach (['handovers' => 'body', 'handover_revisions' => 'body',
                 'consultations' => 'response_note', 'consultation_followups' => 'note'] as $t => $col) {
           DB::table($t)->select(['id', $col])->whereNotNull($col)->orderBy('id')
               ->chunkById(500, fn ($rows) => DB::transaction(function () use ($rows, $t, $col) {
                   foreach ($rows as $r) {
                       DB::table($t)->where('id', $r->id)
                           ->update([$col => Crypt::encryptString(Crypt::decryptString($r->{$col}))]);
                   }
               }));
       }

   (Crypt::decryptString accepts old-or-new key; Crypt::encryptString always writes the new key.)
   Do the same for users.mfa_secret and settings.mail_password if you intend to retire the old
   key completely.
5. Verify with ONLY the new key before dropping the old one — e.g. in tinker:

       $e = new Illuminate\Encryption\Encrypter(base64_decode(substr(env('APP_KEY'), 7)), 'AES-256-CBC');
       DB::table('handover_revisions')->whereNotNull('body')->orderBy('id')
           ->chunkById(500, fn ($rows) => $rows->each(fn ($r) => $e->decryptString($r->body)));
       // throws DecryptException on the first row still under the old key

6. Remove the old key from APP_PREVIOUS_KEYS, redeploy. Keep the old key in escrow alongside
   any backup taken while it was live (a restore of such a backup needs it — §3).
```

## 5. How the data migration works (and how to reverse it)

`database/migrations/2026_09_03_000000_encrypt_clinical_narratives.php` — a **data** migration
(no schema change: all four columns were already `TEXT`).

- Walks each of the four tables with `chunkById(500)` — paginated on the primary key, so
  rewriting the very column being paged over cannot skip or repeat rows (the classic `chunk()`
  trap). Covered by `test_migration_processes_more_rows_than_one_chunk` (600 rows).
- **One transaction per chunk.** A crash mid-run leaves whole chunks either done or untouched;
  simply re-run `php artisan migrate` (or the migration's `up()`) to finish.
- **Idempotent, never double-encrypts.** Each value is tried with `Crypt::decryptString()`: a
  plaintext narrative is not a valid Laravel payload and throws `DecryptException` → encrypt it;
  a value that decrypts cleanly is already ciphertext → skip. Re-running is a no-op
  byte-for-byte (`test_migration_is_idempotent_and_never_double_encrypts`).
- `NULL` stays `NULL`; `''` is encrypted like any other string (the cast does the same on write).
- Logs one line per table to the application log:
  `encrypt_clinical_narratives: ENCRYPT handover_revisions.body — N non-null rows seen, N rewritten, N already in target state`.
- **`down()` is a real reversal**: same walk, decrypting every value that decrypts and leaving
  plaintext alone (`test_migration_down_restores_plaintext_and_is_itself_idempotent`). A row that
  cannot be decrypted with the configured keys is left as is and counted in the log — it is never
  blanked. **Remember to remove the casts from the four models if you roll back**, or the models
  will throw on the restored plaintext.

**Deploy is a hard dependency.** From this release on, the models *require* ciphertext: a
plaintext row read through a model throws `DecryptException` (pinned by
`test_a_plaintext_row_is_unreadable_through_the_model_until_migrated`). The standard deploy
already runs `php artisan migrate` before serving the new code (`docs/DEPLOY-LARAVEL.md`); keep
it that way, and take the pre-migrate backup as usual.

## 6. Rules for developers touching these columns

1. **Read through the model.** `$handover->body`, `$revision->body`, `$consultation->response_note`,
   `$followup->note` — and Eloquent's `->value('body')` / `->pluck('body')` — are cast-aware and
   return plaintext.
2. **A raw read bypasses the cast.** `DB::table(...)`, `joinSub`, `selectRaw`, `DB::select` all
   return ciphertext. The one such site in the codebase is the handover sheet's latest-follow-up
   join in `App\Http\Controllers\ConsultationsController::handover()` (kept raw so it stays one
   query, not one per row); it decrypts explicitly with
   `$f->note === null ? null : Crypt::decryptString($f->note)`. Do the same for any new raw read,
   and add a test in `ClinicalNarrativeEncryptionTest` for it.
3. **Never filter/sort/group on these columns in SQL** — the result would be silently wrong
   (ciphertext order/equality is random). If a real search need arrives, that is a design change
   (a separate search index of authorised excerpts), not a `LIKE`.
4. **`toArray()` / JSON serialisation DECRYPTS.** Shipping a raw model to Inertia or an API
   response includes the plaintext. That is correct for the places these narratives are already
   shown to authorised clinicians (handover editor/API, signature inbox, bulk-reassign preflight,
   consultation sign-off view, service handover sheet). Do not add them to any payload that goes
   somewhere they are not already shown (exports, notifications, logs, dashboards, e-mails). If a
   model ever needs to be serialised *without* its narrative, use `$hidden` — the same gotcha
   documented on `App\Models\Setting::$mail_password`.
5. **Validation limits stay as they are** (`body` ≤ 5,000 chars, `response_note` ≤ 2,000,
   `note` ≤ 500). Ciphertext is roughly 1.4× the base64 of the plaintext plus ~100 bytes of
   IV/MAC/JSON framing; a 5,000-character fully multibyte body (~20 KB) becomes ~38 KB, well
   inside `TEXT`'s 65,535-byte limit. Do not raise the 5,000 limit past ~8,000 chars without
   widening the column to `MEDIUMTEXT`.

## 7. Performance

- **Per request:** one AES decrypt (+ HMAC verify) per narrative shown — microseconds each via
  OpenSSL. The heaviest pages decrypt a few dozen values (handover API: current + 20 revisions;
  handover sheet: one note per open consult); this is noise next to the DB round trip.
- **No query-plan impact:** none of the four columns was indexed or used in a predicate.
- **Storage:** ~1.4× growth on these four columns only; at current volumes (thousands of
  revisions, ~1.3k consultations) this is a few hundred KB.
- **Migration:** encrypts in 500-row chunks with one short transaction each; at current volumes
  it completes in seconds and holds no long locks. It is safe to run during the normal deploy
  window.

## 8. Why the cast tolerates plaintext on read

The four columns use `App\Casts\EncryptedNarrative`, not Laravel's stock `encrypted` cast. Writes are
identical (`Crypt::encryptString`); the difference is the read path. The stock cast throws
`DecryptException` on any value that is not ciphertext, so one unmigrated row — a write from the
previous container during the deploy window, a raw insert, a restored pre-encryption dump — would
turn into a 500 on the handover sheet for every patient. The tolerant cast serves such a value as-is,
writes one `warning` log line naming table / id / column, and encrypts it on the row's next save.

Operator consequence: a **wrong `APP_KEY`** no longer errors — narratives render as base64 ciphertext
and the log fills with `EncryptedNarrative:` warnings. Treat that log line as the alarm (§3). A clean
production log after a deploy means every row is ciphertext under the current key.
