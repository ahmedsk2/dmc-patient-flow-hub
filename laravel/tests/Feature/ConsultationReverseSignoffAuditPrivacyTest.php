<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * Defect (A) — walkthrough 2026-09-23: ConsultationsController::reverseSignoff() used to copy the
 * DECRYPTED response_note straight into audit_log.details['note'], so a clinical narrative sat in
 * PLAINTEXT in the (hash-chained, hourly-shipped, exportable) audit trail even though
 * consultations.response_note itself is encrypted at rest (CLAUDE.md §9 / DATA-06). The fix
 * re-encrypts the cleared note under the app's own encrypter (`note_encrypted`) plus a harmless
 * `note_length` marker, and never writes the plaintext anywhere in `details`.
 */
class ConsultationReverseSignoffAuditPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private const PLAINTEXT_NOTE = 'Patient counselled re: medication non-adherence, safeguarding concern raised.';

    private function admin(): User
    {
        return User::create([
            'username' => 'rsap_admin_'.substr(md5(uniqid('', true)), 0, 10),
            'name' => 'RSAP Admin', 'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 1,
            'email_verified_at' => now(),
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ]);
    }

    private function signedOffConsultation(?string $note): Consultation
    {
        return Consultation::create([
            'mrn' => (string) random_int(10000000, 99999999),
            'patient_name' => 'RSAP Patient',
            'age' => 61,
            'bed' => 'W-4',
            'current_location' => 'Ward',
            'consultation_from' => 'ER',
            'to_service' => 'Cardiology',
            'consultation_date' => now()->subDay()->toDateString(),
            'indication' => [],
            'status' => Consultation::STATUS_SIGNED_OFF,
            'signoff_date' => now()->toDateString(),
            'signed_off_at' => now(),
            'response_disposition' => 'taking_over',
            'response_followup_needed' => true,
            'response_note' => $note,
        ]);
    }

    public function test_reversing_a_signoff_never_writes_the_plaintext_note_to_the_audit_log(): void
    {
        $c = $this->signedOffConsultation(self::PLAINTEXT_NOTE);

        $this->actingAs($this->admin())
            ->post("/consultations/{$c->id}/reverse-signoff")
            ->assertRedirect()->assertSessionHas('flash.type', 'success');

        $row = AuditLog::where('action', 'consultation.reverse_signoff')
            ->where('entity_id', (string) $c->id)->firstOrFail();

        // The whole point: the clinical text must not be recoverable anywhere in the row, whether
        // under a `note` key or hiding inside some other field.
        $this->assertStringNotContainsString(self::PLAINTEXT_NOTE, json_encode($row->details));
        $this->assertArrayNotHasKey('note', $row->details, 'the plaintext key must not exist at all');

        // Forensic intent preserved: what was cleared is still recoverable by whoever already holds
        // APP_KEY (the same trust boundary as the live encrypted column), and its length is visible
        // without decrypting anything.
        $this->assertArrayHasKey('note_encrypted', $row->details);
        $this->assertIsString($row->details['note_encrypted']);
        $this->assertNotSame(self::PLAINTEXT_NOTE, $row->details['note_encrypted']);
        $this->assertSame(self::PLAINTEXT_NOTE, Crypt::decryptString($row->details['note_encrypted']));
        $this->assertSame(mb_strlen(self::PLAINTEXT_NOTE), $row->details['note_length']);

        // Everything else the old row carried is untouched.
        $this->assertSame('taking_over', $row->details['disposition']);
        $this->assertTrue($row->details['followup_needed']);
    }

    public function test_reversing_a_signoff_with_no_note_stores_no_ciphertext(): void
    {
        $c = $this->signedOffConsultation(null);

        $this->actingAs($this->admin())
            ->post("/consultations/{$c->id}/reverse-signoff")
            ->assertRedirect()->assertSessionHas('flash.type', 'success');

        $row = AuditLog::where('action', 'consultation.reverse_signoff')
            ->where('entity_id', (string) $c->id)->firstOrFail();

        $this->assertNull($row->details['note_encrypted']);
        $this->assertSame(0, $row->details['note_length']);
    }
}
