<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 4 — Item 3: admin Security panel — read-only login-anomaly surfacing built entirely from
 * the existing audit_log + users tables (no new schema). Three sections:
 *   1. failed-login clusters per (account, IP) in the last 24h;
 *   2. first-seen IPs for known users (no earlier audit row from the same actor+IP);
 *   3. MFA-noncompliant users while enforcement is on.
 * Admin-only (inside the `admin` route group). Account lockdown is the existing Control user edit
 * (set active=false) — this panel only surfaces, it never writes.
 */
class SecurityController extends Controller
{
    public function index(): Response
    {
        // 1. Last-24h failed-login clusters per account/IP (top 20). audit_log.created_at is written
        //    in the DB session timezone (useCurrent); compare against the DB clock so the window is
        //    correct regardless of any app/DB timezone offset.
        $failedClusters = DB::table('audit_log')
            ->where('action', 'login.failed')
            ->where('created_at', '>=', DB::raw('NOW() - INTERVAL 24 HOUR'))
            ->selectRaw('actor_name, ip, COUNT(*) attempts, MAX(created_at) last_at')
            ->groupBy('actor_name', 'ip')
            ->orderByDesc('attempts')
            ->limit(20)
            ->get();

        // 2. First-time-seen IPs for known users — the most recent login rows whose (actor_id, ip)
        //    pair has no EARLIER audit row. Approximation (history starts at deployment) but enough
        //    to surface a sign-in from a brand-new location.
        $firstSeenIps = DB::table('audit_log as a')
            ->whereIn('a.action', ['login.success', 'login.failed'])
            ->whereNotNull('a.actor_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('audit_log as b')
                ->whereColumn('b.actor_id', 'a.actor_id')
                ->whereColumn('b.ip', 'a.ip')
                ->whereColumn('b.id', '<', 'a.id'))
            ->orderByDesc('a.created_at')
            ->limit(25)
            ->selectRaw('a.actor_name, a.ip, a.action, a.created_at first_at')
            ->get();

        // 3. MFA-noncompliant active users while enforcement is on (level 1 = admins only; 2 = all)
        $level = (int) Setting::current()->mfa_enforcement;
        $mfaNonCompliant = $level > 0
            ? User::where('active', 1)
                ->whereNull('mfa_enrolled_at')
                ->when($level === 1, fn ($q) => $q->where('role', User::ROLE_ADMIN))
                ->orderBy('role')->orderBy('full_name')
                ->get(['id', 'username', 'full_name', 'name', 'role', 'created_at'])
                ->map(fn (User $u) => [
                    'id' => $u->id,
                    'username' => $u->username,
                    'name' => $u->full_name ?: $u->name,
                    'role_label' => $u->roleLabel(),
                ])
            : collect();

        return Inertia::render('Security/Index', [
            'failedClusters' => $failedClusters,
            'firstSeenIps' => $firstSeenIps,
            'mfaNonCompliant' => $mfaNonCompliant->values(),
            'mfaEnforcement' => $level,
            'notifyThreshold' => (int) (Setting::current()->failed_login_notify_threshold ?? 0),
        ]);
    }
}
