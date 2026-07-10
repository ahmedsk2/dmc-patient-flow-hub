<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\AdmissionDiagnosis;
use App\Models\Consultation;
use App\Models\Icd10;
use App\Models\Patient;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Registry search modes that previously had zero coverage: the diagnosis AND/OR multi-code
 * branch, the free-text ICD-10 keyword mode (incl. the <2-char guard), and consultations mode.
 */
class RegistryModesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create([
            'username' => 'rm_' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'RM Admin', 'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ]);
    }

    private function admissionWithDx(string $mrn, array $codes): Admission
    {
        $p = Patient::create(['mrn' => $mrn, 'name' => "Pt {$mrn}"]);
        $a = Admission::create(['patient_id' => $p->id, 'admit_date' => '2024-03-01',
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0]);
        $seq = 1;
        foreach ($codes as $c) {
            AdmissionDiagnosis::create(['admission_id' => $a->id, 'seq' => $seq++, 'icd10_code' => $c]);
        }
        return $a;
    }

    public function test_diagnosis_and_vs_or_matching(): void
    {
        $both = $this->admissionWithDx('60000001', ['J18.9', 'E11.9']);   // pneumonia + diabetes
        $oneOnly = $this->admissionWithDx('60000002', ['J18.9']);          // pneumonia only

        // OR: both admissions match
        $this->actingAs($this->admin)
            ->get('/registry?dx[]=J18.9&dx[]=E11.9&dx_match=or')
            ->assertInertia(fn (AssertableInertia $p) => $p->where('results.total', 2));

        // AND: only the admission carrying BOTH codes matches
        $this->actingAs($this->admin)
            ->get('/registry?dx[]=J18.9&dx[]=E11.9&dx_match=and')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('results.total', 1)
                ->where('results.data.0.mrn', '60000001'));
    }

    public function test_diagnosis_keyword_mode_matches_by_icd_name(): void
    {
        Icd10::create(['code' => 'J18.9', 'name' => 'Pneumonia, unspecified organism']);
        $this->admissionWithDx('60000003', ['J18.9']);
        $this->admissionWithDx('60000004', ['E11.9']); // no pneumonia

        $this->actingAs($this->admin)
            ->post('/registry', ['mode' => 'diagnosis', 'keyword' => 'pneumonia'])
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('results.total', 1)
                ->where('results.data.0.mrn', '60000003'));

        // <2-char keyword guard returns an empty page, not everything
        $this->actingAs($this->admin)
            ->post('/registry', ['mode' => 'diagnosis', 'keyword' => 'p'])
            ->assertInertia(fn (AssertableInertia $p) => $p->where('results.total', 0));
    }

    public function test_consultations_mode_filters_by_signed_and_search(): void
    {
        Consultation::create(['mrn' => '60000005', 'patient_name' => 'Cons Signed', 'consultation_date' => '2024-04-01',
            'signoff_date' => '2024-04-02', 'indication' => [], 'current_location' => 'Ward']);
        Consultation::create(['mrn' => '60000006', 'patient_name' => 'Cons Open', 'consultation_date' => '2024-04-03',
            'indication' => [], 'current_location' => 'Ward']);

        $this->actingAs($this->admin)
            ->get('/registry?mode=consultations&signed_only=1')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('results.total', 1)
                ->where('results.data.0.name', 'Cons Signed'));

        $this->actingAs($this->admin)
            ->post('/registry', ['mode' => 'consultations', 'search' => 'Cons Open'])
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('results.total', 1)
                ->where('results.data.0.name', 'Cons Open'));
    }
}
