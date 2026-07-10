<?php

namespace App\Http\Middleware;

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
            'idleTimeoutMinutes' => fn () => $user ? (int) \App\Models\Setting::current()->idle_timeout_minutes : 0,
            'absTimeoutMinutes' => fn () => $user ? (int) \App\Models\Setting::current()->abs_timeout_minutes : 0,
            'flash' => fn () => $request->session()->get('flash'),
            // bell badge — a cheap COUNT on (user_id, read_at); refreshed by every Inertia visit
            'unreadNotifications' => fn () => $user
                ? \App\Models\Notification::where('user_id', $user->id)->whereNull('read_at')->count()
                : 0,
        ]);
    }
}
