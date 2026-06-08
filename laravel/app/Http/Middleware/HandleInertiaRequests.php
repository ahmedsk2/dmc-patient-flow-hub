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
                    'can' => [
                        'assign' => (bool) $user->can_assign,
                        'add' => (bool) $user->can_add,
                        'manage' => (bool) $user->can_manage,
                        'modify' => (bool) $user->can_modify,
                    ],
                ] : null,
            ],
            'flash' => fn () => $request->session()->get('flash'),
        ]);
    }
}
