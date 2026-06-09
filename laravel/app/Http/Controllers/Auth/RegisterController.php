<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public self-registration. New accounts are created INACTIVE (active = 0) and cannot be admins —
 * an administrator must activate them. Mirrors the legacy register.php gate.
 */
class RegisterController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Auth/Register', [
            'roles' => [2 => 'Registrar', 3 => 'Consultant', 4 => 'Resident', 5 => 'Observer'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:240', 'unique:users,username'],
            'full_name' => ['required', 'string', 'max:191'],
            'email' => ['nullable', 'email', 'max:191', 'unique:users,email'],
            'role' => ['required', 'integer', 'in:2,3,4,5'],   // never admin via self-registration
            'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()],
        ]);

        User::create([
            'username' => $data['username'],
            'name' => $data['full_name'],
            'full_name' => $data['full_name'],
            'email' => $data['email'] ?? null,
            'role' => $data['role'],
            'password' => $data['password'],          // hashed by the model cast
            'active' => 0,                            // pending admin activation
            'pass_exp_date' => now()->toDateString(),
        ]);

        return redirect()->route('login')->with('flash', [
            'type' => 'success',
            'message' => 'Registered — an administrator must activate your account before you can sign in.',
        ]);
    }
}
