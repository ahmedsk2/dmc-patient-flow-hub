<?php

namespace App\Http\Controllers;

use App\Models\Consultation;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * W4 — the PHYSICIAN-scoped consultation dashboard.
 *
 * Statistics, Registry and Reports are admin-only by design (PHI exposure control) and stay that
 * way. This controller is the clinical alternative: it lives in the ordinary auth group and is
 * scoped by Consultation::scopeVisibleTo($user) — a consultant sees their own specialty's book,
 * an admin or a coordinator sees everything and may narrow to one specialty with ?specialty_id=N.
 *
 * Observers are refused FIRST, before any capability flag is consulted (the J1-9 global read-only
 * guarantee — a capability flag must never open a door the role closes).
 *
 * Every query goes through baseQuery(), which pins the three invariants in one place:
 *   1. scopeVisibleTo — the authorization scope, never re-implemented per metric;
 *   2. an EXPLICIT whereNull('consultations.deleted_at') — soft-deleted rows are excluded from
 *      every analytic in this app (see Concerns\MetricQueries). The SoftDeletes global scope
 *      already adds this; it is repeated deliberately so the invariant is visible at the call
 *      site and survives a future switch to a raw DB::table() builder;
 *   3. the optional specialty narrowing, which only a picker can set.
 */
class ConsultationDashboardController extends Controller
{
    /** null = no narrowing (the viewer's full visible scope). */
    private ?int $scopeSpecialtyId = null;

    public function index(Request $request): Response
    {
        $user = $this->currentUser();
        if ($user->isObserver()) {
            throw new AccessDeniedHttpException('Observers have read-only access.');
        }

        $data = $request->validate([
            'specialty_id' => ['nullable', 'integer', 'exists:specialties,id'],
        ]);

        // Coordinators and admins choose a specialty; everyone else is pinned by scopeVisibleTo,
        // so their ?specialty_id is ignored rather than honoured (it must never widen a scope).
        $canPick = $user->isAdmin() || $user->canCoordinateConsultations();
        $this->scopeSpecialtyId = $canPick && isset($data['specialty_id']) ? (int) $data['specialty_id'] : null;

        return Inertia::render('Consultations/Dashboard', [
            'canPick' => $canPick,
            'filters' => ['specialty_id' => $this->scopeSpecialtyId],
            'specialties' => $canPick ? Specialty::orderBy('name')->get(['id', 'name']) : [],
            'scopeLabel' => $this->scopeLabel($user, $canPick),
            'openCounts' => $this->openCounts(),
            'ageing' => $this->ageing(),
            'generatedAt' => now()->format('H:i'),
        ]);
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    /** The ONE scoped query builder — see the class docblock for the three invariants it pins. */
    private function baseQuery(): Builder
    {
        return Consultation::query()
            ->visibleTo($this->currentUser())
            ->whereNull('consultations.deleted_at')
            ->when($this->scopeSpecialtyId !== null,
                fn (Builder $q) => $q->where('consultations.owning_specialty_id', $this->scopeSpecialtyId));
    }

    private function scopeLabel(User $user, bool $canPick): string
    {
        if ($this->scopeSpecialtyId !== null) {
            return (string) (Specialty::whereKey($this->scopeSpecialtyId)->value('name') ?? 'Unknown specialty');
        }
        if ($canPick) {
            return 'All specialties';
        }

        return (string) (Specialty::whereKey($user->specialty_id)->value('name') ?? 'Unassigned');
    }

    /**
     * Open counts by status:
     *   SELECT status, COUNT(*) FROM consultations
     *   WHERE deleted_at IS NULL AND <scope> AND status <> 'signed_off'
     *   GROUP BY status
     * Signed-off rows are never an "open" count, so the three keys always sum to `total`.
     */
    private function openCounts(): array
    {
        $rows = $this->baseQuery()
            ->where('consultations.status', '<>', Consultation::STATUS_SIGNED_OFF)
            ->selectRaw('consultations.status k, COUNT(*) c')
            ->groupBy('k')->pluck('c', 'k')->all();

        $new = (int) ($rows[Consultation::STATUS_NEW] ?? 0);
        $active = (int) ($rows[Consultation::STATUS_ACTIVE] ?? 0);
        $ongoing = (int) ($rows[Consultation::STATUS_ONGOING] ?? 0);

        return ['new' => $new, 'active' => $active, 'ongoing' => $ongoing,
            'total' => $new + $active + $ongoing];
    }

    /**
     * Ageing of OPEN consults, in whole days:
     *   DATEDIFF(CURDATE(), DATE(COALESCE(requested_at, consultation_date)))
     * `requested_at` is authoritative from cutover onward; `consultation_date` is the historical
     * fallback (all 1,283 legacy rows have requested_at NULL — see the design §4.4). A row with
     * NEITHER date is reported as `unknown` rather than silently bucketed: this dashboard never
     * invents a date it does not have.
     */
    private function ageing(): array
    {
        $age = 'DATEDIFF(CURDATE(), DATE(COALESCE(consultations.requested_at, consultations.consultation_date)))';
        $rows = $this->baseQuery()
            ->where('consultations.status', '<>', Consultation::STATUS_SIGNED_OFF)
            ->selectRaw("CASE
                    WHEN COALESCE(consultations.requested_at, consultations.consultation_date) IS NULL THEN 'unknown'
                    WHEN {$age} <= 2 THEN 'b0_2'
                    WHEN {$age} <= 7 THEN 'b3_7'
                    ELSE 'b8_plus'
                END k, COUNT(*) c")
            ->groupBy('k')->pluck('c', 'k')->all();

        return [
            'b0_2' => (int) ($rows['b0_2'] ?? 0),
            'b3_7' => (int) ($rows['b3_7'] ?? 0),
            'b8_plus' => (int) ($rows['b8_plus'] ?? 0),
            'unknown' => (int) ($rows['unknown'] ?? 0),
        ];
    }
}
