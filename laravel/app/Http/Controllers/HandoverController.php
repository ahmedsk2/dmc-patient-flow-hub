<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use App\Models\AuditLog;
use App\Models\Handover;
use App\Models\HandoverRevision;
use App\Models\HandoverSignature;
use App\Models\Notification;
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
    private function audit(string $action, int $admissionId, array $details = []): void
    {
        AuditLog::create([
            'actor_id' => Auth::id(), 'actor_name' => Auth::user()->name,
            'action' => $action, 'entity_type' => 'admission', 'entity_id' => (string) $admissionId,
            'details' => $details, 'ip' => request()->ip(),
        ]);
    }

    /** Same rule as PatientActionController::canManage (owner consultant / Manage capability / admin). */
    private function canManage(Admission $a): bool
    {
        $u = Auth::user();
        if ($u->isObserver()) {
            return false;   // global read-only guarantee — capability flags never override (J1-9)
        }

        return $u->isAdmin() || $u->can_manage || (int) $a->consultant_id === (int) $u->id;
    }

    /** GET /admissions/{admission}/handover — current text + latest 20 revisions (all roles, read-only). */
    public function show(Admission $admission): JsonResponse
    {
        $h = $admission->handover()->with('updatedBy:id,name,full_name')->first();

        return response()->json([
            'body' => $h?->body,
            'updated_by_name' => $h?->updatedBy ? ($h->updatedBy->full_name ?: $h->updatedBy->name) : null,
            'updated_at' => $h?->updated_at?->toIso8601String(),
            'today' => $h !== null && $h->updated_at->isToday(),
            'revisions' => HandoverRevision::where('admission_id', $admission->id)
                ->with('author:id,name,full_name')->orderByDesc('id')->limit(20)->get()
                ->map(fn ($r) => [
                    'body' => $r->body,
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
        if (! ($this->canManage($admission) || $isOutgoing)) {
            throw new AccessDeniedHttpException('Only the primary consultant or a manager may update the handover.');
        }
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        DB::transaction(function () use ($admission, $data) {
            $h = Handover::firstOrNew(['admission_id' => $admission->id]);
            $h->body = $data['body'];
            $h->updated_by = Auth::id();
            $h->updated_at = now();   // explicit — an unchanged body must still satisfy the same-day gate
            $h->save();
            HandoverRevision::create(['admission_id' => $admission->id, 'body' => $data['body'], 'author_id' => Auth::id()]);
        });
        $this->audit('handover.update', $admission->id, ['revision_id' => HandoverRevision::latestIdFor($admission->id)]);

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
            'location' => $s->admission?->current_location,
            'from' => $s->fromConsultant ? ($s->fromConsultant->full_name ?: $s->fromConsultant->name) : '—',
            'to' => $s->toConsultant ? ($s->toConsultant->full_name ?: $s->toConsultant->name) : '—',
            'required_at' => $s->required_at?->toIso8601String(),
            'signed_at' => $s->signed_at?->toIso8601String(),
            'voided_at' => $s->voided_at?->toIso8601String(),
            'body' => $s->admission?->handover?->body,
            'body_updated_at' => $s->admission?->handover?->updated_at?->toIso8601String(),
        ];
        $with = ['admission.patient:id,mrn,name', 'admission.handover', 'fromConsultant:id,name,full_name', 'toConsultant:id,name,full_name'];

        return Inertia::render('Handovers/Index', [
            'awaiting' => HandoverSignature::where('to_consultant_id', $me)->pending()
                ->with($with)->orderByDesc('required_at')->get()->map($shape)->values(),
            'outgoing' => HandoverSignature::where('from_consultant_id', $me)
                ->where('required_at', '>=', now()->subDays(7))
                ->with($with)->orderByDesc('required_at')->get()->map($shape)->values(),
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
        $this->audit('handover.sign', $s->admission_id, ['signature_id' => $s->id, 'revision_id' => $s->revision_id]);
    }

    /** GET /api/notifications — latest 15 for the bell dropdown + unread count. */
    public function notifications(): JsonResponse
    {
        return response()->json([
            'notifications' => Notification::where('user_id', Auth::id())
                ->orderByDesc('id')->limit(15)->get(['id', 'type', 'payload', 'read_at', 'created_at']),
            'unread' => Notification::where('user_id', Auth::id())->whereNull('read_at')->count(),
        ]);
    }

    /** POST /notifications/read-all — opening the bell marks everything read. */
    public function readAll(Request $request)
    {
        Notification::where('user_id', Auth::id())->whereNull('read_at')->update(['read_at' => now()]);

        return $request->expectsJson() ? response()->json(['ok' => true]) : back();
    }
}
