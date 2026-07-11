<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-07-11 hardening (adversarial-review follow-up): users.email carries a restored UNIQUE index
 * — defense-in-depth for the login-adjacent identity used by password-reset and email verification.
 * Duplicate non-null emails are rejected at the database level; multiple NULL emails (accounts with
 * no address on file — the legacy shape) stay allowed because MySQL treats NULLs as distinct.
 */
class UsersEmailUniqueTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_non_null_email_is_rejected_by_the_database(): void
    {
        User::create(['username' => 'u1', 'name' => 'One', 'email' => 'clash@example.test',
            'password' => 'secret12345', 'role' => User::ROLE_RESIDENT, 'active' => 1]);

        $this->expectException(QueryException::class);
        User::create(['username' => 'u2', 'name' => 'Two', 'email' => 'clash@example.test',
            'password' => 'secret12345', 'role' => User::ROLE_RESIDENT, 'active' => 1]);
    }

    public function test_email_uniqueness_is_case_insensitive(): void
    {
        User::create(['username' => 'c1', 'name' => 'C1', 'email' => 'Case@Example.test',
            'password' => 'secret12345', 'role' => User::ROLE_RESIDENT, 'active' => 1]);

        $this->expectException(QueryException::class);
        User::create(['username' => 'c2', 'name' => 'C2', 'email' => 'case@example.test',
            'password' => 'secret12345', 'role' => User::ROLE_RESIDENT, 'active' => 1]);
    }

    public function test_multiple_users_with_null_email_are_allowed(): void
    {
        User::create(['username' => 'n1', 'name' => 'N1', 'email' => null,
            'password' => 'secret12345', 'role' => User::ROLE_RESIDENT, 'active' => 1]);
        User::create(['username' => 'n2', 'name' => 'N2', 'email' => null,
            'password' => 'secret12345', 'role' => User::ROLE_RESIDENT, 'active' => 1]);

        $this->assertSame(2, User::whereNull('email')->count());
    }
}
