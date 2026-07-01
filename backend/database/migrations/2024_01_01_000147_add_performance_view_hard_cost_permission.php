<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $guard = 'api';
        $permName = 'performance.view_hard_cost';

        $exists = DB::table('permissions')->where('name', $permName)->where('guard_name', $guard)->exists();
        if (!$exists) {
            DB::table('permissions')->insert([
                'name' => $permName,
                'guard_name' => $guard,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $permId = DB::table('permissions')->where('name', $permName)->where('guard_name', $guard)->value('id');
        if (!$permId) return;

        $roles = ['super_admin', 'tech_admin', 'ops_admin', 'admin', 'manager'];
        $roleIds = DB::table('roles')->where('guard_name', $guard)->whereIn('name', $roles)->pluck('id');

        foreach ($roleIds as $roleId) {
            $exists = DB::table('role_has_permissions')
                ->where('permission_id', $permId)
                ->where('role_id', $roleId)
                ->exists();
            if (!$exists) {
                DB::table('role_has_permissions')->insert([
                    'permission_id' => $permId,
                    'role_id' => $roleId,
                ]);
            }
        }
    }

    public function down(): void
    {
        $permId = DB::table('permissions')->where('name', 'performance.view_hard_cost')->where('guard_name', 'api')->value('id');
        if ($permId) {
            DB::table('role_has_permissions')->where('permission_id', $permId)->delete();
            DB::table('permissions')->where('id', $permId)->delete();
        }
    }
};
