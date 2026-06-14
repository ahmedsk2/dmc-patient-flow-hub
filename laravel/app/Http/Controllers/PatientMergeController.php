<?php

namespace App\Http\Controllers;

use App\Http\Requests\PatientMergeRequest;
use App\Models\Admission;
use App\Models\Consultation;
use App\Models\Patient;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 4 — Item 9: patient-merge / MRN-dedup tooling (admin only). The legacy `mediumtext` MRN
 * column created genuine duplicate patient rows (the same person under MRN typos). This tool lets an
 * admin re-point ALL of a SOURCE patient's clinical history (admissions + consultations) onto a
 * canonical TARGET in one audited transaction, then soft-delete the source so the operation is
 * recoverable. The merge POST is step-up gated (high-risk, identity-changing — see routes/web.php).
 *
 * Why a whole-patient merge (vs. the per-admission re-point in PatientActionController::modify):
 * Modify moves ONE admission when its MRN is edited; this moves the patient's ENTIRE history at once
 * and disposes of the now-empty duplicate identity. After the merge the target's combined history
 * computes readmissions correctly (Admission::readmissionJoin keys on patient_id, which is now the
 * target's for every moved row).
 */
class PatientMergeController extends Controller
{
    /** Search + the possible-duplicates worklist that feeds the merge UI. */
    public function index(): Response
    {
        return Inertia::render('Admin/PatientMerge', [
            'possibleDuplicates' => $this->possibleDuplicates(),
        ]);
    }

    /**
     * Candidate duplicate pairs, two heuristics (both exclude soft-deleted — incl. already-merged —
     * patients):
     *   1. Same NORMALISED MRN: two live patients whose digits-only MRN is equal (catches leading
     *      zeros / non-digit noise: '00123' ~ '123', 'MR-456' ~ '456').
     *   2. NOMRN placeholder ~ real patient by exact name: the legacy importer minted NOMRN-{id}
     *      placeholders for blank MRNs; a name match to a real (digit-MRN) patient is a likely dupe.
     */
    private function possibleDuplicates(): array
    {
        // 1. Normalised-MRN pairs. CAST(... AS UNSIGNED) strips leading zeros and non-digit noise in
        // MySQL; require the digits-only form to be non-empty (>0) so two blank/non-digit MRNs do not
        // all collapse onto 0 and pair with each other.
        $normalized = DB::table('patients as p1')
            ->join('patients as p2', function ($j) {
                $j->on(DB::raw('CAST(p1.mrn AS UNSIGNED)'), '=', DB::raw('CAST(p2.mrn AS UNSIGNED)'))
                  ->whereColumn('p1.id', '<', 'p2.id');
            })
            ->whereNull('p1.deleted_at')->whereNull('p2.deleted_at')
            ->whereRaw('CAST(p1.mrn AS UNSIGNED) > 0')
            // …but a literally-identical MRN is the unique-constraint case, not a dedup candidate
            ->whereColumn('p1.mrn', '<>', 'p2.mrn')
            ->selectRaw("p1.id id1, p1.mrn mrn1, p1.name name1, p2.id id2, p2.mrn mrn2, p2.name name2, 'normalized-mrn' reason")
            ->limit(50)->get();

        // 2. NOMRN placeholder ~ real patient by exact (case-insensitive) name.
        $nomrn = DB::table('patients as ph')
            ->join('patients as real', function ($j) {
                $j->on(DB::raw('LOWER(ph.name)'), '=', DB::raw('LOWER(real.name)'))
                  ->whereColumn('ph.id', '<>', 'real.id');
            })
            ->whereNull('ph.deleted_at')->whereNull('real.deleted_at')
            ->where('ph.mrn', 'like', 'NOMRN-%')
            ->where('real.mrn', 'not like', 'NOMRN-%')
            ->whereNotNull('ph.name')->where('ph.name', '<>', '')
            ->selectRaw("ph.id id1, ph.mrn mrn1, ph.name name1, real.id id2, real.mrn mrn2, real.name name2, 'nomrn-name-match' reason")
            ->limit(50)->get();

        // de-dup the union on the unordered id-pair (a pair can match both heuristics)
        return $normalized->concat($nomrn)
            ->unique(fn ($d) => min($d->id1, $d->id2) . '-' . max($d->id1, $d->id2))
            ->values()
            ->map(fn ($d) => (array) $d)
            ->all();
    }

    /**
     * Preview a candidate merge: counts on each side + whether either has an open episode (so the UI
     * can warn before the admin commits). No mutation.
     */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source_id' => ['required', 'integer', 'exists:patients,id'],
            'target_id' => ['required', 'integer', 'exists:patients,id'],
        ]);
        $source = Patient::withTrashed()->findOrFail($data['source_id']);
        $target = Patient::withTrashed()->findOrFail($data['target_id']);

        return response()->json([
            'source' => $this->preview($source),
            'target' => $this->preview($target),
        ]);
    }

    /** One side's preview payload — identity + history counts + open-episode flag. */
    private function preview(Patient $p): array
    {
        return [
            'id' => $p->id,
            'mrn' => $p->mrn,
            'name' => $p->name,
            'gender' => $p->gender,
            'age' => $p->age,
            'nationality' => $p->nationality,
            'admissions' => Admission::where('patient_id', $p->id)->count(),
            'consultations' => Consultation::where('patient_id', $p->id)->count(),
            'has_open_admission' => Admission::whereNull('discharge_date')->where('patient_id', $p->id)->exists(),
        ];
    }

    /**
     * Perform the merge. Everything happens inside ONE transaction so a failure at any step rolls the
     * whole thing back (no half-merged patient). Steps, in order:
     *   1. Re-point every SOURCE admission (patient_id) onto the TARGET.
     *   2. Re-point every SOURCE consultation (patient_id) onto the TARGET AND re-stamp its
     *      denormalised mrn to the target's canonical MRN.
     *   3. Apply any admin-chosen canonical demographic overrides to the target (unsubmitted fields
     *      keep the target's existing value — the target is canonical by default).
     *   4. Sanity-check INSIDE the txn: the source must now have ZERO live admissions/consultations
     *      (nothing was missed) — otherwise abort and roll back.
     *   5. Write the 'patient.merge' audit row (both ids + moved counts) BEFORE the source disappears.
     *   6. Soft-delete the source patient (recoverable; the cascadeOnDelete FK never fires on a
     *      soft-delete, so the re-pointed admissions are untouched).
     */
    public function merge(PatientMergeRequest $request): RedirectResponse
    {
        $source = $request->sourcePatient();
        $target = $request->targetPatient();
        $overrides = array_filter(
            $request->validated()['canonical_demographics'] ?? [],
            fn ($v) => $v !== null && $v !== ''
        );

        DB::transaction(function () use ($source, $target, $overrides) {
            // 1. admissions — changing patient_id only; admission_id is untouched so the children
            // (admission_diagnoses, handovers, handover_signatures — all keyed on admission_id) ride
            // along automatically and the adx_admission_code_unique index is never disturbed.
            $admissionsMoved = Admission::where('patient_id', $source->id)
                ->update(['patient_id' => $target->id]);

            // 2. consultations — re-point + re-stamp the denormalised MRN to the target's canonical one
            $consultationsMoved = Consultation::where('patient_id', $source->id)
                ->update(['patient_id' => $target->id, 'mrn' => $target->mrn]);

            // 3. canonical demographics — only admin-submitted, non-empty overrides land on the target
            if ($overrides) {
                $target->update($overrides);
            }

            // 4. sanity check (inside the txn): the source must be empty now. If anything still points
            // at it the merge was incomplete — roll the whole transaction back.
            $sourceLeftovers = Admission::where('patient_id', $source->id)->count()
                + Consultation::where('patient_id', $source->id)->count();
            abort_if($sourceLeftovers > 0, 500, 'Merge sanity check failed — source still has rows after the re-point.');

            // 5. audit BEFORE the source row is soft-deleted (the row survives, but capture the
            // identifying details + counts as the durable record of what moved where)
            Audit::log('patient.merge', 'patient', (string) $target->id, [
                'source_id' => $source->id, 'source_mrn' => $source->mrn, 'source_name' => $source->name,
                'target_id' => $target->id, 'target_mrn' => $target->mrn, 'target_name' => $target->name,
                'admissions_moved' => $admissionsMoved,
                'consultations_moved' => $consultationsMoved,
                'demographics_overridden' => array_keys($overrides),
                'step_up' => $this->stepUpUsed(),
            ]);

            // 6. soft-delete the now-empty source (recoverable; FK cascade is never triggered)
            $source->delete();
        });

        \App\Support\DashboardCache::bust();   // a merged patient changes per-consultant / census aggregates

        return redirect()->route('patient-merge.index')->with('flash', [
            'type' => 'success',
            'message' => "Merged patient #{$source->id} ({$source->mrn}) into #{$target->id} ({$target->mrn}).",
        ]);
    }

    /**
     * Admin-only patient typeahead for the merge source/target pickers. Matches MRN or name; returns
     * a compact payload incl. the open-admission count so the picker can flag an active patient. The
     * SoftDeletes global scope excludes already-merged (trashed) patients automatically.
     */
    public function searchPatients(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $rows = Patient::query()
            ->where(fn ($w) => $w->where('mrn', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%"))
            ->withCount(['admissions as open_admissions_count' => fn ($a) => $a->whereNull('discharge_date')])
            ->orderBy('name')
            ->limit(25)
            ->get(['id', 'mrn', 'name', 'gender', 'age', 'nationality']);

        return response()->json($rows->map(fn (Patient $p) => [
            'id' => $p->id,
            'mrn' => $p->mrn,
            'name' => $p->name,
            'gender' => $p->gender,
            'age' => $p->age,
            'nationality' => $p->nationality,
            'open_admissions_count' => (int) $p->open_admissions_count,
        ]));
    }

    /** Item 4 parity: stamp whether the action rode a fresh (<5 min) step-up window. */
    private function stepUpUsed(): bool
    {
        $verifiedAt = session('stepup.verified_at');

        return $verifiedAt && (now()->getTimestamp() - (int) $verifiedAt) <= 300;
    }
}
