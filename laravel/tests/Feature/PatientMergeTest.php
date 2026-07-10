<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Phase 4 — Item 9: patient-merge / MRN-dedup tooling. The highest-risk item — it re-points all of a
 * SOURCE patient's clinical history (admissions + consultations) onto a canonical TARGET inside one
 * transaction, then soft-deletes the now-empty source so the operation is recoverable. These tests
 * are the contract: re-point completeness, ride-along children (diagnoses/handovers key on
 * admission_id), consultation MRN sync, the audit row, soft-delete + registry/finder exclusion,
 * merge-into-self rejection, readmission detection across the merged history, the duplicates finder,
 * non-admin 403 and the step-up gate.
 */
class PatientMergeTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'pm_' . $role . '_' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'PM User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
        ], $extra));
    }

    private function admin(): User
    {
        return $this->user(User::ROLE_ADMIN);
    }

    private function patient(array $extra = []): Patient
    {
        return Patient::create(array_merge([
            'mrn' => (string) random_int(1000000, 9999999), 'name' => 'Pt', 'age' => 40, 'gender' => 'Male',
        ], $extra));
    }

    /** A closed (discharged) episode by default — the merge guard only blocks two OPEN episodes. */
    private function admission(Patient $p, array $extra = []): Admission
    {
        return Admission::create(array_merge([
            'patient_id' => $p->id,
            'admit_date' => now()->subDays(20)->toDateString(),
            'discharge_date' => now()->subDays(15)->toDateString(),
            'transfer_type' => 'discharge from ward',
            'current_location' => 'Ward',
        ], $extra));
    }

    private function consultation(Patient $p, array $extra = []): Consultation
    {
        return Consultation::create(array_merge([
            'patient_id' => $p->id, 'mrn' => $p->mrn, 'patient_name' => $p->name,
            'consultation_date' => now()->subDays(10)->toDateString(),
        ], $extra));
    }

    /** Stamp a step-up window and POST the merge — the common happy-path driver. */
    private function merge(User $actor, Patient $source, Patient $target, array $extra = [])
    {
        return $this->actingAs($actor)
            ->withSession(['stepup.verified_at' => now()->getTimestamp()])
            ->post('/admin/patient-merge', array_merge([
                'source_id' => $source->id, 'target_id' => $target->id,
            ], $extra));
    }

    // ---- re-point completeness ------------------------------------------------------------------

    public function test_merge_repoints_admissions_to_target(): void
    {
        $source = $this->patient(['mrn' => '5550001']);
        $target = $this->patient(['mrn' => '5550002']);
        $a1 = $this->admission($source);
        $a2 = $this->admission($source);
        $existing = $this->admission($target);

        $this->merge($this->admin(), $source, $target)->assertRedirect();

        $this->assertSame($target->id, (int) Admission::withTrashed()->find($a1->id)->patient_id);
        $this->assertSame($target->id, (int) Admission::withTrashed()->find($a2->id)->patient_id);
        // target gains exactly the two moved rows (3 total); source ends with zero
        $this->assertSame(3, Admission::where('patient_id', $target->id)->count());
        $this->assertSame(0, Admission::where('patient_id', $source->id)->count());
    }

    public function test_merge_repoints_consultations_to_target(): void
    {
        $source = $this->patient(['mrn' => '5551001']);
        $target = $this->patient(['mrn' => '5551002']);
        $c1 = $this->consultation($source);
        $c2 = $this->consultation($source);

        $this->merge($this->admin(), $source, $target)->assertRedirect();

        $this->assertSame(2, Consultation::where('patient_id', $target->id)->count());
        $this->assertSame(0, Consultation::where('patient_id', $source->id)->count());
        $this->assertSame($target->id, (int) Consultation::find($c1->id)->patient_id);
        $this->assertSame($target->id, (int) Consultation::find($c2->id)->patient_id);
    }

    public function test_merge_updates_consultation_mrn_to_target_mrn(): void
    {
        $source = $this->patient(['mrn' => '5552001']);
        $target = $this->patient(['mrn' => '5552002']);
        $c = $this->consultation($source);   // mrn = source's
        $this->assertSame('5552001', $c->mrn);

        $this->merge($this->admin(), $source, $target)->assertRedirect();

        $this->assertSame('5552002', Consultation::find($c->id)->mrn, 'consultation MRN is re-stamped to the target MRN');
    }

    // ---- ride-along children (key on admission_id) ----------------------------------------------

    public function test_diagnoses_and_handovers_ride_along_under_target(): void
    {
        $source = $this->patient(['mrn' => '5553001']);
        $target = $this->patient(['mrn' => '5553002']);
        $a = $this->admission($source);
        $a->diagnoses()->create(['seq' => 1, 'icd10_code' => 'J18']);
        $a->diagnoses()->create(['seq' => 2, 'icd10_code' => 'E11']);
        // a handover keyed on the admission_id (body is NOT NULL)
        DB::table('handovers')->insert([
            'admission_id' => $a->id, 'body' => 'Stable overnight.', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->merge($this->admin(), $source, $target)->assertRedirect();

        // diagnoses + handover are children of the admission row, which now belongs to the target —
        // they resolve through it without being touched directly
        $moved = Admission::withTrashed()->find($a->id);
        $this->assertSame($target->id, (int) $moved->patient_id);
        $this->assertEqualsCanonicalizing(['J18', 'E11'], $moved->diagnoses()->pluck('icd10_code')->all());
        $this->assertSame(1, DB::table('handovers')->where('admission_id', $a->id)->count());

        // and they resolve when walking from the TARGET patient down to its admissions' diagnoses
        $codesUnderTarget = Admission::where('patient_id', $target->id)
            ->with('diagnoses')->get()->flatMap->diagnoses->pluck('icd10_code');
        $this->assertTrue($codesUnderTarget->contains('J18'));
        $this->assertTrue($codesUnderTarget->contains('E11'));
    }

    // ---- audit ----------------------------------------------------------------------------------

    public function test_merge_writes_patient_merge_audit_row(): void
    {
        $source = $this->patient(['mrn' => '5554001']);
        $target = $this->patient(['mrn' => '5554002']);
        $this->admission($source);
        $this->admission($source);
        $this->consultation($source);

        $this->merge($this->admin(), $source, $target)->assertRedirect();

        $audit = AuditLog::where('action', 'patient.merge')->first();
        $this->assertNotNull($audit, 'a patient.merge audit row is written');
        $this->assertSame((string) $target->id, $audit->entity_id);
        $this->assertSame($source->id, (int) $audit->details['source_id']);
        $this->assertSame($target->id, (int) $audit->details['target_id']);
        $this->assertSame('5554001', $audit->details['source_mrn']);
        $this->assertSame('5554002', $audit->details['target_mrn']);
        $this->assertSame(2, (int) $audit->details['admissions_moved']);
        $this->assertSame(1, (int) $audit->details['consultations_moved']);
    }

    // ---- soft-delete + recoverability + exclusion -----------------------------------------------

    public function test_merge_soft_deletes_source_patient(): void
    {
        $source = $this->patient(['mrn' => '5555001']);
        $target = $this->patient(['mrn' => '5555002']);
        $this->admission($source);

        $this->merge($this->admin(), $source, $target)->assertRedirect();

        $this->assertNull(Patient::find($source->id), 'source hidden by the global scope');
        $this->assertNotNull(DB::table('patients')->where('id', $source->id)->value('deleted_at'), 'row survives');
        $this->assertNotNull(Patient::withTrashed()->find($source->id), 'withTrashed still finds it — recoverable');
        $this->assertNotNull(Patient::find($target->id), 'target untouched');
    }

    public function test_merged_source_excluded_from_registry_search(): void
    {
        // distinct MRNs so we can tell which patient the registry row resolves the admission TO; the
        // registry row payload denormalises patient identity to mrn/name (no patient.id), so we assert
        // on those.
        $source = $this->patient(['mrn' => '5556001', 'name' => 'Source Mergeperson']);
        $target = $this->patient(['mrn' => '5556002', 'name' => 'Target Mergeperson']);
        // both have a discharged admission so they would otherwise BOTH appear in the registry
        $aSource = $this->admission($source);
        $this->admission($target);

        $this->merge($this->admin(), $source, $target)->assertRedirect();

        // searching the SOURCE's old MRN returns nothing — the source patient is gone and its
        // admission now carries the TARGET's MRN/name
        $this->actingAs($this->admin())->post('/registry', ['search' => '5556001'])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('results.data', fn ($rows) => collect($rows)->isEmpty()));

        // searching the TARGET's MRN returns BOTH admissions (the moved one + the target's own),
        // each attributed to the target identity — and the moved admission keeps its row id
        $this->actingAs($this->admin())->post('/registry', ['search' => '5556002'])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('results.data', function ($rows) use ($aSource) {
                    $this->assertSame(2, collect($rows)->count(), 'both admissions resolve under the target');
                    $moved = collect($rows)->firstWhere('id', $aSource->id);
                    $this->assertNotNull($moved, 'the merged-away admission still displays');
                    $this->assertSame('5556002', $moved['mrn'], 'displayed under the TARGET MRN');
                    $this->assertSame('Target Mergeperson', $moved['name'], 'displayed under the TARGET name');

                    return true;
                }));
    }

    public function test_merged_source_excluded_from_patient_search_typeahead(): void
    {
        $source = $this->patient(['mrn' => '5557001', 'name' => 'Searchable Source']);
        $target = $this->patient(['mrn' => '5557002', 'name' => 'Searchable Target']);

        $this->merge($this->admin(), $source, $target)->assertRedirect();

        $res = $this->actingAs($this->admin())->postJson('/api/patients/search', ['q' => 'Searchable']);
        $res->assertOk();
        $ids = collect($res->json())->pluck('id');
        $this->assertFalse($ids->contains($source->id), 'soft-deleted source not in the typeahead');
        $this->assertTrue($ids->contains($target->id), 'target still in the typeahead');
    }

    // ---- guards ---------------------------------------------------------------------------------

    public function test_merge_into_self_is_rejected(): void
    {
        $p = $this->patient(['mrn' => '5558001']);
        $this->admission($p);

        $this->actingAs($this->admin())
            ->withSession(['stepup.verified_at' => now()->getTimestamp()])
            ->post('/admin/patient-merge', ['source_id' => $p->id, 'target_id' => $p->id])
            ->assertSessionHasErrors('target_id');

        $this->assertNull(Patient::find($p->id)?->deleted_at, 'patient untouched');
        $this->assertSame(1, Admission::where('patient_id', $p->id)->count());
    }

    public function test_merge_blocked_when_both_have_active_admissions(): void
    {
        $source = $this->patient(['mrn' => '5559001']);
        $target = $this->patient(['mrn' => '5559002']);
        // open (undischarged) episodes on BOTH sides — merging would create a double-open canary hit
        Admission::create(['patient_id' => $source->id, 'admit_date' => now()->toDateString(), 'current_location' => 'Ward']);
        Admission::create(['patient_id' => $target->id, 'admit_date' => now()->toDateString(), 'current_location' => 'Ward']);

        $this->merge($this->admin(), $source, $target)->assertSessionHasErrors('source_id');

        $this->assertNull(Patient::find($source->id)?->deleted_at, 'rolled back — source not deleted');
        $this->assertSame(1, Admission::where('patient_id', $source->id)->count(), 'rolled back — admission not moved');
    }

    public function test_merge_blocked_for_non_admin(): void
    {
        $source = $this->patient(['mrn' => '5560001']);
        $target = $this->patient(['mrn' => '5560002']);
        $this->admission($source);

        $this->actingAs($this->user(User::ROLE_CONSULTANT))
            ->withSession(['stepup.verified_at' => now()->getTimestamp()])
            ->post('/admin/patient-merge', ['source_id' => $source->id, 'target_id' => $target->id])
            ->assertForbidden();

        $this->assertSame(1, Admission::where('patient_id', $source->id)->count(), 'nothing moved');
    }

    public function test_merge_requires_step_up(): void
    {
        $source = $this->patient(['mrn' => '5561001']);
        $target = $this->patient(['mrn' => '5561002']);
        $this->admission($source);

        // no step-up window in the session → bounced to the step-up form, nothing merged
        $this->actingAs($this->admin())
            ->post('/admin/patient-merge', ['source_id' => $source->id, 'target_id' => $target->id])
            ->assertRedirect('/stepup');

        $this->assertSame(1, Admission::where('patient_id', $source->id)->count());
        $this->assertNull(Patient::find($source->id)?->deleted_at);
    }

    public function test_index_requires_admin(): void
    {
        $this->actingAs($this->user(User::ROLE_CONSULTANT))->get('/admin/patient-merge')->assertForbidden();
        $this->actingAs($this->admin())->get('/admin/patient-merge')->assertOk();
    }

    // ---- canonical demographics -----------------------------------------------------------------

    public function test_canonical_demographics_override_copies_chosen_fields_onto_target(): void
    {
        $source = $this->patient(['mrn' => '5562001', 'name' => 'Mohammed Al-Otaibi', 'nationality' => 'Saudi Arabia']);
        $target = $this->patient(['mrn' => '5562002', 'name' => 'Mohamed Alotaibi', 'nationality' => null]);
        $this->admission($source);

        // admin chooses the source spelling of the name + the source nationality as canonical
        $this->merge($this->admin(), $source, $target, [
            'canonical_demographics' => ['name' => 'Mohammed Al-Otaibi', 'nationality' => 'Saudi Arabia'],
        ])->assertRedirect();

        $target->refresh();
        $this->assertSame('Mohammed Al-Otaibi', $target->name);
        $this->assertSame('Saudi Arabia', $target->nationality);
    }

    public function test_no_demographic_override_keeps_target_values(): void
    {
        $source = $this->patient(['mrn' => '5563001', 'name' => 'Source Name']);
        $target = $this->patient(['mrn' => '5563002', 'name' => 'Target Name']);
        $this->admission($source);

        $this->merge($this->admin(), $source, $target)->assertRedirect();

        $this->assertSame('Target Name', $target->fresh()->name, 'target demographics are kept by default');
    }

    // ---- readmission detection across the merged history ----------------------------------------

    public function test_readmission_detected_across_merged_history(): void
    {
        $window = (int) (\App\Models\Setting::current()->readmission_window_days ?? 3);

        $source = $this->patient(['mrn' => '5564001']);
        $target = $this->patient(['mrn' => '5564002']);

        // TARGET has a prior REAL discharge
        Admission::create([
            'patient_id' => $target->id,
            'admit_date' => now()->subDays(10)->toDateString(),
            'discharge_date' => now()->subDays(8)->toDateString(),
            'transfer_type' => 'discharge from ward',
            'current_location' => 'Ward',
        ]);
        // SOURCE has an admission readmitted ONE day after that target discharge (subDays(8) ->
        // subDays(7): DATEDIFF = 1, inside the default 3-day window) — but it is only a readmission
        // once the two histories live under the same patient_id
        $sourceReadmit = Admission::create([
            'patient_id' => $source->id,
            'admit_date' => now()->subDays(7)->toDateString(),
            'current_location' => 'Ward',
        ]);

        // PRE-merge: the source admission is NOT a readmission (different patient_id)
        $preCount = Admission::where('id', $sourceReadmit->id)
            ->whereExists(Admission::readmissionExists($window))->count();
        $this->assertSame(0, $preCount, 'precondition: not a readmission before the merge');

        $this->merge($this->admin(), $source, $target)->assertRedirect();

        // POST-merge: same admission row (now under the target) IS a readmission of the target's prior
        $postCount = Admission::where('id', $sourceReadmit->id)
            ->whereExists(Admission::readmissionExists($window))->count();
        $this->assertSame(1, $postCount, 'readmission detected once the histories are merged onto one patient');
    }

    // ---- possible-duplicates finder -------------------------------------------------------------

    public function test_possible_duplicates_detects_leading_zero_mrn(): void
    {
        $p1 = $this->patient(['mrn' => '00123', 'name' => 'Leading Zero A']);
        $p2 = $this->patient(['mrn' => '123', 'name' => 'Leading Zero B']);

        $this->actingAs($this->admin())->get('/admin/patient-merge')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('possibleDuplicates', function ($dupes) use ($p1, $p2) {
                    $hit = collect($dupes)->first(fn ($d) => in_array($p1->id, [(int) $d['id1'], (int) $d['id2']], true)
                        && in_array($p2->id, [(int) $d['id1'], (int) $d['id2']], true));
                    $this->assertNotNull($hit, 'the leading-zero pair is surfaced');

                    return true;
                }));
    }

    public function test_possible_duplicates_detects_nomrn_name_match(): void
    {
        $real = $this->patient(['mrn' => '4001001', 'name' => 'Fatima Noor']);
        $placeholder = $this->patient(['mrn' => 'NOMRN-9001', 'name' => 'Fatima Noor']);

        $this->actingAs($this->admin())->get('/admin/patient-merge')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('possibleDuplicates', function ($dupes) use ($real, $placeholder) {
                    $hit = collect($dupes)->first(fn ($d) => in_array($real->id, [(int) $d['id1'], (int) $d['id2']], true)
                        && in_array($placeholder->id, [(int) $d['id1'], (int) $d['id2']], true));
                    $this->assertNotNull($hit, 'the NOMRN-name-match pair is surfaced');

                    return true;
                }));
    }

    public function test_possible_duplicates_excludes_already_merged_pairs(): void
    {
        $source = $this->patient(['mrn' => '04002', 'name' => 'Merged Away']);
        $target = $this->patient(['mrn' => '4002', 'name' => 'Merged Away']);

        // before the merge the pair IS surfaced (same normalized MRN)
        $this->actingAs($this->admin())->get('/admin/patient-merge')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('possibleDuplicates', fn ($d) => collect($d)->isNotEmpty()));

        $this->merge($this->admin(), $source, $target)->assertRedirect();

        // after the merge the trashed source must no longer pair with the target
        $this->actingAs($this->admin())->get('/admin/patient-merge')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('possibleDuplicates', function ($dupes) use ($source) {
                    $this->assertTrue(
                        collect($dupes)->every(fn ($d) => (int) $d['id1'] !== $source->id && (int) $d['id2'] !== $source->id),
                        'the soft-deleted source is excluded from the duplicates finder'
                    );

                    return true;
                }));
    }

    public function test_diagnosis_dedup_after_merge(): void
    {
        $source = $this->patient(['mrn' => '5565001']);
        $target = $this->patient(['mrn' => '5565002']);

        // a source admission that will be re-pointed, carrying A00 …
        $sa = $this->admission($source);
        $sa->diagnoses()->create(['seq' => 1, 'icd10_code' => 'A00']);

        $this->merge($this->admin(), $source, $target)->assertRedirect();

        // … the re-pointed admission keeps exactly one A00 row (no duplicate diagnosis links)
        $this->assertSame(1, DB::table('admission_diagnoses')
            ->where('admission_id', $sa->id)->where('icd10_code', 'A00')->count());
    }

    // ---- search preview action ------------------------------------------------------------------

    public function test_search_preview_returns_counts(): void
    {
        $source = $this->patient(['mrn' => '5566001']);
        $target = $this->patient(['mrn' => '5566002']);
        $this->admission($source);
        $this->admission($source);
        $this->consultation($source);
        $this->admission($target);

        $this->actingAs($this->admin())
            ->post('/admin/patient-merge/search', ['source_id' => $source->id, 'target_id' => $target->id])
            ->assertOk()
            ->assertJsonPath('source.admissions', 2)
            ->assertJsonPath('source.consultations', 1)
            ->assertJsonPath('target.admissions', 1);
    }
}
