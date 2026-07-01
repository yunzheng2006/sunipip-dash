<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'router.view',
            'router.create',
            'router.edit',
            'router.delete',
            'router.bind',
            'router.wg_manage',
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'api']);
        }

        $allPerms = Permission::where('guard_name', 'api')->get();

        foreach (['super_admin', 'tech_admin', 'ops_admin', 'admin'] as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'api')->first();
            if ($role) {
                $role->syncPermissions($allPerms);
            }
        }

        $manager = Role::where('name', 'manager')->where('guard_name', 'api')->first();
        if ($manager) {
            $manager->givePermissionTo(
                Permission::where('guard_name', 'api')->whereIn('name', ['router.view', 'router.bind'])->get()
            );
        }
    }

    public function down(): void
    {
        $permissions = [
            'router.view', 'router.create', 'router.edit',
            'router.delete', 'router.bind', 'router.wg_manage',
        ];
        Permission::whereIn('name', $permissions)->delete();
    }
};
