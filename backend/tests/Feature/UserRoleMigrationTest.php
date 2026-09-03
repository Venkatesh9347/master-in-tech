<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserRoleMigrationTest extends TestCase
{
    public function test_users_table_has_default_role_column(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'role'));

        $role = DB::table('users')->first()?->role;
        $this->assertTrue(is_string($role) || $role === null);
    }
}
