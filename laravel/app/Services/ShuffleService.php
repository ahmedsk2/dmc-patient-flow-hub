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
 * "Load" = the consultant's active NON-ICU patients (long-term included; ICU excluded) — the
 * ward census the shuffle is meant to balance. Deterministic and race-free (one transaction).
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

            // current active load per consultant = active NON-ICU patients (long-term included,
            // ICU excluded) — the ward census the balance works against.
            $load = DB::table('admissions')->whereNull('discharge_date')->whereNotNull('consultant_id')
                ->where(fn ($w) => $w->where('current_location', '<>', 'ICU')->orWhereNull('current_location'))
                ->selectRaw('consultant_id, COUNT(*) c')->groupBy('consultant_id')->pluck('c', 'consultant_id')->all();
            foreach ($onService as $u) {
                $load[$u->id] = (int) ($load[$u->id] ?? 0);
            }

            $hosp = $onService->where('specialty_id', 1)->pluck('id')->all();
            $subs = $onService->where('specialty_id', '!=', 1)->pluck('id')->all();
            $minHosp = (int) $settings->min_hospitalist;
            $maxHosp = (int) $settings->max_hospitalist;
            $minSubs = (int) $settings->min_subs;
            $maxSubs = (int) $settings->max_subs;

            $assigned = 0;
            $touched = [];
            foreach ($unassigned as $adm) {
                $pick = $this->leastLoadedUnder($hosp, $load, $minHosp)   // round 1: hospitalists → min
                    ?? $this->leastLoadedUnder($subs, $load, $minSubs)    // round 2: subs → min
                    ?? $this->leastLoadedUnder($hosp, $load, $maxHosp)    // round 3: hospitalists → max
                    ?? $this->leastLoadedUnder($subs, $load, $maxSubs)    // round 4: subs → max
                    ?? $this->leastLoaded(array_merge($hosp, $subs), $load); // round 5: overflow

                if ($pick === null) {
                    continue;
                }
                $adm->update([
                    'consultant_id' => $pick,
                    'is_new_assignment' => true,
                    'assigned_on' => now()->toDateString(),
                    'assigned_at' => now(),
                ]);
                $load[$pick]++;
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
