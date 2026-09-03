<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Consultation;
use App\Models\ConsultationFollowup;
use App\Models\Handover;
use App\Models\HandoverRevision;
use App\Models\Patient;
use App\Models\Specialty;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * DATA-06 (PDPL security measures) — application-level encryption of the four free-text clinical
 * narratives that are never searched, sorted or exported by value:
 *
 *   handovers.body, handover_revisions.body, consultations.response_note, consultation_followups.note
 *
 * The models carry Laravel's `encrypted` cast (AES-256-CBC under APP_KEY — the same mechanism that
 * already protects users.mfa_secret), so MySQL only ever holds ciphertext while every authorised
 * reader still sees plaintext. These tests pin three things: (a) the DB really holds ciphertext,
 * (b) every read path — model, and the raw latest-follow-up join on the handover sheet — still
 * yields plaintext, and (c) the one-off data migration encrypts existing rows in place, is
 * idempotent, and reverses cleanly. See docs/ENCRYPTION-AT-REST.md.
 */
class ClinicalNarrativeEncryptionTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_09_03_000000_encrypt_clinical_narratives.php';

    private const COLUMN = [
        'handovers' => 'body', 'handover_revisions' => 'body',
        'consultations' => 'response_note', 'consultation_followups' => 'note',
    ];

    private function user(array $overrides = []): User
    {
        return User::create(array_merge([
            'username' => 'enc_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'Enc User', 'password' => 'secret12345', 'role' => User::ROLE_CONSULTANT, 'active' => 1,
            'email_verified_at' => now(), 'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $overrides));
    }

    private function admission(array $overrides = []): Admission
    {
        $p = Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'Enc Patient']);

        return Admission::create(array_merge([
            'patient_id' => $p->id, 'admit_date' => now()->subDays(2)->toDateString(),
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0,
        ], $overrides));
    }

    private function consultation(array $extra = []): Consultation
    {
        return Consultation::create(array_merge([
            'mrn' => (string) random_int(10000000, 99999999),
            'patient_name' => 'Enc Consult', 'age' => 61, 'bed' => 'W-12',
            'current_location' => 'Ward', 'consultation_from' => 'ER',
            'consultation_date' => now()->subDays(4)->toDateString(),
            'requested_at' => now()->subDays(4),
            'indication' => [], 'other_indication' => null,
            'to_service' => 'Cardiology', 'status' => Consultation::STATUS_ACTIVE,
        ], $extra));
    }

    /** The column exactly as MySQL holds it — bypasses every Eloquent cast. */
    private function raw(string $table, int $id, string $column): ?string
    {
        return DB::table($table)->where('id', $id)->value($column);
    }

    /** Stored value is a Laravel ciphertext of $plain (not the plaintext, not containing it). */
    private function assertStoredEncrypted(string $table, int $id, string $column, string $plain): void
    {
        $stored = $this->raw($table, $id, $column);
        $this->assertNotNull($stored, "{$table}.{$column} is NULL");
        $this->assertNotSame($plain, $stored, "{$table}.{$column} is stored in PLAINTEXT");
        if ($plain !== '') {
            $this->assertStringNotContainsString($plain, $stored, "{$table}.{$column} leaks the plaintext");
        }
        $this->assertSame($plain, Crypt::decryptString($stored), "{$table}.{$column} does not decrypt under APP_KEY");
    }

    private function assertStoredPlaintext(string $table, int $id, string $column, string $plain): void
    {
        $this->assertSame($plain, $this->raw($table, $id, $column), "{$table}.{$column} is not plaintext");
    }

    private function migration(): object
    {
        return require base_path(self::MIGRATION);
    }

    // ---- (a) the casts: ciphertext at rest, plaintext through the model -------------------------

    public function test_handover_body_and_revision_body_are_ciphertext_at_rest_and_plaintext_through_the_model(): void
    {
        $owner = $this->user();
        $a = $this->admission(['consultant_id' => $owner->id]);
        $plain = 'Day 3: NIV overnight, wean in AM. Family updated.';

        $h = Handover::create(['admission_id' => $a->id, 'body' => $plain, 'updated_by' => $owner->id]);
        $r = HandoverRevision::create(['admission_id' => $a->id, 'body' => $plain, 'author_id' => $owner->id]);

        $this->assertStoredEncrypted('handovers', $h->id, 'body', $plain);
        $this->assertStoredEncrypted('handover_revisions', $r->id, 'body', $plain);

        $this->assertSame($plain, Handover::find($h->id)->body);
        $this->assertSame($plain, HandoverRevision::find($r->id)->body);
        // Eloquent's value()/pluck() are cast-aware — the shortcuts the existing tests rely on
        $this->assertSame($plain, Handover::where('id', $h->id)->value('body'));
        $this->assertSame([$plain], HandoverRevision::where('id', $r->id)->pluck('body')->all());
    }

    public function test_consultation_response_note_is_ciphertext_at_rest_and_plaintext_through_the_model(): void
    {
        $plain = 'Continue beta blocker, repeat echo in 6 weeks.';
        $c = $this->consultation(['response_note' => $plain]);

        $this->assertStoredEncrypted('consultations', $c->id, 'response_note', $plain);
        $this->assertSame($plain, Consultation::find($c->id)->response_note);
    }

    public function test_consultation_followup_note_is_ciphertext_at_rest_and_plaintext_through_the_model(): void
    {
        $c = $this->consultation();
        $plain = 'Rate controlled, continue beta blocker';
        $f = ConsultationFollowup::create(['consultation_id' => $c->id, 'followup_date' => now()->toDateString(),
            'note' => $plain, 'author_id' => null]);

        $this->assertStoredEncrypted('consultation_followups', $f->id, 'note', $plain);
        $this->assertSame($plain, ConsultationFollowup::find($f->id)->note);
        $this->assertSame($plain, $c->followups()->first()->note);
    }

    public function test_null_narratives_stay_null_in_both_directions(): void
    {
        $c = $this->consultation(['response_note' => null]);
        $f = ConsultationFollowup::create(['consultation_id' => $c->id, 'followup_date' => now()->toDateString(),
            'note' => null, 'author_id' => null]);

        $this->assertNull($this->raw('consultations', $c->id, 'response_note'));
        $this->assertNull($this->raw('consultation_followups', $f->id, 'note'));
        $this->assertNull(Consultation::find($c->id)->response_note);
        $this->assertNull(ConsultationFollowup::find($f->id)->note);
    }

    // ---- (e) the raw latest-follow-up join on the handover sheet -------------------------------

    public function test_handover_sheet_latest_followup_note_is_readable_plaintext(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $viewer = $this->user(['specialty_id' => $cardio->id, 'full_name' => 'Dr Cardio']);
        $c = $this->consultation(['owning_specialty_id' => $cardio->id, 'to_service' => $cardio->name]);

        ConsultationFollowup::create(['consultation_id' => $c->id, 'followup_date' => now()->subDays(2)->toDateString(),
            'note' => 'Older note that must not surface', 'author_id' => $viewer->id]);
        $latest = ConsultationFollowup::create(['consultation_id' => $c->id, 'followup_date' => now()->toDateString(),
            'note' => 'Rate controlled, continue beta blocker', 'author_id' => $viewer->id]);

        // guard the premise: the row the sheet joins to really is ciphertext at rest
        $this->assertStoredEncrypted('consultation_followups', $latest->id, 'note', 'Rate controlled, continue beta blocker');

        $this->actingAs($viewer)->get('/consultations/handover')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $row = collect($page->toArray()['props']['groups'])->flatMap(fn ($g) => $g['consultations'])->first();
                $this->assertSame('Rate controlled, continue beta blocker', $row['last_followup']['note']);
                $this->assertSame('Dr Cardio', $row['last_followup']['author']);
                $this->assertTrue($row['last_followup']['is_today']);
            });
    }

    public function test_handover_sheet_reports_a_null_note_as_null(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $viewer = $this->user(['specialty_id' => $cardio->id]);
        $c = $this->consultation(['owning_specialty_id' => $cardio->id, 'to_service' => $cardio->name]);
        ConsultationFollowup::create(['consultation_id' => $c->id, 'followup_date' => now()->toDateString(),
            'note' => null, 'author_id' => $viewer->id]);

        $this->actingAs($viewer)->get('/consultations/handover')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $row = collect($page->toArray()['props']['groups'])->flatMap(fn ($g) => $g['consultations'])->first();
                $this->assertNull($row['last_followup']['note']);
                $this->assertTrue($row['last_followup']['is_today']);
            });
    }

    // ---- (c)/(d) the in-place data migration ----------------------------------------------------

    /**
     * Seed PLAINTEXT rows straight into the four tables (raw inserts bypass the casts — exactly the
     * state production is in before the migration runs). Returns [table => [id => plaintext]] plus
     * the ids of a NULL-note and an empty-string-note follow-up.
     */
    private function seedPlaintext(int $revisions = 3): array
    {
        $owner = $this->user();
        $a = $this->admission(['consultant_id' => $owner->id]);
        $c = $this->consultation();   // response_note NULL at this point

        $ids = [];
        $ids['handovers'][DB::table('handovers')->insertGetId([
            'admission_id' => $a->id, 'body' => 'Plain handover body', 'updated_by' => $owner->id,
            'created_at' => now(), 'updated_at' => now(),
        ])] = 'Plain handover body';

        $rows = [];
        foreach (range(1, $revisions) as $i) {
            $rows[] = ['admission_id' => $a->id, 'body' => "Plain revision {$i}", 'author_id' => $owner->id, 'created_at' => now()];
        }
        DB::table('handover_revisions')->insert($rows);
        foreach (DB::table('handover_revisions')->where('admission_id', $a->id)->orderBy('id')->get(['id', 'body']) as $r) {
            $ids['handover_revisions'][$r->id] = $r->body;
        }

        DB::table('consultations')->where('id', $c->id)->update(['response_note' => 'Plain response note']);
        $ids['consultations'][$c->id] = 'Plain response note';

        $ids['consultation_followups'][DB::table('consultation_followups')->insertGetId([
            'consultation_id' => $c->id, 'followup_date' => now()->toDateString(),
            'note' => 'Plain followup note', 'author_id' => $owner->id, 'created_at' => now(),
        ])] = 'Plain followup note';
        // a NULL note and an EMPTY note: NULL must stay NULL, '' must round-trip as ''
        $ids['null_followup'] = DB::table('consultation_followups')->insertGetId([
            'consultation_id' => $c->id, 'followup_date' => now()->subDay()->toDateString(),
            'note' => null, 'author_id' => $owner->id, 'created_at' => now(),
        ]);
        $ids['empty_followup'] = DB::table('consultation_followups')->insertGetId([
            'consultation_id' => $c->id, 'followup_date' => now()->subDays(2)->toDateString(),
            'note' => '', 'author_id' => $owner->id, 'created_at' => now(),
        ]);

        return $ids;
    }

    public function test_migration_encrypts_pre_existing_plaintext_rows_in_all_four_tables(): void
    {
        $seed = $this->seedPlaintext();
        Log::spy();

        $this->migration()->up();

        foreach (self::COLUMN as $table => $column) {
            foreach ($seed[$table] as $id => $plain) {
                $this->assertStoredEncrypted($table, $id, $column, $plain);
            }
        }
        $this->assertNull($this->raw('consultation_followups', $seed['null_followup'], 'note'), 'NULL must stay NULL');
        $this->assertStoredEncrypted('consultation_followups', $seed['empty_followup'], 'note', '');

        // and the models now read every one of them back as plaintext
        $this->assertSame('Plain handover body', Handover::find(array_key_first($seed['handovers']))->body);
        $this->assertSame('Plain response note', Consultation::find(array_key_first($seed['consultations']))->response_note);
        $this->assertSame('Plain followup note', ConsultationFollowup::find(array_key_first($seed['consultation_followups']))->note);
        $this->assertSame('', ConsultationFollowup::find($seed['empty_followup'])->note);

        // counts are logged per table
        Log::shouldHaveReceived('info')
            ->withArgs(fn ($msg) => str_contains((string) $msg, 'handover_revisions'))
            ->atLeast()->once();
    }

    public function test_migration_is_idempotent_and_never_double_encrypts(): void
    {
        $seed = $this->seedPlaintext();
        // a row the CAST already encrypted (an app write that raced the deploy) must be left alone too
        $castWritten = ConsultationFollowup::create(['consultation_id' => array_key_first($seed['consultations']),
            'followup_date' => now()->subDays(3)->toDateString(), 'note' => 'written through the cast', 'author_id' => null]);

        $this->migration()->up();
        $first = $this->snapshot($seed, $castWritten->id);

        $this->migration()->up();   // second run: byte-for-byte unchanged (a re-encrypt would mint a new IV)
        $this->assertSame($first, $this->snapshot($seed, $castWritten->id));

        $this->assertSame('written through the cast', Crypt::decryptString($this->raw('consultation_followups', $castWritten->id, 'note')));
    }

    public function test_migration_down_restores_plaintext_and_is_itself_idempotent(): void
    {
        $seed = $this->seedPlaintext(revisions: 2);
        $this->migration()->up();
        foreach (self::COLUMN as $table => $column) {
            foreach ($seed[$table] as $id => $plain) {
                $this->assertStoredEncrypted($table, $id, $column, $plain);   // premise
            }
        }

        $this->migration()->down();

        foreach (self::COLUMN as $table => $column) {
            foreach ($seed[$table] as $id => $plain) {
                $this->assertStoredPlaintext($table, $id, $column, $plain);
            }
        }
        $this->assertNull($this->raw('consultation_followups', $seed['null_followup'], 'note'));
        $this->assertStoredPlaintext('consultation_followups', $seed['empty_followup'], 'note', '');

        $this->migration()->down();   // already plaintext — nothing to do, nothing corrupted
        foreach (self::COLUMN as $table => $column) {
            foreach ($seed[$table] as $id => $plain) {
                $this->assertStoredPlaintext($table, $id, $column, $plain);
            }
        }
    }

    public function test_migration_processes_more_rows_than_one_chunk(): void
    {
        // 600 revisions > the 500-row chunk: proves the chunkById pagination survives rewriting
        // the very column it pages over (the classic chunk() trap)
        $seed = $this->seedPlaintext(revisions: 600);
        $this->assertCount(600, $seed['handover_revisions']);

        $this->migration()->up();

        $encrypted = 0;
        foreach (DB::table('handover_revisions')->orderBy('id')->get(['id', 'body']) as $r) {
            $this->assertSame($seed['handover_revisions'][$r->id], Crypt::decryptString($r->body));
            $encrypted++;
        }
        $this->assertSame(600, $encrypted);
    }

    public function test_a_plaintext_row_is_unreadable_through_the_model_until_migrated(): void
    {
        // documents the hard dependency: deploy MUST run the migration — a stray plaintext row is a
        // DecryptException, never silently served as if it were ciphertext
        $seed = $this->seedPlaintext();
        $this->expectException(DecryptException::class);
        Handover::find(array_key_first($seed['handovers']))->body;
    }

    /** Raw bytes of every seeded narrative + the cast-written one, for byte-equality across runs. */
    private function snapshot(array $seed, int $extraFollowupId): array
    {
        $out = [];
        foreach (self::COLUMN as $table => $column) {
            foreach (array_keys($seed[$table]) as $id) {
                $out["{$table}:{$id}"] = $this->raw($table, $id, $column);
            }
        }
        $out['consultation_followups:extra'] = $this->raw('consultation_followups', $extraFollowupId, 'note');
        $out['consultation_followups:empty'] = $this->raw('consultation_followups', $seed['empty_followup'], 'note');

        return $out;
    }
}
