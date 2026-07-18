<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use App\Models\Handover;
use App\Models\HandoverRevision;
use App\Models\HandoverSignature;
use App\Models\Notification;
use App\Models\Setting;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Handover text (current + revisions), the signature inbox, and the in-app notification feed.
 * Every mutation is audited and attributed to the SESSION user, like PatientActionController.
 */
class HandoverController extends Controller
{
    /** GET /admissions/{admission}/handover — current text + latest 20 revisions (all roles, read-only). */
    public function show(Admission $admission): JsonResponse
    {
        // Break-glass PHI-read logging (parity with AdmissionsController::edit): the handover body +
        // revisions are free-text clinical narrative, so when an admin turns `log_record_opens` ON
        // this writes ONE audit row per open. Reads stay all-roles (handover is cross-cover by design).
        if (Setting::current()->log_record_opens) {
            Audit::log('handover.read', 'admission', (string) $admission->id, ['mrn' => $admission->patient?->mrn]);
        }

        $h = $admission->handover()->with('updatedBy:id,name,full_name')->first();

        return response()->json([
            'body' => $h?->body,
            'checkpoints' => $h?->checkpoints,
            'updated_by_name' => $h?->updatedBy ? ($h->updatedBy->full_name ?: $h->updatedBy->name) : null,
            'updated_at' => $h?->updated_at?->toIso8601String(),
            'today' => $h !== null && $h->updated_at->isToday(),
            'revisions' => HandoverRevision::where('admission_id', $admission->id)
                ->with('author:id,name,full_name')->orderByDesc('id')->limit(20)->get()
                ->map(fn ($r) => [
                    'body' => $r->body,
                    'checkpoints' => $r->checkpoints,
                    'author' => $r->author ? ($r->author->full_name ?: $r->author->name) : null,
                    'at' => $r->created_at?->toIso8601String(),
                ]),
        ]);
    }

    /** POST /admissions/{admission}/handover — upsert the current text + append a revision. */
    public function save(Request $request, Admission $admission)
    {
        if (Auth::user()->isObserver()) {
            throw new AccessDeniedHttpException('Observers are read-only.');
        }
        // canManage, PLUS the outgoing consultant of a still-pending signature: after a gated
        // transfer they no longer own the admission but must be able to refresh the text until
        // the receiver signs (the inbox "My outgoing" editor).
        $isOutgoing = HandoverSignature::where('admission_id', $admission->id)->pending()
            ->where('from_consultant_id', Auth::id())->exists();
        if (! (Auth::user()->canManageAdmission($admission) || $isOutgoing)) {
            throw new AccessDeniedHttpException('Only the primary consultant or a manager may update the handover.');
        }
        // jsonValidate(), not $request->validate(): this route isn't under api/* so Laravel's
        // automatic exception-to-JSON rendering (bootstrap/app.php's shouldRenderJsonWhen) is
        // OFF here — a plain validate() failure would redirect-back instead of answering JSON,
        // which breaks the fetch()-based saveHandover()/checkpoint callers (see Controller::jsonValidate).
        $data = $this->jsonValidate($request, [
            'body' => ['required', 'string', 'max:5000'],
            'checkpoints' => ['nullable', 'array'],
            'checkpoints.vte_completed' => ['boolean'],
            'checkpoints.ready_for_discharge' => ['boolean'],
            'checkpoints.high_risk' => ['boolean'],
            'checkpoints.needs_workup' => ['boolean'],
            'checkpoints.workup_pending' => ['boolean'],
            'checkpoints.code_status' => ['nullable', 'in:full,dnr,dni'],
        ]);

        DB::transaction(function () use ($admission, $data, $request) {
            $h = Handover::firstOrNew(['admission_id' => $admission->id]);
            // checkpoints: replace with the submitted set when present, else keep the current ones
            $cp = $request->has('checkpoints') ? $this->normalizeCheckpoints($data['checkpoints'] ?? []) : $h->checkpoints;
            $h->body = $data['body'];
            $h->checkpoints = $cp;
            $h->updated_by = Auth::id();
            $h->updated_at = now();   // explicit — an unchanged body must still satisfy the same-day gate
            $h->save();
            HandoverRevision::create(['admission_id' => $admission->id, 'body' => $data['body'], 'checkpoints' => $cp, 'author_id' => Auth::id()]);
        });
        Audit::log('handover.update', 'admission', (string) $admission->id, ['revision_id' => HandoverRevision::latestIdFor($admission->id)]);

        // Resolve any persistent "incomplete handover" reminders for this admission (all recipients).
        // Keyed on the indexed admission_id column — the previous JSON payload compare required a
        // (string) cast to match at all, which was a real bug once.
        Notification::where('type', 'handover.incomplete')->whereNull('resolved_at')
            ->where('admission_id', $admission->id)->update(['resolved_at' => now()]);

        return $request->expectsJson()
            ? response()->json(['saved' => true, 'updated_at' => now()->toIso8601String()])
            : back()->with('flash', ['type' => 'success', 'message' => 'Handover saved.']);
    }

    /** GET /handovers — the signature inbox (awaiting my signature / my outgoing, last 7 days). */
    public function index(): Response
    {
        $me = Auth::id();
        $shape = fn (HandoverSignature $s) => [
            'id' => $s->id,
            'admission_id' => $s->admission_id,
            'patient' => $s->admission?->patient?->name ?? 'Unknown',
            'mrn' => $s->admission?->patient?->mrn,
            'bed' => $s->admission?->bed,
            // N1-6: 'location' (current_location) was never read by Handovers/Index.vue — dropped
            'from' => $s->fromConsultant ? ($s->fromConsultant->full_name ?: $s->fromConsultant->name) : '—',
            'to' => $s->toConsultant ? ($s->toConsultant->full_name ?: $s->toConsultant->name) : '—',
            'required_at' => $s->required_at?->toIso8601String(),
            'signed_at' => $s->signed_at?->toIso8601String(),
            'voided_at' => $s->voided_at?->toIso8601String(),
            'body' => $s->admission?->handover?->body,
            'body_updated_at' => $s->admission?->handover?->updated_at?->toIso8601String(),
            'checkpoints' => $s->admission?->handover?->checkpoints,
        ];
        $with = ['admission.patient:id,mrn,name', 'admission.handover', 'fromConsultant:id,name,full_name', 'toConsultant:id,name,full_name'];

        return Inertia::render('Handovers/Index', [
            'awaiting' => HandoverSignature::where('to_consultant_id', $me)->pending()
                ->with($with)->orderByDesc('required_at')->get()->map($shape)->values(),
            'outgoing' => HandoverSignature::where('from_consultant_id', $me)
                ->where('required_at', '>=', now()->subDays(7))
                ->with($with)->orderByDesc('required_at')->get()->map($shape)->values(),
            // HC-T8: the inbox "Needs handover" tab — active admissions with no note saved today,
            // scoped by the SAME D1 own-only predicate as the board (User::seesOwnPatientsOnly):
            // own patients only for a plain consultant, unit-wide for admin/registrar/resident/observer.
            'needsHandover' => Admission::needsHandoverToday()
                ->when(Auth::user()->seesOwnPatientsOnly(), fn ($q) => $q->where('consultant_id', Auth::id()))
                ->with(['patient:id,mrn,name', 'handover', 'consultant:id,name,full_name'])
                ->orderBy('admit_date')->get()
                ->map(fn ($a) => [
                    'admission_id' => $a->id,
                    'patient' => $a->patient?->name ?? 'Unknown',
                    'mrn' => $a->patient?->mrn,
                    'bed' => $a->bed,
                    'consultant' => $a->consultant ? ($a->consultant->full_name ?: $a->consultant->name) : '—',
                    'last_updated' => $a->handover?->updated_at?->toIso8601String(),
                    'checkpoints' => $a->handover?->checkpoints,
                ])->values(),
        ]);
    }

    /**
     * GET /handovers/preflight?from_consultant_id= — per-admission handover freshness for the
     * bulk-reassign modal (same capability gate as the bulk reassign itself).
     */
    public function preflight(Request $request): JsonResponse
    {
        $u = Auth::user();
        if (! ($u->isAdmin() || $u->can_assign || $u->can_manage)) {
            throw new AccessDeniedHttpException('Requires the Assign or Manage capability.');
        }
        $data = $request->validate(['from_consultant_id' => ['required', 'exists:users,id']]);

        return response()->json(Admission::active()->where('consultant_id', $data['from_consultant_id'])
            ->with(['patient:id,mrn,name', 'handover'])->orderBy('admit_date')->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->patient?->name ?? 'Unknown',
                'mrn' => $a->patient?->mrn,
                'handover_today' => $a->handover !== null && $a->handover->updated_at->isToday(),
                'body' => $a->handover?->body,
                'checkpoints' => $a->handover?->checkpoints,
                'updated_at' => $a->handover?->updated_at?->toIso8601String(),
            ])->values());
    }

    /** POST /handovers/{signature}/sign — receiving consultant (or admin) acknowledges the handover. */
    public function sign(HandoverSignature $signature)
    {
        $u = Auth::user();
        if ($u->isObserver() || ! ($u->isAdmin() || (int) $signature->to_consultant_id === (int) $u->id)) {
            throw new AccessDeniedHttpException('Only the receiving consultant may sign.');
        }
        if ($signature->voided_at) {
            return back()->with('flash', ['type' => 'error', 'message' => 'This handover request is no longer active.']);
        }
        if ($signature->signed_at) {
            return back()->with('flash', ['type' => 'error', 'message' => 'Already signed.']);
        }
        $this->signRow($signature);

        return back()->with('flash', ['type' => 'success', 'message' => 'Handover signed.']);
    }

    /** POST /handovers/sign-many {ids:[]} — sign every pending signature addressed to me. */
    public function signMany(Request $request)
    {
        $u = Auth::user();
        if ($u->isObserver()) {
            throw new AccessDeniedHttpException('Observers are read-only.');
        }
        $data = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']]);

        $rows = HandoverSignature::whereIn('id', $data['ids'])->pending()
            ->when(! $u->isAdmin(), fn ($q) => $q->where('to_consultant_id', $u->id))
            ->get();
        DB::transaction(function () use ($rows) {
            foreach ($rows as $s) {
                $this->signRow($s);
            }
        });

        return back()->with('flash', ['type' => $rows->count() ? 'success' : 'error',
            'message' => $rows->count() ? "Signed {$rows->count()} handover(s)." : 'Nothing to sign.']);
    }

    /** Sign one row: stamp + re-bind to the CURRENT latest revision (what the signer actually read). */
    private function signRow(HandoverSignature $s): void
    {
        $s->update([
            'signed_at' => now(), 'signed_by' => Auth::id(),
            'revision_id' => HandoverRevision::latestIdFor($s->admission_id) ?? $s->revision_id,
        ]);
        Audit::log('handover.sign', 'admission', (string) $s->admission_id, ['signature_id' => $s->id, 'revision_id' => $s->revision_id]);
    }

    /** Coerce the six checkpoint fields to a canonical shape (booleans + a nullable code_status enum). */
    private function normalizeCheckpoints(array $c): array
    {
        return [
            'vte_completed' => (bool) ($c['vte_completed'] ?? false),
            'ready_for_discharge' => (bool) ($c['ready_for_discharge'] ?? false),
            'high_risk' => (bool) ($c['high_risk'] ?? false),
            'needs_workup' => (bool) ($c['needs_workup'] ?? false),
            'workup_pending' => (bool) ($c['workup_pending'] ?? false),
            'code_status' => in_array($c['code_status'] ?? null, ['full', 'dnr', 'dni'], true) ? $c['code_status'] : null,
        ];
    }

    /**
     * GET /api/notifications — latest 15 for the bell dropdown + the still-open "actionable"
     * (handover.incomplete) list + a resolved-aware unread count. Actionable reminders persist
     * in both the dropdown and the badge count until resolved server-side (HandoverController::save),
     * NOT dismissed by read-all — see readAll() below.
     */
    public function notifications(): JsonResponse
    {
        $me = Auth::id();
        $actionable = fn ($q) => $q->where('type', 'handover.incomplete')->whereNull('resolved_at');

        return response()->json([
            'notifications' => Notification::where('user_id', $me)->orderByDesc('id')->limit(15)
                ->get(['id', 'type', 'payload', 'read_at', 'resolved_at', 'created_at']),
            'actionable' => Notification::where('user_id', $me)->where($actionable)
                ->orderByDesc('id')->get(['id', 'type', 'payload', 'created_at']),
            'unread' => Notification::where('user_id', $me)->where(fn ($q) => $q
                ->where(fn ($x) => $x->where('type', '!=', 'handover.incomplete')->whereNull('read_at'))
                ->orWhere($actionable))->count(),
        ]);
    }

    /** POST /notifications/read-all — opening the bell marks everything read EXCEPT actionable reminders (those persist until resolved). */
    public function readAll(Request $request)
    {
        Notification::where('user_id', Auth::id())->whereNull('read_at')
            ->where('type', '!=', 'handover.incomplete')->update(['read_at' => now()]);

        return $request->expectsJson() ? response()->json(['ok' => true]) : back();
    }
}
