<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use App\Models\Consultation;
use App\Models\User;
use App\Support\Audit;
use App\Support\DashboardCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 4 — Item 1: the admin "Recently Deleted" view + Restore actions. Admin-only (inside the
 * `admin` route group). Lists the most-recent 100 soft-deleted rows of each kind (withTrashed +
 * whereNotNull) and restores one inside a transaction with an audit row. No auto-purge — restore
 * is manual and recovery is forever (the retention policy is a Phase-5 decision).
 */
class TrashedController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Trashed/Index', [
            'admissions' => Admission::withTrashed()->whereNotNull('deleted_at')
                ->with('patient:id,mrn,name')
                ->orderByDesc('deleted_at')->limit(100)->get()
                ->map(fn (Admission $a) => [
                    'id' => $a->id,
                    'mrn' => $a->patient?->mrn,
                    'name' => $a->patient?->name ?? 'Unknown',
                    'admit_date' => optional($a->admit_date)->toDateString(),
                    'discharge_date' => optional($a->discharge_date)->toDateString(),
                    'deleted_at' => optional($a->deleted_at)->toIso8601String(),
                ]),
            'consultations' => Consultation::withTrashed()->whereNotNull('deleted_at')
                ->orderByDesc('deleted_at')->limit(100)->get()
                ->map(fn (Consultation $c) => [
                    'id' => $c->id,
                    'mrn' => $c->mrn,
                    'name' => $c->patient_name ?? 'Unknown',
                    'date' => optional($c->consultation_date)->toDateString(),
                    'deleted_at' => optional($c->deleted_at)->toIso8601String(),
                ]),
            'users' => User::withTrashed()->whereNotNull('deleted_at')
                ->orderByDesc('deleted_at')->limit(100)->get()
                ->map(fn (User $u) => [
                    'id' => $u->id,
                    'username' => $u->username,
                    'name' => $u->full_name ?: $u->name,
                    'role_label' => $u->roleLabel(),
                    'deleted_at' => optional($u->deleted_at)->toIso8601String(),
                ]),
        ]);
    }

    public function restoreAdmission(int $id): RedirectResponse
    {
        $admission = Admission::withTrashed()->findOrFail($id);

        // same guard as StoreAdmissionRequest: restoring an active admission must not produce a
        // duplicate-active-MRN (the board would show the same patient twice)
        if (! $admission->discharge_date) {
            $duplicate = Admission::whereNull('discharge_date')
                ->where('patient_id', $admission->patient_id)
                ->where('id', '<>', $admission->id)
                ->exists()
                || Admission::whereNull('discharge_date')
                    ->whereHas('patient', fn ($q) => $q->where('mrn', $admission->patient?->mrn))
                    ->exists();
            if ($duplicate) {
                return back()->with('flash', ['type' => 'error',
                    'message' => 'Cannot restore — that patient already has an active admission.']);
            }
        }

        DB::transaction(function () use ($admission) {
            $admission->restore();
            Audit::log('admission.restore', 'admission', (string) $admission->id, [
                'mrn' => $admission->patient?->mrn,
            ]);
        });
        DashboardCache::bust();

        return back()->with('flash', ['type' => 'success', 'message' => 'Admission restored.']);
    }

    public function restoreConsultation(int $id): RedirectResponse
    {
        $consultation = Consultation::withTrashed()->findOrFail($id);
        DB::transaction(function () use ($consultation) {
            $consultation->restore();
            Audit::log('consultation.restore', 'consultation', (string) $consultation->id);
        });

        return back()->with('flash', ['type' => 'success', 'message' => 'Consultation restored.']);
    }

    public function restoreUser(int $id): RedirectResponse
    {
        $user = User::withTrashed()->findOrFail($id);
        DB::transaction(function () use ($user) {
            // restore keeps the stored `active` flag as-is — re-activation is a separate Control edit
            $user->restore();
            Audit::log('user.restore', 'user', (string) $user->id, ['username' => $user->username]);
        });

        return back()->with('flash', ['type' => 'success', 'message' => "Restored {$user->username}."]);
    }
}
