<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * DATA-06 — the `encrypted` cast for clinical narratives, tolerant of a legacy PLAINTEXT value on read.
 *
 * Laravel's stock `encrypted` cast throws DecryptException the moment it meets a value that is not
 * ciphertext. For these columns that would turn ONE unmigrated row — a write from the previous
 * container during the deploy window, a raw insert, a restored pre-encryption dump — into a 500 on
 * the handover sheet for EVERY patient. Here a value that does not decrypt is served as-is and
 * logged (table / id / column), and it becomes ciphertext on its next save through the model.
 *
 * Writes are byte-for-byte what the stock cast does (Crypt::encryptString), so the data migration's
 * "already ciphertext?" detection and every raw-column assertion in the tests are unaffected.
 *
 * Trade-off, deliberately accepted: with a WRONG APP_KEY the UI shows base64 ciphertext instead of
 * an exception. The warning log line is the signal — see docs/ENCRYPTION-AT-REST.md §3 and §8.
 */
final class EncryptedNarrative implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return Crypt::decryptString((string) $value);
        } catch (DecryptException) {
            Log::warning('EncryptedNarrative: value is not ciphertext under the configured key(s) — served as-is, will be encrypted on next save', [
                'table' => $model->getTable(),
                'id' => $model->getKey(),
                'column' => $key,
            ]);

            return (string) $value;
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : Crypt::encryptString((string) $value);
    }
}
