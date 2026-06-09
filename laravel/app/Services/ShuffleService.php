<?php

namespace App\Services;

use App\Models\Admission;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Auto-assign the unassigned active queue across on-service consultants, balancing load toward the
 * configured capacities. Reconstruction of the legacy 4-round "shuffle": hospitalists (specialty 1)
 * absorb load first up to max_hospitalist, then subspecialists up to max_subs, always filling the
 * least-loaded consultant first. Deterministic and race-free (runs in one transaction).
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

            // current active load per consultant (the denominator the balance works against)
            $load = DB::table('admissions')->whereNull('discharge_date')->whereNotNull('consultant_id')
                ->selectRaw('consultant_id, COUNT(*) c')->groupBy('consultant_id')->pluck('c', 'consultant_id')->all();
            foreach ($onService as $u) {
                $load[$u->id] = (int) ($load[$u->id] ?? 0);
            }

            $hosp = $onService->where('specialty_id', 1)->pluck('id')->all();
            $subs = $onService->where('specialty_id', '!=', 1)->pluck('id')->all();
            $maxHosp = (int) $settings->max_hospitalist;
            $maxSubs = (int) $settings->max_subs;

            $assigned = 0;
            $touched = [];
            foreach ($unassigned as $adm) {
                $pick = $this->leastLoadedUnder($hosp, $load, $maxHosp)
                    ?? $this->leastLoadedUnder($subs, $load, $maxSubs)
                    ?? $this->leastLoaded(array_merge($hosp, $subs), $load); // overflow: everyone at cap

                if ($pick === null) {
                    continue;
                }
                $adm->update([
                    'consultant_id' => $pick,
                    'is_new_assignment' => true,
                    'assigned_on' => now()->toDateString(),
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
