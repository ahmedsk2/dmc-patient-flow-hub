<?php

namespace App\Services;

use App\Models\Admission;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Auto-assign the unassigned active queue across on-service consultants, balancing load toward the
 * configured capacities (legacy 4-round "shuffle", per clinical sign-off 2026-06-09):
 *   round 1 — fill each hospitalist (specialty 1) up to MIN_hospitalist (least-loaded first)
 *   round 2 — fill each subspecialist up to MIN_subs
 *   round 3 — hospitalists up to MAX_hospitalist
 *   round 4 — subspecialists up to MAX_subs
 *   round 5 — overflow: least-loaded overall (so the queue never stalls when everyone's at cap)
 * "Load" = the consultant's active NON-ICU patients, EXCLUDING medically-discharged ("discharged
 * still in"). TB patients count ASYMMETRICALLY (N1-3, legacy parity): they are EXCLUDED from a
 * HOSPITALIST's load (dmc-patients-shuffle.php:121-144 skipped TB when counting hospitalists) but
 * INCLUDED in a SUBSPECIALIST's load (lines 236-242 counted every active non-ICU patient) — TB is
 * a subspecialty concern, so a TB-heavy sub legitimately looks more loaded. Long-term stays
 * included per the 2026-06-09 owner sign-off — the ward census the shuffle balances.
 * An unassigned ICU queue row is only ever given to a HOSPITALIST (legacy lines 76-102 distributed
 * ICU rows round-robin across hospitalists; subspecialists never receive ICU patients).
 * Deterministic and race-free (one transaction).
 */
class ShuffleService
{
    /** @return array{assigned:int, consultants:int, skipped:int} */
    public function run(?int $actorId = null): array
    {
        $settings = Setting::current();

        return DB::transaction(function () use ($settings, $actorId) {
            $unassigned = Admission::query()->whereNull('discharge_date')->whereNull('consultant_id')
                ->orderBy('id')->get();

            if ($unassigned->isEmpty()) {
                return ['assigned' => 0, 'consultants' => 0, 'skipped' => 0];
            }

            $onService = User::where('role', User::ROLE_CONSULTANT)->where('active', 1)->where('on_service', 1)
                ->get(['id', 'specialty_id']);
            if ($onService->isEmpty()) {
                return ['assigned' => 0, 'consultants' => 0, 'skipped' => $unassigned->count()];
            }

            // base active load per consultant = active NON-ICU patients excluding the
            // medically-discharged ("discharged still in"); long-term stays included (owner sign-off).
            // This INCLUDES TB — the TB subtraction happens per-consultant below.
            $tbExists = 'EXISTS (SELECT 1 FROM admission_diagnoses ad
                JOIN tb_diagnoses tb ON tb.icd10_code = ad.icd10_code
                WHERE ad.admission_id = admissions.id)';
            $wardActive = DB::table('admissions')->whereNull('discharge_date')->whereNotNull('consultant_id')
                ->where(fn ($w) => $w->where('current_location', '<>', 'ICU')->orWhereNull('current_location'))
                ->whereNull('medical_discharge_date')->whereNull('deleted_at');   // Phase 4 — Item 1: live rows only
            $base = (clone $wardActive)
                ->selectRaw('consultant_id, COUNT(*) c')->groupBy('consultant_id')->pluck('c', 'consultant_id')->all();
            // TB count per consultant (the slice excluded ONLY from a hospitalist's load)
            $tbCount = (clone $wardActive)->whereRaw($tbExists)
                ->selectRaw('consultant_id, COUNT(*) c')->groupBy('consultant_id')->pluck('c', 'consultant_id')->all();

            $hosp = $onService->where('specialty_id', 1)->pluck('id')->all();
            $subs = $onService->where('specialty_id', '!=', 1)->pluck('id')->all();
            // hospitalist load = base MINUS TB; subspecialist load = base (TB included) — legacy asymmetry
            $hospSet = array_flip($hosp);
            $load = [];
            foreach ($onService as $u) {
                $b = (int) ($base[$u->id] ?? 0);
                $load[$u->id] = isset($hospSet[$u->id]) ? $b - (int) ($tbCount[$u->id] ?? 0) : $b;
            }
            $minHosp = (int) $settings->min_hospitalist;
            $maxHosp = (int) $settings->max_hospitalist;
            $minSubs = (int) $settings->min_subs;
            $maxSubs = (int) $settings->max_subs;

            // which QUEUED rows are TB — a TB patient assigned to a hospitalist must not raise that
            // hospitalist's load (TB is excluded from the hospitalist count), but DOES raise a sub's
            $queueTb = DB::table('admissions')->whereIn('admissions.id', $unassigned->pluck('id'))
                ->whereRaw($tbExists)->pluck('id')->flip();

            $assigned = 0;
            $touched = [];
            foreach ($unassigned as $adm) {
                // an ICU queue row only ever goes to a hospitalist (legacy); a queue without
                // hospitalists skips it rather than handing ICU care to a subspecialist
                $pick = $adm->current_location === 'ICU'
                    ? ($this->leastLoadedUnder($hosp, $load, $minHosp)
                        ?? $this->leastLoadedUnder($hosp, $load, $maxHosp)
                        ?? $this->leastLoaded($hosp, $load))
                    : ($this->leastLoadedUnder($hosp, $load, $minHosp)   // round 1: hospitalists → min
                        ?? $this->leastLoadedUnder($subs, $load, $minSubs)    // round 2: subs → min
                        ?? $this->leastLoadedUnder($hosp, $load, $maxHosp)    // round 3: hospitalists → max
                        ?? $this->leastLoadedUnder($subs, $load, $maxSubs)    // round 4: subs → max
                        ?? $this->leastLoaded(array_merge($hosp, $subs), $load)); // round 5: overflow

                if ($pick === null) {
                    continue;
                }
                $adm->update([
                    'consultant_id' => $pick,
                    'is_new_assignment' => true,
                    'assigned_on' => now()->toDateString(),
                    'assigned_at' => now(),
                ]);
                // ICU rows never count toward the ward load (legacy); a TB row counts only when the
                // pick is a subspecialist — a hospitalist's TB patients stay off their load (N1-3)
                if ($adm->current_location !== 'ICU'
                    && ! ($queueTb->has($adm->id) && isset($hospSet[$pick]))) {
                    $load[$pick]++;
                }
                $touched[$pick] = true;
                $assigned++;
            }

            return ['assigned' => $assigned, 'consultants' => count($touched), 'skipped' => $unassigned->count() - $assigned];
        });
    }

    private function leastLoadedUnder(array $ids, array $load, int $cap): ?int
    {
        $best = null;
        foreach ($ids as $id) {
            if ($load[$id] >= $cap) {
                continue;
            }
            if ($best === null || $load[$id] < $load[$best]) {
                $best = $id;
            }
        }
        return $best;
    }

    private function leastLoaded(array $ids, array $load): ?int
    {
        $best = null;
        foreach ($ids as $id) {
            if ($best === null || $load[$id] < $load[$best]) {
                $best = $id;
            }
        }
        return $best;
    }
}
