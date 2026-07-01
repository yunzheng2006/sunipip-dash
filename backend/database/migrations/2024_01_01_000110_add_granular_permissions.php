<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    public function up(): void
    {
        $newPermissions = [
            'customer.view_all',
            'subscription.refund',
            'subscription.transfer',
            'payment.gateway_refund',
        ];

        foreach ($newPermissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'api']);
        }

        // 给 super_admin / tech_admin / ops_admin / admin 同步全部权限
        $allPerms = Permission::where('guard_name', 'api')->get();
        foreach (['super_admin', 'tech_admin', 'ops_admin', 'admin'] as $roleName) {
            $role = \Spatie\Permission\Models\Role::where('name', $roleName)->where('guard_name', 'api')->first();
            if ($role) {
                if ($roleName === 'tech_admin') {
                    $role->syncPermissions($allPerms->where('name', '!=', 'user.assign_role'));
                } elseif ($roleName === 'ops_admin' || $roleName === 'admin') {
                    $role->syncPermissions($allPerms->whereNotIn('name', ['user.assign_role', 'user.delete']));
                } else {
                    $role->syncPermissions($allPerms);
                }
            }
        }

        // 给 manager 加新权限
        $manager = \Spatie\Permission\Models\Role::where('name', 'manager')->where('guard_name', 'api')->first();
        if ($manager) {
            $manager->givePermissionTo([
                'customer.view_all',
                'subscription.refund',
                'subscription.transfer',
                'payment.gateway_refund',
            ]);
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', [
            'customer.view_all',
            'subscription.refund',
            'subscription.transfer',
            'payment.gateway_refund',
        ])->delete();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
