<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DATA-06 (PDPL security measures) — encrypt the four free-text clinical narratives IN PLACE.
 *
 *   handovers.body, handover_revisions.body, consultations.response_note, consultation_followups.note
 *
 * From this migration on, the models carry Laravel's `encrypted` cast for these columns, so every
 * NEW write is ciphertext (AES-256-CBC under APP_KEY, exactly like users.mfa_secret). This
 * migration brings the EXISTING rows up to the same state. It is a data migration, not a schema
 * one: the columns are already TEXT (64 KiB), which comfortably holds the ~1.4x base64 growth of
 * even a 5,000-character multibyte handover body, so nothing structural changes.
 *
 * How it works
 *   - Chunks of 500 rows (chunkById — paginates on the PK, so rewriting the very column being
 *     paged over cannot skip or repeat rows the way chunk() would).
 *   - One DB transaction PER CHUNK: a crash mid-way leaves whole chunks either done or untouched,
 *     never a half-written row, and a re-run simply picks up where it left off.
 *   - Idempotent: a value that already decrypts under the current APP_KEY (or one of the
 *     APP_PREVIOUS_KEYS) is skipped — never double-encrypted. Detection is "try to decrypt": a
 *     plaintext narrative is not a valid Laravel payload and throws DecryptException. (Theoretical
 *     limit: a note whose plaintext IS a valid Laravel payload would be mistaken for ciphertext.
 *     Clinical prose does not look like base64-wrapped JSON; accepted.)
 *   - NULL stays NULL; '' becomes the ciphertext of '' (the cast does the same on write).
 *   - down() is the exact reversal: decrypt every value that decrypts, leave plaintext alone.
 *     A row that cannot be decrypted with the configured keys is left AS IS (and counted), which
 *     is the only safe thing to do — see docs/ENCRYPTION-AT-REST.md on why APP_KEY is now the
 *     root of trust for these columns.
 *
 * Uses the query builder + Crypt directly — models may change shape later; this must not.
 */
return new class extends Migration
{
    private const CHUNK = 500;

    /** table => encrypted column. Every table has an auto-increment `id` PK. */
    private const TARGETS = [
        'handovers' => 'body',
        'handover_revisions' => 'body',
        'consultations' => 'response_note',
        'consultation_followups' => 'note',
    ];

    public function up(): void
    {
        foreach (self::TARGETS as $table => $column) {
            $this->rewrite($table, $column, encrypt: true);
        }
    }

    public function down(): void
    {
        foreach (self::TARGETS as $table => $column) {
            $this->rewrite($table, $column, encrypt: false);
        }
    }

    /**
     * Walk one table and rewrite each non-NULL value into the target state, skipping any value
     * that is already there. $encrypt=true: plaintext -> ciphertext. $encrypt=false: the reverse.
     */
    private function rewrite(string $table, string $column, bool $encrypt): void
    {
        $seen = 0;
        $changed = 0;
        $skipped = 0;

        DB::table($table)
            ->select(['id', $column])
            ->whereNotNull($column)
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($rows) use ($table, $column, $encrypt, &$seen, &$changed, &$skipped) {
                DB::transaction(function () use ($rows, $table, $column, $encrypt, &$seen, &$changed, &$skipped) {
                    foreach ($rows as $row) {
                        $seen++;
                        $stored = (string) $row->{$column};
                        $plain = $this->tryDecrypt($stored);   // null => not ciphertext we can read

                        if ($encrypt) {
                            if ($plain !== null) {          // already encrypted — never re-encrypt
                                $skipped++;
                                continue;
                            }
                            $new = Crypt::encryptString($stored);
                        } else {
                            if ($plain === null) {          // already plaintext (or undecryptable) — leave it
                                $skipped++;
                                continue;
                            }
                            $new = $plain;
                        }

                        DB::table($table)->where('id', $row->id)->update([$column => $new]);
                        $changed++;
                    }
                });
            });

        Log::info(sprintf(
            'encrypt_clinical_narratives: %s %s.%s — %d non-null rows seen, %d rewritten, %d already in target state',
            $encrypt ? 'ENCRYPT' : 'DECRYPT', $table, $column, $seen, $changed, $skipped,
        ));
    }

    /** The plaintext if $value is a Laravel ciphertext readable with the configured key(s); else null. */
    private function tryDecrypt(string $value): ?string
    {
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }
    }
};
