<?php

namespace App\Http\Middleware;

use App\Models\Notification;
use App\Models\Setting;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user = $request->user();

        return array_merge(parent::share($request), [
            'appName' => config('app.name'),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => (int) $user->role,
                    'role_label' => $user->roleLabel(),
                    'is_admin' => $user->isAdmin(),
                    // Phase 4 — Item 4: drives the optional TOTP field on the step-up form
                    'mfa_enrolled' => $user->mfaEnabled(),
                    // 2026-07-11 auth-hardening: lets the client reason about verification state;
                    // the middleware gate (email.verify) is authoritative, this is informational only
                    'email_verified' => $user->email_verified_at !== null,
                    // Wave 2, Item 10: first-login tour gate. NULL → the tour auto-starts; the "?"
                    // replay never touches this. ISO string (or null) so the client can compare.
                    'tour_completed_at' => optional($user->tour_completed_at)->toIso8601String(),
                    'can' => [
                        'assign' => (bool) $user->can_assign,
                        'add' => (bool) $user->can_add,
                        'manage' => (bool) $user->can_manage,
                        'modify' => (bool) $user->can_modify,
                    ],
                ] : null,
            ],
            // Phase 4 — Item 2: client idle-warning overlay reads these (server middleware is authoritative)
            'idleTimeoutMinutes' => fn () => $user ? (int) Setting::current()->idle_timeout_minutes : 0,
            'absTimeoutMinutes' => fn () => $user ? (int) Setting::current()->abs_timeout_minutes : 0,
            'flash' => fn () => $request->session()->get('flash'),
            // bell badge — resolved-aware COUNT (user_id, read_at) that also folds in still-open
            // "handover.incomplete" actionable reminders (they persist until resolved, not read-all
            // dismissed); refreshed by every Inertia visit. Kept IDENTICAL to HandoverController::notifications' unread expression.
            'unreadNotifications' => fn () => $user
                ? Notification::where('user_id', $user->id)->where(fn ($q) => $q
                    ->where(fn ($x) => $x->where('type', '!=', 'handover.incomplete')->whereNull('read_at'))
                    ->orWhere(fn ($x) => $x->where('type', 'handover.incomplete')->whereNull('resolved_at')))->count()
                : 0,
        ]);
    }
}
