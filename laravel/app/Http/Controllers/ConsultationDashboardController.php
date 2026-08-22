<?php

namespace App\Http\Controllers;

use App\Models\Consultation;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
 *
 * The scope is passed as an ARGUMENT to every metric rather than held on the instance: metrics that
 * read mutable controller state are correct only while the assignment happens to precede them, and
 * a future reorder would silently serve unscoped counts.
 */
class ConsultationDashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->currentUser();
        if ($user->isObserver()) {
            throw new AccessDeniedHttpException('Observers have read-only access.');
        }

        // Validated for EVERY viewer, so a malformed or unknown id is a 422/redirect rather than a
        // silent no-op. It is only HONOURED for a picker (below) — a non-picker's parameter can
        // never widen (or narrow) what scopeVisibleTo already decided for them.
        $data = $request->validate([
            'specialty_id' => ['nullable', 'integer', 'exists:specialties,id'],
        ]);

        $canPick = $user->isAdmin() || $user->canCoordinateConsultations();
        $specialtyId = $canPick && isset($data['specialty_id']) ? (int) $data['specialty_id'] : null;
        // One query, reused for both the picker options and the scope label.
        $specialties = $canPick ? Specialty::orderBy('name')->get(['id', 'name']) : collect();

        return Inertia::render('Consultations/Dashboard', [
            'canPick' => $canPick,
            'filters' => ['specialty_id' => $specialtyId],
            'specialties' => $specialties,
            'scopeLabel' => $this->scopeLabel($user, $canPick, $specialtyId, $specialties),
            'openCounts' => $this->openCounts($user, $specialtyId),
            'ageing' => $this->ageing($user, $specialtyId),
            // Day-granular figures deserve a day-granular, zone-explicit stamp: "as of 14:30" on a
            // page whose every number counts whole days is ambiguous about WHICH day it means.
            'generatedAt' => now()->format('j M Y, H:i') . ' ' . config('app.timezone'),
        ]);
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    /** The ONE scoped query builder — see the class docblock for the three invariants it pins. */
    private function baseQuery(User $user, ?int $specialtyId): Builder
    {
        return Consultation::query()
            ->visibleTo($user)
            ->whereNull('consultations.deleted_at')
            ->when($specialtyId !== null,
                fn (Builder $q) => $q->where('consultations.owning_specialty_id', $specialtyId));
    }

    /**
     * baseQuery() narrowed to rows still on the books, which is what BOTH tiles on this page count —
     * so they can never disagree about which rows are open.
     *
     * `open()` is the model's canonical "not closed" filter (status <> 'signed_off') and is used
     * rather than re-spelling it here. `signoff_date` is then checked too, the same belt the
     * handover sheet carries and for the same reason: legacy:import writes `signoff_date` without
     * `status`, so after a reload with the source-of-truth flag off, closed legacy consults come
     * back as status='new'. Without this clause they would headline this page as open work and land
     * in the >7-day escalation bucket, while the printed sheet correctly ignored them.
     */
    private function openQuery(User $user, ?int $specialtyId): Builder
    {
        return $this->baseQuery($user, $specialtyId)->open()->whereNull('consultations.signoff_date');
    }

    /**
     * @param  Collection<int, Specialty>  $specialties  already-fetched picker options, if any
     */
    private function scopeLabel(User $user, bool $canPick, ?int $specialtyId, Collection $specialties): string
    {
        if ($specialtyId !== null) {
            $name = $specialties->firstWhere('id', $specialtyId)?->name
                ?? Specialty::whereKey($specialtyId)->value('name');

            return (string) ($name ?? 'Unknown specialty');
        }
        if ($canPick) {
            return 'All specialties';
        }

        // NOTE — this names the viewer's OWN specialty, which is the bulk of what they see but not
        // quite all of it: scopeVisibleTo also returns consults in other specialties where they are
        // the consultant or entered_by (see the scope's docblock). The label therefore reads as a
        // slight UNDER-statement of the scope — never an over-statement, so no row is hidden behind
        // a label that claims to exclude it.
        return (string) (Specialty::whereKey($user->specialty_id)->value('name') ?? 'Unassigned');
    }

    /**
     * Open counts by status:
     *   SELECT status, COUNT(*) FROM consultations
     *   WHERE deleted_at IS NULL AND <scope> AND <open> GROUP BY status
     *
     * `total` is the sum of the GROUPED rows, not new+active+ongoing: `status` is a plain string
     * column with no CHECK constraint, so a hand-run SQL fix on prod could leave a value outside the
     * three known states. Summing the rows keeps `total` honest and — critically — keeps it equal to
     * the ageing buckets below, which count the same row set. A named tile is never fabricated.
     */
    private function openCounts(User $user, ?int $specialtyId): array
    {
        $rows = $this->openQuery($user, $specialtyId)
            ->selectRaw('consultations.status k, COUNT(*) c')
            ->groupBy('k')->pluck('c', 'k')->all();

        return [
            'new' => (int) ($rows[Consultation::STATUS_NEW] ?? 0),
            'active' => (int) ($rows[Consultation::STATUS_ACTIVE] ?? 0),
            'ongoing' => (int) ($rows[Consultation::STATUS_ONGOING] ?? 0),
            'total' => (int) array_sum(array_map('intval', $rows)),
        ];
    }

    /**
     * Ageing of OPEN consults, in whole days:
     *   DATEDIFF(<app's today>, DATE(COALESCE(requested_at, consultation_date)))
     *
     * `requested_at` is authoritative from cutover onward; `consultation_date` is the historical
     * fallback (all 1,283 legacy rows have requested_at NULL — see the design §4.4). A row with
     * NEITHER date is reported as `unknown` rather than silently bucketed: this dashboard never
     * invents a date it does not have.
     *
     * "Today" is BOUND from PHP (config('app.timezone'), i.e. the clinical day the rest of the app
     * runs on) instead of using MySQL's CURDATE(). config/database.php pins no session timezone, so
     * CURDATE() is the DB host's day — UTC on the deployment box — and between 00:00 and 03:00 local
     * it is still YESTERDAY. That is exactly when the night shift prints the handover sheet, and it
     * would have made this page disagree by one day with ConsultationsController::openDays() (the
     * ONE ageing rule, which compares against PHP's now()->startOfDay()) about the very same consult:
     * the sheet showing "8 days, escalate" while the "Over 7 days" tile read 0. Same rule, same day,
     * one source of truth for what day it is.
     */
    private function ageing(User $user, ?int $specialtyId): array
    {
        $today = now()->toDateString();
        $age = 'DATEDIFF(?, DATE(COALESCE(consultations.requested_at, consultations.consultation_date)))';
        $rows = $this->openQuery($user, $specialtyId)
            ->selectRaw("CASE
                    WHEN COALESCE(consultations.requested_at, consultations.consultation_date) IS NULL THEN 'unknown'
                    WHEN {$age} <= 2 THEN 'b0_2'
                    WHEN {$age} <= 7 THEN 'b3_7'
                    ELSE 'b8_plus'
                END k, COUNT(*) c", [$today, $today])
            ->groupBy('k')->pluck('c', 'k')->all();

        return [
            'b0_2' => (int) ($rows['b0_2'] ?? 0),
            'b3_7' => (int) ($rows['b3_7'] ?? 0),
            'b8_plus' => (int) ($rows['b8_plus'] ?? 0),
            'unknown' => (int) ($rows['unknown'] ?? 0),
        ];
    }
}
