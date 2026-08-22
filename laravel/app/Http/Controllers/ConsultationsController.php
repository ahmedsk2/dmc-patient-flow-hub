<?php

namespace App\Http\Controllers;

use App\Models\Consultation;
use App\Models\ConsultationReason;
use App\Models\Patient;
use App\Models\Specialty;
use App\Models\User;
use App\Support\Audit;
use App\Support\AuditDiff;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ConsultationsController extends Controller
{
    /**
     * The ONLY moves POST /consultations/{consultation}/status will make. Sign-off is absent on
     * purpose: it is a clinical assertion carrying a required response payload, so it goes through
     * signoff() and its own canManageConsultation gate. `signed_off` maps to an empty list — a
     * signed-off consult is frozen until an admin reverses the sign-off (same-day only).
     */
    private const STATUS_MOVES = [
        Consultation::STATUS_NEW => [Consultation::STATUS_ACTIVE, Consultation::STATUS_ONGOING],
        Consultation::STATUS_ACTIVE => [Consultation::STATUS_ONGOING],
        Consultation::STATUS_ONGOING => [Consultation::STATUS_ACTIVE],
        Consultation::STATUS_SIGNED_OFF => [],
    ];

    /** The four tabs of the workspace, in display order — also the only accepted `?status=` values. */
    private const STATUS_TABS = [
        Consultation::STATUS_NEW, Consultation::STATUS_ACTIVE,
        Consultation::STATUS_ONGOING, Consultation::STATUS_SIGNED_OFF,
    ];

    public function index(Request $request): Response|RedirectResponse
    {
        // legacy access_PICU_patients [0,2,3,4] page gate — observers are read-only and the
        // consultations workspace is a clinical-role page (J2-12)
        if (Auth::user()->isObserver()) {
            throw new AccessDeniedHttpException('Observers have read-only access.');
        }

        // SPC-TM-011 (Wave 1): the free-text term (patient name/MRN) rides a POST body
        // (/consultations/search). A legacy GET-with-term redirects term-less, keeping status/scope.
        if ($request->isMethod('get') && trim((string) $request->query('search', '')) !== '') {
            return redirect()->route('consultations.index', \Illuminate\Support\Arr::except($request->query(), ['search']));
        }

        $filters = $request->only('search', 'status', 'scope');
        // W2A: `status` now names one of the FOUR real states. Anything else — an absent param, or a
        // bookmarked legacy `?status=signed` — falls back to `new`, the tab that demands triage. The
        // per-tab counts ship on every render, so an empty default tab still points at the work.
        $status = in_array($filters['status'] ?? '', self::STATUS_TABS, true)
            ? $filters['status']
            : Consultation::STATUS_NEW;
        $mine = ($filters['scope'] ?? '') === 'mine';
        $reasons = ConsultationReason::pluck('name', 'id');

        // W1 specialty scoping: every list AND every count runs through the same visibility scope,
        // so a tab count can never advertise rows the viewer is not allowed to open.
        $scoped = fn () => Consultation::query()->visibleTo(Auth::user());

        // The per-status tab counts must answer the SAME question the list is currently answering —
        // "how many rows in this state match what I'm looking at" — not a silent unconditional total.
        // Sharing this builder between the list and $counts (below) means a clinician who searches an
        // MRN sees the tab badges agree with the list: zero in the current tab, N in another, which
        // doubles as the "this patient's consult is over there" signal the search itself can't give
        // once results are confined to one status.
        $filtered = fn () => $scoped()
            ->when($mine, fn ($q) => $q->where('consultant_id', Auth::id()))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) =>
                $w->where('patient_name', 'like', "%{$s}%")->orWhere('mrn', 'like', "%{$s}%")));

        // resolved ONCE for the whole page: every row's can_modify is this user's verdict
        $viewer = Auth::user();

        $consultations = $filtered()
            ->with('consultant:id,full_name,name')
            ->where('status', $status)
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Consultation $c) => [
                'id' => $c->id,
                'name' => $c->patient_name ?? 'Unknown',
                'mrn' => $c->mrn,
                'age' => $c->age,
                'bed' => $c->bed,
                'location' => $c->current_location,
                'from' => $c->consultation_from,
                'to' => $c->to_service,
                'consultant' => $c->consultant?->full_name ?? $c->consultant?->name ?? '—',
                'consultant_id' => $c->consultant_id,
                'date' => optional($c->consultation_date)->toDateString(),
                'signoff' => optional($c->signoff_date)->toDateString(),
                'status' => $c->status,
                'open_days' => self::openDays($c),
                // The workspace cannot mirror canModifyConsultation on its own — the shared auth
                // payload carries only the four capability flags and no specialty — so the verdict
                // ships per row. Without it the client fell back to admin/can_manage/own-consultant,
                // which is far NARROWER than the server: a registrar of the owning team saw her own
                // book's untriaged rows and could move none of them, and coordinators — whose whole
                // purpose is modifying everyone's — got no controls at all. Sign-off is deliberately
                // NOT derived from this (canManageConsultation stays its own, narrower gate).
                'can_modify' => $viewer->canModifyConsultation($c),
                'disposition' => $c->response_disposition,
                // the OTHER half of the recorded response: collected at sign-off, and until now
                // never shown to anyone
                'followup_needed' => $c->response_followup_needed,
                'reasons' => collect($c->indication ?? [])->map(fn ($id) => $reasons[$id] ?? null)->filter()->values(),
                'indication_ids' => array_map('intval', $c->indication ?? []),
                'other' => $c->other_indication,
            ]);

        $counts = $filtered()->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');

        return Inertia::render('Consultations/Index', [
            'consultations' => $consultations,
            'filters' => ['search' => $filters['search'] ?? '', 'status' => $status, 'scope' => $mine ? 'mine' : ''],
            // full objects (not just names): the form filters the consultant dropdown by
            // INTERNAL specialty when "to service" matches one
            'specialties' => Specialty::orderBy('name')->get(['id', 'name', 'is_external']),
            'stats' => [
                Consultation::STATUS_NEW => (int) ($counts[Consultation::STATUS_NEW] ?? 0),
                Consultation::STATUS_ACTIVE => (int) ($counts[Consultation::STATUS_ACTIVE] ?? 0),
                Consultation::STATUS_ONGOING => (int) ($counts[Consultation::STATUS_ONGOING] ?? 0),
                Consultation::STATUS_SIGNED_OFF => (int) ($counts[Consultation::STATUS_SIGNED_OFF] ?? 0),
                // matches-the-current-tabs total: same $filtered() builder as the four counts above,
                // so it always sums to them. Deliberately DIFFERENT from `open`/`mine_open` below,
                // which are headline "of all open work" figures and stay unfiltered by search/mine
                // on purpose — they answer "how am I doing overall", not "what does this view show".
                'total' => (int) $counts->sum(),
                // personal counter for consultant-role viewers (K1-13): own OPEN out of all open
                'mine_open' => $scoped()->open()->where('consultant_id', Auth::id())->count(),
                'open' => $scoped()->open()->count(),
            ],
            'reasons' => $reasons->map(fn ($name, $id) => ['id' => $id, 'name' => $name])->values(),
            'consultants' => User::consultantOptions(),
        ]);
    }

    public function store(\App\Http\Requests\ConsultationRequest $request): RedirectResponse
    {
        // Observer gate lives in ConsultationRequest::authorize() (403 before validation)
        $data = $request->validated();

        $patient = Patient::where('mrn', $data['mrn'])->first();
        // W1: the owning team is RESOLVED server-side from to_service (internal specialties only).
        // An external/free-text service resolves to NULL — the Unassigned bucket. Never guessed,
        // and never accepted from the payload.
        $c = Consultation::create([
            ...$data,
            'patient_id' => $patient?->id,
            'owning_specialty_id' => self::resolveOwningSpecialtyId($data['to_service'] ?? null),
            'requested_at' => now(),                     // REAL request time — cutover onward only
            'indication' => $data['indication'] ?? [],
            'entered_by' => Auth::id(),                  // session-sourced, immutable
        ]);
        Audit::log('consultation.create', 'consultation', (string) $c->id, ['mrn' => $data['mrn']]);

        return back()->with('flash', ['type' => 'success', 'message' => 'Consultation created.']);
    }

    /**
     * Move a consultation between the three OPEN states. Authorization is the same predicate that
     * guards editing — own specialty / coordinator / admin, observers refused first — so anyone who
     * may work the consult may say where it stands. Illegal moves answer 422 JSON (jsonFail): this
     * route is outside api/*, so a plain ValidationException would redirect instead, and the tabs
     * only ever offer legal moves, making a 422 a genuine client/API error rather than user input.
     */
    public function status(Request $request, Consultation $consultation): RedirectResponse
    {
        if (! Auth::user()->canModifyConsultation($consultation)) {
            throw new AccessDeniedHttpException('You may not change this consultation.');
        }

        $data = $this->jsonValidate($request, [
            'status' => ['required', 'string', Rule::in([
                Consultation::STATUS_NEW, Consultation::STATUS_ACTIVE, Consultation::STATUS_ONGOING,
            ])],
        ]);

        $from = (string) $consultation->status;
        $to = $data['status'];

        // Belt AND braces: `status` and `signoff_date` are meant to be written together (see the
        // Consultation docblock), but the model itself names column drift as the standing hazard —
        // and a row carrying a sign-off date is closed whatever its status column happens to say.
        // Keying the freeze on either column means this guard cannot be defeated by that drift.
        $frozen = $from === Consultation::STATUS_SIGNED_OFF || $consultation->signoff_date !== null;

        if ($frozen || ! in_array($to, self::STATUS_MOVES[$from] ?? [], true)) {
            $this->jsonFail(['status' => $frozen
                ? 'A signed-off consultation cannot be moved. An admin must reverse the sign-off first.'
                : "A consultation cannot move from {$from} to {$to}."]);
        }

        $consultation->update(['status' => $to]);
        Audit::log('consultation.status_change', 'consultation', (string) $consultation->id,
            ['from' => $from, 'to' => $to]);

        return back()->with('flash', ['type' => 'success', 'message' => 'Consultation status updated.']);
    }

    /**
     * Sign off — now the ledger's closing entry rather than a date stamp. The gate is UNCHANGED
     * (User::canManageConsultation: observers refused first, then admin / can_manage / the receiving
     * consultant) and simply moved into ConsultationSignoffRequest::authorize() so the 403 fires
     * before validation. Coordinators are not in that predicate and stay refused by design.
     */
    public function signoff(\App\Http\Requests\ConsultationSignoffRequest $request, Consultation $consultation): RedirectResponse
    {
        if ($consultation->signoff_date) {
            return back()->with('flash', ['type' => 'error', 'message' => 'Already signed off.']);
        }
        $data = $request->validated();
        $consultation->update([
            'status' => Consultation::STATUS_SIGNED_OFF,
            'signoff_date' => now()->toDateString(),   // retained: every legacy report reads this
            'signed_off_at' => now(),                   // real time — cutover onward
            'signed_off_by' => Auth::id(),              // session-sourced, never from the payload
            'response_disposition' => $data['response_disposition'],
            'response_followup_needed' => (bool) ($data['response_followup_needed'] ?? false),
            'response_note' => $data['response_note'] ?? null,
        ]);
        Audit::log('consultation.signoff', 'consultation', (string) $consultation->id, [
            'disposition' => $data['response_disposition'],
            'followup_needed' => (bool) ($data['response_followup_needed'] ?? false),
        ]);

        return back()->with('flash', ['type' => 'success', 'message' => 'Consultation signed off.']);
    }

    /**
     * POST /consultations/{consultation}/followup — record TODAY's "seen" tick.
     *
     * JSON in / JSON out: the worklist ticks rows off with fetch() and renders a refusal inline, so
     * this must answer a plain 422 rather than an Inertia redirect. Non-api/* routes do not get
     * Laravel's automatic exception-to-JSON rendering, which is what jsonValidate()/jsonFail() are for.
     *
     * Order of checks matters:
     *   1. Observers are read-only BEFORE any capability flag (the global guarantee).
     *   2. The actor must be on the RECEIVING side of the consult — see mayRecordFollowup(), which
     *      is deliberately narrower than the read predicate.
     *   3. A signed-off consult is closed — nothing may be appended to it.
     *   4. One tick per consult per day. A second tick the same day is REJECTED with a friendly 422,
     *      never a silent overwrite: the row is append-only and "seen X of Y" must stay exact.
     *   5. The FIRST tick on a `new` consult promotes it to `active` — the single automatic status
     *      transition in the whole design, because "not seen" has just become untrue.
     */
    public function followup(Request $request, Consultation $consultation): JsonResponse
    {
        $user = Auth::user();
        if ($user->isObserver()) {
            throw new AccessDeniedHttpException('Observers have read-only access.');
        }
        if (! self::mayRecordFollowup($user, $consultation)) {
            throw new AccessDeniedHttpException('This consultation belongs to another service.');
        }

        $data = $this->jsonValidate($request, ['note' => ['nullable', 'string', 'max:500']]);
        $note = trim((string) ($data['note'] ?? '')) ?: null;

        // Same either-column freeze as status(): `status` and `signoff_date` are meant to move
        // together, but legacy:import writes `signoff_date` and never writes `status`, so every
        // re-imported signed-off row carries the schema default `new`. Keying on `status` alone
        // would let a CLOSED consult be ticked AND auto-promoted into the daily worklist.
        if ($consultation->status === Consultation::STATUS_SIGNED_OFF || $consultation->signoff_date !== null) {
            $this->jsonFail(['note' => 'This consultation is signed off — no further follow-up can be recorded.']);
        }

        $today = now()->toDateString();
        if ($consultation->followups()->whereDate('followup_date', $today)->exists()) {
            $this->jsonFail(['note' => 'A follow-up is already recorded for this consultation today.']);
        }

        $promoted = false;
        try {
            DB::transaction(function () use ($consultation, $note, $today, &$promoted) {
                $consultation->followups()->create([
                    'followup_date' => $today,
                    'note' => $note,
                    'author_id' => Auth::id(),          // session-sourced, never from the payload
                ]);
                if ($consultation->status === Consultation::STATUS_NEW) {
                    $consultation->update(['status' => Consultation::STATUS_ACTIVE]);
                    $promoted = true;
                }
                Audit::log('consultation.followup', 'consultation', (string) $consultation->id, [
                    'followup_date' => $today,
                    'has_note' => $note !== null,
                ]);
                if ($promoted) {
                    Audit::log('consultation.status_change', 'consultation', (string) $consultation->id, [
                        'from' => Consultation::STATUS_NEW,
                        'to' => Consultation::STATUS_ACTIVE,
                        'reason' => 'first_followup',
                    ]);
                }
            });
        } catch (UniqueConstraintViolationException) {
            // lost a race with a concurrent tick (double-click / two devices) — same friendly answer
            // as the pre-check above rather than a 500 on a live clinical page
            $this->jsonFail(['note' => 'A follow-up is already recorded for this consultation today.']);
        }

        return response()->json([
            'ok' => true,
            'status' => $consultation->fresh()->status,
            'promoted' => $promoted,
            'followup_date' => $today,
        ]);
    }

    /**
     * May this user record TODAY's tick against this consult?
     *
     * Deliberately NARROWER than User::canSeeConsultation(), which is a VISIBILITY predicate and on
     * purpose also returns true for `entered_by` ("you never lose sight of a consult you personally
     * booked"). A follow-up is not a read: it is the receiving team's assertion that they rounded on
     * this patient today. Letting the REFERRER tick it — a Nephrology registrar closing out the day
     * on a Cardiology consult she merely booked — would misattribute the clinical fact (`author_id`
     * would name her) and inflate Cardiology's "seen 8 of 12" with a round nobody on that team made.
     *
     * So: the owning team, the assigned consultant, a coordinator, or an admin. Observers are
     * refused by the caller before this is ever reached.
     */
    private static function mayRecordFollowup(User $user, Consultation $consultation): bool
    {
        if ($user->isAdmin() || $user->canCoordinateConsultations()) {
            return true;
        }
        if ($user->specialty_id !== null
            && (int) $consultation->owning_specialty_id === (int) $user->specialty_id) {
            return true;
        }

        return $consultation->consultant_id !== null
            && (int) $consultation->consultant_id === (int) $user->id;
    }

    /**
     * Undo a same-day sign-off (admin only) — clears the ENTIRE closing entry, not just the date.
     * Sign-off writes a block (status + signed_off_at + signed_off_by + the response), so a reversal
     * that cleared only `signoff_date` would leave the row still naming a signer, a time and a
     * disposition for a consult that is no longer closed — a misattributed clinical record — and
     * would strand it forever, because the status endpoint freezes anything carrying
     * `status = signed_off`. The consult returns to `ongoing`: back on the books, with no daily
     * commitment invented for it.
     */
    public function reverseSignoff(Request $request, Consultation $consultation): RedirectResponse
    {
        if (! Auth::user()->isAdmin()) {
            throw new AccessDeniedHttpException('Admin only.');
        }
        // Undo corrects a SAME-DAY mistake (legacy 48consultation.php undo) — mirrors the
        // same-day reverse-discharge guard; older sign-offs are history.
        if (! $consultation->signoff_date) {
            return back()->with('flash', ['type' => 'error', 'message' => 'This consultation is not signed off.']);
        }
        if (! $consultation->signoff_date->isToday()) {
            return back()->with('flash', ['type' => 'error', 'message' => 'Only same-day sign-offs can be reversed.']);
        }
        $was = [
            'disposition' => $consultation->response_disposition,
            'followup_needed' => $consultation->response_followup_needed,
            'note' => $consultation->response_note,
            'signed_off_by' => $consultation->signed_off_by,
            'signed_off_at' => optional($consultation->signed_off_at)->toDateTimeString(),
            'to' => Consultation::STATUS_ONGOING,
        ];
        $consultation->update([
            'status' => Consultation::STATUS_ONGOING,
            'signoff_date' => null,        // kept in lockstep with `status` — see the Consultation docblock
            'signed_off_at' => null,
            'signed_off_by' => null,
            'response_disposition' => null,
            'response_followup_needed' => null,
            'response_note' => null,
        ]);
        // the cleared response (incl. the free-text note) is preserved in the audit trail, which is
        // where an undone clinical assertion belongs — on the record of what happened, not on the
        // live row
        Audit::log('consultation.reverse_signoff', 'consultation', (string) $consultation->id, $was);

        return back()->with('flash', ['type' => 'success', 'message' => 'Sign-off reversed.']);
    }

    /** Edit a consultation (receiving consultant / manager / admin). */
    public function update(\App\Http\Requests\ConsultationRequest $request, Consultation $consultation): RedirectResponse
    {
        // edit is open to any clinical role (J1-10 legacy parity); gate lives in ConsultationRequest::authorize()
        $data = $request->validated();
        // field-level diff (Item 4): snapshot the editable fields before the update, diff after
        $fields = ['patient_name', 'mrn', 'age', 'bed', 'current_location',
            'consultation_from', 'to_service', 'consultant_id', 'indication', 'other_indication',
            // an ownership move is a clinical fact about who is carrying the patient — it belongs in
            // the diff, not just in the hidden column
            'owning_specialty_id'];
        $before = $consultation->only($fields);
        $payload = [...$data, 'indication' => $data['indication'] ?? []];
        // W1: `to_service` is the routing label the clinician reads; `owning_specialty_id` is the
        // book that actually controls visibility. Move one and the other MUST follow, or the consult
        // displays as one team's while living in another's — cluttering the book it left and never
        // appearing in the one it names. Re-resolved only when the label actually changes, so an
        // ownership set deliberately elsewhere is never clobbered by an unrelated edit.
        if (array_key_exists('to_service', $data)
            && (string) $data['to_service'] !== (string) $consultation->to_service) {
            $payload['owning_specialty_id'] = self::resolveOwningSpecialtyId($data['to_service']);
        }
        $consultation->update($payload);
        $diff = AuditDiff::diff($before, $consultation->fresh()->only($fields));
        Audit::log('consultation.modify', 'consultation', (string) $consultation->id, $diff);

        return back()->with('flash', ['type' => 'success', 'message' => 'Consultation updated.']);
    }

    /** Delete a consultation (admin only). */
    public function destroy(Request $request, Consultation $consultation): RedirectResponse
    {
        if (! Auth::user()->isAdmin()) {
            throw new AccessDeniedHttpException('Admin only.');
        }
        $id = $consultation->id;
        $consultation->delete();
        Audit::log('consultation.delete', 'consultation', (string) $id);

        // W0: soft delete — say so, so nobody believes the record is gone for good.
        return back()->with('flash', ['type' => 'success',
            'message' => 'Consultation removed from the ledger. An admin can restore it from Recently Deleted.']);
    }

    /**
     * Map a `to_service` name onto the owning IM subspecialty (case-insensitive, internal only) —
     * the same matching rule as the W1 backfill migration, so a row created today and a row
     * backfilled yesterday land in the same book. Anything unmatched (external services, free text)
     * returns NULL: Unassigned. Shared with ConsultationRequest's own-specialty rule.
     */
    public static function resolveOwningSpecialtyId(?string $toService): ?int
    {
        $wanted = mb_strtolower(trim((string) $toService));
        if ($wanted === '') {
            return null;
        }

        $match = Specialty::where('is_external', false)->get(['id', 'name'])
            ->first(fn (Specialty $s) => mb_strtolower(trim((string) $s->name)) === $wanted);

        return $match?->id;
    }

    /**
     * How long an OPEN consult has been waiting, in whole days.
     *
     * `requested_at` is the authoritative request time but is NULL for all 1,283 historical rows —
     * §4.4 refuses to fabricate a time that never existed — so ageing falls back to the date-only
     * `consultation_date` those rows do have. Both are compared at day granularity, which is the
     * only precision the fallback can honestly claim. A signed-off consult is not open and has no
     * ageing; a row with neither timestamp reports NULL ("—") rather than a misleading 0.
     */
    private static function openDays(Consultation $c): ?int
    {
        if ($c->status === Consultation::STATUS_SIGNED_OFF) {
            return null;
        }
        $start = $c->requested_at ?? $c->consultation_date;
        if ($start === null) {
            return null;
        }

        return max(0, (int) $start->copy()->startOfDay()->diffInDays(now()->startOfDay()));
    }
}
